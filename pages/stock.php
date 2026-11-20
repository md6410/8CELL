<?php
$tab = $_GET['tab'] ?? 'scan';
echo ui_tabs(['scan' => 'اسکنِ ورود/خروج', 'overview' => 'موجودی', 'lot' => 'محموله و برچسبِ دانه', 'moves' => 'گردش'], $tab, 'stock');
if ($tab === 'scan') { ?>
<section class="card" id="whscan"><header><h2>اسکنِ انباردار</h2></header><p class="muted">چاپِ برچسب موجودی نمی‌سازد؛ اسکنِ ورود می‌سازد. کدِ ۳۳ نویسه = یک دانه؛ کدِ ۲۷ نویسه = محموله (با تعداد).</p>
<div class="grid" style="grid-template-columns:140px 1fr 90px auto"><select class="mode"><?php if (can('stock.in')) echo '<option value="stock_in">ورود</option>'; if (can('stock.out')) echo '<option value="stock_out">خروج</option>'; ?><option value="scan_lookup">فقط شناسایی</option></select>
<input id="whScan" class="code scan-in" placeholder="اسکن و Enter…" autocomplete="off" data-scan-keep data-scan-confirm><input class="qty" value="۱"><button class="btn cam" type="button">📷</button></div>
<div class="scan-view" id="scanBox" hidden style="margin-top:8px"><video id="scanVideo" playsinline muted autoplay></video><div class="scan-frame"></div><button type="button" class="btn sm" onclick="stopScan()">بستنِ دوربین</button></div><div class="scan-msg msg" style="margin:6px 0;min-height:1.6em"></div><ol class="log"></ol></section>
<script src="assets/vendor/zxing.min.js" defer></script><script src="assets/scan-feedback.js" defer></script><script src="assets/scan-box.js" defer></script>
<script>document.addEventListener('DOMContentLoaded',function(){var C=window.Cell,r=document.getElementById('whscan'),inp=r.querySelector('.code'),msg=r.querySelector('.msg');
function go(code){var m=r.querySelector('.mode').value;var p=m==='scan_lookup'?C.api('scan_lookup',{code:code},'GET'):C.api(m,{code:code,qty:C.toInt(r.querySelector('.qty').value)||1});
return p.then(function(d){var t=m==='scan_lookup'?(d.label+(d.card?': '+d.card.name+' · موجودی '+C.fa(d.card.stock):'')+(d.status?' · '+d.status:'')):(m==='stock_in'?'ورود: ':'خروج: ')+code;msg.textContent='✔ '+t;msg.className='scan-msg ok';var li=document.createElement('li');li.textContent=t;r.querySelector('.log').prepend(li);var b=document.getElementById('scanBox');window.scanFeedback&&window.scanFeedback.ok(b&&!b.hidden?b:null,code);return true;})
.catch(function(e){msg.textContent='✖ '+e.message;msg.className='scan-msg err';var b=document.getElementById('scanBox');window.scanFeedback&&window.scanFeedback.fail(b&&!b.hidden?b:null,e.message);return false;});}
inp.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();var v=inp.value.trim();inp.value='';if(v)go(v);}});inp.addEventListener('input',function(e){if(e.isTrusted)return;var v=inp.value.trim();inp.value='';if(v)go(v);});inp.focus();
r.querySelector('.cam').onclick=function(){var b=document.getElementById('scanBox');if(b&&!b.hidden)window.stopScan();else window.startScan('whScan');};});</script>
<?php } elseif ($tab === 'overview') {
    $wf = req_int('warehouse_id'); $q = trim((string)($_GET['q'] ?? ''));
    echo '<form class="filters"><input type="hidden" name="p" value="stock"><input type="hidden" name="tab" value="overview"><label>انبار<select name="warehouse_id"><option value="">همه</option>' . implode('', array_map(fn($w) => '<option value="' . $w['id'] . '"' . ($wf == $w['id'] ? ' selected' : '') . '>' . h($w['name']) . '</option>', warehouse_list())) . '</select></label><label>جست‌وجو<input name="q" value="' . h($q) . '"></label><button class="btn">نمایش</button></form>';
    echo ui_card('موجودیِ زنده (جمعِ حرکت‌ها)', ui_table(['code' => ['کدِ محموله', fn($r) => '<code>' . h(wh_pretty_code($r['code'])) . '</code>'], 'name' => ['کالا', fn($r) => h($r['name'] . ($r['vtitle'] ? ' — ' . $r['vtitle'] : ''))], 'wname' => 'انبار', 'ownership' => ['مالکیت', fn($r) => h(wh_ownerships()[$r['ownership']] ?? '')],
        'on_hand' => ['موجودی', fn($r) => qty_show((int)$r['on_hand'], $r['unit_id'] ? (int)$r['unit_id'] : null)], 'reserved' => ['رزرو', 'num'], 'labeled' => ['برچسبِ واردنشده', 'num'], 'unit_cost' => ['بهای واحد', 'money'],
        'lb' => ['', fn($r) => '<a class="btn sm" href="' . h(page_url('labels', ['lot_id' => $r['lot_id']])) . '">برچسب</a>']], wh_overview(['warehouse_id' => $wf, 'q' => $q]), ['filter' => 1]));
} elseif ($tab === 'lot') {
    echo ui_card('ساختنِ محموله / دانه‌ها (ورودِ بی‌فاکتور)', '<p class="muted">برای خرید از فاکتورِ خرید استفاده کنید تا بهای تمام‌شده و بدهی ثبت شود. این‌جا برای موجودیِ اولیه است.</p>' . ui_form('lot_create', ['pq' => ['کالا', 'text', 1], 'product_id' => ['', 'hidden'], 'variant_id' => ['', 'hidden'],
        'warehouse_id' => ['انبار', 'select', 1, array_column(warehouse_list(), 'name', 'id')], 'ownership' => ['مالکیت', 'select', 1, wh_ownerships()], 'seller_code' => ['کدِ فروشنده (فقط کالای امانی؛ ۱ تا ۴ نویسهٔ لاتین)', 'text'], 'qty' => ['تعداد', 'int'], 'unit_cost' => ['بهای واحد', 'money']], [], ['then' => 'none', 'submit' => 'ساختن']))
        . '<script>document.addEventListener("DOMContentLoaded",function(){var i=document.querySelector("[name=pq]");i.classList.add("ppick");Cell.productPicker(i,function(p,v){var f=i.form;f.product_id.value=p.id;f.variant_id.value=v?v.id:"";});});</script>';
} else {
    echo ui_card('آخرین حرکت‌ها', ui_table(['at' => ['زمان', 'datetime'], 'kind' => 'نوع', 'name' => 'کالا', 'code' => ['کد', 'code'], 'qty' => ['تعداد', 'num'], 'unit_cost' => ['بها', 'money'], 'ref' => 'مرجع', 'un' => 'کاربر'],
        rows("SELECT m.*, p.name, COALESCE(u.code, l.code) code, CONCAT(COALESCE(m.ref_type,''),' ',COALESCE(m.ref_id,'')) ref, us.name un FROM stock_moves m JOIN lots l ON l.id=m.lot_id JOIN products p ON p.id=m.product_id LEFT JOIN wh_units u ON u.id=m.unit_id LEFT JOIN users us ON us.id=m.user_id ORDER BY m.id DESC LIMIT 300"), ['filter' => 1]));
}
