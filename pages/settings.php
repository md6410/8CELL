<?php
$tab = $_GET['tab'] ?? 'biz'; $ro = !can('settings.edit');
echo ui_tabs(['biz' => 'پروفایلِ کسب‌وکار', 'fiscal' => 'سالِ مالی و قفلِ ماه', 'inv' => 'فاکتور و سند', 'doc' => 'فروش و صندوق', 'features' => 'امکانات'], $tab, 'settings');
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
    $k = ['reserve_minutes' => ['زمانِ رزروِ سبد (دقیقه)', 'int'],
        'pos_require_unit' => ['برای کالای دانه‌دار اسکنِ دانه الزامی است', 'select', 0, ['0' => 'خیر', '1' => 'بله']], 'shortage_to_staff' => ['کسریِ صندوق به حسابِ صندوق‌دار', 'select', 0, ['1' => 'بله', '0' => 'خیر (هزینه)']],
        'staff_debt_pct' => ['سقفِ بدهیِ کارمند (٪ حقوق)', 'float'], 'staff_debt_cap' => ['سقفِ بدهیِ کارمندِ بی‌حقوق (ریال)', 'money'], 'loss_sms_confirm' => ['تأییدِ پیامکیِ ضرر', 'select', 0, ['0' => 'خیر', '1' => 'بله']],
        'channel_price_tolerance' => ['تحملِ اختلافِ قیمتِ کانال (ریال)', 'int'], 'scan_sound' => ['صدای اسکن', 'select', 0, ['1' => 'روشن', '0' => 'خاموش']], 'captcha_after' => ['کپچا پس از چند درخواستِ کد', 'int'], 'sess_ttl_min' => ['مهلتِ نشستِ بی‌فعالیت (دقیقه)', 'int'], 'base_url' => ['نشانیِ سایت', 'text'], 'own_seller_code' => ['کدِ فروشندهٔ خودِ فروشگاه در کدِ محموله (۱ تا ۴ نویسهٔ لاتین؛ پیش‌فرض OWN)', 'text']];
    $v = []; foreach ($k as $n => $f) $v[$n] = setting($n, ''); echo ui_card('تنظیماتِ سند و فروش', $ro ? '' : ui_form('settings_save', $k, $v));
} elseif ($tab === 'inv') {
    $yn = ['1' => 'بله', '0' => 'خیر'];
    $k = ['doc_template' => ['قالبِ چاپ', 'select', 1, doc_templates()], 'doc_color' => ['رنگِ سند', 'color'], 'doc_font_size' => ['اندازهٔ قلم (pt)', 'float'], 'doc_digits' => ['ارقام', 'select', 1, ['fa' => 'فارسی', 'en' => 'لاتین']],
        'invoice_title' => ['عنوانِ فاکتور', 'text'], 'proforma_title' => ['عنوانِ پیش‌فاکتور', 'text'], 'off_title' => ['عنوانِ صورتحسابِ رسمی', 'text'], 'doc_header_text' => ['شعار/متنِ زیرِ نام', 'text'],
        'doc_pat_invoice' => ['الگوی شمارهٔ فاکتور ({Y} {y} {M} {D} {N})', 'text'], 'doc_pat_proforma' => ['الگوی شمارهٔ پیش‌فاکتور', 'text'], 'doc_pat_official' => ['الگوی شمارهٔ رسمی', 'text'], 'doc_pat_credit' => ['الگوی شمارهٔ برگشت', 'text'],
        'doc_pad' => ['طولِ شماره (صفرِ پیشین)', 'int'], 'doc_start' => ['شمارهٔ آغاز', 'int'], 'doc_reset_yearly' => ['شروعِ دوباره در هر سال', 'select', 1, $yn],
        'proforma_valid_days' => ['اعتبارِ پیش‌فرضِ پیش‌فاکتور (روز)', 'int'], 'proforma_gap_days' => ['فاصلهٔ پیش‌فاکتور تا فاکتور (روز)', 'int'], 'doc_vat_on' => ['مالیاتِ پیش‌فرض روشن', 'select', 1, $yn],
        'doc_tax_round' => ['گردکردنِ مالیاتِ سند', 'select', 1, ['round' => 'نزدیک‌ترین (اینورا)', 'floor' => 'رو به پایین', 'ceil' => 'رو به بالا']],
        'doc_total_round' => ['گردکردنِ جمعِ نهایی', 'select', 1, ['off' => 'خاموش', 'fx' => 'فقط سندِ دارای قیمتِ دلاری', 'always' => 'همیشه']], 'fx_round' => ['واحدِ گردکردن (ریال)', 'int'], 'doc_total_round_dir' => ['جهت', 'select', 1, ['down' => 'رو به پایین', 'near' => 'نزدیک‌ترین']],
        'doc_show_seller' => ['مشخصاتِ فروشنده', 'select', 1, $yn], 'doc_show_bank' => ['حسابِ بانکی', 'select', 1, $yn], 'doc_show_bank_pf' => ['حسابِ بانکی روی پیش‌فاکتور', 'select', 1, $yn], 'doc_show_stamp' => ['مهرِ پیش‌فرض', 'select', 1, $yn], 'doc_show_sig' => ['امضای پیش‌فرض', 'select', 1, $yn],
        'doc_show_watermark' => ['نقشِ «پیش‌فاکتور»', 'select', 1, $yn], 'doc_show_validity' => ['خطِ اعتبارِ پیش‌فاکتور', 'select', 1, $yn], 'doc_show_words' => ['مبلغ به حروف', 'select', 1, $yn],
        'vf_on' => ['QRِ تأییدِ اصالت', 'select', 1, $yn], 'vf_name' => ['رابطِ پیش‌فرضِ اصالت (نام)', 'text'], 'vf_mobile' => ['رابطِ پیش‌فرضِ اصالت (موبایل)', 'text'], 'doc_default_notes' => ['توضیحاتِ پیش‌فرض', 'textarea'], 'doc_default_terms' => ['شرایطِ پیش‌فرض', 'textarea']];
    $v = []; foreach ($k as $n => $x) $v[$n] = doc_cfg($n);
    $mk = ['moadian_on' => ['ارسال به سامانهٔ مودیان', 'select', 1, $yn], 'moadian_base' => ['نشانیِ API سامانه', 'ltr'], 'moadian_cert' => ['گواهیِ امضا (PEM)', 'textarea'],
        'moadian_secret_key' => [setting('moadian_secret_key') ? 'کلیدِ خصوصی (ثبت شده؛ برای تعویض، کلیدِ تازه را بچسبانید)' : 'کلیدِ خصوصی (PEM)', 'textarea'],
        'moadian_units' => ['کدِ واحدها در سامانه («نام=کد» در هر خط)', 'textarea'], 'moadian_sstid_default' => ['شناسهٔ ۱۳ رقمیِ پیش‌فرضِ کالا/خدمت', 'ltr']];
    $mv = ['moadian_secret_key' => '']; foreach ($mk as $n => $x) if ($n !== 'moadian_secret_key') $mv[$n] = moadian_cfg($n) ?? setting($n, '');
    $moadianCard = ui_card('سامانهٔ مودیان', '<p class="muted">شناسهٔ یکتای حافظه و کدِ اقتصادی از «پروفایلِ کسب‌وکار» خوانده می‌شوند. کلید و گواهی را از کارپوشهٔ مالیاتی بگیرید؛ کلیدِ خصوصی هرگز نمایش داده نمی‌شود. برای آزمون، نشانیِ محیطِ آزمایشیِ سامانه را بگذارید. کدِ واحدها را با جدولِ رسمیِ سامانه تطبیق دهید.</p>'
        . ($ro ? '' : ui_form('settings_save', $mk, $mv)));
    echo ui_card('فاکتور و سند (همان تنظیماتِ اینورا)', $ro ? '' : ui_form('settings_save', $k, $v) . '<p class="muted">نمونهٔ شمارهٔ بعدیِ فاکتور: <b dir="ltr">' . h(fa_num(peek_seq('invoice', today()))) . '</b></p>');
    echo $moadianCard;
} else {
    $h = ''; foreach (features_builtin() as $k => [$t, $g, $def]) { $on = feature_on($k); $h .= '<tr><td>' . h($g) . '</td><td>' . h($t) . '</td><td>' . ($on ? ui_badge('روشن', 'ok') : ui_badge('خاموش')) . '</td><td>' . (can('ext.manage') ? ui_btn_api($on ? 'خاموش' : 'روشن', 'feature_toggle', ['key' => $k, 'on' => $on ? 0 : 1], ['class' => 'sm']) : '') . '</td></tr>'; }
    echo ui_card('امکاناتِ هسته', '<table class="tbl"><tr><th>گروه</th><th>امکان</th><th>وضعیت</th><th></th></tr>' . $h . '</table>');
}
