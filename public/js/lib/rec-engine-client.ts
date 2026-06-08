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

// The full §6 event_type enum — exactly these 9. `wishlist_remove` was missing
// from this union (it had 8); the 3.4.0 context audit caught the drift against
// the contract before a live-walk could (LESSONS §2.7). Kept in §6 order.
export type EventType =
  | 'product_view'
  | 'category_view'
  | 'search'
  | 'cart_add'
  | 'cart_remove'
  | 'wishlist_add'
  | 'wishlist_remove'
  | 'checkout_start'
  | 'checkout_complete';

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

/** Default batch window before the buffer auto-flushes (ms). */
const DEFAULT_BATCH_WINDOW_MS = 30_000;

/** The wire shape of a single browse event (§6). */
interface WireEvent {
  event_id: string;
  session_id: string;
  event_type: EventType;
  event_ts: string;
  source: string;
  sku?: string;
  category_path?: string;
  search_query?: string;
  dwell_seconds?: number;
}

type Logger = { log: (...args: unknown[]) => void; warn: (...args: unknown[]) => void };

/**
 * Browse-tracking client used by both WordPress and Shopify wrappers.
 *
 * Sub-PR 3.4.1 fills track / flush / destroy + the buffering and unload
 * transport. captureUrlParams (cookie/URL-param capture) lands in 3.4.2 and
 * mergeIdentity (identity.merge) in 3.7 — both still throw so accidental use
 * is impossible.
 *
 * Transport: events buffer in memory for `batchWindowMs` (default 30s), then
 * POST same-origin to `beaconUrl` as `{events:[...]}` (the WP wrapper points
 * that at /wp-json/smaily-connect/v1/beacon). On page-hide the remaining
 * buffer is flushed via `navigator.sendBeacon` so it survives unload.
 *
 * Consent: nothing is ever SENT without consent. track() always buffers
 * (cheap, synchronous), but every flush checks `consentChecker` first and
 * DROPS the buffer when consent is absent — matching the admin promise that a
 * site with no consent banner collects no events. No checker ⇒ no consent.
 */
export class RecEngineClient {
  private readonly config: RecEngineClientConfig;
  private buffer: WireEvent[] = [];
  private flushTimer: ReturnType<typeof setTimeout> | null = null;
  private pageHideHandler: (() => void) | null = null;
  private destroyed = false;

