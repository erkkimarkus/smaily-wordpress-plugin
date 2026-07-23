# Upstream merge proposal — fold the Smaily Connect rewrite into the wordpress.org plugin

**Audience:** the Smaily team that owns `sendsmaily/smaily-wordpress-plugin` and the
`smaily-connect` wordpress.org listing.
**From:** the fork (`erkkimarkus/smaily-wordpress-plugin`), at **v3.0.0 (GA)**.
**Status:** proposal / decision request. Nothing irreversible has been done — the fork
ships behind an `Update URI` guard and is distributed only via GitHub Releases. This
document makes the case and lists what it takes to land; **the decision is Smaily's.**

---

## TL;DR

The fork is a **rewrite of the WordPress plugin** that **preserves the entire 1.x
feature set and adds** a modern admin UI, the Smaily Campaign Intelligence integration,
a durable ingest pipeline, GDPR tooling, real test coverage, static analysis, and
stronger credential encryption. Upstream `2.0.0` is, by its own changelog, the 1.x line
plus a minimum-version bump — not a rewrite.

Because the fork is a **strict superset** of what is on wordpress.org today, the
sensible path is **not a git merge** (the two are parallel codebases) but a **wholesale
takeover**: upstream adopts the rewrite as the next major version (`3.0.0`, which already
sits monotonically above `2.0.0`) and publishes it to wordpress.org. This proposal lists
the prerequisites so that takeover is clean and low-risk for the ~2,000 existing installs.

---

## What each "2.0" actually is

The two releases share a major-version number and the `smaily-connect` slug but are
**unrelated codebases** (full detail: [`audits/UPSTREAM_COMPARISON.md`](audits/UPSTREAM_COMPARISON.md)):

- **Upstream 2.0.0** (wordpress.org, ~2,000+ active installs) — the direct continuation
  of the 1.6 line; its release content is raising the WordPress/PHP minimums plus WP 7.0
  support (~14 commits / ~730 lines since the fork point).
- **Fork 3.0.0** (this repo) — a parallel rewrite on the same slug: the legacy 1.x code
  is **preserved and still runs**, with a new `Smaily\Connect` (PSR-4) architecture, React
  admin UI, and the Campaign Intelligence integration layered alongside it. In-place
  upgrades keep behaving like 1.x until the merchant completes the new setup wizard.

## The case — why a wholesale takeover

The fork contains everything the wordpress.org plugin does **and** supersedes it:

