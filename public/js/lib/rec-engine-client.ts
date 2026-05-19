/**
 * Smaily Recommendation Engine — browser-side tracking client.
 *
 * This is a TypeScript class designed to be platform-agnostic: no `wp.*`,
 * no `window.ajaxurl`, no WordPress nonces inside the class. Configuration
 * comes through constructor injection so the same class works behind a
 * WordPress wrapper (public/js/beacon.js) AND behind a Shopify Theme App
 * Extension's beacon.js. Mailstone 2 extracts this file unchanged into
 * the `@smaily/recengine-client` npm package; the platform wrappers stay
 * in their respective plugins.
 *
 * Phase 2 sub-PR 2.A ships only the public API surface: every method
 * throws `Not implemented`. Phase 3 sub-PR 6 fills the bodies. Locking
 * the signatures here means the wrapper code can be written against a
 * stable contract from the start, and the npm extraction in Mailstone 2
 * is a copy-paste rather than a redesign.
 *
 * Public surface follows ROADMAP.md §3.2.
 */

/**
 * Cookie names — supplied per request from the setup-exchange config
 * (the rec-engine's tenants control these so the same client code works
 * across multi-tenant deployments).
 */
export interface CookieNames {
  /** Visitor token issued on first email-link click. ~365-day TTL. */
  visitor: string;
  /** Anonymous browser session ID (UUID v4). ~30-day TTL. */
  session: string;
  /** Last-touch recommendation id from a campaign click. ~30-day TTL. */
  recId: string;
  /** Last-touch campaign context label (welcome / cart_abandoned / ...). */
  context: string;
}

/**
 * Configuration handed to the constructor. The defaults reflect the
 * pilot deployment; production values come from the platform wrapper
 * (which reads them from the setup-exchange response).
 */
export interface RecEngineClientConfig {
  /** Same-origin URL the wrapper exposes (e.g. /wp-json/smaily-connect/v1/beacon). */
  beaconUrl: string;

  /** Cookie names — must match what the platform wrapper sets server-side. */
  cookieNames: CookieNames;

  /** TTL of the anonymous-session cookie in days. Defaults to 30. */
  sessionTtlDays?: number;

  /** Batch window in milliseconds before the buffer flushes. Defaults to 30_000. */
  batchWindowMs?: number;

  /** Email of the currently signed-in user, if any. */
  customerEmail?: string | null;

  /** Platform-side user identifier — used for cross-device merge hints. */
  customerExternalId?: string | null;

  /**
   * Returns true when the user has granted marketing consent. The wrapper
   * implements this against the host platform's consent API (WP Consent,
   * Cookiebot, Complianz, CookieYes; or Shopify customer privacy).
   */
  consentChecker?: () => boolean;

  /** Logger override — defaults to console. */
  logger?: { log: (...args: unknown[]) => void; warn: (...args: unknown[]) => void };
}

export type EventType =
  | 'product_view'
  | 'category_view'
  | 'search'
  | 'cart_add'
  | 'cart_remove'
  | 'checkout_start'
  | 'checkout_complete'
  | 'wishlist_add';

export interface TrackingEvent {
  event_type: EventType;
  sku?: string;
  category_path?: string;
  search_query?: string;
  dwell_seconds?: number;
}

export type MergeReason =
  | 'user_logged_in'
  | 'email_provided_at_checkout'
  | 'email_link_click';

/**
 * Browse-tracking client used by both WordPress and Shopify wrappers.
 *
 * Phase 2 (this file) ships signatures only — every method throws to
 * make accidental production use impossible. Phase 3 fills the bodies
 * one method at a time.
 */
export class RecEngineClient {
  /**
   * Stored for Phase 3 method bodies. Underscore prefix tells TypeScript
   * (and reviewers) that the field is deliberately read-once-on-init —
   * Phase 3 removes the prefix when the methods start consuming it.
   */
  private readonly _config: RecEngineClientConfig;

  public constructor(config: RecEngineClientConfig) {
    this._config = config;
    // Touch the field so TypeScript's noUnusedLocals doesn't complain
    // while Phase 2 stubs are still throwing. Removed in Phase 3.
    void this._config;
  }

  /**
   * Queue a browse event for the next batched flush. Events are buffered
   * in memory for `batchWindowMs` (default 30s) before being POSTed to
   * `beaconUrl`. Synchronous — never throws on consent denial; the
   * consent check happens inside the buffer flush.
   */
  public track(_event: TrackingEvent): void {
    throw new Error('RecEngineClient.track: Not implemented (Phase 3 sub-PR 6)');
  }

  /**
   * Force-flush the buffer immediately. Returns a promise that resolves
   * when the server response is received (or when no events were queued).
   */
  public async flush(): Promise<void> {
    throw new Error('RecEngineClient.flush: Not implemented (Phase 3 sub-PR 6)');
  }

  /**
   * Inspect the current URL for `smaily_vt` / `smaily_rec` / `smaily_ctx`
   * parameters left by a campaign click. When present, the corresponding
   * cookies are set (using the names from `config.cookieNames`) and the
   * params are stripped from the URL via `history.replaceState`.
   *
   * Returns true if any params were captured.
   */
  public captureUrlParams(): boolean {
    throw new Error('RecEngineClient.captureUrlParams: Not implemented (Phase 3 sub-PR 6)');
  }

  /**
   * Promote the anonymous session to an identified one. Called from the
   * platform wrapper when the user logs in, provides an email at
   * checkout, or clicks a campaign email link.
   *
   * Returns a promise that resolves once the identity.merge event has
   * been accepted by the server.
   */
  public async mergeIdentity(_email: string, _reason: MergeReason): Promise<void> {
    throw new Error('RecEngineClient.mergeIdentity: Not implemented (Phase 3 sub-PR 6)');
  }

  /**
   * Cancel any pending batch flush and detach event listeners. The
   * wrapper calls this on page-unload to keep navigation snappy.
   */
  public destroy(): void {
    throw new Error('RecEngineClient.destroy: Not implemented (Phase 3 sub-PR 6)');
  }
}
