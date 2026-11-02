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
- Find a user by ID or @username: add or deduct balance, ban or unban, **⭐ make them a reseller**
- **Reseller pricing:** every product has a customer price and an optional cheaper ⭐ reseller price. Resellers automatically see and pay their price.
- **🕵️ TXID Reviews:** approve or reject suspicious payment claims with one tap
- Recent deposits and orders
- Broadcast a message (text, photo, video…) to all users
- DM alerts for every sale, every deposit and every product that runs out of stock

**Safety built in:**
- Webhook secret verification, so only Telegram can talk to the bot
- Every blockchain transaction can be used **only once**, so a TXID can't be replayed or claimed by two users
- A TXID sent *before* a deposit was created can't be claimed for it
- **Anti-theft:** if someone submits a TXID whose amount matches *another* customer's unique deposit amount, the money goes to that customer
- TXID claims are auto-approved only when the amount is within $2 / 5% of the deposit and it was sent before the deposit expired. Anything else waits for **your approval** (Approve / Reject buttons in Telegram)
- At most 5 TXID attempts per user per hour
- Each deposit is credited **exactly once**
- Purchases run in a database transaction, so a key is never sold twice and balances can't go negative
- One-time confirm tokens, so double-tapping "Confirm" can't buy twice

---

## Requirements

- cPanel hosting with **PHP 8.0+** (with the `curl`, `pdo_mysql` and `mbstring` extensions, which are on by default almost everywhere)
- A MySQL / MariaDB database
- **HTTPS** on your domain (free AutoSSL / Let's Encrypt in cPanel). Telegram requires it.

---

## Installation (about 3 minutes, one form)

1. **Make your bot:** in Telegram open [@BotFather](https://t.me/BotFather), send `/newbot`, pick a name and copy the **token**. Then open your new bot and press **START**.
2. **Upload:** download this repo as a ZIP (**Code → Download ZIP**). In cPanel open **File Manager → public_html**, create a folder `bot`, upload the ZIP into it and click **Extract**. If the files land in a sub-folder, move them up into `bot`.
3. **Install:** open `https://yourdomain.com/bot/install.php` and fill in the form:
   - your bot token
   - your wallet addresses (at least one)
   - your cPanel login, so the installer can create the database for you (not saved)

   Click **🚀 Install**. You get a "Your shop is live" message in Telegram.
4. Send **/admin** to your bot → **Catalog** → add a category, a product and your keys. Done ✅

The installer finds your Telegram ID automatically, writes the config, links the bot to Telegram and locks itself afterwards. To start over, delete `config.php` and open `install.php` again.

**Optional:** add the cron job shown on the success page (cPanel → Cron Jobs → Once Per Minute). Without it, deposits are still checked automatically whenever anyone uses the bot. With it, they're also checked when the bot is quiet.

**Everything is free.** You need no API keys and no payment gateway. Blockchain data comes from free public services.

---

## How deposits work (no gateway)

1. The user picks an amount (e.g. $10) and a network.
2. The bot shows **your address** and a **unique amount**, e.g. `10.0372 USDT` (USDT is 1:1 with USD; BTC is converted at the live price). The extra decimals (at most $0.10) identify which user paid.
3. The **cron job** (every minute) or the user's **🔄 Check Payment** button scans your address on the blockchain. When a transfer with that exact amount arrives and is confirmed, the user's wallet is credited and they get a message.
4. **If the user sent a different amount** (for example, their exchange deducted a withdrawal fee), they tap **🧾 Submit TXID** and paste the transaction hash. The bot checks that transaction on-chain and credits **what actually arrived**.
5. Payments that match no deposit are reported to admins with an explorer link.
6. Suspicious TXID claims (amount far from the deposit, or sent long after it expired) are held and sent to you with ✅ Approve / ❌ Reject buttons.

Deposits expire after 60 minutes (configurable). A payment that arrives late is still credited.

### Blockchain data sources (all free, no signup)
| Network | Source | Notes |
|---|---|---|
| USDT TRC20 | TronGrid API | A free API key from [trongrid.io](https://www.trongrid.io) is recommended (`trongrid_api_key`) to avoid rate limits |
| USDT BEP20 | BSC public RPC (`bsc-rpc.publicnode.com`) | Change `rpc` if it's slow; confirmations default 15 |
| BTC | mempool.space API | Or `https://blockstream.info/api`; confirmations default 1 |
| BTC price | Coinbase → Kraken → CoinGecko → Binance | Automatic fallback |

### Cron job (optional)
cPanel → **Cron Jobs** → *Once Per Minute* (`* * * * *`) → command:

```
php /home/YOURUSER/public_html/bot/cron.php >/dev/null 2>&1
```

`setup.php` prints the exact path for your account. If your host has no command-line PHP, use:
`wget -q -O /dev/null "https://yourdomain.com/bot/cron.php?key=YOUR_WEBHOOK_SECRET"`

The cron job only calls the blockchain APIs while there are open deposits, so it is very light. Without cron, deposits are checked whenever someone uses the bot (at most once a minute) and when users tap **Check Payment**.

### Wallet tips
- Use addresses from a wallet **you control** (Trust Wallet, TronLink, MetaMask for BSC, Electrum/BlueWallet for BTC).
- Use a **dedicated address** for the shop, not one that already receives lots of other payments.
- A TRON address needs a little TRX to *send* USDT later. Receiving is free.

---

## File structure

```
bot/
├── webhook.php          ← Telegram sends updates here
├── install.php          ← setup wizard (open it once in your browser)
├── setup.php            ← re-links the bot if you change domain (setup.php?key=SECRET)
├── config.php           ← your settings (created by the installer)
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
| Installer: "could not reach cPanel automatically" | Your host blocks it. Choose **I already made one**, create a database in cPanel → MySQL Databases, and type its details. |
| Bot doesn't reply | Open `setup.php?key=YOUR_SECRET` (the secret is `webhook_secret` in `config.php`) and read any ⚠️ line. Check `storage/error.log`. |
| "SSL certificate problem" in the webhook error | Enable AutoSSL in cPanel → SSL/TLS Status. |
| Deposits don't credit | Check that the cron job runs (run `php cron.php` in cPanel Terminal, it prints what it scanned). Check `storage/error.log` for API errors. For TRON rate limits add a TronGrid API key. For BSC timeouts try another `rpc` or lower `log_chunk`. The user can always use **Submit TXID**. |
| Broadcast stops early on a large user list | Some hosts kill long scripts. Ask the host to raise `max_execution_time`, or broadcast in smaller batches. |
| 500 error | Usually PHP < 8.0. Select PHP 8.x in cPanel → **MultiPHP Manager**. |

**Updating an existing shop:** upload the new ZIP over the old files and click Extract → overwrite. Your `config.php` isn't in the ZIP, so your settings are kept. The database upgrades itself automatically.

**Admin tips:**
- To make a reseller: the person starts your bot → `/admin` → **Find User** → send their @username → **⭐ Make Reseller**. Then set **⭐ Reseller Price** on each product.
- Users can type `/cancel` to stop any text input.
- Only sell keys you have the right to resell.