| | Upstream 2.0.0 (wordpress.org) | Fork 3.0.0 |
|---|---|---|
| 1.x feature set (subscriber sync, abandoned cart, CF7, Elementor widget, checkout opt-in block, product RSS feed) | ✓ | ✓ (preserved; RSS URL builder rebuilt into the new UI) |
| Modern setup wizard + admin UI | — (PHP-partial pages) | ✓ React + TypeScript + Tailwind, mobile-first |
| Smaily Campaign Intelligence (catalog / customers / orders / browse ingest, backfill, attribution, identity-merge) | — | ✓ every domain verified against the deployed engine |
| Background work | WP-Cron | Action Scheduler |
| Idempotent ingest (per-record `event_id`, per-item error split) | — | ✓ durable queue + Event Log + retry + health notices |
| GDPR export / erase / opt-out | — | ✓ via the WP Privacy API |
| Credential encryption | **AES-256-CBC, static IV** (an `AUTH_KEY` prefix inside the stored blob — a DB dump leaks the key prefix; equal plaintexts → equal ciphertexts) | **AES-256-GCM**, random per-message nonce; legacy blobs auto-re-encrypted on upgrade |
| PHP tests | **none** (`composer test` runs only the block's JS tests) | **374 unit (898 assertions) + 114 real-environment integration** (WP 7.0 + WC 10.7 + MariaDB via wp-env) |
| Static analysis | none at the released tag | **PHPStan level 6**, clean |
| WordPress.org Plugin Check | — | **clean** (one intentional `Update URI` finding, removed at the merge) — see [`audits/CODE_QUALITY_AUDIT.md`](audits/CODE_QUALITY_AUDIT.md) |
| Security audit | — | **0 Critical / 0 High** — see [`audits/SECURITY_AUDIT.md`](audits/SECURITY_AUDIT.md) |

The takeover therefore loses upstream nothing (every 1.x behaviour and option key is
carried over, with an in-place upgrade path) and gains the rewrite, the integration, the
tests, and the crypto fix.

## What "the merge" actually means

It is **not** a `git merge` — the histories are parallel and the codebases are different.
It is **upstream replacing its plugin source with the fork's** and publishing it as the
next major version. Mechanics:

- **Version continuity is already solved.** The fork is `3.0.0`, above upstream's `2.0.0`,
  so the takeover lands monotonically and every existing install upgrades forward cleanly.
  (This is exactly why the fork renumbered to the 2.1.0-beta line and then to 3.0 — see
  DECISIONS F3-35.)
- **Distribution flips** from GitHub Releases to the wordpress.org SVN flow; the
  `Update URI` guard that currently keeps wordpress.org from overwriting the fork is
  removed at that point (it is only load-bearing while forked).
- **Existing merchants** get the rewrite as a normal plugin update; legacy behaviour
  continues until they complete the wizard, so nothing breaks on update.

## Prerequisites & prep checklist

| # | Item | Owner | Status |
|---|---|---|---|
| 1 | **This proposal** — the case for the takeover | fork | ✅ this document |
| 2 | **Remove the `Update URI` header** (the one intentional Plugin Check finding) | fork | ✅ done (2026-07-23, ships in v3.9.0) |
| 3 | **React admin UI i18n** — wire `@wordpress/i18n` (`__()`, text domain `smaily-connect`) + `wp_set_script_translations`; the UI is currently English-only (0/41 components localized). wordpress.org reviewers expect a translatable UI | fork | ✅ done (W-7) |
| 4 | **Inline `<script>` → enqueue** — move the admin-notice dismiss handler to an enqueued script | fork | ✅ done (W-5) |
| 5 | **Reconcile 3 open upstream items** — #120 (translation `.pot` catalogs, manual merge — ours has diverged), #128 (WP7 / minimum-version bump — conflicts with our WC 6.9 floor, decide together), #132 (`release.sh` HTTP-429 recovery — only if the wordpress.org SVN flow is used) | joint | ⏳ discuss |
| 6 | **wordpress.org submission mechanics** — SVN (not git), readme.txt assets/screenshots, the plugin-review queue | Smaily | ⏳ Smaily-owned |
| 7 | **Pilot passes** — a real-merchant pilot against the acceptance criteria in [`TESTING.md`](TESTING.md) before the rewrite reaches ~2,000 production installs | joint | ⏳ prerequisite |

Items 2–4 are concrete plugin work the fork can do on a go-ahead. Item 5 needs a short
joint decision. Items 6–7 are Smaily-owned / gating.

## Risks & mitigations

- **Blast radius.** The rewrite would reach ~2,000 production installs. *Mitigation:*
  the in-place upgrade preserves legacy behaviour until the wizard is completed; the pilot
  (item 7) must pass first; the upgrade path and rollback are documented in
  [`MIGRATION.md`](MIGRATION.md).
- **Minimum-version bump (#128).** Upstream raises floors; the fork pins WooCommerce 6.9
  for the pilot merchant. *Mitigation:* reconcile the floors jointly before submission.
- **Translations.** The `.pot` catalogs diverged (#120) and the React UI is not yet
  localized (item 3). *Mitigation:* item 3 + a manual `.pot` merge before submission.
- **Ongoing divergence.** Every week the fork advances, the eventual takeover is more
  work. *Mitigation:* decide direction soon; the fork freezes net-new scope once a
  go-ahead is given.

## The decision

The technical readiness is here (GA-released, audited, Plugin-Check-clean). What remains
is **a Smaily business decision**: Smaily owns the wordpress.org slug, the ~2,000 installs,
and the brand, and the rewrite couples the public plugin to Smaily Campaign Intelligence.

**Open questions for the Smaily team:**
1. Does Smaily want the public wordpress.org plugin to carry the Campaign Intelligence
   integration (vs. keeping it fork-/pilot-only)?
2. If yes — who owns the wordpress.org SVN publish, and on what timeline relative to the
   pilot?
3. The #128 minimum-version reconciliation (WooCommerce floor) — align on 6.9 vs higher.

On a "yes," the fork executes items 2–4 and supports items 5–7.

---

## References

- [`audits/UPSTREAM_COMPARISON.md`](audits/UPSTREAM_COMPARISON.md) — codebase comparison (point-in-time)
- [`audits/UPSTREAM_AUDIT.md`](audits/UPSTREAM_AUDIT.md) — commit-by-commit upstream divergence
- [`audits/SECURITY_AUDIT.md`](audits/SECURITY_AUDIT.md) · [`audits/CODE_QUALITY_AUDIT.md`](audits/CODE_QUALITY_AUDIT.md) — the pre-3.0 audits + Plugin Check pass
- [`DECISIONS.md`](DECISIONS.md) — architecture decisions (incl. F3-35, the version-collision guard)
- [`RECENGINE_API_CONTRACT.md`](RECENGINE_API_CONTRACT.md) — the plugin ↔ Campaign Intelligence contract
- [`TESTING.md`](TESTING.md) — pilot acceptance criteria · [`MIGRATION.md`](MIGRATION.md) — the 1.x → 3.x upgrade path
