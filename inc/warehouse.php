<?php
// انبارِ چندگانه با کدِ دانه — «موجودی یک عدد نیست؛ مجموعِ دانه‌هاست» (منبعِ حقیقت: stock_moves)
declare(strict_types=1);

function wh_ownerships(): array { return ['O' => 'خریداری‌شده', 'C' => 'امانی (فروشنده)']; }
function wh_get(int $id): ?array { return row("SELECT * FROM warehouses WHERE id=?", [$id]); }
/** کدِ کامل (۲۷) = کدِ کالا/ترکیب (۲۰) + فروشنده (۲) + مالکیت (۱) + انبار (۴) */
function wh_lot_code(string $item20, string $seller, string $own, string $wh4): string { return $item20 . substr(sprintf('%02s', $seller), 0, 2) . $own . $wh4; }
function wh_parse_code(string $code): array {
    $code = strtoupper($code); $n = strlen($code);
    $o = ['len' => $n, 'cat' => substr($code, 0, 4), 'type' => substr($code, 4, 3), 'pid' => (int)substr($code, 7, 11), 'vcode' => substr($code, 18, 2)];
    if ($n >= 27) { $o += ['seller' => substr($code, 20, 2), 'own' => substr($code, 22, 1), 'wh' => substr($code, 23, 4), 'lot_code' => substr($code, 0, 27)]; }
    if ($n == 33) $o['serial'] = substr($code, 27, 6);
    return $o;
}
function unit_parse(string $code): ?array { return strlen($code) == 33 ? wh_parse_code($code) : null; }
function wh_pretty_code(string $c): string { $c = strtoupper($c); return trim(implode(' ', array_filter([substr($c, 0, 4), substr($c, 4, 3), substr($c, 7, 11), substr($c, 18, 2), substr($c, 20, 2), substr($c, 22, 1), substr($c, 23, 4), substr($c, 27, 6)], 'strlen'))); }

