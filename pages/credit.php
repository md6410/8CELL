<?php
// حساب‌های نسیهٔ مشتری و همکار + قول‌های پرداخت + یادآوری
$who = in_array($_GET['tab'] ?? '', ['customer', 'partner'], true) ? $_GET['tab'] : '';
echo ui_tabs(['' => 'همه', 'customer' => 'مشتریان', 'partner' => 'همکاران'], $who, 'credit');
$acc = credit_accounts($who); $sum = array_sum(array_column($acc, 'bal')); $late = array_filter($acc, fn($r) => $r['late_days'] > 0);
echo '<div class="stats">' . ui_stat('کلِ طلب', $sum) . ui_stat('تعدادِ بدهکار', count($acc), false) . ui_stat('دیرکرد', count($late), false) . ui_stat('چکِ در جریان', array_sum(array_column($acc, 'cheques'))) . '</div>';
echo ui_card('بدهکاران', ui_table(['name' => ['نام', fn($r) => h($r['name']) . ($r['is_partner'] ? ' ' . ui_badge('همکار', 'warn') : '')], 'mobile' => ['موبایل', 'num'], 'bal' => ['بدهی', 'money'], 'cheques' => ['چکِ در جریان', 'money'],
    'due' => ['موعد', fn($r) => $r['due'] ? jdate($r['due']) . ($r['promise_due'] ? ' (قول)' : '') : '—'], 'late_days' => ['دیرکرد', fn($r) => $r['late_days'] ? ui_badge(fa_num((string)$r['late_days']) . ' روز', 'err') : '']],
    $acc, ['filter' => 1, 'href' => fn($r) => page_url('customer', ['id' => $r['id']]), 'empty' => 'بدهکاری نیست.']));
$ps = promises_open();
echo ui_card('قول‌های پرداختِ باز', ui_table(['name' => 'نام', 'amount' => ['مبلغ', 'money'], 'due_date' => ['موعد', fn($r) => jdate($r['due_date']) . ($r['due_date'] < today() ? ' ' . ui_badge('گذشته', 'err') : ($r['due_date'] === today() ? ' ' . ui_badge('امروز', 'warn') : ''))],
    'note' => 'یادداشت', 'notif' => ['اطلاع به مشتری', fn($r) => ($r['sms_customer'] ? 'پیامک ' : '') . ($r['bale_customer'] ? 'بله' : '') ?: '—'],
    'a' => ['', fn($r) => can('customers.credit') ? ui_btn_api('عمل شد', 'promise_set', ['id' => $r['id'], 'status' => 'kept'], ['class' => 'sm']) . ' ' . ui_btn_api('عمل نشد', 'promise_set', ['id' => $r['id'], 'status' => 'broken'], ['class' => 'sm']) . ' ' . ui_btn_api('لغو', 'promise_set', ['id' => $r['id'], 'status' => 'canceled'], ['class' => 'sm', 'confirm' => 'لغو شود؟']) : '']],
    $ps, ['empty' => 'قولِ بازی نیست. قولِ پرداخت را از صفحهٔ هر مشتری ثبت کنید.']));
$last = setting('notify_last_run'); echo '<p class="muted">یادآوری‌ها با cron (index.php?p=cron&k=…) خودکار اجرا می‌شوند؛ آخرین اجرا: ' . ($last ? jdate((string)$last, true) : 'هنوز نه') . '. '
    . (can('notify.alerts') || can('settings.edit') ? ui_btn_api('اجرای یادآوری‌ها الان', 'notify_run', [], ['class' => 'sm']) : '') . '</p>';
