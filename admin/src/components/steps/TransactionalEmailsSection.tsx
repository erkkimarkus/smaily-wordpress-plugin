import { useCallback, type Dispatch } from 'react';

import { __ } from '@admin/lib/i18n';
import { type SmailyCredentials, type WizardAction, type WizardState } from '../../state/types';
import { Card, Toggle } from '../primitives';
import { CredentialBlock } from './CredentialBlock';

export interface TransactionalEmailsSectionProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  /** True when rendered inside Settings tabs — see Step1Connect's own flag. */
  inSettings?: boolean;
}

/**
 * Transactional emails — the account-connection block (PRO-1504 stage 1;
 * relocated to the Connection tab by PRO-1540 after the pilot found the
 * feature undiscoverable buried inside WooCommerce automations). An
 * OPTIONAL capability on top of the main Smaily connection: a separate
 * Smaily account (bound under account_key='transactional'), used only for
 * transactional sends, kept apart from the marketing account so a
 * marketing bounce/complaint can never threaten order-email delivery.
 *
 * Toggling on reveals the same CredentialBlock + "Test connection" +
 * green-checkmark pattern the main Smaily connection above uses. The two
 * trigger sections (order confirmation, shipping confirmation) that used
 * to render directly under this toggle now live on the WooCommerce tab
 * (TransactionalTriggersSection), gated on this connection being
 * established.
 */
export function TransactionalEmailsSection({
  state,
  dispatch,
  inSettings = false,
}: TransactionalEmailsSectionProps): React.JSX.Element {
  const onCredentialsChange = useCallback(
    (payload: Partial<SmailyCredentials>) => {
      dispatch({ type: 'SET_TRANSACTIONAL_CREDENTIALS', payload });
    },
    [dispatch],
  );
  const onConnStart = useCallback(
    () => dispatch({ type: 'TEST_TRANSACTIONAL_CONNECTION_START' }),
    [dispatch],
  );
  const onConnSuccess = useCallback(
    (accountName?: string) =>
      dispatch({ type: 'TEST_TRANSACTIONAL_CONNECTION_SUCCESS', payload: { accountName } }),
    [dispatch],
  );
  const onConnFailure = useCallback(
    (errorMessage: string) =>
      dispatch({ type: 'TEST_TRANSACTIONAL_CONNECTION_FAILURE', payload: { error: errorMessage } }),
    [dispatch],
  );

  return (
    <Card
      title={ __( 'Transactional emails', 'smaily-connect' ) }
      description={ __(
        'Send order confirmations and shipping notices through a separate Smaily account, kept apart from your marketing sends.',
        'smaily-connect',
      ) }
    >
      <Toggle
        name="smly-transactional-emails-enabled"
        checked={state.transactionalEmailsEnabled}
        onChange={(e) =>
          dispatch({ type: 'SET_TRANSACTIONAL_EMAILS_ENABLED', payload: e.target.checked })
        }
        label={ __( 'Use transactional emails', 'smaily-connect' ) }
      />

      {state.transactionalEmailsEnabled && (
        <div className="mt-5 space-y-4">
          <CredentialBlock
            title={ __( 'Transactional Smaily account', 'smaily-connect' ) }
            description={ __(
              'A separate Smaily account used only for transactional sends.',
              'smaily-connect',
            ) }
            credentials={state.transactionalCredentials}
            connection={state.transactionalConnection}
            onCredentialsChange={onCredentialsChange}
            onConnectionStart={onConnStart}
            onConnectionSuccess={onConnSuccess}
            onConnectionFailure={onConnFailure}
            idSuffix="transactional"
          />

          <p className="text-sm text-text-secondary">
            {inSettings
              ? __(
                  'Once connected, set up the Order confirmation and Shipping confirmation triggers on the WooCommerce tab.',
                  'smaily-connect',
                )
              : __(
                  'Once connected, set up the Order confirmation and Shipping confirmation triggers in the WooCommerce automations step.',
                  'smaily-connect',
                )}
          </p>
        </div>
      )}
    </Card>
  );
}
