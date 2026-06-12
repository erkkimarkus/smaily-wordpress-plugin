# Changelog

All notable changes to the Smaily Connect plugin. The `readme.txt` changelog
carries the same content in WordPress.org format; this file is the fuller
repo-side log.

## 2.1.0-beta.2 — pilot debugging (2026-06-12)

First fixes from real pilot data (DECISIONS F3-36): the pilot store has **no
SKUs at all** and its old orders reference deleted products — both broke
rec-engine sync, one of them silently.

- **SkuResolver — synthetic `wc-{id}` product keys** (`Support/SkuResolver.php`):
  the engine keys catalog, order items, AND browse events on `sku`, but WC
  doesn't require SKUs. One shared resolver now supplies the key on all three
  surfaces: the real SKU when set, else `wc-{product/variation id}`. Before:
  catalog silently dropped SKU-less units *before enqueue* (zero Event Log
  trace — the engine just never saw the store), orders built empty `items[]`
  and were D6-rejected on every retry, browse omitted `sku` and the engine
  rejected `product_view`/`cart_*` events.
- **Deleted-product orders stop failing**: current WC ZEROES the order items'
  product reference on permanent deletion (verified empirically on WC 10.7 —
  the initial assumption that the id survives was wrong; the integration test
  caught it), so those lines are unkeyable. Such orders are now terminal-
  skipped cleanly; on WC data where the reference survives, the line keys
  `wc-{id}` and ingests (unit-covered). Either way: no more red rows.
  (Non-catalog SKUs in orders/browse are engine-accepted — proven by the
  existing green live-walks, which always sent SKUs without catalog rows.)
- **Empty-`items[]` orders are terminal-skipped, not sent**: an order whose
  every line drops (deleted products, fee-only orders) can never pass the
  engine's `items` min-1 — OrderFlusher now skips it (third terminal-skip
  case) instead of send-and-fail-forever flooding the Event Log.
- **Mock hardened in the same pass** (LESSONS §2.3 discipline): the mock
  orders route now enforces `items` min 1 — it was the divergence that kept
  integration green while the live engine D6-failed.
- Catalog live-walk D6 lock-proof lever updated: empty-sku is no longer
  producible through the builder; the proof now uses an over-64-char SKU
  (contract §3 cap).
