<?php
$f = ['kind' => ['نوع', 'select', 1, ['loss' => 'ضرر/خرابی/گم‌شدن', 'return' => 'عودت به تأمین‌کننده', 'buyout' => 'خریدِ فروشگاه از انبارِ امانیِ فروشنده']], 'code' => ['کدِ دانه یا محموله', 'text', 1], 'qty' => ['تعداد (برای محموله)', 'int', 0, [], 1],
    'bearer' => ['بارِ مالی (کالای امانی)', 'select', 0, ['seller' => 'فروشنده', 'store' => 'فروشگاه']], 'price' => ['قیمتِ خرید (برای خریدِ امانی)', 'money'], 'reason' => ['دلیل', 'textarea', 1]];
if (setting('loss_sms_confirm', '0') === '1') $f['otp'] = ['کدِ تأییدِ پیامکی', 'text', 1];
echo ui_card('ثبتِ ضرر / عودت', (setting('loss_sms_confirm', '0') === '1' ? '<p>' . ui_btn_api('دریافتِ کدِ تأیید', 'otp_confirm', [], ['then' => 'none']) . '</p>' : '') . ui_form('stock_loss', $f, [], ['confirm' => 'ثبت شود؟']));
echo ui_card('سابقه', ui_table(['created_at' => ['زمان', 'datetime'], 'kind' => 'نوع', 'name' => 'کالا', 'qty' => ['تعداد', 'num'], 'amount' => ['مبلغ (بهای خرید)', 'money'], 'bearer' => 'بار', 'reason' => 'دلیل'],
    rows("SELECT l.*, p.name FROM stock_losses l JOIN lots t ON t.id=l.lot_id JOIN products p ON p.id=t.product_id ORDER BY l.id DESC LIMIT 200")));
