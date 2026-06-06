# CLAUDE.md — Agent Working Guide (Smaily Connect plugin)

If you are a fresh agent picking up this repo: read this first, then `STATUS.md`
(where we are now), then `docs/RECENGINE_API_CONTRACT.md` (the contract you
build against), `docs/DECISIONS_DRAFT.md` (why things are the way they are), and
`docs/LESSONS.md` (mistakes already made — don't repeat them). README gives the
30-second project orientation.

This file is the operational knowledge that otherwise lives only in agent memory
and commit messages. It exists so you don't re-discover it the painful way.

---

## Keeping the docs current — part of every change

The handoff docs are only worth reading if they're true. Keeping them current is
not a separate chore; it's part of the work that changed them.

- **STATUS.md** — update in the SAME commit that finishes a sub-PR, syncs the
  contract, or changes a lock condition / roadmap. (Its own header states the
  rule.) Stale status = a defect, treat it like a failing test.
- **CLAUDE.md** (this file) — when you learn a new operational fact the hard way
  (a new `sg`-style gotcha, a build-command change, a new scar), add it here in
  the same commit. The whole point is that the next agent doesn't re-discover it.
- **DECISIONS_DRAFT.md** — when a decision is made, changed, or superseded,
  record it (with why, not just what). A reversed decision gets updated, not
  silently dropped.
- **LESSONS.md** — when a class of mistake is caught (especially mock-vs-live,
  context-audit, or seam bugs), add the lesson so it generalizes.
- **README roadmap / INDEX.md** — if you change what's done or which files
  exist, refresh these too. They went stale once (README said Customers/Orders
  were pending after they shipped); don't let it recur.

**The rule across all of them:** if your change makes a doc wrong, your change
isn't finished until the doc is fixed in the same commit. If you notice a doc is
already stale, fixing it is in-scope now, not a future task.

---

## Operational knowledge (the painful-to-rediscover bits)

### Integration tests need `sg docker`
The agent sandbox strips the `docker` supplementary group from the running
process, so a bare `docker info` looks like Docker is unavailable — it isn't.
Docker is installed and the daemon runs; you (the user `erkki`) are in the
`docker` group. Restore it per-command with `sg`:

```
sg docker -c "composer run test:integration"
```

Integration tests run real WP + WooCommerce + MariaDB via wp-env. Do NOT
conclude "Docker unavailable" from a bare `docker info` failure — use `sg`.

### Live-walk needs a fresh setup-token
Live-walks (against the real MiuMjau engine) need a connected tenant. The
setup-token is **one-time** (consumed on exchange) and connections get scrubbed
by integration test runs. When a live-walk reports `is_connected = 0`:
- Ask the user to mint a fresh token into `/tmp/smaily_re_setup_token`
  (plain token, secret-safe file method).
- Exchange it via the plugin's real SetupExchange + store() path (F3-12).
- Never echo the token; delete temp files after.

### woocommerce-stubs are PHPStan-only
`woocommerce-stubs` is in the PHPStan config, NOT the runtime autoload. In unit
tests, WC objects are built with PHPUnit `createMock` + shared shims (e.g. the
`WC_Order` shim in HookHandlerTest, `WC_Order_Item_Product`). Reuse this pattern
for any new WC-dependent unit test.

### Use the IsoDate helper for datetimes — never raw format
The engine's strict Zod `.datetime()` requires Z-suffix (`Y-m-d\TH:i:s\Z`), NOT
`+00:00`. Raw `gmdate('c')` / `$date->format('c')` produces `+00:00` and the
engine rejects it. This bug shipped twice (customer `first_seen_at`, catalog
`on_sale_until`) before being caught by a live-walk. The fix is the shared
`IsoDate` helper (F3-21) — every builder uses it so the bug can't recur. Any new
datetime field goes through IsoDate.

### Build / test / walk commands
- `npm run ci:strict` — PHPCS + PHPStan + PHPUnit unit + JS (eslint/tsc/vitest).
  Must be `exit=0`.
- `sg docker -c "composer run test:integration"` — real-environment integration.
- Live-walk scripts live in `bin/` (e.g. `bin/walk-3.3.cjs`). Run against the
  connected engine; needs a setup-token (above).
- `composer run package` — produces the distributable ZIP.

(Verify exact paths/scripts against the repo — this list is the working set as
of orders ingest; update if the build evolves.)

---

## How we work (the rhythm)

### Sub-PR rhythm with human checkpoints
Work proceeds in small sub-PRs (e.g. 3.3.0, 3.3.1...). Each one:
1. Plan first — state scope, files, surface edge cases, **report the plan and
   wait for go-ahead before coding.**
2. Code + tests.
3. Gates green: `ci:strict` + integration (`sg docker`) + relevant regression
   (e.g. catalog/customers regression must stay green when shared code changes).
4. Report at the end (results, gate output, anything surfaced) **before** the
   next sub-PR. Push directly to main per project rhythm.

Do NOT batch multiple sub-PRs without a checkpoint. The human participates at
edge cases and strategic decisions, not every line — but the checkpoint between
steps is the safety rail.

### Context audit before building (LESSONS §2.5)
Before starting real code on a new area, do a context audit: `git log`, read the
relevant DECISIONS entries + the fresh contract section + the template code you
are mirroring. This is how the queue silent-loss (3.3.0), the topple-enqueue
(3.3.3), and the guest-flusher question were caught **before** coding rather
than in a live-walk.

### Spec sync is byte-identical (CC-8)
When the engine team changes the contract, sync `docs/RECENGINE_API_CONTRACT.md`
byte-for-byte with the engine repo: replace the file, `git diff` + md5 confirm
identical, commit with a message naming the engine commit + what changed, push.
The embedded header note records the sync. This discipline has caught real
bugs (products->items drift, datetime Z-form, the seam bugs).

**A sync is NOT code-complete.** Syncing the doc byte-for-byte does NOT mean the
plugin code follows. The W2 catalog wrapper rename (`items`->`products`) synced
into the doc but the code kept sending `items`; the mock (still on `items`) hid
it for five syncs until the first catalog live-walk after W2 (N-7.1) caught the
`400`. So after every sync that changes a **wire shape** (wrapper key, a required
field, an enum, a removed field): (1) check the real plugin code follows in the
same pass; (2) move the **mock to the new shape in the same sync** (else it masks
the drift); (3) run that endpoint's live-walk (or one curl) before calling it
done. Don't let a breaking change wait for an unrelated sub-PR to surface it.
(LESSONS §2.7.)

---

## Things NOT to do (each is a scar)

- **Don't assume a wire shape — live-probe it.** Catalog was coded to `products`;
  the live engine wanted `items`; the mock (built to the same wrong assumption)
  hid it until the 3.2.4 live-walk. For customers we probed the wrapper key
  before locking it (3.3.1) and avoided the repeat. If a contract detail could
  diverge, send one live request before committing to it.
- **Don't repeat the datetime bug** — use IsoDate (above).
- **Don't invent real-world facts** — URLs, prices, versions, support links.
  A fabricated `smaily.com/support` URL shipped once (correct is
  `https://smaily.com/help/`). If you don't know, check or ask; don't construct
  a plausible-looking value.
- **Don't trust mocks for format validation.** Mocks validate loosely; the
  engine's Zod is strict. Every formatted field (wrapper key, datetime, enum) is
  a mock-vs-live divergence risk. Live-walk must cover each formatted field, not
  just happy-path structure. (LESSONS §2.3, §2.4.)
- **Catalog-flusher D6 consolidation (lock RESOLVED in N-7.1).** This was a hard
  lock condition: while the catalog flusher was all-or-nothing it would mark
  engine-rejected products SENT (silent loss). N-7.1 moved it onto the shared
  `AbstractD6Flusher`; the catalog live-walk proves the split against the real
  engine (`flusher_d6_split_lock_proof`: a no-SKU product comes back as a D6
  per-item error and is marked FAILED, the valid one SENT). Keep it on the D6
  base — do not reintroduce an all-or-nothing 2xx success path.
- **Don't trust a sync as code-complete** — see the CC-8 note above. A wire-shape
  change in a sync (wrapper key, required field, enum, removed field) must be
  verified against the plugin code AND the mock in the same pass, then live-walked.
  The W2 `items`->`products` drift hid for five syncs because none of these
  happened. (LESSONS §2.7.)

---

## Coexistence map (legacy vs new)

- Legacy namespace `Smaily_Connect\*` + new `Smaily\Connect\*` coexist.
- Two independent feature flags / gates:
  - `setup_completed` (email wizard) — switches Smaily contact-sync legacy ->
    new. Coordinates the legacy<->new Smaily-sync path.
  - `is_connected()` (rec-engine, Step 4, optional) — gates rec-engine ingest.
    Independent of the email wizard. Rec-engine ingest fires iff the engine is
    connected, regardless of wizard state.
- These target **different destinations** (Smaily contact-API vs rec-engine), so
  there's no double-sync conflict. The CustomerHookHandler (rec-engine) uses
  `is_connected()`; it enqueues all registered users (A-filter, F3-20), matching
  the email-sync handler's breadth (neither filters by role).

---

## Architecture pattern (every ingest domain follows this)

PayloadBuilder (WC object -> wire shape) -> IngestQueue (generic, carries
event_type + entity_id + payload + event_uuid) -> Flusher (batch flush, D6
errors[] parse, Action Scheduler job) -> HookHandler (WC hooks -> enqueue).
Catalog established it (F3-16); customers mirrored it (F3-19); orders mirrors it
again. The queue is shared and event_type-scoped (each flusher drains only its
own event types — this prevents one flusher consuming another's rows).

D6 contract (F3-18): batch ingest returns `200 {ok, processed, deduplicated,
errors:[{index, <natural_key>?, field, message}]}`. Invariant: `processed +
deduplicated + errors.length == total`. The flusher maps `errors[].index ->
batch_rows[index]` (index-aligned parallel arrays), marks errored rows failed,
the rest sent. CustomerFlusher is the reference implementation.
