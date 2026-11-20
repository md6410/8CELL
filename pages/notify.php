<?php
// هشدارها: اتصالِ هر کاربر به بله، تنظیمِ بات و یادآوری‌ها، دفترِ هشدارها
$u = current_user(); $me = row("SELECT bale_chat_id, bale_code, mobile FROM users WHERE id=?", [$u['id']]);
$h = '<p>گیرندهٔ هشدارها: هر کاربری که توانایی «دریافتِ هشدارها» دارد' . (can('notify.alerts') ? ' — <b>شما هم</b>.' : ' — <b>شما ندارید</b> (از مدیر بخواهید).') . '</p>';
if (!bale_on()) $h .= '<p class="warn">بات بله هنوز تنظیم نشده است' . (can('settings.edit') ? ' (پایینِ همین صفحه).' : '.') . '</p>';
elseif ($me['bale_chat_id']) $h .= '<p class="ok">بله وصل است.</p><div class="actions">' . ui_btn_api('پیامِ آزمایشی', 'bale_test', [], ['class' => 'sm']) . ' ' . ui_btn_api('قطعِ اتصال', 'bale_unlink', [], ['class' => 'sm', 'confirm' => 'قطع شود؟']) . '</div>';
else { $code = $me['bale_code'] ?: ''; $h .= '<p>برای گرفتنِ هشدار در بله: ۱) دکمهٔ زیر را بزنید ۲) کد را برای باتِ ' . h('@' . trim((string)setting('bale_bot_username', '…'), '@')) . ' بفرستید ۳) چند دقیقه بعد (اجرای یادآوری‌ها) وصل می‌شوید.</p>'
    . ($code ? '<p>کدِ شما: <b dir="ltr">' . h($code) . '</b>' . (bale_link_url($code) ? ' — <a target="_blank" href="' . h(bale_link_url($code)) . '">بازکردنِ بات</a>' : '') . '</p>' : '') . ui_btn_api('گرفتنِ کدِ اتصال', 'bale_link', [], ['class' => 'sm', 'then' => 'reload']); }
$h .= '<p class="muted">پیامکِ هشدار به موبایلِ کاربر (' . h(fa_num((string)($me['mobile'] ?: 'ثبت‌نشده'))) . ') با قالب‌های «هشدارِ موعدِ قولِ پرداخت»، «هشدارِ سررسیدِ چک» و «خلاصهٔ کالاهای رو به اتمام» در تنظیماتِ پیامک.</p>';
echo ui_card('بله و هشدارهای من', $h);
if (can('settings.edit')) {
    $yn = ['1' => 'بله', '0' => 'خیر'];
    $k = ['bale_bot_token' => [setting('bale_bot_token') ? 'توکنِ بات (ثبت شده؛ برای تعویض توکنِ تازه)' : 'توکنِ باتِ بله (از @BotFather در بله)', 'password'], 'bale_bot_username' => ['نامِ کاربریِ بات (بی @)', 'ltr'],
        'notify_bale' => ['هشدار در بله', 'select', 1, $yn], 'notify_sms' => ['هشدار با پیامک', 'select', 1, $yn], 'notify_stock' => ['خلاصهٔ روزانهٔ کالاهای رو به اتمام', 'select', 1, $yn],
        'credit_remind_days' => ['یادآوریِ قولِ پرداخت، چند روز پیش', 'int'], 'cheque_remind_days' => ['یادآوریِ سررسیدِ چک، چند روز پیش', 'int'], 'stock_cover_days' => ['«رو به اتمام» یعنی کمتر از چند روز فروش', 'int'], 'adj_round' => ['گردکردنِ قیمت در «٪ همهٔ اقلام» (ریال)', 'int']];
    $v = ['bale_bot_token' => '', 'bale_bot_username' => setting('bale_bot_username', ''), 'notify_bale' => setting('notify_bale', '1'), 'notify_sms' => setting('notify_sms', '1'), 'notify_stock' => setting('notify_stock', '1'),
        'credit_remind_days' => setting('credit_remind_days', '1'), 'cheque_remind_days' => setting('cheque_remind_days', '2'), 'stock_cover_days' => setting('stock_cover_days', '7'), 'adj_round' => setting('adj_round', '1000')];
    $cron = rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/index.php?p=cron&k=' . (setting('cron_key') ? '…' : '(کلیدِ cron در «به‌روزرسانی»)');
    echo ui_card('تنظیمِ هشدارها', ui_form('settings_save', $k, $v) . '<p class="muted">یادآوری‌ها با همان cronِ به‌روزرسانی هر چند دقیقه اجرا می‌شوند: <code dir="ltr">' . h($cron) . '</code> — هر هشدار برای هر نفر فقط یک بار می‌رود.</p>' . ui_btn_api('اجرای یادآوری‌ها الان', 'notify_run', [], ['class' => 'sm']));
}
if (can('notify.alerts') || can('settings.edit')) echo ui_card('آخرین هشدارها', ui_table(['at' => ['زمان', 'datetime'], 'channel' => ['کانال', fn($r) => $r['channel'] === 'bale' ? 'بله' : 'پیامک'], 'target' => ['گیرنده', 'code'], 'ok' => ['نتیجه', fn($r) => $r['ok'] ? ui_badge('رفت', 'ok') : ui_badge('نرفت', 'err') . ' <small>' . h((string)$r['msg']) . '</small>'], 'text' => ['متن', fn($r) => h(mb_substr((string)$r['text'], 0, 120))]],
    rows("SELECT * FROM notify_log ORDER BY id DESC LIMIT 100"), ['empty' => 'هنوز هشداری نرفته است.']));
