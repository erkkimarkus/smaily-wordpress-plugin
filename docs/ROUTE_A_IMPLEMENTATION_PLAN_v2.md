# Route A implementation plan — engine widens to match spec (v2)

**Status**: Confirmed by Erkki. Ready to execute, starting with W1.
**Version**: 2 (consolidates strategic decisions made during audit phase)
**Strategic decision**: Plugin-primary architecture. Engine widens to the published spec; admin CSV upload remains as transitional onboarding tool. MiuMjau will eventually migrate from admin CSV to plugin sync (CSV path retired thereafter).
**Scope**: every gap surfaced by the architectural audit gets a work item here. Nothing omitted.

---

## Context — how we got here

The published spec (`RECENGINE_API_CONTRACT.md`) describes a plugin-primary architecture with batch ingest endpoints, rich catalog fields, email-based customer identity, and order status/currency tracking. The engine was scaffolded at commit `4ee73b1` with a narrower implementation that diverged from spec on multiple axes — single-object customers/orders, `smaily_contact_id` required, narrow catalog ingest, no order status/currency. Production data for MiuMjau pilot has flowed through the admin CSV upload path (`lib/admin/commit/handlers.ts`), which DOES populate the rich fields. The plugin-ingest endpoints have never been used in production.

Plugin team built against spec. First live walk surfaced the divergences. Erkki confirmed plugin-primary as the strategic direction — engine catches up to spec, admin CSV becomes transitional.

This plan executes the convergence in 5 sequenced work items.

---

## Guiding principles (unchanged from v1)

1. **Don't break MiuMjau pilot.** Admin CSV upload path stays functional throughout the transition. MiuMjau data spine untouched until they migrate to plugin sync (separate later decision).

2. **Plugin team is the customer.** Each engine work item unblocks a specific plugin sub-PR. Sequence work to keep plugin team building, not waiting.

3. **One work item = one Code brief = one commit (or small commit chain).** No mega-PRs. Smaller PRs are easier to validate, roll back, and document.

4. **Live-test every claim against deployed engine.** The audit revealed that prior "X/X PASS" reports were in-process tests, not deployed-HTTP tests (audit task 8). Every work item ends with a curl against `https://re-erkkimarkus-projects.vercel.app`, response pasted into the report. In-process tests are still useful but never sufficient.