function wh_item20(int $pid, ?int $vid): string {
    if ($vid) { $c = val("SELECT code FROM product_variants WHERE id=? AND product_id=?", [$vid, $pid]); if ($c) return $c; }
    $c = val("SELECT base_code FROM products WHERE id=?", [$pid]); if (!$c) throw new UserError('کالا یافت نشد'); return $c;
}
/** محموله (کالا × انبار × فروشنده × مالکیت) را بیاب یا بساز؛ بهای واحد با میانگینِ متحرک */
function wh_lot_ensure(int $pid, ?int $vid, int $whId, string $seller = '00', string $own = 'O', ?int $supplierId = null): array {
    $w = wh_get($whId); if (!$w) throw new UserError('انبار یافت نشد');
    $code = wh_lot_code(wh_item20($pid, $vid), $seller, $own, $w['code']);
    $l = row("SELECT * FROM lots WHERE code=?", [$code]);
    if ($l) return $l;
    $id = ins('lots', ['product_id' => $pid, 'variant_id' => $vid, 'warehouse_id' => $whId, 'seller_code' => $seller, 'ownership' => $own, 'code' => $code, 'supplier_id' => $supplierId, 'created_at' => now()]);
    return row("SELECT * FROM lots WHERE id=?", [$id]);
}
function wh_lot_balance(int $lotId): int { return (int)val("SELECT COALESCE(SUM(qty),0) FROM stock_moves WHERE lot_id=?", [$lotId]); }
function wh_move(array $lot, int $qty, string $kind, array $opt = []): int {
    $id = ins('stock_moves', ['lot_id' => $lot['id'], 'unit_id' => $opt['unit_id'] ?? null, 'product_id' => $lot['product_id'], 'variant_id' => $lot['variant_id'], 'warehouse_id' => $lot['warehouse_id'],
        'qty' => $qty, 'kind' => $kind, 'ref_type' => $opt['ref_type'] ?? null, 'ref_id' => $opt['ref_id'] ?? null, 'unit_cost' => (int)($opt['unit_cost'] ?? $lot['unit_cost']),
        'note' => $opt['note'] ?? null, 'user_id' => current_user()['id'] ?? null, 'at' => now()]);
    return $id;
}
/** افزودنِ دانه‌ها (برچسب‌خورده، هنوز موجودی نیستند — اسکن موجودی می‌سازد) */
function wh_units_add(int $lotId, int $qty, array $opt = []): array {
    $lot = row("SELECT * FROM lots WHERE id=?", [$lotId]); if (!$lot) throw new UserError('محموله یافت نشد');
    if ($qty < 1 || $qty > 5000) throw new UserError('تعدادِ دانه نامعتبر است');
    $codes = [];
    $n = (int)val("SELECT COUNT(*) FROM wh_units WHERE lot_id=?", [$lotId]);
    for ($i = 0; $i < $qty; $i++) {
        $n++;
        $code = $lot['code'] . str_pad(strtoupper(base_convert((string)$n, 10, 36)), 6, '0', STR_PAD_LEFT);
        ins('wh_units', ['lot_id' => $lotId, 'code' => $code, 'status' => $opt['status'] ?? 'labeled', 'unit_cost' => (int)($opt['unit_cost'] ?? $lot['unit_cost']), 'created_at' => now(), 'in_at' => ($opt['status'] ?? '') === 'in_stock' ? now() : null]);
        $codes[] = $code;
    }
    return $codes;
}
function wh_unit_by_code(string $code): ?array { return row("SELECT u.*, l.product_id, l.variant_id, l.warehouse_id, l.code lot_code FROM wh_units u JOIN lots l ON l.id=u.lot_id WHERE u.code=?", [strtoupper($code)]); }
/** به‌روزرسانیِ بهای میانگینِ محموله پیش از ورود */
function wh_lot_cost_in(array $lot, int $qty, int $unitCost): void {
    $bal = max(0, wh_lot_balance((int)$lot['id']));
    $avg = ($bal + $qty) > 0 ? (int)round(($bal * (int)$lot['unit_cost'] + $qty * $unitCost) / ($bal + $qty)) : $unitCost;
    upd('lots', ['unit_cost' => $avg], 'id=?', [$lot['id']]);
}
/** ورود: کدِ دانه (۳۳) یا کدِ محموله (۲۷) */
function wh_stock_in(string $code, int $qty = 1, array $opt = []): array {
    $code = strtoupper(trim($code));
    return tx(function () use ($code, $qty, $opt) {
        if (strlen($code) == 33) {
            $u = wh_unit_by_code($code);
            if (!$u) throw new UserError('این کد تعریف نشده است');
            if ($u['status'] === 'in_stock') { $w = wh_get((int)$u['warehouse_id']); throw new UserError('این دانه قبلاً وارد شده و در «' . ($w['name'] ?? '') . '» است'); }
            if ($u['status'] === 'void') throw new UserError('این دانه باطل شده است');
            $lot = row("SELECT * FROM lots WHERE id=?", [$u['lot_id']]);
            if ($u['status'] === 'labeled') wh_lot_cost_in($lot, 1, (int)$u['unit_cost']);
            upd('wh_units', ['status' => 'in_stock', 'in_at' => now(), 'sale_id' => null, 'out_at' => null], 'id=?', [$u['id']]);
            wh_move($lot, 1, $opt['kind'] ?? 'in', ['unit_id' => $u['id'], 'unit_cost' => (int)$u['unit_cost']] + $opt);
            do_action('stock_changed', (int)$lot['product_id'], $lot['variant_id'] ? (int)$lot['variant_id'] : null); catalog_touch((int)$lot['product_id']);
            return ['lot' => $lot['code'], 'unit' => $code, 'qty' => 1];
        }
        $lot = row("SELECT l.*, p.serial FROM lots l JOIN products p ON p.id=l.product_id WHERE l.code=?", [substr($code, 0, 27)]);
        if (!$lot) throw new UserError('این کد تعریف نشده است');
        if ($qty < 1) throw new UserError('تعداد باید مثبت باشد');
        $cost = (int)($opt['unit_cost'] ?? $lot['unit_cost']);
        wh_lot_cost_in($lot, $qty, $cost); $lot['unit_cost'] = (int)val("SELECT unit_cost FROM lots WHERE id=?", [$lot['id']]);
        $units = [];
        if ($lot['serial']) {
            $units = wh_units_add((int)$lot['id'], $qty, ['status' => 'in_stock', 'unit_cost' => $cost]);
            foreach ($units as $uc) { $uid = (int)val("SELECT id FROM wh_units WHERE code=?", [$uc]); wh_move($lot, 1, $opt['kind'] ?? 'in', ['unit_id' => $uid, 'unit_cost' => $cost] + $opt); }
        } else wh_move($lot, $qty, $opt['kind'] ?? 'in', ['unit_cost' => $cost] + $opt);
        do_action('stock_changed', (int)$lot['product_id'], $lot['variant_id'] ? (int)$lot['variant_id'] : null); catalog_touch((int)$lot['product_id']);
        return ['lot' => $lot['code'], 'qty' => $qty, 'units' => $units];
    });
}
/** خروج (FIFO): هر خروج دقیقاً می‌گوید کدام دانه رفت. برمی‌گرداند: [[unit_id, code, cost, qty, lot_id]] */
function wh_stock_out(array $sel, int $qty, array $opt = []): array {
    // sel: ['unit_code'=>..] | ['lot_id'=>..] | ['product_id'=>..,'variant_id'=>..,'warehouse_id'=>..]
    return tx(function () use ($sel, $qty, $opt) {
        $out = [];
        if (!empty($sel['unit_code']) || !empty($sel['unit_id'])) {
            $u = !empty($sel['unit_id']) ? row("SELECT u.*, l.product_id, l.variant_id, l.warehouse_id FROM wh_units u JOIN lots l ON l.id=u.lot_id WHERE u.id=?", [$sel['unit_id']]) : wh_unit_by_code($sel['unit_code']);
            if (!$u) throw new UserError('این کد تعریف نشده است');
            if ($u['status'] !== 'in_stock') throw new UserError('این دانه در انبار نیست (' . wh_unit_status_label($u['status']) . ')');
            $lot = row("SELECT * FROM lots WHERE id=?", [$u['lot_id']]);
            upd('wh_units', ['status' => 'out', 'out_at' => now(), 'sale_id' => ($opt['ref_type'] ?? '') === 'sale' ? $opt['ref_id'] : null], 'id=?', [$u['id']]);
            wh_move($lot, -1, $opt['kind'] ?? 'out', ['unit_id' => $u['id'], 'unit_cost' => (int)$u['unit_cost']] + $opt);
            $out[] = ['unit_id' => (int)$u['id'], 'code' => $u['code'], 'cost' => (int)$u['unit_cost'], 'qty' => 1, 'lot_id' => (int)$lot['id']];
            do_action('stock_changed', (int)$lot['product_id'], $lot['variant_id'] ? (int)$lot['variant_id'] : null); catalog_touch((int)$lot['product_id']);
            return $out;
        }
        if (!empty($sel['lot_id'])) $lots = rows("SELECT l.*, p.serial FROM lots l JOIN products p ON p.id=l.product_id WHERE l.id=?", [$sel['lot_id']]);
        else $lots = rows("SELECT l.*, p.serial FROM lots l JOIN products p ON p.id=l.product_id WHERE l.product_id=? AND " . (!empty($sel['variant_id']) ? "l.variant_id=" . (int)$sel['variant_id'] : "l.variant_id IS NULL") . " AND l.warehouse_id=? ORDER BY l.id", [(int)$sel['product_id'], (int)$sel['warehouse_id']]);
        $need = $qty; $exclude = array_map('intval', $opt['exclude_units'] ?? []);
        foreach ($lots as $lot) {
            if ($need <= 0) break;
            if ($lot['serial']) {
                $us = rows("SELECT * FROM wh_units WHERE lot_id=? AND status='in_stock'" . ($exclude ? " AND id NOT IN (" . in_list($exclude) . ")" : "") . " AND id NOT IN (SELECT unit_id FROM stock_reservations WHERE unit_id IS NOT NULL" . (!empty($opt['own_cart']) ? " AND (cart_id IS NULL OR cart_id<>" . (int)$opt['own_cart'] . ")" : "") . ") ORDER BY in_at, id LIMIT " . (int)$need, [$lot['id']]);
                foreach ($us as $u) {
                    upd('wh_units', ['status' => 'out', 'out_at' => now(), 'sale_id' => ($opt['ref_type'] ?? '') === 'sale' ? $opt['ref_id'] : null], 'id=?', [$u['id']]);
                    wh_move($lot, -1, $opt['kind'] ?? 'out', ['unit_id' => $u['id'], 'unit_cost' => (int)$u['unit_cost']] + $opt);
                    $out[] = ['unit_id' => (int)$u['id'], 'code' => $u['code'], 'cost' => (int)$u['unit_cost'], 'qty' => 1, 'lot_id' => (int)$lot['id']]; $need--;
                }
            } else {
                $bal = wh_lot_balance((int)$lot['id']); $take = min($bal, $need);
                if ($take > 0) { wh_move($lot, -$take, $opt['kind'] ?? 'out', $opt); $out[] = ['unit_id' => null, 'code' => $lot['code'], 'cost' => (int)$lot['unit_cost'], 'qty' => $take, 'lot_id' => (int)$lot['id']]; $need -= $take; }
            }
        }
        if ($need > 0) throw new UserError('موجودی کافی نیست (کمبود ' . fa_num($need) . ')');
        if ($lots) { do_action('stock_changed', (int)$lots[0]['product_id'], $lots[0]['variant_id'] ? (int)$lots[0]['variant_id'] : null); catalog_touch((int)$lots[0]['product_id']); }
        return $out;
    });
}
function wh_unit_status_label(string $s): string { return ['labeled' => 'برچسب‌خورده، واردنشده', 'in_stock' => 'در انبار', 'out' => 'خارج‌شده', 'void' => 'باطل'][$s] ?? $s; }

