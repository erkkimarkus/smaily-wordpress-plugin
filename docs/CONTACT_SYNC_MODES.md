# CONTACT_SYNC_MODES.md — Contact-sync mode engine (design)

> **Status: DESIGN / for review (2026-06-30).** Not yet implemented. This file
> is the agreed shape before any code. DECISIONS F3-48 carries the summary +
> rationale; this file carries the full design. Implementation sub-PRs are
> listed in § Implementation sequence.

## 1. Why this exists

Different merchants need different contact-sync behaviour, driven by their
**lawful basis for marketing** and their store shape. Three real pilot/managed
stores, three different needs:

- **Client 1 (Prike)** — wants **all** customers in Smaily under *legitimate
  interest*; no consent management in WordPress (consent lives in Smaily).
  Legacy could not do this (its cron only synced `user_newsletter=1`), which is
  exactly why ~contacts went "missing" and a Make double-sync was bolted on.
- **Client 2** — collects **consent** (homepage form → Smaily; checkout/account
  checkbox → our plugin), and wants to email **only** those who opted in. Needs
  the store side kept in sync with Smaily's unsubscribes/returns.
- **Client 3 (MiuMjau-shaped)** — no accounts, only **guest checkout**, wants a
  cart opt-in checkbox; forward a contact **only** when the box is ticked, sync
  nothing back.

Rather than a combinatorial matrix of independent toggles (foot-guns: a merchant
could configure an incoherent / unlawful combination), the plugin exposes a
small set of **named presets** that each map to a lawful basis, plus a couple of
genuinely-orthogonal sub-options and developer filters for the long tail.

This decision was taken with Erkki (2026-06-30): named presets, design the whole
engine before building, default to the consent-safe preset.

## 2. The model

Internally factored as `legal_basis` (legitimate_interest | consent) + a few
sub-options; surfaced in the UI as **three named presets**:

| Surface | **1. All customers** (legitimate interest) | **2. Subscribers only** (consent) — *default* | **3. Checkout opt-in only** |
|---|---|---|---|
| Lawful basis | Legitimate interest | Consent | Consent |
| Live-sync trigger | every registered customer (register / profile / order) | only on opt-in (`user_newsletter=1` via checkbox) | only the checkout checkbox |
| Account (registered) sync | yes | yes | **no** |
| Mass backfill / daily-refresh audience | **all** registered customers | only `user_newsletter=1` | none (event-only) |
| Guests | optional (`include_guests`, default **off**) | guest checkout opt-in | guest checkout opt-in (the point) |
| `is_unsubscribed` sent on upsert | **never** (Smaily owns suppression) | `0` on opt-in; `1` on WP opt-out | `0` on checkbox |
| Sync-back (Smaily → WP reconcile) | no | **yes** — leavers **and** returners | no |
| Automation `force_opt_in` | `true` (subscribe + deliver) | **`false`** (trigger fires, consent untouched) | **`false`** |

Client 1 = preset 1, Client 2 = preset 2, Client 3 = preset 3. Preset 3 is
"consent, no accounts, no reconcile, guests" — a coherent bundle, not a fourth
lawful basis.

## 3. Presets in detail

### Preset 1 — All customers (legitimate interest)
- **Audience:** every registered customer. Guests only if `include_guests` is on
  (default off — Erkki).
- **Live:** register / profile-update / order → upsert (no opt-in filter).
- **Mass:** backfill + daily refresh cover **all** registered customers — this is
  what fixes Prike's "missing contacts".
- **Send:** never send `is_unsubscribed` (Smaily owns suppression; a plain upsert
  preserves an existing unsubscribe — confirmed by Erkki).
- **Sync-back:** none.
- **Automations:** `force_opt_in=true` (the merchant asserts a basis to reach
  all). **Legal note:** `force_opt_in=true` re-subscribes a contact on trigger,
  overriding an explicit unsubscribe — GDPR Art. 21 makes the unsubscribe right
  absolute for direct marketing. The preset's warning banner (§9) states this; see
  the open question in § 11 on whether to default this to `false` even here.

