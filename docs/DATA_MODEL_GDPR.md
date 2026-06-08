# Smaily Connect — Personal Data Model & GDPR Rights

**Purpose.** A single map of every personal-data element the rec-engine
integration touches: where it lives, which system owns it, and what each GDPR
right (export / erase / opt-out) does to it. This is the input for the 3.8 GDPR
work and the factual basis for the privacy-policy section.

**Scope note.** This document covers ONLY rec-engine personal data. WooCommerce's
own data (orders, addresses, purchase history) is owned and exported/erased by
**WooCommerce's own GDPR tooling**, not this plugin. The rec-engine plugin must
not duplicate or re-export Woo data.

---

## The four systems

Personal data related to recommendations lives across four systems with
different ownership:

- **Engine** — the recommendation engine (separate repo). Holds the rec-specific
  personal data: browse events, visitor tokens, computed recommendations,
  attribution. Owns the §8/§9/§10 GDPR API.
- **Plugin** (this repo) — stores local rec-specific markers in WordPress
  (order-meta, user-meta) that link a WooCommerce shopper to engine records.
- **Smaily** — the marketing platform. Owns **consent**, including the
  **profiling consent** (separate granular parameter) that authorises the
  rec-engine to profile a contact. Plugin reads this back; Smaily is the
  authority.
- **WooCommerce** — owns order/customer commerce data. **Out of scope** for this
  plugin's GDPR handlers (Woo has its own).

---

## Data element inventory

Each row is one personal-data element: where it lives, what it is, and the GDPR
disposition. "Export" = Art 15 (subject access), "Erase" = Art 17, "Opt-out" =
Art 21 (profiling objection).

### Engine-held (rec-specific personal data)

| Element | What it is | Export (Art 15) | Erase (Art 17) |
|---|---|---|---|
| browse_events | Page/product views, cart events tied to a visitor/customer | Yes — personal data | Deleted (CASCADE) |
| visitor_tokens | Anonymous-visitor identifiers | Yes — personal data | Deleted |
| recommendations | Computed product recs surfaced to the customer | Yes — personal data | Deleted (CASCADE) |
| email_events | Engine-tracked email interaction signals | Yes — personal data | Deleted (CASCADE) |
| customer (engine record) | Engine's customer row (email natural-key) | Yes — personal data | Deleted (CASCADE) |
| rec_attribution | Which rec drove which order — decision-logic credit | **No — omitted** (trade secret / decision logic; legal-review pending) | Deleted (records_removed counts it) |
| gdpr_audit_log row | Proof a delete happened | n/a | **Retained** (compliance proof) |
| lift_metrics_daily | Aggregate lift stats | n/a | **Anonymised**, not deleted (aggregate, no PII after) |

### Plugin-held (local rec markers in WordPress)

| Element | Where | What it is | Export (Art 15) | Erase (Art 17) |
|---|---|---|---|---|
| _smaily_rec_id | order-meta | Which rec was attributed to this order | Yes — rec-specific | Removed |
| _smaily_visitor_token | order-meta | Visitor token captured at checkout | Yes — rec-specific | Removed |
| _smaily_rec_ctx | order-meta | Rec context at attribution | Yes — rec-specific | Removed |
| _smaily_anon_session_id | order-meta | Anon session linked to the order | Yes — rec-specific | Removed |
| _smaily_rec_merged_anon_sid | user-meta | Last anon-session merged into this user (3.7 dedup marker) | Yes — rec-specific | Removed |

**Important boundary.** `_smaily_rec_id` and friends sit *on* a WooCommerce order
object, but they are rec-specific meta the plugin added. The plugin's exporter
returns **these meta values**; it does **not** export the order itself (line
items, totals, address) — that is WooCommerce's exporter's job. The plugin reads
rec-meta off the order; it does not re-export Woo data.

### Smaily-held (consent authority)

| Element | What it is | GDPR role |
|---|---|---|
| Marketing consent | Permission to send marketing email | Smaily-owned; governs email, not profiling |
| **Profiling consent** | Separate granular parameter authorising rec-engine profiling | **Authority for Art 21 opt-out.** Plugin writes it to Smaily and reads it back. Withdrawn → no profiling. |
| Tracking consent | (Today not yet mandatory) | Smaily-owned |

