# Mock ↔ Real-engine divergence audit (ingest endpoints)

**Status**: internal engineering tool (not part of the public/pilot docs).
**Author**: plugin side, Phase 3.2.4.
**Purpose**: record, per ingest endpoint, where the integration **mock server**
and the plugin's **payload design** (variant A) diverge from the **deployed
engine's actual behaviour**, and what has to change in the mock when the
engine grows toward the spec (Route A).

## Why this exists

The engine team's audit found that the whole plugin-facing ingest API surface
is a **day-zero scaffold** (engine commit `4ee73b1`) — narrower than the
contract document. `RECENGINE_API_CONTRACT.md` was authored aspirationally and
the engine never matched it (the LESSONS §2.4 pattern, at the document level).

Both the plugin's PayloadBuilders **and** the integration mock server were
built to that spec (variant A: batch wrapper + per-item `event_id`). So today:

- the **mock** mirrors the **spec** (wide),
- the **plugin** sends to the **spec** (wide),
- the **engine** implements a **scaffold** (narrow).

The mock therefore confirms the plugin's assumptions, not the engine's reality
— exactly the failure mode the catalog live test (walk-3.2) caught. This audit
extends that catch to customers / orders / browse **before** 3.3 implements
them, so the mock can be aligned in the same commit rather than discovered in a
second live round.

## Verification legend

| Mark | Meaning |
|------|---------|
| ✅ | Live-verified by me against the real MiuMjau engine (walk-3.2 / probes) |
| 📋 | Engine-team-reported; **not yet** live-probed (endpoint not implemented plugin-side) |
| ⏳ | To be live-verified when 3.3 implements the endpoint |

## Cross-cutting findings (apply to every ingest endpoint)

These are properties of the engine's request handling, not of one endpoint.

| Dimension | Spec / plugin / mock assume | Real engine | Source |
|-----------|-----------------------------|-------------|--------|
| **`event_id` location** | per-item (inside each array object) | **per-item is canonical** since Route A W1 (both per-item and wrapper-level dedup; per-item also validated as UUID v4). Before W1 the engine accepted only wrapper-level and silently stripped per-item. | ✅ catalog W/P probe + W1 post-deploy verify |
| **Batch error semantics** | (unspecified) → assumed per-item `errors[]` | **all-or-nothing today**: one bad item → whole-batch HTTP 400, no `errors[{index,...}]`. **Superseded by D6** (DECISIONS F3-18): per-item `errors[]` is the canonical target for **all four** endpoints — customers (W4) and orders (W5) built to it; catalog + browse retrofitted together (**N-7**). | ✅ catalog partial-success probe |
| **`event_id` optionality** | optional except browse | optional (Layer-1 natural-key UPSERT works without it) | ✅ catalog no-event_id probe |

The `event_id`-location finding generalises to **all four endpoints** (shared
Zod shape). **Resolved by W1**: the engine now honours the plugin's per-item
design directly — per-item is canonical, wrapper-level stays for backward
compatibility (CC-9). The plugin needed **no change** (it has sent per-item,
one `event_uuid` per queue row, since F3-7). Live-verified post-deploy: a
per-item retry returns `{"deduplicated":1,"deduplicated_all":true}`.

---

## 1. catalog — `POST /api/v1/ingest/catalog`  ✅ live-verified, mock aligned

| Dimension | Mock (before 3.2.4) | Real engine | Mock now | Plugin now |
|-----------|---------------------|-------------|----------|------------|
| Wrapper key | `products` | **`items`** | `items` ✅ | `items` ✅ (fix a2ea53c) |
| Shape | batch array | batch array | batch | batch |
| `event_id` location | per-item | **per-item canonical** (W1) | per-item ✅ | per-item ✅ |
| `category_path` | accepted empty | **required, non-empty** (400 otherwise) | not enforced | variation→parent fix (2f14e88) |
| Bad item | (n/a) | whole-batch 400 | n/a | flush marks batch failed |
| No `event_id` | dedup skipped | Layer-1 UPSERT (200) | works | n/a |

**Fully aligned (W1).** Before W1 the mock dedup'd on per-item (matching the
plugin) while the engine honoured only wrapper-level — the mock passed but
reality differed. W1 made per-item canonical on the deployed engine, so mock,
plugin, and engine now agree. catalog is ✅ live-aligned end to end; the only
remaining catalog item is the all-or-nothing batch-error edge (F3-14, a later
refinement, not a blocker).

---

## 2. customers — `POST /api/v1/ingest/customers`  📋 engine-team-reported, ⏳ to verify

