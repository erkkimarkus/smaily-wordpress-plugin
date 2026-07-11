# Plugin API surfaces

The plugin's OWN integration surfaces: REST routes, WordPress hooks/filters it
exposes, JS globals, and CLI tooling. For the **outbound** API the plugin
speaks to the rec-engine (ingest wire shapes, D6 error contract, endpoints
map, cookie names), the single source of truth is
[`RECENGINE_API_CONTRACT.md`](RECENGINE_API_CONTRACT.md) — nothing from it is
restated here.

Audience: anyone extending the plugin or reviewing it. Everything below is
derived from the code as of this doc's last update; when in doubt the code
wins (`includes/REST/EndpointRegistry.php` is the route registry).

---

## 1. REST routes — namespace `smaily-connect/v1`

Registered from `REST\EndpointRegistry::endpoints()` on `rest_api_init`.
**Auth for all admin routes:** WP cookie auth + `wp_rest` nonce +
`manage_options` capability (`Constants::CAPABILITY`). These are internal
endpoints for the plugin's own React admin app — request/response shapes are
defined by the endpoint classes in `includes/REST/` and consumed by
`admin/src/api/*.ts`; they are not a stability-guaranteed public API.

| Method | Path | Endpoint class | Purpose |
|---|---|---|---|
| POST | `/test-smaily` | `TestConnectionEndpoint` | Validate Smaily API credentials (wizard Step 1) |
| POST | `/backfill/start` | `BackfillEndpoint` | Start a backfill (`job_type`: `contacts`, `products`, `customers`, `orders`) |
| GET | `/backfill/status` | `BackfillEndpoint` | Progress: users walked, contacts synced, ETA |
| POST | `/backfill/cancel` | `BackfillEndpoint` | Cancel a running backfill |
| GET | `/workflows` | `WorkflowsEndpoint` | List the Smaily account's automation workflows |
| POST | `/settings` | `SettingsEndpoint` | Save a settings tab (per-tab sanitisation) |
| GET | `/events` | `EventsEndpoint` | Event Log listing (both queues) |
| GET | `/events/detail` | `EventsEndpoint` | One row's stored request/response exchange (F3-44) |
| POST | `/events/retry` | `EventsEndpoint` | Re-drive failed queue rows |
| POST | `/rec-engine/setup-exchange` | `RecEngineEndpoint` | Exchange a one-time setup token for a per-connection API key |
| POST | `/rec-engine/ping` | `RecEngineEndpoint` | Verify the stored engine connection |
| POST | `/rec-engine/disconnect` | `RecEngineEndpoint` | Drop the engine connection |
| GET | `/rec-engine/automations/catalog` | `AutomationsEndpoint` | Proxy: engine §11 trigger catalog (no cache — engine is the source of truth) |
| GET | `/rec-engine/automations/config` | `AutomationsEndpoint` | Proxy: engine §12 config |
| PUT | `/rec-engine/automations/config` | `AutomationsEndpoint` | Proxy: engine §13 config PUT (engine 422 passes through verbatim; engine 401 → 502 `api_key_rejected`) |
| POST | `/relay` | `BeaconEndpoint` | **Public** browse-event proxy — see §1.1 |

### 1.1 `POST /relay` — the public browse-beacon proxy

The only route with `permission_callback => __return_true` (anonymous
storefront visitors post browse events). Defense layers, in order:

1. **Hard gate before any work**: 404 unless the engine is connected AND
   browse tracking is enabled (indistinguishable from a route that doesn't
   exist — deliberate, F3-41).
2. **Rate limits** (transient counters, 60 s window): 30 events/session,
   120/IP — filterable (§2); over-limit → 429 `rate_limited`.
3. **Batch cap**: max 100 events per request.
4. **Event-type whitelist** (9 types): `product_view`, `category_view`,
   `search`, `cart_add`, `cart_remove`, `wishlist_add`, `wishlist_remove`,
   `checkout_start`, `checkout_complete`.
5. **Per-event field whitelist** to the contract §6 shape; unknown keys dropped.
6. **Profiling gate**: events from a known contact who opted out of profiling
   are dropped server-side (`Privacy\ProfilingConsent`).

The route is named `/relay` (and the script `sc-runtime.js`) because "beacon"
is on ad-block filter lists — do not rename (F3-41).

### 1.2 Legacy namespace `smaily/v1`

The legacy codebase (`includes/smaily-api.class.php`) registers two
`manage_options`-gated routes still used by legacy admin surfaces:
GET `/smaily/v1/autoresponders`, GET `/smaily/v1/configuration`.

## 2. WordPress filters the plugin exposes

All in the `smaily_connect_*` namespace (locations in parentheses):

