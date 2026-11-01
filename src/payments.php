<?php
declare(strict_types=1);

function gateways_enabled(): array
{
    $g = [];
    if (cfg('nowpayments.enabled')) {
        $g['now'] = '🪙 Crypto (BTC, USDT, LTC…)';
    }
    if (cfg('paystack.enabled')) {
        $g['ps'] = '💳 Card / Bank Transfer';
    }
    return $g;
}

function gateway_label(string $gw): string
{
    return ['now' => 'Crypto', 'ps' => 'Card / Bank'][$gw] ?? $gw;
}

/**
 * Create a pending deposit and a payment link.
 * Returns ['ok'=>true,'id'=>int,'url'=>string] or ['ok'=>false,'error'=>string].
 */
function create_deposit(array $user, string $gw, float $amount): array
{
    if (!array_key_exists($gw, gateways_enabled())) {
        return ['ok' => false, 'error' => 'This payment method is not available.'];
    }
    $uid = (int)$user['id'];
    $ref = 'D' . $uid . 'X' . bin2hex(random_bytes(5));
    q(
        'INSERT INTO deposits (user_id, gateway, amount, reference) VALUES (?,?,?,?)',
        [$uid, $gw, dec($amount), $ref]
    );
    $id = (int)db()->lastInsertId();

    $r = $gw === 'now'
        ? nowpayments_create($id, $ref, $amount)
        : paystack_create($id, $ref, $amount, $uid);

    if (!$r['ok']) {
        q("UPDATE deposits SET status='failed' WHERE id=?", [$id]);
        return $r;
    }
    q(
        'UPDATE deposits SET pay_url=?, gateway_ref=?, pay_amount=?, pay_currency=? WHERE id=?',
        [$r['url'], $r['gateway_ref'] ?? null, $r['pay_amount'] ?? null, $r['pay_currency'] ?? null, $id]
    );
    return ['ok' => true, 'id' => $id, 'url' => $r['url']];
}

/**
 * Credit a deposit to the user's wallet exactly once (safe against duplicate IPNs).
 * Returns true if this call credited it.
 */
function credit_deposit(int $depositId, ?string $gatewayRef = null): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $dep = q('SELECT * FROM deposits WHERE id=? FOR UPDATE', [$depositId])->fetch();
        if (!$dep || $dep['status'] === 'paid') {
            $pdo->commit();
            return false;
        }
        q(
            "UPDATE deposits SET status='paid', paid_at=NOW(), gateway_ref=COALESCE(?, gateway_ref) WHERE id=?",
            [$gatewayRef, $depositId]
        );
        q('UPDATE users SET balance = balance + ? WHERE id=?', [$dep['amount'], $dep['user_id']]);
        $bal = q('SELECT balance FROM users WHERE id=?', [$dep['user_id']])->fetchColumn();
        add_tx((int)$dep['user_id'], 'deposit', $dep['amount'], $bal, 'Deposit #' . $depositId . ' (' . gateway_label($dep['gateway']) . ')');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    send(
        $dep['user_id'],
        "✅ <b>Deposit confirmed!</b>\n\n"
        . '💵 Amount: <b>' . money($dep['amount']) . "</b>\n"
        . '💰 New balance: <b>' . money($bal) . "</b>\n\n"
        . 'You can now buy from the shop.',
        kb([[btn('🛒 Shop', 'shop'), btn('🏠 Dashboard', 'home')]])
    );
    if (cfg('notify_admin_deposits')) {
        notify_admins('💳 Deposit #' . $depositId . ': <b>' . money($dep['amount']) . '</b> by <code>' . $dep['user_id'] . '</code> via ' . gateway_label($dep['gateway']));
    }
    return true;
}

function mark_deposit(int $depositId, string $status): void
{
    q("UPDATE deposits SET status=? WHERE id=? AND status='pending'", [$status, $depositId]);
}

// ---------------------------------------------------------------------------
// NOWPayments (crypto)
// ---------------------------------------------------------------------------

function nowpayments_api(): string
{
    return cfg('nowpayments.sandbox') ? 'https://api-sandbox.nowpayments.io/v1' : 'https://api.nowpayments.io/v1';
}

