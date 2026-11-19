<?php
$c = acc_calc();
echo ui_card('سهمِ شرکا', ui_table(['name' => 'شریک', 'coef' => ['ضریب', 'num'], 'earned' => ['طلبِ کل', 'money'], 'settled' => ['دریافت‌شده', 'money'], 'balance' => ['مانده', 'money']], $c['partners'], ['empty' => 'شریکی تعریف نشده است.']) . ($c['depRes'] > 0 ? '<p class="warn">هزینهٔ پوشش‌داده‌نشده: ' . fa_money($c['depRes']) . '</p>' : '')
    . ($c['partners'] ? ui_form('acc_settle', ['partner_id' => ['شریک', 'select', 1, array_column($c['partners'], 'name', 'id')], 'amount' => ['مبلغ', 'money', 1], 'd' => ['تاریخ', 'date'], 'note' => ['یادداشت', 'text']], [], ['submit' => 'ثبتِ تسویه']) : ''));
echo ui_card('صورتِ ماهانه (هر واریز اول هزینهٔ ماه را می‌پوشاند)', ui_table(['ym' => ['ماه', 'num'], 'credit' => ['واریز', 'money'], 'expense' => ['هزینه', 'money'], 'covered' => ['پوشش', 'money'], 'pending' => ['هزینهٔ معوق', 'money'], 'income' => ['قابلِ تقسیم', 'money']], array_reverse($c['months'])));
$defs = crud_defs();
foreach (['acc_partners', 'acc_expenses'] as $e) { $d = $defs[$e]; echo ui_card($d[0], ui_table(array_map(fn($f) => $f[0], $d[3]), rows("SELECT * FROM `{$d[1]}` ORDER BY id DESC")) . ui_form('crud_save', $d[3], ['active' => 1, 'coef' => 1], ['hidden' => ['_e' => $e], 'submit' => 'افزودن'])); }
$docs = rows("SELECT * FROM docs WHERE status='issued' AND kind IN ('invoice','official') ORDER BY id DESC LIMIT 100");
foreach ($docs as &$d) { $d['buyer'] = json_decode($d['buyer'], true)['name'] ?? ''; $d['st'] = doc_pay_state($d + ['id' => $d['id']])['label']; }
echo ui_card('وضعیتِ پرداختِ فاکتورها', ui_table(['number' => ['شماره', 'num'], 'buyer' => 'خریدار', 'total' => ['مبلغ', 'money'], 'st' => 'وضعیت'], $docs, ['href' => fn($r) => page_url('doc', ['id' => $r['id']])]));
