# BACKLOG.md — consolidated deferred-work index (Smaily Connect)

**Internal doc.** This repo stays private (the pilot ships via a built ZIP; the
public face is an upstream-merge into the main plugin, not this Git history). So
this backlog is internal working knowledge, like STATUS / CLAUDE / DECISIONS.

## What this file is for

One place to see **everything deferred** — so a pilot go/no-go can tell
*conscious deferral* from *forgotten*, and post-pilot priority is a single list
instead of five STATUS sub-sections + scattered DECISIONS notes.

**Division of labour (keep it this way):**
- **STATUS.md** — "where we are now" (current state, what just shipped).
- **BACKLOG.md** (this file) — "what's deferred + why + priority" (the index).
- **DECISIONS_DRAFT.md** — the *why* / rationale. This file **links** there, it
  does not duplicate the reasoning.

Priority legend: 🔴 pilot-need (before go-live) · 🟡 post-pilot · 🟢 nice-to-have
· 🔵 manual pilot-verification (not deferred code — execution tasks).

_Last updated: 2026-06-09 (initial consolidation: gathered STATUS §Post-pilot /
§Known-deferred / §Future-backlog / (a)-TODO + DECISIONS inline defer-notes +
WP7_COMPAT Phase-4 + three audit-only gaps that were tracked nowhere)._

---

## 🔴 Pilot-need — before go-live (mostly NOT plugin feature code)

| What | Why deferred | Doc location |
|---|---|---|
| **WP 7.0 env-matrix verification** | Floors declare WP tested-7.0, but integration runs only WP 6.9.4. Add a wp-env compat env so integration runs on both 6.9.4 + 7.0. Pre-pilot pin. | STATUS §Post-pilot (L366), `docs/WP7_COMPAT.md` |
| **Privacy-policy must mention profiling** | Legal — the opt-out/default-on model requires a transparent disclosure. Erkki / docs. | STATUS L407/L422, DECISIONS F3-31 (L1643) |
| **(a) fail-open GDPR-window review** | Conscious consent-risk in the read-error window (fail-open + opt-out model). Erkki investigating separately before any opt-in flip. | STATUS L423, DECISIONS L1637 |
| **Doc-drift fix (upstream-merge need)** | Public docs describe an earlier state — go to the main plugin's docs on merge: **README** "(in progress)" → feature-complete + add Event-Log/profiling rows; **INSTALL.md** add the profiling-opt-out section ((a) shipped a My-Account toggle); ~~**readme.txt** rewrite for 2.0~~ (DONE 2026-06-11, FABLE_AUDIT fix F4 — stable tag 2.0.0-beta.1, 2.0 description + changelog + the rec-engine external-services disclosure). | Audit (OSA-1); see README L5/L20/L39, `docs/INSTALL.md`, `readme.txt` |

> **GCM encryption — RESOLVED 2026-06-11** (FABLE_AUDIT fix F3, DECISIONS F3-32:
> Cypher v2 `smy2:` AES-256-GCM + legacy-read fallback + Activation upgrade
> re-encryption of all stored secrets). No longer a backlog item.

> **TESTING.md pilot-acceptance plan — RESOLVED** (Erkki supplied the business
> logistics: duration, real-data, go/no-go). No longer a backlog item.

---

## 🟡 Post-pilot — deferred plugin work

| What | Why deferred | Doc location |
|---|---|---|
| **3.10.3 email channel** (`wp_mail`) | Admin-notice base already covers proactive-in-wp-admin; email needs working server SMTP (recommend an SMTP plugin in the doc). | STATUS L362, DECISIONS L1606 |
| **Queue janitor** (prune `sent`/`failed` rows + index `created_at`) | Scale-housekeeping, not pilot-blocking. | STATUS L365, DECISIONS L1618 |
| **(a) explicit opt-in if AKI tightens** | Conditional — `ProfilingConsent::is_allowed()` is invertible; flip only if the regulator requires opt-in. | STATUS L406, DECISIONS L1643 |
| **(a) drop-count UI surface** (`smly_profiling_dropped_24h`) | Counter is wired; surfacing it in the UI is a refinement, not built. | STATUS L386, DECISIONS L1682 |
| **Auto-retry of transient failures** | Conscious choice: Retry is **manual-only** — auto-retrying a deterministic 4xx loops. Revisit only if transient-vs-permanent classification is added. | STATUS L339, DECISIONS L1601 |
| **`orders_count` scale** | Large-order-volume behaviour of the HPOS order-count / pagination path not load-checked. _(Was tracked nowhere before this file — audit-only gap.)_ | Audit-only — needs a home; verify under a high-order-count env post-pilot |

