<?php
/**
 * Copy this file to config.php and fill in your details.
 */
return [
    // ---- Telegram -------------------------------------------------------
    // Get it from @BotFather
    'bot_token'    => '123456789:AAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    // Bot username without @ (used for "return to bot" links)
    'bot_username' => 'YourShopBot',
    // Random string (letters, numbers, _ and - only, 20+ chars).
    // Used to verify that webhook calls really come from Telegram, and to protect setup.php
    'webhook_secret' => 'change_me_to_a_long_random_string_123',
    // Full URL of the folder where you uploaded the bot (no trailing slash), must be HTTPS
    'base_url'     => 'https://yourdomain.com/bot',
    // Telegram numeric user IDs of admins (get yours from @userinfobot)
    'admin_ids'    => [123456789],

    // ---- Shop -----------------------------------------------------------
    'shop_name'        => 'Digital Keys Shop',
    'currency'         => 'USD',   // wallet currency (keep USD: USDT is 1:1, BTC is converted at live price)
    'currency_symbol'  => '$',
    'support_username' => 'YourSupportUsername', // without @
    'timezone'         => 'UTC',
    'max_qty_per_order'=> 10,
    'notify_admin_sales'    => true,  // DM admins on every sale
    'notify_admin_deposits' => true,  // DM admins on every deposit

    // ---- Deposits (USD amounts) -----------------------------------------
    'deposit' => [
        'min'     => 5,
        'max'     => 1000,
        'presets' => [5, 10, 20, 50, 100, 200],
    ],

    // ---- Database (create in cPanel > MySQL Databases) ------------------
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'cpaneluser_shop',
        'user' => 'cpaneluser_shop',
        'pass' => 'database_password',
    ],

    // ---- Crypto wallets (payments go DIRECTLY to you, no gateway) --------
    // Deposits are verified automatically on the blockchain.
    // Use addresses from a wallet YOU control (Trust Wallet, TronLink, Electrum...).
    // Exchange deposit addresses also work, but some exchanges credit late or merge transfers.
    'crypto' => [
        'expiry_minutes' => 60, // how long a user has to pay a deposit

        // USDT on TRON (TRC20). Address starts with T...
        'trc20' => [
            'enabled' => true,
            'address' => 'TYourTronAddressHere',
            // Optional but recommended: free key from https://www.trongrid.io (avoids rate limits)
            'trongrid_api_key' => '',
        ],

        // USDT on BNB Smart Chain (BEP20). Address starts with 0x...
        'bep20' => [
            'enabled' => true,
            'address' => '0xYourBscAddressHere',
            // Public BSC RPC. Alternatives: https://bsc-dataseed.bnbchain.org , https://bsc.drpc.org
            'rpc' => 'https://bsc-rpc.publicnode.com',
            'confirmations' => 15,
            'log_chunk' => 1000, // blocks per request; lower it if your RPC complains about block range
        ],

        // Bitcoin. Address starts with bc1..., 1... or 3...
        'btc' => [
            'enabled' => true,
            'address' => 'bc1YourBitcoinAddressHere',
            'confirmations' => 1,
            'api' => 'https://mempool.space/api', // or https://blockstream.info/api
        ],
    ],
];
