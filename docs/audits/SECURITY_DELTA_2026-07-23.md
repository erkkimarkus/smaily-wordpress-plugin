# Security delta audit — v3.9.0 release gate (PRO-1504/1518/1519/1506/1517 + Update URI removal)

- **Date:** 2026-07-23
- **Baseline:** delta `123d479..69a83b8` (last-audited v3.8.1 release-gate tip
  to the pre-cut main tip; 21 commits — PRO-1506 flush-time tombstone repair
  (`8751a8b`/`17a1c6d`), PRO-1504 Stage 1 config surface (`1422e6c`/
  `e680bab`/`124a2ac`), PRO-1504 simplify pass (`e0d5b58`), PRO-1504 Stage 2
  sender/suppression/fail-open (`58c8565`/`7f933b0`/`584064d`/`4dba01b`/
  `91f1825`), a further simplify pass (`b7145ab`/`db87688`), PRO-1518
  Store-API order-confirmation twin (`0b3d652`/`7859e48`/`c2f2462`), PRO-1519
  bounded retry ceiling (`12d1d61`/`65593ce`), PRO-1517 mock-fidelity fixes
  (`33fd02f`), the F3-35 `Update URI` header removal (`69a83b8`)). 5255
  insertions / 153 deletions across 46 files — well over the >2,000-line
  policy threshold, and independently a release boundary (policy point 1).
- **Auditor:** Claude (Fable 5)
- **Trigger (re-audit policy):** release boundary (cutting v3.9.0) — policy
  point 1 applies regardless of delta size. Also qualifies on point 2: a
  brand-new outbound-HTTP send surface (`Client::send_message()` +
  `TransactionalFlusher`), new REST-settings fields (`SettingsEndpoint`), new
  credential storage (a second Smaily account), and new WC hook bindings
  (`Bootstrap`) — all named high-risk surface categories.
- **Scope:** full delta read, file-by-file, via `git diff 123d479..69a83b8`.
  Deep adversarial pass on the brief's six focus areas (below). PCP against
  the built ZIP is explicitly OUT of scope for this pass — the release-build
  worker runs it later today as part of the v3.9.0 cut.

## Verdict

