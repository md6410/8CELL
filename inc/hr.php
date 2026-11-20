<?php
// منابع انسانی: پرونده، قرارداد و امضا، حضور (کارت‌زنی)، مرخصی با مانده، حقوق با پورسانت، فیش و سند
declare(strict_types=1);

function employee_save(array $in): int {
    $name = trim((string)($in['name'] ?? '')); if ($name === '') throw new UserError('نامِ کارمند لازم است');
    $nid = preg_replace('/\D/', '', en_num((string)($in['national_id'] ?? '')));
    if ($nid !== '' && !national_code_ok($nid)) throw new UserError('کدِ ملی معتبر نیست');
    $iban = strtoupper(preg_replace('/\s/', '', en_num((string)($in['iban'] ?? '')))); if ($iban !== '' && !iban_ok($iban)) throw new UserError('شبا معتبر نیست');
    $d = ['name' => $name, 'national_id' => $nid ?: null, 'mobile' => norm_mobile($in['mobile'] ?? '') ?: null, 'unit' => trim((string)($in['unit'] ?? '')) ?: null, 'position' => trim((string)($in['position'] ?? '')) ?: null,
        'contract_type' => in_array($in['contract_type'] ?? 'full', ['full', 'part', 'hourly', 'project'], true) ? ($in['contract_type'] ?? 'full') : 'full', 'base_salary' => to_int($in['base_salary'] ?? 0),
        'insured' => empty($in['uninsured']) ? 1 : 0, 'children' => to_int($in['children'] ?? 0), 'married' => empty($in['married']) ? 0 : 1, 'hire_date' => jparse($in['hire_date'] ?? '') ?: null,
        'user_id' => (int)($in['user_id'] ?? 0) ?: null, 'iban' => $iban ?: null, 'active' => empty($in['inactive']) ? 1 : 0];
    $id = (int)($in['id'] ?? 0);
    if ($id) { $o = row("SELECT * FROM employees WHERE id=?", [$id]); upd('employees', $d, 'id=?', [$id]); [$b, $a] = audit_diff($o, $d); audit('employees', $id, 'update', $b, $a, 'کارمند'); }
    else { $id = ins('employees', $d); audit('employees', $id, 'create', null, ['name' => $name], 'کارمندِ تازه'); }
    if ($d['user_id']) upd('users', ['employee_id' => $id], 'id=?', [$d['user_id']]);
    return $id;
}
function contract_save(array $in): int {
    $id = (int)($in['id'] ?? 0);
    if ($id && val("SELECT signed_at FROM contracts WHERE id=?", [$id])) throw new UserError('متنِ امضاشده دیگر عوض نمی‌شود');
    $d = ['employee_id' => (int)$in['employee_id'], 'title' => trim((string)($in['title'] ?? '')) ?: 'قراردادِ کار', 'body' => (string)($in['body'] ?? ''), 'start_date' => jparse($in['start'] ?? '') ?: today(),
        'end_date' => jparse($in['end'] ?? '') ?: null, 'salary' => to_int($in['salary'] ?? 0)];
    if (trim($d['body']) === '') throw new UserError('متنِ قرارداد لازم است');
    if ($id) { upd('contracts', $d, 'id=?', [$id]); return $id; }
    $d['created_at'] = now(); return ins('contracts', $d);
}
function contract_sign(int $id, string $method = 'otp', string $otp = ''): array {
    $c = row("SELECT c.*, e.mobile FROM contracts c JOIN employees e ON e.id=c.employee_id WHERE c.id=?", [$id]); if (!$c) throw new UserError('قرارداد یافت نشد');
    if ($c['signed_at']) throw new UserError('این قرارداد قبلاً امضا شده است');
    if ($method === 'otp') { if (!$c['mobile'] || !otp_check($c['mobile'], $otp, 'confirm')) throw new UserError('کدِ تأیید نادرست است'); }
    $hash = hash('sha256', $c['title'] . "\n" . $c['body'] . "\n" . $c['start_date'] . "\n" . $c['salary']);
    upd('contracts', ['hash' => $hash, 'signed_at' => now(), 'sign_method' => $method], 'id=?', [$id]);
    audit('contracts', $id, 'sign', null, ['hash' => $hash], 'امضای قرارداد');
    return ['hash' => $hash];
}
/** مادهٔ ۵۱ قانونِ کار: ۴۴ ساعت در هفته ≈ ۷٫۳۳ ساعت در روز؛ مرخصیِ سالانه ۲۶ روز (مادهٔ ۶۴) */
function hr_rules(): array { return setting_json('hr_rules', []) + ['start' => '08:00', 'hours' => 7.33, 'grace_min' => 10, 'leave_days_year' => 26, 'month_hours' => 220]; }
/** کارت‌زنی: ورود/خروج؛ تأخیر و اضافه‌کار خودکار */
function attendance_punch(int $empId, ?string $at = null, string $source = 'card'): array {
    $at = $at ?: now(); $d = substr($at, 0, 10); $t = substr($at, 11, 8);
    $r = row("SELECT * FROM attendance WHERE employee_id=? AND d=?", [$empId, $d]);
    if (!$r) { ins('attendance', ['employee_id' => $empId, 'd' => $d, 't_in' => $t, 'source' => $source]); $r = row("SELECT * FROM attendance WHERE employee_id=? AND d=?", [$empId, $d]); }
    else upd('attendance', ['t_out' => $t], 'id=?', [$r['id']]);
    return attendance_compute((int)$r['id']);
}
function attendance_compute(int $id): array {
    $r = row("SELECT * FROM attendance WHERE id=?", [$id]); $h = hr_rules();
    $late = 0; $work = 0; $over = 0;
    if ($r['t_in']) { $late = max(0, (int)((strtotime($r['d'] . ' ' . $r['t_in']) - strtotime($r['d'] . ' ' . $h['start'] . ':00')) / 60)); if ($late <= (int)$h['grace_min']) $late = 0; }
    if ($r['t_in'] && $r['t_out']) { $work = max(0, (int)((strtotime($r['d'] . ' ' . $r['t_out']) - strtotime($r['d'] . ' ' . $r['t_in'])) / 60)); $over = max(0, $work - (int)round((float)$h['hours'] * 60)); }
    upd('attendance', ['late_min' => $late, 'work_min' => $work, 'over_min' => $over], 'id=?', [$id]);
    return ['late_min' => $late, 'work_min' => $work, 'over_min' => $over, 't_in' => $r['t_in'], 't_out' => $r['t_out']];
}
function attendance_month(int $empId, string $ym): array {
    [$a, $b] = jmonth_range($ym);
    $rs = rows("SELECT * FROM attendance WHERE employee_id=? AND d BETWEEN ? AND ? ORDER BY d", [$empId, $a, $b]);
    $leaves = rows("SELECT * FROM leaves WHERE employee_id=? AND status='approved' AND NOT (to_d<? OR from_d>?)", [$empId, $a, $b]);
    $unpaid = 0.0; $paid = 0.0; foreach ($leaves as $l) { if ($l['kind'] === 'unpaid') $unpaid += (float)$l['days']; else $paid += (float)$l['days']; }
    return ['days' => count($rs), 'late' => array_sum(array_column($rs, 'late_min')), 'over' => array_sum(array_column($rs, 'over_min')), 'work' => array_sum(array_column($rs, 'work_min')),
        'leave_paid' => $paid, 'leave_unpaid' => $unpaid, 'rows' => $rs, 'month_days' => (int)((strtotime($b) - strtotime($a)) / 86400) + 1];
}
function leave_kinds(): array { return ['paid' => 'استحقاقی', 'sick' => 'استعلاجی', 'unpaid' => 'بدونِ حقوق']; }
/** ماندهٔ مرخصیِ استحقاقیِ سالِ جلالی = سهمِ سال − استفاده‌شده */
function leave_balance(int $empId, ?int $jy = null): float {
    $jy = $jy ?: (int)substr(jtoday(), 0, 4);
    $a = jparse("$jy/01/01"); $b = jparse("$jy/12/" . jalali_month_days($jy, 12));
    $used = (float)val("SELECT COALESCE(SUM(days),0) FROM leaves WHERE employee_id=? AND kind='paid' AND status='approved' AND from_d BETWEEN ? AND ?", [$empId, $a, $b]);
    $opening = (float)setting('leave_open_' . $empId . '_' . $jy, '0');
    return (float)hr_rules()['leave_days_year'] + $opening - $used;
}
function leave_request(array $in): int {
    $emp = (int)($in['employee_id'] ?? 0); $kind = isset(leave_kinds()[$in['kind'] ?? '']) ? $in['kind'] : 'paid';
    $f = jparse($in['from'] ?? ''); $t = jparse($in['to'] ?? '') ?: $f;
    if (!$emp || !$f || $t < $f) throw new UserError('کارمند و بازهٔ مرخصی را درست وارد کنید');
    $days = isset($in['days']) && $in['days'] !== '' ? to_float($in['days']) : (float)((strtotime($t) - strtotime($f)) / 86400 + 1);
    if ($kind === 'paid') { $bal = leave_balance($emp, (int)substr(jdate($f, false, false), 0, 4)); if ($days > $bal + 1e-9) throw new UserError('مانده ' . fa_num(rtrim(rtrim(number_format($bal, 1, '.', ''), '0'), '.')) . ' روز است'); }
    $id = ins('leaves', ['employee_id' => $emp, 'kind' => $kind, 'from_d' => $f, 'to_d' => $t, 'days' => $days, 'status' => 'approved', 'note' => $in['note'] ?? null, 'created_at' => now()]);
    audit('leaves', $id, 'create', null, ['days' => $days, 'kind' => $kind], 'مرخصی');
    return $id;
}
/** نرخ‌های قانونِ کار — مصوبِ سالانه در تنظیمات، نه در کد (پیش‌فرض‌ها: مصوبهٔ ۱۴۰۵، برگرفته از Weby labor-rules.php؛ همه به ریال) */
function labor_rates(): array {
    return setting_json('labor_rates', []) + ['min_daily' => 5541850, 'min_monthly' => 166255500, 'housing' => 30000000, 'food' => 22000000, 'marriage' => 5000000,
        'child_factor' => 3, 'ins_pct' => 7, 'ins_er_pct' => 23, 'tax_exempt' => 400000000, 'tax_pct' => 10, 'over_factor' => 1.4, 'ins_cap_factor' => 7,
        'tax_s2_from' => 0, 'tax_s2_pct' => 0, 'tax_s3_from' => 0, 'tax_s3_pct' => 0, 'tax_s4_from' => 0, 'tax_s4_pct' => 0, 'tax_s5_from' => 0, 'tax_s5_pct' => 0];
}
/** پله‌های مالیاتِ حقوق (مادهٔ ۸۵ و قانونِ بودجهٔ هر سال): [از مبلغِ ماهانه، نرخ٪]؛ پلهٔ اول از سقفِ معافیت با «نرخِ مالیات». پلهٔ با «از» صفر خاموش است. */
function payroll_tax_steps(array $L): array {
    $st = [[(int)$L['tax_exempt'], (float)$L['tax_pct']]];
    for ($i = 2; $i <= 5; $i++) { $f = (int)($L["tax_s{$i}_from"] ?? 0); if ($f > (int)$L['tax_exempt']) $st[] = [$f, (float)($L["tax_s{$i}_pct"] ?? 0)]; }
    usort($st, fn($a, $b) => $a[0] <=> $b[0]); return $st;
}
/** مالیاتِ پلکانی: هر بخش از درآمدِ مشمول با نرخِ پلهٔ خودش */
function payroll_tax(int $taxable, array $L): int {
    $st = payroll_tax_steps($L); $t = 0.0;
    foreach ($st as $i => [$from, $pct]) { $to = $st[$i + 1][0] ?? PHP_INT_MAX; if ($taxable > $from) $t += (min($taxable, $to) - $from) * $pct / 100; }
    return (int)round($t);
}
/** سقفِ دستمزدِ مشمولِ بیمه = ضریب (۷) × حداقلِ مزدِ ماهانه؛ صفر یعنی بی‌سقف */
function payroll_ins_base(int $base, array $L): int { $cap = (int)round((float)($L['ins_cap_factor'] ?? 0) * (int)$L['min_monthly']); return $cap > 0 ? min($base, $cap) : $base; }
/** روزهای کاریِ واقعیِ ماه (بی‌تعطیلات) — مبنای نرخِ ساعتِ اضافه‌کاری */
function hr_month_workdays(string $ym): int { [$a, $b] = jmonth_range($ym); $n = 0; for ($d = $a; $d <= $b; $d = date('Y-m-d', strtotime("$d +1 day"))) if (!holiday_info($d)['off']) $n++; return max(1, $n); }
/**
 * فیشِ ماه (روالِ Weby hr.php payslip_calc):
 *  - مرخصیِ بی‌حقوق/غیبت: مزدِ روز = پایه ÷ ۳۰
 *  - نرخِ ساعت = پایه ÷ (روزِ کاریِ واقعیِ ماه × ساعتِ روز)؛ اضافه‌کاری × ضریب
 *  - حقِ اولاد = ۳ × حداقلِ مزدِ روزانه × فرزند
 *  - مبنای بیمه = پایه + مسکن + اضافه‌کاری (بن و پورسانت/پاداش مشمول نیستند)
 *  - مبنای بیمه حداکثر ۷ برابرِ حداقلِ مزدِ ماهانه (سقفِ تأمین اجتماعی)
 *  - مالیات = پلکانی روی (ناخالص − بیمهٔ کارمند)، از سقفِ معافیت به بالا
 */
