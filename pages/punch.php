<?php
// مقصدِ QRِ ایستگاه: کارت‌زنیِ کاربرِ واردشده (با دکمه، تا پیش‌نمایشِ لینک در پیام‌رسان کارت نزند)
$t = (string)($_GET['t'] ?? '');
echo ui_card('کارت‌زنی', '<p>' . h(current_user()['name']) . '، ' . jdate(today()) . ' ساعتِ ' . fa_num(date('H:i')) . '</p>' . ui_form('att_punch_qr', [], [], ['hidden' => ['t' => $t], 'submit' => 'ثبتِ ورود/خروج', 'then' => 'go:' . page_url('my')]));
