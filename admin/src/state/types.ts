/**
 * WizardState + WizardAction — shared by both the wizard (admin/wizard.php)
 * and the settings tabs (admin/settings.php).
 *
 * Per SUGGESTION.md §1 (the "üks komponent, kaks renderdamise-konteksti"
 * decision) the same reducer drives both contexts; only the initial
 * state differs. settings-reducer.ts builds an initial state seeded from
 * the server-loaded option values, wizard-reducer.ts starts at step 1
 * with empty fields. Both call into the same wizardReducer function.
 *
 * Some actions are wizard-only (e.g. WIZARD_GO_TO_STEP) — they simply
 * have no observable effect in Settings because `currentStep` isn't
 * rendered there. The reducer doesn't branch on `inSettings`; the React
 * components do.
 */

/** Smaily API credential triple — same shape as Settings\CredentialSet PHP. */
export interface SmailyCredentials {
  subdomain: string;
  username: string;
  password: string;
}

/**
 * Multilingual mode picker — single-language stores collapse the wizard
 * UX to "single"; multi-language sites pick A / B / C per PLUGIN.md §4.
 */
export type MultilingualMode = 'single' | 'A' | 'B' | 'C';

/**
 * The five Settings tab slugs. Maps 1:1 to URL hashes (#connection,
 * #subscribers, ...). Integrations is informational so it never
 * appears in dirtyTabs.
 */
/**
 * Settings tab keys.
 *
 * The first four are real PillTabs in the Settings UI. `finish` is a
 * wizard-only pseudo-tab — `POST /settings { tab: 'finish' }` toggles
 * `smly_plus_setup_completed`; Settings UI never surfaces it. We
 * fold it into the same endpoint so the boot payload / nonce /
 * permission-check plumbing is reused.
 */
export type SettingsTabKey =
  | 'connection'
  | 'subscribers'
  | 'woocommerce'
  | 'recommendations'
  | 'finish';

/**
 * Discriminated union for API-call lifecycle (connection tests, backfill
 * starts, save-settings round-trips). The discriminator (`kind`) lets
 * components render the right state without nullable bools.
 */
export type AsyncStatus =
  | { kind: 'idle' }
  | { kind: 'pending' }
  | { kind: 'success'; message?: string }
  | { kind: 'failure'; error: string };

/**
 * Backfill progress state, one slot per job_type. Mirrors the shape the
 * BackfillEndpoint REST status response returns.
 */
export interface BackfillProgress {
  status: 'idle' | 'running' | 'completed' | 'failed' | 'cancelled';
  processed: number;
  total: number;
  percent: number;
  etaSeconds: number | null;
  error: string | null;
  /** UTC datetime string from the server, or null when never started. */
  startedAt: string | null;
  /** UTC datetime string set on terminal status, else null. */
  completedAt: string | null;
}

/**
 * Per-language credential set in Mode A. The `accountKey` is the
 * Settings\Credentials lookup key (typically the language code) and
 * gets persisted as `smly_plus_credentials_{accountKey}` on save.
 */
export interface ModeAccount {
  accountKey: string;
  language: string;
  credentials: SmailyCredentials;
  connection: AsyncStatus;
}

/**
 * Workflow → trigger mapping populated by Step 3. One row per
 * (trigger, language, accountKey) combination — Mode B uses
 * accountKey='default' for every row; Mode A varies per language.
 */
export type AutomationTrigger = 'welcome' | 'first_order' | 'abandoned_cart';

export interface AutomationMapping {
  triggerType: AutomationTrigger;
  /** Language code from env.detectedLanguages, or 'default' for single-language sites. */
  language: string;
  accountKey: string;
  workflowId: string;
  isDefaultFallback: boolean;
}

/**
 * Single source-of-truth state for both wizard and settings.
 *
 * Field-population strategy: Phase 2 sub-PR 2.B defines the full shape
 * but only the actions touching Step 1 + Step 2 are reduced. Later
 * sub-PRs (2.D / 2.E) add actions for Steps 3-6 against the same state
 * tree — never a parallel store.
 */
