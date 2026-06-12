# Changelog

All notable changes to the Smaily Connect plugin. The `readme.txt` changelog
carries the same content in WordPress.org format; this file is the fuller
repo-side log.

## 2.0.0-beta.1 — in development (feature-complete for pilot 2026-06-09; audited + hardened 2026-06-11)

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
