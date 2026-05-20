import { useReducer, useState } from 'react';

import { saveSettings } from '../../api/saveSettings';
import { buildTabPayload } from '../../state/buildTabPayload';
import { wizardReducer } from '../../state/wizard-reducer';
import { type SettingsTabKey, type WizardState } from '../../state/types';
import { Banner } from '../primitives';
import {
  Step1Connect,
  Step2Subscribers,
  Step3WooCommerce,
  Step4Recommendations,
  Step5Integrations,
  Step6Done,
} from '../steps';
import { StepRail, type StepRailItem } from './StepRail';
import { WizardFooter } from './WizardFooter';

export interface WizardProps {
  initialState: WizardState;
}

const STEP_LABELS: Array<{ label: string; description: string }> = [
  { label: 'Connect', description: 'Smaily credentials' },
  { label: 'Subscribers', description: 'Field mapping + backfill' },
  { label: 'WooCommerce', description: 'Automation triggers' },
  { label: 'Recommendations', description: 'Optional (rec-engine)' },
  { label: 'Integrations', description: 'Elementor / CF7 / Blocks' },
  { label: 'Done', description: 'Summary + finish' },
];

/**
 * Wizard root — orchestrates the six steps via a single useReducer
 * driven by wizardReducer. State is hydrated from the PHP boot payload
 * (admin/wizard.php → wp_localize_script → window.smailyConnectBoot)
 * inside admin/src/index.tsx before this component receives it.
 *
 * Per-step layout: <StepRail | step body | WizardFooter>.
 *
 * canAdvance gating (Phase 2):
 *   Step 1 — requires smailyConnection.kind === 'success'
 *   Step 2-5 — always pass (toggles + info)
 *   Step 6 — Finish enabled when Step 1 connection passed
 *
 * The Finish handler is a stub in Phase 2 — it just closes the wizard
 * by reloading to the Settings page. Phase 3 wires it to a bulk save
 * across the four /settings tabs.
 */
export function Wizard({ initialState }: WizardProps): React.JSX.Element {
  const [state, dispatch] = useReducer(wizardReducer, initialState);
  const [finishStatus, setFinishStatus] = useState<'idle' | 'saving' | 'error'>('idle');
  const [finishError, setFinishError] = useState<string | null>(null);

  const railItems: StepRailItem[] = STEP_LABELS.map((entry, idx) => ({
    id: idx + 1,
    label: entry.label,
    description: entry.description,
    completed: state.currentStep > idx + 1,
  }));

  const canAdvance = computeCanAdvance(state);
  const advanceHint = computeAdvanceHint(state);

  const handleContinue = (): void => dispatch({ type: 'WIZARD_NEXT_STEP' });
  const handleBack = (): void => dispatch({ type: 'WIZARD_PREVIOUS_STEP' });

  // Sub-PR 2.H.17 — Finish actually persists wizard state.
  //
  // The earlier Phase 2 stub redirected to Settings without saving,
  // so Erkki could walk Step 1..6, click Finish, and end up on a
  // Settings page showing empty credentials — the wizard's state
  // lived only in React. Now Finish POSTs all four settings-tab
  // payloads in sequence (connection → subscribers → woocommerce →
  // recommendations) and only redirects on a clean run; a failure
  // surfaces an inline banner with the field-level error so the
  // user can hop back to the relevant step.
  const handleFinish = async (): Promise<void> => {
    setFinishStatus('saving');
    setFinishError(null);

    const tabs: SettingsTabKey[] = [
      'connection',
      'subscribers',
      'woocommerce',
      'recommendations',
    ];

    for (const tab of tabs) {
      const payload = buildTabPayload(state, tab);
      try {
        const response = await saveSettings({ tab, data: payload });
        if (!response.saved) {
          const message = response.errors
            .map((e) => `${e.field}: ${e.message}`)
            .join('; ') || 'Save failed.';
          setFinishStatus('error');
          setFinishError(`Couldn't save ${tab}: ${message}`);
          return;
        }
      } catch (err) {
        const message = err instanceof Error ? err.message : 'Network error';
        setFinishStatus('error');
        setFinishError(`Couldn't save ${tab}: ${message}`);
        return;
      }
    }

    if (typeof window !== 'undefined') {
      window.location.href = 'admin.php?page=smaily-connect-settings';
    }
  };

  const handleStepClick = (step: number): void =>
    dispatch({ type: 'WIZARD_GO_TO_STEP', payload: { step } });

  return (
    <div className="min-h-screen bg-page-bg font-sans text-text-primary">
      <div className="mx-auto flex max-w-6xl gap-6 px-6 py-8">
        <StepRail
          currentStep={state.currentStep}
          steps={railItems}
          onStepClick={handleStepClick}
        />

        <div className="min-w-0 flex-1">
          <div className="rounded-lg bg-surface shadow-card">
            <div className="px-6 py-6">
              {renderStep(state, dispatch)}
              {finishStatus === 'error' && finishError !== null && (
                <Banner tone="danger" className="mt-4">
                  {finishError}
                </Banner>
              )}
            </div>

            <WizardFooter
              currentStep={state.currentStep}
              totalSteps={6}
              onBack={handleBack}
              onContinue={handleContinue}
              onFinish={() => void handleFinish()}
              canAdvance={canAdvance}
              advanceHint={advanceHint}
              loading={finishStatus === 'saving'}
            />
          </div>
        </div>
      </div>
    </div>
  );
}

function renderStep(
  state: WizardState,
  dispatch: React.Dispatch<Parameters<typeof wizardReducer>[1]>,
): React.JSX.Element {
  switch (state.currentStep) {
    case 1:
      return <Step1Connect state={state} dispatch={dispatch} />;
    case 2:
      return <Step2Subscribers state={state} dispatch={dispatch} />;
    case 3:
      return <Step3WooCommerce state={state} dispatch={dispatch} />;
    case 4:
      return <Step4Recommendations state={state} dispatch={dispatch} />;
    case 5:
      return <Step5Integrations state={state} />;
    case 6:
      return <Step6Done state={state} />;
    default:
      return <Step1Connect state={state} dispatch={dispatch} />;
  }
}

function computeCanAdvance(state: WizardState): boolean {
  if (state.currentStep === 1) {
    return state.smailyConnection.kind === 'success';
  }
  if (state.currentStep === 6) {
    return state.smailyConnection.kind === 'success';
  }
  return true;
}

function computeAdvanceHint(state: WizardState): string | undefined {
  if (state.currentStep === 1 && state.smailyConnection.kind !== 'success') {
    return 'Test your Smaily connection to continue.';
  }
  return undefined;
}
