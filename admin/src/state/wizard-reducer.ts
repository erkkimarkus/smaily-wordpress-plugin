import {
  DEFAULT_SYNC_FIELDS,
  emptyCredentials,
  idleAsync,
  idleBackfill,
  type ModeAccount,
  type WizardAction,
  type WizardState,
} from './types';

/**
 * Wizard reducer — drives both /wizard and /settings panels.
 *
 * Same function, two callers:
 *   - admin/wizard.php mounts <App view="wizard"> with wizardInitialState
 *   - admin/settings.php mounts <App view="settings"> with settingsInitialState
 *     (assembled in settings-reducer.ts from data-env attributes the
 *     PHP mount emits)
 *
 * Per Erkki's sub-PR 2.B note: actions whose semantics are
 * wizard-specific (WIZARD_GO_TO_STEP, WIZARD_NEXT_STEP, ...) still
 * dispatch from Settings — they're no-ops there because Settings UI
 * doesn't render currentStep. The reducer doesn't branch on
 * state.inSettings; React components decide what to expose.
 */

export const wizardInitialState: WizardState = {
  inSettings: false,
  currentStep: 1,
  env: {
    detectedLanguages: [],
    elementorPresent: false,
    cf7Present: false,
    storeTotals: { customers: 0, orders: 0, products: 0 },
  },

  smailyCredentials: { ...emptyCredentials },
  smailyConnection: idleAsync,
  multilingualMode: 'single',
  perLanguageAccounts: [],
  recEngineSetupToken: '',
  recEngineConnection: idleAsync,

  subscriberSyncEnabled: true,
  syncFields: [...DEFAULT_SYNC_FIELDS],
  wordpressSubscriptionCheckbox: false,
  checkoutSubscriptionCheckbox: false,
  contactsBackfill: idleBackfill,
};

const MAX_STEP = 6;
const MIN_STEP = 1;

export function wizardReducer(state: WizardState, action: WizardAction): WizardState {
  switch (action.type) {
    // Step 1: Connect ------------------------------------------------------
    case 'SET_SMAILY_CREDENTIALS':
      return {
        ...state,
        smailyCredentials: { ...state.smailyCredentials, ...action.payload },
        // Mutating credentials invalidates any previous connection-test result.
        smailyConnection: idleAsync,
      };

    case 'TEST_SMAILY_CONNECTION_START':
      return { ...state, smailyConnection: { kind: 'pending' } };

    case 'TEST_SMAILY_CONNECTION_SUCCESS':
      return {
        ...state,
        smailyConnection: { kind: 'success', message: action.payload.accountName },
      };

    case 'TEST_SMAILY_CONNECTION_FAILURE':
      return {
        ...state,
        smailyConnection: { kind: 'failure', error: action.payload.error },
      };

    case 'SET_MULTILINGUAL_MODE': {
      // Mode change resets per-language accounts only when leaving Mode A
      // — keep the saved list intact when entering it so users don't lose
      // configuration on a B → A toggle.
      const leavingModeA = state.multilingualMode === 'A' && action.payload !== 'A';
      return {
        ...state,
        multilingualMode: action.payload,
        perLanguageAccounts: leavingModeA ? [] : state.perLanguageAccounts,
      };
    }

    case 'ADD_MODE_ACCOUNT': {
      const exists = state.perLanguageAccounts.some(
        (acct) => acct.accountKey === action.payload.accountKey,
      );
      if (exists) {
        return state;
      }
      return {
        ...state,
        perLanguageAccounts: [...state.perLanguageAccounts, action.payload],
      };
    }

    case 'REMOVE_MODE_ACCOUNT':
      return {
        ...state,
        perLanguageAccounts: state.perLanguageAccounts.filter(
          (acct) => acct.accountKey !== action.payload.accountKey,
        ),
      };

    case 'UPDATE_MODE_ACCOUNT_CREDENTIALS':
      return {
        ...state,
        perLanguageAccounts: state.perLanguageAccounts.map((acct): ModeAccount =>
          acct.accountKey === action.payload.accountKey
            ? {
                ...acct,
                credentials: { ...acct.credentials, ...action.payload.credentials },
                // Credential mutation invalidates the connection check.
                connection: idleAsync,
              }
            : acct,
        ),
      };

    case 'SET_REC_ENGINE_SETUP_TOKEN':
      return {
        ...state,
        recEngineSetupToken: action.payload,
        recEngineConnection: idleAsync,
      };

    case 'TEST_REC_ENGINE_CONNECTION_START':
      return { ...state, recEngineConnection: { kind: 'pending' } };

    case 'TEST_REC_ENGINE_CONNECTION_SUCCESS':
      return {
        ...state,
        recEngineConnection: { kind: 'success', message: action.payload.message },
      };

    case 'TEST_REC_ENGINE_CONNECTION_FAILURE':
      return {
        ...state,
        recEngineConnection: { kind: 'failure', error: action.payload.error },
      };

    // Step 2: Subscribers --------------------------------------------------
    case 'SET_SUBSCRIBER_SYNC_ENABLED':
      return { ...state, subscriberSyncEnabled: action.payload };

    case 'TOGGLE_SYNC_FIELD': {
      const present = state.syncFields.includes(action.payload.field);
      return {
        ...state,
        syncFields: present
          ? state.syncFields.filter((f) => f !== action.payload.field)
          : [...state.syncFields, action.payload.field],
      };
    }

    case 'SET_SYNC_FIELDS':
      return { ...state, syncFields: action.payload };

    case 'SET_WORDPRESS_SUBSCRIPTION_CHECKBOX':
      return { ...state, wordpressSubscriptionCheckbox: action.payload };

    case 'SET_CHECKOUT_SUBSCRIPTION_CHECKBOX':
      return { ...state, checkoutSubscriptionCheckbox: action.payload };

    case 'BACKFILL_START':
      return {
        ...state,
        contactsBackfill: {
          ...state.contactsBackfill,
          status: 'running',
          processed: 0,
          percent: 0,
          error: null,
        },
      };

    case 'BACKFILL_PROGRESS':
      return {
        ...state,
        contactsBackfill: action.payload.progress,
      };

    case 'BACKFILL_CANCEL':
      return {
        ...state,
        contactsBackfill: { ...state.contactsBackfill, status: 'cancelled' },
      };

    // Wizard navigation ----------------------------------------------------
    case 'WIZARD_GO_TO_STEP':
      return {
        ...state,
        currentStep: clamp(action.payload.step, MIN_STEP, MAX_STEP),
      };

    case 'WIZARD_NEXT_STEP':
      return { ...state, currentStep: Math.min(MAX_STEP, state.currentStep + 1) };

    case 'WIZARD_PREVIOUS_STEP':
      return { ...state, currentStep: Math.max(MIN_STEP, state.currentStep - 1) };

    default:
      return assertNever(action);
  }
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

/**
 * Compile-time exhaustiveness guard — if a new WizardAction case is
 * added to the union and the switch above doesn't handle it,
 * `action` here is inferred as `WizardAction` instead of `never`,
 * which fails the function-return type-check.
 */
function assertNever(action: never): never {
  throw new Error(`wizardReducer: unhandled action ${JSON.stringify(action)}`);
}
