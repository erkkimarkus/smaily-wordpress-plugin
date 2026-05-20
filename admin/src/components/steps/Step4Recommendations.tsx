import { type Dispatch } from 'react';

import { type WizardAction, type WizardState } from '../../state/types';
import { Banner, Button, Card, Toggle } from '../primitives';

export interface Step4RecommendationsProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  inSettings?: boolean;
}

/**
 * Step 4 — Recommendations engine.
 *
 * Dual variant based on rec-engine setup-token exchange status:
 *
 *   4a (recEngineConnection.kind === 'success') — feature toggles for
 *       orders / customers / products / cart events / browse tracking.
 *   4b (any other state) — marketing card with a "Set up" CTA that
 *       routes back to Step 1's rec-engine block.
 *
 * Phase 3 wires the backfill panels next to each toggle. Phase 2 ships
 * only the toggles — they persist into wizardState.recEngineFeatures
 * which Phase 3's hooks consume.
 */
export function Step4Recommendations({
  state,
  dispatch,
  inSettings = false,
}: Step4RecommendationsProps): React.JSX.Element {
  const isConnected = state.recEngineConnection.kind === 'success';

  // Sub-PR 2.H.15 — Variant4b's "Back to Step 1" CTA now routes
  // depending on whether the user is inside the wizard or the
  // Settings tabs. Wizard dispatches WIZARD_GO_TO_STEP; Settings
  // changes location.hash to the Connection tab where the
  // rec-engine setup-token field lives.
  const handleBackToStep1 = (): void => {
    if (inSettings) {
      if (typeof window !== 'undefined') {
        window.location.hash = 'connection';
      }
      return;
    }
    dispatch({ type: 'WIZARD_GO_TO_STEP', payload: { step: 1 } });
  };

  return (
    <div className="space-y-6">
      {!inSettings && (
        <div>
          <p className="text-sm font-medium uppercase tracking-wide text-text-tertiary">
            Step 4 of 6
          </p>
          <h2 className="mt-1 text-2xl font-semibold text-text-primary">
            Personalised recommendations
          </h2>
          <p className="mt-2 text-sm text-text-secondary">
            Sync product, customer, and order data to the Smaily recommendation engine for
            personalised product recommendations in your campaigns.
          </p>
        </div>
      )}

      {isConnected ? (
        <Variant4a state={state} dispatch={dispatch} />
      ) : (
        <Variant4b inSettings={inSettings} onBackToStep1={handleBackToStep1} />
      )}
    </div>
  );
}

function Variant4a({
  state,
  dispatch,
}: {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
}): React.JSX.Element {
  type FeatureKey = keyof WizardState['recEngineFeatures'];

  const toggle = (feature: FeatureKey) => (e: React.ChangeEvent<HTMLInputElement>): void => {
    dispatch({
      type: 'SET_REC_ENGINE_FEATURE',
      payload: { feature, enabled: e.target.checked },
    });
  };

  return (
    <>
      <Card
        title="Data synchronisation"
        description="The engine learns from joined order + customer + product data. Best results when all three are synced."
      >
        <div className="space-y-3">
          <Toggle
            name="rec-sync-orders"
            checked={state.recEngineFeatures.syncOrders}
            onChange={toggle('syncOrders')}
            label="Sync orders to recommendations engine"
          />
          <Toggle
            name="rec-sync-customers"
            checked={state.recEngineFeatures.syncCustomers}
            onChange={toggle('syncCustomers')}
            label="Sync customers to recommendations engine"
          />
          <Toggle
            name="rec-sync-products"
            checked={state.recEngineFeatures.syncProducts}
            onChange={toggle('syncProducts')}
            label="Sync products to recommendations engine"
          />
          <Toggle
            name="rec-sync-cart-events"
            checked={state.recEngineFeatures.trackCartEvents}
            onChange={toggle('trackCartEvents')}
            label="Track cart events in real-time"
          />
        </div>
      </Card>

      <Card
        title="Browsing telemetry"
        description="Tracks product / category views to power 'similar products' recommendations."
      >
        <Toggle
          name="rec-track-browsing"
          checked={state.recEngineFeatures.trackBrowsing}
          onChange={toggle('trackBrowsing')}
          label="Track browsing behaviour"
          description="Requires marketing consent (WP Consent API / Cookiebot / Complianz / CookieYes)."
        />
        {state.recEngineFeatures.trackBrowsing && (
          <Banner tone="warning" className="mt-4">
            Browsing telemetry only fires when the visitor has granted marketing consent. If your
            site doesn&apos;t have a consent banner installed, the beacon won&apos;t collect events.
          </Banner>
        )}
      </Card>
    </>
  );
}

function Variant4b({
  inSettings,
  onBackToStep1,
}: {
  inSettings: boolean;
  onBackToStep1: () => void;
}): React.JSX.Element {
  return (
    <Card title="Personalised product recommendations in every email">
      <p className="text-sm text-text-secondary">
        Pilot clients see 2-8× revenue from targeted product emails compared to generic
        newsletters. The recommendations engine learns from orders, customers, and products to
        surface the right product to each contact.
      </p>

      <ul className="mt-4 grid gap-2 text-sm text-text-secondary md:grid-cols-2">
        <li>Welcome series — match new subscribers to your bestsellers</li>
        <li>Cart-abandoned reminders — show what they almost bought</li>
        <li>Cross-sell — recommend complementary items after purchase</li>
        <li>Win-back — re-engage with previously-bought favourites</li>
        <li>Newsletter — fill each issue with each contact&apos;s likely matches</li>
        <li>Anniversary — celebrate purchase milestones with a personalised gift</li>
      </ul>

      <div className="mt-5 flex items-center gap-3">
        <Button variant="primary" type="button" onClick={() => window.open('https://smaily.com/recommendations/', '_blank')}>
          Activate recommendations engine →
        </Button>
        <Button variant="ghost" type="button" onClick={onBackToStep1}>
          {inSettings
            ? 'Already have an endpoint? Open Connection tab'
            : 'Already have an endpoint? Back to Step 1'}
        </Button>
      </div>
    </Card>
  );
}
