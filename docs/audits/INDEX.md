# Audits — register

**Single index of every audit run on Smaily Connect.** This folder
(`docs/audits/`) holds the audit reports; this file is the table that says what
each one covered, when, against which repo state, and what it concluded. New
audits get a row here **and** a report file in this folder.

> Why this folder exists: audits were scattered in `docs/`. Keeping them together
> with one register makes "when did we last look at X, and has the code moved
> since?" answerable at a glance — and enforces the re-audit policy below.

---

## Register

| Audit | Date | Repo state (baseline) | Scope | Auditor | Outcome | Report |
|---|---|---|---|---|---|---|
| **Codebase audit (Fable)** | 2026-06-11 | `906cf3d` · v2.0.0-beta.1 | Whole codebase — functionality / security / DB-quality (3 parallel passes) | Claude (Fable 5) | 1 CRITICAL (password in debug.log → F1) + 1 IMPORTANT (static crypto IV → F3) + recs F2/F4/F5/F6. **All F1–F6 fixed.** | [`FABLE_AUDIT.md`](FABLE_AUDIT.md) |
| **Security audit** | 2026-06-25 | `2597888` · v2.1.0-beta.10 · delta `906cf3d..HEAD` | High-risk surfaces + broad sweep; wordpress.org security bar | Claude (Opus 4.8) | **0 Critical / 0 High.** 2 Low (admin-gated SSRF on engine base_url; PII-at-rest cleartext), 2 Info (rate-limit transient; unconditional `error_log`). composer/npm deps clean. | [`SECURITY_AUDIT.md`](SECURITY_AUDIT.md) |
| **Code-quality + wp.org-readiness** | 2026-06-25 | `2597888` · v2.1.0-beta.10 · delta `906cf3d..HEAD` | Changed-code quality/architecture + full PCP 2.0.0 + plugin-review guidelines | Claude (Opus 4.8) | **No High defects; GA/upstream-ready.** **Punch-list APPLIED same day** (ABSPATH sweep, `error_log`→DebugLog, PCP `phpcs:ignore`s, blocks apiVersion 3, `.zipignore`); **`wp plugin check` against the built ZIP is clean** except 2 intentional (`Update URI`=fork guard → upstream merge; `(BETA)`=3.0 cut). ci:strict exit=0 (unit 374, JS 158). | [`CODE_QUALITY_AUDIT.md`](CODE_QUALITY_AUDIT.md) |
| **Upstream audit** (sendsmaily/smaily-wordpress-plugin) | 2026-06-03 | fork `39ade27` vs upstream `86da046` | Commit-by-commit review of the 14 upstream commits since fork-point | Claude Code | 0 security, 6 bug-fixes (**all cherry-picked** 2026-06-03), 3 compat open (#120/#128/#132) | [`UPSTREAM_AUDIT.md`](UPSTREAM_AUDIT.md) |
| **Upstream comparison** (snapshot) | 2026-06-12 | fork `bed2aa6` vs upstream tag `2.0.0` | Point-in-time feature/stack/version/quality comparison + version-collision story | Claude Code | Snapshot companion to the upstream audit; informs the merge strategy | [`UPSTREAM_COMPARISON.md`](UPSTREAM_COMPARISON.md) |
| **Mock ↔ real-engine divergence** | Phase 3.2.4 (living) | n/a (per-endpoint register) | Where the integration mock + payload design diverge from the deployed engine | Plugin side | Engineering tool — records divergences to close as the engine grows toward the contract | [`MOCK_DIVERGENCE_AUDIT.md`](MOCK_DIVERGENCE_AUDIT.md) |
| **3.1.0 release gate (F3-46)** | 2026-06-26 | `904f4ab` · v3.1.0 · delta = `LandingCapture` (new input/cookie surface) | Focused security pass on the new surface (read-only `$_GET` capture + first-party cookie write) + PCP against the BUILT ZIP | Claude (Opus 4.8) | **No new findings.** Input: `wp_unslash`+`sanitize_text_field`+strict regex (uuid / `vt_` / slug), no SQL/eval/file-IO, no echo (→ no XSS), no header-injection; nonce N/A (public read-only landing). Cookies: first-party, non-secret (rec_id uuid + opaque token), SameSite=Lax/Secure-on-https/HttpOnly=false (contract). No open redirect (redirect endpoint not built). **PCP on the ZIP clean** except the intentional `Update URI` (F3-35). ci:strict exit=0 (unit 391, JS 158); integration OK 119. | inline (this row) |
| **F3-47/F3-48 security re-audit** | 2026-06-30 | `c369486` · delta `522b305..HEAD` (contact-language resolver + contact-sync mode engine) | Independent security pass (fresh-context agent) on the delta: REST mode-write, new external HTTP (action-log/contact-list), the global `update_user_meta`/`add_user_meta` hooks, consent/PII, SQL | Claude (Opus 4.8, clean-context agent) | **0 Critical/High.** 1 Medium — reconcile→meta-hook echo could re-create a Smaily-`delete`d contact (GDPR hygiene) → **FIXED** (`ContactReconciler` reentrancy guard `is_applying()`/`run_suppressed()`; HookHandler skips); 1 Low (redundant consent re-POST, same root, fixed). Confirmed clean: REST `manage_options`-gated + mode validated; Authorization header never captured in `last_exchange`; action-log/contact PII never persisted; meta hooks no-DoS/no-recursion; consent (marketing vs profiling) separated; SQL `$wpdb->prepare`d. | [`2026-06-30-SECURITY_RE_AUDIT_F347_F348.md`](2026-06-30-SECURITY_RE_AUDIT_F347_F348.md) |

---

## Re-audit policy — NOT optional

An audit is a snapshot of a specific repo state. Code moves; a stale audit hands
false confidence (the 2026-06-25 audits exist precisely because ~10k lines landed
after the 2026-06-11 one). So:

**Re-run the security + code-quality audits after any of these:**
1. **A release boundary** — before cutting a GA / non-beta tag, and before any
   **upstream/wordpress.org submission** (the public review bar is higher).
2. **A large delta** — rule of thumb: **> ~2,000 changed lines** of plugin code,
   or any change touching a **security-sensitive surface**: a new/changed REST
   route, the public `/relay` beacon, auth/capability/nonce logic, crypto, SQL
   against custom tables, what gets stored/logged (secrets/PII), GDPR/consent, or
   file/HTTP I/O with external input.
3. **A new external trust boundary** — a new outbound destination, a new
   data-at-rest surface, or a new public/unauthenticated entry point.

**Scope of a re-audit** = the delta since the last audit's baseline (its
`Repo state` column), plus a security pass on any high-risk surface the delta
touched, plus PCP **against the built ZIP** for a release/submission gate. Record
the new run as a row above + a dated report, and bump the relevant doc baselines.

**Where this is enforced:** `CLAUDE.md` (operational rhythm) points here; `STATUS.md`
records each run. If you make a bigger change and skip the re-audit, that's a defect
— treat it like a skipped gate.
