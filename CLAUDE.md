# CLAUDE.md — Agent Working Guide (Smaily Connect plugin)

If you are a fresh agent picking up this repo: read this first, then `STATUS.md`
(where we are now), then `docs/RECENGINE_API_CONTRACT.md` (the contract you
build against), `docs/DECISIONS.md` (why things are the way they are), and
`docs/LESSONS.md` (mistakes already made — don't repeat them). README gives the
30-second project orientation.

This file is the operational knowledge that otherwise lives only in agent memory
and commit messages. It exists so you don't re-discover it the painful way.

---

## Keeping the docs current — part of every change

The handoff docs are only worth reading if they're true. Keeping them current is
not a separate chore; it's part of the work that changed them.

- **STATUS.md** — update in the SAME commit that finishes a sub-PR, syncs the
  contract, or changes a lock condition / roadmap. (Its own header states the
  rule.) Stale status = a defect, treat it like a failing test.
- **CLAUDE.md** (this file) — when you learn a new operational fact the hard way
  (a new `sg`-style gotcha, a build-command change, a new scar), add it here in
  the same commit. The whole point is that the next agent doesn't re-discover it.
- **DECISIONS.md** — when a decision is made, changed, or superseded,
  record it (with why, not just what). A reversed decision gets updated, not
  silently dropped.
- **LESSONS.md** — when a class of mistake is caught (especially mock-vs-live,
  context-audit, or seam bugs), add the lesson so it generalizes.
- **README roadmap / INDEX.md** — if you change what's done or which files
  exist, refresh these too. They went stale once (README said Customers/Orders
  were pending after they shipped); don't let it recur.

**The rule across all of them:** if your change makes a doc wrong, your change
isn't finished until the doc is fixed in the same commit. If you notice a doc is
already stale, fixing it is in-scope now, not a future task.

---

## Operational knowledge (the painful-to-rediscover bits)

### Integration tests need `sg docker`
The agent sandbox strips the `docker` supplementary group from the running
process, so a bare `docker info` looks like Docker is unavailable — it isn't.
Docker is installed and the daemon runs; you (the user `erkki`) are in the
`docker` group. Restore it per-command with `sg`:

```
sg docker -c "composer run test:integration"
```

Integration tests run real WP + WooCommerce + MariaDB via wp-env. Do NOT
conclude "Docker unavailable" from a bare `docker info` failure — use `sg`.

**Filtered/single-test integration runs:** the wrapper runs the suite in the
`…-tests-cli-1` container with `phpunit.integration.xml.dist`. A hand-rolled
`docker exec` into a `…-wordpress-1` container with the default
`phpunit.xml.dist` loads the UNIT bootstrap (no wp-load, no WooCommerce) —
the test then fails with `undefined function update_option()` / "WooCommerce
missing" even though the real suite is green. Correct form:

```
sg docker -c "docker exec wp-env-connect-<hash>-tests-cli-1 \
  php /var/www/html/wp-content/plugins/smaily-connect/vendor/bin/phpunit \
  --configuration /var/www/html/wp-content/plugins/smaily-connect/phpunit.integration.xml.dist \
  --filter <TestName>"
```

### Live-walk needs a fresh setup-token — from the SANDBOX tenant, never MiuMjau
**MiuMjau IS the pilot's PRODUCTION tenant** (engine-side correction,
2026-06-12 sync: the engine has exactly two tenants — MiuMjau and the
"Smaily Connect test" sandbox; there is no separate dev tenant). The
2026-06-12 walks ran against production and the engine team had to purge the
residue. **All future walks use the "Smaily Connect test" sandbox tenant** —
ask Erkki for a setup token from THAT tenant's Integrations page.

Also learned the hard way: under the engine's pre-0036 single-key model, a
token exchange ROTATED the tenant's only API key — the 2026-06-12 wp-env
exchange silently revoked the live pilot store's key mid-day. The engine now
issues per-connection keys (migration 0036), so this can't recur — but it's
the template for why dev work never touches a production tenant.

Mechanics (unchanged): the setup-token is **one-time** (consumed on
exchange) and connections get scrubbed by integration test runs (snapshot/
restore the `smly_rec_*` options around a suite run to keep one alive). When
a live-walk reports `is_connected = 0`:
- Ask the user to mint a fresh SANDBOX token (or a full setup URL
  `https://<engine>/setup/<token>`) into a `/tmp/smaily_re_setup_*` file
  (plain token/URL, secret-safe file method).
- Exchange it via the plugin's real SetupExchange + store() path (F3-12).
- Never echo the token; delete temp files after.

