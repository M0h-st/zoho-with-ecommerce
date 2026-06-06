# Deluge Integration (Zoho CRM)

Primary integration using **Zoho Deluge**, **WooCommerce REST API**, and **Zoho CRM API**.

## Integration tools

| Tool | Role |
|------|------|
| **Zoho Deluge** | Orchestrates sync (scheduled function or workflow) |
| **WooCommerce REST API** | Reads new/processing orders |
| **Zoho CRM API** | Creates/updates **Contacts** and **Deals** |

## Flow

```
Schedule (Zoho CRM)
       ↓
Deluge function
       ↓
GET /wp-json/wc/v3/orders  ← WooCommerce REST API (OAuth 1.0a signed)
       ↓
For each order:
  ├── Search/update Lead by email
  ├── Search/update Contact by email
  └── Create Deal (skip if already synced)
```

## 1. WooCommerce REST API keys

1. WP Admin → **WooCommerce → Settings → Advanced → REST API**
2. **Add key** — Read permission
3. Copy **Consumer key** and **Consumer secret**

## 2. Configure API credentials in the script

WooCommerce REST API uses **OAuth 1.0a one-legged** signing (not OAuth 2.0 — WC has no OAuth2 token endpoint for API keys).

Edit inside the function body:

```deluge
store_url = "https://your-store.com";
consumer_key = "ck_xxxxxxxx";
consumer_secret = "cs_xxxxxxxx";
wc_status = "processing";
deal_stage = "Qualification";
```

Timestamp is generated in **GMT unix seconds** via `unixEpoch("GMT")`.

## 3. Install Deluge function

1. Zoho CRM → **Setup → Developer Space → Functions**
2. **+ Function** → Category: **Standalone**
3. Function name: `sync_wc_orders` (no spaces — not `Sync WooCommerce Orders`)
4. Return type: **string**
5. Paste code from [`sync-woocommerce-orders.deluge`](sync-woocommerce-orders.deluge)
6. Wrapper must be: `string standalone.sync_wc_orders() { ... }`
7. Edit config variables inside the function body

## 4. Schedule it

1. **Setup → Automation → Workflow Rules** (or **Schedules**)
2. Trigger: every 15 minutes (or on demand)
3. Action: run function `Sync WooCommerce Orders`

## 5. Test

1. Place a test order on WooCommerce (COD is fine)
2. Run the function manually or wait for schedule
3. Check Zoho CRM → **Contacts** and **Deals**

## Verify in Zoho CRM

- **Leads** — customer email, Lead Source = WooCommerce
- **Contacts** — customer email from order billing
- **Deals** — title `WC_Order_123`, amount = order total

## Notes

- Deluge uses built-in `zoho.crm.*` — no separate CRM OAuth in the script
- OAuth 1.0a HMAC-SHA1 signing (GMT timestamp via unixEpoch)
- Duplicate orders skipped by Deal name
- Leads and Contacts updated when email already exists
