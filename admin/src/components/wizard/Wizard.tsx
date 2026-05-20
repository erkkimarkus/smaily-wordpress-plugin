import { useReducer } from 'react';

import { wizardReducer } from '../../state/wizard-reducer';
import { type WizardState } from '../../state/types';
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
  const handleFinish = (): void => {
    // Phase 2 stub — full multi-tab save is a Phase 3 task. For now
    // redirect to the Settings page so the user can verify their
    // saved credentials persisted.
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
            <div className="px-6 py-6">{renderStep(state, dispatch)}</div>

            <WizardFooter
              currentStep={state.currentStep}
              totalSteps={6}
              onBack={handleBack}
              onContinue={handleContinue}
              onFinish={handleFinish}
              canAdvance={canAdvance}
              advanceHint={advanceHint}
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
