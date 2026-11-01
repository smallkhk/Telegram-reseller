# Telegram Reseller Bot (cPanel / shared hosting)

A fully automated Telegram shop for digital keys. It runs on any ordinary **cPanel web hosting**, so you don't need a VPS. It's written in plain PHP + MySQL, with no Composer and no background processes.

## Features

**For customers (all buttons):**
- 📊 **Dashboard**: balance, order count, total spent, user ID
- 💳 **Crypto wallet deposits straight to YOUR addresses, with no payment gateway:**
  - 💵 **USDT TRC20** (Tron)
  - 💵 **USDT BEP20** (BNB Smart Chain)
  - ₿ **Bitcoin**
  - Verified **automatically on the blockchain**. No middleman, no fees, no KYC with a gateway.
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
- Every blockchain transaction can be used **only once**, so a TXID can't be replayed or claimed by two users
- A TXID sent *before* a deposit was created can't be claimed for it
- Each deposit is credited **exactly once**
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
   - your **USDT TRC20 / USDT BEP20 / BTC addresses** in the `crypto` section

### 4. Run setup
Open this in your browser:

```
https://yourdomain.com/bot/setup.php?key=YOUR_WEBHOOK_SECRET
```

It creates the tables, connects the bot to Telegram, checks your wallet addresses and prints the cron command. You should see all ✅.

### 5. Add the cron job
See [Setting up the cron job](#setting-up-the-cron-job-important) below.

### 6. Add products
Open your bot → `/admin` → **Catalog** → **Add Category** → **Add Product** → paste or upload the keys.

That's it. Customers send `/start`.

---

## How deposits work (no gateway)

1. The user picks an amount (e.g. $10) and a network.
2. The bot shows **your address** and a **unique amount**, e.g. `10.0372 USDT` (USDT is 1:1 with USD; BTC is converted at the live price). The extra decimals (at most $0.10) identify which user paid.
3. The **cron job** (every minute) or the user's **🔄 Check Payment** button scans your address on the blockchain. When a transfer with that exact amount arrives and is confirmed, the user's wallet is credited and they get a message.
4. **If the user sent a different amount** (for example, their exchange deducted a withdrawal fee), they tap **🧾 Submit TXID** and paste the transaction hash. The bot checks that transaction on-chain and credits **what actually arrived**.
5. Payments that match no deposit are reported to admins with an explorer link.

Deposits expire after 60 minutes (configurable). A payment that arrives late is still credited.

### Blockchain data sources (all free, no signup)
| Network | Source | Notes |
|---|---|---|
| USDT TRC20 | TronGrid API | A free API key from [trongrid.io](https://www.trongrid.io) is recommended (`trongrid_api_key`) to avoid rate limits |
| USDT BEP20 | BSC public RPC (`bsc-rpc.publicnode.com`) | Change `rpc` if it's slow; confirmations default 15 |
| BTC | mempool.space API | Or `https://blockstream.info/api`; confirmations default 1 |
| BTC price | Coinbase → Kraken → CoinGecko → Binance | Automatic fallback |

### Setting up the cron job (important)
cPanel → **Cron Jobs** → *Once Per Minute* (`* * * * *`) → command:

```
php /home/YOURUSER/public_html/bot/cron.php >/dev/null 2>&1
```

`setup.php` prints the exact path for your account. If your host has no command-line PHP, use:
`wget -q -O /dev/null "https://yourdomain.com/bot/cron.php?key=YOUR_WEBHOOK_SECRET"`

The cron job only calls the blockchain APIs while there are open deposits, so it is very light. Without cron, deposits are still credited when users tap **Check Payment**, but not automatically.

### Wallet tips
- Use addresses from a wallet **you control** (Trust Wallet, TronLink, MetaMask for BSC, Electrum/BlueWallet for BTC).
- Use a **dedicated address** for the shop, not one that already receives lots of other payments.
- A TRON address needs a little TRX to *send* USDT later. Receiving is free.

---

## File structure

```
bot/
├── webhook.php          ← Telegram sends updates here
├── setup.php            ← one-time installer (safe to re-run)
├── config.php           ← your settings (create from config.sample.php)
├── install.sql          ← database schema (setup.php runs it for you)
├── cron.php           ← checks the blockchain for deposits (run every minute)
├── src/                 ← bot logic (blocked from web access)
│   ├── bot.php          ← customer menus, shop, wallet, purchase
│   ├── admin.php        ← admin panel
│   ├── crypto.php       ← on-chain deposit verification (TRON, BSC, BTC)
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
| Deposits don't credit | Check that the cron job runs (run `php cron.php` in cPanel Terminal, it prints what it scanned). Check `storage/error.log` for API errors. For TRON rate limits add a TronGrid API key. For BSC timeouts try another `rpc` or lower `log_chunk`. The user can always use **Submit TXID**. |
| Broadcast stops early on a large user list | Some hosts kill long scripts. Ask the host to raise `max_execution_time`, or broadcast in smaller batches. |
| 500 error | Usually a typo in `config.php`, or PHP < 8.0. Select PHP 8.x in cPanel → **MultiPHP Manager**. |

**Admin tips:**
- Users can type `/cancel` to stop any text input.
- Only sell keys you have the right to resell.