export interface WizardState {
  /** True when rendering inside Settings tabs (sub-PR 2.F). */
  inSettings: boolean;

  /** 1-based wizard step index. Settings ignores this. */
  currentStep: number;

  /** Detected store environment — populated from the server-emitted data-env. */
  env: {
    detectedLanguages: string[];
    elementorPresent: boolean;
    cf7Present: boolean;
    storeTotals: {
      customers: number;
      orders: number;
      products: number;
    };
  };

  /** Step 1 — Connect. */
  smailyCredentials: SmailyCredentials;
  smailyConnection: AsyncStatus;
  multilingualMode: MultilingualMode;
  perLanguageAccounts: ModeAccount[];
  /**
   * Mode A fallback selector — the accountKey of the credential set that
   * handles contacts whose detected language has no per-language entry.
   * Values: 'default' (= smailyCredentials) | any perLanguageAccounts[].accountKey.
   */
  defaultFallbackAccountKey: string;
  recEngineSetupToken: string;
  recEngineConnection: AsyncStatus;

  /** Step 3 — WooCommerce automations. Workflow id per (trigger, account_key). */
  automationMappings: AutomationMapping[];
  welcomeEnabled: boolean;
  firstOrderEnabled: boolean;
  abandonedCartEnabled: boolean;
  /** Minutes a cart stays untouched before the abandoned-cart trigger fires. */
  abandonedCartCutoffMinutes: number;

  /** Step 4 — Recommendations. */
  recEngineFeatures: {
    syncOrders: boolean;
    syncCustomers: boolean;
    syncProducts: boolean;
    trackCartEvents: boolean;
    /** Browse tracking is opt-in (PLUGIN.md §1: "vaikimisi väljas, GDPR-tundlik"). */
    trackBrowsing: boolean;
  };

  /**
   * Per-tab dirty flag — true when the tab's slice of state diverges from
   * what was last persisted via POST /settings. Settings UI surfaces Save +
   * Discard CTAs based on these flags. Wizard context ignores them — the
   * Finish button saves everything at once.
   */
  dirtyTabs: {
    connection: boolean;
    subscribers: boolean;
    woocommerce: boolean;
    recommendations: boolean;
  };

  /** Step 2 — Subscribers. */
  subscriberSyncEnabled: boolean;
  syncFields: string[];
  wordpressSubscriptionCheckbox: boolean;
  checkoutSubscriptionCheckbox: boolean;
  contactsBackfill: BackfillProgress;

  /** Step 3 — WooCommerce automations. Wizard sub-PR 2.E fills in the shape. */
  // Reserved.

  /** Step 4 — Recommendations. Wizard sub-PR 2.E fills in the shape. */
  // Reserved.
}

/**
 * Default sub-set of subscriber-sync fields — matches the upstream
 * Options::SUBSCRIBER_SYNC_DEFAULT_FIELDS list. UI in Step 2 (sub-PR 2.E)
 * lets the user toggle each one.
 */
export const DEFAULT_SYNC_FIELDS = [
  'first_name',
  'last_name',
  'phone',
  'birthday',
  'gender',
  'customer_group',
  'customer_id',
  'first_registered',
  'nickname',
  'site_title',
] as const;

export const emptyCredentials: SmailyCredentials = {
  subdomain: '',
  username: '',
  password: '',
};

export const idleAsync: AsyncStatus = { kind: 'idle' };

export const idleBackfill: BackfillProgress = {
  status: 'idle',
  processed: 0,
  total: 0,
  percent: 0,
  etaSeconds: null,
  error: null,
  startedAt: null,
  completedAt: null,
};

/**
 * The action union. Names use SCREAMING_SNAKE_CASE per the Redux
 * convention the prototype already established; payloads are typed
 * exhaustively so the reducer's switch is checked at compile time
 * (`assertNever` helper in wizard-reducer.ts).
 */
