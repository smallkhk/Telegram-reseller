<?php
/**
 * Telegram sends every update (messages, button presses) here.
 */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

$secret = (string)cfg('webhook_secret');
if ($secret === '' || !hash_equals($secret, $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

http_response_code(200);
$update = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($update)) {
    exit;
}

try {
    handle_update($update);
} catch (Throwable $e) {
    log_error('Update error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (is_schema_error($e)) {
        // Database is missing something (e.g. an upgrade didn't run): repair it and try again.
        try {
            migrate_schema(true);
            handle_update($update);
            log_error('Database repaired automatically; update processed.');
        } catch (Throwable $e2) {
            log_error('Retry after repair failed: ' . $e2->getMessage());
            report_error_to_admins($e2);
        }
    } else {
        report_error_to_admins($e);
    }
}

// No cron job? Bot traffic checks deposits in the background (at most once a minute).
try {
    maybe_run_background_checks();
} catch (Throwable $e) {
    log_error('Background checks: ' . $e->getMessage());
}
