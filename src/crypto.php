<?php
declare(strict_types=1);

/**
 * Direct-to-wallet crypto deposits with automatic on-chain verification.
 * No payment gateway: you receive funds straight into your own addresses.
 *
 * How a deposit is matched:
 *  1. Each deposit gets a UNIQUE amount (e.g. 10.0372 USDT) -> the scanner finds
 *     an incoming transfer with exactly that amount and credits the right user.
 *  2. If the user sent a different amount (e.g. an exchange deducted a fee) they can
 *     submit their TXID, and the bot verifies that transaction on-chain.
 */

const USDT_TRC20_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
const USDT_BEP20_CONTRACT = '0x55d398326f99059ff775485246999027b3197955';
const ERC20_TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

function chain_info(string $chain): array
{
    $all = [
        'trc20' => ['label' => 'USDT (TRC20 · Tron)', 'btn' => '💵 USDT · TRC20 (Tron)', 'coin' => 'USDT', 'network' => 'TRON (TRC20)', 'pay_decimals' => 4,
                    'explorer' => 'https://tronscan.org/#/transaction/'],
        'bep20' => ['label' => 'USDT (BEP20 · BSC)', 'btn' => '💵 USDT · BEP20 (BSC)', 'coin' => 'USDT', 'network' => 'BNB Smart Chain (BEP20)', 'pay_decimals' => 4,
                    'explorer' => 'https://bscscan.com/tx/'],
        'btc'   => ['label' => 'Bitcoin (BTC)', 'btn' => '₿ Bitcoin (BTC)', 'coin' => 'BTC', 'network' => 'Bitcoin', 'pay_decimals' => 8,
                    'explorer' => 'https://mempool.space/tx/'],
    ];
    return $all[$chain] ?? ['label' => $chain, 'btn' => $chain, 'coin' => '?', 'network' => '?', 'pay_decimals' => 8, 'explorer' => ''];
}

/** e.g. "USDT TRC20" or "BTC". */
function chain_short(string $chain): string
{
    return $chain === 'btc' ? 'BTC' : 'USDT ' . strtoupper($chain);
}

/** Chains that are enabled and have an address configured. */
function chains_enabled(): array
{
    $out = [];
    foreach (['trc20', 'bep20', 'btc'] as $c) {
        if (cfg("crypto.$c.enabled") && trim((string)cfg("crypto.$c.address")) !== '') {
            $out[$c] = chain_info($c);
        }
    }
    return $out;
}

function chain_address(string $chain): string
{
    $a = trim((string)cfg("crypto.$chain.address"));
    if ($chain === 'bep20') {
        return strtolower($a);
    }
    if ($chain === 'btc' && stripos($a, 'bc1') === 0) {
        return strtolower($a);
    }
    return $a;
}

function txid_valid(string $chain, string $txid): bool
{
    return $chain === 'bep20'
        ? (bool)preg_match('/^0x[0-9a-fA-F]{64}$/', $txid)
        : (bool)preg_match('/^[0-9a-fA-F]{64}$/', $txid);
}

function normalize_txid(string $chain, string $txid): string
{
    $txid = trim($txid);
    // Accept explorer links too
    if (preg_match('/(0x)?[0-9a-fA-F]{64}/', $txid, $m)) {
        $txid = $m[0];
    }
    $txid = strtolower($txid);
    if ($chain === 'bep20' && !str_starts_with($txid, '0x')) {
        $txid = '0x' . $txid;
    }
    if ($chain !== 'bep20' && str_starts_with($txid, '0x')) {
        $txid = substr($txid, 2);
    }
    return $txid;
}

// ---------------------------------------------------------------------------
// Small utilities (no bcmath/gmp needed)
// ---------------------------------------------------------------------------

function setting_get(string $k, $default = null)
{
    $v = q('SELECT v FROM settings WHERE k=?', [$k])->fetchColumn();
    return $v === false ? $default : $v;
}