| Filter | Default | Purpose |
|---|---|---|
| `smaily_connect_setup_url` | `Constants::SETUP_BASE_URL` | Override the engine setup-exchange base URL (`Constants.php`) |
| `smaily_connect_docs_url` | `https://smaily.com/connect-woo/` | Override the merchant docs URL every UI link resolves through (`Constants::docs_url()`) |
| `smaily_connect_capture_attribution` | `true` | Master switch for the server-side attribution-cookie capture (`LandingCapture`, F3-46; capture additionally requires a connected engine) |
| `smaily_connect_beacon_consent_category` | `'marketing'` | WP-Consent-API category the browse beacon gates on (`StorefrontBeacon`) |
| `smaily_connect_beacon_rate_limit_ip` | `120`/min | `/relay` per-IP rate limit (`BeaconEndpoint`) |
| `smaily_connect_beacon_rate_limit_session` | `30`/min | `/relay` per-session rate limit (`BeaconEndpoint`) |
| `smaily_connect_janitor_sent_retention_days` | see `QueueJanitor` | Retention of `sent` queue rows |
| `smaily_connect_janitor_failed_retention_days` | see `QueueJanitor` | Retention of `failed` queue rows |
| `smaily_connect_failed_notice_threshold` | see `NotificationManager` | Failed-row count that triggers the admin health notice |
| `smaily_connect_abandoned_cart_max_age_seconds` | see legacy `cron.class.php` | Legacy abandoned-cart age window |
| `smaily_connect_account_fields` | — | Legacy: extra fields on the account profile-settings form (`profile-settings.class.php`) |

NB: there is **no** `smaily_connect_beacon_consent` filter — browse consent is
only the WP Consent API signal, the JS `consentOverride` hatch (§4), and the
category filter above (F3-50).

## 3. Action hooks

Public `do_action` surface is minimal: `Bootstrap::on_abandoned_cart_tick()`
fires the legacy hook pair `smaily_connect_cron_abandoned_carts_status` then
`smaily_connect_cron_abandoned_carts_email` (the legacy cart handlers listen
on these). Everything else scheduled is an **Action Scheduler** hook
(`smly_plus_*` / `smly_rec_*`) — internal contracts, listed with cadences in
[`ARCHITECTURE.md` §6](ARCHITECTURE.md#6-background-work--action-scheduler).
Don't attach third-party listeners to the queue-flush hooks; use the Event Log
or the filters above.

## 4. JS globals (storefront)

`StorefrontBeacon::enqueue()` prints the boot object the `sc-runtime.js`
bundle reads:

```js
window.smailyConnectBeacon = {
  config: {
    beaconUrl,                     // rest_url('smaily-connect/v1/relay')
    cookieNames:   { visitor, session, recId, context },  // engine config, §6 defaults
    urlParams:     { visitorToken, recId, context },
    cookieTtlDays: { visitor, recId, context },
    sessionTtlDays,
  },
  context: { pageType, ... },      // 'product'|'category'|'search'|'checkout'|'order-received'|'other'
                                   // (+ sku/categoryPath/searchQuery where the page provides them)
  consent: { category },           // WP-Consent-API category (filterable, §2)
  // Optional, merchant/theme-provided — checked BEFORE fail-closed default,
  // AFTER window.wp_has_consent:
  consentOverride: () => boolean,
};
```

Consent resolution order (`beacon-core.ts`): `window.wp_has_consent(category)`
if the WP Consent API is present → `consentOverride()` if defined → **fail
closed** (no events, no error). Cookie and URL-param names come from the
engine config at connect time — treat the defaults (`smaily_rec_uid`,
`smaily_anon_sid`, `smaily_rec_id`, `smaily_rec_ctx`; `smaily_vt`,
`smaily_rec`, `smaily_ctx`) as fallbacks, not constants.

The React admin bundle additionally expects `window.wp.i18n` (enqueued with a
`wp-i18n` dependency; see [`ARCHITECTURE.md` §9](ARCHITECTURE.md#9-i18n-architecture)).

## 5. CLI / `bin/` tooling (dev-only, not shipped)

| Tool | Purpose |
|---|---|
| `bin/run-integration-tests.sh` | Integration-suite wrapper (= `composer run test:integration`) with the automatic `smly_rec_*` snapshot/restore guard; `--restore-only` restores the dev connection without running |
| `bin/lib-smly-snapshot.sh` / `.cjs` | The shared snapshot/restore guard (bash lib + Node wrapper `guardSmlyRec()` for walk scripts) |
| `bin/restore-smly-rec-options.php` | Container-side secret-safe (STDIN) options restore |
| `bin/walk-*.cjs` | Live-walk scripts per surface (catalog, orders, browse, GDPR, automations, …) — run against the SANDBOX tenant only; see [`DEVELOPER.md`](DEVELOPER.md#live-walks-real-engine) |
| `bin/build-i18n.sh` | The i18n build (TSX transpile + md5-named script-translation JSON) |
| `bin/check-contract-staleness.sh` | CI guard: md5-compares our vendored contract copy against the engine repo |

## 6. Outbound APIs (pointers only)

- **Rec-engine**: everything through `Smaily\RecEngine\Client`, URLs resolved
  from the stored per-connection endpoints map (with `PATH_*` constant
  fallbacks). Wire shapes, D6 error contract, §3b remove, browse §6, identity
  §7, GDPR §8–§10, automations §11–§13: [`RECENGINE_API_CONTRACT.md`](RECENGINE_API_CONTRACT.md).
- **Smaily contact API**: `Smaily\Client` (HTTP Basic, subdomain credentials);
  field naming per [`FIELD_MAPPING.md`](FIELD_MAPPING.md).
