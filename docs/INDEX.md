# Documentation index

A catalog of every document in the Smaily Connect project — plugin repo, engine
repo, and Erkki's internal notes. Use this as the starting point if you don't know
where something is.

**Language convention:**
- All published documents in repos: **English**
- Internal drafts and notes (only Erkki): **Estonian or English** (Erkki's choice)
- Code comments, commit messages, technical PR/issue text: **English**
- Conversations with Claude (development workflow): **Estonian** (Erkki's working
  language)

**Locations:**
- `plugin-repo/docs/` — Smaily Connect plugin documentation
- `engine-repo/docs/` — rec-engine documentation (separate repo, engine team owns it)
- Erkki's local working drafts — outside repos, regenerated to repos when ready

---

## Plugin documentation (`plugin-repo/docs/`)

### User-facing — ships with the plugin or in marketplace

| Document | Audience | Status | Description |
|----------|----------|--------|-------------|
| `README.md` | Anyone evaluating the plugin | **Exists** | First impression. What the plugin does, who it's for, install summary, links to deeper docs. Lives at repo root, also surfaces in marketplace listings. (Refined further at Phase 3 end.) |
| `docs/INSTALL.md` | Pilot merchant installing + setting up | **Written** (P4) | Merchant-facing: install (ZIP upload) → setup wizard (Smaily + rec-engine connect, browser-cookie consent + browse toggle) → verify (engine-confirmed backfill, Event Log, live smoke test) → troubleshoot (Event Log → Retry, health notices). Screenshot placeholders inline. Add the profiling-consent section once Smaily-consent (a) ships. |
| `MIGRATION.md` | WP-admin running the legacy `sendsmaily/smaily-wordpress-plugin` | **Written** (`docs/MIGRATION.md`) | How to migrate from the legacy plugin to this one: in-place upgrade, what persists, wizard takeover, rollback path. (This row previously said TODO after the doc had shipped — fixed 2026-06-11.) |
| `FAQ.md` / `TROUBLESHOOTING.md` | WP-admin running into problems | **TODO** (after pilot, ~2-4 weeks in) | Real user questions and symptom→cause→fix patterns. Written **after** the pilot is in production and asks real questions. Drafting it earlier would just be guesses. |
| `CHANGELOG.md` | Anyone tracking versions | **Written** (2026-06-11, repo root) | Version-by-version what changed: full 2.0.0-beta.1 entry + the 1.x history. `readme.txt` carries the same content in wp.org format. |
| `docs/TESTING.md` (pilot-acceptance) | Pilot engagement: is it production-ready? | **Written** (2026-06-11, from Erkki's criteria) | Business pass/fail criteria for the pilot engagement (distinct from INSTALL.md's technical verify): two gating dimensions (technical stability + merchant experience), business metrics tracked-not-gated, and logistics (4–6 wk, real data from start, check-in cadence, go/no-go review). NB: the existing root `/TESTING.md` is a separate Phase-2 dev sanity-test, not this. |

### Developer-facing — lives in repo, may be public or repo-only

| Document | Audience | Status | Description |
|----------|----------|--------|-------------|
| `CLAUDE.md` (repo root) | Any agent picking up the repo | **Exists** | Agent working guide — the entry point. Workflow rhythm, operational knowledge (`sg docker`, setup-token, woocommerce-stubs PHPStan-only, IsoDate), do-not-do scars, the architecture pattern. Read first. |
| `STATUS.md` (repo root) | Any agent/dev, the coordinator | **Active** | Single source of "where we are now": done/in-progress, lock conditions, pilot go-live checklist, roadmap. Kept current in the same commit that changes reality. |
| `BACKLOG.md` (repo root) | Any agent/dev, pilot go/no-go | **Active** | Consolidated deferred-work index — every deferred item with priority (🔴 pilot-need / 🟡 post-pilot / 🟢 nice-to-have / 🔵 manual verification), why-deferred, and doc location. STATUS = "where we are now"; BACKLOG = "what's deferred"; DECISIONS = the rationale (linked, not duplicated). |
| `ARCHITECTURE.md` | Future developers, possible upstream reviewers | **TODO** (Phase 3 end) | High-level: layers (UI/REST/Services/Integrations/Storage), how the plugin talks to Smaily, how it talks to the rec engine, where the boundaries are. Diagrams. Key design decisions summarized (full reasoning in `DECISIONS.md`). |
| `docs/DECISIONS.md` | Future developers | **Finalized** (2026-06-11, was `DECISIONS_DRAFT.md`) | All significant technical decisions with reasoning — a single-file, F-numbered decision log, kept current in the same commit a decision changes. The ADR-per-file split was considered and rejected: the F-numbered log IS the working format. |
| `DEVELOPER.md` / `CONTRIBUTING.md` | Future contributors | **TODO** (Phase 3 end) | Dev environment setup (wp-env + Chromium + mock server + composer + npm), `npm run` scripts, testing conventions, how to make a sub-PR. |
| `API.md` | Anyone extending the plugin | **TODO** (Phase 3 end) | All plugin REST endpoints (those the wizard and Settings call) — request/response shapes. Internal API, but anyone extending the plugin needs to know it. Largely automatable from the `EndpointRegistry`. |
| `LESSONS.md` | Future developers, Erkki for next projects | **Exists** | General lessons from building with an AI agent. Carries forward to the next project (Shopify app, etc.). Updated as new lessons emerge. |
| `WP7_COMPAT.md` | Future developers, strategy reviewers | **Exists** | WordPress 7 compatibility plan and strategic opportunities (Connectors / Abilities / MCP). Updated as WP 7 ecosystem matures. |
| `RECENGINE_API_CONTRACT.md` | Plugin developers + engine team | **Exists** | Authoritative API contract between plugin and rec engine. Single source of truth. Both teams reference this. |
| `PLUGIN.md` | Plugin developers | **Exists** | Original plugin specification. Some sections superseded by `DECISIONS.md` and `ARCHITECTURE.md` after Phase 3. |
| `PLUGIN_IMPLEMENTATION_WP.md` | Plugin developers | **Exists, partially outdated** | WordPress-specific implementation guide. Updated at Phase 3 end (variant A custom queue replaces old AS-native sketch — see `DECISIONS.md` F3-7). |
| `STYLE_MAPPING.md` | UI developers | **Exists** | Tailwind tokens, color palette, the layered-input pattern, primitive components. |
| `FIELD_MAPPING.md` | Plugin developers, integration consumers | **Exists** | Canonical field-naming standard (Smaily WC plugin convention is canonical). Required reading for anyone touching subscriber sync. |
| `UPSTREAM_COMPARISON.md` | Erkki, strategy/merge reviewers | **Written** (2026-06-12) | Point-in-time comparison of upstream 2.0.0 (a 1.6-line min-versions bump on w.org) vs fork 2.1.0-beta.1: features, stacks, version support, code quality, known problems, the version-collision story. Snapshot, not a living register — `UPSTREAM_AUDIT.md` stays the commit-level source of truth. |
| `ENGINE_TEAM_PILOT_SYNC.md` | Engine team + Erkki | **Written** (2026-06-12) | Pilot go-live joint-sync brief: what the engine will see from the pilot tenant after beta.2 (synthetic `wc-{id}` keys, catalog backfill jump, orders retry flood, browse sku), the joint verification checklist, contract byte-sync action. Paste into the engine conversation; one-shot doc for the go-live window. |
| `SPEC_DRAFT_BROWSE_ABANDONED_CART.md` | Engine team + Erkki | **Draft** (2026-06-12, post-pilot 🟡) | Feature spec draft: engine-side abandoned-cart from browse signals for guest-only stores (identity via Smaily-click/checkout merge; order-based suppression; consent gate; F3-37 age-window requirement). BACKLOG 🟡 entry links here. |
| `TRIGGER_ROADMAP_DRAFT.md` | Erkki, product/strategy | **Draft** (2026-06-12, post-pilot 🟡) | Idea register: Family A = engine-free Woo→Smaily triggers (post-purchase, review ask, cross/upsell, contact-field enrichment, cancellation recovery); Family B = engine-side sweeps (replenishment, win-back, back-in-stock…). Two-tier engine-upsell frame; 2 concrete plugin items (variation-stock hook gap, P10 no-email guard). |

### Internal — Erkki's working drafts

| Document | Audience | Status | Description |
|----------|----------|--------|-------------|
| ~~`DECISIONS_DRAFT.md`~~ | — | **Finalized** as `docs/DECISIONS.md` (2026-06-11) | Was the Phase-3 working draft; now the canonical decision log (see the developer-facing table above). |
| `HANDOFF_PROMPT.md` (`spec/`) | — | **Superseded** | Old re-briefing template (pre-Phase-3). Replaced by `/CLAUDE.md` + `/STATUS.md`; kept with a banner for history. |
| `PROJECT_PLAN.md` | Erkki | **Exists** | Original project plan with phases. Some sections superseded by reality (Phase 2 took longer than planned). |
| `ROADMAP.md` | Erkki, strategic | **Exists** | Long-term roadmap: Milestone 1 (WC plugin), Milestone 2 (npm @smaily/recengine-client), Milestone 3 (Shopify app), Milestone 4 (Magento, TBD). |
| `SUGGESTION.md` | Erkki | **Exists** | Early-phase product suggestions, mostly historical. |

---

## Engine documentation (`engine-repo/docs/`)

These live in the **engine repo**, owned by the engine team. The plugin team
references them; the engine team writes them. Listed here for completeness so
anyone working across both sides knows what exists.

| Document | Audience | Description |
|----------|----------|-------------|
| `RECENGINE_API_CONTRACT.md` (copy/mirror of plugin's) | Both teams | The canonical API contract. Single source of truth. Should match the plugin-side copy byte-for-byte. |
| `PILOT_MONITORING.md` | Erkki, engine team | SQL queries, admin UI checkpoints, Vercel logs filters for monitoring the pilot's first live data. Used during pilot rollout. |
| Engine architecture / internals | Engine team | Owned by the engine team; plugin team doesn't need direct access. |

---

## Documentation that doesn't exist yet but probably should

These come up in conversation but haven't been written. Listed here so they don't
get forgotten.

| Document | When to write | Notes |
|----------|--------------|-------|
| `SECURITY_AUDIT.md` | Phase 3 end | A formal security audit walkthrough: `$wpdb->prepare` coverage, `esc_*` coverage, capability checks, dependency audit. Claude prepares the template, Code runs the audit. |
| `CODE_QUALITY_AUDIT.md` | Phase 3 end | Checklist for code quality (style, architecture, tests, docs). Code runs through it, generates a report. Used to validate upstream-merge readiness. |
| `UPSTREAM_MERGE_PROPOSAL.md` | When proposing the fork back to Smaily | A summary document for the Smaily team explaining what was done, why, with links to ARCHITECTURE / DECISIONS / SECURITY_AUDIT / CODE_QUALITY_AUDIT. |

---

## Maintenance

When adding a new document to either repo, add a row to this index. When a
document changes status (e.g. `TODO` → `Exists`), update its row.

This index is itself a document — keep it current. Stale indexes erode trust faster
than missing ones.

**Last reviewed:** pilot-hardening P4 (INSTALL.md written)
