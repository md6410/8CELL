<?php
// ارسال با پیک و شرکت‌های حمل (پیکِ فروشگاه، اسنپ‌باکس، تپسی‌پک، الوپیک، پست، تیپاکس، چاپار، باربری…) + لینکِ ثبتِ نشانی برای مشتری
//  - ثبتِ دستی: شرکت، پیک، کدِ رهگیری، هزینه و پرداخت‌کننده، پس‌کرایه، وضعیت. هیچ اتصالِ شبکه‌ای به شرکت‌ها نیست (فقط ثبت و پیامکِ رهگیری).
//  - هزینه‌ای که فروشگاه می‌دهد سندِ «هزینهٔ ارسال و پیک» می‌خورد (یک سند برای هر ارسال).
//  - لینکِ نشانی (روالِ Weby addr-link): توکنِ امضاشدهٔ کوتاه (بی جدولِ تازه)، ۷ روز؛ مشتری بی‌ورود نشانی و موقعیتش را می‌زند و در دفترچهٔ نشانی‌اش می‌نشیند.
declare(strict_types=1);

const ADDR_LINK_DAYS = 7;
function ship_carriers(): array { return apply_filters('ship_carriers', ['peyk' => 'پیکِ فروشگاه', 'snapp' => 'اسنپ‌باکس', 'tapsi' => 'تپسی‌پک', 'alopeyk' => 'الوپیک', 'post' => 'پست', 'tipax' => 'تیپاکس', 'chapar' => 'چاپار', 'barbari' => 'باربری', 'other' => 'سایر']); }
function ship_statuses(): array { return ['registered' => 'ثبت شد', 'picked' => 'تحویلِ پیک شد', 'delivered' => 'به مشتری رسید', 'returned' => 'برگشت خورد', 'canceled' => 'لغو']; }
function ship_payers(): array { return ['shop' => 'فروشگاه می‌پردازد', 'customer' => 'مشتری (پس‌کرایه)', 'free' => 'رایگان/در قیمت']; }

