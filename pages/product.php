<?php
$id = req_int('id'); $p = $id ? product_get($id) : null; if ($id && !$p) throw new UserError('کالا یافت نشد');
$f = ['name' => ['نام', 'text', 1], 'category_id' => ['دسته', 'select', 1, ui_opts("SELECT id, CONCAT(name,' (',code,')') name FROM categories")], 'item_type' => ['نوع', 'select', 1, wh_item_types()], 'brand' => ['برند', 'text'],
    'unit_id' => ['واحد', 'select', 0, ui_opts("SELECT id, name FROM units")], 'serial' => ['ردیابیِ دانه‌به‌دانه (کدِ ۳۳ نویسه)', 'check'], 'barcode' => ['بارکدِ کارخانه', 'text'], 'price' => ['قیمتِ فروش (ریال)', 'money', 1],
    'sale_price' => ['قیمتِ حراج', 'money'], 'sale_until' => ['حراج تا', 'date'], 'cost_extra' => ['هزینهٔ جانبیِ هر واحد', 'money'], 'seller_share_pct' => ['سهمِ فروشنده از سود ٪', 'float'], 'price_guard_pct' => ['نگهبانِ نوسانِ قیمت ٪', 'float'],
    'min_stock' => ['حداقلِ موجودی (هشدار)', 'int'], 'warranty' => ['گارانتی', 'text'], 'weight_g' => ['وزن (گرم)', 'int'], 'dims' => ['ابعاد', 'text'], 'image' => ['نشانیِ تصویر', 'text'], 'description' => ['توضیح', 'textarea'], 'specs' => ['مشخصاتِ فنی', 'textarea'], 'inactive' => ['غیرفعال', 'check'], 'confirm_price' => ['تأییدِ تغییرِ بزرگِ قیمت (نگهبانِ نوسان)', 'check']];
$v = $p ? $p + ['inactive' => !$p['active']] : ['serial' => 1, 'item_type' => 'SAL'];
if ($p) $v['sale_until'] = $p['sale_until'];
$extra = $p ? '' : '<label class="field"><span>ترکیب‌ها (هر خط یک ترکیب، مثلاً رنگ/اندازه)</span><textarea id="vts" rows="3"></textarea></label>';
$form = ui_form('product_save', $f, $v, ['hidden' => $p ? ['id' => $p['id']] : [], 'then' => 'go:index.php?p=product&id={id}', 'extra' => $extra]);
if (!can('products.edit')) $form = '<dl class="kv">' . implode('', array_map(fn($k) => '<dt>' . h($f[$k][0]) . '</dt><dd>' . h((string)($p[$k] ?? '')) . '</dd>', array_keys(array_intersect_key($f, $p ?? [])))) . '</dl>';
echo ui_card($p ? $p['name'] . ' — ' . $p['base_code'] : 'کالای تازه', $form, $p ? '<a class="btn sm" href="' . h(product_link((int)$p['id'])) . '" target="_blank">لینکِ کاتالوگ</a> ' . (can('products.edit') ? ui_btn_api('کپی (نسخهٔ تازه)', 'product_duplicate', ['id' => $p['id']], ['class' => 'sm', 'prompt' => 'name:نامِ نسخهٔ تازه', 'then' => 'go:index.php?p=product&id={id}']) : '') . ' ' . ui_btn_api('ارسالِ لینک با پیامک', 'product_link_sms', ['id' => $p['id']], ['class' => 'sm', 'prompt' => 'mobile:موبایلِ مشتری', 'then' => 'none']) : '');
if (!$p) { echo '<script>document.addEventListener("DOMContentLoaded",function(){var f=document.querySelector("form[data-api]");f.__extend=function(o){o.variants=document.getElementById("vts").value.split("\n");return o;};});</script>'; return; }
$card = product_card((int)$p['id']);
echo '<div class="cols">';
echo ui_card('کارتِ کالا', '<dl class="kv"><dt>قیمتِ نهایی</dt><dd>' . ui_money($card['price']) . ($card['discount'] ? ' (به‌جای ' . ui_money($card['list']) . ')' : '') . '</dd><dt>قابلِ فروش</dt><dd>' . fa_num($card['stock']) . ' ' . h($card['unit']) . '</dd><dt>موجودیِ کل</dt><dd>' . fa_num(stock_on_hand((int)$p['id'])) . '</dd><dt>رزرو</dt><dd>' . fa_num(stock_reserved((int)$p['id'])) . '</dd></dl>');
$vs = product_variants((int)$p['id']);
echo ui_card('ترکیب‌ها', ui_table(['code' => ['کد', 'code'], 'title' => 'عنوان', 'price_delta' => ['اختلافِ قیمت', 'money'], 'barcode' => 'بارکد', 's' => ['قابلِ فروش', fn($r) => fa_num(sellable_stock((int)$p['id'], (int)$r['id']))]], $vs, ['empty' => 'بی‌ترکیب.'])
    . (can('products.edit') ? ui_form('variant_add', ['title' => ['عنوانِ ترکیب', 'text', 1], 'vcode' => ['کدِ ترکیب (اختیاری؛ ۲ تا ۶ نویسهٔ لاتین مثلِ RED)', 'text'], 'price_delta' => ['اختلافِ قیمت', 'money'], 'barcode' => ['بارکد', 'text']], [], ['hidden' => ['product_id' => $p['id']], 'submit' => 'افزودنِ ترکیب']) : ''));
if (can('partner.manage')) {
    $gs = rows("SELECT g.*, pg.disc_single, pg.disc_max, pg.max_qty FROM grades g LEFT JOIN product_grade_prices pg ON pg.grade_id=g.id AND pg.product_id=? WHERE g.kind='partner'", [$p['id']]);
    $h = ''; foreach ($gs as $g) $h .= '<h3>' . h($g['name']) . '</h3>' . ui_form('grade_price_save', ['single' => ['تخفیفِ تکی ٪', 'float'], 'max' => ['تخفیفِ بیشینه ٪', 'float'], 'max_qty' => ['تعدادِ بیشینه', 'int']], ['single' => $g['disc_single'] ?? $g['disc_pct'], 'max' => $g['disc_max'] ?? $g['disc_pct'], 'max_qty' => $g['max_qty'] ?? 1], ['hidden' => ['product_id' => $p['id'], 'grade_id' => $g['id']], 'class' => 'inline']) . '<p class="muted">قیمتِ ۱ عدد: ' . fa_money(partner_price((int)$p['id'], null, (int)$g['id'], 1)) . ' · ' . fa_num((int)($g['max_qty'] ?? 1)) . ' عدد: ' . fa_money(partner_price((int)$p['id'], null, (int)$g['id'], (int)($g['max_qty'] ?? 1))) . '</p>';
    echo ui_card('قیمتِ همکاری (تخفیفِ خطی روی قیمتِ نهایی)', $h ?: '<p class="empty">گریدِ همکار تعریف نشده است.</p>');
}
echo ui_card('تاریخچهٔ قیمت', ui_table(['at' => ['زمان', 'datetime'], 'old_price' => ['قبل', 'money'], 'new_price' => ['بعد', 'money']], rows("SELECT * FROM price_history WHERE product_id=? ORDER BY id DESC LIMIT 30", [$p['id']])));
echo '</div>';
