<?php
// روندِ فروش و سود: ۱۲ ماه (نمودارِ میله‌ای) + پرفروش‌ترین رشد/افت در برابرِ دورهٔ قبل
$f = report_filters($_GET + ['from' => jdate(date('Y-m-d', strtotime('-29 day')), false, false)]);
$m = monthly_trend(12, $f); $mx = max(1, max(array_map(fn($r) => max($r['_rev'], abs($r['_profit'])), $m)));
$bars = '<div class="trend">'; foreach ($m as $r) $bars .= '<div class="tcol" title="' . h($r['month'] . ' — فروش ' . fa_money($r['_rev']) . ' · سود ' . fa_money($r['_profit']) . ' · حاشیه ' . $r['margin']) . '"><div class="tb"><i class="rev" style="height:' . round($r['_rev'] * 100 / $mx, 1) . '%"></i><i class="pro' . ($r['_profit'] < 0 ? ' neg' : '') . '" style="height:' . round(abs($r['_profit']) * 100 / $mx, 1) . '%"></i></div><small>' . h(mb_substr($r['month'], 2)) . '</small></div>';
$bars .= '</div><p class="muted"><span class="lg rev"></span> فروشِ خالص <span class="lg pro"></span> سودِ ناخالص (پس از بها، کارمزد و پورسانت)</p>';
$last = end($m); $prevM = $m[count($m) - 2] ?? null;
echo '<div class="stats">' . ui_stat('فروشِ این ماه', $last['_rev']) . ui_stat('سودِ این ماه', $last['_profit']) . '<div class="stat"><span>تغییرِ فروش نسبت به ماهِ قبل</span><b>' . h($last['rev_change']) . '</b></div><div class="stat"><span>تغییرِ سود</span><b>' . h($last['profit_change']) . '</b></div><div class="stat"><span>حاشیهٔ سود</span><b>' . h($last['margin']) . '</b></div></div>';
echo ui_card('روندِ ۱۲ ماه', $bars . ui_table(['month' => 'ماه', 'revenue' => ['فروش', 'money'], 'rev_change' => 'تغییر', 'profit' => ['سود', 'money'], 'profit_change' => 'تغییرِ سود', 'margin' => 'حاشیه', 'cnt' => ['تعدادِ فروش', 'num'], 'returns' => ['برگشتی', 'money']], array_reverse($m)));
echo ui_date_filter('trends', $f);
$t = trend_compare($f, 'product'); $up = array_filter($t, fn($r) => $r['_rc'] >= 10 && $r['_rc'] < 1e9); $down = array_filter($t, fn($r) => $r['_rc'] <= -10);
usort($up, fn($a, $b) => ($b['revenue'] - $b['revenue_prev']) <=> ($a['revenue'] - $a['revenue_prev'])); usort($down, fn($a, $b) => ($a['revenue'] - $a['revenue_prev']) <=> ($b['revenue'] - $b['revenue_prev']));
$pu = array_filter($t, fn($r) => $r['profit'] > $r['profit_prev']); usort($pu, fn($a, $b) => ($b['profit'] - $b['profit_prev']) <=> ($a['profit'] - $a['profit_prev']));
$cols = ['name' => 'کالا', 'revenue_prev' => ['دورهٔ قبل', 'money'], 'revenue' => ['این دوره', 'money'], 'rev_change' => 'تغییر', 'profit' => ['سود', 'money'], 'profit_change' => 'تغییرِ سود'];
echo '<div class="cols">' . ui_card('▲ بیشترین رشدِ فروش', ui_table($cols, array_slice($up, 0, 15), ['empty' => 'کالایی با رشدِ ۱۰٪+ نیست.'])) . ui_card('▼ بیشترین افتِ فروش', ui_table($cols, array_slice($down, 0, 15), ['empty' => 'کالایی با افتِ ۱۰٪+ نیست.'])) . '</div>';
echo ui_card('سودآورترین رشد (سودِ بیشتر از دورهٔ قبل)', ui_table($cols + ['margin' => 'حاشیه', 'margin_prev' => 'حاشیهٔ قبل'], array_slice($pu, 0, 15), ['empty' => '—']));
echo '<p><a class="btn" href="' . h(page_url('reports', ['r' => 'trend_product', 'from' => jdate($f['from'], false, false), 'to' => jdate($f['to'], false, false)])) . '">جدولِ کاملِ روندِ همهٔ کالاها (با خروجیِ اکسل) ←</a></p>';
