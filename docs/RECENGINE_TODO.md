# Rec engine — engine-side requirements and recommendations

Compiled in preparation for Phase 3 of the Smaily Connect WordPress plugin. The
plugin (and the future Shopify app and other channels) assumes the engine meets
these requirements. Sorted by priority: **P0 = blocks the pilot client going live**,
**P1 = required before broader rollout**, **P2 = good practice / future**.

Authoritative API contract: `RECENGINE_API_CONTRACT.md`. This document is the
**engine-side to-do**, not a replacement for the contract.

---

## Status (updated after Phase 3 sub-PR 3.1.2 and 3.2 preparation)

**P0 — all done ✅** The pilot client going live is no longer engine-blocked.
- P0 #1 ✅ Production publicly reachable (Vercel No Protection)
- P0 #2 ✅ API key auth enforced on every endpoint (verified `401` without a key)
- P0 #3 ✅ Setup exchange works and one-time use is enforced (plugin 3.1.2 live test
  proved it: HTTP 200 against the real engine, tenant + api_key + round-trip; a
  second call with the same token is rejected)
- P0 #4 ✅ Stable response format (`X-Engine-Version: 1.0.0` header present, JSON
  structure per the contract)

**P1 — in progress / not done** (required before a second client)
- P1 #5 API key revoke — status unknown
- P1 #6 Rate limiting — engine reports "100 req/sec inbound" (3.2 report), enforcement
  status unknown
- P1 #7 Multi-tenant isolation — presumed from implementation, needs confirmation
- P1 #8 GDPR — required by Phase 3 sub-PR 3.8

**P2 — partly done**
- P2 #9 ✅ Idempotency IMPLEMENTED (engine commit 985c488, migration 0025
  `ingest_event_log`, unique `(tenant_id, event_id)` + 90-day retention, 22/22
  dedup tests pass, `{"deduplicated": true}` response)
- P2 #10 Versioning — `X-Engine-Version` present (see P0 #4), URL versioning `/v1/`
  in use
- P2 #11 ✅ Ping endpoint COMPLETE — route exists and `ingest_ping` is in the
  endpoints map (all 11 endpoints visible, confirmed during plugin 3.2.0
  preparation, commit e3acd85)
- P2 #12 Batch support — engine tolerates up to 25,000 objects/request, the plugin
  stays spec-conservative (100)

**New / uncategorized** (discovered in the Phase 3 sub-PR 3.2 plan audit)
- 🆕 The `event_id` location in the catalog body isn't documented in the spec —
  the engine added it in commit 985c488, but **per-product vs top-level is open**.
  The plugin will do a live probe before coding. **Engine team: please officially
  document this in `RECENGINE_API_CONTRACT.md` §3-5 (catalog/customers/orders body
  structure).**

---

## P0 — blocks the pilot client going live

### 1. Production publicly reachable, no Vercel SSO ✅ DONE
Vercel Deployment Protection (SSO wall) must be **removed from production**
("No Protection"). Reason: the plugin is a machine client, not a human — it can't
get through Vercel SSO login. The pilot client's WP server must reach the engine
over the internet.

- Vercel → Project Settings → Deployment Protection → Production = No Protection
- Security shifts from SSO to **api-key auth** (see point 2). The engine isn't left
  exposed — protection just moves to the right layer (machine auth, not human login).
- Dev/preview environments **may** stay behind SSO; only production needs to be public.

### 2. Every endpoint enforces api-key auth ✅ DONE
A public engine without auth = all data exposed. Every endpoint (except setup
exchange, see point 3) must require a valid api-key.

- Request without a valid api-key → **HTTP 401**
- API key sent in the request (Bearer header or whatever the contract specifies)
- Applies to ALL data endpoints: events, recommendations, identity-merge, GDPR,
  backfill ingest
- Only exception: `POST /setup/exchange` (uses the setup token, not the api-key)

### 3. Setup-token exchange (`POST /api/setup/exchange`) ✅ DONE
The plugin gets a one-time setup token from the merchant and exchanges it for a
persistent api-key. **Verified in plugin 3.1.2 live test** (HTTP 200, tenant_id +
api_key + endpoints map).

- ✅ **Setup tokens are truly one-time** — after a successful exchange the same
  token must not produce a second api-key. Plugin live test proved it (token
  "burned" after the first use).
- ✅ Exchange returns an api-key that the plugin stores encrypted on its side
- ✅ Path: `/api/setup/exchange` (not `/setup/exchange` — the `/api` prefix is
  mandatory)
- Response format and status codes must match the contract so the plugin Client can
  parse them

### 4. Stable, documented response format ✅ DONE
The plugin parses responses. Every endpoint must return a **consistent** structure.

- ✅ JSON, in the shape defined by the contract
- ✅ `X-Engine-Version: 1.0.0` header present (verified)
- ✅ Error responses structured (code + message), not an HTML page or empty body
- ✅ 4xx vs 5xx distinction meaningful (4xx = client error, don't retry; 5xx =
  engine error, may retry)

