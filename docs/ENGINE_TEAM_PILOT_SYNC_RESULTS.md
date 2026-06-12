# Engine-side results — pilot sync checklist (2026-06-12 evening)

Reply to `ENGINE_TEAM_PILOT_SYNC.md` (plugin repo). Engine repo at commit
`d00749c`, production deploy READY 14:24 UTC.

## ⚠️ One correction to the brief's tenant model

The engine has exactly **two** tenants: **MiuMjau** (`55cf5b85-…8210`) and the
empty **"Smaily Connect test"** sandbox (`39ed99e7-…8a3e`). There is no third
"pilot tenant" — **MiuMjau IS the pilot tenant** (engine `PILOT_MONITORING.md`
says the same). Consequences:

- Today's 40/40 live-walks ran **against the pilot tenant**, not a separate dev
  tenant. The engine side found and purged the residue (6 `walk-*@example.test`
  customers, 11 orders + 11 items, 18 browse events). Catalog was clean.
  Nothing reached Smaily (`contact_sync_enabled = false`).
- Future endpoint walks must use the **"Smaily Connect test"** sandbox tenant —
  ask Erkki for a setup token from that tenant's Integrations page.
- Checklist item 6's "GDPR not yet live-walked on THIS tenant" is technically
  false (the §8/§9 walks hit this tenant), but a clean joint round-trip is
  still worth doing — see item 6 below.

## Checklist results

| # | Item | Status |
|---|---|---|
| 1 | Contract md5 (CC-8) | ✅ MATCH — `019af129fef5f4eede6a1c3232fb497f` in both repos (engine `docs/RECENGINE_API_CONTRACT.md` == plugin copy) |
| 2 | Catalog count + zero D6 | 🟡 Engine has **5783** catalog rows for the tenant (5201 `wc-*` synthetic + ~580 real-SKU rows), 5346 in stock, 76 categories, still growing during the sweep. **Plugin side: compare to the store's product+variation count.** No 4xx/5xx in engine runtime logs in the post-deploy window; D6 per-item errors are returned in responses (not stored engine-side), so confirm `errors: []` from your batch logs. |
| 3 | Orders retry wave | ✅ 2345 order events in 24h, 2333+ orders stored, **0 duplicate event_ids** (dedup holds). Spot-check order `61226`: all 5 items join to catalog rows (mixed `wc-*` + EAN keys), prices/stock sane. Note: 384/5551 order items (6.9%) reference SKUs with no catalog row — consistent with deleted products in historical orders; confirm the magnitude looks right plugin-side. |
| 4 | Browse with `sku` | 🟡 **No real browse traffic yet.** Last browse events were the 15:49 walk events (since purged). 18 browse event_ids total in 24h, all from the walk. `with_customer_match`/`retroactive_bound` can't be assessed until the store's beacon sends real traffic — **plugin side: confirm tracking is enabled on the pilot store.** |
| 5 | Engine log sweep | ✅ Postgres: zero ERRORs since the 14:24 UTC deploy (before it: the two known error classes, both fixed — see below). Vercel runtime: zero error/warning entries in the deploy window. |
| 6 | GDPR round-trip | ✅ **Done against the live API on this tenant** (Erkki-authorized temp key, 15:14 UTC): create `gdpr-roundtrip-20260612@example.test` → `GET …/export` returns full export with retention metadata → `DELETE …` returns per-table removal counts → re-export = 404 `customer_not_found`. Temp key revoked immediately after; revoked key correctly gets 401 (also live-proves the new key-revoke path end-to-end). |

## What changed ENGINE-side today that YOU should know

1. **Per-connection API keys (migration 0036).** `setup/exchange` no longer
   rotates the tenant's single key — every exchange now creates its OWN key.
   Your stored key can no longer be killed by someone else redeeming a token
   (that's exactly what broke the connection this morning). Admin UI now lists
   keys (origin, last-used, revoke) and setup tokens (multi-active, labels,
   revoke).
2. **Catalog ingest fixes (`d00749c`)** — the 91% error rate your retries hit
   was engine-side, now fixed: (a) missing intra-batch SKU dedup (same key
   twice in one batch → Postgres `ON CONFLICT … cannot affect row a second
   time`); (b) description truncation could split an emoji surrogate pair →
   jsonb `invalid input syntax for type json`. Both verified gone post-deploy.
   If your retry queue still holds items that failed with 500s from before
   14:24 UTC, they will succeed on retry.
3. **Store key reality check:** the store is NOT uniformly SKU-less — order
   `61226` mixes `wc-*` and real EAN SKUs, and ~580 catalog rows have real
   keys. Harmless engine-side (keys are opaque), but the F3-36 caveat (key
   changes if a merchant assigns a SKU later) applies to more products than
   "all of them".

## SPEC_DRAFT_BROWSE_ABANDONED_CART — engine-side answers

Good fit: the engine already has `cart_abandonment` in its Phase-2 trigger set
and a `browse_abandonment` playbook seam in the Pet pack, so this lands on
existing rails. Answers to the 5 open questions:

1. **Job shape:** cron sweep (nightly batch + optional intraday run), not
   event-driven — matches the engine's data-enrichment paradigm (no per-event
   orchestration in MVP) and makes the F3-37 age-window trivially enforceable
   in one query.
2. **Defaults:** W1 = 2h–24h (below 2h feels like surveillance, beyond 24h
   cart is stale); rate cap max 1 reminder / 7 days per customer, engine-side.
3. **Trigger path:** reuse the existing Smaily custom-field mechanism
   (engine CLAUDE.md §14.1(a) trigger-block model): engine writes
   `rec_cart_abandoned=yes` + reconstructed cart into contact fields, the
   tenant's own Smaily automation sends. No new engine-side template engine.
4. **`qty` on `cart_add`:** not needed for v1 — SKU set + catalog join gives
   name/price/url, which is enough for a reminder. Add later if a tenant asks.
5. **Consent:** Smaily contact state at trigger time, via the existing
   contact-sync path — Smaily's own automation refuses unsubscribed contacts,
   which is the authoritative gate; the engine additionally never syncs
   opted-out customers (`customers.opted_out`). No cached flag.

Status stays 🟡 post-pilot on the engine backlog too; nothing blocks.

## Open items after this sync

- [ ] Plugin side: catalog count comparison vs store (item 2) + `errors: []` confirmation from backfill batch logs.
- [ ] Plugin side: enable/verify browse beacon on the pilot store (item 4).
- [x] ~~Joint: GDPR round-trip~~ — done engine-side 2026-06-12 15:14 UTC (item 6 ✅).
- [ ] Either: future walks → "Smaily Connect test" sandbox tenant only.
