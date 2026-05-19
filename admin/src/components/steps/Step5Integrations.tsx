import { type WizardState } from '../../state/types';
import { Card, Pill } from '../primitives';

export interface Step5IntegrationsProps {
  state: WizardState;
  inSettings?: boolean;
}

interface IntegrationCard {
  title: string;
  description: string;
  /** Detection key from state.env — true means the integration is installed. */
  installedKey: 'elementorPresent' | 'cf7Present' | null;
  /** Relative WP admin path (admin_url() prefixes it server-side). */
  href: string;
  /** Button label depending on installed state. */
  ctaInstalled: string;
  ctaMissing: string;
}

const CARDS: IntegrationCard[] = [
  {
    title: 'Elementor',
    description: 'The Smaily subscription-form widget is available in the Elementor editor.',
    installedKey: 'elementorPresent',
    href: 'admin.php?page=elementor-app',
    ctaInstalled: 'Open Elementor',
    ctaMissing: 'Install Elementor',
  },
  {
    title: 'Contact Form 7',
    description: 'Configure individual CF7 forms in Forms → Smaily tab.',
    installedKey: 'cf7Present',
    href: 'admin.php?page=wpcf7',
    ctaInstalled: 'Open CF7',
    ctaMissing: 'Install Contact Form 7',
  },
  {
    title: 'Smaily Landing Pages',
    description: 'Embed Smaily landing pages anywhere via the Gutenberg block.',
    installedKey: null,
    href: 'post-new.php?post_type=page',
    ctaInstalled: 'Add a new page',
    ctaMissing: 'Add a new page',
  },
];

/**
 * Step 5 — Integrations.
 *
 * Informative-only step: three cards link out to the WP admin pages for
 * each integration. Each card surfaces whether the underlying plugin is
 * installed (detected via state.env on PHP-mount) so admins know where
 * to start.
 *
 * Links use admin_url() output (server-side) so the relative href stays
 * in-window — `target="_blank"` would dump users into a new tab which
 * is jarring in the wizard flow.
 */
export function Step5Integrations({
  state,
  inSettings = false,
}: Step5IntegrationsProps): React.JSX.Element {
  return (
    <div className="space-y-6">
      {!inSettings && (
        <div>
          <p className="text-sm font-medium uppercase tracking-wide text-text-tertiary">
            Step 5 of 6
          </p>
          <h2 className="mt-1 text-2xl font-semibold text-text-primary">Integrations</h2>
          <p className="mt-2 text-sm text-text-secondary">
            Smaily plays nicely with the other tools you already have installed. Configure each one
            from its own admin page — no extra setup required here.
          </p>
        </div>
      )}

      <div className="grid gap-4 md:grid-cols-3">
        {CARDS.map((card) => {
          const installed =
            card.installedKey === null
              ? true
              : Boolean(state.env[card.installedKey]);
          return (
            <Card
              key={card.title}
              title={card.title}
              headerAccessory={
                installed ? <Pill tone="success">Installed</Pill> : <Pill tone="neutral">Not installed</Pill>
              }
            >
              <p className="text-sm text-text-secondary">{card.description}</p>
              <a
                href={card.href}
                className="mt-4 inline-flex h-8 items-center justify-center rounded bg-brand-soft-bg px-3 text-sm font-medium text-brand-soft-text hover:bg-brand-soft-bg/80"
              >
                {installed ? card.ctaInstalled : card.ctaMissing} →
              </a>
            </Card>
          );
        })}
      </div>
    </div>
  );
}
