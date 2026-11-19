<?php $f = cw_followup();
echo ui_card('تماس‌های امروز', ui_table(['name' => 'مشتری', 'mobile' => ['موبایل', 'num'], 'next_at' => ['موعد', 'date'], 'uname' => 'ثبت‌کننده', 'note' => 'یادداشت'], $f['calls'], ['href' => fn($r) => page_url('customer', ['id' => $r['customer_id']]), 'empty' => 'تماسی برای امروز نیست.']));
echo ui_card('بدهی‌های سررسیده', ui_table(['name' => 'مشتری', 'mobile' => ['موبایل', 'num'], 'bal' => ['بدهی', 'money']], $f['dues'], ['href' => fn($r) => page_url('customer', ['id' => $r['customer_id']]), 'empty' => 'بدهیِ سررسیده‌ای نیست.']));
