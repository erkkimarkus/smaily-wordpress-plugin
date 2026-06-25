import { type WizardState } from '../../state/types';
import { Card, Pill } from '../primitives';
import { RssFeedSection } from './RssFeedSection';
import { __ } from '@admin/lib/i18n';

export interface Step5IntegrationsProps {
  state: WizardState;
  inSettings?: boolean;
}

interface IntegrationCard {
  title: string;
  description: string;
  /** Detection key from state.env — true means the integration is installed. */
  installedKey: 'elementorPresent' | 'cf7Present' | null;
  /**
   * Sub-PR 2.H.15 — separate hrefs per installed state. The old single
   * `href` shipped users to `admin.php?page=wpcf7` even when CF7 was
   * absent, which WordPress renders as "Sorry, you are not allowed to
   * access this page" because the slug doesn't resolve. Each card now
   * has an explicit hrefInstalled (the plugin's own settings page) and
   * hrefMissing (plugin-install search filtered to the matching plugin).
   */
  hrefInstalled: string;
  hrefMissing: string;
  /** Button label depending on installed state. */
  ctaInstalled: string;
  ctaMissing: string;
}

const CARDS: IntegrationCard[] = [
  {
    title: __('Elementor', 'smaily-connect'),
    description: __(
      'The Smaily subscription-form widget is available in the Elementor editor.',
      'smaily-connect',
    ),
    installedKey: 'elementorPresent',
    hrefInstalled: 'admin.php?page=elementor-app',
    hrefMissing: 'plugin-install.php?s=elementor&tab=search&type=term',
    ctaInstalled: __('Open Elementor', 'smaily-connect'),
    ctaMissing: __('Install Elementor', 'smaily-connect'),
  },
  {
    title: __('Contact Form 7', 'smaily-connect'),
    description: __('Configure individual CF7 forms in Forms → Smaily tab.', 'smaily-connect'),
    installedKey: 'cf7Present',
    hrefInstalled: 'admin.php?page=wpcf7',
    hrefMissing: 'plugin-install.php?s=contact+form+7&tab=search&type=term',
    ctaInstalled: __('Open CF7', 'smaily-connect'),
    ctaMissing: __('Install Contact Form 7', 'smaily-connect'),
  },
  {
    title: __('Smaily Landing Pages', 'smaily-connect'),
    description: __('Embed Smaily landing pages anywhere via the Gutenberg block.', 'smaily-connect'),
    installedKey: null,
    hrefInstalled: 'post-new.php?post_type=page',
    hrefMissing: 'post-new.php?post_type=page',
    ctaInstalled: __('Add a new page', 'smaily-connect'),
    ctaMissing: __('Add a new page', 'smaily-connect'),
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
 * Below the cards, WooCommerce stores additionally get the product
 * RSS-feed URL builder (RssFeedSection) — client-side only, so the
 * step stays save-free. Gated on env.rss, which the server emits only
 * when WC is active.
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
            {__('Step 5 of 6', 'smaily-connect')}
          </p>
          <h2 className="mt-1 text-2xl font-semibold text-text-primary">
            {__('Integrations', 'smaily-connect')}
          </h2>
          <p className="mt-2 text-sm text-text-secondary">
            {__(
              'Smaily plays nicely with the other tools you already have installed. Configure each one from its own admin page — no extra setup required here.',
              'smaily-connect',
            )}
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
                installed ? (
                  <Pill tone="success">{__('Installed', 'smaily-connect')}</Pill>
                ) : (
                  <Pill tone="neutral">{__('Not installed', 'smaily-connect')}</Pill>
                )
              }
            >
              <p className="text-sm text-text-secondary">{card.description}</p>
              <a
                href={installed ? card.hrefInstalled : card.hrefMissing}
                className="mt-4 inline-flex h-8 items-center justify-center rounded bg-brand-soft-bg px-3 text-sm font-medium text-brand-soft-text hover:bg-brand-soft-bg/80"
              >
                {installed ? card.ctaInstalled : card.ctaMissing} →
              </a>
            </Card>
          );
        })}
      </div>

      {state.env.rss != null && <RssFeedSection rss={state.env.rss} />}
    </div>
  );
}
