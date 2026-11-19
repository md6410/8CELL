<?php
// کانال‌های فروش (قراردادِ پایدارِ هسته برای ماژول‌ها): هر کانال یک «فروشنده» با حساب، کلید و انبارِ تأمین است
declare(strict_types=1);

function channel_kinds(): array { return ['pos' => 'صندوقِ حضوری', 'partner' => 'همکار', 'online' => 'فروشگاهِ آنلاین', 'market' => 'بازارگاه', 'other' => 'سایر']; }
function channel_get(int $id): ?array { return row("SELECT * FROM channels WHERE id=?", [$id]); }
function channel_by_slug(string $slug): ?array { return row("SELECT * FROM channels WHERE slug=?", [$slug]); }
/** ثبتِ کانال به‌عنوانِ فروشنده (برای ماژول‌ها: در قلابِ channel_register صدا بزنید) */
function channel_register_module(array $in): array {
    $slug = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($in['slug'] ?? '')));
    if ($slug === '') throw new UserError('شناسهٔ کانال لازم است');
    if ($c = channel_by_slug($slug)) return $c;
    return channel_get(channel_save($in + ['slug' => $slug]));
}
function channel_save(array $in): int {
    $id = (int)($in['id'] ?? 0); $name = trim((string)($in['name'] ?? '')); if ($name === '') throw new UserError('نامِ کانال لازم است');
    $kind = isset(channel_kinds()[$in['kind'] ?? '']) ? $in['kind'] : 'online';
    $whs = implode(',', array_filter(array_map('intval', (array)($in['warehouse_ids'] ?? []))));
    $d = ['name' => $name, 'kind' => $kind, 'fee_pct' => to_float($in['fee_pct'] ?? 0), 'branch_id' => (int)($in['branch_id'] ?? 0) ?: null, 'warehouse_ids' => $whs ?: null,
        'price_rule' => in_array($in['price_rule'] ?? 'retail', ['retail', 'adjust'], true) ? ($in['price_rule'] ?? 'retail') : 'retail', 'price_adj_pct' => to_float($in['price_adj_pct'] ?? 0),
        'safety_stock' => max(0, to_int($in['safety_stock'] ?? 0)), 'all_products' => array_key_exists('all_products', $in) ? (empty($in['all_products']) ? 0 : 1) : 1, 'active' => empty($in['inactive']) ? 1 : 0];
    return tx(function () use ($id, $d, $in, $name) {
        if ($id) { $o = channel_get($id); upd('channels', $d, 'id=?', [$id]); if ($o['user_id']) upd('users', ['name' => $name], 'id=?', [$o['user_id']]); [$b, $a] = audit_diff($o, $d); audit('channels', $id, 'update', $b, $a, 'کانال'); return $id; }
        $slug = (string)($in['slug'] ?? '') ?: 'ch' . substr(bin2hex(random_bytes(3)), 0, 6);
        $uid = ins('users', ['username' => 'channel_' . $slug, 'name' => $name, 'kind' => 'channel', 'active' => 1, 'created_at' => now()]);
        $d += ['slug' => $slug, 'user_id' => $uid, 'key_id' => substr(bin2hex(random_bytes(8)), 0, 16), 'secret' => bin2hex(random_bytes(24)), 'module' => $in['module'] ?? null, 'created_at' => now()];
        $id = ins('channels', $d);
        audit('channels', $id, 'create', null, ['name' => $name, 'kind' => $d['kind']], 'کانالِ تازه');
        return $id;
    });
}
function channel_rotate_secret(int $id): string { $s = bin2hex(random_bytes(24)); upd('channels', ['secret' => $s], 'id=?', [$id]); audit('channels', $id, 'rotate', null, null, 'کلیدِ تازهٔ کانال'); return $s; }
function channel_whs(array $c): ?array { $w = array_filter(array_map('intval', explode(',', (string)$c['warehouse_ids']))); return $w ?: null; }
function channel_price(array $c, int $pid, ?int $vid): int {
    $ov = val("SELECT price_override FROM channel_products WHERE channel_id=? AND product_id=?", [$c['id'], $pid]);
    if ($ov !== null && (int)$ov > 0) return (int)$ov;
    $p = retail_final_price($pid, $vid);
    return $c['price_rule'] === 'adjust' ? (int)round($p * (100 + (float)$c['price_adj_pct']) / 100) : $p;
}
/** کانال هرگز بیش از موجودیِ واقعی نمی‌فروشد: موجودی − رزرو − حاشیهٔ امن */
function channel_stock(array $c, int $pid, ?int $vid): int {
    $w = channel_whs($c);
    return max(0, stock_on_hand($pid, $vid, $w) - stock_reserved($pid, $vid, $w) - (int)$c['safety_stock']);
}
function channel_catalog(array $c, ?string $since = null): array {
    $where = "p.active=1 AND p.item_type IN ('SAL','CIR')" . ($c['all_products'] ? '' : " AND p.id IN (SELECT product_id FROM channel_products WHERE channel_id=" . (int)$c['id'] . ")");
    if ($since) $where .= " AND p.id IN (SELECT product_id FROM catalog_changes WHERE at>" . db()->quote($since) . ")";
    $out = [];
    foreach (rows("SELECT p.* FROM products p WHERE $where ORDER BY p.id LIMIT 5000") as $p) {
        $vs = product_variants((int)$p['id']);
        $item = ['product_id' => (int)$p['id'], 'code' => $p['base_code'], 'name' => $p['name'], 'brand' => $p['brand'], 'image' => $p['image'], 'description' => $p['description'], 'warranty' => $p['warranty'], 'weight_g' => $p['weight_g'], 'variants' => []];
        if ($vs) foreach ($vs as $v) $item['variants'][] = ['variant_id' => (int)$v['id'], 'code' => $v['code'], 'title' => $v['title'], 'price' => channel_price($c, (int)$p['id'], (int)$v['id']), 'stock' => channel_stock($c, (int)$p['id'], (int)$v['id'])];
        else { $item['price'] = channel_price($c, (int)$p['id'], null); $item['stock'] = channel_stock($c, (int)$p['id'], null); }
        $out[] = $item;
    }
    return ['channel' => $c['slug'], 'at' => now(), 'full' => $since === null, 'items' => $out];
}
/** امضای درخواست: HMAC-SHA256(secret, ts \n METHOD \n action \n body) */
function channel_sign(string $secret, string $ts, string $method, string $action, string $body): string { return hash_hmac('sha256', $ts . "\n" . strtoupper($method) . "\n" . $action . "\n" . $body, $secret); }
function channel_auth(string $action, string $body): array {
    if (!feature_on('channels_api')) throw new UserError('API کانال‌ها خاموش است', 403);
    $key = (string)($_SERVER['HTTP_X_CHANNEL_KEY'] ?? ''); $ts = (string)($_SERVER['HTTP_X_TIMESTAMP'] ?? ''); $sig = strtolower((string)($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
    $c = $key ? row("SELECT * FROM channels WHERE key_id=? AND active=1", [$key]) : null;
    if (!$c || !ctype_digit($ts) || abs(time() - (int)$ts) > 300) throw new UserError('امضای نادرست', 401);
    if (!hash_equals(channel_sign($c['secret'], $ts, $_SERVER['REQUEST_METHOD'] ?? 'GET', $action, $body), $sig)) throw new UserError('امضای نادرست', 401);
    return $c;
}
/** سفارشِ کانال ← همان ثبتِ اتمیِ صندوق؛ تکرارِ شناسه = همان فروش */
function channel_order_commit(array $c, array $o): array {
    $ext = trim((string)($o['ext_id'] ?? '')); if ($ext === '' || mb_strlen($ext) > 80) throw new UserError('شناسهٔ سفارشِ کانال لازم است');
    $ex = row("SELECT * FROM channel_orders WHERE channel_id=? AND ext_id=?", [$c['id'], $ext]);
    if ($ex && $ex['sale_id']) return ['sale_id' => (int)$ex['sale_id'], 'duplicate' => true, 'number' => val("SELECT number FROM sales WHERE id=?", [$ex['sale_id']])];
    $lines = []; $tol = (int)setting('channel_price_tolerance', 0);
    foreach ((array)($o['items'] ?? []) as $it) {
        $pid = (int)($it['product_id'] ?? 0); $vid = (int)($it['variant_id'] ?? 0) ?: null;
        if (!$pid && !empty($it['code'])) { $r = scan_lookup(scan_normalize((string)$it['code'])); if ($r['type'] === 'product') $pid = (int)$r['row']['id']; elseif ($r['type'] === 'variant') { $pid = (int)$r['row']['product_id']; $vid = (int)$r['row']['id']; } }
        if (!$pid) throw new UserError('کالای ناشناخته در سفارش');
        if (!$c['all_products'] && !val("SELECT 1 FROM channel_products WHERE channel_id=? AND product_id=?", [$c['id'], $pid])) throw new UserError('این کالا به این کانال داده نشده است');
        $qty = to_int($it['qty'] ?? 0); if ($qty <= 0) throw new UserError('تعدادِ نامعتبر');
        $cur = channel_price($c, $pid, $vid); $price = isset($it['price']) ? to_int($it['price']) : $cur;
        if (abs($price - $cur) > $tol) throw new UserError('قیمتِ کهنه: قیمتِ فعلیِ کالا ' . fa_money($cur) . ' است', 409);
        if ($qty > channel_stock($c, $pid, $vid)) throw new UserError('موجودیِ کانال کافی نیست', 409);
        $lines[] = ['product_id' => $pid, 'variant_id' => $vid, 'qty' => $qty, 'price' => $price, 'disc' => to_int($it['disc'] ?? 0)];
    }
    if (!$lines) throw new UserError('سفارش قلم ندارد');
    $cust = null;
    if (!empty($o['customer']['mobile'])) $cust = customer_find_or_create((string)$o['customer']['mobile'], (string)($o['customer']['name'] ?? ''), (string)($o['customer']['address'] ?? ''));
    $calc = calc_doc(array_map(fn($l) => ['qty' => $l['qty'], 'price' => $l['price'], 'disc' => $l['disc'], 'unit_id' => (int)val("SELECT unit_id FROM products WHERE id=?", [$l['product_id']]) ?: null, 'title' => 'x'], $lines), ['doc_disc' => to_int($o['doc_disc'] ?? 0)]);
    $paid = isset($o['paid']) ? to_int($o['paid']) : $calc['total'];
    if ($paid !== $calc['total']) throw new UserError('مبلغِ پرداخت‌شده (' . fa_money($paid) . ') با جمعِ سفارش (' . fa_money($calc['total']) . ') برابر نیست', 409);
    $fee = isset($o['fee']) ? to_int($o['fee']) : (int)floor($calc['total'] * (float)$c['fee_pct'] / 100);
    if (!$ex) { try { ins('channel_orders', ['channel_id' => $c['id'], 'ext_id' => $ext, 'payload' => json_encode($o, JSON_UNESCAPED_UNICODE), 'status' => 'pending', 'created_at' => now()]); } catch (PDOException $e) {} }
    set_current_user((int)$c['user_id']);
    $sid = sale_commit(['cart_token' => md5('ch' . $c['id'] . ':' . $ext), 'channel_id' => $c['id'], 'customer_id' => $cust, 'lines' => $lines, 'doc_disc' => to_int($o['doc_disc'] ?? 0),
        'payments' => [['method' => 'channel', 'amount' => $calc['total'], 'ref' => $ext]], 'defer_stock' => true, 'channel_fee' => $fee, 'ship_to' => $o['ship_to'] ?? null, 'note' => 'سفارشِ ' . $c['name'] . ' ' . $ext]);
    tx(function () use ($c, $ext, $sid, $calc, $fee) {
        upd('channel_orders', ['sale_id' => $sid, 'status' => 'ok'], 'channel_id=? AND ext_id=?', [$c['id'], $ext]);
        if (!val("SELECT id FROM channel_ledger WHERE ref_type='sale' AND ref_id=? AND kind='sale'", [$sid])) {
            channel_ledger_add((int)$c['id'], $calc['total'], 'sale', 'sale', $sid, 'فروشِ ' . $ext);
            if ($fee > 0) { channel_ledger_add((int)$c['id'], -$fee, 'fee', 'sale', $sid, 'کارمزد'); ledger_post('channel_fee', $sid, ['amount' => $fee, 'channel_id' => (int)$c['id']]); }
        }
    });
    do_action('channel_order_committed', (int)$c['id'], $ext, $sid);
    return ['sale_id' => $sid, 'duplicate' => false, 'number' => val("SELECT number FROM sales WHERE id=?", [$sid])];
}
function channel_ledger_add(int $cid, int $amount, string $kind, ?string $rt, ?int $rid, string $note = ''): int { return ins('channel_ledger', ['channel_id' => $cid, 'amount' => $amount, 'kind' => $kind, 'ref_type' => $rt, 'ref_id' => $rid, 'note' => mb_cut($note, 300), 'at' => now()]); }
/** مانده = جمعِ ردیف‌ها (مثبت = کانال بدهکار است) */
function channel_balance(int $cid): int { return (int)val("SELECT COALESCE(SUM(amount),0) FROM channel_ledger WHERE channel_id=?", [$cid]); }
function channel_settle(int $cid, int $amount, int $bankId, string $note = ''): int {
    if ($amount == 0) throw new UserError('مبلغ لازم است');
    return tx(function () use ($cid, $amount, $bankId, $note) {
        $id = channel_ledger_add($cid, -$amount, 'settle', 'settle', null, $note ?: 'تسویه');
        ledger_post('channel_settle', $id, ['amount' => $amount, 'channel_id' => $cid, 'bank_account_id' => $bankId]);
        audit('channel_ledger', $id, 'settle', null, ['amount' => $amount], 'تسویهٔ کانال');
        return $id;
    });
}
/** قلاب برای ماژول‌ها تا خودشان را ثبت کنند */
function channels_boot(): void { do_action('channel_register'); }
