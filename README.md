# Smaily Connect

A WordPress plugin that integrates WooCommerce with [Smaily](https://smaily.com), an email marketing platform. Sends customer and order data to Smaily for transactional and marketing automation, and connects WooCommerce stores to Smaily's recommendation engine for personalized product suggestions in email campaigns.

**Status:** 2.0.0-beta.1 — under active development. See [Roadmap](#roadmap) for what's complete and what's in progress.

This repository is a fork of [`sendsmaily/smaily-wordpress-plugin`](https://github.com/sendsmaily/smaily-wordpress-plugin). It carries the legacy 1.x code alongside a new 2.0 codebase that coexists with it during the transition. See [Relationship to upstream](#relationship-to-upstream) for details.

---

## What's new in 2.0

The 2.0 rewrite (built alongside the legacy 1.x code, not replacing it) introduces:

- **Modern admin UI** — React + Tailwind, mobile-first, replaces the legacy WordPress admin pages
- **Setup wizard** — guided first-run experience for connecting the plugin to a Smaily account
- **Recommendation engine integration** — connects to Smaily's recommendation engine for product suggestions in email campaigns, with full attribution flow (`product_url` + UTM + recommendation tokens)
- **Action Scheduler** for background work — replaces WP-Cron for reliability under load
- **Idempotent data ingestion** — products, customers, and orders are sent with per-record `event_id`s so retries never duplicate
- **Comprehensive WooCommerce coverage** — product changes, customer registrations, order events, browse activity (in progress per the Roadmap below)
- **Backward compatibility with 1.x** — existing 1.x installs upgrade in place; legacy subscriber syncing continues until the new setup wizard is completed

The 2.0 design treats the plugin as a serious sync layer between WooCommerce and Smaily, not a thin contact form. See [`docs/DECISIONS_DRAFT.md`](docs/DECISIONS_DRAFT.md) for the architectural choices and their rationales.

---

## Roadmap

The plugin is being built in phases. Each phase is a coherent slice of functionality, gated on real-environment testing before being marked complete.

### Phase 1 — Coexistence & activation ✓

Legacy 1.x code preserved; new 2.0 code activates alongside it. Existing 1.x installs continue working without interruption. Database migrations, plugin activation hooks, and the upgrade path are in place.

### Phase 2 — Wizard, settings, and Smaily-side infrastructure ✓

Setup wizard for first-run connection. New mobile-first admin UI. Subscriber sync, abandoned cart, welcome series, and first-order triggers configurable through the new UI. Payload builders and the Smaily API client are in place.

### Phase 3 — Recommendation engine integration (in progress)

The plugin connects to Smaily's recommendation engine and feeds it product, customer, order, and browse data so the engine can produce personalized recommendations in email campaigns.

| Sub-area | Status | Notes |
|----------|--------|-------|
| Setup-exchange (engine connection) | ✓ Complete | Live-verified |
| Catalog ingest (products → engine) | ✓ Complete | Live-verified against deployed engine, 14/14 scenarios |
| Customers ingest | ✓ Complete | Live-verified 10/10 against deployed engine (customers-end) |
| Orders ingest | ✓ Complete | Live-verified 12/12 against deployed engine (orders-end) |
| Browse ingest | ✓ Complete | `ingest_browse` + the public `/beacon` proxy; live-walked 13/13 (browse-beacon 3.4.0) |
| Backfill (historical data) | ✓ Complete | Cursor-paginated catalog/customers/orders backfill; live-walked 7/7 (3.5) |
| Beacon (browse tracking) | ✓ Complete | Client-side beacon (buffer/sendBeacon, cookies, WP-Consent-API gate, WC events) — shipped within browse-beacon 3.4.1–3.4.3 |
| Identity merge | ⏳ Pending | Anonymous-to-known visitor merging on checkout, login, or manual mapping |
| GDPR | ⏳ Pending | WP Privacy API integration for export and erase requests |
| Step 4 4a activation | ⏳ Pending | Final UI shift from mode-A to mode-B |

### Phase 4 — Pilot stabilization (planned)

After Phase 3 completes, the plugin enters a pilot stabilization period before general availability.

---

## Requirements

| Component | Minimum | Tested up to |
|-----------|---------|--------------|
| WordPress | 6.6 | 7.0 |
| WooCommerce | 10.0 | 10.7 |
| PHP | 8.0 | 8.3 |
| Smaily account | Active | — |

The plugin uses Action Scheduler (bundled with WooCommerce) for background work and requires HTTPS for Smaily API connections.

---

## Installation

### Fresh install

1. Download the latest release ZIP from the [Releases](../../releases) page
2. In WordPress admin, go to **Plugins → Add New Plugin → Upload Plugin**
3. Choose the ZIP file and click **Install Now**
4. Click **Activate**
5. Open **Smaily Connect** in the admin sidebar to start the setup wizard

### Upgrading from legacy 1.x

If you have the legacy `sendsmaily/smaily-wordpress-plugin` (1.x) already installed:

**Read [`docs/MIGRATION.md`](docs/MIGRATION.md) fully before upgrading.** It covers three upgrade paths, prerequisites, the first-hour verification protocol, the legacy credential guard, and rollback. The migration is designed to be safe and reversible, but a few specifics are important to understand in advance.

---

## Architecture

The plugin has three main layers:

1. **WordPress integration layer** — hooks into WooCommerce events (product changes, customer registrations, orders), exposes admin pages, manages settings, runs background jobs via Action Scheduler.
2. **Domain layer** — payload builders translate WordPress/WooCommerce data into the canonical wire format that Smaily and the recommendation engine expect.
3. **Transport layer** — HTTP clients send payloads to Smaily's marketing API and the recommendation engine, with idempotent retry, queue-backed durability, and exponential backoff.

The 2.0 code lives under the `Smaily\Connect` namespace. The legacy 1.x code lives under `Smaily_Connect` and continues to function until the new setup wizard is completed.

See [`docs/DECISIONS_DRAFT.md`](docs/DECISIONS_DRAFT.md) for the major architectural decisions, including the coexistence strategy (F1-1), the wizard-first activation model (F2-x), the variant A idempotency model (F3-7), and the catalog-end milestone defining the canonical pattern for ingest endpoints (F3-16).

---

## Documentation

All project documentation lives in [`docs/`](docs/). Start with the index.

| Document | Audience | Purpose |
|----------|----------|---------|
| [`docs/INDEX.md`](docs/INDEX.md) | All | Catalog of every document in the project |
| [`docs/MIGRATION.md`](docs/MIGRATION.md) | Pilot clients upgrading from 1.x | Step-by-step upgrade procedure |
| [`docs/DECISIONS_DRAFT.md`](docs/DECISIONS_DRAFT.md) | Maintainers, contributors | Every significant architectural decision with rationale |
| [`docs/LESSONS.md`](docs/LESSONS.md) | Maintainers | Lessons learned during development, especially around boundaries and testing discipline |
| [`docs/RECENGINE_API_CONTRACT.md`](docs/RECENGINE_API_CONTRACT.md) | Plugin + engine developers | The canonical contract between the plugin and the recommendation engine |
| [`docs/WP7_COMPAT.md`](docs/WP7_COMPAT.md) | Maintainers | WordPress 7.0 compatibility notes and the Abilities API strategy |

---

## Development

### Setup

```bash
git clone https://github.com/erkkimarkus/smaily-wordpress-plugin.git
cd smaily-wordpress-plugin
composer install
npm install
```

### Local environment

The plugin is developed and tested against `wp-env` (WordPress + WooCommerce in a Docker container):

```bash
npx wp-env start
```

Once running, WordPress is available at `http://localhost:8888`. See `.wp-env.json` for the exact WP and WC versions used.

### Running tests

```bash
npm run ci:strict              # Full pre-push gate — PHPCS, PHPStan, PHP unit, JS lint, typecheck, JS unit (one command)
composer run test:integration  # Integration tests (real WP + WC via wp-env)
```

The integration test suite covers the boundary cases where unit tests can mislead — see `docs/LESSONS.md` §1 and §2 for why this matters.

### Live engine testing

The catalog end-to-end scenarios can be driven against a live deployed recommendation engine (rather than the in-repo mock). This is gated on `RECENGINE_LIVE=1` and requires a connected tenant in the wp-env database (run a real setup-exchange first):

```bash
RECENGINE_LIVE=1 node bin/walk-3.2.cjs
```

`bin/walk-3.2.cjs` is the live-walk script that drives the catalog-end end-to-end scenarios; without `RECENGINE_LIVE=1` it skips cleanly.

### Browse-beacon consent integration

The storefront browse beacon never sends an event (and never sets a tracking cookie) without marketing consent. Consent is resolved in this order:

1. A site-provided JS override, if present.
2. The **WP Consent API** (`window.wp_has_consent('marketing')`) — implemented by CookieYes, Complianz, Real Cookie Banner, and others. This is the supported path and needs no configuration.
3. Fail-safe **deny** — no consent signal means no tracking.

If your consent plugin is **not** WP-Consent-API-compatible (e.g. Cookiebot, or a custom solution), adapt it through the escape-hatch — no plugin code changes needed:

**Change the consent category** (PHP filter), if your setup categorises tracking under something other than `marketing`:

```php
add_filter( 'smaily_connect_beacon_consent_category', fn() => 'statistics' );
```

**Provide a custom consent check** (JS override) — point it at your plugin's own consent state. Define it before the beacon script runs (e.g. via `wp_add_inline_script` on a higher-priority handle, or a theme header script):

```js
window.smailyConnectBeacon = window.smailyConnectBeacon || {};
window.smailyConnectBeacon.consentOverride = function () {
  // Return true only when the visitor has granted marketing/tracking consent.
  // Example for Cookiebot:
  return !!(window.Cookiebot && window.Cookiebot.consent && window.Cookiebot.consent.marketing);
};
```

The override takes precedence over the WP Consent API. A future release may add a no-code consent-bridge UI for common non-compatible plugins; until then this escape-hatch covers them.

---

## Relationship to upstream

This repository is a fork of [`sendsmaily/smaily-wordpress-plugin`](https://github.com/sendsmaily/smaily-wordpress-plugin), the official Smaily-maintained plugin.

**What the fork adds:**

- A new `Smaily\Connect` namespace with the 2.0 codebase
- The setup wizard, mobile-first admin UI, recommendation engine integration, and the design described in the Roadmap above
- Comprehensive documentation in `docs/`

**What the fork preserves:**

- The full legacy `Smaily_Connect` code, so existing 1.x installs continue working
- Compatibility with all legacy settings, options, and database tables

**Upstream commits relevant to the fork** are tracked in [`docs/UPSTREAM_AUDIT.md`](docs/UPSTREAM_AUDIT.md). Security and bug fixes from upstream are evaluated against the 2.0 codebase and brought over when they apply; refactors and stylistic changes are not.

---

## License

This plugin inherits the license of its upstream. See `LICENSE` for the full text.

---

## Contributing

The plugin is in active development and not yet open to external contributions. Once the Roadmap above is complete and the plugin reaches general availability, contribution guidelines will be added.

For issues, questions, or feedback during the beta period, please reach out through the channel provided with your pilot access.

---

## Acknowledgments

Built in collaboration with the Smaily recommendation engine team. The architectural discipline reflected in this codebase — mandatory live testing before ZIP, mock-vs-real verification, contract sync between plugin and engine repos, decision documents capturing reasoning — comes from the lessons documented in `docs/LESSONS.md` over the course of Phase 2 and Phase 3 development.