- **Health-notice placement fixed on the plugin's own pages**: the admin
  wrapper now emits the `wp-header-end` marker, so WP core relocates admin
  notices above the React app (full-width, like every other admin page)
  instead of injecting them inside the React header next to the Settings
  tabs (core's fallback target is the first `h1` in `.wrap` — which was the
  React flex-header's own h1).
- **Abandoned-cart backlog guard + per-cart error handling** (F3-37): the
  legacy reminder pass now (1) only emails carts the customer touched within
  the last 24h (`smaily_connect_abandoned_cart_max_age_seconds` filter) —
  older carts are expired without emailing — and (2) logs-and-continues on a
  per-cart API failure instead of aborting the whole loop unmarked. Before,
  a dormant cron period accumulated an unbounded backlog that the first
  working tick after re-arming would mass-mail. (The pilot's day-1 mass
  email actually came from a third-party cart plugin's identical backlog
  drain when the plugin swap revived the site's dead cron — but our pipeline
  carried the same flaw, enabled, one working autoresponder away from the
  same flood.)

## 2.1.0-beta.1 — in development (feature-complete for pilot 2026-06-09; audited + hardened 2026-06-11)

> Version note: this release was developed as 2.0.0-beta.1 and renumbered to
> 2.1.0-beta.1 on 2026-06-12 — upstream (sendsmaily) released its own,
> unrelated 2.0.0 on wordpress.org (a 1.x-line WordPress-7.0 compatibility
> bump), and a lower-versioned fork install would have been offered (or
> auto-updated into) that package. The plugin also now declares an
> `Update URI` so wordpress.org updates never apply to the fork. See
> DECISIONS F3-35.

A major release built **alongside** the 1.x feature set, not replacing it:
existing installs upgrade in place and legacy behaviour continues until the new
setup wizard is completed.

### Added
- **Setup wizard** — guided 6-step first-run flow (Smaily credentials → subscriber
  sync → WooCommerce automations → recommendations → integrations → done) with a
  redesigned, mobile-first React admin.
- **Recommendation-engine integration** (optional, token-connected): durable,
  idempotent ingest of the product catalog, customers and orders (batched, with
  per-item engine error handling); one-click backfill of existing store data
  (cursor-resumable); anonymous-session → known-customer identity merge on login.
- **Browse tracking (opt-in, double-gated)** — a storefront beacon for product
  views / searches / cart and checkout events; off by default, fires only with
  shopper cookie consent (WP Consent API; escape-hatch filter + JS override for
  non-compatible consent plugins).
- **Event Log** (Settings tab) over both durable queues with filters, payload
  drill-down, per-row and bulk **Retry** of failed events.
- **Proactive health notices** — recurring health check raises dismissible admin
  notices for failure spikes, rec-engine downtime, and Smaily API downtime.
- **Privacy**: WordPress personal-data **export/erase** integration covering
  rec-engine data (HPOS-safe); shopper **profiling opt-out** (My Account toggle,
  opt-out model synced with Smaily; opted-out shoppers' browse events are
  dropped and never retroactively bound).
- **Queue janitor** — daily retention prune of terminal queue rows
  (sent 30d / failed 90d, filterable; pending rows never pruned).
- **Multilingual-aware routing** — Polylang / WPML / TranslatePress adapters for
  per-language Smaily accounts and automation workflows.
- **Product RSS feed URL builder** on the Integrations step/tab (wizard and
  Settings) — category / limit / ordering / tax-rate pickers with a live,
  copyable feed URL, prefilled from previously-saved 1.x RSS settings. The
  feed itself is the unchanged 1.x endpoint (all parameters travel in the
  URL), so existing template URLs keep working.
- Estonian translations for all new 2.0 strings.

### Changed
- Background work moved from WP-Cron to **Action Scheduler**.
- Stored API credentials re-encrypted with **AES-256-GCM** (versioned format,
  automatic migration on upgrade; the legacy CBC format remains readable).
- Version floors: WordPress 6.6+ (tested 7.0), WooCommerce 6.9+ (tested 10.7),
  PHP 8.0+.

### Fixed
- All applicable upstream 1.6.2-line fixes are included (empty abandoned carts,
  gender mapping, birthday parse failure, profile-data save, Elementor
  failure_url, checkout-optin asset path).
- A leftover diagnostic that logged the connection payload to `debug.log` on
  settings save was removed.

## 1.6.1

- Fixed discounted-price calculation in the RSS feed when using the tax-rate
  parameter.
- Unified the discount-percentage display in the RSS feed (at most one decimal,
  no trailing zeros).

## 1.6.0

- Added tax-rate support for RSS-feed product prices.
- Elementor widget: custom hidden fields on the subscription form.

## 1.5.1

- Added a label to the hidden-fields section of the subscription block settings.

## 1.5.0

- Customizable hidden fields on the Smaily subscription block form.

## 1.4.3

- Abandoned-cart records are deleted on more hooks, so completed purchases don't
  leave records lingering.

## 1.4.2

- Fixed Elementor integration assets being excluded from the plugin package.

## 1.4.1

- Abandoned-cart cutoff minimum corrected from 30 to 10 minutes.

## 1.4.0

- RSS-feed items show prices including taxes; Discount Rules for WooCommerce
  supported in the feed and abandoned-cart reminders.

## 1.3.3

- Elementor widget performance: fewer API calls during render.

## 1.3.2

- RSS-feed `pubDate` formatted per RFC 822.

## 1.3.1

- Invalid-credentials admin notice rendered closer to the input fields;
  autoresponder listing validation hardened.

## 1.3.0

- Contact Form 7 integration configurable per form.

## 1.2.4

- Admin notices rendered outside the form element.

## 1.2.3

- Text domain loaded in `init` (WordPress 6.7+ standard).

## 1.2.2

- RSS-feed product query: random ordering removed (empty-feed bug).

## 1.2.1

- Fixed abandoned-cart reminder emails not sending (query syntax error).

## 1.2.0

- New block for embedding Smaily Landing Pages.

## 1.1.0

- New Elementor widget for Smaily subscription forms.

## 1.0.0

- Combined Smaily for Contact Form 7, Smaily for WP, and Smaily for WooCommerce
  into a single plugin.
