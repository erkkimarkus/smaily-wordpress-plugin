import type { JSX } from 'react';

import { Settings } from './components/settings';
import { Wizard } from './components/wizard';
import { hydrateState, readBoot } from './state/hydrate';

type View = 'wizard' | 'settings' | 'unknown';

interface AppProps {
  view: View;
}

/**
 * Top-level switch between the wizard and settings panels.
 *
 * State hydration: both panels share the same wizardReducer, so we
 * hydrate once at the App level and hand the resulting WizardState to
 * either <Wizard> or <Settings>. The `inSettings` flag on the state
 * itself signals to step components whether they should hide
 * wizard-only chrome (the "Step N of 6" eyebrow, etc.).
 *
 * The `unknown` view shouldn't happen in production — admin/settings.php
 * + admin/wizard.php always set data-view explicitly — but if a future
 * caller forgets, render a small placeholder rather than crashing.
 */
export function App({ view }: AppProps): JSX.Element {
  const boot = readBoot();
  const inSettings = view === 'settings';
  const initialState = hydrateState(boot, inSettings);

  if (view === 'settings') {
    return <Settings initialState={initialState} />;
  }

  if (view === 'wizard') {
    return <Wizard initialState={initialState} />;
  }

  return (
    <div className="min-h-screen bg-page-bg p-6 font-sans text-text-primary">
      <p>Smaily Connect — unknown view. Check the data-view attribute on the mount node.</p>
    </div>
  );
}
