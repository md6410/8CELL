<?php
$f = report_filters($_GET); echo ui_date_filter('perf', $f);
$rows = perf_staff_rows($f['from'], $f['to']); $ym = str_replace('/', '-', jym(today()));
foreach ($rows as &$r) { $t = staff_target_progress((int)$r['id'], $ym); $r['target'] = $t['target']; $r['pct'] = $t['pct']; }
echo ui_card('عملکردِ کارکنان و کانال‌ها', ui_table(['rank' => ['رتبه', 'num'], 'label' => 'فروشنده', 'cnt' => ['تعداد', 'num'], 'total' => ['فروش', 'money'], 'avg' => ['میانگینِ سبد', 'money'], 'disc' => ['تخفیفِ داده‌شده', 'money'], 'returns' => ['برگشت', 'money'], 'commission' => ['پورسانت', 'money'], 'pct' => ['هدفِ این ماه', fn($r) => $r['target'] ? fa_num((string)$r['pct']) . '٪ از ' . fa_money($r['target']) : '—']], $rows));
echo ui_card('تعیینِ هدفِ ماه', ui_form('target_set', ['user_id' => ['کارمند', 'select', 1, ui_opts("SELECT id, name FROM users WHERE active=1")], 'ym' => ['ماه (۱۴۰۵-۰۸)', 'text', 1, [], fa_num($ym)], 'target' => ['هدفِ فروش', 'money', 1]]));
