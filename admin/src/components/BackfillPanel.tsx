import type React from 'react';
import { type BackfillJobType } from '../api/backfill';
import { useBackfillProgress } from '../hooks/useBackfillProgress';
import { Button, ProgressBar } from './primitives';

interface BackfillPanelProps {
  /** Rec-engine domain to backfill: 'products' | 'customers' | 'orders'. */
  jobType: BackfillJobType;
  /** Human label, e.g. "Products". */
  label: string;
  /** How many records exist (the button is disabled at 0). */
  recordCount: number;
  /** Poll interval; Settings uses 30s, the wizard 5s. */
  intervalMs?: number;
}

/**
 * One rec-engine backfill control: an "Import now" button + a progress bar for
 * a single domain. The API + useBackfillProgress hook are already job-type
 * parameterised (3.5.0-.2 wired the products/customers/orders job types), so
 * each panel just instantiates the hook with its jobType.
 *
 * Unlike the contacts backfill (Step2Subscribers), this holds progress purely
 * in the hook and does NOT mirror it into the reducer — only the contacts
 * backfill feeds the Step 6 summary, so rec-engine domains need no reducer slot.
 */
export function BackfillPanel({
  jobType,
  label,
  recordCount,
  intervalMs,
}: BackfillPanelProps): React.JSX.Element {
  const { progress, pollError, start, cancel } = useBackfillProgress({
    jobType,
    intervalMs: intervalMs ?? 30_000,
  });

  const isRunning = progress.status === 'running';
  const isComplete = progress.status === 'completed';
  const hasFailed = progress.status === 'failed';
  const wasCancelled = progress.status === 'cancelled';
  const showProgress = isRunning || isComplete || hasFailed || wasCancelled;
  const noun = label.toLowerCase();

  return (
    <div className="rounded-lg border border-border-subtle p-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-sm font-medium text-text-primary">{label}</p>
          <p className="mt-0.5 text-xs text-text-tertiary">
            {recordCount > 0
              ? `${recordCount} ${noun} to import into the engine.`
              : `No ${noun} to import.`}
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => void start()}
            disabled={isRunning || recordCount === 0}
            loading={isRunning && progress.processed === 0}
            type="button"
          >
            {isRunning ? 'Importing…' : 'Import now'}
          </Button>
          {isRunning && (
            <Button variant="ghost" size="sm" onClick={() => void cancel()} type="button">
              Cancel
            </Button>
          )}
        </div>
      </div>

      {showProgress && (
        <div className="mt-3">
          <ProgressBar
            percent={progress.percent}
            tone={hasFailed ? 'danger' : isComplete ? 'success' : 'brand'}
            ariaLabel={`${label} backfill progress`}
          />
          <p className="mt-2 text-xs text-text-secondary">
            {/* Engine-confirmed sent (not just walked) so the count is honest
                when rows fail; the per-row failures are listed in the Event Log. */}
            {isRunning && `Synced ${progress.sent} of ${progress.total}.`}
            {isComplete && `Done — ${progress.sent} of ${progress.total} ${noun} synced.`}
            {hasFailed && `Backfill failed${progress.error ? `: ${progress.error}` : '.'}`}
            {wasCancelled && 'Backfill cancelled. Re-run when ready.'}
          </p>
          {progress.failed > 0 && (isRunning || isComplete) && (
            <p className="mt-1 text-xs text-danger-fg">
              {progress.failed} {progress.failed === 1 ? 'record' : 'records'} failed to
              sync — see the Event Log for details.
            </p>
          )}
          {(isComplete || wasCancelled || hasFailed) && progress.completedAt && (
            <p className="mt-1 text-xs text-text-tertiary">
              Last run finished {formatLastRun(progress.completedAt)}.
            </p>
          )}
        </div>
      )}

      {pollError && (
        <p className="mt-3 text-xs text-danger">Couldn&apos;t check status: {pollError}</p>
      )}
    </div>
  );
}

/**
 * Render a server-emitted UTC datetime ("2026-05-21 10:42:17") in the
 * merchant's local timezone. Mirrors Step2Subscribers' helper.
 */
function formatLastRun(utcDatetime: string): string {
  const iso = utcDatetime.replace(' ', 'T') + 'Z';
  const date = new Date(iso);
  if (isNaN(date.getTime())) {
    return utcDatetime;
  }
  return date.toLocaleString();
}
