import { type WizardState } from '../../state/types';
import { Banner, Button, Card } from '../primitives';

export interface Step6DoneProps {
  state: WizardState;
  inSettings?: boolean;
  onOpenSmailyDashboard?: () => void;
  onOpenRecEngineDashboard?: () => void;
}

interface SummaryItem {
  label: string;
  active: boolean;
  detail?: string;
}

/**
 * Step 6 — Done. Renders a live state-reflection summary of everything
 * the user configured + outbound links to the Smaily + rec-engine
 * dashboards.
 *
 * Each summary row is derived from state, not stored. That keeps Step 6
 * always honest — if the user navigates Back and toggles something off,
 * the indicator updates automatically when they return.
 *
 * inSettings hides this entire view from the Settings tab tree.
 */
export function Step6Done({
  state,
  inSettings = false,
  onOpenSmailyDashboard,
  onOpenRecEngineDashboard,
}: Step6DoneProps): React.JSX.Element {
  const items: SummaryItem[] = computeSummary(state);

  return (
    <div className="space-y-6">
      {!inSettings && (
        <div>
          <p className="text-sm font-medium uppercase tracking-wide text-text-tertiary">
            Step 6 of 6
          </p>
          <h2 className="mt-1 text-2xl font-semibold text-text-primary">You&apos;re all set</h2>
          <p className="mt-2 text-sm text-text-secondary">
            Smaily Connect is configured and ready. Click Finish to save and exit the wizard, or
            review the summary below first.
          </p>
        </div>
      )}

      <Card title="What's active">
        <ul className="divide-y divide-border-subtle">
          {items.map((item) => (
            <li key={item.label} className="flex items-start gap-3 py-3">
              <span
                className={`mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${
                  item.active
                    ? 'bg-success text-text-white'
                    : 'border border-border-strong bg-surface text-text-tertiary'
                }`}
                aria-hidden
              >
                {item.active ? '✓' : '○'}
              </span>
              <span className="min-w-0 flex-1">
                <span
                  className={`block text-sm font-medium ${
                    item.active ? 'text-text-primary' : 'text-text-tertiary'
                  }`}
                >
                  {item.label}
                </span>
                {item.detail && <span className="block text-xs text-text-tertiary">{item.detail}</span>}
              </span>
            </li>
          ))}
        </ul>
      </Card>

      <Banner tone="info">
        All sync events appear in the <strong>Event Log</strong> tab (Settings → Event Log). Use it
        to diagnose any unexpected behaviour during the first days of operation.
      </Banner>

      <Card title="Open dashboards">
        <div className="flex flex-wrap items-center gap-3">
          <Button
            variant="secondary"
            type="button"
            onClick={() => openSmailyDashboard(state, onOpenSmailyDashboard)}
            disabled={state.smailyConnection.kind !== 'success' || !smailySubdomain(state)}
          >
            Open Smaily dashboard →
          </Button>
          {state.recEngineConnection.kind === 'success' && (
            <Button variant="secondary" type="button" onClick={onOpenRecEngineDashboard}>
              Open Recommendations dashboard →
            </Button>
          )}
        </div>
      </Card>
    </div>
  );
}

function computeSummary(state: WizardState): SummaryItem[] {
  const items: SummaryItem[] = [];

  // Connection -----------------------------------------------------------
  const accountCount = 1 + state.perLanguageAccounts.length;
  items.push({
    label: 'Smaily connected',
    active: state.smailyConnection.kind === 'success',
    detail:
      state.multilingualMode === 'A'
        ? `${accountCount} accounts configured (Mode A)`
        : `Multilingual mode: ${state.multilingualMode}`,
  });

  // Subscribers ----------------------------------------------------------
  items.push({
    label: 'Subscriber sync enabled',
    active: state.subscriberSyncEnabled,
    detail: state.subscriberSyncEnabled
      ? `${state.syncFields.length} fields synced`
      : undefined,
  });

  if (state.contactsBackfill.status === 'completed') {
    items.push({
      label: 'Initial backfill complete',
      active: true,
      detail: `${state.contactsBackfill.processed} contacts synced.`,
    });
  }

  // Step 3 — automations -------------------------------------------------
  items.push({
    label: 'Welcome email',
    active: state.welcomeEnabled,
    detail: state.welcomeEnabled
      ? `${countMappings(state, 'welcome')} workflow(s) mapped`
      : undefined,
  });
  items.push({
    label: 'First-order email',
    active: state.firstOrderEnabled,
    detail: state.firstOrderEnabled
      ? `${countMappings(state, 'first_order')} workflow(s) mapped`
      : undefined,
  });
  items.push({
    label: 'Abandoned-cart reminder',
    active: state.abandonedCartEnabled,
    detail: state.abandonedCartEnabled
      ? `${state.abandonedCartCutoffMinutes}-minute cutoff`
      : undefined,
  });

  // Step 4 — recommendations ---------------------------------------------
  const recActiveCount = Object.values(state.recEngineFeatures).filter(Boolean).length;
  items.push({
    label: 'Recommendations engine',
    active: state.recEngineConnection.kind === 'success',
    detail:
      state.recEngineConnection.kind === 'success'
        ? `${recActiveCount} of 5 features active`
        : 'Not connected (optional)',
  });

  return items;
}

function countMappings(state: WizardState, trigger: 'welcome' | 'first_order' | 'abandoned_cart'): number {
  return state.automationMappings.filter((m) => m.triggerType === trigger).length;
}

function smailySubdomain(state: WizardState): string {
  const sub = state.smailyCredentials.subdomain.trim();
  if (sub !== '') {
    return sub;
  }
  // Mode A — fall back to the configured default-fallback account's subdomain.
  const fallback = state.perLanguageAccounts.find(
    (a) => a.accountKey === state.defaultFallbackAccountKey,
  );
  return fallback?.credentials.subdomain.trim() ?? '';
}

function openSmailyDashboard(
  state: WizardState,
  override?: () => void,
): void {
  if (override) {
    override();
    return;
  }
  const sub = smailySubdomain(state);
  if (sub === '') {
    return;
  }
  if (typeof window !== 'undefined') {
    window.open(`https://${sub}.sendsmaily.net`, '_blank', 'noopener,noreferrer');
  }
}
