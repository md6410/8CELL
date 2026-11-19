<?php
$tab = $_GET['tab'] ?? 'ledger';
echo ui_tabs(['ledger' => 'حسابِ نقدی و تنخواه', 'rules' => 'قاعدهٔ پورسانت', 'comm' => 'پورسانتِ ماه'], $tab, 'staff');
$users = ui_opts("SELECT id, name FROM users WHERE active=1 AND kind='staff' ORDER BY name");
if ($tab === 'ledger') {
    $rows = []; foreach ($users as $uid => $n) $rows[] = ['id' => $uid, 'name' => $n, 'bal' => stacc_balance($uid), 'cash' => stacc_balance($uid, ['shift_cash', 'handover']), 'lim' => stacc_cash_limit($uid), 'blk' => stacc_blocked($uid)];
    echo ui_card('حساب‌های کارکنان (مانده = جمعِ ردیف‌ها)', ui_table(['name' => 'کارمند', 'cash' => ['نقدِ دست', 'money'], 'bal' => ['کلِ بدهی/نقد', 'money'], 'lim' => ['سقف', 'money'], 'blk' => ['وضعیت', fn($r) => ($r['blk'] ? ui_badge('مسدود', 'err') : ui_badge('آزاد', 'ok')) . ' ' . ui_btn_api(setting('stacc_block_' . $r['id']) === '1' ? 'رفعِ مسدودیِ دستی' : 'مسدودِ دستی', 'stacc_block', ['user_id' => $r['id'], 'on' => setting('stacc_block_' . $r['id']) === '1' ? 0 : 1], ['class' => 'sm'])]], $rows));
    echo '<div class="cols">' . ui_card('ثبتِ ردیف', ui_form('stacc_add', ['user_id' => ['کارمند', 'select', 1, $users], 'kind' => ['نوع', 'select', 1, stacc_kinds()], 'amount' => ['مبلغ (مثبت = بدهکارِ کارمند)', 'money', 1], 'note' => ['شرح', 'text']]))
        . ui_card('تحویلِ نقد به بانک', ui_form('stacc_handover', ['user_id' => ['کارمند', 'select', 1, $users], 'amount' => ['مبلغ', 'money', 1], 'bank_account_id' => ['حساب', 'select', 1, ui_opts("SELECT id, title name FROM bank_accounts WHERE active=1")], 'note' => ['شرح', 'text']])) . '</div>';
    echo ui_card('آخرین ردیف‌ها', ui_table(['at' => ['زمان', 'datetime'], 'un' => 'کارمند', 'kind' => ['نوع', fn($r) => h(stacc_kinds()[$r['kind']] ?? $r['kind'])], 'amount' => ['مبلغ', 'money'], 'note' => 'شرح'], rows("SELECT l.*, u.name un FROM staff_ledger l JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 100")));
} elseif ($tab === 'rules') {
    if (can('commission.manage')) echo ui_card('قاعدهٔ تازه (پلکانی)', ui_form('commission_rule_save', ['name' => ['نام', 'text', 1], 'base' => ['پایه', 'select', 1, ['profit' => 'سودِ ردیف (خالص − بها)', 'revenue' => 'فروشِ خالص']], 'user_id' => ['فقط برای کارمند', 'select', 0, $users + ui_opts("SELECT id, name FROM users WHERE kind='channel'")],
        'category_id' => ['فقط دسته', 'select', 0, ui_opts("SELECT id, name FROM categories")], 'channel_id' => ['فقط کانال', 'select', 0, ui_opts("SELECT id, name FROM channels")]], [], ['extra' => '<h3>پله‌ها</h3>' . ui_lines('tiers', [['k' => 'from', 't' => 'از مبلغِ پایه', 'd' => '0'], ['k' => 'pct', 't' => 'درصد', 'd' => '5']], [], false)]));
    echo ui_card('قاعده‌ها (خاص‌ترین قاعده اعمال می‌شود)', ui_table(['name' => 'نام', 'base' => 'پایه', 'tiers' => ['پله‌ها', fn($r) => h(implode(' | ', array_map(fn($t) => 'از ' . fa_money($t['from']) . ': ' . fa_num((string)$t['pct']) . '٪', json_decode($r['tiers'], true) ?: [])))], 'un' => 'کارمند', 'cn' => 'دسته'],
        rows("SELECT r.*, u.name un, c.name cn FROM commission_rules r LEFT JOIN users u ON u.id=r.user_id LEFT JOIN categories c ON c.id=r.category_id WHERE r.active=1")));
} else {
    $ym = str_replace('/', '-', en_num((string)($_GET['ym'] ?? jym(today()))));
    echo '<form class="filters"><input type="hidden" name="p" value="staff"><input type="hidden" name="tab" value="comm"><label>ماه<input name="ym" value="' . h(fa_num($ym)) . '"></label><button class="btn">نمایش</button></form>';
    echo ui_card('پورسانتِ خالصِ ماه (برگشت‌ها کم شده)', ui_table(['name' => 'کارمند', 'total' => ['پورسانت', 'money'], 'cnt' => ['ردیف', 'num']], rows("SELECT u.name, SUM(c.amount) total, COUNT(*) cnt FROM commission_lines c JOIN users u ON u.id=c.user_id WHERE c.ym=? GROUP BY u.id, u.name ORDER BY total DESC", [$ym])));
}
