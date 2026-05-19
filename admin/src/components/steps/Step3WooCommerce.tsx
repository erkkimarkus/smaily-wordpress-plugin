import { type Dispatch } from 'react';

import { type WizardAction, type WizardState } from '../../state/types';
import { Label, NumberInput } from '../primitives';
import { AutomationSection } from './AutomationSection';

export interface Step3WooCommerceProps {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
  inSettings?: boolean;
}

/**
 * Step 3 — WooCommerce automations.
 *
 * Three independently-togglable sections:
 *   3a Welcome email — fires on user_register / created_customer hooks
 *   3b First-order email — fires on the customer's first paid order
 *   3c Abandoned-cart reminder — cron-driven (PLUGIN.md §5)
 *
 * Mode-aware workflow mappings live inside AutomationSection (one per
 * trigger). The shared reducer carries the rows in state.automationMappings
 * so Step 6's Done summary + the PHP-side persistence later read from
 * one place.
 *
 * The abandoned-cart section gets an extra Cutoff input (10-min minimum
 * per PLUGIN.md §5.3c).
 */
export function Step3WooCommerce({
  state,
  dispatch,
  inSettings = false,
}: Step3WooCommerceProps): React.JSX.Element {
  return (
    <div className="space-y-6">
      {!inSettings && (
        <div>
          <p className="text-sm font-medium uppercase tracking-wide text-text-tertiary">
            Step 3 of 6
          </p>
          <h2 className="mt-1 text-2xl font-semibold text-text-primary">WooCommerce automations</h2>
          <p className="mt-2 text-sm text-text-secondary">
            Map each trigger to a Smaily workflow. Multi-language sites pick a workflow per
            language; single-language sites pick one workflow per trigger.
          </p>
        </div>
      )}

      <AutomationSection
        state={state}
        dispatch={dispatch}
        trigger="welcome"
        title="Welcome email"
        description="Sent when a new contact subscribes (registration, created_customer, subscription-form)."
        isEnabled={state.welcomeEnabled}
        onEnabledChange={(enabled) => dispatch({ type: 'SET_WELCOME_ENABLED', payload: enabled })}
      />

      <AutomationSection
        state={state}
        dispatch={dispatch}
        trigger="first_order"
        title="First-order email"
        description="Sent the first time a customer completes an order (wc_get_customer_order_count === 1)."
        isEnabled={state.firstOrderEnabled}
        onEnabledChange={(enabled) =>
          dispatch({ type: 'SET_FIRST_ORDER_ENABLED', payload: enabled })
        }
      />

      <AutomationSection
        state={state}
        dispatch={dispatch}
        trigger="abandoned_cart"
        title="Abandoned-cart reminder"
        description="Fires when a cart has been idle for the cutoff time. Action Scheduler ticks every 15 minutes."
        isEnabled={state.abandonedCartEnabled}
        onEnabledChange={(enabled) =>
          dispatch({ type: 'SET_ABANDONED_CART_ENABLED', payload: enabled })
        }
        extras={
          <div className="mt-4">
            <Label htmlFor="smly-cart-cutoff">Cutoff time</Label>
            <div className="mt-1 w-40">
              <NumberInput
                id="smly-cart-cutoff"
                value={state.abandonedCartCutoffMinutes}
                onChange={(e) =>
                  dispatch({
                    type: 'SET_ABANDONED_CART_CUTOFF_MINUTES',
                    payload: parseInt(e.target.value, 10) || 30,
                  })
                }
                min={10}
                unit="min"
              />
            </div>
            <p className="mt-1 text-xs text-text-tertiary">
              Minimum 10 minutes. The cron job evaluates carts older than this.
            </p>
          </div>
        }
      />
    </div>
  );
}
