# LESSONS.md — Lessons from building with an AI agent

Compiled from the end of Phase 2 of the Smaily Connect WordPress plugin, where fixing
integration bugs took ~19 iterations. Much of that was avoidable. This document is meant
to be carried into **the start of the next project** and handed to the **Code agent**
so the same patterns don't repeat.

---

## TL;DR — three things from day one

1. **Build marker** — commit hash visible at runtime (console / footer / `/version`).
   Kills the "is it a bug or stale cache?" ambiguity.
2. **Agent sees the real environment** — Docker / wp-env / browser / real API in the
   agent's hands **before** coding starts, not later.
3. **Integration tests at the boundaries** — every "done" piece needs one end-to-end
   flow test in a real environment, not just unit coverage.

Plus two non-technical:
- **Domain walkthrough catches spec errors** that no test catches.
- **Live probe before coding** when a contract detail (field name, payload location,
  response shape) is undocumented — a 5-minute curl against a two-system assumption
  saves a day of iteration later.

---

## 1. Root cause: the integration layer was systematically untested

All the late-Phase-2 pain reduces to one thing. Backend logic was strong (95% unit
coverage, 140 PHPUnit + 96 Vitest tests, all green). But **boundaries between
components** were mocked, not actually tested.

Every bug lived at a **boundary**, not inside a component:

| Bug | Boundary |
|-----|----------|
| `restRoot` vs `restUrl` field mismatch | PHP ↔ TypeScript |
| Wizard didn't call save | Wizard ↔ backend |
| Legacy hook crash on REST save | New code ↔ legacy code |
| Backfill DB table missing | Plugin ↔ WP migration (dbDelta) |
| Workflows returned mock data | Plugin ↔ Smaily API |
| Writes with key X, reads with key Y | Write ↔ read |

**General rule: mocks hide boundaries. Integration bugs live at boundaries.**
The more components in the system (here: React + REST + WP + WC + Polylang + legacy +
Smaily API), the more boundaries, the more important real integration testing.

---

## 2. Biggest time sinks, ranked

### 2.1 Cache ambiguity (most expensive)

**What happened:** repeatedly "isn't saving" → reinstall → sometimes works. We didn't
know whether staging was even running the right code. Every "doesn't work" was
ambiguous: bug or stale code?

**Fix (found too late):** `buildHash` in the boot payload. ~5 lines of code. A console
check `window.appBoot.buildHash === "abc1234"` confirms in a second whether the right
code is running.

**Rule for next time:** every deployable project needs a build marker **from day one**.
Commit hash (with `-dirty` flag if the working tree is modified) visible at runtime.
Without it, all staging debugging is blind.

### 2.2 Agent couldn't see the real environment (second most expensive)

**What happened:** the agent fixed CSS and integration logic **blindly** — guessed a
fix, the human tested in staging, broke, repeat. The alignment bug took 5 attempts.
The backfill bug (missing DB table) would **never** have been resolved through the
staging cycle, because you can't see it without DB access.

**Fix (found too late):** Docker + wp-env + Chromium in the agent's environment. After
that the agent saw real WP itself, read debug.log, reproduced bugs. The backfill bug
resolved on the first wp-env run (debug.log showed the SQL error immediately).

**Rule for next time:** if the agent is building something that runs in environment X,
the agent must have **access to environment X from day one**. WP plugin → wp-env.
React app → browser. API → real HTTP test. Blind fixing is slow and imprecise.

### 2.3 Mock tests created false confidence (root cause behind most bugs)

**What happened:** all unit tests green, but every integration bug got through. Mocks
test a function **in isolation**, not the flow **end to end**. "Green test" ≠
"working feature."

**Fix:** the agent started **running full flows in the browser (Chromium)** before
every ZIP — the same thing a human would do manually (step 1 → 6, close, reopen, check
persistence). After that: the first clean walkthrough.

**Rule for next time:** green unit tests ≠ working feature. Every "done" sub-PR needs
**one end-to-end flow test in a real environment**, not just unit coverage. Units for
logic (fast, valuable), integration for boundaries (which was missing initially).

### 2.4 Mocks reflect your assumptions, not reality

**What happened** (Phase 3 sub-PR 3.1.2): the plugin called the rec engine with path
`/setup/exchange`, the mock server cheerfully responded to `/setup/exchange`. Both
tests green. The real engine, however, required `/api/setup/exchange` — so plugin ×
engine compatibility was **broken**, even though the mock said "all OK." The mock was
**built around the same assumption** as the plugin (wrong path), so it confirmed the
assumption, not reality.

