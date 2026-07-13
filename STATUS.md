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

_Last updated: 2026-07-13 (**PRO-1337 DONE — uninstall.php now sweeps
`smly_rec_*` (rec-engine connection state), closing the gap PRO-1336 flagged.**
Approved design: full irrecoverable local delete, no engine-side revoke call.
Three additions, all mirroring `uninstall.php`'s existing `smly_plus_*`
conventions rather than a new one: (1) a `LIKE 'smly_rec_%'` option sweep —
catches all 9 `Settings\RecEngineSettings` options (api_key, base_url, tenant
id/name, endpoints, config, connected, issued_at) plus
`NotificationManager::OPTION_DOWN_SINCE` (`smly_rec_health_down_since`, a
`smly_rec_*` option that lives outside RecEngineSettings) — prefix sweep
chosen over an explicit key list (mirrors `smly_plus_%`) so a future
`smly_rec_*` option needs no new uninstall.php line; (2) the Action Scheduler
purge gained an `OR hook LIKE 'smly_rec_%'` clause — the four rec-engine
recurring flush hooks (`smly_rec_flush_ingest/_customers/_orders/
_catalog_remove`, `Bootstrap.php`) were previously never unscheduled, so
they'd keep firing on a class that no longer exists after an uninstall; (3)
the custom-tables list (`smly_rec_event_queue`, `smly_rec_visitor`) was
**already correct pre-PRO-1337** — verified against the migrations, no
duplicate/change made there. `docs/ARCHITECTURE.md` §7 corrected in the same
commit (it already claimed "uninstall.php removes all of it" — now true,
with the engine-side-revoke note added: the api_key stays valid on the engine
until rotated/revoked in the engine admin, per-connection keys since engine
migration 0036 so no other store is affected, and a re-install needs a fresh
setup token). `tests/Unit/UninstallCleanupTest.php` extended with two new
source-level pins (reflection against `RecEngineSettings`/`NotificationManager`
option constants and the four Flusher/IngestQueue `FLUSH_HOOK` constants) —
`uninstall.php` itself is still never executed by the suite (destructive,
same rationale as PRO-1336). Gates: `ci:strict` exit=0 (phpcs 0 errors/phpstan
no errors/unit 563 tests incl. +2 new/lint/typecheck/vitest 236); integration
148 OK (`sg docker`, sandbox tenant `Smaily Connect test` snapshot/restored
cleanly, `smly_rec_*` unaffected by the suite run itself since uninstall.php
isn't executed). Prior:
**PRO-1338 DONE — merchant docs site
(`docs/site/index.html`) now documents the `[smaily_connect_newsletter_form]`
shortcode**, in both EN/ET. Added a "Shortcode" subsection under
Settings → Integrations (alongside the existing newsletter-block/CF7/Elementor
copy): what it renders, an attributes table (`success_url`, `failure_url`,
`show_name`, `autoresponder_id` — names/defaults read straight from
`Public_Base::smaily_shortcode_render()`'s `shortcode_atts()` call and the
`smaily-public-basic.php` partial), a copy-paste example, and the
theme-override note (`smaily/smaily-public-basic.php` via `locate_template()`).
Plus a one-line pointer bullet in the Step 5 wizard summary. **Limitation
documented, no code change:** unlike the CF7/Widget/Elementor/Gutenberg
dropdowns (PRO-1277/PRO-1334), `autoresponder_id` here is hand-typed with no
list to validate against — a workflow later disabled/deleted in Smaily leaves
the form rendering and submitting normally with no automation firing, and
nothing in WordPress flags it. Verified structurally (no browser available
here): Python `html.parser` tag-balance walk over the whole file reports zero
mismatches/unclosed tags; `data-lang="en"`/`"et"` block counts (121/119) are
unchanged from HEAD — this change added zero new `data-lang` blocks, only
content inside existing paired blocks. Docs-only change; `ci:strict` not run
(nothing else touched). Prior:
**PRO-1334 DONE — preserve-and-flag (PRO-1277)
extended to the classic Widget, Elementor widget, and Gutenberg block
autoresponder dropdowns.** Classic Widget (`includes/smaily-widget.class.php`)
mirrors CF7 exactly, reusing `Helper::is_autoresponder_unavailable()` unchanged:
a saved-but-now-disabled `autoresponder_id` gets a selected, labeled
"Workflow #N (disabled in Smaily)" option plus the same warning notice text
(reused verbatim — no new string needed there). Gutenberg block
(`blocks/newsletter-signup/src/edit.js`) does the equivalent purely
client-side: `autoresponders` (already the filtered enabled list from
`/smaily/v1/autoresponders`) plus the saved `autoresponderId` attribute are
enough to detect unavailability in the editor, so the REST endpoint
(`includes/smaily-api.class.php`) needed NO change — reuses the same two
msgids via `sprintf`/`__` from `@wordpress/i18n` (already flowed into the .pot,
confirmed: unlike the admin TS bundle, `wp i18n make-pot` parses this block's
plain `.js` directly, no esbuild-transpile workaround needed). Elementor
(`integrations/elementor/newsletter-widget.class.php`) got a **reduced,
non-dynamic fix, and here's why**: Elementor's SELECT `options` are a
per-widget-TYPE schema cached once via `Controls_Manager::get_element_stack()`
and shared across every instance/document — confirmed via Elementor's own
source + developer docs, including a filed core issue where reading a
widget's own raw settings during `register_controls()` is documented to
error ("widget doesn't exist on stage"). There is no reliable per-instance
hook to inject a flagged option there without either a sitewide postmeta scan
or client-side JS overrides of Elementor's own panel — disproportionate
machinery for a dropdown cosmetic gap. Separately, the CF7/Widget *mechanical*
silent-wipe risk doesn't actually apply to Elementor: its settings are a
persistent client-side model, not re-derived from the rendered `<select>`'s
value on save, so an untouched saved id survives a save regardless. Shipped
instead: one additional sentence appended (not replacing) the control's
existing, already-translated `description`, telling the merchant a missing
entry means "disabled in Smaily, your selection is kept" rather than "gone."
Tests: no new Helper logic was added (Widget/block both reuse PRO-1277's
already-tested `filter_enabled_autoresponders()` / `is_autoresponder_unavailable()`
unchanged), so no new unit tests; `LegacyHelperAutorespondersTest.php` still
covers the shared logic. One new PHP string (the Elementor description
addendum) added to `.pot`/`-et.po` (ET translated) via `bin/build-i18n.sh`;
the two reused strings' locations were merged in, translations intact
(spot-checked: msgstr count 523→524, empty-msgstr count unchanged at 1). No
merchant-docs-site change (re-verified: still doesn't document per-row
dropdown filtering, per PRO-1277's own conclusion). Gates: `ci:strict` exit=0
(phpcs 0 errors/phpstan no errors/unit 561 tests unchanged/lint/typecheck/
vitest 236 unchanged); `composer run build` — all blocks incl.
newsletter-signup compile clean; integration 148 OK (`sg docker`, sandbox
tenant `Smaily Connect test` snapshot/restored cleanly). **Human acceptance
needed (not verifiable here):** visually confirm the flagged option +
Estonian translation actually render in the WP Widgets screen, the Gutenberg
block inspector, and — for the Elementor description text — the Elementor
panel; none of the three has an Elementor instance available in this
environment to render against. Prior:
**PRO-1336 DONE — uninstall.php now removes the
PRO-1194 profiling-consent state.** The durable opt-out registry option
(`smly_profiling_optouts`, autoload=false, hashed-email keys) and its two
per-contact transients (`smly_profiling_<hash>` daily-TTL fresh cache,
`smly_profiling_stale_<hash>` no-expiry stale cache — both from
`ProfilingConsent`, commit 31a8c0d) were not covered by `uninstall.php`'s
existing cleanup: its LIKE-prefix sweep only matches `smly_plus_*`, and its
explicit legacy-options list is `smaily_connect_*`-scoped — neither shape
matched `smly_profiling_*`. Fix follows the file's own two established
conventions rather than inventing a third: the single known option key
(`smly_profiling_optouts`) joins the explicit `$legacy_options` array
(delete_option + the existing per-key cache-flush loop already iterates it);
the two per-contact, unbounded-count transients get a new LIKE-prefix sweep
(`_transient_smly_profiling_%` / `_transient_timeout_smly_profiling_%`),
mirroring the existing `smly_plus_%` sweep — the stale-cache prefix nests
inside the fresh-cache prefix so one pair of LIKEs catches both. No existing
test executes uninstall.php (it DROPs tables + bulk-deletes options, which
`tests/Integration/Support/EnvScrub.php`'s own docblock calls out as too
destructive to run inside the shared test process — that's why EnvScrub
exists as a non-destructive sibling instead of piggybacking on the real
file); added `tests/Unit/UninstallCleanupTest.php` instead, a cheap
source-level pin against `ProfilingConsent`'s actual constants (via
Reflection) so a future prefix rename or a stripped cleanup line fails
loudly without executing the destructive script. Gates: `ci:strict` exit=0
(phpcs 0 errors/phpstan no errors/unit 561 tests, +2 new/lint/typecheck/
vitest 236); integration 148 OK (`sg docker`, sandbox tenant `Smaily Connect
test` snapshot/restored cleanly — unaffected, this change doesn't touch
`smly_rec_*`). **Related finding, NOT fixed here (scope was PRO-1336 only):**
`uninstall.php`'s LIKE sweep also does not cover `smly_rec_*` (the rec-engine
connection: API key, tenant, endpoints map — `Settings\RecEngineSettings`),
despite `docs/ARCHITECTURE.md` §7 claiming uninstall "removes all of it
(options, tables, AS actions)" — that doc line is stale/inaccurate for the
options piece. Left as a follow-up (separate scope, pre-existing, not
introduced by this change) — worth its own Linear issue given it's a
real data-retention gap on uninstall. Prior:
**PRO-1194 fail-open GDPR window — hardened
(serve-stale-on-error + durable opt-out registry).** Design approved by Erkki;
implements options B+C from the `docs/DATA_MODEL_GDPR.md` fail-open review
(commit 47897a0). `ProfilingConsent` (`includes/Privacy/ProfilingConsent.php`)
now resolves a profiling decision through four layers instead of one: (1) the
existing fresh per-email transient (1-day TTL, unchanged); (2) a new **durable
opt-out registry** — a single `smly_profiling_optouts` option (autoload=false,
keyed by hashed email, opt-outs only) that a read error can never override,
cleared only by a later successful engine read-back showing opt-in; (3) a new
**stale cache** — a second per-email transient with no TTL, holding the last
successfully fetched answer, served on a read error instead of defaulting to
allowed; (4) true fail-open, now residual — only fires for a contact the
plugin has never resolved either way (never-seen + engine down). Every
successful read (and every WP-side `opt_out()`/`opt_in()`) writes all three
stores together via a new `remember()` helper. This is a privacy-POSITIVE-only
change — it never allows more profiling than before; it only closes cases
where the old code defaulted to "allowed" and now correctly denies. No
user-visible change (no merchant-docs-site edit — verified the site doesn't
describe the fail-open window). `docs/DATA_MODEL_GDPR.md`'s fail-open review
section is updated in the same commit: status flipped from "DRAFT" to
"IMPLEMENTED 2026-07-13", the B/C alternatives-table rows marked implemented,
and a new "Implemented behavior" matrix documents the four layers. Tests:
`tests/Unit/Privacy/ProfilingConsentTest.php` gained 4 new cases (stale served
on error, durable opt-out wins over an error, a successful opt-in read clears
the durable entry, an opt-out read persists it durably) plus stub updates to
4 existing cases (`get_option`/`update_option` now touched by every
success-path write). No integration test added — `ProfilingConsent` had none
to extend. Gates: `ci:strict` exit=0 (phpcs 0 errors/phpstan no errors/unit
559 tests incl. ProfilingConsentTest's 10 methods expanding to 16 tests via
the `is_allowed` data provider (24 assertions)/lint/typecheck/vitest 236);
integration 148 OK (`sg docker`, sandbox tenant `Smaily Connect test`
snapshot/restored cleanly). PRO-1194 overall **stays OPEN** — legal sign-off (entity name, URL,
lawful-basis framing) still pending; only the fail-open hardening sub-item is
done. Prior:
**PRO-1277 DONE — legacy Autoresponder dropdown
(CF7 / Elementor / Gutenberg newsletter block) stops offering `is_enabled=false`
Smaily workflows.** `Helper::get_autoresponders_list()` (`workflows.php?
trigger_type=form_submitted`, `includes/smaily-helper.class.php`) shapes rows
through the new `Helper::filter_enabled_autoresponders()`, which drops any row
whose `is_enabled` coerces false (`FILTER_VALIDATE_BOOLEAN`, so a bool/int/string
"false"/"0" wire value is all read the same way — a row missing the key is kept,
since we can't classify it). This is the single fetch point behind all four
consumers (CF7 admin tab, the classic Widget, the Elementor widget, the Gutenberg
block's `/smaily/v1/autoresponders` REST route) so the fix applies uniformly.
Preserve-and-flag (same pattern as Magento's PRO-1268): in the CF7 admin tab, if
a form's saved `autoresponder_id` is no longer in the filtered enabled list
(`Helper::is_autoresponder_unavailable()`), the dropdown keeps it as a selectable,
flagged option ("Workflow #N (disabled in Smaily)") plus a warning notice, instead
of silently reverting the binding to "No autoresponder" on the next save. Only CF7
got the flag treatment — the classic Widget has the identical native-`<select>`
shape and risk but wasn't touched (follow-up below); Elementor/Gutenberg use their
own control rendering and weren't in scope. Tests: `tests/Unit/
LegacyHelperAutorespondersTest.php` (16 cases: bool/int/string `is_enabled` wire
shapes, junk/incomplete rows, missing-key kept, the 4 preserve-and-flag states).
`languages/smaily-connect.pot` + `-et.po` gained the two new CF7-partial strings
(ET translated). No merchant-docs-site change — the site doesn't document
per-row dropdown filtering or a disabled-workflow marker. Gates: `ci:strict`
exit=0 (phpcs/phpstan/unit 555/lint/typecheck/vitest 236); integration 148 OK
(`sg docker`, sandbox tenant `Smaily Connect test` snapshot/restored cleanly).
Follow-up filed: extend preserve-and-flag to the classic Widget
(`includes/smaily-widget.class.php`) — same helper, same bug shape, not done
here. Prior:
**PRO-1194 retention finalized —
`docs/DATA_MODEL_GDPR.md` merchant privacy-policy template.** Engine team answered
the retention question + Erkki decided the orders/customers wording (2026-07-12):
browse events (incl. `smaily_visitor_token` rows) and visitor-token↔customer
bindings — 90-day TTL from creation, engine daily `cleanup-expired-data` cron
hard-deletes; order & customer ingest rows — **no fixed calendar period**, retained
for the duration of the merchant relationship, individual control is the Art 17
erase (`DELETE /api/v1/customer/{email}`), natural upper bound is merchant
offboarding (engine-side tenant purge — tracked as an engine-backlog follow-up, not
yet built); recommendations 730 days from issue (pending never aged out early),
rec_attribution 730 days, email_events 365 days, decision_log 30 days
(engine-internal, not a customer-facing element in the inventory). The
`[CONFIRM WITH ENGINE TEAM]` retention placeholder in BOTH template languages
(EN+ET, content-identical siblings) is replaced with concrete plain-language
retention text; the template↔code fact map gained a row and the open-placeholders
list marks item 3 RESOLVED. Other placeholders (Smaily legal entity name, Smaily
privacy-policy URL, merchant-legal-review caveat, lawful-basis framing) untouched;
the fail-open GDPR window review section untouched (it had no retention
cross-reference); nothing published to the merchant docs site. **PRO-1194 stays
OPEN** — legal sign-off (entity name, URL, lawful-basis framing) still pending.
Docs-only, no code change. Prior:
**Contract re-synced byte-identical (engine `945b7ad`, md5
`3dbe029b…`) — PRO-1279.** Doc-only delta since our `2dec424` sync (v1.4.0 → v1.4.1,
PATCH bump): §3 `tags` example gains `"product_id": "7620134"`, and the identity bullet
now states cross-variant grouping by `tags.product_id` is **live** (engine PRO-1227,
was "future" in v1.3.0); §3 also gains an explicit Magento-only carve-out (Magento's
catalog `sku` field IS its platform-canonical key — does not apply to Shopify/Woo).
CC-8 conformance verified: no wire-shape change for Woo — `CatalogPayloadBuilder::tags()`
already emits `tags.product_id` via `SkuResolver::product_group_id()` since PRO-1224/
PRO-1230, pinned by `CatalogPayloadBuilderTest` and mirrored in the mock router. No code
change, no live-walk needed (prose clarify + an already-shipped field's example gains
a value). Gates: `bin/check-contract-staleness.sh` green. Prior:
**PRO-1258 DONE — `EndpointRegistry::expected_routes()`
now lists the `/events` triple** (GET `/events`, GET `/events/detail`, POST
`/events/retry`) — the PRO-1197 follow-up below. The route surface is confirmed
against every `register_rest_route()` call in `includes/REST/`: 16
method+path pairs total, matching `docs/API.md` §1 exactly (the legacy
`smaily/*` namespace in `smaily-api.class.php` is out of this list's scope on
purpose). `RestRouteRegistrationTest` now pins all 16 against the live
`rest_get_server()`, and `EndpointRegistryTest` asserts every pair plus
`assertCount(16)` so a route can't silently drop out again. Code-only test-coverage
fix, no wire/behavior change. Gates: ci:strict exit=0 + integration suite green
via the wrapper. Prior:
**PRO-1195 DONE — abandoned cart REWRITTEN onto the
namespaced pipeline; legacy pass RETIRED. Landed on main, UNRELEASED** (rides a
later cut AFTER the 2026-07-14 MiuMjau window — no version bump, readme.txt
untouched). Erkki-approved design: `CartHookHandler` (WC cart hooks; **guest
carts included** — session-token rows; identity = logged-in user / session
billing email / checkout-entered email via classic
`checkout_update_order_review` + Store API `cart_update_customer_from_request`;
a cart syncs only once an email is known) → new `smly_plus_cart_session`
tracker (migration 009; own scalar JSON `[{product_id, variation_id,
quantity}]`, never `serialize(get_cart())` — the F3-53 poison class is
structurally gone) → `CartAbandonmentSweeper` on the EXISTING 15-min
`smly_plus_abandoned_cart` AS tick (same cutoff option; F3-37 backlog guard
carried over, same filter name; expiry/prune housekeeping runs even while
gated) → `automation.abandoned_cart` rows in the Smaily EventQueue
(`pending()` grew only/exclude event-type scoping; the main Flusher excludes
the cart type) → new `CartFlusher` on its own AS action
`smly_plus_flush_cart_events` (60 s): F3-54 router-first → legacy
`autoresponder_id` fallback (force_opt_in=false) → observable terminal skip;
a non-101 fallback body code is terminal FAILED (Event-Log-retryable, never an
eternal loop); F3-44 exchange stored per row (never the Authorization header);
the Events retry endpoint kicks the cart flush hook too. Wire fields keep
EXACT legacy template parity (`is_abandoned_cart`, store/names, prefilled
`product_<field>_1..10`, `over_10_products`); language via
ContactLanguageResolver only (`for_user` + new `for_guest()`), omit-on-empty.
**Upgrade continuity (definition of done) proven:** the new code reads the
SAME options (normalized status incl. the carried-over autoresponder_id,
cutoff, fields — zero reconfiguration); one-time READ-ONLY `LegacyCartDrain`
on `Activation::run` (stamp `smly_plus_cart_legacy_drained`) migrates
`mail_sent IS NULL` legacy rows with their ORIGINAL `cart_updated` (recent →
reminds via the new pipeline; stale → F3-37 expiry without emailing; poison
rows logged+skipped with a per-row Throwable backstop; schedules NOTHING per
F3-53); the legacy table is NOT dropped (rollback-safe; drop = a later
one-way door). Retirement: legacy `Cart` tracker + the Cron abandoned-cart
add_actions deregistered (methods kept for the upstream diff) so a stray
surviving legacy WP-Cron event finds nothing to fire; Bootstrap's tick no
longer bridges the legacy hook names. Tests: +36 unit
(CartFlusher/CartAbandonmentSweeper/CartPayloadBuilder/CartHookHandler +
EventQueue scoping + main-Flusher exclusion); integration reworked — new
`CartPipelineTest` (logged-in E2E to the mocked Smaily transport incl. F3-44
exchange asserts, guest checkout-email capture, legacy-autoresponder fallback
carry-over, backlog guard, order-clears, Bootstrap hook registration) + new
`LegacyCartDrainTest` (both F3-53 poison classes, read-only + one-time +
original-timestamp semantics, drained-recent-reminds vs stale-expires);
`AbandonedCartGuardTest` retired WITH the pass it drove (its bug classes
re-pinned against the new code), `AbandonedCartSettingsSeamTest` re-pointed at
the new consumers, `LegacyCronScheduleTest` extended (cart callbacks
uninvocable). Gates: **ci:strict exit=0** (unit 539, vitest 236, PHPCS 0
errors, PHPStan clean) + **integration FULL OK (148 tests, 756 assertions)**
via the wrapper (sandbox connection auto-restored, tenant verified
'Smaily Connect test'). NO live Smaily/engine traffic. Merchant docs site
UNCHANGED — behavioral parity; every abandoned-cart statement on it stays true
(guest coverage extends, contradicts nothing). Docs same commit: DECISIONS
PRO-1195 (+ F3-37/F3-53/F3-54 pointer notes), CLAUDE.md coexistence map +
scar-note rewrite, ARCHITECTURE/API dev-doc tables refreshed (new AS jobs +
table + retired hook pair), BACKLOG row closed. Prior:
**PRO-1197 — developer docs written: `docs/ARCHITECTURE.md`,
`docs/DEVELOPER.md`, `docs/API.md`** (docs-only). The three long-TODO developer-facing
docs now exist, written from the actual repo (EndpointRegistry route surface incl. the
`/events` triple + the public `/relay` defense layers; all 11 `smaily_connect_*` filters
enumerated from code; the `window.smailyConnectBeacon` boot shape from
`StorefrontBeacon::beacon_config()`/`page_context()`; the AS job table from
`Bootstrap::register_action_scheduler_jobs()`; custom tables from `migrations/`).
They LINK to the deep docs (contract, DATA_MODEL_GDPR, DECISIONS, CLAUDE.md) instead
of duplicating them. `FAQ.md`/`TROUBLESHOOTING.md` stays **deliberately deferred**
until pilot support traffic supplies real symptom→cause→fix questions (recorded in
INDEX.md; the PRO-1197 issue stays open for that part). docs/INDEX.md rows moved
TODO→Written in the same commit. Gate: ci:strict as the docs-only sanity gate.
Noticed, not fixed at the time: `EndpointRegistry::expected_routes()` did not list
the three `/events` routes the registry registers — the route-registration test
under-covered them (FIXED as PRO-1258, see the entry above). Prior:
**PRO-1256 DONE — shared `smly_rec_*` snapshot/restore
guard + `--restore-only`** (dev tooling, follow-up to PRO-1240). The guard logic
moved out of `bin/run-integration-tests.sh` into `bin/lib-smly-snapshot.sh` —
sourced by the wrapper (NO behavior change: EXIT-trap restore,
fixture/production/empty never clobbers a good snapshot, secret-safe STDIN
restore, tenant_name verification with loud MiuMjau/fixture warnings, guard
problems never fail the run) and also executable (`snapshot`/`restore`
subcommands, always exit 0; the pre/post decision now persists via a
pending-decision file so it survives across processes). Walk scripts opt in
via the Node wrapper `bin/lib-smly-snapshot.cjs` — `guardSmlyRec()` snapshots
up front and restores on process exit (crash/SIGINT included); wired into
`bin/walk-3.1.cjs`, the ONLY existing walk that writes/deletes `smly_rec_*`
connection options (the others only read the connection or truncate the
queue table — decision recorded in CLAUDE.md; any future connection-writing
walk must call it). New `bash bin/run-integration-tests.sh --restore-only`
restores the dev connection from the durable snapshot without running the
suite (non-secret output only; exit 3 when no usable snapshot). Proof:
integration suite via the wrapper **OK (146 tests, 721 assertions)** with
restore verified `tenant_name='Smaily Connect test'`; `--restore-only`
demonstrated (real restore, 9 options, tenant verified; exit-3 path with the
snapshot absent); standalone `snapshot`→`restore` round-trip green; NO
live-walk run (host-side tooling only). ci:strict exit=0. Docs same commit:
CLAUDE.md (filtered-runs + live-walk + TENANT-scoped notes now point at the
shared lib + `--restore-only`), LESSONS §2.17 addendum. Prior:
**PRO-1194 DRAFT DELIVERED — merchant privacy-policy
template (EN+ET) + fail-open GDPR window review, in `docs/DATA_MODEL_GDPR.md`**
(docs-only; the issue stays OPEN for Erkki/legal sign-off — nothing published to
any user-visible surface, merchant docs site untouched). Two new sections: (1) a
clearly-marked DRAFT privacy-policy template block, EN + ET siblings, written
from verified plugin behavior (profiling = purchase history + consented browse;
customer/order payload fields; F3-49 visitor-token-only browse identity; F3-46
consent-ungated attribution cookies `smaily_rec_id`/`smaily_rec_ctx` 30 d +
`smaily_rec_uid` 365 d defaults; My Account opt-out via `ProfilingConsentAccount`;
WP Privacy export/erase via `GdprHandler`; ≤24 h opt-out propagation from the
`ProfilingConsent` daily-TTL cache), with a template↔code fact map and explicit
placeholders — Smaily legal entity name, Smaily privacy-policy URL, engine-side
retention period (`[CONFIRM WITH ENGINE TEAM]`), and the lawful-basis framing
(legitimate interest + Art 21 opt-out per F3-31) all need Erkki/legal
confirmation; (2) a fail-open GDPR window decision review (behavior restated
from `ProfilingConsent.php`, risk analysis incl. the transient-eviction
sharpening F3-31 didn't cover, 5 alternatives) — **recommendation: keep the
F3-31 fail-open default but harden with serve-stale-on-error + durably persisted
known opt-outs (options B+C, follow-up sub-PR)**; no code/behavior change in
this pass. Gate: ci:strict as the docs-only sanity gate. Prior:
**PRO-1250 DONE — contract-staleness CI guard**
(Decision A on PRO-1247): new standalone workflow
`.github/workflows/contract-staleness.yml` (push to main / PR / daily 05:17 UTC
schedule — the schedule is the real guard — / dispatch) runs
`bin/check-contract-staleness.sh`, which md5-compares our vendored
`docs/RECENGINE_API_CONTRACT.md` against the engine repo's main
(`erkkimarkus/smaily-recommendations`, PRIVATE) and fails "CONTRACT COPY STALE —
sync from engine@<sha>" (exit 1) with local+engine md5 + the CC-8 instruction;
a missing/expired secret fails with a DISTINCT "CANNOT CHECK" (exit 2). Script
verified locally in all modes: in-sync via local checkout arg, no-arg fallback,
and the real GitHub-API CI path (all OK, md5 `b285ded8…`, engine commit
`2dec424`); simulated-stale temp copy → exit 1 with the full message;
no-source → exit 2. **Pending from Erkki: mint a fine-grained PAT
(contents:read on `smaily-recommendations` only) and add it as repo secret
`ENGINE_CONTRACT_READ_TOKEN`** — until then the scheduled run is red with
"CANNOT CHECK" (deliberately loud, not silent). Per-bump contract-sync issues
are RETIRED for this repo (CLAUDE.md CC-8 note updated); the sync discipline
itself (byte-identical + mock + code follow-through) is unchanged. Prior:
**v3.6.1 RELEASED** — patch release per Erkki's call
(PRO-1241 is a bugfix). Full GH release on the fork, tag `v3.6.1`, target main,
**Latest** (non-prerelease); asset `smaily-connect.zip` is the locally-built verified
ZIP (clean build-hash `aa86c9a`, 1 076 060 B — re-verified un-clobbered after the
expected harmless `release.yml` failure). Contents since 3.6.0: PRO-1241 gross
(tax-inclusive) order amounts per contract v1.4.0 §5 + the PRO-1240 dev-only
snapshot tooling (`bin/`, not shipped). Changelog + upgrade notice state that
already-connected stores' HISTORICAL order rows are corrected by the
engine-coordinated re-sync (rides PRO-1233, ~2026-07-14) — nothing merchant-side;
new orders are gross immediately on update. Gates (3.6.1 release-gate row in
`docs/audits/INDEX.md`): PCP on the built ZIP clean except the intentional
`Update URI` (F3-35); ci:strict exit=0 (unit 503, vitest 236); integration not
re-run (delta past the integration-green `b249887` is version/readme/`.pot`-header
only); no security re-audit (below policy threshold — no new
REST/auth/crypto/SQL/PII/external-HTTP surface; judgement recorded in the row).
**Gate-time incident, recovered:** a container-side `rm -rf` on the bind-mounted
`plugins/smaily-connect` PCP path wiped the host working tree incl. `.git`;
recovered by a full re-clone from `origin/main` (everything was pushed; only the
local version-bump commit needed recreating) + full rebuild — CLAUDE.md PCP
section now carries the never-touch-the-mount rule. Prior:
**PRO-1241 DONE — all order money fields GROSS (tax-inclusive)
per contract v1.4.0 §5.** MiuMjau prod verification (PRO-1202)
showed line items serialized ex-tax (bare `get_total()`) under a gross `total_amount` —
per-SKU revenue understated ~24% (median `unit_price/catalog.price` ≈ 1/1.24, Estonian
VAT). Contract synced byte-identical to **v1.4.0** (engine `2dec424`, md5 `b285ded8…`,
commit `434ffee`): §5 "Amount semantics" (all order money gross), §6 `plugin_magento`
source constant (no code change for us) + profiling opt-out enforcement documented (our
F3-49 sender-side omission already conforms). Code (`150e04e`): `OrderPayloadBuilder` —
the single money chokepoint (live hook, flusher retries, order backfill all build through
it at send time) — now wires `line_total = get_total() + get_total_tax()`, `unit_price =
gross line ÷ qty` (post-discount basis per §5, no longer `subtotal/qty`), line
`discount_amount` = the gross subtotal-vs-total delta, order `discount_amount =
get_total_discount(false)`; `total_amount` unchanged (already gross incl. shipping). No
wire-SHAPE change, values change basis. §5 sender invariant `Σ line_total + shipping ≈
total_amount` pinned in unit tests (taxed multi-line + discounted, zero-tax, rounding
edge, gross-discount arg pin) and integration (REAL WC tax engine: 24% rate + fixed-cart
coupon + taxed shipping; zero-tax case). Mock deliberately does NOT reject on tax basis
(live doesn't either — §5 invariant is monitoring, not a 4xx; documented at the route).
Docs: DECISIONS PRO-1241 (supersedes F3-22's amount serialization, banner added),
CLAUDE.md gross-money note. Gates: **ci:strict exit=0**; **integration OK (146 tests,
721 assertions, +2)** via the PRO-1240 auto-snapshot path (restore verified
`tenant_name='Smaily Connect test'`). **Live-walk `bin/walk-pro1241-gross-orders.cjs`
LIVE OK 9/9** against the sandbox: gross line_total/unit_price/discount on the wire,
invariant exact (55.80 + 6.20 = 62.00), engine accepted (processed=1, zero errors[]),
F3-44 stored exchange confirms; residue = ONE sandbox order row (external_order_id 2852)
+ auto-created customer `pro1241-gross@example.com`; store-side fully cleaned (order via
`wc_get_order()->delete(true)`, tax rate/options restored). **Deployment dependency:**
the one-time historical MiuMjau order re-sync (net→gross correction) rides the
engine-side PRO-1233 purge + re-backfill window (~2026-07-14), engine-coordinated —
release this (human-gated, separate step) before the MiuMjau flip so live+backfill
orders go gross. Prior: **PRO-1240 DONE — automatic `smly_rec_*` snapshot/restore
around integration runs.** `bin/run-integration-tests.sh` (= `composer run
test:integration`) now snapshots the DEV site's `smly_rec_*` options to
`~/.local/state/smaily-connect/smly_rec_snapshot.json` (mode 600, outside the repo,
`.prev.json` rotation) before the suite and — even on suite failure, via an EXIT
trap — restores them secret-safely afterwards (JSON over STDIN into `docker exec -i …
wp eval-file bin/restore-smly-rec-options.php`, never on a command line) and verifies
the restored `tenant_name` (loud warning on `MiuMjau`/fixture). A fixture/empty state
never overwrites a good snapshot; an intentionally disconnected dev site is not
auto-reconnected. Closes the F3-53 / LESSONS §2.17 memory-based-discipline gap that
twice killed the dev sandbox connection. Proof run: integration 144 OK (697
assertions) via the new path, restore verified `tenant_name='Smaily Connect test'`;
ci:strict exit=0. Docs updated same commit: CLAUDE.md (live-walk + filtered-runs +
TENANT-scoped notes), LESSONS §2.17 mechanical-guard addendum. Prior:
**v3.6.0 RELEASED.** Full GH release on the fork, tag
`v3.6.0`, target main, **Latest** (non-prerelease); asset `smaily-connect.zip` is the
locally-built verified ZIP (clean build-hash `f8903ce`, 1 075 310 B — re-verified
byte-identical AFTER `release.yml` fired on publish and failed harmlessly as expected
(no wp-cli in the runner); the asset was NOT clobbered, per the CLAUDE.md release
note). Contents: PRO-1224 canonical `woo-<id>` identity + `tags.product_id`, PRO-1230
§3b `catalog/remove` on hard delete, merchant docs site + in-plugin Documentation
links. Gates were green at prep (3.6.0 release-gate row in `docs/audits/INDEX.md`):
delta security+quality re-audit **0 Crit/High/Med**, PCP on the built ZIP clean except
the intentional `Update URI` (F3-35), ci:strict exit=0, integration 144 OK,
PRO-1224/1230 live-walk LIVE OK 20/20. **Deployment dependency:** MiuMjau's update to
3.6.0 must ride the coordinated engine-side purge + full re-backfill (engine PRO-1233,
week of 2026-07-14) — do not update the pilot store before the engine flip. Prior same
day: **Contract re-synced byte-identical (engine `2ff57e8`, md5
`1777746b…`) — PRO-1234.** Doc-only delta since our `8a0749f` sync, two engine commits:
`2ff57e8` (§3 identity clarify: a merchant-entered SKU, if ever sent, goes in
`tags.merchant_sku` — NEVER in `external_id`, which carries the platform variant id and
drives collision detection; engine consumes `tags.merchant_sku` nowhere today) and
`6b225fb` (setup-exchange endpoints map now carries `ingest_catalog_remove`). CC-8
conformance verified: plugin already conforms — merchant SKU appears on NO rec-engine
wire path (PRO-1224 dropped it entirely; `CatalogPayloadBuilder` `external_id` = raw
`get_id()`), and `Client::catalog_remove()` already resolves
`endpoints[ingest_catalog_remove]` with the absolute-path fallback (the fallback is now
for pre-`6b225fb` stored maps, no longer load-bearing for fresh connections). Mock moved
in the same sync: setup-exchange map now serves `ingest_catalog_remove` (14 keys); stale
"speculative key" Client docblock fixed. **No wire-SHAPE change → no live-walk needed**
(prose clarify + map addition already exercised by ClientTest both ways). Gates:
ci:strict exit=0; integration not run (doc + mock-map + comment delta only). Prior:
**v3.6.0 RELEASE PREPARED** (published later the same day — see
the head of this note; the release is the prerequisite for the MiuMjau key-migration,
engine PRO-1233, week of 2026-07-14). Version bumped 3.5.0→3.6.0 in all pinned spots
(`e1d20e5`); changelog + upgrade notice call out that already-connected stores need the
coordinated engine-side purge + full re-backfill (keys change to `woo-<id>`) BEFORE the
pilot flip. Re-audit policy TRIGGERED (REST retry-kick change + new outbound
`Client::catalog_remove()` + new `before_delete_post` surface, ~3.4k lines) → delta
security + code-quality re-audit run by a clean-context agent: **0 Crit/High/Med, 1 Low
(cross-flusher tombstone ordering race, accepted — follow-up: ask the engine whether a
plain upsert clears a tombstone's `recommendable=false`), 4 Info accepted** —
`docs/audits/2026-07-10-SECURITY_QUALITY_RE_AUDIT_PRO1224_PRO1230.md` + two INDEX rows.
Gates: ci:strict exit=0 (unit 500, vitest 236); integration not re-run (delta past the
integration-green feature commits is version/readme/i18n-refs only; sandbox connection
untouched). Full local build sequence run (admin+client JS, blocks, `bin/build-i18n.sh`
— new "Documentation" string now in the `.mo` — prod-vendor package, dev vendor
restored). **PCP on the built ZIP: clean except the intentional `Update URI`** (one
gate-time fix: upgrade notice shortened under the 300-char limit). ZIP verified: clean
build-hash `f8903ce`, v3.6.0, required present, dev artifacts absent, ~1.07 MB —
`smaily-connect.zip` at the repo root, release notes drafted (scratchpad
`release-notes-3.6.0.md`). The `gh release create` / tag followed the same day —
RELEASED, see the head of this note.
Prior: **PRO-1224 + PRO-1230 LIVE-WALK DONE against the "Smaily
Connect test" sandbox** — `bin/walk-pro1224-1230.cjs`, LIVE OK, 20 checks. Proven on the
REAL engine: catalog rows key `sku=woo-<id>` + `tags.product_id` = RAW canonical parent
id (simple AND variable — variation rows key `woo-<variation_id>`, group to the parent);
a product WITH a merchant WC SKU still keys `woo-<id>` and the merchant SKU appears
NOWHERE in any wire payload; an order's `items[]` join on the same `woo-<id>` keys —
all accepted (processed, no errors[]; F3-44 exchanges stored). §3b live proof
(PRO-1230): trash enqueues ONLY the soft in_stock=false row (zero `catalog.remove`);
a hard-deleted PARENT flushes ONE `catalog/remove` → live engine `outcome=removed`
(variable parent: removed_products=1, rows_tombstoned=2; simple: removed_products=1)
— a REAL removal, not a not_found, because the §3b match hit the walk's own freshly
synced `tags.product_id`. Dev wp-env re-connected to the sandbox via a fresh setup
token (secret-safe STDIN exchange; token consumed + file deleted); `smly_rec_*`
snapshot saved host-side for post-suite restore (LESSONS §2.17). Still pending: the
one-time engine-side purge + full re-backfill of already-synced stores (coordinate
with the engine before the pilot flip). Prior: **PRO-1230 DONE — hard-delete → §3b `catalog/remove`;
landed on main, UNRELEASED.** A permanently deleted PARENT product (incl. purge-from-
trash — `before_delete_post` fires for both) now enqueues ONE `catalog.remove` row via
`CatalogHookHandler::on_hard_delete_product` (Bootstrap rebind; `wp_trash_post` keeps
the F3-40 in_stock=false soft path untouched). Payload = the RAW un-prefixed CANONICAL
parent id (`SkuResolver::product_group_id()` = `tags.product_id`; engine confirmed
2026-07-10 the §3b match is that exact string — NOT `woo-<id>`). Routing: single
VARIATION delete keeps the per-SKU soft path (§3b would tombstone surviving siblings);
translation delete re-syncs the canonical (P4); auto-draft GC skipped; per-variation
soft rows from WC's cascade delete are pre-claimed into the one remove. New
`CatalogRemoveFlusher` (own AS hook `smly_rec_flush_catalog_remove`, 60s tick, added to
the Event-Log retry kick list) drains the new `catalog.remove` event type from the
shared queue — §3b is NOT D6 ({ok, removed_products, rows_tombstoned, not_found}, no
errors[]), so `AbstractD6Flusher` grew a protected `apply_response()` seam; on 2xx every
row is SENT with the per-row outcome (`removed`/`not_found` — a not_found is contract-
success, never a retry) stored per F3-44. `Client::catalog_remove()` resolves
`endpoints[ingest_catalog_remove]` w/ fallback `PATH_INGEST_CATALOG_REMOVE` (the v1.3.0
map doesn't carry the key — fallback is load-bearing). Mock moved in the same pass
(CC-8): §3b route w/ wrapper validation + exact-string `tags.product_id` matching.
Docs: DECISIONS PRO-1230 (+ F3-40 gap-closed note), CLAUDE.md trash/hard-delete note
rewritten; merchant docs site unchanged (background ingest detail, no user-visible
behavior change). Gates: **ci:strict exit=0** + **integration OK** (`sg docker`; new
E2E: hard-delete→§3b wire w/ tombstone match, trash-no-remove + purge-removes,
variation-delete soft path, variable-parent family remove). The §3b live-walk is DONE
(2026-07-10, see the head of this note); pre-PRO-1224 rows lack `tags.product_id` →
§3b not_found until the coordinated purge + re-backfill. Prior: **PRO-1224 CORE DONE —
canonical `woo-<id>` key everywhere +
`tags.product_id`; landed on main, UNRELEASED.** `Support\SkuResolver::resolve()` now ALWAYS
emits `woo-<canonical_id>` (the platform id; prefix `woo-`, not `wc-`) and NEVER the merchant
WC SKU field — reversing F3-36's "real SKU else `wc-{id}`" (the resolver PATTERN — one
chokepoint for catalog+order+browse, canonicalization, never-drop deleted-line fallback now
`woo-oi-<id>` — is unchanged). `CatalogPayloadBuilder` now emits `tags.product_id` = the RAW
canonical PARENT id (`SkuResolver::product_group_id()`) — grouping (PRO-1227) + removal
(§3b/PRO-1230) key; RAW, not `woo-`-prefixed, for **Shopify parity** (`tags.product_id =
product.id`) + the §3b example `["7620134"]` (caught by reading the shipped Shopify code +
contract, NOT PRO-1230's looser `woo-<product_id>` prose — LESSONS §2.20). Merchant SKU
**dropped entirely** (engine answer PRO-1225: consumed nowhere; never in `external_id` —
that's the raw platform id + collision key). Docs: F3-36 SUPERSEDED banner + full PRO-1224
entry in DECISIONS; CLAUDE.md SkuResolver note rewritten; LESSONS §2.20. Mock moved to the new
shape (records `tags`; scenario triggers re-keyed sku→`event_id` since `sku` is no longer
test-controllable). Gates: **ci:strict exit=0** (PHPCS clean, PHPStan OK, unit 483, tsc,
vitest 236) + **integration 140 OK** (`sg docker`). The PRO-1224 **live-walk** is DONE
(2026-07-10, see the head of this note); still NOT done: the **one-time engine-side purge +
full re-backfill** of already-synced stores (every key changes `wc-<id>`→`woo-<id>`) — must be
coordinated with the engine before the pilot flip. **PRO-1230** (hard-delete → §3b) now
unblocked on the contract + `tags.product_id`. `external_id` decision resolved (omit merchant
SKU). Prior: **Contract synced to v1.3.0 (engine `8a0749f`, md5 `1886669e…`)** — additive MINOR
(PRO-1229/1228): §3b `catalog/remove`, sharpened `sku` identity rule, `tags.product_id`,
soft-removal lifecycle. Prior: **Merchant documentation site + in-plugin docs links —
landed on main, UNRELEASED.** New `docs/site/index.html`: a single self-contained
**bilingual (EN/ET)** HTML page (7 sections — Overview, Getting started/wizard,
Settings, Importing, Error messages, FAQ, Data & privacy) built to look and work like
the Shopify docs at `connect.smaily.com/docs`; content written from real plugin
behavior (no Shopify-isms — no OAuth prompts, no 60-day order window, WP Consent API
companion, Action Scheduler, WP Privacy tools). No build step, no external deps; hosted
separately, **live at https://smaily.com/connect-woo/**, excluded from the ZIP via
`.zipignore`. `docs/INSTALL.md` → thin pointer; CLAUDE.md/INDEX.md carry the keep-current
rule (update the site in BOTH languages in the same commit user-visible behavior
changes). Plugin now links to the docs from the **wizard + Settings screens** (a visible
"Documentation" link at the top of `admin/wizard.php`'s mount — help material one click
away on install) and the **Plugins page** (action link + row meta); every UI link
resolves through `Constants::docs_url()` (const `DOCS_URL`, filter
`smaily_connect_docs_url`) — one line to change when Smaily docs move to
`connect.smaily.com` (the long-term plan: each plugin its own home there). New string
"Documentation"/"Dokumentatsioon" in `.pot`+`-et.po` (`.mo`/`.json` regenerate at
package time). Commits `e57bcea` (site+doc updates), `df8a9c0` (in-plugin links),
`5db99cd` (CLAUDE note). Gates: **ci:strict exit=0** (PHPCS 0 errors, PHPStan OK, unit
480, vitest 236); PHPCS clean on the 3 touched PHP files. Integration NOT run (wp-env
down; change touches no ingest/queue/REST data path the suite covers — admin-UI-only,
ci:strict is the relevant gate). No release cut. Prior: **F3-55 — backfill progress:
users WALKED vs contacts SYNCED;
v3.5.0 RELEASED.** Prike: "contact sync shows 30k contacts going to Smaily, we have 16k
opt-ins" — the WIRE was correct (F3-48 audience filter POSTs only the mode's audience),
but `total_count=count_users()`, `processed_count` counts rows walked, and the UI
labelled that walk count "contacts synced" (`Step2Subscribers` + `Step6Done`). Fix
(`5104950`): migration 008 adds cumulative `synced_count` (audience members handled =
POSTed + already-fresh; the walk keeps driving percent/ETA — an audience-based
denominator would freeze through opted-out ID ranges); `ContactAudience::
count_audience()` = mode-aware SQL count NEXT TO `should_sync_user()`, integration test
pins the two halves agree in every mode; `/backfill/status` carries `synced` +
`audience_estimate` (contacts only, estimate only on non-running polls); UI copy —
pre-start "about N of them will be synced to Smaily as contacts" (only when the mode
narrows), running "Checked X of Y users — Z contacts synced", done "Done — Z contacts
synced (X users checked)"; et translations, i18n rebuilt (build-i18n ×2, admin-bundle
JSON verified). Engine backfills untouched. Also STABILIZED the recurring
`EngineAutomationsSection` dropdown vitest flake (`f92e4ef` — await the option, not the
select; failed 2× today under loaded parallel runs). Gates: ci:strict exit=0 (unit 480,
vitest 236); integration FULL 139/674 (+2); connection snapshot/restored. **v3.5.0
RELEASED** per recipe (build-hash `ea5bce0`, ZIP ~1.07 MB verified incl. migration 008,
PCP clean except intentional F3-35, audits-register row, GH release Latest). Prike saab
öelda: andmevoog oli kogu aeg õige — 3.5.0 näitab seda ausalt. Prior same day:
**F3-54 — the REAL Prike fatal found and fixed; v3.4.3 RELEASED
(critical).** Martin's correction (fatal at the option guard, line 166; admin off-toggle
didn't stop it) invalidated the F3-53 poison-row theory: the crash is OUR seam —
`SettingsEndpoint::save_woocommerce` wrote `smaily_connect_abandoned_cart_status` as a
BARE BOOLEAN (WP stores `'1'`/`''`) while THREE consumers offset into it as an array
(legacy email pass; `Options::get_woocommerce_settings_from_db`; inverted in
`EnvDetector`, where `(bool)` on a disabled array read as ENABLED). PHP 8 repro'd:
`'1'['enabled']` and `''['enabled']` both throw "Cannot access offset of type string on
string". Every store that saves the WooCommerce tab / wizard Step 3 corrupts the option —
Prike crashed loudly; the dev env never saved that tab (option absent), and the guard
test seeded the option ITSELF in the array shape, so the seam was structurally invisible
(LESSONS §2.19). Fix (`8c1c9d2`): (1) `Options::abandoned_cart_status()` +
pure `normalize_abandoned_cart_status()` — ONE shape gate, all consumers read through it,
corrupted stores heal automatically; (2) the legacy email pass dispatches ROUTER-FIRST
(`AutomationRouter::trigger_automation('abandoned_cart', …)` — wizard mapping row is the
workflow source; multilingual + F3-48 force_opt_in + F3-44 exchange capture; ApiException
= transient → retry) with fallback to the legacy `autoresponder_id` for pre-wizard
stores; enabled-with-neither-source logs once per pass, carts stay pending; (3)
`save_woocommerce` writes the ARRAY shape and PRESERVES `autoresponder_id` (3.4.x
destroyed it); (4) hydrate reads via the normalizer. Tests: +6 unit (normalizer), +5
integration (`AbandonedCartSettingsSeamTest` — REAL writer + REAL reader in one
scenario). Gates: ci:strict exit=0 (unit 480, vitest 232 — one pre-existing FLAKY:
`EngineAutomationsSection` "filters INACTIVE workflows" failed once under the parallel
full run, passes isolated ×2 + on rerun; T2.4 surface, untouched here); integration FULL
137/652; sandbox connection snapshot/restored (tenant verified). **v3.4.3 RELEASED** per
recipe (clean build-hash `597ae8f`, ZIP verified ~1.06 MB, PCP-on-ZIP clean except the
intentional F3-35 finding, audits-register row added, GH release Latest). **Prike:
install v3.4.3 as a proper update — no manual option cleanup needed** (the normalizer
heals the corrupt value; the interim `wp option delete
smaily_connect_abandoned_cart_status` remains valid until then; the v3.4.2 legacy-cron
cleanup is included). Prior same day:
**F3-53 — Prike abandoned-cart PHP 8 fatal loop + legacy WP-Cron
resurrection fixed.** Prike (installed the new module over the old one, no in-place upgrade)
hit a 15-minute fatal loop on `smly_plus_abandoned_cart`: old-writer `cart_content` rows
deserialize to string items, `prepare_products_data()` read `$cart_item['product_id']`
unguarded (PHP 8 fatal, cart stayed `mail_sent NULL`, whole pass aborted every tick) — and
the legacy WP-Cron events were alive because `Lifecycle::activate()`/`check_for_dependency`
re-scheduled them after WPCronAuditor's one-time clear, with `Cron::smaily_sync_subscribers`
still add_action-registered (= the F3-47 language clobber runnable daily, on Prike of all
stores). Fixes: (1) poison-row hardening in the legacy email pass — non-array cart_content
terminal-marked + logged, non-array/keyless items skipped, per-cart `try/catch (Throwable)`
backstop terminal-marks a throwing cart (deterministic ⇒ would recur forever); (2)
`Lifecycle::set_scheduled_actions()` + both call sites REMOVED (AS owns scheduling;
deactivate clears stay); (3) the `smaily_connect_cron_sync_subscribers` add_action removed —
the mass-send is uninvocable (method kept for the upstream diff). Tests: +2
AbandonedCartGuardTest (poison shapes incl. the exact Prike string-items case + the
Throwable backstop via a throwing `pre_http_request`), +3 new LegacyCronScheduleTest
(no callback registered; WC activation doesn't re-arm; scheduler method gone);
EnvScrub now also clears `smaily_connect_abandoned_cart_fields`. Gates: ci:strict exit=0
(PHPUnit unit 474, PHPCS 0 errors, PHPStan clean, vitest 232, tsc/eslint clean);
integration full suite OK (131 tests, 620 assertions; +5 new). NB the full suite
OVERWROTE the dev-site sandbox connection with fixture values (`re-fixture.test` /
"MiuMjau"-named fixture) — snapshotted before, restored after, verified
`tenant=Smaily Connect test, connected=1`; §2.17's "scrub touches only the tests site"
does NOT hold for the connection options, follow the CLAUDE.md snapshot/restore rule.
Docs: DECISIONS F3-53, LESSONS §2.18 (+ restored the `## 3` header §2.17's commit
accidentally ate). Client-side mitigation sent to Prike's dev meanwhile: abandoned cart
OFF in settings + `wp cron event delete` × 3 legacy events. Same day, Erkki's call
("teeme kohe korda ja siis release"): **F3-53 addendum — abandoned-cart `language` now
routes through ContactLanguageResolver** (`0014d76`; the legacy helper's cron fallback
sent `language:''` = wipes the contact's stored language — the F3-47 class at
abandoned-cart scale; key omitted when unresolved, integration test captures the real
wire body at the `pre_http_request` seam and pins `language='en'`). Then
**v3.4.2 RELEASED** per the CLAUDE.md recipe: bump in all six places + CHANGELOG.md
backfilled 3.2.0–3.4.1 (it had stalled at 3.1.0), committed BEFORE the build → clean
build-hash `d6fb061`; ci:strict exit=0 (re-run after bump); integration FULL suite OK
(132 tests, 627 assertions; connection snapshot/restored); admin+client+blocks rebuilt;
i18n skip per recipe (no admin-string/.po changes, artifacts current); prod-vendor ZIP
built + verified (v3.4.2 everywhere, required present, dev artifacts absent, ~1.06 MB);
PCP on the BUILT ZIP clean except the intentional `plugin_updater_detected` (F3-35);
audits-register 3.4.2 gate row added. GH release `v3.4.2` on the fork (normal release,
Latest), ZIP attached. **Prike next step: install v3.4.2 as a proper plugin update** —
the update itself clears the legacy WP-Cron residue (`maybe_run_upgrade → Activation::run
→ WPCronAuditor`); then abandoned cart can be re-enabled (poison rows will terminal-mark
+ log on the first tick). **Open (BACKLOG): rewrite abandoned-cart onto the new
namespaced pipeline** (own store shape instead of `serialize(get_cart())`, guest
capture, Event Log observability) — deferred, legacy path is hardened; separate sub-PR
with its own plan/checkpoint. Prior:
**v3.4.1 RELEASED — T2.4 pilot-feedback UI fixes + contract
v1.2.0 sync (`recipe_en`).** Patch release per the CLAUDE.md recipe: version bumped in all
six places, committed BEFORE the build → clean build-hash `ae3bc3d`; ci:strict exit=0
(PHPUnit unit 474, PHPCS 0 errors, PHPStan clean, vitest 232, tsc/eslint clean);
admin+client+blocks rebuilt; i18n artifacts current from the same-day T2.4 build-i18n run
(no admin-string changes since — skip per CLAUDE.md); prod-vendor ZIP built + verified
(v3.4.1 everywhere, required present incl. `dist/admin/admin.js`,
`dist/public/js/sc-runtime.js`, `blocks/*/build`, `vendor/autoload.php`, `composer.json`,
`languages/*.mo` + admin-bundle JSON; tests/docs/node_modules/admin-src/dev-vendor absent;
~1.06 MB); **PCP against the BUILT ZIP clean except the single intentional
`plugin_updater_detected`** (F3-35). No security re-audit — delta is React-admin-only
(shipped-PHP delta = version-bump lines; re-audit policy not triggered; judgement recorded
as the 3.4.1 row in `docs/audits/INDEX.md` per the 3.3.x lesson). No integration full-suite
run (PHP delta mock/test-only; sandbox connection preserved; the T2.4 automations-suite run
was OK 6). GH release `v3.4.1` on the fork (normal release, Latest), ZIP attached. Prior
same day:
**T2.4 — engine-automations UI feedback fixes (Erkki's real-store
test of v3.4.0; F3-52 addendum).** Five fixes, all React admin — PHP untouched. (1) **Language
mode is STORE-GLOBAL** (F3-52 addendum): the display mode derives ALWAYS from the store's
structure (`deriveLanguageMode`), uniformly for every row; a server row's stored
`language_mode` is a wire fact only and is never honoured for display (the sandbox's
walk-saved `single` `replenish_due` row rendered one dropdown while its neighbours got the
per-language table). New pure `convertAutomationMap` translates stored maps at hydrate —
single `{id}` → per_language `{fallback:id}` (languages unpicked); per_language → single
`{id:fallback}` (no fallback → `{}`, merchant re-picks) — and the PUT sends the derived mode.
(2) **Cooldown input UX:** a local typing draft (empty allowed — no snap-to-0, no "0…"
prefix), commit clamped to 1–365 on blur, an empty/garbage draft reverts to the previous
value; implemented as a section-local `CooldownField`, the shared NumberInput primitive's
immediate-commit behaviour untouched (other call sites). (3) **Empty test-address warning:**
an `enabled && test_mode && test_emails=[]` row gets an inline warning-tone note (the engine's
test fire path sends ONLY to the listed addresses — an empty list means nobody ever gets an
email, silently); a warning, not an error — saving stays allowed. (4) **Human generic error
banner:** `classifyAutomationsFailure`'s generic branch now leads with a human message
("Connecting to Smaily Campaign Intelligence failed (HTTP nnn). Check the connection on the
Campaign Intelligence tab and try again.") and demotes the raw technical error (the old
"GET /… → 401" headline, Erkki's deleted-key case) to a new `AutomationsFailure.detail`
rendered as a small detail line; the save path appends the detail in parentheses; the
key_rejected banner already pointed to the Campaign Intelligence tab — unchanged.
(5) **Forward-compatible `recipe_en?`:** the catalog type gained the optional field; non-et
admin locales show it when present, else `recipe_et` (`pickRecipe` — consistent with the
name/description locale logic). Mock + contract deliberately NOT touched — they sync in
their own pass after the engine deploy lands (do not depend on it). i18n: 3 new strings,
et translations complete (build-i18n run twice — extract then compile; 0 untranslated;
translations verified in the admin-bundle JSON). Tests: +24 vitest (conversion both
directions + uniform-mode buildRows, pickRecipe, save-path detail, classifier messages/detail
×6, cooldown draft/revert/clamp ×3, empty-address warning, recipe_en render ×2, banner detail
line; the Settings save-helper now blur-commits like the real flow). Gates: ci:strict exit=0
(PHPUnit unit 474, PHPCS 0 errors, PHPStan clean, vitest 232, eslint/tsc clean); PHP delta
zero — no integration run (would scrub the sandbox connection). Prior same day:
**v3.4.0 RELEASED — engine-run automations settings (T2) GA.**
Release gate on top of the T2.3 live-walk + T2 security re-audit (both below, same day):
version bumped in all six places (plugin header/constants, package.json, readme.txt stable
tag + changelog + upgrade notice, ConstantsTest, both test bootstraps), committed BEFORE the
build → clean build-hash `aa42ce6`; ci:strict exit=0 (PHPUnit unit 474, PHPCS 0 errors,
PHPStan clean, vitest 208, tsc/eslint clean); admin+client+blocks rebuilt; i18n artifacts
current from the T2.2 build-i18n run (no admin-string changes since — skip per CLAUDE.md);
prod-vendor ZIP built + verified (v3.4.0 everywhere, required files present incl.
`dist/admin/admin.js`, `dist/public/js/sc-runtime.js`, `blocks/*/build`, `vendor/autoload.php`,
`composer.json`, `languages/*.mo` + admin-bundle JSON; tests/docs/node_modules/admin-src/
dev-vendor absent; ~1.05 MB); **PCP against the BUILT ZIP clean except the single intentional
`plugin_updater_detected`** (Update URI clobber-guard, F3-35 — note the old `(BETA)` name
finding is gone since GA). GH release `v3.4.0` on the fork (normal release, no prerelease),
ZIP attached. No integration full-suite run in this pass (would scrub the sandbox connection;
PHP delta since the T2.1-gated suite is zero). Prior same day:
**T2.3 — automations live-walk GREEN against the real engine
(sandbox).** Dev wp-env re-connected via secret-safe STDIN exchange — the stale connection
still pointed at PRODUCTION MiuMjau (the exact CLAUDE.md trap); now `tenant_name="Smaily
Connect test"`, verified before any traffic. New `bin/walk-t2-automations.cjs` (tenant
hard-gate `sandbox_tenant_not_production`, all calls through the plugin's
`Client::automations_*` methods, not curl): **15/15 checks PASS** — §11 catalog 200 with the
4 pet-sector triggers (`replenish_due, winback_rescue, life_stage, post_purchase`), all 6
fields + `language_modes=["single","per_language"]` + `docs` URL; §12 initial config 200
(0 rows, fresh tenant = fail-closed); §13 valid PUT (all 8 fields, test_mode=true) →
`{ok:true, upserted:1}`; GET round-trip returns every value unchanged **and
`configured_via='plugin'` (the brief's acceptance criterion)**; invalid PUT A (enabled
without `automation_map.id`) → 422 with the v1.1.0 INDEXED `errors[]`
(`{index:0, trigger_key, field:"automation_map", message:"automation_map.id on nõutav"}` —
live proof the indexed shape is deployed); invalid PUT B (`per_language` without
`fallback`) → 422; invalid key → 401 `unauthorised`; fail-closed cleanup PUT
(`enabled=false, test_mode=true`) verified by GET — no enabled placeholder row left in the
sandbox. **QA checklist (brief) coverage:** (1) catalog renders from the API/new trigger
without a release — walk `catalog_get_200` + vitest "renders an unknown catalog trigger
dynamically" (browser render = manual pilot check); (2) no cross-store catalog cache — code
fact: the proxy/UI cache NOTHING (F3-51 rule 1), every GET hits the engine; (3) enabled
without workflow-id → 422 at the field — walk `put_invalid_missing_id_422_indexed` + vitest
`issuesByTrigger` binding + pre-validation tests (in-browser field render = manual pilot
check); (4) per_language without fallback → 422 — walk `put_invalid_no_fallback_422` +
vitest; (5) reopen shows GET state — walk round-trip + F3-52 rule 4 (fetch on every open,
no cache) + vitest dirty-draft tests (browser reopen = manual pilot check); (6) test_mode
default true + separate confirmed go-live — vitest fail-closed default row + "requires a
confirm before switching test mode off"; walk proves the test_mode=true round-trip
(confirm dialog in browser = manual pilot check); (7) missing/bad key → clear error — walk
`invalid_key_401` + unit 401→502 `api_key_rejected` mapping + vitest key-rejected banner.
The brief's final acceptance leg — test address receives the email on the nightly engine
run — is engine-side, NOT plugin-provable: manual pilot check. **Security re-audit on the
T2 surface run same day** (`docs/audits/2026-07-07-SECURITY_RE_AUDIT_T2_AUTOMATIONS.md` +
INDEX row): 0 Critical/High/Medium; 1 Low FIXED in the pass (engine-origin `docs` URL now
scheme-guarded `isHttpUrl()` before rendering as an anchor href); 3 Info accepted; also
swept the un-registered F3-49/F3-50 delta — clean. Prior same day:
**OrderBackfill full-suite flake RESOLVED — stale live-walk
order residue, NOT cross-test state.** The 3 recurring `RecEngineOrderBackfillTest` count
failures (+1 on every order count) were caused by ONE order sitting in the dev wp-env
`wc_orders` table since 2026-06-19: the F3-43 live-walk's `wc-label-printed` custom-status
order. Its cleanup used `wp_delete_post()` — a silent NO-OP for HPOS orders — and the
test's own `delete_all_orders()` swept via `wc_get_orders` + registered statuses, which
cannot see an unregistered custom status, while the backfill's F3-42 denylist SQL counts
it as a sale. Once probed (raw wc_orders dump at assert time) the failure was
deterministic, isolation included — the earlier "green in isolation" reads reflected
different env state, not test ordering. Fix: `delete_all_orders()` now sweeps STATUS-BLIND
off the active order table (reuses `OrderBackfillJob::table_spec()`); order cleanup in
`RecEngineOrdersTest::tearDown()` + `bin/walk-f3-43-orders.cjs` + `bin/walk-3.3-orders.cjs`
switched to `wc_get_order()->delete(true)`; bonus one-liner — `RecEngineMockServer::
terminate()` uses `defined('SIGTERM') ? SIGTERM : 15` (pcntl absent in the wp-env CLI →
end-of-run fatal gone). Asserts untouched. Gates: full integration suite green TWICE in a
row (`OK (126 tests, 594 assertions)` both runs, no shutdown fatal), ci:strict exit=0.
LESSONS §2.16. Prior same day: **T2.2 — engine-triggered automations config, React UI (F3-52)**.
The "Engine-run recommendation automations" sub-section renders UNDER the store-run
WooCommerce automations (Step 3 / WooCommerce tab): catalog-driven trigger cards (§11 —
no hardcoded keys, a new engine trigger appears without a plugin release; `_et`/`_en` copy
by admin locale, `recipe_et` + catalog `docs` link always), per trigger enable-toggle +
workflow picker (useWorkflows; per-language rows + fallback radio on multilingual A/B sites
→ `language_mode:"per_language"`, else `single`), cooldown 1–365 (default 7), and the
fail-closed test-mode block (`test_mode` default ON, up-to-50 test addresses, "Activate for
real…" as a SEPARATE confirmed action — never the enable toggle). ONE Save, TWO parallel
requests: the engine slice joins the WooCommerce tab's sticky-footer Save (and the wizard
Step-3 Continue) but keeps its OWN dirty bit — on partial failure (local POST ok, engine PUT
failed) only the engine section stays dirty with its error in-section; §13 all-or-nothing ⇒
a 422 keeps the whole slice dirty, errors[] bound to rows/fields by trigger_key/index.
Round-trip: GET on every open is the truth (dirty draft survives a tab switch), PUT sends
every rendered row with all 8 fields, `daily_cap` passes through GET→PUT untouched, §12
read-only fields stripped at hydrate; a config row missing from the catalog is neither
rendered nor sent. Not connected → upsell banner (Settings: CTA to the Campaign Intelligence
tab; wizard: next-step hint + a post-connect "back to Step 3" banner in Step 4). Errors: 503
→ upsell state; 502 api_key_rejected → reconnect banner; other → retry banner; skeleton on
load. New: api/automations.ts, state/engine-automations.ts (pure rows/validation/save-orch),
hooks/useAutomationsData.ts, components/steps/EngineAutomationsSection.tsx; slice + 5 actions
in the shared reducer. i18n: bin/build-i18n.sh run, all new strings translated in
smaily-connect-et.po (0 untranslated). Tests: 44 new vitest (engine-automations logic 28,
reducer slice 4, section component 9, Settings save-orchestration/partial-failure 3).
DECISIONS F3-52. Gates: ci:strict exit=0 (PHPUnit unit 474, PHPCS 0 errors, PHPStan clean,
vitest 208, tsc/eslint clean); PHP untouched — no integration run required for this UI
sub-PR. **Next:** T2 live-walk against the sandbox when the engine side is ready (the
OrderBackfill full-suite flake is RESOLVED — see the top entry). Prior same day: **T2.1 —
the PHP layer** below.)_

_(T2.1 record follows — same day:_ **T2.1 — engine-triggered automations config, PHP layer (F3-51)**.
Contract synced to **v1.1.0** (commit 9ec2ff8, engine c16377e+7b5b922, byte-identical md5
`7e41726bcd17fab163586b7f97093e0d`): §11 GET /automations/catalog, §12 GET /automations/config,
§13 PUT /automations/config — the engine-run automations (replenishment/win-back enrolment into
merchant-built Smaily workflows) get their plugin-side CONFIGURATION surface; execution never
touches the plugin. T2.1 ships the PHP layer in the same pass as the sync (LESSONS §2.7 — mock
+ code move with the doc): `Client::automations_catalog()/automations_config()/
put_automations_config()` (map keys `automations_catalog`/`automations_config` + new
`PATH_AUTOMATIONS_*` fallbacks — load-bearing for every pre-v1.1.0 connection, "Map age" §1;
first PUT verb in the Client, wire-pinned), mock-engine routes with strict §11–§13 validation
(all-or-nothing indexed 422, Estonian custom-check messages, engine-stamped
`configured_via`/`updated_at`) + `automations_*` endpoints-map keys, and the admin REST proxy
`REST\AutomationsEndpoint` (GET catalog / GET config / PUT config; `is_connected()` gate 503;
**no cache, no wp_options copy — the engine's GET is the source of truth**; PUT forwards
`configs` as-is, engine 422 passes through verbatim, engine 401 → 502 `api_key_rejected`; wired
via EndpointRegistry + expected_routes). Tests: ClientAutomationsTest (8) +
AutomationsEndpointTest (9) unit; RecEngineAutomationsTest integration (catalog shape, PUT→GET
round-trip, all-or-nothing 422 with row/field binding, wrapper-422 without index, 401, gate).
DECISIONS F3-51. Gates: ci:strict exit=0 (PHPUnit unit 474, PHPCS 0 errors, PHPStan clean,
vitest 164, tsc/eslint clean); integration — RecEngineAutomationsTest `OK (6 tests, 83
assertions)` in isolation; full suite 126 tests / 590 assertions with **3 pre-existing
RecEngineOrderBackfillTest failures that reproduce IDENTICALLY on clean main** (stash-verified,
same env/day: 120 tests, the same 3 failures without any T2.1 code — NOT a T2.1 regression;
**RESOLVED 2026-07-07, see the top entry**: the cause was a stale custom-status order the
2026-06-19 F3-43 live-walk left in the dev DB, not cross-test state). Next: T2.2 — the React
settings UI (**shipped, see the T2.2 entry above**).
Prior: 2026-07-03 —)_

**v3.3.2 — browse 0-events on CookieYes RESOLVED the RIGHT way (F3-50)**: root cause was the missing free `wp-consent-api` companion plugin, NOT a CookieYes incompatibility (CookieYes registers into the WP Consent API once it's installed). The 3.3.1 CookieYes cookie-parser was a mis-fix (Erkki caught it — per-vendor code + CookieYes docs prove standard support) and is **reverted**; 3.3.2 keeps browse consent on the standard WP Consent API + adds a `NotificationManager` admin advisory guiding merchants to install `wp-consent-api`. MiuMjau fix = install that plugin (wp-admin). Prior same day: **v3.3.1** (reverted) and **F3-49 DONE — browse events carry `smaily_visitor_token` (cold-start), NOT rec_id/email; browse attribution stays order-signal-driven** — resolves the browse-identity gap Erkki raised 2026-07-01. The beacon sent only `session_id`, so contract §6 per-event identity-resolution + retroactive-binding never fired and the async order-attribution path-3 was inert. Engine-team answer (2026-07-03): browse does NOT feed attribution (order `smaily_rec_id` + email-click drive `direct`/`exact_later`/`indirect_*`; browse would at best give the soft `assisted_view`) — so we DON'T add rec_id/email to browse, but DO add the opaque `smaily_visitor_token` for future cold-start personalization (the engine binds the browse row via it; ingest already accepts the field). Profiling opt-out on the token path is engine-side (server-enforced 2026-07-03); guest-browse-session-only = accepted v1 limitation. Wired into `enrich()` (omit-on-empty) + JS/integration/live-walk coverage; DECISIONS F3-49. Prior: 2026-06-30 — **F3-47 SP-A DONE — contact-sync language via `ContactLanguageResolver`**.
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

**RELEASED v3.3.1 (2026-07-03)** — full GH release on the fork (`erkkimarkus/smaily-wordpress-plugin`,
build `8af6bc1`, ZIP ~1000 KB attached, tag `v3.3.1`, Latest, NOT a pre-release). Headline:
**CookieYes consent bridge** — browse tracking sent 0 events on CookieYes stores because the
beacon is fail-closed on the WP Consent API (`window.wp_has_consent`), which CookieYes doesn't
expose (confirmed live on MiuMjau). `detectConsent()` now falls back to CookieYes's own
`cookieyes-consent` cookie (grant on `action:yes` + `advertisement`, filterable), WP Consent API
keeps precedence, `cookieyes_consent_update` re-triggers mid-session. Once browse fires it carries
the 3.3.0 `smaily_visitor_token`. Gates: ci:strict exit=0 (PHPUnit 456, vitest 170 incl. 6 new
CookieYes cases, static clean); integration OK (120 tests, 499 assertions). **No PCP re-run /
security re-audit** — PHP surface delta is two `apply_filters` lines in the boot blob (no
REST/auth/SQL/crypto); substantive change is JS. **MiuMjau gets the fix via the normal plugin
update** (wp-admin — Erkki has no server file access, so the mu-plugin unblock was ruled out).
DECISIONS F3-49 / LESSONS §2.15.

**RELEASED v3.3.0 (2026-07-03)** — full GH release on the fork (`erkkimarkus/smaily-wordpress-plugin`,
build `16a530f`, ZIP ~996 KB attached, tag `v3.3.0`, marked Latest, NOT a pre-release). Headline:
**F3-49** — browse events now carry the opaque `smaily_visitor_token` (identity for the engine's
future cold-start personalization binding, NOT attribution), while `smaily_rec_id`/email stay OFF
browse (data-minimization, client-side). Resolves the browse-identity gap Erkki raised 2026-07-01;
the engine team confirmed (2026-07-03) browse does NOT feed attribution (order signals drive the
`direct`/`exact_later`/`indirect_*` mix) and asked only for the visitor token. Gates: ci:strict
exit=0 (PHPUnit 456, vitest 164, PHPStan/PHPCS/tsc/eslint clean); integration OK (120 tests, 499
assertions); **browse live-walk 14/14 green** vs the SANDBOX ("Smaily Connect test", NOT MiuMjau),
incl. `engine_accepts_browse_visitor_token`. **No PCP re-run / security re-audit** — the shipped PHP
surface is unchanged from 3.2.1 (JS + tests only, version strings aside; no REST/auth/SQL/crypto
surface touched); 3.2.1 PCP was clean except the intentional `Update URI`. DECISIONS F3-49. **MiuMjau
+ Prike get this via their next deploy** (browse bundle `sc-runtime.js` rebuilt in the ZIP).

**RELEASED v3.2.1 (2026-07-01)** — full GH release on the fork (`erkkimarkus/smaily-wordpress-plugin`,
build `4b6fd3f`, ZIP ~992 KB attached). Headline: the **F3-48.5a contact-sync mode-selector UI
refinement** (mode card visible only when sync enabled + below the sync toggle; "Checkout opt-in only"
disabled until the checkout checkbox is on; homepage radio-card style) + doc-accuracy fixes (the
"ships dormant" wording). Admin-UI-only, no functional/data change. Gated by the **F3-48 Smaily
contact-API live-walk 12/12 green** (`bin/walk-f3-48-contact-sync.cjs`, sandbox); PCP on the ZIP clean
except the intentional `Update URI`; ci:strict exit=0 (PHPUnit 456, vitest 161); ET i18n complete.
No security/code-quality re-audit (TSX-only delta, no security surface). **Next gate: Prike cutover.**

**RELEASED v3.2.0 (2026-06-30)** — full GH release on the fork (`erkkimarkus/smaily-wordpress-plugin`,
build `5034cc9`, ZIP ~991 KB attached). Headline: the **block-checkout rec-attribution fix** (the
MiuMjau `smaily_rec_id`-empty regression — MiuMjau runs block checkout, so the cookie was captured
but never stamped onto the order; now stamped via `woocommerce_store_api_checkout_order_processed`)
+ the **F3-48 contact-sync mode engine** (the *mode selector* — consent presets — is configured in
the wizard/Settings; on an already-set-up install (`smly_plus_setup_completed=true`, e.g. MiuMjau)
the cron-safe contact-language + consent sync go live immediately on upgrade, same credentials, no
re-wizard. The legacy daily mass-sync behind the `en`-drift is cleared by `WPCronAuditor` on
upgrade **regardless** of wizard state — only the *live per-event* sync ownership and the mode
selector are gated by `setup_completed`). Both re-audits'
findings fixed; PCP on the ZIP clean except the intentional `Update URI`. **MiuMjau needs this build
deployed** to fix attribution; then the manual live block-checkout acceptance test.

**F3-48 Smaily contact-API live-walk — DONE & GREEN (2026-07-01, `bin/walk-f3-48-contact-sync.cjs`,
12/12 against the `smailydemo` SANDBOX).** Drives the real `Smaily\Connect\Smaily\Client` over live
Smaily: contact upsert code 101 with a SHORT `language` (`et`) + custom fields; `is_unsubscribed`
0→1→0 round-trip (F3-48.6) read back via `contact.php?email=`; absent-language upsert accepted
(omit=keep); `history.php` (reconcile delta) + `list=1` (rebaseline) + autoresponder-list shapes;
`autoresponder.php` accepts the `force_opt_in` param. Two LIVE divergences the mock hid, both now
handled in the walk (NOT plugin bugs — the form-encoded batch the Client sends returns 101 with a
valid domain): (1) live Smaily rejects RFC-6761 **reserved-TLD** emails (`.test`/`.example`/
`.invalid`) with code **203** "invalid data" — the walk uses `@example.com`; (2) `contact.php` is
**async** — an immediate readback after a 101 upsert misses (`206`), so the walk **polls**. Both
documented: LESSONS §2.14 + `re/docs/smaily-api/guides/gotchas.md` ("Reserved-TLD emails"). **Next
gate: Prike cutover** (Erkki installs Connect → wizard → preset 1 → Make off).
Pending follow-up: a Shopify-Connect feature-parity doc for the platform-agnostic changes (Erkki).

**F3-48 contact-sync mode engine — DESIGN APPROVED (Erkki, 2026-06-30); F3-48.1–.6 DONE (engine feature-complete).**
F3-48.5a (post-v3.2.0 UI refinement, Erkki 2026-06-30): in Step2Subscribers the mode-selector Card
is now gated on `state.subscriberSyncEnabled` and rendered **below** the "Contact synchronisation"
sync toggle (hidden when sync is off — the who-gets-synced question is moot then). The
"Checkout opt-in only" preset radio is `disabled` until the checkout subscription checkbox toggle is
on (`!state.checkoutSubscriptionCheckbox` → disabled + a hint line). Radio cards now use the shared
`Radio` primitive + the homepage card style (`border-brand bg-brand-soft-bg` when selected), matching
MultilingualModePicker instead of the hand-rolled `<input type=radio>`. TSX-only; ci:strict exit=0
(PHPUnit 456, vitest 161, tsc/eslint clean). Ships in the next release (after the F3-48 live-walk).
F3-48.6: consent opt-in/opt-out propagation (WP→Smaily) — a `user_newsletter` meta-transition
handler (consent mode) enqueues a separate `:consent` row (opt-in → is_unsubscribed=0, opt-out →
=1); routine data sync never sends is_unsubscribed. Fixed a latent bug found here: the Flusher
dropped the live contact-sync `language` (only the backfill sent it) — now forwarded. Regression
locks added. Gates: ci:strict exit=0 (PHPUnit 448, vitest 161), integration OK 119. **Remaining:
.7 — Prike cutover (Erkki installs the plugin, sets preset 1, Make off) + thorough end testing
(full gates + live-walk sandbox + security/code-quality re-audit + PCP against built ZIP + i18n
.pot/.po regen).**
F3-48.5: Settings/wizard UI — "Contact sync mode" Card in Step2Subscribers (3 radio presets +
legitimate-interest warning Banner + include_guests checkbox + preset-1-only force-opt-in toggle),
wired through the wizard reducer → buildTabPayload/hydrate/settings-reducer →
SettingsEndpoint::save_subscribers (validated) + EnvDetector::saved_settings (boot). New English
`__()` strings — .pot/-et.po regen + ET translation is a packaging step (bin/build-i18n.sh). Gates:
ci:strict exit=0 (PHPUnit 442, vitest 161, tsc/eslint clean), integration OK 119. Remaining: .6
is_unsubscribed opt-out + regression locks → .7 Prike cutover + thorough end testing.
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
- **Browse-event identity — RESOLVED (F3-49, 2026-07-03).** The beacon carried only
  `session_id`, so contract §6 per-event identity-resolution + retroactive-binding never
  fired from our stream and the async order-attribution path-3 was inert. Engine team
  confirmed browse does NOT feed attribution — order `smaily_rec_id` + email-click drive
  the `direct`/`exact_later`/`indirect_*` mix; browse would at best give the soft
  `assisted_view`. So browse still carries NO `smaily_rec_id`/`customer_email`
  (data-minimization), but NOW carries the opaque `smaily_visitor_token` (omit-on-empty)
  for the engine's future **cold-start personalization** binding (the engine binds the
  browse row via it; ingest already accepts the field). Profiling opt-out on the
  token/external_id path is engine-side (server-enforced 2026-07-03) — the plugin's
  email `ProfilingConsent` gate stays the first filter. Guest-browse-session-only is an
  accepted v1 limitation. See DECISIONS F3-49 + the "Browse browser-timing" item above.
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

- **Browse 0-events on CookieYes — RESOLVED (v3.3.2, F3-50, 2026-07-03).** The beacon is
  **fail-closed** on the WP Consent API (`detectConsent()` sends only when
  `window.wp_has_consent(category) === true`). MiuMjau saw 0 `/api/v1/ingest/browse` (while
  ping/orders/catalog were fine) because `window.wp_has_consent` was **undefined** live.
  **Root cause (corrected):** CookieYes DOES integrate the WP Consent API — but only when the
  free companion **"WP Consent API" plugin** (`wp-consent-api`) is installed (it defines
  `wp_has_consent`; CookieYes registers into it, `Advertisement`→`marketing`). MiuMjau just
  lacked that plugin. **3.3.1 mis-fix reverted:** it shipped a CookieYes-specific cookie-parser
  on the wrong assumption "CookieYes can't do the API" — Erkki caught it (per-vendor code =
  maintenance debt; CookieYes's docs prove it supports the standard). **3.3.2:** revert the
  vendor code (browse consent stays purely on WP Consent API + `consentOverride` hatch) + a
  `NotificationManager` admin advisory (browse on + connected + no `wp_has_consent` → "install
  the free WP Consent API plugin"). MiuMjau fix = install `wp-consent-api` (wp-admin, no file
  access). DECISIONS F3-50 / LESSONS §2.15. NB: there is NO `smaily_connect_beacon_consent` PHP
  filter (only JS `consentOverride` + `smaily_connect_beacon_consent_category`).
