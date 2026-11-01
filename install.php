<?php
/**
 * One-page installer. Upload the files, open this page, fill the form once, done.
 * It locks itself automatically after a successful install.
 */
declare(strict_types=1);

define('APP_ROOT', __DIR__);
$configFile = __DIR__ . '/config.php';

// Used by the installer to check that HTTPS works on this domain.
if (isset($_GET['ping'])) {
    exit('pong');
}

$installed = is_file($configFile);
$errors = [];
$done = null;
$v = fn(string $k, string $d = '') => htmlspecialchars((string)($_POST[$k] ?? $d), ENT_QUOTES, 'UTF-8');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function inst_http(string $method, string $url, array $headers = [], $body = null, bool $verify = true): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    $raw = curl_exec($ch);
    $err = $raw === false ? curl_error($ch) : '';
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$raw, true);
    return ['code' => $code, 'raw' => (string)$raw, 'json' => is_array($json) ? $json : null, 'error' => $err];
}

function inst_tg(string $token, string $method, array $params = []): array
{
    $api = getenv('INSTALL_TG_API') ?: 'https://api.telegram.org'; // env override for automated tests only
    $r = inst_http('POST', "{$api}/bot{$token}/{$method}", ['Content-Type: application/json'], $params);
    return $r['json'] ?? ['ok' => false, 'description' => $r['error'] ?: 'No response from Telegram'];
}

/**
 * Create database + user via cPanel's own API (UAPI) on this server.
 * Returns [dbName, dbUser, dbPass] or throws.
 */
function inst_cpanel_create_db(string $cpUser, string $cpPass): array
{
    $auth = ['Authorization: Basic ' . base64_encode($cpUser . ':' . $cpPass)];
    $call = function (string $fn, array $args) use ($auth): array {
        // Loopback call to this same server's cPanel; its certificate is issued for the
        // public hostname, not "localhost", so certificate checks can't apply here.
        $cpanel = getenv('INSTALL_CPANEL_URL') ?: 'https://localhost:2083'; // env override for automated tests only
        $r = inst_http('GET', $cpanel . '/execute/Mysql/' . $fn . '?' . http_build_query($args), $auth, null, false);
        if ($r['code'] === 401 || $r['code'] === 403) {
            throw new RuntimeException('cPanel login failed. Check your cPanel username and password.');
        }
        if (!$r['json']) {
            throw new RuntimeException('Could not reach cPanel automatically (' . ($r['error'] ?: 'HTTP ' . $r['code']) . '). Use "I already created a database" instead.');
        }
        return $r['json'];
    };

    // cPanel requires the "username_" prefix on database and user names.
    $prefix = substr(preg_replace('/[^a-z0-9]/', '', strtolower($cpUser)), 0, 16);
    $suffix = substr(bin2hex(random_bytes(2)), 0, 3);
    $db = $prefix . '_tgshop' . $suffix;
    $user = $prefix . '_tgbot' . $suffix;
    $pass = rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'Aa'), '=') . '9!';

    foreach ([
        ['create_database', ['name' => $db]],
        ['create_user', ['name' => $user, 'password' => $pass]],
        ['set_privileges_on_database', ['user' => $user, 'database' => $db, 'privileges' => 'ALL PRIVILEGES']],
    ] as [$fn, $args]) {
        $res = $call($fn, $args);
        if (empty($res['status'])) {
            $msg = implode(' ', (array)($res['errors'] ?? ['unknown error']));
            throw new RuntimeException("cPanel could not $fn: $msg");
        }
    }
    return [$db, $user, $pass];
}

function inst_valid_address(string $chain, string $a): bool
{
    $p = [
        'trc20' => '/^T[1-9A-HJ-NP-Za-km-z]{33}$/',
        'bep20' => '/^0x[0-9a-fA-F]{40}$/',
        'btc'   => '/^(bc1[02-9ac-hj-np-z]{11,71}|[13][1-9A-HJ-NP-Za-km-z]{25,34})$/i',
    ];
    return (bool)preg_match($p[$chain], $a);
}

