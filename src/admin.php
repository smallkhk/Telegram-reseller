<?php
declare(strict_types=1);

// ===========================================================================
// Admin panel (inside Telegram)
// ===========================================================================

function admin_home_view(): array
{
    $today = date('Y-m-d 00:00:00');
    $users = (int)q('SELECT COUNT(*) FROM users')->fetchColumn();
    $usersToday = (int)q('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$today])->fetchColumn();
    $balances = q('SELECT COALESCE(SUM(balance),0) FROM users')->fetchColumn();
    $dep = q("SELECT COALESCE(SUM(amount),0) AS total, COALESCE(SUM(CASE WHEN paid_at >= ? THEN amount ELSE 0 END),0) AS today FROM deposits WHERE status='paid'", [$today])->fetch();
    $ord = q('SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS total, COALESCE(SUM(CASE WHEN created_at >= ? THEN total ELSE 0 END),0) AS today FROM orders', [$today])->fetch();
    $stock = (int)q("SELECT COUNT(*) FROM product_keys k JOIN products p ON p.id=k.product_id WHERE k.status='available' AND p.deleted=0")->fetchColumn();

    $text = "⚙️ <b>Admin Panel</b>\n\n"
        . "👥 Users: <b>{$users}</b> (+{$usersToday} today)\n"
        . '💰 Wallet balances: <b>' . money($balances) . "</b>\n"
        . '💳 Deposits: <b>' . money($dep['total']) . '</b> (today ' . money($dep['today']) . ")\n"
        . "🛒 Sales: <b>{$ord['n']}</b> orders • <b>" . money($ord['total']) . '</b> (today ' . money($ord['today']) . ")\n"
        . "🔑 Keys in stock: <b>{$stock}</b>\n"
        . '⭐ Resellers: <b>' . (int)q('SELECT COUNT(*) FROM users WHERE is_reseller=1')->fetchColumn() . '</b>'
        . (($held = (int)q('SELECT COUNT(*) FROM users WHERE is_held=1')->fetchColumn()) ? "\n⏸ Accounts on hold: <b>{$held}</b>" : '')
        . (($rev = (int)q("SELECT COUNT(*) FROM deposits WHERE review=1 AND status IN ('pending','expired')")->fetchColumn()) ? "\n🕵️ TXID claims waiting for you: <b>{$rev}</b>" : '');

    return [$text, kb([
        [btn('🗂 Catalog', 'a:cats'), btn('👤 Find User', 'a:uf')],
        [btn('⭐ Resellers', 'a:rs'), btn('🕵️ TXID Reviews', 'a:rv')],
        [btn('⏸ On Hold', 'a:hl'), btn('💼 Wallets', 'a:wl')],
        [btn('💳 Deposits', 'a:deps'), btn('🧾 Orders', 'a:ords')],
        [btn('📢 Broadcast', 'a:bc'), btn('🔄 Refresh', 'a:home')],
        [btn('🏠 Dashboard', 'home')],
    ])];
}

function admin_cats_view(): array
{
    $cats = q('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id AND p.deleted=0) AS n FROM categories c ORDER BY c.sort, c.name')->fetchAll();
    $rows = [];
    foreach ($cats as $c) {
        $rows[] = [btn(($c['active'] ? '📁 ' : '🙈 ') . $c['name'] . " ({$c['n']})", 'a:c:' . $c['id'])];
    }
    $rows[] = [btn('➕ Add Category', 'a:ca')];
    $rows[] = [btn('⬅️ Admin', 'a:home')];
    return ["🗂 <b>Catalog</b>\n\n" . ($cats ? 'Select a category to manage its products:' : 'No categories yet. Add one to get started.'), kb($rows)];
}

function admin_cat_view(int $cid): array
{
    $c = q('SELECT * FROM categories WHERE id=?', [$cid])->fetch();
    if (!$c) {
        return admin_cats_view();
    }
    $prods = q(
        "SELECT p.*, (SELECT COUNT(*) FROM product_keys k WHERE k.product_id=p.id AND k.status='available') AS stock
         FROM products p WHERE p.category_id=? AND p.deleted=0 ORDER BY p.name",
        [$cid]
    )->fetchAll();
    $rows = [];
    foreach ($prods as $p) {
        $rows[] = [btn(($p['active'] ? '' : '🙈 ') . $p['name'] . ' • ' . money($p['price']) . ($p['reseller_price'] !== null ? ' / ⭐' . money($p['reseller_price']) : '') . ' • ' . $p['stock'] . ' keys', 'a:p:' . $p['id'])];
    }
    $rows[] = [btn('➕ Add Product', 'a:pa:' . $cid)];
    $rows[] = [btn('✏️ Rename', 'a:cr:' . $cid), btn($c['active'] ? '🙈 Hide' : '👁 Show', 'a:ct:' . $cid), btn('🗑 Delete', 'a:cd:' . $cid)];
    $rows[] = [btn('⬅️ Catalog', 'a:cats')];
    $text = '📁 <b>' . e($c['name']) . '</b> ' . ($c['active'] ? '(visible)' : '(hidden)') . "\n\n" . ($prods ? 'Products:' : 'No products yet.');
    return [$text, kb($rows)];
}

function admin_product_view(int $pid): array
{
    $p = q('SELECT p.*, c.name AS cat FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.id=? AND p.deleted=0', [$pid])->fetch();
    if (!$p) {
        return admin_cats_view();
    }
    $avail = product_stock($pid);
    $sold = (int)q("SELECT COUNT(*) FROM product_keys WHERE product_id=? AND status='sold'", [$pid])->fetchColumn();
    $text = '📦 <b>' . e($p['name']) . "</b>\n"
        . '📁 ' . e($p['cat']) . "\n"
        . '💵 Customer price: <b>' . money($p['price']) . "</b>\n"
        . '⭐ Reseller price: <b>' . ($p['reseller_price'] !== null ? money($p['reseller_price']) : 'same as customer') . "</b>\n"
        . "🔑 Available: <b>{$avail}</b> • Sold: <b>{$sold}</b>\n"
        . '👁 Status: <b>' . ($p['active'] ? 'Visible' : 'Hidden') . "</b>\n\n"
        . '📝 ' . ($p['description'] ? e($p['description']) : '<i>No description</i>');
    return [$text, kb([
        [btn('➕ Add Keys', 'a:pk:' . $pid)],
        [btn('💲 Customer Price', 'a:pp:' . $pid), btn('⭐ Reseller Price', 'a:pr:' . $pid)],
        [btn('✏️ Rename', 'a:pn:' . $pid), btn('📝 Description', 'a:pd:' . $pid)],
        [btn($p['active'] ? '🙈 Hide' : '👁 Show', 'a:pt:' . $pid), btn('🧹 Clear Unsold Keys', 'a:px:' . $pid)],
        [btn('🗑 Delete Product', 'a:pdel:' . $pid)],
        [btn('⬅️ Back', 'a:c:' . $p['category_id'])],
    ])];
}

function admin_user_view(int $uid): array
{
    $u = get_user($uid);
    if (!$u) {
        return ['❌ User not found.', kb([[btn('🔎 Search again', 'a:uf'), btn('⬅️ Admin', 'a:home')]])];
    }
    $o = q('SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS s FROM orders WHERE user_id=?', [$uid])->fetch();
    $d = q("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE user_id=? AND status='paid'", [$uid])->fetchColumn();
    $text = "👤 <b>User</b>\n\n"
        . "🆔 <code>{$u['id']}</code>\n"
        . '👋 ' . e($u['first_name']) . ($u['username'] ? ' (@' . e($u['username']) . ')' : '') . "\n"
        . '💰 Balance: <b>' . money($u['balance']) . "</b>\n"
        . '💳 Deposited: <b>' . money($d) . "</b>\n"
        . "🛒 Orders: <b>{$o['n']}</b> (" . money($o['s']) . ")\n"
        . '📅 Joined: ' . e($u['created_at']) . "\n"
        . '👤 Type: <b>' . ($u['is_reseller'] ? '⭐ Reseller' : 'Customer') . "</b>\n"
        . ($u['is_held'] ? '⏸ <b>ON HOLD</b>: ' . e($u['hold_reason']) . "\n" : '')
        . '🚦 Status: <b>' . ($u['is_banned'] ? 'Banned' : 'Active') . '</b>';
    return [$text, kb([
        [btn('➕ Add Balance', 'a:ub:' . $uid), btn('➖ Deduct Balance', 'a:ud:' . $uid)],
        [btn($u['is_reseller'] ? '👤 Make Normal Customer' : '⭐ Make Reseller', 'a:ures:' . $uid)],
        [$u['is_held'] ? btn('✅ Release Hold', 'a:urel:' . $uid) : btn('⏸ Put On Hold', 'a:uhold:' . $uid)],
        [btn($u['is_banned'] ? '✅ Unban' : '🚫 Ban', 'a:uban:' . $uid)],
        [btn('⬅️ Admin', 'a:home')],
    ])];
}

function admin_callback(array $user, int $chatId, ?int $msgId, array $p): void
{
    $uid = (int)$user['id'];
    $action = $p[0] ?? 'home';
    $id = (int)($p[1] ?? 0);
    $cancel = fn(string $back) => kb([[btn('❌ Cancel', $back)]]);

    switch ($action) {
        case 'home':
            [$t, $k] = admin_home_view();
            break;
        case 'cats':
            [$t, $k] = admin_cats_view();
            break;
        case 'c':
            [$t, $k] = admin_cat_view($id);
            break;
        case 'ca':
            set_state($uid, 'a_cat_add');
            [$t, $k] = ['📁 Send the <b>name</b> of the new category:', $cancel('a:cats')];
            break;
        case 'cr':
            set_state($uid, 'a_cat_rename', $id);
            [$t, $k] = ['✏️ Send the new <b>name</b> for this category:', $cancel('a:c:' . $id)];
            break;
        case 'ct':
            q('UPDATE categories SET active = 1 - active WHERE id=?', [$id]);
            [$t, $k] = admin_cat_view($id);
            break;
        case 'cd':
            $n = (int)q('SELECT COUNT(*) FROM products WHERE category_id=? AND deleted=0', [$id])->fetchColumn();
            if ($n > 0) {
                toast('Delete or move the products in this category first.', true);
                return;
            }
            q('DELETE FROM categories WHERE id=?', [$id]);
            toast('Category deleted.');
            [$t, $k] = admin_cats_view();
            break;
        case 'pa':
            set_state($uid, 'a_prod_add', $id);
            [$t, $k] = [
                "📦 <b>New product</b>\n\nSend it in this format:\n<code>Name | Price | Description</code>\n\nExample:\n<code>Windows 11 Pro Key | 9.99 | Lifetime retail key, global activation</code>\n\n<i>Description is optional.</i>",
                $cancel('a:c:' . $id),
            ];
            break;
        case 'p':
            [$t, $k] = admin_product_view($id);
            break;
        case 'pk':
            set_state($uid, 'a_keys', $id);
            [$t, $k] = [
                "🔑 <b>Add keys</b>\n\nSend the keys <b>one per line</b> as a message, or upload a <b>.txt</b> file with one key per line.\n\nDuplicates are skipped automatically.",
                $cancel('a:p:' . $id),
            ];
            break;
        case 'pr':
            set_state($uid, 'a_rprice', $id);
            [$t, $k] = ["⭐ Send the <b>reseller price</b> (e.g. <code>7.50</code>).\n\nSend <code>-</code> to remove it (resellers then pay the normal price).", $cancel('a:p:' . $id)];
            break;
        case 'ures':
            $target = get_user($id);
            if (!$target) {
                toast('User not found.', true);
                return;
            }
            $new = $target['is_reseller'] ? 0 : 1;
            q('UPDATE users SET is_reseller=? WHERE id=?', [$new, $id]);
            send($id, $new
                ? "⭐ <b>Congratulations! You are now a reseller.</b>\n\nYou now see special reseller prices in the shop."
                : 'ℹ️ Your account was changed back to a normal customer account.', kb([[btn('🛒 Shop', 'shop')]]));
            toast($new ? 'User is now a reseller ⭐' : 'User is now a normal customer');
            [$t, $k] = admin_user_view($id);
            break;
        case 'rs':
            [$t, $k] = admin_resellers_view();
            break;
        case 'rv':
            [$t, $k] = admin_reviews_view();
            break;
        case 'dpe':
            set_state($uid, 'a_depamt', $id);
            send($chatId, "✏️ How much should deposit #{$id} be credited (in " . e(cfg('currency')) . ')? Send a number, e.g. <code>9.50</code>', $cancel('a:rv'));
            return;
        case 'tc':
            $dep = (int)($p[2] ?? 0);
            $r = admin_credit_tx($id, $dep);
            $msg = $r === 'credited' ? "✅ Payment credited to deposit #{$dep}." : "⚠️ Could not credit: " . ($r === 'used' ? 'payment already used.' : ($r === 'duplicate' ? 'deposit already paid.' : 'deposit is no longer open.'));
            toast($msg, true);
            edit($chatId, $msgId, $msg, kb([[btn('⚙️ Admin Panel', 'a:home')]]));
            return;
        case 'urel':
            q('UPDATE users SET is_held=0, hold_reason=NULL WHERE id=?', [$id]);
            send($id, '✅ <b>Your account is active again.</b> Thanks for your patience!', kb([[btn('🏠 Dashboard', 'home')]]));
            toast('Hold released ✅');
            [$t, $k] = admin_user_view($id);
            break;
        case 'uhold':
            if (is_admin($id)) {
                toast('You cannot hold an admin.', true);
                return;
            }
            q("UPDATE users SET is_held=1, hold_reason='Put on hold by admin' WHERE id=?", [$id]);
            send($id, "⏸ <b>Your account is on hold.</b> An admin is reviewing it.", kb([[btn('🆘 Contact Support', 'sup')]]));
            toast('Account on hold ⏸');
            [$t, $k] = admin_user_view($id);
            break;
        case 'hl':
            [$t, $k] = admin_held_view();
            break;
        case 'wl':
            [$t, $k] = admin_wallets_view();
            break;
        case 'we':
            $chain = (string)($p[1] ?? '');
            if (!in_array($chain, ['trc20', 'bep20', 'btc'], true)) {
                return;
            }
            set_state($uid, 'a_wallet', $chain);
            $info = chain_info($chain);
            $hint = ['trc20' => 'starts with T', 'bep20' => 'starts with 0x', 'btc' => 'starts with bc1, 1 or 3'][$chain];
            [$t, $k] = ["✏️ <b>New {$info['label']} address</b>\n\nPaste the address ({$hint}).\n\n⚠️ Copy-paste it from your wallet — never type it by hand.", $cancel('a:wl')];
            break;
        case 'wok':
            // Confirmed: the pending address is kept in the admin's state while they confirm.
            if (($user['state'] ?? '') !== 'a_wallet_confirm' || !str_contains((string)$user['state_data'], '|')) {
                toast('This confirmation expired. Please start again.', true);
                [$t, $k] = admin_wallets_view();
                break;
            }
            [$chain, $addr] = explode('|', (string)$user['state_data'], 2);
            $old = (string)cfg("crypto.$chain.address");
            if (!address_valid($chain, $addr) || !save_config_value(['crypto', $chain, 'address'], $addr)) {
                toast('Could not save. Check that config.php is writable (permission 640 or 644).', true);
                [$t, $k] = admin_wallets_view();
                break;
            }
            if (!cfg("crypto.$chain.enabled")) {
                save_config_value(['crypto', $chain, 'enabled'], true);
            }
            $who = $user['username'] ? '@' . e($user['username']) : e($user['first_name']);
            notify_admins("🔐 <b>Wallet address changed</b> by {$who} (<code>{$uid}</code>)\n\n"
                . '🌐 ' . e(chain_info($chain)['label']) . "\n"
                . '❌ Old: <code>' . e($old ?: '—') . "</code>\n"
                . '✅ New: <code>' . e($addr) . "</code>\n\n"
                . "<i>If you did not expect this, change it back immediately in Admin → 💼 Wallets.</i>");
            toast('✅ Address saved');
            [$t, $k] = admin_wallets_view();
            break;
        case 'wt':
            $chain = (string)($p[1] ?? '');
            if (!in_array($chain, ['trc20', 'bep20', 'btc'], true)) {
                return;
            }
            $on = !cfg("crypto.$chain.enabled");
            if ($on && !address_valid($chain, (string)cfg("crypto.$chain.address"))) {
                toast('Set an address for this network first.', true);
                [$t, $k] = admin_wallets_view();
                break;
            }
            if (!save_config_value(['crypto', $chain, 'enabled'], $on)) {
                toast('Could not save. Check that config.php is writable.', true);
            } else {
                toast($on ? '✅ Network turned on' : '⛔ Network turned off');
            }
            [$t, $k] = admin_wallets_view();
            break;
        case 'dpa':
        case 'dpr':
            if ($action === 'dpa') {
                $r = approve_claim($id);
                $msg = ['credited' => "✅ Deposit #{$id} approved and credited.", 'used' => "⚠️ Deposit #{$id}: that transaction was already used.", 'duplicate' => "⚠️ Deposit #{$id} was already credited."][$r] ?? "⚠️ Deposit #{$id} is no longer waiting for review.";
            } else {
                $msg = reject_claim($id) ? "❌ Deposit #{$id} claim rejected. The customer was told." : "⚠️ Deposit #{$id} is no longer waiting for review.";
            }
            toast(strip_tags($msg), true);
            // Replace the buttons on the review message with the result.
            edit($chatId, $msgId, $msg, kb([[btn('⚙️ Admin Panel', 'a:home')]]));
            return;
        case 'pp':
            set_state($uid, 'a_price', $id);
            [$t, $k] = ['💲 Send the new <b>price</b> (e.g. <code>9.99</code>):', $cancel('a:p:' . $id)];
            break;
        case 'pn':
            set_state($uid, 'a_pname', $id);
            [$t, $k] = ['✏️ Send the new <b>product name</b>:', $cancel('a:p:' . $id)];
            break;
        case 'pd':
            set_state($uid, 'a_pdesc', $id);
            [$t, $k] = ['📝 Send the new <b>description</b> (send <code>-</code> to remove it):', $cancel('a:p:' . $id)];
            break;
        case 'pt':
            q('UPDATE products SET active = 1 - active WHERE id=?', [$id]);
            [$t, $k] = admin_product_view($id);
            break;
        case 'px':
            $n = product_stock($id);
            [$t, $k] = ["🧹 Delete all <b>{$n}</b> unsold keys of this product? This cannot be undone.", kb([
                [btn('✅ Yes, clear them', 'a:pxy:' . $id)],
                [btn('❌ No', 'a:p:' . $id)],
            ])];
            break;
        case 'pxy':
            $n = q("DELETE FROM product_keys WHERE product_id=? AND status='available'", [$id])->rowCount();
            toast("Removed {$n} keys.");
            [$t, $k] = admin_product_view($id);
            break;
        case 'pdel':
            [$t, $k] = ["🗑 Delete this product? Unsold keys will be removed. Past orders stay visible to buyers.", kb([
                [btn('✅ Yes, delete', 'a:pdy:' . $id)],
                [btn('❌ No', 'a:p:' . $id)],
            ])];
            break;
        case 'pdy':
            $cid = (int)q('SELECT category_id FROM products WHERE id=?', [$id])->fetchColumn();
            q('UPDATE products SET deleted=1, active=0 WHERE id=?', [$id]);
            q("DELETE FROM product_keys WHERE product_id=? AND status='available'", [$id]);
            toast('Product deleted.');
            [$t, $k] = admin_cat_view($cid);
            break;
        case 'uf':
            set_state($uid, 'a_user_find');
            [$t, $k] = ['🔎 Send the user\'s <b>Telegram ID</b> or <b>@username</b>:', $cancel('a:home')];
            break;
        case 'u':
            [$t, $k] = admin_user_view($id);
            break;
        case 'ub':
            set_state($uid, 'a_bal_add', $id);
            [$t, $k] = ['➕ Send the amount to <b>add</b> to this user\'s balance:', $cancel('a:u:' . $id)];
            break;
        case 'ud':
            set_state($uid, 'a_bal_sub', $id);
            [$t, $k] = ['➖ Send the amount to <b>deduct</b> from this user\'s balance:', $cancel('a:u:' . $id)];
            break;
        case 'uban':
            if (is_admin($id)) {
                toast('You cannot ban an admin.', true);
                return;
            }
            q('UPDATE users SET is_banned = 1 - is_banned WHERE id=?', [$id]);
            [$t, $k] = admin_user_view($id);
            break;
        case 'deps':
            [$t, $k] = admin_deposits_view();
            break;
        case 'ords':
            [$t, $k] = admin_orders_view();
            break;
        case 'bc':
            set_state($uid, 'a_broadcast');
            [$t, $k] = ["📢 <b>Broadcast</b>\n\nSend the message (text, photo, video…) you want to send to <b>all users</b>.", $cancel('a:home')];
            break;
        default:
            toast('Unknown action');
            return;
    }
    edit($chatId, $msgId, $t, $k);
}

function admin_resellers_view(): array
{
    $rows = q('SELECT * FROM users WHERE is_reseller=1 ORDER BY created_at DESC LIMIT 50')->fetchAll();
    $kb = [];
    foreach ($rows as $u) {
        $kb[] = [btn('⭐ ' . ($u['username'] ? '@' . $u['username'] : $u['first_name']) . ' • ' . money($u['balance']), 'a:u:' . $u['id'])];
    }
    $kb[] = [btn('👤 Find User to promote', 'a:uf')];
    $kb[] = [btn('⬅️ Admin', 'a:home')];
    $text = "⭐ <b>Resellers</b>\n\n" . ($rows
        ? 'Tap a reseller to manage them.'
        : "No resellers yet.\n\nTo make someone a reseller: tap <b>Find User</b>, send their @username or ID, then tap <b>⭐ Make Reseller</b>. (They must have started the bot first.)");
    return [$text, kb($kb)];
}

function admin_wallets_view(): array
{
    $text = "💼 <b>Deposit wallets</b>\n\nCustomers pay to these addresses:\n";
    $kb = [];
    foreach (['trc20', 'bep20', 'btc'] as $c) {
        $info = chain_info($c);
        $addr = (string)cfg("crypto.$c.address");
        $on = cfg("crypto.$c.enabled") && $addr !== '';
        $text .= "\n" . ($on ? '✅' : '⛔') . ' <b>' . e($info['label']) . "</b>\n"
            . ($addr !== '' ? '<code>' . e($addr) . '</code>' : '<i>no address set</i>') . "\n";
        $kb[] = [btn('✏️ ' . chain_short($c), 'a:we:' . $c), btn($on ? '⛔ Turn off' : '✅ Turn on', 'a:wt:' . $c)];
    }
    $pending = (int)q("SELECT COUNT(*) FROM deposits WHERE status='pending'")->fetchColumn();
    $text .= "\n<i>Changing an address is safe: deposits that already showed the old address are still watched until they finish"
        . ($pending ? " ({$pending} open right now)" : '') . '.</i>';
    $kb[] = [btn('⬅️ Admin', 'a:home')];
    return [$text, kb($kb)];
}

function admin_held_view(): array
{
    $rows = q('SELECT * FROM users WHERE is_held=1 ORDER BY id DESC LIMIT 50')->fetchAll();
    if (!$rows) {
        return ["⏸ <b>On Hold</b>\n\nNo accounts are on hold. 👍", kb([[btn('⬅️ Admin', 'a:home')]])];
    }
    $text = "⏸ <b>Accounts on hold</b>\n";
    $kb = [];
    foreach ($rows as $u) {
        $name = $u['username'] ? '@' . $u['username'] : ($u['first_name'] ?: (string)$u['id']);
        $text .= "\n• " . e($name) . " (<code>{$u['id']}</code>) — " . e($u['hold_reason']);
        $kb[] = [btn('👤 ' . $name, 'a:u:' . $u['id']), btn('✅ Release', 'a:urel:' . $u['id'])];
    }
    $kb[] = [btn('⬅️ Admin', 'a:home')];
    return [$text, kb($kb)];
}

function admin_reviews_view(): array
{
    $rows = q("SELECT d.*, t.amount AS rx FROM deposits d LEFT JOIN chain_txs t ON t.chain=d.chain AND t.txid=d.claim_txid AND t.idx=d.review_idx WHERE d.review=1 AND d.status IN ('pending','expired') ORDER BY d.id DESC LIMIT 20")->fetchAll();
    if (!$rows) {
        return ["🕵️ <b>TXID Reviews</b>\n\nNothing waiting for approval. 👍", kb([[btn('⬅️ Admin', 'a:home')]])];
    }
    $text = "🕵️ <b>TXID claims waiting for approval</b>\n";
    $kb = [];
    foreach ($rows as $d) {
        $info = chain_info($d['chain']);
        $text .= "\n#{$d['id']} • user <code>{$d['user_id']}</code> • asked " . money($d['amount']) . ' • received ' . e(norm_dec((string)$d['rx'])) . ' ' . $info['coin']
            . ' • <a href="' . e($info['explorer'] . $d['claim_txid']) . '">tx</a>';
        $kb[] = [btn("✅ #{$d['id']}", 'a:dpa:' . $d['id']), btn("✏️ #{$d['id']}", 'a:dpe:' . $d['id']), btn("❌ #{$d['id']}", 'a:dpr:' . $d['id'])];
    }
    $kb[] = [btn('⬅️ Admin', 'a:home')];
    return [$text, kb($kb)];
}

function admin_deposits_view(): array
{
    $rows = q('SELECT * FROM deposits ORDER BY id DESC LIMIT 20')->fetchAll();
    $icons = ['pending' => '⏳', 'paid' => '✅', 'cancelled' => '❌', 'expired' => '⌛'];
    $text = "💳 <b>Recent deposits</b>\n\n";
    foreach ($rows as $d) {
        $text .= ($icons[$d['status']] ?? '•') . " #{$d['id']} • <b>" . money($d['status'] === 'paid' ? $d['credited'] : $d['amount']) . '</b> • '
            . norm_dec((string)$d['pay_amount']) . ' ' . chain_short($d['chain'])
            . " • <code>{$d['user_id']}</code>\n   <i>" . e(substr($d['created_at'], 0, 16)) . "</i>\n";
    }
    if (!$rows) {
        $text .= 'No deposits yet.';
    }
    return [$text, kb([[btn('🔄 Refresh', 'a:deps'), btn('⬅️ Admin', 'a:home')]])];
}

function admin_orders_view(): array
{
    $rows = q('SELECT * FROM orders ORDER BY id DESC LIMIT 20')->fetchAll();
    $text = "🧾 <b>Recent orders</b>\n\n";
    foreach ($rows as $o) {
        $text .= "#{$o['id']} • " . e($o['product_name']) . " ×{$o['quantity']} • <b>" . money($o['total']) . "</b> • <code>{$o['user_id']}</code>\n   <i>" . e(substr($o['created_at'], 0, 16)) . "</i>\n";
    }
    if (!$rows) {
        $text .= 'No orders yet.';
    }
    return [$text, kb([[btn('🔄 Refresh', 'a:ords'), btn('⬅️ Admin', 'a:home')]])];
}

/** Adjust a user's wallet by a signed amount. Returns new balance or null if it would go negative. */
function admin_adjust_balance(int $uid, float $amount, int $adminId): ?string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = q('UPDATE users SET balance = balance + ? WHERE id=? AND balance + ? >= 0', [dec($amount), $uid, dec($amount)]);
        if ($st->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }
        $bal = (string)q('SELECT balance FROM users WHERE id=?', [$uid])->fetchColumn();
        add_tx($uid, $amount >= 0 ? 'admin_credit' : 'admin_debit', $amount, $bal, $amount >= 0 ? 'Added by admin' : 'Deducted by admin');
        $pdo->commit();
        return $bal;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function admin_handle_state(array $user, array $m): void
{
    $chatId = (int)$m['chat']['id'];
    $uid = (int)$user['id'];
    $state = (string)$user['state'];
    $sid = (int)$user['state_data'];
    $text = trim((string)($m['text'] ?? ''));

    switch ($state) {
        case 'a_cat_add':
            if ($text === '') {
                send($chatId, 'Please send a text name.');
                return;
            }
            q('INSERT INTO categories (name) VALUES (?)', [mb_substr($text, 0, 100)]);
            set_state($uid, null);
            [$t, $k] = admin_cat_view((int)db()->lastInsertId());
            send($chatId, "✅ Category created.\n\n" . $t, $k);
            return;

        case 'a_cat_rename':
            if ($text === '') {
                return;
            }
            q('UPDATE categories SET name=? WHERE id=?', [mb_substr($text, 0, 100), $sid]);
            set_state($uid, null);
            [$t, $k] = admin_cat_view($sid);
            send($chatId, "✅ Renamed.\n\n" . $t, $k);
            return;

        case 'a_prod_add':
            $parts = array_map('trim', explode('|', $text, 3));
            $price = isset($parts[1]) ? parse_amount($parts[1]) : null;
            if (count($parts) < 2 || $parts[0] === '' || $price === null) {
                send($chatId, "⚠️ Wrong format. Use:\n<code>Name | Price | Description</code>", kb([[btn('❌ Cancel', 'a:c:' . $sid)]]));
                return;
            }
            q(
                'INSERT INTO products (category_id, name, price, description) VALUES (?,?,?,?)',
                [$sid, mb_substr($parts[0], 0, 150), dec($price), ($parts[2] ?? '') !== '' ? $parts[2] : null]
            );
            $pid = (int)db()->lastInsertId();
            set_state($uid, 'a_keys', $pid);
            send($chatId, "✅ Product created!\n\n🔑 Now send the keys for it (one per line, or upload a .txt file).", kb([[btn('⏭ Skip for now', 'a:p:' . $pid)]]));
            return;

        case 'a_keys':
            $raw = $text;
            if (!empty($m['document'])) {
                $raw = admin_download_text_file($m['document']);
                if ($raw === null) {
                    send($chatId, '⚠️ Could not read that file. Please upload a plain .txt file under 5 MB.');
                    return;
                }
            }
            $added = admin_add_keys($sid, $raw);
            set_state($uid, null);
            [$t, $k] = admin_product_view($sid);
            send($chatId, "✅ Added <b>{$added['added']}</b> keys" . ($added['skipped'] ? " (skipped {$added['skipped']} duplicates)" : '') . ".\n\n" . $t, $k);
            return;

        case 'a_price':
            $price = parse_amount($text);
            if ($price === null) {
                send($chatId, '⚠️ Send a valid price, e.g. <code>9.99</code>');
                return;
            }
            q('UPDATE products SET price=? WHERE id=?', [dec($price), $sid]);
            set_state($uid, null);
            [$t, $k] = admin_product_view($sid);
            send($chatId, "✅ Price updated.\n\n" . $t, $k);
            return;

        case 'a_depamt':
            $amount = parse_amount($text);
            if ($amount === null) {
                send($chatId, '⚠️ Send a valid amount, e.g. <code>9.50</code>');
                return;
            }
            set_state($uid, null);
            $r = approve_claim($sid, $amount);
            send($chatId, $r === 'credited'
                ? "✅ Deposit #{$sid} credited with <b>" . money($amount) . '</b>.'
                : "⚠️ Deposit #{$sid} is no longer waiting for review.", kb([[btn('🕵️ TXID Reviews', 'a:rv'), btn('⚙️ Admin', 'a:home')]]));
            return;

        case 'a_wallet':
            $chain = (string)$user['state_data'];
            $addr = normalize_address($chain, preg_replace('/\s+/', '', $text));
            if (!address_valid($chain, $addr)) {
                send($chatId, '⚠️ That is not a valid ' . e(chain_info($chain)['label']) . ' address. Please paste it again:', kb([[btn('❌ Cancel', 'a:wl')]]));
                return;
            }
            set_state($uid, 'a_wallet_confirm', $chain . '|' . $addr);
            send($chatId, "🔍 <b>Please double-check the new address</b>\n\n"
                . '🌐 ' . e(chain_info($chain)['label']) . "\n"
                . '<code>' . e($addr) . "</code>\n\n"
                . "Compare the first and last 6 characters with your wallet:\n"
                . '<b>' . e(substr($addr, 0, 6)) . '</b> … <b>' . e(substr($addr, -6)) . "</b>\n\n"
                . 'Customers will pay to this address from now on.', kb([
                    [btn('✅ Yes, save it', 'a:wok')],
                    [btn('❌ Cancel', 'a:wl')],
                ]));
            return;

        case 'a_rprice':
            if ($text === '-') {
                q('UPDATE products SET reseller_price=NULL WHERE id=?', [$sid]);
            } else {
                $price = parse_amount($text);
                if ($price === null) {
                    send($chatId, '⚠️ Send a valid price, e.g. <code>7.50</code>, or <code>-</code> to remove.');
                    return;
                }
                q('UPDATE products SET reseller_price=? WHERE id=?', [dec($price), $sid]);
            }
            set_state($uid, null);
            [$t, $k] = admin_product_view($sid);
            send($chatId, "✅ Reseller price updated.\n\n" . $t, $k);
            return;

        case 'a_pname':
            if ($text === '') {
                return;
            }
            q('UPDATE products SET name=? WHERE id=?', [mb_substr($text, 0, 150), $sid]);
            set_state($uid, null);
            [$t, $k] = admin_product_view($sid);
            send($chatId, "✅ Name updated.\n\n" . $t, $k);
            return;

        case 'a_pdesc':
            q('UPDATE products SET description=? WHERE id=?', [$text === '-' || $text === '' ? null : $text, $sid]);
            set_state($uid, null);
            [$t, $k] = admin_product_view($sid);
            send($chatId, "✅ Description updated.\n\n" . $t, $k);
            return;

        case 'a_user_find':
            $found = ctype_digit($text)
                ? get_user((int)$text)
                : (q('SELECT * FROM users WHERE username=?', [ltrim($text, '@')])->fetch() ?: null);
            set_state($uid, null);
            [$t, $k] = admin_user_view($found ? (int)$found['id'] : 0);
            send($chatId, $t, $k);
            return;

        case 'a_bal_add':
        case 'a_bal_sub':
            $amount = parse_amount($text);
            if ($amount === null) {
                send($chatId, '⚠️ Send a valid amount, e.g. <code>10</code>');
                return;
            }
            $signed = $state === 'a_bal_add' ? $amount : -$amount;
            $bal = admin_adjust_balance($sid, $signed, $uid);
            set_state($uid, null);
            if ($bal === null) {
                send($chatId, '❌ Not possible — balance cannot go below zero.');
            } else {
                send($sid, ($signed >= 0 ? '🎁 <b>' . money($amount) . '</b> was added to your wallet.' : '➖ <b>' . money($amount) . '</b> was deducted from your wallet.') . "\n💰 New balance: <b>" . money($bal) . '</b>');
            }
            [$t, $k] = admin_user_view($sid);
            send($chatId, ($bal !== null ? "✅ Balance updated.\n\n" : '') . $t, $k);
            return;

        case 'a_broadcast':
            set_state($uid, null);
            admin_broadcast($chatId, (int)$m['message_id']);
            return;
    }
    set_state($uid, null);
}

function admin_download_text_file(array $doc): ?string
{
    if (($doc['file_size'] ?? 0) > 5 * 1024 * 1024) {
        return null;
    }
    $f = tg('getFile', ['file_id' => $doc['file_id']]);
    $path = $f['result']['file_path'] ?? null;
    if (!$path) {
        return null;
    }
    $res = http_request('GET', rtrim((string)cfg('telegram_api', 'https://api.telegram.org'), '/') . '/file/bot' . cfg('bot_token') . '/' . $path);
    if ($res['code'] !== 200) {
        return null;
    }
    // Strip UTF-8 BOM
    return preg_replace('/^\xEF\xBB\xBF/', '', $res['raw']);
}

function admin_add_keys(int $pid, string $raw): array
{
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $keys = array_values(array_unique(array_filter(array_map('trim', $lines), fn($l) => $l !== '')));
    $inputCount = count($keys);
    if (!$keys) {
        return ['added' => 0, 'skipped' => 0];
    }
    $existing = q('SELECT key_value FROM product_keys WHERE product_id=?', [$pid])->fetchAll(PDO::FETCH_COLUMN);
    $existing = array_flip($existing);
    $keys = array_values(array_filter($keys, fn($k) => !isset($existing[$k])));

    foreach (array_chunk($keys, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '(?,?)'));
        $params = [];
        foreach ($chunk as $k) {
            $params[] = $pid;
            $params[] = $k;
        }
        q("INSERT INTO product_keys (product_id, key_value) VALUES $ph", $params);
    }
    return ['added' => count($keys), 'skipped' => $inputCount - count($keys)];
}

function admin_broadcast(int $adminChat, int $messageId): void
{
    send($adminChat, '📢 Broadcast started… you will get a report when it finishes.');
    // Reply to Telegram now so it doesn't retry this update while we send.
    finish_request();

    $ids = q('SELECT id FROM users WHERE is_banned=0')->fetchAll(PDO::FETCH_COLUMN);
    $ok = 0;
    $fail = 0;
    foreach ($ids as $id) {
        $r = tg('copyMessage', ['chat_id' => $id, 'from_chat_id' => $adminChat, 'message_id' => $messageId]);
        if (!empty($r['ok'])) {
            $ok++;
        } else {
            $fail++;
            $retry = (int)($r['parameters']['retry_after'] ?? 0);
            if ($retry > 0) {
                sleep($retry);
            }
        }
        usleep(40000); // ~25 msgs/sec, under Telegram's limit
    }
    send($adminChat, "📢 Broadcast finished.\n✅ Delivered: <b>{$ok}</b>\n❌ Failed: <b>{$fail}</b>");
}
