<?php
declare(strict_types=1);

// ===========================================================================
// Routing
// ===========================================================================

function handle_update(array $u): void
{
    if (isset($u['message'])) {
        handle_message($u['message']);
    } elseif (isset($u['callback_query'])) {
        handle_callback($u['callback_query']);
    }
}

function upsert_user(array $from): array
{
    q(
        'INSERT INTO users (id, username, first_name) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE username=VALUES(username), first_name=VALUES(first_name)',
        [(int)$from['id'], $from['username'] ?? null, mb_substr((string)($from['first_name'] ?? ''), 0, 128)]
    );
    return get_user((int)$from['id']);
}

function get_user(int $id): ?array
{
    $u = q('SELECT * FROM users WHERE id=?', [$id])->fetch();
    return $u ?: null;
}

function set_state(int $uid, ?string $state, $data = null): void
{
    q('UPDATE users SET state=?, state_data=? WHERE id=?', [$state, $data === null ? null : (string)$data, $uid]);
}

function handle_message(array $m): void
{
    if (($m['chat']['type'] ?? '') !== 'private' || empty($m['from'])) {
        return;
    }
    $chatId = (int)$m['chat']['id'];
    $user = upsert_user($m['from']);
    $uid = (int)$user['id'];

    if ($user['is_banned']) {
        send($chatId, '🚫 Your account has been suspended. Contact support if you think this is a mistake.');
        return;
    }

    $text = trim((string)($m['text'] ?? ''));

    if (str_starts_with($text, '/start') || $text === '/menu') {
        set_state($uid, null);
        [$t, $k] = main_view(get_user($uid));
        send($chatId, $t, $k);
        return;
    }
    if ($text === '/cancel') {
        set_state($uid, null);
        [$t, $k] = main_view(get_user($uid));
        send($chatId, "❌ Cancelled.\n\n" . $t, $k);
        return;
    }
    if ($text === '/admin' && is_admin($uid)) {
        set_state($uid, null);
        [$t, $k] = admin_home_view();
        send($chatId, $t, $k);
        return;
    }
    if ($text === '/balance') {
        send($chatId, '💰 Your balance: <b>' . money($user['balance']) . '</b>', kb([[btn('💳 Deposit', 'dep'), btn('🏠 Dashboard', 'home')]]));
        return;
    }

    if (!empty($user['state'])) {
        handle_state($user, $m);
        return;
    }

    [$t, $k] = main_view($user);
    send($chatId, $t, $k);
}

function handle_state(array $user, array $m): void
{
    $chatId = (int)$m['chat']['id'];
    $uid = (int)$user['id'];
    $state = (string)$user['state'];
    $text = trim((string)($m['text'] ?? ''));

    if ($state === 'dep_amount') {
        $amount = parse_amount($text);
        $min = (float)cfg('deposit.min', 1);
        $max = (float)cfg('deposit.max', 10000);
        if ($amount === null || $amount < $min || $amount > $max) {
            send($chatId, '⚠️ Please send a valid amount between <b>' . money($min) . '</b> and <b>' . money($max) . '</b>.', kb([[btn('❌ Cancel', 'dep')]]));
            return;
        }
        set_state($uid, null);
        deposit_method($user, $chatId, null, $amount);
        return;
    }

    if (str_starts_with($state, 'a_')) {
        if (!is_admin($uid)) {
            set_state($uid, null);
            return;
        }
        admin_handle_state($user, $m);
        return;
    }

    set_state($uid, null);
}

