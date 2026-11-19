<?php
// فروشِ حضوری: شیفت، سبد و اسکن، قیمتِ گریدی، پرداختِ چندگانه، ثبتِ اتمی (sale_commit)، مرجوعی و بستنِ شیفت
declare(strict_types=1);

function pay_methods(): array { return apply_filters('payment_methods', ['cash' => 'نقد', 'card' => 'کارت‌خوان', 'transfer' => 'کارت‌به‌کارت', 'credit' => 'نسیه', 'wallet' => 'کیف پول', 'cheque' => 'چک', 'channel' => 'دریافتِ کانال']); }

// ── شیفت
function shift_current(int $cashboxId): ?array { return row("SELECT * FROM shifts WHERE cashbox_id=? AND status='open'", [$cashboxId]); }
function shift_of_user(int $uid): ?array { return row("SELECT s.*, c.name cbname, c.branch_id FROM shifts s JOIN cashboxes c ON c.id=s.cashbox_id WHERE s.user_id=? AND s.status='open' ORDER BY s.id DESC LIMIT 1", [$uid]); }
function shift_open(int $cashboxId, int $opening): int {
    $u = require_login();
    $cb = row("SELECT * FROM cashboxes WHERE id=? AND active=1", [$cashboxId]); if (!$cb) throw new UserError('صندوق یافت نشد');
    if (!branch_allowed((int)$cb['branch_id'])) throw new UserError('به این شعبه دسترسی ندارید', 403);
    if (stacc_blocked((int)$u['id'])) throw new UserError('حسابِ شما به‌دلیلِ بدهیِ بیش از سقف مسدود است؛ با مدیر تماس بگیرید');
    if ($s = shift_current($cashboxId)) throw new UserError('این صندوق شیفتِ باز دارد' . ((int)$s['user_id'] !== (int)$u['id'] ? ' (کاربرِ دیگر)' : ''));
    if (shift_of_user((int)$u['id'])) throw new UserError('شما در صندوقِ دیگری شیفتِ باز دارید');
    try { $id = ins('shifts', ['cashbox_id' => $cashboxId, 'user_id' => $u['id'], 'opening_cash' => max(0, $opening), 'opened_at' => now(), 'open_key' => $cashboxId]); }
    catch (PDOException $e) { throw new UserError('این صندوق شیفتِ باز دارد'); }
    audit('shifts', $id, 'open', null, ['opening_cash' => $opening], 'بازکردنِ شیفت');
    return $id;
}
/** مورد انتظار = اولیه + نقدِ دریافتی − بازپرداختِ نقد */
function shift_expected(int $shiftId): array {
    $s = row("SELECT * FROM shifts WHERE id=?", [$shiftId]);
    $by = []; foreach (rows("SELECT method, SUM(amount) a FROM receipts WHERE shift_id=? GROUP BY method", [$shiftId]) as $r) $by[$r['method']] = (int)$r['a'];
    $cashIn = (int)val("SELECT COALESCE(SUM(amount),0) FROM receipts WHERE shift_id=? AND method='cash' AND amount>0", [$shiftId]);
    $cashOut = -(int)val("SELECT COALESCE(SUM(amount),0) FROM receipts WHERE shift_id=? AND method='cash' AND amount<0", [$shiftId]);
    return ['opening' => (int)$s['opening_cash'], 'cash_in' => $cashIn, 'cash_out' => $cashOut, 'expected' => (int)$s['opening_cash'] + $cashIn - $cashOut, 'by_method' => $by,
        'sales' => (int)val("SELECT COUNT(*) FROM sales WHERE shift_id=?", [$shiftId]), 'total' => (int)val("SELECT COALESCE(SUM(total),0) FROM sales WHERE shift_id=?", [$shiftId])];
}
/** شمارشِ کور: صندوق‌دار عددِ مورد انتظار را پیش از شمارش نمی‌بیند */
function shift_diff(int $expected, int $counted): array { $d = $counted - $expected; return ['diff' => $d, 'label' => $d < 0 ? 'کسری ' . (-$d) : ($d > 0 ? 'اضافه ' . $d : 'بی‌مغایرت')]; }
function shift_close(int $shiftId, int $counted, array $opt = []): array {
    $u = require_login();
    return tx(function () use ($shiftId, $counted, $opt, $u) {
        $s = row("SELECT * FROM shifts WHERE id=? FOR UPDATE", [$shiftId]);
        if (!$s || $s['status'] !== 'open') throw new UserError('شیفتِ باز یافت نشد');
        if ((int)$s['user_id'] !== (int)$u['id'] && !can('shift.manage')) throw new UserError('این دسترسی را ندارید', 403);
        $e = shift_expected($shiftId); $df = shift_diff($e['expected'], $counted);
        $sum = $e + ['counted' => $counted, 'diff' => $df['diff'], 'diff_label' => $df['label']];
        upd('shifts', ['status' => 'closed', 'closed_at' => now(), 'counted_cash' => $counted, 'expected_cash' => $e['expected'], 'diff' => $df['diff'], 'open_key' => null,
            'summary' => json_encode($sum, JSON_UNESCAPED_UNICODE), 'handed_to' => (int)($opt['handed_to'] ?? 0) ?: null], 'id=?', [$shiftId]);
        ledger_post('shift_close', $shiftId, ['diff' => $df['diff'], 'cashbox_id' => (int)$s['cashbox_id'], 'user_id' => (int)$s['user_id']]);
        // نقدِ شمرده‌شده تا تحویل به بانک/گاوصندوق دستِ صندوق‌دار است
        $holder = (int)($opt['handed_to'] ?? 0) ?: (int)$s['user_id'];
        if ($counted > 0) stacc_add($holder, $counted, 'shift_cash', 'shift', $shiftId, 'نقدِ شیفتِ ' . $shiftId);
        if ($df['diff'] < 0 && setting('shortage_to_staff', '1') === '1') stacc_add((int)$s['user_id'], -$df['diff'], 'shortage', 'shift', $shiftId, 'کسریِ صندوق');
        audit('shifts', $shiftId, 'close', null, ['counted' => $counted, 'expected' => $e['expected'], 'diff' => $df['diff']], 'بستنِ شیفت');
        return $sum;
    });
}

