<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$cfgFile = APP_ROOT . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit('config.php is missing. Copy config.sample.php to config.php and edit it.');
}
$GLOBALS['config'] = require $cfgFile;

require __DIR__ . '/helpers.php';
require __DIR__ . '/telegram.php';
require __DIR__ . '/crypto.php';
require __DIR__ . '/bot.php';
require __DIR__ . '/admin.php';

date_default_timezone_set((string)cfg('timezone', 'UTC'));
