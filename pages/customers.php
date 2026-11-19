<?php
$q = trim((string)($_GET['q'] ?? ''));
echo '<form class="filters"><input type="hidden" name="p" value="customers"><label>نام، موبایل، کدِ ملی یا نامِ فرد<input name="q" value="' . h($q) . '"></label><button class="btn">جست‌وجو</button>' . (can('customers.edit') ? ' <a class="btn primary" href="' . h(page_url('customer')) . '">+ مشتریِ تازه</a>' : '') . '</form>';
$rs = $q !== '' ? customer_search($q, 200) : rows("SELECT id, name, mobile, kind, is_partner FROM customers WHERE merged_into IS NULL ORDER BY id DESC LIMIT 200");
foreach ($rs as &$r) { $r['bal'] = customer_balance((int)$r['id']); $r['wal'] = wallet_balance((int)$r['id']); }
echo ui_card('مشتریان', ui_table(['name' => 'نام', 'mobile' => ['موبایل', 'num'], 'kind' => ['نوع', fn($r) => $r['kind'] === 'org' ? 'سازمان' : 'شخص'], 'is_partner' => ['', fn($r) => $r['is_partner'] ? ui_badge('همکار', 'warn') : ''], 'bal' => ['بدهیِ نسیه', 'money'], 'wal' => ['کیف پول', 'money']], $rs, ['href' => fn($r) => page_url('customer', ['id' => $r['id']])]));
