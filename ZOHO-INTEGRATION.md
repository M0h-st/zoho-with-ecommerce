# WooCommerce → Zoho CRM Integration

## Integration tools

- **Zoho Deluge** — automation logic inside Zoho CRM
- **WooCommerce REST API** — read new orders
- **Zoho CRM API** — create/update Contacts and Deals

---

## Architecture (recommended)

```
Customer places order (WooCommerce)
              ↓
WooCommerce REST API  GET /wp-json/wc/v3/orders
              ↓
Deluge function (scheduled in Zoho CRM)
              ↓
Zoho CRM API
   ├── Contact upsert (by email)
   └── Deal create (order total + products)
              ↓
Verify in CRM → Contacts + Deals
```

| Step | System | Action |
|------|--------|--------|
| 1 | WooCommerce | Customer checkout |
| 2 | REST API | Deluge fetches order (name, email, products, total) |
| 3 | CRM API | Upsert Contact, create Deal |
| 4 | Zoho CRM UI | Confirm Contact and Deal exist |

Implementation: [`deluge/sync-woocommerce-orders.deluge`](deluge/sync-woocommerce-orders.deluge)

Setup guide: [`deluge/README.md`](deluge/README.md)

---

## Part A — WooCommerce REST API

### Create API keys

1. WP Admin → **WooCommerce → Settings → Advanced → REST API**
2. **Add key**
   - Description: `Zoho CRM Sync`
   - User: admin
   - Permissions: **Read**
3. Copy **Consumer key** and **Consumer secret**

### Test endpoint

```bash
curl "https://YOUR-STORE.com/wp-json/wc/v3/orders?status=processing&per_page=1" \
  -u "ck_XXXX:cs_XXXX"
```

---

## Part B — Zoho Deluge (OAuth 1.0a)

WooCommerce REST API uses **OAuth 1.0a one-legged** signing. The Deluge script signs each request with your consumer key/secret — no Zoho Connection needed.

### Deluge function

1. **Setup → Developer Space → Functions → + Function**
2. Paste [`deluge/sync-woocommerce-orders.deluge`](deluge/sync-woocommerce-orders.deluge)
3. Set at top of script:
   - `store_url`
   - `consumer_key`
   - `consumer_secret`
   - `wc_status`
   - `deal_stage`
4. Save and **Execute** to test

### Schedule

- **Setup → Automation** → Workflow or Schedule
- Run `Sync WooCommerce Orders` every 15 minutes (or after each test order manually)

---

## Part C — Verify in Zoho CRM

1. Log in to [Zoho CRM](https://crm.zoho.com) (or your regional URL)
2. **Contacts** — find customer by billing email
3. **Deals** — find `WooCommerce Order #123`
4. Confirm **Amount** and product **Description** match the order

---

## Data mapped

| WooCommerce | Zoho Lead / Contact | Zoho Deal |
|-------------|---------------------|-----------|
| Billing name | First_Name, Last_Name | — |
| Billing email | Email | — |
| Billing phone | Phone | — |
| Billing address | Street / Mailing_* | — |
| Order ref | Description (Lead) | Deal_Name `WC_Order_{id}` |
| Line items | — | Description |
| Order total | — | Amount |

---

## Optional — WordPress plugin

The repo also includes a WordPress plugin (`woocommerce-zoho-crm/`) that syncs on order status via PHP + OAuth. Use this if you prefer server-side hooks instead of Deluge polling.

**WooCommerce → Zoho CRM** in WP Admin for plugin OAuth setup.

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Empty orders list | Check API key permissions and order status filter |
| Deal stage error | Set `deal_stage` to a valid pipeline stage name |
| Duplicate deals | Script skips orders when Deal name already exists |

---

## Deployment checklist

- [ ] WooCommerce REST API key created (Read)
- [ ] Zoho Connection configured and linked in Deluge
- [ ] Deluge function saved and tested
- [ ] Schedule or manual run verified
- [ ] Test order appears in Contacts + Deals
