=== WPCalibrate Product Catalog Connector ===
Contributors: wpcalibrate
Tags: woocommerce, pinterest, catalog, product feed, ecommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
WC requires at least: 8.5
WC tested up to: 11.1
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronize WooCommerce product catalogs with Pinterest using dependable hosted feed URLs (CSV/TSV) and optional Pinterest API v5 integration.

== Description ==

**WPCalibrate Product Catalog Connector** enables WooCommerce store administrators to prepare, validate, and synchronize their product catalog with Pinterest.

The plugin provides two independent connection methods:
1. **Hosted Catalog Feed URL:** A high-performance, cached, and secure HTTPS feed endpoint (CSV or TSV) that Pinterest can fetch automatically on a regular schedule. This is the primary and most dependable connection layer.
2. **Pinterest API Connection:** An optional advanced integration using OAuth 2.0 and the current official Pinterest API v5 to register your hosted feed and retrieve remote processing diagnostics directly within WordPress.

*Important Note:* This plugin is an independent integration developed by WPCalibrate. It is not affiliated with, endorsed by, or sponsored by Pinterest.

### Key Features

* **Hosted Feed Generation:** Generates Pinterest-compatible retail feeds formatted in CSV or TSV adhering to RFC 4180 and official Pinterest catalog specifications.
* **Stable Product Identifiers:** Preserves stable IDs (`wc_prod_{id}`, `wc_var_{id}`, and shared `item_group_id`) across title, slug, and price changes.
* **Variable Product Support:** Exports product variations as separate items with parent grouping relationships, individual prices, images, and inventory.
* **Granular Product Rules:** Configurable inclusion and exclusion rules based on categories, stock status, product types, and image/price availability.
* **Scalable Batched Architecture:** Built for catalogs of all sizes using Action Scheduler queue processing, lock-protected concurrency control, and atomic file publication.
* **Feed Security:** Unguessable cryptographically random feed access tokens, one-click token rotation, and optional HTTP Basic Authentication.
* **Intelligent Synchronization:** Automated recurring schedules (12h, daily, twice weekly, weekly) combined with debounced dirty-state detection when products or stock levels update.
* **WooCommerce HPOS Compatible:** Full declared compatibility with WooCommerce High-Performance Order Storage (HPOS) and Cart/Checkout Blocks.
* **Privacy Conscious:** Zero tracking or telemetry. Only store catalog information is exported; customer, order, and personal data are never processed or sent.

== Privacy and Third-Party Services ==

This plugin facilitates data synchronization between your WooCommerce store and Pinterest:

1. **Feed Only Mode (Default):**
   * Exposes a protected HTTPS catalog URL containing only your store's public product metadata (titles, descriptions, prices, links, images, availability, categories).
   * Pinterest fetches this file directly from your server. No credentials or tracking scripts are exchanged.

2. **Pinterest API Mode (Optional):**
   * Used only if you explicitly configure Pinterest Developer credentials (App ID and App Secret) and click "Connect with Pinterest".
   * Authorizes your store via OAuth 2.0 to access Pinterest API v5 (`https://api.pinterest.com/v5`).
   * Transmits authentication requests, hosted feed location, and reads processing diagnostics.
   * Pinterest's service terms and privacy practices apply to data processed through their API: [Pinterest Privacy Policy](https://policy.pinterest.com/en/privacy-policy).

== Installation ==

1. Upload the `wpcalibrate-product-catalog-connector` folder to your `/wp-content/plugins/` directory, or install the ZIP file via the WordPress Plugins menu.
2. Activate the plugin through the 'Plugins' menu in WordPress. Ensure WooCommerce is active.
3. Navigate to **WPCalibrate > Catalog Connector** in your WordPress admin menu.
4. Review your catalog basics, product rules, and field mappings.
5. Copy your hosted feed URL and add it to your Pinterest Business Catalog manager, or connect via OAuth under the Pinterest tab.

== Frequently Asked Questions ==

= Does this plugin require Pinterest API credentials to work? =
No. The hosted feed URL works completely independently without Pinterest API credentials. Connecting the API is entirely optional.

= How are variations handled? =
Eligible variations are exported as individual feed rows with their specific attributes, prices, stock states, and images, linked together by their parent `item_group_id`.

= Will regenerating the feed slow down my website? =
No. Feed generation processes products in controlled background batches using Action Scheduler or non-blocking routines, keeping memory and CPU usage minimal.

= Is WooCommerce HPOS supported? =
Yes. The plugin is fully compatible with WooCommerce High-Performance Order Storage (HPOS) and does not depend on legacy order post storage.

== Changelog ==

= 1.0.1 =
* Fix: Resolved PHP 8 named argument unpacking fatal error in Action Scheduler / WP-Cron catalog sync callbacks.
* Fix: Prevented type and offset errors when extracting WooCommerce store base location.
* Fix: Made lifecycle hook callback signatures defensive against non-standard parameter counts.
* Fix: Added capability fallback for admin menus when WooCommerce is not yet active.
* Add: Added regression tests covering named arguments and hook callback safety.

= 1.0.0 =
* Initial public release.
* Hosted catalog feed engine (CSV and TSV) with streaming writers.
* Product eligibility rules and attribute mapping.
* Support for simple products and variable product variations.
* Action Scheduler batch processor and concurrency locking.
* Intelligent dirty-state tracking with debounced triggers.
* Pinterest API v5 OAuth integration and feed registration.
* System diagnostics, issue logging, and sync history.
* Full WooCommerce HPOS compatibility declaration.