**0 Blocker, 0 Critical/High. 1 Should-fix (Medium: unescaped merge-tag
text fields in the new transactional-email `context` payload) — FIXED
same-day (PRO-1537, see the finding's Resolution note below). 0 Low. 2
Info (accepted, noted below). RESULT: v3.9.0 may proceed; the should-fix is
recorded as a fast-follow, not a release blocker** (rationale in the
finding).

---

## 1. Transactional-email credential storage (`Client`, `SettingsEndpoint`, `Credentials`)

- **Storage mechanism is reused, not new.** `SettingsEndpoint::
  save_transactional_emails()` persists the transactional account's
  subdomain/username/password through the SAME `persist_credentials()`
  helper the default and per-language accounts already used (the commit
  `e0d5b58` "dedupe credential-persist" refactor extracted one shared method
  from what were three copy-pasted blocks) — same `encrypt_password()` call,
  same option shape (`Credentials::PHASE2_OPTION_PREFIX . 'transactional'`),
  same "empty inbound password = leave the stored secret as-is" rule that
  prevents the wizard's password-omitted boot payload from clobbering the
  encrypted secret on a second save. **No new crypto surface.**
- **Never echoed in the boot payload.** `EnvDetector::saved_settings()`'s
  new `transactionalCredentials` block hardcodes `'password' => ''`
  (verified by reading the diff directly) — mirrors the existing default-
  account security gate byte-for-byte. The stored password never reaches
  the browser.
- **`Client::send_message()` reuses the single `request()` chokepoint.**
  The new JSON-body `$json` flag only changes encoding
  (`Content-Type: application/json` + `wp_json_encode($data)` instead of
  form body); the `Authorization: Basic …` header assembly and the
  `last_exchange` capture (method/endpoint/body request, http/body
  response — no header) are the SAME code path every other Client method
  already goes through. **Confirmed directly tested**:
  `ClientTest::test_send_message_last_exchange_never_carries_the_auth_header`
  asserts the string `Authorization` doesn't appear anywhere in
  `wp_json_encode($exchange)`. The F3-44 "never store the Authorization
  header" rule holds for the new path.
- **`TransactionalFlusher::record_exchange()`** (the queue-side capture, a
  distinct code path from `Client::last_exchange()`) stores only
  `$this->current_exchange['request'|'response']`, which is itself sourced
  from `Client::last_exchange()` — no independent capture of the auth
  header, no new field that could carry it.

**Conclusion: clean.** No secret is newly logged, echoed, or stored outside
the existing encrypted-option + F3-44-safe-exchange pattern.

## 2. Send/gate/suppression/fail-open logic

- **Default-off is real, not just a documented convention.**
  `TransactionalGate::resolve_if_open()` reads
  `get_option( self::OPTION_ENABLED, false )` — an unconfigured store has no
  such option, so the `false` default applies and the gate returns `null`
  (no send) on the very first condition, before touching the resolver or
  credentials. **Directly tested**:
  `TransactionalGateTest::test_master_toggle_off_closes_the_gate`. The two
  new WC hook bindings in `Bootstrap::init_hooks()` are registered
  UNCONDITIONALLY (the class's own comment: "TransactionalGate self-gates
  every attempt, so registering unconditionally is safe") — confirmed this
  is genuinely safe: every one of `on_order_processed()` /
  `on_block_checkout_order_processed()` / `on_order_status_changed()` routes
  through `attempt()`, which calls `$this->gate->resolve_if_open()` before
  doing anything network-visible. With the toggle off, zero HTTP calls,
  zero queue rows, zero order-meta writes (confirmed by reading `attempt()`
  directly, and by the STATUS.md-recorded integration test "everything-off
  is a verified zero-behavior-change no-op").
- **No forged/unauthenticated trigger.** All three hook bindings are
  internal WordPress `do_action()` hooks fired only from real WooCommerce
  order-processing code (`WC_Checkout::process_checkout()`, the Store-API
  checkout controller, `WC_Order::status_transition()`) — none is a REST
  route, none is reachable by an unauthenticated HTTP request directly.
  `on_order_processed()`'s and `on_order_status_changed()`'s optional
  `?\WC_Order $order = null` fallback (`wc_get_order((int) $order_id)`)
  only matters if a third party re-fires the action manually with fewer
  args — it still resolves a REAL order from the DB by id, it can't be
  pointed at attacker-supplied data.
- **Fail-open cannot loop or double-send.** `fail_open()` is guarded by the
  SAME order-meta value (`META_STATUS_FAILED_OPEN`) it writes — a
  second call for the same order+trigger short-circuits at the `if (
  (string) $order->get_meta( $meta_key ) === self::META_STATUS_FAILED_OPEN
  )` check before touching the mailer. The native-email re-fire
  (`TransactionalSuppression::fire_native_bypassing_suppression()`) uses a
  request-scoped static `$bypass` flag reset in a `finally` block, so a
  throwing `trigger()` call can't leave the class permanently
  bypass-suppression. **Directly tested**
  (`TransactionalFlusherTest`, `TransactionalSuppressionTest`).
- **Retry cannot double-send either.** `send_now()` writes
  `META_STATUS_QUEUED` before dispatch; `attempt()` in the hook handler
  checks `$order->get_meta( $meta_key ) !== ''` and returns early on ANY
  non-empty value — queued, sent, or failed_open all count as "already
  attempted", so a second hook fire for the same order (the PRO-1518 twin-
  hook case, or a repeated shipped-status flip) is a genuine no-op, not a
  retry. The async `flush()` path only ever pulls rows still `pending`
  (`$this->queue->pending(...)`) — a row that reached `mark_sent` or
  `mark_failed` is never re-selected. **Directly tested**:
  `TransactionalEmailHookHandlerTest::
  test_order_confirmation_is_idempotent_across_both_checkout_hooks`,
  `test_repeated_flips_into_the_shipped_set_do_not_resend`, plus the
  integration-level "firing both hooks for the same order still sends
  exactly once" test recorded in STATUS.md.
- **The PRO-1519 retry ceiling is correctly scoped and bounded.**
  `enforce_retry_ceiling()` first checks `event_type` against the two
  transactional types by name (`in_array(…, true)`) before doing anything
  time-based — a marketing-side row from the shared `EventQueue` can never
  be aged out by this class even if it somehow reached `process()` (it
  can't: `flush()`'s own `pending()` call already scopes to the two
  transactional types). The ceiling check reads `created_at` off the row
  (absent for `send_now()`'s synchronous first attempt — a fresh row is
  never past-ceiling) and throws the same `TerminalDispatchException` a
  deterministic Smaily rejection throws, routing through the existing
  `mark_failed` + `fail_open()` path — no new fallback branch that could
  itself become a fail-open loop. One hour, checked every AS tick (60s) —
  bounded, not unbounded-fail-open.

**Conclusion: clean.** The gate is genuinely default-off, unreachable by a
forged/unauthenticated request, and both the fail-open path and the retry
ceiling are structurally incapable of a double-send or an infinite loop —
confirmed by direct code read plus the unit/integration tests that pin
exactly these properties.

## 3. REST/settings surface (`SettingsEndpoint`, `EnvDetector`)

- **No new REST route.** `SettingsEndpoint::register()` is unchanged in
  this delta — the transactional fields ride the SAME existing
  `POST /wp-json/smaily-connect/v1/settings` route, under the SAME
  `permission_check()` (`current_user_can( Constants::CAPABILITY )`, i.e.
  `manage_options`) and the SAME REST-core nonce enforcement every other
  tab already had. `handle()`'s `tab` allowlist (`VALID_TABS`) is untouched
  — the transactional fields arrive inside the existing `'woocommerce'` tab
  payload (verified: `save_transactional_emails( $data )` is called from
  inside the same handler branch that already persists the cart-cutoff
  option, no new `$tab` branch was added).
- **`shippedOrderStatuses` sanitization is a real allowlist-shaped
  cleanse.** `sanitize_key()` per entry + `array_filter` drops any
  empty/invalid result — this can only ever produce WP-safe option-value
  strings, not markup or SQL.
- **The `VALID_TRIGGER_TYPES` allowlist genuinely closes the gap the Stage 1
  STATUS entry named** ("previously any string reached an INSERT"):
  `replace_automation_mappings()`'s per-row loop now does
  `if ( ! in_array( $trigger, self::VALID_TRIGGER_TYPES, true ) ) { continue;
  }` before any other field is read — a garbage `trigger_type` never reaches
  the mapping table's INSERT. Confirmed by reading the diff: the check
  literally subsumes the pre-existing `$trigger === ''` guard (empty string
  is not a member of the allowlist either).
- **`EnvDetector::order_statuses()`** reads only `wc_get_order_statuses()`
  (an internal WC registry call, no request input) — no new input surface,
  just a new read-only boot-payload field.

**Conclusion: clean.** No new route, no new auth bypass, sanitization on
every new writable field, and the allowlist fix is a genuine narrowing (not
just documented — code-verified).

## 4. Store-API / block-checkout twin (PRO-1518) — idempotence + surface width

Already covered under §2's idempotence analysis (the meta-guard-before-
dispatch property is the SAME mechanism regardless of which hook fired
first). No widening of any unauthenticated surface:
`woocommerce_store_api_checkout_order_processed` is WooCommerce Blocks' own
internal action, fired server-side after the Store API's own checkout
processing completes — not a route this plugin registers, and firing it
requires already having gone through WC's real checkout validation
(payment, stock, etc.). Same class of hook as the pre-existing F3-46
`LandingCapture`/`HookHandler::on_block_checkout_order_processed()`
precedent this commit explicitly mirrors.

## 5. PRO-1506 flush-time tombstone repair — injection/escaping check

`IngestFlusher`'s new `catalog.delete` branch calls two PRE-EXISTING
`CatalogPayloadBuilder` methods (`ensure_valid_removal()`,
`build_unresolvable()`) at a new call site — **`CatalogPayloadBuilder.php`
itself is untouched in this delta** (confirmed: `git diff` on that file
between the two commits is empty). The placeholder-URL construction
(`home_url( '/?smaily_connect_removed_product=' . $product_id )`) was
already reviewed in the 2026-07-21 v3.8.1 delta audit (`$product_id` is
cast `int` throughout the call chain — no string concatenation of
untrusted data). PRO-1506 only adds a second, idempotent call site to
already-audited code; no new injection surface.

## 6. PRO-1517 mock/test changes — shipped-tree check

`git show --stat 33fd02f` touches exactly `STATUS.md`,
`docs/audits/MOCK_DIVERGENCE_AUDIT.md`,
`tests/Integration/Fixtures/mock-rec-engine/router.php`, and the new
`tests/Integration/RecEngineMockFidelityTest.php` — all under `docs/` or
`tests/`, neither of which ships in the release ZIP (`tests/` is excluded
by `.zipignore`; confirmed no plugin-code file appears in this commit's
diff). Nothing from this commit reaches the shipped plugin tree.

## 7. Commit `69a83b8` — Update URI header removal

Diff is exactly a 13-line removal from `smaily-connect.php`'s header
comment block (the `Update URI:` line + its rationale comment) plus
doc-only changes to `CLAUDE.md`, `STATUS.md`, `docs/DECISIONS.md`,
`docs/UPSTREAM_MERGE_PROPOSAL.md` — no functional PHP code, no logic
change. **Confirmed: this retires the long-standing intentional PCP finding
`plugin_updater_detected`** that every release-gate row back to the
2026-06-25 code-quality audit has carried as an accepted/intentional
finding (the header no longer exists to trigger it). The register note
below closes that finding out; it should NOT appear in the v3.9.0 PCP run.

## Should-fix (Medium): unescaped merge-tag text fields in `TransactionalPayloadBuilder`

**Finding.** `TransactionalPayloadBuilder::build()`/`product_fields()`
place several text fields into the `context` object POSTed to
`message/send.php` WITHOUT any HTML-escaping:
`first_name`, `last_name`, `order_number`, `payment_method`,
`shipping_method`, and the per-slot `product_name` / `product_description`.
This is a real inconsistency introduced by this delta, not a pre-existing
pattern extended:

- The sibling builder for the SAME merge-tag delivery mechanism —
  `CartPayloadBuilder::product_fields()` — explicitly
  `htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 )`-
  encodes every product-derived text value before it goes into the
  `product_<field>_N` slots, specifically because (per its own docblock
  language elsewhere in the file) the value is "ready to drop straight into
  an email merge tag." `TransactionalPayloadBuilder`'s own
  `price_display()` inherits the SAME strip-tags treatment for prices, but
  the text fields (`product_name`, `product_description`) and the new
  order-level fields (`first_name`, `last_name`, …) get none.
- `docs/DECISIONS.md`'s PRO-1504 entry (point 4) explicitly frames this
  class as "**Template parity** with the abandoned-cart merge-tag shape
  (CartPayloadBuilder)" — the design intent was parity, so the missing
  escaping reads as an oversight in translating that parity, not a
  considered decision (no DECISIONS.md text defends leaving these fields
  raw).
- **Attacker-reachable path exists.** `first_name` / `last_name` come
  straight from WooCommerce checkout billing fields — ordinary free-text
  input the customer fully controls at checkout, with no HTML-stripping
  applied anywhere in this builder. WooCommerce checkout does not require
  the billing email to belong to the person entering it; an attacker can
  place an order with an arbitrary victim email as `billing_email` and an
  HTML/script payload as `first_name`, and `TransactionalFlusher::
  send_now()` will POST that payload verbatim inside `context.first_name`
  to `message/send.php`, addressed `to: [victim@example.com]`.
- **Severity is capped by an unknown, not a known safe behavior.**
  Whether this is actually exploitable depends entirely on how Smaily's
  own `message/send.php` merge-tag substitution treats the `context`
  values when composing the outbound HTML email — that is an external
  system this repo doesn't control and this audit can't verify. If Smaily
  auto-escapes merge tags before substitution (plausible for a mature ESP),
  the practical impact is none; if it substitutes raw (as many simple
  merge-tag engines do), this is stored HTML/content injection into a
  transactional email sent to an arbitrary address the attacker chooses —
  a phishing/spoofing vector wearing the merchant's legitimate sender
  identity, not merely "self-XSS in the attacker's own inbox." No test in
  this delta exercises an adversarial (HTML-bearing) `first_name`/
  `last_name` value, so the current behavior — raw pass-through — is
  unverified against that engine-side assumption either way.
- **Not a blocker for v3.9.0**: (a) it depends on unverified external
  rendering behavior rather than a proven local vulnerability; (b) the
  feature ships default-OFF and gated behind a second, separately-
  configured Smaily account most stores won't have set up on day one; (c)
  it's a narrow, mechanically obvious fix (mirror
  `CartPayloadBuilder`'s existing `htmlspecialchars()` treatment onto the
  same field set) rather than a design problem. Recommend fixing promptly
  as a fast-follow, ideally before the transactional-email feature reaches
  its first pilot store with the toggle turned on.

