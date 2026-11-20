<?php
// صفِ تأییدِ واریزها (روالِ Weby admin/pay-approvals.php)
$st = in_array($_GET['st'] ?? '', ['approved', 'rejected'], true) ? $_GET['st'] : 'pending';
echo '<nav class="tabs">'; foreach (['pending' => 'منتظرِ تأیید (' . fa_num(payreq_open_count()) . ')', 'approved' => 'تأییدشده', 'rejected' => 'ردشده'] as $k => $t) echo '<a class="' . ($k === $st ? 'on' : '') . '" href="' . h(page_url('payreqs', ['st' => $k])) . '">' . h($t) . '</a>'; echo '</nav>';
$rs = payreq_queue($st);
$cols = ['sale_number' => ['فروش', fn($r) => '<a href="' . h(page_url('sale', ['id' => $r['sale_id']])) . '">' . h(fa_num($r['sale_number'])) . '</a>'], 'cname' => 'همکار', 'amount' => ['مبلغ', 'money'], 'acc' => 'حساب', 'created_at' => ['ثبت', 'datetime'],
    'files' => ['فیش', fn($r) => implode(' ', array_map(fn($f) => '<a class="btn sm" target="_blank" href="' . h(page_url('payreq_file', ['id' => $f['id']])) . '">🧾</a>', payreq_files((int)$r['id']))) ?: ui_badge('بی‌فیش', 'warn')]];
if ($st === 'pending') $cols['a'] = ['', fn($r) => ui_btn_api('تأیید', 'payreq_approve', ['id' => $r['id']], ['class' => 'sm primary', 'confirm' => 'واریزِ ' . fa_money($r['amount']) . ' ریال تأیید شود؟']) . ' ' . ui_btn_api('رد', 'payreq_reject', ['id' => $r['id']], ['class' => 'sm', 'prompt' => 'note:دلیلِ رد'])];
else $cols['decision_note'] = 'یادداشت';
echo ui_card('واریزهای همکاران', ui_table($cols, $rs, ['empty' => 'موردی نیست.']));
