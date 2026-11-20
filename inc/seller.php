<?php
// پنلِ فروشندهٔ امانی/تأمین‌کننده — روالِ Weby (seller/*, seller-profile, contracts):
//  - کاربرِ kind=seller فقط به پنلِ خودش دسترسی دارد (user_load همهٔ توانایی‌ها را جز seller.portal برمی‌دارد).
//  - دعوت: لینکِ امضاشدهٔ ۷ روزه با پیامک؛ فروشنده نام‌کاربری و رمز می‌گذارد.
//  - می‌بیند: موجودیِ کالاهایش نزدِ ما، فروش‌رفته‌ها، صورت‌حساب (فاکتور، پرداخت، عودت، خرید از انبار) و مانده؛
//    تأییدِ دریافتِ هر پرداخت؛ تکمیلِ پرونده (شبا، کارت…) و پذیرشِ قرارداد (زمان و IP ثبت می‌شود).
declare(strict_types=1);

function seller_me(): array { $u = current_user(); $s = $u && $u['supplier_id'] ? row("SELECT * FROM suppliers WHERE id=?", [(int)$u['supplier_id']]) : null; if (!$s) throw new UserError('این حساب به پروندهٔ فروشنده‌ای وصل نیست', 403); return $s; }
function seller_invite_token(int $sid): string { $b = base_convert((string)$sid, 10, 36) . '.' . base_convert((string)intdiv(time() + 7 * 86400, 86400), 10, 36); return $b . '.' . substr(rtrim(strtr(base64_encode(hash_hmac('sha256', 'sjoin|' . $b, app_secret(), true)), '+/', '-_'), '='), 0, 12); }
function seller_invite_parse(string $t): ?int {
    if (!preg_match('/^([0-9a-z]+)\.([0-9a-z]+)\.([A-Za-z0-9_-]{12})$/', $t, $m)) return null; $b = $m[1] . '.' . $m[2];
    if (!hash_equals(substr(rtrim(strtr(base64_encode(hash_hmac('sha256', 'sjoin|' . $b, app_secret(), true)), '+/', '-_'), '='), 0, 12), $m[3]) || (int)base_convert($m[2], 36, 10) * 86400 + 86399 < time()) return null;
    return (int)base_convert($m[1], 36, 10);
}
function seller_invite(int $sid, bool $sms = true): array {
    $s = row("SELECT * FROM suppliers WHERE id=?", [$sid]); if (!$s) throw new UserError('فروشنده یافت نشد');
    $url = rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/v.php?sj=' . seller_invite_token($sid); $r = null;
    if ($sms && $s['mobile']) $r = sms_send_tpl((string)$s['mobile'], 'seller_invite', ['NAME' => (string)$s['name'], 'LINK' => $url], 'notify', ['ref_type' => 'supplier', 'ref_id' => $sid]);
    audit('suppliers', $sid, 'invite', null, ['sms' => (bool)$r], 'دعوت به پنلِ فروشنده');
    return ['url' => $url, 'msg' => $r ? ($r['ok'] ? 'پیامکِ دعوت رفت' : 'پیامک نرفت: ' . $r['msg']) : 'لینکِ دعوت ساخته شد'];
}
/** پذیرشِ دعوت: ساختن یا به‌روزکردنِ کاربرِ فروشنده */
function seller_join(int $sid, string $username, string $password): int {
    $s = row("SELECT * FROM suppliers WHERE id=?", [$sid]); if (!$s) throw new UserError('دعوت نامعتبر است');
    $username = trim(en_num($username)); if (!preg_match('/^[A-Za-z0-9_.\-]{3,60}$/', $username)) throw new UserError('نامِ کاربری فقط حروفِ لاتین و عدد (۳ نویسه به بالا)');
    if (mb_strlen($password) < 8) throw new UserError('رمز دست‌کم ۸ نویسه');
    $ex = row("SELECT * FROM users WHERE supplier_id=? AND kind='seller'", [$sid]);
    if (val("SELECT id FROM users WHERE username=? AND id<>?", [$username, (int)($ex['id'] ?? 0)])) throw new UserError('این نامِ کاربری گرفته شده است');
    $d = ['username' => $username, 'name' => $s['name'], 'pass_hash' => password_hash($password, PASSWORD_DEFAULT), 'kind' => 'seller', 'supplier_id' => $sid, 'active' => 1];
    if ($ex) { upd('users', $d, 'id=?', [$ex['id']]); $id = (int)$ex['id']; } else $id = ins('users', $d + ['created_at' => now()]);
    audit('users', $id, 'seller_join', null, ['supplier' => $sid], 'فروشنده به پنل پیوست');
    return $id;
}
/** موجودیِ کالاهای این فروشنده نزدِ ما */
function seller_stock(int $sid): array {
    return rows("SELECT p.name, v.title vtitle, l.ownership, w.name wname, SUM(m.qty) on_hand, MAX(l.unit_cost) unit_cost FROM lots l JOIN stock_moves m ON m.lot_id=l.id JOIN products p ON p.id=l.product_id
        LEFT JOIN product_variants v ON v.id=l.variant_id LEFT JOIN warehouses w ON w.id=l.warehouse_id WHERE l.supplier_id=? GROUP BY l.id, p.name, v.title, l.ownership, w.name HAVING on_hand<>0 ORDER BY p.name", [$sid]);
}
/** فروش‌رفته‌ها از کالاهای این فروشنده (بی نامِ مشتری) */
function seller_sold(int $sid, string $from, string $to): array {
    return rows("SELECT s.d, s.number, p.name, -SUM(m.qty) qty, MAX(l.unit_cost) unit_cost, -SUM(m.qty)*MAX(l.unit_cost) value FROM stock_moves m JOIN lots l ON l.id=m.lot_id JOIN products p ON p.id=l.product_id JOIN sales s ON s.id=m.ref_id
        WHERE l.supplier_id=? AND m.ref_type='sale' AND s.d BETWEEN ? AND ? GROUP BY s.id, s.d, s.number, p.name ORDER BY s.d DESC", [$sid, $from, $to]);
}
/** صورت‌حساب با ماندهٔ جاری (مثبت = طلبِ فروشنده از ما) */
function seller_statement(int $sid): array {
    $r = [];
    foreach (rows("SELECT d, number, total FROM purchase_invoices WHERE supplier_id=? AND status='posted'", [$sid]) as $x) $r[] = ['d' => $x['d'], 't' => 'فاکتورِ ' . ($x['number'] ?: 'خرید'), 'in' => (int)$x['total'], 'out' => 0];
    foreach (rows("SELECT id, d, amount, method, confirmed_at FROM supplier_payments WHERE supplier_id=?", [$sid]) as $x) $r[] = ['d' => $x['d'], 't' => 'پرداخت (' . (['cash' => 'نقد', 'bank' => 'بانکی', 'cheque' => 'چک'][$x['method']] ?? $x['method']) . ')' . ($x['confirmed_at'] ? ' ✓' : ''), 'in' => 0, 'out' => (int)$x['amount'], 'pay_id' => (int)$x['id'], 'confirmed' => (bool)$x['confirmed_at']];
    foreach (rows("SELECT DATE(l.created_at) d, l.kind, l.amount FROM stock_losses l JOIN lots t ON t.id=l.lot_id WHERE t.supplier_id=? AND l.kind IN ('return','buyout')", [$sid]) as $x) $r[] = ['d' => $x['d'], 't' => $x['kind'] === 'return' ? 'عودتِ کالا' : 'خرید از انبارِ شما', 'in' => $x['kind'] === 'buyout' ? (int)$x['amount'] : 0, 'out' => $x['kind'] === 'return' ? (int)$x['amount'] : 0];
    usort($r, fn($a, $b) => strcmp($a['d'], $b['d'])); $bal = 0; foreach ($r as &$x) { $bal += $x['in'] - $x['out']; $x['bal'] = $bal; }
    return array_reverse($r);
}
function seller_pay_confirm(int $payId, string $note = ''): void {
    $s = seller_me(); $p = row("SELECT * FROM supplier_payments WHERE id=? AND supplier_id=?", [$payId, $s['id']]); if (!$p) throw new UserError('پرداخت یافت نشد');
    if ($p['confirmed_at']) throw new UserError('قبلاً تأیید شده است');
    upd('supplier_payments', ['confirmed_at' => now(), 'confirm_note' => mb_cut($note, 300) ?: null], 'id=?', [$payId]);
    audit('supplier_payments', $payId, 'confirm', null, ['note' => $note], 'تأییدِ دریافتِ وجه توسطِ فروشنده');
    notify_managers('spay:' . $payId, '✅ ' . $s['name'] . ' دریافتِ ' . fa_money($p['amount']) . ' ریال را تأیید کرد.');
}
function seller_profile_save(array $in): void {
    $s = seller_me(); $iban = strtoupper(preg_replace('/\s+/', '', en_num((string)($in['iban'] ?? ''))));
    if ($iban !== '' && !preg_match('/^IR\d{24}$/', $iban)) throw new UserError('شبا باید IR و ۲۴ رقم باشد');
    $card = preg_replace('/\D/', '', en_num((string)($in['card_no'] ?? ''))); if ($card !== '' && strlen($card) !== 16) throw new UserError('شمارهٔ کارت ۱۶ رقم است');
    $d = ['iban' => $iban ?: null, 'card_no' => $card ?: null, 'bank_name' => mb_cut(trim((string)($in['bank_name'] ?? '')), 80) ?: null, 'address' => mb_cut(trim((string)($in['address'] ?? '')), 400) ?: null, 'phone' => mb_cut(trim(en_num((string)($in['phone'] ?? ''))), 40) ?: null, 'email' => mb_cut(trim((string)($in['email'] ?? '')), 120) ?: null];
    $old = row("SELECT iban, card_no, bank_name, address, phone, email FROM suppliers WHERE id=?", [$s['id']]); upd('suppliers', $d, 'id=?', [$s['id']]);
    [$b, $a] = audit_diff($old, $d); if ($a) { audit('suppliers', $s['id'], 'seller_profile', $b, $a, 'پروندهٔ فروشنده (از پنلِ خودش)'); if (isset($a['iban']) || isset($a['card_no'])) notify_managers('sbank:' . $s['id'] . ':' . md5(json_encode($a)), '⚠️ ' . $s['name'] . ' حسابِ بانکی‌اش را در پنل عوض کرد؛ پیش از پرداخت بررسی کنید.'); }
}
function seller_contract_accept(): void { $s = seller_me(); if (trim((string)setting('seller_contract_text', '')) === '') throw new UserError('قراردادی تعریف نشده است');
    upd('suppliers', ['contract_at' => now(), 'contract_ip' => mb_cut((string)($_SERVER['REMOTE_ADDR'] ?? ''), 45), 'contract_ver' => substr(md5((string)setting('seller_contract_text', '')), 0, 8)], 'id=?', [$s['id']]); audit('suppliers', $s['id'], 'contract', null, ['ip' => $_SERVER['REMOTE_ADDR'] ?? ''], 'پذیرشِ قرارداد'); }
