<?php
$q = trim((string)($_GET['q'] ?? ''));
if (can('products.edit')) echo '<p><a class="btn primary" href="' . h(page_url('product')) . '">+ کالای تازه</a></p>';
$rs = rows("SELECT p.*, c.name cat FROM products p LEFT JOIN categories c ON c.id=p.category_id" . ($q !== '' ? " WHERE p.name LIKE " . db()->quote('%' . $q . '%') . " OR p.base_code LIKE " . db()->quote(strtoupper($q) . '%') : '') . " ORDER BY p.id DESC LIMIT 1000");
foreach ($rs as &$r) { $r['final'] = final_price($r)['price']; $r['stock'] = sellable_stock((int)$r['id']); }
echo ui_card('کالاها (' . fa_num(count($rs)) . ')', ui_table(['base_code' => ['کد', 'code'], 'name' => 'نام', 'cat' => 'دسته', 'item_type' => ['نوع', fn($r) => h(wh_item_types()[$r['item_type']] ?? '')], 'final' => ['قیمتِ نهایی', 'money'], 'stock' => ['قابلِ فروش', 'num'],
    'active' => ['', fn($r) => $r['active'] ? '' : ui_badge('غیرفعال', 'err')]], $rs, ['filter' => 1, 'href' => fn($r) => page_url('product', ['id' => $r['id']])]));
