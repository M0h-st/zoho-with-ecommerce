# WooCommerce → Zoho CRM Integration

WordPress plugin that syncs WooCommerce orders to **Zoho CRM Free** as Contacts and Deals (or Leads).

**Author:** Mohanad Abdellah

**Repository:** [github.com/M0h-st/zoho-with-ecommerce](https://github.com/M0h-st/zoho-with-ecommerce)

## Repository contents

| Path | Description |
|------|-------------|
| `app/public/wp-content/plugins/woocommerce-zoho-crm/` | WordPress plugin source |
| `integration/sync-latest-order.php` | CLI script to sync the latest order |
| `ZOHO-INTEGRATION.md` | Setup and OAuth guide |

This repo tracks **only the integration plugin** — not WordPress core, WooCommerce, themes, or uploads.

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+
- Zoho CRM account (Free tier supported)
- Zoho API Console **Server-based Application**

## Installation

1. Copy the plugin folder into your WordPress site:

   ```bash
   cp -R app/public/wp-content/plugins/woocommerce-zoho-crm /path/to/wordpress/wp-content/plugins/
   ```

2. Activate **WooCommerce Zoho CRM Integration** in WP Admin → Plugins.

3. Follow [ZOHO-INTEGRATION.md](ZOHO-INTEGRATION.md) to connect Zoho via OAuth.

## Configuration

**WooCommerce → Zoho CRM**

1. Register the **Redirect URI** shown in the plugin settings in [Zoho API Console](https://api-console.zoho.com/).
2. Enter **Client ID** and **Client Secret**.
3. Click **Save & Connect to Zoho CRM**.
4. Enable the integration and place a test order.

## Verify sync

- WP Admin → order screen → **Zoho CRM** meta box
- **WooCommerce → Zoho CRM → Sync latest order**
- CLI (from a full Local/site checkout that includes WordPress):

  ```bash
  php integration/sync-latest-order.php
  ```

- Zoho CRM → **Contacts** and **Deals**

## Security

- **Do not commit** Zoho Client Secret, Refresh Token, or `local-config.php`.
- Credentials are stored in the WordPress database (`wp_options`) after OAuth.
- Use `local-config.example.php` as a local dev template only (copy to `local-config.php`, gitignored).

## License

GPL-2.0-or-later — see [LICENSE](LICENSE). Copyright © 2026 Mohanad Abdellah.