---

## P1 — required before broader rollout

### 5. API key revoke / rotation
If a pilot client's api-key leaks (e.g. plugin DB compromised), there must be a way
to **revoke** it without affecting other clients.

- Endpoint or admin capability for revoking an api-key
- Revoked key → 401, and the plugin must be able to obtain a new key with a new
  setup token
- Recommended: tie the api-key to a specific merchant/shop id (multi-tenant
  isolation)

### 6. Rate limiting
A public endpoint = a DDoS / abuse risk. Every api-key (or IP) should be
rate-limited.

- Sensible limit per api-key (event ingest can be higher, recommendations lower)
- Over the limit → **HTTP 429** + `Retry-After` header
- The plugin should handle 429 gracefully (event queue retry later) — but the
  engine must **enforce** the limit

### 7. Multi-tenant isolation
Each merchant's data (events, visitors, recommendations) must be **strictly
separated**.

- Merchant A's api-key must NEVER see merchant B's data
- api-key → shop-id mapping enforced on every request
- Critical for GDPR and trade secrecy (one merchant must not see another's customer
  base)

### 8. GDPR endpoint support
The plugin integrates with the WP Privacy API (Phase 3 sub-PR 3.8). The engine must
support:

- User data **export** (what the engine holds about a contact)
- User data **deletion** (right to be forgotten)
- Endpoints and format in the contract; the engine must actually delete data, not
  just flag it

---

## P2 — good practice / future

### 9. Idempotency in event ingest ✅ IMPLEMENTED
The plugin's event queue may retry (network outage, 5xx). The engine **prevents
duplicates**.

**Implementation** (engine commit 985c488, migration 0025):
- ✅ Events carry a unique id (`event_id` wire field name, the plugin sends it on
  every ingest endpoint)
- ✅ Unique `(tenant_id, event_id)` in the `ingest_event_log` table, 90-day
  permanent retention
- ✅ Same `event_id` twice → `200 {"deduplicated": true}` (no-op)
- ✅ Backward compat: if the plugin doesn't send `event_id` → natural-key UPSERT
  (sku / email / external_order_id). `event_id` is a **defensive layer** on top of
  natural-key UPSERT, not a replacement.
- ✅ The plugin treats `{"deduplicated": true}` as a successful processing —
  queue row `completed`, not retried
- 22/22 dedup tests pass (engine side)

**Open** (see status summary above): the `event_id` **location in the body** is
undocumented in the spec (per-product vs top-level). The plugin will do a live
probe before 3.2 coding; once that's resolved, the engine team should **document
it** in `RECENGINE_API_CONTRACT.md` §3-5.

### 10. Versioning
The API will change over time (Shopify and other channels are coming). A versioning
strategy keeps old clients working.

- `X-Engine-Version` (already P0 #4) plus consider URL versioning (`/v1/...`) or
  header versioning
- Breaking change → new version, the old one persists for a while (plugin updates
  aren't instant across all merchants)

### 11. Observability / health endpoint ✅ COMPLETE
The plugin (and you) needs to know whether the engine is working.

**Implementation** (engine commit 668d463 + endpoints-map fix e3acd85):
- ✅ Route EXISTS: `GET /api/v1/ingest/ping`, verified `401` without an api-key
- ✅ Endpoints map includes `ingest_ping` (all 11 endpoints visible in the setup
  response, confirmed in plugin 3.2.0 preparation)
- ✅ Plugin `RecEngineConnectivityTest` ping turns on in 3.2.0 — a full
  connectivity check live test
- Engine-side logging: who/when/what endpoint (debug + abuse detect) — status
  unknown

### 12. Backfill volume and batch support
The plugin runs rec-engine backfill (orders/customers/products, batch 100 — Phase 3
sub-PR 3.5). A large merchant = a lot of data.

- The engine must tolerate batch ingest (hundreds/thousands of events in a row)
- Consider a bulk endpoint (one request, N events) vs single requests (throughput
  for large backfills)
- The rate limit (point 6) must not make backfill too slow — consider a higher
  limit for ingest

---

## Note on security in general

P0 #1-4 was **done in the right order** — api-key auth was enforced before Vercel
SSO was removed, so there was no "public + open" window. ✓

Going forward: P1 (revoke, rate-limit, multi-tenant isolation) must be ready
**before a second client**. The pilot can go live without P1 (a single merchant,
controlled environment), but broader rollout requires P1.

---

## Relationship to plugin development

Plugin Phase 3 sub-PRs assume:
- **3.1** (Client + setup exchange) → P0 #3, #4
- **3.4** (events ingest) → P0 #2, P2 #9
- **3.5** (backfill) → P2 #12
- **3.7** (identity-merge) → P0 #2, P1 #7
- **3.8** (GDPR) → P1 #8

All of P0 must be ready before the pilot client goes live. P1 before a second
client. P2 on a rolling basis.