| Dimension | Spec / mock assumption (variant A) | Real engine (reported) | Mock fix under Route A |
|-----------|------------------------------------|------------------------|------------------------|
| Shape | batch `{"customers":[...]}` | **single object** (not batch) | drop the array wrapper; accept one object |
| Wrapper key | `customers` | **unchecked** (Zod doesn't validate the wrapper) | n/a once single-object |
| Required field | `email` (idempotency `(tenant,email)`) | **`smaily_contact_id` required** | enforce `smaily_contact_id`; plugin must source it |
| `event_id` | per-item | **per-item canonical** (W1, cross-cutting) | none — plugin already per-item |
| Silent-dropped | (all spec fields stored) | **6 fields silently dropped** (TBD which — to enumerate when probed) | mock should drop them too, or note as no-op |

**Plugin impact (3.3)**: `smaily_contact_id` requirement is the sharp edge —
the plugin doesn't currently have that value; sourcing/storing it is design
work. Single-object vs batch changes the queue/flush batching for customers.

---

## 3. orders — `POST /api/v1/ingest/orders`  📋 engine-team-reported, ⏳ to verify

| Dimension | Spec / mock assumption (variant A) | Real engine (reported) | Mock fix under Route A |
|-----------|------------------------------------|------------------------|------------------------|
| Shape | batch `{"orders":[...]}` | **single object** | drop wrapper; one object |
| Wrapper key | `orders` | **unchecked** | n/a once single-object |
| Idempotency | `(tenant, external_order_id)` | `external_order_id` (assumed kept) | confirm on probe |
| Silent-dropped | stored | **`status`, `currency`, `smaily_rec_ctx` dropped** | mock drops them / no-op |
| `event_id` | per-item | **per-item canonical** (W1, cross-cutting) | none — plugin already per-item |

**Plugin impact (3.3)**: the order PayloadBuilder already drafts `status`,
`currency`, `smaily_rec_ctx` (per spec) — these are sent but silently dropped
today. No plugin error, but the data won't land until Route A stores them.
Single-object batching change as with customers.

---

## 4. browse — `POST /api/v1/ingest/browse`  📋 engine-team-reported ("cleanest" = wrapper-shape only), ⏳ to verify

| Dimension | Spec / mock assumption | Real engine (reported) | Mock fix under Route A |
|-----------|------------------------|------------------------|------------------------|
| Shape | batch `{"events":[...]}` | **wrapper matches** (batch `events[]`) | none |
| Wrapper key | `events` | `events` (matches) | none |
| `event_id` | required | **required-ness flips** (TBD) | confirm on probe |
| `source` | required | **required-ness flips** (TBD) | confirm on probe |
| `event_id` location | per-item | **per-item canonical** (W1, W6) — matters most here since browse has no natural-key fallback | none — W1 covers browse (plan W6) |
| Batch error semantics | assumed per-item `errors[]` | **all-or-nothing** (one bad event → whole-batch HTTP 400, no `errors[]`), same as catalog — **not** "already per-item" | per-item `errors[]` retrofit (**N-7**), same as catalog (F3-18 / D6) |

**Note**: browse is the closest to spec **on wrapper shape only** (the engine
team called it "cleanest" — the wrapper key and batch array already match).
It's also the most dependent on the `event_id`-location decision, because
browse has **no natural-key UPSERT fallback** (§7) — if per-item `event_id` is
stripped and the engine only honours wrapper-level, a batch of N browse events
sharing one wrapper `event_id` would dedup as a single event. That would be a
**correctness** problem for browse (unlike catalog/customers/orders, where
Layer-1 covers it). **Resolved**: W1 made per-item canonical (plan W6 folds
browse into W1), so browse's no-fallback risk is moot — per-item dedup works.

**Batch errors — correction**: "cleanest" refers to wrapper shape only, **not**
error handling. Browse was earlier read as "already per-item" on batch errors;
that is wrong. Browse is **also all-or-nothing** (one bad event → whole-batch
HTTP 400, no `errors[]`), exactly like catalog, and needs the **same N-7
retrofit** to per-item `errors[]` (DECISIONS F3-18 / D6). The only existing
partial-success reference is the **admin CSV path** (`commitCatalog →
import_errors`), not the HTTP endpoints.

---

## Summary: what the mock needs when Route A lands

> **Important**: the "real engine" columns for customers / orders / browse below
> describe the **pre-Route-A scaffold**. The locked Route A v2 plan widens the
> engine **toward the plugin's variant-A design** (batch wrappers, email-key,
> per-item `event_id`, the rich fields). So the mock's eventual target is the
> spec/plugin shape, not the current narrow scaffold — each row gets re-probed
> and aligned when W4/W5 lands.

1. **catalog** — ✅ **done (W1)**. Wrapper key, category_path, per-item dedup all
   aligned mock ↔ plugin ↔ engine. Residual: F3-14 all-or-nothing batch edge.
2. **customers** (after W4) — batch `{customers:[...]}` + email-key (W4 drops
   `smaily_contact_id`, D1); per-item `event_id`. Re-probe + align mock then.
3. **orders** (after W5) — batch `{orders:[...]}` + `status`/`currency`/
   `smaily_rec_ctx` accepted; per-item `event_id`. Re-probe + align mock then.
4. **browse** — per-item `event_id` already canonical (W1/W6); confirm
   `event_id`/`source` required-ness when implemented plugin-side. **Batch
   errors: all-or-nothing today → per-item `errors[]` retrofit (N-7), same as
   catalog (F3-18 / D6).**

**Build the mock from the engine's real responses**, not from the spec — capture
a real response per endpoint (as done for catalog) or sync against an
engine-team fixture, so the mock cannot drift back toward the aspirational spec
(LESSONS §2.4).

## Open dependency

All of customers / orders / browse are **engine-team-reported, not yet
live-probed** — the plugin doesn't implement those endpoints, so there's no
authenticated path to probe them today. Each row marked 📋 must be confirmed
against the real engine when 3.3 implements the endpoint, the same way catalog
was confirmed in 3.2.4.
