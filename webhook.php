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
}
