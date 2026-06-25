# Changelog

All notable changes to the Smaily Connect plugin. The `readme.txt` changelog
carries the same content in WordPress.org format; this file is the fuller
repo-side log.

## 3.0.1 — internationalization + Estonian translation (2026-06-25)

- **i18n (W-7):** the React admin UI (~244 strings / 24 components) is now wrapped
  with a `wp.i18n` shim (`admin/src/lib/i18n.ts`); `wizard.php` enqueues `wp-i18n` +
  `wp_set_script_translations`. Reproducible catalog build via `bin/build-i18n.sh`
  (esbuild-transpiles `.tsx` so make-pot can read it; renames the catalog to WP's
  expected `…-et-464ceaab….json`).
- **l10n:** complete Estonian translation — all 275 previously-untranslated strings
  (admin UI + blocks + PHP), plurals + printf placeholders preserved; Playwright-verified
  full-wizard render.
- **W-5:** the admin-notice dismiss moved from an inline `<script>` to an enqueued
  `admin/js/notice-dismiss.js`.

No functional change. (These are the fork-side upstream-readiness items; the
`Update URI` clobber-guard stays until the actual wordpress.org merge.)

## 3.0.0 — first general-availability release (2026-06-25)

Graduates the `2.1.0-beta` line (beta.1–beta.10) to GA. Existing settings,
credentials and connections are preserved; an in-place update needs no re-import.

- **Sync:** WooCommerce → Smaily Campaign Intelligence — catalog, customers, orders
  and consent-gated browse tracking; backfill of existing data; Event Log with
  per-row retry and the stored request/response (F3-44); GDPR export / erase /
  opt-out; multilingual canonical SKU + `{lang:value}` payloads (CC.1–CC.4);
  order-sync data-loss fixes (F3-42/F3-43); trashed products kept as
  `in_stock=false` (F3-40); browse beacon renamed off ad-block lists (F3-41).
- **Hardening:** WordPress.org Plugin Check pass — ABSPATH guards across the legacy
  layer, `error_log` gated behind `WP_DEBUG` via `Support\DebugLog`, justified
  `phpcs:ignore`s for custom-table queries / internal exceptions; editor blocks
  moved to Block API v3 for the WordPress 7.0 iframe editor; `.zipignore` slimmed.
- The `Update URI` clobber-guard (F3-35) stays until the upstream merge.

## 2.1.0-beta.6 — engine default URL → intelligence.smaily.com (2026-06-16)

The recommendation engine moved to its production domain
**`https://intelligence.smaily.com`** (migrated from the earlier
`*.vercel.app` preview deploys). Updated every **static** reference to the
new host: the `Constants::SETUP_BASE_URL` default used for the first
setup-exchange, the connection-screen setup-URL placeholder, code-comment
examples, the API contract base/setup/`engine_base_url`/curl examples, and the
integration connectivity-test base. No contract, data, field, header
(`X-Engine-Version`), or setup-token-flow change — only the host.

The runtime path is unchanged and self-adapting: the engine returns its live
`engine_base_url` in the setup-exchange response and the plugin extracts the
host from whatever setup URL the merchant pastes, so existing installs keep
working without action. The old `*.vercel.app` alias still resolves and is
still accepted — the new URL is only a default, not a hard requirement.

## 2.1.0-beta.5 — product rename: Smaily Campaign Intelligence (2026-06-15)

Display-name change only — no contract, data, or behaviour change. The
recommendation-engine product is now **Smaily Campaign Intelligence** (short
form **Campaign Intelligence**) across all user-facing plugin surfaces: the
Settings / wizard tab and step, the connection + data-sync screens, the Step-6
summary, admin health notices, GDPR export/erasure data labels, the My Account
profiling section, the plugin header description, README and readme.txt.
Internal identifiers (option keys, REST routes, `recEngine` / `rec_engine`
symbols), the engine API contract, endpoint paths and headers are unchanged.
The Estonian translations (`.po` / `.pot` / `.mo`) were updated to match — the
brand name stays in English. Supersedes the short-lived "Smaily Intelligence
Engine" name (`docs/ENGINE_TEAM_product_name.md`).

## 2.1.0-beta.3 — catalog correctness: multilingual + non-product signal (2026-06-14)

Multilingual catalog correctness (DECISIONS F3-38; sub-PRs CC.1–CC.4). On
WPML/Polylang stores the recommendation catalog previously got **one row per
language translation** — duplicate products the engine can't merge, producing
language-mixed recommendations — and non-products (gift cards, donations,
language-switcher pseudo-products) leaked in. MiuMjau (the pilot) runs WPML +
WooCommerce Multilingual.

- **Translations collapse to one canonical product.** Every translation now maps
  to a single stable key (`wc-{canonical_id}`) across catalog, orders AND
  browse-tracking, so the engine sees one product, learns on one key, and never
  recommends the same product twice in different languages. The collapse happens
  in the catalog enumeration (backfill + live hooks); the synthetic key is
  canonicalized in the shared `SkuResolver`, so all three surfaces stay
  consistent. Variations are linked across languages via WooCommerce
  Multilingual. Deleting one translation re-syncs the canonical product instead
  of removing it while other languages remain (P4).
- **Localized content (model B).** `name` / `description` / `product_url` are
  sent per language as `{lang: value}` (description clamped to 500 chars per
  language); the engine localizes each customer's recommendations to their
  language. Single-language stores are unaffected — sent as plain strings, the
  prior behaviour.
- **Structural signal for non-product exclusion, not a filter.** Each product
  now sends `product_type` (incl. gift-card plugin types), `is_virtual` and
  `is_downloadable`, so the engine reliably classifies and excludes gift
  cards/donations without name-guessing. The plugin deliberately does NOT filter
  products itself — "is this recommendable?" is a business-model decision (a
  digital-goods store sells virtual/downloadable products), so the engine's
  `recommendable` flag owns the exclusion (DECISIONS F3-38).
- Multilingual detection runs through the existing `DetectorFactory` (WPML /
  Polylang / TranslatePress / single-language fallback), so the same wire shape
  is produced regardless of the i18n plugin. Proven against the real engine:
  `bin/walk-cc3-multilingual.cjs` 9/9 (the engine accepts the `{lang:value}`
  object form + the signal fields).

After upgrading on a multilingual store: re-run the catalog import — coordinate
with Smaily, the recommendation engine resets the product graph (catalog +
orders + recommendations + cadence + co-purchase + browse) for a clean canonical
re-sync.

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
- **Variation stock changes now refresh the catalog**: variations fire
  `woocommerce_variation_set_stock_status`, not the parent-product hook —
  only the parent hook was registered, so a variation selling out never
  updated its catalog row's `in_stock` and the engine could keep
  recommending it. The variation hook is now wired to the same handler
  (identical signature); integration-tested through a real WC save.
- **Catalog attributes now wire term LABELS, not term ids** (engine ask
  2026-06-12, their #1 priority): a taxonomy attribute's `get_options()`
  returns term IDS (`pa_kaubamargid: ["398"]`) and a variation's value is
  the term SLUG — both were forwarded as-is, blocking the engine's brand /
  life_stage / pack_size rule derivation. The builder now resolves taxonomy
  options via `wc_get_product_terms(fields=names)` and variation slugs via
  `get_term_by`, with raw-value fallbacks. Custom attributes unchanged.
  NB: deployed stores need a catalog backfill re-run to refresh existing
  engine rows with labels.
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
