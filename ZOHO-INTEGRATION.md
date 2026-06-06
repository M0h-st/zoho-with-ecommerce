# WooCommerce → Zoho CRM Integration

Production-ready WordPress plugin that syncs WooCommerce orders to **Zoho CRM Free** as Contacts and Deals (or Leads).

## Architecture

```
Customer checkout
       ↓
WooCommerce order (processing/completed)
       ↓
Plugin hooks → Zoho CRM API v2
       ├── Contacts/upsert  (customer by email)
       └── Deals or Leads   (order value + products)
```

| Component | Location |
|-----------|----------|
| WordPress plugin | `app/public/wp-content/plugins/woocommerce-zoho-crm/` |
| CLI sync script | `integration/sync-latest-order.php` |

---

## Client setup (production)

### 1. Install & activate

1. Deploy the site (Local, staging, or production).
2. WP Admin → **Plugins** → activate **WooCommerce Zoho CRM Integration**.

### 2. Create Zoho API application

1. Log in to [Zoho API Console](https://api-console.zoho.com/) with the **client's Zoho account**.
2. **Add Client** → **Server-based Applications**.
3. Copy the **Redirect URI** shown in WordPress:
   - **WooCommerce → Zoho CRM** → connection box
   - Example: `https://yourstore.com/wp-admin/admin-post.php?action=wczc_oauth_callback`
4. Paste that URI into Zoho API Console → **Authorized Redirect URIs** → Save.
5. Copy **Client ID** and **Client Secret** from Zoho.

> **Local dev:** use your Local site URL (e.g. `http://ecommerce-with-zoho.local/wp-admin/admin-post.php?action=wczc_oauth_callback`). It must match exactly in Zoho.

### 3. Connect in WordPress (OAuth — no manual tokens)

1. **WooCommerce → Zoho CRM**
2. Paste **Client ID** and **Client Secret**
3. Select correct **Data center** (US / EU / India / Australia)
4. Click **Save settings**
5. Click **Connect to Zoho CRM**
6. Log in to Zoho → approve access
7. Redirect back → status shows **Connected**

The plugin stores the refresh token securely in the WordPress database. No curl commands or Self Client needed.

### 4. Configure sync

| Setting | Recommended |
|---------|-------------|
| Enable integration | ✓ |
| CRM record type | Deal |
| Sync when order is | Processing |
| Deal stage | Match client's Zoho pipeline (e.g. `Qualification`) |

Click **Test API connection** to verify.

### 5. Test end-to-end

1. Place a test order on the store.
2. Confirm order reaches **Processing** status.
3. Check WP Admin order screen → **Zoho CRM** box shows Contact ID + Deal ID.
4. Log in to [Zoho CRM](https://crm.zoho.com) → verify Contact and Deal.

Or run manually:

```bash
php integration/sync-latest-order.php
```

---

## What gets synced

| WooCommerce | Zoho Contact | Zoho Deal |
|-------------|--------------|-----------|
| Billing name | First_Name, Last_Name | Deal title |
| Email | Email (upsert key) | — |
| Phone | Phone | — |
| Address | Mailing_* | — |
| Line items | — | Description |
| Order total | — | Amount |

Each order syncs once. Order meta tracks Zoho IDs and prevents duplicates.

---

## Admin features

- **Connect / Disconnect / Re-authorize** — standard OAuth 2.0 flow
- **Connection status** — connected timestamp
- **Redirect URI** — copy-paste for Zoho API Console
- **Per-order sync** — manual "Sync to Zoho CRM" button on order screen
- **Diagnostics** — test connection, sync latest order
- **Logging** — WooCommerce → Status → Logs → `woocommerce-zoho-crm`

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| `Invalid Redirect URI` | Redirect URI in Zoho must match WordPress exactly |
| `Invalid OAuth state` | Click Connect again (session expired) |
| Token refresh failed | Re-authorize via **Connect to Zoho CRM** |
| Invalid deal stage | Use exact stage name from Zoho CRM setup |
| Order not syncing | Enable integration + confirm order status matches setting |

---

## Deployment checklist

- [ ] Plugin activated on production
- [ ] Zoho Server-based app created with production redirect URI
- [ ] OAuth connected (green Connected status)
- [ ] Integration enabled
- [ ] Test order synced and verified in Zoho CRM
- [ ] Deal stage matches client pipeline
