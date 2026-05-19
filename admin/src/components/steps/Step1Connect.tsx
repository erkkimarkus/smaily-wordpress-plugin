import { useEffect, type Dispatch } from 'react';

import { type WizardAction, type WizardState } from '../../state/types';
import { useTestConnection } from '../../hooks/useTestConnection';
import { Banner, Button, Card, Input, Label } from '../primitives';
import { MultilingualModePicker } from '../wizard';

export interface Step1ConnectProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  /**
   * True when rendered inside Settings tabs. Hides the wizard-only
   * eyebrow ("Step 1 of 6") + the marketing intro paragraph; the
   * field cards stay identical so a Connection-tab page is just the
   * same Step 1 minus the chrome.
   */
  inSettings?: boolean;
}

/**
 * Step 1 — Connect Smaily account.
 *
 * Three field groupings:
 *   1. Smaily credentials (subdomain + username + password)
 *   2. MultilingualModePicker — auto-hides for single-language sites
 *   3. Per-language credential blocks (Mode A only)
 *   4. Optional rec-engine setup-token paste (Phase 3 wires up)
 *
 * The Test Connection button is shared between (1) and (3); each
 * block tracks its own AsyncStatus in WizardState so a Mode A row
 * can be tested individually.
 */
export function Step1Connect({ state, dispatch, inSettings = false }: Step1ConnectProps): React.JSX.Element {
  const { mutate: testConnection, status, error, reset } = useTestConnection();

  // Reset hook status when the parent dispatches a credential edit so
  // the success banner doesn't linger across mutations.
  useEffect(() => {
    if (state.smailyConnection.kind === 'idle' && (status === 'success' || status === 'error')) {
      reset();
    }
  }, [state.smailyConnection.kind, status, reset]);

  // Mirror the hook status into the reducer's smailyConnection so the
  // wizard footer's canAdvance gating + Settings banner reflect the
  // same source of truth.
  useEffect(() => {
    if (status === 'pending' && state.smailyConnection.kind !== 'pending') {
      dispatch({ type: 'TEST_SMAILY_CONNECTION_START' });
    } else if (status === 'success' && state.smailyConnection.kind !== 'success') {
      dispatch({
        type: 'TEST_SMAILY_CONNECTION_SUCCESS',
        payload: { accountName: '' },
      });
    } else if (status === 'error' && state.smailyConnection.kind !== 'failure') {
      dispatch({
        type: 'TEST_SMAILY_CONNECTION_FAILURE',
        payload: { error: error ?? 'Connection failed.' },
      });
    }
  }, [status, error, dispatch, state.smailyConnection.kind]);

  const handleFieldChange = (field: 'subdomain' | 'username' | 'password') => (
    event: React.ChangeEvent<HTMLInputElement>,
  ): void => {
    dispatch({
      type: 'SET_SMAILY_CREDENTIALS',
      payload: { [field]: event.target.value },
    });
  };

  const handleTestClick = (): void => {
    void testConnection(state.smailyCredentials);
  };

  const credentialsComplete =
    state.smailyCredentials.subdomain !== '' &&
    state.smailyCredentials.username !== '' &&
    state.smailyCredentials.password !== '';

  return (
    <div className="space-y-6">
      {!inSettings && (
        <div>
          <p className="text-sm font-medium uppercase tracking-wide text-text-tertiary">Step 1 of 6</p>
          <h2 className="mt-1 text-2xl font-semibold text-text-primary">Connect your Smaily account</h2>
          <p className="mt-2 text-sm text-text-secondary">
            Enter the credentials from your Smaily admin → Settings → API. We&apos;ll verify them
            before saving — nothing is persisted until you click Continue.
          </p>
        </div>
      )}

      <Card title="Smaily API credentials">
        <div className="grid gap-4 md:grid-cols-3">
          <div>
            <Label htmlFor="smaily-subdomain" required>
              Subdomain
            </Label>
            <Input
              id="smaily-subdomain"
              value={state.smailyCredentials.subdomain}
              onChange={handleFieldChange('subdomain')}
              placeholder="mypetshop"
              autoComplete="off"
              className="mt-1"
            />
            <p className="mt-1 text-xs text-text-tertiary">
              The bit before <code className="font-mono">.sendsmaily.net</code>.
            </p>
          </div>

          <div>
            <Label htmlFor="smaily-username" required>
              API username
            </Label>
            <Input
              id="smaily-username"
              value={state.smailyCredentials.username}
              onChange={handleFieldChange('username')}
              autoComplete="off"
              className="mt-1"
            />
          </div>

          <div>
            <Label htmlFor="smaily-password" required>
              API password
            </Label>
            <Input
              id="smaily-password"
              type="password"
              value={state.smailyCredentials.password}
              onChange={handleFieldChange('password')}
              autoComplete="new-password"
              className="mt-1"
            />
          </div>
        </div>

        <div className="mt-5 flex items-center justify-between gap-4">
          <Button
            variant="secondary"
            onClick={handleTestClick}
            disabled={!credentialsComplete}
            loading={status === 'pending'}
            type="button"
          >
            Test connection
          </Button>

          {state.smailyConnection.kind === 'success' && (
            <Banner tone="success" className="flex-1">
              Connected to Smaily.
            </Banner>
          )}
          {state.smailyConnection.kind === 'failure' && (
            <Banner tone="danger" className="flex-1">
              {state.smailyConnection.error}
            </Banner>
          )}
        </div>
      </Card>

      <MultilingualModePicker
        value={state.multilingualMode}
        onChange={(mode) => dispatch({ type: 'SET_MULTILINGUAL_MODE', payload: mode })}
        detectedLanguages={state.env.detectedLanguages}
      />

      {state.multilingualMode === 'A' && state.env.detectedLanguages.length > 1 && (
        <Card
          title="Per-language Smaily accounts"
          description="Add a Smaily subdomain for each language. Contacts route to the matching account based on their detected locale."
        >
          <p className="text-sm text-text-secondary">
            Sub-PR 2.E follow-up wires the per-language credential editor — the data shape is already
            in WizardState.perLanguageAccounts.
          </p>
        </Card>
      )}
    </div>
  );
}
