<?php
declare(strict_types=1);

/** Read a config value using dot notation, e.g. cfg('db.host'). */
function cfg(string $key, $default = null)
{
    $v = $GLOBALS['config'];
    foreach (explode('.', $key) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) {
            return $default;
        }
        $v = $v[$k];
    }
    return $v;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            cfg('db.host', 'localhost'),
            (int)cfg('db.port', 3306),
            cfg('db.name')
        );
        $pdo = new PDO($dsn, (string)cfg('db.user'), (string)cfg('db.pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Keep MySQL's NOW() in the same timezone as PHP so expiry times line up.
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    return $st;
}

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Normalise a number to a 2-decimal string for safe DECIMAL math in SQL. */
function dec($n): string
{
    return number_format(round((float)$n, 2), 2, '.', '');
}

function money($n): string
{
    return cfg('currency_symbol', '$') . number_format((float)$n, 2);
}

function is_admin($id): bool
{
    return in_array((int)$id, array_map('intval', (array)cfg('admin_ids', [])), true);
}

function log_error(string $msg): void
{
    @file_put_contents(
        APP_ROOT . '/storage/error.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
        FILE_APPEND
    );
}

/**
 * Simple HTTP client. Returns ['code' => int, 'json' => ?array, 'raw' => string].
 */
function http_request(string $method, string $url, array $headers = [], $body = null, int $timeout = 25): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === false) {
        log_error("HTTP $method $url failed: " . curl_error($ch));
        $raw = '';
    }
    curl_close($ch);
    $json = json_decode($raw, true);
    return ['code' => $code, 'json' => is_array($json) ? $json : null, 'raw' => $raw];
}

/** Record a wallet movement. */
function add_tx(int $uid, string $type, $amount, $balanceAfter, string $note = ''): void
{
    q(
        'INSERT INTO transactions (user_id, type, amount, balance_after, note) VALUES (?,?,?,?,?)',
        [$uid, $type, dec($amount), dec($balanceAfter), mb_substr($note, 0, 255)]
    );
}

function parse_amount(string $text): ?float
{
    $text = trim(str_replace([',', cfg('currency_symbol', '$'), ' '], ['.', '', ''], $text));
    if ($text === '' || !is_numeric($text)) {
        return null;
    }
    $n = round((float)$text, 2);
    return $n > 0 ? $n : null;
}

/** Send the HTTP response now and keep running (for long jobs like broadcasts). */
function finish_request(): void
{
    ignore_user_abort(true);
    @set_time_limit(0);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
        return;
    }
    if (!headers_sent()) {
        header('Connection: close');
        header('Content-Length: 0');
    }
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

/**
 * Upgrade the database of an existing shop automatically (adds new columns once).
 */
function migrate_schema(bool $force = false): void
{
    $target = 4;
    try {
        $v = (int)(q("SELECT v FROM settings WHERE k='schema_version'")->fetchColumn() ?: 1);
    } catch (Throwable $e) {
        return; // tables not created yet
    }
    if ($v >= $target && !$force) {
        return;
    }
    $alters = [
        'ALTER TABLE users ADD COLUMN is_reseller TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE products ADD COLUMN reseller_price DECIMAL(14,2) NULL',
        'ALTER TABLE deposits ADD COLUMN review TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE deposits ADD COLUMN review_idx INT NULL',
        // v3: account holds, sender address, TXID claim log
        'ALTER TABLE users ADD COLUMN is_held TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE users ADD COLUMN hold_reason VARCHAR(255) NULL',
        'ALTER TABLE chain_txs ADD COLUMN from_addr VARCHAR(100) NULL',
        'CREATE TABLE IF NOT EXISTS txid_claims (
            id INT AUTO_INCREMENT PRIMARY KEY,
            chain VARCHAR(10) NOT NULL,
            txid VARCHAR(100) NOT NULL,
            user_id BIGINT NOT NULL,
            deposit_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_claim (chain, txid, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        // v4: remember which address each deposit showed (addresses can be changed in the bot)
        'ALTER TABLE deposits ADD COLUMN pay_address VARCHAR(100) NULL',
    ];
    foreach ($alters as $sql) {
        try {
            db()->exec($sql);
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }
    }
    q("INSERT INTO settings (k, v) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$target]);
}

/** Simple per-key rate limit stored in the settings table. Returns false when over the limit. */
function rate_limit(string $key, int $max, int $windowSeconds): bool
{
    $k = 'rl_' . $key;
    $raw = (string)(q('SELECT v FROM settings WHERE k=?', [$k])->fetchColumn() ?: '');
    [$start, $count] = array_map('intval', array_pad(explode('|', $raw), 2, 0));
    if (time() - $start > $windowSeconds) {
        $start = time();
        $count = 0;
    }
    if ($count >= $max) {
        return false;
    }
    q('INSERT INTO settings (k, v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$k, $start . '|' . ($count + 1)]);
    return true;
}

/** True for "unknown column / missing table" database errors (fixable by migrate_schema). */
function is_schema_error(Throwable $e): bool
{
    $m = $e->getMessage();
    return $e instanceof PDOException
        && (str_contains($m, '42S22') || str_contains($m, '42S02') || stripos($m, 'Unknown column') !== false || stripos($m, "doesn't exist") !== false);
}

/** Tell admins about an unexpected error (at most once every 10 minutes). */
function report_error_to_admins(Throwable $e): void
{
    try {
        $last = (int)(q("SELECT v FROM settings WHERE k='last_error_alert'")->fetchColumn() ?: 0);
        if (time() - $last < 600) {
            return;
        }
        q("INSERT INTO settings (k, v) VALUES ('last_error_alert', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [time()]);
        notify_admins("⚠️ <b>Bot error</b> (the bot kept running):\n<code>" . e(mb_substr($e->getMessage(), 0, 300)) . "</code>\n\nIf this keeps happening, send this message to your developer.");
    } catch (Throwable $ignored) {
    }
}

/**
 * Save a changed setting into config.php (safely: write a temp file, then swap it in).
 * Returns true on success.
 */
function save_config_value(array $path, $value): bool
{
    $file = APP_ROOT . '/config.php';
    $cfg = require $file;
    $ref = &$cfg;
    foreach ($path as $k) {
        if (!isset($ref[$k]) || !is_array($ref[$k])) {
            $ref[$k] = $ref[$k] ?? [];
        }
        $ref = &$ref[$k];
    }
    $ref = $value;
    unset($ref);

    $php = "<?php\n// Settings for your shop bot. Updated " . date('Y-m-d H:i') . ".\nreturn " . var_export($cfg, true) . ";\n";
    $tmp = $file . '.tmp' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, fileperms($file) & 0777);
    // Make sure the new file is valid before replacing the old one.
    $check = @include $tmp;
    if (!is_array($check) || !@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }
    $GLOBALS['config'] = $cfg;
    return true;
}
