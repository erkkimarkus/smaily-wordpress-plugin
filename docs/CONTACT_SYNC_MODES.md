# CONTACT_SYNC_MODES.md — Contact-sync mode engine (design)

> **Status: DESIGN — APPROVED (Erkki, 2026-06-30).** Not yet implemented. Both
> open questions resolved (§ 11). This file is the agreed shape before any code.
> DECISIONS F3-48 carries the summary + rationale; this file carries the full
> design. Implementation sub-PRs are listed in § Implementation sequence.

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
| Automation `force_opt_in` | **`false`** default; advanced toggle to force opt-in | **`false`** (trigger fires, consent untouched) | **`false`** |

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
- **Automations:** `force_opt_in=false` **by default** (resolved with Erkki) — even
  under legitimate interest an explicit unsubscribe is honoured (GDPR Art. 21: the
  right to object to direct marketing is absolute). A merchant who genuinely wants
  triggers to re-subscribe enables the advanced **"Force opt-in on automation
  triggers"** toggle (§ 4), which flips this preset to `true` behind a warning. The
  strict presets (2, 3) never expose that toggle — they are always `false`.

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
- **"Force opt-in on automation triggers"** (bool, default **off**) — shown ONLY
  for preset 1 (legitimate interest); when on, automation triggers send
  `force_opt_in=true` (re-subscribe on trigger). Behind a warning. Presets 2/3
  never expose it (always `false`).
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
- consent presets (2, 3) → `force_opt_in=false` (always);
- legitimate-interest preset (1) → `force_opt_in=false` by default, `true` only
  if the merchant enables the advanced "Force opt-in on automation triggers"
  toggle (§ 4) behind a warning.

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
  it is preset 2's feature.** **Delta-first (Erkki, resource concern):** the
  standing reconcile polls the Smaily **action-log** (`GET /api/history.php`,
  `since_seq_id` cursor) for only `optin`/`optout`/`delete`/`complaint` deltas —
  O(changes), a handful of requests, fine on shared hosting even for large bases.
  A full `GET /api/contact.php?list=1` pull is the **occasional re-baseline only**
  (onboarding / manual / stale-or-missing cursor — the action-log's ~30-day
  retention means a long-dormant poller needs a re-baseline to recover the gap).
  Never a full pull on every tick. Cursor persisted in
  `smly_plus_contact_reconcile_seq`. Mirrors the engine's action-log approach
  (`re/docs/CONTACT_RECONCILIATION_DESIGN.md`) and the consent-strategy split in
  `docs/CONSENT_STRATEGY_COMPARISON.md`.

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
- An advanced **"Force opt-in on automation triggers" `Toggle`** shown ONLY for
  preset 1, default off, with its own `Banner tone="warning"`: *"Triggering a
  welcome / abandoned-cart / first-order automation will re-subscribe the contact
  in Smaily, overriding an existing unsubscribe."*
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

**Resolved decisions (Erkki, 2026-06-30):**
1. Preset 1 automation `force_opt_in`: **default `false`** (honour unsubscribes
   even under legitimate interest — GDPR Art. 21) + an advanced "Force opt-in on
   automation triggers" toggle (preset 1 only, behind a warning) to override.
   Presets 2/3 are always `false`.
2. Preset names: keep **"All customers (legitimate interest)"** / **"Subscribers
   only (consent)"** / **"Checkout opt-in only"**.

## 12. Implementation sequence (after design sign-off)

1. **Mode config + `ContactSyncMode`/`ContactAudience` core** + wiring into
   `HookHandler` (live `contact.sync` gate) + `BackfillJob` (audience filter).
   **DONE (F3-48.1).** Send semantics (`is_unsubscribed` on opt-out) deferred to
   step 5 with the regression locks.
2. **`ContactReconciler`** (Smaily→WP, both directions, consent mode) —
   **action-log delta poll** (`history.php` + `since_seq_id`) as the standing
   reconcile, full `list=1` pull only as an occasional re-baseline. **DONE
   (F3-48.2)** — `Client::get_action_log()` + `Client::list_contacts()` +
   `ContactReconciler::reconcile()`/`rebaseline()`, not yet wired.
3. **SP-D cron takeover** (`on_contact_sync_tick` → `ContactReconciler::reconcile`
   + mode-aware `BackfillJob` refresh via a non-clearing `start(false)`; legacy
   buggy mass-send retired). Wires step 2 in. **DONE (F3-48.3)** —
   `start(bool $reset_freshness)` + `BackfillJob::should_start_refresh()` (re-arm
   guard) + the rewritten tick (reconcile + non-clearing refresh; legacy
   `smaily_connect_cron_sync_subscribers` no longer fired, left orphaned).
4. **`AutomationRouter` `force_opt_in`** made mode-driven (welcome / first_order /
   abandoned_cart unified). **DONE (F3-48.4)** — `trigger_automation` now passes
   `ContactSyncMode::automation_force_opt_in()` (consent/checkout → always `false`;
   legitimate interest → `false` unless the advanced toggle is on). Replaces the
   hard-coded `true` default.
5. **Settings / wizard UI** — mode radio-cards + warning banner + `include_guests`.
   **DONE (F3-48.5)** — "Contact sync mode" Card in `Step2Subscribers` (3 radio
   presets, legitimate-interest warning Banner, `include_guests` checkbox,
   preset-1-only force-opt-in toggle); wired through the wizard reducer
   (`SET_CONTACT_SYNC_MODE`/`SET_INCLUDE_GUESTS`/`SET_AUTOMATION_FORCE_OPT_IN`),
   `buildTabPayload`/`hydrate`/`settings-reducer`, `SettingsEndpoint::
   save_subscribers` (validated) + `EnvDetector::saved_settings` (boot). New
   English `__()` strings — `.pot`/`-et.po` regen + ET translation is a packaging
   step (`bin/build-i18n.sh`).
6. **Regression locks** — `is_unsubscribed` + `force_opt_in` per mode; audience
   per mode.
7. **Cutover (Prike)** — install plugin → wizard → preset 1 → Make data-sync off.

Builds on F3-47 (the language resolver — already shipped, mode-independent).
