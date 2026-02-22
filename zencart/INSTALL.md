# ZenoBank Crypto Payment Gateway for Zen Cart

Accept cryptocurrency payments (USDC, USDT, and more) via **ZenoBank** (https://zenobank.io/).

**Compatibility:** Zen Cart 2.1.0 and above.

---

## Step 1 — Upload files

Extract the archive and upload the files to the **root of your store** (the folder that contains `includes/`, `admin/`, `index.php`).

File structure:

```
YOUR_STORE/
│
├── zenobank_webhook.php                                   ← upload here
│
└── includes/
    ├── modules/
    │   └── payment/
    │       └── zenobank.php                               ← upload here
    └── languages/
        └── english/
            └── modules/
                └── payment/
                    └── lang.zenobank.php                  ← upload here
```

> If your store uses a different default language (e.g. `german`), also copy `lang.zenobank.php`
> into `includes/languages/german/modules/payment/` and translate the strings manually.

---

## Step 2 — Install the module in Admin

1. Log in to your store **Admin panel**
2. Go to: **Modules → Payment**
3. Find **«Crypto Payment (ZenoBank)»** in the list
4. Click **Install**

The module will appear in the active payment methods list.

---

## Step 3 — Get your API Key from ZenoBank

1. Register at https://dashboard.zenobank.io/
2. Go to the **Integrations** section
3. Copy your **API Key** (starts with `sk_...`)

---

## Step 4 — Configure the module

Go to: **Modules → Payment → ZenoBank → Edit**

| Field | Value |
|-------|-------|
| **Enable ZenoBank Crypto** | True |
| **Payment method title** | e.g. "Pay with Cryptocurrency" |
| **Description** | Short description shown to customers |
| **ZenoBank API Key** | Paste the key from Step 3 |
| **Sort order** | 0 = show first in the list |
| **Payment zone** | Leave empty for all countries |
| **Pending status** | Select order status for new unpaid orders (e.g. Pending) |
| **Completed status** | Select order status when payment is confirmed (e.g. Processing) |
| **Expired status** | Select status for expired payments, or leave 0 to do nothing |
| **Debug Log** | False (set to True only for troubleshooting) |

> **Important:** if the API Key field is empty, the module will not appear at checkout.

---

## Step 5 — Verify the webhook

The webhook URL is set automatically — no manual configuration needed.
The module passes this URL to ZenoBank when creating each payment:

```
https://YOUR-DOMAIN/zenobank_webhook.php
```

---

## How it works

```
1. Customer selects "Pay with Cryptocurrency" → clicks "Confirm Order"
2. The module saves the order to the database
3. Sends a request to the ZenoBank API → receives a payment page URL
4. Customer is redirected to the ZenoBank payment page
5. Customer pays with cryptocurrency
6. ZenoBank sends a webhook to zenobank_webhook.php
7. The module verifies the signature and updates the order status
```

---

## Debug Log

The module supports detailed logging of all operations.

**Enable logging:**

In the module settings (Admin → Modules → Payment → ZenoBank → Edit), set the **«Debug Log»** field to `True`.

**Log file location:**

```
YOUR_STORE/logs/zenobank.log
```

**What is logged:**

- Every incoming webhook from ZenoBank (IP address, request body)
- Parsed fields: orderId, status, verification token
- HMAC-SHA256 signature verification result
- Current and new order status
- ZenoBank API call on payment creation (response status, checkoutUrl)
- All errors with HTTP response codes

**Example log entries:**

```
[2025-01-15 12:34:00] [ZenoBank Webhook] Webhook received | IP=1.2.3.4 | body={...}
[2025-01-15 12:34:00] [ZenoBank Webhook] Token verification OK for order_id=42
[2025-01-15 12:34:00] [ZenoBank Webhook] Updating order #42 status: 1 → 2 (COMPLETED)
[2025-01-15 12:34:00] [ZenoBank Webhook] Order #42 marked as COMPLETED ✓
```

> **Note:** disable logging (`False`) after troubleshooting to prevent the log file from growing indefinitely.

---

## Security

- On install, the module automatically generates a **secret key** (64 characters).
- The key is stored in the database and is **not shown** in the admin panel.
- All webhooks are verified via **HMAC-SHA256** signature — impossible to forge.

---

## Requirements

- PHP 7.4 or higher
- PHP extensions: `curl`, `json`, `openssl`
- Zen Cart 2.1.0+
- HTTPS on your store (recommended)

---

## Module files

| File | Purpose |
|------|---------|
| `includes/modules/payment/zenobank.php` | Main module class |
| `includes/languages/english/modules/payment/lang.zenobank.php` | Language strings |
| `zenobank_webhook.php` | Webhook handler |

---

## Uninstall

1. Admin → Modules → Payment → ZenoBank → **Remove**
2. Delete the three module files from the server

---

## Support

- ZenoBank API docs: https://docs.zenobank.io/api-reference/api-overview
- ZenoBank Dashboard: https://dashboard.zenobank.io/
