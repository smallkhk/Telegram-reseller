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
