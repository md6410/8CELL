<?php
$t = label_template(req_int('tpl')) ?: label_template((int)val("SELECT id FROM label_templates ORDER BY id LIMIT 1"));
if (!$t) exit('قالبی نیست');
$copies = max(1, min(500, req_int('copies', 1))); $list = [];
if (req_int('sale_id')) $list[] = label_values('ship', ['sale_id' => req_int('sale_id')]);
elseif (req_int('lot_id')) {
    $mode = (string)($_GET['units'] ?? 'none');
    if ($mode !== 'none') { $lot = row("SELECT * FROM lots WHERE id=?", [req_int('lot_id')]); foreach (rows("SELECT code FROM wh_units WHERE lot_id=? AND status=? ORDER BY id", [req_int('lot_id'), $mode === 'in_stock' ? 'in_stock' : 'labeled']) as $u) $list[] = label_values($t['kind'] === 'lot' ? 'lot' : 'item', ['lot_id' => $lot['id'], 'product_id' => $lot['product_id'], 'variant_id' => $lot['variant_id'], 'code' => $u['code']]) + ['code' => $u['code'], 'code_pretty' => wh_pretty_code($u['code'])]; }
    else $list[] = label_values('lot', ['lot_id' => req_int('lot_id'), 'qty' => wh_lot_balance(req_int('lot_id'))]);
} elseif (req_int('product_id')) $list[] = label_values($t['kind'] === 'shelf' ? 'shelf' : 'item', ['product_id' => req_int('product_id'), 'variant_id' => req_int('variant_id') ?: null]);
$all = []; foreach ($list as $v) for ($i = 0; $i < $copies; $i++) $all[] = $v;
echo label_page_html($t, $all ?: [label_values('item', ['product_id' => 0])]);
