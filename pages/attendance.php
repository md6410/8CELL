<?php
$u = current_user(); $me = $u['employee_id'] ? row("SELECT * FROM employees WHERE id=?", [$u['employee_id']]) : null;
if ($me) { $t = row("SELECT * FROM attendance WHERE employee_id=? AND d=?", [$me['id'], today()]);
    echo ui_card('کارت‌زنیِ من — ' . jdate(today()) . ' ' . jweekday_name(today()), '<p>ورود: ' . h(fa_num($t['t_in'] ?? '—')) . ' · خروج: ' . h(fa_num($t['t_out'] ?? '—')) . '</p>' . ui_btn_api(empty($t['t_in']) ? 'ثبتِ ورود' : 'ثبتِ خروج', 'punch', ['employee_id' => $me['id']], ['class' => 'primary big'])); }
$emps = ui_opts("SELECT id, name FROM employees WHERE active=1 ORDER BY name");
echo ui_card('ثبتِ دستیِ حضور', ui_form('punch', ['employee_id' => ['کارمند', 'select', 1, $emps], 'at' => ['تاریخ', 'date', 1], 'time' => ['ساعت (۰۸:۰۵)', 'text', 1]], [], ['submit' => 'ثبت (اولی ورود، بعدی خروج)']));
echo ui_card('حضورِ امروز', ui_table(['name' => 'کارمند', 't_in' => ['ورود', 'num'], 't_out' => ['خروج', 'num'], 'late_min' => ['تأخیر (دقیقه)', 'num'], 'over_min' => ['اضافه‌کار', 'num']], rows("SELECT a.*, e.name FROM attendance a JOIN employees e ON e.id=a.employee_id WHERE a.d=? ORDER BY e.name", [today()])));
$r = hr_rules(); echo ui_card('قاعدهٔ حضور', can('settings.edit') ? ui_form('settings_save', ['hr_rules[start]' => ['شروعِ کار', 'text'], 'hr_rules[hours]' => ['ساعتِ کارِ روزانه', 'float'], 'hr_rules[grace_min]' => ['ارفاقِ تأخیر (دقیقه)', 'int'], 'hr_rules[leave_days_year]' => ['مرخصیِ استحقاقیِ سالانه (روز)', 'float'], 'hr_rules[month_hours]' => ['ساعتِ کارِ ماه (برای نرخِ ساعتی)', 'int']],
    ['hr_rules[start]' => $r['start'], 'hr_rules[hours]' => $r['hours'], 'hr_rules[grace_min]' => $r['grace_min'], 'hr_rules[leave_days_year]' => $r['leave_days_year'], 'hr_rules[month_hours]' => $r['month_hours']]) : '');
if (can('hr.attendance')) {
    $lv = rows("SELECT l.*, e.name FROM leaves l JOIN employees e ON e.id=l.employee_id WHERE l.status='pending' ORDER BY l.from_d");
    echo ui_card('درخواست‌های مرخصی (' . fa_num((string)count($lv)) . ')', ui_table(['name' => 'کارمند', 'kind' => ['نوع', fn($r) => leave_kinds()[$r['kind']] ?? ''], 'from_d' => ['از', 'date'], 'to_d' => ['تا', 'date'], 'days' => ['روز', 'num'], 'bal' => ['مانده', fn($r) => fa_num((string)round(leave_balance((int)$r['employee_id']), 1))], 'note' => 'توضیح',
        'a' => ['', fn($r) => ui_btn_api('تأیید', 'leave_decide', ['id' => $r['id'], 'ok' => 1], ['class' => 'sm primary']) . ' ' . ui_btn_api('رد', 'leave_decide', ['id' => $r['id'], 'ok' => 0], ['class' => 'sm', 'prompt' => 'note:دلیل'])]], $lv, ['empty' => 'درخواستی نیست.']));
    $sts = rows("SELECT * FROM hr_stations ORDER BY id");
    echo ui_card('ایستگاه‌های کارت‌زنی', '<p class="muted">لینکِ هر ایستگاه را روی سیستم یا تبلتِ محلِ کار باز کنید (اولین دستگاه صاحبش می‌شود). کارکنان QRِ روی آن را با گوشیِ خودشان اسکن می‌کنند؛ کد هر ۳۰ ثانیه عوض می‌شود.</p>'
        . ui_table(['name' => 'ایستگاه', 'device_label' => ['دستگاه', fn($r) => $r['device_id'] ? '<small>' . h(mb_substr((string)$r['device_label'], 0, 60)) . '</small>' : ui_badge('هنوز باز نشده', 'warn')],
            'p' => ['', fn($r) => $r['pending_device'] ? ui_badge('دستگاهِ تازه منتظر', 'warn') . ' ' . ui_btn_api('تأیید (جایگزین)', 'station_approve', ['id' => $r['id']], ['class' => 'sm', 'confirm' => 'این دستگاه جایگزین شود؟']) : ''],
            'a' => ['', fn($r) => '<a class="btn sm" target="_blank" href="' . h(station_url($r)) . '">باز کردن</a> ' . ui_btn_api('لینکِ تازه', 'station_unbind', ['id' => $r['id']], ['class' => 'sm', 'confirm' => 'لینکِ قبلی و دستگاهش باطل شود؟']) . ' ' . ui_btn_api($r['active'] ? 'غیرفعال' : 'فعال', 'station_toggle', ['id' => $r['id']], ['class' => 'sm'])]], $sts, ['empty' => '—'])
        . ui_form('station_create', ['name' => ['نامِ ایستگاه (مثلاً درِ ورودیِ فروشگاه)', 'text', 1]], [], ['submit' => 'ساختنِ ایستگاه']));
}
