import { useEffect, type Dispatch } from 'react';

import { __, sprintf } from '@admin/lib/i18n';
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
          <p className="text-sm font-medium uppercase tracking-wide text-text-tertiary">{ __( 'Step 2 of 6', 'smaily-connect' ) }</p>
          <h2 className="mt-1 text-2xl font-semibold text-text-primary">{ __( 'Sync your subscribers', 'smaily-connect' ) }</h2>
          <p className="mt-2 text-sm text-text-secondary">
            { __(
              'Choose which fields to copy from WordPress to Smaily, and decide where to surface the subscription opt-in checkbox.',
              'smaily-connect',
            ) }
          </p>
        </div>
      )}

      <Card title={ __( 'Contact synchronisation', 'smaily-connect' ) }>
        <Toggle
          name="smly-subscriber-sync-enabled"
          checked={state.subscriberSyncEnabled}
          onChange={(e) =>
            dispatch({ type: 'SET_SUBSCRIBER_SYNC_ENABLED', payload: e.target.checked })
          }
          label={ __( 'Sync contacts to Smaily', 'smaily-connect' ) }
          description={ __(
            'When on, new WP users + WooCommerce customers are pushed to your Smaily contact list.',
            'smaily-connect',
          ) }
        />

        {state.subscriberSyncEnabled && (
          <div className="mt-5">
            <p className="text-sm font-medium text-text-primary">{ __( 'Fields to sync', 'smaily-connect' ) }</p>
            <p className="mt-1 text-xs text-text-tertiary">
              { __( 'Email is always synced. Pick the additional fields you want copied across.', 'smaily-connect' ) }
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

      <Card title={ __( 'Subscription checkboxes', 'smaily-connect' ) }>
        <div className="space-y-4">
          <Toggle
            name="smly-wp-subscription"
            checked={state.wordpressSubscriptionCheckbox}
            onChange={(e) =>
              dispatch({ type: 'SET_WORDPRESS_SUBSCRIPTION_CHECKBOX', payload: e.target.checked })
            }
            label={ __( 'Show subscription checkbox during WordPress registration', 'smaily-connect' ) }
          />
          <Toggle
            name="smly-checkout-subscription"
            checked={state.checkoutSubscriptionCheckbox}
            onChange={(e) =>
              dispatch({ type: 'SET_CHECKOUT_SUBSCRIPTION_CHECKBOX', payload: e.target.checked })
            }
            label={ __( 'Show subscription checkbox during WooCommerce checkout', 'smaily-connect' ) }
          />
        </div>
      </Card>

      <Card title={ __( 'Initial backfill', 'smaily-connect' ) } description={ __( 'Import the existing WordPress users into Smaily.', 'smaily-connect' ) }>
        <p className="text-sm text-text-secondary">
          {state.env.storeTotals.customers > 0
            ? sprintf(
                // translators: %d is the number of WordPress users.
                __( '%d users will be processed in batches of 100, ~30 seconds apart.', 'smaily-connect' ),
                state.env.storeTotals.customers,
              )
            : __( 'No existing WordPress users detected — the backfill will be a no-op.', 'smaily-connect' )}
        </p>

        <div className="mt-4 flex items-center gap-3">
          <Button
            variant="primary"
            onClick={handleStart}
            disabled={isRunning || state.env.storeTotals.customers === 0}
            loading={isRunning && progress.processed === 0}
            type="button"
          >
            {isRunning ? __( 'Running…', 'smaily-connect' ) : __( 'Start backfill', 'smaily-connect' )}
          </Button>

          {isRunning && (
            <Button variant="ghost" onClick={() => void cancel()} type="button">
              { __( 'Cancel', 'smaily-connect' ) }
            </Button>
          )}
        </div>

        {(isRunning || isComplete || hasFailed || wasCancelled) && (
          <div className="mt-5">
            <ProgressBar
              percent={progress.percent}
              tone={hasFailed ? 'danger' : isComplete ? 'success' : 'brand'}
              ariaLabel={ __( 'Subscriber backfill progress', 'smaily-connect' ) }
            />
            <p className="mt-2 text-xs text-text-secondary">
              {isRunning && sprintf(
                // translators: %1$d is processed count, %2$d is total count.
                __( 'Synced %1$d of %2$d.', 'smaily-connect' ),
                progress.processed,
                progress.total,
              )}
              {isComplete && sprintf(
                // translators: %1$d is processed count, %2$d is total count.
                __( 'Done — %1$d of %2$d contacts synced.', 'smaily-connect' ),
                progress.processed,
                progress.total,
              )}
              {hasFailed && (progress.error
                ? sprintf(
                    // translators: %s is the error message.
                    __( 'Backfill failed: %s', 'smaily-connect' ),
                    progress.error,
                  )
                : __( 'Backfill failed.', 'smaily-connect' ))}
              {wasCancelled && __( 'Backfill cancelled. Re-run when ready.', 'smaily-connect' )}
            </p>
            {(isComplete || wasCancelled || hasFailed) && progress.completedAt && (
              <p className="mt-1 text-xs text-text-tertiary">
                {sprintf(
                  // translators: %s is a localized date/time.
                  __( 'Last run finished %s.', 'smaily-connect' ),
                  formatLastRun(progress.completedAt),
                )}
              </p>
            )}
            {isRunning && (
              <p className="mt-2 text-xs text-text-tertiary">
                { __(
                  'Backfill runs in the background on your server. You can safely leave this page or close the browser — the job will continue. Return here any time to check progress.',
                  'smaily-connect',
                ) }
              </p>
            )}
          </div>
        )}

        {pollError && (
          <p className="mt-3 text-xs text-danger">{sprintf(
            // translators: %s is the error message.
            __( "Couldn't check status: %s", 'smaily-connect' ),
            pollError,
          )}</p>
        )}
      </Card>
    </div>
  );
}

const FIELD_LABELS: Record<string, string> = {
  first_name: __( 'First name', 'smaily-connect' ),
  last_name: __( 'Last name', 'smaily-connect' ),
  phone: __( 'Phone', 'smaily-connect' ),
  birthday: __( 'Birthday', 'smaily-connect' ),
  gender: __( 'Gender', 'smaily-connect' ),
  customer_group: __( 'Customer group', 'smaily-connect' ),
  customer_id: __( 'Customer ID', 'smaily-connect' ),
  first_registered: __( 'First registered date', 'smaily-connect' ),
  nickname: __( 'Nickname', 'smaily-connect' ),
  site_title: __( 'Site title', 'smaily-connect' ),
};

function prettyFieldLabel(field: string): string {
  return FIELD_LABELS[field] ?? field.replace(/_/g, ' ');
}

/**
 * Render a server-emitted UTC datetime ("2026-05-21 10:42:17") in the
 * merchant's local timezone. Used by the "Last run finished …" hint
 * under the backfill progress bar. We deliberately avoid Intl options
 * the older Node ICU builds in CI choke on — toLocaleString with no
 * arguments is the broadest path.
 */
function formatLastRun(utcDatetime: string): string {
  const iso = utcDatetime.replace(' ', 'T') + 'Z';
  const date = new Date(iso);
  if (isNaN(date.getTime())) {
    return utcDatetime;
  }
  return date.toLocaleString();
}