**The same pattern repeated in 3.2** — the engine added `event_id` acceptance in the
catalog body (commit 985c488), but the **location in the body was undocumented**
(per-product vs top-level). A mock could be built around either variant and stay
green; the real engine accepts only one.

**Fix:** when a contract detail is **undocumented** or **recently added**, do a
**live probe** before coding — two curl calls against the real endpoint, see which
gets 200 / which gets 4xx. Lock against the real engine's response, not the mock
assumption. Then build the mock to **match the real behavior**.

**Rule for next time:** mocks test plugin logic **against mock assumptions**. Only a
**live call against the real system** tests real compatibility. So **both are
needed**, but **a live call is mandatory** before ZIP/merge, not "if we get to it."
The path bug should have been resolved with five minutes of curl, five days before
the live test caught it.

**Third time, same pattern** (sub-PR 3.2.2): the mock engine's setup-exchange
returned its endpoints map with **unprefixed keys** (`catalog`) and **relative
paths** — built around the plugin's original assumption. The real engine returns
`ingest_catalog` keys with **absolute URLs** (verified in the 3.1.2 live exchange).
A `Client::ingest_catalog` reading `endpoints()['ingest_catalog']` would have passed
every mock test and resolved a null URL in production. The fix rebuilt the mock to
the engine's live shape. One way to *enforce* this going forward: seed the mock from
a captured real setup-response, or periodically sync it against an engine-team
fixture, so the mock can't drift back toward plugin assumptions.

### 2.5 Context-vs-code audit at the start of a new session

**What happened** (Phase 3 sub-PR 3.2): the agent took the session context summary,
started with the plan, **but checked it against the real code first**. Found 3
divergences: (a) names in the plan were already taken in another module, (b) the DB
schema was already in place (migration committed), (c) an out-of-date architecture
doc described an old approach predating a recent design decision.

**This is the right behavior.** After long gaps (session switch, restart, memory
refresh), the **context summary is a risk** — it's a human's understanding, not the
code's actual state. Blind-coding from it leads to drift that staging tests catch
(= slow + expensive feedback loop).

**Rule for next time:** at the start of a new session, **before writing a single line
of code**, the agent must:
1. `git log` — where are we actually (commit hash + messages)
2. Look at existing components against the context plan (are the names free? does the
   table exist? is the doc current?)
3. Report any divergences **before** locking the plan

An honest context-code audit saves hours of later rewriting.

### 2.6 Test environment state is not persistent

**What happened** (Phase 3 sub-PR 3.2 setup): the plugin ran setup-exchange against
the real engine (in 3.1.2), and api_key was saved encrypted in `wp_options`. A few
weeks later, when 3.2 coding started, the agent checked the DB → **all `smly_rec_*`
keys were gone**. Migration tables were still there (activation hook on every boot),
but the `wp_options` connection state had been wiped.

The cause: `wp-env` recreates the WordPress DB on certain `start`/`stop` cycles —
this is **documented behavior**, not a bug. But it means **every sub-PR** that needs
a "connected" state (setup-exchange result, saved settings, integration test
fixtures) has to account for the fact that **state can disappear on any restart**.

**Solution** (two layers):

1. **Fixture bootstrap** in the test suite — `EnvSeed.php` (opposite of `EnvScrub`)
   establishes the required state at test start, using the plugin's own save API with
   mock data. Gives integration tests a "connected" state **without needing a real
   API call**.
2. **Live tests preserve real data** — Chromium walks with the real API key (which a
   human re-mints if lost) verify real compatibility.

**Rule for next time:** test environment state is not persistent. Any state tests
depend on must come from a **fixture** (regenerable on every run) or a **persistent
volume** (DB snapshot that survives restarts). Don't rely on the wp-env DB.

This is a **general rule** for other container-based dev environments too (Docker
Compose, dev server, Vercel preview) — restart wipes state unless it's been
explicitly persisted. Design tests to be restart-proof.

---

## 3. The non-technical lesson: spec errors vs bugs

Several of the biggest fixes **weren't bugs** — they were **spec errors** (ambiguity
or omission in the specification). The agent implemented exactly what the spec said;
the error was in the spec.