function handle_callback(array $cq): void
{
    $GLOBALS['cb_answer'] = null;
    $data = (string)($cq['data'] ?? '');
    $chatId = (int)($cq['message']['chat']['id'] ?? $cq['from']['id']);
    $msgId = isset($cq['message']['message_id']) ? (int)$cq['message']['message_id'] : null;

    try {
        $user = upsert_user($cq['from']);
        $uid = (int)$user['id'];
        if ($user['is_banned']) {
            toast('🚫 Your account has been suspended.', true);
            return;
        }
        // Any button press cancels a pending text input.
        if (!empty($user['state'])) {
            set_state($uid, null);
        }

        $p = explode(':', $data);
        if ($p[0] === 'a') {
            if (!is_admin($uid)) {
                toast('Not allowed.', true);
                return;
            }
            admin_callback($user, $chatId, $msgId, array_slice($p, 1));
            return;
        }

        switch ($p[0]) {
            case 'home':
                [$t, $k] = main_view($user);
                edit($chatId, $msgId, $t, $k);
                break;
            case 'shop':
                [$t, $k] = shop_view();
                edit($chatId, $msgId, $t, $k);
                break;
            case 'cat':
                [$t, $k] = category_view((int)($p[1] ?? 0));
                edit($chatId, $msgId, $t, $k);
                break;
            case 'p':
                [$t, $k] = product_view((int)($p[1] ?? 0));
                edit($chatId, $msgId, $t, $k);
                break;
            case 'b':
                [$t, $k] = confirm_view($user, (int)($p[1] ?? 0), (int)($p[2] ?? 1));
                edit($chatId, $msgId, $t, $k);
                break;
            case 'c':
                do_purchase($user, $chatId, $msgId, (int)($p[1] ?? 0), (int)($p[2] ?? 1), (string)($p[3] ?? ''));
                break;
            case 'dep':
                [$t, $k] = deposit_view($user);
                edit($chatId, $msgId, $t, $k);
                break;
            case 'da':
                deposit_method($user, $chatId, $msgId, (float)($p[1] ?? 0));
                break;
            case 'dc':
                set_state($uid, 'dep_amount');
                edit($chatId, $msgId, '✏️ Send the amount you want to deposit (' . cfg('currency') . ').' . "\n\nMin: <b>" . money(cfg('deposit.min')) . '</b> • Max: <b>' . money(cfg('deposit.max')) . '</b>', kb([[btn('❌ Cancel', 'dep')]]));
                break;
            case 'dg':
                start_deposit($user, $chatId, $msgId, (string)($p[1] ?? ''), (float)($p[2] ?? 0));
                break;
            case 'dk':
                check_deposit($user, (int)($p[1] ?? 0));
                break;
            case 'ord':
                [$t, $k] = orders_view($uid);
                edit($chatId, $msgId, $t, $k);
                break;
            case 'o':
                [$t, $k] = order_view($uid, (int)($p[1] ?? 0));
                edit($chatId, $msgId, $t, $k);
                break;
            case 'tx':
                [$t, $k] = history_view($uid);
                edit($chatId, $msgId, $t, $k);
                break;
            case 'sup':
                [$t, $k] = support_view();
                edit($chatId, $msgId, $t, $k);
                break;
            default:
                toast('Unknown action');
        }
    } finally {
        $a = $GLOBALS['cb_answer'] ?? null;
        tg('answerCallbackQuery', ['callback_query_id' => $cq['id']] + ($a ?: []));
    }
}

// ===========================================================================
// Views
// ===========================================================================

function back_home(): array
{
    return [btn('🏠 Dashboard', 'home')];
}

function main_view(array $user): array
{
    $uid = (int)$user['id'];
    $stats = q('SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS spent FROM orders WHERE user_id=?', [$uid])->fetch();
    $name = e($user['first_name'] ?: ($user['username'] ?: 'there'));

    $text = "🏪 <b>" . e(cfg('shop_name')) . "</b>\n"
        . "👋 Welcome, <b>{$name}</b>!\n\n"
        . "📊 <b>Your Dashboard</b>\n"
        . "━━━━━━━━━━━━━━━\n"
        . '💰 Balance: <b>' . money($user['balance']) . "</b>\n"
        . '📦 Orders: <b>' . (int)$stats['n'] . "</b>\n"
        . '💸 Total spent: <b>' . money($stats['spent']) . "</b>\n"
        . "🆔 Your ID: <code>{$uid}</code>\n"
        . "━━━━━━━━━━━━━━━\n"
        . 'Choose an option below 👇';

    $rows = [
        [btn('🛒 Shop', 'shop'), btn('💳 Deposit', 'dep')],
        [btn('📦 My Orders', 'ord'), btn('📜 History', 'tx')],
        [btn('🆘 Support', 'sup'), btn('🔄 Refresh', 'home')],
    ];
    if (is_admin($uid)) {
        $rows[] = [btn('⚙️ Admin Panel', 'a:home')];
    }
    return [$text, kb($rows)];
}

function shop_view(): array
{
    $cats = q(
        "SELECT c.id, c.name,
                (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id AND p.active=1 AND p.deleted=0) AS n
         FROM categories c WHERE c.active=1 ORDER BY c.sort, c.name"
    )->fetchAll();
    $cats = array_values(array_filter($cats, fn($c) => (int)$c['n'] > 0));

    if (!$cats) {
        return ["🛒 <b>Shop</b>\n\nNo products available right now. Please check back later.", kb([back_home()])];
    }
    $rows = [];
    foreach (array_chunk($cats, 2) as $pair) {
        $rows[] = array_map(fn($c) => btn('📁 ' . $c['name'], 'cat:' . $c['id']), $pair);
    }
    $rows[] = back_home();
    return ["🛒 <b>Shop</b>\n\nChoose a category:", kb($rows)];
}

