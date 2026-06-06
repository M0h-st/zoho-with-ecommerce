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
GET /wp-json/wc/v3/orders  ← WooCommerce REST API
       ↓
For each order:
  ├── Search/update Contact by email
  └── Create Deal (skip if already synced)
```

## 1. WooCommerce REST API keys

1. WP Admin → **WooCommerce → Settings → Advanced → REST API**
2. **Add key** — Read permission
3. Copy **Consumer key** and **Consumer secret**

## 2. Zoho Connection (WooCommerce)

1. Zoho CRM → **Setup → Developer Space → Connections**
2. **Add Connection** → **Custom Service**
3. Name: `woocommerce_rest_api` (must match script)
4. Authentication: **Basic Auth**
   - Username = Consumer key
   - Password = Consumer secret
5. Base URL = your store URL (e.g. `https://yourstore.com`)

## 3. Install Deluge function

1. Zoho CRM → **Setup → Developer Space → Functions**
2. **+ Function** → name: `Sync WooCommerce Orders`
3. Paste code from [`sync-woocommerce-orders.deluge`](sync-woocommerce-orders.deluge)
4. Edit top of script:
   - `store_url`
   - `wc_status` (`processing` or `completed`)
   - `deal_stage` (must exist in your CRM pipeline)
   - `connection_name`

## 4. Schedule it

1. **Setup → Automation → Workflow Rules** (or **Schedules**)
2. Trigger: every 15 minutes (or on demand)
3. Action: run function `Sync WooCommerce Orders`

## 5. Test

1. Place a test order on WooCommerce (COD is fine)
2. Run the function manually or wait for schedule
3. Check Zoho CRM → **Contacts** and **Deals**

## Verify in Zoho CRM

- **Contacts** — customer email from order billing
- **Deals** — title `WooCommerce Order #123`, amount = order total

## Notes

- Deluge uses built-in `zoho.crm.*` — no separate CRM OAuth in the script
- Duplicate orders skipped by Deal name
- Contacts updated when email already exists
- For local stores, use your Local URL and ensure Zoho can reach it (tunnel/ngrok for cloud Zoho)
