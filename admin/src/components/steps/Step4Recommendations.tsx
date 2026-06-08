import { useState, type Dispatch } from 'react';

import {
  disconnectEngine,
  pingEngine,
  setupExchange,
  type SetupExchangeFailure,
} from '../../api/recEngine';
import { type WizardAction, type WizardState } from '../../state/types';
import { Banner, Button, Card, Input, Label, Toggle } from '../primitives';
import { BackfillPanel } from '../BackfillPanel';

export interface Step4RecommendationsProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  inSettings?: boolean;
}

/**
 * Step 4 — Recommendations engine.
 *
 * Two-state UI driven by state.recEngineConnection:
 *
 *   not-connected  → SetupCard: paste setup URL, Connect button.
 *                    Talks to /rec-engine/setup-exchange which does
 *                    the one-time token round-trip server-side and
 *                    stores the encrypted api_key in wp_options.
 *
 *   connected (4a) → TenantHeader (✓ Connected as <name>) + Test
 *                    connection + Disconnect, then the data-sync
 *                    feature toggles (orders / customers / products
 *                    / cart events / browse). Toggles only render
 *                    once connected — Step-4-inside progressive
 *                    disclosure mirrors the 2.I tab-lock pattern.
 *
 * Step 4 is optional. Continue can advance even when not connected
 * (Wizard.tsx canAdvance returns true for steps other than 1 + 6).
 */
export function Step4Recommendations({
  state,
  dispatch,
  inSettings = false,
}: Step4RecommendationsProps): React.JSX.Element {
  const isConnected = state.recEngineConnection.kind === 'success';

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
            personalised product recommendations in your campaigns. Optional — you can come back
            and set this up later.
          </p>
        </div>
      )}

      {isConnected ? (
        <ConnectedView state={state} dispatch={dispatch} />
      ) : (
        <SetupCard dispatch={dispatch} />
      )}
    </div>
  );
}

