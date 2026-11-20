<?php
// تهاتر و اصلاحِ فروش — روالِ Weby (pos-offset.php، pos-amend.php)
//  تهاتر: کالایی که مشتری به‌جای بخشی از پول می‌دهد، به ارزشِ خرید، «روشِ پرداختِ تهاتر» است (سند: کالای تهاتری)؛
//    کالا به موجودی نمی‌رود؛ در «انبارِ بلاتکلیف» می‌ماند تا مدیر تصمیم بگیرد: ورود به انبار (موجودی + سند) یا اسقاط (ضرر).
//  اصلاح: تا N ساعت پس از فروش (تنظیمِ مدیر؛ صفر = خاموش)، فروشندهٔ همان فروش یا مدیرِ شیفت اقلام را جابه‌جا می‌کند:
//    برگشتِ اقلامِ پس‌داده (به انبار) + فروشِ تازهٔ پیوسته برای اقلامِ جدید؛ مابه‌التفاوت فقط یک بار گرفته یا پس داده می‌شود.
declare(strict_types=1);

function offset_states(): array { return ['pending' => 'بلاتکلیف', 'stocked' => 'وارد انبار شد', 'scrapped' => 'اسقاط شد']; }
function offset_unit_cost(int $pid, ?int $vid = null): int { return (int)val("SELECT unit_cost FROM lots WHERE product_id=? AND (variant_id<=>?) AND unit_cost>0 ORDER BY id DESC LIMIT 1", [$pid, $vid]) ?: (int)val("SELECT unit_cost FROM lots WHERE product_id=? AND unit_cost>0 ORDER BY id DESC LIMIT 1", [$pid]); }
/** تصمیمِ مدیر؛ «ورود به انبار» برای کالای دارای شناسه موجودی می‌سازد (کالای دانه‌ای: دانه‌های تازه با کد برای برچسب) */
function offset_decide(int $id, string $state, string $note = ''): array {
    if (!in_array($state, ['stocked', 'scrapped'], true)) throw new UserError('تصمیمِ نامعتبر');
    $o = row("SELECT * FROM sale_offsets WHERE id=?", [$id]); if (!$o || $o['state'] !== 'pending') throw new UserError('این قلم بلاتکلیف نیست');
    return tx(function () use ($o, $id, $state, $note) {
        $codes = [];
        if ($state === 'stocked' && $o['product_id']) {
            $p = product_get((int)$o['product_id']); $lot = wh_lot_ensure((int)$o['product_id'], $o['variant_id'] ? (int)$o['variant_id'] : null, 1); $uc = intdiv((int)$o['value'], max(1, (int)$o['qty']));
            if ($p && $p['serial']) { $codes = wh_units_add((int)$lot['id'], (int)$o['qty'], ['unit_cost' => $uc]); foreach ($codes as $c) wh_stock_in($c, 1, ['kind' => 'offset', 'unit_cost' => $uc, 'ref_type' => 'offset', 'ref_id' => $id]); }
            else wh_stock_in($lot['code'], (int)$o['qty'], ['kind' => 'offset', 'unit_cost' => $uc, 'ref_type' => 'offset', 'ref_id' => $id]);
        }
        upd('sale_offsets', ['state' => $state, 'note' => mb_cut($note, 300) ?: null, 'decided_by' => current_user()['id'] ?? null, 'decided_at' => now()], 'id=?', [$id]);
        ledger_post($state === 'stocked' ? 'offset_stock' : 'offset_scrap', $id);
        audit('sale_offsets', $id, 'decide', ['state' => 'pending'], ['state' => $state], 'انبارِ بلاتکلیف');
        return ['codes' => $codes, 'msg' => $state === 'stocked' ? ($codes ? 'وارد شد؛ ' . fa_num((string)count($codes)) . ' دانه برای برچسب' : 'وارد انبار شد') : 'اسقاط ثبت شد'];
    });
}
function offsets_limbo(string $state = 'pending'): array { return rows("SELECT o.*, s.number, s.d FROM sale_offsets o JOIN sales s ON s.id=o.sale_id WHERE o.state=? ORDER BY o.id DESC LIMIT 300", [$state]); }