/** موجودیِ واقعی (جمعِ حرکت‌ها) */
function stock_on_hand(int $pid, ?int $vid = null, ?array $whIds = null): int {
    $w = "m.product_id=?" . ($vid ? " AND m.variant_id=" . (int)$vid : "") . ($whIds !== null ? " AND m.warehouse_id IN (" . in_list($whIds) . ")" : "");
    return (int)val("SELECT COALESCE(SUM(m.qty),0) FROM stock_moves m JOIN lots l ON l.id=m.lot_id WHERE $w", [$pid]);
}
function stock_reserved(int $pid, ?int $vid = null, ?array $whIds = null, ?int $exceptCart = null): int {
    $w = "product_id=? AND (expires_at IS NULL OR expires_at>=?)" . ($vid ? " AND variant_id=" . (int)$vid : "") . ($whIds !== null ? " AND warehouse_id IN (" . in_list($whIds) . ")" : "") . ($exceptCart ? " AND (cart_id IS NULL OR cart_id<>" . (int)$exceptCart . ")" : "");
    return (int)val("SELECT COALESCE(SUM(qty),0) FROM stock_reservations WHERE $w", [$pid, now()]);
}
/** موجودیِ قابلِ فروش = موجودی − رزرو (همهٔ انبارهای فروش) */
function sellable_stock(int $pid, ?int $vid = null, ?array $whIds = null): int {
    $t = val("SELECT item_type FROM products WHERE id=?", [$pid]);
    if (!in_array($t, wh_site_types(), true)) return 0;
    return max(0, stock_on_hand($pid, $vid, $whIds) - stock_reserved($pid, $vid, $whIds));
}
function stock_reservations_gc(): void { q("DELETE FROM stock_reservations WHERE expires_at IS NOT NULL AND expires_at<? AND sale_id IS NULL", [now()]); }

