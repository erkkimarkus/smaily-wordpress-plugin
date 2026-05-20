import { useEffect, type Dispatch } from 'react';

import { useBackfillProgress } from '../../hooks/useBackfillProgress';
import { DEFAULT_SYNC_FIELDS, type WizardAction, type WizardState } from '../../state/types';
import { Button, Card, Checkbox, ProgressBar, Toggle } from '../primitives';

export interface Step2SubscribersProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  inSettings?: boolean;
  /**
   * Override the backfill polling cadence — Settings passes 30_000,
   * wizard sticks to the default 5_000.
   */
  backfillIntervalMs?: number;
}

/**
 * Step 2 — Subscribers.
 *
 *   1. Sync-enabled toggle (PLUGIN.md §5.2 — defaults ON)
 *   2. Field selection grid (10 fields × 2 columns)
 *   3. WordPress / WooCommerce checkbox toggles
 *   4. Backfill panel:
 *        - "Start backfill" button
 *        - ProgressBar with live polling via useBackfillProgress
 *        - Status text (running / completed / failed / cancelled)
 */
export function Step2Subscribers({
  state,
  dispatch,
  inSettings = false,
  backfillIntervalMs,
}: Step2SubscribersProps): React.JSX.Element {
  const { progress, pollError, start, cancel } = useBackfillProgress({
    jobType: 'contacts',
    intervalMs: backfillIntervalMs ?? (inSettings ? 30_000 : 5_000),
  });

  // Mirror hook progress into the reducer so other components (the
  // Settings widget, Step 6 summary) see the same data.
  useEffect(() => {
    dispatch({
      type: 'BACKFILL_PROGRESS',
      payload: { jobType: 'contacts', progress },
    });
  }, [progress, dispatch]);

  const handleStart = async (): Promise<void> => {
    dispatch({ type: 'BACKFILL_START', payload: { jobType: 'contacts' } });
    await start();
  };

  const isRunning = progress.status === 'running';
  const isComplete = progress.status === 'completed';
  const hasFailed = progress.status === 'failed';
  const wasCancelled = progress.status === 'cancelled';

  return (
    <div className="space-y-6">
      {!inSettings && (
        <div>
          <p className="text-sm font-medium uppercase tracking-wide text-text-tertiary">Step 2 of 6</p>
          <h2 className="mt-1 text-2xl font-semibold text-text-primary">Sync your subscribers</h2>
          <p className="mt-2 text-sm text-text-secondary">
            Choose which fields to copy from WordPress to Smaily, and decide where to surface the
            subscription opt-in checkbox.
          </p>
        </div>
      )}

      <Card title="Contact synchronisation">
        <Toggle
          name="smly-subscriber-sync-enabled"
          checked={state.subscriberSyncEnabled}
          onChange={(e) =>
            dispatch({ type: 'SET_SUBSCRIBER_SYNC_ENABLED', payload: e.target.checked })
          }
          label="Sync contacts to Smaily"
          description="When on, new WP users + WooCommerce customers are pushed to your Smaily contact list."
        />

        {state.subscriberSyncEnabled && (
          <div className="mt-5">
            <p className="text-sm font-medium text-text-primary">Fields to sync</p>
            <p className="mt-1 text-xs text-text-tertiary">
              Email is always synced. Pick the additional fields you want copied across.
            </p>
            <div className="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2">
              {DEFAULT_SYNC_FIELDS.map((field) => (
                <Checkbox
                  key={field}
                  name={`smly-sync-${field}`}
                  checked={state.syncFields.includes(field)}
                  onChange={() => dispatch({ type: 'TOGGLE_SYNC_FIELD', payload: { field } })}
                  label={prettyFieldLabel(field)}
                />
              ))}
            </div>
          </div>
        )}
      </Card>

      <Card title="Subscription checkboxes">
        <div className="space-y-4">
          <Toggle
            name="smly-wp-subscription"
            checked={state.wordpressSubscriptionCheckbox}
            onChange={(e) =>
              dispatch({ type: 'SET_WORDPRESS_SUBSCRIPTION_CHECKBOX', payload: e.target.checked })
            }
            label="Show subscription checkbox during WordPress registration"
          />
          <Toggle
            name="smly-checkout-subscription"
            checked={state.checkoutSubscriptionCheckbox}
            onChange={(e) =>
              dispatch({ type: 'SET_CHECKOUT_SUBSCRIPTION_CHECKBOX', payload: e.target.checked })
            }
            label="Show subscription checkbox during WooCommerce checkout"
          />
        </div>
      </Card>

      <Card title="Initial backfill" description="Import the existing WordPress users into Smaily.">
        <p className="text-sm text-text-secondary">
          {state.env.storeTotals.customers > 0
            ? `${state.env.storeTotals.customers} users will be processed in batches of 100, ~30 seconds apart.`
            : 'No existing WordPress users detected — the backfill will be a no-op.'}
        </p>

        <div className="mt-4 flex items-center gap-3">
          <Button
            variant="primary"
            onClick={handleStart}
            disabled={isRunning || state.env.storeTotals.customers === 0}
            loading={isRunning && progress.processed === 0}
            type="button"
          >
            {isRunning ? 'Running…' : 'Start backfill'}
          </Button>

          {isRunning && (
            <Button variant="ghost" onClick={() => void cancel()} type="button">
              Cancel
            </Button>
          )}
        </div>

        {(isRunning || isComplete || hasFailed || wasCancelled) && (
          <div className="mt-5">
            <ProgressBar
              percent={progress.percent}
              tone={hasFailed ? 'danger' : isComplete ? 'success' : 'brand'}
              ariaLabel="Subscriber backfill progress"
            />
            <p className="mt-2 text-xs text-text-secondary">
              {isRunning && `Synced ${progress.processed} of ${progress.total}.`}
              {isComplete && `Done — ${progress.processed} contacts synced.`}
              {hasFailed && `Backfill failed${progress.error ? `: ${progress.error}` : '.'}`}
              {wasCancelled && 'Backfill cancelled. Re-run when ready.'}
            </p>
            {isRunning && (
              <p className="mt-2 text-xs text-text-tertiary">
                Backfill runs in the background on your server. You can safely
                leave this page or close the browser — the job will continue.
                Return here any time to check progress.
              </p>
            )}
          </div>
        )}

        {pollError && (
          <p className="mt-3 text-xs text-danger">Couldn&apos;t check status: {pollError}</p>
        )}
      </Card>
    </div>
  );
}

const FIELD_LABELS: Record<string, string> = {
  first_name: 'First name',
  last_name: 'Last name',
  phone: 'Phone',
  birthday: 'Birthday',
  gender: 'Gender',
  customer_group: 'Customer group',
  customer_id: 'Customer ID',
  first_registered: 'First registered date',
  nickname: 'Nickname',
  site_title: 'Site title',
};

function prettyFieldLabel(field: string): string {
  return FIELD_LABELS[field] ?? field.replace(/_/g, ' ');
}
