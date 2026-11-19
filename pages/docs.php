<?php
$f = report_filters($_GET); echo ui_date_filter('docs', $f);
if (can('docs.edit')) echo '<div class="actions" style="margin:0 0 12px">' . implode(' ', array_map(fn($k, $t) => '<a class="btn" href="' . h(page_url('doc', ['kind' => $k])) . '">+ ' . h($t) . '</a>', array_keys(doc_kinds()), doc_kinds())) . '</div>';
$st = ['draft' => ['پیش‌نویس', 'warn'], 'issued' => ['ثبت‌شده', 'ok'], 'void' => ['باطل', 'err']];
$rs = rows("SELECT * FROM docs WHERE d BETWEEN ? AND ? ORDER BY id DESC LIMIT 500", [$f['from'], $f['to']]);
foreach ($rs as &$r) { $r['buyer_name'] = json_decode($r['buyer'], true)['name'] ?? ''; }
echo ui_card('اسناد', ui_table(['number' => ['شماره', fn($r) => h(fa_num($r['number'] ?: '—'))], 'kind' => ['نوع', fn($r) => h(doc_kinds()[$r['kind']] ?? '')], 'd' => ['تاریخ', 'date'], 'buyer_name' => 'خریدار', 'total' => ['مبلغ', 'money'],
    'status' => ['وضعیت', fn($r) => ui_badge($st[$r['status']][0], $st[$r['status']][1]) . ($r['confirmed'] ? ' ' . ui_badge('تأییدِ خریدار', 'ok') : '')]], $rs, ['filter' => 1, 'href' => fn($r) => page_url('doc', ['id' => $r['id']])]));
