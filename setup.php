<?php
/**
 * One-time setup: creates the database tables and connects the bot to Telegram.
 *
 * Open in your browser:  https://yourdomain.com/bot/setup.php?key=YOUR_WEBHOOK_SECRET
 * Safe to run again any time (e.g. after changing the domain).
 */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

$cli = PHP_SAPI === 'cli';
if (!$cli && !hash_equals((string)cfg('webhook_secret'), (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden. Open setup.php?key=YOUR_WEBHOOK_SECRET');
}
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
}

function out(string $s): void
{
    echo $s . PHP_EOL;
}

// 1) Check config
$secret = (string)cfg('webhook_secret');
if (!preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secret)) {
    out('❌ webhook_secret must be 16+ characters using only letters, numbers, _ and -');
    exit;
}
$base = rtrim((string)cfg('base_url'), '/');
if (!str_starts_with($base, 'https://')) {
    out('❌ base_url must start with https:// (enable free SSL / AutoSSL in cPanel)');
    exit;
}

// 2) Database
try {
    $sql = (string)file_get_contents(__DIR__ . '/install.sql');
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        db()->exec($stmt);
    }
    out('✅ Database tables ready');
} catch (Throwable $e) {
    out('❌ Database error: ' . $e->getMessage());
    out('   Check the db settings in config.php');
    exit;
}

// 3) Telegram
$me = tg('getMe');
if (empty($me['ok'])) {
    out('❌ Telegram bot token is invalid: ' . ($me['description'] ?? 'no response'));
    exit;
}
out('✅ Bot: @' . $me['result']['username']);

$hook = tg('setWebhook', [
    'url' => $base . '/webhook.php',
    'secret_token' => $secret,
    'allowed_updates' => ['message', 'callback_query'],
    'drop_pending_updates' => true,
    'max_connections' => 20,
]);
out(!empty($hook['ok']) ? '✅ Webhook set to ' . $base . '/webhook.php' : '❌ setWebhook failed: ' . ($hook['description'] ?? ''));

tg('setMyCommands', ['commands' => [
    ['command' => 'start', 'description' => 'Open dashboard'],
    ['command' => 'balance', 'description' => 'Show wallet balance'],
    ['command' => 'cancel', 'description' => 'Cancel current action'],
]]);
out('✅ Bot commands set');

$info = tg('getWebhookInfo');
if (!empty($info['result']['last_error_message'])) {
    out('⚠️ Last webhook error reported by Telegram: ' . $info['result']['last_error_message']);
}

out('');
out('Payment callback URLs (paste these in your gateway dashboards):');
out('  NOWPayments IPN : ' . $base . '/ipn/nowpayments.php');
out('  Paystack webhook: ' . $base . '/ipn/paystack.php');
out('');
out('🎉 Done! Open your bot in Telegram and send /start. Admins send /admin.');
out('🔒 Tip: you can delete setup.php from the server now (re-upload it if you need it again).');
