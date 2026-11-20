<?php
// پنلِ فروشنده — فقط دادهٔ خودش
$s = seller_me(); $tab = in_array($_GET['tab'] ?? '', ['stock', 'sold', 'statement', 'profile'], true) ? $_GET['tab'] : 'stock';
echo ui_tabs(['stock' => 'موجودیِ من نزدِ فروشگاه', 'sold' => 'فروش‌رفته‌ها', 'statement' => 'صورت‌حساب و پرداخت‌ها', 'profile' => 'پرونده و قرارداد'], $tab, 'seller');
$bal = supplier_balance((int)$s['id']); $st = seller_stock((int)$s['id']);
echo '<div class="stats">' . ui_stat('طلبِ شما از فروشگاه', $bal) . ui_stat('تعدادِ کالا نزدِ ما', array_sum(array_column($st, 'on_hand')), false) . ui_stat('ارزشِ موجودی', array_sum(array_map(fn($r) => $r['on_hand'] * $r['unit_cost'], $st))) . '</div>';
$ct = trim((string)setting('seller_contract_text', '')); if ($ct !== '' && !$s['contract_at'] && $tab !== 'profile') echo '<p class="warn">قراردادِ همکاری را هنوز نپذیرفته‌اید — <a href="' . h(page_url('seller', ['tab' => 'profile'])) . '">پرونده و قرارداد</a></p>';
if ($tab === 'stock') echo ui_card('موجودیِ کالاهای شما', ui_table(['name' => ['کالا', fn($r) => h($r['name'] . ($r['vtitle'] ? ' — ' . $r['vtitle'] : ''))], 'wname' => 'انبار', 'ownership' => ['نوع', fn($r) => wh_ownerships()[$r['ownership']] ?? ''], 'on_hand' => ['موجودی', 'num'], 'unit_cost' => ['قیمتِ خرید', 'money']], $st, ['filter' => 1, 'empty' => 'کالایی از شما نزدِ ما نیست.']));
elseif ($tab === 'sold') { $f = report_filters($_GET); echo ui_date_filter('seller', $f, ['tab' => 'sold']); $rs = seller_sold((int)$s['id'], $f['from'], $f['to']);
    echo ui_card('فروش‌رفته‌ها (' . fa_num((string)array_sum(array_column($rs, 'qty'))) . ' عدد، ' . fa_money(array_sum(array_column($rs, 'value'))) . ' ریال به قیمتِ خرید)', ui_table(['d' => ['تاریخ', 'date'], 'number' => ['فروش', 'num'], 'name' => 'کالا', 'qty' => ['تعداد', 'num'], 'unit_cost' => ['قیمتِ خرید', 'money'], 'value' => ['جمع', 'money']], $rs, ['empty' => '—'])); }
elseif ($tab === 'statement') echo ui_card('صورت‌حساب (مثبت = طلبِ شما)', ui_table(['d' => ['تاریخ', 'date'], 't' => 'شرح', 'in' => ['بستانکار', 'money'], 'out' => ['پرداخت/عودت', 'money'], 'bal' => ['مانده', 'money'],
    'a' => ['', fn($r) => !empty($r['pay_id']) && empty($r['confirmed']) ? ui_btn_api('دریافت کردم', 'seller_pay_confirm', ['id' => $r['pay_id']], ['class' => 'sm', 'prompt' => 'note:یادداشت (اختیاری)']) : '']], seller_statement((int)$s['id']), ['empty' => '—']));
else { echo ui_card('پروندهٔ من', ui_form('seller_profile_save', ['iban' => ['شبا (IR…)', 'ltr'], 'card_no' => ['شمارهٔ کارت', 'ltr'], 'bank_name' => ['بانک', 'text'], 'phone' => ['تلفن', 'ltr'], 'email' => ['ایمیل', 'ltr'], 'address' => ['نشانی', 'textarea']], $s, ['then' => 'none'])
        . '<p class="muted">تغییرِ حسابِ بانکی به مدیرِ فروشگاه خبر داده می‌شود.</p>');
    if ($ct !== '') echo ui_card('قراردادِ همکاری', '<div style="white-space:pre-wrap;max-height:50vh;overflow:auto;border:1px solid #e5e7eb;padding:10px;border-radius:8px">' . h($ct) . '</div>'
        . ($s['contract_at'] ? '<p class="ok">پذیرفته شد: ' . jdate($s['contract_at'], true) . '</p>' : ui_btn_api('متن را خواندم و می‌پذیرم', 'seller_contract_accept', [], ['class' => 'primary', 'confirm' => 'قرارداد را می‌پذیرید؟']))); }
