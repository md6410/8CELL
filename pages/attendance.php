<?php
$u = current_user(); $me = $u['employee_id'] ? row("SELECT * FROM employees WHERE id=?", [$u['employee_id']]) : null;
if ($me) { $t = row("SELECT * FROM attendance WHERE employee_id=? AND d=?", [$me['id'], today()]);
    echo ui_card('کارت‌زنیِ من — ' . jdate(today()) . ' ' . jweekday_name(today()), '<p>ورود: ' . h(fa_num($t['t_in'] ?? '—')) . ' · خروج: ' . h(fa_num($t['t_out'] ?? '—')) . '</p>' . ui_btn_api(empty($t['t_in']) ? 'ثبتِ ورود' : 'ثبتِ خروج', 'punch', ['employee_id' => $me['id']], ['class' => 'primary big'])); }
$emps = ui_opts("SELECT id, name FROM employees WHERE active=1 ORDER BY name");
echo ui_card('ثبتِ دستیِ حضور', ui_form('punch', ['employee_id' => ['کارمند', 'select', 1, $emps], 'at' => ['تاریخ', 'date', 1], 'time' => ['ساعت (۰۸:۰۵)', 'text', 1]], [], ['submit' => 'ثبت (اولی ورود، بعدی خروج)']));
echo ui_card('حضورِ امروز', ui_table(['name' => 'کارمند', 't_in' => ['ورود', 'num'], 't_out' => ['خروج', 'num'], 'late_min' => ['تأخیر (دقیقه)', 'num'], 'over_min' => ['اضافه‌کار', 'num']], rows("SELECT a.*, e.name FROM attendance a JOIN employees e ON e.id=a.employee_id WHERE a.d=? ORDER BY e.name", [today()])));
$r = hr_rules(); echo ui_card('قاعدهٔ حضور', can('settings.edit') ? ui_form('settings_save', ['hr_rules[start]' => ['شروعِ کار', 'text'], 'hr_rules[hours]' => ['ساعتِ کارِ روزانه', 'float'], 'hr_rules[grace_min]' => ['ارفاقِ تأخیر (دقیقه)', 'int'], 'hr_rules[leave_days_year]' => ['مرخصیِ استحقاقیِ سالانه (روز)', 'float'], 'hr_rules[month_hours]' => ['ساعتِ کارِ ماه (برای نرخِ ساعتی)', 'int']],
    ['hr_rules[start]' => $r['start'], 'hr_rules[hours]' => $r['hours'], 'hr_rules[grace_min]' => $r['grace_min'], 'hr_rules[leave_days_year]' => $r['leave_days_year'], 'hr_rules[month_hours]' => $r['month_hours']]) : '');