function SetupCard({
  dispatch,
}: {
  dispatch: Dispatch<WizardAction>;
}): React.JSX.Element {
  const [setupUrl, setSetupUrl] = useState('');
  const [status, setStatus] = useState<'idle' | 'pending' | 'error'>('idle');
  const [error, setError] = useState<SetupExchangeFailure | null>(null);

  const handleConnect = async (): Promise<void> => {
    if (setupUrl.trim() === '') {
      return;
    }
    setStatus('pending');
    setError(null);
    dispatch({ type: 'TEST_REC_ENGINE_CONNECTION_START' });

    const response = await setupExchange({ setupUrl: setupUrl.trim() });

    if (response.connected) {
      dispatch({
        type: 'TEST_REC_ENGINE_CONNECTION_SUCCESS',
        payload: { message: response.tenantName },
      });
      setStatus('idle');
      // Wipe the local input so the (one-time-used) token doesn't
      // sit in the DOM after success.
      setSetupUrl('');
      return;
    }

    setStatus('error');
    setError(response);
    dispatch({
      type: 'TEST_REC_ENGINE_CONNECTION_FAILURE',
      payload: { error: response.message },
    });
  };

  return (
    <Card title="Connect your recommendations engine">
      <p className="text-sm text-text-secondary">
        Paste the setup URL from your Smaily admin → Recommendations. The link looks like{' '}
        <span className="font-mono text-xs">https://&lt;host&gt;/setup/&lt;token&gt;</span> and is
        single-use — once accepted, the engine generates a long-lived API key the plugin stores
        encrypted on this site.
      </p>

      <div className="mt-4 space-y-2">
        <Label htmlFor="rec-engine-setup-url" required>
          Setup URL
        </Label>
        <Input
          id="rec-engine-setup-url"
          value={setupUrl}
          onChange={(e) => {
            setSetupUrl(e.target.value);
            if (status === 'error') {
              setStatus('idle');
              setError(null);
            }
          }}
          placeholder="https://re-...vercel.app/setup/..."
          autoComplete="off"
        />
      </div>

      <div className="mt-5 flex items-center gap-3">
        <Button
          variant="primary"
          type="button"
          onClick={() => void handleConnect()}
          loading={status === 'pending'}
          disabled={setupUrl.trim() === ''}
        >
          Connect
        </Button>
      </div>

      {error !== null && (
        <Banner tone="danger" className="mt-4" title={errorTitle(error)}>
          {error.message}
          {error.error === 'token_expired_or_used' && error.regenerateUrl !== undefined && error.regenerateUrl !== '' && (
            <>
              {' '}
              <a
                href={error.regenerateUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="underline"
              >
                Regenerate the link
              </a>
              .
            </>
          )}
        </Banner>
      )}
    </Card>
  );
}

function ConnectedView({
  state,
  dispatch,
}: {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
}): React.JSX.Element {
  type FeatureKey = keyof WizardState['recEngineFeatures'];

  const tenantName =
    state.recEngineConnection.kind === 'success'
      ? state.recEngineConnection.message ?? 'Smaily recommendations engine'
      : 'Smaily recommendations engine';

  const [pingStatus, setPingStatus] = useState<'idle' | 'pending' | 'success' | 'error'>('idle');
  const [pingMessage, setPingMessage] = useState<string>('');

  const handlePing = async (): Promise<void> => {
    setPingStatus('pending');
    setPingMessage('');
    const result = await pingEngine();
    if (result.ok) {
      setPingStatus('success');
      setPingMessage(
        `Engine v${result.engineVersion} responded — tenant status: ${result.tenantStatus || 'active'}.`,
      );
    } else {
      setPingStatus('error');
      setPingMessage(result.message);
    }
  };

  const handleDisconnect = async (): Promise<void> => {
    const confirmed = window.confirm(
      'Disconnect the recommendations engine? The plugin will stop syncing data; existing campaigns on the engine side continue to work until you remove them there.',
    );
    if (!confirmed) {
      return;
    }
    await disconnectEngine();
    dispatch({
      type: 'TEST_REC_ENGINE_CONNECTION_FAILURE',
      payload: { error: 'Disconnected.' },
    });
    // FAILURE reset is the cleanest existing action; the UI immediately
    // collapses to the SetupCard since recEngineConnection.kind !== 'success'.
    // (We deliberately don't add a dedicated DISCONNECT action — the
    // existing reducer surface handles the state transition.)
  };

  const toggle = (feature: FeatureKey) => (e: React.ChangeEvent<HTMLInputElement>): void => {
    dispatch({
      type: 'SET_REC_ENGINE_FEATURE',
      payload: { feature, enabled: e.target.checked },
    });
  };

  return (
    <>
      <Card title="Engine connection">
        <div className="flex items-center justify-between gap-4">
          <Banner tone="success" className="flex-1">
            <span className="font-medium">✓ Connected</span> as{' '}
            <span className="font-mono">{tenantName}</span>
          </Banner>
          <div className="flex shrink-0 gap-2">
            <Button
              variant="secondary"
              type="button"
              onClick={() => void handlePing()}
              loading={pingStatus === 'pending'}
            >
              Test connection
            </Button>
            <Button variant="ghost" type="button" onClick={() => void handleDisconnect()}>
              Disconnect
            </Button>
          </div>
        </div>
        {pingStatus !== 'idle' && pingMessage !== '' && (
          <Banner
            tone={pingStatus === 'success' ? 'success' : 'danger'}
            className="mt-4"
          >
            {pingMessage}
          </Banner>
        )}
      </Card>

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
        title="Import existing data"
        description="The toggles above sync future changes. Import your existing catalog, customers, and orders into the engine once so recommendations have history to learn from. Runs in the background in batches."
      >
        <div className="space-y-3">
          <BackfillPanel
            jobType="products"
            label="Products"
            recordCount={state.env.storeTotals.products}
          />
          <BackfillPanel
            jobType="customers"
            label="Customers"
            recordCount={state.env.storeTotals.customers}
          />
          <BackfillPanel
            jobType="orders"
            label="Orders"
            recordCount={state.env.storeTotals.orders}
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

function errorTitle(failure: SetupExchangeFailure): string {
  switch (failure.error) {
    case 'invalid_setup_url':
      return 'Setup URL not recognised';
    case 'token_expired_or_used':
      return 'Setup link already used';
    case 'token_not_found':
      return 'Setup link not found';
    case 'engine_unreachable':
    default:
      return 'Engine unreachable';
  }
}
