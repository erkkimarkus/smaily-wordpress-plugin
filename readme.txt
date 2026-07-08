=== Smaily Connect ===
Contributors: sendsmaily, kaarel
Tags: smaily, newsletter, email, mail, marketing
Requires PHP: 8.0
Requires at least: 6.6
Tested up to: 7.0
WC requires at least: 6.9
WC tested up to: 10.7
Stable tag: 3.5.0
License: GPLv3 or later

Email marketing, automations and personalized product recommendations for WordPress, WooCommerce, Contact Form 7 and Elementor — powered by Smaily.

== Description ==

**Smaily Connect – The Only Email Marketing Plugin You Need!**

Transform your **WordPress website, WooCommerce store, Contact Form 7 and Elementor** into an **email marketing powerhouse** with Smaily – the all-in-one plugin designed to **automate your marketing, grow your audience, and drive more sales effortlessly**.

**Why Smaily Connect?**

**Turn Visitors into Subscribers** – Capture leads from **every touchpoint** – your website, WooCommerce store, and contact forms – all in one seamless flow.

**Automate Like a Pro** – Send high-converting emails effortlessly: welcome emails and abandoned cart reminders – **without lifting a finger**.

**Smart Form Integration** – Sync your **Contact Form 7** submissions directly to your Smaily lists for a frictionless email collection experience.

**Elementor Integration** – Build beautiful newsletter sign-up forms right inside Elementor using our dedicated widget!

**Smarter Email Campaigns** – Segment your audience and send **relevant offers, tailored product updates, and engaging content** that keeps subscribers interested and active.

**Personalized Product Recommendations** – Connect your WooCommerce store to Smaily Campaign Intelligence and use shopper-specific product suggestions in your email campaigns.

**Easy, Fast & Code-Free Setup** – No tech skills needed! A **guided setup wizard** walks you through connecting your Smaily account and configuring every integration.

= What's new in 2.0 =

Version 2.0 is a major update built alongside the proven 1.x feature set:

* **Setup wizard** – a guided, step-by-step first-run experience: connect your Smaily account, configure subscriber sync, WooCommerce automations, and form integrations.
* **Modern admin** – a redesigned, mobile-friendly settings interface.
* **Campaign Intelligence (WooCommerce)** – optionally connect your store to Smaily Campaign Intelligence. The plugin syncs your product catalog, customers and orders so it can generate personalized product recommendations for your email campaigns.
* **Browse tracking (opt-in)** – an optional storefront beacon records browsing activity (product views, searches, cart events) to improve recommendations. It is **off by default**, requires the site admin to enable it, and only runs for shoppers who have given cookie consent (WP Consent API compatible, e.g. CookieYes).
* **Privacy built in** – integrates with the WordPress Privacy tools (personal data export and erasure), and shoppers can opt out of recommendation profiling from their WooCommerce My Account page.
* **Reliability you can see** – background work runs on durable queues (Action Scheduler); an Event Log shows every sync event, failed items can be retried from the UI, and health notices warn you proactively when a connected service is unreachable.
* **Multilingual-aware** – language detection works with Polylang, WPML and TranslatePress for routing subscribers to the right lists and automations.
* **Product RSS feed** – load store products straight into your Smaily email templates. The familiar 1.x feed is unchanged; its URL builder now lives on the Integrations tab, and existing feed URLs keep working.

Existing installs upgrade in place — your settings, credentials and connections are preserved. If you have already completed the setup wizard, the new behaviour (including the cron-safe contact-language and consent sync) is active immediately on upgrade with no re-setup; an install that has never finished the wizard keeps its legacy live sync until it does.

= Documentation & Support =