/** رزرو برای سبد (دو صندوق یک دانه را هم‌زمان نمی‌فروشند) */
function stock_reserve(int $cartId, ?int $cashboxId, int $pid, ?int $vid, int $whId, int $qty, ?int $unitId = null): int {
    stock_reservations_gc();
    $exp = date('Y-m-d H:i:s', time() + 60 * (int)setting('reserve_minutes', 30));
    if ($unitId) {
        $u = row("SELECT u.*, l.warehouse_id FROM wh_units u JOIN lots l ON l.id=u.lot_id WHERE u.id=?", [$unitId]);
        if (!$u || $u['status'] !== 'in_stock') throw new UserError('این دانه در انبار نیست');
        $r = row("SELECT r.*, c.name cb FROM stock_reservations r LEFT JOIN cashboxes c ON c.id=r.cashbox_id WHERE r.unit_id=?", [$unitId]);
        if ($r) { if ((int)$r['cart_id'] === $cartId) throw new UserError('این دانه همین الان در سبد است'); throw new UserError('در سبدِ صندوقِ دیگری است' . ($r['cb'] ? ' (' . $r['cb'] . ')' : '')); }
        try { return ins('stock_reservations', ['cart_id' => $cartId, 'cashbox_id' => $cashboxId, 'lot_id' => $u['lot_id'], 'unit_id' => $unitId, 'product_id' => $pid, 'variant_id' => $vid, 'warehouse_id' => $u['warehouse_id'], 'qty' => 1, 'expires_at' => $exp, 'created_at' => now()]); }
        catch (PDOException $e) { throw new UserError('در سبدِ صندوقِ دیگری است'); }
    }
    $avail = stock_on_hand($pid, $vid, [$whId]) - stock_reserved($pid, $vid, [$whId]);
    if ($avail < $qty) throw new UserError('موجودیِ قابلِ فروش کافی نیست (موجود: ' . fa_num(max(0, $avail)) . ')');
    return ins('stock_reservations', ['cart_id' => $cartId, 'cashbox_id' => $cashboxId, 'product_id' => $pid, 'variant_id' => $vid, 'warehouse_id' => $whId, 'qty' => $qty, 'expires_at' => $exp, 'created_at' => now()]);
}
function stock_release_cart(int $cartId): void { q("DELETE FROM stock_reservations WHERE cart_id=?", [$cartId]); }