  public constructor(config: RecEngineClientConfig) {
    this.config = config;

    // Final flush on unload — pagehide fires on real navigations AND on the
    // bfcache path where 'unload' does not. sendBeacon keeps it reliable.
    if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
      this.pageHideHandler = (): void => {
        this.flushOnUnload();
      };
      window.addEventListener('pagehide', this.pageHideHandler);
    }
  }

  /**
   * Queue a browse event for the next batched flush. Synchronous; never
   * throws on consent denial — the consent check happens at flush time.
   */
  public track(event: TrackingEvent): void {
    if (this.destroyed) {
      return;
    }
    this.buffer.push(this.enrich(event));
    this.scheduleFlush();
  }

  /**
   * Force-flush the buffer immediately. Resolves when the request settles
   * (or right away when nothing is queued / consent is absent). Best-effort:
   * a network error is logged, not thrown — telemetry must never break a page.
   */
  public async flush(): Promise<void> {
    if (this.flushTimer !== null) {
      clearTimeout(this.flushTimer);
      this.flushTimer = null;
    }
    if (this.buffer.length === 0) {
      return;
    }
    if (!this.hasConsent()) {
      this.buffer = [];
      return;
    }
    const events = this.buffer;
    this.buffer = [];
    try {
      await fetch(this.config.beaconUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ events }),
        credentials: 'same-origin',
        keepalive: true,
      });
    } catch (err) {
      this.logger().warn('[smaily-rec] beacon flush failed', err);
    }
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
    throw new Error('RecEngineClient.captureUrlParams: Not implemented (Phase 3 sub-PR 3.4.2)');
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
    throw new Error('RecEngineClient.mergeIdentity: Not implemented (Phase 3 sub-PR 3.7)');
  }

  /**
   * Cancel any pending batch flush and detach event listeners. The wrapper
   * calls this on SPA teardown / component unmount. It does NOT send — the
   * pagehide listener owns the final unload flush; destroy() just stops
   * tracking and drops anything still buffered.
   */
  public destroy(): void {
    this.destroyed = true;
    if (this.flushTimer !== null) {
      clearTimeout(this.flushTimer);
      this.flushTimer = null;
    }
    if (this.pageHideHandler !== null && typeof window !== 'undefined') {
      window.removeEventListener('pagehide', this.pageHideHandler);
      this.pageHideHandler = null;
    }
    this.buffer = [];
  }

  // --- internals ------------------------------------------------------

  private scheduleFlush(): void {
    if (this.flushTimer !== null) {
      return;
    }
    this.flushTimer = setTimeout(() => {
      this.flushTimer = null;
      void this.flush();
    }, this.batchWindowMs());
  }

  /**
   * Synchronous unload flush via sendBeacon — fetch (even keepalive) is not
   * reliable once the page is unloading. Drops the buffer when consent is
   * absent, same as flush().
   */
  private flushOnUnload(): void {
    if (this.buffer.length === 0) {
      return;
    }
    if (!this.hasConsent()) {
      this.buffer = [];
      return;
    }
    const events = this.buffer;
    this.buffer = [];
    const body = JSON.stringify({ events });
    if (typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
      navigator.sendBeacon(this.config.beaconUrl, new Blob([body], { type: 'application/json' }));
    } else {
      // Last resort — keepalive fetch, fire and forget.
      void this.flushBody(body);
    }
  }

  private async flushBody(body: string): Promise<void> {
    try {
      await fetch(this.config.beaconUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body,
        credentials: 'same-origin',
        keepalive: true,
      });
    } catch (err) {
      this.logger().warn('[smaily-rec] beacon unload flush failed', err);
    }
  }

  private enrich(event: TrackingEvent): WireEvent {
    const wire: WireEvent = {
      event_id: uuidV4(),
      session_id: this.sessionId(),
      event_type: event.event_type,
      event_ts: new Date().toISOString(),
      source: 'plugin_woo',
    };
    if (event.sku !== undefined) {
      wire.sku = event.sku;
    }
    if (event.category_path !== undefined) {
      wire.category_path = event.category_path;
    }
    if (event.search_query !== undefined) {
      wire.search_query = event.search_query;
    }
    if (event.dwell_seconds !== undefined) {
      wire.dwell_seconds = event.dwell_seconds;
    }
    return wire;
  }

  /**
   * Read the anonymous-session cookie. 3.4.1 only READS it (so events carry a
   * session_id when one exists); generating/persisting the cookie + the
   * URL-param capture is 3.4.2 (captureUrlParams). Empty until then.
   */
  private sessionId(): string {
    if (typeof document === 'undefined') {
      return '';
    }
    const name = this.config.cookieNames.session;
    const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.*+?^${}()|[\]\\])/g, '\\$1') + '=([^;]*)'));
    const value = match?.[1];
    return value !== undefined ? decodeURIComponent(value) : '';
  }

  private hasConsent(): boolean {
    return this.config.consentChecker ? this.config.consentChecker() : false;
  }

  private batchWindowMs(): number {
    return this.config.batchWindowMs ?? DEFAULT_BATCH_WINDOW_MS;
  }

  private logger(): Logger {
    return this.config.logger ?? console;
  }
}

/**
 * RFC-4122 v4 UUID. Uses crypto.randomUUID when available, falling back to
 * getRandomValues, then Math.random (only on ancient/locked-down runtimes).
 */
function uuidV4(): string {
  const cryptoObj = typeof crypto !== 'undefined' ? crypto : undefined;
  if (cryptoObj && typeof cryptoObj.randomUUID === 'function') {
    return cryptoObj.randomUUID();
  }
  const bytes = new Uint8Array(16);
  if (cryptoObj && typeof cryptoObj.getRandomValues === 'function') {
    cryptoObj.getRandomValues(bytes);
  } else {
    for (let i = 0; i < 16; i++) {
      bytes[i] = Math.floor(Math.random() * 256);
    }
  }
  // Version (4) + variant (10xx) bits.
  bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x40;
  bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80;
  let out = '';
  for (let i = 0; i < 16; i++) {
    out += ((bytes[i] ?? 0) + 0x100).toString(16).slice(1);
    if (i === 3 || i === 5 || i === 7 || i === 9) {
      out += '-';
    }
  }
  return out;
}