function product_stock(int $pid): int
{
    return (int)q("SELECT COUNT(*) FROM product_keys WHERE product_id=? AND status='available'", [$pid])->fetchColumn();
}

function category_view(int $cid): array
{
    $cat = q('SELECT * FROM categories WHERE id=? AND active=1', [$cid])->fetch();
    if (!$cat) {
        return shop_view();
    }
    $prods = q(
        "SELECT p.id, p.name, p.price,
                (SELECT COUNT(*) FROM product_keys k WHERE k.product_id=p.id AND k.status='available') AS stock
         FROM products p WHERE p.category_id=? AND p.active=1 AND p.deleted=0 ORDER BY p.name",
        [$cid]
    )->fetchAll();

    $rows = [];
    foreach ($prods as $p) {
        $stock = (int)$p['stock'];
        $label = $p['name'] . ' • ' . money($p['price']) . ($stock > 0 ? " • {$stock} left" : ' • ❌ Sold out');
        $rows[] = [btn($label, 'p:' . $p['id'])];
    }
    $rows[] = [btn('⬅️ Back', 'shop'), btn('🏠 Dashboard', 'home')];
    $text = '📁 <b>' . e($cat['name']) . "</b>\n\n" . ($prods ? 'Choose a product:' : 'No products in this category yet.');
    return [$text, kb($rows)];
}

function product_view(int $pid): array
{
    $p = q('SELECT * FROM products WHERE id=? AND active=1 AND deleted=0', [$pid])->fetch();
    if (!$p) {
        return ['❌ This product is no longer available.', kb([[btn('⬅️ Shop', 'shop')]])];
    }
    $stock = product_stock($pid);
    $text = '📦 <b>' . e($p['name']) . "</b>\n\n"
        . ($p['description'] ? e($p['description']) . "\n\n" : '')
        . '💵 Price: <b>' . money($p['price']) . "</b>\n"
        . '📊 In stock: <b>' . $stock . "</b>\n\n"
        . ($stock > 0 ? 'Select quantity to buy:' : '❌ <b>Sold out.</b> Please check back later.');

    $rows = [];
    if ($stock > 0) {
        $max = min($stock, (int)cfg('max_qty_per_order', 10));
        $qtys = array_values(array_filter([1, 2, 3, 5, 10, 20, 50], fn($q) => $q <= $max));
        foreach (array_chunk($qtys, 4) as $chunk) {
            $rows[] = array_map(fn($q) => btn("🛒 Buy {$q}", "b:{$pid}:{$q}"), $chunk);
        }
    }
    $rows[] = [btn('⬅️ Back', 'cat:' . $p['category_id']), btn('🏠 Dashboard', 'home')];
    return [$text, kb($rows)];
}

function confirm_view(array $user, int $pid, int $qty): array
{
    $p = q('SELECT * FROM products WHERE id=? AND active=1 AND deleted=0', [$pid])->fetch();
    if (!$p || $qty < 1 || $qty > (int)cfg('max_qty_per_order', 10)) {
        return ['❌ This product is no longer available.', kb([[btn('⬅️ Shop', 'shop')]])];
    }
    $stock = product_stock($pid);
    if ($stock < $qty) {
        return ["❌ Only <b>{$stock}</b> left in stock.", kb([[btn('⬅️ Back', 'p:' . $pid)]])];
    }
    $total = round((float)$p['price'] * $qty, 2);
    $bal = (float)$user['balance'];

    $text = "🧾 <b>Confirm your order</b>\n\n"
        . '📦 Product: <b>' . e($p['name']) . "</b>\n"
        . "🔢 Quantity: <b>{$qty}</b>\n"
        . '💵 Total: <b>' . money($total) . "</b>\n"
        . '💰 Your balance: <b>' . money($bal) . "</b>\n";

    if ($bal < $total) {
        $text .= "\n⚠️ <b>Insufficient balance.</b> You need <b>" . money($total - $bal) . '</b> more.';
        return [$text, kb([[btn('💳 Deposit', 'dep')], [btn('⬅️ Back', 'p:' . $pid)]])];
    }

    $token = bin2hex(random_bytes(4));
    q('UPDATE users SET buy_token=? WHERE id=?', [$token, $user['id']]);
    $text .= '💰 Balance after: <b>' . money($bal - $total) . '</b>';
    return [$text, kb([
        [btn('✅ Confirm Purchase', "c:{$pid}:{$qty}:{$token}")],
        [btn('❌ Cancel', 'p:' . $pid)],
    ])];
}