For documentation, feature requests, and support, visit our [Help Center](https://smaily.com/help/user-manuals/).

= External services =

This plugin uses [Smaily Public API](https://smaily.com/help/api/) to communicate with your Smaily account. This is needed to establish a connection and transfer information between your WordPress site and your Smaily account. The plugin uses the API for following functionality:

- validating Smaily account connection with API key
- listing available automation workflows
- triggering automation workflows on form submissions and during sending abandoned cart reminders
- managing user subscription status during subscriber synchronization
- updating user subscription status when unsubscribing from newsletters
- reading and writing the shopper's recommendation-profiling consent preference

If you connect the optional **Smaily Campaign Intelligence** (a Smaily-operated service; the connection is established with a one-time setup token issued for your account), the plugin additionally sends the following WooCommerce data to it so it can compute personalized product recommendations:

- product catalog data (titles, prices, categories, stock status, product URLs)
- customer records (email address, name, registration date)
- order data (order status, totals, purchased items)
- browsing events (product views, searches, cart and checkout events) — **only** when the site admin has enabled browse tracking **and** the shopper has given cookie consent; shoppers who have opted out of profiling are excluded
- personal-data export, erasure and profiling opt-out requests, so WordPress Privacy tools and shopper preferences are honored on the engine side as well

You can manage how much information is shared between your WordPress site and Smaily account by configuring the plugin settings.

Privacy Policy: [Smaily Privacy Policy](https://smaily.com/privacy-policy/)
Terms of Service: [Smaily Terms of Service](https://smaily.com/terms-of-service/)

= Contribute =

Contribute to the development via [GitHub](https://github.com/sendsmaily/smaily-wordpress-plugin). We welcome new issues and pull requests.

== Installation ==

1. Upload the plugin files to your site's `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Open the Smaily Connect admin page and follow the setup wizard to connect your Smaily account and configure the integrations.

== Changelog ==

= 3.5.0 =
* Improved: the contact backfill progress now shows two honest numbers — how many WordPress users were checked, and how many contacts were actually synced to Smaily according to your contact sync mode (in consent mode only opted-in users are synced; that has always been the case, but the progress display previously showed the checked-users count labelled as synced contacts).
* New: before starting a backfill, the panel shows an estimate of how many contacts your current sync mode will actually sync (e.g. "about 16000 of them will be synced to Smaily as contacts").
* Fixed: the wizard's Done summary counted checked users as synced contacts.

= 3.4.3 =
* Fixed: saving the WooCommerce settings stored the abandoned-cart on/off state in a format the abandoned-cart email task could not read, which crashed the task on PHP 8 every 15 minutes — and turning the feature off did not stop it. The setting is now stored and read in one consistent format, and already-affected sites are healed automatically on update.
* Fixed: the wizard could show abandoned cart as enabled when it was actually disabled (on stores upgraded from an older plugin version).
* Improved: abandoned-cart reminders now use the workflow you map in the setup wizard / settings (including per-language workflows on multilingual stores). Stores upgraded from an older plugin version that have not run the wizard keep using their previously configured autoresponder, which is now also preserved when settings are re-saved.

= 3.4.2 =
* Fixed: a corrupted abandoned-cart row (left behind by an older plugin version after an in-place module swap) could crash the abandoned-cart email task on PHP 8 and repeat every 15 minutes, blocking all abandoned-cart reminders. Such rows are now skipped and logged, and one bad cart can no longer stop the others.
* Fixed: the plugin no longer re-creates the old WP-Cron schedules (for example after WooCommerce is re-activated) — scheduling is fully owned by the new task scheduler. Updating also cleans up leftover old schedules automatically.
* Fixed: the retired daily subscriber sync can no longer run even if an old scheduled event still exists on the site (it could overwrite contact languages with the wrong value).
* Fixed: abandoned-cart reminder emails now resolve the contact's language with the same logic as contact sync, instead of a method that could produce an empty or wrong language in scheduled runs.

= 3.4.1 =
* Fixes to the engine-run automations settings from real-store testing:
* Fixed: on a store whose language setup is store-wide, every automation row now shows the same uniform per-language workflow rows — a row no longer switches layout based on how it happened to be saved.
* Fixed: the cooldown input no longer snaps to 0 while you type; you can clear it and type a new value, and it is validated when you leave the field.
* New: a warning is shown when an automation is enabled in test mode but no test addresses are listed (the engine sends test emails only to the listed addresses, so an empty list means nobody receives anything).
* Improved: connection-failure messages are clearer — a human-readable summary pointing to the Campaign Intelligence tab, with the technical detail shown separately.
* Improved: on English-locale stores the automation recipe descriptions now show in English when the engine catalog provides them (recipe_en).
* New: **Engine-run recommendation automations** settings. When Smaily Campaign Intelligence is connected, the WooCommerce automations tab (and wizard Step 3) gains a section where you enable the engine's automation triggers (replenishment due, win-back, and more — the list comes from the engine for your store's sector) and bind each to your own Smaily workflow, with per-language workflow support on multilingual stores. Everything is fail-closed: triggers start off and in test mode (emails go only to your test addresses), and going live is a separate confirmed action. Sending happens engine-side; the plugin only saves your configuration.

= 3.3.2 =
* Browse tracking relies on the standard **WordPress Consent API** for consent. If browse tracking is on but no consent signal is present, the plugin now shows an admin notice explaining that the free **WP Consent API** plugin must be installed so your cookie banner (CookieYes, Complianz, Real Cookie Banner, …) can pass consent to Smaily — otherwise no browse data is collected. Reverts the CookieYes-specific consent reading added in 3.3.1 in favour of the standard, which CookieYes supports once the WP Consent API plugin is active.

= 3.3.1 =
* Fix: browse tracking on stores using CookieYes for cookie consent. (Superseded by 3.3.2, which uses the standard WordPress Consent API path instead of reading CookieYes directly.)

= 3.3.0 =
* Browse tracking now includes an anonymous Smaily visitor token (from a recommendation-link click) on browse events, so future personalization can use a shopper's browse history. Purchase attribution is unchanged — it is still credited from the order, not from browse. No recommendation id or email is attached to browse events. Only affects stores with browse tracking enabled and consented.

= 3.2.1 =
* Improved: the contact-sync mode selector now appears only when contact sync is enabled, and the "Checkout opt-in only" mode is selectable only when the checkout subscription checkbox is turned on. Restyled to match the rest of the setup wizard. No functional or data change.

= 3.2.0 =
* Fix: recommendation attribution is now captured on the WooCommerce **Block (Store API) checkout**, not just the classic checkout. On a block-checkout store the recommendation id was captured but never stamped onto the order; block-checkout orders now carry the attribution so purchases are credited to the recommendation.
* Contact sync modes: choose how customers are synced to Smaily by your lawful basis — "All customers (legitimate interest)", "Subscribers only (consent)" (default), or "Checkout opt-in only" — in the setup wizard / Settings. Consent mode mirrors Smaily unsubscribes/re-subscribes back into WordPress.
* Contacts are now synced with the correct language consistently (including from background jobs), and an opt-in / opt-out made in WordPress propagates to Smaily.
* Guest-checkout contacts and the checkout opt-in checkbox are now honoured by the contact sync.

= 3.1.0 =
* Recommendation attribution (landing capture): when a shopper clicks a product recommendation in a Smaily email and lands on the shop, the plugin now captures the recommendation id server-side and attaches it to the resulting order, so the engine can credit the purchase to the recommendation. Captured as a first-party functional cookie, independent of the browse-tracking consent toggle; disable with the `smaily_connect_capture_attribution` filter.

= 3.0.1 =
* Internationalization: the React admin UI (setup wizard + Settings) is now fully translatable, and the plugin ships a complete Estonian translation. No functional change.

= 3.0.0 =
First general-availability release, graduating the 2.1.0-beta line. Existing settings, credentials and connections are preserved; an in-place update needs no re-import.
* WooCommerce → Smaily Campaign Intelligence sync: catalog, customers, orders and consent-gated browse tracking, with backfill of existing data, an Event Log (with per-row retry and the request/response detail), and GDPR export / erase / opt-out.
* Hardening: WordPress.org Plugin Check pass (sanitization, escaping, prefixing, ABSPATH guards); editor blocks updated to Block API v3 for the WordPress 7.0 iframe editor; diagnostics gated behind WP_DEBUG.

= 2.1.0-beta.10 =

* Improved: the Event Log's "Details" view now shows the exact request sent to Campaign Intelligence and the engine's response, instead of an empty payload — making sync issues far easier to diagnose. An event that was skipped without being sent is now clearly marked as such.
* Changed: removed the old plugin settings screen — all configuration lives in the setup wizard and Settings (which use the same underlying options). Your subscription widget and the Plugins-page "Settings" link are unchanged.

= 2.1.0-beta.9 =

* Fixed: orders that previously never reached Campaign Intelligence are now sent. (1) Orders in a custom WooCommerce status — for example a shipping plugin's "label printed" or "shipped" — are now treated as sales instead of being silently skipped. (2) An order that contains a product you later deleted is no longer dropped: the line is kept from the order's saved details so the whole order still reaches the engine (that one deleted item just won't drive product-level recommendations). On-hold orders are not counted as a sale until payment is captured — they're sent once they move to processing or completed. After updating, re-run the order import (Campaign Intelligence → Import existing data → Orders) to pick up orders that were skipped before.
* Fixed: the "Settings" link under Smaily Connect on the Plugins page now opens the current plugin screen instead of the old module's settings page.

= 2.1.0-beta.8 =

* Fixed: the browse-tracking script now loads for visitors who use ad/tracking blockers. Its filename and endpoint were renamed off a generic name that common blocker lists flagged, which had stopped browse events from being recorded for those visitors. Browse tracking is otherwise unchanged — it still only runs when you've enabled it and the shopper has given cookie/marketing consent.

= 2.1.0-beta.7 =

* Fixed: products you move to the Trash that customers had previously ordered are now kept in Campaign Intelligence as out-of-stock, instead of disappearing. The recommendation engine still learns from that purchase history (so those customers keep getting the right kind of suggestions) but won't recommend a trashed product. Restoring a product from the Trash marks it available again. After updating, re-run the product import (Campaign Intelligence → Import existing data → Products) so products already in the Trash are picked up.

= 2.1.0-beta.6 =

* Changed: Smaily Campaign Intelligence now connects to its production address `https://intelligence.smaily.com` for new connections. Existing connections keep working unchanged — no settings change is required. To move an existing store onto the new address, reconnect using a fresh setup link from Smaily.

= 2.1.0-beta.5 =

* Renamed: the recommendation-engine feature is now called **Smaily Campaign Intelligence** throughout the admin (the Campaign Intelligence tab and setup step, the connection screen, health notices, and privacy data labels). This is a display-name change only — your data, connection and settings are unaffected, and no re-import is needed.

= 2.1.0-beta.4 =

* Fixed: deleting draft or never-published products (including the temporary "auto-draft" entries WordPress cleans up automatically) no longer creates failed catalog events in the Event Log. Such products were never sent to the recommendation engine, so there is nothing to remove.
* Fixed: the catalog import progress on multilingual stores now shows the true number of products synced (e.g. "1354 products synced") instead of a misleading fraction that compared products against the per-language post count.
* Fixed: the Event Log's Retry and Details buttons stay visible on narrow screens and on failed rows — they are no longer pushed off the right edge.

= 2.1.0-beta.3 =

* Improved: multilingual stores (WPML / Polylang) — a translated product is now a single product in recommendations instead of one per language, so recommendations no longer mix languages or repeat the same item. Each customer sees product names, descriptions and links in their own language.
* Improved: product variations on multilingual stores are linked across languages (with WooCommerce Multilingual).
* Improved: gift cards, donations and similar non-products are excluded from recommendations more reliably — the plugin now sends each product's type and virtual/downloadable status so the engine can classify them. (The plugin does not filter your products, so virtual or downloadable goods you genuinely sell are kept.)
* On multilingual stores, re-run the catalog import after upgrading (coordinate with Smaily for the clean re-sync).

= 2.1.0-beta.2 =

* Fixed: stores whose products have no SKUs now sync fully to the recommendation engine. Products without a SKU are keyed by a stable synthetic identifier (wc-<id>) across catalog, order and browse-tracking sync; previously such products were silently skipped and their orders failed to sync.
* Fixed: orders whose products were later permanently deleted from the store no longer fail repeatedly in the Event Log — they are skipped cleanly (WooCommerce removes the product reference on deletion, so those line items cannot be synced).
* Fixed: orders with no syncable line items (e.g. fee-only orders) are now skipped cleanly instead of repeatedly failing in the Event Log.
* Fixed: products sold out at variation level are now correctly marked out-of-stock for the recommendation engine (previously only parent-product stock changes were tracked).
* Fixed: product attribute values now sync as readable labels instead of internal IDs, enabling brand- and attribute-based recommendation rules. Re-run the catalog import after upgrading to refresh existing data.
* Fixed: admin health notices now appear at the top of the plugin's Settings and wizard pages (full width, like on other admin pages) instead of overlapping the page header.
* Fixed: abandoned cart reminders are only sent for recently abandoned carts (24 hours by default) — older carts are expired silently, so re-enabling the feature after a pause can never mass-email historical carts. A failure to send one reminder no longer blocks the rest.

= 2.1.0-beta.1 =

Major update, built alongside the existing 1.x feature set (existing installs upgrade in place; legacy behaviour continues until the new setup wizard is completed):

* New guided setup wizard and a redesigned, mobile-friendly admin interface.
* WooCommerce ↔ Smaily recommendation-engine integration: product catalog, customer and order sync with durable idempotent queues, plus one-click import (backfill) of existing store data.
* Optional storefront browse tracking (off by default, cookie-consent-gated) to improve recommendations.
* Event Log: every sync event visible in the admin, with per-row and bulk retry for failures.
* Proactive health notices when the Smaily API or the recommendation engine is unreachable, or failures accumulate.
* Privacy: WordPress personal-data export/erasure cover recommendation-engine data; shoppers can opt out of recommendation profiling from My Account; stored API credentials re-encrypted with authenticated encryption (AES-256-GCM).
* Multilingual-aware subscriber routing (Polylang, WPML, TranslatePress).

= 1.6.1 =

Fixed an issue where the discounted price was not correctly calculated in the RSS feed when using the tax rate parameter. The discounted price is now calculated correctly regardless of the tax rate used in the feed.

Unified the discount percentage display value in the RSS feed. The discount percentage is now displayed at most with one decimal place and without trailing zeros. For example, a discount of 10% will be displayed as "10%" instead of "10.0%". A discount of 10.5% will be displayed as "10.5%" instead of "10.50%". This change improves the readability of the discount percentage for the imported products in Smaily templates.

= 1.6.0 =

Added support for adding a tax rate to the RSS-feed product prices. This allows to change the tax rate used in the feed to match the tax rate used in Smaily email templates. This is especially useful for stores that want to target customers in different regions with different tax rates in their email campaigns.

Smaily Elementor widget now supports adding custom hidden fields to the subscription form. This allows to set custom fields for subscribers added via the Elementor widget allowing to better segment the subscribers in Smaily.

= 1.5.1 =

Added a label to the hidden fields section in the Smaily subscription block settings for better clarity.

= 1.5.0 =

You can now customize the hidden fields on the Smaily subscription block form. This allows to set custom fields for subscribers added via the block form allowing to better segment the subscribers in Smaily.

= 1.4.3 =

Added more hooks where the Smaily abandoned cart record is deleted. The current approach might on some occasions leave abandoned cart records lingering around even when the user has made a purchase.

= 1.4.2 =
Fixes an issue where Elementor integration assets were being excluded from the plugin package. This caused the Elementor widget styles to be missing after installation. The packaging patterns have been updated to ensure all necessary assets are included.

= 1.4.1 =
Fixes an issue where the abandoned cart cutoff time minimum value was 30 minutes instead of 10 minutes as intended. The minimum cutoff time has been corrected to 10 minutes, allowing users to set a lower threshold for considering carts as abandoned.

= 1.4.0 =

Improved RSS-feed items to show prices including taxes. Also added support for Discount Rules for WooCommerce plugin to correctly show discounted prices in the feed and in the abandoned cart reminders.

= 1.3.3 =

Improved the Elementor widget performance by reducing the number of API calls made during the rendering process.

= 1.3.2 =

Fixed a bug where the RSS-feed `pubDate` was not correctly formatted, which could lead to issues while importing sorted products into Smaily templates. Now the `pubDate` is formatted according to the RFC 822 standard, ensuring compatibility with RSS parsers.

= 1.3.1 =

- Improved the admin notice when the Smaily API credentials are invalid. Now the notice is rendered closer to the credentials input fields for better visibility.
- Improved autoresponder listing function validation to handle edge cases and ensure robust performance.

= 1.3.0 =

Improved the Contact Form 7 integration by allowing user to configure each form individually.

= 1.2.4 =

Render admin notices outside the form element to ensure proper display and avoid potential conflicts with form submission.

= 1.2.3 =

Load the plugin text domain in the `init` action. This complies with the WordPress 6.7+ plugin development standards and ensures that the plugin translations are loaded correctly.

= 1.2.2 =

Fixed RSS feed product query by removing random ordering. The combination of random ordering and query limits could result in empty product feeds on subsequent requests, causing RSS parser failures.

= 1.2.1 =

Fixed a bug where abandoned cart reminder emails were not sent due to a syntax error in the query statement building process.

= 1.2.0 =

Added a new block component for embedding Smaily Landing Pages.

= 1.1.0 =

Introduced a new Elementor widget that makes it easy to add a Smaily subscription form when building pages with Elementor.


= 1.0.0 =
* Combined Smaily for Contact Form 7, Smaily for WP, and Smaily for WooCommerce into a single plugin for a streamlined experience.

== Upgrade Notice ==

= 3.5.0 =
The contact backfill progress now reports users checked and contacts synced as separate numbers, matching your contact sync mode — no data-flow change, only honest reporting. Safe in-place update.

= 3.4.3 =
Critical fix: saving WooCommerce settings could crash the abandoned-cart email task on PHP 8 every 15 minutes (and turning the feature off did not stop it). Update immediately if you use abandoned-cart reminders; affected sites are healed automatically.

= 3.4.2 =
Reliability fixes for stores upgraded from an older plugin version: abandoned-cart crash loop fixed, leftover legacy schedules cleaned up on update, abandoned-cart contact-language handling corrected. Recommended for all stores.

= 3.4.1 =
Settings-screen fixes for the engine-run automations section (language rows, cooldown input, test-address warning, clearer error messages, English recipes). UI-only; safe in-place update.

= 3.4.0 =
Adds the engine-run recommendation automations settings section (visible when Smaily Campaign Intelligence is connected). Fail-closed: nothing is enabled until you configure it; safe in-place update.

= 3.3.2 =
Browse tracking uses the standard WordPress Consent API; if no consent signal is present you'll now be told to install the free WP Consent API plugin. Recommended for any store using browse tracking with a cookie banner.

= 3.3.1 =
Superseded by 3.3.2.

= 3.3.0 =
Browse events now carry an anonymous visitor token for future personalization. No change to purchase attribution or store behavior; safe in-place update.

= 3.2.1 =
Minor UI refinement to the contact-sync mode selector. No functional or data change; safe in-place update.

= 3.2.0 =
Fixes recommendation attribution on the WooCommerce Block checkout and adds contact-sync modes. Recommended especially for stores using the block checkout.

= 3.1.0 =
Recommendation attribution: rec-link clicks are now captured server-side and attached to orders, so the engine can credit purchases to email recommendations. No re-import required.

= 3.0.1 =
Translation update: the admin interface is now fully translatable and ships a complete Estonian translation. No functional change; no re-import required.

== Screenshots ==

1. Smaily Connect Admin View
2. Getting Started
3. Subscriber Synchronization
4. Abandoned Cart Reminder Emails
5. Import Products To Templates From RSS-Feed
6. Opt-In Form Block
7. Integrate With Contact Form 7
8. Smaily Elementor Opt-In Form