// ── اصلاحِ فروش
function amend_hours(): int { return max(0, (int)setting('pos_amend_hours', '24')); }
/** [مجاز؟، پیام] */
function amend_gate(array $s, ?array $u = null): array {
    $u = $u ?: current_user(); $h = amend_hours();
    if (!$u) return [false, 'ابتدا وارد شوید'];
    if ($s['channel_id']) return [false, 'فروشِ کانال از این‌جا اصلاح نمی‌شود'];
    if ($s['fulfil'] !== 'done') return [false, 'فقط فروشِ تحویل‌شدهٔ صندوق'];
    if (!can('pos.amend', $u) && !can('shift.manage', $u)) return [false, 'دسترسیِ «اصلاحِ فروش» ندارید'];
    if (!can('shift.manage', $u) && (int)$s['seller_id'] !== (int)$u['id']) return [false, 'فقط فروشِ خودتان را می‌توانید اصلاح کنید'];
    if ($h <= 0 && !can('shift.manage', $u)) return [false, 'اصلاحِ فروش خاموش است'];
    $left = (int)ceil((strtotime($s['at']) + $h * 3600 - time()) / 60);
    if ($left <= 0 && !can('shift.manage', $u)) return [false, 'مهلتِ ' . fa_num((string)$h) . ' ساعتهٔ اصلاح گذشته است؛ از «مرجوعی» استفاده کنید'];
    return [true, $left > 0 ? fa_num((string)intdiv($left, 60)) . ' ساعت و ' . fa_num((string)($left % 60)) . ' دقیقه تا پایانِ مهلت' : 'مهلت گذشته (اختیارِ مدیر)'];
}
/** پیش‌بینیِ جمعِ فروشِ تازه با همان قاعدهٔ sale_commit */
function sale_preview_total(array $lines, ?int $cust): int {
    $ls = []; foreach ($lines as $l) { $p = product_get((int)$l['product_id']); if (!$p) throw new UserError('کالای ناشناخته'); $ls[] = ['qty' => (int)$l['qty'], 'unit_id' => $p['unit_id'] ? (int)$p['unit_id'] : null, 'price' => price_for((int)$l['product_id'], (int)($l['variant_id'] ?? 0) ?: null, $cust, (int)$l['qty']), 'disc' => 0]; }
    return $ls ? (int)calc_doc($ls, ['doc_disc' => 0, 'vat_pct' => vat_pct(today()), 'tax_round' => tax_round_mode(today())])['total'] : 0;
}
/**
 * $in: remove = [[sale_item_id, qty, units[]]]، add = [[product_id, variant_id, qty, unit_ids[]]]، method = روشِ مابه‌التفاوت، reason
 * برمی‌گرداند: refund (ارزشِ برگشتی)، new_total، diff (+ گرفتیم / − پس دادیم)، new_sale_id
 */
