# DECISIONS.md — Smaily Connect technical decisions

The canonical log of all significant technical design decisions in the Smaily
Connect plugin, with the reasoning behind each. (Maintained through Phase 3 as
`DECISIONS_DRAFT.md`; finalized under this name 2026-06-11 — the single-file
F-numbered log was chosen over an ADR-per-file split.) Purpose: a future developer
(including a future you) can quickly understand **why** the plugin is the way it
is, not just **what** it is.

Each decision follows a short form:
- **Context** — what the situation or problem was
- **Decision** — what was chosen
- **Rationale** — why
- **Alternatives** — what was rejected and why (when relevant)
- **Relationships** — how it influences other decisions (when relevant)

At the end of Phase 3 this is refined into a final `DECISIONS.md` or split into
ADR files (`docs/adr/NNNN-decision-name.md` pattern).

---

## Cross-cutting — project-wide decisions

### CC-1: Single-source-of-truth components (DRY discipline)

**Context:** the same logic in two places drifts — when one side changes, the other
falls behind. Several drift bugs were found at the end of Phase 2 (read/write key
mismatch).

**Decision:** if the same logic or data structure is used in two or more places,
create **one shared component** and delegate to it from each call site.

**Concrete examples:**
- `SubscriberPayloadBuilder.php` — WP user → Smaily payload. Backfill and
  HookHandler delegate to it.
- `buildTabPayload` (TypeScript) — wizard and Settings import the same mapper
- `Client::PATH_*` constants — all plugin↔engine URLs from one place
- `EndpointRegistry.php` — one declarative route list, Bootstrap + tests read the
  same source
- `IngestQueue` — all rec-engine event types share one table/API/idempotency
  mechanism (variant A)

**Rationale:** all the late-Phase-2 drift bugs (syncFields read/write mismatch,
restRoot/restUrl, wp_subscription, automation_mapping) **all** came from duplicated
logic. One source of truth makes drift structurally impossible.

**Consequence:** when adding a new feature, **always ask**: "is this logic already
somewhere? Can I delegate to an existing shared component?"

---

### CC-2: Read/write symmetry rule

**Context:** half the Phase 2 bugs were "writes with key X, reads with key Y" —
subtle, invisible at the unit-test level, surfaces in staging.

**Decision:** every data write must be **tested with a round-trip** — the write
happens with key X, **a test reads back with key X**. If the key names diverge, the
test fails automatically.

**Consequence:** the integration test suite (3.0) `SettingsRoundTripTest` and in
every feature sub-PR (e.g. pinning the `endpoints[ingest_catalog]` key name in
catalog).

---

### CC-3: Sensitive credentials never in code, chat, or reports

**Context:** in Phase 3 sub-PR 3.0 the agent's report leaked the setup token
(`Fey-...`). The token was rotated immediately, but the pattern had to change.

**Decision:** the **values** of setup tokens, api keys, passwords, and crypto keys
must not end up in:
- Code (committed) — use encrypted `wp_options` storage
- Chat / report text — refer to "env variable", "encrypted in DB"
- Commit messages — same
- Log files (including debug.log) — sanitize

**Consequence:** agent discipline: before sending a report, scan for sensitive
values. Credentials are passed in a separate terminal via env variables, not in chat.

---

### CC-4: Encryption via the `Cypher` class (Smaily password + rec-engine api-key)

**Context:** the plugin stores two sensitive credentials: the Smaily password and
the rec-engine api-key. Both must be encrypted at rest.

