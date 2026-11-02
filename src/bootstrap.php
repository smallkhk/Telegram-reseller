<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$cfgFile = APP_ROOT . '/config.php';
if (!is_file($cfgFile)) {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Location: install.php');
    }
    exit("Not installed yet. Open install.php in your browser.\n");
}
$GLOBALS['config'] = require $cfgFile;

require __DIR__ . '/helpers.php';
require __DIR__ . '/telegram.php';
require __DIR__ . '/crypto.php';
require __DIR__ . '/bot.php';
require __DIR__ . '/admin.php';

date_default_timezone_set((string)cfg('timezone', 'UTC'));

try {
    migrate_schema();
} catch (Throwable $e) {
    log_error('Migration failed: ' . $e->getMessage());
}
