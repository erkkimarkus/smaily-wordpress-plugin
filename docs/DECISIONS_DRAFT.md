# DECISIONS_DRAFT.md — Smaily Connect technical decisions

A draft consolidating all significant technical design decisions in the Smaily
Connect plugin, with the reasoning behind each. Purpose: a future developer
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
the token or (b) the full URL `https://re-...vercel.app/setup/<token>`.

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

**Per-product location in the body:** `{"products":[{"event_id":"...","sku":...}]}`,
not top-level. Confirmed by the engine's 6/6 sanity tests (3.2 preparation).

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

## How to keep this document going (during Phase 3)

For every new significant technical decision (as part of a sub-PR plan or
discovered along the way):

1. Add a **new entry** in the relevant category (F3-13, F3-14, ...)
2. Follow the 5-field form: Context / Decision / Rationale / Alternatives /
   Relationships
3. Keep it **short** (5-15 lines per decision) — this is a draft, not a full ADR
4. At the end of Phase 3, refine:
   - Either split into **one ADR file per decision**
     (`docs/adr/0001-coexistence.md`, ...)
   - Or keep a single `DECISIONS.md` — depending on the repo's culture

**What's likely to be added in Phase 3:**
- F3-13: 3.5 backfill architecture (cursor pagination, batch size, retry)
- F3-14: 3.6 beacon (client side, server proxy, cookie management)
- F3-15: 3.7 identity-merge (three triggers: post-checkout, login, manual)
- F3-16: 3.8 GDPR (export/delete, WP Privacy API integration)
- F3-17: 3.9 Step 4 4a activation (UI shift mode-A → mode-B)

In each sub-PR's planning phase: "is this decision worth adding to DECISIONS?"
Rule of thumb: **if the rationale requires more than one sentence**, add it.
