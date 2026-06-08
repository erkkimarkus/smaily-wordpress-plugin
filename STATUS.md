# Smaily Connect — Current Status

**Single source of "where we are now."**

> ## Keeping this current — NOT optional
> A handoff doc that goes stale is worse than none: it hands the next agent
> false confidence. This file MUST be updated as part of the same commit that
> changes reality — never "later."
>
> **Update this file in the SAME commit when you:**
> - finish a sub-PR (move it to done, add the commit hash)
> - sync the contract (record the engine commit + what changed)
> - hit, resolve, or newly defer a lock condition / blocker
> - reach a milestone or change the roadmap
>
> **The rule:** if a change makes this file wrong, the change isn't done until
> this file is fixed in the same commit. Stale status is a defect — treat it
> like a failing test. If you (an agent) notice this file disagrees with the
> repo, fixing it is in-scope right now, not a separate task. Also bump
> _Last updated_ below.
>
> This already bit us once: the README roadmap table said Customers/Orders were
> "Pending / Awaiting W4/W5" long after they shipped, because it was written
> once and never refreshed. Don't let this file become that.

If this file and your memory disagree, trust this file and fix it. The roadmap
table in README is a high-level view; this is the working register.

_Last updated: 2026-06-08 (3.4 browse-beacon COMPLETE — proxy + abuse model + client transport + cookies + WP-wrapper + WC events; browse live-walk 13/13 against MiuMjau incl. retroactive_bound=2 + the live abuse filter; ZIP'd. Browser render-timing is a manual pilot check. Next: 3.5 backfill)_

---

## The two-team picture

Two repos, one byte-identical contract (`docs/RECENGINE_API_CONTRACT.md`):

- **Plugin** (this repo) — WordPress plugin. Sends WooCommerce data (catalog,
  customers, orders, browse) to the recommendation engine via API; syncs
  contacts to Smaily. Consumes the contract.
- **Engine** (separate repo, the "engine team") — multi-tenant recommendation
  engine. Receives ingest, computes recommendations, runs Smaily sync/poll,
  attribution, learning. Owns the contract; the plugin tracks it.

Coordination is via the shared contract + escalation of edge cases. Routine
plugin work builds against the stable contract **without** per-step sign-off
from the engine team. Sync only when the engine changes the contract (bugfix,
new field, semantics). Escalate edge cases (these have found real engine bugs).

---

## Engine side (the contract the plugin builds against)

**Route A core: COMPLETE.** All five ingest/order endpoints aligned — batch,
D6 per-item `errors[]`, email identity, `compare_price` semantics. Contract
synced byte-identical across both repos (8 syncs, latest engine commit
`3dd5d16`).

| Engine work item | What it delivered | Status |
|---|---|---|
| W1 | per-item Layer-2 dedup canonical | synced |
| W2 | product_url/in_stock required (F3-17) | synced |
| W3 | compare_price/on_sale_until canonical (D2 Variant 1) | synced |
| W4 | email-first identity (no smaily_contact_id), batch customers, D6 reference | synced |
| W5 | batch orders, status/currency/items, D6; Bug 1 + Bug 2 fixed | synced |
| N-6 | browse §6: 9 event types, checkout_* valid, source optional | synced |
| N-7 | catalog + browse retrofit all-or-nothing -> D6 | synced |
| Final pass | request_id setup-only, §8 GDPR export cleanup | synced |

**Engine backend: ~90-95% real.** Narrow gaps, almost all engine-internal:
browse signal unconsumed (intentional, §14.2 Variant-A, post-MVP — beacon data
accumulates, influences recommendations later), mass/transactional playbooks
deferred, lift_global placeholder, one bad AI model ID. **None of these change
the contract the plugin consumes.**

**Engine frontend: ~40-50% real.** Functional: dashboards, tenant CRUD, CSV
upload wizards, integrations. **Stub: Customers browse, Orders browse,
Recommendations, Settings, Decision-log, Cron-status** — UI-only gaps over
working backends. Engine team is building these UI-first. Pilot-debug relevance:
see "Pilot go-live" below.

---

## Plugin side (our work)

### Done

- **catalog-end** — ZIP'd, live-walked. PayloadBuilder + Client + IngestQueue +
  IngestFlusher + CatalogHookHandler. (F3-16 canonical pattern.)
- **customers-end** — ZIP'd (791c00b), live-walked 10/10 against MiuMjau engine.
  CustomerPayloadBuilder + Client::ingest_customers + ApiException D6 +
  CustomerFlusher (D6 reference) + CustomerHookHandler. (F3-19 milestone.)
  Commit chain: 0fcbcd0 -> 9fabcf7 -> db3a0da -> 26a6e44 -> e4dfb91 -> 791c00b.
- **orders-end** — ZIP'd, live-walked 12/12 against MiuMjau engine. **No format
  surprises** — ordered_at Z-form (IsoDate F3-21 carried over) and the WC→enum
  status mapping both validated live (the engine rejects a raw WC status, so the
  mapping is necessary AND correct). OrderPayloadBuilder + Client::ingest_orders
  (batch 50) + OrderFlusher (D6) + OrderHookHandler (status-change wiring).
  Commit chain: 29edfe4 -> 652e16c -> 4d036cf -> a8bde99 (.3) -> this commit (.4,
  + ZIP; the .4 build-hash is this commit).
- **plugin-side N-7** — catalog-flusher D6 consolidation (the lock, now RESOLVED).
  N-7.0 extracted `AbstractD6Flusher` (shared D6 flush + errors[].index split +
  invariant) and refactored Customer/OrderFlusher onto it (byte-identical
  behavior, regression green). N-7.1 moved the catalog IngestFlusher onto the base
  (catalog all-or-nothing -> D6), updated the mock + tests to D6, and live-walked
  catalog 15/15 against MiuMjau — including `flusher_d6_split_lock_proof` (a no-SKU
  product is D6-rejected per-item and marked FAILED, the valid one SENT). The
  N-7.1 live-walk also **caught the W2 `items`->`products` wrapper drift** (the
  sync had updated the doc, not the code; the mock hid it) — fixed in Client +
  mock + ClientTest. (DECISIONS F3-22 + N-7; LESSONS §2.7.)

### Done — 3.4 browse-beacon (complete, live-walked + ZIP'd)

- **3.4 browse-beacon** — storefront telemetry → server proxy → engine
  `/api/v1/ingest/browse`. Differs from the ingest domains: client-buffered
  best-effort telemetry, NOT the Queue/Flusher pattern (intentional, F3-16
  deviation). **3.4.0 DONE** (server side): `Client::ingest_browse` (`events`
  wrapper), public `POST /beacon` proxy (`BeaconEndpoint`) with the abuse model
  — hard-404 gate (connected + `track_browsing`), per-IP + per-session
  rate-limit, server-side §6 event_type/event_id validation + field-whitelist.
  Mock browse route (D6) + unit (validate_batch, ingest_browse) + integration
  (7 proxy tests). Gates green. **NOTE/deviation to confirm:** the route is
  registered *unconditionally* and the handler 404s when disabled (not
  conditional registration) — same attack surface, but testable without
  rebuilding the REST server (which segfaults wp-env). (DECISIONS F3-24.)
  **3.4.1 DONE** (client transport): filled `RecEngineClient.track/flush/destroy`
  in `rec-engine-client.ts` — in-memory buffer, 30s batch window, consent-gated
  flush (no consent ⇒ buffer dropped, nothing sent), `navigator.sendBeacon` on
  pagehide, fetch keepalive otherwise. EventType union 8→9 (added
  `wishlist_remove`, the §2.7 drift). `captureUrlParams` (3.4.2) + `mergeIdentity`
  (3.7) still throw. 11 vitest tests. Gates green (ci:strict exit=0).
  **3.4.2 DONE** (cookies — closes the attribution loop, the cookie PRODUCER
  the 3.4.0 audit found missing): `captureUrlParams()` (campaign URL params
  smaily_vt/rec/ctx → first-party cookies, then strip the URL — cookie SAVED
  before `history.replaceState` strip so attribution can't be lost) +
  `ensureSession()` (generates the `smaily_anon_sid` v4 cookie). Cookie names +
  TTLs + URL-param names come from the engine config; cookies are SameSite=Lax,
  Secure on https, Path=/. **Cookie writes are consent-gated** (no tracking
  cookie without consent — same principle as 3.4.1 no-send; the WP Consent API
  *wiring* is 3.4.3). 7 more vitest tests (18 total). `mergeIdentity` (3.7)
  still throws.
  **3.4.3a DONE** (WP-wrapper + storefront wiring, first PHP+JS sub-PR): PHP
  `StorefrontBeacon` (wp_enqueue_scripts, gated on connected + track_browsing +
  WC active) enqueues the beacon + prints `window.smailyConnectBeacon` (config
  from engine config + page context from WC conditional tags); `beacon.ts` entry
  + `beacon-core.ts` logic wire consent to the **WP Consent API** (CookieYes etc.;
  fail-safe DENY; native `wp_listen_for_consent_change` re-run) with an
  escape-hatch (`smaily_connect_beacon_consent` PHP filter +
  `consentOverride` JS, documented in README) for non-compatible plugins, then
  on consent: ensureSession + captureUrlParams + page-view track
  (product_view/category_view/search/checkout_start/checkout_complete). Build:
  beacon bundles RecEngineClient inline → self-contained classic-loadable
  `dist/public/js/beacon.js` (no top-level import/export; vite entry swap +
  beacon-core/entry split). category_path reuses `CatalogPayloadBuilder::
  primary_category_path` (made public) so browse↔catalog correlate. Tooling
  globs broadened lib→public/js. 10 vitest + 6 integration. Gates green.
  **3.4.3b DONE** (WC cart events, JS-only): `attachCartListeners()` wires
  WC's jQuery `added_to_cart` → `cart_add` and `removed_from_cart` →
  `cart_remove`, SKU from the button's `data-product_sku`. Attached in start()
  so cart tracking is consent-gated too; no-op when jQuery is absent. Known gap:
  the single-product form-POST add-to-cart fires no JS event, so its cart_add
  isn't tracked, and a SKU-less event is skipped (best-effort, §14.2). 5 more
  vitest tests (33 client + beacon-core total). Gates green. **3.4.3 complete.**
  **3.4.4 DONE** (live-walk + ZIP): `bin/walk-3.4-browse.cjs` — **13/13 against
  the real MiuMjau engine**. Two paths: in-process REST dispatch to `/beacon`
  (full proxy→engine chain + the abuse filter on the live endpoint) + direct
  `Client::ingest_browse` (the §6 per-item behaviours the proxy 400s first).
  Proven live: all **9 event types processed** (EventType 8→9 §2.7 fix confirmed
  against the engine), anonymous vs `with_customer_match`, missing-event_id +
  invalid-event_type → engine per-item `errors[]`, dedup, and **`retroactive_bound=2`**
  (anon session events rebound to a customer once an email resolves — browse's
  hardest engine behaviour, end-to-end). Abuse on the live `/beacon`:
  101-events→400, bad-type→400, missing-id→400, rate-limit→429. ZIP includes
  `dist/public/js/beacon.js` (self-contained). **3.4 browse-beacon COMPLETE.**
  Browser render-timing (when checkout_start/complete fire) is a manual pilot
  check, NOT live-walk-covered (CLAUDE.md + below).

### In progress

- **3.5 backfill** — traverse EXISTING WC records into the engine (the live
  hooks only ingest CHANGES). One ingest path, two triggers: backfill enqueues
  into the SAME IngestQueue + AbstractD6Flusher the hooks use (DECISIONS F3-25).
  **3.5.0 DONE** (base + infra + catalog): `AbstractBackfillJob` (cursor/state/
  AS-tick/progress, resumable `WHERE id > cursor`) + `CatalogBackfillJob`
  (products → catalog.upsert, variation fan-out mirrors the hook). Enqueue +
  **inline-flush per batch** (decision (b)): progress = SENT, queue bounded. No
  freshness marker (decision (i), UPSERT-idempotent). Generalised the shared
  infra: `BackfillJobInterface` (legacy contacts BackfillJob implements it too),
  `BackfillEndpoint` SUPPORTED += products + `target_for()` (rec_engine vs
  smaily, coexist under the (job_type,target) UNIQUE key — no schema change),
  `Bootstrap::make_backfill_job()` (single dispatch for endpoint + AS tick,
  contacts gate removed), `backfill.ts` union += products. Tests prove
  resumability (resumes from cursor, not restart) + bounded queue. ci:strict
  exit=0; integration OK 56 (+5 backfill).
  **3.5.1 DONE** (customers): `CustomerBackfillJob` — `WHERE ID > cursor` on
  wp_users → customer.upsert, CustomerFlusher inline-flush. **A-filter (F3-20)
  consistent with CustomerHookHandler**: every registered user, NO role/email
  filter — the consistency is the ABSENCE of a predicate (both unfiltered), so
  neither side sends a different cohort. Test proves a subscriber/editor (non-
  customer role) is backfilled, plus resumability + bounded. Wired:
  make_backfill_job 'customers', SUPPORTED += customers, backfill.ts union.
  ci:strict exit=0; integration OK 60 (+4).
  **3.5.2 DONE** (orders, HPOS-aware): `OrderBackfillJob` — direct
  `WHERE id > cursor` against the active order table (`wc_orders` HPOS /
  `wp_posts` legacy, detected via OrderUtil; `wc_get_orders` only offers
  offset/paged, which shifts under inserts → would break the cursor). **Status
  filter matches the hook**: enumerates only mapped (sale) statuses via SQL
  `status IN (...)`, using `OrderPayloadBuilder::mapped_wc_statuses()` as the
  single source (CC-9 — can't drift from map_status). Progress denominator =
  mapped orders, not all. Test storage split: **wp-env runs WC 10.7 + HPOS, so
  the HPOS path is integration-tested; the legacy path (the pilot's WC 6.9.4
  mode) is unit-tested via the pure `table_spec` — structurally identical but
  not run against real wp_posts orders here** (CLAUDE.md "OrderBackfill"). Tests:
  resumability + bounded + status-filter (unmapped excluded) + full. ci:strict
  exit=0; integration OK 64 (+4 order backfill). **3.5.0-.2 backend complete.**
  **3.5.3a DONE** (admin UI, JS-only): reusable `BackfillPanel` (Import-now
  button + ProgressBar + status, mirrors Step2's contacts panel) — instantiates
  the already-generic `useBackfillProgress({jobType})`; progress lives in the
  hook (no reducer mirror — only contacts feeds the Step6 summary). Three panels
  (products/customers/orders, each disabled at 0 records via
  `state.env.storeTotals`) in a new "Import existing data" Card inside
  Step4Recommendations `ConnectedView` (gated on the rec-engine connection, not
  the Smaily-email one). API + hook needed no changes (3.5.0-.2 wired the job
  types). 3 vitest tests. ci:strict exit=0. Next: 3.5.3b live-walk + ZIP.

### Next

- **3.5.3b** backfill live-walk (drive all 3 backfill jobs against the real
  engine — wp-env is HPOS, so it runs the HPOS order path against real wc_orders;
  assert progress reaches 100% = all mapped sent) + ZIP. Needs a fresh
  setup-token. Then the roadmap.

### Waiting / lock conditions

- **catalog-flusher N-7 D6 consolidation — RESOLVED (N-7.1, 2026-06-06).** The
  catalog flusher now extends `AbstractD6Flusher`; an engine per-item rejection
  marks that row FAILED, not SENT (silent-loss class closed). Proven against the
  real engine by the catalog live-walk (`flusher_d6_split_lock_proof`: sent:1,
  failed:1). No remaining lock conditions on the plugin side. (DECISIONS F3-22.)

### Roadmap (Phase 3 remaining)

- ~~**3.4** browse-beacon~~ — DONE (above). NB §14.2: the engine consumes browse
  post-MVP — pilot expectation is "collects data, improves recommendations
  later, not now".
- **3.5** backfill (cursor pagination)
- **3.6** beacon (note: checkout_complete browse event is NOT engine-coupled to
  the orders endpoint — don't assume linkage; attribution is future work)
- **3.7** identity-merge (reconciles email-split: same person, two emails -> two
  customer records until merge)
- **3.8** GDPR (WP Privacy API)
- **3.9** Step 4 (4a) activation

---

## Pilot go-live — both sides must be ready

Pilot does NOT go live until all of these hold. No deadline pressure (D5).

**Plugin side:**
- [x] catalog-end ZIP'd + live-walked
- [x] customers-end ZIP'd + live-walked
- [x] orders-end ZIP'd + live-walked (12/12)
- [x] catalog-flusher N-7 D6-fix (lock RESOLVED — N-7.1, catalog live-walk 15/15)
- [ ] **order-backfill LEGACY path verified against a legacy WC env** (WC 6.x,
  HPOS off). The pilot is WC 6.9.4 = legacy storage, but the wp-env integration
  runs WC 10.7 + HPOS — so the integration green covers the HPOS order-backfill
  path, NOT the pilot's legacy path (unit-tested via `table_spec` only, 3.5.2).
  Low risk (same SQL shape), but a real legacy-DB run (does
  `WHERE post_type='shop_order' AND post_status IN ('wc-completed'…) AND ID >
  cursor` behave on a real WC 6.x posts schema?) must happen before the pilot
  backfills orders. Not a blocker for 3.5.x dev (HPOS env proves the logic);
  schedule before pilot go-live. (CLAUDE.md "OrderBackfill".)

**Engine side:**
- [x] backend (90-95%, gaps engine-internal)
- [ ] **frontend debug views** — Customers browse + Orders browse (at minimum)
  functional, so a pilot problem ("X didn't sync") can be seen in the UI rather
  than debugged DB-direct. Engine team building UI-first.

A working backend the team can't see into is debug-blindness in pilot. Both
sides ready = go-live.

---

## Known deferred items (tracked, not blocking)

- N-7 EVENT_* constant location asymmetry (catalog `EVENT_CATALOG_*` on
  CatalogHookHandler, customer/order on their Flusher) — still asymmetric after
  N-7. N-7 chose an **abstract base** (`AbstractD6Flusher`), NOT a monolithic
  dispatcher, so each flusher keeps its own constants/hook/group; the "unify under
  a dispatcher" premise no longer applies. Cosmetic only — defer or drop.
- Flaky useBackfillProgress test (fake-timers race) — fix with deterministic
  timer mocking.
- **Browse browser-timing — manual pilot verification (not live-walk-covered).**
  The 3.4 live-walk proves the engine contract (proxy→engine + abuse + all 9
  types) but a server-side walk can't observe the browser MOMENT a page-view
  fires (checkout_start on the checkout page, checkout_complete on
  order-received, product_view on a product page). The PHP page-type detection
  (`StorefrontBeacon::page_context`) can't be driven in the integration harness
  (no `WP_UnitTestCase`/`go_to()`); JS mapping is vitest-tested. So confirm the
  render moment manually during the pilot (or a future Chromium E2E — not built,
  YAGNI, low risk). See CLAUDE.md "Browse browser-timing".
- GDPR export omits rec_attribution — engine-side Art 15 legal review (not a
  contract issue for the plugin).
- F3-19 guest-customer flusher concern: RESOLVED by W5 — engine auto-creates the
  customer from the order's customer_email; OrderFlusher is order_id-based (guest
  orders have an order_id), so no payload-carried path needed.

---

## Future / backlog (not scheduled)

Feature ideas worth keeping, distinct from "Known deferred items" above (those
are tracked technical debt). These are NOT scheduled — build only when a real
need arrives (YAGNI).

- **Consent-bridge extension (future).** The beacon supports non-WP-Consent-API
  consent plugins (Cookiebot, custom) today via the **escape-hatch** — the
  `smaily_connect_beacon_consent` PHP filter + `window.smailyConnectBeacon.consentOverride`
  JS override — a developer-level adapter that requires writing code. Future: a
  **user-level consent-bridge**, modelled on the existing plugin-integration pages
  (Elementor, Contact Form), so a non-technical client on a non-WP-Consent-API
  plugin can map their cookie-consent signal without code — e.g. a guide, a
  "select your consent plugin" activation, or a settings panel that maps an
  incompatible plugin's consent state. MiuMjau (CookieYes) does NOT need this
  (WP-Consent-API-native); this is for future clients on Cookiebot or custom
  solutions. The escape-hatch covers it technically in the meantime. Build only
  when a real Cookiebot/custom client lands.
