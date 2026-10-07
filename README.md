# WPCalibrate Product Catalog Connector

[![WordPress Compatibility](https://img.shields.io/badge/WordPress-6.4%2B-blue.svg)](https://wordpress.org)
[![WooCommerce Compatibility](https://img.shields.io/badge/WooCommerce-8.5%2B%20%7C%20HPOS-96588a.svg)](https://woocommerce.com)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%20%7C%208.3-777bb4.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-green.svg)](license.txt)
[![Latest Release](https://img.shields.io/badge/Version-1.0.2-orange.svg)](https://github.com/zeeshanraza-official/wpcalibrate-product-catalog-connector/releases)

**WPCalibrate Product Catalog Connector** is an enterprise-grade WooCommerce plugin that synchronizes your store inventory with **Pinterest Business Shopping Catalogs**. Designed for high-volume stores (up to 50,000+ items), it eliminates gateway timeouts and memory exhaustion through deterministic chunked background streaming and atomic cache replacement.

---

## Table of Contents
1. [About](#about)
2. [Key Features](#key-features)
3. [Architecture & Synchronization Method](#architecture--synchronization-method)
4. [System Requirements](#system-requirements)
5. [Installation](#installation)
6. [5-Step Setup Workflow](#5-step-setup-workflow)
7. [Developer Documentation & Hooks](#developer-documentation--hooks)
8. [Changelog](#changelog)
9. [Support & Commercial Licensing](#support--commercial-licensing)
10. [License](#license)

---

## About

Pinterest is one of the world's highest-converting discovery and social commerce engines. To showcase your products as **Rich Product Pins**, qualify for the **Pinterest Verified Merchant Program**, and run **Pinterest Shopping Ads** or **Dynamic Retargeting**, your store requires a continuously updated, fully compliant retail catalog feed.

Traditional catalog export plugins often query all store products in a single HTTP request, causing 504 Gateway Timeouts, PHP memory exhaustion, and server crashes on larger stores.

**WPCalibrate Product Catalog Connector** solves this problem by using WooCommerce's **Action Scheduler** queue to stream product feeds in deterministic background batches, outputting strictly compliant RFC 4180 CSV or TSV feeds protected by cryptographic access tokens.

---

## Key Features

### 🛍️ Pinterest Retail Integration
* **Shoppable Product Pins:** Automatically transforms WooCommerce products and variable product variations into Pinterest Product Pins with real-time pricing and stock status.
* **Shopping Ads & Retargeting Ready:** Generates compliant feeds formatted specifically for Pinterest Business Catalogs and dynamic product ads.
* **Dual Integration Modes:**
  * **Hosted Feed Only (Recommended):** Zero-configuration static hosted feed URL ready to paste into Pinterest Business Hub.
  * **Pinterest API + Feed:** Direct OAuth 2.0 integration with Pinterest API v5 to register feeds and inspect processing status directly from WordPress.

### ⚡ Enterprise Scalability & Performance
* **Chunked Batch Processing:** Processes products in configurable background batches (default 250 items/batch) via Action Scheduler. Never blocks frontend shoppers or checkout.
* **Atomic File Publication:** Feeds are compiled into an isolated `.tmp` file and only swapped into production after 100% successful generation and header validation. Pinterest never encounters partial or corrupted feeds.
* **Smart Dirty-State Invalidation:** Debounces product edits and stock status transitions (10-minute cooldown) to prevent excessive feed regenerations during bulk catalog updates.
* **Direct Streaming Endpoint:** Fast streaming via `wp-load.php` with HTTP `304 Not Modified` and `ETag` caching support.

### 🎯 Granular Product Filtering & Rules
* **Product Type Selection:** Include or exclude simple products, variable parents, and individual variations.
* **Stock & Availability Rules:** Exclude out-of-stock items, manage backorder mappings, and filter private or draft products.
* **Category Filtering:** Include or exclude products by specific WooCommerce product categories.
* **Price Floors:** Filter out products below a minimum price threshold.

### 🏷️ Taxonomy & Field Mapping
* **Google Product Category:** Map global taxonomy codes or names for accurate Pinterest product categorization.
* **Brand & Manufacturer Mapping:** Extract brand identifiers from custom taxonomies, product attributes, or custom meta fields.
* **GTIN / MPN Identifiers:** Map barcodes, UPC, EAN, or manufacturer part numbers.
* **Custom Labels 0–4:** Map promotion tags, seasonal identifiers, or margin buckets to custom labels for Pinterest ad campaign segmentation.

### 🛡️ Security & Privacy
* **Cryptographic Token Protection:** Feed URLs are shielded with a 64-character unguessable hash.
* **Instant Token Rotation:** Rotate feed URLs with a single click if compromised.
* **Secret Scrubbing:** Sensitive API credentials, tokens, and authorization codes are redacted from logs and diagnostic reports.

---

## Architecture & Synchronization Method

```
WooCommerce Catalog
       │
       ▼
Product Query (Batched) ──► Action Scheduler Queue (250 items/batch)
                                      │
                                      ▼
                      Eligibility & Field Mapping Engine
                                      │
                                      ▼
                          Streaming Writer (CSV / TSV)
                                      │
                                      ▼
                             Temporary File (.tmp)
                                      │
                                      ▼
                         Pre-publication Validation
                                      │
                       ┌──────────────┴──────────────┐
                    [PASS]                         [FAIL]
                       │                              │
                       ▼                              ▼
                 Atomic Rename               Retain Previous Feed
              (feed-xxxxxxxx.csv)             & Log Diagnostics
                       │
                       ▼
        Secure HTTPS Tokenized Endpoint
                       │
                       ▼
             Pinterest Catalog Bot
```

---

## System Requirements

| Requirement | Minimum | Recommended |
|---|---|---|
| **WordPress** | 6.4 | 6.7+ |
| **WooCommerce** | 8.5 | 9.0+ / 10.0+ |
| **PHP** | 8.2 | 8.3+ |
| **HPOS** | Compatible | Enabled |
| **Action Scheduler** | Bundled in WC | Active |

---

## Installation

### Method 1: WordPress Dashboard (ZIP Upload)
1. Download `wpcalibrate-product-catalog-connector-1.0.2.zip` from the [Releases](https://github.com/zeeshanraza-official/wpcalibrate-product-catalog-connector/releases) page.
2. Log in to your WordPress admin dashboard and navigate to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the ZIP file and click **Install Now**.
4. Click **Activate Plugin**.

### Method 2: Manual Installation via FTP
1. Extract `wpcalibrate-product-catalog-connector-1.0.2.zip`.
2. Upload the `wpcalibrate-product-catalog-connector/` directory to `/wp-content/plugins/` on your server.
3. In WordPress admin, navigate to **Plugins** and activate **WPCalibrate Product Catalog Connector**.

---

## 5-Step Setup Workflow

Navigate to **WPCalibrate → Catalog Connector** in your WordPress administration menu:

1. **Step 1: Store & Regional Settings (Catalog Tab)**
   * Select feed format: **CSV** (recommended) or **TSV**.
   * Specify two-letter ISO target country (e.g. `US`, `GB`, `CA`, `AU`).
   * Enter default locale (e.g. `en_US`, `en_GB`) and three-letter currency code (e.g. `USD`, `GBP`).
2. **Step 2: Product Filter Rules (Product Rules Tab)**
   * Choose product types to export (simple products, variations).
   * Configure out-of-stock exclusion and minimum price thresholds.
3. **Step 3: Field & Category Mapping (Field Mapping Tab)**
   * Define default Google Product Category and map brand attribute.
   * Configure custom labels 0 to 4 for Pinterest ad segmentation.
4. **Step 4: Regenerate & Test Feed (Sync & Diagnostics Tabs)**
   * Click **Regenerate Feed Now** to initiate background compilation.
   * View the 10-row preview table and system diagnostic checks in the **Diagnostics** tab.
5. **Step 5: Connect with Pinterest Business**
   * Copy your **Hosted Pinterest Retail Feed URL** from the **Overview** tab.
   * In **Pinterest Business Hub**, go to **Catalogs → Data Sources → Add Data Source**.
   * Paste your feed URL and set Pinterest's fetch schedule (e.g., daily).

---

## Developer Documentation & Hooks

### Filters

#### Modify Product Feed Row Data
```php
add_filter( 'wpcal_pcc_product_row', function( array $row, WC_Product $product ): array {
    // Customize or override Pinterest catalog attributes
    if ( $product->get_sku() ) {
        $row['mpn'] = $product->get_sku();
    }
    return $row;
}, 10, 2 );
```

#### Adjust Background Batch Size
```php
add_filter( 'wpcal_pcc_batch_size', function( int $batch_size ): int {
    // Lower batch size on restricted memory servers or increase on dedicated hardware
    return 150;
} );
```

#### Custom Availability Value Mapping
```php
add_filter( 'wpcal_pcc_availability', function( string $status, WC_Product $product ): string {
    // Return 'in_stock', 'out_of_stock', or 'preorder'
    return $status;
}, 10, 2 );
```

---

## Changelog

### [1.0.2] - 2026-10-07
* **Fixed:** Admin sidebar menu logo rendering size. Cropped transparent image padding and constrained dimensions to a crisp 20x20px with `box-sizing: content-box` matching core dashboard icons.
* **Fixed:** Redesigned "How to Use: 5-Step Setup Workflow" with an interactive progress pipeline bar, resilient 5-card responsive grid, and self-contained inline style fallbacks.
* **Added:** Dynamic `filemtime` asset cache busting in `Assets.php` ensuring client browsers immediately fetch updated CSS/JS assets.

### [1.0.1] - 2026-10-07
* **Fixed:** Admin sidebar logo display size and added strict dimension constraints via `admin_head` hook.
* **Fixed:** Support tab layout blow-out on "Marketplace & Licenses" card on medium viewports.
* **Fixed:** PHP 8 named argument unpacking fatal error in Action Scheduler / WP-Cron batch callbacks.
* **Fixed:** Safe base location extraction guarding `wc_get_base_location()` return type.
* **Added:** Comprehensive Overview tab featuring Plugin Purpose, 5-Step Guided Setup Workflow, Architecture & Synchronization Method, and Module Navigation Hub.
* **Added:** Automated FTP deployment script and packaging tool with POSIX path verification.

### [1.0.0] - 2026-10-07
* Initial production release.
* RFC 4180 compliant CSV and TSV streaming feed writers.
* Action Scheduler background batch processor with atomic file replacement.
* Concurrency locking and 64-character cryptographic token security.
* Pinterest API v5 client abstraction with OAuth 2.0 flow.
* Full unit, integration, and mock API test suites (27/27 passing).

---

## Support & Commercial Licensing

* **Official Website:** [https://wpcalibrate.com](https://wpcalibrate.com)
* **Marketplace & License Portal:** [https://marketplace.wpcalibrate.com](https://marketplace.wpcalibrate.com)
* **Email Support:** [support@wpcalibrate.com](mailto:support@wpcalibrate.com)
* **WhatsApp Assistance:** [+447474795976](https://wa.me/447474795976)

---

## License

WPCalibrate Product Catalog Connector is licensed under the [GNU General Public License v2.0 or later](license.txt).
