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

_Last updated: 2026-06-09 (3.9 Step-4 activation COMPLETE — locked design: connecting the rec-engine syncs ALL domains (system-decides), the four per-domain sync toggles (orders/customers/products/cart) were cosmetic/write-only and are REMOVED; browse-tracking is the only Step-4 toggle (legal-consent gate, opt-in default-off). Disconnect clears only the connection options and PRESERVES `smly_plus_rec_track_browsing`, so re-connect restores the toggle — which required a mandatory hydration fix (EnvDetector emits the saved value, hydrate reads it instead of hardcoding false; also fixes a plain-reload blanking bug). Dead option keys cleaned up idempotently on upgrade-detect. PLUGIN.md §Step-4-4a/§6 revised to match the vision; DECISIONS F3-29. Then a pre-3.9 task: PLUGIN.md translated ET→EN. Next: Phase 3 done; Smaily profiling-consent wiring + beacon two-gate stop is the remaining separate piece. POST-3.9: (i) **legacy-WC order-backfill verified** — WC 6.9.4 + PHP 8.1 env, real `wp_posts` traversal, full integration 75/75 on legacy (pilot precondition RESOLVED, see go-live checklist); (ii) a production-readiness audit surfaced two NEW pilot-blockers beyond features — failed-queue-row invisibility/no-re-drive (P1) and no surfaced diagnostic trail (P2) — plus a WC-version-header mismatch (header says 7.0, pilot is 6.9.4) and a missing pilot-onboarding doc; tracked for prioritisation. Then pilot-hardening began: **P5** version-floors reconciled (WC 6.9/WP 6.2/PHP 8.0); **3.10.0** Event Log visibility shipped — `/events` UNION read-model + Settings tab + sticky failed-banner + backfill progress now engine-confirmed sent/failed (no more "1400/1400 while failed"). Sequence ahead: 3.10.1 recovery → 3.10.2 notice → P4 onboarding doc; then Smaily-consent (awaits its spec). See pilot-hardening sequence below.)_

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