function deposit_view(array $user): array
{
    $presets = (array)cfg('deposit.presets', [10, 20, 50]);
    $rows = [];
    foreach (array_chunk($presets, 3) as $chunk) {
        $rows[] = array_map(fn($a) => btn(money($a), 'da:' . $a), $chunk);
    }
    $rows[] = [btn('✏️ Custom amount', 'dc')];
    $rows[] = back_home();
    $text = "💳 <b>Deposit funds</b>\n\n"
        . '💰 Current balance: <b>' . money($user['balance']) . "</b>\n\n"
        . "Choose how much you want to add to your wallet.\n"
        . 'Payments are confirmed <b>automatically</b> ⚡';
    return [$text, kb($rows)];
}

function deposit_method(array $user, int $chatId, ?int $msgId, float $amount): void
{
    $min = (float)cfg('deposit.min', 1);
    $max = (float)cfg('deposit.max', 10000);
    if ($amount < $min || $amount > $max) {
        edit($chatId, $msgId, '⚠️ Amount must be between ' . money($min) . ' and ' . money($max) . '.', kb([[btn('⬅️ Back', 'dep')]]));
        return;
    }
    $gws = gateways_enabled();
    if (!$gws) {
        edit($chatId, $msgId, '⚠️ Deposits are temporarily unavailable. Please contact support.', kb([back_home()]));
        return;
    }
    if (count($gws) === 1) {
        start_deposit($user, $chatId, $msgId, (string)array_key_first($gws), $amount);
        return;
    }
    $rows = [];
    foreach ($gws as $key => $label) {
        $rows[] = [btn($label, "dg:{$key}:" . dec($amount))];
    }
    $rows[] = [btn('⬅️ Back', 'dep')];
    edit($chatId, $msgId, '💳 Deposit <b>' . money($amount) . "</b>\n\nChoose a payment method:", kb($rows));
}

function start_deposit(array $user, int $chatId, ?int $msgId, string $gw, float $amount): void
{
    $min = (float)cfg('deposit.min', 1);
    $max = (float)cfg('deposit.max', 10000);
    if ($amount < $min || $amount > $max) {
        edit($chatId, $msgId, '⚠️ Invalid amount.', kb([[btn('⬅️ Back', 'dep')]]));
        return;
    }
    edit($chatId, $msgId, '⏳ Creating your payment link…');
    $r = create_deposit($user, $gw, $amount);
    if (!$r['ok']) {
        edit($chatId, $msgId, '❌ ' . e($r['error']), kb([[btn('⬅️ Back', 'dep')], back_home()]));
        return;
    }
    $extra = '';
    if ($gw === 'ps' && strtoupper((string)cfg('paystack.currency')) !== strtoupper((string)cfg('currency'))) {
        $local = round($amount * (float)cfg('paystack.rate', 1), 2);
        $extra = "\n💱 You will pay: <b>" . number_format($local, 2) . ' ' . e(cfg('paystack.currency')) . '</b>';
    }
    $text = "🧾 <b>Deposit #{$r['id']}</b>\n\n"
        . '💵 Amount: <b>' . money($amount) . "</b>\n"
        . '💳 Method: <b>' . gateway_label($gw) . "</b>{$extra}\n\n"
        . "👉 Tap <b>Pay Now</b> to complete the payment.\n"
        . "Your wallet will be credited <b>automatically</b> once the payment is confirmed — you'll get a message here."
        . ($gw === 'now' ? "\n\n<i>Crypto payments may take a few minutes to confirm on the network.</i>" : '');
    edit($chatId, $msgId, $text, kb([
        [urlbtn('💳 Pay Now', $r['url'])],
        [btn('🔄 Check Status', 'dk:' . $r['id'])],
        back_home(),
    ]));
}

