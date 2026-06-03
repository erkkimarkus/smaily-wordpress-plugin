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
| `README.md` | Anyone evaluating the plugin | **TODO** (Phase 3 end) | First impression. What the plugin does, who it's for, install summary, links to deeper docs. Lives at repo root, also surfaces in marketplace listings. |
| `INSTALL.md` | New WP-admin installing fresh | **TODO** (Phase 3 end) | Step-by-step install on a clean WordPress. Activation, wizard walkthrough, first sync verification. Screenshots. |
| `MIGRATION.md` | WP-admin running the legacy `sendsmaily/smaily-wordpress-plugin` | **TODO** (before pilot) | How to migrate from the legacy plugin to this one. What persists, what changes, what to back up first, rollback path. Critical: written **before pilot client install**. |
| `FAQ.md` / `TROUBLESHOOTING.md` | WP-admin running into problems | **TODO** (after pilot, ~2-4 weeks in) | Real user questions and symptom→cause→fix patterns. Written **after** the pilot is in production and asks real questions. Drafting it earlier would just be guesses. |
| `CHANGELOG.md` | Anyone tracking versions | **TODO** (Phase 3 end) | Version-by-version what changed. Required for WordPress.org marketplace if we publish there. Can be regenerated from git history. |
| `TESTING.md` | Pilot client verifying the install works | **TODO** (before pilot) | Acceptance test plan for the pilot. Step-by-step "install → activate → wizard → verify Smaily connection → verify contact sync → ...". Pass/fail criteria. |

### Developer-facing — lives in repo, may be public or repo-only

| Document | Audience | Status | Description |
|----------|----------|--------|-------------|
| `ARCHITECTURE.md` | Future developers, possible upstream reviewers | **TODO** (Phase 3 end) | High-level: layers (UI/REST/Services/Integrations/Storage), how the plugin talks to Smaily, how it talks to the rec engine, where the boundaries are. Diagrams. Key design decisions summarized (full reasoning in `DECISIONS.md`). |
| `DECISIONS.md` (from `DECISIONS_DRAFT.md`) | Future developers | **Draft exists** (`DECISIONS_DRAFT.md`), refined at Phase 3 end | All significant technical decisions with reasoning. Currently maintained as a draft, refined into a final document (or split into `docs/adr/NNNN-*.md` files) at Phase 3 end. |
| `DEVELOPER.md` / `CONTRIBUTING.md` | Future contributors | **TODO** (Phase 3 end) | Dev environment setup (wp-env + Chromium + mock server + composer + npm), `npm run` scripts, testing conventions, how to make a sub-PR. |
| `API.md` | Anyone extending the plugin | **TODO** (Phase 3 end) | All plugin REST endpoints (those the wizard and Settings call) — request/response shapes. Internal API, but anyone extending the plugin needs to know it. Largely automatable from the `EndpointRegistry`. |
| `LESSONS.md` | Future developers, Erkki for next projects | **Exists** | General lessons from building with an AI agent. Carries forward to the next project (Shopify app, etc.). Updated as new lessons emerge. |
| `WP7_COMPAT.md` | Future developers, strategy reviewers | **Exists** | WordPress 7 compatibility plan and strategic opportunities (Connectors / Abilities / MCP). Updated as WP 7 ecosystem matures. |
| `RECENGINE_API_CONTRACT.md` | Plugin developers + engine team | **Exists** | Authoritative API contract between plugin and rec engine. Single source of truth. Both teams reference this. |
| `PLUGIN.md` | Plugin developers | **Exists** | Original plugin specification. Some sections superseded by `DECISIONS.md` and `ARCHITECTURE.md` after Phase 3. |
| `PLUGIN_IMPLEMENTATION_WP.md` | Plugin developers | **Exists, partially outdated** | WordPress-specific implementation guide. Updated at Phase 3 end (variant A custom queue replaces old AS-native sketch — see `DECISIONS.md` F3-7). |
| `STYLE_MAPPING.md` | UI developers | **Exists** | Tailwind tokens, color palette, the layered-input pattern, primitive components. |
| `FIELD_MAPPING.md` | Plugin developers, integration consumers | **Exists** | Canonical field-naming standard (Smaily WC plugin convention is canonical). Required reading for anyone touching subscriber sync. |

### Internal — Erkki's working drafts

| Document | Audience | Status | Description |
|----------|----------|--------|-------------|
| `DECISIONS_DRAFT.md` | Erkki, then refined into `DECISIONS.md` | **Active** | Working draft of technical decisions during Phase 3. Each new decision adds an entry. Refined at Phase 3 end. |
| `RECENGINE_TODO.md` | Engine team (handover) | **Active** | Engine-side requirements and status, sorted by P0/P1/P2 priority. Updated as engine team makes progress. |
| `HANDOFF_PROMPT.md` | Erkki, for new Claude sessions | **Exists** | Re-briefing template when starting a new Claude session after a long gap. |
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

**Last reviewed:** Phase 3 sub-PR 3.2.1 (commit context: 7cf1659)
