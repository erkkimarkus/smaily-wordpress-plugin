import type { JSX } from 'react';

/**
 * Phase 2 sub-PR 2.A — minimal skeleton that proves the build pipeline
 * works end-to-end. Real Wizard / Settings views land in sub-PRs 2.D–2.F.
 *
 * The `data-view` attribute on the mount node distinguishes the two
 * contexts (wizard vs settings). For now both paths render the same
 * placeholder card so the bundle is exercise-able from
 * admin/settings.php and admin/wizard.php once sub-PR 2.H wires the
 * enqueue + mount nodes up.
 */

type View = 'wizard' | 'settings' | 'unknown';

interface AppProps {
  view: View;
}

export function App({ view }: AppProps): JSX.Element {
  return (
    <div className="min-h-screen bg-page-bg font-sans text-text-primary">
      <div className="mx-auto max-w-3xl p-6">
        <div className="rounded-lg bg-surface p-6 shadow-card">
          <h1 className="text-2xl font-semibold">Smaily Connect (BETA)</h1>
          <p className="mt-2 text-text-secondary">
            Phase 2 build skeleton — view: <code className="font-mono">{view}</code>.
          </p>
          <p className="mt-4 text-sm text-text-tertiary">
            Real Wizard and Settings panels land in sub-PRs 2.D through 2.F.
          </p>
        </div>
      </div>
    </div>
  );
}
