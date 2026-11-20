<?php
// میزِ کارِ کارمند و ایستگاهِ کارت‌زنی — روالِ Weby (staff/my*, staff/leave, hr-stations, station.php)
//  ایستگاه: لینکِ رازدار روی سیستمِ محلِ کار؛ اولین دستگاه صاحبش می‌شود و دستگاهِ دیگر منتظرِ تأییدِ مدیر می‌ماند.
//    QRِ روی صفحه هر ۳۰ ثانیه عوض می‌شود؛ کارمند با گوشیِ خودش (واردشده) اسکن می‌کند و ورود/خروجش ثبت می‌شود.
//    عکسِ QR کهنه (بیش از یک دقیقه) کار نمی‌کند؛ پس از خانه نمی‌شود کارت زد.
declare(strict_types=1);

const ATT_WINDOW = 30;
function station_create(string $name): int { if (trim($name) === '') throw new UserError('نامِ ایستگاه لازم است'); return ins('hr_stations', ['name' => mb_cut(trim($name), 120), 'skey' => bin2hex(random_bytes(16)), 'created_at' => now()]); }
function station_url(array $s): string { return rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/v.php?st=' . $s['skey']; }
function station_by_key(string $k): ?array { return preg_match('/^[a-f0-9]{32}$/', $k) ? row("SELECT * FROM hr_stations WHERE skey=? AND active=1", [$k]) : null; }
/** کدام دستگاه؟ — شناسهٔ کوکیِ همین ایستگاه */
function station_device(array $s): string {
    $c = 'c8st' . $s['id']; $v = (string)($_COOKIE[$c] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $v)) { $v = bin2hex(random_bytes(16)); if (PHP_SAPI !== 'cli' && !headers_sent()) setcookie($c, $v, ['expires' => time() + 5 * 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]); $_COOKIE[$c] = $v; }
    return $v;
}
/** [وضع، پیام]: ok | pending */
function station_device_check(array $s, string $dev, string $label = ''): string {
    if (!$s['device_id']) { upd('hr_stations', ['device_id' => $dev, 'device_label' => mb_cut($label, 200)], 'id=?', [$s['id']]); return 'ok'; }
    if (hash_equals((string)$s['device_id'], $dev)) return 'ok';
    if ($s['pending_device'] !== $dev) upd('hr_stations', ['pending_device' => $dev, 'pending_label' => mb_cut($label, 200)], 'id=?', [$s['id']]);
    return 'pending';
}
function station_approve(int $id): void { $s = row("SELECT * FROM hr_stations WHERE id=?", [$id]); if (!$s || !$s['pending_device']) throw new UserError('درخواستی نیست'); upd('hr_stations', ['device_id' => $s['pending_device'], 'device_label' => $s['pending_label'], 'pending_device' => null, 'pending_label' => null], 'id=?', [$id]); }
function att_token(int $stationId, ?int $win = null): string { $w = $win ?? intdiv(time(), ATT_WINDOW); return $stationId . '.' . base_convert((string)$w, 10, 36) . '.' . substr(hash_hmac('sha256', "att|$stationId|$w", app_secret()), 0, 12); }
function att_token_check(string $t): ?int {
    if (!preg_match('/^(\d+)\.([0-9a-z]+)\.([a-f0-9]{12})$/', $t, $m)) return null; $w = (int)base_convert($m[2], 36, 10); $now = intdiv(time(), ATT_WINDOW);
    if ($w < $now - 2 || $w > $now) return null; // حداکثر حدودِ یک دقیقه
    return hash_equals(att_token((int)$m[1], $w), $t) && row("SELECT id FROM hr_stations WHERE id=? AND active=1", [(int)$m[1]]) ? (int)$m[1] : null;
}
/** کارت‌زدنِ کاربرِ واردشده با QRِ ایستگاه */
function att_punch_qr(string $token): array {
    $u = require_login(); $sid = att_token_check($token); if (!$sid) throw new UserError('این کد منقضی یا نامعتبر است؛ کدِ روی صفحهٔ ایستگاه را دوباره اسکن کنید');
    $emp = (int)($u['employee_id'] ?? 0) ?: (int)val("SELECT id FROM employees WHERE user_id=? AND active=1", [$u['id']]); if (!$emp) throw new UserError('حسابِ شما به پروندهٔ پرسنلی وصل نیست');
    $last = row("SELECT * FROM attendance WHERE employee_id=? AND d=?", [$emp, today()]);
    if ($last && strtotime(today() . ' ' . ($last['t_out'] ?: $last['t_in'])) > time() - 60) throw new UserError('همین الان کارت زده‌اید');
    $r = attendance_punch($emp, null, 'station'); q("UPDATE attendance SET station_id=? WHERE employee_id=? AND d=?", [$sid, $emp, today()]);
    return $r + ['msg' => ($r['t_out'] ? 'خروج ' . fa_num(substr((string)$r['t_out'], 0, 5)) : 'ورود ' . fa_num(substr((string)$r['t_in'], 0, 5))) . ' ثبت شد'];
}
// ── مرخصی از پنلِ خودِ کارمند: درخواست ← تأیید/ردِ منابعِ انسانی
function leave_self_request(array $in): int {
    $u = require_login(); $emp = (int)($u['employee_id'] ?? 0) ?: (int)val("SELECT id FROM employees WHERE user_id=?", [$u['id']]); if (!$emp) throw new UserError('حسابِ شما به پروندهٔ پرسنلی وصل نیست');
    $kind = isset(leave_kinds()[$in['kind'] ?? '']) ? $in['kind'] : 'paid'; $f = jparse((string)($in['from'] ?? '')); $t = jparse((string)($in['to'] ?? '')) ?: $f;
    if (!$f || $t < $f) throw new UserError('بازهٔ مرخصی را درست بزنید'); $days = isset($in['days']) && $in['days'] !== '' ? to_float($in['days']) : (float)((strtotime($t) - strtotime($f)) / 86400 + 1);
    if ($kind === 'paid' && $days > leave_balance($emp, (int)substr(jdate($f, false, false), 0, 4)) + 1e-9) throw new UserError('بیش از ماندهٔ مرخصی');
    $id = ins('leaves', ['employee_id' => $emp, 'kind' => $kind, 'from_d' => $f, 'to_d' => $t, 'days' => $days, 'status' => 'pending', 'note' => mb_cut((string)($in['note'] ?? ''), 300) ?: null, 'requested_by' => $u['id'], 'created_at' => now()]);
    pn_add('leave', 'درخواستِ مرخصیِ ' . $u['name'] . ': ' . fa_num((string)$days) . ' روز از ' . jdate($f), '', page_url('attendance'), 'hr.attendance');
    return $id;
}
function leave_decide(int $id, bool $ok, string $note = ''): void {
    if (!can('hr.attendance')) throw new UserError('تصمیمِ مرخصی با منابعِ انسانی است', 403);
    $l = row("SELECT * FROM leaves WHERE id=?", [$id]); if (!$l || $l['status'] !== 'pending') throw new UserError('درخواستِ باز نیست');
    $me = current_user(); if ($me && !$me['is_super'] && (int)$l['requested_by'] === (int)$me['id']) throw new UserError('مرخصیِ خودتان را نمی‌توانید تأیید کنید', 403);
    if ($ok && $l['kind'] === 'paid' && (float)$l['days'] > leave_balance((int)$l['employee_id'], (int)substr(jdate($l['from_d'], false, false), 0, 4)) + 1e-9) throw new UserError('بیش از ماندهٔ مرخصی');
    upd('leaves', ['status' => $ok ? 'approved' : 'rejected', 'decided_by' => current_user()['id'] ?? null, 'decided_at' => now(), 'decision_note' => mb_cut($note, 300) ?: null], 'id=?', [$id]);
    audit('leaves', $id, $ok ? 'approve' : 'reject', null, ['note' => $note], 'مرخصی');
}
/** خلاصهٔ میزِ کارِ کاربر */
function my_desk(): array {
    $u = require_login(); $ym = str_replace('/', '-', jym(today())); [$a, $b] = jmonth_range($ym);
    $emp = (int)($u['employee_id'] ?? 0) ?: (int)val("SELECT id FROM employees WHERE user_id=?", [$u['id']]);
    return ['today' => (int)val("SELECT COALESCE(SUM(total),0) FROM sales WHERE seller_id=? AND d=?", [$u['id'], today()]), 'today_n' => (int)val("SELECT COUNT(*) FROM sales WHERE seller_id=? AND d=?", [$u['id'], today()]),
        'month' => (int)val("SELECT COALESCE(SUM(total),0) FROM sales WHERE seller_id=? AND d BETWEEN ? AND ?", [$u['id'], $a, $b]), 'commission' => commission_month((int)$u['id'], $ym), 'cash' => stacc_balance((int)$u['id']),
        'emp' => $emp, 'att' => $emp ? row("SELECT * FROM attendance WHERE employee_id=? AND d=?", [$emp, today()]) : null, 'leave_bal' => $emp ? leave_balance($emp) : 0.0,
        'sales' => rows("SELECT id, number, total, at FROM sales WHERE seller_id=? ORDER BY id DESC LIMIT 20", [$u['id']]),
        'payslips' => $emp ? rows("SELECT * FROM payslips WHERE employee_id=? AND status<>'calc' ORDER BY ym DESC LIMIT 12", [$emp]) : [],
        'leaves' => $emp ? rows("SELECT * FROM leaves WHERE employee_id=? ORDER BY id DESC LIMIT 12", [$emp]) : []];
}
