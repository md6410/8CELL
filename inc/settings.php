<?php
// تنظیمات، پروفایلِ کسب‌وکار، شعبه/انبار/صندوق و مقادیرِ پیش‌فرض
declare(strict_types=1);

function setting(string $k, $default = null) {
    static $cache = null;
    if ($k === '__flush') { $cache = null; return null; }
    if ($cache === null) { $cache = []; try { foreach (rows("SELECT k,v FROM settings") as $r) $cache[$r['k']] = $r['v']; } catch (Throwable $e) {} }
    return array_key_exists($k, $cache) ? $cache[$k] : $default;
}
function setting_json(string $k, $default = []) { $v = setting($k); if ($v === null || $v === '') return $default; $d = json_decode($v, true); return $d === null ? $default : $d; }
function settings_save(array $kv, bool $audit = true): void {
    foreach ($kv as $k => $v) {
        if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        $old = setting($k);
        if ((string)$old === (string)$v) continue;
        q("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$k, (string)$v]);
        if ($audit && !str_contains($k, 'secret') && !str_contains($k, 'token') && !str_contains($k, 'api_key'))
            audit('settings', $k, 'update', [$k => $old], [$k => $v], setting_label($k));
    }
    setting('__flush');
}
function setting_label(string $k): string {
    $l = ['biz_name' => 'نامِ کسب‌وکار', 'vat_pct' => 'نرخِ مالیات', 'biz_national_id' => 'شناسهٔ ملی'];
    return $l[$k] ?? $k;
}

/** فیلدهای پروفایلِ کسب‌وکار — هرچه روی فاکتور/رسید چاپ می‌شود */
function biz_fields(): array {
    return [
        'biz_name' => ['نامِ کسب‌وکار', 'text', true], 'biz_kind' => ['نوعِ شخص', 'select:person=حقیقی,legal=حقوقی', true],
        'biz_national_id' => ['شناسهٔ ملی / کدِ ملی', 'text', false], 'biz_economic_code' => ['کدِ اقتصادی', 'text', false],
        'biz_reg_no' => ['شمارهٔ ثبت', 'text', false], 'biz_postal_code' => ['کدِ پستی', 'text', false],
        'biz_address' => ['نشانی', 'textarea', false], 'biz_phone' => ['تلفن', 'text', false], 'biz_mobile' => ['موبایلِ مدیر', 'text', false],
        'biz_logo' => ['لوگو (نشانیِ تصویر)', 'text', false], 'biz_stamp' => ['مهر (نشانیِ تصویر)', 'text', false], 'biz_sign' => ['امضا (نشانیِ تصویر)', 'text', false],
        'doc_footer' => ['پانویسِ فاکتور', 'textarea', false], 'brand_color' => ['رنگِ اصلی', 'color', false],
    ];
}
function biz_profile_save(array $in): void {
    $kind = $in['biz_kind'] ?? 'person';
    $nid = preg_replace('/\D/', '', en_num($in['biz_national_id'] ?? ''));
    if ($nid !== '') {
        if ($kind === 'legal' ? !legal_id_ok($nid) : !national_code_ok($nid)) throw new UserError('شناسهٔ ملی معتبر نیست');
    }
    $pc = preg_replace('/\D/', '', en_num($in['biz_postal_code'] ?? ''));
    if ($pc !== '' && !preg_match('/^\d{10}$/', $pc)) throw new UserError('کدِ پستی باید ۱۰ رقم باشد');
    if (trim((string)($in['biz_name'] ?? '')) === '') throw new UserError('نامِ کسب‌وکار لازم است');
    $save = [];
    foreach (biz_fields() as $k => $f) if (array_key_exists($k, $in)) $save[$k] = trim((string)$in[$k]);
    $save['biz_national_id'] = $nid; $save['biz_postal_code'] = $pc;
    settings_save($save);
}
function biz(): array { $o = []; foreach (biz_fields() as $k => $f) $o[substr($k, 4)] = setting($k, ''); $o['name'] = setting('biz_name', '8CELL'); return $o; }

/** کدِ انبار: کدِ استان (۲ حرف) + شماره (۲ رقم) */
function wh_code(string $prov, $num): string { return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $prov) . 'XX', 0, 2)) . sprintf('%02d', (int)$num % 100); }
function wh_provinces(): array {
    return ['TH' => 'تهران', 'AL' => 'البرز', 'IS' => 'اصفهان', 'KR' => 'خراسان رضوی', 'FA' => 'فارس', 'AE' => 'آذربایجان شرقی', 'AW' => 'آذربایجان غربی',
        'KZ' => 'خوزستان', 'MZ' => 'مازندران', 'GL' => 'گیلان', 'QM' => 'قم', 'KE' => 'کرمان', 'YZ' => 'یزد', 'HM' => 'همدان', 'KS' => 'کرمانشاه',
        'MK' => 'مرکزی', 'QZ' => 'قزوین', 'ZN' => 'زنجان', 'SM' => 'سمنان', 'AR' => 'اردبیل', 'GO' => 'گلستان', 'LO' => 'لرستان', 'HZ' => 'هرمزگان',
        'BU' => 'بوشهر', 'SB' => 'سیستان و بلوچستان', 'KD' => 'کردستان', 'IL' => 'ایلام', 'CB' => 'چهارمحال و بختیاری', 'KB' => 'کهگیلویه و بویراحمد',
        'KN' => 'خراسان شمالی', 'KJ' => 'خراسان جنوبی'];
}