// ── سبد
function cart_open(): array {
    $u = require_login(); $s = shift_of_user((int)$u['id']);
    if (!$s) throw new UserError('ابتدا شیفت را باز کنید');
    $c = row("SELECT * FROM carts WHERE user_id=? AND shift_id=? AND status='open' ORDER BY id DESC LIMIT 1", [$u['id'], $s['id']]);
    if ($c) return $c;
    $id = ins('carts', ['token' => bin2hex(random_bytes(16)), 'shift_id' => $s['id'], 'cashbox_id' => $s['cashbox_id'], 'user_id' => $u['id'], 'created_at' => now()]);
    return row("SELECT * FROM carts WHERE id=?", [$id]);
}
function cart_get(int $id): array { $c = row("SELECT * FROM carts WHERE id=?", [$id]); if (!$c || $c['status'] !== 'open') throw new UserError('سبد یافت نشد'); $u = current_user(); if ($u && (int)$c['user_id'] !== (int)$u['id'] && !can('shift.manage')) throw new UserError('این سبد مالِ شما نیست', 403); return $c; }
function cart_wh(array $c): int { return (int)val("SELECT b.default_wh_id FROM cashboxes cb JOIN branches b ON b.id=cb.branch_id WHERE cb.id=?", [$c['cashbox_id']]) ?: (int)val("SELECT id FROM warehouses WHERE active=1 ORDER BY id LIMIT 1"); }
/** قیمتِ ردیف برای مشتری: همکار ← قیمتِ همکاری؛ گرید ← درصدِ گرید؛ وگرنه قیمتِ نهایی */
function price_for(int $pid, ?int $vid, ?int $customerId, int $qty): int {
    $base = retail_final_price($pid, $vid);
    if ($customerId) {
        $c = row("SELECT * FROM customers WHERE id=?", [$customerId]);
        if ($c && $c['is_partner'] && $c['partner_grade_id'] && feature_on('pos_partner_price')) return partner_price($pid, $vid, (int)$c['partner_grade_id'], $qty);
        if ($c && $c['grade_id']) { $g = (float)val("SELECT disc_pct FROM grades WHERE id=?", [$c['grade_id']]); if ($g > 0) return (int)round($base * (100 - $g) / 100); }
    }
    return $base;
}
/** اسکن یا جست‌وجو ← ردیف؛ موجودی همان لحظه رزرو می‌شود */
function pos_scan(int $cartId, string $raw, int $qty = 1): array {
    $c = cart_get($cartId); $code = scan_normalize($raw);
    if ($code === '') throw new UserError('کد خالی است');
    $r = scan_lookup($code); $wh = cart_wh($c);
    return tx(function () use ($c, $r, $code, $wh, $qty) {
        if ($r['type'] === 'unit') {
            $u = $r['row'];
            if ((int)$u['warehouse_id'] !== $wh) throw new UserError('این دانه در انبارِ این شعبه نیست');
            stock_reserve((int)$c['id'], (int)$c['cashbox_id'], (int)$u['product_id'], $u['variant_id'] ? (int)$u['variant_id'] : null, $wh, 1, (int)$u['id']);
            $id = ins('cart_items', ['cart_id' => $c['id'], 'product_id' => $u['product_id'], 'variant_id' => $u['variant_id'], 'unit_id' => $u['id'], 'qty' => 1, 'price' => price_for((int)$u['product_id'], $u['variant_id'] ? (int)$u['variant_id'] : null, $c['customer_id'] ? (int)$c['customer_id'] : null, 1)]);
            return ['item_id' => $id, 'msg' => 'افزوده شد: ' . product_card((int)$u['product_id'], $u['variant_id'] ? (int)$u['variant_id'] : null)['name']];
        }
        if (in_array($r['type'], ['product', 'variant', 'lot'], true)) {
            $pid = $r['type'] === 'product' ? (int)$r['row']['id'] : (int)$r['row']['product_id']; $vid = $r['type'] === 'product' ? null : ($r['type'] === 'variant' ? (int)$r['row']['id'] : ($r['row']['variant_id'] ? (int)$r['row']['variant_id'] : null));
            $p = product_get($pid); if (!$p || !$p['active']) throw new UserError('این کالا فعال نیست');
            if ($p['serial'] && setting('pos_require_unit', '0') === '1') throw new UserError('کدِ دانه را اسکن کنید');
            if (!$vid && $r['type'] === 'product' && val("SELECT 1 FROM product_variants WHERE product_id=? AND active=1 LIMIT 1", [$pid])) throw new UserError('این کالا ترکیب دارد؛ کدِ ترکیب را اسکن کنید');
            $qb = $p['unit_id'] ? max(1, $qty) * (int)unit_of((int)$p['unit_id'])['scale'] : max(1, $qty);
            $ex = row("SELECT * FROM cart_items WHERE cart_id=? AND product_id=? AND (variant_id<=>?) AND unit_id IS NULL", [$c['id'], $pid, $vid]);
            stock_reserve((int)$c['id'], (int)$c['cashbox_id'], $pid, $vid, $wh, $qb);
            if ($ex) { $nq = (int)$ex['qty'] + $qb; upd('cart_items', ['qty' => $nq, 'price' => price_for($pid, $vid, $c['customer_id'] ? (int)$c['customer_id'] : null, $nq)], 'id=?', [$ex['id']]); $id = (int)$ex['id']; }
            else $id = ins('cart_items', ['cart_id' => $c['id'], 'product_id' => $pid, 'variant_id' => $vid, 'qty' => $qb, 'price' => price_for($pid, $vid, $c['customer_id'] ? (int)$c['customer_id'] : null, $qb)]);
            return ['item_id' => $id, 'msg' => 'افزوده شد: ' . product_card($pid, $vid)['name']];
        }
        if ($r['type'] === 'customer') { cart_set_customer((int)$c['id'], (int)$r['row']['id']); return ['msg' => 'مشتری: ' . $r['row']['name']]; }
        throw new UserError('کدِ «' . $code . '» ناشناخته است');
    });
}
function cart_set_qty(int $itemId, $qty): void {
    $it = row("SELECT ci.*, p.unit_id FROM cart_items ci JOIN products p ON p.id=ci.product_id WHERE ci.id=?", [$itemId]); if (!$it) throw new UserError('ردیف یافت نشد');
    $c = cart_get((int)$it['cart_id']);
    if ($it['unit_id']) throw new UserError('ردیفِ دانه‌دار تعداد ندارد؛ دانهٔ دیگر را اسکن کنید');
    $q = qty_in($qty, $it['unit_id'] ? (int)$it['unit_id'] : null); if ($q <= 0) { cart_remove($itemId); return; }
    tx(function () use ($it, $c, $q) {
        q("DELETE FROM stock_reservations WHERE cart_id=? AND unit_id IS NULL AND product_id=? AND (variant_id<=>?)", [$c['id'], $it['product_id'], $it['variant_id']]);
        stock_reserve((int)$c['id'], (int)$c['cashbox_id'], (int)$it['product_id'], $it['variant_id'] ? (int)$it['variant_id'] : null, cart_wh($c), $q);
        upd('cart_items', ['qty' => $q, 'price' => price_for((int)$it['product_id'], $it['variant_id'] ? (int)$it['variant_id'] : null, $c['customer_id'] ? (int)$c['customer_id'] : null, $q)], 'id=?', [$it['id']]);
    });
}
function cart_remove(int $itemId): void {
    $it = row("SELECT * FROM cart_items WHERE id=?", [$itemId]); if (!$it) return; cart_get((int)$it['cart_id']);
    tx(function () use ($it) {
        if ($it['unit_id']) q("DELETE FROM stock_reservations WHERE cart_id=? AND unit_id=?", [$it['cart_id'], $it['unit_id']]);
        else q("DELETE FROM stock_reservations WHERE cart_id=? AND unit_id IS NULL AND product_id=? AND (variant_id<=>?)", [$it['cart_id'], $it['product_id'], $it['variant_id']]);
        q("DELETE FROM cart_items WHERE id=?", [$it['id']]);
    });
}
/** تخفیفِ ردیف با نامِ کاربر؛ سقف همین‌جا و دوباره در ثبت */
function pos_line_discount(int $itemId, $amount, string $note = ''): void {
    $it = row("SELECT ci.*, p.unit_id FROM cart_items ci JOIN products p ON p.id=ci.product_id WHERE ci.id=?", [$itemId]); if (!$it) throw new UserError('ردیف یافت نشد');
    cart_get((int)$it['cart_id']); require_cap('pos.discount');
    $gross = line_amount((int)$it['qty'], (int)$it['price'], $it['unit_id'] ? (int)$it['unit_id'] : null);
    $s = trim(en_num((string)$amount)); $disc = str_ends_with($s, '%') ? (int)floor($gross * to_float($s) / 100) : to_int($s);
    if ($disc < 0 || $disc > $gross) throw new UserError('تخفیف نامعتبر است');
    discount_assert($gross, $disc);
    upd('cart_items', ['disc' => $disc, 'disc_by' => current_user()['id'] ?? null, 'note' => $note !== '' ? mb_cut($note, 200) : $it['note']], 'id=?', [$itemId]);
    audit('cart_items', $itemId, 'discount', null, ['disc' => $disc], 'تخفیفِ ردیف');
}
function discount_assert(int $gross, int $disc, ?array $u = null): void {
    $u = $u ?: current_user(); if (!$u || $u['is_super'] || $gross <= 0 || $disc <= 0) return;
    if (($u['kind'] ?? '') === 'channel') return;
    $pct = $disc * 100 / $gross; $max = (float)$u['max_discount_pct'];
    if ($pct > $max + 1e-9) throw new UserError('بیش از سقفِ تخفیفِ شما (' . fa_num((string)$max) . '٪)');
}
function cart_set_customer(int $cartId, ?int $customerId): void {
    $c = cart_get($cartId);
    upd('carts', ['customer_id' => $customerId ?: null], 'id=?', [$cartId]);
    foreach (rows("SELECT * FROM cart_items WHERE cart_id=?", [$cartId]) as $it) upd('cart_items', ['price' => price_for((int)$it['product_id'], $it['variant_id'] ? (int)$it['variant_id'] : null, $customerId ?: null, (int)$it['qty'])], 'id=?', [$it['id']]);
}
function cart_view(int $cartId): array {
    $c = cart_get($cartId);
    $items = rows("SELECT ci.*, p.name, p.unit_id, v.title vt, u.code ucode FROM cart_items ci JOIN products p ON p.id=ci.product_id LEFT JOIN product_variants v ON v.id=ci.variant_id LEFT JOIN wh_units u ON u.id=ci.unit_id WHERE ci.cart_id=? ORDER BY ci.id", [$cartId]);
    $lines = array_map(fn($i) => ['title' => $i['name'] . ($i['vt'] ? ' — ' . $i['vt'] : ''), 'qty' => (int)$i['qty'], 'unit_id' => $i['unit_id'] ? (int)$i['unit_id'] : null, 'price' => (int)$i['price'], 'disc' => (int)$i['disc']], $items);
    $calc = $lines ? calc_doc($lines, ['doc_disc' => (int)$c['doc_disc']]) : ['total' => 0, 'sub' => 0, 'vat' => 0, 'lines' => [], 'lineDisc' => 0, 'docDisc' => 0];
    foreach ($items as $k => &$i) { $i['calc'] = $calc['lines'][$k] ?? null; $i['qty_show'] = qty_show((int)$i['qty'], $i['unit_id'] ? (int)$i['unit_id'] : null); }
    $cust = $c['customer_id'] ? row("SELECT id, name, mobile, credit_limit, is_partner FROM customers WHERE id=?", [$c['customer_id']]) : null;
    if ($cust) { $cust['balance'] = customer_balance((int)$cust['id']); $cust['wallet'] = wallet_balance((int)$cust['id']); }
    return ['cart' => $c, 'items' => $items, 'totals' => array_diff_key($calc, ['lines' => 1]), 'customer' => $cust];
}

