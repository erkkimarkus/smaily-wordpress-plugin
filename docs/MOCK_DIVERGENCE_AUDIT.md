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
| **`event_id` location** | per-item (inside each array object) | **wrapper-level only** (top-level, beside the array); per-item is silently stripped by Zod → Layer-2 dedup never fires | ✅ catalog W/P probe |
| **Batch error semantics** | (unspecified) → assumed per-item `errors[]` | **all-or-nothing**: one bad item → whole-batch HTTP 400, no `errors[{index,...}]` | ✅ catalog partial-success probe |
| **`event_id` optionality** | optional except browse | optional (Layer-1 natural-key UPSERT works without it) | ✅ catalog no-event_id probe |

The `event_id`-location finding is the big one: it generalises to **all four
endpoints**, because they share the same Zod request shape. The plugin's
per-item design (one `event_uuid` per queue row) only activates the engine's
Layer-2 dedup if the engine adds per-item support (Route A extension) **or**
the plugin moves to one `event_id` per batch (a PayloadBuilder + IngestQueue
redesign). Decision pending; **no plugin change made**.

---

## 1. catalog — `POST /api/v1/ingest/catalog`  ✅ live-verified, mock aligned

| Dimension | Mock (before 3.2.4) | Real engine | Mock now | Plugin now |
|-----------|---------------------|-------------|----------|------------|
| Wrapper key | `products` | **`items`** | `items` ✅ | `items` ✅ (fix a2ea53c) |
| Shape | batch array | batch array | batch | batch |
| `event_id` location | per-item | **wrapper-level** | per-item ⚠️ | per-item ⚠️ |
| `category_path` | accepted empty | **required, non-empty** (400 otherwise) | not enforced | variation→parent fix (2f14e88) |
| Bad item | (n/a) | whole-batch 400 | n/a | flush marks batch failed |
| No `event_id` | dedup skipped | Layer-1 UPSERT (200) | works | n/a |

**Residual divergence**: the mock dedups on **per-item** `event_id` (matches
the plugin), but the real engine dedups on **wrapper-level** `event_id`. So the
mock's dedup test passes while reality differs. This resolves itself when the
Layer-2 location decision lands (per-item engine support, or wrapper-level
plugin payloads) — at which point both mock and plugin align to the choice.

---

## 2. customers — `POST /api/v1/ingest/customers`  📋 engine-team-reported, ⏳ to verify

| Dimension | Spec / mock assumption (variant A) | Real engine (reported) | Mock fix under Route A |
|-----------|------------------------------------|------------------------|------------------------|
| Shape | batch `{"customers":[...]}` | **single object** (not batch) | drop the array wrapper; accept one object |
| Wrapper key | `customers` | **unchecked** (Zod doesn't validate the wrapper) | n/a once single-object |
| Required field | `email` (idempotency `(tenant,email)`) | **`smaily_contact_id` required** | enforce `smaily_contact_id`; plugin must source it |
| `event_id` | per-item | wrapper-level (cross-cutting) | per Layer-2 decision |
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
| `event_id` | per-item | wrapper-level (cross-cutting) | per Layer-2 decision |

**Plugin impact (3.3)**: the order PayloadBuilder already drafts `status`,
`currency`, `smaily_rec_ctx` (per spec) — these are sent but silently dropped
today. No plugin error, but the data won't land until Route A stores them.
Single-object batching change as with customers.

---

## 4. browse — `POST /api/v1/ingest/browse`  📋 engine-team-reported ("cleanest"), ⏳ to verify

| Dimension | Spec / mock assumption | Real engine (reported) | Mock fix under Route A |
|-----------|------------------------|------------------------|------------------------|
| Shape | batch `{"events":[...]}` | **wrapper matches** (batch `events[]`) | none |
| Wrapper key | `events` | `events` (matches) | none |
| `event_id` | required | **required-ness flips** (TBD) | confirm on probe |
| `source` | required | **required-ness flips** (TBD) | confirm on probe |
| `event_id` location | per-item | wrapper-level (cross-cutting) — but browse has no natural key, so this matters most here | per Layer-2 decision |

**Note**: browse is the closest to spec (the engine team called it "cleanest").
It's also the most dependent on the `event_id`-location decision, because
browse has **no natural-key UPSERT fallback** (§7) — if per-item `event_id` is
stripped and the engine only honours wrapper-level, a batch of N browse events
sharing one wrapper `event_id` would dedup as a single event. That would be a
**correctness** problem for browse (unlike catalog/customers/orders, where
Layer-1 covers it). Flag for the Layer-2 decision: browse may force per-item.

---

## Summary: what the mock needs when Route A lands

1. **catalog** — align dedup to wrapper-level `event_id` (or keep per-item if
   the engine adds per-item support). Everything else already aligned.
2. **customers** — single-object (not batch); enforce `smaily_contact_id`;
   model the 6 silent-dropped fields.
3. **orders** — single-object; model `status`/`currency`/`smaily_rec_ctx` as
   dropped.
4. **browse** — confirm `event_id` / `source` required-ness; resolve the
   per-item-vs-wrapper question (browse has no Layer-1 fallback).

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