**Secret-safe exchange mechanic (used CC.3, 2026-06-14).** The exchange runs
inside the wp-env cli container (`wp eval-file`), which can't read the host
`/tmp`. Bridge the token WITHOUT putting it on any command line: write a
container-visible PHP that reads the URL from STDIN (`trim(fgets(STDIN))`),
calls `SetupExchange::parse_setup_url()` → `exchange()` → `$settings->store()`,
and prints only NON-secret fields (kind, tenant_name, connected). Pipe the file
in: `cat /tmp/smaily_re_setup_url | sg docker -c "docker exec -i <cli> wp
eval-file <script> --allow-root"`. `docker exec -i` forwards stdin; the token
never appears in args/output. **Always print the resulting `tenant_name` and
abort any send if it's `MiuMjau`** — the dev wp-env can be left pointing at
production. `bin/walk-cc3-multilingual.cjs` bakes this safety gate in
(`sandbox_tenant_not_production`); after CC.3 the dev env is connected to the
"Smaily Connect test" SANDBOX — keep it there.

### woocommerce-stubs are PHPStan-only
`woocommerce-stubs` is in the PHPStan config, NOT the runtime autoload. In unit
tests, WC objects are built with PHPUnit `createMock` + shared shims (e.g. the
`WC_Order` shim in HookHandlerTest, `WC_Order_Item_Product`). Reuse this pattern
for any new WC-dependent unit test.

### Use SkuResolver for the engine product key — never raw get_sku()
The engine keys catalog, order items, AND browse events on `sku`, but WC
doesn't require SKUs — the pilot store has none at all (F3-36). Every place
that puts a product key on the wire goes through `Support\SkuResolver`
(real SKU else synthetic `wc-{id}`; deleted-product order lines key from the
ids stored on the line item). A raw `get_sku()` + empty-check reintroduces
the pilot's day-1 breakage: silently empty catalog (pre-enqueue drop, no
Event Log trace), D6-failed orders, rejected browse events. If you add a new
SKU surface, use the resolver; if a record still can't be keyed, make it
observable (terminal skip), never a silent pre-enqueue drop (LESSONS §2.11).

