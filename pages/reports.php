<?php
$ds = array_filter(report_datasets(), fn($d) => can($d[1])); $key = (string)($_GET['r'] ?? array_key_first($ds)); $f = report_filters($_GET);
$cols = (array)($_GET['c'] ?? []);
if (!empty($_GET['export']) && isset($ds[$key])) { if (!can('reports.export')) throw new UserError('این دسترسی را ندارید', 403); ob_end_clean(); an_export(report_run($key, $f, $cols), (string)$_GET['export']); exit; }
echo '<form class="filters" method="get"><input type="hidden" name="p" value="reports"><label>گزارش<select name="r" onchange="this.form.submit()">' . implode('', array_map(fn($k, $d) => '<option value="' . $k . '"' . ($k === $key ? ' selected' : '') . '>' . h($d[0]) . '</option>', array_keys($ds), $ds)) . '</select></label>'
    . '<label>از<input class="jd" name="from" value="' . h(jdate($f['from'])) . '"></label><label>تا<input class="jd" name="to" value="' . h(jdate($f['to'])) . '"></label>'
    . '<label>شعبه<select name="branch_id"><option value="">همه</option>' . implode('', array_map(fn($b) => '<option value="' . $b['id'] . '"' . ($f['branch_id'] == $b['id'] ? ' selected' : '') . '>' . h($b['name']) . '</option>', branch_list())) . '</select></label>'
    . '<label>کانال<select name="channel"><option value="">همه</option><option value="pos"' . ($f['channel'] === 'pos' ? ' selected' : '') . '>حضوری</option>' . implode('', array_map(fn($c) => '<option value="' . $c['id'] . '"' . ($f['channel'] === (string)$c['id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>', rows("SELECT id, name FROM channels"))) . '</select></label>'
    . '<label>جست‌وجو<input name="q" value="' . h($f['q']) . '"></label>';
if (isset($ds[$key])) { echo '<details><summary>ستون‌ها</summary>'; foreach ($ds[$key][2] as $k => $t) echo '<label class="ck"><input type="checkbox" name="c[]" value="' . h($k) . '"' . (!$cols || in_array($k, $cols, true) ? ' checked' : '') . '> ' . h($t) . '</label> '; echo '</details>'; }
echo '<button class="btn primary">نمایش</button>' . (can('reports.export') ? ' <button class="btn" name="export" value="xls">اکسل</button> <button class="btn" name="export" value="csv">CSV</button> <button class="btn" type="button" onclick="print()">چاپ / PDF</button>' : '') . '</form>';
if (!isset($ds[$key])) return;
$rep = report_run($key, $f, $cols); $tc = [];
foreach ($rep['cols'] as $k => $t) $tc[$k] = [$t, fn($r) => h(report_cell($k, $r[$k] ?? ''))];
$tot = []; foreach ($rep['rows'] as $r) foreach ($rep['cols'] as $k => $t) if (is_numeric($r[$k] ?? null) && !in_array($k, ['number', 'mobile', 'code', 'rank', 'd'], true)) $tot[$k] = ($tot[$k] ?? 0) + $r[$k];
echo ui_card($rep['title'] . ' (' . fa_num(count($rep['rows'])) . ' ردیف)', ui_table($tc, $rep['rows'], ['filter' => 1]) . ($tot ? '<p><b>جمع:</b> ' . implode(' · ', array_map(fn($k, $v) => h($rep['cols'][$k]) . ' ' . fa_money($v), array_keys($tot), $tot)) . '</p>' : ''));
