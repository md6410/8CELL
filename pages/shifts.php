<?php
$u = current_user(); $my = shift_of_user((int)$u['id']);
if ($my) {
    echo ui_card('بستنِ شیفتِ من (شمارشِ کور)', '<p>نقدِ داخلِ صندوق را بشمارید و وارد کنید؛ عددِ مورد انتظار پس از ثبت نمایش داده می‌شود.</p>'
        . ui_form('shift_close', ['counted' => ['نقدِ شمرده‌شده (ریال)', 'money', 1], 'handed_to' => ['تحویل به (اختیاری)', 'select', 0, ui_opts("SELECT id, name FROM users WHERE active=1 AND kind='staff' AND id<>" . (int)$u['id'])]], [], ['hidden' => ['shift_id' => $my['id']], 'submit' => 'بستنِ شیفت', 'confirm' => 'شیفت بسته شود؟', 'then' => 'go:' . page_url('shifts', ['id' => $my['id']])]));
}
$id = req_int('id');
if ($id && ($s = row("SELECT s.*, c.name cb, u.name un FROM shifts s JOIN cashboxes c ON c.id=s.cashbox_id JOIN users u ON u.id=s.user_id WHERE s.id=?", [$id]))) {
    $sum = $s['summary'] ? json_decode($s['summary'], true) : shift_expected($id) + ['counted' => null, 'diff' => null, 'diff_label' => ''];
    $bm = ''; foreach ($sum['by_method'] ?? [] as $m => $a) $bm .= '<dt>' . h(pay_methods()[$m] ?? $m) . '</dt><dd>' . ui_money($a) . '</dd>';
    echo ui_card('گزارشِ شیفتِ ' . fa_num($id) . ' — ' . $s['cb'] . ' — ' . $s['un'], '<dl class="kv"><dt>وضعیت</dt><dd>' . ($s['status'] === 'open' ? ui_badge('باز', 'warn') : ui_badge('بسته', 'ok')) . '</dd><dt>تعداد/جمعِ فروش</dt><dd>' . fa_num($sum['sales']) . ' · ' . ui_money($sum['total']) . '</dd>'
        . '<dt>موجودیِ اولیه</dt><dd>' . ui_money($sum['opening']) . '</dd><dt>نقدِ دریافتی</dt><dd>' . ui_money($sum['cash_in']) . '</dd><dt>بازپرداختِ نقد</dt><dd>' . ui_money($sum['cash_out']) . '</dd>'
        . ($s['status'] === 'closed' || can('shift.manage') ? '<dt>مورد انتظار</dt><dd>' . ui_money($sum['expected']) . '</dd>' : '') . ($s['status'] === 'closed' ? '<dt>شمرده</dt><dd>' . ui_money($sum['counted']) . '</dd><dt>مغایرت</dt><dd>' . h(fa_num($sum['diff_label'])) . '</dd>' : '') . $bm . '</dl>',
        '<a class="btn sm" target="_blank" href="index.php?p=print_shift&id=' . $id . '">چاپ</a>');
}
$where = can('shift.manage') ? '1=1' : 's.user_id=' . (int)$u['id'];
echo ui_card('شیفت‌ها', ui_table(['id' => ['#', 'num'], 'cb' => 'صندوق', 'un' => 'کاربر', 'opened_at' => ['باز', 'datetime'], 'closed_at' => ['بسته', 'datetime'], 'expected_cash' => ['مورد انتظار', 'money'], 'counted_cash' => ['شمرده', 'money'], 'diff' => ['مغایرت', 'money']],
    rows("SELECT s.*, c.name cb, u.name un FROM shifts s JOIN cashboxes c ON c.id=s.cashbox_id JOIN users u ON u.id=s.user_id WHERE $where ORDER BY s.id DESC LIMIT 100"), ['href' => fn($r) => page_url('shifts', ['id' => $r['id']])]));
