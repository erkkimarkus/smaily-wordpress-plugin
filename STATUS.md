# Smaily Connect — Current Status

**Single source of "where we are now."**

> ## Keeping this current — NOT optional
> A handoff doc that goes stale is worse than none: it hands the next agent
> false confidence. This file MUST be updated as part of the same commit that
> changes reality — never "later."
>
> **Update this file in the SAME commit when you:**
> - finish a sub-PR (move it to done, add the commit hash)
> - sync the contract (record the engine commit + what changed)
> - hit, resolve, or newly defer a lock condition / blocker
> - reach a milestone or change the roadmap
>
> **The rule:** if a change makes this file wrong, the change isn't done until
> this file is fixed in the same commit. Stale status is a defect — treat it
> like a failing test. If you (an agent) notice this file disagrees with the
> repo, fixing it is in-scope right now, not a separate task. Also bump
> _Last updated_ below.
>
> This already bit us once: the README roadmap table said Customers/Orders were
> "Pending / Awaiting W4/W5" long after they shipped, because it was written
> once and never refreshed. Don't let this file become that.

If this file and your memory disagree, trust this file and fix it. The roadmap
table in README is a high-level view; this is the working register.

_Last updated: 2026-06-30 (**F3-47 SP-A DONE — contact-sync language via `ContactLanguageResolver`**.
Managed (non-pilot) client Prike: ~1000 Smaily contacts drifted to language `en`. Root cause —
the upstream plugin's daily "sync all subscribers" cron derives language from the cron-unsafe
`Helper::get_current_language_code()`, which in a cron tick returns `get_locale()` = the WP
**site** locale; Prike's WP locale is `en` but its WPML content default is `et`, so the cron
mass-pushed `en` daily and out-raced the merchant's (correct) Make automations
(`_user_preferred_language`/`wpml_language`, default `et`). Our own new live path had a sibling
latent bug (`get_user_locale()` + always-set `language` key). Fix (SP-A): one shared
`Support\ContactLanguageResolver` — context-independent, mirrors the Make sources
(`_user_preferred_language` → latest order `wpml_language` → WPML default via `DetectorFactory`
→ site-locale short code), normalises to `et`/`en`, and **omits `language` on empty** (absent
preserves Smaily's value, empty wipes). Wired into `HookHandler` (`for_user`/`for_order`,
omit-when-empty on all three payloads). Robust **without a live-store data check** (Erkki has no
direct shop access — we ship only the plugin): the latest-order tier preserves the non-`et`
minority. Decisions (Erkki): sync **all** registered customers regardless of consent; **no
guests**; **never send `is_unsubscribed`** (Smaily owns consent — the legacy path reset it,
another reason to migrate Prike off it). Contact sync is `setup_completed`-gated, independent of
the rec-engine → ships before Prike goes on the engine. Gates: ci:strict exit=0 (PHPUnit 404
+13, JS 158, PHPStan clean, PHPCS 0 errors); integration OK 119. **SP-B DONE** — `BackfillJob::build_subscriber_payload` adds `language` via the same resolver
(omit-on-empty), so a one-off backfill run = the corrective mass re-sync of the ~1000 (re-sends
each contact with the resolver's language, not the cron's stale `en`). **Pending sub-PRs:** SP-D
(replace legacy daily-cron bridge `on_contact_sync_tick` so the `en`-clobber stops — until then
the backfill fixes contacts but the daily cron can re-drift them), SP-E (lock `is_unsubscribed`
out of the payload), SP-G (cutover: Connect plugin → wizard → Make data-sync off). **NOTE: the
ad-hoc SP-D/SP-E plan is now SUPERSEDED by the F3-48 contact-sync mode engine** (below) — the
cron takeover + `is_unsubscribed`/`force_opt_in` handling fold into that engine.

**F3-48 contact-sync mode engine — DESIGN APPROVED (Erkki, 2026-06-30); F3-48.1 + .2 + .3 + .4 DONE.**
F3-48.4: `AutomationRouter::trigger_automation` passes `ContactSyncMode::automation_force_opt_in()`
(consent/checkout → never re-subscribe on trigger; legitimate interest → only with the advanced
toggle) instead of the hard-coded `true`. Gates: ci:strict exit=0 (PHPUnit 442), integration OK 119.
Remaining: .5 UI (preset selector) → .6 is_unsubscribed opt-out + regression locks → .7 Prike
cutover (+ thorough end testing per Erkki: full gates + live-walk + security/code-quality re-audit
+ PCP against the built ZIP).
F3-48.3 (cron takeover): `on_contact_sync_tick` no longer fires the legacy buggy mass-send (the
F3-47 site-locale clobber — now orphaned); it runs `ContactReconciler::reconcile()` (consent) +
a mode-aware refresh via non-clearing `BackfillJob::start(false)`, guarded by
`should_start_refresh()` (skip while running / re-arm once per freshness window). Gates:
ci:strict exit=0 (PHPUnit 441), integration OK 119. Remaining: automation force_opt_in → UI →
is_unsubscribed+locks → Prike cutover.
F3-48.2: `ContactReconciler` (Smaily→WP marketing-consent mirror, consent mode) + `Client::
get_action_log()`/`list_contacts()`. Delta-first — standing reconcile polls the Smaily action-log
(`history.php` + `since_seq_id`) for optin/optout/delete/complaint deltas (O(changes), light on
shared hosting); full `list=1` pull only as an occasional re-baseline. Marketing-only (never
profiling). New cross-team doc `docs/CONSENT_STRATEGY_COMPARISON.md` (engine vs plugin consent
layers). Not yet wired — cron takeover next. Gates: ci:strict exit=0 (PHPUnit 436), integration
OK 119.
F3-48.1: `ContactSyncMode` (preset→policy) + `ContactAudience` (mode-aware audience) wired into
the HookHandler live `contact.sync` gate + BackfillJob audience filter; default `consent`
narrows the new path to `user_newsletter=1` (matches legacy), legit-interest syncs all. Gates:
ci:strict exit=0 (PHPUnit 425, PHPStan clean, PHPCS 0 errors); integration OK 119. Remaining
sub-PRs below. Different stores need
different sync behaviour by lawful basis: Prike wants ALL customers (legitimate interest;
legacy only synced `user_newsletter=1` → the missing-contacts root cause); Client 2 wants
consent + Smaily↔WP reconcile; Client 3 wants checkout-opt-in-only/guests. Decision (Erkki):
named **presets** (not a toggle matrix), default = consent (lawful-safe AND matches legacy's
opt-in filter, so upgrades never silently broaden). Three presets: All customers (legit
interest) / Subscribers only (consent, default) / Checkout opt-in only. `include_guests`
checkbox default off; bidirectional reconcile; **automation `force_opt_in` is mode-driven**
(unifies the AutomationRouter-always-true vs legacy-abandoned-false inconsistency;
`force_opt_in` is an undocumented Smaily param being added to `../re/docs` by a separate
agent). UI = radio-card presets in Step2Subscribers + warning Banner for legit-interest.
Full design: `docs/CONTACT_SYNC_MODES.md`; DECISIONS F3-48. Both open questions resolved
(preset-1 `force_opt_in` defaults `false` + advanced preset-1-only toggle; preset labels kept).
Implementation sequence: mode core → reconciler + cron takeover → automation force_opt_in → UI
→ regression locks → Prike cutover. Builds on the shipped F3-47 language resolver
(mode-independent). Interim mitigation while
Prike is still on the old plugin: uncheck "Language" in its Subscriber Synchronization (stops the
daily `en` overwrite — omitted field preserves the existing value). DECISIONS F3-47). Prior — 2026-06-26 (**F3-46 DONE — server-side rec-attribution landing capture**.
Engine brief `PLUGIN_BRIEF_woo_rec_link_redirect.md` (rev 2): prod shows 374 orders/30d, 0
with `smaily_rec_id` — attribution empty. Root cause: the capture→stamp→send chain already
existed end-to-end (`HookHandler` reads cookie `smaily_rec_id` → order meta → `OrderPayloadBuilder`),
but the ONLY cookie producer was client-side JS (`StorefrontBeacon`/`captureUrlParams`), gated
behind browse-tracking + marketing consent + not-ad-blocked → it never fired on the pilot. Fix:
new `Integrations\WooCommerce\LandingCapture` on `template_redirect` writes the SAME cookies
server-side, ungated by the beacon path (DECISIONS F3-46). **Two decisions (Erkki):** (1)
**follow the contract, not the brief** — capture `smaily_rec`→`smaily_rec_id`/`smaily_vt`→`smaily_rec_uid`/`smaily_ctx`→`smaily_rec_ctx`
(the brief's `smre_*`/`utm_content`/90d diverge from the byte-synced §"Cookie names"); accept
`utm_content` only as a fallback guarded by `utm_source=smaily`+uuid; **feedback sent to the
engine team** to realign their brief; (2) **capture unconditionally when connected** — rec
attribution is a first-party functional signal (rec_id uuid + opaque visitor token, no PII),
decoupled from the browse beacon; browse/Layer-2 consent stays separate; `smaily_connect_capture_attribution`
filter is the escape-hatch. Zero downstream change (no HookHandler/builder edit). Out of scope:
the brief's optional redirect endpoint (§3.4, YAGNI), Layer-2 site-wide vid, the pre-existing
block-checkout stamping gap. Gates: ci:strict exit=0 (unit 391 +17, JS 158); integration OK 119
(+5). **The real click→land→buy→attribute round-trip is a manual pilot check** (server path is
unit+integration-proven; the browser moment is not walk-coverable). **Released `v3.1.0`** — full
GitHub release on the fork (`erkkimarkus/smaily-wordpress-plugin`, build `904f4ab`, ZIP ~994 KB
attached) — the pilot needs this to fix the empty attribution; after install, do the manual
rec-link round-trip check. Release gate: PCP against the BUILT ZIP clean except the intentional
`Update URI` (F3-35); a focused security pass on the new `$_GET`/cookie surface found no new
findings (audit register row added). Prior: **`v3.0.1` RELEASED** — full GitHub release on the fork
(`erkkimarkus/smaily-wordpress-plugin`, build `a34ed40`, ZIP 966 KB attached): the React
admin UI internationalization (W-7) + the W-5 enqueue refactor + a **complete Estonian
translation** (all 275 strings; admin UI + blocks + PHP). Translation-only, no functional
change. `bin/build-i18n.sh` rebuilds `languages/*.mo`/`*.json` (incl. the admin-bundle
catalog `…-et-464ceaab….json`) reproducibly. Gates: ci:strict exit=0 (unit 374, JS 158);
integration OK 114; Playwright-verified full-wizard Estonian render (0 console errors); PCP
against the ZIP clean bar the intentional `Update URI`. These are the fork-side
upstream-readiness items (see `docs/UPSTREAM_MERGE_PROPOSAL.md`); **W-3** (remove `Update
URI`) stays until the actual wp.org merge, and the full-ET review by a native speaker +
upstream #119/#120/#128/#132 + the Smaily go/no-go remain. Prior: **Pre-3.0 GA audit pass**
— three read-only audits on the
`906cf3d..HEAD` delta (~151 files / +10.4k lines since the 2026-06-11 Fable audit):
Security, Code-quality + wordpress.org-readiness (incl. `wp plugin check` PCP 2.0.0),
relocated with the existing audit docs into a new **`docs/audits/`** folder + register
(`docs/audits/INDEX.md`) + a **re-audit policy** (CLAUDE.md). **Result: no
release-blockers; codebase well-built.** Security 0 Critical / 0 High (2 Low: admin-gated
SSRF on engine base_url, Event Log PII-at-rest cleartext; deps clean). Code-quality
GA/upstream-ready. Punch-list for the 3.0 cut: ABSPATH guard on ~29 shipped legacy files
(`includes/smaily-*`, `integrations/**`, `blocks/**`, …), drop "(BETA)" from the plugin
`Name` header, gate ~21 unconditional `error_log()` behind WP_DEBUG, `esc_html`/phpcs
the ~9 `ExceptionNotEscaped` throws, PCP polish, re-run PCP against the built ZIP. At
upstream merge: **remove the `Update URI` header** (F3-35 fork-only guard, not allowed on
wp.org). **Punch-list NOW APPLIED (full PCP-clean, Erkki's call):** ABSPATH guard on the
~29 legacy files, `error_log` → new `Support\DebugLog` (WP_DEBUG-gated, ~23 sites), file-level
`phpcs:disable` on the custom-table DAOs + justified ignores (DB/nonce/hookname/textdomain/
ExceptionNotEscaped), readme Upgrade-Notice trimmed, blocks `apiVersion` 2→3 (⚠️ needs an
editor smoke-test), `.zipignore` drops stale `dist/partials`+`dist/template` / `BACKLOG.md` /
`blocks/.eslintrc.cjs` and ships `composer.json`. **`wp plugin check` against the BUILT ZIP is
clean except the 2 intentional** (`Update URI`=fork guard, `(BETA)`=3.0 cut). ci:strict exit=0
(unit 374, JS 158). Lesson: run PCP against the ZIP with `--slug` (the dev-tree run hid `dist/`
+ blocks; a wrong unzip-dir name caused 255 false TextDomainMismatch) — CLAUDE.md updated.
**Then the 3.0.0 GA cut STARTED:** all tests green first (ci:strict unit 374 / JS 158;
integration 114; **a Playwright browser smoke-test confirmed the apiVersion-2→3 blocks
render in the WP 7.0 iframe editor with 0 console errors**); version bumped **2.1.0-beta.10
→ 3.0.0** across the header / both constants / package.json / readme Stable-tag+changelog
+upgrade-notice / the 3 test pins, and **"(BETA)" dropped** from the plugin Name (clears
PCP `mismatched_plugin_name`). **`v3.0.0` GA RELEASED** — built the `--no-dev` ZIP (974 KB,
no dev cruft, no `-dirty`, from commit `910f632`); **final `wp plugin check` against the prod
ZIP = 1 finding, the intentional `Update URI`** (`mismatched_plugin_name` cleared by the BETA
drop); published as a **full (non-pre-) GitHub release** on the fork
(`erkkimarkus/smaily-wordpress-plugin`, tag `v3.0.0`, ZIP attached) — `release.yml` fires but
fails harmlessly (no wp-cli) and does not clobber the asset. Then the
**upstream-merge prep started** (the technical items the fork can do before Smaily
greenlights the takeover; see `docs/UPSTREAM_MERGE_PROPOSAL.md`): **W-5 DONE** — the
admin-notice dismiss moved from an inline `<script>` to an enqueued
`admin/js/notice-dismiss.js` (E2E-verified); **W-7 DONE** — the React admin UI is now
**fully internationalized** (~244 strings across 24 files wrapped with a `wp.i18n` shim
`admin/src/lib/i18n.ts`; `wizard.php` enqueues `wp-i18n` + `wp_set_script_translations`;
`bin/build-i18n.sh` reproducibly rebuilds `languages/*.mo`/`*.json` incl. the admin-bundle
catalog — make-pot can't read `.tsx` so it esbuild-transpiles first, and the catalog is
renamed to WP's expected `…-et-464ceaab….json`; a Playwright check confirms Estonian
renders end-to-end with 0 console errors; 21 representative ET strings translated, the
rest of the `.po` ready to fill). ci:strict exit=0 (unit 374, JS 158); integration OK 114.
**Still NEXT:** **W-3** remove `Update URI` (at the merge, not before — it's the fork
clobber-guard), reconcile upstream #119/#120/#128/#132, full ET translation of the `.po`,
and the Smaily go/no-go on the takeover. See `docs/audits/`. Prior:
**Faas 2: legacy admin settings-page removed (F3-45)** — the
redundant legacy `Admin` settings page + `Settings`/`Renderer`/`Sanitizer` + partials +
`smaily-admin.css/js` deleted; the subscription **widget** and Plugins-page **Settings
link** relocated (100% preserved); `Notices`/`Notice_Registry` KEPT (1.3.0 upgrade notice).
Hard constraint honoured: must not break the ~2000 installs — new UI owns the same option
keys, kept integrations unchanged. ci:strict exit=0; integration OK 114. Prior:
**Event Log stores the real request + engine response
(Problem 3 / F3-44)** — Details showed `Payload: []`; now order/catalog/Smaily rows store
the exact JSON sent + the engine reply (`sent_payload` / `last_response`, migration 007,
both queues), never the auth header; a terminal-skip records `outcome:"skipped"` (exposes
the silent "sent"). ci:strict exit=0 (unit 377, JS 158); integration OK 114. Prior:
**Order sync data-loss fixes (F3-42/F3-43)** — engine brief
order #58922: a guest order with a deleted product was marked "sent" but never POSTed.
F3-43: a deleted-product line is never dropped (keys `wc-oi-{item_id}`) so the order isn't
lost; F3-42: custom WC statuses (label-printed/shipped) default through as a sale
(denylist), on-hold now non-sale (reverses F3-22). ci:strict exit=0; integration OK 113;
live-walk 7/7 (`bin/walk-f3-43-orders.cjs`). The brief's Problem 2 (errors[]→FAILED) was
already built (F3-18); Problem 3 (store request/response in Event Log) is a deferred
follow-up. Prior: **F3-40 trash fix now live-walked 7/7 against the sandbox**
(`bin/walk-f3-40-trash.cjs`) — trash → `catalog.delete` with the clobber guard live
(`delete=1 upsert=0`), the engine ACCEPTS `in_stock=false`, untrash → `in_stock=true`
accepted, backfill trashed → delete accepted. Closes the mock-only gap F3-40 shipped
with, and confirms the 2026-06-18 pilot log errors were pre-existing orphan rows
cleared on retry, not a bug in the fix. ci:strict exit=0 (unit 359, JS 158). Also:
the Plugins-list **"Settings" link repointed from the legacy view to the new UI**
(`smaily-connect-settings`; Task 2 Faas 1) — the legacy view-layer teardown is a
separate pending sub-PR. Prior:
**Browse beacon renamed off "beacon" → `/relay` +
`sc-runtime.js` (F3-41)** — engine-team brief Teema 3: zero real browse events. After
the two-gate config was fixed (toggle on + CookieYes marketing consent), Erkki found
the storefront POST only succeeded with the **ad-blocker off** — the word "beacon" is
on EasyPrivacy filter lists and blocked both browser-visible names: the script
`dist/public/js/beacon.js` and the route `/wp-json/smaily-connect/v1/beacon`. Fix:
neutral names — script `dist/public/js/sc-runtime.js` (vite entry key only; source
`public/js/beacon.ts` unchanged), route `/relay` (`BeaconEndpoint::ROUTE`,
`StorefrontBeacon` beaconUrl, `EndpointRegistry`, browse live-walk, integration tests),
handle `smaily-connect-runtime`. **Consent unchanged** — first-party + WP-Consent-API
marketing-gated; only the filter-tripping name is neutral, not the consent. Internal
names (classes, `beacon.ts`, `window.smailyConnectBeacon`, `beaconUrl` key) keep
"beacon" on purpose (not browser-visible). Whether a blocker still catches `/relay` is
a **manual browser check** (200 with blocker on); the integration test only proves the
server dispatches `/relay`. Gates: ci:strict exit=0 (unit 359, JS 158); integration OK
113. DECISIONS F3-41; CLAUDE.md beacon-naming note. **Released `v2.1.0-beta.8-rc.1`**
(GH pre-release on the fork, build `e85bb2a`, ZIP attached; cumulative — includes the
F3-40 trash fix). Pilot: install → product re-import → confirm `POST …/v1/relay` = 200
with an ad-blocker on. Prior: **Trashed products kept in catalog as `in_stock=false`
(F3-40)** — engine-team 2026-06-17 brief, Teema 2: ~4% of pilot order lines had no
`catalog.sku` match (~567 rows / ~265 customers) → species un-inferable from
purchases. Erkki traced them to the WordPress **trash** (not permanent delete).
Root cause: trashing fires NO catalog hook (`before_delete_post` is
permanent-delete-only; trashing routes via `wp_update_post`), and the backfill was
`publish`-only → trashed-but-once-bought products go missing/stale and orphan the
`order_items.sku ↔ catalog.sku` join. Fix (A+B, the engine's "send `in_stock=false`,
don't drop" rule): `Bootstrap` binds `wp_trash_post → on_delete_product` +
`untrashed_post → on_save_product`; `CatalogBackfillJob` enumerates `publish`+`trash`,
sending trashed products as `in_stock=false` (kept for the join/training, not
recommended — the engine has no delete-by-key, so a `catalog.delete` row IS an
`in_stock=false` upsert). `is_removable` extracted to a shared static (F3-39 guard
reused on both paths). **Caught + guarded a real clobber bug** (live-hook integration
test, not review): `wp_trash_post()` also fires `save_post_product` → `on_save_product`,
which re-upserted `in_stock=true` and undid the removal — `on_save_product` now
early-returns on a `trash`-status save. Permanently-deleted products remain
unrecoverable (no WC data) — accepted. Gates: **ci:strict exit=0 (unit 359, JS 158);
integration OK 113**. DECISIONS F3-40; CLAUDE.md trash note. **Released
`v2.1.0-beta.7-rc.1`** (GH pre-release on the fork, build `bd8b9cb`, ZIP attached);
pilot still needs a **catalog re-backfill after install** to populate the trashed rows.
Brief's Teema 1 (order statuses: custom `label printed`/`shipped` etc. dropped by the
5-key `STATUS_MAP`) = client fixes WC-side, no plugin change; Teema 3 (browse beacon
0 events) = pilot config (toggle now on + CookieYes installed + marketing consent
given), watching for engine browse traffic. Prior: **`2.1.0-beta.6-rc.1` RELEASED** — GH pre-release on
the fork (build `b99eb15`, ZIP attached): engine **default URL →
`https://intelligence.smaily.com`** (migrated off the `*.vercel.app` preview
host). Static-reference-only change — the `Constants::SETUP_BASE_URL` default,
the connection-screen setup-URL placeholder, contract
base/setup/`engine_base_url`/curl examples, and the integration
connectivity-test base. **No contract/data/field/header (`X-Engine-Version`)
change**; the runtime path self-adapts (engine returns its live
`engine_base_url`, plugin derives the host from the pasted setup URL) and the
old `*.vercel.app` alias still resolves — existing installs need no action.
Prior: **`2.1.0-beta.5-rc.1` RELEASED** — display-name change only, the
rec-engine product renamed **Smaily Campaign Intelligence** across user-facing
surfaces (internal identifiers, REST routes, contract, endpoints unchanged; ET
translations updated). Prior: **`2.1.0-beta.4-rc.1` RELEASED** — GH pre-release
on the fork, bundles three fixes on top of beta.3: **CC.5 catalog.delete
auto-draft-burst fix** (skip never-published artifacts whose removal object the
engine 400s, F3-39 / LESSONS §2.12), Event Log actions column pinned right
(Retry/Details stay reachable), and backfill progress showing the honest
synced-product count instead of the misleading multilingual sent/raw-total
fraction. Awaiting Erkki's install to MiuMjau. — Prior: **catalog-correctness
CC.1–CC.4 DONE + RELEASED + DEPLOYED**;
multilingual fix, model **(B) {lang:value}** + structural signal
(NOT a filter, engine owns `recommendable`, F3-38). Released
**`v2.1.0-beta.3-rc.1`** (GH pre-release on the fork); Erkki DEPLOYED to MiuMjau;
**canonical re-sync running; engine team confirmed the data arrives in the correct
shape.** Go-live sequence: deploy ✓ → engine wipes MiuMjau SKU-graph → full
re-backfill (signal + customers.language ride along) → engine recompute (in the
wipe/rebackfill phase, continuing engine-side). CC.1 canonical adapter primitive;
CC.2 canonical SKU (catalog+orders+browse, via SkuResolver) + collapse + P4; CC.3
{lang:value} payload (500-char/lang); CC.4 product_type/is_virtual/is_downloadable
signal. Live-walk **9/9** (`bin/walk-cc3-multilingual.cjs`, sandbox). MiuMjau =
WPML + WCML (variations auto-resolve). OPEN: language-switcher `wc-49143` classify
(its product_type from the re-backfill / post-49143 inspection); MiuMjau gift-card
type string (self-heals on re-backfill); CI "Lint and test" PRE-EXISTING red
(integration-without-WC, not ours); dev wp-env sandbox conn scrubbed by the last
integration run (fresh token for future walks). Release/CI notes in CLAUDE.md. See
the catalog-correctness section below. Earlier 2026-06-12 late:
**engine go-live sync done** — results in
docs/ENGINE_TEAM_PILOT_SYNC_RESULTS.md; MiuMjau IS the pilot tenant (walks →
sandbox from now on, CLAUDE.md updated); engine fixed 2 catalog-ingest bugs
(the 91% retry-error rate was theirs); pilot needs: connection check after
the key-rotation window + Retry-all-failed again + browse enable + joint
GDPR run. Earlier: **P8 pilot day-1 fix: SkuResolver / F3-36** — the
pilot store has NO SKUs + old orders reference deleted products; catalog was
silently empty (pre-enqueue drop, no Event Log trace), orders D6-failed on
empty `items[]` (the 50 red rows), browse events rejected. Fix: synthetic
`wc-{id}` keys on all three surfaces + empty-items terminal skip + mock now
enforces items min-1; **2.1.0-beta.2**; LESSONS §2.11. Same day, P9: pilot
mass-email incident (sender = third-party CartBounty Pro, NOT us — but our
legacy pipeline had the same unbounded-backlog flaw, enabled) → F3-37
backlog guard + per-cart errors, rc.2. Live-walks ALL GREEN
(catalog 15/15 w/ new lock-proof lever, orders 12/12, browse 13/13); GH
pre-release **v2.1.0-beta.2-rc.1** published with the pilot ZIP. Next: pilot
redeploy + catalog re-backfill + Retry all failed. Also: health-notice
placement fix (wp-header-end). Earlier same day: **P7 upstream auto-update clobber guard** — upstream's
w.org 2.0.0 would have been offered/auto-applied OVER the fork; fixed with
`Update URI` header + renumber to **2.1.0-beta.1**; first GH pre-release
v2.1.0-beta.1-rc.1 with pilot ZIP. Same day, earlier: **P6 RSS feed URL builder** — pilot-prep finding:
old 1.6 RSS tab had no new-UI home (2.H.3 side-effect; the feed itself never
broke). Rebuilt stateless on Integrations step/tab; EnvDetector emits the
boot-payload `rss` block; DECISIONS F3-34. Previous day 2026-06-11: THREE update groups. **Upstream-merge prep
sub-PR (latest):** README feature-complete refresh; CHANGELOG.md created;
DECISIONS_DRAFT finalized as `docs/DECISIONS.md` (single-file log chosen over
ADR split); .pot regenerated + et.po updated with 39 new-string Estonian
translations (+ compiled .mo/.json now ship in the ZIP — upstream #120
reconciled: nothing to merge, fork already carried those); phase-4
cron-interval TODO decided (keep — public API); WP 7.0 is now the integration
BASELINE (suite 99/99, pre-pilot pin closed; pilot-repro recipe in CLAUDE.md);
fresh pilot ZIP cut. BACKLOG doc-drift row closed in full. **Audit + fixes:** full codebase audit (`docs/audits/FABLE_AUDIT.md`) → fix series F1–F6 all landed: F1 removed the 2.H.16 diagnostics that logged the Smaily password to debug.log (CRITICAL); F2 dead-ajax cleanup + audit corrections; F3 Cypher v2 AES-256-GCM + upgrade re-encryption (closes BACKLOG GCM, F3-32); F4 readme.txt rewritten for 2.0.0-beta.1 incl. the rec-engine external-services disclosure; F5 INSTALL.md profiling-opt-out section; F6 queue janitor + created_at index (BACKLOG item pulled forward, F3-33). Integration now OK 99. **Earlier:** `docs/TESTING.md` pilot-acceptance plan written from Erkki's business input — the "Erkki / business: TESTING.md" go-live item is closed; see the milestone section. Previous 2026-06-09: 3.9 Step-4 activation COMPLETE — locked design: connecting the rec-engine syncs ALL domains (system-decides), the four per-domain sync toggles (orders/customers/products/cart) were cosmetic/write-only and are REMOVED; browse-tracking is the only Step-4 toggle (legal-consent gate, opt-in default-off). Disconnect clears only the connection options and PRESERVES `smly_plus_rec_track_browsing`, so re-connect restores the toggle — which required a mandatory hydration fix (EnvDetector emits the saved value, hydrate reads it instead of hardcoding false; also fixes a plain-reload blanking bug). Dead option keys cleaned up idempotently on upgrade-detect. PLUGIN.md §Step-4-4a/§6 revised to match the vision; DECISIONS F3-29. Then a pre-3.9 task: PLUGIN.md translated ET→EN. Next: Phase 3 done; Smaily profiling-consent wiring + beacon two-gate stop is the remaining separate piece. POST-3.9: (i) **legacy-WC order-backfill verified** — WC 6.9.4 + PHP 8.1 env, real `wp_posts` traversal, full integration 75/75 on legacy (pilot precondition RESOLVED, see go-live checklist); (ii) a production-readiness audit surfaced two NEW pilot-blockers beyond features — failed-queue-row invisibility/no-re-drive (P1) and no surfaced diagnostic trail (P2) — plus a WC-version-header mismatch (header says 7.0, pilot is 6.9.4) and a missing pilot-onboarding doc; tracked for prioritisation. Then pilot-hardening began: **P5** version-floors reconciled (WC 6.9/WP 6.2/PHP 8.0); **3.10.0** Event Log visibility shipped — `/events` UNION read-model + Settings tab + sticky failed-banner + backfill progress now engine-confirmed sent/failed (no more "1400/1400 while failed"). Sequence ahead: 3.10.1 recovery → 3.10.2 notice → P4 onboarding doc; then Smaily-consent (awaits its spec). See pilot-hardening sequence below. **🎯 ALL of that now DONE — 2026-06-09: pilot-hardening complete (P5/3.10.0-2/P4) + (a) Smaily profiling-consent complete ((a).0 enforcement + (a).1 beacon two-gate + (a).2 My-Account opt-out UX + 9/10 live-walk, §10 accepted as 3.8-proven). PLUGIN-SIDE PILOT-FEATURE-COMPLETE; feature-complete ZIP cut. Remaining for go-live is non-plugin-code: TESTING.md (business), engine-frontend (engine team), manual/pilot verifications, the (a) TODOs. See the milestone section below.**)_

---

## The two-team picture

Two repos, one byte-identical contract (`docs/RECENGINE_API_CONTRACT.md`):

- **Plugin** (this repo) — WordPress plugin. Sends WooCommerce data (catalog,
  customers, orders, browse) to the recommendation engine via API; syncs
  contacts to Smaily. Consumes the contract.
- **Engine** (separate repo, the "engine team") — multi-tenant recommendation
  engine. Receives ingest, computes recommendations, runs Smaily sync/poll,
  attribution, learning. Owns the contract; the plugin tracks it.

Coordination is via the shared contract + escalation of edge cases. Routine
plugin work builds against the stable contract **without** per-step sign-off
from the engine team. Sync only when the engine changes the contract (bugfix,
new field, semantics). Escalate edge cases (these have found real engine bugs).

---

## Engine side (the contract the plugin builds against)

**Route A core: COMPLETE.** All five ingest/order endpoints aligned — batch,
D6 per-item `errors[]`, email identity, `compare_price` semantics. Contract
synced byte-identical across both repos (8 syncs, latest engine commit
`3dd5d16`).

| Engine work item | What it delivered | Status |
|---|---|---|
| W1 | per-item Layer-2 dedup canonical | synced |
| W2 | product_url/in_stock required (F3-17) | synced |
| W3 | compare_price/on_sale_until canonical (D2 Variant 1) | synced |
| W4 | email-first identity (no smaily_contact_id), batch customers, D6 reference | synced |
| W5 | batch orders, status/currency/items, D6; Bug 1 + Bug 2 fixed | synced |
| N-6 | browse §6: 9 event types, checkout_* valid, source optional | synced |
| N-7 | catalog + browse retrofit all-or-nothing -> D6 | synced |
| Final pass | request_id setup-only, §8 GDPR export cleanup | synced |

**Engine backend: ~90-95% real.** Narrow gaps, almost all engine-internal:
browse signal unconsumed (intentional, §14.2 Variant-A, post-MVP — beacon data
accumulates, influences recommendations later), mass/transactional playbooks
deferred, lift_global placeholder, one bad AI model ID. **None of these change
the contract the plugin consumes.**

**Engine frontend: ~40-50% real.** Functional: dashboards, tenant CRUD, CSV
upload wizards, integrations. **Stub: Customers browse, Orders browse,
Recommendations, Settings, Decision-log, Cron-status** — UI-only gaps over
working backends. Engine team is building these UI-first. Pilot-debug relevance:
see "Pilot go-live" below.

---

## Plugin side (our work)

### Done

- **catalog-end** — ZIP'd, live-walked. PayloadBuilder + Client + IngestQueue +
  IngestFlusher + CatalogHookHandler. (F3-16 canonical pattern.)
- **customers-end** — ZIP'd (791c00b), live-walked 10/10 against MiuMjau engine.
  CustomerPayloadBuilder + Client::ingest_customers + ApiException D6 +
  CustomerFlusher (D6 reference) + CustomerHookHandler. (F3-19 milestone.)
  Commit chain: 0fcbcd0 -> 9fabcf7 -> db3a0da -> 26a6e44 -> e4dfb91 -> 791c00b.
- **orders-end** — ZIP'd, live-walked 12/12 against MiuMjau engine. **No format
  surprises** — ordered_at Z-form (IsoDate F3-21 carried over) and the WC→enum
  status mapping both validated live (the engine rejects a raw WC status, so the
  mapping is necessary AND correct). OrderPayloadBuilder + Client::ingest_orders
  (batch 50) + OrderFlusher (D6) + OrderHookHandler (status-change wiring).
  Commit chain: 29edfe4 -> 652e16c -> 4d036cf -> a8bde99 (.3) -> this commit (.4,
  + ZIP; the .4 build-hash is this commit).
- **plugin-side N-7** — catalog-flusher D6 consolidation (the lock, now RESOLVED).
  N-7.0 extracted `AbstractD6Flusher` (shared D6 flush + errors[].index split +
  invariant) and refactored Customer/OrderFlusher onto it (byte-identical
  behavior, regression green). N-7.1 moved the catalog IngestFlusher onto the base
  (catalog all-or-nothing -> D6), updated the mock + tests to D6, and live-walked
  catalog 15/15 against MiuMjau — including `flusher_d6_split_lock_proof` (a no-SKU
  product is D6-rejected per-item and marked FAILED, the valid one SENT). The
  N-7.1 live-walk also **caught the W2 `items`->`products` wrapper drift** (the
  sync had updated the doc, not the code; the mock hid it) — fixed in Client +
  mock + ClientTest. (DECISIONS F3-22 + N-7; LESSONS §2.7.)

### Done — 3.4 browse-beacon (complete, live-walked + ZIP'd)

- **3.4 browse-beacon** — storefront telemetry → server proxy → engine
  `/api/v1/ingest/browse`. Differs from the ingest domains: client-buffered
  best-effort telemetry, NOT the Queue/Flusher pattern (intentional, F3-16
  deviation). **3.4.0 DONE** (server side): `Client::ingest_browse` (`events`
  wrapper), public `POST /beacon` proxy (`BeaconEndpoint`) with the abuse model
  — hard-404 gate (connected + `track_browsing`), per-IP + per-session
  rate-limit, server-side §6 event_type/event_id validation + field-whitelist.
  Mock browse route (D6) + unit (validate_batch, ingest_browse) + integration
  (7 proxy tests). Gates green. **NOTE/deviation to confirm:** the route is
  registered *unconditionally* and the handler 404s when disabled (not
  conditional registration) — same attack surface, but testable without
  rebuilding the REST server (which segfaults wp-env). (DECISIONS F3-24.)
  **3.4.1 DONE** (client transport): filled `RecEngineClient.track/flush/destroy`
  in `rec-engine-client.ts` — in-memory buffer, 30s batch window, consent-gated
  flush (no consent ⇒ buffer dropped, nothing sent), `navigator.sendBeacon` on
  pagehide, fetch keepalive otherwise. EventType union 8→9 (added
  `wishlist_remove`, the §2.7 drift). `captureUrlParams` (3.4.2) + `mergeIdentity`
  (3.7) still throw. 11 vitest tests. Gates green (ci:strict exit=0).
  **3.4.2 DONE** (cookies — closes the attribution loop, the cookie PRODUCER
  the 3.4.0 audit found missing): `captureUrlParams()` (campaign URL params
  smaily_vt/rec/ctx → first-party cookies, then strip the URL — cookie SAVED
  before `history.replaceState` strip so attribution can't be lost) +
  `ensureSession()` (generates the `smaily_anon_sid` v4 cookie). Cookie names +
  TTLs + URL-param names come from the engine config; cookies are SameSite=Lax,
  Secure on https, Path=/. **Cookie writes are consent-gated** (no tracking
  cookie without consent — same principle as 3.4.1 no-send; the WP Consent API
  *wiring* is 3.4.3). 7 more vitest tests (18 total). `mergeIdentity` (3.7)
  still throws.
  **3.4.3a DONE** (WP-wrapper + storefront wiring, first PHP+JS sub-PR): PHP
  `StorefrontBeacon` (wp_enqueue_scripts, gated on connected + track_browsing +
  WC active) enqueues the beacon + prints `window.smailyConnectBeacon` (config
  from engine config + page context from WC conditional tags); `beacon.ts` entry
  + `beacon-core.ts` logic wire consent to the **WP Consent API** (CookieYes etc.;
  fail-safe DENY; native `wp_listen_for_consent_change` re-run) with an
  escape-hatch (`smaily_connect_beacon_consent` PHP filter +
  `consentOverride` JS, documented in README) for non-compatible plugins, then
  on consent: ensureSession + captureUrlParams + page-view track
  (product_view/category_view/search/checkout_start/checkout_complete). Build:
  beacon bundles RecEngineClient inline → self-contained classic-loadable
  `dist/public/js/beacon.js` (no top-level import/export; vite entry swap +
  beacon-core/entry split). category_path reuses `CatalogPayloadBuilder::
  primary_category_path` (made public) so browse↔catalog correlate. Tooling
  globs broadened lib→public/js. 10 vitest + 6 integration. Gates green.
  **3.4.3b DONE** (WC cart events, JS-only): `attachCartListeners()` wires
  WC's jQuery `added_to_cart` → `cart_add` and `removed_from_cart` →
  `cart_remove`, SKU from the button's `data-product_sku`. Attached in start()
  so cart tracking is consent-gated too; no-op when jQuery is absent. Known gap:
  the single-product form-POST add-to-cart fires no JS event, so its cart_add
  isn't tracked, and a SKU-less event is skipped (best-effort, §14.2). 5 more
  vitest tests (33 client + beacon-core total). Gates green. **3.4.3 complete.**
  **3.4.4 DONE** (live-walk + ZIP): `bin/walk-3.4-browse.cjs` — **13/13 against
  the real MiuMjau engine**. Two paths: in-process REST dispatch to `/beacon`
  (full proxy→engine chain + the abuse filter on the live endpoint) + direct
  `Client::ingest_browse` (the §6 per-item behaviours the proxy 400s first).
  Proven live: all **9 event types processed** (EventType 8→9 §2.7 fix confirmed
  against the engine), anonymous vs `with_customer_match`, missing-event_id +
  invalid-event_type → engine per-item `errors[]`, dedup, and **`retroactive_bound=2`**
  (anon session events rebound to a customer once an email resolves — browse's
  hardest engine behaviour, end-to-end). Abuse on the live `/beacon`:
  101-events→400, bad-type→400, missing-id→400, rate-limit→429. ZIP includes
  `dist/public/js/beacon.js` (self-contained). **3.4 browse-beacon COMPLETE.**
  Browser render-timing (when checkout_start/complete fire) is a manual pilot
  check, NOT live-walk-covered (CLAUDE.md + below).

### Done — 3.5 backfill (complete, live-walked + ZIP'd)

- **3.5 backfill** — traverse EXISTING WC records into the engine (the live
  hooks only ingest CHANGES). One ingest path, two triggers: backfill enqueues
  into the SAME IngestQueue + AbstractD6Flusher the hooks use (DECISIONS F3-25).
  **3.5.0 DONE** (base + infra + catalog): `AbstractBackfillJob` (cursor/state/
  AS-tick/progress, resumable `WHERE id > cursor`) + `CatalogBackfillJob`
  (products → catalog.upsert, variation fan-out mirrors the hook). Enqueue +
  **inline-flush per batch** (decision (b)): progress = SENT, queue bounded. No
  freshness marker (decision (i), UPSERT-idempotent). Generalised the shared
  infra: `BackfillJobInterface` (legacy contacts BackfillJob implements it too),
  `BackfillEndpoint` SUPPORTED += products + `target_for()` (rec_engine vs
  smaily, coexist under the (job_type,target) UNIQUE key — no schema change),
  `Bootstrap::make_backfill_job()` (single dispatch for endpoint + AS tick,
  contacts gate removed), `backfill.ts` union += products. Tests prove
  resumability (resumes from cursor, not restart) + bounded queue. ci:strict
  exit=0; integration OK 56 (+5 backfill).
  **3.5.1 DONE** (customers): `CustomerBackfillJob` — `WHERE ID > cursor` on
  wp_users → customer.upsert, CustomerFlusher inline-flush. **A-filter (F3-20)
  consistent with CustomerHookHandler**: every registered user, NO role/email
  filter — the consistency is the ABSENCE of a predicate (both unfiltered), so
  neither side sends a different cohort. Test proves a subscriber/editor (non-
  customer role) is backfilled, plus resumability + bounded. Wired:
  make_backfill_job 'customers', SUPPORTED += customers, backfill.ts union.
  ci:strict exit=0; integration OK 60 (+4).
  **3.5.2 DONE** (orders, HPOS-aware): `OrderBackfillJob` — direct
  `WHERE id > cursor` against the active order table (`wc_orders` HPOS /
  `wp_posts` legacy, detected via OrderUtil; `wc_get_orders` only offers
  offset/paged, which shifts under inserts → would break the cursor). **Status
  filter matches the hook**: enumerates only mapped (sale) statuses via SQL
  `status IN (...)`, using `OrderPayloadBuilder::mapped_wc_statuses()` as the
  single source (CC-9 — can't drift from map_status). Progress denominator =
  mapped orders, not all. Test storage split: **wp-env runs WC 10.7 + HPOS, so
  the HPOS path is integration-tested; the legacy path (the pilot's WC 6.9.4
  mode) is unit-tested via the pure `table_spec` — structurally identical but
  not run against real wp_posts orders here** (CLAUDE.md "OrderBackfill"). Tests:
  resumability + bounded + status-filter (unmapped excluded) + full. ci:strict
  exit=0; integration OK 64 (+4 order backfill). **3.5.0-.2 backend complete.**
  **3.5.3a DONE** (admin UI, JS-only): reusable `BackfillPanel` (Import-now
  button + ProgressBar + status, mirrors Step2's contacts panel) — instantiates
  the already-generic `useBackfillProgress({jobType})`; progress lives in the
  hook (no reducer mirror — only contacts feeds the Step6 summary). Three panels
  (products/customers/orders, each disabled at 0 records via
  `state.env.storeTotals`) in a new "Import existing data" Card inside
  Step4Recommendations `ConnectedView` (gated on the rec-engine connection, not
  the Smaily-email one). API + hook needed no changes (3.5.0-.2 wired the job
  types). 3 vitest tests. ci:strict exit=0.
  **3.5.3b DONE** (live-walk + ZIP): `bin/walk-3.5-backfill.cjs` — **7/7 against
  the real MiuMjau engine**, all three backfill domains. Proven live: products
  + customers backfill reach **100%** (processed == total); the **order status
  filter on real HPOS data** (wp-env is WC 10.7 + HPOS) — 4 mapped of 5 orders,
  the pending one excluded (total=4); **multi-batch resumability** against the
  real engine (order job driven at batch 2 → 3 batches, cursor monotonic, never
  restarts); and **bounded queue** (pending empty after every inline flush). ZIP
  includes the new admin BackfillPanel + the storefront beacon. **3.5 backfill
  COMPLETE.** NB: the live-walk runs the HPOS order path; the LEGACY path (the
  pilot's WC 6.9.4 mode) remains unit-tested only — a pilot go-live precondition
  (above + CLAUDE.md).

### Done — 3.7 identity-merge (complete, live-walked + ZIP'd)

- **3.7 identity-merge** — bind an anonymous browse session to a known customer
  on login (§7). NOT a customer↔customer merge (the roadmap one-liner was wrong;
  v1 has no such thing — DECISIONS F3-27). Complementary to the engine's
  automatic browse-event retroactive binding (§6): covers "logs in but generates
  no email-carrying browse event after". **3.7.0 DONE**: `Client::merge_identity`
  (single §7 object, not a batch) + `IdentityHookHandler` (server-side `wp_login`
  → reads the anon-session/visitor-token cookies from $_COOKIE → posts the merge;
  api_key stays server-side, no new proxy). Dedup via user meta
  (`_smaily_rec_merged_anon_sid` — repeat logins same session don't re-hit the
  engine; a new session re-merges). 404 customer_not_found → log + skip
  (retroactive binding is the safety net). **Checkout trigger deferred** — NOT
  redundant (order ingest only stores attribution, doesn't bind history) but the
  guest's customer is auto-created by the async order ingest, absent at checkout
  → would 404; login timing is sound (A-filter ingested the user already). JS
  `mergeIdentity` stub kept (M2 platform-agnostic). Mock merge route + unit
  (Client) + 6 integration tests. ci:strict exit=0; integration OK 70 (+6).
  **3.7.1 DONE** (live-walk + ZIP): `bin/walk-3.7-identity.cjs` — **6/6 against
  the real MiuMjau engine**. Proven live: explicit merge binds an anon session
  (`browse_events_updated=2`); idempotent on repeat (`updated=0` — no
  double-binding; the engine returns `already_bound=0` on a pure repeat, an
  informational field the plugin never consumes); and the distinction from
  retroactive binding — after a browse event with the email retroactively binds
  (`retroactive_bound=2`, 3.4.4 behaviour reconfirmed), the merge is a no-op
  (`updated=0`); plus the 404 path (unknown customer → `customer_not_found`).
  ZIP'd. **3.7 identity-merge COMPLETE.**

### In progress

- **3.8 GDPR** — rec-engine personal-data rights via the WP Privacy API. Scope
  authority: `docs/DATA_MODEL_GDPR.md` (referenced, not re-derived). DECISIONS
  F3-28. **3.8.0 DONE**: `Client::customer_export` (§8 GET) / `customer_delete`
  (§9 DELETE) / `customer_opt_out` (§10 POST) + `GdprHandler` registering a WP
  Privacy **exporter** (Art 15) + **eraser** (Art 17). Export is conservative
  (engine browse_events/visitor_tokens/recommendations/email_events + customer
  record MINUS decision-logic fields like segment/RFM/engagement + plugin
  `_smaily_*` rec-meta; NOT Woo orders/totals — Woo's exporter owns that; NOT
  rec_attribution — silent). Erase is complete (engine §9 CASCADE incl.
  attribution; 404=already-gone=success; + plugin meta removed). **HPOS-safe**:
  order meta via `$order->get_meta`/`delete_meta_data` (NOT get_post_meta — would
  miss wc_orders_meta under HPOS; caught by PHPStan, a real bug). Opt-out = the
  §10 Client method only (the Smaily profiling-consent trigger + beacon two-gate
  stop is a separate later piece). Mock §8/§9/§10 routes + 3 Client unit tests +
  5 integration (incl. the WC-boundary test: `_smaily_rec_id` exported,
  `total_amount`/`line_total` NOT). **3.8.1 DONE** (live-walk + ZIP):
  `bin/walk-3.8-gdpr.cjs` — **10/10 against MiuMjau**: export surfaces engine
  browse-activity + the order `_smaily_rec_id` read from **real `wc_orders_meta`
  (HPOS)**, excludes Woo totals + decision fields + rec_attribution; opt-out
  toggles true→false; erase removes engine records + the HPOS order-meta; a
  second erase is 404-idempotent-success. The walk **caught a latent 3.8.0 bug**:
  the GDPR endpoint URLs use a `{email}` path placeholder but `Client` substituted
  via `sprintf`/`%s`, sending the literal `{email}` to the engine (404). Unit +
  mock endpoints maps had mirrored the wrong `%s`, hiding it through all green
  gates. Fixed to `str_replace('{email}',…)` (fallback templates → `{email}` too);
  mock/unit maps switched to `{email}` + the mock customer routes now **422 on a
  literal-placeholder email** so a regression fails integration. LESSONS §2.9.
  ci:strict exit=0; unit 285; integration OK 75. ZIP'd. **3.8 GDPR COMPLETE.**

### Done — 3.9 Step-4 activation (complete)

- **3.9** Step-4 activation — connect ⇒ sync all (system-decides). The four
  per-domain sync toggles (orders/customers/products/cart) were cosmetic
  (write-only options, no consumer — ingest always gated on `is_connected()`
  alone) and are **removed** from UI + types/reducers/hydrate + the POST writes;
  dead keys cleaned idempotently in `Activation::cleanup_removed_rec_feature_options()`.
  **Browse-tracking is the only Step-4 toggle** (legal-consent gate, opt-in
  default-off). **Disconnect** clears only the `smly_rec_*` connection options and
  preserves `smly_plus_rec_track_browsing`, so **re-connect restores the toggle** —
  enabled by the **mandatory hydration fix** (`EnvDetector::rec_engine_snapshot()`
  emits the saved value independent of connection; `hydrate.ts` reads it, no longer
  hardcoding `false` — also fixes a plain-reload blanking bug). PLUGIN.md
  §Step-4-4a/§6 + §15-test-5 revised; DECISIONS F3-29; README row. ci:strict exit=0
  (unit 285, JS 134); integration OK. **Phase 3 feature work done.**

### Done — 3.10.0 pilot-hardening: Event Log visibility (Layer 1)

- **3.10.0** — the diagnostics-visibility layer (production-readiness audit P2).
  New `EventsEndpoint` (`/events` + `/events/detail`) = a read-only **Event Log**
  (PLUGIN.md §13) UNION-ing both durable queues (`smly_rec_event_queue` +
  `smly_plus_event_queue`) with source/status/type filters, pagination, drill-down
  payload, and a **failed-in-24h count** for the sticky banner. No schema change —
  the queues already carry status/attempts/last_error/created_at. New React
  **Event Log** Settings tab (table + filters + drill-down modal + sticky banner),
  always available (read-only, no Save/Discard). **Backfill progress fixed** to
  report engine-confirmed `sent` + terminal `failed` (read-time count of the job's
  event-types since `started_at`) instead of records *walked* — kills the
  "1400/1400 while rows failed" lie; the panel now shows "N synced" + a failed
  notice pointing to the Event Log. Watch-item confirmed: `last_error` carries the
  HTTP code (`http_4xx`/`http_5xx`, `d6_item_error`), so 3.10.1's auto-transient
  retry can classify 4xx-vs-5xx for free. Gates: ci:strict exit=0 (unit 285, JS
  140 +6); integration OK 82 (+7, `RecEngineEventsTest`).

### Done — P6 RSS feed URL builder in Integrations (2026-06-12)

- **P6** — pilot-prep finding: the old 1.6 plugin's RSS settings tab had no
  home in the new UI. The FEED never broke (legacy `Rss` class registers
  rewrite + template whenever WC is active; all params live in the URL's query
  string — pilot's existing template URLs keep working). The tab vanished as a
  side-effect of the 2.H.3 legacy-menu hide. Rebuilt as `RssFeedSection` on the
  **Integrations** step/tab (wizard Step 5 + Settings, same component),
  **client-side + stateless** — no save path, Integrations stays info-only.
  `EnvDetector::rss_snapshot()` emits base URL (permalink-aware) + product
  categories + legacy-option prefill; null hides the section when WC inactive.
  URL builder mirrors legacy admin.js byte-for-byte. DECISIONS F3-34.
  Gates: ci:strict exit=0; integration +2 (`RssBootSnapshotTest` pins the
  legacy-classes-loaded seam the unit suite must fake). Follow-up same day:
  README.md "What's new in 2.0" + readme.txt 2.0 feature list + CHANGELOG
  gained the RSS-builder line (user-facing feature, worth surfacing); fresh
  pilot ZIP cut.

### Done — P7 upstream auto-update clobber guard: 2.1.0-beta.1 + Update URI (2026-06-12)

- **P7** — release-prep surfaced a **pilot-blocker-class risk**: upstream
  shipped its own **2.0.0** to wordpress.org (2026-06-03, same
  `smaily-connect` slug; verified live). Fork at `2.0.0-beta.1` < 2.0.0 →
  WP would offer (or with auto-updates, silently apply) upstream's 1.x-line
  package OVER the fork mid-pilot. Fix (DECISIONS **F3-35**): (1)
  **`Update URI` header** — core skips w.org updates for the plugin entirely
  (WP 5.8+; primary guard, stays until upstream merge); (2) **renumbered
  2.0.0-beta.1 → 2.1.0-beta.1** (Erkki's call) across all version literals
  (header, PHP constants, Stable tag, package.json+lock, test bootstraps,
  ConstantsTest, CHANGELOG version-note, README, MIGRATION pointer fix).
  UPSTREAM_AUDIT #128 carries the find. First GitHub **pre-release**
  (v2.1.0-beta.1-rc.1) with the pilot ZIP attached — README's Releases
  install link now resolves. NOTE: pilot ZIPs from before this fix
  (≤ db4e1cd) are vulnerable if installed on a site with auto-updates on.

### Done (code+gates) / pending (live-walks) — P8 pilot day-1: SkuResolver (2026-06-12)

- **P8 / F3-36** — pilot connect surfaced that the store has **zero SKUs** and
  old orders reference deleted products. Three surfaces were broken, catalog
  SILENTLY (pre-enqueue drop → engine never saw the store; LESSONS §2.11).
  Fix: `Support\SkuResolver` — real SKU else synthetic `wc-{id}` — used by
  CatalogPayloadBuilder (expand no longer filters; HookHandler guards
  removed), OrderPayloadBuilder, StorefrontBeacon (sku always present).
  OrderFlusher terminal-skips empty-`items[]` orders (3rd skip case).
  Deleted products: WC ZEROES the items' product reference on permanent
  deletion (empirical, WC 10.7 — initial id-survives assumption was wrong,
  the new integration test caught it) → all-deleted orders terminal-skip
  cleanly; id-survives data keys wc-{id} (unit-covered). Mock orders route
  now enforces items min-1 (the divergence that hid this). Version →
  2.1.0-beta.2.
- **Live-walks GREEN (run by Erkki, 2026-06-12 ~15:45): 3.2 catalog 15/15
  (incl. the NEW over-64-char lock-proof lever — live engine D6-rejected it,
  split held: sent 1 / failed 1), 3.3-orders 12/12, 3.4-browse 13/13.**
  Engine-write permission stays human-gated (the agent classifier correctly
  refuses agent-self-granted permission rules; walks run via `!` or a
  user-added rule).
- Also in beta.2: health-notice placement fix — `wp-header-end` marker in the
  admin wrapper, so WP relocates notices above the React app instead of into
  the React header next to the tabs (Erkki's screenshot find).
- GH pre-release **v2.1.0-beta.2-rc.1** published (build `e145607`, ZIP
  attached, deploy steps in the release notes). Supersedes beta.1-rc.1 for
  the pilot.
- Pilot redeploy steps: install the beta.2 ZIP → **catalog backfill re-run**
  (fills the silently-empty catalog) → Event Log **Retry all failed** (flusher
  rebuilds payloads fresh at flush; SKU-less orders heal in place, deleted-
  product orders leave the queue as clean skips).
- NB: wp-env carries a LIVE MiuMjau connection. An integration-suite run
  scrubs it (EnvScrub) — snapshot/restore the `smly_rec_*` options around the
  suite (done once already this way).

### Done — P9 pilot day-1 #2: abandoned-cart backlog guard (F3-37, 2026-06-12)

- **Incident:** mass abandoned-cart emails to customers minutes after the 2.x
  install. **Sender was CartBounty Pro** (third-party plugin on the pilot
  site; `cartbounty-pro` in the email links, no such email in the Smaily
  account) — most plausibly its backlog drained when the plugin swap revived
  the site's dead WP-Cron. NOT our pipeline — but ours has the identical
  flaw and is ENABLED in the pilot DB (real 1.6-era option, wizard displayed
  it honestly), one working autoresponder away from the same flood.
- **Fix:** 24h backlog guard (filterable) on `cart_updated` (epoch compare —
  the Z-form vs MySQL-format string-compare seam is a trap) + per-cart
  log-and-continue instead of abort-unmarked. `AbandonedCartGuardTest`
  (integration; fixture builds the cart table via real Lifecycle DDL).
- **Pilot actions:** (1) decide ONE abandoned-cart system — CartBounty Pro
  was already doing it; if it stays, turn OUR toggle OFF (Settings →
  WooCommerce); double reminders otherwise. (2) Confirm attribution
  on-site: CartBounty's email log timestamps vs install time.

### Done — engine-team go-live sync (2026-06-12 evening; results in docs/ENGINE_TEAM_PILOT_SYNC_RESULTS.md)

- **Tenant correction:** MiuMjau IS the pilot production tenant (no separate
  dev tenant exists). Today's walks ran against production; engine purged the
  residue. **Future walks: "Smaily Connect test" sandbox ONLY** (CLAUDE.md
  updated). Related incident, root-caused engine-side: the wp-env token
  exchange (~12:08 UTC) ROTATED the tenant's single API key → the live pilot
  store's key was silently revoked mid-day; engine migration 0036 (per-
  connection keys) fixes the class.
- **Results:** contract md5 MATCH; orders ✅ (2345 events/24h, dedup holds, the
  6.9% non-catalog item SKUs = deleted-product lines — NB this also proves the
  pilot's WC 6.9.4 does NOT zero item ids on product delete, unlike WC 10.7,
  so the resolver's id-survives path is the live one there); catalog 🟡 5783
  rows and growing (5201 wc-* + ~580 real SKUs — store is MIXED, not uniformly
  SKU-less); browse 🟡 no real traffic yet; engine logs clean post-deploy.
- **Engine fixed two of THEIR catalog-ingest bugs today** (intra-batch SKU
  dedup; emoji-split in description truncation) — the 91% error rate the
  backfill retries hit was engine-side, gone after their 14:24 UTC deploy.
- **Open items (Erkki / pilot admin):** (1) pilot store: verify connection
  alive (the key-rotation window!) — reconnect if Step 4 shows disconnected;
  (2) Event Log → Retry all failed AGAIN (pre-14:24 engine-500 rows now
  succeed; 401 rows from the key window too); (3) compare engine catalog
  count vs store product+variation count; (4) enable/verify browse tracking
  (off by default, consent-gated) — engine sees zero real browse traffic;
  (5) joint GDPR round-trip with an Erkki-issued API key; (6) sandbox setup
  token for wp-env so dev work leaves the production tenant.
- SPEC_DRAFT_BROWSE_ABANDONED_CART: engine answered all 5 open questions
  (cron sweep; 2h–24h window; 1/7d cap; custom-field trigger path; Smaily
  consent authoritative; NO qty needed → v1 needs zero plugin changes).
  Stays 🟡 on both backlogs.

### In progress — catalog-correctness series (CC.1–CC.4, 2026-06-13)

Engine brief `docs/PLUGIN_BRIEF_catalog_correctness.md` (+ design
`docs/MULTILINGUAL_DESIGN.md`, contract sync `RECENGINE_API_CONTRACT.md` §3
multilingual / §4 `language` / §620-624 catalog identity): the MiuMjau pilot
sync emitted **one catalog row per language translation** (WPML/Polylang store
each translation as its own `wp_posts` row) → duplicate synthetic SKUs the
engine can't dedupe → language-mixed recommendations; plus non-products (gift
cards, donation, language-switcher pseudo-product) reached the catalog.

**MiuMjau's actual plugin = WPML + WooCommerce Multilingual (WCML)** (Erkki
confirmed, 2026-06-13 — the brief said "Polylang/WPML" generically; the store is
WPML). `DetectorFactory` picks `WPMLAdapter` via `ICL_SITEPRESS_VERSION`. WCML
registers `product_variation` as translatable, so `wpml_object_id` (hence
`get_canonical_post_id`) resolves variations across languages **automatically** —
no attribute-matching layer needed despite MiuMjau having variable products.

**Engine-coordinated go-live order (engine team, 2026-06-13):** the canonical
SKU must cover the WHOLE SKU graph, not just catalog — **catalog AND order
items** (else the reload leaves a catalog↔orders mismatch). Plan: (1) plugin
canonical scheme to production (catalog + orders both); (2) engine WIPES the
MiuMjau SKU graph — catalog + orders/order_items + recommendations +
cadence_curves_customer + co_purchase_edges + browse_events (NOT customers /
email_events — those are email-keyed and just backfilled 30k events); (3) plugin
full re-backfill — catalog + order history (+ `{lang:value}` + customers.language);
(4) engine nightly recompute + clean re-seed. **No surgical orphan purge** — the
full wipe+re-sync supersedes the §624 manual-purge note.

**Erkki decision (2026-06-13): localization model = (B) `{lang:value}` object.**
Rationale: the expensive part (P1 translation-collapse to a canonical product
with a stable SKU) is shared by A and B; the engine is fully ready for B
(per-customer localization via `customers.language`); single-language stores
degrade gracefully to A (one-key object). Sequence CC.1 → CC.2 → CC.3 with a
checkpoint between each; CC.4 last (blocked — see below).

- **CC.1 DONE (2026-06-13)** — adapter primitive for canonical resolution.
  `DetectorInterface` gains `get_default_language()` + `get_canonical_post_id(int)`;
  implemented across all 4 adapters (Polylang `pll_default_language` +
  `pll_get_post`; WPML `wpml_default_language` + `wpml_object_id`; TranslatePress
  / SiteLocale = passthrough, one record per product). Fallback everywhere:
  unresolvable canonical → return input (**never DROP a product**). No runtime
  path calls it yet — **behaviour unchanged.** New `PolylangAdapterTest` (covers
  the real `wc-59221 LV → wc-59199 ET` shampoo case) + SiteLocale +2. Gates:
  ci:strict exit=0 (331 unit / JS 156), integration OK 108. **Behaviour-neutral.**
- **CC.2 DONE (2026-06-13)** — canonical SKU across the WHOLE graph + catalog
  enumeration collapse (P1 + P4). Scope grew from "catalog only" to "catalog +
  orders + browse" per the engine's whole-SKU-graph correction.
  - **SkuResolver is now canonical-aware** (`resolve` / `resolve_order_item` gain
    an optional `?DetectorInterface`, default lazy `DetectorFactory::create()`):
    a synthetic key collapses its id to the canonical post (`wc-{canonical_id}`);
    a real SKU is the merchant's key, untouched. Because all THREE wire surfaces
    go through SkuResolver (`CatalogPayloadBuilder:95`, `OrderPayloadBuilder:183-4`,
    `StorefrontBeacon:148`), this one change canonicalizes catalog + order items
    + browse with **zero call-site churn** — orders/browse get it for free.
  - **Catalog enumeration collapse**: backfill `enqueue_record` SKIPS a
    translation whose canonical is itself an enumerated published product
    (stateless skip-if-not-self; `processed_count` still counts every post so
    progress reaches 100%, `sent` is lower = the collapse); the live hook
    `on_save/on_stock` re-syncs the canonical; **P4** delete re-syncs the
    canonical on a translation delete (≠ marking the SKU gone), deletes only on
    the canonical's own removal. Never a silent drop (draft-canonical → the
    published post stands in; LESSONS §2.11).
  - **Variations**: WCML links `product_variation` → `get_canonical_post_id`
    resolves them automatically; no special code.
  - Detector injected into CatalogHookHandler + CatalogBackfillJob (Bootstrap
    `multilingual_detector()`); SkuResolver lazy-loads the same factory instance.
  - Tests: SkuResolver +4 (canonical/order-item), CatalogHookHandler +3
    (collapse/P4), `WPMLAdapterTest` +6 (pilot-relevant adapter), integration +2
    (real-queue collapse + draft-canonical-no-drop, stub detector; live-hook
    isolation via queue truncate; mock now records `last_catalog_skus`). Gates:
    ci:strict exit=0 (344 unit / 156 JS), integration OK 110.
  - **Still single-language content** until CC.3 — CC.2 fixes keys + collapse;
    `{lang:value}` payload is CC.3.
- **CC.3 DONE (code+gates+live-walk) (2026-06-14)** — model B
  `{lang:value}` payload. `CatalogPayloadBuilder` takes an optional
  `DetectorInterface` (Bootstrap injects it; lazy factory default).
  `build()` calls `get_translations()` once: **array form → `{lang:value}`**
  for name/description/product_url; **string form (single-language) → the
  product's own scalar fields** (model A, unchanged behaviour). description is
  tag-stripped + **clamped to 500 chars PER LANGUAGE**; empty per-language
  entries dropped; an all-empty REQUIRED field falls back to the scalar so it's
  never sent empty. SkuResolver gets the same detector for the canonical SKU.
  Tests: builder +4 (object form / per-lang clamp / empty-drop / scalar
  fallback), integration +1 (`{lang:value}` name survives the real Client→mock
  JSON round-trip; mock now records `last_catalog_names`). Gates: ci:strict
  exit=0 (348 unit / 156 JS), integration OK 111.
  **CC-8 live-walk DONE (2026-06-14)** — `bin/walk-cc3-multilingual.cjs`, **7/7
  against the "Smaily Connect test" SANDBOX**: the real engine's strict Zod
  **accepts the `{lang:value}` object** form of name/description/product_url
  (processed=1, errors=[]) AND the single-language string form (model A). A stub
  detector feeds the REAL CatalogPayloadBuilder, so it emits the same wire bytes
  the WPML/Polylang path would — the engine can't tell the source, so the wire
  contract is proven regardless of i18n plugin (no need to configure Polylang in
  wp-env). Test SKUs `LIVE-CC3-*` → excluded by the engine's `recommendable`
  flag. **NB: the dev wp-env is now connected to the SANDBOX tenant** (was
  MiuMjau-production — switched via the new token; this is the correct/safe
  state per CLAUDE.md, do not point dev at MiuMjau).
- **CC.4 — DONE: structural signal, not a filter (DECISIONS F3-38, 2026-06-14).**
  No plugin-side non-product filter (business-model decision a connector can't
  make safely; the engine's `recommendable` flag owns exclusion). Engine team
  CONFIRMED the division (commit 37a8f66) + is already consuming the signal
  (contract §3, migration 0040, `classifyRecommendable`). `CatalogPayloadBuilder`
  now always emits three top-level fields: `product_type`
  (`WC_Product::get_type()`, incl. gift-card plugins' custom types — the robust
  non-product signal), `is_virtual`, `is_downloadable` (stored, never
  auto-excluding). Builder +2 unit tests; **live-walk 9/9** (the gift-card
  `product_type: pw-gift-card` send is accepted, `processed=1 errors=[]`). Gates:
  ci:strict exit=0 (350 unit / 156 JS). Two engine return-questions answered in
  `docs/ENGINE_TEAM_recommendable_signal.md` (language-switcher `wc-49143` is NOT
  removed by CC.1–3 — needs its product_type / a post-49143 inspection to decide
  a targeted drop; MiuMjau's gift-card type string — Erkki to confirm or
  self-heal on re-backfill). **Ship the signal with the canonical re-backfill**
  so the post-reload catalog classifies on the first pass.

- **CC.5 — catalog.delete skips never-published artifacts (DECISIONS F3-39,
  2026-06-14).** Pilot Event Log showed a burst of failed `catalog.delete` rows
  (`d6_item_error field=category_path` / `product_url: String must contain at
  least 1 character(s)`) — WordPress's daily auto-draft GC purging `AUTO-DRAFT`
  products fired `before_delete_post` → `catalog.delete` with empty REQUIRED
  fields the engine 400s. Root cause: the backfill is `publish`-only but the
  delete hook filtered nothing (LESSONS §2.12). Fix: `CatalogHookHandler::
  enqueue_delete()` skips a removal whose object has blank `category_path` or
  `product_url` (`removable()` helper). Delete-only by design — the upsert path
  still surfaces an empty `category_path` on a *published* product as an intended
  merchant-data-gap signal. +2 unit tests. Pre-existing failed rows are cleared
  manually (retry can't fix them).

Already done (no work): **P2b `customers.language`** — `CustomerPayloadBuilder`
already sends ISO 639-1 from `get_user_locale()`.

- **F3-40 — trashed products kept in catalog as `in_stock=false` (2026-06-17,
  engine brief Teema 2).** ~4% of pilot order lines had no `catalog.sku` match;
  Erkki traced them to the WordPress **trash**. Trashing fires no catalog hook
  (`before_delete_post` is permanent-delete-only) and the backfill was
  `publish`-only, so trashed-but-bought products orphan the join. Fix (A+B):
  `Bootstrap` binds `wp_trash_post → on_delete_product` + `untrashed_post →
  on_save_product`; `CatalogBackfillJob` enumerates `publish`+`trash`, sending
  trashed products as `in_stock=false` (kept for the join/training; engine has no
  delete-by-key, so `catalog.delete` ≡ `in_stock=false` upsert). `is_removable`
  → shared static (reuses the F3-39 guard on both paths). Guarded a real clobber
  bug: `wp_trash_post()` also fires `save_post_product`, which re-upserted
  `in_stock=true` → `on_save_product` now skips a `trash`-status save (caught by
  the new integration test). Permanently-deleted products stay unrecoverable
  (no WC data). Gates: ci:strict exit=0 (unit 359, JS 158); integration OK 113.
  Released **`v2.1.0-beta.7-rc.1`** (GH pre-release, build `bd8b9cb`, ZIP attached);
  pilot needs a catalog re-backfill after install.
  (Brief Teema 1 = client fixes order statuses WC-side, no plugin change; Teema 3
  browse = pilot config now corrected, watching engine traffic.)
  **Live-walk 7/7 (2026-06-19, `bin/walk-f3-40-trash.cjs`, sandbox)** — closes the
  mock-only gap F3-40 shipped with (it had only ci:strict + mock-integration; per
  CLAUDE.md a catalog wire-shape change isn't done until live-walked). Proven against
  the real engine through the real code: trash → exactly one `catalog.delete` and NO
  upsert (the clobber guard live: `delete=1 upsert=0`), the engine **ACCEPTS
  `in_stock=false`** (`processed=1 errors=[]`); untrash → `catalog.upsert`, engine
  accepts `in_stock=true`; the backfill's trashed-product branch
  (`enqueue_record` → `enqueue_unavailable`) → `catalog.delete` the engine accepts.
  This also confirms the 2026-06-18 pilot log errors were **pre-existing orphan rows
  cleared on retry**, not a wire-shape bug in the fix (the happy path is engine-clean).
  (Not live-covered, by design: the `is_removable` skip of a category-less trashed
  product — WC auto-assigns "Uncategorized", fragile live — is unit-tested.)

### Done — Faas 2: legacy admin settings-page removed (F3-45, 2026-06-19)

Constraint (Erkki): **must not break the ~2000 existing installs** — only remove what's
unneeded under the new plugin or trivially replaceable with 100% functionality preserved.
A per-file audit BEFORE deleting found the legacy `Admin` class is NOT pure views:
- **Removed** (redundant + non-navigable): `admin/smaily-admin.class.php`,
  `smaily-admin-{settings,renderer,sanitizer}.class.php`, the `smaily-admin-*.php`
  settings partials, `admin/css|js/smaily-admin.*`, the credentials hook (a REST no-op),
  and the now-moot hide-legacy-menu shim.
- **Relocated** into `smaily.class.php::init_classes` (merchants lose nothing): the
  subscription widget (`widgets_init`) + the Plugins-page Settings link (→ the new UI).
- **Kept** (live dependents): `Notices` + `Notice_Registry` + `partials/notices/*` (the
  1.3.0 CF7 upgrade notice still calls `Notice_Registry::add_notice`!), `Widget`, the WC
  integrations, `Cypher`, `Options`, `Rss`, `Cart`, CF7 / Elementor.
- **No config stranded:** the new `SettingsEndpoint` reads + writes the SAME legacy option
  keys (credentials / subscriber-sync / abandoned-cart) the old page used, and the kept
  integrations read those keys. Fixed a stale `@param Admin` doc in `smaily-api.class.php`.
- Gates: **ci:strict exit=0 (unit 377, JS 158); integration OK 114** (plugin boots with the
  legacy admin gone). No wire change → no live-walk. DECISIONS F3-45.

### Done — Event Log stores the real request + engine response (Problem 3 / F3-44, 2026-06-19)

**Engine brief 2026-06-19, Problem 3:** the Event Log "Details" showed `Payload: []` —
order/catalog rows enqueue an empty payload (built fresh at send, F3-8) and only a short
`last_error` was kept, so "what did we send / what did the engine reply?" was
un-answerable, and a terminal-skip read a bare "sent" with no trace it never POSTed. Fix
(BOTH queues, per Erkki):
- **Migration 007** adds `sent_payload` + `last_response` (nullable LONGTEXT) to
  `smly_rec_event_queue` AND `smly_plus_event_queue`; a new `store_exchange()` on each
  queue writes them (separate from `mark_*` → no churn to existing overrides).
- **Rec-engine:** `AbstractD6Flusher` (single choke point) records per row
  accepted / rejected{error} / http_error; a terminal-skip → `sent_payload=null,
  last_response={outcome:"skipped"}` (exposes the silent "sent").
- **Smaily:** `Client::last_exchange()` captured in the `request()` chokepoint; the
  `Flusher` reads it via try/finally (captured even when the call throws) and stores it.
- **Never stores the Authorization header**; all rows incl. success; ~10 KB trim;
  janitor-pruned. Details modal now shows **Request sent** + **Engine response**.
- Gates: **ci:strict exit=0 (unit 377, JS 158); integration OK 114**. No wire-contract
  change (stored locally) → no live-walk needed. DECISIONS F3-44; CLAUDE.md note.

### Done — Order sync correctness: custom statuses + deleted-product lines (F3-42/F3-43, 2026-06-19)

**Engine brief 2026-06-19 (order #58922):** a guest order with a DELETED product was
marked "sent" but **never reached the engine** (no POST). Read-only investigation
cross-checked the brief against our docs/contract; two real data-loss fixes, one
already-solved (not rebuilt), one deferred:
- **F3-43 (P1, the #58922 cause):** `OrderPayloadBuilder::items()` DROPPED a line whose
  deleted product had zeroed ids → empty `items[]` → `OrderFlusher` terminal-skip →
  `mark_sent` WITHOUT POSTing (silent loss). Fix: `SkuResolver::resolve_order_item()`
  never returns '' — a zeroed-id line keys on the order-item id (`wc-oi-{item_id}`), so a
  product line is never dropped and the order is never lost. Reverses F3-36's
  drop-for-deleted; the empty-items terminal-skip now only guards a genuinely
  product-less order (shipping/fee only).
- **F3-42 (status mapping):** custom WC statuses (`label-printed`/`shipped`/…) were
  silently dropped by the 5-key allowlist (the order never reached the engine — the
  earlier Teema 1). Flipped to a DENYLIST: custom statuses default through as
  `processing`; the backfill mirrors via `status NOT IN (non_sale_wc_statuses())` (CC-9).
  **on-hold → non-sale** (reverses F3-22, per the engine team — payment not captured;
  sent when it moves to processing/completed).
- **Already solved (NOT rebuilt):** the engine's `200 {errors:[…]}` per-item →
  `mark_failed` path (F3-18 / AbstractD6Flusher). #58922 bypassed it via the terminal-skip
  `mark_sent`; the F3-43 fix makes the order POST so the existing D6 path handles any
  rejection.
- **Deferred (separate follow-up):** the brief's Problem 3 — store the real request
  payload + HTTP response in the Event Log Details (order/catalog rows enqueue an empty
  payload by design → `Payload: []`). Schema + flusher + admin-UI; not in this sub-PR.
- Gates: **ci:strict exit=0 (unit, JS 158); integration OK 113; live-walk 7/7**
  (`bin/walk-f3-43-orders.cjs`, sandbox — a custom-status order → engine accepts as
  `processing`; a deleted-product order on WC 10.7 (zeroes the ids) →
  `items:[{sku:"wc-oi-…"}]` → engine accepts). DECISIONS F3-42/F3-43; CLAUDE.md
  order-status note. Engine-side: the team will log per-item rejects to an
  `import_errors` table; a WC-completed-vs-engine data audit is suggested for other
  silently-missing orders.

### Done — Settings plugin-link opens the new UI, not the legacy view (Task 2 Faas 1, 2026-06-19)

- Pilot-reported bug: the "Settings" action link on the Plugins list opened the
  OLD module's setup view. Root cause: legacy `Smaily_Connect\Admin`
  (`admin/smaily-admin.class.php:386`) linked to `admin.php?page=smaily-connect`
  (its `$this->plugin_name` slug). That legacy menu is hidden (`remove_menu_page`,
  `Bootstrap.php:232`) but its page ROUTE still renders the legacy settings view.
  Fix: repoint to `admin.php?page=smaily-connect-settings` (the new Settings page,
  `admin/wizard.php`), which self-redirects to the wizard when
  `smly_plus_setup_completed` is false (wizard-first gate) — correct in both
  states. PHPCS exit=0; ci:strict exit=0 (unit 359, JS 158).
- **Faas 1 of the legacy cleanup.** **Faas 2 is now DONE (F3-45, below).**

### Done — engine ask #1: attribute term labels (2026-06-12 late)

- Engine's PROMPT_woo_plugin_team.md (in docs/, committed) ask #1 fixed:
  `raw_attributes` now carries term LABELS — taxonomy options (term ids) via
  `wc_get_product_terms(fields=names)`, variation slugs via `get_term_by`;
  custom attributes pass through. Unit tests rewritten (the old fake had
  already-string options — LESSONS §2.4 shape, which is how the id leak
  shipped) + a REAL WC_Product_Attribute integration test. Gates green
  (323 unit / 107 integration). **Ships in the next rc (with tomorrow's
  P10 + variation-stock hook); after deploy the pilot needs ANOTHER catalog
  backfill re-run so existing rows pick up labels.**
- NB: wp-env is now DISCONNECTED by design — the integration run scrubbed
  the MiuMjau (production!) connection and it was deliberately not restored;
  next connection = sandbox token (engine corrections doc).
- Remaining engine asks: #2 retry-queue terminal report (Erkki/pilot DB when
  queue empty), #3 browse beacon sends nothing — needs pilot-side check
  (consent gate? toggle? JS loading?), #4 catalog count compare when
  backfill done, #5 ask merchant about `live-test-cat` (104 products).

### Next — pilot-hardening sequence (in order)

**Pilot-blockers (must close before pilot), in order:**
- [x] **P5** — version-floor reconciliation (WC 6.9 / WP 6.2→6.6 / PHP 8.0; WP
  tested 7.0 restored after a context-dimming slip, LESSONS §2.10).
- [x] **3.10.0** — Event Log visibility (Layer 1, above).
- [x] **3.10.1** — failed-row recovery (Layer 2, P1): `IngestQueue::reset_failed()`
  + `EventQueue::reset_failed()` (FAILED→PENDING, reset attempts/retry-park/error) +
  `POST /events/retry` (single row / all-in-a-queue / all-in-both) that kicks the
  flushers for a prompt re-send + "Retry" (per failed row) / "Retry all failed"
  (banner) buttons in the Event Log. **Manual-only** by design (auto-retry would
  loop on a deterministic 4xx; the `http_NNN` classification is recorded for a
  future guarded auto-transient pass). ci:strict exit=0 (unit 285, JS 144);
  integration OK 85 (+3, `RecEngineEventsTest` retry cases).
- [x] **3.10.2** — proactive admin-notice (Layer 3 base, §13a): `NotificationManager`
  + a 15-min recurring health-check that raises a sticky `notice-error` on **three
  signals covering both of the pilot's sync paths**: (a) failed events > 50 in 24h
  across both queues (filterable threshold); (b) the **rec-engine** unreachable > 1h;
  (c) the **Smaily** API unreachable > 1h (contacts + email automations) — both
  down signals use the same time-based `down_since` + periodic ping, gated so an
  unconfigured store isn't reported "down". Auto-clears when the condition resolves;
  **dismissible with a 24h cooldown** (nonce'd admin-post link, no per-page nag, no
  JS). No email — that's 3.10.3, post-pilot. Pure `evaluate_signals` (10 unit tests);
  `RecEngineHealthCheckTest` (2 integration). ci:strict exit=0 (unit 295, JS 144);
  integration OK 87.
- [x] **P4** — pilot/merchant onboarding doc: `docs/INSTALL.md` (merchant-facing —
  install → setup wizard → verify → troubleshoot, integrating the 3.10.x Event Log
  / Retry / health-notice flows; documents only the *current* browser-cookie consent
  + browse toggle, NOT profiling-consent which ships with (a)). The formal
  pilot-acceptance plan (`TESTING.md`, business pass/fail) is a separate follow-up —
  it needs Erkki's acceptance criteria.

> **Consolidated deferred index: `BACKLOG.md`** — the canonical single view of
> all deferred work (priority + why + location). The sections below remain for
> narrative continuity; `BACKLOG.md` is the list to triage from.

**Post-pilot (deferred):**
- **3.10.3** — email channel (§13a email level + Notifications subpanel) via
  `wp_mail` (admin-notice base already covers proactive-in-wp-admin; email needs
  working server SMTP — recommend an SMTP plugin in the doc).
- ~~Queue janitor (prune `sent`/`failed` rows + index `created_at`)~~ — DONE
  2026-06-11, FABLE_AUDIT fix F6 / DECISIONS F3-33 (pulled forward pre-pilot:
  daily AS prune, sent 30d / failed 90d filterable, pending never; migration
  006 `idx_created_at`). (~~GCM encryption~~ — DONE 2026-06-11, FABLE_AUDIT
  fix F3 / DECISIONS F3-32: Cypher v2 GCM + upgrade re-encryption.)
- ~~**WP 7.0 env-matrix verification**~~ — **RESOLVED 2026-06-11**, with a
  twist: instead of a two-env matrix, Erkki moved the integration BASELINE to
  WP 7.0 (`.wp-env.json` core → `wordpress.org/wordpress-7.0.zip`; the WP 6.9.4
  baseline was an interim step). **Full suite 99/99 green on WP 7.0** (WC 10.7 +
  Polylang). One real 7.0 finding: the heavier core exhausted the 128M phpunit
  memory limit during in-process REST dispatch → the runner now passes
  `-d memory_limit=512M`. The PILOT still runs the old stack — the
  pilot-faithful override recipe (WC 6.9.4 + PHP 8.1) is documented in
  CLAUDE.md ("Integration baseline is WP 7.0…").

**After pilot-hardening — (a) Smaily profiling-consent wiring (in progress):**
Spec: `SMAILY_PROFILING_CONSENT_SPEC.md`; design: DECISIONS F3-31 (OPT-OUT model,
default-on). Sub-PRs:
- [x] **(a).0** — probe-first + Client read/write + enforcement core. A live probe
  against the real Smaily API confirmed write (`upsert_subscribers`, custom fields
  auto-create → `101`) + read-back (`GET /api/contact.php?email=` → `is_unsubscribed`
  + `smaily_rec_profiling`); caught that the spec had *assumed* read-back (the one
  real risk). Built `Client::get_contact_consent` + `write_profiling_consent` +
  `ProfilingConsent` (pure opt-out rule `is_allowed`, cached read-back daily TTL,
  WP opt-out → Smaily write + cache + engine §10 opt-out, fail-open). 12 unit tests.
- [x] **(a).1** — beacon two-gate + retroactive-bind respect. The `BeaconEndpoint`
  proxy now drops browse events carrying an opted-out contact's email before
  forwarding (anon events — no email — pass on the cookie gate alone); all-dropped
  → `processed:0` without calling the engine. Drop is a **conscious drop** (opt-out
  working, not an error): aggregated into a 24h counter (`smly_profiling_dropped_24h`,
  for a future surface) + logged **once per batch** (never per event → no flood).
  Plus: `IdentityHookHandler` **skips `identity.merge` for an opted-out contact** —
  so their anon browse history is NOT retroactively bound to their profile (respect
  the opt-out backwards; the anon events stay unattributed). Wired via
  `Bootstrap::profiling_consent()`. Tests: +2 beacon (drop one / all-dropped) + 1
  identity (no retroactive bind). ci:strict exit=0 (unit 307, JS 144); integration
  OK 90 (+3).
- [x] **(a).2** — WP opt-out UX + live-walk. **WP UX:** `ProfilingConsentAccount`
  adds a **WooCommerce My Account → privacy toggle** ("use my data for personalised
  recommendations") — shopper-facing per spec §10, the working opt-out the model
  requires. The toggle's state mirrors the read-back (shows a Smaily-side opt-out
  too); checked→`opt_in`, unchecked→`opt_out`. **Live-walk** `bin/walk-a-profiling.cjs`
  against real Smaily + the engine, via the WIRED code: **9/10** — write→read-back
  round-trip ✓, enforcement rule ✓, may_profile ✓, opt-out→Smaily=0 ✓, **beacon-stop
  (drop) ✓**, opt-in restore ✓. The **§10 step is env-blocked**: the dev connection
  is the stale integration fixture (`re-fixture.test`, unreachable), not real
  MiuMjau — re-run after a real setup-token re-exchange; §10 itself is already
  3.8-live-walked (10/10) + integration-proven. 3 account unit tests. ci:strict
  exit=0 (unit 310); integration OK 90.
- **TODO** — explicit opt-in if AKI tightens (`is_allowed()` is invertible); privacy
  policy must mention profiling (Erkki / docs).

---

## 🎯 PLUGIN-SIDE PILOT-FEATURE-COMPLETE (2026-06-09)

All plugin-side feature + hardening work for the pilot is done: Phase 3
(catalog/customers/orders/browse ingest + backfill + identity-merge + GDPR +
Step-4 activation), pilot-hardening (P5 version floors, 3.10.0–3.10.2 Event Log /
Retry / health notices, P4 `INSTALL.md`, legacy-WC verification), and (a) Smaily
profiling-consent ((a).0 enforcement + (a).1 beacon two-gate + (a).2 opt-out UX +
live-walk). Feature-complete ZIP cut at this commit.

**What remains before pilot go-live (NOT plugin feature code):**
- **Erkki / business:** ~~`TESTING.md` pilot-acceptance plan~~ — **DONE 2026-06-11**:
  `docs/TESTING.md` written from Erkki's input (two gating dimensions — technical
  stability + merchant experience; business metrics tracked-not-gated; logistics:
  4–6 wk, real data from pilot start, twice-weekly→weekly check-ins, go/no-go at
  pilot end). Remaining business items: the (a) TODOs above (profiling opt-in if
  AKI tightens; privacy-policy profiling mention; the fail-open GDPR-window review).
- **Optional:** §10 profiling live-walk re-run (proven by 3.8 + integration —
  belt-and-suspenders only; needs a fresh real-MiuMjau token).
- **Engine team:** the engine-side frontend debug views (Customers/Orders browse)
  — see Engine side below.
- **Manual / pilot verification (not machine-testable):** the browse render-moment
  (page-view fires on the right page), and live consent gating (CookieYes actually
  suppresses the beacon). See "Known deferred items".
- ~~**Pre-pilot pin:** the WP 7.0 env-matrix verification~~ — DONE 2026-06-11
  (baseline moved to WP 7.0, suite 99/99; see Post-pilot section).

### Waiting / lock conditions

- **catalog-flusher N-7 D6 consolidation — RESOLVED (N-7.1, 2026-06-06).** The
  catalog flusher now extends `AbstractD6Flusher`; an engine per-item rejection
  marks that row FAILED, not SENT (silent-loss class closed). Proven against the
  real engine by the catalog live-walk (`flusher_d6_split_lock_proof`: sent:1,
  failed:1). No remaining lock conditions on the plugin side. (DECISIONS F3-22.)

### Roadmap (Phase 3 remaining)

- ~~**3.4** browse-beacon~~ — DONE (above). NB §14.2: the engine consumes browse
  post-MVP — pilot expectation is "collects data, improves recommendations
  later, not now".
- ~~**3.5** backfill~~ — DONE (above): catalog/customers/orders backfill,
  cursor-resumable, inline-flush bounded, live-walked 7/7. (Legacy order path =
  pilot precondition.)
- ~~**3.6** beacon~~ — REMOVED (not a separate sub-PR). The README feature table
  split "Browse ingest" + "Beacon (browse tracking)" as two items; 3.4
  browse-beacon shipped BOTH (3.4.0 = Client::ingest_browse + /beacon proxy =
  browse ingest; 3.4.1-.3 = the client beacon track/flush/cookies/consent/WC
  events = beacon tracking). So "3.6 beacon" duplicated 3.4. (A storefront
  recommendation-render widget is a separate FUTURE epic — never numbered here.)
- ~~**3.7** identity-merge~~ — DONE (above): anon-session → known-customer
  binding on login (NOT a customer↔customer merge — v1 has none); live-walked
  6/6. (DECISIONS F3-27.)
- ~~**3.8** GDPR (WP Privacy API)~~ — DONE (above): exporter (Art 15) + eraser
  (Art 17) + opt-out (§10), HPOS-safe order-meta; live-walked 10/10. The 3.8.1
  walk caught a latent `{email}`-placeholder substitution bug (DECISIONS F3-28.6,
  LESSONS §2.9).
- ~~**3.9** Step-4 activation~~ — DONE (above): connect ⇒ sync all
  (system-decides); per-domain sync toggles removed, browse-tracking the only
  Step-4 toggle (consent-gated, preserved across disconnect/re-connect via the
  mandatory hydration fix). DECISIONS F3-29. **Phase 3 feature work complete.**

---

## Pilot go-live — both sides must be ready

Pilot does NOT go live until all of these hold. No deadline pressure (D5).

**Plugin side:**
- [x] catalog-end ZIP'd + live-walked
- [x] customers-end ZIP'd + live-walked
- [x] orders-end ZIP'd + live-walked (12/12)
- [x] catalog-flusher N-7 D6-fix (lock RESOLVED — N-7.1, catalog live-walk 15/15)
- [x] **order-backfill LEGACY path verified against a real legacy WC env**
  (RESOLVED 2026-06-09). Stood up a `.wp-env.override.json` pinning **WC 6.9.4 +
  PHP 8.1** (WP core 6.9.4); reset the carried-over HPOS options so
  `is_hpos()=false`, `wc_orders` absent → a faithful WC 6.9.4 legacy store
  (orders in `wp_posts`). `RecEngineOrderBackfillTest` ran the legacy
  `table_spec(false)` path — `WHERE post_type='shop_order' AND post_status IN(…)
  AND ID > cursor` against the real WC 6.x posts schema (4 tests, 14 assertions),
  and the **FULL integration suite passed 75/75 on legacy** (no other path has a
  hidden HPOS assumption). PHP pin to 8.1 was used (WC 6.9.4 on PHP 8.3 risks
  deprecations); `OrderBackfillJob::is_hpos()` is correctly guarded with
  `class_exists(OrderUtil)` so it can't fatal on a pre-HPOS WC. (Harness note: the
  mock-server teardown uses the `SIGTERM` constant, undefined without `pcntl` on
  the PHP 8.1 image → the documented exit-255 wrapper quirk; tests pass.)

**Engine side:**
- [x] backend (90-95%, gaps engine-internal)
- [ ] **frontend debug views** — Customers browse + Orders browse (at minimum)
  functional, so a pilot problem ("X didn't sync") can be seen in the UI rather
  than debugged DB-direct. Engine team building UI-first.

A working backend the team can't see into is debug-blindness in pilot. Both
sides ready = go-live.

---

## Known deferred items (tracked, not blocking)

> Consolidated with priority in `BACKLOG.md` (🟢 / 🔵 tiers).

- N-7 EVENT_* constant location asymmetry (catalog `EVENT_CATALOG_*` on
  CatalogHookHandler, customer/order on their Flusher) — still asymmetric after
  N-7. N-7 chose an **abstract base** (`AbstractD6Flusher`), NOT a monolithic
  dispatcher, so each flusher keeps its own constants/hook/group; the "unify under
  a dispatcher" premise no longer applies. Cosmetic only — defer or drop.
- Flaky useBackfillProgress test (fake-timers race) — fix with deterministic
  timer mocking.
- **Browse browser-timing — manual pilot verification (not live-walk-covered).**
  The 3.4 live-walk proves the engine contract (proxy→engine + abuse + all 9
  types) but a server-side walk can't observe the browser MOMENT a page-view
  fires (checkout_start on the checkout page, checkout_complete on
  order-received, product_view on a product page). The PHP page-type detection
  (`StorefrontBeacon::page_context`) can't be driven in the integration harness
  (no `WP_UnitTestCase`/`go_to()`); JS mapping is vitest-tested. So confirm the
  render moment manually during the pilot (or a future Chromium E2E — not built,
  YAGNI, low risk). See CLAUDE.md "Browse browser-timing".
- GDPR export omits rec_attribution — engine-side Art 15 legal review (not a
  contract issue for the plugin).
- F3-19 guest-customer flusher concern: RESOLVED by W5 — engine auto-creates the
  customer from the order's customer_email; OrderFlusher is order_id-based (guest
  orders have an order_id), so no payload-carried path needed.

---

## Future / backlog (not scheduled)

Feature ideas worth keeping, distinct from "Known deferred items" above (those
are tracked technical debt). These are NOT scheduled — build only when a real
need arrives (YAGNI).

- **Consent-bridge extension (future).** The beacon supports non-WP-Consent-API
  consent plugins (Cookiebot, custom) today via the **escape-hatch** — the
  `smaily_connect_beacon_consent` PHP filter + `window.smailyConnectBeacon.consentOverride`
  JS override — a developer-level adapter that requires writing code. Future: a
  **user-level consent-bridge**, modelled on the existing plugin-integration pages
  (Elementor, Contact Form), so a non-technical client on a non-WP-Consent-API
  plugin can map their cookie-consent signal without code — e.g. a guide, a
  "select your consent plugin" activation, or a settings panel that maps an
  incompatible plugin's consent state. MiuMjau (CookieYes) does NOT need this
  (WP-Consent-API-native); this is for future clients on Cookiebot or custom
  solutions. The escape-hatch covers it technically in the meantime. Build only
  when a real Cookiebot/custom client lands.
