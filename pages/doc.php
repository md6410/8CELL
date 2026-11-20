<?php
// سند: ویرایشگرِ پیش‌نویس، نمای سندِ ثبت‌شده با همهٔ عملیاتِ اینورا (تبدیل، برگشت، اصلاح/نسخه، تأییدِ خریدار، لینکِ رمزدار، QRِ اصالت)
$id = req_int('id'); $d = $id ? doc_get($id) : null;
if ($id && !$d) throw new UserError('سند یافت نشد');
$editing = !$d || $d['status'] === 'draft' || req_int('amend');
$banks = ui_opts("SELECT id, title name FROM bank_accounts WHERE active=1");
$docAssets = '<link rel="stylesheet" href="assets/doc.css?v=' . APP_VERSION . '"><style>.docpv{background:#e9ebe9;padding:12px;overflow:auto;display:flex;flex-direction:column;align-items:center;gap:12px;border-radius:10px}.docpv .paper{box-shadow:0 2px 10px rgba(0,0,0,.15);flex:none}</style>' . doc_assets_html();

if ($d && !$editing) {
    [$stl, $stk] = doc_status($d); $ps = doc_pay_state($d); $lc = doc_link_check($d);
    $act = '<a class="btn sm" target="_blank" href="index.php?p=print_doc&id=' . $d['id'] . '">چاپ / PDF</a>';
    if ($d['status'] === 'issued') {
        if (in_array($d['kind'], ['proforma', 'invoice'], true) && can('docs.edit')) $act .= ' ' . ui_btn_api($d['kind'] === 'proforma' ? 'تبدیل به فاکتور' : 'ساختنِ پیش‌فاکتور', 'doc_convert', ['id' => $d['id']], ['class' => 'sm', 'then' => 'go:index.php?p=doc&id={id}']);
        if (can('docs.edit')) $act .= ' ' . ui_btn_api('سندِ تازه از روی این', 'doc_clone', ['id' => $d['id']], ['class' => 'sm', 'then' => 'go:index.php?p=doc&id={id}']);
        if (can('docs.issue') && !$d['confirmed']) $act .= ' <a class="btn sm" href="' . h(page_url('doc', ['id' => $d['id'], 'amend' => 1])) . '">نسخهٔ اصلاحی</a> ' . ui_btn_api('ابطال', 'doc_void', ['id' => $d['id']], ['class' => 'sm danger', 'prompt' => 'reason:دلیلِ ابطال']);
        if (acc_confirmable($d) && !$d['confirmed'] && can('docs.issue')) $act .= ' ' . ui_btn_api('تأییدِ خریدار (قفلِ همیشگی)', 'doc_confirm', ['id' => $d['id']], ['class' => 'sm', 'prompt' => 'note:تأییدِ خریدار — پس از این، سند دیگر هرگز تغییر نمی‌کند و حذف نمی‌شود. توضیح (مثلاً تأییدِ تلفنی، امضای نسخهٔ کاغذی):']);
    }
    $kv = '<dl class="kv"><dt>وضعیت</dt><dd>' . ui_badge($stl, $stk) . ($d['confirmed'] ? ' ' . ui_badge('تأییدِ خریدار ' . jdate($d['confirmed_at']), 'ok') . ($d['confirm_note'] ? ' <small>' . h($d['confirm_note']) . '</small>' : '') : '') . ' · نسخهٔ ' . fa_num($d['revision']) . '</dd>'
        . '<dt>صدور</dt><dd>' . jdate($d['issued_at'], true) . ' — ' . h((string)val("SELECT name FROM users WHERE id=?", [$d['issued_by'] ?: $d['created_by']])) . '</dd>'
        . (acc_confirmable($d) ? '<dt>پرداخت</dt><dd>' . h($ps['label']) . ' — مانده ' . ui_money($ps['remain']) . ' ریال</dd>' : '')
        . ($d['sale_id'] ? '<dt>فروش</dt><dd><a href="' . h(page_url('sale', ['id' => $d['sale_id']])) . '">' . h(fa_num((string)val("SELECT number FROM sales WHERE id=?", [$d['sale_id']]))) . '</a></dd>' : '')
        . ($d['credit_ref'] ? '<dt>برگشتِ فاکتورِ</dt><dd><a href="' . h(page_url('doc', ['id' => $d['credit_ref']])) . '">' . h(fa_num((string)val("SELECT number FROM docs WHERE id=?", [$d['credit_ref']]))) . '</a></dd>' : '')
        . ($lc ? '<dt>پیوند</dt><dd class="' . ($lc[0] === 'warn' ? 'err' : 'muted') . '">' . h($lc[1]) . ' <a href="' . h(page_url('doc', ['id' => $d['linked_id']])) . '">باز کن</a></dd>' : '')
        . '</dl>';
    if ($d['kind'] === 'official') {
        $iss = official_issues($d);
        $kv .= '<form class="filters" data-api="doc_moadian" data-then="reload"><input type="hidden" name="id" value="' . $d['id'] . '"><label>سامانهٔ مودیان<select name="state">' . implode('', array_map(fn($k, $v) => '<option value="' . $k . '"' . (($d['moadian'] ?: 'none') === $k ? ' selected' : '') . '>' . h($v) . '</option>', array_keys(moadian_states()), moadian_states())) . '</select></label><label>شمارهٔ منحصربه‌فردِ مالیاتی<input name="tax_id" dir="ltr" value="' . h($d['opts']['tax_id'] ?? '') . '"></label><button class="btn sm">ثبت</button></form>'
            . ($iss['W'] ? '<ul class="muted">' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $iss['W'])) . '</ul>' : '');
        if (moadian_on() && $d['status'] === 'issued' && can('docs.issue')) {
            $mx = '<dl class="kv">' . ($d['tax_id'] ? '<dt>شمارهٔ مالیاتی</dt><dd dir="ltr">' . h($d['tax_id']) . '</dd>' : '') . ($d['moadian_ref'] ? '<dt>شمارهٔ مرجعِ سامانه</dt><dd dir="ltr">' . h($d['moadian_ref']) . '</dd>' : '')
                . ($d['moadian_at'] ? '<dt>آخرین تبادل</dt><dd>' . jdate($d['moadian_at'], true) . '</dd>' : '') . '</dl>' . ($d['moadian_msg'] ? '<pre class="' . ($d['moadian'] === 'rejected' ? 'err' : 'muted') . '" style="white-space:pre-wrap">' . h($d['moadian_msg']) . '</pre>' : '');
            [$mj, $me] = moadian_invoice($d);
            if ($me && !in_array($d['moadian'], ['sent', 'accepted'], true)) $mx .= '<ul class="err">' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $me)) . '</ul>';
            $mx .= '<div class="actions">' . (!in_array($d['moadian'], ['sent', 'accepted'], true) && !$me ? ui_btn_api('ارسال به سامانهٔ مودیان', 'moadian_send', ['id' => $d['id']], ['class' => 'primary', 'confirm' => 'صورتحساب امضا و به سامانهٔ مودیان فرستاده شود؟']) : '')
                . ($d['moadian_ref'] ? ' ' . ui_btn_api('استعلامِ نتیجه', 'moadian_inquiry', ['id' => $d['id']]) : '') . '</div>';
            $kv .= '<div class="card sub"><h3>اتصالِ سامانهٔ مودیان</h3>' . $mx . '</div>';
        }
    }
    echo ui_card((doc_kinds()[$d['kind']] ?? '') . ' ' . fa_num((string)$d['number']), $kv, $act);
    echo '<div class="cols">';
    if ($d['confirmed'] && $ps['remain'] > 0 && can('acc.share')) echo ui_card('ثبتِ واریز', ui_form('doc_pay', ['amount' => ['مبلغ (ریال)', 'money', 1, [], $ps['remain']], 'd' => ['تاریخ', 'date'], 'ref' => ['شمارهٔ پیگیری', 'text']], [], ['hidden' => ['id' => $d['id']]]));
    // برگشت از فروش
    if ($d['status'] === 'issued' && in_array($d['kind'], ['invoice', 'official'], true) && can('docs.issue') && !$d['sale_id']) {
        $done = doc_credit_returned((int)$d['id']); $h = '<form class="form" data-api="doc_credit" data-then="go:index.php?p=doc&id={id}" data-confirm="سندِ برگشت از فروش صادر شود؟"><input type="hidden" name="id" value="' . $d['id'] . '"><table class="tbl"><tr><th>ردیف</th><th>فروخته</th><th>برگشت‌خورده</th><th>برگشتِ این بار</th></tr>'; $any = false;
        foreach ($d['lines'] as $i => $l) { $left = (float)$l['qty'] - ($done[$i] ?? 0); if ($left <= 0) continue; $any = true; $h .= '<tr><td>' . h($l['name']) . '</td><td>' . doc_qty($l['qty']) . '</td><td>' . doc_qty($done[$i] ?? 0) . '</td><td><input name="q[' . $i . ']" value="0" style="width:70px"></td></tr>'; }
        $h .= '</table><div class="grid">' . ui_field('reason', ['دلیل', 'text', 1]) . ui_field('note', ['توضیح', 'text']) . '</div><div class="actions"><button class="btn">صدورِ برگشت</button></div></form>';
        if ($any) echo ui_card('برگشت از فروش (سندِ جدا؛ فاکتور پاک نمی‌شود)', $h);
    }
    // QRِ اصالت
    if ($d['verify_token']) {
        $lg = rows("SELECT * FROM vf_log WHERE doc_id=? ORDER BY id DESC LIMIT 30", [$d['id']]);
        echo ui_card('QRِ تأییدِ اصالت', '<p class="muted">هر کس QR را اسکن کند، رمزی یک‌بارمصرف به رابطِ سند پیامک می‌شود و تا رمز درست زده نشود هیچ‌چیز از سند نمایش داده نمی‌شود.</p><p><a target="_blank" dir="ltr" href="' . h(vf_url($d['verify_token'])) . '">' . h(vf_url($d['verify_token'])) . '</a></p>'
            . ui_form('doc_contact', ['name' => ['نامِ رابط', 'text'], 'mobile' => ['موبایلِ رابط (گیرندهٔ رمز)', 'text']], ['name' => $d['contact_name'], 'mobile' => $d['contact_mobile']], ['hidden' => ['id' => $d['id']], 'submit' => 'ذخیرهٔ رابط'])
            . ui_table(['at' => ['زمان', 'datetime'], 'event' => ['رویداد', fn($r) => h(vf_log_label($r['event']))], 'ip' => ['IP', 'code'], 'ua' => ['دستگاه', fn($r) => '<small>' . h(mb_substr((string)$r['ua'], 0, 60)) . '</small>']], $lg, ['empty' => 'هنوز اسکنی نشده است.']));
    }
    // لینکِ رمزدار
    if ($d['status'] !== 'draft') {
        $sh = rows("SELECT * FROM doc_shares WHERE doc_id=? ORDER BY id DESC", [$d['id']]);
        echo ui_card('لینکِ دریافت با رمز', ui_form('doc_share', ['days' => ['اعتبار (روز؛ ۰ = همیشه)', 'int', 0, [], 30], 'max' => ['سقفِ دفعاتِ دیدن (۰ = بی‌سقف)', 'int', 0, [], 0], 'pin' => ['رمز (خالی = ۵ رقمِ تصادفی)', 'text']], [], ['hidden' => ['id' => $d['id']], 'submit' => 'ساختنِ لینک', 'then' => 'none']) . '<p class="muted">لینک و رمز را جدا بفرستید. ۵ رمزِ اشتباه لینک را قفل می‌کند.</p>'
            . ui_table(['created_at' => ['ساخته', 'datetime'], 'expires_at' => ['تا', 'datetime'], 'views' => ['دیده', 'num'], 'fails' => ['اشتباه', 'num'], 'dead' => ['وضعیت', fn($r) => $r['dead'] ? ui_badge($r['dead'] === 'locked' ? 'قفل' : 'لغو', 'err') : ui_badge('فعال', 'ok')],
                'a' => ['', fn($r) => $r['dead'] ? '' : ui_btn_api('پیامکِ لینک', 'doc_share_sms', ['id' => $r['id']], ['class' => 'sm', 'prompt' => 'mobile:موبایلِ گیرنده', 'then' => 'none']) . ' ' . ui_btn_api('لغو', 'doc_share_revoke', ['id' => $r['id']], ['class' => 'sm danger', 'confirm' => 'لینک غیرفعال شود؟'])]], $sh, ['empty' => '—']));
    }
    $revs = rows("SELECT revision, reason, hash, at, user_id FROM doc_revisions WHERE doc_id=? ORDER BY revision DESC", [$d['id']]);
    echo ui_card('نسخه‌ها', ui_table(['revision' => ['نسخه', 'num'], 'at' => ['زمان', 'datetime'], 'reason' => 'دلیل', 'hash' => ['اثرِ انگشت', fn($r) => '<code>' . h(substr($r['hash'], 0, 16)) . '…</code>'],
        'a' => ['', fn($r) => (int)$r['revision'] < (int)$d['revision'] && $d['status'] === 'issued' && !$d['confirmed'] && can('docs.issue') ? ui_btn_api('بازگردانی', 'doc_restore', ['id' => $d['id'], 'rev' => $r['revision']], ['class' => 'sm', 'confirm' => 'این نسخه به‌عنوانِ نسخهٔ تازه بازگردانده شود؟']) : '']], $revs));
    echo '</div>' . $docAssets . '<div class="docpv">' . doc_render_html($d) . '</div>';
    return;
}
// ── ویرایشگر
$amend = $d && $d['status'] === 'issued';
if ($amend && $d['confirmed']) throw new UserError('خریدار این سند را تأیید کرده؛ دیگر تغییر نمی‌کند');
$kind = $d['kind'] ?? (isset(doc_kinds()[$_GET['kind'] ?? '']) ? $_GET['kind'] : 'invoice');
$o = $d['opts'] ?? []; $b = $d['buyer'] ?? [];
$f = ['kind' => ['نوع', 'select', 1, $amend ? [$kind => doc_kinds()[$kind]] : array_diff_key(doc_kinds(), ['return' => 1])], 'currency' => ['واحدِ پول', 'select', 1, ['IRR' => 'ریال', 'IRT' => 'تومان', 'USD' => 'دلار']], 'd' => ['تاریخ', 'date', 1],
    'number' => ['شمارهٔ دستی (خالی = خودکار)', 'text'], 'valid_days' => ['اعتبارِ پیش‌فاکتور (روز)', 'int'],
    'customer_id' => ['', 'hidden'], 'cust' => ['مشتری (جست‌وجو؛ یا خریدارِ دستی)', 'text'],
    'buyer_kind' => ['نوعِ خریدار', 'select', 0, buyer_kinds()], 'buyer_name' => ['نامِ خریدار', 'text'], 'buyer_national_id' => ['شناسه/کدِ ملی', 'text'], 'buyer_economic_code' => ['شمارهٔ اقتصادی', 'text'], 'buyer_reg_no' => ['شمارهٔ ثبت', 'text'],
    'buyer_postal' => ['کدِ پستی', 'text'], 'buyer_province' => ['استان', 'text'], 'buyer_city' => ['شهر', 'text'], 'buyer_phone' => ['تلفن', 'text'], 'buyer_mobile' => ['موبایل', 'text'], 'buyer_address' => ['نشانی', 'text'],
    'doc_disc' => ['تخفیفِ کل', 'float'], 'doc_disc_type' => ['نوعِ تخفیفِ کل', 'select', 1, ['amount' => 'مبلغ', '%' => 'درصد']], 'vat_on' => ['مالیات بر ارزش افزوده دارد', 'check'], 'vat_pct' => ['نرخِ مالیات ٪', 'float'],
    'extra_label' => ['عنوانِ «سایر هزینه‌ها»', 'text'], 'extra' => ['مبلغِ سایر', 'float'], 'settle' => ['نحوهٔ فروش', 'select', 1, ['cash' => 'نقدی', 'credit' => 'غیرنقدی', 'both' => 'نقدی و غیرنقدی']],
    'stamp_on' => ['مهر روی سند', 'check'], 'sig_on' => ['امضا روی سند', 'check'], 'bank_ids' => ['حساب‌های بانکیِ روی سند', 'multi', 0, $banks], 'contact_name' => ['رابطِ اصالت (نام)', 'text'], 'contact_mobile' => ['رابطِ اصالت (موبایل)', 'text'],
    'notes' => ['توضیحات', 'textarea'], 'terms' => ['شرایط و ملاحظات', 'textarea']];