export type WizardAction =
  // Step 1: Connect ----------------------------------------------------------
  | { type: 'SET_SMAILY_CREDENTIALS'; payload: Partial<SmailyCredentials> }
  | { type: 'TEST_SMAILY_CONNECTION_START' }
  | { type: 'TEST_SMAILY_CONNECTION_SUCCESS'; payload: { accountName?: string } }
  | { type: 'TEST_SMAILY_CONNECTION_FAILURE'; payload: { error: string } }
  | { type: 'SET_MULTILINGUAL_MODE'; payload: MultilingualMode }
  | { type: 'ADD_MODE_ACCOUNT'; payload: ModeAccount }
  | { type: 'REMOVE_MODE_ACCOUNT'; payload: { accountKey: string } }
  | { type: 'UPDATE_MODE_ACCOUNT_CREDENTIALS'; payload: { accountKey: string; credentials: Partial<SmailyCredentials> } }
  | { type: 'TEST_MODE_ACCOUNT_CONNECTION_START'; payload: { accountKey: string } }
  | { type: 'TEST_MODE_ACCOUNT_CONNECTION_SUCCESS'; payload: { accountKey: string; accountName?: string } }
  | { type: 'TEST_MODE_ACCOUNT_CONNECTION_FAILURE'; payload: { accountKey: string; error: string } }
  | { type: 'SET_DEFAULT_FALLBACK_ACCOUNT_KEY'; payload: string }
  | { type: 'SET_REC_ENGINE_SETUP_TOKEN'; payload: string }
  | { type: 'TEST_REC_ENGINE_CONNECTION_START' }
  | { type: 'TEST_REC_ENGINE_CONNECTION_SUCCESS'; payload: { message?: string } }
  | { type: 'TEST_REC_ENGINE_CONNECTION_FAILURE'; payload: { error: string } }

  // Step 2: Subscribers ------------------------------------------------------
  | { type: 'SET_SUBSCRIBER_SYNC_ENABLED'; payload: boolean }
  | { type: 'TOGGLE_SYNC_FIELD'; payload: { field: string } }
  | { type: 'SET_SYNC_FIELDS'; payload: string[] }
  | { type: 'SET_WORDPRESS_SUBSCRIPTION_CHECKBOX'; payload: boolean }
  | { type: 'SET_CHECKOUT_SUBSCRIPTION_CHECKBOX'; payload: boolean }
  | { type: 'BACKFILL_START'; payload: { jobType: 'contacts' } }
  | { type: 'BACKFILL_PROGRESS'; payload: { jobType: 'contacts'; progress: BackfillProgress } }
  | { type: 'BACKFILL_CANCEL'; payload: { jobType: 'contacts' } }

  // Step 3: WooCommerce automations -----------------------------------------
  | { type: 'SET_WELCOME_ENABLED'; payload: boolean }
  | { type: 'SET_FIRST_ORDER_ENABLED'; payload: boolean }
  | { type: 'SET_ABANDONED_CART_ENABLED'; payload: boolean }
  | { type: 'SET_ABANDONED_CART_CUTOFF_MINUTES'; payload: number }
  | { type: 'UPSERT_AUTOMATION_MAPPING'; payload: AutomationMapping }
  | { type: 'REMOVE_AUTOMATION_MAPPING'; payload: { triggerType: AutomationTrigger; language: string; accountKey: string } }
  | { type: 'SET_AUTOMATION_FALLBACK'; payload: { triggerType: AutomationTrigger; language: string; accountKey: string } }

  // Step 4: Recommendations -------------------------------------------------
  | { type: 'SET_REC_ENGINE_FEATURE'; payload: { feature: 'syncOrders' | 'syncCustomers' | 'syncProducts' | 'trackCartEvents' | 'trackBrowsing'; enabled: boolean } }

  // Settings dirty-tab tracking ---------------------------------------------
  | { type: 'MARK_TAB_DIRTY'; payload: { tab: SettingsTabKey } }
  | { type: 'CLEAR_TAB_DIRTY'; payload: { tab: SettingsTabKey } }
  | { type: 'CLEAR_ALL_TABS_DIRTY' }

  // Wizard navigation --------------------------------------------------------
  | { type: 'WIZARD_GO_TO_STEP'; payload: { step: number } }
  | { type: 'WIZARD_NEXT_STEP' }
  | { type: 'WIZARD_PREVIOUS_STEP' };