Examples:
- Mode A "default account" logic — commercial absurdity that only a domain expert saw
- Wizard-first architecture — UX logic decision
- Field-naming standard — cross-platform consistency need
- "Continue should save" — UX expectation

**Rule for next time:** tests check "does the code do what the spec says?". Only a
**domain expert** checks "does the spec itself make sense?". These are separate
checks — both are needed. **A staging walkthrough with a domain expert catches spec
errors that technical tests don't catch.**

---

## 4. Concrete checklist for the start of a new project

From day one, before feature work:

- [ ] **Build marker** — commit hash visible at runtime (console / footer / `/version`
      endpoint)
- [ ] **Agent access to the real environment** — Docker / wp-env / browser / real API
      in the agent's hands
- [ ] **Integration test baseline before features** — "does the app start, do
      endpoints respond, does the DB schema get created, does saving round-trip" in a
      real environment (not mocks)
- [ ] **Read/write symmetry rule** — if you save with key X, a test reads back with
      key X
- [ ] **Endpoint registration from one place** (array loop / `EndpointRegistry`
      declarative list), not each one by hand — avoids copy-paste-gap 404s
- [ ] **Full uninstall + reinstall** in the test protocol (not upload-over) — avoids
      leftover/cache confusion
- [ ] **Path constants from one place** — all external system URLs (`Client::PATH_*`
      or similar), not inline strings across files. Avoids "some with `/api`, some
      without" divergence.
- [ ] **Sensitive credentials in env variables** — setup token / api key / password
      **must not** end up in chat, reports, commit messages, or code. Refer to "env
      variable", never paste the value.

For every sub-PR / feature:

- [ ] **One end-to-end flow test in a real environment** (not just unit coverage)
- [ ] **The agent shows "I ran the flow through, confirmed" in the report**, not just
      "unit tests green"
- [ ] **Live probe before coding** when a contract detail (field name, payload
      location, response shape) is undocumented or recently added — 5 minutes of curl
      vs 5 days of iteration
- [ ] **Fixture bootstrap in the test suite** — all state tests assume must come from
      a fixture (regenerable), not from a persisted DB. Container restart wipes state.

At the start of every new session (after a gap):

- [ ] **Context-code audit** — `git log`, check component names/existence against the
      context plan, report divergences **before** locking the plan
- [ ] **Environment check** — Docker / wp-env / Chromium / deps working? If not, fix
      before feature work

---

## 5. What worked well (keep doing)

Not everything was wrong — these were good and worth repeating:

- **Sub-PR-by-sub-PR build + review at each step** — kept tempo and quality
- **Strong unit-test discipline for logic** — fast development, clean backend. The
  problem wasn't "too few tests," it was "the wrong kind of tests at the integration
  layer."
- **Honest acknowledgment of limits** — the agent said what it could NOT test
  (e.g. "real API requires the human's credentials") instead of pretending
- **Security awareness** — the agent detected prompt injection in tool output and
  asked permission before crossing a boundary (binary download)
- **Shared single-source components** — `SubscriberPayloadBuilder`, `buildTabPayload`,
  `Client::PATH_*` constants shared as one source of truth, not duplicated (prevents
  drift)
- **EndpointRegistry declarative route list** — one list, two consumers (Bootstrap +
  tests). Route 404s became structurally impossible, not just "tested for." The best
  architectural step in Phase 3.
- **Engine = source of truth for paths** (Phase 3 3.1.2 polish) — the plugin prefers
  the engine-returned endpoints map over hardcoded constants. Engine path migrations
  don't require plugin updates. Constants are fallback. The right direction of
  dependency in a two-system design.
- **Coordinated design decisions from both sides before coding** — the Phase 3 sub-PR
  3.2 idempotency model (variant A) was confirmed on four points by the engine team
  **before** plugin coding began. The engine implemented in advance. So the live test
  had to work on the first call, not discover a mismatch. The opposite of the
  path-bug pattern (assume → implement → discover the difference). Lock the contract
  before coding.

---

## 6. A balancing thought

Not all iterations were avoidable. The end of Phase 2 was the **first real-environment
contact**, which always exposes assumptions. And the agent's speed (a sub-PR a day,
clean backend) came **precisely** from that unit-test discipline.

The trade-off isn't "more tests vs fewer," it's **the right kind of tests in the right
place**: units for logic (fast), integration at boundaries (which was missing
initially).

The checklist above would have reduced the ~19 late-Phase-2 iterations to an
estimated ~5.
