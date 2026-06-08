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

### 2.7 A spec sync updates the doc, not the code

**What happened** (Phase 3 sub-PR N-7.1): the catalog ingest wire wrapper key
flip-flopped. The doc originally said `products`; a 3.2.4 live probe found the
deployed engine then wanted `items` (we switched the plugin + mock). Later **W2**
(engine `b5b1295`) renamed it **back to `products`** — a clean break, an
`items`-wrapped payload now `400`s. The W2 contract **sync updated the doc**
(byte-identical, CC-8 discipline) **but not the plugin code**: `Client::ingest_catalog`
kept sending `{items:[...]}`. The mock — still enforcing `items` from the pre-W2
shape — **stayed green on every integration run**, so the drift was invisible. It
sat from W2 through W3/W4/W5/N-6/N-7 until the **first catalog live-request after
W2** (the N-7.1 catalog live-walk) `400`d on `products: Required`.

**Why the other endpoints were safe:** customers and orders each got a live-walk
**right after** their contract change (customers after W4, orders after W5), so any
drift would have surfaced immediately. Catalog was the unique case — its breaking
change (W2) and its next live-walk (N-7.1) were far apart. **The gap is exactly
(contract-change → next live-walk of that endpoint); the longer it is, the longer a
drift hides.**

**Two failure modes a wire-shape change can cause:**
- **Missing/renamed required field** (wrapper key, a newly-required field) → hard
  `400`. This is what bit catalog.
- **Removed field still sent** (W3 `discount_price`, W4 `smaily_contact_id` / `consent`)
  → Zod **silently strips** it (W1 confirmed: a stray top-level `event_id` is ignored).
  No `400`, but silent waste / privacy surprise. A full audit must check **both**.

**Fix / rule for next time:** a contract **sync is not code-complete**. Every sync
that changes a **wire shape** (wrapper key, a required field, an enum value, a removed
field) must be checked **against the real plugin code in the same pass**, and the
**mock must move to the new shape in the same sync** — otherwise the mock masks the
very drift it exists to catch. After any sync touching an endpoint, run that
endpoint's live-walk (or a single curl) before treating it as done; don't let a
breaking change wait for an unrelated sub-PR to discover it. When auditing, walk the
**whole changelog**, not just the most recent sync — drift accumulates silently across
syncs whenever the endpoint isn't live-exercised between them.

### 2.8 PHPStan as a storage-assumption (HPOS) bug detector

**What happened** (3.8.0 GDPR): the GDPR exporter/eraser read order rec-meta with
`get_post_meta( $order_id, '_smaily_rec_id' )`. PHPStan flagged an unrelated line
— casting `wc_get_orders( [ 'return' => 'ids' ] )` results to int ("Cannot cast
WC_Order to int", because the stub types the return as `WC_Order[]`). Chasing
*why* the types didn't line up — instead of mechanically silencing it — surfaced
a real bug: **`get_post_meta` / `delete_post_meta` only work under legacy
(posts) order storage.** Under **HPOS** (High-Performance Order Storage — the
wp-env + WC-10.7 mode, and a store can enable it at WC 8.2+) order meta lives in
`wc_orders_meta`, so the post-meta calls would silently read/erase **nothing** —
a partial GDPR export and an incomplete erase, both failing *quietly*.

The fix is storage-agnostic WooCommerce APIs: `$order->get_meta( $key )`,
`$order->delete_meta_data( $key )` + `$order->save()` — they resolve to whichever
backend is active.

**Why it nearly slipped:** the unit/integration tests run on the HPOS wp-env, but
a test that only checks "export returns *something*" wouldn't notice that the
order-meta section was empty; and the GDPR live-walk could have missed it too
(the engine side would pass). The static analyzer caught it *before* any runtime
test — because the type mismatch was a symptom of the storage assumption.

**Rules for next time:**
- Treat a PHPStan complaint as a question ("why don't these types agree?"), not a
  nuisance to cast away. The cast that doesn't typecheck is often a storage /
  shape assumption that's wrong.
- For ORDER data, prefer the WooCommerce object API over WP core post/meta
  functions: `$order->get_meta()` not `get_post_meta()`, `wc_get_orders()` not
  `WP_Query( post_type=shop_order )`. Post/meta functions are legacy-storage-only;
  the WC API is HPOS-safe. (Sibling to 3.5.2: read meta via the WC API, but
  ENUMERATE with direct SQL when you need a stable id-cursor `wc_get_orders`
  can't give — pick the storage-correct tool for each job.)

---

### 2.9 A URL placeholder convention is a wire shape — the live-walk caught it, the mock hid it

**What happened** (3.8.1 GDPR live-walk): the GDPR customer endpoints carry the
email in the URL **path**. The engine's endpoints-map advertises them with a
literal `{email}` token: `…/customer/{email}/export`. The plugin's `Client`
built the URL with `sprintf( resolve_url(…), rawurlencode($email) )` — i.e. it
assumed a `%s` placeholder. `sprintf` on a string that contains `{email}` (and
no `%s`) returns it **unchanged**, so every GDPR request went to the literal
`…/customer/{email}/export`. The engine received the literal string `{email}`
as the email and answered `No customer with email '{email}'` — a 404 that looked
like an engine bug (the un-interpolated `{email}` in the message reinforced the
illusion). A raw request to the *same* URL with the email substituted returned
`200`, proving the fault was entirely plugin-side.

**Why every test was green anyway:** the unit ClientTest seeded its endpoints map
with `…/customer/%s/export`, and the integration mock's endpoints map did too.
Both mirrored the plugin's *wrong* assumption, so `sprintf` substituted happily
and the assertions passed. The live engine is the only place that used `{email}`
— so only the live-walk could surface it. This is §2.3/§2.4 again, in a new
costume: **the placeholder syntax in an endpoints-map value is itself a wire
shape**, and a mock built to the same assumption as the code validates the bug
instead of catching it.

The fix: substitute via `str_replace( '{email}', rawurlencode($email), $url )`,
not `sprintf` — unifying on the engine's `{email}` convention (fallback path
templates now use `{email}` too). The mock + unit map were switched to `{email}`
to mirror live, and the mock's customer routes now **422 on a literal-placeholder
email**, so a future `sprintf`-style regression fails the integration suite loudly.

**Rules for next time:**
- When a URL comes from the engine's endpoints-map, **the placeholder syntax is
  part of the contract** — confirm it (`{name}` vs `%s` vs `:name`) before
  choosing a substitution function. `sprintf` silently no-ops on a non-`%`
  template; `str_replace` silently no-ops on a missing token. Either can ship a
  literal placeholder to the wire.
- Seed unit/mock endpoints maps with the **engine's real placeholder form**, not
  a form convenient for your substitution code. A mock map that matches your code
  proves nothing.
- A 404 whose message echoes an un-interpolated placeholder (`'{email}'`,
  `':id'`) is almost always *your* request sending the literal token — look at
  the outgoing URL before blaming the engine.
- 3.8.0 shipped (committed) with this latent bug and full green gates; 3.8.1's
  live-walk is what caught it. A formatted-field feature is not done until a
  live-walk has exercised it against the real engine (CLAUDE.md scar).

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
