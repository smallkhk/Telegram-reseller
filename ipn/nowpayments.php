<?php
/**
 * NOWPayments IPN callback — credits wallets automatically.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

$raw = (string)file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_NOWPAYMENTS_SIG'] ?? '';

if (!nowpayments_verify_signature($raw, $sig)) {
    log_error('NOWPayments IPN: bad signature');
    http_response_code(400);
    exit('Bad signature');
}

$data = json_decode($raw, true);
$ref = (string)($data['order_id'] ?? '');
$status = (string)($data['payment_status'] ?? '');

try {
    $dep = q("SELECT * FROM deposits WHERE reference=? AND gateway='now'", [$ref])->fetch();
    if (!$dep) {
        log_error("NOWPayments IPN: unknown order $ref");
        http_response_code(200);
        exit('Unknown order');
    }

    // Make sure the invoice is for the amount we expect.
    $priceOk = abs((float)($data['price_amount'] ?? 0) - (float)$dep['amount']) < 0.01
        && strtolower((string)($data['price_currency'] ?? '')) === strtolower((string)cfg('currency', 'USD'));

    if (in_array($status, (array)cfg('nowpayments.credit_statuses', ['finished']), true)) {
        if ($priceOk) {
            credit_deposit((int)$dep['id'], (string)($data['payment_id'] ?? ''));
        } else {
            log_error("NOWPayments IPN: amount mismatch for deposit #{$dep['id']}: $raw");
        }
    } elseif ($status === 'partially_paid' && $dep['status'] === 'pending') {
        send($dep['user_id'], "⚠️ Deposit #{$dep['id']}: we received only part of the payment. Please contact support with your deposit number.");
        notify_admins("⚠️ Deposit #{$dep['id']} partially paid by <code>{$dep['user_id']}</code>: paid " . e($data['actually_paid'] ?? '?') . ' ' . e($data['pay_currency'] ?? '') . ' of ' . e($data['pay_amount'] ?? '?') . '. Credit manually if needed.');
    } elseif (in_array($status, ['failed', 'refunded'], true)) {
        mark_deposit((int)$dep['id'], 'failed');
    } elseif ($status === 'expired') {
        mark_deposit((int)$dep['id'], 'expired');
    }
} catch (Throwable $e) {
    log_error('NOWPayments IPN error: ' . $e->getMessage());
    http_response_code(500);
    exit('Error');
}

http_response_code(200);
echo 'OK';