function payroll_calc(int $empId, string $ym, array $extra = []): array {
    $e = row("SELECT * FROM employees WHERE id=?", [$empId]); if (!$e) throw new UserError('کارمند یافت نشد');
    $ym = str_replace('/', '-', en_num($ym)); $L = labor_rates(); $att = attendance_month($empId, $ym); $h = hr_rules();
    $sal = (int)$e['base_salary']; $dayWage = $sal / 30;
    $cut = (int)round($dayWage * ($att['leave_unpaid'] + (float)($extra['absent'] ?? 0)));
    $base = max(0, $sal - $cut);
    $hourly = $sal / (hr_month_workdays($ym) * max(1.0, (float)$h['hours']));
    $over = (int)round($att['over'] / 60 * $hourly * (float)$L['over_factor']);
    $comm = $e['user_id'] ? commission_month((int)$e['user_id'], $ym) : 0;
    $items = [['t' => 'حقوقِ پایه', 'a' => $base, 'k' => 'earn']];
    if ($cut) $items[0]['t'] .= ' (کسرِ ' . fa_num((string)($att['leave_unpaid'] + (float)($extra['absent'] ?? 0))) . ' روز)';
    $items[] = ['t' => 'حقِ مسکن', 'a' => (int)$L['housing'], 'k' => 'earn', 'ins' => 1];
    $items[] = ['t' => 'بن و خواربار', 'a' => (int)$L['food'], 'k' => 'earn'];
    if ($e['married']) $items[] = ['t' => 'حقِ تأهل', 'a' => (int)$L['marriage'], 'k' => 'earn'];
    if ((int)$e['children'] > 0) $items[] = ['t' => 'حقِ اولاد', 'a' => (int)$L['child_factor'] * (int)$L['min_daily'] * (int)$e['children'], 'k' => 'earn'];
    if ($over) $items[] = ['t' => 'اضافه‌کار (' . fa_num((string)round($att['over'] / 60, 1)) . ' ساعت)', 'a' => $over, 'k' => 'earn', 'ins' => 1];
    if ($comm) $items[] = ['t' => 'پورسانت', 'a' => $comm, 'k' => 'earn', 'commission' => 1];
    foreach ((array)($extra['earn'] ?? []) as $x) if (to_int($x['a'] ?? 0)) $items[] = ['t' => (string)$x['t'], 'a' => to_int($x['a']), 'k' => 'earn'];
    $gross = array_sum(array_column($items, 'a'));
    $insBase = payroll_ins_base($base + (int)$L['housing'] + $over, $L);
    $ins = $e['insured'] ? (int)round($insBase * (float)$L['ins_pct'] / 100) : 0;
    $tax = payroll_tax($gross - $ins, $L);
    if ($ins) $items[] = ['t' => 'بیمهٔ سهمِ کارمند (' . fa_num((string)$L['ins_pct']) . '٪)', 'a' => $ins, 'k' => 'ded', 'acc' => 'ins'];
    if ($tax) $items[] = ['t' => 'مالیاتِ حقوق', 'a' => $tax, 'k' => 'ded', 'acc' => 'tax'];
    $debt = to_int($extra['debt'] ?? 0); if ($debt) $items[] = ['t' => 'کسرِ بدهی', 'a' => $debt, 'k' => 'ded', 'acc' => 'debt'];
    $ded = array_sum(array_map(fn($i) => $i['k'] === 'ded' ? $i['a'] : 0, $items));
    $warn = []; if ($e['contract_type'] === 'full' && $sal > 0 && $sal < (int)$L['min_monthly']) $warn[] = 'حقوقِ پایه کمتر از حداقلِ مزدِ ماهانهٔ مصوب است';
    return ['employee' => $e, 'ym' => $ym, 'items' => $items, 'gross' => $gross, 'deductions' => $ded, 'net' => $gross - $ded, 'ins_er' => $e['insured'] ? (int)round($insBase * (float)$L['ins_er_pct'] / 100) : 0, 'warnings' => $warn, 'attendance' => array_diff_key($att, ['rows' => 1])];
}
function payslip_save(int $empId, string $ym, array $extra = []): int {
    $c = payroll_calc($empId, $ym, $extra);
    $ex = row("SELECT * FROM payslips WHERE employee_id=? AND ym=?", [$empId, $c['ym']]);
    if ($ex && $ex['status'] !== 'calc') throw new UserError('فیشِ این ماه قطعی شده است');
    $d = ['items' => json_encode($c['items'], JSON_UNESCAPED_UNICODE), 'gross' => $c['gross'], 'deductions' => $c['deductions'], 'net' => $c['net']];
    if ($ex) { upd('payslips', $d, 'id=?', [$ex['id']]); return (int)$ex['id']; }
    return ins('payslips', $d + ['employee_id' => $empId, 'ym' => $c['ym'], 'status' => 'calc', 'created_at' => now()]);
}
/** قطعی‌کردن ← سندِ حقوق */
function payslip_post(int $id): void {
    $p = row("SELECT * FROM payslips WHERE id=?", [$id]); if (!$p || $p['status'] !== 'calc') throw new UserError('فیش قابلِ قطعی‌کردن نیست');
    tx(function () use ($p, $id) {
        ledger_post('payroll', $id);
        upd('payslips', ['status' => 'posted'], 'id=?', [$id]);
        $items = json_decode($p['items'], true) ?: []; $uid = (int)val("SELECT user_id FROM employees WHERE id=?", [$p['employee_id']]);
        foreach ($items as $i) if (($i['acc'] ?? '') === 'debt' && $uid) stacc_add($uid, -(int)$i['a'], 'repay', 'payslip', $id, 'کسر از حقوق');
        audit('payslips', $id, 'post', null, ['net' => $p['net']], 'قطعی‌کردنِ فیش');
    });
}
function payslip_pay(int $id, int $bankId): void {
    $p = row("SELECT * FROM payslips WHERE id=?", [$id]); if (!$p || $p['status'] !== 'posted') throw new UserError('ابتدا فیش را قطعی کنید');
    tx(function () use ($p, $id, $bankId) { ledger_post('payroll_pay', $id, ['amount' => (int)$p['net'], 'bank_account_id' => $bankId, 'employee_id' => (int)$p['employee_id']]); upd('payslips', ['status' => 'paid', 'paid_at' => now()], 'id=?', [$id]); });
}
function payslip_text(int $id): string {
    $p = row("SELECT p.*, e.name FROM payslips p JOIN employees e ON e.id=p.employee_id WHERE p.id=?", [$id]); $o = [];
    foreach (json_decode($p['items'], true) ?: [] as $i) $o[] = $i['t'] . ' ' . fa_money($i['a']);
    return 'فیشِ ' . $p['name'] . ' ' . fa_num(str_replace('-', '/', $p['ym'])) . ': ' . implode('، ', $o) . ' — خالص ' . fa_money($p['net']);
}
