# Food Customizer

Custom WooCommerce food-ordering plugin for **Staria Chinar** (delivery). Self-contained — all logic lives in this plugin; no theme or WooCommerce core edits, no custom database tables (uses post meta + `wp_options`).

## Requirements
- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+

## Features
- **Single-page shop** (`[fc_shop]` shortcode): category tabs + AJAX product grid, product cards with dual-currency price and a quantity stepper.
- **Category tabs control** (admin): show/hide tabs, show/hide the "All" tab, set a default category, hide specific categories.
- **Time-scheduled categories**: per-category availability windows (e.g. lunch 11:30–15:30). Outside the window the tab is hidden **and** its products are not purchasable. Keeps the shop page uncached while schedules are active.
- **Custom product page**: dark, product-image-led layout — single-column stacked on mobile/tablet, two-column on desktop. Ingredients, size/variant pickers, removable ingredients, paid add-ons, EU-14 allergens, and a "combine with" (cross-sell) row.
- **Dual currency**: EUR base price with informational BGN (fixed rate 1 EUR = 1.95583 BGN), toggleable.
- **Checkout extras**: cutlery & napkins toggle (real hidden product), delivery zones with per-zone "busy" toggle + ETA, delivery-to-door / to-entrance, ASAP / scheduled time, minimum-order enforcement (with optional hard checkout gate).
- **Floating mini-cart** with live item count + total (cart fragments).
- **Admin settings page** organised into tabs (Colours, Borders, Texts, Menu, General): every colour, border, radius and label is editable; texts follow the WordPress site language (translatable — Bulgarian `.mo` included).

## Structure
```
food-customizer.php          Bootstrap (defines constants, boots classes on plugins_loaded)
includes/                    PHP classes (one concern each)
assets/css, assets/js        Front-end + admin styling and behaviour
templates/                   Custom single-product content template
languages/                   Translations (bg_BG)
```

## Install
Copy this folder to `wp-content/plugins/food-customizer/` and activate. Configure under **WooCommerce → Food Customizer**.

> Note: products, settings, and pages are stored in the WordPress database — migrating the plugin alone carries the code, not the content.