/** برنامهٔ پرداخت: جمع باید دقیقاً برابر باشد؛ فقط نقد باقی (پولِ خرد) دارد */
function pay_plan_validate(int $total, array $payments): array {
    $norm = []; $sum = 0; $nonCash = 0;
    foreach ($payments as $p) {
        $m = (string)($p['method'] ?? ''); $a = to_int($p['amount'] ?? 0);
        if ($a == 0) continue;
        if (!isset(pay_methods()[$m])) throw new UserError('روشِ پرداختِ نامعتبر');
        if ($a < 0) throw new UserError('مبلغِ پرداخت منفی است');
        $norm[] = ['method' => $m, 'amount' => $a, 'ref' => mb_cut((string)($p['ref'] ?? ''), 120)];
        $sum += $a; if ($m !== 'cash') $nonCash += $a;
    }
    if ($nonCash > $total) throw new UserError('دریافت‌های غیرِنقدی بیش از مبلغِ قابلِ پرداخت است');
    if ($sum < $total) throw new UserError(fa_money($total - $sum) . ' ریال باقی‌مانده');
    $change = $sum - $total;
    if ($change > 0) foreach ($norm as &$p) if ($p['method'] === 'cash') { $p['amount'] -= $change; break; }
    return ['payments' => array_values(array_filter($norm, fn($p) => $p['amount'] > 0)), 'change' => $change, 'paid' => $sum];
}