**Recommendation (not applied — audit-and-record scope, no code changes
made by this pass):** apply the same `htmlspecialchars( …, ENT_QUOTES |
ENT_SUBSTITUTE | ENT_HTML401 )` treatment `CartPayloadBuilder` already uses
to every text-shaped field in `TransactionalPayloadBuilder::build()` and
`product_fields()`'s per-item compute closure — `first_name`, `last_name`,
`order_number`, `payment_method`, `shipping_method`, `product_name`,
`product_description` (leave `product_sku`/`product_quantity`/prices as
they are — SKUs are typically merchant-controlled slugs and prices already
go through `price_display()`'s strip-tags treatment).

**Resolution (2026-07-23, same day, PRO-1537):** fixed. All seven listed
fields now route through a new private `escape()` helper
(`htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 )`,
byte-identical flags to `CartPayloadBuilder`'s idiom); `product_sku`/
`product_quantity`/prices left untouched as recommended. Adversarial unit
tests (`<script>…</script>`, `<b onmouseover=…>`) pin the escaped output for
both the order-level fields and the per-slot product fields. `npm run
ci:strict` exit=0 (PHPUnit unit 652/652, +2; vitest 251/251 unchanged; tsc/
PHPStan/PHPCS clean). Rides the v3.9.0 cut.

## Info (accepted, no action needed)

1. **`attempt()`'s meta-guard-then-write is two sequential calls, not one
   atomic operation** (`get_meta()` check in the hook handler, `update_meta_data()`
   + `save()` inside `send_now()`/`process()` moments later) — a true
   concurrent double-fire (two simultaneous requests for the same order)
   could theoretically both pass the guard check before either write lands.
   In practice WooCommerce order-processing hooks for one order fire
   sequentially within a single PHP request (classic OR block checkout
   processes one order once); the PRO-1518 docstring itself frames the
   guard as covering "if a third party's setup somehow fires both hooks for
   one order" within that same request, which it does. A genuine cross-
   request race would need two truly parallel processing attempts for the
   same order — a pre-existing class of concern for any order-hook-driven
   send in this codebase (not specific to this delta), not a new
   regression.
2. **`resolver->resolve_workflow( $trigger_type, null )` always passes
   `language: null`** for the transactional account (by design — "this
   account has no per-language variant", directly tested in
   `TransactionalGateTest::
   test_resolver_is_queried_with_null_language_this_account_has_no_per_language_variant`)
   — noted only because it's a deliberate narrowing versus the marketing-
   side resolver calls, not an oversight.

## Gates run for this pass

- **None re-run by this audit pass** — this is a docs-only audit-and-record
  task per the task brief; `npm run ci:strict` / `sg docker -c "composer run
  test:integration"` results for each commit in this delta are already
  recorded per-commit in STATUS.md (all green at commit time, most recently
  PRO-1517: PHPUnit unit 650/650, vitest 251/251, integration 180/907
  assertions, sandbox tenant "Smaily Connect test" restored).
- **PCP against the built ZIP is explicitly OUT of scope for this pass** —
  the release-build worker runs it later today as part of the v3.9.0 cut
  (per the task brief). Expected result per this audit: the single
  historically-intentional `plugin_updater_detected` finding should now be
  ABSENT (§7 above), leaving PCP clean with zero findings — the release-
  build worker should treat a NEW appearance of `plugin_updater_detected`
  as a signal something regressed the header removal, not as an
  intentional finding to wave through as before.

## Assumptions

- "Plugin lines" for the >2,000-line policy trigger is read as the
  `git diff --stat` total across the whole delta (5,255 insertions / 153
  deletions, 46 files) — comfortably over threshold regardless of how
  test-vs-production lines are split.
- The `123d479..69a83b8` range given in the task brief was taken literally
  as the audit scope. `123d479` ("docs: STATUS truth-fix — v3.8.1
  published") sits two commits AFTER `251a29f` (the version-bump commit the
  v3.8.1 register row above cites as that gate's baseline) — both are
  docs-only publication-record commits with no code, so starting from
  `123d479` instead of `251a29f` narrows the range by exactly those two
  no-op-for-security commits and creates no gap against the prior audit's
  coverage.