### Phase-4 strategic (preserved plan — do NOT drop)

| What | Status | Doc location |
|---|---|---|
| **WP 7.0 AI-catalog registration** | **Planned, NOT built.** Smaily Connect offers AI-based recommendations, so it should register into WP 7.0 "Armstrong"'s AI infrastructure. Three opportunities, all experimental in WP 7 → wait for stable contracts: **(1)** Smaily + rec-engine as **Connectors** (centralised credential storage); **(2) Abilities API ⭐** — register typed actions AI agents can discover (the marketing win: first Estonian/Nordic email platform with Abilities support); **(3) MCP Adapter** — auto-exposes the Abilities to MCP tools. P5 touched only version-floors, so no AI-feature code exists yet to preserve. | `docs/WP7_COMPAT.md` §Strategic-opportunity (Opp 1/2/3, L67–125, roadmap L147–152) |

---

## 🟢 Nice-to-have / cosmetic

| What | Status | Doc location |
|---|---|---|
| **N-7 `EVENT_*` constant-location asymmetry** | Cosmetic — catalog constants on CatalogHookHandler, customer/order on their Flusher. N-7 chose an abstract base (not a dispatcher), so the "unify" premise no longer applies. Defer or drop. | STATUS L505 |
| **Flaky `useBackfillProgress` test** (fake-timer race) | Fix with deterministic timer mocking. | STATUS L510 |
| **JS `mergeIdentity` stub** | **Intentional, NOT dead code** — kept platform-agnostic for the M2 path. Documented as deliberately retained. | DECISIONS L1439, STATUS L252 |
| **User-level consent-bridge** (Cookiebot / custom) | YAGNI — MiuMjau is CookieYes (WP-Consent-API-native, needs nothing). Build when a non-WP-Consent-API client arrives. | STATUS L535 |

---

## 🔵 Manual pilot-verification (NOT deferred code — execution tasks)

These are things a server-side test can't prove; verify by hand during the pilot.

| What | Why not machine-testable | Doc location |
|---|---|---|
| **Browse render-timing** (page-view fires on the right page) | A server-side walk can't observe the browser moment (`checkout_start` on checkout, `checkout_complete` on order-received, `product_view` on a product page). PHP page-type detection can't be driven in the integration harness (no `go_to()`); JS mapping is vitest-tested. (Future Chromium E2E — not built, YAGNI.) | STATUS L512, CLAUDE.md "Browse browser-timing" |
| **CookieYes live consent-gating** | Confirm a real consent plugin actually suppresses the beacon. | STATUS L428 |

---

## ✅ Resolved / decided — recorded so they aren't re-raised

| Item | Outcome |
|---|---|
| **Route-A N-4 — CSV divergence** | RESOLVED (N-4a): the admin CSV path now also requires `product_url` + `in_stock`, matching `ProductSchema`. Engine commit `852ea04`. (`docs/RECENGINE_API_CONTRACT.md` L1439.) |
| **Route-A D-5 — export attribution** | DECIDED: the engine export deliberately omits the `rec_attribution` array (engine-side Art-15 legal review, not a plugin contract issue). Tracked as known-deferred. (STATUS L521, contract L1175.) |
| **Route-A F-1 — AI field-mapping wizard** | RESOLVED — **engine-side** functionality (the engine maps fields via AI), believed built. Never a plugin-side item, so correctly untracked in this repo. **Confirm with the engine team.** Not a plugin deferral. |
