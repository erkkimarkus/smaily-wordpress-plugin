import {
  DEFAULT_SYNC_FIELDS,
  emptyCredentials,
  idleAsync,
  idleBackfill,
  type WizardState,
} from './types';

/**
 * Settings reducer surface — same wizardReducer function, different
 * initial state.
 *
 * The PHP-side admin/settings.php mount (sub-PR 2.H) emits a
 * `data-env` attribute on the mount node with the server-loaded option
 * values. `buildSettingsInitialState` parses that bag into a WizardState
 * so the Settings tabs render the saved configuration on first paint
 * rather than flashing empty defaults.
 *
 * This file deliberately does NOT export a settingsReducer function.
 * There's exactly one reducer in the system (wizardReducer); having two
 * would defeat the SUGGESTION.md §1 "üks komponent, kaks renderdamise-
 * konteksti" architecture.
 */

export { wizardReducer } from './wizard-reducer';

/**
 * Shape of the server-emitted env payload. Sub-PR 2.H formalises this
 * in includes/Wizard/EnvDetector.php; defining it here keeps the parsing
 * layer typed.
 */
export interface ServerEnv {
  detectedLanguages?: string[];
  elementorPresent?: boolean;
  cf7Present?: boolean;
  storeTotals?: {
    customers?: number;
    orders?: number;
    products?: number;
  };
  smailyCredentials?: {
    subdomain?: string;
    username?: string;
    // password deliberately NEVER round-trips — server-loaded credentials
    // arrive with a "configured: true" marker but the secret stays on the
    // server. The Settings UI shows "Connected as alice@..." instead of
    // a password input pre-filled with cleartext.
  };
  smailyConnected?: boolean;
  multilingualMode?: 'single' | 'A' | 'B' | 'C';
  subscriberSyncEnabled?: boolean;
  syncFields?: string[];
  wordpressSubscriptionCheckbox?: boolean;
  checkoutSubscriptionCheckbox?: boolean;
}

/**
 * Build the initial state for the Settings context.
 *
 * Currentstep is 0 — Settings doesn't render the step rail. The wizard
 * navigation actions still mutate it (they're no-ops UI-wise) so the
 * reducer stays branch-free.
 */
export function buildSettingsInitialState(env: ServerEnv = {}): WizardState {
  return {
    inSettings: true,
    currentStep: 0,
    env: {
      detectedLanguages: env.detectedLanguages ?? [],
      elementorPresent: env.elementorPresent ?? false,
      cf7Present: env.cf7Present ?? false,
      storeTotals: {
        customers: env.storeTotals?.customers ?? 0,
        orders: env.storeTotals?.orders ?? 0,
        products: env.storeTotals?.products ?? 0,
      },
    },

    smailyCredentials: {
      ...emptyCredentials,
      subdomain: env.smailyCredentials?.subdomain ?? '',
      username: env.smailyCredentials?.username ?? '',
    },
    smailyConnection: env.smailyConnected ? { kind: 'success' } : idleAsync,
    multilingualMode: env.multilingualMode ?? 'single',
    perLanguageAccounts: [],
    defaultFallbackAccountKey: 'default',
    recEngineSetupToken: '',
    recEngineConnection: idleAsync,

    subscriberSyncEnabled: env.subscriberSyncEnabled ?? true,
    syncFields: env.syncFields ?? [...DEFAULT_SYNC_FIELDS],
    wordpressSubscriptionCheckbox: env.wordpressSubscriptionCheckbox ?? false,
    checkoutSubscriptionCheckbox: env.checkoutSubscriptionCheckbox ?? false,
    contactsBackfill: idleBackfill,

    automationMappings: [],
    welcomeEnabled: false,
    firstOrderEnabled: false,
    abandonedCartEnabled: false,
    abandonedCartCutoffMinutes: 30,

    recEngineFeatures: {
      syncOrders: true,
      syncCustomers: true,
      syncProducts: true,
      trackCartEvents: true,
      trackBrowsing: false,
    },

    dirtyTabs: {
      connection: false,
      subscribers: false,
      woocommerce: false,
      recommendations: false,
    },
  };
}