/** نمای کلیِ موجودی برای هر کالا/ترکیب/انبار */
function wh_overview(array $f = []): array {
    $w = ['1=1']; $p = [];
    if (!empty($f['warehouse_id'])) { $w[] = 'l.warehouse_id=?'; $p[] = (int)$f['warehouse_id']; }
    if (!empty($f['q'])) { $w[] = '(pr.name LIKE ? OR l.code LIKE ?)'; $p[] = '%' . $f['q'] . '%'; $p[] = strtoupper($f['q']) . '%'; }
    $rs = rows("SELECT l.id lot_id, l.code, l.product_id, l.variant_id, l.warehouse_id, l.ownership, l.unit_cost, pr.name, pr.unit_id, pr.min_stock, v.title vtitle, w.name wname,
        (SELECT COALESCE(SUM(qty),0) FROM stock_moves m WHERE m.lot_id=l.id) on_hand,
        (SELECT COALESCE(SUM(qty),0) FROM stock_reservations r WHERE r.lot_id=l.id OR (r.lot_id IS NULL AND r.product_id=l.product_id AND (r.variant_id<=>l.variant_id) AND r.warehouse_id=l.warehouse_id)) reserved,
        (SELECT COUNT(*) FROM wh_units u WHERE u.lot_id=l.id AND u.status='labeled') labeled
        FROM lots l JOIN products pr ON pr.id=l.product_id LEFT JOIN product_variants v ON v.id=l.variant_id JOIN warehouses w ON w.id=l.warehouse_id
        WHERE " . implode(' AND ', $w) . " ORDER BY pr.name, l.code LIMIT 1000", $p);
    return $rs;
}
function low_stock(int $limit = 20): array {
    return rows("SELECT p.id, p.name, p.min_stock, COALESCE((SELECT SUM(m.qty) FROM stock_moves m WHERE m.product_id=p.id),0) on_hand FROM products p WHERE p.active=1 AND p.min_stock>0
        HAVING on_hand < p.min_stock ORDER BY on_hand LIMIT " . (int)$limit);
}

// ── خروجِ سفارش (فروش‌های کانال/همکار که باید جمع و ارسال شوند)
function whops_out_queue(): array {
    return rows("SELECT s.*, c.name cname, ch.name chname FROM sales s LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN channels ch ON ch.id=s.channel_id WHERE s.fulfil IN ('pending','picking') AND s.status='done' ORDER BY s.id");
}
function whops_find_sale(string $q): ?array { $q = trim(en_num($q)); return row("SELECT * FROM sales WHERE number=? OR id=? LIMIT 1", [$q, ctype_digit($q) ? (int)$q : 0]); }
function whops_order_scanned_units(int $saleId): array { return rows("SELECT r.*, u.code FROM stock_reservations r JOIN wh_units u ON u.id=r.unit_id WHERE r.sale_id=? AND r.unit_id IS NOT NULL", [$saleId]); }
/** اسکنِ یک دانه برای یک قلمِ سفارش */
function whops_order_scan_item(int $saleId, string $code): array {
    return tx(function () use ($saleId, $code) {
        $s = row("SELECT * FROM sales WHERE id=? FOR UPDATE", [$saleId]);
        if (!$s || !in_array($s['fulfil'], ['pending', 'picking'], true)) throw new UserError('این سفارش در صفِ خروج نیست');
        $u = wh_unit_by_code(scan_normalize($code));
        if (!$u) throw new UserError('این کد دانهٔ شناخته‌شده‌ای نیست');
        if ($u['status'] !== 'in_stock') throw new UserError('این دانه در انبار نیست');
        $r = row("SELECT * FROM stock_reservations WHERE unit_id=?", [$u['id']]);
        if ($r && (int)$r['sale_id'] === $saleId) throw new UserError('این دانه قبلاً برای همین سفارش اسکن شده است');
        if ($r) throw new UserError('این دانه برای سفارش/سبدِ دیگری رزرو است');
        $item = row("SELECT si.* FROM sale_items si WHERE si.sale_id=? AND si.product_id=? AND (si.variant_id<=>?) LIMIT 1", [$saleId, $u['product_id'], $u['variant_id']]);
        if (!$item) throw new UserError('این کالا در این سفارش نیست');
        $picked = (int)val("SELECT COUNT(*) FROM stock_reservations WHERE sale_id=? AND unit_id IS NOT NULL AND product_id=? AND (variant_id<=>?)", [$saleId, $u['product_id'], $u['variant_id']]);
        if ($picked >= (int)$item['qty']) throw new UserError('همهٔ تعدادِ این قلم اسکن شده است');
        // رزروِ عمومیِ همین قلم را به دانه تبدیل کن
        $gen = row("SELECT * FROM stock_reservations WHERE sale_id=? AND unit_id IS NULL AND product_id=? AND (variant_id<=>?) LIMIT 1", [$saleId, $u['product_id'], $u['variant_id']]);
        if ($gen) { if ((int)$gen['qty'] > 1) q("UPDATE stock_reservations SET qty=qty-1 WHERE id=?", [$gen['id']]); else q("DELETE FROM stock_reservations WHERE id=?", [$gen['id']]); }
        ins('stock_reservations', ['sale_id' => $saleId, 'lot_id' => $u['lot_id'], 'unit_id' => $u['id'], 'product_id' => $u['product_id'], 'variant_id' => $u['variant_id'], 'warehouse_id' => $u['warehouse_id'], 'qty' => 1, 'created_at' => now()]);
        if ($s['fulfil'] === 'pending') upd('sales', ['fulfil' => 'picking'], 'id=?', [$saleId]);
        return ['code' => $u['code'], 'item' => $item['title'], 'picked' => $picked + 1, 'qty' => (int)$item['qty']];
    });
}
/** بستنِ سفارش: همهٔ اقلام اسکن شده باشند ← خروج + سندِ بهای تمام‌شده */
function whops_order_close(int $saleId): array {
    return tx(function () use ($saleId) {
        $s = row("SELECT * FROM sales WHERE id=? FOR UPDATE", [$saleId]);
        if (!$s || !in_array($s['fulfil'], ['pending', 'picking'], true)) throw new UserError('این سفارش در صفِ خروج نیست');
        $cost = 0;
        foreach (rows("SELECT si.*, p.serial FROM sale_items si JOIN products p ON p.id=si.product_id WHERE si.sale_id=?", [$saleId]) as $it) {
            $units = [];
            if ($it['serial']) {
                $res = rows("SELECT * FROM stock_reservations WHERE sale_id=? AND unit_id IS NOT NULL AND product_id=? AND (variant_id<=>?)", [$saleId, $it['product_id'], $it['variant_id']]);
                if (count($res) < (int)$it['qty']) throw new UserError('همهٔ اقلام اسکن نشده‌اند: ' . $it['title'] . ' (' . fa_num(count($res)) . ' از ' . fa_num($it['qty']) . ')');
                q("DELETE FROM stock_reservations WHERE sale_id=? AND unit_id IS NOT NULL AND product_id=? AND (variant_id<=>?)", [$saleId, $it['product_id'], $it['variant_id']]);
                foreach ($res as $r) $units = array_merge($units, wh_stock_out(['unit_id' => (int)$r['unit_id']], 1, ['ref_type' => 'sale', 'ref_id' => $saleId]));
            } else {
                q("DELETE FROM stock_reservations WHERE sale_id=? AND product_id=? AND (variant_id<=>?)", [$saleId, $it['product_id'], $it['variant_id']]);
                $units = wh_stock_out(['product_id' => (int)$it['product_id'], 'variant_id' => $it['variant_id'], 'warehouse_id' => (int)$s['warehouse_id']], (int)$it['qty'], ['ref_type' => 'sale', 'ref_id' => $saleId]);
            }
            $c = 0; foreach ($units as $u) $c += $u['cost'] * $u['qty'];
            upd('sale_items', ['cost' => $c, 'units' => json_encode(array_values(array_map(fn($o) => $o['code'], array_filter($units, fn($o) => $o['unit_id']))))], 'id=?', [$it['id']]);
            $cost += $c;
        }
        upd('sales', ['fulfil' => 'shipped', 'cost' => $cost], 'id=?', [$saleId]);
        ledger_post_cogs($saleId);
        commission_for_sale($saleId);
        audit('sales', $saleId, 'ship', ['fulfil' => $s['fulfil']], ['fulfil' => 'shipped'], 'بستنِ سفارش');
        do_action('sale_status_changed', $saleId, 'shipped');
        return ['ok' => true, 'cost' => $cost];
    });
}
/** برگشتِ سفارشِ ارسال‌نشده: آزادکردنِ دانه‌ها با دلیل */
function whops_order_reset(int $saleId, string $reason): void {
    if (trim($reason) === '') throw new UserError('دلیل لازم است');
    q("DELETE FROM stock_reservations WHERE sale_id=? AND unit_id IS NOT NULL", [$saleId]);
    foreach (rows("SELECT * FROM sale_items WHERE sale_id=?", [$saleId]) as $it)
        if (!val("SELECT id FROM stock_reservations WHERE sale_id=? AND product_id=? AND (variant_id<=>?)", [$saleId, $it['product_id'], $it['variant_id']]))
            ins('stock_reservations', ['sale_id' => $saleId, 'product_id' => $it['product_id'], 'variant_id' => $it['variant_id'], 'warehouse_id' => (int)val("SELECT warehouse_id FROM sales WHERE id=?", [$saleId]), 'qty' => $it['qty'], 'created_at' => now()]);
    upd('sales', ['fulfil' => 'pending'], 'id=?', [$saleId]);
    audit('sales', $saleId, 'unpick', null, ['reason' => $reason], 'بازنشانیِ اسکنِ سفارش');
}

/** ضرر / عودت به تأمین‌کننده: ردِ فیزیکی + مالی + تصمیمی */
function stock_loss(array $in): int {
    $kind = in_array($in['kind'] ?? '', ['loss', 'return', 'buyout'], true) ? $in['kind'] : 'loss';
    $reason = trim((string)($in['reason'] ?? '')); if (mb_strlen($reason) < 3) throw new UserError('دلیل را بنویسید');
    if (setting('loss_sms_confirm', '0') === '1') {
        $u = require_login();
        if (!$u['mobile'] || !otp_check($u['mobile'], (string)($in['otp'] ?? ''), 'confirm')) throw new UserError('کدِ تأییدِ پیامکی نادرست است');
    }
    $code = strtoupper(scan_normalize((string)($in['code'] ?? ''))); $qty = max(1, to_int($in['qty'] ?? 1));
    return tx(function () use ($kind, $reason, $code, $qty, $in) {
        if (strlen($code) == 33) { $u = wh_unit_by_code($code); if (!$u) throw new UserError('این کد تعریف نشده است'); $lot = row("SELECT * FROM lots WHERE id=?", [$u['lot_id']]); $sel = ['unit_code' => $code]; $qty = 1; }
        else { $lot = row("SELECT * FROM lots WHERE code=?", [substr($code, 0, 27)]); if (!$lot) throw new UserError('کدِ محموله یافت نشد'); $sel = ['lot_id' => (int)$lot['id']]; }
        $out = wh_stock_out($sel, $qty, ['kind' => $kind, 'note' => $reason, 'ref_type' => 'loss']);
        $amount = 0; foreach ($out as $o) $amount += $o['cost'] * $o['qty'];
        $bearer = $lot['ownership'] === 'C' ? (($in['bearer'] ?? 'seller') === 'store' ? 'store' : 'seller') : 'store';
        $id = ins('stock_losses', ['kind' => $kind, 'lot_id' => $lot['id'], 'qty' => $qty, 'amount' => $amount, 'bearer' => $bearer, 'reason' => $reason, 'units' => json_encode(array_column($out, 'code')),
            'confirmed_by' => current_user()['id'] ?? null, 'created_at' => now()]);
        q("UPDATE stock_moves SET ref_id=? WHERE ref_type='loss' AND ref_id IS NULL AND lot_id=?", [$id, $lot['id']]);
        if ($kind === 'buyout') { // خریدِ فروشگاه از انبارِ امانیِ فروشنده: خروج از C + ورود به O
            $own = wh_lot_ensure((int)$lot['product_id'], $lot['variant_id'] ? (int)$lot['variant_id'] : null, (int)$lot['warehouse_id'], '00', 'O');
            $price = to_int($in['price'] ?? 0) ?: (int)round($amount / max(1, $qty));
            foreach ($out as $o) {
                if ($o['unit_id']) { // دانه با هویتِ تازه در محمولهٔ خریداری‌شده
                    $nc = wh_units_add((int)$own['id'], 1, ['status' => 'labeled', 'unit_cost' => $price]); wh_stock_in($nc[0], 1, ['kind' => 'buyout', 'ref_type' => 'loss', 'ref_id' => $id]);
                } else wh_stock_in($own['code'], $o['qty'], ['kind' => 'buyout', 'unit_cost' => $price, 'ref_type' => 'loss', 'ref_id' => $id]);
            }
            upd('stock_losses', ['amount' => $price * $qty], 'id=?', [$id]);
            ledger_post('buyout', $id, ['amount' => $price * $qty, 'supplier_id' => $lot['supplier_id']]);
        } elseif ($lot['ownership'] === 'O' || $bearer === 'store') ledger_post($kind === 'return' ? 'stock_return' : 'stock_loss', $id, ['amount' => $amount, 'supplier_id' => $lot['supplier_id']]);
        audit('stock_losses', $id, 'create', null, ['kind' => $kind, 'qty' => $qty, 'amount' => $amount], 'ضرر/عودت');
        return $id;
    });
}