5. **Spec is the contract; spec updates accompany each engine change.** When work item N completes, spec §X is updated in the same session and synced to plugin-repo (the sync-demonstration cycle we're building).

---

## Confirmed strategic decisions (locked in)

**D1: Smaily UID = email.** Erkki confirms this is canonical. The `smaily_contact_id` column is a redundant artifact from the original scaffold — it was designed to hold a Smaily-internal contact ID that doesn't exist in practice. All writes default to email; the column is removed in W4.

**D2: Price field semantics — Variant 1 (Shopify convention)**:
- `price` (number, required) = current price the customer pays. If on sale, this is the sale price.
- `compare_price` (number, optional) = reference price displayed struck-through next to `price` when present and greater than `price`. This is what the customer would pay without a discount.
- `on_sale_until` (ISO 8601, optional) = when the sale ends (informational, doesn't gate display).
- Existing `discount_price` column is migrated → `price` becomes authoritative.
- Existing `discount_until` column is migrated → `on_sale_until` (which already exists per migration 0014).

**D3: No feature-flag for W4.** With D1 confirmed (email is canonical), W4 becomes a clean one-way migration. No `X-Customer-Mode` header, no parallel code paths. Drop `smaily_contact_id` outright.

**D4: MiuMjau migrates to plugin sync, CSV retired.** Historical MiuMjau data is preserved through W4 migration. After Route A complete, MiuMjau onboards a plugin instance against the same tenant_id; existing rows are updated by plugin sync.

**D5: No pilot-live deadline pressure.** Quality before speed. Realistic timeline ~2.5-3 weeks engine work, no shortcuts.

---

## Work items, prioritized

Ordered by **plugin-unblock impact**, not by engine-internal complexity.

### W1 — Layer-2 dedup: per-item `event_id` acceptance (1 day)

**Plugin-unblock**: 3.2 catalog ZIP (plugin-side currently paused on this finding).

**What**: Zod schemas on all 4 ingest endpoints accept `event_id` at **per-item** location (matches spec §7, matches plugin design, validated empirically by plugin's W/P test). Wrapper-level acceptance stays for backward compatibility but is documented as legacy. Dedup table keys remain `(tenant_id, event_id)` regardless of payload location.

**Why first**: smallest, most isolated change. Empirically validated. Unblocks plugin's 3.2 ZIP within hours of completion. Low arch-risk. Proves the deploy-validation discipline (principle 4) before larger work items.

**Engine work**:
- `app/api/v1/ingest/catalog/route.ts` — `ItemSchema` accepts `event_id: z.string().uuid().optional()` per item. Handler iterates items, calls `checkAndRegisterEventId(tenant_id, item.event_id, 'catalog')` per item that carries one. Wrapper-level `event_id` (existing behavior) continues to work via existing path.
- Same pattern for customers, orders, browse handlers.
- `scripts/test-idempotency.ts` extended: each scenario runs BOTH wrapper-level AND per-item; plus new scenarios that run over HTTP against deployed Vercel (not in-process).

**Deploy validation** (mandatory):
- curl against `https://re-erkkimarkus-projects.vercel.app/api/v1/ingest/catalog` with `event_id` per item, retry same payload, confirm response body contains `"deduplicated": true`
- Paste full response in commit message or report

**Spec update**: §7 Idempotency clarifies "per-item is canonical, wrapper-level supported for backward compatibility"; §3-6 example payloads use per-item.

**Acceptance**:
- [ ] All 4 ingest endpoints accept `event_id` per item (Zod schemas updated)
- [ ] Wrapper-level still works (backward compat)
- [ ] In-process test suite: 22+ existing + new per-item scenarios all pass
- [ ] Deployed-HTTP curl validation: per-item Variant P retry returns `{"deduplicated": true}` against real Vercel
- [ ] tsc + build clean
- [ ] Spec §7 updated, commit, push, plugin-team syncs plugin-repo

---

### W2 — Catalog ingest field expansion (3-5 days)

**Plugin-unblock**: catalog data parity. Plugin can send the rich data the spec promises and the render pipeline needs.

**What**: Zod `ItemSchema` on `/api/v1/ingest/catalog` accepts every field admin CSV path writes:
- `product_url` (string URL, required by render pipeline)
- `image_url` (string URL, optional but blanks render badly — plugin should send when available)
- `description` (string, max 500 chars per spec)
- `compare_price` (number, optional — Variant 1 semantics per D2)
- `on_sale_until` (ISO 8601, optional)
- `external_id` (string, optional — plugin debug traceability)
- Multilingual variants: `name`, `description`, `product_url` accept `{lang: string}` object form per spec §3

**Engine work**:
- Widen `ItemSchema` in `app/api/v1/ingest/catalog/route.ts`
- Map each new field through to the UPSERT statement (model after `lib/admin/commit/handlers.ts:commitCatalog`)
- Multilingual handling: if `name` is an object, write to `name_i18n` JSONB column; if string, write to `name` text column. Same pattern for description, product_url.
- Default behaviors: `description` truncates at 500 chars with warning (don't fail the row); `image_url` null OK (render uses `?? ''`).

**Risk + mitigation**:
- Admin CSV path shares some validation logic. Keep admin CSV's Zod schema separate from plugin ingest schema; share underlying field types/transforms only. Test admin CSV smoke before deploying W2.

**Deploy validation**:
- curl with full payload (sku, name, category_path, price, compare_price, product_url, image_url, description, on_sale_until, external_id, multilingual variant)
- Verify catalog row in DB has all fields populated correctly
- Verify Smaily contact-sync push (next cron) writes correct values to `rec_N_link_url`, `rec_N_image_url`

**Spec update**: §3 Catalog body field reference table corrected to match Zod; new fields documented; multilingual object form examples validated by curl.

**Acceptance**:
- [ ] All spec-listed catalog fields accepted by ingest
- [ ] Multilingual object form works for name, description, product_url
- [ ] Admin CSV smoke test still passes (no regression)
- [ ] Deployed-HTTP validation: full rich payload renders correct catalog row
- [ ] Smaily contact-sync output unchanged in shape; values now come from plugin ingest
- [ ] Spec §3 updated, sync demonstration

---

### W3 — Price field rationalization (1-1.5 days)

**Plugin-unblock**: render pipeline correctness — sale display in recommendation emails works against plugin-ingested data.

**What**: Implement D2 (Variant 1 — Shopify convention). Bigger than original 0.5-day estimate because touches admin CSV + render + Smaily sync, not just catalog ingest.

**Engine work**:
1. **Migration**: backfill `compare_price` from `discount_price` where `discount_price IS NOT NULL` (one-time data migration, irreversible without backup); drop `discount_price` column after backfill verified.
2. **`lib/admin/commit/handlers.ts`**: stop writing `discount_price`; write `compare_price` directly when AI-mapping detects it. Update target-schema (`lib/ai/target-schemas/catalog.ts`) to use `compare_price` field name (was `discount_price`).
3. **`lib/engine/render/context-builder.ts:73-77`**: rewrite the savings/percentage math to use `(compare_price - price)` instead of `(price - discount_price)`. Sale condition: `compare_price > price`.
4. **`lib/smaily/contact-sync.ts:39,127`**: outbound Smaily slot field name. Decide: rename `rec_N_discount_price` → `rec_N_compare_price` (clean but breaks Smaily-side templates pilot may have configured), OR keep field name and just change source value. Erkki to decide during W3 (probably keep field name to avoid Smaily template churn).

**Risk + mitigation**:
- MiuMjau already has Smaily-side email templates referencing `rec_N_discount_price`. **Don't break those**. Most likely path: keep Smaily slot field name `rec_N_discount_price`, but the *value* now comes from `compare_price` column. Document the naming legacy as tech debt.
- `discount_until` column existed but already had `on_sale_until` as the migration-0014 target. Use `on_sale_until` going forward; backfill from `discount_until`; drop `discount_until`.

**Deploy validation**:
- After deploy, trigger Smaily contact-sync cron manually (or wait for next 05:00 run)
- Verify a MiuMjau customer's contact fields show sensible discount/compare values
- Pilot monitoring SQL query to confirm `compare_price` populated correctly

**Spec update**: §3 catalog body uses `price` + `compare_price` + `on_sale_until`. `discount_price` removed.

**Acceptance**:
- [ ] Migration: discount_price → compare_price data preserved, columns dropped
- [ ] Admin CSV writes compare_price (not discount_price)
- [ ] Render pipeline reads compare_price
- [ ] Smaily contact-sync output preserved (template values still populate)
- [ ] No regression on MiuMjau current Smaily templates (manual visual check on a test contact)
- [ ] Spec updated, sync demonstration

---

### W4 — Customers: email-first identity + batch wrapper (2-3 days)

**Plugin-unblock**: 3.3 customers sub-PR.

**What**: This is simpler than the v1 plan estimated because D1 (Smaily UID = email) eliminates the engine-fill mechanism complexity (no W4c needed).

**Engine work**:

**W4a — Customers ingest accepts batch + email-key**:
- Zod schema: `{ event_id?, customers: z.array(CustomerSchema).min(1).max(100) }` (matches spec §4)
- Per-customer: `email` required, all spec-promised fields accepted (`first_name`, `last_name`, `country`, `language`, `phone`, `first_seen_at`, `external_id`, `consent.*`)
- Handler iterates customers array, UPSERTs by `(tenant_id, email)`
- Each item may carry `event_id` for Layer-2 dedup (per W1 pattern)

**W4b — Customers table schema migration**:
- Drop `smaily_contact_id` column (after writes migrated)
- Add new columns: `first_name`, `last_name`, `country`, `language`, `phone`, `external_id`, `consent_marketing`, `consent_recommendations`, `consent_at`
- New unique constraint: `(tenant_id, email)` (was `(tenant_id, smaily_contact_id)`)
- Update all code paths reading `smaily_contact_id` to use `email`:
  - `lib/smaily/action-log.ts:193` — simplify `action.contact_id ?? action.email` → just `action.email`. The Smaily `contact_id` field becomes dead code (Smaily's identity is email).
  - `lib/smaily/sync-tenant.ts:82,263` — already uses email for outbound; remove smaily_contact_id selection.
  - `lib/admin/commit/handlers.ts:333-335,384,403,641,668-669` — admin CSV path: remove smaily_contact_id mapping, key on email.
  - `app/api/v1/ingest/customers/route.ts` — refactor for batch + email-key (W4a above).
  - `app/api/v1/ingest/orders/route.ts:54,74,85,95` — order paths use email for customer lookup/auto-create.
  - `app/api/webhooks/smaily/.../email-events/route.ts:74` — update comment + join logic.

**Risk + mitigation**:
- This is still the biggest single change architecturally despite being smaller than v1 estimated. Mitigation: deploy to staging-equivalent (a test tenant) first; run end-to-end flow (customer ingest → trigger → recommendations → Smaily push) against test tenant before pointing MiuMjau at the new code.
- Historical MiuMjau customer data: existing rows have `smaily_contact_id` set (to email value, per D1 analysis). Migration script asserts `smaily_contact_id = email` on every row before dropping the column (sanity check — if any row has them differing, halt migration, investigate).

**Deploy validation**:
- curl plugin-ingest customers endpoint with batch payload (5 customers, mixed fields, multilingual where applicable)
- Verify all 5 rows in `customers` table with email-keyed PK
- Trigger Smaily contact-sync — verify push still works, contact fields populate
- Test action-log poll (manually or wait for next cron) — verify email-based match still resolves customer_id

**Spec update**: §4 fully revised. `smaily_contact_id` references removed from spec. Examples use email identity.

**Acceptance**:
- [ ] Migration applied: `smaily_contact_id` dropped, email-keyed unique constraint
- [ ] Migration sanity check passed (smaily_contact_id == email for all rows pre-drop)
- [ ] Plugin customers ingest accepts batch
- [ ] All read/write paths updated to email-key
- [ ] Smaily action-log poll matches by email
- [ ] Smaily contact-sync push works (no regression)
- [ ] Admin CSV path still works (no regression)
- [ ] Deployed-HTTP validation against test tenant: full end-to-end flow
- [ ] MiuMjau pilot smoke test (admin CSV upload of a known good file → catalog populated correctly)
- [ ] Spec §4 updated, sync demonstration

---

### W5 — Orders: batch wrapper + status + currency + items (3-4 days)

**Plugin-unblock**: 3.3 orders sub-PR.

**What**: Similar pattern to W4 but smaller scope (no identity-fill complexity, customer creation now goes via W4's email-key).

**Engine work**:

**W5a — Schema migration**:
- Add `currency` (text, default 'EUR') to orders table
- Add `status` (text or enum: `completed`, `processing`, `cancelled`, `refunded`) to orders table
- Add `smaily_rec_ctx` (text, nullable) to orders table
- Add `discount_amount` to order_items table

**W5b — Orders ingest accepts batch**:
- Zod schema: `{ event_id?, orders: z.array(OrderSchema).min(1).max(50) }` (matches spec §5 — 50 cap, not 100)
- Per-order accepts all spec fields including new `status`, `currency`, `smaily_rec_ctx`
- Per-item accepts `discount_amount` (new)
- Auto-create customer from `customer_email` if not exists (W4's email-key path)
- Each item may carry `event_id` for Layer-2 dedup

**W5c — Attribution code updates**:
- `lib/engine/attribution/` reads new `smaily_rec_ctx` field where relevant
- Existing attribution flow (`smaily_rec_id` → recommendations table) unchanged

**Deploy validation**:
- curl orders batch payload (3 orders, varied statuses, currencies, items)
- Verify orders + order_items rows correct
- Trigger attribution cron — verify rec_attribution rows created
- MiuMjau pilot smoke test

**Spec update**: §5 fully revised.

**Acceptance**:
- [ ] Migration applied: status, currency, smaily_rec_ctx, item discount_amount columns
- [ ] Plugin orders ingest accepts batch
- [ ] Auto-create customer via email-key (W4 dependency satisfied)
- [ ] Attribution flow works with new fields
- [ ] Admin CSV path still works (no regression)
- [ ] Deployed-HTTP validation
- [ ] MiuMjau smoke test
- [ ] Spec §5 updated, sync demonstration

---

### W6 — Browse: no separate work (folded into W1)

Browse is the cleanest endpoint per the audit. W1's per-item event_id covers it. No separate work item needed beyond the W1 spec-update wording.

---

### Cross-cutting: deploy-validation discipline

**Not a separate work item — a process change applied to all of W1-W5.**

Every brief includes a **deploy validation section**:
```
After implementation:
1. Push to main (Vercel auto-deploys)
2. Wait for deploy completion (Vercel dashboard / `vercel inspect`)
3. Run validation curls against https://re-erkkimarkus-projects.vercel.app
4. Paste actual response bodies (or response status + key fields) in report
5. Only then claim "live verified"
```

This eliminates the in-process vs deployed-HTTP drift the audit revealed. Adds ~5-10 minutes per brief; saves debugging cycles later.

---

### Cross-cutting: spec rewrites accompany each work item

Per work item:
1. Engine work complete + deploy-validated
2. Claude drafts updated spec sections (specifically: which sections, which fields, which examples)
3. Code validates spec examples by running each as curl against deployed engine
4. Commit spec to engine-repo
5. Erkki signals plugin-team to sync plugin-repo copy
6. Diff verify byte-for-byte

Cumulative: by end of W5, spec is fully aligned with code, plugin-repo + engine-repo synced. Five sync demonstrations practiced.

---

## Sequencing diagram

```
Week 1:  [W1 Layer-2 per-item]                       → plugin 3.2 ZIP unblocked
                ↓
Week 1-2: [W2 catalog fields] ∥ [W3 price rationalize]  (parallelizable)
                ↓
Week 2-3: [W4 customers email-first]                 → plugin 3.3 customers unblocked
                ↓
Week 3:   [W5 orders batch + status + currency]      → plugin 3.3 orders unblocked
                ↓
Week 3:   Final sync demonstrations, CHANGELOG       → Route A complete
```

**Total estimated**: 2.5-3 weeks engine work + plugin live walks per item.

**Critical path**: W1 → W4 → W5. W1 is fast; W4 is the long pole.

**Parallelizable**: W2 + W3 can overlap (different concerns, minimal code overlap).

---

## Risk register

**R1 — MiuMjau pilot interruption.** Mitigation: admin CSV path stays functional, never edit admin commit handler logic except to widen. Each work item includes admin CSV smoke test in acceptance criteria.

**R2 — W4 customer identity migration surfaces unknown tight couplings.** Mitigation: pre-migration sanity check (assert `smaily_contact_id == email` on every row). Deploy to test tenant first.

**R3 — W3 Smaily slot field naming churn breaks pilot templates.** Mitigation: keep Smaily slot field names stable (`rec_N_discount_price` outbound), change source value internally. Document as tech debt.

**R4 — Spec drift returns despite the new discipline.** Mitigation: deploy-validation discipline + per-item spec rewrites in the same session. Every claim verified empirically.

**R5 — Plugin team blocked while engine works.** Mitigation: communicate per-item progress. Plugin team can prep non-ingest work (queue logic, error handling, WC-side integration) during engine work.

**R6 — Future Claude session loses context.** Mitigation: this v2 document, plus per-item briefs that stand alone. New Claude sessions can pick up at any work item.

---

## What this plan does NOT cover

❌ Code briefs themselves — each W gets its own brief in its own Claude session, referencing this plan
❌ Schedule commitments — engineering estimates carry uncertainty; this plan is a sequence, not a timeline contract
❌ Plugin-side work — plugin team owns their sub-PRs; this plan only describes engine-side commitments per item
❌ Post-Route-A backlog — Smaily field-name cleanups, CSV path retirement, performance tuning, etc., come after Route A complete

---

## What's locked in (Erkki has confirmed)

- D1: Smaily UID = email
- D2: Variant 1 price semantics (price + compare_price)
- D3: No feature-flag for W4
- D4: MiuMjau migrates to plugin sync, CSV retired (later, post-Route-A)
- D5: No pilot-live deadline pressure
- W1 starts next

---

## What still needs decisions (deferred to per-item briefs)

- W3: Smaily slot field naming (keep `rec_N_discount_price` outbound, or rename to `rec_N_compare_price`?). Recommended: keep, document as tech debt.
- W4: How to handle MiuMjau existing customer rows during migration — direct migration script or transition phase? Likely direct (sanity check passes).
- W5: `status` field — text or proper enum constraint? Likely text for forward-compat (new statuses don't need migration).

These get resolved when the relevant brief is drafted.
