# Engine-team question — non-product exclusion: filter vs. signal (2026-06-14)

**Purpose:** close the last open item of the catalog-correctness work (your
brief `PLUGIN_BRIEF_catalog_correctness.md` §P3 / `PROMPT_woo_plugin_team.md`
item 6 — "filter non-product post types out of catalog sync"). Paste into the
engine conversation and decide together.

## Where we are

- **Catalog-correctness CC.1–CC.3 DONE + live-walked** (sandbox, 7/7): canonical
  collapse of WPML/WCML translations to one `wc-{canonical_id}` across catalog +
  orders + browse; `{lang:value}` content (model B), per-language 500-char clamp.
  The engine accepted the `{lang:value}` object form (strict Zod, `processed=1
  errors=[]`).
- The **non-product filter (§P3) is the only piece left**, and we want to push
  back on *where* it should live before building anything.

## Our position: the plugin should NOT hard-filter non-products

We think a plugin-side hard exclusion is the **wrong layer**, for three reasons:

1. **It's a per-client rule.** MiuMjau's donation is categorised `kassitoit`
   (same as real food); its gift cards are a specific gift-card plugin's product
   type. The next store's "non-products" look nothing like these. There is no
   generic rule the plugin can encode.
2. **Heuristics backfire.** A `is_virtual()` / `is_downloadable()` /
   category-name filter would exclude the **real products of a legitimate
   virtual- or downloadable-goods store** (a merchant who sells only digital
   goods). "Is this recommendable?" is a **business-model decision**, not a
   structural one the connector can make safely.
3. **The right layer is the engine.** Your `recommendable` flag (migration 0039)
   is centralized, recomputed on every upsert, self-healing on re-sync, and
   tunable engine-side without redeploying every connector. Erkki confirms it
   already works — the MiuMjau gift cards/donation no longer appear in results.

So our proposed division: **the plugin sends signal; the engine owns the
exclusion decision.** Not the reverse.

## What we think you asked for (please confirm)

Your brief's bonus-ask was to send richer `raw_attributes` (incl. product type)
so the engine can derive `recommendable` robustly instead of name-matching. That
matches the "plugin sends signal" model. If so, we'll add a generic signal — no
exclusion logic in the plugin.

## Questions

1. **Confirm the division:** do you agree the plugin should NOT hard-filter, and
   instead send signal for the engine to decide? (If there's a case where you
   genuinely want the connector to drop a row, give us the **robust per-type
   rule** — product type / plugin meta — we will not ship name/category
   heuristics that harm a virtual-goods store.)

2. **Exactly which signal fields do you want?** Our proposal:
   - `product_type` — the WC type string (`simple` / `variable` / `grouped` /
     `external`, **plus gift-card plugins' custom types** like `pw-gift-card`,
     `gift-card`, `gift_card` — these ARE the reliable gift-card signal).
   - `is_virtual` / `is_downloadable` — WC booleans (so you can distinguish a
     digital-goods store from config artifacts).
   - Placement: a **top-level catalog field** (e.g. `product_type`), or inside
     the existing `raw_attributes` object? (`raw_attributes` currently carries
     custom *attribute* labels only — product type is metadata, not an
     attribute, so a top-level field reads cleaner. Your call — it's your
     storage.) Whatever we pick, we update the contract + mock + a live-walk
     (CC-8) in the same pass.

3. **Urgency:** is the current name-match `recommendable` sufficient for the
   MiuMjau go-live (non-products already excluded), or do you want the signal
   shipped before the SKU-graph wipe + full re-backfill? We'd prefer to ship it
   as a small follow-up (not block go-live), unless you see name-match failing
   for the pilot.

## Reference

- Plugin position + decision log: `docs/DECISIONS.md` (CC.4), `STATUS.md`
  (catalog-correctness section).
- Contract: `RECENGINE_API_CONTRACT.md` §3 (catalog), engine-internal
  `recommendable`.