### Order status: custom statuses go THROUGH; deleted-product lines are NEVER dropped (F3-42/F3-43)
Two pilot data-loss fixes (engine brief 2026-06-19, order #58922) that flip
earlier decisions — don't re-revert them:
- **Status mapping is a DENYLIST, not an allowlist.** `OrderPayloadBuilder::
  map_status()` sends `''` (skip) ONLY for `pending`/`on-hold`/`failed`/
  `checkout-draft`/`draft`/`auto-draft`/`trash`; **every other status — incl.
  any custom shipping status (`label-printed`, `shipped`, …) — defaults to a
  sale (`processing`)**. The old 5-key allowlist silently dropped custom
  statuses (the orders never reached the engine). `on-hold` is now NON-sale
  (reverses F3-22, per the engine team — payment not captured). The order
  backfill mirrors this: `status NOT IN (non_sale_wc_statuses())` (CC-9 single
  source). Engine `status` is a strict enum (`completed|processing|cancelled|
  refunded`) — a custom status must MAP to one, never pass through raw.
- **A deleted-product order line is never dropped.** `SkuResolver::
  resolve_order_item()` never returns `''` — when current WC has zeroed the
  stored ids it keys on the order-item id (`wc-oi-{item_id}`), so the line (and
  the whole order) is never silently lost (#58922 was marked "sent" with no
  POST because an empty `items[]` terminal-skipped + `mark_sent`). The empty-
  items terminal skip now only fires for a genuinely product-less order
  (shipping/fee only). Reverses F3-36's drop-the-line for the deleted case.

### Event Log "Details" shows the real request + engine response (F3-44)
Order/catalog/Smaily queue rows enqueue an EMPTY `payload` (the flusher builds the
wire object fresh at send), so Details used to show `Payload: []`. The flushers now
store the send-time exchange per row via `IngestQueue` / `EventQueue::store_exchange`:
`sent_payload` (the exact JSON POSTed; NULL on a terminal skip) + `last_response`
(`{http, outcome, error?}`). Migration 007 added both columns to BOTH queues. Rules
that must hold: **never store the Authorization header** — the Smaily `Client` captures
the exchange in `request()` from method/endpoint/body + reply, NOT the auth `$args`;
the Smaily `Flusher` reads `Client::last_exchange()` in a `try/finally` so a throwing
call is still captured; fields trim to ~10 KB and are janitor-pruned. A terminal-skip
stores `last_response={outcome:"skipped"}` — that's how you tell a row marked "sent"
that never actually POSTed (the #58922 confusion). Stored for ALL rows, success too.

### Trashing a product fires NO catalog hook — it's kept as `in_stock=false`
`before_delete_post` is **permanent-delete-only**; trashing routes through
`wp_update_post`, so a trashed product fires neither the delete nor (usefully)
the save hook. Left alone, a trashed-but-once-bought product silently keeps a
stale engine catalog row or has none — its order lines orphan the
`order_items.sku ↔ catalog.sku` join (the 2026-06-17 pilot ~4% miss, F3-40).
The fix keeps it in the graph as `in_stock=false` (engine has no delete-by-key;
a `catalog.delete` row IS an `in_stock=false` upsert): `Bootstrap` binds
`wp_trash_post → on_delete_product` and `untrashed_post → on_save_product`, and
`CatalogBackfillJob` enumerates `publish` **and** `trash`. **Trap (cost a green→
red integration cycle):** `wp_trash_post()` then calls `wp_update_post(trash)`,
which fires `save_post_product` → `on_save_product` AFTER the removal — re-upserting
`in_stock=true` and undoing it. `on_save_product` early-returns when the saved
post's status is `trash`; don't remove that guard. A *permanently* deleted
product can't be recovered (no WC data to build a row) — that's accepted, not a
bug. After any change here the pilot needs a catalog re-backfill.

### Use the IsoDate helper for datetimes — never raw format
The engine's strict Zod `.datetime()` requires Z-suffix (`Y-m-d\TH:i:s\Z`), NOT
`+00:00`. Raw `gmdate('c')` / `$date->format('c')` produces `+00:00` and the
engine rejects it. This bug shipped twice (customer `first_seen_at`, catalog
`on_sale_until`) before being caught by a live-walk. The fix is the shared
`IsoDate` helper (F3-21) — every builder uses it so the bug can't recur. Any new
datetime field goes through IsoDate.

### Contact-sync language goes through ContactLanguageResolver — never get_user_locale / get_current_language_code (F3-47)
The Smaily contact `language` code is resolved ONLY by `Support\
ContactLanguageResolver` (`for_user` / `for_order`). It is context-independent
(no `ICL_LANGUAGE_CODE`/`pll_current_language`/`get_user_locale` reads), so it
returns the same answer in a cron tick as an HTTP request. Sources mirror the
merchant's working Make automations: `_user_preferred_language` user meta →
most-recent order's `wpml_language` → the multilingual default via
`DetectorFactory` (WPML `wpml_default_language`, e.g. `et`) → site-locale short
code; normalised to the short form (`en_US`→`en`). The scar it routes around
(Prike, F3-47): the legacy cron's `Helper::get_current_language_code()` falls
back to `get_locale()` in cron = the WP **site** locale (`en`), which on an
`et`-content store with an `en` WP locale clobbered ~1000 contacts to `en`
daily. **Two rules:** (1) a NEW datetime-style sin — never reintroduce a raw
`get_user_locale()`/`get_locale()` language source on the contact path; route it
through the resolver. (2) **Omit `language` when the resolver returns `''`** —
Smaily treats absent as "leave existing intact", empty as "wipe"; the
HookHandler payload builders add the key only when non-empty. (3) The resolved
code is **clamped to the site's active languages** (`DetectorFactory::
get_detected_languages()`) — a code outside that set (dirty history, e.g. a
stray `ru` on an `et`/`en`-only store) falls to the default, so the sync can't
spawn a list that shouldn't exist; the resolver never invents a language, the
clamp just locks it. Contact sync is gated by `setup_completed` (email wizard),
independent of the rec-engine — so this can ship to a non-engine store. The corrective mass re-sync of an already-
drifted store is the backfill running the SAME resolver (SP-B), not a one-off.

### Build / test / walk commands
- `npm run ci:strict` — PHPCS + PHPStan + PHPUnit unit + JS (eslint/tsc/vitest).
  Must be `exit=0`.
  **vitest-green ≠ typecheck-green.** vitest runs through esbuild, which STRIPS
  TS types without checking them — a wrongly-typed test (e.g. a mock object with
  the wrong field shape) RUNS fine under `npm run test` but fails `npm run
  typecheck` (tsc). So `npm run test` passing tells you nothing about types.
  Always run the full `ci:strict` chain (it runs tsc after vitest), never just
  `npm run test`. (Scar 3.5.3a: a `getBackfillStatus` mock used camelCase
  `etaSeconds` instead of the API's snake_case `eta_seconds`; vitest green, tsc
  red, ci:strict exit=2.)
- `sg docker -c "composer run test:integration"` — real-environment integration.
- Live-walk scripts live in `bin/` (e.g. `bin/walk-3.3.cjs`). Run against the
  connected engine; needs a setup-token (above).
- `composer run package` — produces the distributable ZIP.

(Verify exact paths/scripts against the repo — this list is the working set as
of orders ingest; update if the build evolves.)

### Audits live in `docs/audits/` — re-run after bigger changes
All audit reports + the register table live in `docs/audits/` (start at
`docs/audits/INDEX.md`): the Fable codebase audit, the Security audit, the
Code-quality + wordpress.org-readiness audit (carries the GA/upstream punch-list),
the upstream audit + comparison, and the mock↔engine divergence register.

An audit is a snapshot of one repo state — it goes stale as code moves (the
2026-06-25 audits exist because ~10k lines landed after the 2026-06-11 one). So
**re-run the security + code-quality audits after a bigger change** — concretely:
before any GA/non-beta tag or wordpress.org submission; after a large delta
(rule of thumb > ~2,000 changed plugin lines); or after any change to a
security-sensitive surface (a REST route, the public `/relay` beacon,
auth/capability/nonce, crypto, custom-table SQL, what gets stored/logged
(secrets/PII), GDPR/consent, external HTTP/file I/O). Scope = the delta since the
last audit's baseline + a security pass on any high-risk surface it touched + PCP
**against the built ZIP** for a release gate. Record the run as a row in
`docs/audits/INDEX.md` + a dated report, and note it in STATUS.md. Skipping the
re-audit on a bigger change is a defect, like a skipped gate. The full policy is
in `docs/audits/INDEX.md` § Re-audit policy.

**Running PCP (WordPress Plugin Check):** in wp-env (PCP plugin installed via
`wp plugin install plugin-check --activate`). **Run it against the BUILT ZIP, not
the dev tree** — `composer run package` → `docker cp` the zip into the `…-cli-1`
container → unzip into `wp-content/plugins/<dir>` → `wp plugin check <dir>
--slug=smaily-connect --format=csv --allow-root --exclude-directories=vendor`.
Two gotchas that cost real time (2026-06-25, the pre-3.0 PCP-clean pass):
- **The dev-tree run UNDER-reports.** `wp plugin check smaily-connect` (the mounted
  working tree) hides `dist/` duplicate templates, the `blocks/` tree, and which files
  actually ship; it reads clean while the ZIP is not. The packaged ZIP is the only
  honest gate. (Conversely it also trips on dev-only `*.zip`/`.github`/`*.md`/configs
  that `.zipignore` excludes — noise, not findings.)
- **Always pass `--slug=smaily-connect`.** PCP infers the expected text domain from
  the plugin DIRECTORY name; unzip to any other dir (`smaily-connect-pkg`, …) and every
  `__( …, 'smaily-connect' )` call becomes a false `TextDomainMismatch` (hundreds of
  them). `--slug` pins the expected domain.
- For the **real release ZIP**, `composer install --no-dev --optimize-autoloader`
  before `composer run package` (else the ZIP ships phpunit et al.); ship `composer.json`
  (PCP flags `missing_composer_json_file` when `vendor/` ships without it).
Two findings are intentional and remain until specific milestones: `plugin_updater_detected`
(the `Update URI` clobber-guard, F3-35 — removed at the upstream merge) and, while still
a beta, `mismatched_plugin_name` (the `(BETA)` Name suffix — dropped at the 3.0 GA bump).

### React admin i18n — rebuild with `bin/build-i18n.sh`, never plain `compile-translations`
The React admin UI strings are wrapped with a thin `wp.i18n` shim
(`admin/src/lib/i18n.ts`, called as `__( 'text', 'smaily-connect' )`); the bundle
reads `window.wp.i18n` at runtime (it does NOT bundle `@wordpress/i18n`), and
`admin/wizard.php` enqueues the bundle with a `wp-i18n` dependency +
`wp_set_script_translations`. Two gotchas make the standard `compile-translations`
WRONG for this, so use **`bin/build-i18n.sh`** (it needs the wp-env container):
- **`wp i18n make-pot` cannot parse `.tsx`** (the bundled WP-CLI uses a PHP ES parser
  that chokes on TypeScript → it silently extracts ZERO admin strings). The script
  first **esbuild-transpiles `admin/src/` → a throwaway `_i18n-src/` of plain JS** so
  make-pot can see the `__()` calls.
- **`make-json` hashes its output to its own scheme**, but WordPress loads the
  script-translation JSON by `md5()` of the script path **relative to the plugin dir**
  — `dist/admin/admin.js` → `smaily-connect-et-464ceaab21588225a35cae9f83dfa47d.json`.
  The script builds the combined catalog (via a `--use-map`) and **renames it** to that
  fixed name. (The hash is stable; the path never changes.)
- The **committed** i18n source is `languages/smaily-connect.pot` + `…-et.po`. The
  `*.mo`/`*.json` are gitignored build artifacts (shipped in the ZIP via rsync, NOT
  git) — `bin/build-i18n.sh` regenerates them from the `.po`. Run it before packaging
  whenever admin strings or translations changed; the `.po` translations survive
  (`update-po` preserves `msgstr`). Verify a real render with the Playwright check
  (set the dev site to a locale, confirm `wp.i18n.__()` returns the translation).

### Cutting a release ZIP + GH pre-release (the full local sequence)
`composer run package` ALONE is not a release — it rsync+zips the working tree
but does NOT build the JS/blocks/translations, and `dist/`, `vendor/`,
`blocks/*/build/` are gitignored. The CI `release.yml` is INCOMPLETE (it never
runs the admin vite build and its `compile-translations` step has no wp-cli, so
it fails) — so the authoritative ZIP is built LOCALLY. Full sequence (verified
2026-06-14, v2.1.0-beta.3-rc.1):
1. Bump version in FOUR places: `smaily-connect.php` (Version header +
   `SMAILY_CONNECT_VERSION` + `SMAILY_CONNECT_PLUGIN_VERSION`), `package.json`,
   `readme.txt` (Stable tag + Changelog + Upgrade Notice). Also the test pins:
   `tests/Unit/ConstantsTest.php`, `tests/bootstrap.php`,
   `tests/phpstan-bootstrap.php` (else ConstantsTest fails). Commit FIRST so
   `package:hash` stamps a clean (non-`-dirty`) build-hash.
2. `npm run build:admin && npm run build:client` → `dist/admin/*`,
   `dist/public/js/beacon.js`.
3. `composer run install-block-modules && composer run build` → `blocks/*/build/*`
   (the first installs `blocks/node_modules`; without it `wp-scripts` is missing).
4. Translations: run **`bash bin/build-i18n.sh`** (needs the wp-env container) to
   rebuild `languages/*.mo` + `*.json` — including the admin-bundle catalog
   `…-et-464ceaab….json` — from the committed `.po`. The plain `compile-translations`
   composer script does NOT produce the correct admin-bundle JSON (see the i18n note
   above). Skip only if no admin strings or `.po` translations changed AND the
   `*.mo`/`*.json` already on disk are current (they are gitignored, shipped from disk).
5. `composer install --no-dev --optimize-autoloader` (prod vendor) →
   `composer run package` → `composer install` (restore dev so tests work again).
6. VERIFY the ZIP before releasing: version string; required present
   (`dist/admin/admin.js`, `dist/public/js/beacon.js`, `blocks/*/build/*`,
   `vendor/autoload.php`, `languages/*.mo`); NOT present (`tests`, `docs`,
   `node_modules`, `admin/src`, `dist/client`, dev vendor pkgs). `.zipignore`
   excludes `blocks/node_modules` (583M) — a bloated ZIP means it leaked.
7. **`gh release create … --repo erkkimarkus/smaily-wordpress-plugin`** — the
   `--repo` is MANDATORY: `gh` defaults to `upstream` (sendsmaily) and 404s
   (no write access). Tag convention `v<version>-rc.<N>`, `--prerelease`,
   `--target main`, attach `smaily-connect.zip`. `release.yml` fires on publish
   but fails harmlessly (no wp-cli) → does NOT clobber the attached asset
   (confirmed: prior releases' release.yml runs are all red too).

### CI "Lint and test the codebase" is PRE-EXISTING red on main — not authoritative
The GH workflow runs `composer run test:php` (= bare `phpunit`, includes the
Integration suite) in a runner WITHOUT WooCommerce → ~76 "WooCommerce not active"
errors. It has been red since before the catalog-correctness work (e.g. e22a26b,
2026-06-12). Do NOT read a red "Lint and test" as "I broke something." The
authoritative gates are LOCAL: `npm run ci:strict` (unit + static + JS) and
`sg docker -c "composer run test:integration"` (real WP+WC via wp-env). If you
touch CI, the fix is to run only `phpunit --testsuite unit` there (or give the
integration job a wp-env), not to chase the integration errors.

### Browse beacon ships as `sc-runtime.js` + `/relay` — NOT "beacon" (ad-block lists)
The storefront beacon's two browser-visible names are deliberately neutral: the
script is `dist/public/js/sc-runtime.js` (vite entry key `public/js/sc-runtime`,
source file still `public/js/beacon.ts`) and the proxy route is
`/wp-json/smaily-connect/v1/relay` (`BeaconEndpoint::ROUTE`). The word **"beacon"**
is on EasyPrivacy ad-block filter lists and was blocked for real pilot users (the
POST 404'd until the ad-blocker was disabled — F3-41). Do NOT rename these back to
"beacon", and don't introduce new browser-facing tracker-keyword names (track,
collect, analytics, pixel, telemetry…). Internal names (the `StorefrontBeacon` /
`BeaconEndpoint` classes, `beacon.ts`/`beacon-core.ts`, `window.smailyConnectBeacon`,
the `beaconUrl` config key) keep "beacon" on purpose — they're not browser-visible,
so renaming them is churn for no benefit. Whether a blocker still catches `/relay` is
a **manual browser check** (200 with the blocker on); the integration test only proves
the server dispatches `/relay`.

### Browse browser-timing is NOT live-walk-covered (manual pilot check)
Browse (3.4) is client-originated telemetry, so unlike catalog/customers/orders
the live-walk (`bin/walk-3.4-browse.cjs`) proves only the server side:
proxy→engine §6 + the abuse filter + the engine accepting all 9 event types.
The **browser MOMENT** a page-view fires — `checkout_start` on the checkout
page, `checkout_complete` on order-received, `product_view` on a product page —
is NOT observable from a server-side proxy walk. Coverage is split:
- engine accepts the types → live-walk (9-types check);
- JS maps `pageType` → event → vitest (`beacon-core.test.ts`);
- PHP picks the `pageType` from WC conditional tags (`StorefrontBeacon::
  page_context`) → only the `other` default is integration-tested. The harness
  is plain `TestCase` (no `WP_UnitTestCase`/`go_to()`), so `is_checkout()` /
  `is_product()` can't be driven to exercise the branches — writing a test that
  faked them would prove nothing. The conditional-tag branching is trivial; the
  real "does it fire on the right page" check is **manual pilot verification**
  (or a future Chromium E2E — not built, YAGNI, low risk).

Do NOT claim the live-walk validates checkout/page-view timing — it validates
the engine contract, not the browser render moment.

### Rec attribution capture is SERVER-SIDE (LandingCapture) — separate from the browse beacon
`Integrations\WooCommerce\LandingCapture` (F3-46) captures the recommendation
attribution params an email rec link carries (`smaily_rec`/`smaily_vt`/`smaily_ctx`,
or `utm_content` guarded by `utm_source=smaily`) into the first-party cookies the
checkout already stamps onto the order — on `template_redirect`, **ungated by the
browse beacon's toggle/consent/ad-block path**. This is the missing piece behind the
pilot's "374 orders / 0 `smaily_rec_id`": the cookie producer used to be JS-only
(`StorefrontBeacon`/`captureUrlParams`), which never ran with browse-tracking off.
Two things that must stay true:
- **It writes the SAME cookies `HookHandler::save_attribution_cookies_to_order()`
  reads** (`smaily_rec_id`/`smaily_rec_uid`/`smaily_rec_ctx`, names+TTLs from the
  engine config — the contract §"Cookie names", NOT the brief's `smre_*`/90d). Do not
  rename to a parallel cookie set; the whole point is zero downstream change.
- **Attribution capture is consent-UNgated (Erkki, F3-46)** — first-party functional
  signal, gated only on `is_connected()` + the `smaily_connect_capture_attribution`
  filter. Browse telemetry (Layer 2) stays consent-gated (StorefrontBeacon). Don't
  fold attribution back behind the browse consent gate.
- **`headers_already_sent()` is a test seam** — PHPUnit's own progress output makes the
  bare `headers_sent()` true mid-suite, so both the unit and integration tests override
  it; never inline `headers_sent()` back or the write-path tests can't run.

The click→land→buy→attribute round-trip (does the cookie set on a real landing, does a
test purchase carry `smaily_rec_id`, does the engine credit it via path-1) is a **manual
pilot check** — like browse timing, the server path is unit+integration-proven but the
browser moment isn't live-walk-coverable.

### OrderBackfill — which storage path the tests actually cover (HPOS vs legacy)
OrderBackfillJob (3.5.2) reads orders with a direct `WHERE id > cursor` query
against whichever table is active — `wc_orders` (HPOS) or `wp_posts` (legacy) —
detected via `OrderUtil::custom_orders_table_usage_is_enabled()`. The table +
column mapping is a pure method (`OrderBackfillJob::table_spec`).

**The wp-env test env runs WC 10.7 with HPOS ENABLED** (orders in `wc_orders`,
zero in `wp_posts`). So:
- the **HPOS path is INTEGRATION-tested** (RecEngineOrderBackfillTest runs
  against real `wc_orders`);
- the **legacy path is UNIT-tested only** (`OrderBackfillJobTest::table_spec`) —
  it is structurally identical (same WHERE shape, different table/columns) but
  is NOT exercised against real `wp_posts` orders in this env.

The PILOT is WC 6.9.4 → **legacy storage** (HPOS only defaults at WC 8.2+). So
the pilot's actual path is the unit-tested-only one. Low risk (the SQL is the
same shape, table_spec-verified), but if a legacy-storage order-backfill issue
surfaces, reproduce it against a LEGACY WC env — the HPOS-mode wp-env won't show
it. Do NOT assume "integration green" covers the legacy order path.

### Integration baseline is WP 7.0; the pilot stack needs an override to reproduce
Since 2026-06-11 `.wp-env.json` pins `core: WordPress/WordPress#7.0` (Erkki's
call: new work targets 7.0; the earlier WP 6.9.4 baseline was an interim step).
The PILOT still runs the OLD stack — WC 6.9.4, legacy order storage, older WP —
so a pilot bug may NOT reproduce on the default env. To stand up a
pilot-faithful env, drop in a `.wp-env.override.json` (gitignored-by-use,
delete after) like the legacy-WC verification used:

```
{ "core": "WordPress/WordPress#6.9.4", "phpVersion": "8.1",
  "plugins": ["https://downloads.wordpress.org/plugin/woocommerce.6.9.4.zip",
               "https://downloads.wordpress.org/plugin/polylang.latest-stable.zip"] }
```

then `npx @wordpress/env start --update` (NOT `npx wp-env` — that alias only
prints a deprecation notice and exits 0 WITHOUT starting, which silently looks
like success), reset the carried-over HPOS options so `is_hpos()=false`, run
the suite, and restore the default env afterwards (delete the override +
`start --update` again). See the go-live checklist entry in STATUS.md for the
original WC 6.9.4 walk-through.

### Endpoints-map URL placeholder is `{email}`, not `%s` — substitute, don't sprintf
The engine's endpoints-map advertises the GDPR customer URLs (§8/§9/§10) with a
literal `{email}` token: `…/customer/{email}/export`. The email goes in the URL
**path** (rawurlencoded) and the substitution convention is `{email}`. 3.8.0
shipped `sprintf(resolve_url(…), rawurlencode($email))` — a silent no-op on a
`{email}` URL, so the literal `{email}` was sent and the engine 404'd (`No
customer with email '{email}'`). The unit + mock endpoints maps had used `%s`,
mirroring the bug → all gates green; only the LIVE engine used `{email}`, so only
the 3.8.1 live-walk caught it. Use `Client::customer_url()` (`str_replace`), keep
fallback `PATH_CUSTOMER_*_TMPL` constants on `{email}`, and seed mock/unit maps
with `{email}` (the mock 422s on a literal-placeholder email). General rule: a
URL from the endpoints-map carries the engine's placeholder syntax — confirm it
(`{name}` vs `%s`) before picking a substitution function; a 404 echoing an
un-interpolated token is YOUR request, not an engine bug. (LESSONS §2.9.)

---

## How we work (the rhythm)

### Sub-PR rhythm with human checkpoints
Work proceeds in small sub-PRs (e.g. 3.3.0, 3.3.1...). Each one:
1. Plan first — state scope, files, surface edge cases, **report the plan and
   wait for go-ahead before coding.**
2. Code + tests.
3. Gates green: `ci:strict` + integration (`sg docker`) + relevant regression
   (e.g. catalog/customers regression must stay green when shared code changes).
4. Report at the end (results, gate output, anything surfaced) **before** the
   next sub-PR. Push directly to main per project rhythm.

Do NOT batch multiple sub-PRs without a checkpoint. The human participates at
edge cases and strategic decisions, not every line — but the checkpoint between
steps is the safety rail.

### Context audit before building (LESSONS §2.5)
Before starting real code on a new area, do a context audit: `git log`, read the
relevant DECISIONS entries + the fresh contract section + the template code you
are mirroring. This is how the queue silent-loss (3.3.0), the topple-enqueue
(3.3.3), and the guest-flusher question were caught **before** coding rather
than in a live-walk.

### Spec sync is byte-identical (CC-8)
When the engine team changes the contract, sync `docs/RECENGINE_API_CONTRACT.md`
byte-for-byte with the engine repo: replace the file, `git diff` + md5 confirm
identical, commit with a message naming the engine commit + what changed, push.
The embedded header note records the sync. This discipline has caught real
bugs (products->items drift, datetime Z-form, the seam bugs).

**A sync is NOT code-complete.** Syncing the doc byte-for-byte does NOT mean the
plugin code follows. The W2 catalog wrapper rename (`items`->`products`) synced
into the doc but the code kept sending `items`; the mock (still on `items`) hid
it for five syncs until the first catalog live-walk after W2 (N-7.1) caught the
`400`. So after every sync that changes a **wire shape** (wrapper key, a required
field, an enum, a removed field): (1) check the real plugin code follows in the
same pass; (2) move the **mock to the new shape in the same sync** (else it masks
the drift); (3) run that endpoint's live-walk (or one curl) before calling it
done. Don't let a breaking change wait for an unrelated sub-PR to surface it.
(LESSONS §2.7.)

---

## Things NOT to do (each is a scar)

- **Don't assume a wire shape — live-probe it.** Catalog was coded to `products`;
  the live engine wanted `items`; the mock (built to the same wrong assumption)
  hid it until the 3.2.4 live-walk. For customers we probed the wrapper key
  before locking it (3.3.1) and avoided the repeat. If a contract detail could
  diverge, send one live request before committing to it.
- **Don't repeat the datetime bug** — use IsoDate (above).
- **Don't invent real-world facts** — URLs, prices, versions, support links.
  A fabricated `smaily.com/support` URL shipped once (correct is
  `https://smaily.com/help/`). If you don't know, check or ask; don't construct
  a plausible-looking value.
- **Don't trust mocks for format validation.** Mocks validate loosely; the
  engine's Zod is strict. Every formatted field (wrapper key, datetime, enum) is
  a mock-vs-live divergence risk. Live-walk must cover each formatted field, not
  just happy-path structure. (LESSONS §2.3, §2.4.)
- **Catalog-flusher D6 consolidation (lock RESOLVED in N-7.1).** This was a hard
  lock condition: while the catalog flusher was all-or-nothing it would mark
  engine-rejected products SENT (silent loss). N-7.1 moved it onto the shared
  `AbstractD6Flusher`; the catalog live-walk proves the split against the real
  engine (`flusher_d6_split_lock_proof`: a no-SKU product comes back as a D6
  per-item error and is marked FAILED, the valid one SENT). Keep it on the D6
  base — do not reintroduce an all-or-nothing 2xx success path.
- **Don't trust a sync as code-complete** — see the CC-8 note above. A wire-shape
  change in a sync (wrapper key, required field, enum, removed field) must be
  verified against the plugin code AND the mock in the same pass, then live-walked.
  The W2 `items`->`products` drift hid for five syncs because none of these
  happened. (LESSONS §2.7.)

---

## Coexistence map (legacy vs new)

- Legacy namespace `Smaily_Connect\*` + new `Smaily\Connect\*` coexist.
- Two independent feature flags / gates:
  - `setup_completed` (email wizard) — switches Smaily contact-sync legacy ->
    new. Coordinates the legacy<->new Smaily-sync path.
  - `is_connected()` (rec-engine, Step 4, optional) — gates rec-engine ingest.
    Independent of the email wizard. Rec-engine ingest fires iff the engine is
    connected, regardless of wizard state.
- These target **different destinations** (Smaily contact-API vs rec-engine), so
  there's no double-sync conflict. The CustomerHookHandler (rec-engine) uses
  `is_connected()`; it enqueues all registered users (A-filter, F3-20), matching
  the email-sync handler's breadth (neither filters by role).

---

## Architecture pattern (every ingest domain follows this)

PayloadBuilder (WC object -> wire shape) -> IngestQueue (generic, carries
event_type + entity_id + payload + event_uuid) -> Flusher (batch flush, D6
errors[] parse, Action Scheduler job) -> HookHandler (WC hooks -> enqueue).
Catalog established it (F3-16); customers mirrored it (F3-19); orders mirrors it
again. The queue is shared and event_type-scoped (each flusher drains only its
own event types — this prevents one flusher consuming another's rows).

D6 contract (F3-18): batch ingest returns `200 {ok, processed, deduplicated,
errors:[{index, <natural_key>?, field, message}]}`. Invariant: `processed +
deduplicated + errors.length == total`. The flusher maps `errors[].index ->
batch_rows[index]` (index-aligned parallel arrays), marks errored rows failed,
the rest sent. CustomerFlusher is the reference implementation.