if ($kind === 'official') $f += ['off_kind' => ['نوعِ صورتحساب', 'select', 1, ['type1' => 'نوعِ اول (خریدارِ مشخص)', 'type2' => 'نوعِ دوم (مصرف‌کنندهٔ نهایی)']], 'subject' => ['موضوع', 'select', 1, doc_subjects()],
    'ref_doc_id' => ['صورتحسابِ مرجع (برای اصلاحی/ابطالی/برگشت)', 'select', 0, ui_opts("SELECT id, CONCAT(number,' — ',DATE_FORMAT(d,'%Y-%m-%d')) name FROM docs WHERE kind='official' AND status='issued' ORDER BY id DESC LIMIT 200")], 'tax_id' => ['شمارهٔ منحصربه‌فردِ مالیاتی (۲۲ نویسه)', 'text']];
if ($amend) $f = ['reason' => ['دلیلِ اصلاح (در نسخه‌ها ثبت می‌شود)', 'text', 1]] + array_diff_key($f, ['kind' => 1, 'number' => 1]);
$vals = ['kind' => $kind, 'currency' => $d['currency'] ?? 'IRR', 'd' => $d['d'] ?? today(), 'number' => $d && $d['manual_number'] ? $d['number'] : '', 'valid_days' => $o['valid_days'] ?? doc_cfg('proforma_valid_days'),
    'customer_id' => $d['customer_id'] ?? '', 'cust' => $d && $d['customer_id'] ? ($b['name'] ?? '') : '', 'buyer_kind' => $b['kind'] ?? 'legal', 'buyer_name' => $d && !$d['customer_id'] ? ($b['name'] ?? '') : '', 'buyer_national_id' => $b['national_id'] ?? '',
    'buyer_economic_code' => $b['economic_code'] ?? '', 'buyer_reg_no' => $b['reg_no'] ?? '', 'buyer_postal' => $b['postal_code'] ?? '', 'buyer_province' => $b['province'] ?? '', 'buyer_city' => $b['city'] ?? '', 'buyer_phone' => $b['phone'] ?? '', 'buyer_mobile' => $b['mobile'] ?? '', 'buyer_address' => $b['address'] ?? '',
    'doc_disc' => $o['doc_disc'] ?? '', 'doc_disc_type' => $o['doc_disc_type'] ?? 'amount', 'vat_on' => $o['vat_on'] ?? doc_cfg('doc_vat_on'), 'vat_pct' => $o['vat_pct'] ?? vat_pct(), 'extra_label' => $o['extra_label'] ?? '', 'extra' => $o['extra'] ?? '', 'settle' => $o['settle'] ?? 'cash',
    'stamp_on' => $o['stamp_on'] ?? doc_cfg('doc_show_stamp'), 'sig_on' => $o['sig_on'] ?? doc_cfg('doc_show_sig'), 'bank_ids' => $o['bank_ids'] ?? [], 'contact_name' => $d['contact_name'] ?? '', 'contact_mobile' => $d['contact_mobile'] ?? '',
    'notes' => $o['notes'] ?? doc_cfg('doc_default_notes'), 'terms' => $o['terms'] ?? doc_cfg('doc_default_terms'), 'off_kind' => $o['off_kind'] ?? 'type1', 'subject' => $d['subject'] ?? 'main', 'ref_doc_id' => $d['ref_doc_id'] ?? '', 'tax_id' => $o['tax_id'] ?? ''];