function check_deposit(array $user, int $depId): void
{
    $dep = q('SELECT * FROM deposits WHERE id=? AND user_id=?', [$depId, $user['id']])->fetch();
    if (!$dep) {
        toast('Deposit not found.', true);
        return;
    }
    if ($dep['status'] === 'paid') {
        toast('✅ This deposit has already been credited to your wallet.', true);
        return;
    }
    if ($dep['status'] !== 'pending') {
        toast('❌ This deposit is ' . $dep['status'] . '. Please create a new one.', true);
        return;
    }
    if ($dep['gateway'] === 'ps' && paystack_verify_and_credit($dep)) {
        toast('✅ Payment confirmed! Your wallet has been credited.', true);
        return;
    }
    toast("⏳ Payment not confirmed yet.\n\nIf you've already paid, please wait — your wallet is credited automatically once the payment is confirmed.", true);
}

function orders_view(int $uid): array
{
    $orders = q('SELECT * FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 15', [$uid])->fetchAll();
    if (!$orders) {
        return ["📦 <b>My Orders</b>\n\nYou haven't bought anything yet.", kb([[btn('🛒 Shop', 'shop')], back_home()])];
    }
    $rows = [];
    foreach ($orders as $o) {
        $rows[] = [btn("#{$o['id']} • {$o['product_name']} ×{$o['quantity']} • " . money($o['total']), 'o:' . $o['id'])];
    }
    $rows[] = back_home();
    return ["📦 <b>My Orders</b>\n\nTap an order to view your keys again:", kb($rows)];
}

function order_view(int $uid, int $oid): array
{
    $o = q('SELECT * FROM orders WHERE id=?', [$oid])->fetch();
    if (!$o || ((int)$o['user_id'] !== $uid && !is_admin($uid))) {
        return ['❌ Order not found.', kb([[btn('⬅️ Back', 'ord')]])];
    }
    $keys = q('SELECT key_value FROM product_keys WHERE order_id=? ORDER BY id', [$oid])->fetchAll(PDO::FETCH_COLUMN);
    $text = "🧾 <b>Order #{$o['id']}</b>\n"
        . '📦 ' . e($o['product_name']) . " ×{$o['quantity']}\n"
        . '💵 ' . money($o['total']) . "\n"
        . '📅 ' . e($o['created_at']) . "\n\n"
        . "🔑 <b>Keys:</b>\n";
    $keyLines = array_map(fn($k) => '<code>' . e($k) . '</code>', $keys);
    $chunks = chunk_lines($keyLines, 3500 - mb_strlen($text));
    $text .= $chunks[0] ?? '—';
    if (count($chunks) > 1) {
        $text .= "\n\n<i>…list truncated. Contact support for the full list.</i>";
    }
    return [$text, kb([[btn('⬅️ Back', 'ord'), btn('🏠 Dashboard', 'home')]])];
}

function history_view(int $uid): array
{
    $txs = q('SELECT * FROM transactions WHERE user_id=? ORDER BY id DESC LIMIT 20', [$uid])->fetchAll();
    $icons = ['deposit' => '➕', 'purchase' => '🛒', 'admin_credit' => '🎁', 'admin_debit' => '➖', 'refund' => '↩️'];
    $text = "📜 <b>Wallet History</b>\n\n";
    if (!$txs) {
        $text .= 'No transactions yet.';
    }
    foreach ($txs as $t) {
        $sign = (float)$t['amount'] >= 0 ? '+' : '−';
        $text .= ($icons[$t['type']] ?? '•') . ' <b>' . $sign . money(abs((float)$t['amount'])) . '</b> — ' . e($t['note'])
            . "\n   <i>" . e(substr($t['created_at'], 0, 16)) . ' • bal ' . money($t['balance_after']) . "</i>\n";
    }
    return [$text, kb([back_home()])];
}

function support_view(): array
{
    $sup = (string)cfg('support_username', '');
    $text = "🆘 <b>Support</b>\n\nHaving an issue with a deposit or a key? Contact our support team and include your <b>User ID</b> and <b>Order / Deposit number</b>.";
    $rows = [];
    if ($sup !== '') {
        $rows[] = [urlbtn('💬 Contact Support', 'https://t.me/' . ltrim($sup, '@'))];
    }
    $rows[] = back_home();
    return [$text, kb($rows)];
}

// ===========================================================================
// Purchase
// ===========================================================================

/**
 * Atomically: lock stock, deduct balance, create order, assign keys.
 */