function sale_amend(int $saleId, array $in): array {
    $s = row("SELECT * FROM sales WHERE id=?", [$saleId]); if (!$s) throw new UserError('فروش یافت نشد');
    [$ok, $msg] = amend_gate($s); if (!$ok) throw new UserError($msg);
    $rem = array_values(array_filter((array)($in['remove'] ?? []), fn($r) => to_int($r['qty'] ?? 0) > 0)); $add = array_values(array_filter((array)($in['add'] ?? []), fn($r) => to_int($r['qty'] ?? 0) > 0 && (int)($r['product_id'] ?? 0)));
    if (!$rem && !$add) throw new UserError('چیزی برای اصلاح انتخاب نشده است');
    $method = in_array($in['method'] ?? 'cash', ['cash', 'card', 'transfer', 'credit', 'wallet'], true) ? $in['method'] : 'cash'; $reason = trim((string)($in['reason'] ?? '')) ?: 'اصلاحِ فروش';
    $cust = $s['customer_id'] ? (int)$s['customer_id'] : null;
    if (in_array($method, ['credit', 'wallet'], true) && !$cust) throw new UserError('فروشِ گذری به حسابِ مشتری نمی‌رود');
    $newTotal = $add ? sale_preview_total($add, $cust) : 0;
    $GLOBALS['__amend'] = 1;
    try {
        return tx(function () use ($s, $saleId, $rem, $add, $method, $reason, $cust, $newTotal) {
            $u = current_user(); $shift = shift_of_user((int)$u['id']); $refund = 0; $rid = null; $sid2 = null;
            if ($rem) { $rid = return_commit($saleId, $rem, 'اصلاح: ' . $reason, 'exchange'); $r = row("SELECT amount, tax FROM sale_returns WHERE id=?", [$rid]); $refund = (int)$r['amount'] + (int)$r['tax']; }
            if ($add) {
                $use = min($refund, $newTotal); $pays = []; if ($use > 0) $pays[] = ['method' => 'exchange', 'amount' => $use]; if ($newTotal - $use > 0) $pays[] = ['method' => $method, 'amount' => $newTotal - $use];
                if ($method === 'cash' && $newTotal - $use > 0 && !$shift) throw new UserError('برای دریافتِ نقد شیفتِ باز لازم است');
                $sid2 = sale_commit(['cart_token' => bin2hex(random_bytes(16)), 'lines' => $add, 'payments' => $pays, 'customer_id' => $cust, 'shift_id' => $shift['id'] ?? null, 'amend_of' => $saleId, 'note' => 'اصلاحِ فروشِ ' . $s['number'], 'due' => null]);
                $real = (int)val("SELECT total FROM sales WHERE id=?", [$sid2]); if ($real !== $newTotal) throw new UserError('قیمت‌ها در این فاصله عوض شد؛ دوباره امتحان کنید');
            }
            $left = $refund - min($refund, $newTotal);
            if ($left > 0) { // پس‌دادنِ مابقی با روشِ انتخابی
                if ($method === 'credit') { customer_ledger_add($cust, -$left, 'return', 'return', $rid, 'مابه‌التفاوتِ اصلاحِ ' . $s['number']); ledger_post('exchange_payout', 'r' . $rid, ['amount' => $left, 'method' => 'credit', 'customer_id' => $cust]); }
                elseif ($method === 'wallet') { wallet_add($cust, $left, 'refund', 'return', $rid, 'مابه‌التفاوتِ اصلاحِ ' . $s['number']); ledger_post('exchange_payout', 'r' . $rid, ['amount' => $left, 'method' => 'wallet', 'customer_id' => $cust]); }
                else { if ($method === 'cash' && !$shift) throw new UserError('برای پرداختِ نقد شیفتِ باز لازم است');
                    ins('receipts', ['return_id' => $rid, 'sale_id' => $saleId, 'shift_id' => $shift['id'] ?? null, 'cashbox_id' => $shift['cashbox_id'] ?? null, 'method' => $method, 'amount' => -$left, 'customer_id' => $cust, 'ref' => 'مابه‌التفاوتِ اصلاح', 'at' => now()]);
                    ledger_post('exchange_payout', 'r' . $rid, ['amount' => $left, 'method' => $method, 'cashbox_id' => $shift['cashbox_id'] ?? null]); }
            }
            audit('sales', $saleId, 'amend', null, ['refund' => $refund, 'new_total' => $newTotal, 'new_sale' => $sid2, 'reason' => $reason], 'اصلاحِ فروش');
            $diff = $newTotal - $refund;
            return ['refund' => $refund, 'new_total' => $newTotal, 'diff' => $diff, 'new_sale_id' => $sid2, 'return_id' => $rid,
                'msg' => $diff > 0 ? 'مابه‌التفاوت ' . fa_money($diff) . ' ریال دریافت شد' : ($diff < 0 ? fa_money(-$diff) . ' ریال به مشتری پس داده شد' : 'بی‌مابه‌التفاوت')];
        });
    } finally { unset($GLOBALS['__amend']); }
}
