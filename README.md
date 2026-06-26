# Smaily Connect

A WordPress plugin that integrates WooCommerce with [Smaily](https://smaily.com), an email marketing platform. It syncs customer and order data to Smaily for transactional and marketing automation, and connects WooCommerce stores to **Smaily Campaign Intelligence** for personalized product recommendations in email campaigns.

**Status: 3.0.0 — first general-availability release.** The plugin is feature-complete and hardened (WordPress.org Plugin Check pass, full unit + real-environment integration coverage). It is distributed here via [GitHub Releases](../../releases); it is versioned 3.x because an unrelated upstream `2.0.0` (a 1.x-line WordPress 7.0 bump) occupies the `smaily-connect` slot on wordpress.org — see [Relationship to upstream](#relationship-to-upstream).

This repository is a fork of [`sendsmaily/smaily-wordpress-plugin`](https://github.com/sendsmaily/smaily-wordpress-plugin) that carries the legacy 1.x code alongside a new 2.x/3.x architecture which coexists with it during the transition.

---

## Features

- **Smaily email sync** — subscriber sync, abandoned-cart and order-based triggers, Contact Form 7 + Elementor signup widgets, a checkout opt-in block, and the product RSS feed for email templates (all carried over from the 1.x line, the RSS URL builder rebuilt into the new UI).
- **Modern admin** — a React + Tailwind, mobile-first setup wizard and Settings UI that replaces the legacy admin pages.
- **Campaign Intelligence integration** — sends product catalog, customers, orders, and consent-gated browse activity to Smaily Campaign Intelligence so it can produce personalized recommendations, with a full attribution flow (`product_url` + UTM + recommendation tokens) and one-click backfill of existing data.
- **Built to be reliable** — Action Scheduler (not WP-Cron) for background work; idempotent ingestion with per-record `event_id`s so retries never duplicate; a durable queue with per-item error handling; an **Event Log** with per-row retry and the stored request/response for each row; proactive admin health notices.
- **Privacy-first** — GDPR export / erase / opt-out via the WP Privacy API; shopper profiling opt-out synced with Smaily; browse tracking is consent-gated (WP Consent API) and off by default.
- **Safe upgrades** — existing 1.x installs upgrade in place; legacy subscriber syncing continues unchanged until the new setup wizard is completed. Credentials are stored with AES-256-GCM.

See [`docs/DECISIONS.md`](docs/DECISIONS.md) for the architecture and the rationale behind each choice.

---

## Project status

3.0.0 is the first GA build. Phase 1–3 (coexistence, the wizard + admin UI, and the full Campaign Intelligence integration — catalog / customers / orders / browse ingest, backfill, identity-merge, GDPR) are complete and each ingest domain was verified against the deployed engine. Pilot-hardening (Event Log, retry, health notices, queue retention) is in place, and a pre-3.0 security + code-quality + WordPress.org Plugin Check audit landed with all findings addressed (see [`docs/audits/`](docs/audits/)).

What's next is **a real-merchant pilot** (acceptance criteria in [`docs/TESTING.md`](docs/TESTING.md)) and then the **upstream merge** — folding the rewrite back into the wordpress.org plugin.

---

## Requirements

| Component | Minimum | Tested up to |
|-----------|---------|--------------|
| WordPress | 6.6 | 7.0 |
| WooCommerce | 6.9 | 10.7 |
| PHP | 8.0 | 8.3 |
| Smaily account | Active | — |

The plugin uses Action Scheduler (bundled with WooCommerce) for background work and requires HTTPS for Smaily API connections.

---

## Installation

**Fresh install:** download the latest release ZIP from the [Releases](../../releases) page → in WordPress admin, **Plugins → Add New Plugin → Upload Plugin** → choose the ZIP → **Install Now** → **Activate** → open **Smaily Connect** to start the setup wizard.

**Upgrading from legacy 1.x:** read [`docs/MIGRATION.md`](docs/MIGRATION.md) first. It covers the upgrade paths, the first-hour verification protocol, the legacy credential guard, and rollback. The migration is designed to be safe and reversible.

---

## Architecture

Three layers:

1. **WordPress integration** — hooks into WooCommerce events (product changes, customer registrations, orders), exposes the admin UI, manages settings, and runs background jobs via Action Scheduler.
2. **Domain** — payload builders translate WordPress/WooCommerce data into the canonical wire format Smaily and Campaign Intelligence expect.
3. **Transport** — HTTP clients send payloads to Smaily's marketing API and to Campaign Intelligence, with idempotent retry, queue-backed durability, and exponential backoff.

The 3.x code lives under the `Smaily\Connect` namespace (PSR-4); the legacy 1.x code lives under `Smaily_Connect` and keeps working until the setup wizard is completed. Each ingest domain follows one pattern: PayloadBuilder → IngestQueue → Flusher → HookHandler.

---

## Documentation

All project documentation lives in [`docs/`](docs/) — start with [`docs/INDEX.md`](docs/INDEX.md).

| Document | Purpose |
|----------|---------|
| [`docs/INDEX.md`](docs/INDEX.md) | Catalog of every document in the project |
| [`docs/MIGRATION.md`](docs/MIGRATION.md) | Step-by-step upgrade from the legacy 1.x plugin |
| [`docs/DECISIONS.md`](docs/DECISIONS.md) | Every significant architectural decision, with rationale |
| [`docs/RECENGINE_API_CONTRACT.md`](docs/RECENGINE_API_CONTRACT.md) | The canonical plugin ↔ Campaign Intelligence contract |
| [`docs/audits/`](docs/audits/) | Security, code-quality, and WordPress.org-readiness audits + the audit register |

---

## Development

```bash
git clone https://github.com/erkkimarkus/smaily-wordpress-plugin.git
cd smaily-wordpress-plugin
composer install && npm install
npx @wordpress/env start    # WordPress + WooCommerce in Docker, at http://localhost:8888
```

(Use the full `@wordpress/env` package name — the bare `npx wp-env` alias prints a deprecation notice and exits 0 without starting anything.) The baseline env is WP 7.0 + WC 10.7; reproducing the pilot's older stack (WC 6.9.4, legacy order storage) needs the override recipe in `CLAUDE.md`.

```bash
npm run ci:strict              # Full pre-push gate: PHPCS, PHPStan, PHP unit, JS lint + typecheck + unit
composer run test:integration  # Integration tests (real WP + WC via wp-env)
```

The integration suite covers the boundary cases where unit tests can mislead (see [`docs/LESSONS.md`](docs/LESSONS.md)). Live engine scenarios can be driven against a deployed Campaign Intelligence instance via the `bin/walk-*.cjs` scripts (gated on a connected tenant; they skip cleanly otherwise).

**Browse-beacon consent.** The storefront beacon never sends an event or sets a tracking cookie without marketing consent, resolved as: a site-provided JS override → the **WP Consent API** (`wp_has_consent('marketing')`, native to CookieYes, Complianz, Real Cookie Banner, …) → fail-safe deny. For a non-WP-Consent-API plugin (Cookiebot, custom), adapt it through the escape-hatch without code changes — the `smaily_connect_beacon_consent_category` PHP filter (if you categorise tracking as something other than `marketing`) or a `window.smailyConnectBeacon.consentOverride` JS callback pointed at your plugin's consent state.

**Recommendation attribution (landing capture).** Separate from the browse beacon: when a shopper clicks a product recommendation in a Smaily email, they land on your shop with the recommendation id in the URL. The plugin captures it **server-side** (on `template_redirect`, only when the engine is connected) into a first-party functional cookie that is then attached to the resulting order — so the engine can credit the purchase to the recommendation. This is a functional attribution signal (a recommendation id + an opaque visitor token, no personal data on their own) and is captured independently of the browse-tracking consent gate above. To disable it entirely, return false from the `smaily_connect_capture_attribution` PHP filter.

---

## Relationship to upstream

This repository is a fork of [`sendsmaily/smaily-wordpress-plugin`](https://github.com/sendsmaily/smaily-wordpress-plugin), the official Smaily-maintained plugin. The two share the `smaily-connect` wordpress.org slug but are, today, different codebases: upstream's `2.0.0` is the continuation of the 1.x line (a minimum-version bump), while this fork is a parallel rewrite that **preserves the full legacy feature set and adds** the new architecture, admin UI, and Campaign Intelligence integration.

To land monotonically and to avoid wordpress.org silently overwriting the fork mid-pilot, this fork is versioned **3.x** and ships an `Update URI` header that opts it out of wordpress.org auto-updates. The eventual **upstream merge** folds the rewrite back into the official plugin; upstream commit history is tracked in [`docs/audits/UPSTREAM_AUDIT.md`](docs/audits/UPSTREAM_AUDIT.md) and the codebase comparison in [`docs/audits/UPSTREAM_COMPARISON.md`](docs/audits/UPSTREAM_COMPARISON.md).

---

## License

This plugin inherits the license of its upstream. See `LICENSE` for the full text.

## Contributing

The plugin is not yet open to external contributions. For issues, questions, or feedback during the pilot, please reach out through the channel provided with your pilot access.

## Acknowledgments

Built in collaboration with the Smaily Campaign Intelligence team. The discipline reflected in this codebase — mandatory live testing before a release ZIP, mock-vs-real verification, a byte-synced contract between the plugin and engine repos, and decision documents capturing reasoning — is documented in [`docs/LESSONS.md`](docs/LESSONS.md).
