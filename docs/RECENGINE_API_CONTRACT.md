# Smaily Recommendation Engine — API Contract v1.0

**Version**: 1.0.0
**Published**: 2026-05-19
**Last updated**: 2026-05-22 (translation + event_id coverage + dedup window correction)
**Status**: Stable — basis for plugin implementation

---

## Document location and synchronization

This contract lives in two repositories and must stay byte-for-byte synchronized:
- `plugin-repo/docs/RECENGINE_API_CONTRACT.md` (Smaily Connect WordPress plugin)
- `engine-repo/docs/RECENGINE_API_CONTRACT.md` (rec engine)

When proposing a change (either side):
1. Discuss in shared channel before implementing
2. Update both repos in the same work session, one commit per repo
3. Verify diffs match before merging either

When you spot a drift (one side has a field, the other doesn't; an endpoint moved; a response shape changed) — fix both copies immediately, don't defer. Past drifts (`/api` path prefix, `event_id` body coverage) caused integration bugs that took days to trace.

Why not git submodule or a separate contracts repo right now: over-engineering for two consumers. Revisit when a third consumer arrives (Shopify app, Milestone 3 on the plugin roadmap).

---

## Overview

This document consolidates the earlier dialogue (`RECENGINE_API_ANALYSIS.md` + `RECENGINE_API_ROUND2.md`) into a clean REST API specification. It is platform-agnostic — the same contract serves WooCommerce, Shopify, Magento, PrestaShop, custom stores, and Make-flow integrations.

**WordPress-specific notes** (Action Scheduler, HPOS, hooks, REST endpoint patterns) live in a separate document, `PLUGIN_IMPLEMENTATION_WP.md`.

---

## Table of contents

1. [Base context](#base-context)
2. [Authentication](#authentication)
3. [Versioning](#versioning)
4. [URL namespace and cookie names](#url-namespace-and-cookie-names)
5. [Error handling](#error-handling)
6. [Rate limiting](#rate-limiting)
7. [Idempotency](#idempotency)
8. [Endpoints](#endpoints)
   - [POST /api/setup/exchange](#1-post-apisetupexchange)
   - [GET /api/v1/ingest/ping](#2-get-apiv1ingestping)
   - [POST /api/v1/ingest/catalog](#3-post-apiv1ingestcatalog)
   - [POST /api/v1/ingest/customers](#4-post-apiv1ingestcustomers)
   - [POST /api/v1/ingest/orders](#5-post-apiv1ingestorders)
   - [POST /api/v1/ingest/browse](#6-post-apiv1ingestbrowse)
   - [POST /api/v1/identity/merge](#7-post-apiv1identitymerge)
   - [GET /api/v1/customer/{email}/export](#8-get-apiv1customeremailexport)
   - [DELETE /api/v1/customer/{email}](#9-delete-apiv1customeremail)
   - [POST /api/v1/customer/{email}/opt-out](#10-post-apiv1customeremailopt-out)
9. [Appendices](#appendices)

---

## Base context

**Base URL**: varies by deployment environment, available as `engine_base_url` in the setup-exchange response.

**Production base URL** (first pilot): `https://re-erkkimarkus-projects.vercel.app`

> The previous pilot deploy `re-seven-indol.vercel.app` is retired (sub-PR 3.0 connectivity test caught the dead URL). All URL examples below use the current production base.

**Path prefix**: ALL plugin-to-engine requests use the `/api` prefix, including setup-exchange itself (`/api/setup/exchange`, NOT `/setup/exchange`). An earlier draft of spec v1.0 documented setup without `/api` — that was a defect fixed in sub-PR 3.1.2. The engine's actual route table serves everything under `/api/*`. The centralized constants list lives on the plugin side at `Smaily\Connect\Smaily\RecEngine\Client::PATH_*`.

**Content-Type**: all requests and responses use `application/json; charset=utf-8`.
- The engine **always** returns `Content-Type: application/json`, including in error responses.
- The engine **always** returns `Cache-Control: no-store` for authenticated endpoint responses (requests carrying an API key).

**Character encoding**: all request and response strings are UTF-8. JSON keys use lowercase + underscore (`first_name`, not `firstName`).

**Timezone**: all timestamp fields use ISO 8601 in UTC (`2026-05-19T10:15:23Z`). The engine converts internally to the tenant's timezone when needed.

**ID formats**:
- Tenant IDs: UUID v4
- Customer IDs: UUID v4 (generated engine-side from the first customer ingest)
- Recommendation IDs: UUID v4
- Visitor tokens: opaque string, 8–12 characters, prefix `vt_`
- Order `external_id`: text, plugin/platform-defined
- SKU: text, max 64 characters

---

## Authentication

**Scheme**: HTTP Bearer Token

```
Authorization: Bearer sk_<random_32_chars>
```

The API key is tenant-scoped, obtained via `POST /api/setup/exchange`. **The API key must never appear in client-side code** (JavaScript, mobile app bundle). A plugin-side server proxy is required for browse events.

The setup endpoint (`POST /api/setup/exchange`) is the **only** unauthenticated endpoint — it accepts a setup token instead of an API key.

**Auth failure responses**:
- `401 Unauthorized` if the `Authorization` header is missing or the API key is invalid
- `401 Unauthorized` with `error: "api_key_revoked"` and a `regenerate_url` if the API key has been revoked in the admin UI

```json
{
  "error": "api_key_revoked",
  "regenerate_url": "https://re-erkkimarkus-projects.vercel.app/setup/regenerate/{tenant_id}",
  "message": "Your API key was revoked. Use the regenerate URL to obtain a new one."
}
```

---

## Versioning

**Engine version** is sent in every response header:

```
X-Engine-Version: 1.0.0
```

**Versioning rules** (Semantic Versioning 2.0):
- **MAJOR** (`2.0.0`): breaking changes to the API contract. The plugin must update before working with the new major.
- **MINOR** (`1.1.0`): new endpoints or new optional fields. Backward-compatible.
- **PATCH** (`1.0.1`): bug fixes; no contract changes.

**Plugin-side behavior** on version mismatch:
- The plugin declares its `compatible_engine_version_range` (e.g. `>=1.0.0,<2.0.0`)
- After every response, the plugin checks the `X-Engine-Version` header
- On mismatch: **graceful degradation** — the plugin continues operating and displays an admin notice ("Engine version X.Y.Z is newer than this plugin supports")
- The plugin **does not refuse to operate** on a version mismatch — data loss is a larger risk than a compatibility issue

**Setup response includes `engine_version`** so the plugin knows at install time which version it's working with.

**Note on version consistency**: the engine reads `ENGINE_VERSION` from environment configuration. The same value appears in the `X-Engine-Version` HTTP header and in any Smaily contact-sync payload field (`rec_engine_version`). One source of truth — if the env var is `1.0.0`, both surfaces show `1.0.0`.

---

## URL namespace and cookie names

### URL parameters (on campaign links)

The engine renders Smaily contact-field `product_url` values with these parameters appended:

```
https://shop.example.com/product/widget?
  utm_source=smaily&
  utm_campaign=welcome_series&
  smaily_vt=vt_8f3k2a&
  smaily_rec=rec_abc123&
  smaily_ctx=cart_abandoned
```

**Reserved Smaily-prefixed parameters**:

| Parameter | Content | Use |
|-----------|---------|-----|
| `smaily_vt` | Visitor token (opaque, prefix `vt_`) | Identity resolution (anonymous → customer_id) |
| `smaily_rec` | Recommendation ID (UUID) | Attribution: which recommendation was clicked |
| `smaily_ctx` | Context string (`welcome`, `cart_abandoned`, `cross_sell`, etc.) | Attribution: in which context the click occurred |

**UTM namespace** is reserved for the client's marketing tools (Google Analytics, ad platforms). The engine does NOT use `utm_content` for `rec_id` — that would pollute GA attribution data.

**Plugin-side capture**: when the plugin sees a URL with `smaily_*` parameters, it stores them in cookies (see below) and forwards them in subsequent API calls to the engine.

### Cookie names (plugin-side management)

The plugin manages four cookies. Names come from the **engine setup-response config** (allowing per-deployment overrides):

| Cookie | Default name | TTL | Content | SameSite/Secure |
|--------|--------------|-----|---------|-----------------|
| Visitor token | `smaily_rec_uid` | 365 days | URL `smaily_vt` value | Lax / Secure |
| Anonymous session ID | `smaily_anon_sid` | 30 days | UUID v4 (plugin-generated on first visit) | Lax / Secure |
| Recommendation ID | `smaily_rec_id` | 30 days | URL `smaily_rec` value (last-touch) | Lax / Secure |
| Context | `smaily_rec_ctx` | 30 days | URL `smaily_ctx` value (last-touch) | Lax / Secure |

**HttpOnly** = `false` — cookies are JavaScript-accessible (the beacon proxy uses them).
**Domain**: `auto` (uses the `Domain=.example.com` pattern so both `www.example.com` and `example.com` are covered).

**Last-touch overwrite**: each new email click overwrites `smaily_rec_id` and `smaily_rec_ctx`. Last touch wins on the cookie. **The engine retains first-touch info** in the `rec_attribution` table; the cookie carries only last touch.

---

## Error handling

### HTTP status codes

| Code | Meaning | Plugin retry? |
|------|---------|---------------|
| 200 | OK, success | — |
| 201 | Created (resource created) | — |
| 204 | No Content (success, no body) | — |
| 400 | Bad Request — invalid request body | NO |
| 401 | Unauthorized — API key invalid or revoked | NO (display admin notice) |
| 403 | Forbidden — tenant deactivated or access denied | NO |
| 404 | Not Found — resource does not exist | NO |
| 409 | Conflict — idempotency conflict (rare) | NO |
| 429 | Too Many Requests — rate limit | YES (exponential backoff, honor `Retry-After` / `retry_after_seconds`) |
| 500 | Internal Server Error — engine down | YES (exponential backoff, up to 3 retries) |
| 502/503/504 | Bad Gateway / Service Unavailable / Gateway Timeout | YES (same as 500) |

### Error response format

Every error response contains JSON:

```json
{
  "error": "error_code_snake_case",
  "message": "Human-readable explanation",
  "details": {
    "field": "Optional context (validation errors)",
    "valid_values": ["array of valid values if applicable"]
  },
  "request_id": "req_uuid_v4",
  "timestamp": "2026-05-19T10:15:23Z"
}
```

`request_id` is for debugging — when the plugin sees an error, it surfaces this in the admin notice so Erkki can file a support request referencing the request_id.

### Validation error example

```json
HTTP 400 Bad Request

{
  "error": "validation_failed",
  "message": "One or more fields failed validation",
  "details": {
    "errors": [
      {
        "field": "products[0].sku",
        "code": "required",
        "message": "SKU is required and cannot be empty"
      },
      {
        "field": "products[2].price",
        "code": "invalid_type",
        "message": "Price must be a positive number",
        "actual_value": -5.99
      }
    ]
  },
  "request_id": "req_8f3k2a-...",
  "timestamp": "2026-05-19T10:15:23Z"
}
```

**Plugin behavior**: on 400, parse `details.errors` and surface in an admin notice. **Do not retry** — a bad request will not improve with retries.

---

## Rate limiting

**Default limits**:

| Endpoint prefix | Limit | Window |
|-----------------|-------|--------|
| `/api/v1/ingest/browse` | 500 requests | per 1 second |
| `/api/v1/ingest/catalog` | 100 requests | per 1 second |
| `/api/v1/ingest/customers` | 100 requests | per 1 second |
| `/api/v1/ingest/orders` | 100 requests | per 1 second |
| `/api/v1/identity/merge` | 100 requests | per 1 second |
| `/api/v1/customer/...` (GDPR) | 10 requests | per 60 seconds |
| `/api/setup/exchange` | 10 requests | per 60 seconds (per IP) |

**Identity**: authenticated requests are tracked by `<tenant_id>:<endpoint_prefix>`. The anonymous setup-exchange endpoint is tracked by `ip:<ip>`.

**Storage**: in-memory per Vercel instance (MVP; not shared across instances). Redis-backed global rate limiting is deferred to v2. For the pilot scale (one tenant, single Vercel instance handling traffic), per-instance limits are sufficient.

**429 Too Many Requests response**:

```
HTTP 429 Too Many Requests
X-RateLimit-Limit: 500
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 2026-05-19T10:15:28Z

{
  "error": "rate_limit_exceeded",
  "message": "Rate limit exceeded. Retry after 5 seconds.",
  "retry_after_seconds": 5,
  "request_id": "req_...",
  "timestamp": "2026-05-19T10:15:23Z"
}
```

> **Header note**: the engine emits the `X-RateLimit-*` trio (Limit, Remaining, Reset). The literal `Retry-After` header is not currently emitted — the `retry_after_seconds` field in the JSON body conveys the same information. Plugin should read from the body.

**Plugin behavior**:
- Honor `retry_after_seconds` from the response body (or `X-RateLimit-Reset` timestamp)
- Exponential backoff: 1s, 2s, 4s, 8s, 16s (max 5 retries)
- After 5 retries: log the error, display an admin notice, **do not lose the event** (keep it in the local queue)
- Browse events: **batch mode auto-activates** on 429 (collects events in a 5-second window, sends up to 100 per batch)

---

## Idempotency

Two layers of idempotency protect against duplicate processing:

### Layer 1: Natural-key UPSERT (always active)

Each ingest endpoint has a natural business key that uniquely identifies the record:

| Endpoint | Natural key |
|----------|-------------|
| `/api/v1/ingest/catalog` | `(tenant_id, sku)` |
| `/api/v1/ingest/customers` | `(tenant_id, email)` |
| `/api/v1/ingest/orders` | `(tenant_id, external_order_id)` |
| `/api/v1/ingest/browse` | (none — browse events are not semantically idempotent) |

Sending the same record twice updates (UPSERT) — no duplicates. This protects against any retry, regardless of whether the plugin sends an `event_id`.

### Layer 2: Transport-level deduplication (`event_id`, optional, per-item)

To handle queue-level retries cleanly, the plugin may send an `event_id` field **on each item** — every `products[]` / `customers[]` / `orders[]` / `events[]` object may carry its own `event_id` (string, UUID v4). The engine records `(tenant_id, event_id)` in the `ingest_event_log` table with a 90-day permanent retention window (cleaned by the daily retention cron).

**Behavior** (per-item, integer counts):
- Each item carrying a **new** `event_id` → processed, and that `event_id` is logged.
- Each item whose `event_id` is **already** in `ingest_event_log` → skipped; counted in `deduplicated`.
- The response returns integer counts: `{"processed": N, "deduplicated": M}`, where `M` is the number of items whose `event_id` was already seen. When `M` equals the total item count (a pure no-op retry), the response also includes `"deduplicated_all": true`.
- **Intra-batch duplicates** (the same `event_id` twice in one request): the first occurrence is `processed`, the second is `deduplicated`.
- Items **without** an `event_id` use Layer 1 (natural-key UPSERT) only and count toward `processed` when the row is created/updated.
- **Wrapper-level `event_id` is not supported.** An `event_id` placed at the top level of the request body (outside the item objects) is **silently ignored** — the request proceeds using per-item semantics only. There is no whole-request boolean short-circuit; the response is always the integer-count shape.

The per-item `event_id` field is **optional** on catalog / customers / orders — if omitted, only Layer 1 (natural-key UPSERT) is used. Browse has no Layer-1 fallback, so `event_id` is effectively required there (see the browse endpoint section).

**Endpoints accepting `event_id`**:
- `/api/v1/ingest/catalog` — per product (each `products[]` object)
- `/api/v1/ingest/customers` — per customer (each `customers[]` object)
- `/api/v1/ingest/orders` — per order (each `orders[]` object)
- `/api/v1/ingest/browse` — per event (each `events[]` object)

**Wire field name**: `event_id` (snake_case, string, UUID v4). The plugin's internal queue column may use a different name (the Smaily Connect plugin uses `event_uuid` internally) — the PayloadBuilder maps it to `event_id` on the wire.

**Browse events** are semantically not idempotent: the same customer may view the same SKU three times in a day, producing three legitimate browse events. The `event_id` layer protects against retry duplicates (network errors, queue replays), not against legitimate repeated activity.

### Idempotency examples

**Catalog, two requests with the same `event_id`**:
```bash
# Request 1
POST /api/v1/ingest/catalog
{"products":[{"sku":"ACA-001","event_id":"<uuid-1>","name":"...","price":9.99,...}]}
# → 200 {"ok":true,"processed":1,"deduplicated":0,"errors":[]}

# Request 2 (retry with same per-item event_id)
POST /api/v1/ingest/catalog
{"products":[{"sku":"ACA-001","event_id":"<uuid-1>","name":"...","price":9.99,...}]}
# → 200 {"ok":true,"processed":0,"deduplicated":1,"errors":[],"deduplicated_all":true}
```

**Catalog, two requests with different `event_id` but same SKU**:
```bash
# Request 1
POST /api/v1/ingest/catalog
{"products":[{"sku":"ACA-001","event_id":"<uuid-1>","name":"...","price":9.99,...}]}
# → 200 {"ok":true,"processed":1,"deduplicated":0,"errors":[]}

# Request 2 (legitimate update — different event_id, same SKU)
POST /api/v1/ingest/catalog
{"products":[{"sku":"ACA-001","event_id":"<uuid-2>","name":"...","price":12.99,...}]}
# → 200 {"ok":true,"processed":1,"deduplicated":0,"errors":[]} (Layer 1 UPSERT)
```

---

## Endpoints

### 1. POST /api/setup/exchange

Setup-token exchange. **Unauthenticated endpoint** (the only one).

**Use case**: after tenant creation in the admin UI, Erkki gets a setup URL (e.g. `https://re-erkkimarkus-projects.vercel.app/setup/abc123xyz`). The client pastes this URL into the plugin's Settings; the plugin extracts the token (`abc123xyz`) and calls this endpoint to obtain its technical configuration.

**URL**: `POST /api/setup/exchange`

**Auth**: none

**Rate limit**: 10 requests per 60 seconds (per IP)

**Headers**:
```
Content-Type: application/json
User-Agent: <plugin-identifier>/<version>  (e.g. "SmailyRecEngine-WooPlugin/0.1.0")
```

**Request body**:
```json
{
  "setup_token": "abc123xyz",
  "plugin_info": {
    "name": "smaily-rec-woo",
    "version": "0.1.0",
    "platform": "wordpress",
    "platform_version": "6.4.2",
    "ecommerce_platform": "woocommerce",
    "ecommerce_platform_version": "8.5.1",
    "site_url": "https://erkkipood.ee"
  }
}
```

`plugin_info` is recorded in the audit log (`tenant_setup_tokens.used_from_plugin`). This tells Erkki which plugin version the client connected with.

**Response 200 OK**:
```json
{
  "tenant_id": "550e8400-e29b-41d4-a716-446655440000",
  "tenant_name": "Erkki Pood",
  "api_key": "sk_8f3k2a4e1c4d8a9b2f7e3d1a6c8b9e0f",
  "engine_base_url": "https://re-erkkimarkus-projects.vercel.app",
  "engine_version": "1.0.0",
  "endpoints": {
    "ingest_ping":       "https://re-erkkimarkus-projects.vercel.app/api/v1/ingest/ping",
    "ingest_catalog":    "https://re-erkkimarkus-projects.vercel.app/api/v1/ingest/catalog",
    "ingest_customers":  "https://re-erkkimarkus-projects.vercel.app/api/v1/ingest/customers",
    "ingest_orders":     "https://re-erkkimarkus-projects.vercel.app/api/v1/ingest/orders",
    "ingest_browse":     "https://re-erkkimarkus-projects.vercel.app/api/v1/ingest/browse",
    "identity_merge":    "https://re-erkkimarkus-projects.vercel.app/api/v1/identity/merge",
    "customer_export":   "https://re-erkkimarkus-projects.vercel.app/api/v1/customer/{email}/export",
    "customer_delete":   "https://re-erkkimarkus-projects.vercel.app/api/v1/customer/{email}",
    "customer_opt_out":  "https://re-erkkimarkus-projects.vercel.app/api/v1/customer/{email}/opt-out",
    "recommendations_preview": "https://re-erkkimarkus-projects.vercel.app/api/v1/recommendations/preview",
    "recommendations_issue":   "https://re-erkkimarkus-projects.vercel.app/api/v1/recommendations/issue"
  },
  "config": {
    "tracking_cookie_name": "smaily_rec_uid",
    "session_cookie_name": "smaily_anon_sid",
    "rec_id_cookie_name": "smaily_rec_id",
    "context_cookie_name": "smaily_rec_ctx",
    "cookie_ttl_days": 365,
    "session_ttl_days": 30,
    "rec_id_ttl_days": 30,
    "context_ttl_days": 30,
    "rate_limit_browse": 500,
    "rate_limit_other": 100,
    "batch_size_max": 100,
    "supported_languages": ["et", "en"],
    "url_param_visitor_token": "smaily_vt",
    "url_param_rec_id": "smaily_rec",
    "url_param_context": "smaily_ctx"
  },
  "issued_at": "2026-05-19T10:15:23Z"
}
```

**Endpoint map convention**: keys use `ingest_*`, `identity_*`, `customer_*`, `recommendations_*` prefixes for the categories. Plugin code should read endpoint URLs from this map (`endpoints[ingest_catalog]`) rather than concatenating base URL + hardcoded paths. This way, future path migrations on the engine side don't require plugin updates — only the setup-response map changes.

**Response 410 Gone** (token expired or used):
```json
{
  "error": "setup_token_expired_or_used",
  "message": "This setup token has expired or has already been used. Ask the engine administrator to generate a new one.",
  "regenerate_url": "https://re-erkkimarkus-projects.vercel.app/admin/tenants/{tenant_id}/regenerate-setup-token",
  "request_id": "req_..."
}
```

**Response 404 Not Found** (token does not exist):
```json
{
  "error": "setup_token_not_found",
  "message": "Setup token not found. Verify the URL is correct.",
  "request_id": "req_..."
}
```

**Idempotency**: setup tokens are **one-time use**. The first exchange marks `used_at = NOW()`. A subsequent exchange returns 410. The plugin must securely store the API key from the first exchange.

**Plugin-side**: after a successful exchange, the plugin stores `api_key`, `engine_base_url`, and `config` in its WordPress options table (api_key encrypted, `wp_options` with `autoload=false`).

---

### 2. GET /api/v1/ingest/ping

Health-check endpoint. The plugin's "Test Connection" button in Settings calls this.

**URL**: `GET /api/v1/ingest/ping`

**Auth**: `Authorization: Bearer sk_...`

**Headers**:
```
Authorization: Bearer sk_...
User-Agent: SmailyRecEngine-WooPlugin/0.1.0
```

**Response 200 OK**:
```json
{
  "ok": true,
  "pong": true,
  "tenant_id": "550e8400-...",
  "industry": "pet",
  "engine_version": "1.0.0",
  "ts": "2026-05-19T10:15:23Z"
}
```

**Response 401** if the API key is invalid (see Authentication).

**Response 403** if the tenant is deactivated:
```json
{
  "error": "tenant_inactive",
  "message": "This tenant is currently deactivated. Contact engine administrator.",
  "tenant_status": "suspended"
}
```

**Rate limit**: 100 req/sec (default for non-browse endpoints).

**Idempotency**: not applicable (read-only endpoint).

---

### 3. POST /api/v1/ingest/catalog

Batch upload of the product catalog. The engine UPSERTs each product (same `sku` = update, new `sku` = insert).

**URL**: `POST /api/v1/ingest/catalog`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec, up to 100 products per request

**Request body**:
```json
{
  "products": [
    {
      "event_id": "evt_b3f1a2c4-...",
      "sku": "ACA-DOG-3KG",
      "name": "Acana Adult Dog 3kg",
      "category_path": "food/dry",
      "price": 22.99,
      "compare_price": 25.99,
      "on_sale_until": "2026-06-01T00:00:00Z",
      "in_stock": true,
      "description": "Premium dry food for adult dogs",
      "image_url": "https://erkkipood.ee/wp-content/uploads/aca-dog-3kg.jpg",
      "product_url": "https://erkkipood.ee/product/acana-adult-dog-3kg/",
      "external_id": "12345",
      "tags": {
        "brand": "Acana",
        "category_path": "food/dry"
      },
      "raw_attributes": {
        "pa_species": ["dog"],
        "pa_life_stage": ["adult"],
        "pa_protein": ["chicken"],
        "meta_typical_pack_size": "3kg",
        "_wc_categories": ["Food", "Dry Food", "Acana"]
      }
    }
  ]
}
```

**Multilingual variant** (`name`, `description`, `product_url` accept object form):
```json
{
  "products": [
    {
      "sku": "ACA-DOG-3KG",
      "name": {
        "et": "Acana Täiskasvanud Koer 3kg",
        "en": "Acana Adult Dog 3kg"
      },
      "description": {
        "et": "Premium kuivtoit täiskasvanud koertele",
        "en": "Premium dry food for adult dogs"
      },
      "product_url": {
        "et": "https://erkkipood.ee/et/toode/acana-koer-3kg/",
        "en": "https://erkkipood.ee/en/product/acana-dog-3kg/"
      },
      "price": 22.99,
      "in_stock": true,
      "tags": { "brand": "Acana", "category_path": "food/dry" },
      "raw_attributes": { }
    }
  ]
}
```

The engine accepts both forms — field type is checked at runtime. Storage behavior:
- A single-language `string` is wrapped as `{default: "..."}` in the field's `*_i18n` JSONB column (`name_i18n`, `description_i18n`, `product_url_i18n`), with the string also kept in the plain text column.
- An object (`{et: ..., en: ...}`) is stored verbatim in the `*_i18n` column, plus a representative scalar (`default` → tenant default → `en` → first key) in the plain text column for the non-i18n render fallback.
- `description` is truncated to **500 characters per language** (the row is not rejected).
- `image_url` has no `*_i18n` column — only a representative scalar is stored.

**Field reference**:

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `event_id` | UUID v4 string | NO | Per-product transport-level dedup key. See [Idempotency](#idempotency). |
| `sku` | string (max 64) | YES | Unique product identifier (natural key for UPSERT) |
| `name` | string \| `{lang: string}` | YES | Product name |
| `category_path` | string | YES | Hierarchical category (`food/dry`, `accessories/leashes`) |
| `price` | number | YES | Customer's current price (NOT regular_price) |
| `compare_price` | number | NO | For sale items — what they would cost without the discount |
| `on_sale_until` | ISO 8601 string | NO | End of sale period (if applicable) |
| `in_stock` | boolean | YES | Whether the product is available |
| `description` | string \| `{lang: string}` | NO | Short description (max 500 characters) |
| `image_url` | string (URL) \| `{lang: string}` | NO | Product image URL. **Stored as a representative scalar only** — there is no `image_url_i18n` column, so the `{lang}` form is accepted but not stored per-language. |
| `product_url` | string (URL) \| `{lang: string}` | YES | Product page URL. **Required, non-empty** — an empty string `""` is rejected (400), mirroring `category_path`. No silent fallback to `product_base_url + sku`. |
| `external_id` | string | NO | Plugin/platform internal ID (for debugging/traceability). |
| `tags` | object | NO | Best-effort mapping (engine uses immediately) |
| `raw_attributes` | object | NO | Raw platform data. **Currently stored verbatim and not processed** — the AI mapping wizard / `unmapped_attributes` flow is planned, not yet implemented. |

**Response 200 OK**:
```json
{
  "ok": true,
  "processed": 47,
  "deduplicated": 0,
  "errors": []
}
```

`processed` = products UPSERTed; `deduplicated` = products whose per-item `event_id` was already seen (see [Idempotency](#idempotency)). `errors` is currently always `[]` on a 200 — catalog validation is **all-or-nothing** (see partial-success note below).

> **Not yet implemented:** the `unmapped_attributes` response array and the AI mapping wizard are planned but not built. `raw_attributes` is currently stored verbatim and not processed, so no `unmapped_attributes` is emitted.

**Response 200 OK (deduplicated retry, all items)**:
```json
{
  "ok": true,
  "processed": 0,
  "deduplicated": 5,
  "errors": [],
  "deduplicated_all": true
}
```

Returned when every product carrying an `event_id` in the request was already processed (matching `event_id` already in `ingest_event_log`). No catalog row was created or updated. `deduplicated` is the integer count of skipped items, and `deduplicated_all: true` flags a pure no-op retry. Plugin should treat this as a successful no-op — the original request was processed earlier. (A *mixed* batch returns e.g. `{"processed":3,"deduplicated":2,"errors":[]}` with no `deduplicated_all`.)

**Validation is all-or-nothing** (no partial success). If **any** product in the batch fails schema validation, the whole request is rejected with `400 validation_failed` and nothing is written — so on a 200 the `errors` array is always empty. The error body keys nested product-field failures under the top-level `products` key:
```json
{
  "error": "validation_failed",
  "details": {
    "formErrors": [],
    "fieldErrors": {
      "products": ["String must contain at least 1 character(s)"]
    }
  }
}
```

(Example above: a product sent with `product_url: ""`. A missing required field yields `"Required"`; a wrong type yields `"Invalid input"`.)

**Idempotency**: two layers, as described in [Idempotency](#idempotency):
- **Layer 1** (always active): `(tenant_id, sku)` natural-key UPSERT. Same SKU sent twice → second call updates.
- **Layer 2** (optional, per-item `event_id`): an item whose `event_id` was already seen is counted in `deduplicated` and not re-UPSERTed (the whole request is a no-op when `deduplicated_all: true`).

---

### 4. POST /api/v1/ingest/customers

Batch upload of customers. UPSERT by email.

> **Implementation status (Route A):** The engine currently accepts **single-object** payloads (one customer per POST). Batch array acceptance (the `customers[]` wrapper) lands in W4 / W5 per Route A plan v2. The field reference and examples below describe the target batch contract.

**URL**: `POST /api/v1/ingest/customers`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec, up to 100 customers per request

**Request body**:
```json
{
  "customers": [
    {
      "event_id": "evt_a1b2c3d4-...",
      "email": "mari@example.com",
      "first_name": "Mari",
      "last_name": "Tamm",
      "country": "EE",
      "language": "et",
      "phone": "+372...",
      "first_seen_at": "2026-01-15T10:30:00Z",
      "external_id": "67",
      "consent": {
        "marketing": true,
        "recommendations": true,
        "consent_at": "2026-01-15T10:30:00Z"
      }
    }
  ]
}
```

**Field reference**:

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `event_id` | UUID v4 string | NO | Per-customer transport-level dedup key. See [Idempotency](#idempotency). |
| `email` | string (valid email) | YES | Primary identifier (natural key for UPSERT). Engine lowercases on ingest. |
| `first_name` | string | NO | |
| `last_name` | string | NO | |
| `country` | string (ISO 3166-1 alpha-2) | NO | E.g. "EE", "FI", "US" |
| `language` | string (ISO 639-1) | NO | E.g. "et", "en", "ru" — used for template rendering |
| `phone` | string | NO | |
| `first_seen_at` | ISO 8601 | NO | Registration timestamp (if different from row creation) |
| `external_id` | string | NO | Platform-internal user_id |
| `consent.marketing` | boolean | NO | GDPR consent for marketing |
| `consent.recommendations` | boolean | NO | GDPR consent for the recommendation engine |
| `consent.consent_at` | ISO 8601 | NO | Consent timestamp |

> **Note on consent**: the engine does **not** filter recommendations based on `consent.marketing`. Smaily is the source of truth for marketing consent — Smaily will not send to contacts without it, regardless of what the engine writes to their contact fields. The engine accepts and stores these consent fields for audit purposes, but it does not gate processing on them.

**Response 200 OK**:
```json
{
  "ok": true,
  "processed": 30,
  "created": 5,
  "updated": 25,
  "skipped": 0,
  "errors": [],
  "request_id": "req_..."
}
```

**Response 200 OK (deduplicated retry)**: integer-count shape, as in catalog. A duplicate `event_id` is counted in `deduplicated`; a whole-request no-op returns `{"ok":true,"processed":0,"deduplicated":1,"errors":[],"deduplicated_all":true}`.

**Idempotency**: two layers, as described in [Idempotency](#idempotency):
- **Layer 1**: `(tenant_id, email)` natural-key UPSERT.
- **Layer 2**: optional per-item `event_id` for retry deduplication (integer counts).

---

### 5. POST /api/v1/ingest/orders

Batch upload of orders + order items. HPOS-aware payload.

> **Implementation status (Route A):** The engine currently accepts **single-object** payloads (one order per POST; the order's line items are the nested `items[]` array). Batch array acceptance (the `orders[]` wrapper) lands in W4 / W5 per Route A plan v2. The field reference and examples below describe the target batch contract.

**URL**: `POST /api/v1/ingest/orders`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec, up to 50 orders per request (orders + items can be sizable together)

**Request body**:
```json
{
  "orders": [
    {
      "event_id": "evt_c1d2e3f4-...",
      "external_order_id": "WC-12345",
      "customer_email": "mari@example.com",
      "ordered_at": "2026-05-15T14:30:00Z",
      "total_amount": 67.50,
      "discount_amount": 5.00,
      "currency": "EUR",
      "status": "completed",
      "smaily_rec_id": "rec_abc123",
      "smaily_visitor_token": "vt_8f3k2a",
      "smaily_rec_ctx": "cart_abandoned",
      "session_id": "wp_sess_abc123",
      "items": [
        {
          "sku": "ACA-DOG-3KG",
          "qty": 1,
          "unit_price": 22.99,
          "line_total": 22.99,
          "discount_amount": 0.00
        },
        {
          "sku": "POC-DENT",
          "qty": 2,
          "unit_price": 22.255,
          "line_total": 44.51,
          "discount_amount": 5.00
        }
      ]
    }
  ]
}
```

**Field reference**:

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `event_id` | UUID v4 string | NO | Per-order transport-level dedup key. See [Idempotency](#idempotency). |
| `external_order_id` | string | YES | Plugin/platform order ID (UNIQUE per tenant — natural key) |
| `customer_email` | string | YES | Customer reference is by email (not external_id). Engine lowercases on ingest. |
| `ordered_at` | ISO 8601 | YES | Order placement timestamp |
| `total_amount` | number | YES | Total after discounts |
| `discount_amount` | number | NO | Total discount (default 0) |
| `currency` | string (ISO 4217) | NO | Default "EUR" |
| `status` | enum | YES | `completed`, `processing`, `cancelled`, `refunded` |
| `smaily_rec_id` | string | NO | Attribution: which recommendation was clicked pre-purchase (from cookie) |
| `smaily_visitor_token` | string | NO | Attribution: visitor token (from cookie) |
| `smaily_rec_ctx` | string | NO | Attribution: context (from cookie) |
| `session_id` | string | NO | Session ID — used for retroactive attribution |
| `items[]` | array | YES | Order line items |
| `items[].sku` | string | YES | |
| `items[].qty` | integer | YES | Quantity |
| `items[].unit_price` | number | YES | Per-unit price |
| `items[].line_total` | number | YES | Line total (qty × unit_price − discount) |
| `items[].discount_amount` | number | NO | Line-specific discount |

**Attribution flow** (engine-side):

For each order, the engine performs attribution matching as follows:
1. If `smaily_rec_id` is present → look up `recommendations` table, verify customer match → INSERT `rec_attribution` with `attribution_type='direct'`, `'exact_later'`, or `'indirect_*'` depending on SKU match and time gap
2. Else if `smaily_visitor_token` is present → look up `visitor_tokens` → find recent recommendations → match
3. Else if `session_id` is present → check `browse_events` within the last 7 days for a rec_id link → match
4. If no match → INSERT `rec_attribution` with `attribution_type='control_purchase'`, `outcome_score=0.0`

Detailed attribution logic lives in the `lib/engine/attribution/` module (specified in the Faas 2.5 brief).

**Response 200 OK**:
```json
{
  "ok": true,
  "processed": 10,
  "created": 7,
  "updated": 3,
  "skipped": 0,
  "attribution_resolved": 6,
  "attribution_control": 4,
  "errors": [],
  "request_id": "req_..."
}
```

`attribution_resolved` = orders where the engine matched a recommendation.
`attribution_control` = orders assigned to the control group (no engine influence).

**Response 200 OK (deduplicated retry)**: integer-count shape, as in catalog. A duplicate `event_id` is counted in `deduplicated`; a whole-request no-op returns `{"ok":true,"processed":0,"deduplicated":1,"errors":[],"deduplicated_all":true}`.

**Idempotency**: two layers, as described in [Idempotency](#idempotency):
- **Layer 1**: `(tenant_id, external_order_id)` natural-key UPSERT (items are fully replaced on update).
- **Layer 2**: optional per-item `event_id` for retry deduplication (integer counts).

---

### 6. POST /api/v1/ingest/browse

Browse events batch. The highest-volume endpoint.

**URL**: `POST /api/v1/ingest/browse`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 500 req/sec (higher than other endpoints), up to 100 events per request

**Event types**:
- `product_view` — customer opened a product page
- `category_view` — customer opened a category page
- `search` — customer searched
- `cart_add` — customer added to cart
- `cart_remove` — customer removed from cart
- `checkout_start` — customer began checkout
- `checkout_complete` — checkout completed (order created) — **in addition to the orders endpoint**
- `wishlist_add` — customer added to wishlist (if the platform supports it)

**Request body**:
```json
{
  "events": [
    {
      "event_id": "evt_7d8f3a2b-4e1c-...",
      "session_id": "wp_sess_abc123",
      "event_type": "product_view",
      "sku": "ACA-DOG-3KG",
      "category_path": "food/dry",
      "dwell_seconds": 45,
      "event_ts": "2026-05-19T10:15:23Z",
      "source": "plugin_woo",
      
      "customer_email": "mari@example.com",
      "smaily_visitor_token": "vt_8f3k2a",
      "smaily_rec_id": "rec_abc123",
      "smaily_ctx": "cart_abandoned",
      "external_id": "67"
    }
  ]
}
```

**Field reference**:

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `event_id` | UUID v4 string | YES | Plugin-generated, transport-level dedup key. Required for browse (no natural key). |
| `session_id` | string | YES | Plugin manages the session cookie (`smaily_anon_sid`) |
| `event_type` | enum | YES | See event types list |
| `sku` | string | NO | Required for `product_view`, `cart_add`, `cart_remove` |
| `category_path` | string | NO | Required for `category_view` |
| `search_query` | string | NO | Required for `search` |
| `dwell_seconds` | integer | NO | Time on page (for `product_view`) |
| `event_ts` | ISO 8601 | YES | Event occurrence timestamp |
| `source` | string | YES | Constant: `plugin_woo`, `plugin_shopify`, `make`, `custom` |
| `customer_email` | string | NO | Identity hint (if user is logged in) |
| `smaily_visitor_token` | string | NO | Identity hint (from cookie) |
| `smaily_rec_id` | string | NO | Attribution (from cookie) |
| `smaily_ctx` | string | NO | Attribution (from cookie) |
| `external_id` | string | NO | Platform user_id |

**Identity resolution flow** (engine-side):

For each event, the engine resolves `customer_id` as follows:
1. If `smaily_visitor_token` is present → look up `visitor_tokens` → resolve customer_id
2. Else if `customer_email` is present → look up `customers` by email → resolve customer_id
3. Else if `external_id` is present → look up `customers` by external_id → resolve customer_id
4. Otherwise → INSERT browse_event with `customer_id = NULL` (anonymous)

**Retroactive binding**: when `customer_id` is resolved (steps 1–3), the engine UPDATEs all earlier `browse_events` with the same `session_id` where `customer_id IS NULL`. The customer gets full session history even if their first click was anonymous.

**Response 200 OK**:
```json
{
  "ok": true,
  "processed": 23,
  "with_customer_match": 18,
  "anonymous": 5,
  "retroactive_bound": 3,
  "duplicates_skipped": 0,
  "errors": [],
  "request_id": "req_..."
}
```

`duplicates_skipped` = `event_id` values already present in `ingest_event_log` (idempotency).
`retroactive_bound` = anonymous events bound to a customer_id retroactively.

**Response 200 OK (deduplicated retry, all events)**:
```json
{
  "ok": true,
  "processed": 0,
  "deduplicated": 5,
  "errors": [],
  "with_customer_match": 0,
  "anonymous": 0,
  "retroactive_bound": 0,
  "duplicates_skipped": 5,
  "deduplicated_all": true
}
```

Returned when every event in the request was already processed (matching `event_id` already in `ingest_event_log`). `deduplicated` is the integer count of skipped events (and `duplicates_skipped` is a backward-compatible alias of the same count). Because browse has no Layer-1 fallback, per-item `event_id` is what prevents a retry from duplicating events — each event deduplicates independently (a 5-event retry yields `deduplicated: 5`, not a single whole-request hit).

**Idempotency**: `event_id` is **required** for browse (unlike other ingest endpoints where it's optional). Browse events have no natural-key UPSERT to fall back on — the same customer may legitimately view the same SKU repeatedly. The `event_id` is the only protection against retry duplicates. Storage: `(tenant_id, event_id)` in `ingest_event_log`, 90-day permanent retention.

**Browse events are NOT semantically idempotent** — the same customer may view the same SKU three times in a day, producing three legitimate events. The `event_id` layer protects against transport-level retries (network errors, queue replays), not against legitimate repeated activity.

---

### 7. POST /api/v1/identity/merge

Anonymous visitor → known customer manual merge. The plugin calls this when a customer logs in and we have cookies tied to an anonymous session that we now want to bind to the customer.

**URL**: `POST /api/v1/identity/merge`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec

**Use case**:
1. Mari arrives at the store via a `?smaily_vt=vt_xyz` link → plugin sets the cookie, starts an anonymous session
2. Mari browses a few pages; the plugin sends browse events with `smaily_visitor_token` — the engine resolves customer_id (Mari) → events are linked to Mari
3. **However, when** the plugin sees Mari is **now logged in** (`is_user_logged_in()` returns true) — but `customer_email` wasn't previously known — the plugin calls `identity/merge` to explicitly preserve the **session-anon-history → known-customer** mapping.

**Request body**:
```json
{
  "anon_session_id": "anon_sess_uuid_v4_from_cookie",
  "smaily_visitor_token": "vt_8f3k2a",
  "customer_email": "mari@example.com",
  "customer_external_id": "67",
  "merge_ts": "2026-05-19T10:15:23Z",
  "merge_reason": "user_logged_in"
}
```

**Field reference**:

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `anon_session_id` | UUID | NO | Plugin-side anon-session cookie (`smaily_anon_sid`) |
| `smaily_visitor_token` | string | NO | Token from email click (`smaily_rec_uid` cookie) |
| `customer_email` | string | YES | Known customer identity |
| `customer_external_id` | string | NO | Platform user_id |
| `merge_ts` | ISO 8601 | YES | Merge timestamp |
| `merge_reason` | enum | NO | `user_logged_in`, `email_provided_at_checkout`, `manual_admin` |

At least **one** of `anon_session_id` or `smaily_visitor_token` must be present (otherwise there's nothing to merge).

**Response 200 OK**:
```json
{
  "ok": true,
  "customer_id": "550e8400-...",
  "merged": {
    "browse_events_updated": 12,
    "browse_events_already_bound": 3,
    "visitor_tokens_bound": 1,
    "session_history_days": 22
  },
  "request_id": "req_..."
}
```

`browse_events_updated` = anonymous events bound to customer_id.
`session_history_days` = how many days the bound events reach back (gives a sense of how much history the customer recovered).

**Response 404 Not Found**:
```json
{
  "error": "customer_not_found",
  "message": "No customer found with email mari@example.com. Send via POST /api/v1/ingest/customers first.",
  "request_id": "req_..."
}
```

**Idempotency**: same merge twice = no-op (events already bound; response shows `browse_events_already_bound`).

---

### 8. GET /api/v1/customer/{email}/export

GDPR data export. Returns all engine-held data for the customer.

**URL**: `GET /api/v1/customer/{email}/export`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 10 req/60 seconds per tenant (privacy-relevant endpoint)

**URL parameters**:
- `{email}` — URL-encoded email address

**Query parameters**:
- `format` — optional, `json` (default) or `ndjson` (for large datasets)
- `since` — optional, ISO 8601 — filter events from this timestamp forward

**Response 200 OK** (`format=json`):
```json
{
  "export_metadata": {
    "exported_at": "2026-05-19T10:15:23Z",
    "tenant_id": "550e8400-...",
    "customer_email": "mari@example.com",
    "customer_id": "660f9500-...",
    "data_retention_policy": {
      "browse_events": "90 days",
      "email_events": "365 days",
      "recommendations": "730 days",
      "rec_attribution": "730 days",
      "orders": "indefinite"
    }
  },
  "customer": {
    "email": "mari@example.com",
    "first_name": "Mari",
    "last_name": "Tamm",
    "country": "EE",
    "language": "et",
    "first_seen_at": "2026-01-15T10:30:00Z",
    "cold_start_tier": 3,
    "engagement_state": "warm",
    "inferred_species": "dog",
    "consent": {
      "marketing": true,
      "recommendations": true,
      "consent_at": "2026-01-15T10:30:00Z"
    }
  },
  "orders": [
    {
      "external_order_id": "WC-12345",
      "ordered_at": "2026-05-15T14:30:00Z",
      "total_amount": 67.50,
      "items": []
    }
  ],
  "browse_events": [
    {
      "event_ts": "2026-05-19T10:15:23Z",
      "event_type": "product_view",
      "sku": "ACA-DOG-3KG",
      "dwell_seconds": 45
    }
  ],
  "email_events": [
    {
      "event_ts": "2026-05-18T09:00:00Z",
      "event_type": "click",
      "campaign_id": "welcome_series",
      "rec_id": "rec_abc123"
    }
  ],
  "recommendations": [
    {
      "rec_id": "rec_abc123",
      "issued_at": "2026-05-17T05:00:00Z",
      "sku": "ACA-DOG-3KG",
      "intent_type": "complement",
      "status": "closed_win",
      "outcome_score": 1.0
    }
  ],
  "rec_attribution": [
    {
      "rec_id": "rec_abc123",
      "order_id": "WC-12345",
      "attribution_type": "direct",
      "outcome_score": 1.0,
      "click_ts": "2026-05-18T09:05:00Z",
      "purchase_ts": "2026-05-18T09:12:00Z"
    }
  ]
}
```

**Response 404 Not Found** if the customer doesn't exist.

**Response 200 OK with empty data** (customer exists but has no data yet):
```json
{
  "export_metadata": { },
  "customer": { },
  "orders": [],
  "browse_events": [],
  "email_events": [],
  "recommendations": [],
  "rec_attribution": []
}
```

**Idempotency**: read-only; all exports are identical (same data state).

**Audit log**: every export is logged to the `gdpr_audit_log` table — tenant_id, customer_email, action=`'export'`, performed_at, source (`'plugin'` or `'admin_ui'`).

---

### 9. DELETE /api/v1/customer/{email}

GDPR full data deletion.

**URL**: `DELETE /api/v1/customer/{email}`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 10 req/60 seconds per tenant

**URL parameters**:
- `{email}` — URL-encoded email address

**Request body** (optional confirmation):
```json
{
  "confirm": true,
  "reason": "user_request"
}
```

`reason` may be: `user_request`, `admin_action`, `legal_obligation`, `account_closure`.

**Response 200 OK**:
```json
{
  "ok": true,
  "deleted": true,
  "customer_email": "mari@example.com",
  "records_removed": {
    "customer": 1,
    "orders": 5,
    "order_items": 18,
    "browse_events": 247,
    "email_events": 89,
    "recommendations": 145,
    "rec_attribution": 67,
    "visitor_tokens": 3,
    "trigger_candidates": 4
  },
  "audit_log_id": "audit_uuid_...",
  "deleted_at": "2026-05-19T10:15:23Z",
  "request_id": "req_..."
}
```

`audit_log_id` references the `gdpr_audit_log` row that records the deletion fact (not its content) — **required for GDPR compliance as proof**.

**What persists after DELETE**:
- The `gdpr_audit_log` row (date + email + action; no content)
- Aggregated metrics in `lift_metrics_daily` (anonymized; contains no email/customer_id)

**What is deleted**:
- All tables referencing customer_id (ON DELETE CASCADE)
- `visitor_tokens` (so future email clicks won't re-identify Mari)

**Response 404 Not Found** if the customer doesn't exist.

**Idempotency**: same DELETE twice = the second call returns 404 (customer already deleted). The plugin should treat 404 as a successful operation.

---

### 10. POST /api/v1/customer/{email}/opt-out

GDPR opt-out: data is retained, but the customer is excluded from future recommendations.

**URL**: `POST /api/v1/customer/{email}/opt-out`

**Auth**: `Authorization: Bearer sk_...`

**Rate limit**: 100 req/sec

**Use case**: the customer clicked an "Exclude me from the recommendation system" link in a contact form. The plugin calls this endpoint. The engine flags the customer `opted_out=true` — every future `issue-daily-recommendations` cron run skips this customer.

**URL parameters**:
- `{email}` — URL-encoded email

**Request body**:
```json
{
  "opt_out": true,
  "reason": "user_preference",
  "opted_out_at": "2026-05-19T10:15:23Z"
}
```

**Reverse** (opt back in):
```json
{
  "opt_out": false,
  "reason": "user_preference"
}
```

**Response 200 OK**:
```json
{
  "ok": true,
  "customer_email": "mari@example.com",
  "opt_out_status": true,
  "previous_status": false,
  "effective_at": "2026-05-19T10:15:23Z",
  "next_recommendations_cron": "2026-05-20T05:00:00Z",
  "request_id": "req_..."
}
```

`next_recommendations_cron` tells when the next cron run will skip the customer (useful for audit).

**Response 404 Not Found** if the customer doesn't exist.

**Idempotency**: same opt-out twice = second call returns `previous_status: true, opt_out_status: true` (no-op but successful).

**Audit log**: logged to `gdpr_audit_log` (action=`'opt_out'` or `'opt_in'`).

---

## Appendices

### Appendix A: Pagination (future)

v1.0 does **not** support pagination — all batch endpoints are **single-request, max 100 (50 for orders) per call**. The plugin splits larger batches itself.

v2.0 plans cursor-based pagination for GET endpoints (e.g. `GET /api/v1/customers?cursor=...` for admin UI).

### Appendix B: Webhooks (future)

v1.0 does **not** support webhook back-channel. The plugin polls for data.

v2.0 plans registrable webhooks:
```
POST /api/v1/webhooks
{ "url": "https://shop.com/webhook", "events": ["sync.completed", "recs.updated"] }
```

JSON schema (reserved for v2):
```json
{
  "webhook_id": "wh_uuid",
  "event_type": "sync.completed",
  "tenant_id": "tnt_uuid",
  "occurred_at": "2026-05-19T10:15:23Z",
  "data": { }
}
```

HMAC signature in the `X-Smaily-Signature` header.

### Appendix C: Multi-tenant single-WP (future)

v1.0 = **1 plugin = 1 tenant**. The plugin's WordPress options table stores only one `api_key` + `tenant_id`.

v2.0 plans multi-tenant single-WP (agencies + WPMU + WC Multistore). The API design (setup-token + tenant-bound config) already supports this.

### Appendix D: Curl examples

**Test connection**:
```bash
curl -X GET https://re-erkkimarkus-projects.vercel.app/api/v1/ingest/ping \
  -H "Authorization: Bearer sk_..."
```

**Catalog upload**:
```bash
curl -X POST https://re-erkkimarkus-projects.vercel.app/api/v1/ingest/catalog \
  -H "Authorization: Bearer sk_..." \
  -H "Content-Type: application/json" \
  -d '{
    "products": [
      {
        "event_id": "evt_b3f1a2c4-4e1c-4d8a-9b2f-7e3d1a6c8b9e",
        "sku": "TEST-001",
        "name": "Test Product",
        "category_path": "test",
        "price": 9.99,
        "in_stock": true,
        "product_url": "https://example.com/test"
      }
    ]
  }'
```

**Browse event**:
```bash
curl -X POST https://re-erkkimarkus-projects.vercel.app/api/v1/ingest/browse \
  -H "Authorization: Bearer sk_..." \
  -H "Content-Type: application/json" \
  -d '{
    "events": [
      {
        "event_id": "evt_7d8f3a2b-4e1c-4d8a-9b2f-7e3d1a6c8b9e",
        "session_id": "test_session_001",
        "event_type": "product_view",
        "sku": "TEST-001",
        "event_ts": "2026-05-19T10:15:23Z",
        "source": "plugin_woo"
      }
    ]
  }'
```

### Appendix E: Changelog

**v1.0.0** (2026-05-19) — Initial stable release.
- 10 endpoints documented
- URL namespace + cookie names finalized
- Plugin-agent feedback integrated (`event_id` idempotency, `compare_price`, multilingual, GDPR endpoint set, `smaily_rec` parameter, rate limit + `Retry-After`)
- Attribution flow documented (within `/api/v1/ingest/orders`)
- Retroactive session binding documented (within `/api/v1/ingest/browse`)
- GDPR endpoint set (export, delete, opt-out)

**v1.0.0 — 2026-05-22 documentation update** (no breaking changes):
- Translated to English
- Added document-sync header note (this contract lives in two repos)
- Added §7 [Idempotency](#idempotency) covering both natural-key UPSERT (Layer 1) and `event_id` deduplication (Layer 2)
- Extended `event_id` documentation: catalog/customers/orders endpoints now show it explicitly (optional); browse continues to require it
- Corrected dedup window: 90-day permanent (via `ingest_event_log` table), not 60-minute (the earlier draft pre-dated the migration 0025 implementation)
- Added 11th endpoint key to setup-response example: `recommendations_preview`, `recommendations_issue` (previously omitted from the map — the endpoints existed but weren't surfaced)
- Renamed setup-response endpoint map keys to use `ingest_*` / `customer_*` / `recommendations_*` prefixes consistently (e.g. `catalog` → `ingest_catalog`) — the plugin can now read `endpoints[ingest_catalog]` rather than constructing paths
- Added note on engine version consistency (single ENGINE_VERSION env var feeds both HTTP header and Smaily contact-sync payload)
- Added note on consent (Smaily is source of truth; engine accepts but doesn't gate on consent fields)

**v1.0.0 — W1 idempotency contract sync** (no breaking changes for plugin clients; no prior consumers of the removed path):
- **Per-item `event_id` dedup on all four ingest endpoints.** Each `products[]` / `customers[]` / `orders[]` / `events[]` object may carry its own `event_id`; dedup is per item, including intra-batch duplicates (first occurrence `processed`, second `deduplicated`). Engine commit `1c9b4e9`.
- **Integer `processed` / `deduplicated` counts** replace the old boolean `{"deduplicated": true}` retry response, plus an optional `"deduplicated_all": true` flag for a pure no-op retry. §7 and all four endpoints' Layer-2 response examples updated accordingly.
- **Wrapper-level `event_id` removed** (no consumers — plugin ingest had no prior clients; MiuMjau flows through the admin CSV path). A stray top-level `event_id` is now silently ignored (Zod strips it); there is no whole-request boolean short-circuit. Engine commit `01b7950`.
- Added **Implementation status** notes to §4 Customers and §5 Orders: the engine currently accepts single-object payloads; `customers[]` / `orders[]` batch arrays are target state for W4 / W5. (The `products` → `items` catalog wrapper-key correction remains a separate tracked item.)

**v1.0.0 — W2 catalog field expansion** (no breaking changes for the plugin — it already sends the full field set):
- **Catalog ingest expanded to the full §3 field set**, mapped through a shared `ProductSchema` + `toCatalogInsert()` (single source of truth for plugin ingest + admin CSV). Engine commits `b5b1295`, `81b0936`.
- **Wrapper renamed `items[]` → `products[]`** (clean break; an `items[]`-wrapped payload now fails validation). Engine commit `b5b1295`.
- **Multilingual** `name` / `description` / `product_url`: `string | {lang: string}`; string wrapped as `{default}`, object stored in the `*_i18n` JSONB column + a representative plain scalar; `description` truncated to 500/language.
- **`external_id` + `raw_attributes` columns added** (migration `0026`, additive/nullable). `raw_attributes` is raw-store only (the AI wizard / `unmapped_attributes` is not implemented).
- **`compare_price` / `on_sale_until` accepted and stored** (store-only; sale-display math is W3).
- **`product_url` (required + non-empty) and `in_stock` (required) tightened** to match spec §3, per F3-17 (the plugin always sends both). Empty `product_url` fails loud (400) rather than falling back to `product_base_url + sku`. This brief's commits.
- §3 response example corrected to the real shape `{ok, processed, deduplicated, errors}` (removed `created`/`updated`/`skipped`/`unmapped_attributes`/`request_id`); documented all-or-nothing validation and the scalar-only `image_url` limitation.

---

**End of document**
