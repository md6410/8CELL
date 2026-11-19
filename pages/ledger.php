<?php
$f = report_filters($_GET); $tab = $_GET['tab'] ?? 'tb';
echo ui_tabs(['tb' => 'ترازِ آزمایشی', 'j' => 'دفترِ روزنامه', 'new' => 'سندِ دستی'], $tab, 'ledger');
if ($tab !== 'new') echo ui_date_filter('ledger', $f, ['tab' => $tab]);
if ($tab === 'tb') { $tb = trial_balance($f['from'], $f['to']);
    echo ui_card('ترازِ آزمایشی — ' . ($tb['ok'] ? 'تراز است ✔' : 'ناتراز!'), ui_table(['code' => ['کد', 'code'], 'name' => 'سرفصل', 'dr' => ['گردشِ بدهکار', 'money'], 'cr' => ['گردشِ بستانکار', 'money'], 'bal' => ['مانده', 'money']], $tb['rows'], ['href' => fn($r) => page_url('gl', ['account' => $r['id'], 'from' => jdate($f['from'], false, false), 'to' => jdate($f['to'], false, false)])]) . '<p><b>جمع: ' . fa_money($tb['dr']) . ' / ' . fa_money($tb['cr']) . '</b></p>');
} elseif ($tab === 'j') {
    $js = rows("SELECT * FROM journals WHERE d BETWEEN ? AND ? ORDER BY d DESC, id DESC LIMIT 300", [$f['from'], $f['to']]);
    $h = ''; foreach ($js as $j) { $ls = rows("SELECT l.*, a.code, a.name FROM journal_lines l JOIN accounts a ON a.id=l.account_id WHERE journal_id=?", [$j['id']]);
        $h .= '<details><summary>سندِ ' . fa_num($j['number']) . ' · ' . jdate($j['d']) . ' · ' . h($j['descr']) . ' · ' . fa_money($j['total']) . ' <small class="muted">(' . h($j['source_type'] . ' ' . $j['source_id']) . ')</small></summary>' . ui_table(['code' => ['کد', 'code'], 'name' => 'سرفصل', 'party' => ['تفصیلی', fn($r) => h(($r['party_type'] ? (party_types()[$r['party_type']] ?? $r['party_type']) . ' ' . $r['party_id'] : ''))], 'debit' => ['بدهکار', 'money'], 'credit' => ['بستانکار', 'money']], $ls) . '</details>'; }
    echo ui_card('اسناد', $h ?: '<p class="empty">سندی نیست.</p>');
} elseif (can('ledger.post')) {
    $acc = ui_opts("SELECT id, CONCAT(code,' — ',name) name FROM accounts WHERE level<>'group' AND level<>'kol' ORDER BY code");
    $rows = ''; for ($i = 0; $i < 6; $i++) $rows .= '<tr><td><select name="lines[' . $i . '][account_id]"><option value="">—</option>' . implode('', array_map(fn($k, $v) => '<option value="' . $k . '">' . h($v) . '</option>', array_keys($acc), $acc)) . '</select></td><td><input class="money" name="lines[' . $i . '][debit]"></td><td><input class="money" name="lines[' . $i . '][credit]"></td><td><input name="lines[' . $i . '][descr]"></td></tr>';
    echo ui_card('سندِ دستی (فقط حسابدار؛ سندِ ناتراز ثبت نمی‌شود)', ui_form('journal_manual', ['d' => ['تاریخ', 'date', 1], 'descr' => ['شرح', 'text', 1]], [], ['extra' => '<table class="tbl"><tr><th>سرفصل</th><th>بدهکار</th><th>بستانکار</th><th>شرح</th></tr>' . $rows . '</table>']));
}