function purchase(int $uid, int $pid, int $qty): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Locking the product row serialises concurrent buyers of the same product.
        $prod = q('SELECT * FROM products WHERE id=? AND active=1 AND deleted=0 FOR UPDATE', [$pid])->fetch();
        if (!$prod) {
            throw new RuntimeException('This product is no longer available.');
        }
        $qty = max(1, min($qty, (int)cfg('max_qty_per_order', 10)));
        $keys = q(
            "SELECT id, key_value FROM product_keys WHERE product_id=? AND status='available' ORDER BY id LIMIT " . (int)$qty . ' FOR UPDATE',
            [$pid]
        )->fetchAll();
        if (count($keys) < $qty) {
            throw new RuntimeException('Not enough stock. Only ' . count($keys) . ' left.');
        }
        $total = dec((float)$prod['price'] * $qty);

        $st = q('UPDATE users SET balance = balance - ? WHERE id=? AND balance >= ?', [$total, $uid, $total]);
        if ($st->rowCount() !== 1) {
            throw new RuntimeException('Insufficient balance. Please deposit first.');
        }

        q(
            'INSERT INTO orders (user_id, product_id, product_name, quantity, unit_price, total) VALUES (?,?,?,?,?,?)',
            [$uid, $pid, $prod['name'], $qty, $prod['price'], $total]
        );
        $oid = (int)$pdo->lastInsertId();

        $ids = array_map('intval', array_column($keys, 'id'));
        $in = implode(',', array_fill(0, count($ids), '?'));
        q("UPDATE product_keys SET status='sold', order_id=?, sold_at=NOW() WHERE id IN ($in)", array_merge([$oid], $ids));

        $bal = q('SELECT balance FROM users WHERE id=?', [$uid])->fetchColumn();
        add_tx($uid, 'purchase', -(float)$total, $bal, "Order #{$oid}: {$prod['name']} ×{$qty}");
        $pdo->commit();

        return [
            'ok' => true,
            'order_id' => $oid,
            'product' => $prod['name'],
            'qty' => $qty,
            'total' => $total,
            'balance' => $bal,
            'keys' => array_column($keys, 'key_value'),
        ];
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function do_purchase(array $user, int $chatId, ?int $msgId, int $pid, int $qty, string $token): void
{
    $uid = (int)$user['id'];
    // One-time token stops double-taps from buying twice.
    $st = q('UPDATE users SET buy_token=NULL WHERE id=? AND buy_token=?', [$uid, $token]);
    if ($token === '' || $st->rowCount() !== 1) {
        toast('This confirmation has expired. Please start again.', true);
        return;
    }

    $r = purchase($uid, $pid, $qty);
    if (!$r['ok']) {
        edit($chatId, $msgId, "❌ <b>Purchase failed</b>\n\n" . e($r['error']), kb([
            [btn('💳 Deposit', 'dep'), btn('⬅️ Back', 'p:' . $pid)],
            back_home(),
        ]));
        return;
    }

    $head = "✅ <b>Purchase successful!</b>\n\n"
        . "🧾 Order: <b>#{$r['order_id']}</b>\n"
        . '📦 ' . e($r['product']) . " ×{$r['qty']}\n"
        . '💸 Paid: <b>' . money($r['total']) . "</b>\n"
        . '💰 New balance: <b>' . money($r['balance']) . "</b>\n\n"
        . "🔑 <b>Your key" . ($r['qty'] > 1 ? 's' : '') . ":</b>\n";
    $keyLines = array_map(fn($k) => '<code>' . e($k) . '</code>', $r['keys']);
    $chunks = chunk_lines($keyLines, 3800 - mb_strlen($head));

    // The receipt replaces the confirmation message (no buttons, so it stays put).
    edit($chatId, $msgId, $head . ($chunks[0] ?? ''));
    foreach (array_slice($chunks, 1) as $c) {
        send($chatId, $c);
    }
    send($chatId, '🎉 Thanks for your purchase! You can view these keys anytime under <b>My Orders</b>.', kb([
        [btn('🛒 Continue Shopping', 'shop'), btn('🏠 Dashboard', 'home')],
    ]));

    $left = product_stock($pid);
    if (cfg('notify_admin_sales')) {
        $uname = $user['username'] ? '@' . e($user['username']) : e($user['first_name']);
        notify_admins("🛒 Sale #{$r['order_id']}: " . e($r['product']) . " ×{$r['qty']} — <b>" . money($r['total']) . "</b> by {$uname} (<code>{$uid}</code>)\n📊 Stock left: <b>{$left}</b>");
    } elseif ($left === 0) {
        notify_admins('⚠️ <b>' . e($r['product']) . '</b> is now out of stock.');
    }
}