### Done — 3.5 backfill (complete, live-walked + ZIP'd)

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
  types). 3 vitest tests. ci:strict exit=0.
  **3.5.3b DONE** (live-walk + ZIP): `bin/walk-3.5-backfill.cjs` — **7/7 against
  the real MiuMjau engine**, all three backfill domains. Proven live: products
  + customers backfill reach **100%** (processed == total); the **order status
  filter on real HPOS data** (wp-env is WC 10.7 + HPOS) — 4 mapped of 5 orders,
  the pending one excluded (total=4); **multi-batch resumability** against the
  real engine (order job driven at batch 2 → 3 batches, cursor monotonic, never
  restarts); and **bounded queue** (pending empty after every inline flush). ZIP
  includes the new admin BackfillPanel + the storefront beacon. **3.5 backfill
  COMPLETE.** NB: the live-walk runs the HPOS order path; the LEGACY path (the
  pilot's WC 6.9.4 mode) remains unit-tested only — a pilot go-live precondition
  (above + CLAUDE.md).

### Done — 3.7 identity-merge (complete, live-walked + ZIP'd)

- **3.7 identity-merge** — bind an anonymous browse session to a known customer
  on login (§7). NOT a customer↔customer merge (the roadmap one-liner was wrong;
  v1 has no such thing — DECISIONS F3-27). Complementary to the engine's
  automatic browse-event retroactive binding (§6): covers "logs in but generates
  no email-carrying browse event after". **3.7.0 DONE**: `Client::merge_identity`
  (single §7 object, not a batch) + `IdentityHookHandler` (server-side `wp_login`
  → reads the anon-session/visitor-token cookies from $_COOKIE → posts the merge;
  api_key stays server-side, no new proxy). Dedup via user meta
  (`_smaily_rec_merged_anon_sid` — repeat logins same session don't re-hit the
  engine; a new session re-merges). 404 customer_not_found → log + skip
  (retroactive binding is the safety net). **Checkout trigger deferred** — NOT
  redundant (order ingest only stores attribution, doesn't bind history) but the
  guest's customer is auto-created by the async order ingest, absent at checkout
  → would 404; login timing is sound (A-filter ingested the user already). JS
  `mergeIdentity` stub kept (M2 platform-agnostic). Mock merge route + unit
  (Client) + 6 integration tests. ci:strict exit=0; integration OK 70 (+6).
  **3.7.1 DONE** (live-walk + ZIP): `bin/walk-3.7-identity.cjs` — **6/6 against
  the real MiuMjau engine**. Proven live: explicit merge binds an anon session
  (`browse_events_updated=2`); idempotent on repeat (`updated=0` — no
  double-binding; the engine returns `already_bound=0` on a pure repeat, an
  informational field the plugin never consumes); and the distinction from
  retroactive binding — after a browse event with the email retroactively binds
  (`retroactive_bound=2`, 3.4.4 behaviour reconfirmed), the merge is a no-op
  (`updated=0`); plus the 404 path (unknown customer → `customer_not_found`).
  ZIP'd. **3.7 identity-merge COMPLETE.**

### In progress

- **3.8 GDPR** — rec-engine personal-data rights via the WP Privacy API. Scope
  authority: `docs/DATA_MODEL_GDPR.md` (referenced, not re-derived). DECISIONS
  F3-28. **3.8.0 DONE**: `Client::customer_export` (§8 GET) / `customer_delete`
  (§9 DELETE) / `customer_opt_out` (§10 POST) + `GdprHandler` registering a WP
  Privacy **exporter** (Art 15) + **eraser** (Art 17). Export is conservative
  (engine browse_events/visitor_tokens/recommendations/email_events + customer
  record MINUS decision-logic fields like segment/RFM/engagement + plugin
  `_smaily_*` rec-meta; NOT Woo orders/totals — Woo's exporter owns that; NOT
  rec_attribution — silent). Erase is complete (engine §9 CASCADE incl.
  attribution; 404=already-gone=success; + plugin meta removed). **HPOS-safe**:
  order meta via `$order->get_meta`/`delete_meta_data` (NOT get_post_meta — would
  miss wc_orders_meta under HPOS; caught by PHPStan, a real bug). Opt-out = the
  §10 Client method only (the Smaily profiling-consent trigger + beacon two-gate
  stop is a separate later piece). Mock §8/§9/§10 routes + 3 Client unit tests +
  5 integration (incl. the WC-boundary test: `_smaily_rec_id` exported,
  `total_amount`/`line_total` NOT). **3.8.1 DONE** (live-walk + ZIP):
  `bin/walk-3.8-gdpr.cjs` — **10/10 against MiuMjau**: export surfaces engine
  browse-activity + the order `_smaily_rec_id` read from **real `wc_orders_meta`
  (HPOS)**, excludes Woo totals + decision fields + rec_attribution; opt-out
  toggles true→false; erase removes engine records + the HPOS order-meta; a
  second erase is 404-idempotent-success. The walk **caught a latent 3.8.0 bug**:
  the GDPR endpoint URLs use a `{email}` path placeholder but `Client` substituted
  via `sprintf`/`%s`, sending the literal `{email}` to the engine (404). Unit +
  mock endpoints maps had mirrored the wrong `%s`, hiding it through all green
  gates. Fixed to `str_replace('{email}',…)` (fallback templates → `{email}` too);
  mock/unit maps switched to `{email}` + the mock customer routes now **422 on a
  literal-placeholder email** so a regression fails integration. LESSONS §2.9.
  ci:strict exit=0; unit 285; integration OK 75. ZIP'd. **3.8 GDPR COMPLETE.**

### Done — 3.9 Step-4 activation (complete)

- **3.9** Step-4 activation — connect ⇒ sync all (system-decides). The four
  per-domain sync toggles (orders/customers/products/cart) were cosmetic
  (write-only options, no consumer — ingest always gated on `is_connected()`
  alone) and are **removed** from UI + types/reducers/hydrate + the POST writes;
  dead keys cleaned idempotently in `Activation::cleanup_removed_rec_feature_options()`.
  **Browse-tracking is the only Step-4 toggle** (legal-consent gate, opt-in
  default-off). **Disconnect** clears only the `smly_rec_*` connection options and
  preserves `smly_plus_rec_track_browsing`, so **re-connect restores the toggle** —
  enabled by the **mandatory hydration fix** (`EnvDetector::rec_engine_snapshot()`
  emits the saved value independent of connection; `hydrate.ts` reads it, no longer
  hardcoding `false` — also fixes a plain-reload blanking bug). PLUGIN.md
  §Step-4-4a/§6 + §15-test-5 revised; DECISIONS F3-29; README row. ci:strict exit=0
  (unit 285, JS 134); integration OK. **Phase 3 feature work done.**

### Done — 3.10.0 pilot-hardening: Event Log visibility (Layer 1)

- **3.10.0** — the diagnostics-visibility layer (production-readiness audit P2).
  New `EventsEndpoint` (`/events` + `/events/detail`) = a read-only **Event Log**
  (PLUGIN.md §13) UNION-ing both durable queues (`smly_rec_event_queue` +
  `smly_plus_event_queue`) with source/status/type filters, pagination, drill-down
  payload, and a **failed-in-24h count** for the sticky banner. No schema change —
  the queues already carry status/attempts/last_error/created_at. New React
  **Event Log** Settings tab (table + filters + drill-down modal + sticky banner),
  always available (read-only, no Save/Discard). **Backfill progress fixed** to
  report engine-confirmed `sent` + terminal `failed` (read-time count of the job's
  event-types since `started_at`) instead of records *walked* — kills the
  "1400/1400 while rows failed" lie; the panel now shows "N synced" + a failed
  notice pointing to the Event Log. Watch-item confirmed: `last_error` carries the
  HTTP code (`http_4xx`/`http_5xx`, `d6_item_error`), so 3.10.1's auto-transient
  retry can classify 4xx-vs-5xx for free. Gates: ci:strict exit=0 (unit 285, JS
  140 +6); integration OK 82 (+7, `RecEngineEventsTest`).

### Next — pilot-hardening sequence (in order)

**Pilot-blockers (must close before pilot), in order:**
- [x] **P5** — version-floor reconciliation (WC 6.9 / WP 6.2→6.6 / PHP 8.0; WP
  tested 7.0 restored after a context-dimming slip, LESSONS §2.10).
- [x] **3.10.0** — Event Log visibility (Layer 1, above).
- [x] **3.10.1** — failed-row recovery (Layer 2, P1): `IngestQueue::reset_failed()`
  + `EventQueue::reset_failed()` (FAILED→PENDING, reset attempts/retry-park/error) +
  `POST /events/retry` (single row / all-in-a-queue / all-in-both) that kicks the
  flushers for a prompt re-send + "Retry" (per failed row) / "Retry all failed"
  (banner) buttons in the Event Log. **Manual-only** by design (auto-retry would
  loop on a deterministic 4xx; the `http_NNN` classification is recorded for a
  future guarded auto-transient pass). ci:strict exit=0 (unit 285, JS 144);
  integration OK 85 (+3, `RecEngineEventsTest` retry cases).
- [x] **3.10.2** — proactive admin-notice (Layer 3 base, §13a): `NotificationManager`
  + a 15-min recurring health-check that raises a sticky `notice-error` on **three
  signals covering both of the pilot's sync paths**: (a) failed events > 50 in 24h
  across both queues (filterable threshold); (b) the **rec-engine** unreachable > 1h;
  (c) the **Smaily** API unreachable > 1h (contacts + email automations) — both
  down signals use the same time-based `down_since` + periodic ping, gated so an
  unconfigured store isn't reported "down". Auto-clears when the condition resolves;
  **dismissible with a 24h cooldown** (nonce'd admin-post link, no per-page nag, no
  JS). No email — that's 3.10.3, post-pilot. Pure `evaluate_signals` (10 unit tests);
  `RecEngineHealthCheckTest` (2 integration). ci:strict exit=0 (unit 295, JS 144);
  integration OK 87.
- [ ] **P4** — pilot/merchant onboarding doc (INSTALL + acceptance/verify).

**Post-pilot (deferred):**
- **3.10.3** — email channel (§13a email level + Notifications subpanel) via
  `wp_mail` (admin-notice base already covers proactive-in-wp-admin; email needs
  working server SMTP — recommend an SMTP plugin in the doc).
- Queue janitor (prune `sent`/`failed` rows + index `created_at`), GCM encryption.
- **WP 7.0 env-matrix verification** — the version floors now declare WP 6.6 /
  tested 7.0 (per `docs/WP7_COMPAT.md`), but the integration suite still runs only
  WP 6.9.4. WP7_COMPAT recommends a wp-env matrix adding a WP 7.0 compat env so
  integration runs on both 6.9.4 (current) + 7.0 (compat). Worth doing for real 7.0
  proof; deferred behind the pilot-blockers (analogous to the legacy-WC matrix).

**After pilot-hardening:**
- **Smaily profiling-consent wiring + beacon two-gate stop** — awaits the Smaily
  profiling-consent parameter API spec (`SMAILY_PROFILING_CONSENT_SPEC.md`, to be
  provided). Not a numbered 3.x sub-PR.

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
- ~~**3.5** backfill~~ — DONE (above): catalog/customers/orders backfill,
  cursor-resumable, inline-flush bounded, live-walked 7/7. (Legacy order path =
  pilot precondition.)
- ~~**3.6** beacon~~ — REMOVED (not a separate sub-PR). The README feature table
  split "Browse ingest" + "Beacon (browse tracking)" as two items; 3.4
  browse-beacon shipped BOTH (3.4.0 = Client::ingest_browse + /beacon proxy =
  browse ingest; 3.4.1-.3 = the client beacon track/flush/cookies/consent/WC
  events = beacon tracking). So "3.6 beacon" duplicated 3.4. (A storefront
  recommendation-render widget is a separate FUTURE epic — never numbered here.)
- ~~**3.7** identity-merge~~ — DONE (above): anon-session → known-customer
  binding on login (NOT a customer↔customer merge — v1 has none); live-walked
  6/6. (DECISIONS F3-27.)
- ~~**3.8** GDPR (WP Privacy API)~~ — DONE (above): exporter (Art 15) + eraser
  (Art 17) + opt-out (§10), HPOS-safe order-meta; live-walked 10/10. The 3.8.1
  walk caught a latent `{email}`-placeholder substitution bug (DECISIONS F3-28.6,
  LESSONS §2.9).
- ~~**3.9** Step-4 activation~~ — DONE (above): connect ⇒ sync all
  (system-decides); per-domain sync toggles removed, browse-tracking the only
  Step-4 toggle (consent-gated, preserved across disconnect/re-connect via the
  mandatory hydration fix). DECISIONS F3-29. **Phase 3 feature work complete.**

---

## Pilot go-live — both sides must be ready

Pilot does NOT go live until all of these hold. No deadline pressure (D5).

**Plugin side:**
- [x] catalog-end ZIP'd + live-walked
- [x] customers-end ZIP'd + live-walked
- [x] orders-end ZIP'd + live-walked (12/12)
- [x] catalog-flusher N-7 D6-fix (lock RESOLVED — N-7.1, catalog live-walk 15/15)
- [x] **order-backfill LEGACY path verified against a real legacy WC env**
  (RESOLVED 2026-06-09). Stood up a `.wp-env.override.json` pinning **WC 6.9.4 +
  PHP 8.1** (WP core 6.9.4); reset the carried-over HPOS options so
  `is_hpos()=false`, `wc_orders` absent → a faithful WC 6.9.4 legacy store
  (orders in `wp_posts`). `RecEngineOrderBackfillTest` ran the legacy
  `table_spec(false)` path — `WHERE post_type='shop_order' AND post_status IN(…)
  AND ID > cursor` against the real WC 6.x posts schema (4 tests, 14 assertions),
  and the **FULL integration suite passed 75/75 on legacy** (no other path has a
  hidden HPOS assumption). PHP pin to 8.1 was used (WC 6.9.4 on PHP 8.3 risks
  deprecations); `OrderBackfillJob::is_hpos()` is correctly guarded with
  `class_exists(OrderUtil)` so it can't fatal on a pre-HPOS WC. (Harness note: the
  mock-server teardown uses the `SIGTERM` constant, undefined without `pcntl` on
  the PHP 8.1 image → the documented exit-255 wrapper quirk; tests pass.)

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