**Decision:** both are encrypted with the same `Cypher` class, stored in
`wp_options` with the **`autoload=false`** flag (doesn't go into the `alloptions`
cache — perf, and doesn't end up in mass dumps).

**Rationale:** the same pattern for both sensitive credentials means an easier
audit ("check that everything sensitive is Cypher-encrypted and `autoload=false`").
Phase 3 3.1 security checkpoint pins the cipher-string length in the DB.

---

### CC-5: Build marker (`buildHash`) against cache ambiguity

**Context:** the late-Phase-2 staging-bugfix cycle burned several iterations on
"is it a bug or stale cache?". The plugin was reinstalled several times to confirm
that staging was even running the right code.

**Decision:** the boot payload contains `buildHash` (git commit hash, with a
`-dirty` flag if the working tree is modified). A console check
`window.smailyConnectBoot.buildHash === "abc1234"` confirms in a second whether
the right code is running.

**Rationale:** without it all staging debugging is blind. ~5 lines of code, saves
hours.

**Generalization:** every future project (Shopify app, etc.) needs a marker like
this from day one.

---

### CC-6: wp-env + Chromium in the agent's environment (sees real WP)

**Context:** in late Phase 2 the agent fixed things blindly through staging — guessed
a fix, the human tested, broke, repeat. The alignment bug took 5 attempts.

**Decision:** Docker + wp-env (WP 6.9.4 + WC 10.7 + Polylang) + Chromium **in the
agent's environment**. The agent sees real WP itself, reads debug.log, reproduces
bugs.

**Rationale:** the backfill bug (missing DB table) would **never** have been
resolved through the staging cycle — it required real WP for `SHOW TABLES` to
expose what was missing. The first wp-env run resolved it.

**Rule for new projects:** if the agent is building something that runs in
environment X, the agent needs **access to environment X from day one**, not later.
Fixing blind is slow and inaccurate.

---

### CC-7: Mock server by default, live behind env flag (rec engine)

**Context:** the rec-engine setup token is **one-time use** (spec §7.1) — CI can't
run repeatedly with the same token. Every test would burn the token.

**Decision:** `RecEngineMockServer.php` (`php -S` locally) serves a spec-shaped
`/api/setup/exchange` + `/api/v1/ingest/*` mock. By default all integration tests
use the mock. **Live tests** against the real engine run only with the
`RECENGINE_LIVE=1` env flag (local manual confirmation + a Chromium walk before ZIP).

**Rationale:** the mock tests **plugin logic** (deterministic, CI-friendly), the
live call tests **compatibility** (against the contract). Different purposes, not
competing.

**Consequence + lesson:** the 3.1.2 path bug proved that **a mock can hide** a
real-engine mismatch when the mock is built around the same wrong assumption as the
plugin. **A live test is MANDATORY before ZIP** (not just "if we get to it").

---

### CC-8: Two-repo contract sync via embedded header note

**Context:** the API contract (`RECENGINE_API_CONTRACT.md`) lives in both the
plugin repo and the engine repo. The two copies must stay byte-for-byte
synchronized — and drift between them caused integration bugs in 3.1.2 (path
prefix) and 3.2.2 (endpoints-map keys). A separate `CONTRACT_SYNC.md` document
would introduce indirection (someone reads the contract, doesn't know it's
shared, doesn't think to look for a separate sync doc).

**Decision:** the sync process lives **in the contract document itself** — a
~10-line header note right after the title, documenting that the file exists
in two repos and the process for keeping them aligned.

**Rationale:**
- Information is where the reader is already looking (in the contract file).
  No need to know that a separate sync doc exists.
- New contributors on either side see the sync process the first time they
  open the contract — no tribal-knowledge erosion.
- Past drifts (`/api` prefix, `event_id` body coverage) are referenced in the
  note as concrete proof that the process matters.

**Alternatives rejected:**
- Separate `CONTRACT_SYNC.md` document — adds indirection without adding
  content. Reader has to know it exists.
- No formal documentation, "just talk in Slack" — relies on tribal knowledge,
  which the past drifts proved insufficient.
- Git submodule or a separate `smaily-recengine-contracts` repo — over-
  engineering for two consumers. Revisit when a third consumer (Shopify app,
  Milestone 3 on the plugin roadmap) arrives.

**Consequence:** the header note is implemented in both repos in the same work
session — first practical demonstration of the sync process. CI check that
verifies byte-identical content between repos is a backlog item (low priority,
triggered when a third drift occurs).

---

### CC-9: Single canonical path, not "canonical + legacy"

**Context:** when the engine implements a new contract behavior (e.g. W1
per-item Layer-2 dedup in Route A), the temptation is to retain the previous
behavior as "legacy backward-compat" alongside the new canonical path. This
creates two parallel paths in code, schema, and documentation. During W1
implementation, the engine team initially kept wrapper-level `event_id` dedup
as a legacy boolean-shape response alongside the new per-item integer-counts
path.

**Decision:** if there are **no prior clients** depending on the previous
behavior, remove it. Single canonical path is the right state.

**Rationale:**
- Less code to maintain (one Zod schema, one response shape, one dedup
  mechanism)
- Less documentation surface (one canonical path, not "canonical vs legacy"
  split that future readers have to disambiguate)
- Less test coverage spread (one path to validate end-to-end, not two)
- Less audit surface (one security and validation model)
- Less drift risk during ongoing work — when the canonical path evolves across
  Route A work items, only one path needs to keep up
- Less confusion for future developers ("why two shapes? what's the
  difference? when does each apply?")

**Concrete application:** in W1, wrapper-level `event_id` dedup is removed
entirely. Per-item is the only path. The plugin has always sent per-item
(`CatalogPayloadBuilder` places `event_id` inside each product object), so
removing wrapper-level doesn't affect the plugin — plugin is already aligned
with the canonical path.

**When this rule does NOT apply:** if a previous behavior has actual consumers
(existing customer integrations, deployed sister services, public API),
backward-compatibility may justify keeping a legacy path with a documented
deprecation window. Verify "no consumers" before applying this rule.

**Practical check before removing**: confirm with the human owner that no
clients depend on the to-be-removed path. The plugin-engine pair here had no
prior clients of the plugin ingest endpoints (production data flowed through
admin CSV upload, not plugin ingest), so wrapper-level had no consumer to
protect.

---

### CC-10: Upstream sync cadence — quarterly review of fork parent

**Context:** the plugin is a fork of `sendsmaily/smaily-wordpress-plugin`. The
upstream continues to receive commits — bug fixes, compat updates, occasional
security patches. The first upstream audit (`docs/audits/UPSTREAM_AUDIT.md`,
2026-06-03) found 14 commits accumulated since fork point, including 6 bug
fixes confirmed reproducible in our fork. Without a deliberate sync rhythm,
upstream fixes accumulate silently and the fork drifts further from a safe
reference.

**Decision:** review upstream commits on a **quarterly minimum** cadence,
with ad-hoc reviews triggered by:
- Security disclosures (CVE on WordPress / WooCommerce / a dependency)
- Major WordPress or WooCommerce releases (compat-fix likelihood)
- Pilot-client reports that match an upstream-fixed issue

**Process** (documented in `docs/audits/UPSTREAM_AUDIT.md` as a cumulative log):

1. `git fetch upstream`
2. List commits since the previous audit's HEAD: `git log <prev-audit-head>..upstream/main --oneline`
3. Categorize each commit:
   - 🔴 Security → cherry-pick (P0)
   - 🟠 Bug fix → cherry-pick if confirmed present in our fork
   - 🟡 Compat → discuss with project owner (versions, deprecations, etc.)
   - 🟢 Equivalent already in place → ignore, note in audit
   - ⚪ Not relevant → ignore, note in audit
   - 🔵 Style only → ignore (we have our own style)
4. For each 🟠 candidate, **verify the bug is present in our code** at the
   line level before recommending cherry-pick. Don't assume file existence
   means bug presence.
5. Audit document is committed first (pure audit, no code changes). Project
   owner reviews. Approved cherry-picks land in subsequent commits, each
   with `ci:strict` + integration tests passing.

**Rationale:**
- **Upstream bugs continue to affect our fork** until either (a) the affected
  legacy code is replaced, or (b) the fix is cherry-picked. Both are valid
  paths; the choice is per-feature, not blanket.
- **Audit-before-action** keeps the project owner in control of strategic
  drift — some upstream changes (PHP/WP minimum version bumps) intentionally
  conflict with our pilot-compatibility targets and must be rejected even when
  upstream considers them progress.
- **Cumulative log** in `docs/audits/UPSTREAM_AUDIT.md` (rather than ephemeral notes)
  means a new contributor or a future Claude session can read the entire fork
  history of sync decisions in one place.

**Phase 4 strategic question (deferred):** whether to retire the legacy
integration layer entirely (eliminates upstream dependency for those files) or
maintain coexistence (requires continued upstream sync). The Phase 3
architecture intentionally builds alongside legacy rather than replacing it
(F1-1); this question gets revisited when the new architecture is feature-
complete (post-Route-A, post-pilot).

**Consequence:** in the audit log, every cherry-pick decision is traceable to
a categorization and rationale. If a pilot regression later traces to a
known-but-unpicked upstream commit, the audit log shows whether that was an
explicit "ignore" decision or an oversight. Audit-first prevents both blind
adoption and silent omission.

---

## Phase 1 — Smaily-side infrastructure

### F1-1: Coexistence strategy (legacy + new side by side)

**Context:** the existing `sendsmaily/smaily-wordpress-plugin` (legacy
`Smaily_Connect\*` namespace) works and pilot clients use it. A new rewrite would
be **risky** (regressions) and **slow** (can't reuse the legacy WC integration).

**Decision:** the new `Smaily\Connect\*` namespace lives side by side with legacy
**in the same plugin**. Migration is gradual: new code takes features over one by
one, legacy stays for backward compat, gets removed in the Phase 4+ cycle.

**Rationale:** **reduces pilot-client risk** (the legacy path keeps working if the
new one breaks). Lets the new UI (React wizard) be built **in parallel** with the
existing WC integration.

**Alternatives rejected:**
- **Full rewrite as a new plugin** — risky, requires merchant handover
- **In-place refactor** — interrupts pilot-client work, high regression risk

**Consequence:** in late Phase 2 two bugs surfaced (2.H.4, 2.H.5) from the
`pre_update_option_smaily_connect_api_credentials` legacy hook crashing during new
REST saves. Fix: `REST_REQUEST` guard early-skip. **Phase 4 cleanup** replaces it
with a `current_filter()` / context-arg check.

---

### F1-2: DB Migrator + numbered SQL migrations (not one schema file)

**Context:** the plugin needs its own DB schema (event queue, backfill job,
automation mapping, rec event queue, rec visitor). It expands over time.

**Decision:** a `DB Migrator` class + numbered SQL migrations
(`0001_event_queue.sql`, `0002_...`, ...). The activation hook runs any missing
ones. Version stored in `wp_options` (`smly_plus_schema_version`).

**Rationale:** lets the DB schema **expand version by version** without redefining
the whole schema. The activation hook is idempotent (existing migrations are
skipped).

**Consequence + lesson (2.H.7):** `dbDelta` is **sensitive** — comments (`--`)
caused a column-recognition error and the table wasn't created. SchemaMigrationTest
(Phase 3 3.0) now pins table existence after activation.

---

### F1-3: Multilingual adapter pattern (WPML/Polylang/TranslatePress/SiteLocale)

**Context:** the WP ecosystem has **4 different** multilingual plugins, each with
its own API. The plugin must detect the language regardless of which one is active.

**Decision:** a `MultilingualAdapter` interface + 4 concrete adapters
(`WpmlAdapter`, `PolylangAdapter`, `TranslatePressAdapter`, `SiteLocaleAdapter`).
`EnvDetector` picks the active one, the plugin queries language through the adapter
API.

**Rationale:** **a new multilingual plugin only needs a new adapter**, not
`if (is_wpml()) ... elseif (is_polylang()) ...` across the plugin code. The adapter
pattern is the standard solution when runtime polymorphism isn't available natively.

**Alternatives rejected:**
- Support only one (Polylang) — shrinks the client base
- if-elseif at every multilingual touch point — grows the surface area to control

---

### F1-4: Action Scheduler over WP-Cron

**Context:** the plugin needs background processes (backfill, event flush, retries).
WP-Cron is WordPress's built-in, BUT it's **request-driven** (relies on visits) —
slow/irregular on a low-traffic site.

**Decision:** Action Scheduler (already a dependency through WC anyway, small
addition). It gives us:
- Real async (not visit-dependent)
- Retry mechanism built in
- Admin UI (Tools → Scheduled Actions) for observability

**Rationale:** **the dependency is already there** (Smaily Connect requires WC), AS
is production-grade and a debug tool exists.

**Consequence:** Phase 3 IngestFlusher uses an AS callback, retry logic is at the
AS level. Client-level retries are lowered (1-2 attempts) to avoid blocking the
AS worker with a 31-second in-request backoff.

---

### F1-5: Magic-link auth (not a password) for the wizard

**Context:** the plugin wizard needs user authentication against Smaily during
setup.

**Decision:** magic link via email (not a password). The same pattern as
Tuduaeg.ee.

**Rationale:** **UX** — the user doesn't have to remember a password. **Security**
— no password to compromise. **Smaily support** — the Smaily API supports magic-link
generation.

---

## Phase 2 — React wizard + Settings UI

### F2-1: React + Vite + Tailwind, not native WP-admin

**Context:** the wizard and Settings UI need to be modern, mobile-friendly, fast.
Native WP-admin (PHP render + jQuery) is slow to develop, hard to test, has a dated
look.

**Decision:** **React + Vite + TypeScript + Tailwind** for the whole UI. PHP only
renders the mount point + boot payload; React takes over.

**Rationale:**
- **TypeScript** — compile-time error checking, indispensable for a large UI
  codebase
- **Vite** — fast build, hot reload in development
- **Tailwind** — utility-first, consistent style system, no per-component CSS files
- **React** — component-based reuse, state management (reducer), Vitest tests

**Alternatives rejected:**
- Native WP-admin — slow, dated, hard to test
- Vue/Svelte — less common, fewer WP-ecosystem examples

---

### F2-2: Custom 14 primitives, not a UI library (shadcn/ui or similar)

**Context:** the Smaily brand style is its own (pink + dark navy). Third-party UI
libs (Material, Ant, Chakra) don't fit visually.

**Decision:** **14 custom primitive components** (Button, Input, Card, PillTab,
StepRail, etc.) in Tailwind style, matching Smaily brand colors and typography.

**Rationale:** **brand consistency** + **smaller bundle** than shadcn/ui (which
would pull in all of Radix-deps) + **full control** over UX details (animations,
focus states).

**Consequence:** the layered-input pattern (native `<input>` with `opacity:0` plus
a custom span on top) resolved the Phase 2 alignment bug (WP core `load-styles.php`
margin -0.25rem beat Tailwind by specificity).

---

### F2-3: Wizard-first architecture (`setup_completed` flag)

**Context:** Settings and the wizard are **two** edit UIs for the same config. A
user could open Settings directly and skip the wizard — but that assumes they know
what to configure at each step.

**Decision:** Settings is reachable **only** after the wizard's Finish
(`smly_plus_setup_completed = true`). Before Finish: a Settings URL **redirects to
the wizard**.

**Rationale:**
- **Onboarding design** — the user gets a guided experience before "100 settings"
  intimidation
- **Fewer support questions** — the wizard ensures everything required is
  configured, not "why doesn't it work?"
- **Settings = editing after onboarding**, not initial setup

**Alternatives rejected:**
- Settings always open — confuses a new user ("where do I start?")
- Wizard only on first install, hidden later — loses "Re-run wizard"

**Consequence:** `uninstall.php` (sub-PR 2.J) **must** remove
`smly_plus_setup_completed`, otherwise a reinstall doesn't trigger the wizard
(discovered in Erkki's staging test).

---

### F2-4: Progressive disclosure in Settings

**Context:** Settings has 5 tabs (Connection, Subscribers, WooCommerce,
Recommendations, Integrations). Not all are always relevant — Subscribers/WC/Rec
**require a Smaily connection**.

**Decision:**
- **Connection is always visible**
- **Subscribers/WC/Rec are locked** until `smailyConnection === 'success'`, with a
  banner "Smaily connection required"
- **Integrations is always visible** (info-only, doesn't require a connection)
- **Hash deep-link to a locked tab** bounces to Connection

**Rationale:** **reduces confusion** — the user doesn't see locked tabs they have
no context for. Natural flow: connect → configure (tabs unlock).

---

### F2-5: Continue saves every step (not just Finish)

**Context:** originally the wizard Finish was a **stub** — `redirect to Settings`,
no save. We discovered that all wizard flow data was lost, because nothing was
saving.

**Decision:** **every Continue click POSTs its step's payload** before navigating
to the next step. Finish gets simpler: it only sets `setup_completed = true` (all
data is already saved).

**Rationale:**
- **Resilient to interruption** — the user can close in Step 3 → Steps 1-2 are
  saved → on return they continue from Step 3
- **A simple mental model** — "this step is done"
- **Step 1 credentials save immediately** — Step 3 workflows-fetch can use them
  (if saving were on Finish, Step 3 wouldn't see the credentials)

**Alternatives rejected:**
- Save only on Finish — everything is lost on cancel
- Save on every field change — many network requests, bad perf, race conditions

**Consequence:** the wizard and Settings have **different save patterns**: the
wizard does Continue-save (linear onboarding), Settings does Save/Discard per tab
(editing). Both are right in their own context. The shared `buildTabPayload`
keeps the logic in one place.

---

### F2-6: Mode A/B/C multilingual (account mapping)

**Context:** a multilingual WP site can have a **separate Smaily account per
language** (Mode A), **one Smaily account for all languages** (Mode B), or a
**hybrid** (Mode C — a default account plus per-language overrides).

**Decision:** three explicit modes, the user picks one in wizard Step 1. The
backend resolves with an `accountKey` parameter for "which account for this data":
- Mode A: `accountKey = language-code` (`et`, `en`)
- Mode B: `accountKey = 'default'`
- Mode C: `accountKey = 'default'` + per-language override map

**Rationale:** **flexibility** — different Smaily merchants are organized
differently. Estonian market typically Mode A (separate campaigns per language),
larger markets typically Mode B (one brand account).

**Consequence:** `EnvDetector::accountKey($language)` resolves the mode logic, so
backend call sites query it rather than each doing their own if-elseif.
Consolidated in the `Settings\Credentials` value object.

---

### F2-7: Field-mapping standard — Smaily's official convention is canonical

**Context:** the plugin syncs WC user fields to Smaily (First name, Phone, Gender,
etc.). Every Smaily field has a **field name** — this must be **consistent** across
the WC plugin / Shopify app / rec engine, so that the same contact from different
sources doesn't produce duplicated fields.

**Decision:** the **Smaily official WC plugin's
(`sendsmaily/smaily-woocommerce-plugin`) convention is canonical**. We use its
field names: `first_name`, `last_name`, `store`, `user_phone`, `user_gender`,
`birthday`, `customer_id`, `customer_group`, `first_registered`, `site_title`,
`nickname`. Documented in `FIELD_MAPPING.md`.

**Rationale:**
- **Backward compat** — the pilot client's existing Smaily templates
  (`{{ first_name }}`) keep working
- **Cross-platform consistency** — WC + Shopify + rec engine speak the same field
  language
- **Upstream compatibility** — our plugin is a fork of the official one, syncing
  is easier

**One subtle detail:** `user_phone` and `user_gender` keep the `user_*` prefix
(even though `first_name` doesn't) — backward compat with WP-meta names and with
the pilot client's existing segments and templates. Compatibility wins over
consistency.

**Platform-specific fields are namespaced:** `wc_*` (WC-only), `shopify_*`
(future), `rec_*` (rec-engine derived). Cross-channel fields (first_name) are
shared, platform-specific fields are isolated.

---

### F2-8: Mode A "default account" logic

**Context:** in Mode A the user configures language-specific accounts (et, en).
But **what happens** when WC produces a contact without language information (e.g.
through a non-multilingual plugin)?

**Decision:** Mode A always has **one default account** (e.g. the first one
defined), and "language-less" contacts go there. A spec-error discovered through
Erkki's domain walkthrough — initially the agent assumed every contact must end
up in a language-specific account.

**Rationale:** **commercial reality** — WC doesn't always carry language context
(admin-created contacts, imported contacts, non-multilingual scenarios). Without a
fallback, contacts would silently disappear.

---

### F2-9: Layered-input pattern (checkbox/radio alignment)

**Context:** WP core `load-styles.php` sets a rule
`input[type=checkbox],input[type=radio] { margin: -0.25rem }`. This **beat
Tailwind by specificity**, breaking the plugin UI (checkboxes were offset).

**Decision:** the **layered-input pattern** — the native `<input type="checkbox">`
is `opacity:0; position:absolute` (hidden, but functional for accessibility), with
a custom span next to it for the visual checkbox (Tailwind style). A click on the
span triggers the native input's focus + click events.

**Rationale:** to **bypass WP-core CSS specificity** without `!important`.
**Accessibility is preserved** (screen readers see the native input). The visual
is under full control.

**Consequence:** the pattern is documented in `STYLE_MAPPING.md`, used for all
checkboxes/radios in the plugin.

---

### F2-10: Empty sync values are omitted (absent != empty)

**Context:** if a WC user has no `birthday`, how does the plugin send it to
Smaily? An empty string (`birthday: ""`) would erase the existing value in Smaily.
`null` may be ignored by the engine.

**Decision:** an **empty source value is omitted from the body** (the field is
**not** in the payload). Smaily semantics: absent (no field) != empty (the field
is there but blank).

**Rationale:**
- **Preserves existing data in Smaily** on re-sync (if the data disappeared on the
  WC side due to a bug, it isn't erased in Smaily)
- **Smaily's official convention** — the same pattern used by Smaily and other
  plugins

**Consequence:** the same logic carries into Phase 3 rec-engine PayloadBuilders
(CatalogPayloadBuilder omits empty `description`/`image_url`).

---

## Phase 3 — rec-engine integration

### F3-1: Integration test baseline BEFORE features (sub-PR 3.0)

**Context:** the late-Phase-2 staging-bugfix cycle burned ~20 iterations on
integration-boundary bugs (route 404, save/read mismatch, dbDelta crash). Phase 3
adds 10 new REST endpoints = 10× that risk.

**Decision:** **before any rec feature**, build a `tests/Integration/` suite in
real WP wp-env:
- `RestRouteRegistrationTest` — all endpoints register
- `SchemaMigrationTest` — tables + indexes exist
- `SettingsRoundTripTest` — read/write symmetry
- `BuildHashTest` — boot payload is correct
- `DebugLogCleanTest` — no PHP Fatal/Warning from our code
- `RecEngineConnectivityTest` — conditional (env flag)

**Rationale:** **every test references a concrete Phase 2 bug class** — investing
~1 day in 3.0 saves a week in Phase 3.

**Consequence:** must-pass CI gate (not informational) — avoids the "nobody checks
the green light" pattern.

---

### F3-2: EndpointRegistry declarative route list

**Context:** the Phase 2 backfill bug (2.H.7) happened because Bootstrap **forgot**
to register an endpoint. The test didn't catch it (because the test forgot too).

**Decision:** `EndpointRegistry.php` is a declarative route list. **Bootstrap loops
over it**. **The test reads the same list** (RestRouteRegistrationTest).

**Rationale:** **route 404 becomes structurally impossible**, not just "tested for."
One list, two consumers. If someone adds an endpoint to the registry but forgets to
update the Bootstrap code → a **compile-time contradiction**, not a runtime 404.

**Note:** the best architectural step in Phase 3. **Generalizes** to all shared
structures — when two places need to be in sync, **declare in one place** and have
both read from it.

---

### F3-3: Rec engine public + api-key auth (not Vercel SSO)

**Context:** the rec engine is on Vercel, by default behind **Deployment
Protection** (SSO wall). The pilot client can't bypass SSO (a machine client, not
a human).

**Decision:** rec engine production = **No Protection**. Security shifts to the
**api-key layer** (setup token → api-key exchange). Every endpoint requires
`Authorization: Bearer <api_key>`, except `POST /api/setup/exchange` (which uses a
one-time setup token).

**Rationale:** **the correct machine-auth approach** — same pattern as Smaily
(subdomain + username + password). Vercel SSO is a dev-protection (human login),
not a plugin-auth mechanism.

**Consequence:** a critical security ordering — **api-key auth must be enforced
BEFORE No Protection is turned on**, or there's a "public + open" window.
Documented in RECENGINE_TODO.md P0 (engine-team work item).

---

### F3-4: Setup-token UI = full URL, not just the token

**Context:** Step 4 UI needs a setup token. Two variants: the user pastes (a) just
the token or (b) the full URL `https://intelligence.smaily.com/setup/<token>`.

**Decision:** **the full URL**. The plugin parses it: host = base_url, what comes
after `/setup/` = token.

**Rationale:**
- **Less user error** — copying a whole URL is easier than slicing out a token
- **base_url isn't a CONST in code** — staging vs prod URL without a plugin update
- **Flexible** — the engine could one day move (new Vercel project, custom domain)

**Alternative rejected:** just the token + a CONST base_url — a hardcoded base_url
would break staging/prod switching and would require a plugin update on an engine
move.

---

### F3-5: Path constants centralized (Client.php `PATH_*`)

**Context:** sub-PR 3.1.2 path bug — the plugin called `/setup/exchange` (wrong),
the engine expected `/api/setup/exchange`. The ping path was already correct
(`/api/v1/ingest/ping`), but setup-exchange was wrong. **Inconsistency** — paths
weren't centralized.

**Decision:** **10 path constants** in `Client.php` (`PATH_SETUP_EXCHANGE`,
`PATH_PING`, `PATH_INGEST_*`, etc.). All plugin↔engine URLs from one place. No
inline strings.

**Rationale:** **resolves the path-mismatch pattern for good** — everything follows
the same `/api` pattern. 3.2+ ingest endpoints use the constants, not inline strings.

**Lesson (LESSONS.md §2.4):** the mock hid the path bug because the mock was built
around the same wrong assumption as the plugin. **A live test is the only thing
that catches a mock↔engine mismatch.**

---

### F3-6: Endpoints map preferred over constants (engine = source of truth for paths)

**Context:** the plugin gets an `endpoints` map from setup-exchange — engine-returned
URLs for every endpoint. The plugin could ignore it (use hardcoded `PATH_*`) or
prefer it.

**Decision:** the plugin **prefers the engine-returned endpoints map** over
constants. `RecEngineSettings::endpoints()['ingest_catalog']`, falling back to
`Client::PATH_INGEST_CATALOG` only when the map is empty (pre-setup-exchange).

**Rationale:**
- **Engine = source of truth for paths** — the right direction of dependency in a
  two-system design
- **Engine path migrations don't require plugin updates** — if the engine moves
  `/ingest/catalog` elsewhere, the plugin follows automatically
- **Fallback is a safety net** — if the map is empty (an early call before
  exchange), the plugin still works

---

### F3-7: Variant A idempotency (uuid on every ingest endpoint)

**Context:** the plugin event queue (`smly_rec_event_queue`) is **uuid-based
anyway** (migration 004 enforced `event_uuid NOT NULL UNIQUE`). Two options for
engine deduplication:
- (A) uuid on every endpoint (browse + catalog + customers + orders) — uniform
- (B) uuid only on browse, the rest use natural-key UPSERT (sku, email,
  external_order_id)

**Decision:** **Variant A** — uuid everywhere. The wire field name is `event_id`
(per the spec §6 browse pattern), the plugin side is `event_uuid` (queue column);
`PayloadBuilder` converts between them.

**Rationale:**
- **Queue model = wire model** uniformity (B would create a divergence)
- **Engine async pipeline race protection** — natural-key UPSERT races on async
  processing
- **Audit trail** — the engine can trace exactly which plugin event matched which
  engine row
- **Plugin-side genericity** — the same `PayloadBuilder` pattern for
  catalog/customers/orders

**Defensive layer (not XOR):** `event_id` is **an extra layer on top of natural-key
UPSERT**, not a replacement. The plugin sends `event_id` → engine uuid-dedup. The
plugin doesn't send it (old version) → engine natural-key UPSERT. **Both work.
Backward compat.**

**Per-item location in the body:** `{"items":[{"event_id":"...","sku":...}]}`,
not top-level wrapper. **Live-verified** in sub-PR 3.2.4 W/P diagnostic test
against the deployed engine (Variant P retry returned `{"deduplicated": 1,
"deduplicated_all": true}`; Variant W still returns the legacy boolean
`{"deduplicated": true}` until W1 wrapper-level removal completes per CC-9).

**Note on the 6/6 sanity test correction:** the engine team's pre-3.2 "6/6
sanity tests passed" report was retrospectively found to test wrapper-level
event_id (which Zod accepted) rather than per-item (which Zod silently
stripped). The actual per-item Layer-2 implementation arrived in Route A W1
(after the 3.2.4 plugin live test surfaced the silent-strip behavior). This is
why 3.2.4 live testing matters and why mock-vs-deployed must be validated for
spec examples too (LESSONS.md §2.4 applied to specs, not just code).

---

### F3-8: Sub-PR 3.2 scope = variant 3 (infra + catalog end-to-end)

**Context:** 3.2 could have been (1) all 3 endpoints together, (2) split into 3
sub-PRs, (3) only catalog + infra.

**Decision:** **variant 3** — IngestQueue + Flusher + HookHandler + AS jobs +
catalog end-to-end. Customers + orders in 3.3 (same pattern).

**Rationale:**
- **3.2 builds NEW infra** (first rec-engine data-ingest machinery) — first time =
  highest risk
- **Catalog end-to-end exercises the whole machine with ONE endpoint** — if an
  infra bug surfaces, it's isolated to the catalog path
- **Customers + orders are "the same pattern"** — 3.3 lands quickly after the
  catalog confirmation

**Alternative rejected:** all 3 together — would make it harder to isolate an
infra bug (is it in infra or in a specific endpoint?).

---

### F3-9: Mock for determinism, live for compatibility

**Context:** the rec-engine setup token is one-time → CI can't repeatedly run live
tests.

**Decision:** (see CC-7 above — cross-cutting). 3.2-specific: `RecEngineMockServer`
extended to catalog/customers/orders/browse endpoints. The live test
(`RECENGINE_LIVE=1`) uses the real api_key against the MiuMjau tenant.

**Consequence:** the mock server must **match the spec exactly** (same response
shape as the engine). LESSONS.md §2.4 pattern: if the mock diverges, integration
goes green but production breaks.

---

### F3-10: Layered Client retry strategy

**Context:** any HTTP call can fail (429, 5xx, network outage). Where to retry?

**Decision:** **two-layered retry**, each layer with its own context:
- **Setup-exchange + ping** (synchronous, user is waiting): Client does a full
  exponential backoff (1+2+4+8+16=31s, max 5 attempts, honoring Retry-After)
- **Ingest flow** (AS-job context): Client max-attempts **1-2**, row-level retry
  goes through the queue table's `next_retry_at` + AS re-tick

**Rationale:** an AS-job 31s block per failing call would **lock the AS worker**
the whole time → other jobs (email side) wait. The queue table is already a retry
mechanism (`next_retry_at`, `max_attempts`) — a Client-level retry would duplicate.

**Consequence:** retry logic depends on the Client constructor parameter (Flusher
passes `max_attempts=1`, others use the default 5).

---

### F3-11: api-key encrypted + never reaches the browser (proxy ping)

**Context:** the plugin stores the rec-engine api-key. The Step 4 UI needs a
"Test connection" button. Option (a) — React fetches the engine directly (api-key
in localhost). Option (b) — React calls a plugin REST endpoint, the plugin makes
the engine call server-side, React only sees the result.

**Decision:** **proxy ping**. React → `POST /wp-json/smaily-connect/v1/rec-engine/ping`
→ the plugin decrypts the api-key → calls the engine → returns status.

**Rationale:**
- **The api-key DOES NOT leak to the browser** — encrypted in DB, plain only in
  server-side memory
- **The boot payload `recEngine` block contains NO apiKey** — only tenant-display
  (connected, tenantName, engineVersion, etc.). React doesn't need the api-key.

**The security checkpoint** in 3.1 pinned all four: cipher-string in DB, setup
token NOT in `wp_options` after exchange, boot payload contains NO apiKey,
endpoints 403 without capability.

---

### F3-12: EnvSeed goes through the real `store(ExchangeResult::success())` path

**Context:** integration tests need a "connected" state as a fixture. One option —
direct SQL into the DB. Another — use the plugin code itself
(`RecEngineSettings::store()` with mock data).

**Decision:** **the plugin-code path** (`store(ExchangeResult::success(fixture-data))`).

**Rationale:**
- **Read/write symmetry is automatic** — the fixture goes through the same code
  path as the real setup-exchange, so if `store()` ever forgot a key, the
  fixture-test would expose it
- **Wp-env DB wipe protection** — the fixture is regenerated on every test-suite
  run, doesn't depend on persisted DB (LESSONS.md §2.6)

---

### F3-13: Systematic spec drift uncovered in 3.2.4 — Route A selected

**Context:** sub-PR 3.2.4 live testing surfaced a wrapper-key drift
(`products` → `items`), which triggered a broader engine-team audit. The audit
discovered the drift was systematic: the contract document was authored
aspirationally, the engine was scaffolded at commit `4ee73b1` with a narrower
implementation, and the two never converged. 6 drifts confirmed across catalog
(items wrapper, category_path non-empty, event_id location, dedup-not-impl)
and at minimum customers + orders (smaily_contact_id required, single-object
not batch, multiple silent-dropped fields).

**Decision:** **Route A** — engine grows to match spec; plugin design is
preserved. Production data for the MiuMjau pilot flows through admin CSV
upload, not plugin ingest, so engine convergence work doesn't disrupt the
pilot's existing data. After Route A complete, pilot migrates from CSV to
plugin sync (D4 in Route A plan v2).

**Rationale:**
- **Plugin design is built on spec** — variant A idempotency (F3-7), batch
  IngestQueue + Flusher (F3-8), per-item event_id (F3-7) are all aligned to
  spec. Route B (spec shrinks to engine reality) would force a plugin-side
  rewrite of design decisions made carefully across 3.0-3.2.4.
- **Pilot day-one capability matters** — campaign-link attribution requires
  `product_url`; sale-aware recommendations require `compare_price`. Route B
  would strip these from the plugin spec, reducing pilot business value.
- **Spec is the contract** — the spec was always v1.0.0; the engine never
  realized it. Route A "realizes" the contract rather than redefining it.

**Alternatives rejected:**
- **Route B** — spec shrinks to engine — loses pilot business value
- **Route C** — hybrid (pilot-critical engine builds, nice-to-have spec
  shrinks) — adds categorization overhead and creates "spec says X, engine
  does X minus N" ambiguity for future contributors

**Strategic decisions locked** (Route A plan v2):
- D1: Smaily UID = email (no `smaily_contact_id` required)
- D2: Variant 1 price semantics (`price` = current, `compare_price` =
  struck-through reference)
- D3: No feature-flag for W4 (clean one-way migration)
- D4: MiuMjau migrates to plugin sync, CSV retired (post-Route-A)
- D5: No pilot-live deadline pressure (quality before speed)

**Consequence:** plugin pause from 3.3 until W4 + W5 of Route A complete
(~2-3 weeks engine work). Plugin's 3.2.4 catalog-end ZIP is produced
independently (W1 already complete). The 6 contract drifts and their
resolutions become CHANGELOG entries on both repos.

---

### F3-14: Mock-vs-live applies to spec documents, not just code

**Context:** the 3.2.4 audit found that the contract document's example
payloads had never been validated against the deployed engine. The 6/6 sanity
test report from the engine team tested wrapper-level event_id (which Zod
accepted) and read as "Layer-2 works" without verifying per-item (which Zod
silently stripped) — the same pattern as the 3.1.2 mock-server divergence,
but applied to specifications rather than code.

**Decision:** specifications must be **live-validated** with the same
discipline as code. When a spec section is authored or revised:
1. Each example payload is run as `curl` against the deployed engine
2. The actual response (status + body) is pasted into the commit message or
   review report
3. Only after live-validation does the spec change get committed
4. Mock servers are then aligned to the live-validated behavior, not to spec
   text

**Rationale:**
- The LESSONS.md §2.4 pattern ("mocks reflect your assumptions, not reality")
  applies to specs too — they're another form of "writing down what you think
  the system does." Specs can drift from reality the same way mocks can.
- 4 (now 6) instances of mock-vs-real drift demonstrated the cost; the
  contract document drift made it 7. Pattern justifies the discipline.

**Consequence:** Route A's cross-cutting process (in plan v2) includes
"deploy-validate every spec example before commit." Plugin team applies the
same on its side when documenting plugin-internal APIs.

---

### F3-15: Single canonical event_id path — wrapper-level removed (Route A W1)

**Context:** see CC-9. The W1 implementation initially kept wrapper-level
`event_id` dedup as a legacy boolean-shape path alongside the new per-item
integer-counts canonical path. With no prior clients on plugin ingest
endpoints (production flowed through admin CSV upload), backward-compat is not
a constraint.

**Decision:** wrapper-level event_id support is removed entirely. Per-item is
the canonical and only path. Single Zod schema, single response shape, single
dedup mechanism.

**Rationale:** see CC-9 (less code, less docs, less audit surface, less drift
risk). The plugin already sends per-item via `CatalogPayloadBuilder`, so
removing wrapper-level has zero plugin-side impact.

**Live verification (3.2.4 walk):** per-item Variant P retry against deployed
engine returns `{"deduplicated": 1, "deduplicated_all": true}` (W1 working as
designed). The W/P diagnostic test stays in `walk-3.2.cjs` as structural drift
protection — if a future change re-introduces wrapper-only behavior or strips
per-item, the walk catches it immediately.

---

### F3-16: Catalog-end milestone — sub-PR 3.2.4 complete

**Context:** sub-PR 3.2.4 completes Phase 3 catalog-ingest. Plugin and engine
are aligned on catalog-end through the canonical per-item Layer-2 dedup, all
fields the engine accepts are populated correctly by the plugin's
`CatalogPayloadBuilder`, and the variant-product expansion works end-to-end.
Catalog ZIP is produced (`smaily-connect-2.0.0-beta.1-a75f096.zip`).

**Decision:** catalog-end is the **canonical reference** for Phase 3 ingest
implementation. Subsequent ingest sub-PRs (3.3 customers, 3.3 orders, 3.4
browse, 3.5 backfill) follow the same architectural pattern:
1. `PayloadBuilder` maps plugin domain → engine wire shape
2. `Client::ingest_*` sends to the deployed engine endpoint
3. `IngestQueue` carries `event_uuid` per row, dedup-protected
4. `IngestFlusher` AS-job sends batches with per-item event_id
5. Mock server matches deployed engine response shape (after live verification)
6. Walk script proves end-to-end against deployed engine before ZIP

**Live-verified scenarios (14/14):**
- Upsert end-to-end (hook → queue → flusher → engine)
- Resend idempotency (Layer-1 UPSERT + Layer-2 dedup)
- Batch 100 products → `processed: 100`
- Variable product → separate event_uuid per variation (2/2 sent)
- Delete → `catalog.delete` event reaches engine
- Partial-success → engine returns `400` for whole batch (all-or-nothing)
- No event_id → Layer-1 natural-key UPSERT (spec §7 backward compat)
- Wrapper Variant W and per-item Variant P diagnostic (W/P-flip captured)

**Consequence:** 3.3 customers + orders can mechanically follow the catalog
template once Route A W4 + W5 complete. The architectural decisions are
proven; the work is "fill in the per-endpoint mappings, apply the same
pattern, live-verify before ZIP."

---

### F3-17: Required-if-always-sent principle (W2 application)

**Context:** during Route A W2 (catalog field expansion), the engine team
flagged that `product_url` and `in_stock` were marked required in spec §3
but the engine Zod schema was lenient (`optional()` and `default(true)`
respectively). They asked whether plugin always sends these — if yes, tighten
the engine to match the spec; if not, loosen the spec to match the engine.

**Decision:** if the plugin can **always** send a field (no conditional skip
path, no edge case), the engine **should require** it. Defaults hide drift.

**Rationale:**
- **One truth, no silent fallbacks** — sister principle to CC-9 (single
  canonical path). A field that's "required in spec, defaulted in engine" is
  the same anti-pattern as "canonical in spec, legacy in engine": readers
  see two stories.
- **Loud failure over silent guess** — if `product_url` is missing for any
  reason, a 400 surfaces the underlying problem (e.g., a custom plugin broke
  `get_permalink()`); a silent `product_base_url + sku` fallback hides it
  until customer-facing email rendering goes wrong.
- **Plugin verification is cheap** — `CatalogPayloadBuilder` is auditable in
  seconds (unconditional array-literal block, doc-comment states "REQUIRED
  fields are always present"). The "always sent" claim is mechanically
  verifiable, not a hopeful assumption.

**Concrete application (W2):**
- `product_url`: required + non-empty (mirrors `category_path` strictness)
- `in_stock`: required (always a real boolean from `is_in_stock()`)
- Engine commit `967e142` tightened the schema; spec commit `645c4fa` synced
- Live-validated: missing → 400, empty string → 400 (product_url), both
  present → 200

**When this rule does NOT apply:** if the plugin has a legitimate conditional
skip path (e.g., a field that depends on plugin configuration the user can
disable), keep optional. The rule is "can the plugin always send" not
"should the plugin always send."

---

### F3-18: Per-item `errors[]` batch contract (D6) — decided

**Context:** with W2's all-or-nothing catalog validation, a single invalid
product in a 100-row batch fails the whole batch with 400, and the plugin
marks all 100 rows `mark_failed` — conflating 1 poison row with 99 healthy
ones. When the engine team raised the customers batch-error contract (Q1
before W4), this became a cross-team decision: should batch ingest be
all-or-nothing or per-item partial-success?

**Decision:** **per-item `errors[]` is the canonical batch-ingest contract,
unified across all batch endpoints** (engine-side decision D6). The engine
processes the good records, returns 200 with an `errors[]` array listing the
rejected ones. The plugin's flusher parses `errors[]` and splits the batch:
healthy rows → `mark_sent`, rejected rows → `mark_failed` / `record_attempt`.

**Canonical D6 response shape:**
```json
{
  "ok": true,
  "processed": 28,
  "deduplicated": 1,
  "errors": [
    {"index": 3, "email": "bad-email", "field": "email", "message": "Invalid email"}
  ]
}
```
Invariant: `processed + deduplicated + errors.length == total`. Error object:
`{index, <natural_key>?, field, message}` — `index` is batch position
(maps directly to the flusher's index-aligned `batch_rows[index]`), natural
key (`email`/`sku`/`external_order_id`) included when available, omitted for
browse (no Layer-1 natural key). Same shape on every endpoint, so the flusher
logic is identical regardless of endpoint.

**Rationale:**
- **One mental model, one code path** — a developer learns one error contract,
  not "catalog behaves one way, customers another." The flusher handles the
  same `errors[]` shape for every endpoint. Application of CC-9 (single
  canonical path) to error handling.
- **Partial-success serves transactional endpoints** — customers and orders
  are larger, more variable batches (backfill sends thousands; email data has
  more edge cases than product SKUs). One bad record shouldn't block 99 good
  ones.
- **Catalog all-or-nothing was an artefact, not a requirement** — the engine
  team audited it and confirmed: it came from whole-array `safeParse` + the
  W1 dedup transaction, not an atomicity need. No reason to keep it.

**Plugin-side cost (Code audit):**
- `ApiException`: small (~5-10 lines) — the response body is already
  JSON-decoded in `Client::request_url` and passed to the constructor; it
  just discards everything except `request_id`/`error_code`/`message`.
  Preserving `details`/`errors` is a small getter addition. **This is now a
  3.3 requirement, not a future candidate.**
- Flusher: medium — catch the return value, parse `errors[]`, map
  `index → queue-row`, split `mark_sent` vs `mark_failed`. Favorable fact:
  the flusher already builds `$products` and `$batch_rows` as index-aligned
  parallel arrays, so `errors[].index → batch_rows[index]` is a clean mapping.

**Sequencing (engine-side):**
- **W4 customers** is built to D6 from the start — the first endpoint to
  implement per-item `errors[]`, defining the canonical HTTP shape.
- **W5 orders** built to D6 when it lands.
- **Catalog + browse retrofitted together** (engine work item N-7) from
  all-or-nothing → per-item `errors[]` after W4 — proving the shape on a
  fresh build (customers) before touching the already-synced older endpoints.

**Correction to earlier framing:** browse was mis-categorized as "cleanest /
already per-item" in `docs/audits/MOCK_DIVERGENCE_AUDIT.md`; it is also all-or-nothing and
needs the same retrofit as catalog. The only existing partial-success
reference is the admin CSV path (`commitCatalog` → `import_errors`), not the
HTTP endpoints. (Audit doc to be corrected.)

**Alternatives rejected:**
- **All-or-nothing everywhere** (catalog's W2 model) — simpler engine
  validation, but conflates 1 bad row with N in the audit log, and forces a
  degraded per-row-retry fallback (100x HTTP) on the plugin to isolate the
  culprit. Per-item `errors[]` gives precise isolation in one request.
- **Per-endpoint inconsistency** (catalog all-or-nothing, customers per-item)
  — rejected after the consistency question: if per-item is better, apply it
  everywhere; two contracts means two mental models for no benefit.

---

### F3-19: Customers-end milestone — sub-PR 3.3 complete

**Context:** 3.3 customers ingest completes the second Phase-3 ingest endpoint,
following the F3-16 canonical 6-step pattern (PayloadBuilder → Client →
IngestQueue → Flusher → mock → live-walk). It is the first endpoint built to
the D6 per-item contract (F3-18) from the start.

**Decision:** customers-end is the **canonical D6 reference** for orders (W5)
and the catalog + browse N-7 retrofit. The `errors[].index → batch_rows[index]`
split in CustomerFlusher is the pattern those endpoints copy.

**Live-verified (walk-3.3, 10/10 against the real MiuMjau engine):** connected,
upsert end-to-end, Layer-2 `event_id` dedup (`deduplicated_all` on resend), D6
partial success (`{processed:1, errors:[{index:1, field:email}]}`), the
`processed+deduplicated+errors==total` invariant, batch all-sent, the
`customers` wrapper, and the builder's absent-not-empty omission (no nulls on
the wire). The walk caught a real datetime bug (→ F3-21).

**Consequence:** orders (W5) mechanically follows. Customers ZIP produced. The
chain is live-wired (hooks enqueue, AS schedules the flusher). **ZIP ≠
pilot-go-live** — like catalog-end, it's a proven artefact; the pilot installs
after W5.

**Known follow-ups (plugin-side N-7 + housekeeping):**
- **✅ Catalog-flusher D6 consolidation — RESOLVED in N-7.1 (see F3-23).** The
  catalog IngestFlusher now extends `AbstractD6Flusher`; an engine per-item
  rejection marks that row FAILED, not SENT (silent-loss class closed, lock
  lifted). Proven live (`flusher_d6_split_lock_proof`).
- **EVENT_* constant asymmetry (N-7).** Catalog event constants live on
  CatalogHookHandler, customer/order's on their Flusher. **Intentionally kept**
  after N-7 — the chosen abstract-base design (not a monolithic dispatcher) gives
  each flusher its own constants; the "dispatcher" that would have unified them
  didn't happen (F3-23). Cosmetic; defer or drop.
- **Flaky test.** `admin/src/hooks/useBackfillProgress.test.ts` (fake-timers
  race) flakes ~1 in several full ci:strict runs; passes in isolation. Fix
  with deterministic timer mocking.

---

### F3-20: Customer ingest enqueues every registered user (A-filter)

**Context:** which WP users should the customer hooks (`user_register`,
`profile_update`, `woocommerce_created_customer`,
`woocommerce_save_account_details`) enqueue as rec-engine customers?

**Decision:** **A-filter** — every registered user, no role check.

**Rationale:** both existing sync paths are broader than a role filter — the
legacy subscriber-sync keys on the newsletter opt-in, and the new email
HookHandler syncs every registered user — so a `customer`-only filter would be
narrower than both AND would drop custom-role shoppers (VIP, wholesale,
member). Guest buyers (no WP user) are captured by the W5 order path. Admin
"noise" is small and self-resolving (a user with no purchase history gets no
recommendations).

**Alternatives rejected:** B-strict (`customer` role only — misses custom
roles); B-broad (staff-role blacklist — a maintenance burden as new staff
roles appear).

---

### F3-21: Datetime on the wire — `Z` form via a shared IsoDate helper

**Context:** the engine validates every timestamp with a strict Zod
`.datetime()` that **rejects a numeric offset** — `2026-01-15T10:30:00+00:00`
(PHP's `'c'` format) fails as "Invalid datetime"; only the `Z`-suffix form
(`...Z`, contract §base) passes.

**Decision:** one `IsoDate::to_z(int $timestamp)` helper is the single source
for wire datetime formatting; every PayloadBuilder routes its datetime fields
through it.

**Rationale:** F3-1 single-source applied to datetime. Two builders formatted
independently (`first_seen_at`, `on_sale_until`) and both hit the `+00:00`
bug; orders (W5) adds several more datetime fields. One helper means the bug
cannot recur.

**Found via:** the 3.3.4 customers live-walk — the mock didn't validate the
datetime format, so integration was green and only the live engine surfaced it
(LESSONS §2.4, the same shape as the catalog products→items divergence).
`first_seen_at` was an active bug; `on_sale_until` a latent sibling — both
fixed.

### F3-22: Orders-end milestone — sub-PR 3.3 orders complete (W5)

> **Partially superseded:** the on-hold→processing mapping was reversed by
> F3-42 (on-hold is NOT a sale), and the amount serialization (net
> `get_total()` lines, `subtotal/qty` unit_price, ex-tax discounts) was
> superseded by PRO-1241 — all order money fields are GROSS per contract
> v1.4.0 §5. The pattern, batch wrapper, and the rest of the status mapping
> stand.

**Context:** the third ingest domain (orders), built on the F3-16 canonical
6-step pattern and the D6 per-item contract (F3-18), against the W5 orders
contract (batch `{orders:[...]}`, `customer_email` identity, required `status`
enum, `currency` default EUR, per-line `discount_amount`, async attribution).

**Decision:** OrderPayloadBuilder (WC_Order → §5 wire) + Client::ingest_orders
(batch 50, `orders` wrapper) + OrderFlusher (D6) + OrderHookHandler
(`woocommerce_order_status_changed` → enqueue iff the mapped status is non-empty
and actually changed). **WC status → engine enum mapping is Variant 2** (F3-22
status mapping): completed/processing/cancelled/refunded map direct; on-hold →
processing; pending/failed/draft/custom → `''` (skip — not ingested). The
mapping is necessary AND correct: the live engine **rejects a raw WC status**,
confirmed by the orders live-walk (12/12).

**Rationale:** mirror the customers/catalog ends so the shared queue + flusher
abstractions hold across all three domains. Status mapping centralizes the
WC↔engine enum translation in one public `map_status()` (reused by the flusher's
`row_to_object` skip-decision and the hook handler's change-gate).

**Alternatives:** Variant 1 (pass WC status raw, let the engine map) — rejected,
the engine's enum is strict and rejects unmapped values. Variant 3 (ingest every
status incl. pending/failed) — rejected, those are not meaningful orders for
recommendations.

**Relationships:** F3-16 (pattern), F3-18 (D6), F3-21 (IsoDate ordered_at),
F3-19 (guest-customer concern, RESOLVED by W5 engine auto-create). No format
surprises on the live-walk — the Z-form datetime + status mapping both validated
live on the first call.

### F3-23: Plugin-side N-7 — `AbstractD6Flusher` consolidation + W2 wrapper drift

**Context:** after three ingest domains each grew a near-identical D6 flusher
(batch flush, `errors[].index → batch_rows[index]` split, invariant check, AS
job). Catalog's flusher was still **all-or-nothing** — on a 200+errors[] it would
mark a per-item-rejected product SENT (silent loss). That was a **hard lock
condition** before pilot catalog go-live (STATUS.md).

**Decision (architecture):** extract a shared **abstract base**
`AbstractD6Flusher` holding the D6 flush skeleton; each domain subclass provides
`event_types()`, `batch_size()`, `endpoint_label()`, `send()`, `row_to_object()`
and keeps its **own** AS hook / group / recurring tick. Catalog's IngestFlusher
moved onto the base (all-or-nothing → D6), closing the lock. Proven against the
real engine by the catalog live-walk `flusher_d6_split_lock_proof` (a no-SKU
product comes back as a D6 per-item error → marked FAILED; the valid one SENT).

**Rationale:** one copy of the D6 logic (less drift surface, CC-9 single-source),
while preserving per-domain scheduling independence — the queue is event_type
scoped so each flusher drains only its own rows.

**Alternatives:** (a) three independent D6 copies — rejected, triplicated the
silent-loss-risk logic. (b) one **monolithic dispatcher-flusher** draining all
event types — rejected, it couples the domains' scheduling/back-off and loses the
event_type isolation. (i-b abstract base) was chosen over both (confirmed with
the human before coding). NOTE: this means the EVENT_* constant asymmetry
(catalog on its HookHandler, customer/order on their Flusher) is **intentionally
kept**, not unified — the "dispatcher" premise that would have unified it didn't
happen (STATUS deferred items).

**The W2 wrapper drift (caught here):** the N-7.1 catalog live-walk — the first
catalog live-request since W2 — `400`d on `products: Required`. The catalog wire
wrapper had flip-flopped: doc said `products`; 3.2.4 live probe found the engine
then wanted `items` (we switched); **W2 (engine `b5b1295`) renamed it back to
`products`** (clean break). The W2 sync updated the doc **but not the code**
(`Client::ingest_catalog` kept sending `items`), and the mock — still on `items`
— hid it for five syncs. Fixed in Client + mock + ClientTest. A full
drift-audit across all eight syncs confirmed the **wrapper was the only
plugin-breaking drift**: customers/orders were live-walked right after their
syncs (so verified), browse/identity/GDPR are not shipped (stub/PATH-consts, no
drift surface), and removed fields (discount_price, smaily_contact_id, consent)
are not sent. New lesson class: **a sync updates the doc, not the code** (LESSONS
§2.7; CLAUDE.md CC-8 note).

**Relationships:** F3-18 (D6 contract), F3-16/F3-19/F3-22 (the three domains
consolidated), CC-9 (single-source), LESSONS §2.4 + §2.7 (mock-vs-live, sync-vs-code).

### F3-24: Browse-beacon architecture (3.4) — server proxy, no queue, always-register + handler-404

**Context:** browse events are client-originated, anonymous, high-volume
storefront telemetry — a different shape from the server-originated ingest
domains (catalog/customers/orders). The contract mandates a server proxy: "the
API key must never appear in client-side code — a plugin-side server proxy is
required for browse events" (§ auth).

**Decisions:**

1. **Server proxy, mandated.** The browser POSTs same-origin to
   `POST /wp-json/smaily-connect/v1/beacon`; `BeaconEndpoint` decrypts the
   tenant api_key server-side and forwards to `/api/v1/ingest/browse`. Direct
   browser→engine is ruled out (api_key leak).

2. **No IngestQueue/Flusher — best-effort telemetry.** Browse does NOT use the
   F3-16 PayloadBuilder→Queue→Flusher pattern. The client buffers in-memory
   (30s window) and the proxy forwards synchronously (the Client's layered
   retry covers transient blips). A lost batch is acceptable; durable delivery
   (AS-backed queue + retry) is over-design for telemetry — unlike an order,
   which is a durable state-change. Deliberate F3-16 deviation.

3. **Abuse model is part of 3.4.0**, not deferred. The `/beacon` route is
   public (anonymous visitors) AND spends the tenant's api_key + engine quota,
   so an unprotected proxy is a real attack surface (spam → polluted engine
   data + cost on the tenant's account). Three layers: a hard gate (404 before
   any work when disabled), per-IP + per-session rate limits (REMOTE_ADDR only
   — XFF is spoofable; the session counter complements IP behind NAT), and
   server-side validation (event_type allowlist, event_id required,
   ≤100 cap, §6 field-whitelist). First violation → whole-batch 400 (our own
   client never emits an invalid event, so a violation is tampering — this is
   the abuse-filter stance, distinct from the engine's lenient per-item D6).

4. **`/beacon` is registered UNCONDITIONALLY; the handler hard-404s when
   disabled** (NOT conditional registration). Rationale: proving "route absent
   when disabled" needs a fresh `WP_REST_Server`, and re-firing `rest_api_init`
   mid-suite to rebuild it **segfaults wp-env** (bisected: BrowseProxyTest +
   RestRouteRegistrationTest together → exit 255 with no PHPUnit summary; each
   alone is green). Conditional registration would therefore be untestable
   (segfault) or need an isolated bare-server route-table check of low value —
   because the **security posture is identical**: a disabled `/beacon` runs one
   `is_enabled()` check then returns 404, doing zero work (no api_key, no engine
   call, no transient). To a client it is indistinguishable from an absent
   route. The only difference is the namespace index lists `/beacon` — a trivial
   info-disclosure (the route name alone, behaving as 404, is not exploitable).
   This is the standard WP-REST pattern (register unconditionally, gate in the
   handler). `/beacon` is therefore in `EndpointRegistry::expected_routes()`.

**Alternatives rejected:** conditional registration (§ point 4 — untestable +
no security gain); a durable browse queue (§ point 2 — over-design); direct
browser→engine (§ point 1 — api_key leak).

**Relationships:** F3-11 (proxy-ping, the api_key-server-side precedent),
F3-16 (the ingest pattern this deviates from), F3-18 (D6, which the engine's
browse response also follows), LESSONS §2.7 (the EventType 8→9 drift caught in
the 3.4.0 context audit).

### F3-25: Rec-engine backfill (3.5) — one ingest path, two triggers; enqueue + inline-flush

**Context:** the live hooks ingest only CHANGED records. A pilot needs the
EXISTING catalog/customers/orders backfilled into the engine once at setup —
traversing thousands of records. The engine has no backfill endpoint or
pagination (Appendix A): backfill uses the SAME ingest endpoints in batches;
cursor traversal is the plugin's job.

**Decisions:**

1. **One ingest path, two triggers.** Backfill does NOT get a parallel ingest
   path — it enqueues into the SAME `IngestQueue` and drains through the SAME
   `AbstractD6Flusher` (per domain) the live hooks use. So D6 error-split, retry
   backoff, and engine `event_id` dedup are reused, not reimplemented. A live
   hook enqueues one changed record; a backfill enqueues every record. Each
   domain's backfill `enqueue_record()` mirrors its HookHandler (catalog fans a
   variable product out to variation units, etc.).

2. **`AbstractBackfillJob` + per-domain subclass** (consistent with the N-7
   `AbstractD6Flusher` shape). The base owns the cursor/state/AS-tick/progress;
   the subclass owns `count_total()`, `fetch_ids_after()`, `enqueue_record()`.
   The legacy contacts `BackfillJob` is NOT refactored into it (it's
   users→Smaily-specific); both implement a shared `BackfillJobInterface` so the
   REST endpoint + AS tick drive them through one path. The
   `smly_plus_backfill_job` table's `(job_type, target)` UNIQUE key lets the
   rec-engine rows (`target = rec_engine`) coexist with the legacy
   `(contacts, smaily)` row — no schema change.

3. **Enqueue + inline-flush per batch** (decision (b), not enqueue-only).
   `process_batch()` enqueues a batch then flushes it inline before advancing
   the cursor. Two reasons: progress reflects records actually SENT (not just
   queued — enqueue-only would show 100% while the recurring flusher still has
   ~100 min of work), and the queue stays BOUNDED (each batch drained before the
   next is enqueued) instead of ballooning to thousands of pending rows. The
   tradeoff — backfill is batch-synchronous, slower than async enqueue-only — is
   fine for a one-time mass setup (true progress > speed). Rejected: (a)
   enqueue-only (misleading progress, unbounded queue); (c) direct Client calls
   (would duplicate the D6/retry logic).

4. **Resumable cursor, no freshness marker.** The cursor (last-seen id) is a
   real `WHERE id > cursor ORDER BY id LIMIT batch` (not offset, which shifts
   under inserts/deletes) saved in the row, so a timed-out/crashed tick resumes
   from the cursor — never restarts (proven:
   RecEngineCatalogBackfillTest::test_resumes_from_cursor_and_does_not_restart).
   No `_smaily_rec_synced_at` freshness skip (decision (i)) — engine ingest is
   an idempotent UPSERT, so re-sending is harmless; a skip-unchanged marker is a
   future re-run optimisation, not correctness (YAGNI).

**Relationships:** F3-16 (the ingest pattern reused), N-7 / `AbstractD6Flusher`
(the abstract-base shape mirrored + the flusher reused), F3-20 (the A-filter the
customers backfill will enumerate by). 3.5.0 ships the base + infra + catalog;
.1 customers, .2 orders, .3 admin UI + live-walk.

### F3-27: Identity merge (3.7) — anon→known on login, server-side, complementary to retroactive binding

**Scope correction (sourced, not assumed).** The roadmap one-liner read "same
person, two emails → two customer records until merge", implying a
customer↔customer merge. Contract §7 + the README say otherwise: identity/merge
is **anonymous-session → known-customer** binding. v1 has NO customer↔customer
merge (email is the natural key — two emails are two customers, full stop). So
3.7 binds an anon session's browse history to a known customer; it does NOT
reconcile two known emails. (F3-14 discipline against a wrong assumption — this
time the plan's, caught by checking the contract before building.)

**Decisions:**

1. **Complementary to retroactive binding, not a duplicate.** The engine binds
   an anon session's earlier browse_events to a customer AUTOMATICALLY when a
   browse event carries the email/visitor-token (§6 retroactive binding). That
   covers "browses after being identified". identity/merge (§7) is the EXPLICIT
   path for "logs in but generates no email-carrying browse event after" — the
   gap retroactive binding leaves.

2. **Server-side `wp_login`, not the client-side JS mergeIdentity.** Login is a
   reliable server signal (vs a JS flag), the api_key stays server-side (no new
   public proxy route — `Client::merge_identity` posts directly), and the
   anon-session / visitor-token cookies are client-set but NOT HttpOnly, so
   $_COOKIE has them on the login request. The JS `mergeIdentity` stub is KEPT
   (still throws) and reserved for the Milestone-2 platform-agnostic client
   (Shopify) — YAGNI on the M2 path in v1.

3. **Checkout (`email_provided_at_checkout`) is NOT a trigger — timing, not
   redundancy.** Checked: order ingest does NOT bind the session's browse
   history — §5 uses `session_id` for attribution matching only ("stores the
   signals; a cron computes rec_attribution"), and the §6 retroactive binding is
   browse-endpoint-only. So a checkout merge would NOT be redundant. BUT a
   guest's customer is auto-created by the ASYNC order ingest, so it isn't in
   the engine yet at checkout — a synchronous merge then 404s. A registered user
   logging in already exists (A-filter ingested them at registration/backfill),
   so login timing is sound. Checkout merge is therefore deferred (the guest's
   history binds via the next email-carrying browse event anyway).

4. **404 customer_not_found → log + skip** (not ingest-then-retry). Rare (the
   A-filter ingests every registered user, so by login the customer exists), and
   retroactive binding is the safety net. Plugin-side **dedup** via user meta
   (`_smaily_rec_merged_anon_sid` = the last anon session merged for a user):
   repeat logins on the same session don't re-hit the engine (the merge is
   idempotent there anyway); a NEW anon session re-merges.

**Relationships:** §6/§7 (retroactive vs explicit binding), F3-20 (the A-filter
that guarantees the customer exists by login), F3-21 (IsoDate for merge_ts),
F3-14 (contract-vs-assumption check). 3.7.0 ships the handler; 3.7.1 the
live-walk + ZIP.

### F3-28: GDPR (3.8) — WP Privacy API, conservative export / complete erase, decision-logic strip

**Scope authority is `docs/DATA_MODEL_GDPR.md`** (a standalone doc) — the code
references it and does NOT re-derive the boundary. Summary of the decisions it
fixes, as applied in 3.8:

1. **Native WP Privacy API integration.** Register a
   `wp_privacy_personal_data_exporters` exporter (Art 15) + a
   `wp_privacy_personal_data_erasers` eraser (Art 17), so the rec data shows up
   in WordPress's own Tools → Export / Erase Personal Data — no bespoke UI.

2. **Export is conservative; erase is complete (asymmetric).** EXPORT surfaces
   only rec-specific PERSONAL data: engine browse_events / visitor_tokens /
   recommendations / email_events, the engine customer record MINUS its
   decision-logic fields (segment / RFM / engagement / inferred_* — the engine's
   classification, a trade secret, like Google/Meta withhold), and the plugin's
   own `_smaily_*` rec-meta. It does NOT re-export WooCommerce order/purchase
   data (Woo's own exporter owns that — the plugin reads rec-meta OFF an order,
   never the order), and NOT `rec_attribution` (the engine omits it from §8 —
   silently, NOT "request separately", because it's decision logic, not a
   subject-access right). ERASE is the opposite — engine §9 deletes everything
   (CASCADE incl. rec_attribution + visitor_tokens) and the plugin removes its
   `_smaily_*` markers.

3. **The WC boundary is the headline guarantee.** The exporter pulls rec-meta
   off an order (`$order->get_meta('_smaily_rec_id')`) but never the order's
   totals / line items. A test proves it: a customer with an order → the export
   contains `_smaily_rec_id` but NOT `total_amount` / `line_total`.

4. **HPOS-safe meta.** The order rec-meta MUST be read/written via the WC_Order
   object (`$order->get_meta` / `delete_meta_data` / `save`), NOT
   `get_post_meta` / `delete_post_meta` — under HPOS (the wp-env + WC-10.7 mode)
   order meta lives in `wc_orders_meta`, so the post-meta calls would silently
   read/erase nothing. (Caught by PHPStan flagging the `wc_get_orders('ids')`
   cast — a real HPOS bug, not just a type nit.)

5. **Opt-out (Art 21) is engine-API only in 3.8.** `Client::customer_opt_out`
   (§10) ships now; the trigger — Smaily profiling-consent withdrawal + the
   beacon's two-gate stop (browser-cookie consent AND Smaily profiling consent)
   — is a SEPARATE later piece, because it depends on the Smaily profiling-consent
   parameter API. 3.8 ships the mechanism the consent wiring will call.

6. **GDPR endpoint URLs use a `{email}` path placeholder — substitute with
   `str_replace`, never `sprintf`** (3.8.1, caught by the live-walk). The engine's
   endpoints-map advertises §8/§9/§10 as `…/customer/{email}/export` etc. The
   email goes in the URL *path* (rawurlencoded), and the substitution token is
   `{email}`, NOT `%s`. 3.8.0 shipped `sprintf(resolve_url(…), rawurlencode($email))`,
   which is a no-op on a `{email}` URL → the literal `{email}` reached the engine
   (404 `No customer with email '{email}'`). The unit + mock endpoints maps had
   used `%s`, mirroring the bug, so every gate was green; only the live engine
   used `{email}`. Fix: `Client::customer_url()` does `str_replace('{email}', …)`
   (fallback `PATH_CUSTOMER_*_TMPL` constants carry `{email}` too); the mock + unit
   maps now use `{email}` and the mock customer routes 422 on a literal-placeholder
   email so a regression fails integration. This is the placeholder-syntax-as-wire-
   shape lesson (LESSONS §2.9), a sibling of the `items`/`products` wrapper drift.

**Relationships:** DATA_MODEL_GDPR.md (the scope authority), F3-20 (the A-filter
customer the export/erase keys on by email), F3-27 (the merge-marker user-meta
the eraser also clears), §8/§9/§10 contract, LESSONS §2.9 (placeholder drift).
3.8.0 ships the handler; 3.8.1 the live-walk (10/10) that caught the placeholder
bug, + ZIP. Smaily profiling-consent wiring + beacon-stop follow separately.

### F3-29: Step 4 activation (3.9) — connect ⇒ sync all (system-decides); per-domain sync toggles removed

**Context:** Step-4 4a (the connected state) shipped five `recEngineFeatures`
checkboxes built faithfully to PLUGIN.md §Step-4-4a (sync orders/customers/products
+ track cart events, default-on; track browsing, default-off). But the backend
**never enforced** four of them — the Catalog/Customer/Order HookHandlers gate on
`is_connected()` alone, and the four option keys (`smly_plus_rec_sync_*`,
`…_track_cart_events`) were write-only with no consumer. Only `track_browsing` was
read (by the beacon). So the toggles were cosmetic, and `hydrate.ts` even hardcoded
them rather than reading the saved values. A spec/vision audit (the research that
preceded 3.9) surfaced the contradiction: PLUGIN.md specced toggles, but Erkki's
product vision had evolved to "activating the engine syncs everything — the system
decides; partial sync just makes a worse engine."

**Decision:** Adopt the vision. Connecting the rec-engine **syncs all domains
unconditionally** (the existing `is_connected()` gate IS the model). Remove the four
sync toggles from the UI + types/reducers/hydrate, stop persisting their options,
and clean up the dead keys (`Activation::cleanup_removed_rec_feature_options()`,
idempotent `delete_option`×4 on upgrade-detect). The **browse-tracking toggle stays**
— it's a legitimate **legal-consent gate** (opt-in, default-off), not a sync
preference, and is layered on top of end-user consent (WP Consent API / CookieYes,
the two-gate model of 3.4.2). Cart events ride the browse beacon (gated by
`track_browsing`), so there is no separate cart toggle. PLUGIN.md §Step-4-4a + §6
revised to follow the vision (the authoritative spec now matches).

**Disconnect / re-connect (Erkki-locked):** `disconnect()` clears only the
connection options (`smly_rec_*`: api_key, base_url, tenant, endpoints, config,
connected) — it does **NOT** delete `smly_plus_rec_track_browsing`. The ConnectedView
(and with it the browse toggle) hides because `is_connected()` is false; on
re-connect the saved browse preference is restored. This **required a hydration
fix** (no longer optional): `EnvDetector::rec_engine_snapshot()` now emits the saved
`track_browsing` (read independent of connection state), and `hydrate.ts` reads it
instead of hardcoding `false`. Without it, re-connect would forget the preference
(and a plain reload blanked a saved-on toggle) — so the hydration fix is the
*precondition* for the disconnect/re-connect decision, not a side cleanup.

**Alternatives rejected:** (a) wire the four toggles to actually gate ingest
(per-domain control) — rejected: contradicts the system-decides vision, and partial
sync degrades the engine; (b) leave the toggles as cosmetic UI — rejected: a UI that
promises a gate it doesn't have is a lie. The placeholder "mode-A → mode-B" label in
the old roadmap stub was never defined in any spec; it is NOT the multilingual
Mode A/B/C (F2-6, a separate email-account axis) nor "Route A" (the engine-convergence
plan) — the audit established this from sources.

**Relationships:** F2-6 (multilingual Mode A/B/C — the unrelated same-named axis),
3.4.2 two-gate consent (browser-cookie consent AND merchant preference), PLUGIN.md
§Step-4-4a + §6 (revised to match), the `is_connected()` ingest gate the
HookHandlers already use. Browse end-user consent (CookieYes / WP Consent API) is
unchanged — remembering the merchant preference does not bypass it.

### F3-30: Pilot-hardening — diagnostics + recovery system (3.10, implements §13/§13a)

**Context:** the production-readiness audit (after Phase-3 feature work) found two
operational pilot-blockers that aren't feature gaps: **P1** — a terminal `failed`
queue row is invisible and never re-driven (the rec queue, unlike the legacy
Smaily queue, has no retry-failed mechanism, and even that only re-drives
`pending`, not terminal `failed`); **P2** — no surfaced diagnostic trail
(`error_log`-only; `last_error` written but never read back; the backfill panel
reports records *walked*, so it read "1400/1400" while rows silently failed). These
are "how do you operate it in a pilot" gaps, more impactful than the (low-risk,
now-closed) legacy-backfill path.

**Decision:** build the spec's already-designed §13 (Event Log) + §13a
(NotificationManager) for the rec queue, **layered** across three audiences
(merchant "is it syncing?", developer "why not?", proactive "something broke"), as
ordered sub-PRs — visibility is the foundation (you can't recover what you can't
see; the "Retry now" button lives inside the Event Log):
1. **3.10.0 — visibility (P2):** read-only `/events` UNION read-model over both
   queues + Event-Log Settings tab + sticky failed-24h banner + backfill progress
   fixed to engine-confirmed sent/failed. No schema change (the columns exist;
   sent/failed counted at read-time from the queue since the run's `started_at`).
2. **3.10.1 — recovery (P1):** `IngestQueue::reset_failed()` (FAILED→PENDING) +
   `/events/retry` + a manual "Retry now / Retry all failed" button. **Manual-only
   base** — auto-retry is deferred because auto-retrying a deterministic 4xx loops
   forever; it needs failure-kind classification, which 3.10.0 already enables
   (`last_error` carries `http_4xx`/`http_5xx`/`d6_item_error`).
3. **3.10.2 — proactive admin-notice (Layer 3 base):** `NotificationManager` notice
   level on health signals (failed-count > N in 24h, engine-down > 1h). No infra.
4. **3.10.3 — email (post-pilot):** §13a email level via **`wp_mail`**, NOT Smaily.

**Email-channel decision (wp_mail over Smaily):** routing the alert through Smaily
couples it to a system that may itself be down — a "Smaily connection failed" alert
can't send via Smaily. `wp_mail` is independent (and is what §13a specs). The
trade-off is server-SMTP reliability, mitigated by the admin-notice base (immune to
mail failure, always visible in wp-admin) + documenting an SMTP-plugin recommendation.

**Relationships:** PLUGIN.md §13 (Event Log) + §13a (NotificationManager) — the
spec authority; F3-18 (the D6 queue's status/attempts/last_error the Event Log
reads); the legacy `smly_plus_retry_failed_events` job (the pattern 3.10.1 fixes —
it re-drives pending, not terminal failed). Pilot-hardening order: P5 → 3.10.0 →
3.10.1 → 3.10.2 → P4 (onboarding doc); 3.10.3 + queue-janitor + GCM are post-pilot.

### F3-31: Profiling consent — opt-out model (default-on), bidirectional Smaily sync

**Context:** the rec-engine profiles Smaily contacts; GDPR requires a consent basis
for profiling. Authority is Smaily (RECENGINE_API_CONTRACT line 722 — consent is NOT
in the rec-engine contract); the plugin writes the consent to a Smaily contact and
reads it back. Spec: `docs/SMAILY_PROFILING_CONSENT_SPEC.md` (the scope authority).

**Decision (Erkki, 2026-06): OPT-OUT model, default-on.** Profile a contact UNLESS
they've explicitly opted out. Enforcement (the inverse of the original opt-in draft):
`profile IF is_unsubscribed != "1" AND smaily_rec_profiling != "0"` — don't-profile
only on the general unsubscribe (stronger) OR an explicit profiling opt-out; a
missing field / contact-not-in-Smaily (206) → profile. Read-back values are Smaily
**strings** ("0"/"1") → string comparison. A read-back error **fails open** (profiles).

**Rationale:** Estonian AKI does not currently require a separate explicit profiling
**opt-in** — it requires a *transparent action* + a *working opt-out*. So default-on
+ a real opt-out path is lawful today. This is a **conscious decision carrying a
small GDPR risk** that Erkki is investigating separately before any opt-in TODO;
mitigated by transparency (privacy policy mentions profiling) + the working opt-out.

**Two consequences:**
- The **WP opt-out UX ((a).2) becomes a GDPR requirement, not a refinement** — the
  opt-out model is only lawful if the shopper can actually opt out.
- **TODO — explicit opt-in if AKI tightens.** `ProfilingConsent::is_allowed()` is
  built pure + invertible: flip to `profile IFF smaily_rec_profiling == "1"`.

**Probe-confirmed mechanism ((a).0):** write via the existing `upsert_subscribers`
(form-encoded, custom fields auto-create) → `{code:101}`; read-back via
`GET /api/contact.php?email=` returns `is_unsubscribed` + `smaily_rec_profiling`
(206 on miss). Smaily rejects `.test` email domains on write — a live-test gotcha,
not a code issue (no latent bug in the contact sync). The probe applied the project
scar "don't assume a wire shape — live-probe it" and caught that my own spec had
*assumed* read-back without verifying — the read-back endpoint was the one real risk.

**Fail-open risk (part of Erkki's separate GDPR review):** a read-back error returns
*profile* (fail-open), consistent with the opt-out/default-on model. The narrow
consent-risk window is: cache-miss **AND** read-error **AND** the contact had
actually opted out — only then is someone profiled briefly without consent. Bounded
by the cache (a WP-side opt-out sets cache `'0'` immediately; a known opt-out stays
cached for the TTL, so the window needs a *cache-expired* opt-out + a *concurrent*
read failure). Narrow + transient, but real — folded into Erkki's separate GDPR
investigation alongside the opt-out-model risk.

**Enforcement runtime (3.10.x-style):** cached read-back (`smly_profiling_*`
transient, **daily TTL** — a Smaily-side opt-out propagates within a day; profiling
isn't real-time-critical, but a week would be a GDPR problem). WP-side opt-out writes
update the cache immediately. OFF → engine §10 `customer_opt_out` (3.8) + beacon-stop
((a).1). Cookie two-gate: the beacon sends IFF browser-cookie-consent (CookieYes,
ePrivacy) AND `smaily_rec_profiling != "0"` (this gate) — profiling default-on means
the beacon depends mostly on the cookie gate, plus the opt-out check.

**(a).1 — beacon gate placement + two design calls:**
- **Gate placement:** the profiling gate is at the **`BeaconEndpoint` proxy**
  (server-side), filtering events whose `customer_email` resolves to opted-out
  before the forward. Anon events (no email) have no contact to check → only the
  cookie gate applies (until identified). may_profile() reads the cache (one check
  per batch — a batch is one visitor), so a read-back is at most once per
  contact-per-TTL in the proxy path.
- **Drop semantics (Erkki):** a profiling-opt-out drop is a **conscious drop, not
  an error** — aggregated into a 24h counter (`smly_profiling_dropped_24h`, for a
  future surface) and logged **once per batch** (never per event, so a heavy
  opted-out browser can't flood the log). Beacon events aren't durable-queue rows,
  so they don't appear in the Event Log — surfacing the drop count is a possible
  refinement, not built.
- **Anon→known retroactive (Erkki):** when an opted-out shopper logs in,
  `IdentityHookHandler` **skips `identity.merge`** — so the engine never
  retroactively binds their prior anon browse to their identity. The opt-out is
  respected **backwards**; the anon events stay unattributed (not deleted — the
  spec's "do not profile" = engine §10 opt-out + don't-bind, not erase).

**Relationships:** SMAILY_PROFILING_CONSENT_SPEC.md (authority), F3-28.5 (the
deferred piece this is), §10 `customer_opt_out` (3.8, the engine-side enforcement),
3.4.2 two-gate consent (the cookie gate this layers onto). Sub-PRs: (a).0 Client
read/write + `ProfilingConsent` enforcement (this); (a).1 beacon two-gate; (a).2
live-walk + WP opt-out UX.

### F3-32 — Cypher v2: AES-256-GCM, versioned blob, upgrade re-encryption (audit fix)

**Context:** FABLE_AUDIT §4#2 — the legacy AES-256-CBC blob used a STATIC IV
(= a prefix of `AUTH_KEY`) for every encryption and persisted that IV inside the
stored value: every DB dump leaked an AUTH_KEY prefix, and equal plaintexts
produced equal ciphertexts. A CBC→GCM upgrade was already in BACKLOG (post-pilot).

**Decision:** `Cypher::encrypt()` now writes ONLY the v2 format — `smy2:` +
base64(nonce(12) ‖ tag(16) ‖ ct), AES-256-GCM, random per-message nonce, key =
raw-binary sha256(SECURE_AUTH_KEY). `decrypt()` dispatches on the prefix and
keeps a behaviour-identical legacy CBC+HMAC read path (decrypt-only, never
written). `Activation::reencrypt_legacy_secrets()` (runs inside `run()`, so on
activation AND upgrade-detect, idempotent via the prefix check) migrates all
three storage locations: the legacy default credentials array, every
`smly_plus_credentials_*` account, and `smly_rec_api_key` (autoload=false
preserved). An undecryptable blob (rotated salts) is left byte-identical —
overwriting would destroy the "re-enter your credential" evidence.

**Rationale:** any IV fix forces a stored-format migration; going straight to
GCM costs ONE migration instead of two and closes the BACKLOG item. AEAD also
retires the hand-rolled encrypt-then-MAC.

**Alternatives:** random-IV CBC (same migration cost, keeps the hand-rolled
HMAC); lazy re-encrypt-on-save only (leaves leaking blobs in the DB
indefinitely on installs that never re-save).

**Relationships:** FABLE_AUDIT §4#2 + remediation log; BACKLOG GCM item
(closed); `CypherGcmTest` (integration — the REAL class; the unit suite runs a
spy shim, see tests/bootstrap.php).

### F3-33 — Queue janitor: terminal-row retention prune + created_at index (audit fix)

**Context:** both durable queues grew without bound (sent/failed rows never
pruned), and the rec-queue had no index a `created_at` range scan could use —
FABLE_AUDIT §5 watch-item; the BACKLOG "Queue janitor" item was pulled forward
pre-pilot (Erkki: months of pilot rows would make this expensive later).

**Decision:** `DB\QueueJanitor` — a daily Action Scheduler tick
(`smly_plus_queue_janitor`) deletes terminal rows past retention from BOTH
queues: `sent` after 30 days, `failed` after 90 days (both filterable:
`smaily_connect_janitor_{sent,failed}_retention_days`). **`pending` rows are
NEVER pruned regardless of age** — they are work, not history; an old parked
retry must survive until it terminally resolves. DELETEs run in LIMIT-1000
batches, max 20 per status per run (a neglected table drains over consecutive
daily ticks instead of one table-locking statement). Migration 006 restates
both CREATE TABLEs with `KEY idx_created_at` (the dbDelta-supported way to add
an index).

**Rationale:** failed rows keep a long window because they are the Event Log's
diagnostic evidence and stay retryable until pruned; sent rows are
engine-confirmed and only useful as a short audit trail.

**Alternatives:** prune on flush (couples housekeeping to the hot path);
TTL-partitioned tables (overkill at this scale); pruning failed fast (destroys
the recovery story 3.10.1 just built).

**Relationships:** FABLE_AUDIT §5/§7#9 + remediation F6; 3.10.0 Event Log
(failed-in-24h count also benefits from the index); `QueueJanitorTest`
(3 integration tests: retention matrix incl. pending-never, filterability,
index presence).

### F3-34 — RSS feed URL builder relocated to Integrations (client-side, stateless)

**Context:** the pilot's old plugin (1.6) had an RSS settings tab; the new UI
seemed to have dropped the feature. Investigation (2026-06-12): the feed itself
never stopped working — the legacy `Rss` class registers its rewrite + template
whenever WC is active, all parameters travel in the feed URL's query string.
Only the settings UI vanished, as a **side-effect** of the 2.H.3 legacy-menu
hide (the RSS tab lived in the hidden menu); the React UI never got a panel.
Existing template URLs at the pilot keep working unchanged.

**Decision:** rebuild the URL builder as `RssFeedSection` under the
**Integrations** step/tab (Erkki's placement call — not data-sync, not
automations; both wizard Step 5 and Settings, same component). **Purely
client-side and stateless**: the old tab's options were only ever prefill for
a URL generator, so the new section saves nothing — no save-footer, no
dirty-tracking, no REST changes; Integrations stays info-only. Server side,
`EnvDetector::rss_snapshot()` emits a `rss` boot-payload block (permalink-aware
base URL via legacy `Rss::make_rss_feed_url()`, `product_cat` terms, legacy
option values as prefill — a migrated store sees its old configuration); null
when WC is inactive, which hides the section. `buildRssFeedUrl()` mirrors the
legacy admin.js param contract byte-for-byte (incl. the `order_by=none` omits
`order_by`+`order` quirk).

**Rationale:** the URL is the artifact the merchant copies into a Smaily
template; persisting builder inputs adds a save path for zero behavioural
gain. Prefill-from-legacy covers migration continuity.

**Alternatives:** RSS panel with a real save path (rejected — would force
dirty-tracking onto the info-only tab for no benefit); un-hiding the legacy
tab (rejected — reverses 2.H.3); leaving it URL-only/undocumented (rejected —
the pilot merchant can't discover the feature).

**Relationships:** 2.H.3 (legacy-menu hide — the cause); `EnvDetectorTest`
(unit: null-gate + WC-active subclass path), `RssBootSnapshotTest`
(integration: pins that the legacy classes are actually loaded in a real env —
the seam the unit suite must fake), `rss-feed-url.test.ts` +
`RssFeedSection.test.tsx` (vitest).

### F3-35 — Version renumbered 2.0.0-beta.1 → 2.1.0-beta.1 + `Update URI` header (upstream-collision guard)

**Context:** preparing the first GitHub release surfaced that upstream
(sendsmaily) shipped its own **2.0.0** to wordpress.org on 2026-06-03 (#128 —
a 1.x-line WP-7.0 compatibility bump, ~2000+ active installs; verified live on
w.org). Our fork installs into the same `smaily-connect/` folder, so WP's
update check matches the w.org slug and compares versions:
`2.0.0-beta.1 < 2.0.0` → the pilot site would be OFFERED upstream's package —
or, with per-plugin auto-updates on (likely carried over from the 1.6
install), silently REPLACED by it mid-pilot, deleting the entire rec-engine
codebase from disk. UPSTREAM_AUDIT had tracked #128 only as a min-versions
conflict; the auto-update clobber vector was a new find.

**Decision:** two independent guards, both in the same commit. (1)
**`Update URI: https://github.com/erkkimarkus/smaily-wordpress-plugin`** in
the plugin header — WP 5.8+ core skips wordpress.org updates entirely for a
plugin whose Update URI is non-w.org; this is the primary, version-arithmetic-
independent protection and must stay until the upstream merge. (2)
**Renumber to 2.1.0-beta.1** (Erkki's call) — clears upstream's 2.0.0 for
humans and for any pre-5.8-semantics tooling, makes "which code runs here"
visible at a glance, and the eventual merge-back lands monotonically
(upstream 2.0.0 → rewrite 2.1.0). All version literals moved in one pass
(plugin header, both PHP constants, Stable tag, package.json+lock, test
bootstraps, ConstantsTest, CHANGELOG/README/MIGRATION); CHANGELOG carries a
version-note explaining the renumber.

**Rationale:** the number bump alone is fragile (upstream's next release
re-collides); the header alone leaves the confusing "2.0.0-beta.1 vs
upstream 2.0.0" support story. Together they cover both the machine and the
human failure modes.

**Alternatives:** slug/folder rename (rejected — breaks the 1.x→2.x in-place
upgrade path and all of MIGRATION.md); `site_transient_update_plugins` filter
(rejected — code where a header suffices); leaving 2.0.0-beta.1 + header only
(rejected — support ambiguity, and beta.1 < 2.0.0 reads as "outdated" in
every plugin list).

**Relationships:** UPSTREAM_AUDIT #128 (now also carries the clobber note);
F3-34/P6 (the release-prep that surfaced this); MIGRATION.md §"Class not
found" (version pointer corrected — composer.json never carried a version).

### F3-36 — SkuResolver: synthetic `wc-{id}` product keys for SKU-less stores (pilot find)

> **⚠️ SUPERSEDED by PRO-1224 (2026-07-10).** The "real SKU when set, else
> synthetic `wc-{id}`" rule below is REVERSED: SkuResolver now ALWAYS emits
> `woo-<id>` (the platform id, prefix `woo-` not `wc-`) and NEVER the merchant SKU
> field. The engine's `sku` is a join/identity key, not a human code, and merchant
> SKUs collapse distinct products (PRO-1223). The SkuResolver *pattern* (one
> chokepoint, canonicalization, deleted-line fallback, never-drop) survives intact;
> only the "prefer the merchant SKU" branch and the `wc-` prefix are gone. See the
> PRO-1224 entry at the end of this file. The context below is kept for the history.

**Context:** first day of pilot debugging (2026-06-12). The pilot store has
**no SKUs on any product**, and its older orders reference deleted products.
The engine keys its entire pipeline on `sku` (catalog natural key
`(tenant_id, sku)`; order `items[].sku` required; browse `product_view`/
`cart_*` `sku` required), and the plugin treated "no SKU" as "not ingestable"
on every surface — each failing differently: catalog units were dropped
BEFORE enqueue (silent — zero Event Log trace, engine catalog simply empty),
orders built an empty `items[]` and were D6-rejected (`items: Array must
contain at least 1`) on every retry (the user-visible symptom: 50 failed
order.upserts), and the beacon omitted `sku` so the engine rejected those
browse events. Net effect: the engine could learn nothing from this store.

**Decision:** one shared `Support\SkuResolver` supplies the engine key on all
three surfaces — the real SKU when set, else synthetic **`wc-{product or
variation id}`** (stable, ≤64 chars). CatalogPayloadBuilder/expand no longer
filters SKU-less units (CatalogHookHandler's belt-and-braces guards removed
too); OrderPayloadBuilder keys lines via the resolver (variation id wins,
like catalog treats variations as units); StorefrontBeacon always emits
`sku`. Plus a safety net: an order whose every line drops is a TERMINAL SKIP
in OrderFlusher (third skip case) — never sent, because `items` min-1 can
never pass. The mock orders route now enforces `items` min-1 (it was the
divergence hiding this from integration), and the catalog walk's D6
lock-proof lever moved from empty-sku (now impossible through the builder)
to an over-64-char SKU.

**Deleted products — assumption falsified by the integration env:** the
plan assumed WC line items retain the product id after deletion. WRONG on
current WC: permanent deletion ZEROES the items' `_product_id` (verified
empirically, WC 10.7 wp-env — the new integration test caught it first run).
So all-deleted orders (the pilot's 2219–2227) resolve as unkeyable → empty
items[] → terminal skip: they leave the queue cleanly instead of D6-failing
forever. The id-survives path (older WC / intact data) still keys `wc-{id}`
and ingests — unit-covered. Both outcomes correct, neither send-and-fail.

**Live evidence instead of a new probe:** non-catalog SKUs in orders and
browse are ACCEPTED by the engine — proven by the existing green walks
(walk-3.3-orders sent item SKUs with no catalog row, 12/12; walk-3.4-browse
sent WALK-* SKUs never ingested, 13/13). So synthetic keys are safe even
when their catalog row never existed.

**Trade-off (accepted):** if the merchant later assigns a real SKU to a
product already ingested as `wc-{id}`, the key changes — fresh catalog entry,
history splits between the keys. Accepted over a tenant-level "key mode"
setting (more code; identical output for a fully SKU-less store). Documented
in SkuResolver's docblock.

**Alternatives:** require the pilot to add SKUs (rejected — unrealistic for a
store that never used them, impossible for deleted products); send product_id
in a separate field + engine-side change (rejected — contract change for a
plugin-solvable problem); skip-only fix without synthetic keys (rejected —
fixes the Event Log noise but leaves the engine blind to the whole store).

**Relationships:** F3-16/F3-19 (the builders); F3-18/N-7.1 (D6 split — the
lock-proof lever change); F3-21 (IsoDate — same single-source pattern);
LESSONS §2.10 (the silent-drop lesson). Pilot go-live: after deploy, re-run
catalog backfill + Event Log "Retry all failed" (the flusher rebuilds
payloads fresh at flush, so the failed rows heal in place).

### F3-37 — Abandoned-cart backlog guard + per-cart error handling (pilot mass-email incident)

**Context:** minutes after the 2.x plugin was installed on the pilot store,
customers received abandoned-cart emails in bulk. **Attribution (corrected
mid-investigation):** the emails came from **CartBounty Pro** — a third-party
abandoned-cart plugin active on the site — NOT from our pipeline. Evidence:
the email links carry `cartbounty-pro` references, and the client's Smaily
account contains no automation and no such email. The first working
hypothesis (our legacy pipeline → Smaily autoresponder) was WRONG and is
recorded here deliberately: it was plausible, code-supported, and falsified
only by looking at the actual email — attribute from the artifact, not the
architecture. The most likely trigger mechanism: the site's WP-Cron had been
effectively dead (which is also why no one remembered abandoned-cart being
on), every cron-based plugin accumulated backlog, and the plugin swap
(deactivating the old 1.6 code / re-arming schedulers) brought cron back to
life — CartBounty's overdue reminders all fired at once. Needs on-site
confirmation (CartBounty's email log timestamps vs install time).

**Why we still fixed OUR pipeline:** it has the IDENTICAL structural flaw,
and the pilot DB has it ENABLED (`smaily_connect_abandoned_cart_status` was
true from the 1.6 era — the wizard toggle honestly displayed it). The legacy
email pass drains `abandoned AND mail_sent IS NULL` with NO time bound, and
aborted the whole loop on the first API error WITHOUT marking anything — so
a dormant period accumulates a backlog, and the first successful tick after
re-arming (e.g. the moment a working autoresponder appears in the Smaily
account) would mass-mail it. On the pilot the flood was one working
autoresponder away.

**Decision (both in `integrations/woocommerce/cron.class.php`):**
1. **Backlog guard:** a reminder is sent only for carts whose `cart_updated`
   is within `smaily_connect_abandoned_cart_max_age_seconds` (filterable,
   default 24h); older carts are expired — marked `mail_sent` without
   emailing, with a summary log line. The age signal is `cart_updated`, NOT
   `cart_abandoned_time`: the status pass stamps the latter NOW() when it
   (re)marks, so after dormancy every historical cart looks freshly
   abandoned by that column. Comparison is epoch-int, not string — MySQL
   reads back `Y-m-d H:i:s` while the writer used the Z-form, and a string
   compare breaks on the separator byte (`' ' < 'T'`) for same-day carts.
2. **Per-cart error handling:** API failures log + `continue` (next cart)
   instead of the upstream `return` (abort loop, mark nothing). A failed
   cart stays unmarked → retried next tick until it sends or ages out of
   the guard window. Bounded retries, no hidden backlog growth.

**Tests:** `AbandonedCartGuardTest` (integration, real table + real hook
wiring; the Smaily client is deliberately unconfigured so any send attempt
fails — a `mail_sent` mark can only come from the guard). Covers: stale
expired even when an earlier cart's send failed (the abort regression);
fresh failed cart stays unmarked; same-day cart not wrongly expired (the
string-compare seam). The tests env never runs activation, so the fixture
creates the cart table via the real Lifecycle DDL (reflection — no schema
duplication).

**Alternatives:** turning the AS job off when the feature is disabled
(insufficient — the pilot has it ENABLED); deleting old cart rows on upgrade
(destructive, loses analytics); time-bounding the SQL query itself (equal
effect but the expiry would be invisible — the guard logs what it expired).

**Relationships:** upstream cherry-pick #123 (same legacy cron file); F3-36
(same day, same lesson family — LESSONS §2.11's silent accumulation, here in
time dimension); STATUS pilot checklist (CartBounty coexistence decision is
the merchant's: two abandoned-cart systems on one store = double-reminder
risk).

### F3-38 — Non-product exclusion is the engine's job (signal, not a connector filter)

**Context:** the catalog-correctness brief (`PLUGIN_BRIEF_catalog_correctness.md`
§P3 / `PROMPT_woo_plugin_team.md` item 6) asked the plugin to filter
non-products (gift cards, donation items, language-switcher pseudo-products) out
of catalog sync — call it "CC.4". CC.1–CC.3 (canonical collapse + `{lang:value}`)
are done and live-walked; this was the last open item.

**Decision (CONFIRMED + implemented, 2026-06-14):** the plugin does **NOT**
build a hard non-product filter. Instead it sends *signal* — `product_type`
(`WC_Product::get_type()`, incl. gift-card plugins' custom types), `is_virtual`,
`is_downloadable` as top-level catalog fields — and the **engine's
`recommendable` flag (migrations 0039/0040) owns the exclusion decision**. Engine
team confirmed the division (commit 37a8f66) and consumes the fields
(`classifyRecommendable`: gift-card types → excluded; virtual/downloadable never
auto-exclude). `CatalogPayloadBuilder` always emits the three fields; builder
unit tests + live-walk 9/9 (`bin/walk-cc3-multilingual.cjs` — the engine accepts
a `pw-gift-card` type). The Q&A + the two engine return-questions
(language-switcher `wc-49143`; MiuMjau's gift-card type string) are in
`docs/ENGINE_TEAM_recommendable_signal.md`. Ship the signal with the canonical
re-backfill so the post-reload catalog classifies first-pass.

**Rationale:** "is this product recommendable?" is a **business-model decision**,
not a structural one a connector can make safely. (1) It's per-client — MiuMjau's
donation is categorised `kassitoit` (same as real food), its gift cards are a
specific plugin's type; the next store looks nothing alike. (2) Any structural
heuristic backfires: a `is_virtual()`/`is_downloadable()`/category filter would
drop the **real products of a legitimate digital-goods store**. (3) The engine
flag is the right layer — centralized, recomputed per upsert, self-healing on
re-sync, tunable without redeploying every connector. Plugin sends signal; engine
decides — not the reverse.

**Alternatives:** a plugin-side filter keyed on the gift-card plugin's product
type (rejected as the default — per-client, needs the merchant's plugin identity,
and only catches gift cards, not the donation case); name/category heuristics
(rejected — fragile, lossy, false-positives). If the engine insists on a
connector-side drop for a specific case, we require a robust per-type rule, never
a name/category guess.

**Relationships:** F3-36 (SkuResolver — the same "engine owns the keying/decision,
plugin supplies clean data" division); engine `recommendable` (migration 0039,
defense-in-depth already shipped); CC.1–CC.3 (the correctness work this closes
out); `ENGINE_TEAM_recommendable_signal.md` (the open question).

### F3-39 — catalog.delete skips objects with empty REQUIRED fields (auto-draft GC burst)

**Context:** the pilot's Event Log showed a burst of failed `catalog.delete` rows
(2026-06-14, all within one second), each `d6_item_error field=category_path` or
`field=product_url: String must contain at least 1 character(s)`. Root cause: the
catalog backfill is `publish`-only (`CatalogBackfillJob`), but `CatalogHookHandler`
fired `catalog.delete` for *any* deleted product post — including the `AUTO-DRAFT`
products WordPress's daily auto-draft GC (`wp_scheduled_auto_draft_delete`) purges
in bulk. Those have an empty `category_path` and were never ingested.

**Decision (implemented, 2026-06-14):** `CatalogHookHandler::enqueue_delete()` skips
a removal whose captured object has a blank `category_path` **or** `product_url`
(`removable()` helper; the multilingual `{lang:value}` empty-array form counts as
blank). The engine has no delete-by-key — removal is an UPSERT with `in_stock=false`
that must pass `ProductSchema` (both fields REQUIRED non-empty, §3) — so such a row
can only ever 400. The skip is silent, mirroring the existing non-product
early-return in `on_delete_product()`.

**Rationale:** a never-published artifact was never sent (backfill is publish-only),
so there is nothing to remove — the `catalog.delete` is a no-op the engine rejects.
The guard is **delete-only by design**: an empty `category_path` on a *published*
product is an intended merchant-data-gap signal the engine surfaces on the upsert
path (`CatalogPayloadBuilder::primary_category_path()` docblock), so a wire-level
guard covering upserts would suppress real signal. See LESSONS §2.12 (the
backfill-vs-incremental eligibility asymmetry) and the §2.11 contrast (this is a
*correct* pre-enqueue drop — the record cannot and should not be sent).

**Alternatives:** (a) a `post_status`-based filter on the delete hook (rejected as
primary — a published-then-trashed product is status `trash` at
`before_delete_post`, needing `_wp_trash_meta_status` archaeology to tell it from a
never-published draft; the required-field check captures the same intent more
directly); (b) a minimal sku-only delete payload (rejected — the engine's
UPSERT-only lifecycle requires the full `ProductSchema` even for removal, §3
"Lifecycle is UPSERT-only"); (c) a wire-level guard in `IngestFlusher::row_to_object`
covering both paths (rejected — would suppress the published-product data-gap
signal, above).

**Relationships:** CC.1–CC.4 / F3-38 (the catalog-correctness work this follows);
F3-36 (LESSONS §2.11 — silent pre-enqueue drops, the contrasting case);
`CatalogBackfillJob` (the publish-only filter the hook now mirrors in spirit).

### F3-40 — Trashed products stay in the catalog as `in_stock=false` (pilot orphan-join fix)

**Context:** an engine-team data audit (2026-06-17) found ~4% of pilot order lines
had no matching `catalog.sku` (~567 rows, ~265 customers) → those customers' species
can't be inferred from purchases → they get a balanced (wrong) mix. Erkki traced a
chunk of the missing product ids to the WordPress **trash** (not permanent deletion).
Mechanism: the catalog backfill is `publish`-only, and **trashing fires no catalog
hook** — `before_delete_post` is permanent-delete-only, and trashing routes through
`wp_update_post`, not a delete. So a trashed-but-once-bought product is neither
re-sent nor removed; its engine catalog row goes missing (if it was trashed before
the first ingest) or stale. The engine never deletes-by-absence (contract §3), so the
fix is to *keep* the row, marked unavailable — not to drop it.

**Decision (implemented, 2026-06-17):** a trashed product is kept in the engine
catalog as an `in_stock=false` UPSERT (the engine has no delete-by-key, so a
`catalog.delete` row IS an `in_stock=false` upsert — `IngestFlusher::row_to_object`
stamps it), so its order-history join / model training survives but it can't be
recommended. Two paths, both reusing the existing removal machinery:
- **Live (A+B / hooks):** `Bootstrap` binds `wp_trash_post → on_delete_product`
  (in_stock=false) and `untrashed_post → on_save_product` (re-sync real stock).
- **Backfill (A):** `CatalogBackfillJob` enumerates `publish` **and** `trash`; a
  published post upserts (flusher loads fresh), a trashed post enqueues a
  `catalog.delete` carrying its captured object, guarded by the now-shared
  `CatalogHookHandler::is_removable` (a blank `category_path`/`product_url` removal
  the engine would 400 is skipped, F3-39).

**The clobber guard (the subtle part):** `wp_trash_post()` calls `wp_update_post()`
to set status `trash`, which fires `save_post_product` → `on_save_product` AFTER the
removal was enqueued — re-upserting the product as `in_stock=true` and undoing the
removal. `on_save_product` now early-returns when the saved post's status is `trash`
(the trash/delete path owns a trashed post). Untrash restores the status *first*,
then fires `untrashed_post`, so a restored product is correctly NOT skipped. This was
caught by the new live-hook integration test, not in review — the two hooks firing on
one trash is non-obvious.

**Scope / known limits:** this closes the **trash** gap, not the hard-delete one — a
*permanently* deleted product's data is gone from WC, so no catalog row can be built
(the engine must tolerate such order lines; it already does, it just can't infer from
them). *(The hard-delete gap is closed later by PRO-1230: contract v1.3.0 §3b
`catalog/remove` needs only the parent product id, capturable at before_delete_post —
see the PRO-1230 entry.)* Multilingual: the collapse keys on a *published* canonical only, so a published
translation of a trashed default-language product is kept (stands in as in_stock=true);
a fully-trashed multilingual product may emit a harmless duplicate `in_stock=false`
per language (idempotent on the engine's SKU upsert). After deploy the pilot needs a
**catalog re-backfill** so existing trashed products enter the graph.

**Alternatives:** (a) include all non-publish statuses (draft/private) — rejected,
drafts are not real sales and would add noise; trash is the precise, merchant-driven
"discontinued" signal. (b) a wire-level `in_stock=false` stamp in the flusher for any
non-publish row — rejected, the `catalog.delete` event already carries that semantics
cleanly. (c) guard `on_save_product` on the canonical product's status instead of the
saved post's — rejected, it drops a published translation whose canonical is trashed.

**Relationships:** F3-39 (the `is_removable` guard this shares + extends to the
backfill); F3-36 / LESSONS §2.11 (never a silent drop — a trashed product is kept,
not dropped); the engine-team 2026-06-17 brief (Teema 2, the orphan-join evidence).

### F3-41 — Browse beacon renamed off "beacon" to dodge ad-block filter lists

**Context:** the engine saw zero real browse events from the pilot (Teema 3). After
the two-gate config was fixed (track-browsing toggle on + CookieYes marketing
consent), Erkki found the storefront request only succeeded with the **ad-blocker
off** — with it on the beacon POST was blocked (surfaced as a 404/failed request).
The cause is the literal word **"beacon"**, which is on EasyPrivacy (and similar)
ad-block filter lists. It appeared in two browser-visible places: the script URL
`dist/public/js/beacon.js` and the proxy route `/wp-json/smaily-connect/v1/beacon`.
Both were blocked by name.

**Decision (implemented, 2026-06-17):** rename the two browser-facing names to neutral
strings — the shipped script is **`dist/public/js/sc-runtime.js`** (vite entry key
`public/js/sc-runtime`, source file `public/js/beacon.ts` unchanged) and the proxy
route is **`/relay`** (`BeaconEndpoint::ROUTE`, `StorefrontBeacon` `beaconUrl`, the
`EndpointRegistry` listing, the browse live-walk, and the integration tests). The
script HANDLE became `smaily-connect-runtime`. **Internal names are deliberately NOT
touched** — the PHP classes (`StorefrontBeacon`, `BeaconEndpoint`), the JS source
files (`beacon.ts`/`beacon-core.ts`), the `window.smailyConnectBeacon` boot global,
and the `beaconUrl` config key all keep their names: they are not browser-visible as
URLs/requests, so renaming them is churn with no ad-block benefit.

**Consent is unchanged — this is not consent evasion.** The beacon stays first-party
(the merchant's own store + recommendation engine) and fully consent-gated (the admin
track-browsing toggle + the WP Consent API `marketing` category). The rename only
removes a filename/path that a *blunt, name-based* filter rule false-flagged; it does
not bypass the user's consent choice, fire without consent, or hide what the beacon
does.

**Scope / limits:** no rename is bulletproof against every list — some aggressive
rules block `/wp-json/` analytics-ish patterns broadly — but it clears the specific
EasyPrivacy match the pilot hit. Confirming the storefront POST now returns 200 with
a blocker enabled is a **manual browser check** (not automatable; the integration
test only proves the server side dispatches `/relay`). The engine contract is
untouched — `/api/v1/ingest/browse` is engine-side; only the plugin's internal WP
proxy name changed.

**Alternatives:** (a) route the POST through `admin-ajax.php` (core, rarely blocked)
— rejected, the tracker-ish `action` param can still be matched and it abandons the
clean REST namespace; (b) inline the script to avoid an external `.js` request —
rejected, larger change, and the POST URL would still need renaming; (c) leave it and
document the ad-blocker caveat — rejected, browse is the cold-start amplifier the
pilot needs working.

**Relationships:** 3.4 browse-beacon (the surface this renames); the engine-team
2026-06-17 brief (Teema 3, the zero-events evidence); CLAUDE.md "Browse" note.

### F3-42 — Order status mapping: custom statuses default THROUGH as a sale; on-hold is not a sale

**Context:** the engine-team 2026-06-19 brief (order #58922 + the earlier Teema 1)
found orders in WooCommerce **custom statuses** never reached the engine. The plugin
mapped WC status → engine enum with a 5-key **allowlist** (`completed`, `processing`,
`on-hold`, `cancelled`, `refunded`); any status absent from it — including every
merchant/shipping-plugin custom status like `label-printed` / `shipped` / `pakikaart-
prinditud` — mapped to `''` and was **silently dropped** (the live hook skipped it AND
the backfill's `status IN (allowlist)` filter excluded it). The engine accepts only the
strict enum `completed|processing|cancelled|refunded`, so a raw custom status can't pass
through verbatim.

**Decision (implemented, 2026-06-19, reverses part of F3-22):** invert the model to a
**denylist**. `map_status()` returns `''` ONLY for an explicit non-sale set
(`pending`, `on-hold`, `failed`, `checkout-draft`, `draft`, `auto-draft`, `trash`);
everything else maps to a sale — the explicit `STATUS_MAP` entries
(`completed`/`processing`/`cancelled`/`refunded`) keep their target, and **any other
(custom/unknown) status defaults to `processing`** (`DEFAULT_SALE_STATUS`). Conservative
default: a confirmed purchase *in progress*, not `completed`, so a custom fulfilment
state isn't over-claimed as finished; if the order later truly completes, the live hook
re-sends it as `completed`. The order backfill's single-source filter flips to
`OrderPayloadBuilder::non_sale_wc_statuses()` and `status NOT IN (denylist)` (CC-9 — the
hook and backfill still can't drift); the flusher's `map_status===''` skip is the safety
net for any non-sale status the SQL prefixing doesn't catch.

**on-hold reversal:** F3-22 mapped `on-hold → processing` ("purchase intent"). The engine
team's brief states on-hold is **not yet a sale** ("pole veel müük" — payment not
captured). Decision (Erkki, 2026-06-19): **follow the engine — on-hold is non-sale**
(moved into the denylist). Safe: an on-hold order that gets paid transitions to
processing/completed and is sent then; one that never pays is correctly never sent.

**Residual risk (accepted):** a FUTURE merchant with a custom **non-sale** status (e.g.
`quote-requested`, `fraud-review`) would have those sent as a sale. MiuMjau's custom
statuses are fulfilment (label-printed/shipped = real paid sales), so it's safe for the
pilot; a small `apply_filters` on the map can tune it later if a real client needs it
(not built now — no added complexity for the pilot).

**Alternatives:** (a) a per-merchant filterable status map — rejected for now as added
complexity the pilot doesn't need (the denylist default covers MiuMjau); (b) keep the
allowlist and tell the merchant to use only standard statuses — that was the prior
"client fixes WC-side" punt (Teema 1), and it kept biting (#58922) because merchants use
custom shipping statuses as terminal states.

**Relationships:** F3-22 (the status mapping this reverses for on-hold + customs); the
engine-team 2026-06-19 brief; CC-9 (the hook↔backfill single-source rule preserved);
F3-43 (the same brief's deleted-product fix, below).

### F3-43 — A deleted-product order line is kept (never dropped) so the order is never lost

**Context:** same 2026-06-19 brief, the #58922 symptom. A guest order with a **deleted**
product was marked **"sent"** by the plugin but **never reached the engine** (no POST at
all). Root cause: `OrderPayloadBuilder::items()` keyed each line via `SkuResolver`; for a
deleted product whose stored ids current WC **zeroes** on permanent deletion,
`resolve_order_item()` returned `''` and the line was **dropped**. Every line dropping →
empty `items[]` → `OrderFlusher::row_to_object` returned `null` → `AbstractD6Flusher`
**`mark_sent()`** the row WITHOUT POSTing (the F3-36 "terminal skip"). So the order
silently vanished AND showed "sent". This is the F3-36 design working as written — but a
"clean skip" is still **silent loss** of the order's RFM/tier value.

**Decision (implemented, 2026-06-19, reverses F3-36 for the deleted-line case):** a
product line is **NEVER dropped**. `SkuResolver::resolve_order_item()` no longer returns
`''`: when both stored ids are zeroed it keys the line on the **order-item id**
(`wc-oi-{item_id}`) — guaranteed non-empty and unique (chosen over the brief's literal
`wc-{product_id}`=`wc-0`, which would collide across deleted lines). The qty / unit_price
/ line_total already come from the line-item **snapshot**, which survives product
deletion, so the line is fully serialisable. The `wc-oi-…` key won't match a catalog row
(no item-level species inference), but the order **ingests** (RFM / tier) — the accepted
trade-off; the order surviving is what matters. `items()` drops its `sku===''` guard.

**Scope:** this closes the *deleted-product line* gap. The flusher's empty-items
terminal-skip REMAINS, but now only fires for a genuinely **product-less** order (only
shipping/fee lines) — the only remaining empty-`items[]` case. Permanently-deleted
products still have no catalog row (F3-36); the order line keys synthetically regardless.

**Silent-"sent" note:** the engine's D6 `errors[]` → `mark_failed` path (F3-18 /
AbstractD6Flusher) was already correct — #58922 never hit it because the terminal skip
`mark_sent` before any POST. Fixing this means the order now POSTs; a genuine engine
rejection is then marked FAILED + retryable, as designed. (Storing the real request
payload + response in the Event Log — the brief's Problem 3 — is a separate follow-up.)

**Relationships:** F3-36 (SkuResolver + the terminal-skip this reverses for deleted
lines); F3-18 (the D6 errors path that already marks FAILED); the engine-team 2026-06-19
brief (#58922); LESSONS §2.11 (never a silent drop — now upheld for orders too).

### F3-44 — Event Log stores the real request payload + engine response per row

**Context:** the engine-team 2026-06-19 brief, Problem 3. The Event Log "Details"
panel showed `Payload: []` for every row — order/catalog rows enqueue an EMPTY
payload (the flusher builds the wire object fresh at send, F3-8), so the stored
`payload` column is empty, and only a short `last_error` code is kept. So a merchant
(or we) couldn't answer "what did we actually send, and what did the engine reply?"
— and the #58922 terminal-skip read a bare "sent" with no trace it never POSTed.

**Decision (implemented, 2026-06-19):** capture the send-time exchange per row, on
BOTH durable queues, and surface it in Details.
- **Schema (migration 007):** two nullable `LONGTEXT` columns on
  `smly_rec_event_queue` AND `smly_plus_event_queue` — `sent_payload` (the exact
  JSON POSTed; null when nothing was sent) and `last_response` (a small JSON
  summary `{http, outcome, error?}`). A new `IngestQueue::store_exchange` /
  `EventQueue::store_exchange` writes them (separate from `mark_*()` so those
  signatures — and the test doubles overriding them — stay unchanged).
- **Rec-engine capture (clean):** `AbstractD6Flusher` is the single choke point —
  after `send($objects)` each row has its sent object + the batch response. It
  stores `accepted` / `rejected{error}` / `http_error` per row, and on a
  terminal-skip stores `sent_payload=null, last_response={outcome:"skipped"}` (the
  visibility fix for the silent "sent").
- **Smaily capture:** the legacy queue is dispatch-based, so the `Smaily\Client`
  records its `last_exchange()` in the single `request()` chokepoint and the
  `Flusher` reads it (via `try/finally`, so a throwing call is still captured) and
  stores it; a no-call event records a skip marker.
- **NEVER stores the Authorization header** — only method/endpoint/body + reply.
- **All rows, success included** (the brief wants "what landed", not just failures),
  each field **trimmed to ~10 KB**; pruned with the row by `QueueJanitor` (sent 30 d
  / failed 90 d), so the table stays bounded.
- **Details UI:** the modal shows **"Request sent to the engine"** + **"Engine
  response"** (pretty-printed) above the enqueued payload; `/events/detail` returns
  the two fields ('' for pre-migration rows / not-yet-flushed rows).

**PII note:** `sent_payload` carries the same data already in WC (email / order
fields); it's transient + janitor-pruned — no new GDPR surface beyond the queue.

**Already solved, NOT rebuilt:** the per-item `errors[]` → `mark_failed` split
(F3-18) was correct; this adds the *visibility* layer on top, it doesn't change the
state machine.

**Relationships:** F3-8 (why order/catalog rows enqueue empty — the build-fresh
decision this works around); F3-18 (the D6 errors path); 3.10.0 (the Event Log this
extends); the engine-team 2026-06-19 brief (Problem 3); F3-43 (the silent-"sent"
the skip marker now exposes).

### F3-45 — Legacy admin settings-PAGE removed; widget + Settings link relocated (Faas 2)

**Context:** the legacy `Smaily_Connect\Admin` settings page is redundant — all
configuration now happens in the new wizard/Settings UI. Removing the legacy view
layer (Faas 2; Faas 1 was the F1 Settings-link repoint). **Hard constraint (Erkki):
must not break the ~2000 existing installs** — only remove what's unneeded under the
new plugin or trivially replaceable with 100% functionality preserved.

**Audit (per-file, before any deletion) — the legacy `Admin` class was NOT pure views:**
- It also registered two LIVE behaviors: the **subscription widget** (a classic
  `WP_Widget` merchants may have placed) and the **Plugins-page "Settings" link**
  (F1 repointed it to the new UI). Deleting `Admin` would silently drop both.
- Its `validate_api_credentials_after_save` hook **early-exits on `REST_REQUEST`**, so
  it did nothing for the new UI's saves — it only served the legacy form. Dead with the page.
- `Notices` + `Notice_Registry` are **NOT dead**: `migrations/upgrade-1-3-0.php` calls
  `Notice_Registry::add_notice()` for the CF7 upgrade notice. Removing them would break
  the 1.3.0 upgrade path. **Kept.**
- **No config is stranded:** the new `SettingsEndpoint` reads AND writes the SAME legacy
  option keys the old page used (`smaily_connect_api_credentials`, `_subscriber_sync_*`,
  `_abandoned_cart_*`), and `EnvDetector` feeds them to the new wizard. The kept legacy
  integrations (`Subscriber_Synchronization` / `Cron` / `Cart`) keep reading those keys —
  now written by the new UI.

**Decision (implemented, 2026-06-19):**
- **Removed** (redundant + non-navigable view layer): `admin/smaily-admin.class.php`,
  `smaily-admin-{settings,renderer,sanitizer}.class.php`, the `smaily-admin-*.php`
  settings partials, `admin/css|js/smaily-admin.*`, the dead `validate_api_credentials`
  hook, and the now-moot hide-legacy-menu shim (2.H.3 — nothing left to hide).
- **Relocated** (so merchants lose nothing) into `smaily.class.php::init_classes`: the
  `widgets_init` → `register_widget(new Widget(...))` and the `plugin_action_links`
  Settings link (→ `admin.php?page=smaily-connect-settings`).
- **Kept** (live dependents): `Notices` + `Notice_Registry` + `partials/notices/*` +
  `smaily-admin-notice.php` (1.3.0 upgrade notice), `Widget`, the WooCommerce integration
  classes, `Cypher`, `Options`, `Rss`, `Cart`, CF7 / Elementor.
- Fixed a stale `@param Admin` docblock in `smaily-api.class.php` (the only other `Admin`
  reference — a doc-only leftover; the constructor never took an Admin).

**Safety:** every removed piece is either unreachable under the new plugin (the legacy
menu is hidden + the Settings link points at the new UI) or redundant (new UI owns the
same option keys). The two live behaviors are preserved verbatim. Gates: ci:strict
exit=0; integration OK 114 (the plugin boots with the legacy admin gone).

**Relationships:** F1 (the Settings-link repoint this completes); 2.H.3 (legacy-menu
hide, now removed as moot); the coexistence model (`setup_completed`) — untouched.

### F3-46 — Server-side landing capture of rec attribution (decoupled from the browse beacon)

**Context:** the engine brief `PLUGIN_BRIEF_woo_rec_link_redirect.md` (rev 2,
2026-06-26): production shows **374 orders / 30 days, 0 carry `smaily_rec_id`** — rec
attribution is empty. The rec link (built by engine `lib/sync/url-builder.ts`) lands on
the merchant's own product page carrying `utm_source=smaily`, `utm_content=<rec_id>`,
**and** the engine's own `smaily_rec` / `smaily_vt` / `smaily_ctx` params. The
capture→stamp→send chain ALREADY existed end-to-end: `HookHandler::
save_attribution_cookies_to_order()` reads cookie `smaily_rec_id` → order meta
`_smaily_rec_id`, and `OrderPayloadBuilder` forwards it. The ONLY missing piece was the
**producer** of that cookie: the sole capture path was client-side JS
(`StorefrontBeacon` → `beacon-core.ts captureUrlParams`), which only runs when
browse-tracking is enabled AND marketing consent is granted AND `sc-runtime.js` isn't
ad-blocked — so on the pilot it never fired and the `rec_id` fell on the floor.

**Decision (implemented, 2026-06-26):** add `Integrations\WooCommerce\LandingCapture`
on `template_redirect` — a server-side producer of the SAME cookies the checkout
stamping already consumes. Zero downstream change (no HookHandler / OrderPayloadBuilder
edit). Specifics:
- **Source param — follow the contract, not the brief literally (Erkki).** The brief
  proposes capturing `utm_content` into new `smre_rec`/`smre_vid` cookies (90d/365d). The
  byte-synced contract (§"Cookie names") instead sources `smaily_rec` → `smaily_rec_id`
  (30d) / `smaily_vt` → `smaily_rec_uid` (365d) / `smaily_ctx` → `smaily_rec_ctx` (30d),
  and even states *"the engine does NOT use `utm_content` for `rec_id`"* (which the engine
  CODE contradicts). We capture **`smaily_rec` primarily** and accept **`utm_content` only
  as a fallback guarded by `utm_source=smaily` + a strict uuid shape** (utm_content is a
  shared GA/ads param). Cookie names + TTLs come from the stored engine config (same
  source `StorefrontBeacon` reads), so the server-set and JS-set cookies are identical.
  **Feedback sent to the engine team** to realign their brief + the §162 contract note
  with `url-builder.ts`. **RESOLVED 2026-06-26:** the engine published brief **rev 3**
  (`re` commit `98b472d`) aligned byte-for-byte to the contract (cookie/param table now
  `smaily_rec`→`smaily_rec_id`/`smaily_vt`→`smaily_rec_uid`/`smaily_ctx`→`smaily_rec_ctx`,
  `utm_source=smaily` guard), and **`utm_content=rec_id` was removed engine-side**
  (`url-builder.ts`: rec_id travels only in `smaily_rec`). Our shipped capture matches it
  exactly; the `utm_content` fallback is now dormant (guarded, harmless — kept as a
  forward-compat safety net). Contract md5 unchanged (the brief moved to the contract).
  Engine also shipped a **domain-less `rec_N_link_path`** (`re`
  `DECISION_rec_link_domainless.md`): the merchant template carries the literal domain +
  `{{rec_N_link_path}}`, so the `smaily_*` params STILL land on the shop on every click —
  **no plugin change** (our capture works across both the old `rec_N_link_url` and new
  `rec_N_link_path` template forms). That decision names the **shop-side plugin path as the
  preferred-accuracy attribution route** (a baked `smaily_rec` is immune to the nightly
  recommendation re-sync that can shift a slot→rec reconstruction).
- **Consent — captured UNCONDITIONALLY when connected (Erkki).** Recommendation
  attribution is a first-party functional signal (a rec_id uuid + an opaque visitor
  token, not PII on their own); tying engine recommendations to real purchases is the
  whole point, and it must not depend on the browse-beacon's toggle/consent/ad-block
  path. **Browse telemetry (Layer 2) stays separately gated** behind the browse-tracking
  toggle + marketing consent (`StorefrontBeacon` unchanged). Escape-hatch: the
  `smaily_connect_capture_attribution` filter (default true) disables it.
- **Gates:** `is_connected()` (rec links exist only for a connected tenant; orders ingest
  only then) + trigger-param presence (fast bail on every ordinary request) +
  `headers_sent()` guard (a `headers_already_sent()` seam so tests can exercise the write
  path past PHPUnit's own output). Cookie attributes mirror the contract: `Path=/`,
  Domain = `COOKIE_DOMAIN`, `SameSite=Lax`, `Secure` on https, `HttpOnly=false` (the
  beacon proxy reads them client-side). `$_COOKIE` is kept coherent within the request.
- **Scope:** Layer 1 only. NOT built: the brief's optional redirect endpoint (§3.4,
  YAGNI), the Layer-2 site-wide `smre_vid` generation, and a fix for the pre-existing
  block-checkout (`woocommerce_store_api_checkout_order_processed`) stamping gap (classic
  checkout only, as today). **UPDATE 2026-06-30 (`e55514d`): the block-checkout stamping gap
  is now FIXED** — it was THE cause of the MiuMjau `smaily_rec_id`-empty regression (MiuMjau
  runs block checkout, so the cookie was captured but never stamped onto the order;
  `is_connected=true`, `order.upsert` 200, but no attribution fields). `HookHandler::
  on_block_checkout_order_processed` (bound to `woocommerce_store_api_checkout_order_processed`)
  now stamps the same cookies on block orders. Past orders can't be plugin-backfilled (the
  checkout cookie is gone) — retroactive attribution is engine-side via the action-log (click
  `value` carries `smaily_rec`, match to order by email+time, ~30-day window). Full write-up:
  `docs/RESPONSE_smaily_rec_capture_regression.md`.

**Gates:** ci:strict exit=0 (unit 391 +17, JS 158, PHPStan clean, PHPCS 0 errors);
integration OK 119 (+5). **Browser-moment verification (does the cookie actually set on a
real rec-link landing, and does a test purchase carry `smaily_rec_id`) is a manual pilot
check** — like the browse render-timing, the server-side path is unit+integration-proven
but the real click→land→buy→attribute round-trip is pilot-verified.

**Relationships:** F3-41 (`StorefrontBeacon` / the JS `captureUrlParams` this complements
server-side); the contract §"Cookie names"; F3-42/F3-43 (the order ingest this feeds);
`PLUGIN_BRIEF_order_sync_reliability.md` (the order-side receiver).

### F3-47 — Contact-sync language goes through `ContactLanguageResolver` (Prike `en`-leak)

**Context:** a managed (non-pilot) client store (Prike) runs the upstream Smaily WP plugin
+ two Make automations as a belt-and-braces contact sync. ~1000 contacts drifted to
language `en`. Root cause: the upstream plugin's "Daily Automatic Subscriber
Synchronization" cron (`Cron::smaily_sync_subscribers` → `Data_Handler::get_user_data` →
`Helper::get_user_language_code`) falls back, for any subscriber lacking a stored
per-user language meta, to `get_current_language_code()` — which the helper's OWN docblock
flags as **cron-unsafe**: in a cron/Action-Scheduler request it returns `get_locale()` =
the **WP site locale**. Prike's WP locale is `en` while its real content default (WPML
`wpml_default_language`) is `et`, so the daily cron mass-pushed `en` and re-clobbered it
every tick, beating the Make automations (whose language logic was actually **correct** —
they read `_user_preferred_language` user meta and `wpml_language` order meta, default
`et`). Our own new live-sync path had a sibling latent bug: `HookHandler::
detect_language_for_user/order` used `get_user_locale()` (admin-UI locale; defaults to the
site locale for front-end customers; emits full `en_US`), and `build_contact_payload`
ALWAYS set the `language` key (empty would wipe the Smaily value).

**Decision (implemented, SP-A 2026-06-30):** one shared `Support\ContactLanguageResolver`
(CC-1) is the single source of the Smaily `language` code for both live-sync and (next
sub-PRs) the backfill / daily refresh. It mirrors the Make automations' (correct) sources
and is **context-independent by construction** (no `ICL_LANGUAGE_CODE` /
`pll_current_language` reads — same answer in a cron tick as an HTTP request):
- `for_user`: `_user_preferred_language` user meta → most-recent order's `wpml_language`
  (injectable provider; `wc_get_orders` limit 1) → the multilingual plugin's configured
  default via `DetectorFactory` (WPML `wpml_default_language` = `et`) → site-locale short
  code. The latest-order tier makes the resolver robust **without a data check on the live
  store** (we can only ship the plugin, not inspect their DB): the non-`et` minority who
  ordered in their language is preserved, not flattened to the `et` default.
- `for_order`: order `wpml_language` → the registered customer's `_user_preferred_language`
  → default → site locale.
- **Normalise to the short code** (`en_US` → `en`); Smaily + Make both key on `et`/`en`.
- **Omit on empty** — resolver returns `''` and the caller drops the `language` key
  (absent leaves Smaily's value intact; empty wipes — never wipe). `HookHandler::
  build_contact_payload/build_automation_payload` + the first-order payload now add
  `language` only when non-empty.
- Filterable: `smaily_connect_contact_language` (final override) +
  `smaily_connect_user_language_meta_key` (redirect the user-meta lookup).
- **Active-language clamp (Erkki):** a resolved code that is NOT one of the site's
  currently-active languages (`DetectorFactory::get_detected_languages()`) is
  clamped to the configured default. The resolver never *invents* a language —
  it only echoes WPML's own values — but this hard-guarantees that dirty history
  (a stray `ru` `_user_preferred_language` / old order `wpml_language` from a
  language the store has since removed) can't spawn a contact list that shouldn't
  exist. No-op when the detector can't enumerate (empty allowlist); the filter
  runs AFTER the clamp, so an explicit override is still the last word.
- **`get_user_locale()` is deliberately NOT a source** — it reintroduces the very
  site-locale leak this fixes.

**Decisions taken (Erkki, 2026-06-30):** (1) keep syncing **all registered customers
regardless of consent** — the new path already does (no opt-in gate); (2) **guests are not
synced** (no account → no contact) — already true; (3) **never send `is_unsubscribed`** —
Smaily owns consent; the new path already omits it (the LEGACY live path sent
`is_unsubscribed=0`, resetting opt-out — a second reason to migrate Prike off it). The
goal is to retire the Make data-sync and let our Connect plugin own the correct contact
sync (contact sync is gated by the email wizard `setup_completed`, independent of the
rec-engine — so it can ship to Prike before they go on the engine).

**Gates:** ci:strict exit=0 (PHPUnit 404 +13, JS 158, PHPStan clean, PHPCS 0 errors);
integration OK 119. The round-trip on the real store (does the corrective backfill move
the ~1000 from `en` to their true language) is a **manual post-deploy check** — we ship
the plugin, the merchant runs it; the resolver is unit-proven.

**Scope:** SP-A (resolver + HookHandler wiring) + SP-B (DONE) — `BackfillJob::
build_subscriber_payload` now adds `language` via the SAME resolver (omit-on-empty), so a
one-off backfill run IS the corrective mass re-sync: every existing contact is re-sent with
the resolver's language instead of the stale `en` the cron pushed. Pending sub-PRs: SP-D
(replace the legacy daily-cron bridge `on_contact_sync_tick` with a correct refresh so the
`en`-clobber stops — until then the backfill fixes contacts but the daily cron can re-drift
them), SP-E (regression test locking `is_unsubscribed` out of the payload), SP-G (cutover:
Connect plugin → wizard → Make data-sync off). Note: at the unit level the backfill resolves
through the single-language SiteLocale detector, so the active-language clamp collapses any
non-site code to the default — the multi-language meta/order priority is proven in
`ContactLanguageResolverTest` (mock detector), not re-tested at the backfill seam.

**Relationships:** CC-1 (single-source-of-truth — joins `SubscriberPayloadBuilder` /
`SkuResolver` / `IsoDate`); the legacy `Helper::get_current_language_code` cron-unsafe
docblock (the bug it routes around); the coexistence map (`setup_completed` email-wizard
gate that owns this path vs the rec-engine `is_connected()` gate).

### F3-48 — Contact-sync mode engine: named presets keyed to lawful basis (DESIGN — approved 2026-06-30)

**Context:** managed/pilot stores want different contact-sync behaviour driven by their
lawful basis for marketing and store shape. Three real cases: Prike wants **all** customers
under *legitimate interest* (legacy couldn't — its cron only synced `user_newsletter=1`,
which is the missing-contacts root cause Make papered over); Client 2 wants **consent**
(opt-in only) with Smaily↔WP reconciliation; Client 3 (MiuMjau-shaped) wants **checkout
opt-in only**, guests, send-only. The F3-47 language fix is mode-independent and already
shipped; the *audience + sync-back + automation-consent* posture is where stores diverge.

**Decision (Erkki, 2026-06-30):** a contact-sync **mode engine** built as a few **named
presets** (not a combinatorial toggle matrix — presets prevent incoherent/unlawful combos
and map to how merchants think). Three presets, internally factored as
`legal_basis` (legitimate_interest | consent) + the `include_guests` sub-option:
1. **All customers (legitimate interest)** — all registered customers, never send
   `is_unsubscribed`, no sync-back, automation `force_opt_in=false` by default
   (an explicit unsubscribe is honoured even here — GDPR Art. 21) + an advanced
   preset-1-only "Force opt-in on automation triggers" toggle to override.
2. **Subscribers only (consent)** — DEFAULT — only `user_newsletter=1`; opt-in → subscribe,
   WP opt-out → `is_unsubscribed=1`; daily Smaily↔WP reconcile **both** directions
   (leavers + returners); automation `force_opt_in=false`.
3. **Checkout opt-in only** — no accounts, checkbox-only (guests), send-only,
   `force_opt_in=false`.

**Rationale / key points:** (a) **Default = consent** is both lawful-safe AND back-compat
(matches legacy's `user_newsletter=1` filter — upgrading never silently broadens audience).
(b) `include_guests` is a checkbox, default OFF (Erkki). (c) Reconcile is **bidirectional**
(Erkki). (d) **Automation `force_opt_in` is mode-driven** — the contact-sync posture also
governs whether a welcome/first_order/abandoned_cart trigger re-subscribes; this unifies a
current inconsistency (new `AutomationRouter` always `true`, legacy abandoned-cart passes
`false`). `force_opt_in` is an undocumented Smaily param — being added to `../re/docs`.
(e) UI = named radio-card presets in `Step2Subscribers` + a `Banner tone="warning"` for the
legitimate-interest preset, matching the existing `Card`/`Toggle`/`Banner` style.
(f) Architecture is CC-1: one `ContactSyncMode` policy core consumed by `HookHandler`,
`BackfillJob`, the SP-D `on_contact_sync_tick` cron takeover, and `AutomationRouter`. The
old unsubscribe-pull is NOT dead — it becomes preset 2's `ContactReconciler`.

**Scope boundary:** does NOT touch rec-engine customer ingest (`CustomerHookHandler`, a
different destination gated by `is_connected()`); abandoned-cart keeps its own enable, only
its automation `force_opt_in` is mode-driven.

**Status:** DESIGN approved (Erkki, 2026-06-30) — full design in `docs/CONTACT_SYNC_MODES.md`;
both open questions resolved (preset-1 `force_opt_in` defaults `false` + advanced toggle;
preset labels kept). **Implementation started: F3-48.1 DONE** — `ContactSyncMode` (preset →
policy) + `ContactAudience` (mode-aware "is this a contact?"), wired into the `HookHandler`
live `contact.sync` gate + the `BackfillJob` audience filter. Default `consent` now narrows
the new path's live + backfill audience to `user_newsletter=1` (matches legacy; legitimate
interest syncs all). **F3-48.2 DONE** — `ContactReconciler` (Smaily→WP marketing-consent
mirror, consent mode only) + `Client::get_action_log()`/`list_contacts()`. Delta-first (Erkki
resource concern): the standing `reconcile()` polls the Smaily action-log (`history.php` +
`since_seq_id` cursor) for `optin`/`optout`/`delete`/`complaint` deltas — O(changes), light on
shared hosting; `rebaseline()` (full `list=1` pull) is the occasional re-baseline only.
Marketing-only (never touches profiling `smaily_rec_profiling`). Mirrors the engine's action-log
approach (`re/docs/CONTACT_RECONCILIATION_DESIGN.md`, `re/docs/smaily-api/reference/action-log.md`);
see `docs/CONSENT_STRATEGY_COMPARISON.md`. **F3-48.3 DONE** — cron takeover:
`Bootstrap::on_contact_sync_tick` no longer fires the legacy buggy
`Cron::smaily_sync_subscribers` mass-send (the F3-47 site-locale clobber, now orphaned/dead);
it runs `ContactReconciler::reconcile()` (consent mode) + a mode-aware refresh via a
non-clearing `BackfillJob::start(false)`, guarded by `BackfillJob::should_start_refresh()`
(skip while a walk runs / re-arm once per freshness window). API errors swallowed so the tick
never fails; abandoned-cart bridge untouched. **F3-48.4 DONE** — `AutomationRouter::
trigger_automation` now passes `ContactSyncMode::automation_force_opt_in()` instead of the
hard-coded `true` default, so welcome/first_order/abandoned_cart honour the mode's consent
posture (consent/checkout → never re-subscribe; legitimate interest → only with the advanced
toggle). **F3-48.5 DONE** — Settings/wizard UI: a "Contact sync mode" Card in `Step2Subscribers`
(3 radio-card presets + legitimate-interest warning Banner + `include_guests` checkbox +
preset-1-only force-opt-in toggle), wired end-to-end (wizard reducer actions →
`buildTabPayload`/`hydrate`/`settings-reducer` → `SettingsEndpoint::save_subscribers` validated +
`EnvDetector::saved_settings` boot). Merchants now pick the preset in the UI (no longer
option-only). **F3-48.6 DONE** — consent opt-in/opt-out propagation (WP → Smaily): a
`user_newsletter` meta-transition handler (consent mode, bound to `update_user_meta` +
`add_user_meta`, reads the pre-write old value) enqueues a separate `:consent` contact-sync row
— opt-in → `is_unsubscribed=0`, opt-out → `is_unsubscribed=1`; the routine data sync never sends
`is_unsubscribed` (so a profile edit can't resurrect a Smaily unsubscribe between reconciles).
Also fixed a **latent bug found here**: the `Flusher` dropped the top-level `language` on the live
contact-sync path (only the backfill ever sent it) — now forwarded into the Smaily row alongside
`is_unsubscribed`. Regression locks added. Remaining: Prike cutover (.7) + thorough end testing.
Supersedes the earlier ad-hoc SP-D/SP-E plan (the cron takeover + `is_unsubscribed` lock fold
into this engine). Builds on F3-47.

**Relationships:** F3-47 (the mode-independent language resolver this builds on); CC-1; the
coexistence map (`setup_completed` wizard gate); the legacy `Cron::smaily_sync_subscribers`
(the buggy mass-send this retires) + its unsubscribe-pull (becomes the reconciler).

### F3-49 — Browse events carry `smaily_visitor_token` (cold-start identity), NOT rec_id/email; browse attribution stays order-signal-driven

**Context:** the browse beacon (`rec-engine-client.ts` `enrich()`) sent per event ONLY `session_id`
— no `smaily_visitor_token`/`smaily_rec_id`/`smaily_ctx`/`customer_email` — although the cookies
hold them, the `/relay` whitelist (`BeaconEndpoint::EVENT_FIELDS`) accepts them, contract §6
identity-resolution + retroactive-binding reference them, and F3-27 pt1 assumed browse carries
identity. Net effect: every browse_event resolved to §6 path-4 (anonymous, session-only); the §6
retroactive-binding-via-browse never fired; the async order-attribution path-3 ("session_id →
browse_events *with a rec_id link*") was inert. Raised by Erkki (2026-07-01) questioning an
over-stated claim that a purchase "loses" rec attribution; the investigation logged it as an open
cross-team question rather than assume.

**Engine-team answer (2026-07-03):** browse does NOT feed attribution. Order-match hierarchy is
order `smaily_rec_id` → visitor_token → email-click → browse; the block-checkout stamping fix
(v3.2.0) means path-1 catches the same case more strongly than browse ever could. The
`direct`/`exact_later`/`indirect_*` classes come from the email click + order `smaily_rec_id`;
browse would at best yield the softest `assisted_view`, and only for an already-identified
customer. Guest-browse non-binding is an accepted v1 limitation — login-merge (§7, F3-27) is the
intended binding path.

**Decisions:**
1. **No browse-level attribution.** Browse attribution rides ORDER signals; `smaily_rec_id` /
   `smaily_ctx` / `customer_email` are deliberately NOT put on browse events (redundant +
   data-minimization). Enforced CLIENT-side (`enrich()` never adds them) — the `/relay` whitelist
   still lists them (a Shopify/other wrapper may send them), so the minimization is the client's.
2. **DO carry `smaily_visitor_token` on browse events** — opaque, low-PII, omit-on-empty (mirrors
   `session_id`; most organic visitors have no token). Value is NOT attribution but future
   **cold-start personalization** (e.g. category inference from browse before the first purchase):
   data accumulates now so a v1.1 feature doesn't start from zero. The engine binds the browse row
   to the customer via the token; ingest already accepts it (whitelist + `with_customer_match`
   sub-count).
3. **Profiling opt-out on the token / external_id path is ENGINE-side** (engine-confirmed,
   server-side enforced from 2026-07-03): an opted-out contact's browse event is never bound to a
   customer on any path (stays anonymous) and identity-merge is a no-op for it. The plugin's
   email-based `ProfilingConsent` gate stays the FIRST filter; the plugin can't map
   visitor_token→email locally (engine-issued), so the token-path opt-out is inherently the
   engine's responsibility, not a plugin blocker.
4. **Guest-browse non-binding + browse-session-only = accepted v1 limitation** (Q3), documented
   here + STATUS "Known deferred items".

**Rationale:** the money question (which rec drove which purchase) is order-attributed and
unaffected; the only thing browse could add is soft assisted-view, which the engine deprioritized.
Sending just the opaque token (not rec_id/email) captures the cold-start value at minimal PII cost,
within the existing consent model.

**Alternatives rejected:** wire rec_id + email onto browse (engine says redundant; data-min);
leave browse identity-blind entirely (forfeits free cold-start data the engine asked for); build
browse-based attribution (engine confirmed no value over order signals).

**Relationships:** F3-24 (browse-beacon architecture — server proxy + whitelist), F3-27
(identity-merge — the intended binding path; §6 retroactive-binding), F3-46 (server-side landing
capture — where ORDER attribution actually originates), the §6 identity-resolution flow, §14.2
("engine consumes browse post-MVP").

### F3-50 — Browse consent stays on the standard WP Consent API; CookieYes is a config fix + an admin advisory, NOT vendor code

**Context:** MiuMjau had browse-tracking on and a healthy connection but the engine got 0
`/api/v1/ingest/browse` requests. The beacon gates fail-closed on the WP Consent API
(`beacon-core.ts` `detectConsent()` sends only when `window.wp_has_consent(category) === true`).
Live probe on the storefront: `typeof window.wp_has_consent === 'undefined'` — no signal ⇒ gate
closed ⇒ 0 events, no error (LESSONS §2.15).

**First (wrong) fix — 3.3.1, reverted.** On the assumption "CookieYes doesn't support the WP
Consent API", 3.3.1 added a CookieYes-specific fallback that read the `cookieyes-consent` cookie
directly. Erkki challenged it on two grounds — (a) per-vendor consent code is a maintenance
treadmill we deliberately avoided by standardising on the WP Consent API, and (b) CookieYes's own
docs say it DOES integrate the WP Consent API. Both correct.

**Root cause (corrected).** CookieYes integrates the WP Consent API, but ONLY when the free
companion **"WP Consent API" plugin** (wordpress.org `wp-consent-api`) is installed + active — that
plugin is what defines `window.wp_has_consent` and the `wp_has_consent()` PHP function; CookieYes
registers consent INTO it (CookieYes `Advertisement` → WP Consent API `marketing`, matching the
beacon's default category). MiuMjau simply lacked the companion plugin. So the gap is a **missing
companion plugin (config), not a CookieYes incompatibility** — and the standard path works once
it's installed, with zero plugin code.

**Decisions:**
1. **Revert the 3.3.1 CookieYes cookie-parse.** Browse consent stays purely on the WP Consent API
   (+ the `consentOverride` JS hatch + fail-closed default). No per-vendor consent code in core.
2. **The line for future CMPs:** WP Consent API is the standard/default (0 per-vendor cost); a
   bespoke adapter is justified ONLY for a dominant CMP or a live client's actual CMP that will not
   adopt the API — NOT the long tail. CookieYes does adopt it, so it needs no adapter at all.
3. **Ship an admin advisory instead** (`NotificationManager::needs_consent_api_notice`): browse on
   + connected + `! function_exists('wp_has_consent')` → a dismissible `notice-warning` telling the
   merchant to install the free WP Consent API plugin. CMP-agnostic, standard-aligned, catches the
   silent-0-events trap for every future client without vendor code.
4. **MiuMjau fix = install the companion plugin** (wp-admin, no server file access — Erkki has
   none). A mu-plugin `consentOverride` unblock was ruled out for the same reason.

**Rationale:** the ecosystem is designed so ONE integration (WP Consent API) covers all compliant
CMPs; parsing a vendor's cookie both duplicates that and takes on its format churn. The advisory
moves the fix to configuration (install a plugin) rather than code we maintain.

**Alternatives rejected:** keep the CookieYes cookie-parse as a fallback (reintroduces the
maintenance debt the revert removes, and can mask the correct config); a merchant-configurable
consent-mapping UI (a larger epic — the long-tail answer if bespoke demand ever grows, not needed
now, YAGNI); tell CookieYes merchants nothing (leaves the silent-0-events trap).

**Relationships:** F3-24 (browse-beacon architecture — the fail-closed WP Consent API gate), F3-49
(the visitor_token this unblocks once browse fires), F3-30 (NotificationManager — the admin-notice
infra reused), LESSONS §2.15 (the assume-vs-live-probe scar, now with the deeper correction that
the standard already covered CookieYes).

### F3-51 — Automations config (T2): the engine is the authority — the plugin is a stateless proxy, no local copy, no duplicate validation

**Context:** the engine can enrol Smaily contacts into merchant-built automations at the right
moment (replenishment due, win-back, …); the plugin's role is CONFIGURATION only (contract
v1.1.0 §11–§13, synced 9ec2ff8). T2.1 is the PHP layer: `Client::automations_catalog()/
automations_config()/put_automations_config()` (+ `PATH_AUTOMATIONS_*` fallbacks) and the
admin REST proxy `AutomationsEndpoint` (GET catalog / GET config / PUT config). The React
settings UI is the next sub-PR (T2.2).

**Decisions:**
1. **The engine's GET is the source of truth — the plugin stores NOTHING.** No wp_options
   copy, no transient, no catalog cache: the UI re-reads via GET on every open. A local copy
   would drift against engine-side operator edits (`configured_via: "admin"`) and against
   catalog changes that ship with engine deploys (§11 says render dynamically — a new trigger
   must not need a plugin release).
2. **No PHP-side duplicate validation on PUT.** The proxy forwards `{configs}` as-is (only a
   minimal is-array check); the engine's Zod schema is the single validator and its **422 is
   passed through verbatim**. Meaning for the UI: §13 validation is ALL-OR-NOTHING — a 422
   means NOTHING was saved (not even the valid rows); the indexed D6-style `errors[]`
   (`{index?, trigger_key?, field, message}`, wrapper-level entries index-less with
   `field:"configs"`) binds each error to its row/field, and the fix is resubmitting the whole
   corrected selection. `ApiException` already carried `errors[]` (F3-18) — no extension needed.
3. **URLs via endpoints-map key + fallback constant, like every engine call.** Map keys
   `automations_catalog`/`automations_config`; fallbacks `PATH_AUTOMATIONS_CATALOG/_CONFIG`.
   The fallbacks are load-bearing for EVERY pre-v1.1.0 connection (a stored map never gains
   keys retroactively — the contract's "Map age" note), so the endpoint factory passes
   `RecEngineSettings::endpoints()` through (the ping factory doesn't need to).
4. **Engine-error mapping in the proxy:** engine 401 → 502 `api_key_rejected` (a clear
   "stored key invalid — reconnect" answer, distinct from WP-side 403 and from unreachable);
   422 → 422 passthrough (above); other 4xx/5xx/network → 502 with the engine's error code
   (same convention as `RecEngineEndpoint::ping`). The api_key never appears in any response.
5. **Fail-closed stays with the merchant:** the plugin must never send `enabled: true`
   without an explicit merchant action and the UI defaults `test_mode` on (§11) — T2.2 UI
   requirements recorded here so the PHP layer's as-is passthrough isn't mistaken for licence
   to auto-enable.

**Rationale:** one authority (engine) + one validator (engine Zod) means zero drift surface —
the exact class of bug (mock/local copy masking the live shape) that cost the most in Phase 3
(LESSONS §2.3/§2.7). The mock engine gained the three routes with strict §11–§13 validation in
the SAME pass as the contract sync, per the sync-is-not-code-complete rule.

**Alternatives rejected:** caching the catalog/config in wp_options (staleness + a second
source of truth; the GET is cheap and admin-only); PHP-side pre-validation for friendlier
errors (duplicate rulebook that drifts — the engine's indexed 422 is already field-precise);
POST with a custom verb param instead of PUT (the contract says PUT; `wp_remote_request`
carries a body on PUT fine, unit-pinned).

**Relationships:** F3-18 (ApiException preserves `errors[]` — reused), CC-1 (URL
single-source via map+constants), F3-28.6/LESSONS §2.9 (endpoints-map placeholder discipline
— no placeholders in these URLs, plain keys), LESSONS §2.7 (mock moved in the same sync).

### F3-52 — Automations config UI (T2.2): joint Save with split dirty state; upsell when unconnected; going live is a confirmed separate act

**Context:** T2.2 renders the F3-51 proxy as the "Engine-run recommendation automations"
sub-section UNDER the store-run WooCommerce automations (Step 3 / WooCommerce tab,
`EngineAutomationsSection`), catalog-driven per §11 (no hardcoded trigger keys — an
engine-deployed trigger appears without a plugin release; docs link from the catalog's
`docs` field; `_et`/`_en` copy picked by the admin locale via `<html lang>`, `recipe_et`
always shown). Design decisions locked by Erkki 2026-07-07.

**Decisions:**
1. **One Save button, TWO parallel requests, SPLIT dirty state.** The engine section joins
   the WooCommerce tab's sticky-footer Save (and the wizard Step-3 Continue): Save fires the
   existing `POST /settings` plus — when the engine slice is dirty — a `PUT` through the
   automations proxy, in parallel. The engine slice keeps its OWN dirty bit
   (`state.engineAutomations.dirty`, deliberately NOT `dirtyTabs.woocommerce`): on a partial
   failure (local POST ok, engine PUT failed) only the engine section stays dirty, its error
   renders inside the section, and Save stays enabled for the corrected resubmit — the
   merchant loses neither half. §13 all-or-nothing means a 422 keeps the WHOLE slice dirty.
   `saveEngineAutomations()` (state/engine-automations.ts) is the single orchestrator both
   save paths call, so Settings and wizard can't drift.
2. **Not connected → modest upsell, context-aware CTA.** In Settings the banner's CTA jumps
   to the Campaign Intelligence tab (hash routing); in the wizard the copy points to the
   NEXT step (connection happens in Step 4), and Step 4 shows a post-connect banner offering
   the way back to Step 3 (`WIZARD_GO_TO_STEP` — the existing navigation). A proxy 503 at
   fetch time renders the same upsell state.
3. **Going live is a separate, confirmed act (fail-closed §11).** `test_mode` defaults ON
   for a never-configured trigger; "Activate for real…" is its own button with a
   `window.confirm` naming the consequence — never the enable toggle. `enabled=true` only
   ever comes from the merchant's explicit toggle click.
4. **The engine's GET is the truth in the UI too.** The section fetches catalog+config on
   every open (unmount on tab switch, no cache); a dirty draft survives the re-fetch, a
   clean slice is replaced. Rows live in state in the EXACT §13 wire shape (snake_case,
   scar 3.5.3a) and the PUT sends every rendered row with all 8 fields — un-edited fields
   (today `daily_cap`) round-trip from GET unchanged, the §12 read-only pair is stripped at
   hydrate so it can't leak into the PUT. A config row absent from the catalog is neither
   rendered nor sent (PUT never deletes). Client-side pre-validation at the fields is a UX
   layer only; the engine's indexed 422 is the validator and binds back to rows by
   trigger_key/index (F3-51 rule 2 unchanged).

**Rationale:** one Save keeps the merchant's mental model ("this tab saves together") while
the split dirty bit + section-local errors make the two destinations' independent failure
modes visible instead of averaging them into one banner; the confirm-gated live switch keeps
the §11 fail-closed promise a UI invariant, not a convention.

**Alternatives rejected:** a separate Save button for the engine section (two buttons on one
tab reads as a bug; the partial-failure semantics give the same safety); folding engine dirty
into `dirtyTabs.woocommerce` (can't express "local saved, engine still dirty"); blocking the
local POST when engine pre-validation fails (would hold the store-run automations hostage to
an engine-side field error).

**Relationships:** F3-51 (the proxy + engine-is-authority rules this UI renders), F3-29
(Step-4 connect flow the upsell/back-banner integrates with), scar 3.5.3a (snake_case wire
types end-to-end), LESSONS §2.3 (mock strictness — component tests mock the api module, the
shapes come from the contract).

**Addendum (2026-07-07, T2.4 — Erkki's real-store test): language mode is STORE-GLOBAL;
a server row's `language_mode` is only a wire fact.** The display mode is derived ALWAYS
and ONLY from the store's structure (`deriveLanguageMode`: multilingual A/B → per_language,
else single) and applies uniformly to every row. The stored `language_mode` on a §12 config
row records how that row was last saved — it is never honoured as a display shape. (The bug:
a walk-saved `replenish_due` row with `language_mode='single'` rendered ONE dropdown on the
sandbox while its never-configured neighbours rendered the per-language table — two shapes
on one screen.) At hydrate, `convertAutomationMap` translates a stored map into the derived
mode: single `{id}` → per_language `{fallback: id}` (language fields start unpicked; the
workflow stays reachable and the row valid); per_language → single `{id: fallback}` (the
language split is meaningless on a single-language store; a map without a fallback converts
to `{}` and the merchant re-picks — validation flags it while enabled). The PUT always sends
the derived mode + the converted map, so the engine row converges to the store's shape on
the next save. Alternative rejected: honouring the server row's mode per row (what shipped
in 3.4.0) — it renders an inconsistent mixed UI and lets a stale wire fact override the
store's actual language structure.

### F3-53 — Abandoned-cart poison-row hardening + the legacy WP-Cron scheduler is removed for good (Prike fatal loop)

**Context:** Prike (2026-07-08) installed the new module over the old one (no in-place
upgrade). From minutes after their setup wizard: a PHP 8 fatal (`Cannot access offset of
type string on string`) on every 15-minute `smly_plus_abandoned_cart` tick — old-writer
`cart_content` rows deserialize to an array of bare STRINGS, and the legacy
`prepare_products_data()` read `$cart_item['product_id']` unguarded. The failing cart
stayed `mail_sent NULL` (retried forever) and the fatal aborted the whole pass (F3-37's
per-cart `continue` covered API errors, not Throwables). Separately, the legacy WP-Cron
events were alive in their cron option: `Lifecycle::activate()` and `activated_plugin`
(`check_for_dependency`, any WooCommerce re-activation) re-scheduled them AFTER
WPCronAuditor's one-time activation clear — and `Cron::smaily_sync_subscribers` was still
`add_action`-registered, so the surviving daily event ran the F3-47 language-clobber
mass-send that F3-48.3 had declared "orphaned, dead, harmless."

**Decisions:**
1. **Poison rows are terminal-marked, never eternally retried.** Non-array
   `cart_content` → log + `update_mail_sent_status` + continue. Non-array / keyless
   cart ITEMS are skipped item-level (the cart itself still sends). A per-cart
   `try/catch (Throwable)` backstop terminal-marks a throwing cart — a data-shape
   throw is deterministic and would recur every tick. Observable in the log, never
   a silent infinite loop (LESSONS §2.11 spirit).
2. **The legacy WP-Cron scheduler is deleted, not just its events cleared.**
   `Lifecycle::set_scheduled_actions()` and both call sites removed; scheduling is
   owned by the AS `smly_plus_*` recurring actions. `deactivate()`'s clears stay as
   residue defense. A version upgrade heals an already-polluted site
   (`maybe_run_upgrade → Activation::run → WPCronAuditor` clear) and nothing re-arms.
3. **The legacy subscriber mass-send is made UNINVOCABLE.** The
   `add_action('smaily_connect_cron_sync_subscribers', …)` registration is removed
   (method body stays for the upstream diff). F3-48.3's intent becomes structural: no
   scheduler event, stray or future, can fire the cron-unsafe language path again.

**Rationale:** every layer that "should never happen" happened at once on a real client;
each fix removes a whole class (untrusted persisted shape, poison-pill pass abortion,
resurrectable legacy scheduler) rather than the single symptom.

**Alternatives rejected:** rewriting abandoned-cart onto the new namespaced pipeline now
(bigger scope; hardening makes the legacy path safe and the bridge keeps business logic
unchanged — a rewrite is a separate decision); leaving a throwing cart `mail_sent NULL`
for retry (deterministic error ⇒ infinite 15-min loop, the exact bug); keeping
`set_scheduled_actions` as a no-op shell (dead code invites re-wiring; the deactivate
clears document the hook names already).

**Relationships:** F3-37 (backlog guard + per-cart API-error handling this extends),
F3-47/F3-48.3 (the language clobber this permanently de-fangs), sub-PR 5.D /
WPCronAuditor (the migration whose one-time clear the re-arm defeated), LESSONS §2.18.

**Addendum (2026-07-08, same day): the abandoned-cart `language` field routes through
ContactLanguageResolver.** Reviewing the pass for the fix above surfaced the same F3-47
class ON THIS PATH: `prepare_user_data()`'s language case called the legacy
`Helper::get_user_language_code()`, whose fallback is the context-dependent
`get_current_language_code()` — in this cron/AS pass that resolved '' (or the wrong
context), and Smaily treats `language: ''` as "wipe the contact's stored language"
(smaller scale than F3-47 — only abandoned-cart contacts missing per-user meta, but the
same wipe/clobber, every 15-minute tick). Now: `ContactLanguageResolver::for_user()`,
omit the key when it returns '' (F3-47 rule 2). This is the interim fix; the full
abandoned-cart rewrite onto the new namespaced pipeline is a separate future decision.

### F3-54 — Abandoned-cart status option: one normalized shape, router-first dispatch (the REAL Prike fatal)

**Context:** Martin's (Prike dev) correction to the F3-53 diagnosis: the PHP 8 fatal was at
the EMPTY-OPTION GUARD (`$status['enabled']`, cron.class.php:166), not in the cart-item
loop — and turning the feature off in wp-admin didn't stop it. Root cause is OUR seam:
`SettingsEndpoint::save_woocommerce()` wrote `smaily_connect_abandoned_cart_status` as a
BARE BOOLEAN (WP stores `'1'`/`''`), while three consumers offset into it as an ARRAY
(the legacy email pass, `Options::get_woocommerce_settings_from_db()`, and — inverted —
`EnvDetector`'s `(bool)` cast, which read a DISABLED array as enabled). A string offset
with a string key is a PHP 8 TypeError (repro'd: both `'1'['enabled']` and `''['enabled']`
throw "Cannot access offset of type string on string"). Toggling off just wrote the other
string. The old guard test never caught it because it seeded the option ITSELF in the
array shape — the fixture's shape, not the real writer's. The new save also DESTROYED the
legacy `autoresponder_id`, and no producer feeds `automation.abandoned_cart` — so the
legacy email pass is the only abandoned-cart sender, reading a workflow id the new UI no
longer maintains.

**Decisions:**
1. **One normalized read path.** `Options::abandoned_cart_status()` (+ pure
   `normalize_abandoned_cart_status()`) accepts array, bare-boolean-string, and garbage,
   always returning `{enabled: bool, autoresponder_id: int}`. Every consumer (cron pass,
   get_woocommerce_settings_from_db, EnvDetector hydrate) reads through it — never a raw
   `get_option` + offset. Heals already-corrupted stores (Prike) with no manual step.
2. **Router-first dispatch in the legacy email pass.** The pass now tries
   `AutomationRouter::trigger_automation('abandoned_cart', …)` first — the wizard's
   automation-mapping row is the workflow source on new-path stores (multilingual modes,
   the F3-48 force_opt_in policy, F3-44 exchange capture come free; the router docblock
   always claimed this trigger). `ApiException` = transient → cart stays unmarked for
   retry (same semantics as the legacy error-array path). **Fallback:** no mapping row +
   a non-zero legacy `autoresponder_id` → the legacy client path, unchanged — zero
   regression for upgraded-but-unwizarded stores. Enabled with NEITHER source = config
   gap: carts stay pending (they send once the merchant maps a workflow; the backlog
   guard bounds the pile), logged once per pass.
3. **The writer produces the array shape and PRESERVES `autoresponder_id`** — it is the
   no-mapping fallback; destroying it (what 3.4.x did) silently killed upgraded stores'
   abandoned cart even where the shape didn't fatal.

**Rationale:** normalize-at-the-reader alone would have silently disabled the feature on
every new-path store (boolean shape carries no workflow id); router-first makes the
mapping table the id source so the feature actually works post-wizard.

**Alternatives rejected:** keeping the boolean option and porting all consumers to it
(loses the fallback id upgraded stores still need); building the
`automation.abandoned_cart` queue producer now (the right end-state, but a bigger change
than a crash-fix release should carry — folded into the BACKLOG rewrite item).

**Relationships:** F3-53 (the incident; the poison-row hardening stays as defense in
depth), F3-48.4 (force_opt_in policy now applied to abandoned cart via the router),
F3-44 (router sends are exchange-captured), LESSONS §2.19, the BACKLOG abandoned-cart
rewrite item (the queue-producer end-state).

### F3-55 — Backfill progress: users WALKED and contacts SYNCED are different numbers with different labels

**Context:** Prike (2026-07-08): "contact sync shows 30k contacts going to Smaily, we have
16k opt-ins." The wire was correct — the F3-48 audience filter POSTs only the mode's
audience — but `total_count` = `count_users()` (every WP user), `processed_count` counts
rows WALKED, and the UI labelled that walk count "contacts synced"
(`Step2Subscribers`, `Step6Done`). On a consent-mode store the label reads as a consent
violation.

**Decisions:**
1. **Two numbers, both shown, each with its own noun.** The walk (`processed/total`)
   keeps driving percent + ETA — it is monotonic and always completes, where an
   audience-based denominator would freeze through opted-out ID ranges and drift when
   users opt in/out mid-run. A new cumulative `synced_count` (migration 008) counts
   AUDIENCE members handled (POSTed + already-fresh); it is the ONLY number the UI may
   label "contacts synced". Copy: running "Checked X of Y users — Z contacts synced",
   done "Done — Z contacts synced (X users checked)".
2. **The audience definition has one home.** `ContactAudience::count_audience()` (the
   SQL count) lives next to `should_sync_user()` (the per-user predicate) and an
   integration test asserts they agree in every mode — two implementations of one
   definition must not drift (CC-9 spirit).
3. **The estimate is shown BEFORE the run** ("about N of them will be synced to Smaily
   as contacts"), only when the mode actually narrows the audience, and is computed
   only on non-running status polls (no usermeta COUNT per 2-second tick).
4. Engine backfills (products/customers/orders) are untouched — they enqueue everything
   they walk and already show engine-confirmed `sent` (3.10.0); `synced_count` stays 0
   for them and their UI ignores it.

**Rationale:** a count a merchant sees must be denominated in the unit its label claims;
"walked" sold as "synced" made a working consent feature look broken.

**Alternatives rejected:** setting `total_count` to the audience size (percent/ETA freeze
on skip-heavy stretches, completion drift when consent changes mid-run); relabeling the
existing number without adding `synced` (hides the number the merchant actually wants).

**Relationships:** F3-48 (the audience filter whose correctness this makes visible),
3.10.0 engine-confirmed counts (the same walked-vs-confirmed distinction for engine
jobs), migration 008.

### PRO-1224 — Product `sku` is ALWAYS `woo-<id>` (platform id), never the merchant SKU; catalog rows carry `tags.product_id`

**Context (2026-07-10):** the engine sharpened the contract's identity rule
(§3, v1.3.0): the ingest `sku` is a **join/identity key, not a human SKU**. On
Shopify (PRO-1223) the plugin's "fall back to the merchant SKU field" collapsed
Urban Green's catalog 605→330 rows — distinct products sharing a blank/reused/
garbage merchant SKU (`"63.00"`, `"12"`, an EAN) landed on one `(tenant, sku)`
key and silently overwrote each other. Woo had the same defect. The engine is
adding fail-loud namespace validation that rejects off-scheme keys.

**Decision:** `Support\SkuResolver::resolve()` ALWAYS emits `woo-<canonical_id>`
(variation id for a variable product, product id for a simple one), prefix
`woo-`, **never** the merchant WC SKU field — not even as a fallback. This
REVERSES F3-36's "real SKU when set, else `wc-{id}`" (prefix and the merchant-SKU
branch both gone); the resolver *pattern* — one chokepoint for catalog + order +
browse, translation canonicalization (CC.2), the never-drop deleted-line fallback
now `woo-oi-<item_id>` (F3-43) — is unchanged. Additionally, every catalog row
now carries `tags.product_id` = the RAW (un-prefixed) canonical PARENT product id
(`SkuResolver::product_group_id()`): the cross-variant grouping key (PRO-1227) and
the product-level removal key (`catalog/remove` §3b, PRO-1230). The raw platform
id continues to ride in `external_id`.

**Rationale:** the merchant SKU is optional/blank/reused on real stores; keying on
it destroys history. The platform id is stable, unique, and namespaced so `woo-`
and `shp-` never cross-join. `tags.product_id` is RAW (not `woo-`-prefixed) for
deliberate parity with Shopify Connect (which ships `tags.product_id = product.id`,
the raw legacyResourceId) and the §3b contract example (`product_ids: ["7620134"]`)
— caught by reading the shipped Shopify code + contract, NOT trusting PRO-1230's
looser "`woo-<product_id>`" phrasing (LESSONS). The merchant SKU is dropped
ENTIRELY (engine answer PRO-1225, 2026-07-10): the engine consumes it nowhere;
if ever needed it goes in `tags.merchant_sku`, never `external_id` (that field is
the platform variant id + drives collision detection) or `sku`.

**Alternatives rejected:** a tenant-level "key mode" setting (merchant-SKU vs
platform-id) — more code, and there is no case where keying on the merchant SKU is
correct; emitting the merchant SKU in `external_id` for display — the engine
surfaces it nowhere, so it is wire + storage for no consumer (data-minimization).

**Migration:** UPSERT-only means old `wc-<id>`/merchant-SKU rows are NOT
auto-removed — the new `woo-<id>` keys create fresh rows and the old ones linger.
Orphan removal is a one-time manual purge on the engine side, coordinated per
already-synced store (contract §3 "changing the SKU scheme"). The pilot is
SKU-less, so every key changes `wc-<id>`→`woo-<id>` — it needs the purge + a full
re-backfill before/at the flip.

**Relationships:** supersedes F3-36 (the pattern lives on); contract v1.3.0 sync
(commit `a5c3ea6`); PRO-1223 (engine fail-loud namespace validation), PRO-1225
(merchant-SKU/external_id engine answer), PRO-1227 (engine groups by
`tags.product_id`), PRO-1230 (hard-delete → `catalog/remove` §3b, consumes
`tags.product_id`); Shopify PRO-1226 (parity).

### PRO-1230 — Hard-deleted parent product → §3b `catalog/remove` (product-level tombstone); trash and variation deletes keep the soft path

**Context (2026-07-10):** contract v1.3.0 added `POST /api/v1/ingest/catalog/remove`
(§3b): a soft tombstone — `in_stock=false` + `recommendable=false` on every catalog
row whose `tags.product_id` matches; rows are kept (learning corpus), never
hard-deleted. This is the designed path for a platform HARD delete, where the
product's variants/SKUs are no longer enumerable. Until now a permanent delete only
got the F3-40 in_stock=false upsert (captured at before_delete_post) — the product
could still linger recommendable-adjacent, and F3-40 explicitly named the hard-delete
gap as accepted.

**Decision:** `before_delete_post` now routes to `CatalogHookHandler::
on_hard_delete_product` (Bootstrap rebind; `wp_trash_post` stays on the F3-40 path
untouched), which routes by post type:
- **PARENT product** (incl. a purge-from-trash — that also fires before_delete_post):
  ONE `catalog.remove` queue row whose payload `product_id` is `SkuResolver::
  product_group_id()` — the RAW un-prefixed CANONICAL parent id, byte-identical to
  the `tags.product_id` the catalog sync stamps (engine confirmed 2026-07-10:
  removal matches the exact string stored in `catalog.tags.product_id`; the issue's
  original `woo-<product_id>` wording was an error). The per-SKU `catalog.delete`
  rows are NOT also enqueued: §3b is strictly stronger, and an in_stock=false UPSERT
  racing the tombstone across two independent flush cycles is avoidable wire noise.
  The handler also PRE-CLAIMS the per-request `catalog.delete` dedupe slots of the
  product's variations — WC cascade-deletes them right after the parent, each firing
  before_delete_post; without the pre-claim each would enqueue a redundant soft
  removal.
- **Single VARIATION** (parent lives on): keeps the existing per-SKU soft path
  (`on_delete_product` → in_stock=false). §3b is PRODUCT-level — firing it for one
  variation would wrongly tombstone all surviving siblings.
- **TRANSLATION of a surviving canonical**: re-sync the canonical (P4), same as trash.
- **auto-draft**: skipped (the daily GC burst was never ingested; each §3b call would
  be a not_found round-trip for nothing). Other never-ingested statuses may still
  fire a removal — idempotent, `not_found` is a contract-defined success.

**Plumbing:** new event type `catalog.remove` through the shared IngestQueue, drained
ONLY by the new `CatalogRemoveFlusher` (own AS hook `smly_rec_flush_catalog_remove` /
group, 60s recurring tick; IngestFlusher's catalog.upsert/delete scoping is untouched).
§3b is NOT D6 — the response is `{ok, removed_products, rows_tombstoned, not_found}`
with no per-item errors[] — so `AbstractD6Flusher` grew a protected `apply_response()`
seam (default = the D6 split); the remove flusher overrides it to mark every batched
row SENT on 2xx, storing the per-row outcome (`removed` / `not_found`) in the F3-44
exchange. HTTP failures inherit the shared terminal-4xx / transient-retry policy; a
keyless row is an observable terminal skip (LESSONS §2.11). `Client::catalog_remove()`
resolves `endpoints[ingest_catalog_remove]` with fallback
`PATH_INGEST_CATALOG_REMOVE` — the v1.3.0 endpoints map does not advertise the key, so
the fallback serves every current connection ("Map age", contract §1). The mock engine
gained the §3b route in the same pass (CC-8): wrapper validation (1..1000, 400 on
malformed) + exact-string matching against the `tags.product_id` values it ingested.

**Rationale for enqueue-not-inline:** hooks must never block on HTTP (established
architecture); the queue gives durable retry + Event Log observability. Removal is
best-effort by contract ("the periodic full re-sync is the reconciler"), so a lost
fast-path event self-heals.

**Alternatives rejected:** (a) sending BOTH §3b and the per-SKU in_stock=false rows on
hard-delete — redundant, plus the cross-flusher ordering hazard above; (b) firing §3b
for a variation delete with the parent id — tombstones surviving siblings; (c) a
separate queue table — the shared queue's event-type scoping already isolates
flushers.

**Migration note:** §3b matches on `tags.product_id`, which rows synced BEFORE
PRO-1224 don't carry — those return `not_found` until the coordinated purge + full
re-backfill (the PRO-1224 migration) has run. Acceptable: same stores, same window.

**Relationships:** PRO-1224 (`tags.product_id` is the removal key; SkuResolver::
product_group_id), PRO-1229/PRO-1228 (contract v1.3.0 sync `a5c3ea6`), F3-40 (trash
soft path unchanged; its "hard-delete gap accepted" limit is now closed), F3-44
(store_exchange per row), F3-18/N-7 (the D6 base this deliberately deviates from,
via the apply_response seam).

### PRO-1241 — All order money fields are GROSS (tax-inclusive), per contract v1.4.0 §5

**Context:** engine-side read-only verification on MiuMjau (PRO-1202) found the
Woo plugin internally inconsistent within a single order: `total_amount` was the
gross grand total (`WC_Order::get_total()`) while `items[].line_total` used
`$item->get_total()` — which in WooCommerce is **NET (ex-tax)**. Median
`total_amount / Σ line_total` = 1.310; median `unit_price / catalog.price` =
0.806 ≈ 1/1.24 (Estonian VAT) — per-SKU revenue in Insights understated ~24% vs
the order-total revenue on the same page. Contract v1.4.0 (§5 "Amount
semantics", engine `2dec424`, synced `434ffee`) standardizes ALL money fields on
the orders endpoint as **gross/tax-inclusive** — what the customer paid; Shopify
and Magento already conform.

**Decision:** `OrderPayloadBuilder` (the single money chokepoint — live hook,
retry flusher and order backfill all build through it at send time) serializes:
- `items[].line_total` = `get_total() + get_total_tax()` (charged amount incl.
  the line's tax share, after line discounts), rounded to 4 decimals;
- `items[].unit_price` = the same gross line basis ÷ qty (NO LONGER the
  pre-discount `subtotal / qty` — §5 defines unit_price on the post-discount
  gross basis);
- `items[].discount_amount` = `(subtotal + subtotal_tax) − (total + total_tax)`
  (the gross delta), omitted when zero;
- order `discount_amount` = `get_total_discount( false )` (tax-inclusive; the
  parameterless default is ex-tax);
- `total_amount` stays `get_total()` — it was already gross (incl. shipping).
No wire-SHAPE change (same keys); values change basis. Sender invariant
`Σ items[].line_total + shipping ≈ total_amount` is pinned by unit tests
(taxed multi-line + discounted + zero-tax + rounding edge) and an integration
test driving the REAL WC tax engine (24% VAT, coupon, taxed shipping). The mock
deliberately does NOT reject on the tax basis — the live engine doesn't either
(the invariant is an engine-side monitoring signal, not a 4xx); pinning lives in
the payload assertions + the live-walk.

**Rationale:** "gross = what the customer paid" is the only basis consistent
with `total_amount`'s de-facto meaning across all three platforms, and the only
one that makes per-SKU revenue sum to order revenue on the same report.

**Alternatives:** (a) keep net lines and have the engine gross them up —
rejected: the engine stores amounts as sent and never recomputes tax (§5);
(b) send both net and gross — rejected: no consumer for net, wire bloat.

**Migration:** already-ingested MiuMjau orders keep the net basis until the
one-time historical re-sync — engine-coordinated, riding the PRO-1233 window
(~2026-07-14); re-ingest fully replaces line items (§5 idempotency), so the
backfill self-corrects rows. NOT part of the plugin change.

**Supersedes** the amount serialization shipped with F3-22/W5 (net
`get_total()` lines, pre-discount `subtotal/qty` unit_price, ex-tax
discounts). Status mapping and everything else in F3-22 stand.

**Relationships:** F3-22 (orders milestone — amounts part superseded), F3-43
(deleted-line snapshot totals now also gross via the same accessors), CC-8
(contract sync `434ffee`), LESSONS §2.3 (a formatted-field basis is exactly the
mock-vs-live class the live-walk must cover).

## How to keep this document going

For every new significant technical decision (as part of a sub-PR plan or
discovered along the way):

1. Add a **new entry** in the relevant category (F3-22, F3-23, ...)
2. Follow the 5-field form: Context / Decision / Rationale / Alternatives /
   Relationships
3. Keep it **short** (5-15 lines per decision) — a log entry, not a full ADR
4. ~~At the end of Phase 3, refine~~ — DECIDED (2026-06-11): keep the single
   `DECISIONS.md` file; the ADR-per-file split was rejected (the F-numbered
   log is the working format both agents and humans navigate by)

**What's likely to be added later in Phase 3** (F3-19/20/21 the 3.3 customers
milestone, A-filter, datetime; F3-22 orders + status mapping; F3-23 N-7
AbstractD6Flusher + W2 drift; F3-24 browse-beacon architecture; F3-25 backfill
architecture — all above). F3-28 (GDPR) and F3-29 (Step-4 activation) are now
written in full above.

In each sub-PR's planning phase: "is this decision worth adding to DECISIONS?"
Rule of thumb: **if the rationale requires more than one sentence**, add it.