### Preset 2 — Subscribers only (consent) — DEFAULT
- **Audience:** only customers with `user_newsletter=1` (set via the
  registration / account / checkout checkbox, persisted to user meta by
  `profile-settings.class.php`).
- **Live:** on opt-**in** → upsert + subscribe (`is_unsubscribed=0`). On opt-**out**
  (customer unticks in My Account) → send `is_unsubscribed=1` (bidirectional
  consent — Erkki).
- **Mass:** backfill + daily refresh cover only `user_newsletter=1`.
- **Sync-back (reconcile):** daily pull from Smaily — **both** directions
  (Erkki): a Smaily unsubscribe → WP `user_newsletter=0`; a Smaily re-subscribe
  → WP `user_newsletter=1`. Keeps WP a faithful mirror of Smaily.
- **Automations:** `force_opt_in=false` — the trigger fires but Smaily does not
  update the contact's consent, so an unsubscribed contact is not re-subscribed
  or mailed.

### Preset 3 — Checkout opt-in only
- **Audience:** none account-based; only the checkout checkbox produces a
  contact (guests included — the whole point).
- **Live:** only the checkout checkbox → upsert + subscribe.
- **Mass:** no backfill / refresh (event-only).
- **Sync-back:** none.
- **Automations:** `force_opt_in=false`.

## 4. Sub-options (orthogonal)

- **`include_guests`** (bool, default **off**) — include guest-order emails as
  contacts. Off in presets 1/2 by default; intrinsically on in preset 3.
- **Reconcile direction** is derived from the mode (consent → both directions),
  not a user toggle, to keep presets coherent. Developer filters can narrow it.
- Everything else (segment/list routing, role filters) is a **filter**, not UI.

## 5. Consent / send semantics

Single policy object decides, per mode, what an upsert carries:
- `is_unsubscribed` is **omitted** unless the mode + event explicitly set it
  (preset 2 opt-in → `0`, opt-out → `1`). Omission preserves Smaily's value;
  presets never silently flip consent.
- The contact **language** comes from `ContactLanguageResolver` (F3-47),
  unchanged and mode-independent.

## 6. Automations + `force_opt_in`

`Client::trigger_automation(workflow_id, addresses, force_opt_in=true)` sends a
Smaily `force_opt_in` flag; `true` (the current default) re-subscribes the
contact on trigger. Today this is inconsistent: the new `AutomationRouter`
(welcome / first_order / abandoned_cart) calls it **without** the third arg →
always `true`, while the **legacy** abandoned-cart cron passes `false`
(cron.class.php:217).

The mode engine unifies this: **the contact-sync mode drives `force_opt_in` on
every automation trigger** (welcome, first_order, abandoned_cart):
- consent presets (2, 3) → `force_opt_in=false`;
- legitimate-interest preset (1) → `force_opt_in=true` (subject to the § 11
  open question / warning).

This guarantees you can't pick a strict contact-sync posture while automations
silently re-subscribe everyone.

`force_opt_in` is an **undocumented** Smaily API parameter; it is being added to
our hand-written Smaily API docs in `../re/docs` (separate task) so the behaviour
relied on here is recorded on the Smaily side too.

## 7. Architecture (CC-1 single source)

One decision core, consumed by every surface:

- **`ContactSyncMode`** — reads `smly_plus_contact_sync_mode` + `include_guests`;
  exposes a pure policy: `{ audience, send_semantics, reconcile_direction,
  automation_force_opt_in }`.
- **`ContactAudience`** — "should this user / email be synced?" Used by the live
  `HookHandler` (per-event gate), `BackfillJob` (the `get_users` query filter),
  and the reconcile job.
- **`ContactSyncPolicy`** — `is_unsubscribed` + `force_opt_in` decisions.
- **`ContactReconciler`** — Smaily → WP sync-back (consent mode only). The old
  unsubscribe-pull lives here, generalised to both directions. **Not dead code —
  it is preset 2's feature.**

**Integration points:**
- `HookHandler` live gate → `ContactAudience`.
- `BackfillJob` audience filter + the SP-D daily refresh → `ContactAudience` +
  `ContactSyncPolicy`.