/** مقادیرِ پیش‌فرض پس از نصب — نصبِ تازه بی‌تنظیم کار می‌کند */
function seed_defaults(): void {
    $has = fn($t) => (int)val("SELECT COUNT(*) FROM `$t`") > 0;
    if (setting('biz_name') === null) settings_save(['biz_name' => 'کسب‌وکارِ من', 'biz_kind' => 'person', 'vat_pct' => '10', 'tax_round' => 'floor',
        'total_round' => '0', 'doc_pattern' => '{y}-{seq5}', 'sms_test_mode' => '1', 'sms_retry_max' => '3', 'update_repo' => 'md6410/8cell',
        'update_branch' => 'main', 'update_auto' => '1', 'update_interval' => '10', 'reserve_minutes' => '30', 'staff_debt_pct' => '20',
        'holiday_rule' => 'next', 'thursday_off' => '0', 'scan_sound' => '1', 'brand_color' => '#0f766e'], false);
    if (!$has('branches')) {
        $b = ins('branches', ['name' => 'شعبهٔ مرکزی', 'active' => 1]);
        $w = ins('warehouses', ['branch_id' => $b, 'name' => 'انبارِ مرکزی', 'province' => 'TH', 'num' => '01', 'code' => 'TH01']);
        upd('branches', ['default_wh_id' => $w], 'id=?', [$b]);
        ins('cashboxes', ['branch_id' => $b, 'name' => 'صندوقِ ۱']);
    }
    if (!$has('fiscal_years')) {
        [$jy] = g2j((int)date('Y'), (int)date('n'), (int)date('j'));
        ins('fiscal_years', ['title' => 'سالِ مالیِ ' . $jy, 'start_date' => jparse("$jy/01/01"), 'end_date' => jparse("$jy/12/" . jalali_month_days($jy, 12)), 'vat_pct' => 10, 'rounding' => 'floor']);
    }
    if (!$has('units')) { ins('units', ['name' => 'عدد', 'scale' => 1]); ins('units', ['name' => 'کیلوگرم', 'scale' => 1000, 'sub_name' => 'گرم']); ins('units', ['name' => 'متر', 'scale' => 100, 'sub_name' => 'سانتی‌متر']); }
    if (!$has('categories')) ins('categories', ['name' => 'عمومی', 'code' => 'GENR']);
    if (!$has('grades')) { ins('grades', ['name' => 'عادی', 'kind' => 'customer', 'disc_pct' => 0]); ins('grades', ['name' => 'همکارِ نقره‌ای', 'kind' => 'partner', 'disc_pct' => 0]); }
    roles_seed();
    ledger_seed_accounts();
    labels_seed();
}

function branch_list(bool $activeOnly = true): array { return rows("SELECT * FROM branches" . ($activeOnly ? " WHERE active=1" : "") . " ORDER BY id"); }
function warehouse_list(): array { return rows("SELECT w.*, b.name AS branch FROM warehouses w LEFT JOIN branches b ON b.id=w.branch_id WHERE w.active=1 ORDER BY w.id"); }
function cashbox_list(): array { return rows("SELECT c.*, b.name AS branch, b.default_wh_id FROM cashboxes c JOIN branches b ON b.id=c.branch_id WHERE c.active=1 ORDER BY c.id"); }
