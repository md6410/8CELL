<?php
$tab = $_GET['tab'] ?? 'print';
echo ui_tabs(['print' => 'چاپ', 'design' => 'طراحیِ قالب'] , $tab, 'labels');
$tpls = rows("SELECT * FROM label_templates ORDER BY kind, id");
if ($tab === 'design') {
    if (!can('labels.design')) throw new UserError('این دسترسی را ندارید', 403);
    $id = req_int('tpl'); $t = $id ? label_template($id) : null;
    echo ui_card('قالب‌ها', ui_table(['name' => 'نام', 'kind' => ['نوع', fn($r) => h(label_kinds()[$r['kind']] ?? '')], 'w' => ['اندازه', fn($r) => h(label_paper_name($r['w_mm'], $r['h_mm']))]], $tpls, ['href' => fn($r) => page_url('labels', ['tab' => 'design', 'tpl' => $r['id']])])
        . '<form class="filters"><input type="hidden" name="p" value="labels"><input type="hidden" name="tab" value="design"><label>قالبِ تازه از اندازهٔ آماده<select name="preset">' . implode('', array_map(fn($p) => '<option value="' . $p[1] . 'x' . $p[2] . '">' . h(fa_num($p[0])) . '</option>', label_presets())) . '</select></label><label>نوع<select name="kind">' . implode('', array_map(fn($k, $v) => '<option value="' . $k . '">' . h($v) . '</option>', array_keys(label_kinds()), label_kinds())) . '</select></label><button class="btn">ساختن</button></form>');
    if (!$t && !empty($_GET['preset'])) { [$w, $hh] = array_map('floatval', explode('x', $_GET['preset'])); $k = isset(label_kinds()[$_GET['kind'] ?? '']) ? $_GET['kind'] : 'item'; $t = ['id' => 0, 'name' => 'قالبِ تازه', 'kind' => $k, 'w_mm' => $w, 'h_mm' => $hh, 'layout' => label_default_layout($k, $w, $hh)]; }
    if ($t) { ?>
<div id="lbl" class="card" data-tpl="<?= h(json_encode($t, JSON_UNESCAPED_UNICODE)) ?>" data-fields="<?= h(json_encode(label_fields($t['kind']), JSON_UNESCAPED_UNICODE)) ?>" data-types="<?= h(json_encode(label_el_types(), JSON_UNESCAPED_UNICODE)) ?>">
<form class="form" data-api="label_tpl_save" data-then="go:index.php?p=labels&tab=design&tpl={id}"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="kind" value="<?= h($t['kind']) ?>">
<div class="grid"><?= ui_field('name', ['نام', 'text', 1], $t['name']) . ui_field('w_mm', ['پهنا (م‌م)', 'float', 1], $t['w_mm']) . ui_field('h_mm', ['بلندی (م‌م)', 'float', 1], $t['h_mm']) ?></div>
<div class="actions"><?php foreach (label_el_types() as $k => $v) echo '<button type="button" class="btn sm" data-add="' . $k . '">+ ' . h($v) . '</button>'; ?><button class="btn primary">ذخیرهٔ قالب</button></div></form>
<div class="lbl-wrap"><div><div class="lbl-canvas"></div><p class="muted">اندازهٔ برچسب همان اندازهٔ کاغذِ چاپ است؛ چیزی کوچک‌شده چاپ نمی‌شود.</p></div><div class="props"></div></div></div>
<script src="assets/label.js" defer></script><?php }
    return;
}
// چاپ
$q = array_filter(['lot_id' => req_int('lot_id'), 'product_id' => req_int('product_id'), 'variant_id' => req_int('variant_id'), 'sale_id' => req_int('sale_id')]);
$kind = !empty($q['sale_id']) ? 'ship' : (!empty($q['lot_id']) ? 'lot' : 'item');
$opts = []; foreach ($tpls as $t) $opts[$t['id']] = (label_kinds()[$t['kind']] ?? '') . ' — ' . $t['name'];
$def = 0; foreach ($tpls as $t) if ($t['kind'] === $kind) { $def = (int)$t['id']; break; }
echo '<form class="card form" method="get" target="_blank" action="index.php"><input type="hidden" name="p" value="print_label">';
foreach ($q as $k => $v) echo '<input type="hidden" name="' . $k . '" value="' . (int)$v . '">';
echo '<h2>چاپِ برچسب</h2><div class="grid">' . ui_field('tpl', ['قالب', 'select', 1, $opts], $def);
if (!$q) echo ui_field('pq', ['کالا', 'text']) . '<input type="hidden" name="product_id"><input type="hidden" name="variant_id">';
if (!empty($q['lot_id'])) { $l = row("SELECT l.*, p.serial FROM lots l JOIN products p ON p.id=l.product_id WHERE l.id=?", [$q['lot_id']]); echo $l && $l['serial'] ? ui_field('units', ['برچسبِ هر دانه', 'select', 1, ['labeled' => 'دانه‌های واردنشده (' . fa_num((int)val("SELECT COUNT(*) FROM wh_units WHERE lot_id=? AND status='labeled'", [$l['id']])) . ')', 'in_stock' => 'دانه‌های موجود', 'none' => 'فقط برچسبِ محموله']]) : ''; }
echo ui_field('copies', ['تعداد نسخه', 'int', 1, [], 1]) . '</div><div class="actions"><button class="btn primary">چاپ همین‌جا</button>';
$st = print_stations(); if ($st && can('labels.print')) echo ' <button type="button" class="btn" id="tost">ارسال به ایستگاهِ چاپ</button> <select id="stn">' . implode('', array_map(fn($s) => '<option value="' . $s['id'] . '">' . h($s['name'] . ($s['online'] ? ' (آنلاین)' : ' (آفلاین)')) . '</option>', $st)) . '</select>';
echo '</div></form>';
?>
<script>document.addEventListener('DOMContentLoaded',function(){var C=window.Cell,f=document.querySelector('form[action="index.php"]');var pq=f.querySelector('[name=pq]');if(pq)C.productPicker(pq,function(p,v){f.product_id.value=p.id;f.variant_id.value=v?v.id:'';});
var b=document.getElementById('tost');if(b)b.onclick=function(){var fd=new FormData(f),q=[];fd.forEach(function(v,k){if(k!=='pq'&&v!=='')q.push(k+'='+encodeURIComponent(v));});var tpl=<?= json_encode(array_column(array_map(fn($t) => ['id' => $t['id'], 'wh' => [(float)$t['w_mm'], (float)$t['h_mm']]], $tpls), 'wh', 'id')) ?>[f.tpl.value];
var data={station_id:document.getElementById('stn').value,url:'index.php?'+q.join('&'),paper_w:tpl[0],paper_h:tpl[1],copies:1,doc_type:'label',title:'برچسب',dedupe:'label:'+q.join('&')};
C.api('print_job',data).then(function(d){if(d.need_confirm){if(!confirm(d.msg))return;data.confirm=1;return C.api('print_job',data);}return d;}).then(function(d){if(d)C.toast('در صفِ چاپ: '+d.status,'s');}).catch(function(e){C.toast(e.message,'e');});};});</script>