function nowpayments_create(int $id, string $ref, float $amount): array
{
    $base = rtrim((string)cfg('base_url'), '/');
    $res = http_request('POST', nowpayments_api() . '/invoice', [
        'x-api-key: ' . cfg('nowpayments.api_key'),
        'Content-Type: application/json',
    ], [
        'price_amount'      => round($amount, 2),
        'price_currency'    => strtolower((string)cfg('currency', 'USD')),
        'order_id'          => $ref,
        'order_description' => cfg('shop_name') . ' wallet deposit #' . $id,
        'ipn_callback_url'  => $base . '/ipn/nowpayments.php',
        'success_url'       => $base . '/ipn/return.php',
        'cancel_url'        => $base . '/ipn/return.php',
        'is_fixed_rate'     => true,
    ]);
    if (empty($res['json']['invoice_url'])) {
        log_error('NOWPayments invoice failed: ' . $res['raw']);
        return ['ok' => false, 'error' => 'Could not create the crypto invoice. Please try again later.'];
    }
    return ['ok' => true, 'url' => $res['json']['invoice_url'], 'gateway_ref' => (string)($res['json']['id'] ?? '')];
}

function nowpayments_verify_signature(string $raw, string $sig): bool
{
    $data = json_decode($raw, true);
    if (!is_array($data) || $sig === '') {
        return false;
    }
    $sort = function (array &$a) use (&$sort): void {
        ksort($a);
        foreach ($a as &$v) {
            if (is_array($v)) {
                $sort($v);
            }
        }
    };
    $sort($data);
    $sorted = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $calc = hash_hmac('sha512', $sorted, (string)cfg('nowpayments.ipn_secret'));
    return hash_equals($calc, strtolower($sig));
}

// ---------------------------------------------------------------------------
// Paystack (card / bank / USSD)
// ---------------------------------------------------------------------------

function paystack_create(int $id, string $ref, float $amount, int $uid): array
{
    $base = rtrim((string)cfg('base_url'), '/');
    $local = round($amount * (float)cfg('paystack.rate', 1), 2);
    $host = parse_url($base, PHP_URL_HOST) ?: 'example.com';
    $res = http_request('POST', 'https://api.paystack.co/transaction/initialize', [
        'Authorization: Bearer ' . cfg('paystack.secret_key'),
        'Content-Type: application/json',
    ], [
        // Paystack requires an email; Telegram users don't have one, so use a placeholder.
        'email'        => 'tg' . $uid . '@' . $host,
        'amount'       => (int)round($local * 100),
        'currency'     => cfg('paystack.currency', 'NGN'),
        'reference'    => $ref,
        'callback_url' => $base . '/ipn/return.php',
        'metadata'     => ['deposit_id' => $id, 'telegram_id' => $uid],
    ]);
    if (empty($res['json']['status']) || empty($res['json']['data']['authorization_url'])) {
        log_error('Paystack init failed: ' . $res['raw']);
        return ['ok' => false, 'error' => 'Could not start the card payment. Please try again later.'];
    }
    return [
        'ok' => true,
        'url' => $res['json']['data']['authorization_url'],
        'gateway_ref' => $ref,
        'pay_amount' => dec($local),
        'pay_currency' => cfg('paystack.currency', 'NGN'),
    ];
}

/** Verify a Paystack transaction via API and credit it if successful. */
function paystack_verify_and_credit(array $dep): bool
{
    $res = http_request('GET', 'https://api.paystack.co/transaction/verify/' . rawurlencode($dep['reference']), [
        'Authorization: Bearer ' . cfg('paystack.secret_key'),
    ]);
    $d = $res['json']['data'] ?? null;
    if (!$d || ($d['status'] ?? '') !== 'success') {
        return false;
    }
    $expected = (int)round((float)$dep['pay_amount'] * 100);
    if ((int)$d['amount'] < $expected || strtoupper((string)$d['currency']) !== strtoupper((string)$dep['pay_currency'])) {
        log_error('Paystack amount mismatch for deposit #' . $dep['id'] . ': ' . json_encode($d));
        return false;
    }
    return credit_deposit((int)$dep['id'], (string)($d['id'] ?? $dep['reference']));
}
