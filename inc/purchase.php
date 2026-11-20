<?php
// خرید و تأمین‌کنندگان: سفارش ← رسید (تطبیق) ← فاکتورِ خرید (سربار و بهای تمام‌شده) ← پرداخت
declare(strict_types=1);

function supplier_save(array $in): int {
    $name = trim((string)($in['name'] ?? '')); if ($name === '') throw new UserError('نامِ تأمین‌کننده لازم است');
    $iban = strtoupper(preg_replace('/\s/', '', en_num((string)($in['iban'] ?? ''))));
    if ($iban !== '' && !iban_ok($iban)) throw new UserError('شبا معتبر نیست');
    $d = ['name' => $name, 'national_id' => preg_replace('/\D/', '', en_num((string)($in['national_id'] ?? ''))) ?: null, 'mobile' => norm_mobile($in['mobile'] ?? '') ?: null, 'phone' => trim((string)($in['phone'] ?? '')) ?: null,
        'iban' => $iban ?: null, 'pay_terms_days' => to_int($in['pay_terms_days'] ?? 0), 'seller_code' => seller_code_clean((string)($in['seller_code'] ?? '')) ?: null,
        'address' => trim((string)($in['address'] ?? '')) ?: null, 'active' => empty($in['inactive']) ? 1 : 0];
    $id = (int)($in['id'] ?? 0);
    if ($id) { $o = row("SELECT * FROM suppliers WHERE id=?", [$id]); upd('suppliers', $d, 'id=?', [$id]); [$b, $a] = audit_diff($o, $d); audit('suppliers', $id, 'update', $b, $a, 'تأمین‌کننده'); return $id; }
    $id = ins('suppliers', $d); audit('suppliers', $id, 'create', null, ['name' => $name], 'تأمین‌کننده'); return $id;
}
function doc_seq_next(string $key): int { q("INSERT INTO doc_counters (k,last) VALUES (?,1) ON DUPLICATE KEY UPDATE last=LAST_INSERT_ID(last+1)", [$key]); $id = (int)db()->lastInsertId(); return $id ?: 1; }

/** سفارشِ خرید (هنوز بدهی نمی‌سازد) */
function po_save(array $in): int {
    $sup = (int)($in['supplier_id'] ?? 0); $wh = (int)($in['warehouse_id'] ?? 0);
    if (!$sup || !$wh) throw new UserError('تأمین‌کننده و انبار را انتخاب کنید');
    $items = array_values(array_filter((array)($in['items'] ?? []), fn($i) => (int)($i['product_id'] ?? 0) && to_int($i['qty'] ?? 0) > 0));
    if (!$items) throw new UserError('دست‌کم یک ردیف لازم است');
    return tx(function () use ($in, $sup, $wh, $items) {
        $d = jparse($in['d'] ?? '') ?: today();
        $id = ins('purchase_orders', ['supplier_id' => $sup, 'warehouse_id' => $wh, 'status' => !empty($in['confirm']) ? 'confirmed' : 'draft', 'd' => $d, 'note' => $in['note'] ?? null, 'created_by' => current_user()['id'] ?? null, 'created_at' => now()]);
        upd('purchase_orders', ['number' => 'PO-' . fa_num_en(jdate($d, false, false), 4) . '-' . doc_seq_next('po')], 'id=?', [$id]);
        foreach ($items as $i) ins('purchase_order_items', ['po_id' => $id, 'product_id' => (int)$i['product_id'], 'variant_id' => (int)($i['variant_id'] ?? 0) ?: null, 'qty' => to_int($i['qty']), 'unit_price' => to_int($i['unit_price'] ?? 0)]);
        audit('purchase_orders', $id, 'create', null, ['items' => count($items)], 'سفارشِ خرید');
        return $id;
    });
}
function fa_num_en(string $j, int $n): string { return substr(str_replace('/', '', $j), 0, $n); }
function po_confirm(int $id): void { upd('purchase_orders', ['status' => 'confirmed'], "id=? AND status='draft'", [$id]); audit('purchase_orders', $id, 'confirm', null, null, 'تأییدِ سفارشِ خرید'); }