- `on_contact_sync_tick` (the SP-D cron takeover) → mode-aware refresh **+**
  (consent mode) `ContactReconciler`. The buggy legacy `Cron::smaily_sync_subscribers`
  mass-send is no longer fired in any mode.
- `AutomationRouter::trigger_automation` → `ContactSyncPolicy::force_opt_in()`.
- `LegacyHookBridge` already strips the legacy live hooks post-wizard; unchanged.

## 8. Storage, default, migration safety

- `smly_plus_contact_sync_mode` — enum, **default `consent`**.
- `smly_plus_contact_sync_include_guests` — bool, default `false`.
- **Default = consent is both lawful-safe and back-compat-safe:** legacy's cron
  already filtered `user_newsletter=1`, so a consent default matches existing
  behaviour — upgrading never silently broadens the audience. Legitimate interest
  is always a deliberate opt-in.
- Prike sets preset 1 explicitly at cutover (which also resolves its missing-
  contacts problem).

## 9. UI / UX

Lives in **`Step2Subscribers`** (wizard) and its **Settings** mirror, matching
the existing `Card` / `Toggle` / `Checkbox` / `Banner` primitives:

- A new **"Contact sync mode"** `Card` above "Contact synchronisation", with the
  three presets as selectable **radio-cards** (title + one-line description each),
  same visual language as the existing cards.
- Selecting **"All customers (legitimate interest)"** reveals a **`Banner`
  tone="warning"`**: *"This sends every customer to Smaily regardless of marketing
  consent. Make sure you have a lawful basis (legitimate interest). Automation
  triggers may re-subscribe contacts — see settings."* (role="alert").
- An **`include_guests` `Checkbox`** under the mode card (default off), shown for
  presets 1/2.
- Preset 2 shows a short info `Banner` that the store mirrors Smaily's
  unsubscribes/returns daily.
- The existing "Subscription checkboxes" + "Fields to sync" + "Initial backfill"
  cards stay; their relevance follows the mode (e.g. the checkboxes matter for
  presets 2/3).

## 10. GDPR guardrails

- Default consent; legitimate interest is deliberate + warned.
- Audience is never silently broadened on upgrade.
- Strict (consent) presets force `force_opt_in=false` so automations cannot
  re-subscribe.
- The lawful basis is the **merchant's** responsibility; the plugin provides a
  faithful mechanism for the chosen basis.

## 11. Scope boundaries & open questions

**Out of scope (unaffected by the mode):**
- **Rec-engine customer ingest** (`CustomerHookHandler`) — a different
  destination (the recommendations engine, gated by `is_connected()`), not Smaily
  contacts.
- **Abandoned-cart as a feature** keeps its own enable toggle; only its automation
  `force_opt_in` is mode-driven.

**Open questions for Erkki before the doc is final:**
1. Preset 1 automation `force_opt_in`: default `true` (reach all, may override an
   unsubscribe), or default `false` with an explicit advanced "force opt-in on
   automation triggers" toggle (+ warning)? The latter respects unsubscribes by
   default even under legitimate interest (GDPR Art. 21) while still meeting the
   "stricter modes pass false" requirement. *(Recommend: default `false` + advanced
   opt-in toggle.)*
2. Preset names — keep "All customers (legitimate interest)" / "Subscribers only
   (consent)" / "Checkout opt-in only", or shorter labels?

## 12. Implementation sequence (after design sign-off)

1. **Mode config + `ContactSyncMode`/`Audience`/`Policy` core** + wiring into
   `HookHandler` (live gate) + `BackfillJob` (audience filter) + send semantics.
2. **`ContactReconciler`** (Smaily→WP, both directions, consent mode) **+ SP-D
   cron takeover** (`on_contact_sync_tick` → mode-aware refresh; legacy buggy
   mass-send retired).
3. **`AutomationRouter` `force_opt_in`** made mode-driven (welcome / first_order /
   abandoned_cart unified).
4. **Settings / wizard UI** — mode radio-cards + warning banner + `include_guests`.
5. **Regression locks** — `is_unsubscribed` + `force_opt_in` per mode; audience
   per mode.
6. **Cutover (Prike)** — install plugin → wizard → preset 1 → Make data-sync off.

Builds on F3-47 (the language resolver — already shipped, mode-independent).