function courier_save(array $in): int {
    $d = ['name' => trim((string)($in['name'] ?? '')), 'mobile' => norm_mobile($in['mobile'] ?? '') ?: null, 'carrier' => isset(ship_carriers()[$in['carrier'] ?? '']) ? $in['carrier'] : 'peyk', 'active' => empty($in['inactive']) ? 1 : 0];
    if ($d['name'] === '') throw new UserError('نامِ پیک لازم است');
    $id = (int)($in['id'] ?? 0); if ($id) { upd('couriers', $d, 'id=?', [$id]); return $id; }
    return ins('couriers', $d);
}
function address_text(array $a): string { return trim(implode('، ', array_filter([$a['province'] ?? '', $a['city'] ?? '', $a['address'] ?? '', !empty($a['plaque']) ? 'پلاک ' . $a['plaque'] : '', !empty($a['unit']) ? 'واحد ' . $a['unit'] : '']))); }
function ship_save(array $in): int {
    $sale = !empty($in['sale_id']) ? row("SELECT * FROM sales WHERE id=?", [(int)$in['sale_id']]) : null;
    if (!$sale && trim((string)($in['sale_number'] ?? '')) !== '') { $sale = row("SELECT * FROM sales WHERE number=?", [trim(en_num((string)$in['sale_number']))]); if (!$sale) throw new UserError('فروشی با این شماره پیدا نشد'); }
    $cid = (int)($in['customer_id'] ?? 0) ?: (int)($sale['customer_id'] ?? 0) ?: null;
    $carrier = (string)($in['carrier'] ?? ''); if (!isset(ship_carriers()[$carrier])) throw new UserError('شرکت/روشِ ارسال را انتخاب کنید');
    $addr = !empty($in['address_id']) ? row("SELECT * FROM customer_addresses WHERE id=? AND customer_id=?", [(int)$in['address_id'], (int)$cid]) : null;
    $ship = $sale && $sale['ship_to'] ? (json_decode((string)$sale['ship_to'], true) ?: []) : [];
    $cust = $cid ? row("SELECT name, mobile, address, postal_code FROM customers WHERE id=?", [$cid]) : null;
    $payer = isset(ship_payers()[$in['payer'] ?? '']) ? $in['payer'] : 'shop'; $cost = to_int($in['cost'] ?? 0); if ($cost < 0) throw new UserError('هزینه منفی نمی‌شود');
    $paidFrom = in_array($in['paid_from'] ?? '', ['cash', 'bank', 'courier'], true) ? $in['paid_from'] : null;
    if ($paidFrom === 'courier' && empty($in['courier_id'])) throw new UserError('برای «بدهی به پیک» پیک را انتخاب کنید');
    $d = ['sale_id' => $sale['id'] ?? null, 'customer_id' => $cid, 'carrier' => $carrier, 'courier_id' => (int)($in['courier_id'] ?? 0) ?: null, 'tracking' => mb_cut(trim(en_num((string)($in['tracking'] ?? ''))), 80) ?: null,
        'cost' => $cost, 'payer' => $payer, 'cod_amount' => to_int($in['cod_amount'] ?? 0), 'paid_from' => $payer === 'shop' && $cost > 0 ? $paidFrom : null,
        'receiver_name' => trim((string)($in['receiver_name'] ?? '')) ?: ($addr['receiver_name'] ?? null) ?: ($ship['name'] ?? null) ?: ($cust['name'] ?? null),
        'receiver_mobile' => norm_mobile($in['receiver_mobile'] ?? '') ?: ($addr['receiver_mobile'] ?? null) ?: ($ship['mobile'] ?? null) ?: ($cust['mobile'] ?? null),
        'address' => mb_cut(trim((string)($in['address'] ?? '')) ?: ($addr ? address_text($addr) : '') ?: ($ship['address'] ?? '') ?: ($cust['address'] ?? ''), 500) ?: null,
        'postal_code' => digits_of($in['postal_code'] ?? '') ?: ($addr['postal_code'] ?? null) ?: ($cust['postal_code'] ?? null), 'lat' => $addr['lat'] ?? null, 'lng' => $addr['lng'] ?? null,
        'note' => mb_cut((string)($in['note'] ?? ''), 300) ?: null, 'created_by' => current_user()['id'] ?? null, 'created_at' => now(), 'status_at' => now()];
    if (!$d['address'] && $carrier !== 'other') throw new UserError('نشانیِ گیرنده لازم است (یا از نشانی‌های مشتری انتخاب کنید)');
    if ($d['paid_from'] === null && $payer === 'shop' && $cost > 0) $d['paid_from'] = 'cash';
    $id = tx(function () use ($d, $in) {
        $id = ins('shipments', $d);
        if ($d['payer'] === 'shop' && $d['cost'] > 0) { $sh = $d['paid_from'] === 'cash' ? shift_of_user((int)(current_user()['id'] ?? 0)) : null; ledger_post('ship_cost', $id, ['bank_account_id' => (int)($in['bank_account_id'] ?? 0), 'cashbox_id' => $sh['cashbox_id'] ?? null]); }
        audit('shipments', $id, 'create', null, ['carrier' => $d['carrier'], 'tracking' => $d['tracking'], 'cost' => $d['cost']], 'ثبتِ ارسال');
        return $id;
    });
    if (!empty($in['sms']) && $d['receiver_mobile']) ship_sms($id);
    return $id;
}
function ship_status(int $id, string $st): void {
    if (!isset(ship_statuses()[$st])) throw new UserError('وضعیتِ نامعتبر');
    $s = row("SELECT * FROM shipments WHERE id=?", [$id]); if (!$s) throw new UserError('یافت نشد');
    upd('shipments', ['status' => $st, 'status_at' => now()], 'id=?', [$id]);
    audit('shipments', $id, 'status', ['status' => $s['status']], ['status' => $st], 'وضعیتِ ارسال');
}
function ship_sms(int $id): array {
    $s = row("SELECT sh.*, c.name courier, c.mobile cmobile FROM shipments sh LEFT JOIN couriers c ON c.id=sh.courier_id WHERE sh.id=?", [$id]); if (!$s || !$s['receiver_mobile']) return ['ok' => false, 'msg' => 'گیرنده موبایل ندارد'];
    $code = $s['tracking'] ?: ($s['cmobile'] ? 'پیک ' . $s['courier'] . ' ' . $s['cmobile'] : '');
    return sms_send_tpl((string)$s['receiver_mobile'], 'ship_track', ['NAME' => (string)$s['receiver_name'], 'CARRIER' => ship_carriers()[$s['carrier']] ?? $s['carrier'], 'CODE' => $code], 'notify', ['ref_type' => 'shipment', 'ref_id' => $id]);
}
function ship_list(array $f = []): array {
    $w = []; $p = [];
    if (!empty($f['status'])) { $w[] = 'sh.status=?'; $p[] = $f['status']; }
    if (!empty($f['carrier'])) { $w[] = 'sh.carrier=?'; $p[] = $f['carrier']; }
    if (!empty($f['from'])) { $w[] = 'sh.created_at>=?'; $p[] = $f['from'] . ' 00:00:00'; }
    if (!empty($f['to'])) { $w[] = 'sh.created_at<=?'; $p[] = $f['to'] . ' 23:59:59'; }
    if (!empty($f['sale_id'])) { $w[] = 'sh.sale_id=?'; $p[] = (int)$f['sale_id']; }
    return rows("SELECT sh.*, s.number sale_number, c.name courier, cu.name cname FROM shipments sh LEFT JOIN sales s ON s.id=sh.sale_id LEFT JOIN couriers c ON c.id=sh.courier_id LEFT JOIN customers cu ON cu.id=sh.customer_id"
        . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY sh.id DESC LIMIT 500", $p);
}
/** جمعِ هزینهٔ ارسال به تفکیکِ شرکت */
function ship_cost_summary(string $from, string $to): array {
    return array_map(fn($r) => $r + ['title' => ship_carriers()[$r['carrier']] ?? $r['carrier']], rows("SELECT carrier, COUNT(*) cnt, SUM(CASE WHEN payer='shop' THEN cost ELSE 0 END) shop_cost, SUM(CASE WHEN payer='customer' THEN cost ELSE 0 END) cust_cost,
        SUM(cod_amount) cod, SUM(status='delivered') delivered, SUM(status='returned') returned FROM shipments WHERE created_at BETWEEN ? AND ? AND status<>'canceled' GROUP BY carrier ORDER BY cnt DESC", [$from . ' 00:00:00', $to . ' 23:59:59']));
}

// ── لینکِ ثبتِ نشانی
function app_secret(): string { $s = (string)setting('app_secret_key', ''); if (strlen($s) < 32) { $s = bin2hex(random_bytes(24)); settings_save(['app_secret_key' => $s], false); } return $s; }
function addr_link_token(int $cid, bool $postal, ?int $until = null): string {
    $exp = intdiv($until ?? (time() + ADDR_LINK_DAYS * 86400), 86400);
    $body = base_convert((string)$cid, 10, 36) . '.' . base_convert((string)$exp, 10, 36) . ($postal ? 'p' : 'm');
    return $body . '.' . substr(rtrim(strtr(base64_encode(hash_hmac('sha256', 'addr|' . $body, app_secret(), true)), '+/', '-_'), '='), 0, 10);
}
function addr_link_parse(string $t): ?array {
    if (!preg_match('/^([0-9a-z]+)\.([0-9a-z]+)([pm])\.([A-Za-z0-9_-]{10})$/', $t, $m)) return null;
    $body = $m[1] . '.' . $m[2] . $m[3];
    if (!hash_equals(substr(rtrim(strtr(base64_encode(hash_hmac('sha256', 'addr|' . $body, app_secret(), true)), '+/', '-_'), '='), 0, 10), $m[4])) return null;
    $exp = (int)base_convert($m[2], 36, 10) * 86400 + 86399; if ($exp < time()) return ['expired' => true];
    $cid = (int)base_convert($m[1], 36, 10); $c = row("SELECT id, name, mobile FROM customers WHERE id=?", [$cid]); if (!$c) return null;
    return ['customer' => $c, 'postal' => $m[3] === 'p', 'exp' => $exp];
}
function addr_link_url(string $t): string { return rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/v.php?a=' . $t; }
/** ساختنِ لینک (و اگر نبود، مشتری)؛ پیامک اختیاری */
function addr_link_make(array $in): array {
    $cid = (int)($in['customer_id'] ?? 0);
    if (!$cid) { $m = norm_mobile($in['mobile'] ?? ''); if (!mobile_ok($m)) throw new UserError('مشتری را انتخاب کنید یا موبایلِ درست بزنید');
        $cid = (int)val("SELECT id FROM customers WHERE mobile=?", [$m]) ?: customer_save(['kind' => 'person', 'name' => trim((string)($in['name'] ?? '')) ?: $m, 'mobile' => $m]); }
    $t = addr_link_token($cid, !empty($in['postal'])); $url = addr_link_url($t); $c = row("SELECT name, mobile FROM customers WHERE id=?", [$cid]);
    $sms = null; if (!empty($in['sms']) && $c['mobile']) $sms = sms_send_tpl((string)$c['mobile'], 'addr_link', ['NAME' => (string)$c['name'], 'TOKEN' => $t, 'LINK' => $url], 'notify', ['ref_type' => 'customer', 'ref_id' => $cid]);
    audit('customers', $cid, 'addr_link', null, ['postal' => !empty($in['postal']), 'sms' => (bool)$sms], 'لینکِ ثبتِ نشانی');
    return ['customer_id' => $cid, 'url' => $url, 'token' => $t, 'msg' => $sms ? ($sms['ok'] ? 'پیامک رفت' : 'پیامک نرفت: ' . $sms['msg']) : 'لینک ساخته شد'];
}
function address_save(int $cid, array $in, string $source = 'staff', bool $postal = false): int {
    $a = ['customer_id' => $cid, 'title' => mb_cut(trim((string)($in['title'] ?? '')), 60) ?: null, 'province' => mb_cut(trim((string)($in['province'] ?? '')), 40) ?: null, 'city' => mb_cut(trim((string)($in['city'] ?? '')), 60) ?: null,
        'address' => mb_cut(trim((string)($in['address'] ?? '')), 500) ?: null, 'plaque' => mb_cut(trim(en_num((string)($in['plaque'] ?? ''))), 20) ?: null, 'unit' => mb_cut(trim(en_num((string)($in['unit'] ?? ''))), 20) ?: null,
        'postal_code' => digits_of($in['postal_code'] ?? '') ?: null, 'receiver_name' => mb_cut(trim((string)($in['receiver_name'] ?? '')), 120) ?: null, 'receiver_mobile' => norm_mobile($in['receiver_mobile'] ?? '') ?: null, 'source' => $source, 'created_at' => now()];
    $lat = (float)en_num((string)($in['lat'] ?? '')); $lng = (float)en_num((string)($in['lng'] ?? ''));
    if ($lat >= 24 && $lat <= 40.5 && $lng >= 44 && $lng <= 63.5) { $a['lat'] = round($lat, 7); $a['lng'] = round($lng, 7); } // فقط داخلِ ایران
    if (!$a['city'] || !$a['address']) { if (empty($a['lat'])) throw new UserError('شهر و نشانی را بنویسید یا موقعیت را روی نقشه/گوشی بزنید'); }
    if ($postal) { if (!$a['address'] || !$a['city']) throw new UserError('نشانیِ کامل و شهر لازم است'); if (!preg_match('/^\d{10}$/', (string)$a['postal_code'])) throw new UserError('کدِ پستیِ ۱۰ رقمی لازم است'); }
    if ($a['receiver_mobile'] && !mobile_ok($a['receiver_mobile'])) throw new UserError('موبایلِ گیرنده معتبر نیست');
    if ((int)val("SELECT COUNT(*) FROM customer_addresses WHERE customer_id=?", [$cid]) >= 20) throw new UserError('دفترچهٔ نشانی پر است (۲۰)');
    if ($ex = val("SELECT id FROM customer_addresses WHERE customer_id=? AND COALESCE(address,'')=? AND COALESCE(postal_code,'')=?", [$cid, (string)$a['address'], (string)$a['postal_code']])) return (int)$ex;
    $a['is_default'] = (int)val("SELECT COUNT(*) FROM customer_addresses WHERE customer_id=?", [$cid]) === 0 ? 1 : 0;
    $id = ins('customer_addresses', $a);
    if ($a['is_default']) upd('customers', ['address' => address_text($a) ?: null, 'postal_code' => $a['postal_code']], 'id=? AND (address IS NULL OR address=\'\')', [$cid]);
    audit('customers', $cid, 'address', null, ['address_id' => $id, 'source' => $source], 'نشانیِ تازه');
    return $id;
}
function addresses_of(int $cid): array { return rows("SELECT * FROM customer_addresses WHERE customer_id=? ORDER BY is_default DESC, id DESC", [$cid]); }

// ── بسته‌بندی (روالِ Weby packaging): مصرفِ کالای «مصرفیِ داخلی» روی هر ارسال؛ از انبار کم و هزینهٔ «مواد بسته‌بندی»
function ship_pack(int $shipId, int $pid, int $qty): int {
    if (!row("SELECT id FROM shipments WHERE id=?", [$shipId])) throw new UserError('ارسال یافت نشد'); $p = product_get($pid); if (!$p) throw new UserError('کالا یافت نشد');
    if ($p['item_type'] !== 'IMP') throw new UserError('فقط کالای «مصرفیِ داخلی» (کارتن، نوار، پلاستیک…) بسته‌بندی است'); if ($qty <= 0) throw new UserError('تعداد لازم است');
    return tx(function () use ($shipId, $pid, $qty) {
        $wh = (int)val("SELECT l.warehouse_id FROM lots l JOIN stock_moves m ON m.lot_id=l.id WHERE l.product_id=? GROUP BY l.warehouse_id HAVING SUM(m.qty)>=? ORDER BY SUM(m.qty) DESC LIMIT 1", [$pid, $qty]); if (!$wh) throw new UserError('موجودیِ این مادهٔ بسته‌بندی کافی نیست');
        $out = wh_stock_out(['product_id' => $pid, 'warehouse_id' => $wh], $qty, ['ref_type' => 'packaging', 'ref_id' => $shipId, 'kind' => 'packaging']); $cost = 0; foreach ($out as $o) $cost += $o['cost'] * $o['qty'];
        $id = ins('shipment_packs', ['shipment_id' => $shipId, 'product_id' => $pid, 'qty' => $qty, 'cost' => $cost, 'created_at' => now()]); if ($cost > 0) ledger_post('packaging', $id); return $id;
    });
}
// ── تسویه با پیک: ارسال‌هایی که هزینه‌شان «بدهی به پیک» ثبت شد، با یک پرداخت بسته می‌شوند
function courier_due(int $cid): array { return rows("SELECT * FROM shipments WHERE courier_id=? AND paid_from='courier' AND courier_settle_id IS NULL AND status<>'canceled' ORDER BY id", [$cid]); }
function courier_balance(int $cid): int { return array_sum(array_map(fn($s) => (int)$s['cost'], courier_due($cid))); }
function courier_settle(int $cid, string $method, int $bankId = 0, string $note = ''): int {
    $due = courier_due($cid); $amt = array_sum(array_map(fn($s) => (int)$s['cost'], $due)); if ($amt <= 0) throw new UserError('بدهی‌ای به این پیک نیست');
    $sh = $method === 'cash' ? shift_of_user((int)(current_user()['id'] ?? 0)) : null; if ($method === 'cash' && !$sh) throw new UserError('برای پرداختِ نقد شیفتِ باز لازم است');
    return tx(function () use ($cid, $method, $bankId, $note, $due, $amt, $sh) {
        $id = ins('courier_settles', ['courier_id' => $cid, 'amount' => $amt, 'method' => $method === 'bank' ? 'bank' : 'cash', 'bank_account_id' => $bankId ?: null, 'note' => mb_cut($note, 300) ?: null, 'created_by' => current_user()['id'] ?? null, 'created_at' => now()]);
        q("UPDATE shipments SET courier_settle_id=? WHERE id IN (" . in_list(array_column($due, 'id')) . ")", [$id]);
        if ($method === 'cash') ins('receipts', ['shift_id' => $sh['id'], 'cashbox_id' => $sh['cashbox_id'], 'method' => 'cash', 'amount' => -$amt, 'ref' => 'تسویه با پیک', 'at' => now()]);
        ledger_post('courier_settle', $id, ['cashbox_id' => $sh['cashbox_id'] ?? null]); audit('couriers', $cid, 'settle', null, ['amount' => $amt, 'n' => count($due)], 'تسویه با پیک'); return $id;
    });
}
// ── نظرسنجیِ پس از خرید (روالِ Weby order-feedback): پیامکِ لینک، امتیاز ۱ تا ۵ و نظر؛ امتیازِ کم به مدیر هشدار می‌دهد
function fb_token(int $saleId): string { $b = base_convert((string)$saleId, 10, 36); return $b . '.' . substr(rtrim(strtr(base64_encode(hash_hmac('sha256', 'fb|' . $b, app_secret(), true)), '+/', '-_'), '='), 0, 10); }
function fb_parse(string $t): ?int { if (!preg_match('/^([0-9a-z]+)\.([A-Za-z0-9_-]{10})$/', $t, $m)) return null; return hash_equals(fb_token((int)base_convert($m[1], 36, 10)), $t) ? (int)base_convert($m[1], 36, 10) : null; }
function fb_send(int $saleId): array {
    $s = row("SELECT s.*, c.name cname, c.mobile cmobile FROM sales s JOIN customers c ON c.id=s.customer_id WHERE s.id=?", [$saleId]); if (!$s || !$s['cmobile']) throw new UserError('این فروش مشتریِ با موبایل ندارد');
    if (val("SELECT sent_at FROM feedback WHERE sale_id=?", [$saleId])) throw new UserError('نظرسنجیِ این فروش قبلاً فرستاده شده است');
    $url = rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/v.php?fb=' . fb_token($saleId);
    $r = sms_send_tpl((string)$s['cmobile'], 'feedback', ['NAME' => (string)$s['cname'], 'LINK' => $url], 'notify', ['ref_type' => 'sale', 'ref_id' => $saleId]);
    q("INSERT INTO feedback (sale_id, customer_id, sent_at, created_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE sent_at=VALUES(sent_at)", [$saleId, $s['customer_id'], $r['ok'] ? now() : null, now()]);
    return ['ok' => $r['ok'], 'msg' => $r['ok'] ? 'لینکِ نظرسنجی رفت' : $r['msg'], 'url' => $url];
}
function fb_answer(int $saleId, int $rating, string $comment): void {
    if ($rating < 1 || $rating > 5) throw new UserError('امتیاز را انتخاب کنید'); $f = row("SELECT * FROM feedback WHERE sale_id=?", [$saleId]);
    if ($f && $f['answered_at']) throw new UserError('نظرِ شما قبلاً ثبت شده است؛ سپاس');
    q("INSERT INTO feedback (sale_id, customer_id, rating, comment, answered_at, created_at) VALUES (?, (SELECT customer_id FROM sales WHERE id=?), ?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment), answered_at=VALUES(answered_at)", [$saleId, $saleId, $rating, mb_cut(trim($comment), 2000) ?: null, now(), now()]);
    if ($rating <= 2) notify_managers('fb:' . $saleId, '😞 امتیازِ ' . fa_num((string)$rating) . ' برای فروشِ ' . fa_num((string)val("SELECT number FROM sales WHERE id=?", [$saleId])) . ($comment !== '' ? ': ' . mb_substr($comment, 0, 200) : ''));
}
/** خودکار: فروش‌های دارای مشتریِ موبایل‌دار پس از N ساعت (cron) */
function fb_auto(): int {
    $h = (int)setting('feedback_after_hours', '0'); if ($h <= 0 || !sms_tpl_enabled('feedback')) return 0; $n = 0;
    foreach (rows("SELECT s.id FROM sales s JOIN customers c ON c.id=s.customer_id LEFT JOIN feedback f ON f.sale_id=s.id WHERE f.id IS NULL AND c.mobile IS NOT NULL AND s.at BETWEEN ? AND ? LIMIT 30", [date('Y-m-d H:i:s', time() - ($h + 48) * 3600), date('Y-m-d H:i:s', time() - $h * 3600)]) as $r)
        { try { if (fb_send((int)$r['id'])['ok']) $n++; } catch (Throwable $e) {} }
    return $n;
}
