<?php
$tab = $_GET['tab'] ?? 'biz'; $ro = !can('settings.edit');
echo ui_tabs(['biz' => 'پروفایلِ کسب‌وکار', 'fiscal' => 'سالِ مالی و قفلِ ماه', 'doc' => 'سند و فروش', 'features' => 'امکانات'], $tab, 'settings');
if ($tab === 'biz') {
    $v = []; foreach (biz_fields() as $k => $f) $v[$k] = setting($k, '');
    $fs = []; foreach (biz_fields() as $k => [$t, $type, $req]) { $o = []; if (str_starts_with($type, 'select:')) { foreach (explode(',', substr($type, 7)) as $p) { [$a, $b] = explode('=', $p); $o[$a] = $b; } $type = 'select'; } $fs[$k] = [$t, $type, $req, $o]; }
    echo ui_card('پروفایلِ کسب‌وکار — هرچه روی فاکتور، رسید و گزارش چاپ می‌شود', $ro ? '' : ui_form('biz_save', $fs, $v));
} elseif ($tab === 'fiscal') {
    $fy = rows("SELECT * FROM fiscal_years ORDER BY start_date DESC");
    echo ui_card('سال‌های مالی', ui_table(['title' => 'عنوان', 'start_date' => ['آغاز', 'date'], 'end_date' => ['پایان', 'date'], 'vat_pct' => ['مالیات ٪', 'num'], 'rounding' => 'گردکردنِ مالیات'], $fy)
        . (can('fiscal.manage') ? ui_form('fiscal_save', ['title' => ['عنوان', 'text', 1], 'start' => ['آغاز (جلالی)', 'date', 1], 'end' => ['پایان', 'date', 1], 'vat_pct' => ['نرخِ مالیات بر ارزش افزوده ٪', 'float', 1, [], 10], 'rounding' => ['گردکردنِ مالیات', 'select', 1, ['floor' => 'رو به پایین (ریالِ صحیح)', 'round' => 'نزدیک‌ترین', 'ceil' => 'رو به بالا']]], [], ['submit' => 'افزودنِ سالِ مالی']) : ''));
    $locks = fiscal_locks();
    echo ui_card('قفلِ ماه — سندِ ماهِ قفل‌شده ویرایش نمی‌شود', ui_table(['ym' => ['ماه', 'num'], 'locked_at' => ['قفل در', 'datetime'], 'a' => ['', fn($r) => can('fiscal.manage') ? ui_btn_api('بازکردن', 'fiscal_lock', ['ym' => $r['ym'], 'on' => 0], ['class' => 'sm', 'confirm' => 'ماه باز شود؟']) : '']], $locks, ['empty' => 'ماهِ قفل‌شده‌ای نیست.'])
        . (can('fiscal.manage') ? ui_form('fiscal_lock', ['ym' => ['ماه (۱۴۰۵/۰۷)', 'text', 1]], [], ['hidden' => ['on' => 1], 'submit' => 'قفلِ ماه', 'confirm' => 'پس از قفل، هیچ سندی در این ماه ثبت یا ویرایش نمی‌شود.']) : ''));
} elseif ($tab === 'doc') {
    $k = ['doc_pattern' => ['الگوی شماره ({y}، {seq5}…)', 'text'], 'total_round' => ['گردکردنِ جمعِ فاکتور (ریال؛ ۰ = هیچ)', 'int'], 'fx_round' => ['گردکردنِ قیمتِ دلاری (ریال)', 'int'], 'reserve_minutes' => ['زمانِ رزروِ سبد (دقیقه)', 'int'],
        'pos_require_unit' => ['برای کالای دانه‌دار اسکنِ دانه الزامی است', 'select', 0, ['0' => 'خیر', '1' => 'بله']], 'shortage_to_staff' => ['کسریِ صندوق به حسابِ صندوق‌دار', 'select', 0, ['1' => 'بله', '0' => 'خیر (هزینه)']],
        'staff_debt_pct' => ['سقفِ بدهیِ کارمند (٪ حقوق)', 'float'], 'staff_debt_cap' => ['سقفِ بدهیِ کارمندِ بی‌حقوق (ریال)', 'money'], 'loss_sms_confirm' => ['تأییدِ پیامکیِ ضرر', 'select', 0, ['0' => 'خیر', '1' => 'بله']],
        'channel_price_tolerance' => ['تحملِ اختلافِ قیمتِ کانال (ریال)', 'int'], 'scan_sound' => ['صدای اسکن', 'select', 0, ['1' => 'روشن', '0' => 'خاموش']], 'captcha_after' => ['کپچا پس از چند درخواستِ کد', 'int'], 'sess_ttl_min' => ['مهلتِ نشستِ بی‌فعالیت (دقیقه)', 'int'], 'base_url' => ['نشانیِ سایت', 'text']];
    $v = []; foreach ($k as $n => $f) $v[$n] = setting($n, ''); echo ui_card('تنظیماتِ سند و فروش', $ro ? '' : ui_form('settings_save', $k, $v));
} else {
    $h = ''; foreach (features_builtin() as $k => [$t, $g, $def]) { $on = feature_on($k); $h .= '<tr><td>' . h($g) . '</td><td>' . h($t) . '</td><td>' . ($on ? ui_badge('روشن', 'ok') : ui_badge('خاموش')) . '</td><td>' . (can('ext.manage') ? ui_btn_api($on ? 'خاموش' : 'روشن', 'feature_toggle', ['key' => $k, 'on' => $on ? 0 : 1], ['class' => 'sm']) : '') . '</td></tr>'; }
    echo ui_card('امکاناتِ هسته', '<table class="tbl"><tr><th>گروه</th><th>امکان</th><th>وضعیت</th><th></th></tr>' . $h . '</table>');
}
