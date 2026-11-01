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
    'currency'         => 'USD',   // wallet currency (ISO code)
    'currency_symbol'  => '$',
    'support_username' => 'YourSupportUsername', // without @
    'timezone'         => 'UTC',
    'max_qty_per_order'=> 10,
    'notify_admin_sales'    => true,  // DM admins on every sale
    'notify_admin_deposits' => true,  // DM admins on every deposit

    // ---- Deposits -------------------------------------------------------
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

    // ---- Payment gateways -----------------------------------------------
    // Crypto payments: https://nowpayments.io (Store settings > API key + IPN secret)
    'nowpayments' => [
        'enabled'    => true,
        'api_key'    => 'YOUR_NOWPAYMENTS_API_KEY',
        'ipn_secret' => 'YOUR_NOWPAYMENTS_IPN_SECRET',
        'sandbox'    => false,
        // Payment statuses that credit the wallet. 'finished' is the safest.
        'credit_statuses' => ['finished'],
    ],

    // Card / bank transfer / USSD: https://paystack.com (Settings > API Keys & Webhooks)
    'paystack' => [
        'enabled'    => false,
        'secret_key' => 'YOUR_PAYSTACK_SECRET_KEY',
        'currency'   => 'NGN',
        // How many units of the Paystack currency equal 1 unit of wallet currency.
        // e.g. wallet in USD, Paystack in NGN at 1 USD = 1600 NGN -> 1600.
        // If your wallet currency is also NGN, set to 1.
        'rate'       => 1600,
    ],
];
