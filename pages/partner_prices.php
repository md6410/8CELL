<?php
// قیمتِ همکاری به‌صورتِ یک‌جا (روالِ Weby admin/partner-prices.php): هر کالا × هر گرید: تخفیفِ تکی، بیشینه، تعدادِ بیشینه
$grades = partner_grades();
if (!$grades) { echo ui_card('قیمتِ همکاری', '<p class="empty">هنوز گریدِ همکار تعریف نشده است. از «اطلاعاتِ پایه ← گریدهای قیمت» گریدی با نوعِ «همکار» بسازید.</p>'); return; }
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'category_id' => req_int('category_id'), 'only_rules' => !empty($_GET['only'])];
echo '<form class="filters"><input type="hidden" name="p" value="partner_prices"><label>جست‌وجو<input name="q" value="' . h($f['q']) . '"></label><label>دسته<select name="category_id"><option value="">همه</option>'
    . implode('', array_map(fn($k, $t) => '<option value="' . $k . '"' . ($f['category_id'] == $k ? ' selected' : '') . '>' . h($t) . '</option>', array_keys($cats = ui_opts("SELECT id, name FROM categories ORDER BY name")), $cats)) . '</select></label>'
    . '<label class="ck"><input type="checkbox" name="only" value="1"' . ($f['only_rules'] ? ' checked' : '') . '> فقط دارای قاعده</label><button class="btn">نمایش</button></form>';
echo '<p class="muted">برای هر گرید: «تکی» = درصدِ تخفیف با ۱ عدد، «بیشینه» = بیشترین درصد، «تعداد» = از این تعداد به بعد بیشینه. بینِ این دو خطی بالا می‌رود و از روی قیمتِ نهاییِ فروشگاه کم می‌شود (همکار هرگز گران‌تر از مشتری نمی‌خرد). ردیفِ صفر = بی‌تخفیفِ همکاری (درصدِ پیش‌فرضِ گرید).</p>';
$rs = partner_prices_grid($f);
$h = '<form class="form" data-api="partner_prices_save" data-then="reload"><div class="tblwrap"><table class="tbl pp"><tr><th rowspan="2">کالا</th><th rowspan="2">قیمتِ نهایی</th>';
foreach ($grades as $g) $h .= '<th colspan="3">' . h($g['name']) . ($g['disc_pct'] > 0 ? ' <small>(پیش‌فرض ' . fa_num((string)(float)$g['disc_pct']) . '٪)</small>' : '') . '</th>';
$h .= '</tr><tr>'; foreach ($grades as $g) $h .= '<th>تکی٪</th><th>بیشینه٪</th><th>تعداد</th>'; $h .= '</tr>';
foreach ($rs as $r) {
    $h .= '<tr><td>' . h($r['name']) . '</td><td class="num" data-retail="' . (int)$r['retail'] . '">' . fa_money($r['retail']) . '</td>';
    foreach ($grades as $g) { $x = $r['rules'][$g['id']] ?? null; $n = '[' . $r['id'] . '][' . $g['id'] . ']';
        $h .= '<td><input name="one' . $n . '" value="' . h($x ? fa_num((string)(float)$x['disc_single']) : '') . '" inputmode="decimal" style="width:52px"></td><td><input name="max' . $n . '" value="' . h($x ? fa_num((string)(float)$x['disc_max']) : '') . '" inputmode="decimal" style="width:52px"></td><td><input name="qty' . $n . '" value="' . h($x ? fa_num((string)$x['max_qty']) : '') . '" inputmode="numeric" style="width:44px"></td>'; }
    $h .= '</tr>';
}
$h .= '</table></div><div class="actions"><button class="btn primary">ذخیرهٔ همه</button></div></form>';
echo ui_card('قیمتِ همکاری (' . fa_num(count($rs)) . ' کالا)', $rs ? $h : '<p class="empty">کالایی نیست.</p>');
$h = ''; foreach ($grades as $g) $h .= '<p>' . h($g['name']) . ' (' . fa_num((string)val("SELECT COUNT(*) FROM customers WHERE is_partner=1 AND partner_grade_id=?", [$g['id']])) . ' همکار): ' . ui_btn_api('فرستادنِ لینکِ فهرستِ قیمت به همه', 'partner_list_send', ['grade_id' => $g['id']], ['class' => 'sm', 'confirm' => 'لینکِ فهرستِ قیمت با پیامک/بله برای همهٔ همکارانِ این گرید فرستاده شود؟']) . '</p>';
echo ui_card('خبر دادن به همکاران', '<p class="muted">پس از تغییرِ قیمت‌ها، لینکِ فهرستِ قیمتِ هر همکار (۳۰ روز اعتبار) با پیامک (قالبِ «لینکِ فهرستِ قیمتِ همکار») و بله فرستاده می‌شود.</p>' . $h);
