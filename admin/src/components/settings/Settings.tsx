import {
  useCallback,
  useEffect,
  useMemo,
  useReducer,
  useState,
  type Dispatch,
} from 'react';

import { useSaveSettings } from '../../hooks/useSaveSettings';
import { actionToTab } from '../../state/action-to-tab';
import { buildTabPayload } from '../../state/buildTabPayload';
import {
  buildSettingsInitialState,
  type ServerEnv,
} from '../../state/settings-reducer';
import {
  type SettingsTabKey,
  type WizardAction,
  type WizardState,
} from '../../state/types';
import { wizardReducer } from '../../state/wizard-reducer';
import { Banner, Button, PillTabs } from '../primitives';
import {
  Step1Connect,
  Step2Subscribers,
  Step3WooCommerce,
  Step4Recommendations,
  Step5Integrations,
} from '../steps';
import { EventLog } from './EventLog';

export interface SettingsProps {
  initialEnv?: ServerEnv;
  /**
   * Pre-hydrated state from admin/src/state/hydrate.ts. When provided,
   * supersedes `initialEnv` — App.tsx hydrates once from the PHP boot
   * payload and hands the same WizardState to both Wizard and Settings.
   * Tests + Storybook still construct Settings with just `initialEnv`.
   */
  initialState?: WizardState;
}

const TABS: Array<{ value: SettingsTabKey | 'integrations' | 'events'; label: string }> = [
  { value: 'connection', label: 'Connection' },
  { value: 'subscribers', label: 'Subscribers' },
  { value: 'woocommerce', label: 'WooCommerce' },
  { value: 'recommendations', label: 'Recommendations' },
  { value: 'integrations', label: 'Integrations' },
  { value: 'events', label: 'Event Log' },
];

type AnyTab = SettingsTabKey | 'integrations' | 'events';

/**
 * Settings root — orchestrates tab routing + per-tab dirty tracking +
 * Save / Discard CTAs.
 *
 * Architectural notes:
 *
 *   1. Same wizardReducer drives state. inSettings=true on each step
 *      component hides the wizard-only chrome (eyebrow, footer). Step 6
 *      doesn't exist as a tab — it's a confirmation-time-only view.
 *
 *   2. Tab routing through location.hash: visiting wp-admin.../settings
 *      #subscribers lands on the Subscribers tab. Browser back/forward
 *      works for free; the user can share a deep link with a colleague.
 *
 *   3. Per-tab dirty tracking via a `taggedDispatch` wrapper. Every
 *      state-mutating action goes through actionToTab() to figure out
 *      which tab's dirty flag to flip. Connection-test lifecycles and
 *      polling events are intentionally non-dirty.
 *
 *   4. Mode A → other-mode swap surfaces a window.confirm() when
 *      perLanguageAccounts has entries that would be discarded. The
 *      reducer's SET_MULTILINGUAL_MODE handler clears the list
 *      automatically; the confirm gates the dispatch.
 *
 *   5. Discard = full page reload — re-fetches server-loaded state.
 *      Phase 4 polish can snapshot pristine state for an in-place revert,
 *      but for the pilot a hard reload is acceptable and bulletproof.
 */
