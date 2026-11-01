<?php
/**
 * Paystack webhook — credits wallets automatically.
 * Set this URL in Paystack Dashboard > Settings > API Keys & Webhooks.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

$raw = (string)file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
$calc = hash_hmac('sha512', $raw, (string)cfg('paystack.secret_key'));

if ($sig === '' || !hash_equals($calc, $sig)) {
    log_error('Paystack webhook: bad signature');
    http_response_code(400);
    exit('Bad signature');
}

$event = json_decode($raw, true);
http_response_code(200);

if (($event['event'] ?? '') !== 'charge.success') {
    exit('Ignored');
}

try {
    $ref = (string)($event['data']['reference'] ?? '');
    $dep = q("SELECT * FROM deposits WHERE reference=? AND gateway='ps'", [$ref])->fetch();
    if ($dep && $dep['status'] === 'pending') {
        // Double-check with Paystack's API before crediting.
        paystack_verify_and_credit($dep);
    }
} catch (Throwable $e) {
    log_error('Paystack webhook error: ' . $e->getMessage());
    http_response_code(500);
    exit('Error');
}
echo 'OK';
