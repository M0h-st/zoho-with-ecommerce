=== WooCommerce Zoho CRM Integration ===
Contributors: mohanadabdellah
Tags: woocommerce, zoho, crm, integration, oauth
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WooCommerce orders to Zoho CRM as Contacts and Deals (or Leads).

== Description ==

Automatically sends customer order data from WooCommerce to Zoho CRM:

* Upsert **Contact** by billing email
* Create **Deal** or **Lead** with order total and products
* OAuth 2.0 connect flow from WooCommerce admin
* Manual sync per order and CLI helper script

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/woocommerce-zoho-crm/`
2. Activate through the Plugins menu
3. Go to **WooCommerce → Zoho CRM** and complete OAuth setup

== Frequently Asked Questions ==

= Where are credentials stored? =

In WordPress options after OAuth. Do not commit secrets to version control.

= Which Zoho scopes are required? =

ZohoCRM.modules.ALL (requested during Connect).

== Changelog ==

= 1.1.5 =
* Fix API connection test scope error
* Multi-DC OAuth token exchange
* Save & Connect flow fixes

= 1.0.0 =
* Initial release