function inst_default_cpanel_user(): string
{
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $u = posix_getpwuid(posix_geteuid());
        if (!empty($u['name'])) {
            return $u['name'];
        }
    }
    if (preg_match('#^/home\d*/([^/]+)/#', __DIR__, $m)) {
        return $m[1];
    }
    return (string)get_current_user();
}

// ---------------------------------------------------------------------------
// Install
// ---------------------------------------------------------------------------

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim((string)($_POST['token'] ?? ''));
    $adminId = trim((string)($_POST['admin_id'] ?? ''));
    $shopName = trim((string)($_POST['shop_name'] ?? ''));
    $support = ltrim(trim((string)($_POST['support'] ?? '')), '@');
    $wallets = [
        'trc20' => trim((string)($_POST['trc20'] ?? '')),
        'bep20' => trim((string)($_POST['bep20'] ?? '')),
        'btc'   => trim((string)($_POST['btc'] ?? '')),
    ];
    $dbMode = ($_POST['db_mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto';

    // 1) Requirements
    if (PHP_VERSION_ID < 80000) {
        $errors[] = 'PHP 8.0 or newer is required (you have ' . PHP_VERSION . '). Change it in cPanel → MultiPHP Manager.';
    }
    foreach (['curl', 'pdo_mysql', 'mbstring'] as $ext) {
        if (!extension_loaded($ext)) {
            $errors[] = "PHP extension \"$ext\" is missing. Enable it in cPanel → Select PHP Version → Extensions.";
        }
    }
    if (!is_writable(__DIR__)) {
        $errors[] = 'This folder is not writable, so config.php cannot be saved. Set folder permission to 755 in File Manager.';
    }

    // 2) Telegram bot
    $bot = null;
    if (!preg_match('/^\d+:[A-Za-z0-9_-]{30,}$/', $token)) {
        $errors[] = 'The bot token looks wrong. Copy it again from @BotFather (looks like 123456:ABC-xyz…).';
    } elseif (!$errors) {
        $me = inst_tg($token, 'getMe');
        if (empty($me['ok'])) {
            $errors[] = 'Telegram rejected the bot token: ' . ($me['description'] ?? 'unknown error');
        } else {
            $bot = $me['result'];
        }
    }

    // 3) Find the admin automatically (the person who pressed Start in the bot)
    $adminUsername = '';
    if ($bot && $adminId === '') {
        inst_tg($token, 'deleteWebhook');
        $up = inst_tg($token, 'getUpdates', ['limit' => 100, 'timeout' => 0]);
        foreach (array_reverse($up['result'] ?? []) as $u) {
            $from = $u['message']['from'] ?? null;
            if ($from && empty($from['is_bot']) && ($u['message']['chat']['type'] ?? '') === 'private') {
                $adminId = (string)$from['id'];
                $adminUsername = (string)($from['username'] ?? '');
                break;
            }
        }
        if ($adminId === '') {
            $errors[] = 'Could not find you yet. Open your bot @' . $bot['username'] . ' in Telegram, press START (or send "hi"), then click Install again.';
        }
    } elseif ($adminId !== '' && !ctype_digit($adminId)) {
        $errors[] = 'Your Telegram ID must be a number (or leave it empty to detect it automatically).';
    }

    // 4) Wallets
    $wallets = array_filter($wallets, fn($a) => $a !== '');
    if (!$wallets) {
        $errors[] = 'Enter at least one wallet address to receive payments.';
    }
    foreach ($wallets as $chain => $addr) {
        if (!inst_valid_address($chain, $addr)) {
            $names = ['trc20' => 'USDT TRC20 (starts with T)', 'bep20' => 'USDT BEP20 (starts with 0x)', 'btc' => 'Bitcoin (starts with bc1, 1 or 3)'];
            $errors[] = 'The ' . $names[$chain] . ' address is not valid.';
        }
    }

    // 5) HTTPS address of this folder
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    $path = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/install.php'))), '/');
    $baseUrl = (getenv('INSTALL_SCHEME') ?: 'https') . '://' . $host . $path; // env override for automated tests only
    $viaHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    if (!$errors && !$viaHttps) {
        $ping = inst_http('GET', $baseUrl . '/install.php?ping=1');
        if (trim($ping['raw']) !== 'pong') {
            $errors[] = "Your site doesn't work on HTTPS yet (Telegram requires it). In cPanel open SSL/TLS Status → Run AutoSSL, wait a few minutes, then try again.";
        }
    }

    // 6) Database
    $db = null;
    if (!$errors) {
        try {
            if ($dbMode === 'auto') {
                [$dbName, $dbUser, $dbPass] = inst_cpanel_create_db(trim((string)$_POST['cp_user']), (string)$_POST['cp_pass']);
                $dbHost = 'localhost';
            } else {
                $dbHost = trim((string)($_POST['db_host'] ?? 'localhost')) ?: 'localhost';
                $dbName = trim((string)($_POST['db_name'] ?? ''));
                $dbUser = trim((string)($_POST['db_user'] ?? ''));
                $dbPass = (string)($_POST['db_pass'] ?? '');
            }
            $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            foreach (array_filter(array_map('trim', explode(';', (string)file_get_contents(__DIR__ . '/install.sql')))) as $stmt) {
                $pdo->exec($stmt);
            }
            $db = ['host' => $dbHost, 'port' => 3306, 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass];
        } catch (Throwable $e) {
            $errors[] = 'Database problem: ' . $e->getMessage();
        }
    }

    // 7) Save config + link bot
    if (!$errors) {
        $secret = bin2hex(random_bytes(20));
        $config = [
            'bot_token' => $token,
            'bot_username' => $bot['username'],
            'webhook_secret' => $secret,
            'base_url' => $baseUrl,
            'admin_ids' => [(int)$adminId],
            'shop_name' => $shopName !== '' ? $shopName : $bot['first_name'],
            'currency' => 'USD',
            'currency_symbol' => '$',
            'support_username' => $support !== '' ? $support : $adminUsername,
            'timezone' => 'UTC',
            'max_qty_per_order' => 10,
            'notify_admin_sales' => true,
            'notify_admin_deposits' => true,
            'deposit' => ['min' => 5, 'max' => 1000, 'presets' => [5, 10, 20, 50, 100, 200]],
            'db' => $db,
            'crypto' => [
                'expiry_minutes' => 60,
                'trc20' => ['enabled' => isset($wallets['trc20']), 'address' => $wallets['trc20'] ?? '', 'trongrid_api_key' => ''],
                'bep20' => ['enabled' => isset($wallets['bep20']), 'address' => $wallets['bep20'] ?? '', 'rpc' => 'https://bsc-rpc.publicnode.com', 'confirmations' => 15, 'log_chunk' => 1000],
                'btc' => ['enabled' => isset($wallets['btc']), 'address' => $wallets['btc'] ?? '', 'confirmations' => 1, 'api' => 'https://mempool.space/api'],
            ],
        ];
        $php = "<?php\n// Created by install.php on " . date('Y-m-d H:i') . ". You can edit these settings.\nreturn " . var_export($config, true) . ";\n";

        $hook = inst_tg($token, 'setWebhook', [
            'url' => $baseUrl . '/webhook.php',
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ]);
        if (empty($hook['ok'])) {
            $errors[] = 'Telegram could not connect to your site: ' . ($hook['description'] ?? 'unknown error');
        } elseif (file_put_contents($configFile, $php, LOCK_EX) === false) {
            $errors[] = 'Could not save config.php. Check folder permissions (755).';
            inst_tg($token, 'deleteWebhook');
        } else {
            @chmod($configFile, 0640);
            inst_tg($token, 'setMyCommands', ['commands' => [
                ['command' => 'start', 'description' => 'Open dashboard'],
                ['command' => 'balance', 'description' => 'Show wallet balance'],
                ['command' => 'admin', 'description' => 'Admin panel (admins only)'],
                ['command' => 'cancel', 'description' => 'Cancel current action'],
            ]]);
            inst_tg($token, 'sendMessage', [
                'chat_id' => (int)$adminId,
                'parse_mode' => 'HTML',
                'text' => "🎉 <b>Your shop is live!</b>\n\nYou are the admin.\n\n1️⃣ Send /admin → Catalog → Add Category → Add Product → paste your keys.\n2️⃣ Share your bot link with customers: https://t.me/{$bot['username']}\n\nSend /start to see what customers see.",
            ]);
            $done = [
                'bot' => $bot['username'],
                'cron_cli' => 'php ' . __DIR__ . '/cron.php >/dev/null 2>&1',
                'cron_url' => 'wget -q -O /dev/null "' . $baseUrl . '/cron.php?key=' . $secret . '"',
            ];
        }
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Shop Bot Setup</title>
<style>
  :root{--bg:#0b1220;--card:#131c2e;--line:#24314a;--text:#e6edf7;--muted:#8fa0bb;--accent:#2aa3ef;--ok:#22c55e;--bad:#ef4444}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:24px 16px}
  .wrap{max-width:640px;margin:0 auto}
  h1{font-size:24px;margin:0 0 4px} .sub{color:var(--muted);margin:0 0 24px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:20px;margin-bottom:16px}
  .card h2{font-size:16px;margin:0 0 4px;display:flex;gap:8px;align-items:center}
  .num{background:var(--accent);color:#fff;border-radius:50%;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;font-size:13px}
  .hint{color:var(--muted);font-size:13px;margin:0 0 14px}
  label{display:block;font-weight:600;font-size:14px;margin:12px 0 4px}
  label small{font-weight:400;color:var(--muted)}
  input[type=text],input[type=password]{width:100%;padding:11px 12px;border-radius:9px;border:1px solid var(--line);background:#0e1626;color:var(--text);font-size:15px}
  input:focus{outline:2px solid var(--accent);border-color:transparent}
  .radio{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
  .radio label{display:flex;gap:6px;align-items:center;font-weight:500;margin:0;padding:8px 12px;border:1px solid var(--line);border-radius:9px;cursor:pointer}
  .hidden{display:none}
  button{width:100%;padding:14px;border:0;border-radius:11px;background:var(--accent);color:#fff;font-size:16px;font-weight:700;cursor:pointer}
  button:disabled{opacity:.6}
  .err{background:#3a1218;border:1px solid var(--bad);border-radius:12px;padding:14px 16px;margin-bottom:16px}
  .err li{margin:4px 0}
  .okbox{text-align:center} .big{font-size:48px}
  a.btn{display:inline-block;background:var(--accent);color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:700;margin-top:8px}
  code.copy{display:block;background:#0e1626;border:1px solid var(--line);border-radius:8px;padding:10px;word-break:break-all;font-size:13px;cursor:pointer;margin-top:6px}
  ol{padding-left:20px;margin:6px 0} ol li{margin:4px 0}
</style>
</head>
<body>
<div class="wrap">

<?php if ($done): ?>
  <div class="card okbox">
    <div class="big">🎉</div>
    <h1>Your shop is live!</h1>
    <p class="sub">We sent you a message in Telegram. Send <b>/admin</b> to add your products.</p>
    <a class="btn" href="https://t.me/<?= htmlspecialchars($done['bot']) ?>" target="_blank">Open @<?= htmlspecialchars($done['bot']) ?></a>
  </div>
  <div class="card">
    <h2>⏰ Optional: 1-minute extra step</h2>
    <p class="hint">Deposits are already checked automatically whenever people use the bot. If you want them confirmed even when the bot is quiet, add a cron job:<br>
    cPanel → <b>Cron Jobs</b> → Common Settings: <b>Once Per Minute</b> → paste this as the Command → Add.</p>
    <code class="copy" onclick="navigator.clipboard.writeText(this.innerText);this.style.borderColor='#22c55e'"><?= htmlspecialchars($done['cron_cli']) ?></code>
    <p class="hint" style="margin-top:10px">If that doesn't work on your host, use this one instead:</p>
    <code class="copy" onclick="navigator.clipboard.writeText(this.innerText);this.style.borderColor='#22c55e'"><?= htmlspecialchars($done['cron_url']) ?></code>
    <p class="hint" style="margin-top:10px">Tap a command to copy it.</p>
  </div>

<?php elseif ($installed): ?>
  <div class="card okbox">
    <div class="big">✅</div>
    <h1>Already installed</h1>
    <p class="sub">This shop is set up. The installer is locked for safety.<br>To install again from scratch, delete <b>config.php</b> in File Manager and reload this page.</p>
  </div>

<?php else: ?>
  <h1>🛒 Shop Bot Setup</h1>
  <p class="sub">Fill this in once and click Install. Takes about 2 minutes.</p>

  <?php if ($errors): ?>
    <div class="err"><b>Please fix this:</b><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerText='Installing… please wait'">
    <div class="card">
      <h2><span class="num">1</span> Your Telegram bot</h2>
      <ol class="hint">
        <li>In Telegram open <b>@BotFather</b> → send <b>/newbot</b> → choose a name → copy the token.</li>
        <li>Open your new bot and press <b>START</b> (this is how we know you're the owner).</li>
      </ol>
      <label>Bot token</label>
      <input type="text" name="token" value="<?= $v('token') ?>" placeholder="123456789:AAH..." required autocomplete="off">
      <label>Shop name <small>(optional)</small></label>
      <input type="text" name="shop_name" value="<?= $v('shop_name') ?>" placeholder="My Key Shop">
      <label>Support username <small>(optional, without @, default: you)</small></label>
      <input type="text" name="support" value="<?= $v('support') ?>" placeholder="mysupport">
      <label>Your Telegram ID <small>(optional, leave empty and it's detected automatically)</small></label>
      <input type="text" name="admin_id" value="<?= $v('admin_id') ?>" placeholder="auto">
    </div>

    <div class="card">
      <h2><span class="num">2</span> Where customers pay you</h2>
      <p class="hint">Paste your own wallet addresses. Fill at least one, and leave empty the ones you don't want.</p>
      <label>💵 USDT TRC20 (Tron) address <small>starts with T</small></label>
      <input type="text" name="trc20" value="<?= $v('trc20') ?>" placeholder="T...">
      <label>💵 USDT BEP20 (BSC) address <small>starts with 0x</small></label>
      <input type="text" name="bep20" value="<?= $v('bep20') ?>" placeholder="0x...">
      <label>₿ Bitcoin address <small>starts with bc1, 1 or 3</small></label>
      <input type="text" name="btc" value="<?= $v('btc') ?>" placeholder="bc1...">
    </div>

    <div class="card">
      <h2><span class="num">3</span> Database</h2>
      <div class="radio">
        <label><input type="radio" name="db_mode" value="auto" <?= ($_POST['db_mode'] ?? 'auto') !== 'manual' ? 'checked' : '' ?> onclick="dbm('auto')"> Create it for me</label>
        <label><input type="radio" name="db_mode" value="manual" <?= ($_POST['db_mode'] ?? '') === 'manual' ? 'checked' : '' ?> onclick="dbm('manual')"> I already made one</label>
      </div>
      <div id="db-auto">
        <p class="hint" style="margin-top:12px">Enter the username and password you use to log in to cPanel. They're used once to create the database and are <b>not saved</b>.</p>
        <label>cPanel username</label>
        <input type="text" name="cp_user" value="<?= $v('cp_user', inst_default_cpanel_user()) ?>" autocomplete="off">
        <label>cPanel password</label>
        <input type="password" name="cp_pass" autocomplete="off">
      </div>
      <div id="db-manual" class="hidden">
        <p class="hint" style="margin-top:12px">From cPanel → MySQL Databases (the user must be added to the database with All Privileges).</p>
        <label>Database name</label><input type="text" name="db_name" value="<?= $v('db_name') ?>">
        <label>Database user</label><input type="text" name="db_user" value="<?= $v('db_user') ?>">
        <label>Database password</label><input type="password" name="db_pass">
        <label>Database host <small>(almost always localhost)</small></label><input type="text" name="db_host" value="<?= $v('db_host', 'localhost') ?>">
      </div>
    </div>

    <button type="submit">🚀 Install</button>
  </form>
  <script>
    function dbm(m){document.getElementById('db-auto').classList.toggle('hidden',m!=='auto');document.getElementById('db-manual').classList.toggle('hidden',m!=='manual');}
    dbm(document.querySelector('input[name=db_mode]:checked').value);
  </script>
<?php endif; ?>

</div>
</body>
</html>
