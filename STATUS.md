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

_Last updated: 2026-06-05 (orders-end complete — .0–.4 done, live-walked 12/12, ZIP'd; next: plugin-side N-7 lock)_

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

### Next

- **plugin-side N-7** — catalog-flusher D6 consolidation (the lock condition
  below), then Phase 3 remaining (roadmap below). Awaiting go-ahead.

### Waiting / lock conditions

- **catalog-flusher N-7 D6 consolidation (LOCK)** — engine catalog is now D6
  (returns 200 + errors[]), but the plugin's IngestFlusher (catalog) is still
  all-or-nothing -> it would mark a per-item-rejected product SENT on a
  200+errors[] response (silent loss). NOT active (pilot not live, dev sends 0
  products). **Hard lock condition: must be fixed before pilot catalog go-live.**
  Plugin-side N-7 follow-up, scheduled after 3.3 orders. (DECISIONS F3-19.)

### Roadmap (Phase 3 remaining, after orders + plugin-side N-7)

- **3.4** browse-beacon (sends events; engine consumes post-MVP per §14.2 — set
  pilot expectation: collects data, improves recommendations later, not now)
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
- [ ] catalog-flusher N-7 D6-fix (lock condition above)

**Engine side:**
- [x] backend (90-95%, gaps engine-internal)
- [ ] **frontend debug views** — Customers browse + Orders browse (at minimum)
  functional, so a pilot problem ("X didn't sync") can be seen in the UI rather
  than debugged DB-direct. Engine team building UI-first.

A working backend the team can't see into is debug-blindness in pilot. Both
sides ready = go-live.

---

## Known deferred items (tracked, not blocking)

- N-7 EVENT_* constant location asymmetry (catalog on HookHandler, customer/
  order on Flusher) — unify when consolidating to a shared D6 dispatcher-flusher.
- Flaky useBackfillProgress test (fake-timers race) — fix with deterministic
  timer mocking.
- GDPR export omits rec_attribution — engine-side Art 15 legal review (not a
  contract issue for the plugin).
- F3-19 guest-customer flusher concern: RESOLVED by W5 — engine auto-creates the
  customer from the order's customer_email; OrderFlusher is order_id-based (guest
  orders have an order_id), so no payload-carried path needed.