/** رسیدِ کالا با تطبیق؛ کسری/اضافه گزارش می‌شود */
function goods_receipt(array $in): array {
    $po = !empty($in['po_id']) ? row("SELECT * FROM purchase_orders WHERE id=?", [(int)$in['po_id']]) : null;
    if ($po && !in_array($po['status'], ['confirmed', 'partial'], true)) throw new UserError('سفارشِ خرید تأیید نشده است');
    $sup = $po ? (int)$po['supplier_id'] : (int)($in['supplier_id'] ?? 0); $wh = $po ? (int)$po['warehouse_id'] : (int)($in['warehouse_id'] ?? 0);
    if (!$sup || !$wh) throw new UserError('تأمین‌کننده و انبار لازم است');
    $recv = []; foreach ((array)($in['items'] ?? []) as $k => $i) $recv[$k] = $i;
    return tx(function () use ($po, $sup, $wh, $recv, $in) {
        $gr = ins('goods_receipts', ['po_id' => $po['id'] ?? null, 'supplier_id' => $sup, 'warehouse_id' => $wh, 'd' => jparse($in['d'] ?? '') ?: today(), 'created_by' => current_user()['id'] ?? null, 'created_at' => now()]);
        $report = [];
        if ($po) {
            foreach (rows("SELECT * FROM purchase_order_items WHERE po_id=?", [$po['id']]) as $pi) {
                $already = (int)val("SELECT COALESCE(SUM(qty),0) FROM goods_receipt_items WHERE po_item_id=?", [$pi['id']]);
                $q = to_int($recv[$pi['id']]['qty'] ?? ($recv[$pi['id']] ?? 0));
                if (is_array($recv[$pi['id']] ?? null)) $q = to_int($recv[$pi['id']]['qty'] ?? 0);
                $ordered = (int)$pi['qty'] - $already;
                ins('goods_receipt_items', ['gr_id' => $gr, 'po_item_id' => $pi['id'], 'product_id' => $pi['product_id'], 'variant_id' => $pi['variant_id'], 'qty_ordered' => $ordered, 'qty' => $q, 'unit_price' => $pi['unit_price']]);
                $name = (string)val("SELECT name FROM products WHERE id=?", [$pi['product_id']]);
                if ($q < $ordered) $report[] = $name . ': کسری ' . ($ordered - $q);
                elseif ($q > $ordered) $report[] = $name . ': اضافه ' . ($q - $ordered);
            }
            $left = (int)val("SELECT COALESCE(SUM(poi.qty),0) - COALESCE((SELECT SUM(gi.qty) FROM goods_receipt_items gi JOIN purchase_order_items p2 ON p2.id=gi.po_item_id WHERE p2.po_id=?),0) FROM purchase_order_items poi WHERE poi.po_id=?", [$po['id'], $po['id']]);
            upd('purchase_orders', ['status' => $left > 0 ? 'partial' : 'received'], 'id=?', [$po['id']]);
        } else {
            foreach ($recv as $i) if ((int)($i['product_id'] ?? 0) && to_int($i['qty'] ?? 0) > 0)
                ins('goods_receipt_items', ['gr_id' => $gr, 'product_id' => (int)$i['product_id'], 'variant_id' => (int)($i['variant_id'] ?? 0) ?: null, 'qty' => to_int($i['qty']), 'unit_price' => to_int($i['unit_price'] ?? 0)]);
        }
        $rep = implode('؛ ', $report);
        upd('goods_receipts', ['report' => $rep ?: 'مطابقِ سفارش'], 'id=?', [$gr]);
        audit('goods_receipts', $gr, 'create', null, ['report' => $rep], 'رسیدِ کالا');
        return ['id' => $gr, 'report' => $rep ?: 'مطابقِ سفارش'];
    });
}
/** پخشِ سربار به نسبتِ مبلغ؛ باقیماندهٔ گرد به بزرگ‌ترین ردیف */
function overhead_split(array $amounts, int $overhead): array {
    $sum = array_sum($amounts); $out = []; $given = 0;
    if ($sum <= 0) return array_map(fn() => 0, $amounts);
    foreach ($amounts as $k => $a) { $out[$k] = intdiv($a * $overhead, $sum); $given += $out[$k]; }
    if ($given !== $overhead) { $big = array_keys($amounts, max($amounts))[0]; $out[$big] += $overhead - $given; }
    return $out;
}
/** فاکتورِ خرید: بدهی می‌سازد، بهای واحد = (قیمت×تعداد + سهمِ سربار)/تعداد، کالا وارد انبار می‌شود */
function purchase_invoice_commit(array $in): array {
    $gr = !empty($in['gr_id']) ? row("SELECT * FROM goods_receipts WHERE id=?", [(int)$in['gr_id']]) : null;
    if ($gr && $gr['status'] === 'invoiced') throw new UserError('برای این رسید قبلاً فاکتور ثبت شده است');
    $sup = $gr ? (int)$gr['supplier_id'] : (int)($in['supplier_id'] ?? 0); $wh = $gr ? (int)$gr['warehouse_id'] : (int)($in['warehouse_id'] ?? 0);
    if (!$sup || !$wh) throw new UserError('تأمین‌کننده و انبار لازم است');
    $items = [];
    if ($gr) foreach (rows("SELECT * FROM goods_receipt_items WHERE gr_id=? AND qty>0", [$gr['id']]) as $r) {
        $price = isset($in['prices'][$r['id']]) ? to_int($in['prices'][$r['id']]) : (int)$r['unit_price'];
        $items[] = ['product_id' => (int)$r['product_id'], 'variant_id' => $r['variant_id'] ? (int)$r['variant_id'] : null, 'qty' => (int)$r['qty'], 'unit_price' => $price];
    } else foreach ((array)($in['items'] ?? []) as $i) if ((int)($i['product_id'] ?? 0) && to_int($i['qty'] ?? 0) > 0)
        $items[] = ['product_id' => (int)$i['product_id'], 'variant_id' => (int)($i['variant_id'] ?? 0) ?: null, 'qty' => to_int($i['qty']), 'unit_price' => to_int($i['unit_price'] ?? 0)];
    if (!$items) throw new UserError('ردیفی برای فاکتور نیست');
    $d = jparse($in['d'] ?? '') ?: today(); fiscal_assert_open($d);
    $overhead = to_int($in['overhead'] ?? 0); $tax = to_int($in['tax'] ?? 0);
    $amounts = array_map(fn($i) => $i['qty'] * $i['unit_price'], $items);
    $shares = overhead_split($amounts, $overhead);
    $sub = array_sum($amounts);
    $supRow = row("SELECT * FROM suppliers WHERE id=?", [$sup]);
    $due = jparse($in['due'] ?? '') ?: date('Y-m-d', strtotime($d . ' +' . (int)($supRow['pay_terms_days'] ?? 0) . ' day'));
    return tx(function () use ($gr, $sup, $wh, $items, $shares, $sub, $overhead, $tax, $d, $due, $in, $supRow) {
        $pi = ins('purchase_invoices', ['gr_id' => $gr['id'] ?? null, 'supplier_id' => $sup, 'number' => trim((string)($in['number'] ?? '')) ?: null, 'd' => $d, 'due_date' => $due,
            'subtotal' => $sub, 'overhead' => $overhead, 'tax' => $tax, 'total' => $sub + $overhead + $tax, 'created_by' => current_user()['id'] ?? null, 'created_at' => now()]);
        $costs = [];
        foreach ($items as $k => $i) {
            $unitCost = (int)round(($i['qty'] * $i['unit_price'] + $shares[$k]) / $i['qty']);
            $own = !empty($in['consign']) ? 'C' : 'O';
            $lot = wh_lot_ensure($i['product_id'], $i['variant_id'], $wh, $own === 'C' ? (string)$supRow['seller_code'] : '', $own, $sup);
            ins('purchase_invoice_items', ['pi_id' => $pi, 'product_id' => $i['product_id'], 'variant_id' => $i['variant_id'], 'qty' => $i['qty'], 'unit_price' => $i['unit_price'], 'overhead_share' => $shares[$k], 'unit_cost' => $unitCost, 'lot_id' => $lot['id']]);
            $serial = (int)val("SELECT serial FROM products WHERE id=?", [$i['product_id']]);
            if ($serial && empty($in['direct_in'])) { wh_lot_cost_in($lot, 0, $unitCost); wh_units_add((int)$lot['id'], $i['qty'], ['status' => 'labeled', 'unit_cost' => $unitCost]); }
            else wh_stock_in($lot['code'], $i['qty'], ['unit_cost' => $unitCost, 'ref_type' => 'purchase', 'ref_id' => $pi]);
            $costs[] = $unitCost;
        }
        if ($gr) upd('goods_receipts', ['status' => 'invoiced'], 'id=?', [$gr['id']]);
        ledger_post('purchase', $pi, ['amount' => $sub + $overhead, 'tax' => $tax, 'supplier_id' => $sup, 'd' => $d]);
        audit('purchase_invoices', $pi, 'create', null, ['total' => $sub + $overhead + $tax], 'فاکتورِ خرید');
        return ['id' => $pi, 'unit_costs' => $costs, 'msg' => 'بهای واحد: ' . implode(' و ', $costs)];
    });
}
/** بدهی‌های باز به تفکیکِ فاکتور با سررسید */
function payables(?int $supplierId = null): array {
    return rows("SELECT pi.*, s.name sname, pi.total - COALESCE((SELECT SUM(amount) FROM supplier_payments sp WHERE sp.pi_id=pi.id),0) remain FROM purchase_invoices pi JOIN suppliers s ON s.id=pi.supplier_id
        WHERE pi.status='posted'" . ($supplierId ? " AND pi.supplier_id=" . (int)$supplierId : "") . " HAVING remain>0 ORDER BY pi.due_date", []);
}
function supplier_balance(int $sid): int {
    return (int)val("SELECT COALESCE(SUM(total),0) FROM purchase_invoices WHERE supplier_id=? AND status='posted'", [$sid]) - (int)val("SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE supplier_id=?", [$sid])
        - (int)val("SELECT COALESCE(SUM(l.amount),0) FROM stock_losses l JOIN lots t ON t.id=l.lot_id WHERE l.kind='return' AND t.supplier_id=?", [$sid])
        + (int)val("SELECT COALESCE(SUM(l.amount),0) FROM stock_losses l JOIN lots t ON t.id=l.lot_id WHERE l.kind='buyout' AND t.supplier_id=?", [$sid]);
}
function supplier_pay(array $in): int {
    $sid = (int)($in['supplier_id'] ?? 0); $amount = to_int($in['amount'] ?? 0);
    if (!$sid || $amount <= 0) throw new UserError('تأمین‌کننده و مبلغ لازم است');
    $method = in_array($in['method'] ?? '', ['cash', 'bank', 'cheque'], true) ? $in['method'] : 'bank';
    $d = jparse($in['d'] ?? '') ?: today(); fiscal_assert_open($d);
    $pi = (int)($in['pi_id'] ?? 0) ?: null;
    if ($pi) { $rem = (int)val("SELECT total - COALESCE((SELECT SUM(amount) FROM supplier_payments WHERE pi_id=?),0) FROM purchase_invoices WHERE id=?", [$pi, $pi]); if ($amount > $rem) throw new UserError('مبلغ بیش از ماندهٔ فاکتور (' . fa_money($rem) . ') است'); }
    return tx(function () use ($sid, $amount, $method, $d, $pi, $in) {
        $chq = null;
        if ($method === 'cheque') $chq = cheque_save(['direction' => 'out', 'number' => $in['cheque_no'] ?? '', 'amount' => $amount, 'due' => $in['cheque_due'] ?? '', 'party_type' => 'supplier', 'party_id' => $sid, 'bank_account_id' => $in['bank_account_id'] ?? null], false);
        $id = ins('supplier_payments', ['supplier_id' => $sid, 'pi_id' => $pi, 'amount' => $amount, 'method' => $method, 'bank_account_id' => (int)($in['bank_account_id'] ?? 0) ?: null, 'cheque_id' => $chq, 'd' => $d, 'note' => $in['note'] ?? null, 'created_by' => current_user()['id'] ?? null, 'created_at' => now()]);
        ledger_post('supplier_pay', $id, ['amount' => $amount, 'supplier_id' => $sid, 'method' => $method, 'bank_account_id' => (int)($in['bank_account_id'] ?? 0), 'd' => $d]);
        audit('supplier_payments', $id, 'create', null, ['amount' => $amount], 'پرداخت به تأمین‌کننده');
        return $id;
    });
}
