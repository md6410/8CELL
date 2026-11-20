<?php
// ضمانتِ فروشگاه — روالِ Weby (includes/warranty.php):
//  ۱) ضمانت به «دانه» می‌چسبد نه به فروش: دو گوشی = دو ضمانت با دو هولوگرام. فقط کالای دانه‌ای با «نوعِ ضمانت».
//  ۲) شرطِ روزِ فروش قفل می‌شود: نامِ نوع، مدت و تاریخِ انقضا روی خودِ ردیف کپی می‌شود.
//  ۳) هیچ ابطالی خودکار نیست: مرجوعی ردیف را به «در انتظارِ ابطال» می‌برد؛ تصمیم با مدیر. تنها گذارِ خودکار انقضای زمانی است.
//  فعال‌سازی با کدِ یک‌بارمصرف به موبایلِ صاحبِ ضمانت (۳۰ دقیقه، ۵ تلاش، ۲ دقیقه فاصله)؛ مدیرِ ارشد بی‌کد هم می‌تواند (در دفتر ثبت می‌شود).
declare(strict_types=1);

const WR_OTP_TTL_MIN = 30; const WR_OTP_MAX_TRIES = 5; const WR_OTP_GAP_SEC = 120;
function warranty_statuses(): array { return ['pending' => 'در انتظارِ فعال‌سازی', 'active' => 'ضمانتِ فعال', 'expired' => 'منقضی', 'cancel' => 'در انتظارِ ابطال', 'void' => 'باطل‌شده']; }
function warranty_actor(): string { $u = current_user(); return $u ? (string)$u['name'] : 'سیستم'; }
function warranty_log(int $wid, string $ev, string $detail = '', string $actor = ''): void { ins('warranty_log', ['warranty_id' => $wid ?: null, 'event' => $ev, 'detail' => mb_cut($detail, 500), 'actor' => $actor ?: warranty_actor(), 'created_at' => now()]); }
function warranty_type_save(array $in): int {
    $d = ['name' => trim((string)($in['name'] ?? '')), 'months' => max(0, to_int($in['months'] ?? 12)), 'terms' => trim((string)($in['terms'] ?? '')) ?: null, 'active' => empty($in['inactive']) ? 1 : 0];
    if ($d['name'] === '' || $d['months'] < 1) throw new UserError('نام و مدتِ ضمانت (ماه) لازم است');
    $id = (int)($in['id'] ?? 0); if ($id) { upd('warranty_types', $d, 'id=?', [$id]); return $id; }
    return ins('warranty_types', $d + ['created_at' => now()]);
}
function warranty_code_new(): string { do { $c = 'W' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 9)); } while (val("SELECT id FROM warranty_items WHERE code=?", [$c])); return $c; }
function warranty_url(string $code): string { return rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/v.php?w=' . rawurlencode($code); }
function warranty_get(int $id): ?array { return row("SELECT * FROM warranty_items WHERE id=?", [$id]); }
function warranty_holo_norm(string $h): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', en_num($h))); }

/** ساختنِ ضمانت برای دانه‌های فروخته‌شدهٔ یک فروش (یک بار برای هر دانه) */
function warranty_from_sale(int $saleId): int {
    if (!feature_on('warranty')) return 0;
    $s = row("SELECT s.*, c.name cname, c.mobile cmobile FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.id=?", [$saleId]); if (!$s) return 0; $n = 0;
    foreach (rows("SELECT si.*, p.warranty_type_id, p.name pname FROM sale_items si JOIN products p ON p.id=si.product_id WHERE si.sale_id=? AND p.warranty_type_id IS NOT NULL", [$saleId]) as $it) {
        $t = row("SELECT * FROM warranty_types WHERE id=? AND active=1", [$it['warranty_type_id']]); if (!$t) continue;
        $ship = $s['ship_to'] ? (json_decode((string)$s['ship_to'], true) ?: []) : [];
        foreach ((array)(json_decode((string)$it['units'], true) ?: []) as $code) {
            $u = row("SELECT * FROM wh_units WHERE code=?", [$code]); if (!$u || val("SELECT id FROM warranty_items WHERE unit_id=?", [$u['id']])) continue;
            $wid = ins('warranty_items', ['code' => warranty_code_new(), 'product_id' => $it['product_id'], 'variant_id' => $it['variant_id'], 'product_title' => mb_cut((string)$it['title'], 250), 'unit_id' => $u['id'], 'unit_code' => $code,
                'type_id' => $t['id'], 'type_name' => $t['name'], 'months' => (int)$t['months'], 'sale_id' => $saleId, 'sale_number' => $s['number'], 'customer_id' => $s['customer_id'],
                'buyer_name' => ($ship['name'] ?? '') ?: $s['cname'], 'buyer_mobile' => ($ship['mobile'] ?? '') ?: $s['cmobile'], 'hologram' => $u['hologram'] ?: null, 'status' => 'pending', 'sold_at' => $s['at'], 'created_at' => now()]);
            warranty_log($wid, 'created', 'فروشِ ' . $s['number'] . ' — دانهٔ ' . $code); $n++;
        }
    }
    return $n;
}
/** مرجوعیِ دانه: ضمانت به صفِ «در انتظارِ ابطال» (نه ابطالِ خودکار) */
function warranty_on_return(array $unitCodes, string $reason): int {
    $n = 0; foreach ($unitCodes as $c) { $w = row("SELECT * FROM warranty_items WHERE unit_code=? AND status IN ('pending','active')", [$c]); if (!$w) continue;
        upd('warranty_items', ['status' => 'cancel', 'cancel_reason' => mb_cut('مرجوعی: ' . $reason, 255), 'cancel_at' => now()], 'id=?', [$w['id']]); warranty_log((int)$w['id'], 'cancel_queue', 'مرجوعی: ' . $reason); $n++; }
    return $n;
}
function warranty_find(string $q): array {
    $q = trim(en_num($q)); if ($q === '') return []; $h = warranty_holo_norm($q); $m = norm_mobile($q);
    return rows("SELECT * FROM warranty_items WHERE code=? OR hologram=? OR unit_code=? OR sale_number=?" . (mobile_ok($m) ? " OR buyer_mobile=" . db()->quote($m) : '') . " ORDER BY id DESC LIMIT 50", [strtoupper($q), $h, $q, $q]);
}
function warranty_list(string $status = '', string $q = ''): array {
    if ($q !== '') return warranty_find($q);
    return rows("SELECT * FROM warranty_items" . ($status ? " WHERE status=" . db()->quote($status) : '') . " ORDER BY id DESC LIMIT 300");
}
function warranty_counts(): array { $o = array_fill_keys(array_keys(warranty_statuses()), 0); foreach (rows("SELECT status, COUNT(*) n FROM warranty_items GROUP BY status") as $r) $o[$r['status']] = (int)$r['n']; return $o; }

/** هولوگرامِ آزاد (نه روی ضمانتِ دیگر، نه روی دانهٔ دیگر) */
function warranty_holo_check(string $raw, int $exceptWid = 0, int $exceptUnit = 0): string {
    $h = warranty_holo_norm($raw); if (!preg_match('/^[A-Z0-9]{4,12}$/', $h)) throw new UserError('شمارهٔ هولوگرام ۴ تا ۱۲ نویسهٔ لاتین/عدد است');
    if (val("SELECT id FROM warranty_items WHERE hologram=? AND id<>?", [$h, $exceptWid])) throw new UserError('این هولوگرام روی ضمانتِ دیگری است');
    if (val("SELECT id FROM wh_units WHERE hologram=? AND id<>?", [$h, $exceptUnit])) throw new UserError('این هولوگرام روی دانهٔ دیگری چسبیده است');
    return $h;
}
/** چسباندنِ هولوگرام به دانه (هنگامِ ورود یا پیش از فروش) */
function unit_set_hologram(string $unitCode, string $raw): void {
    $u = wh_unit_by_code(scan_normalize($unitCode)); if (!$u) throw new UserError('دانه یافت نشد');
    $h = warranty_holo_check($raw, (int)(val("SELECT id FROM warranty_items WHERE unit_id=?", [$u['id']]) ?: 0), (int)$u['id']);
    upd('wh_units', ['hologram' => $h], 'id=?', [$u['id']]); upd('warranty_items', ['hologram' => $h], 'unit_id=? AND status=?', [$u['id'], 'pending']);
    audit('wh_units', $u['id'], 'hologram', null, ['hologram' => $h], 'هولوگرامِ دانه');
}
/** فرستادنِ کدِ فعال‌سازی به موبایلِ صاحبِ ضمانت */
function warranty_otp_send(int $wid, string $mobile, string $name = ''): array {
    $w = warranty_get($wid); if (!$w || $w['status'] !== 'pending') throw new UserError('این ضمانت در انتظارِ فعال‌سازی نیست');
    $m = norm_mobile($mobile); if (!mobile_ok($m)) throw new UserError('موبایلِ صاحبِ ضمانت معتبر نیست');
    $last = row("SELECT * FROM warranty_otp WHERE warranty_id=? ORDER BY id DESC LIMIT 1", [$wid]);
    if ($last && strtotime($last['created_at']) > time() - WR_OTP_GAP_SEC) throw new UserError('کدِ قبلی تازه رفته؛ ' . fa_num((string)(WR_OTP_GAP_SEC - (time() - strtotime($last['created_at'])))) . ' ثانیهٔ دیگر');
    q("UPDATE warranty_otp SET status='dead' WHERE warranty_id=? AND status='open'", [$wid]);
    $code = (string)random_int(10000, 99999);
    ins('warranty_otp', ['warranty_id' => $wid, 'code_hash' => password_hash($code, PASSWORD_DEFAULT), 'mobile' => $m, 'name' => mb_cut(trim($name), 120) ?: null, 'expires_at' => date('Y-m-d H:i:s', time() + WR_OTP_TTL_MIN * 60), 'created_at' => now()]);
    $r = sms_send_tpl($m, 'warranty_otp', ['NAME' => $name ?: (string)$w['buyer_name'], 'PRODUCT' => (string)$w['product_title'], 'CODE' => $code], 'otp', ['ref_type' => 'warranty', 'ref_id' => $wid]);
    warranty_log($wid, 'otp_sent', 'به ' . sms_mask($m) . ($r['ok'] ? '' : ' — نرفت: ' . $r['msg']));
    return ['ok' => $r['ok'], 'msg' => $r['ok'] ? 'کد به ' . sms_mask($m) . ' پیامک شد' : $r['msg'], 'test_code' => !empty($r['test']) ? $code : null];
}
/** فعال‌سازی: با کد، یا بی‌کد فقط مدیرِ ارشد (ثبت در دفتر) */
function warranty_activate(int $wid, array $in): array {
    $w = warranty_get($wid); if (!$w || $w['status'] !== 'pending') throw new UserError('این ضمانت در انتظارِ فعال‌سازی نیست');
    $holo = trim((string)($in['hologram'] ?? '')) !== '' ? warranty_holo_check((string)$in['hologram'], $wid, (int)$w['unit_id']) : ((string)$w['hologram'] ?: '');
    if ($holo === '' && setting('warranty_holo_required', '1') === '1') throw new UserError('شمارهٔ هولوگرام را بزنید');
    $u = current_user(); $mode = 'otp'; $mobile = ''; $name = '';
    if (!empty($in['no_code'])) {
        if (!$u || !$u['is_super']) throw new UserError('فعال‌سازیِ بی‌کد فقط با مدیرِ ارشد است', 403);
        $mobile = norm_mobile($in['mobile'] ?? '') ?: (string)$w['buyer_mobile']; $name = trim((string)($in['name'] ?? '')) ?: (string)$w['buyer_name']; $mode = 'manual';
    } else {
        $o = row("SELECT * FROM warranty_otp WHERE warranty_id=? AND status='open' ORDER BY id DESC LIMIT 1", [$wid]);
        if (!$o || strtotime($o['expires_at']) < time()) throw new UserError('کدِ فعالی نیست؛ دوباره کد بفرستید');
        if ((int)$o['tries'] >= WR_OTP_MAX_TRIES) throw new UserError('تلاش‌های اشتباه زیاد شد؛ کدِ تازه بفرستید');
        if (!password_verify(preg_replace('/\D/', '', en_num((string)($in['code'] ?? ''))), $o['code_hash'])) { q("UPDATE warranty_otp SET tries=tries+1 WHERE id=?", [$o['id']]); warranty_log($wid, 'otp_bad', ''); throw new UserError('کد اشتباه است'); }
        upd('warranty_otp', ['status' => 'used'], 'id=?', [$o['id']]); $mobile = $o['mobile']; $name = (string)($o['name'] ?: $w['buyer_name']);
    }
    $cid = (int)val("SELECT id FROM customers WHERE mobile=?", [$mobile]) ?: null;
    $exp = date('Y-m-d 23:59:59', strtotime('+' . (int)$w['months'] . ' month'));
    upd('warranty_items', ['status' => 'active', 'hologram' => $holo ?: null, 'buyer_mobile' => $mobile ?: null, 'buyer_name' => $name ?: null, 'customer_id' => $cid ?: $w['customer_id'], 'activated_at' => now(), 'expires_at' => $exp,
        'activated_by' => warranty_actor(), 'activate_mode' => $mode], 'id=?', [$wid]);
    if ($holo !== '' && $w['unit_id']) upd('wh_units', ['hologram' => $holo], 'id=? AND hologram IS NULL', [$w['unit_id']]);
    warranty_log($wid, 'activated', ($mode === 'manual' ? 'بی‌کد (مدیرِ ارشد)' : 'با کد') . ' — ' . sms_mask($mobile) . ' — هولوگرام ' . ($holo ?: '—'));
    if ($mobile && sms_tpl_enabled('warranty_on')) sms_send_tpl($mobile, 'warranty_on', ['NAME' => $name, 'PRODUCT' => (string)$w['product_title'], 'DATE' => jdate($exp), 'CODE' => (string)$w['code']], 'notify', ['ref_type' => 'warranty', 'ref_id' => $wid]);
    return ['ok' => true, 'expires' => $exp];
}
/** انقضای خودکار (cron) */
function warranty_expire_due(): int { $n = 0; foreach (rows("SELECT id FROM warranty_items WHERE status='active' AND expires_at<?", [now()]) as $r) { upd('warranty_items', ['status' => 'expired'], 'id=?', [$r['id']]); warranty_log((int)$r['id'], 'expired', '', 'سیستم'); $n++; } return $n; }
function warranty_void(int $wid, string $why): void {
    $w = warranty_get($wid); if (!$w || $w['status'] === 'void') throw new UserError('یافت نشد'); if (trim($why) === '') throw new UserError('دلیلِ ابطال لازم است');
    upd('warranty_items', ['status' => 'void', 'void_at' => now(), 'note' => mb_cut($why, 500)], 'id=?', [$wid]); warranty_log($wid, 'void', $why);
}
/** ردِ ابطال: ضمانت به وضعیتِ پیش از مرجوعی برمی‌گردد */
function warranty_cancel_reject(int $wid): void {
    $w = warranty_get($wid); if (!$w || $w['status'] !== 'cancel') throw new UserError('در صفِ ابطال نیست');
    $st = $w['activated_at'] ? ($w['expires_at'] < now() ? 'expired' : 'active') : 'pending';
    upd('warranty_items', ['status' => $st, 'cancel_reason' => null, 'cancel_at' => null], 'id=?', [$wid]); warranty_log($wid, 'cancel_reject', '');
}
/** خرابی: باز (مشکل) و بستن (اقدام و هزینه ← سندِ «هزینهٔ ضمانت و تعمیر») — بی‌سقفِ تعداد */
function warranty_claim_open(int $wid, string $problem): int {
    $w = warranty_get($wid); if (!$w || $w['status'] !== 'active') throw new UserError('فقط ضمانتِ فعال پذیرشِ خرابی دارد' . ($w && $w['status'] === 'expired' ? ' (منقضی شده)' : ''));
    if (trim($problem) === '') throw new UserError('شرحِ خرابی لازم است');
    $seq = (int)val("SELECT COALESCE(MAX(seq),0)+1 FROM warranty_claims WHERE warranty_id=?", [$wid]);
    $id = ins('warranty_claims', ['warranty_id' => $wid, 'seq' => $seq, 'problem' => $problem, 'opened_by' => warranty_actor(), 'opened_at' => now()]);
    q("UPDATE warranty_items SET claims_count=claims_count+1 WHERE id=?", [$wid]); warranty_log($wid, 'claim_open', 'خرابیِ ' . fa_num((string)$seq) . ': ' . $problem);
    return $id;
}
function warranty_claim_close(int $cid, string $action, int $cost = 0, string $method = 'cash', int $bankId = 0): void {
    $c = row("SELECT * FROM warranty_claims WHERE id=?", [$cid]); if (!$c || $c['status'] !== 'open') throw new UserError('این پرونده باز نیست');
    if (trim($action) === '') throw new UserError('اقدامِ انجام‌شده را بنویسید'); if ($cost < 0) throw new UserError('هزینه منفی نمی‌شود');
    tx(function () use ($c, $cid, $action, $cost, $method, $bankId) {
        upd('warranty_claims', ['status' => 'closed', 'action' => $action, 'cost' => $cost, 'closed_by' => warranty_actor(), 'closed_at' => now()], 'id=?', [$cid]);
        if ($cost > 0) ledger_post('warranty_cost', $cid, ['method' => $method, 'bank_account_id' => $bankId]);
        warranty_log((int)$c['warranty_id'], 'claim_close', $action . ($cost ? ' — هزینه ' . fa_money($cost) : ''));
    });
}
function warranty_cost_total(string $from, string $to): int { return (int)val("SELECT COALESCE(SUM(cost),0) FROM warranty_claims WHERE status='closed' AND closed_at BETWEEN ? AND ?", [$from . ' 00:00:00', $to . ' 23:59:59']); }
