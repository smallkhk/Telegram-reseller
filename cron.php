<?php
/**
 * Checks the blockchain for incoming deposits. Run it every minute from cPanel > Cron Jobs:
 *
 *   php /home/YOURUSER/public_html/bot/cron.php >/dev/null 2>&1
 *
 * If your host has no CLI PHP, use instead:
 *   wget -q -O /dev/null "https://yourdomain.com/bot/cron.php?key=YOUR_WEBHOOK_SECRET"
 */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli' && !hash_equals((string)cfg('webhook_secret'), (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden');
}
@set_time_limit(55);

try {
    $report = run_deposit_checks();
    setting_set('cron_last_run', time());
    echo 'OK ' . date('H:i:s') . ($report ? ' | ' . implode(' | ', $report) : ' | nothing to check') . PHP_EOL;
} catch (Throwable $e) {
    log_error('cron: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
