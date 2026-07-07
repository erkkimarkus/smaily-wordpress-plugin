import {
  DEFAULT_CONTACT_SYNC_MODE,
  DEFAULT_SYNC_FIELDS,
  emptyCredentials,
  idleAsync,
  idleBackfill,
  idleEngineAutomations,
  normalizeContactSyncMode,
  type AutomationMapping,
  type AutomationTrigger,
  type RssFeedBootData,
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
  /**
   * Short git SHA of the bundle + PHP that staging is running, with
   * `-dirty` suffix if the build tree had uncommitted changes.
   * `dev` when git wasn't available at packaging time. Surfaces in the
   * browser console as `window.smailyConnectBoot.buildHash` so Erkki
   * can confirm "this WP is running THIS commit" without rebuilding.
   */
  buildHash: string;
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
    /**
     * RSS-feed builder data from EnvDetector::rss_snapshot(); null when
     * WooCommerce is inactive. Optional for forward/backward payload
     * compatibility (an old cached bundle reading a new payload or
     * vice versa must not crash hydrate).
     */
    rss?: RssFeedBootData | null;
  };
  savedSettings: {
    smailyCredentials: { subdomain: string; username: string; password: string };
    /**
     * True when the server marked the default account as previously
     * verified (sub-PR 2.H.15). hydrate.ts seeds `smailyConnection`
     * to success when this is true AND subdomain + username are
     * populated.
     */
    smailyConnected: boolean;
    /** True once the merchant clicked Finish on Step 6 (sub-PR 2.H.18). */
    setupCompleted: boolean;
    multilingualMode: string;
    defaultFallbackAccountKey: string;
    subscriberSyncEnabled: boolean;
    syncFields: string[];
    wordpressSubscriptionCheckbox: boolean;
    checkoutSubscriptionCheckbox: boolean;
    contactSyncMode: string;
    includeGuests: boolean;
    automationForceOptIn: boolean;
    abandonedCartCutoffMinutes: number;
    welcomeEnabled: boolean;
    firstOrderEnabled: boolean;
    abandonedCartEnabled: boolean;
    /**
     * Saved (trigger, language, accountKey) → workflowId rows.
     * `automationMappings` was previously hard-zeroed in hydrate; the
     * server-side SettingsEndpoint persists them but the boot payload
     * didn't expose them, so reload always blanked the Step 3 dropdowns.
     * EnvDetector::automation_mappings() now reads the table.
     */
    automationMappings?: Array<{
      triggerType: string;
      language: string;
      accountKey: string;
      workflowId: string;
      isDefaultFallback: boolean;
    }>;
    /**
     * Step 4 — rec-engine connection. The api_key intentionally never
     * lands here; the React layer only needs the connected flag plus
     * tenant display info. All authenticated calls flow through the
     * /rec-engine/ping proxy on the server.
     */
    recEngine?: {
      connected: boolean;
      tenantName: string;
      tenantId: string;
      engineVersion: string;
      baseUrl: string;
      issuedAt: string;
      /**
       * The saved browse-tracking merchant preference
       * (smly_plus_rec_track_browsing). Emitted independent of `connected`
       * because disconnect() preserves it — so a re-connect restores the
       * toggle state the merchant last chose. Previously hydrate hardcoded
       * this to false, which both blanked a saved-on preference on reload and
       * made re-connect forget it. The only Step-4 toggle after 3.9.
       */
      trackBrowsing: boolean;
    };
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
        rss: null,
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
        trackBrowsing: false,
      },
      engineAutomations: idleEngineAutomations,
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
      contactSyncMode: DEFAULT_CONTACT_SYNC_MODE,
      includeGuests: false,
      automationForceOptIn: false,
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
      rss: env.rss ?? null,
    },
    smailyCredentials: { ...s.smailyCredentials },
    smailyConnection:
      s.smailyConnected &&
      s.smailyCredentials.subdomain !== '' &&
      s.smailyCredentials.username !== ''
        ? { kind: 'success', message: s.smailyCredentials.username }
        : idleAsync,
    multilingualMode: mode,
    perLanguageAccounts: [],
    defaultFallbackAccountKey: s.defaultFallbackAccountKey || 'default',
    recEngineSetupToken: '',
    recEngineConnection: deriveRecEngineConnection(s.recEngine),
    automationMappings: normaliseAutomationMappings(s.automationMappings),
    welcomeEnabled: s.welcomeEnabled,
    firstOrderEnabled: s.firstOrderEnabled,
    abandonedCartEnabled: s.abandonedCartEnabled,
    abandonedCartCutoffMinutes: s.abandonedCartCutoffMinutes,
    // Read the saved browse preference so reload AND re-connect restore the
    // merchant's last choice (disconnect preserves the option server-side).
    recEngineFeatures: {
      trackBrowsing: s.recEngine?.trackBrowsing ?? false,
    },
    // Deliberately NOT part of the boot payload — the engine's GET is the
    // source of truth (F3-51); the section fetches catalog+config on open.
    engineAutomations: idleEngineAutomations,
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
    contactSyncMode: normalizeContactSyncMode(s.contactSyncMode),
    includeGuests: s.includeGuests,
    automationForceOptIn: s.automationForceOptIn,
    contactsBackfill: idleBackfill,
  };
}

const VALID_TRIGGERS: readonly AutomationTrigger[] = ['welcome', 'first_order', 'abandoned_cart'];

/**
 * Map the rec-engine boot snapshot into the existing AsyncStatus slot
 * the wizard + Step 6 summary read. Connected → kind='success' with
 * the tenant name as the display message. Not connected (or payload
 * missing) → idle. Failure states surface via the live ping endpoint
 * call inside Step 4, not at hydrate time.
 */
function deriveRecEngineConnection(
  rec: BootPayload['savedSettings']['recEngine'],
): WizardState['recEngineConnection'] {
  if (rec && rec.connected) {
    return {
      kind: 'success',
      message: rec.tenantName !== '' ? rec.tenantName : undefined,
    };
  }
  return idleAsync;
}

function normaliseAutomationMappings(
  raw: BootPayload['savedSettings']['automationMappings'],
): AutomationMapping[] {
  if (!Array.isArray(raw)) {
    return [];
  }
  const out: AutomationMapping[] = [];
  for (const row of raw) {
    if (!(VALID_TRIGGERS as readonly string[]).includes(row.triggerType)) {
      continue;
    }
    if (row.workflowId === '' || row.language === '' || row.accountKey === '') {
      continue;
    }
    out.push({
      triggerType: row.triggerType as AutomationTrigger,
      language: row.language,
      accountKey: row.accountKey,
      workflowId: row.workflowId,
      isDefaultFallback: !!row.isDefaultFallback,
    });
  }
  return out;
}
