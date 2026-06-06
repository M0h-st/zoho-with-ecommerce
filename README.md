# WooCommerce → Zoho CRM Integration

Sync customer orders from WooCommerce to **Zoho CRM Free** as **Contacts** and **Deals**.

**Author:** Mohanad Abdellah

**Repository:** [github.com/M0h-st/zoho-with-ecommerce](https://github.com/M0h-st/zoho-with-ecommerce)

## Integration tools

| Tool | Purpose |
|------|---------|
| **Zoho Deluge** | Automation script inside Zoho CRM |
| **WooCommerce REST API** | Read new orders (`/wp-json/wc/v3/orders`) |
| **Zoho CRM API** | Create/update **Contacts** and **Deals** |

```
WooCommerce store                    Zoho CRM
      │                                   │
      │  REST API (orders)                │
      └──────────────► Deluge function ───► Contacts
                                         Deals
```

**Recommended:** [`deluge/`](deluge/) — Deluge + REST API + CRM API (matches standard integration spec).

**Optional:** WordPress plugin in `app/public/wp-content/plugins/woocommerce-zoho-crm/` — syncs from PHP on order status change.

## Repository contents

| Path | Description |
|------|-------------|
| [`deluge/`](deluge/) | **Deluge script** + setup guide |
| `app/public/wp-content/plugins/woocommerce-zoho-crm/` | Optional WordPress plugin |
| `integration/sync-latest-order.php` | Optional CLI sync (plugin-based) |
| [`ZOHO-INTEGRATION.md`](ZOHO-INTEGRATION.md) | Full setup documentation |

## Quick start (Deluge)

1. Create WooCommerce REST API keys (**WooCommerce → Settings → Advanced → REST API**)
2. Add a Zoho **Connection** to your store (Basic Auth with API keys)
3. Copy [`deluge/sync-woocommerce-orders.deluge`](deluge/sync-woocommerce-orders.deluge) into a Zoho CRM Function
4. Schedule the function (e.g. every 15 minutes)
5. Place a test order → verify **Contacts** and **Deals** in Zoho CRM

Details: [`deluge/README.md`](deluge/README.md)

## Requirements

- WordPress + WooCommerce store
- Zoho CRM Free (or higher)
- WooCommerce REST API keys (Read)
- Zoho CRM Deluge function + Connection

## Security

- Do not commit API keys, client secrets, or refresh tokens
- Store WooCommerce keys in Zoho Connections only
- Use HTTPS on production stores

## License

GPL-2.0-or-later — see [LICENSE](LICENSE). Copyright © 2026 Mohanad Abdellah.
