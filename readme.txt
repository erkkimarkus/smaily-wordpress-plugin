=== Smaily Connect ===
Contributors: sendsmaily, kaarel
Tags: smaily, newsletter, email, mail, marketing
Requires PHP: 8.0
Requires at least: 6.6
Tested up to: 7.0
WC requires at least: 6.9
WC tested up to: 10.7
Stable tag: 2.1.0-beta.8
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

Existing 1.x installs upgrade in place: legacy behaviour continues unchanged until you complete the new setup wizard.

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

= 2.1.0-beta.8 =
Recommended update if you use Campaign Intelligence browse tracking: the storefront tracking script and its endpoint were renamed so common ad/tracking blockers no longer block them by name, so browse events are recorded for more visitors. No settings change; consent rules are unchanged.

= 2.1.0-beta.7 =
Recommended update: previously-ordered products you move to the Trash are now kept in Campaign Intelligence as out-of-stock so the engine keeps learning from them (rather than disappearing). Re-run the product import after updating (Campaign Intelligence → Import existing data → Products) to pick up products already in the Trash.

= 2.1.0-beta.6 =
Safe update: new Campaign Intelligence connections use the production address https://intelligence.smaily.com. Existing connections are unaffected and keep working — no settings change and no re-import required. To move an existing store onto the new address, reconnect with a fresh setup link from Smaily.

= 2.1.0-beta.5 =
Naming update only: the recommendation engine is now called Smaily Campaign Intelligence in the plugin interface. No data, connection or settings changes; no re-import required.

= 2.1.0-beta.4 =
Recommended maintenance update: stops failed catalog events for draft/auto-draft products in the Event Log, shows the true synced-product count during catalog import on multilingual stores, and keeps the Event Log's Retry/Details actions reachable. No re-import required.

= 2.1.0-beta.3 =
Recommended for multilingual stores (WPML / Polylang): translated products are now merged into one recommendation per product — no more language-mixed or duplicated recommendations — with per-language names, descriptions and links. After upgrading, re-run the catalog import; coordinate with Smaily, as the recommendation engine resets the product graph for a clean canonical re-sync.

= 2.1.0-beta.2 =
Recommended for all stores, essential for stores whose products have no SKUs: product, order and browse sync to the recommendation engine now works without SKUs. After upgrading, re-run the catalog import and use "Retry all failed" in the Event Log to heal previously failed orders.

= 2.1.0-beta.1 =
Major update. Existing settings and behaviour are preserved — legacy subscriber sync continues unchanged until you complete the new setup wizard. The recommendation-engine features are optional and stay off until you connect them. Stored API credentials are automatically re-encrypted with a stronger scheme on upgrade.

= 1.0.0 =
If upgrading from individual Smaily plugins to the combined version, please review your settings to ensure all integrations are correctly configured.

== Screenshots ==

1. Smaily Connect Admin View
2. Getting Started
3. Subscriber Synchronization
4. Abandoned Cart Reminder Emails
5. Import Products To Templates From RSS-Feed
6. Opt-In Form Block
7. Integrate With Contact Form 7
8. Smaily Elementor Opt-In Form
