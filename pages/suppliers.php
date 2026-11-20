<?php
$id = req_int('id'); $s = $id ? row("SELECT * FROM suppliers WHERE id=?", [$id]) : null;
$f = ['name' => ['نام', 'text', 1], 'national_id' => ['شناسهٔ ملی/کدِ ملی', 'text'], 'mobile' => ['موبایل', 'text'], 'phone' => ['تلفن', 'text'], 'iban' => ['شبا', 'text'], 'pay_terms_days' => ['مهلتِ پرداخت (روز)', 'int'], 'seller_code' => ['کدِ فروشنده در کدِ محموله (۱ تا ۴ نویسهٔ لاتین؛ برای کالای امانی)', 'text'], 'address' => ['نشانی', 'textarea'], 'inactive' => ['غیرفعال', 'check']];
if (can('purchase.edit')) echo ui_card($s ? 'ویرایشِ ' . $s['name'] : 'تأمین‌کنندهٔ تازه', ui_form('supplier_save', $f, $s ? $s + ['inactive' => !$s['active']] : [], ['hidden' => $s ? ['id' => $s['id']] : [], 'then' => 'go:' . page_url('suppliers')]));
if ($s) echo ui_card('صورت‌حساب (دفترِ اشخاص)', ui_table(['d' => ['تاریخ', 'date'], 'descr' => 'شرح', 'debit' => ['بدهکار', 'money'], 'credit' => ['بستانکار', 'money'], 'bal' => ['مانده', 'money']], party_statement('supplier', $id)['rows']));
$rs = rows("SELECT * FROM suppliers ORDER BY name"); foreach ($rs as &$r) $r['bal'] = supplier_balance((int)$r['id']);
echo ui_card('تأمین‌کنندگان', ui_table(['name' => 'نام', 'mobile' => ['موبایل', 'num'], 'seller_code' => ['کد', 'code'], 'pay_terms_days' => ['مهلت', 'num'], 'bal' => ['بدهیِ ما', 'money']], $rs, ['filter' => 1, 'href' => fn($r) => page_url('suppliers', ['id' => $r['id']])]));