function setting_set(string $k, $v): void
{
    q('INSERT INTO settings (k, v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$k, (string)$v]);
}

/** Arbitrary-size hex string -> decimal string. */
function hex_to_dec(string $hex): string
{
    $hex = ltrim(strtolower(preg_replace('/^0x/i', '', $hex)), '0');
    if ($hex === '') {
        return '0';
    }
    $digits = [0]; // little-endian base-10 digits
    foreach (str_split($hex) as $h) {
        $carry = hexdec($h);
        foreach ($digits as $i => $d) {
            $v = $d * 16 + $carry;
            $digits[$i] = $v % 10;
            $carry = intdiv($v, 10);
        }
        while ($carry > 0) {
            $digits[] = $carry % 10;
            $carry = intdiv($carry, 10);
        }
    }
    return implode('', array_reverse($digits));
}

/** Integer units string (e.g. "10037200") -> normalized decimal string ("10.0372"). */
function units_to_dec(string $raw, int $decimals): string
{
    $raw = ltrim($raw, '0');
    if ($raw === '') {
        return '0';
    }
    $raw = str_pad($raw, $decimals + 1, '0', STR_PAD_LEFT);
    $int = substr($raw, 0, -$decimals) ?: '0';
    $frac = $decimals > 0 ? substr($raw, -$decimals) : '';
    return norm_dec($int . ($frac !== '' ? '.' . $frac : ''));
}

/** "10.03720000" -> "10.0372", "5.000" -> "5". */
function norm_dec(string $s): string
{
    $s = trim($s);
    if (str_contains($s, '.')) {
        $s = rtrim(rtrim($s, '0'), '.');
    }
    $s = ltrim($s, '0');
    if ($s === '' || $s[0] === '.') {
        $s = '0' . $s;
    }
    return $s;
}

function floor_cents(float $n): float
{
    return floor(round($n * 100, 6)) / 100;
}

/** BTC price in USD, cached for 60s, with several free sources as fallback. */
function btc_usd_price(): ?float
{
    $cached = setting_get('btc_price');
    if ($cached) {
        [$ts, $p] = explode('|', $cached);
        if (time() - (int)$ts < 60 && (float)$p > 0) {
            return (float)$p;
        }
    }
    $sources = [
        fn() => (float)(http_request('GET', 'https://api.coinbase.com/v2/prices/BTC-USD/spot', [], null, 8)['json']['data']['amount'] ?? 0),
        fn() => (float)(http_request('GET', 'https://api.kraken.com/0/public/Ticker?pair=XBTUSD', [], null, 8)['json']['result']['XXBTZUSD']['c'][0] ?? 0),
        fn() => (float)(http_request('GET', 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=usd', [], null, 8)['json']['bitcoin']['usd'] ?? 0),
        fn() => (float)(http_request('GET', 'https://api.binance.com/api/v3/ticker/price?symbol=BTCUSDT', [], null, 8)['json']['price'] ?? 0),
    ];
    foreach ($sources as $src) {
        $p = $src();
        if ($p > 1000) {
            setting_set('btc_price', time() . '|' . $p);
            return $p;
        }
    }
    log_error('Could not fetch BTC price from any source');
    return null;
}

// ---------------------------------------------------------------------------
// Creating deposits
// ---------------------------------------------------------------------------

/**
 * Returns ['ok'=>true,'deposit'=>array] or ['ok'=>false,'error'=>string].
 */
function create_crypto_deposit(array $user, string $chain, float $usd): array
{
    if (!isset(chains_enabled()[$chain])) {
        return ['ok' => false, 'error' => 'This network is not available.'];
    }
    $uid = (int)$user['id'];
    $open = (int)q("SELECT COUNT(*) FROM deposits WHERE user_id=? AND status='pending'", [$uid])->fetchColumn();
    if ($open >= 3) {
        return ['ok' => false, 'error' => 'You already have 3 unpaid deposits. Pay or cancel one of them first (Deposit → My pending deposits).'];
    }

    $rate = null;
    if ($chain === 'btc') {
        $rate = btc_usd_price();
        if (!$rate) {
            return ['ok' => false, 'error' => 'Could not get the BTC price right now. Please try again in a minute or use USDT.'];
        }
    }

    $pay = null;
    for ($i = 0; $i < 40; $i++) {
        if ($chain === 'btc') {
            $sats = (int)(ceil($usd / $rate * 1e8 / 100) * 100) + random_int(1, 499);
            $cand = number_format($sats / 1e8, 8, '.', '');
        } else {
            $cand = number_format($usd + random_int(1, 999) / 10000, 4, '.', '');
        }
        // Must be unique among open deposits on this network.
        $taken = q(
            "SELECT 1 FROM deposits WHERE chain=? AND pay_amount=? AND status IN ('pending','expired') AND created_at > NOW() - INTERVAL 1 DAY",
            [$chain, $cand]
        )->fetchColumn();
        if (!$taken) {
            $pay = $cand;
            break;
        }
    }
    if ($pay === null) {
        return ['ok' => false, 'error' => 'Too many deposits right now, please try a slightly different amount.'];
    }

    $minutes = max(10, (int)cfg('crypto.expiry_minutes', 60));
    q(
        'INSERT INTO deposits (user_id, chain, amount, pay_amount, rate, expires_at) VALUES (?,?,?,?,?, NOW() + INTERVAL ' . $minutes . ' MINUTE)',
        [$uid, $chain, dec($usd), $pay, $rate !== null ? dec($rate) : null]
    );
    $dep = q('SELECT * FROM deposits WHERE id=?', [(int)db()->lastInsertId()])->fetch();
    return ['ok' => true, 'deposit' => $dep];
}

// ---------------------------------------------------------------------------
// Crediting
// ---------------------------------------------------------------------------

/**
 * Credit a deposit exactly once. Returns true if this call credited it.
 */
function credit_deposit(int $depositId, string $txid, float $creditUsd): bool
{
    $creditUsd = floor_cents($creditUsd);
    if ($creditUsd <= 0) {
        return false;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $dep = q('SELECT * FROM deposits WHERE id=? FOR UPDATE', [$depositId])->fetch();
        if (!$dep || !in_array($dep['status'], ['pending', 'expired'], true)) {
            $pdo->commit();
            return false;
        }
        q("UPDATE deposits SET status='paid', paid_at=NOW(), txid=?, credited=? WHERE id=?", [$txid, dec($creditUsd), $depositId]);
        q('UPDATE users SET balance = balance + ? WHERE id=?', [dec($creditUsd), $dep['user_id']]);
        $bal = q('SELECT balance FROM users WHERE id=?', [$dep['user_id']])->fetchColumn();
        add_tx((int)$dep['user_id'], 'deposit', $creditUsd, $bal, 'Deposit #' . $depositId . ' (' . chain_info($dep['chain'])['label'] . ')');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $info = chain_info($dep['chain']);
    send(
        $dep['user_id'],
        "✅ <b>Deposit confirmed!</b>\n\n"
        . '🧾 Deposit #' . $depositId . "\n"
        . '🌐 ' . e($info['label']) . "\n"
        . '💵 Credited: <b>' . money($creditUsd) . "</b>\n"
        . '💰 New balance: <b>' . money($bal) . "</b>\n\n"
        . 'You can now buy from the shop.',
        kb([[urlbtn('🔍 View transaction', $info['explorer'] . $txid)], [btn('🛒 Shop', 'shop'), btn('🏠 Dashboard', 'home')]])
    );
    if (cfg('notify_admin_deposits')) {
        notify_admins('💳 Deposit #' . $depositId . ': <b>' . money($creditUsd) . '</b> by <code>' . $dep['user_id'] . '</code> via ' . e($info['label'])
            . "\n🔗 <a href=\"" . e($info['explorer'] . $txid) . '">' . e(substr($txid, 0, 18)) . '…</a>');
    }
    return true;
}

function deposit_value_usd(array $dep, string $amount): float
{
    return $dep['chain'] === 'btc' ? (float)$amount * (float)$dep['rate'] : (float)$amount;
}

/**
 * Handle one incoming transfer to our address.
 * $t = ['txid','idx','amount','time','confirmed']
 * $claim = a deposit the user claims this TXID belongs to (or null for auto-matching).
 * Returns: credited | unconfirmed | used | unmatched | too_old | duplicate
 */
/** Open deposit whose unique amount equals this transfer exactly (the automatic match). */
function find_exact_deposit(string $chain, array $t): ?array
{
    $cands = q(
        "SELECT * FROM deposits WHERE chain=? AND status IN ('pending','expired') AND created_at > NOW() - INTERVAL 1 DAY ORDER BY id DESC",
        [$chain]
    )->fetchAll();
    foreach ($cands as $d) {
        if (norm_dec((string)$d['pay_amount']) === $t['amount'] && $t['time'] >= strtotime($d['created_at']) - 120) {
            return $d;
        }
    }
    return null;
}

/**
 * Handle one incoming transfer to our address.
 * $t = ['txid','idx','amount','time','confirmed']
 * $claim = a deposit the user claims this TXID belongs to (or null for auto-matching).
 * Returns: credited | review | unconfirmed | used | unmatched | too_old | duplicate
 */
function process_transfer(string $chain, array $t, ?array $claim = null): string
{
    if (!$t['confirmed']) {
        return 'unconfirmed';
    }
    $existing = q('SELECT * FROM chain_txs WHERE chain=? AND txid=? AND idx=?', [$chain, $t['txid'], $t['idx']])->fetch();
    if ($existing && $existing['deposit_id']) {
        return 'used';
    }

    if ($claim) {
        // A tx sent before the deposit was created can't belong to it.
        if ($t['time'] < strtotime($claim['created_at']) - 60) {
            return 'too_old';
        }
        // Anti-theft: if the amount is exactly another customer's unique deposit amount,
        // the money is theirs. Credit them, refuse the claimer.
        $owner = find_exact_deposit($chain, $t);
        if ($owner && (int)$owner['id'] !== (int)$claim['id']) {
            process_transfer($chain, $t, null);
            return 'used';
        }
        $exact = norm_dec((string)$claim['pay_amount']) === $t['amount'];
        $credit = $exact ? (float)$claim['amount'] : deposit_value_usd($claim, $t['amount']);

        if (!$exact) {
            // Only auto-approve amounts close to what this user asked to deposit,
            // sent before the deposit expired. Everything else goes to the admin.
            $req = (float)$claim['amount'];
            $tol = max((float)cfg('crypto.claim_tolerance_usd', 2), $req * (float)cfg('crypto.claim_tolerance_pct', 5) / 100);
            $late = $t['time'] > strtotime($claim['expires_at']) + 1800;
            if (abs($credit - $req) > $tol || $late) {
                if (!$existing) {
                    q('INSERT IGNORE INTO chain_txs (chain, txid, idx, amount) VALUES (?,?,?,?)', [$chain, $t['txid'], $t['idx'], $t['amount']]);
                }
                request_claim_review($claim, $t, $credit, $late);
                return 'review';
            }
        }
        $target = $claim;
    } else {
        $target = find_exact_deposit($chain, $t);
        $credit = $target ? (float)$target['amount'] : 0.0;
    }

    if (!$existing) {
        q('INSERT IGNORE INTO chain_txs (chain, txid, idx, amount) VALUES (?,?,?,?)', [$chain, $t['txid'], $t['idx'], $t['amount']]);
    }

    if (!$target) {
        // Unknown payment: tell admins once, if it is recent.
        if (!$existing && $t['time'] > time() - 6 * 3600) {
            $info = chain_info($chain);
            notify_admins('❓ <b>Unmatched payment</b> received: <b>' . e($t['amount']) . ' ' . $info['coin'] . '</b> on ' . e($info['network'])
                . "\n🔗 <a href=\"" . e($info['explorer'] . $t['txid']) . "\">View transaction</a>\n"
                . 'The sender can claim it with <b>Submit TXID</b> on their deposit, or you can credit them manually (Admin → Find User).');
        }
        return 'unmatched';
    }

    return reserve_and_credit($target, $chain, $t['txid'], (int)$t['idx'], $credit);
}

/** Atomically tie a transaction to a deposit and credit it. */
function reserve_and_credit(array $dep, string $chain, string $txid, int $idx, float $credit): string
{
    $st = q('UPDATE chain_txs SET deposit_id=? WHERE chain=? AND txid=? AND idx=? AND deposit_id IS NULL', [$dep['id'], $chain, $txid, $idx]);
    if ($st->rowCount() !== 1) {
        return 'used';
    }
    if (!credit_deposit((int)$dep['id'], $txid, $credit)) {
        q('UPDATE chain_txs SET deposit_id=NULL WHERE chain=? AND txid=? AND idx=?', [$chain, $txid, $idx]);
        return 'duplicate';
    }
    q('UPDATE deposits SET review=0 WHERE id=?', [$dep['id']]);
    return 'credited';
}

/** Put a suspicious TXID claim on hold and ask the admins to approve or reject it. */
function request_claim_review(array $dep, array $t, float $credit, bool $late): void
{
    $st = q(
        "UPDATE deposits SET review=1, claim_txid=?, review_idx=? WHERE id=? AND review=0 AND status IN ('pending','expired')",
        [$t['txid'], $t['idx'], $dep['id']]
    );
    if ($st->rowCount() !== 1) {
        return; // already waiting for review
    }
    $info = chain_info($dep['chain']);
    $u = get_user((int)$dep['user_id']);
    $who = $u && $u['username'] ? '@' . e($u['username']) : e($u['first_name'] ?? '');
    $reasons = [];
    if (abs($credit - (float)$dep['amount']) > 0.009) {
        $reasons[] = 'amount differs from the deposit (asked ' . money($dep['amount']) . ', received ≈ ' . money($credit) . ')';
    }
    if ($late) {
        $reasons[] = 'sent after the deposit expired';
    }
    notify_admins_kb(
        "🕵️ <b>TXID claim needs your approval</b>\n\n"
        . "🧾 Deposit #{$dep['id']} by {$who} (<code>{$dep['user_id']}</code>)\n"
        . '💵 Asked: <b>' . money($dep['amount']) . "</b>\n"
        . '📥 Received: <b>' . e($t['amount']) . ' ' . $info['coin'] . '</b> ≈ <b>' . money($credit) . "</b>\n"
        . '🕐 Deposit created: ' . e($dep['created_at']) . "\n"
        . '🕐 Payment sent: ' . date('Y-m-d H:i:s', $t['time']) . "\n"
        . '⚠️ Why: ' . e(implode('; ', $reasons)) . "\n\n"
        . "🔗 <a href=\"" . e($info['explorer'] . $t['txid']) . "\">Check the transaction</a>\n\n"
        . '<i>Approve only if you are sure this customer sent it (e.g. they showed you a screenshot from their wallet/exchange).</i>',
        kb([[btn('✅ Approve ' . money($credit), 'a:dpa:' . $dep['id']), btn('❌ Reject', 'a:dpr:' . $dep['id'])]])
    );
}

/** Admin approved a claim under review. */
function approve_claim(int $depId): string
{
    $dep = q("SELECT * FROM deposits WHERE id=? AND review=1 AND status IN ('pending','expired')", [$depId])->fetch();
    if (!$dep) {
        return 'gone';
    }
    $tx = q('SELECT * FROM chain_txs WHERE chain=? AND txid=? AND idx=?', [$dep['chain'], $dep['claim_txid'], (int)$dep['review_idx']])->fetch();
    if (!$tx) {
        return 'gone';
    }
    return reserve_and_credit($dep, $dep['chain'], $tx['txid'], (int)$tx['idx'], deposit_value_usd($dep, (string)$tx['amount']));
}

function reject_claim(int $depId): bool
{
    $dep = q('SELECT * FROM deposits WHERE id=? AND review=1', [$depId])->fetch();
    if (!$dep) {
        return false;
    }
    q('UPDATE deposits SET review=0, claim_txid=NULL, review_idx=NULL WHERE id=?', [$depId]);
    send($dep['user_id'], "❌ Your TXID for deposit #{$depId} was not approved. If you think this is a mistake, please contact support with proof of payment.", kb([[btn('🆘 Support', 'sup')]]));
    return true;
}

// ---------------------------------------------------------------------------
// Scanning
// ---------------------------------------------------------------------------

/** Scan an address for new incoming payments. Throttled unless $force. */
function scan_chain(string $chain, bool $force = false): void
{
    $key = 'scan_' . $chain;
    if (!$force && time() - (int)setting_get($key, 0) < 20) {
        return;
    }
    if ((int)q('SELECT GET_LOCK(?, 0)', ['tgshop_' . $key])->fetchColumn() !== 1) {
        return; // another scan is running
    }
    try {
        setting_set($key, time());
        foreach (fetch_incoming($chain) as $t) {
            try {
                process_transfer($chain, $t);
            } catch (Throwable $e) {
                log_error("process_transfer $chain {$t['txid']}: " . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        log_error("scan $chain: " . $e->getMessage());
    } finally {
        q('SELECT RELEASE_LOCK(?)', ['tgshop_' . $key]);
    }
}

function fetch_incoming(string $chain): array
{
    switch ($chain) {
        case 'trc20':
            return trc20_incoming();
        case 'bep20':
            return bep20_incoming();
        case 'btc':
            return btc_incoming();
    }
    return [];
}

/**
 * Verify a TXID the user submitted for a deposit.
 * Returns ['status' => credited|unconfirmed|not_found|used|too_old|duplicate|wrong]
 */
function verify_txid(array $dep, string $txid): array
{
    $chain = $dep['chain'];
    try {
        switch ($chain) {
            case 'trc20':
                $transfers = trc20_tx($txid);
                break;
            case 'bep20':
                $transfers = bep20_tx($txid);
                break;
            case 'btc':
                $transfers = btc_tx($txid);
                break;
            default:
                $transfers = [];
        }
    } catch (Throwable $e) {
        log_error("verify_txid $chain $txid: " . $e->getMessage());
        return ['status' => 'not_found'];
    }
    if (!$transfers) {
        return ['status' => 'not_found'];
    }
    $last = 'not_found';
    foreach ($transfers as $t) {
        $last = process_transfer($chain, $t, $dep);
        if ($last === 'credited') {
            break;
        }
    }
    return ['status' => $last];
}

// ---- TRON (USDT TRC20) via TronGrid ----------------------------------------

function trongrid_headers(): array
{
    $h = ['Accept: application/json'];
    $key = trim((string)cfg('crypto.trc20.trongrid_api_key', ''));
    if ($key !== '') {
        $h[] = 'TRON-PRO-API-KEY: ' . $key;
    }
    return $h;
}

function trc20_incoming(): array
{
    $addr = chain_address('trc20');
    $params = [
        'only_to' => 'true',
        'only_confirmed' => 'true',
        'limit' => 200,
        'contract_address' => USDT_TRC20_CONTRACT,
    ];
    $res = http_request('GET', 'https://api.trongrid.io/v1/accounts/' . rawurlencode($addr) . '/transactions/trc20?' . http_build_query($params), trongrid_headers());
    if (!isset($res['json']['data'])) {
        throw new RuntimeException('TronGrid error: ' . substr($res['raw'], 0, 200));
    }
    $out = [];
    foreach ($res['json']['data'] as $tx) {
        if (($tx['to'] ?? '') !== $addr || ($tx['token_info']['address'] ?? '') !== USDT_TRC20_CONTRACT) {
            continue;
        }
        $out[] = [
            'txid' => strtolower($tx['transaction_id']),
            'idx' => 0,
            'amount' => units_to_dec((string)$tx['value'], (int)($tx['token_info']['decimals'] ?? 6)),
            'time' => (int)floor(($tx['block_timestamp'] ?? time() * 1000) / 1000),
            'confirmed' => true,
        ];
    }
    return $out;
}

/** Base58Check TRON address -> 20-byte hex (no 41 prefix). */
function tron_addr_hex(string $addr): string
{
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $bytes = [0];
    foreach (str_split($addr) as $ch) {
        $carry = strpos($alphabet, $ch);
        if ($carry === false) {
            return '';
        }
        foreach ($bytes as $i => $b) {
            $v = $b * 58 + $carry;
            $bytes[$i] = $v & 0xff;
            $carry = $v >> 8;
        }
        while ($carry > 0) {
            $bytes[] = $carry & 0xff;
            $carry >>= 8;
        }
    }
    $hex = bin2hex(pack('C*', ...array_reverse($bytes)));
    // 21 bytes payload (0x41 + 20) + 4 checksum bytes
    $hex = substr(str_pad($hex, 50, '0', STR_PAD_LEFT), -50);
    return substr($hex, 2, 40);
}

/** Look up one TRON transaction and return USDT transfers to our address. */
function trc20_tx(string $txid): array
{
    $usdtHex = 'a614f803b6fd780986a42c78ec9c7f77e6ded13c';
    $ourTopic = str_pad(tron_addr_hex(chain_address('trc20')), 64, '0', STR_PAD_LEFT);
    $headers = array_merge(trongrid_headers(), ['Content-Type: application/json']);

    // walletsolidity only knows confirmed (solidified) transactions
    $confirmed = true;
    $info = http_request('POST', 'https://api.trongrid.io/walletsolidity/gettransactioninfobyid', $headers, ['value' => $txid])['json'] ?? [];
    if (empty($info['id'])) {
        $confirmed = false;
        $info = http_request('POST', 'https://api.trongrid.io/wallet/gettransactioninfobyid', $headers, ['value' => $txid])['json'] ?? [];
    }
    if (empty($info['id']) || ($info['receipt']['result'] ?? '') !== 'SUCCESS') {
        return [];
    }
    $out = [];
    foreach ($info['log'] ?? [] as $i => $log) {
        if (strtolower($log['address'] ?? '') === $usdtHex
            && ('0x' . strtolower($log['topics'][0] ?? '')) === ERC20_TRANSFER_TOPIC
            && strtolower($log['topics'][2] ?? '') === $ourTopic) {
            $out[] = [
                'txid' => strtolower($txid),
                'idx' => 0,
                'amount' => units_to_dec(hex_to_dec($log['data']), 6),
                'time' => (int)floor(($info['blockTimeStamp'] ?? time() * 1000) / 1000),
                'confirmed' => $confirmed,
            ];
        }
    }
    return $out;
}

// ---- BSC (USDT BEP20) via JSON-RPC ------------------------------------------

function bsc_rpc(string $method, array $params)
{
    $url = (string)cfg('crypto.bep20.rpc', 'https://bsc-rpc.publicnode.com');
    $res = http_request('POST', $url, ['Content-Type: application/json'], [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
    ]);
    if (!is_array($res['json']) || array_key_exists('error', $res['json']) || !array_key_exists('result', $res['json'])) {
        throw new RuntimeException("BSC RPC $method failed: " . substr($res['raw'], 0, 200));
    }
    return $res['json']['result'];
}

function bsc_block_time(string $blockHex): int
{
    static $cache = [];
    if (!isset($cache[$blockHex])) {
        $b = bsc_rpc('eth_getBlockByNumber', [$blockHex, false]);
        $cache[$blockHex] = isset($b['timestamp']) ? (int)hexdec($b['timestamp']) : time();
    }
    return $cache[$blockHex];
}

function bsc_addr_topic(): string
{
    return '0x' . str_pad(substr(chain_address('bep20'), 2), 64, '0', STR_PAD_LEFT);
}

function bsc_log_to_transfer(array $log, bool $confirmed): array
{
    $time = isset($log['blockTimestamp']) ? (int)hexdec($log['blockTimestamp']) : bsc_block_time($log['blockNumber']);
    return [
        'txid' => strtolower($log['transactionHash']),
        'idx' => (int)hexdec($log['logIndex']),
        'amount' => units_to_dec(hex_to_dec($log['data']), 18),
        'time' => $time,
        'confirmed' => $confirmed,
    ];
}

function bep20_incoming(): array
{
    $conf = max(1, (int)cfg('crypto.bep20.confirmations', 15));
    $chunk = max(50, (int)cfg('crypto.bep20.log_chunk', 1000));
    $latest = (int)hexdec(bsc_rpc('eth_blockNumber', []));
    $to = $latest - $conf;

    // Only look back as far as the oldest open deposit (BSC makes ~1 block / 0.75s), max ~2.5h.
    $oldest = q("SELECT MIN(created_at) FROM deposits WHERE chain='bep20' AND status IN ('pending','expired') AND created_at > NOW() - INTERVAL 1 DAY")->fetchColumn();
    $age = $oldest ? max(0, time() - strtotime((string)$oldest)) : 600;
    $window = min(12000, (int)ceil($age / 0.75) + 400);

    $cursor = (int)setting_get('bep20_cursor', 0);
    $from = max($cursor + 1, $to - $window);
    $started = time();
    $out = [];
    while ($from <= $to) {
        $end = min($to, $from + $chunk - 1);
        $logs = bsc_rpc('eth_getLogs', [[
            'fromBlock' => '0x' . dechex($from),
            'toBlock' => '0x' . dechex($end),
            'address' => USDT_BEP20_CONTRACT,
            'topics' => [ERC20_TRANSFER_TOPIC, null, bsc_addr_topic()],
        ]]);
        foreach ((array)$logs as $log) {
            if (!empty($log['removed'])) {
                continue;
            }
            $out[] = bsc_log_to_transfer($log, true);
        }
        setting_set('bep20_cursor', $end);
        $from = $end + 1;
        if (time() - $started > 25) {
            break; // continue on the next run (shared-hosting friendly)
        }
    }
    return $out;
}

function bep20_tx(string $txid): array
{
    $r = bsc_rpc('eth_getTransactionReceipt', [$txid]);
    if (!$r || ($r['status'] ?? '') !== '0x1') {
        return [];
    }
    $conf = max(1, (int)cfg('crypto.bep20.confirmations', 15));
    $latest = (int)hexdec(bsc_rpc('eth_blockNumber', []));
    $confirmed = $latest - (int)hexdec($r['blockNumber']) + 1 >= $conf;
    $out = [];
    foreach ($r['logs'] ?? [] as $log) {
        if (strtolower($log['address'] ?? '') === USDT_BEP20_CONTRACT
            && strtolower($log['topics'][0] ?? '') === ERC20_TRANSFER_TOPIC
            && strtolower($log['topics'][2] ?? '') === bsc_addr_topic()) {
            $log['blockNumber'] = $log['blockNumber'] ?? $r['blockNumber'];
            $out[] = bsc_log_to_transfer($log, $confirmed);
        }
    }
    return $out;
}

// ---- Bitcoin via mempool.space (Esplora API) --------------------------------

function btc_api(): string
{
    return rtrim((string)cfg('crypto.btc.api', 'https://mempool.space/api'), '/');
}

function btc_tip(): int
{
    $res = http_request('GET', btc_api() . '/blocks/tip/height');
    $h = (int)trim($res['raw']);
    if ($h <= 0) {
        throw new RuntimeException('BTC tip height unavailable');
    }
    return $h;
}

function btc_tx_to_transfer(array $tx, int $tip): ?array
{
    $addr = chain_address('btc');
    $sats = 0;
    foreach ($tx['vout'] ?? [] as $o) {
        if (($o['scriptpubkey_address'] ?? '') === $addr) {
            $sats += (int)$o['value'];
        }
    }
    if ($sats <= 0) {
        return null;
    }
    $need = max(1, (int)cfg('crypto.btc.confirmations', 1));
    $isConf = !empty($tx['status']['confirmed']) && ($tip - (int)$tx['status']['block_height'] + 1) >= $need;
    return [
        'txid' => strtolower($tx['txid']),
        'idx' => 0,
        'amount' => units_to_dec((string)$sats, 8),
        'time' => (int)($tx['status']['block_time'] ?? time()),
        'confirmed' => $isConf,
    ];
}

function btc_incoming(): array
{
    $tip = btc_tip();
    $res = http_request('GET', btc_api() . '/address/' . rawurlencode(chain_address('btc')) . '/txs');
    if (!is_array($res['json'])) {
        throw new RuntimeException('BTC API error: ' . substr($res['raw'], 0, 200));
    }
    $out = [];
    foreach ($res['json'] as $tx) {
        $t = btc_tx_to_transfer($tx, $tip);
        if ($t && $t['confirmed']) {
            $out[] = $t;
        }
    }
    return $out;
}

function btc_tx(string $txid): array
{
    $res = http_request('GET', btc_api() . '/tx/' . rawurlencode($txid));
    if (!is_array($res['json']) || empty($res['json']['txid'])) {
        return [];
    }
    $t = btc_tx_to_transfer($res['json'], btc_tip());
    return $t ? [$t] : [];
}

// ---------------------------------------------------------------------------
// Background job (cron) — also safe to call often
// ---------------------------------------------------------------------------

function run_deposit_checks(): array
{
    $report = [];
    q("UPDATE deposits SET status='expired' WHERE status='pending' AND expires_at < NOW()");

    foreach (array_keys(chains_enabled()) as $chain) {
        $open = (int)q(
            "SELECT COUNT(*) FROM deposits WHERE chain=? AND status IN ('pending','expired') AND created_at > NOW() - INTERVAL 1 DAY",
            [$chain]
        )->fetchColumn();
        if ($open > 0) {
            scan_chain($chain, true);
            $report[] = "scanned $chain ($open open)";
        }
    }

    // Re-check TXIDs that users submitted but were not final yet.
    $claims = q(
        "SELECT * FROM deposits WHERE claim_txid IS NOT NULL AND review=0 AND status IN ('pending','expired') AND created_at > NOW() - INTERVAL 1 DAY"
    )->fetchAll();
    foreach ($claims as $d) {
        $r = verify_txid($d, $d['claim_txid']);
        if (in_array($r['status'], ['used', 'too_old', 'duplicate'], true)) {
            q('UPDATE deposits SET claim_txid=NULL WHERE id=?', [$d['id']]);
            send($d['user_id'], "❌ The TXID you submitted for deposit #{$d['id']} could not be accepted (" . txid_status_text($r['status']) . ').');
        }
        $report[] = "claim #{$d['id']}: {$r['status']}";
    }
    return $report;
}

/**
 * Run deposit checks at most once a minute, triggered by normal bot traffic.
 * Makes a cron job optional. The Telegram request is answered first so the bot stays fast.
 */
function maybe_run_background_checks(): void
{
    $now = time();
    q("INSERT IGNORE INTO settings (k, v) VALUES ('cron_last_run', '0')");
    // Atomic claim: only one request per minute wins.
    $st = q("UPDATE settings SET v=? WHERE k='cron_last_run' AND CAST(v AS UNSIGNED) <= ?", [$now, $now - 60]);
    if ($st->rowCount() !== 1) {
        return;
    }
    $open = (int)q("SELECT COUNT(*) FROM deposits WHERE (status IN ('pending','expired') AND created_at > NOW() - INTERVAL 1 DAY)")->fetchColumn();
    if ($open === 0) {
        return;
    }
    finish_request();
    run_deposit_checks();
}

function txid_status_text(string $status): string
{
    return [
        'credited' => 'credited',
        'review' => 'sent to the admin for a quick manual check',
        'unconfirmed' => 'waiting for confirmations',
        'not_found' => 'no payment to our address found in this transaction yet',
        'used' => 'this transaction is already used by another deposit',
        'too_old' => 'this transaction was sent before the deposit was created',
        'duplicate' => 'this deposit was already credited',
    ][$status] ?? $status;
}