### WooCommerce-held — OUT OF SCOPE

Orders, line items, addresses, purchase history → WooCommerce's own GDPR
exporters/erasers. The rec-engine plugin does not touch these.

---

## Consent model — granular, not one switch

Following Estonian AKI guidance, consent is **granular** — a shopper can grant
or withhold each independently; a single "cookies: no" must NOT switch all of
them off:

1. **Marketing email** (Smaily email) — Smaily consent.
2. **Tracking** (beacon cookies) — browser-cookie consent (CookieYes / WP
   Consent API). Today not yet mandatory.
3. **Profiling** (rec-engine via Woo, incl. beacon use) — **Smaily profiling
   consent** (separate parameter). This is the one that governs us.

### Two consent gates on the beacon

The browse beacon now requires **both** of these to be ON before it sends:

1. **Browser-cookie consent** (CookieYes / WP Consent API) — the ePrivacy /
   cookie-law basis for setting cookies and firing the beacon at all. (Built in
   3.4.2.)
2. **Profiling consent** (Smaily) — the GDPR profiling basis. If profiling
   consent is OFF, the beacon **stops** even when cookie consent is ON — they
   are distinct legal bases, so both are required.

Profiling consent OFF therefore produces **two actions**:
- **Engine opt-out** (§10) — customer excluded from recommendations; data
  retained; reversible.
- **Beacon-stop** — no new browse events collected.

---

## GDPR rights — what each one does

### Art 15 — Export (subject access)

Returns the shopper's **rec-engine personal data**, and nothing more:

- Included: engine browse_events, visitor_tokens, recommendations, email_events,
  engine customer record; plugin rec-meta (the `_smaily_*` markers).
- **Not included:** `rec_attribution` (decision logic / trade secret — omitted,
  not flagged as "request separately", because it is not a subject-access right);
  the rec-engine's decision logic / weights (trade secret, as Google/Meta also
  withhold); WooCommerce order data (Woo's exporter handles that).
- The privacy policy explains *what categories of data are used and why* — that
  is a separate layer from the export, which returns the personal data itself.

### Art 17 — Erase

Full deletion, asymmetric to export (export is conservative, erase is complete):

- Engine §9 DELETE: customer + browse_events + recommendations + email_events +
  visitor_tokens + **rec_attribution** all deleted (CASCADE). Idempotent (a
  second call returns 404 → treat as success).
- Retained after erase: a `gdpr_audit_log` row (proof the deletion happened) and
  `lift_metrics_daily` in anonymised form (aggregate, no PII).
- Plugin side: the `_smaily_*` order-meta and user-meta markers are removed, so
  no rec-specific traces are left behind in WordPress.

### Art 21 — Opt-out (profiling objection)

- Authority: **Smaily profiling consent** (separate parameter). The plugin
  writes the consent state to Smaily and reads it back; withdrawal is the
  trigger.
- Effect: engine opt-out (§10 — data retained, excluded from recommendations,
  reversible) **plus** beacon-stop (no new events). Distinct from erase: opt-out
  keeps the data and is reversible; erase deletes it.

---

## Build split (3.8 and what follows)

- **3.8 GDPR (this work)** builds the **mechanism**: the engine §8/§9/§10 client
  methods (export / delete / opt-out) and the WP Privacy API exporter + eraser
  (covering engine rec-data + plugin rec-meta, NOT Woo data). Self-contained;
  does not depend on the Smaily consent API.
- **Smaily profiling-consent wiring + beacon-stop** is a **separate piece**
  (after 3.8) because it depends on the Smaily profiling-consent parameter API
  (how the plugin stores and reads it). It *uses* the 3.8 engine-opt-out method,
  so 3.8 must ship the mechanism first.

---

## One-line summary per system

- **Engine** — holds rec personal data; export omits attribution, erase deletes
  everything, opt-out excludes-but-keeps.
- **Plugin** — holds local rec markers; exports/erases its own meta; reads
  profiling consent from Smaily.
- **Smaily** — owns profiling consent (the Art 21 authority); plugin reads it
  back.
- **WooCommerce** — owns commerce data; out of scope for this plugin's GDPR
  handlers.