$ed = '<h3>ردیف‌ها</h3><div id="docEd" data-adjround="' . (int)setting('adj_round', '1000') . '" data-units="' . h(json_encode(doc_units(), JSON_UNESCAPED_UNICODE)) . '" data-rows="' . h(json_encode($d['lines'] ?? [], JSON_UNESCAPED_UNICODE)) . '"><div class="tw"><table class="tbl"><thead><tr><th>شرح</th><th>شناسهٔ کالا</th><th>مقدار</th><th>واحد</th><th>مبلغِ واحد</th><th>تخفیف</th><th>مالیات</th><th>دلار / افزایش٪</th><th></th></tr></thead><tbody></tbody></table></div><button type="button" class="btn sm add">+ ردیف</button></div><p id="calc" class="muted"></p>';
echo ui_card($amend ? 'نسخهٔ اصلاحیِ ' . fa_num($d['number']) : ($d ? 'پیش‌نویسِ ' . (doc_kinds()[$kind] ?? '') : (doc_kinds()[$kind] ?? 'سند') . 'ِ تازه'),
    ui_form($amend ? 'doc_amend' : 'doc_save', $f, $vals, ['hidden' => ($d ? ['id' => $d['id']] : []) + ['_form' => 1], 'extra' => $ed, 'then' => $amend ? 'go:' . page_url('doc', ['id' => $d['id']]) : 'go:index.php?p=doc&id={id}', 'submit' => $amend ? 'ثبتِ نسخهٔ اصلاحی' : 'ذخیرهٔ پیش‌نویس']));
echo '<script src="assets/doc-editor.js?v=' . APP_VERSION . '" defer></script>';
if ($d && $d['status'] === 'draft') {
    $iss = $d['kind'] === 'official' ? official_issues($d) : ['E' => [], 'W' => []];
    $acts = (can('docs.issue') ? ui_btn_api('ثبتِ نهایی (قفل و شماره)', 'doc_commit', ['id' => $d['id']], ['class' => 'primary', 'confirm' => 'پس از ثبت، سند قفل می‌شود. ادامه؟']) : '')
        . ($d['kind'] === 'invoice' && can('docs.edit') ? ' ' . ui_btn_api('تبدیل به صورتحسابِ رسمی', 'doc_official', ['id' => $d['id']], ['class' => '', 'confirm' => 'مبالغ به ریال برمی‌گردد و «سایر» صفر می‌شود. ادامه؟']) : '');
    echo ui_card('ثبت و پیش‌نمایش', ($iss['E'] ? '<ul class="err">' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $iss['E'])) . '</ul>' : '') . ($iss['W'] ? '<ul class="muted">' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $iss['W'])) . '</ul>' : '') . '<div class="actions">' . $acts . '</div>');
    echo $docAssets . '<div class="docpv">' . doc_render_html($d) . '</div>';
}