export function Settings({ initialEnv = {}, initialState }: SettingsProps): React.JSX.Element {
  const [rawState, rawDispatch] = useReducer(
    wizardReducer,
    null,
    () => initialState ?? buildSettingsInitialState(initialEnv),
  );

  // Mode-A destructive-change guard + dirty-tab tagging wrap.
  const dispatch = useMemo<Dispatch<WizardAction>>(() => {
    return (action: WizardAction) => {
      if (action.type === 'SET_MULTILINGUAL_MODE') {
        const hasAccountsToLose =
          rawState.multilingualMode === 'A' &&
          rawState.perLanguageAccounts.length > 0 &&
          action.payload !== 'A';
        if (hasAccountsToLose) {
          const confirmed = window.confirm(
            `Switching to Mode ${action.payload} will discard the ${rawState.perLanguageAccounts.length} per-language credential set(s) you've configured. Continue?`,
          );
          if (!confirmed) {
            return;
          }
        }
      }

      rawDispatch(action);
      const tab = actionToTab(action);
      if (tab !== null) {
        rawDispatch({ type: 'MARK_TAB_DIRTY', payload: { tab } });
      }
    };
  }, [rawDispatch, rawState.multilingualMode, rawState.perLanguageAccounts.length]);

  const [activeTab, setActiveTab] = useTabRouting('connection');

  // Progressive disclosure (sub-PR 2.I). The wizard-first gate at the PHP
  // layer makes sure no one reaches Settings before Step 1 has been
  // completed at least once. But the merchant can still break their
  // connection from inside Settings (rotate credentials, paste garbage,
  // etc.); when that happens we lock the dependent tabs so they can't
  // silently save against an unauthenticated state.
  //
  // Locked tabs: Subscribers, WooCommerce, Recommendations.
  //   Connection is always accessible (that's where you fix the break).
  //   Integrations is info-only — never locked.
  //
  // If the user lands on a locked tab via a #hash deep-link or a
  // post-broken-state reload, bounce them back to Connection so the
  // CTA banner makes sense.
  const isConnected = rawState.smailyConnection.kind === 'success';
  const lockedTabs = useMemo<ReadonlySet<AnyTab>>(
    () =>
      isConnected
        ? new Set<AnyTab>()
        : new Set<AnyTab>(['subscribers', 'woocommerce', 'recommendations']),
    [isConnected],
  );

  useEffect(() => {
    if (lockedTabs.has(activeTab)) {
      setActiveTab('connection');
    }
  }, [lockedTabs, activeTab, setActiveTab]);

  const { mutate: save, status: saveStatus, error: saveError } = useSaveSettings({
    onSuccess: (_response, request) => {
      rawDispatch({
        type: 'CLEAR_TAB_DIRTY',
        payload: { tab: request.tab as SettingsTabKey },
      });
    },
  });

  const handleSave = useCallback((): void => {
    if (activeTab === 'integrations' || activeTab === 'events') {
      return;
    }
    const payload = buildTabPayload(rawState, activeTab);
    void save({ tab: activeTab, data: payload });
  }, [activeTab, rawState, save]);

  const handleDiscard = useCallback((): void => {
    if (!window.confirm('Discard unsaved changes and reload from the server?')) {
      return;
    }
    window.location.reload();
  }, []);

  // 'finish' is a wizard-only pseudo-tab and never lands in PillTabs;
  // narrow it out alongside 'integrations' so dirtyTabs indexing is
  // safe.
  const tabIsDirty =
    activeTab !== 'integrations' && activeTab !== 'finish' && activeTab !== 'events'
      ? rawState.dirtyTabs[activeTab]
      : false;

  return (
    <div className="min-h-screen bg-page-bg font-sans text-text-primary">
      <div className="mx-auto max-w-5xl space-y-6 p-6">
        <header className="flex items-center justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold">Smaily Connect — Settings</h1>
            <p className="mt-1 text-sm text-text-secondary">
              Manage your Smaily integration. Each tab saves independently.
            </p>
          </div>

          <PillTabs
            tabs={TABS.map((t) => {
              const isLocked = lockedTabs.has(t.value);
              return {
                value: t.value,
                label: t.label,
                badge:
                  t.value !== 'integrations' &&
                  t.value !== 'finish' &&
                  t.value !== 'events' &&
                  rawState.dirtyTabs[t.value]
                    ? '•'
                    : undefined,
                disabled: isLocked,
                title: isLocked ? 'Connect to Smaily first to unlock this tab.' : undefined,
              };
            })}
            value={activeTab}
            onChange={(next) => setActiveTab(next as AnyTab)}
            ariaLabel="Settings tabs"
          />
        </header>

        {!isConnected && (
          <Banner tone="warning" title="Smaily connection required">
            Subscribers, WooCommerce, and Recommendations are locked until your
            Smaily credentials authenticate. Fix the connection on the
            Connection tab to unlock them.
          </Banner>
        )}
        {saveStatus === 'error' && saveError !== null && (
          <Banner tone="danger" title="Save failed">
            {saveError}
          </Banner>
        )}
        {saveStatus === 'success' && (
          <Banner tone="success">Settings saved.</Banner>
        )}

        <main>
          <TabPanel active={activeTab === 'connection'}>
            <Step1Connect state={rawState} dispatch={dispatch} inSettings />
          </TabPanel>
          <TabPanel active={activeTab === 'subscribers'}>
            <Step2Subscribers
              state={rawState}
              dispatch={dispatch}
              inSettings
              backfillIntervalMs={30_000}
            />
          </TabPanel>
          <TabPanel active={activeTab === 'woocommerce'}>
            <Step3WooCommerce state={rawState} dispatch={dispatch} inSettings />
          </TabPanel>
          <TabPanel active={activeTab === 'recommendations'}>
            <Step4Recommendations state={rawState} dispatch={dispatch} inSettings />
          </TabPanel>
          <TabPanel active={activeTab === 'integrations'}>
            <Step5Integrations state={rawState} inSettings />
          </TabPanel>
          <TabPanel active={activeTab === 'events'}>
            <EventLog />
          </TabPanel>
        </main>

        {activeTab !== 'integrations' && activeTab !== 'events' && (
          <footer className="sticky bottom-0 -mx-6 flex items-center justify-end gap-3 border-t border-border-subtle bg-surface px-6 py-4 shadow-card">
            <Button
              variant="ghost"
              type="button"
              onClick={handleDiscard}
              disabled={!tabIsDirty}
            >
              Discard changes
            </Button>
            <Button
              variant="primary"
              type="button"
              onClick={handleSave}
              disabled={!tabIsDirty}
              loading={saveStatus === 'pending'}
            >
              Save changes
            </Button>
          </footer>
        )}
      </div>
    </div>
  );
}

/**
 * URL-hash routing — keeps `location.hash` in lockstep with the active tab.
 * Falls back to 'connection' when the hash is missing or unrecognised.
 * Returns the [activeTab, setActiveTab] pair the caller binds to PillTabs.
 */
function useTabRouting(defaultTab: AnyTab): [AnyTab, (next: AnyTab) => void] {
  const validTabs = useMemo(() => TABS.map((t) => t.value), []);

  const readHash = useCallback((): AnyTab => {
    if (typeof window === 'undefined') {
      return defaultTab;
    }
    const raw = window.location.hash.replace(/^#/, '');
    return (validTabs as readonly string[]).includes(raw) ? (raw as AnyTab) : defaultTab;
  }, [defaultTab, validTabs]);

  const [activeTab, setTab] = useState<AnyTab>(readHash);

  useEffect(() => {
    const onHashChange = (): void => setTab(readHash());
    window.addEventListener('hashchange', onHashChange);
    return () => window.removeEventListener('hashchange', onHashChange);
  }, [readHash]);

  const setActiveTab = useCallback((next: AnyTab) => {
    if (typeof window !== 'undefined') {
      window.location.hash = next;
    }
    setTab(next);
  }, []);

  return [activeTab, setActiveTab];
}

function TabPanel({
  active,
  children,
}: {
  active: boolean;
  children: React.ReactNode;
}): React.JSX.Element | null {
  if (!active) {
    return null;
  }
  return <div>{children}</div>;
}

