# Telegram Reseller Bot (cPanel / shared hosting)

A fully automated Telegram shop for digital keys. It runs on any ordinary **cPanel web hosting**, so you don't need a VPS. It's written in plain PHP + MySQL, with no Composer and no background processes.

## Features

**For customers (all buttons):**
- 📊 **Dashboard**: balance, order count, total spent, user ID
- 💳 **Wallet deposits** that confirm automatically:
  - 🪙 Crypto via **NOWPayments** (BTC, USDT, LTC, ETH, 100+ coins)
  - 💳 Card / bank transfer via **Paystack** (optional)
- 🛒 **Shop**: categories → products → choose quantity → confirm → **keys delivered instantly**
- 📦 **My Orders**: view purchased keys again any time
- 📜 **Wallet history** of every deposit and purchase
- 🆘 Support button

**For admins (type `/admin` in the bot):**
- Live stats (users, deposits, sales, stock)
- Add, rename, hide or delete categories and products
- Bulk-add keys by pasting them one per line **or uploading a .txt file** (duplicates skipped)
- Change prices and descriptions
- Find a user by ID or @username: add or deduct balance, ban or unban
- Recent deposits and orders
- Broadcast a message (text, photo, video…) to all users
- DM alerts for every sale, every deposit and every product that runs out of stock

**Safety built in:**
- Webhook secret verification, so only Telegram can talk to the bot
- Signed IPNs (HMAC-SHA512) for NOWPayments and Paystack, and Paystack payments are double-checked through its API
- Each deposit is credited **exactly once**, even if the gateway sends the IPN several times
- Purchases run in a database transaction, so a key is never sold twice and balances can't go negative
- One-time confirm tokens, so double-tapping "Confirm" can't buy twice

---

## Requirements

- cPanel hosting with **PHP 8.0+** (with the `curl`, `pdo_mysql` and `mbstring` extensions, which are on by default almost everywhere)
- A MySQL / MariaDB database
- **HTTPS** on your domain (free AutoSSL / Let's Encrypt in cPanel). Telegram requires it.

---

## Installation (about 10 minutes)

### 1. Create the bot
1. Open [@BotFather](https://t.me/BotFather) → `/newbot` → copy the **token**.
2. Get your own Telegram numeric ID from [@userinfobot](https://t.me/userinfobot). This makes you the admin.

### 2. Create the database
cPanel → **MySQL® Databases**:
1. Create a database, e.g. `cpuser_shop`
2. Create a user with a strong password
3. Add the user to the database with **ALL PRIVILEGES**

### 3. Upload the files
1. cPanel → **File Manager** → `public_html` → create a folder, e.g. `bot`
2. Upload this project's files into `public_html/bot/` (upload a zip and use *Extract*).
3. Rename (copy) `config.sample.php` → `config.php` and edit it:
   - `bot_token`, `bot_username`, `admin_ids`
   - `webhook_secret`: a random string of letters/numbers, 20+ characters
   - `base_url`: e.g. `https://yourdomain.com/bot`
   - `db`: the database name, user and password from step 2
   - your payment gateway keys (see below)

### 4. Run setup
Open this in your browser:

```
https://yourdomain.com/bot/setup.php?key=YOUR_WEBHOOK_SECRET
```

It creates the tables, connects the bot to Telegram and prints the payment callback URLs. You should see all ✅.

### 5. Add products
Open your bot → `/admin` → **Catalog** → **Add Category** → **Add Product** → paste or upload the keys.

That's it. Customers send `/start`.

---

## Payment gateway setup

### NOWPayments (crypto)
1. Sign up at [nowpayments.io](https://nowpayments.io) and add your payout wallet.
2. **Store Settings** → create an **API key** → put it in `nowpayments.api_key`.
3. **Store Settings → Instant Payment Notifications** → generate an **IPN secret** → put it in `nowpayments.ipn_secret`.
4. You don't need to paste a callback URL. The bot sends `https://yourdomain.com/bot/ipn/nowpayments.php` with each invoice.

By default the wallet is credited when the payment status is `finished`. You can add `confirmed` to `credit_statuses` to credit a little faster.

### Paystack (cards, bank transfer, USSD)
1. In `config.php` set `paystack.enabled => true` and add your **secret key**.
2. Paystack Dashboard → **Settings → API Keys & Webhooks** → set the Webhook URL to:
   `https://yourdomain.com/bot/ipn/paystack.php`
3. If your wallet currency is USD but Paystack charges in NGN, set `paystack.rate` (e.g. `1600`). The bot shows the user the NGN amount.

You can enable either gateway or both. With both enabled, users pick one.

---

## File structure

```
bot/
├── webhook.php          ← Telegram sends updates here
├── setup.php            ← one-time installer (safe to re-run)
├── config.php           ← your settings (create from config.sample.php)
├── install.sql          ← database schema (setup.php runs it for you)
├── ipn/
│   ├── nowpayments.php  ← crypto payment callback
│   ├── paystack.php     ← card payment callback
│   └── return.php       ← "thank you" page after paying
├── src/                 ← bot logic (blocked from web access)
│   ├── bot.php          ← customer menus, shop, wallet, purchase
│   ├── admin.php        ← admin panel
│   ├── payments.php     ← gateways + auto-credit
│   ├── telegram.php     ← Telegram API helpers
│   └── helpers.php
└── storage/error.log    ← errors are logged here (blocked from web access)
```

---

## Troubleshooting

| Problem | Fix |
|---|---|
| Bot doesn't reply | Re-open `setup.php?key=…` and read any ⚠️ line. Check that `base_url` is HTTPS and correct. Check `storage/error.log`. |
| "SSL certificate problem" in the webhook error | Enable AutoSSL in cPanel → SSL/TLS Status. |
| Deposits don't credit | Check `storage/error.log` for "bad signature" (wrong IPN secret / Paystack key) or "amount mismatch". Make sure ModSecurity isn't blocking `/ipn/` (ask your host). |
| Broadcast stops early on a large user list | Some hosts kill long scripts. Ask the host to raise `max_execution_time`, or broadcast in smaller batches. |
| 500 error | Usually a typo in `config.php`, or PHP < 8.0. Select PHP 8.x in cPanel → **MultiPHP Manager**. |

**Admin tips:**
- Users can type `/cancel` to stop any text input.
- Only sell keys you have the right to resell. Check the terms of each gateway for allowed product types.