/**
 * ثبتِ اتمیِ فروش — تنها راهِ ثبتِ هر فروش (حضوری یا کانال)
 * $in: cart_token, lines[product_id,variant_id,qty,price?,disc,unit_ids[]], payments, customer_id, doc_disc, seller_id, shift_id|channel_id, warehouse_id, defer_stock, channel_fee, partner_mode, ship_to, note
 */
function sale_commit(array $in): int {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower((string)($in['cart_token'] ?? '')));
    if (strlen($token) !== 32) throw new UserError('کلیدِ سبد نامعتبر است');
    if ($ex = val("SELECT id FROM sales WHERE cart_token=?", [$token])) return (int)$ex; // دوبار کلیک = یک فروش
    $u = current_user();
    $channel = !empty($in['channel_id']) ? row("SELECT * FROM channels WHERE id=? AND active=1", [(int)$in['channel_id']]) : null;
    $d = today(); fiscal_assert_open($d);
    if (!$channel) {
        $shift = !empty($in['shift_id']) ? row("SELECT * FROM shifts WHERE id=? AND status='open'", [(int)$in['shift_id']]) : ($u ? shift_of_user((int)$u['id']) : null);
        if (!$shift) throw new UserError('ابتدا شیفت را باز کنید');
        $cb = row("SELECT cb.*, b.default_wh_id FROM cashboxes cb JOIN branches b ON b.id=cb.branch_id WHERE cb.id=?", [$shift['cashbox_id']]);
        $branch = (int)$cb['branch_id']; $wh = (int)($in['warehouse_id'] ?? 0) ?: (int)$cb['default_wh_id']; $seller = (int)($in['seller_id'] ?? 0) ?: (int)$u['id'];
    } else {
        $shift = null; $cb = null; $branch = (int)$channel['branch_id'] ?: null; $seller = (int)$channel['user_id'];
        $whs = array_filter(array_map('intval', explode(',', (string)$channel['warehouse_ids']))); $wh = (int)($in['warehouse_id'] ?? 0) ?: ($whs ? reset($whs) : (int)val("SELECT id FROM warehouses WHERE active=1 ORDER BY id LIMIT 1"));
    }
    $cust = (int)($in['customer_id'] ?? 0) ?: null;
    $lines = []; $meta = [];
    foreach ((array)($in['lines'] ?? []) as $l) {
        $pid = (int)($l['product_id'] ?? 0); $vid = (int)($l['variant_id'] ?? 0) ?: null; $qty = (int)($l['qty'] ?? 0);
        $p = product_get($pid); if (!$p) throw new UserError('کالای ناشناخته در سبد');
        if (!in_array($p['item_type'], wh_site_types(), true)) throw new UserError('«' . $p['name'] . '» کالای فروشی نیست');
        $price = $channel && isset($l['price']) ? (int)$l['price'] : price_for($pid, $vid, $cust, $qty);
        $v = $vid ? variant_get($vid) : null;
        $lines[] = ['title' => $p['name'] . ($v ? ' — ' . $v['title'] : ''), 'qty' => $qty, 'unit_id' => $p['unit_id'] ? (int)$p['unit_id'] : null, 'price' => $price, 'disc' => (int)($l['disc'] ?? 0)];
        $meta[] = ['p' => $p, 'vid' => $vid, 'unit_ids' => array_map('intval', (array)($l['unit_ids'] ?? [])), 'retail' => retail_final_price($pid, $vid)];
    }
    if (!$lines) throw new UserError('سبد خالی است');
    $calc = calc_doc($lines, ['doc_disc' => (int)($in['doc_disc'] ?? 0), 'vat_pct' => vat_pct($d), 'tax_round' => tax_round_mode($d)]);
    if (!$channel) discount_assert($calc['sub'], $calc['lineDisc'] + $calc['docDisc'], $u);
    $plan = pay_plan_validate($calc['total'], (array)($in['payments'] ?? []));
    $credit = 0; $wallet = 0; foreach ($plan['payments'] as $p) { if ($p['method'] === 'credit') $credit += $p['amount']; if ($p['method'] === 'wallet') $wallet += $p['amount']; }
    if (($credit || $wallet) && !$cust) throw new UserError('برای نسیه یا کیف پول مشتری را انتخاب کنید');
    if ($credit) {
        if ($u && !$u['is_super'] && !$channel && $credit > (int)$u['max_credit']) throw new UserError('بیش از سقفِ نسیهٔ مجازِ شما (' . fa_money((int)$u['max_credit']) . ')');
        $c = row("SELECT credit_limit FROM customers WHERE id=?", [$cust]);
        if (customer_balance($cust) + $credit > (int)$c['credit_limit']) throw new UserError('بیش از سقفِ نسیهٔ مشتری (مانده‌ٔ مجاز: ' . fa_money(max(0, (int)$c['credit_limit'] - customer_balance($cust))) . ')');
    }
    if ($wallet && $wallet > wallet_balance($cust)) throw new UserError('اعتبارِ کیف پول کافی نیست');
    $defer = !empty($in['defer_stock']);
    try {
        $saleId = tx(function () use ($in, $token, $channel, $shift, $cb, $branch, $wh, $seller, $cust, $calc, $meta, $plan, $credit, $wallet, $defer, $d, $u) {
            $jy = substr(jdate($d, false, false), 0, 4);
            $sid = ins('sales', ['cart_token' => $token, 'channel_id' => $channel['id'] ?? null, 'branch_id' => $branch, 'cashbox_id' => $cb['id'] ?? null, 'shift_id' => $shift['id'] ?? null, 'warehouse_id' => $wh,
                'seller_id' => $seller, 'customer_id' => $cust, 'partner_mode' => $in['partner_mode'] ?? null, 'ship_to' => !empty($in['ship_to']) ? json_encode($in['ship_to'], JSON_UNESCAPED_UNICODE) : null,
                'd' => $d, 'at' => now(), 'subtotal' => $calc['sub'], 'line_disc' => $calc['lineDisc'], 'doc_disc' => $calc['docDisc'], 'tax' => $calc['vat'], 'round_adj' => $calc['roundAdj'], 'total' => $calc['total'],
                'channel_fee' => (int)($in['channel_fee'] ?? 0), 'fulfil' => $defer ? 'pending' : 'done', 'note' => mb_cut((string)($in['note'] ?? ''), 400) ?: null]);
            upd('sales', ['number' => ($channel ? 'C' : 'S') . $jy . '-' . sprintf('%05d', doc_seq_next('sale-' . $jy))], 'id=?', [$sid]);
            $cartId = !empty($in['cart_id']) ? (int)$in['cart_id'] : null; $totalCost = 0;
            foreach ($calc['lines'] as $k => $l) {
                $m = $meta[$k]; $pid = (int)$m['p']['id'];
                $iid = ins('sale_items', ['sale_id' => $sid, 'product_id' => $pid, 'variant_id' => $m['vid'], 'title' => $l['title'], 'qty' => $l['qty'], 'price' => $l['price'], 'disc' => $l['disc'],
                    'doc_disc_share' => $l['doc_disc_share'], 'tax' => $l['tax'], 'net' => $l['net'], 'retail_price' => $m['retail']]);
                if ($defer) { // فروشِ کانال/همکار: رزرو تا خروجِ واقعی می‌ماند
                    if ($m['p']['item_type'] !== 'REP') { if (stock_on_hand($pid, $m['vid'], [$wh]) - stock_reserved($pid, $m['vid'], [$wh], $cartId) < $l['qty']) throw new UserError('موجودیِ «' . $l['title'] . '» کافی نیست');
                        ins('stock_reservations', ['sale_id' => $sid, 'product_id' => $pid, 'variant_id' => $m['vid'], 'warehouse_id' => $wh, 'qty' => $l['qty'], 'created_at' => now()]); }
                    continue;
                }
                if ($m['p']['item_type'] === 'REP' && !val("SELECT 1 FROM lots WHERE product_id=? LIMIT 1", [$pid])) continue; // خدمت بی‌انبار
                $out = [];
                if ($m['unit_ids']) foreach ($m['unit_ids'] as $uid) { q("DELETE FROM stock_reservations WHERE unit_id=?", [$uid]); $out = array_merge($out, wh_stock_out(['unit_id' => $uid], 1, ['ref_type' => 'sale', 'ref_id' => $sid])); }
                $rest = $l['qty'] - count($m['unit_ids']);
                if ($rest > 0) $out = array_merge($out, wh_stock_out(['product_id' => $pid, 'variant_id' => $m['vid'], 'warehouse_id' => $wh], $rest, ['ref_type' => 'sale', 'ref_id' => $sid, 'own_cart' => $cartId]));
                $cost = 0; foreach ($out as $o) $cost += $o['cost'] * $o['qty'];
                $codes = array_values(array_map(fn($o) => $o['code'], array_filter($out, fn($o) => $o['unit_id'])));
                upd('sale_items', ['cost' => $cost, 'units' => json_encode($codes)], 'id=?', [$iid]);
                $totalCost += $cost;
            }
            if ($cartId) { q("DELETE FROM stock_reservations WHERE cart_id=?", [$cartId]); upd('carts', ['status' => 'done'], 'id=?', [$cartId]); }
            upd('sales', ['cost' => $totalCost], 'id=?', [$sid]);
            foreach ($plan['payments'] as $p) ins('receipts', ['sale_id' => $sid, 'shift_id' => $shift['id'] ?? null, 'cashbox_id' => $cb['id'] ?? null, 'method' => $p['method'], 'amount' => $p['amount'], 'ref' => $p['ref'] ?: null, 'customer_id' => $cust, 'at' => now()]);
            if ($credit) customer_ledger_add($cust, $credit, 'sale_credit', 'sale', $sid, 'نسیهٔ فروش', !empty($in['due']) ? jparse($in['due']) : null);
            if ($wallet) wallet_add($cust, -$wallet, 'spend', 'sale', $sid, 'پرداخت از کیف پول');
            ledger_post('sale', $sid);
            if (!$defer) { ledger_post_cogs($sid); commission_for_sale($sid); }
            do_action('sale_before_commit', ['sale_id' => $sid, 'input' => $in]);
            audit('sales', $sid, 'create', null, ['total' => $calc['total'], 'seller' => $seller], 'فروش');
            return $sid;
        });
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'cart_token') || $e->getCode() == 23000) { $ex = val("SELECT id FROM sales WHERE cart_token=?", [$token]); if ($ex) return (int)$ex; }
        throw $e;
    }
    do_action('sale_committed', $saleId);
    if (($in['partner_mode'] ?? '') === 'hold') upd('sales', ['fulfil' => 'hold'], 'id=?', [$saleId]);
    if (($in['partner_mode'] ?? '') === 'ship') { try { pship_sms_route($saleId); } catch (Throwable $e) { error_log('pship: ' . $e->getMessage()); } }
    try { sms_notify_sale($saleId); } catch (Throwable $e) { error_log('sms: ' . $e->getMessage()); }
    return $saleId;
}
/** ثبتِ سبدِ صندوق */
function pos_checkout(int $cartId, array $payments, array $opt = []): array {
    $c = cart_get($cartId);
    $items = rows("SELECT * FROM cart_items WHERE cart_id=? ORDER BY id", [$cartId]);
    $lines = []; $byKey = [];
    foreach ($items as $i) {
        $k = $i['product_id'] . ':' . ($i['variant_id'] ?: 0) . ':' . ($i['unit_id'] ? 'u' : 'g') . ':' . (int)$i['price'];
        if (!isset($byKey[$k])) { $byKey[$k] = count($lines); $lines[] = ['product_id' => (int)$i['product_id'], 'variant_id' => $i['variant_id'], 'qty' => 0, 'disc' => 0, 'unit_ids' => []]; }
        $j = $byKey[$k]; $lines[$j]['qty'] += (int)$i['qty']; $lines[$j]['disc'] += (int)$i['disc']; if ($i['unit_id']) $lines[$j]['unit_ids'][] = (int)$i['unit_id'];
    }
    $sid = sale_commit(['cart_token' => $c['token'], 'cart_id' => $cartId, 'shift_id' => $c['shift_id'], 'customer_id' => $c['customer_id'], 'doc_disc' => (int)$c['doc_disc'], 'lines' => $lines,
        'payments' => $payments, 'note' => $c['note'], 'due' => $opt['due'] ?? null, 'partner_mode' => $opt['partner_mode'] ?? null, 'ship_to' => $opt['ship_to'] ?? null, 'defer_stock' => !empty($opt['defer_stock'])]);
    $s = row("SELECT number, total FROM sales WHERE id=?", [$sid]);
    $change = pay_plan_validate((int)$s['total'], $payments)['change'];
    return ['sale_id' => $sid, 'number' => $s['number'], 'change' => $change];
}
function sale_get(int $id): ?array {
    $s = row("SELECT s.*, c.name cname, c.mobile cmobile, u.name seller_name, ch.name chname, cb.name cbname, b.name bname FROM sales s LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN users u ON u.id=s.seller_id
        LEFT JOIN channels ch ON ch.id=s.channel_id LEFT JOIN cashboxes cb ON cb.id=s.cashbox_id LEFT JOIN branches b ON b.id=s.branch_id WHERE s.id=?", [$id]);
    if (!$s) return null;
    $s['items'] = rows("SELECT si.*, p.unit_id FROM sale_items si JOIN products p ON p.id=si.product_id WHERE si.sale_id=?", [$id]);
    $s['receipts'] = rows("SELECT * FROM receipts WHERE sale_id=? ORDER BY id", [$id]);
    $s['returns'] = rows("SELECT * FROM sale_returns WHERE sale_id=? ORDER BY id", [$id]);
    return $s;
}

/** مرجوعی: فروش پاک نمی‌شود؛ برگشت سندِ جداست */
function return_commit(int $saleId, array $items, string $reason, string $method = 'cash'): int {
    $u = require_login();
    if (mb_strlen(trim($reason)) < 2) throw new UserError('دلیلِ برگشت لازم است');
    if (!in_array($method, ['cash', 'card', 'transfer', 'credit', 'wallet'], true)) throw new UserError('روشِ بازپرداختِ نامعتبر');
    $d = today(); fiscal_assert_open($d);
    return tx(function () use ($saleId, $items, $reason, $method, $u, $d) {
        $s = row("SELECT * FROM sales WHERE id=? FOR UPDATE", [$saleId]); if (!$s) throw new UserError('فروش یافت نشد');
        if ($s['fulfil'] !== 'done' && $s['fulfil'] !== 'shipped') throw new UserError('این سفارش هنوز ارسال نشده؛ آن را از صفِ خروج بازنشانی کنید');
        $shift = shift_of_user((int)$u['id']);
        if ($method === 'cash' && !$shift) throw new UserError('برای بازپرداختِ نقد شیفتِ باز لازم است');
        if (in_array($method, ['credit', 'wallet'], true) && !$s['customer_id']) throw new UserError('فروشِ گذری به حسابِ مشتری برنمی‌گردد');
        $rid = ins('sale_returns', ['sale_id' => $saleId, 'shift_id' => $shift['id'] ?? null, 'user_id' => $u['id'], 'reason' => mb_cut(trim($reason), 400), 'amount' => 0, 'refund_method' => $method, 'd' => $d, 'at' => now()]);
        $amount = 0; $tax = 0; $cost = 0; $any = false;
        foreach ($items as $r) {
            $q = to_int($r['qty'] ?? 0); if ($q <= 0) continue;
            $it = row("SELECT si.*, p.serial, p.unit_id FROM sale_items si JOIN products p ON p.id=si.product_id WHERE si.id=? AND si.sale_id=? FOR UPDATE", [(int)$r['sale_item_id'], $saleId]);
            if (!$it) throw new UserError('ردیفِ فروش یافت نشد');
            $max = (int)$it['qty'] - (int)$it['returned_qty'];
            if ($q > $max) throw new UserError('حداکثر ' . fa_num($max) . ' عدد');
            $net = intdiv((int)$it['net'] * $q, (int)$it['qty']); $t = intdiv((int)$it['tax'] * $q, (int)$it['qty']); $c = intdiv((int)$it['cost'] * $q, (int)$it['qty']);
            $units = json_decode((string)$it['units'], true) ?: [];
            $back = [];
            if ($it['serial'] && $units) {
                $want = array_values(array_intersect((array)($r['units'] ?? []), $units)) ?: array_slice(array_values(array_filter($units, fn($x) => (wh_unit_by_code($x)['status'] ?? '') === 'out')), 0, $q);
                foreach (array_slice($want, 0, $q) as $code) { wh_stock_in($code, 1, ['kind' => 'return', 'ref_type' => 'return', 'ref_id' => $rid]); $back[] = $code; }
            } elseif ((int)$it['cost'] > 0 || val("SELECT 1 FROM stock_moves WHERE ref_type='sale' AND ref_id=? AND product_id=? LIMIT 1", [$saleId, $it['product_id']])) {
                $lotId = (int)val("SELECT lot_id FROM stock_moves WHERE ref_type='sale' AND ref_id=? AND product_id=? AND (variant_id<=>?) ORDER BY id LIMIT 1", [$saleId, $it['product_id'], $it['variant_id']]);
                if ($lotId) wh_stock_in((string)val("SELECT code FROM lots WHERE id=?", [$lotId]), $q, ['kind' => 'return', 'unit_cost' => intdiv($c, max(1, $q)), 'ref_type' => 'return', 'ref_id' => $rid]);
            }
            ins('sale_return_items', ['return_id' => $rid, 'sale_item_id' => $it['id'], 'qty' => $q, 'amount' => $net, 'tax' => $t, 'cost' => $c, 'units' => json_encode($back)]);
            q("UPDATE sale_items SET returned_qty=returned_qty+? WHERE id=?", [$q, $it['id']]);
            commission_reverse((int)$it['id'], $q, $rid);
            $amount += $net; $tax += $t; $cost += $c; $any = true;
        }
        if (!$any) throw new UserError('ردیفی برای برگشت انتخاب نشده است');
        $refund = $amount + $tax;
        upd('sale_returns', ['amount' => $amount, 'tax' => $tax, 'cost' => $cost], 'id=?', [$rid]);
        if ($method === 'credit') customer_ledger_add((int)$s['customer_id'], -$refund, 'return', 'return', $rid, 'برگشت از فروشِ ' . $s['number']);
        elseif ($method === 'wallet') wallet_add((int)$s['customer_id'], $refund, 'refund', 'return', $rid, 'برگشت از فروشِ ' . $s['number']);
        else ins('receipts', ['return_id' => $rid, 'sale_id' => $saleId, 'shift_id' => $shift['id'] ?? null, 'cashbox_id' => $shift['cashbox_id'] ?? null, 'method' => $method, 'amount' => -$refund, 'customer_id' => $s['customer_id'], 'at' => now()]);
        ledger_post('return', $rid);
        if ($s['channel_id']) channel_ledger_add((int)$s['channel_id'], -$refund, 'refund', 'return', $rid, 'برگشتِ ' . $s['number']);
        audit('sale_returns', $rid, 'create', null, ['sale' => $s['number'], 'amount' => $refund, 'reason' => $reason], 'مرجوعی');
        do_action('sale_returned', $rid, $saleId);
        return $rid;
    });
}
