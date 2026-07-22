import { useCallback, type Dispatch } from 'react';

import { __ } from '@admin/lib/i18n';
import { type SmailyCredentials, type WizardAction, type WizardState } from '../../state/types';
import { Card, Checkbox, Toggle } from '../primitives';
import { AutomationSection } from './AutomationSection';
import { CredentialBlock } from './CredentialBlock';

export interface TransactionalEmailsSectionProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
}

/** account_key the transactional Smaily account is bound under (PRO-1504). */
const TRANSACTIONAL_ACCOUNT_KEY = 'transactional';

/**
 * Transactional emails — stage 1 (PRO-1504): configuration only, no send
 * path. A separate Smaily account (bound under account_key='transactional')
 * plus two trigger mappings (order confirmation, shipping confirmation) and
 * the "counts as shipped" order-status set. Nothing here changes what a
 * customer receives yet — the sender is a later stage.
 *
 * Mirrors the existing patterns: CredentialBlock for the account (same as
 * Step1Connect's default/per-language blocks), AutomationSection for each
 * trigger's workflow mapping (pinned to the transactional account via
 * accountKeyOverride — this account has no per-language variants).
 *
 * Gated fully behind the enablement toggle: with it off, nothing below the
 * toggle renders — no credential fields, no mapping rows, no extra network
 * calls to /workflows for an unconfigured account.
 */
export function TransactionalEmailsSection({
  state,
  dispatch,
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
        label={ __( 'Enable transactional emails', 'smaily-connect' ) }
      />

      {state.transactionalEmailsEnabled && (
        <div className="mt-5 space-y-6">
          <CredentialBlock
            title={ __( 'Transactional Smaily account', 'smaily-connect' ) }
            description={ __(
              'A separate Smaily account (or sub-account) used only for transactional sends.',
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

          <AutomationSection
            state={state}
            dispatch={dispatch}
            trigger="order_confirmation"
            title={ __( 'Order confirmation', 'smaily-connect' ) }
            description={ __( 'Sent when a customer places an order.', 'smaily-connect' ) }
            isEnabled={state.orderConfirmationEnabled}
            onEnabledChange={(enabled) =>
              dispatch({ type: 'SET_ORDER_CONFIRMATION_ENABLED', payload: enabled })
            }
            accountKeyOverride={TRANSACTIONAL_ACCOUNT_KEY}
          />

          <AutomationSection
            state={state}
            dispatch={dispatch}
            trigger="shipping_confirmation"
            title={ __( 'Shipping confirmation', 'smaily-connect' ) }
            description={ __( 'Sent when an order reaches one of the statuses marked as shipped below.', 'smaily-connect' ) }
            isEnabled={state.shippingConfirmationEnabled}
            onEnabledChange={(enabled) =>
              dispatch({ type: 'SET_SHIPPING_CONFIRMATION_ENABLED', payload: enabled })
            }
            accountKeyOverride={TRANSACTIONAL_ACCOUNT_KEY}
            extras={<ShippedStatusPicker state={state} dispatch={dispatch} />}
          />
        </div>
      )}
    </Card>
  );
}

interface ShippedStatusPickerProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
}

/**
 * "Counts as shipped" order-status multi-select (PRO-1504). Choices come
 * from the server-registered statuses (incl. custom ones) via
 * EnvDetector::order_statuses() — the same checkbox-grid pattern Step 2
 * uses for sync fields. Setting only; no status-change listener yet.
 */
function ShippedStatusPicker({ state, dispatch }: ShippedStatusPickerProps): React.JSX.Element {
  const statuses = state.env.orderStatuses ?? [];

  return (
    <div className="mt-4">
      <p className="text-sm font-medium text-text-primary">
        { __( 'Counts as shipped', 'smaily-connect' ) }
      </p>
      <p className="mt-1 text-xs text-text-tertiary">
        { __( 'Order statuses that trigger the shipping-confirmation email.', 'smaily-connect' ) }
      </p>
      <div className="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2">
        {statuses.map((status) => (
          <Checkbox
            key={status.slug}
            name={`smly-shipped-status-${status.slug}`}
            checked={state.shippedOrderStatuses.includes(status.slug)}
            onChange={() => dispatch({ type: 'TOGGLE_SHIPPED_ORDER_STATUS', payload: { status: status.slug } })}
            label={status.name}
          />
        ))}
      </div>
    </div>
  );
}
