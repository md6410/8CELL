<?php
$f = report_filters($_GET); echo ui_date_filter('pl', $f); $p = pl_statement($f['from'], $f['to']);
echo '<div class="stats">' . ui_stat('فروشِ خالص', $p['net_sales']) . ui_stat('بهای تمام‌شده', $p['cogs']) . ui_stat('سودِ ناخالص', $p['gross']) . ui_stat('هزینه‌ها', $p['expense']) . ui_stat('سودِ خالص', $p['net']) . '</div>';
echo ui_card('صورتِ سود و زیان (از دفترِ کل)', ui_table(['code' => ['کد', 'code'], 'name' => 'سرفصل', 'dr' => ['بدهکار', 'money'], 'cr' => ['بستانکار', 'money'], 'bal' => ['مانده', 'money']], $p['lines']));
foreach (['channel' => 'سود به تفکیکِ کانال', 'branch' => 'سود به تفکیکِ شعبه', 'product' => 'سودِ هر کالا'] as $by => $t)
    echo ui_card($t, ui_table(['name' => 'عنوان', 'qty' => ['تعداد', 'num'], 'revenue' => ['فروشِ خالص', 'money'], 'cost' => ['بها', 'money'], 'fee' => ['کارمزد', 'money'], 'commission' => ['پورسانت', 'money'], 'profit' => ['سود', 'money']], accp_report($f['from'], $f['to'], $by)));
