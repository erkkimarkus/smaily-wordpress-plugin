import {
  DEFAULT_SYNC_FIELDS,
  emptyCredentials,
  idleAsync,
  idleBackfill,
  type WizardState,
} from './types';

/**
 * Server-emitted boot payload — admin/wizard.php + admin/settings.php
 * call `wp_localize_script('smaily-connect-admin', 'smailyConnectBoot',
 * boot)` so this lands on `window.smailyConnectBoot` before the React
 * bundle loads.
 *
 * Shape mirrors WizardState.env + the saved-settings tab payloads. The
 * keys are deliberately not 1:1 with WizardState so we have one place
 * (hydrate.ts) that maps PHP option naming → reducer state, and so the
 * UI can render before the server has fully populated every option.
 */
export interface BootPayload {
  nonce: string;
  /** Base URL for the REST namespace — passed to configureApiClient. */
  restUrl: string;
  /** 'wizard' | 'settings' — same string the data-view attribute carries. */
  view: 'wizard' | 'settings' | 'unknown';
  envSnapshot: {
    detectedLanguages: string[];
    multilingualPlugin: 'wpml' | 'polylang' | 'translatepress' | null;
    elementorPresent: boolean;
    cf7Present: boolean;
    wcActive: boolean;
    hposActive: boolean;
    storeTotals: {
      customers: number;
      orders: number;
      products: number;
    };
  };
  savedSettings: {
    smailyCredentials: { subdomain: string; username: string; password: string };
    multilingualMode: string;
    defaultFallbackAccountKey: string;
    subscriberSyncEnabled: boolean;
    syncFields: string[];
    wordpressSubscriptionCheckbox: boolean;
    checkoutSubscriptionCheckbox: boolean;
    abandonedCartCutoffMinutes: number;
    welcomeEnabled: boolean;
    firstOrderEnabled: boolean;
    abandonedCartEnabled: boolean;
  };
}

/**
 * Read window.smailyConnectBoot with cautious typing. The PHP mount
 * always sets this, but tests + Vite dev (no PHP) need a graceful
 * fallback to wizard-defaults so the bundle still mounts.
 */
export function readBoot(): BootPayload | null {
  const raw = (window as unknown as { smailyConnectBoot?: BootPayload }).smailyConnectBoot;
  if (raw === undefined || raw === null) {
    return null;
  }
  return raw;
}

/**
 * Map a BootPayload to a WizardState. The `inSettings` flag toggles
 * whether the rendered context is Settings (currentStep ignored) or
 * the wizard.
 */
export function hydrateState(boot: BootPayload | null, inSettings: boolean): WizardState {
  if (boot === null) {
    return {
      inSettings,
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
      defaultFallbackAccountKey: 'default',
      recEngineSetupToken: '',
      recEngineConnection: idleAsync,
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
      subscriberSyncEnabled: true,
      syncFields: [...DEFAULT_SYNC_FIELDS],
      wordpressSubscriptionCheckbox: false,
      checkoutSubscriptionCheckbox: false,
      contactsBackfill: idleBackfill,
    };
  }

  const env = boot.envSnapshot;
  const s = boot.savedSettings;

  // Mode default per Erkki's 2.H.5 spec: until the merchant explicitly
  // picks one, multilingual sites land on Mode B (single Smaily account
  // with per-language automation branches — PLUGIN.md §4: "kõige
  // tüüpilisem"). Single-language sites land on 'single'. A stored
  // mode always wins — we never overwrite a deliberate choice.
  const validModes = ['single', 'A', 'B', 'C'] as const;
  const hasSavedMode = (validModes as readonly string[]).includes(s.multilingualMode);
  const envDefault: WizardState['multilingualMode'] =
    env.detectedLanguages.length > 1 ? 'B' : 'single';
  const mode: WizardState['multilingualMode'] = hasSavedMode
    ? (s.multilingualMode as WizardState['multilingualMode'])
    : envDefault;

  return {
    inSettings,
    currentStep: 1,
    env: {
      detectedLanguages: env.detectedLanguages,
      elementorPresent: env.elementorPresent,
      cf7Present: env.cf7Present,
      storeTotals: env.storeTotals,
    },
    smailyCredentials: { ...s.smailyCredentials },
    smailyConnection: idleAsync,
    multilingualMode: mode,
    perLanguageAccounts: [],
    defaultFallbackAccountKey: s.defaultFallbackAccountKey || 'default',
    recEngineSetupToken: '',
    recEngineConnection: idleAsync,
    automationMappings: [],
    welcomeEnabled: s.welcomeEnabled,
    firstOrderEnabled: s.firstOrderEnabled,
    abandonedCartEnabled: s.abandonedCartEnabled,
    abandonedCartCutoffMinutes: s.abandonedCartCutoffMinutes,
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
    subscriberSyncEnabled: s.subscriberSyncEnabled,
    syncFields: s.syncFields.length > 0 ? s.syncFields : [...DEFAULT_SYNC_FIELDS],
    wordpressSubscriptionCheckbox: s.wordpressSubscriptionCheckbox,
    checkoutSubscriptionCheckbox: s.checkoutSubscriptionCheckbox,
    contactsBackfill: idleBackfill,
  };
}
