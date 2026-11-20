/* ویرایشگرِ سند (ردیف‌ها مثلِ newItem اینورا) + محاسبهٔ زنده با همان موتورِ سرور */
(function () {
  'use strict';
  var C = window.Cell, box = document.getElementById('docEd'); if (!box) return;
  var form = box.closest('form'), tb = box.querySelector('tbody'), n = 0;
  var units = JSON.parse(box.getAttribute('data-units') || '[]'), rows = JSON.parse(box.getAttribute('data-rows') || '[]');
  function esc(s) { return C.esc(s == null ? '' : s); }
  function add(r) {
    r = r || {}; var i = n++, tr = document.createElement('tr'), nm = 'lines[' + i + ']';
    tr.innerHTML = '<td class="pick"><input class="pq" name="' + nm + '[name]" value="' + esc(r.name) + '" placeholder="شرح یا جست‌وجوی کالا"><input type="hidden" name="' + nm + '[product_id]" value="' + esc(r.product_id || '') + '"><input type="hidden" name="' + nm + '[sale_item_id]" value="' + esc(r.sale_item_id || '') + '">'
      + '<input name="' + nm + '[desc]" value="' + esc(r.desc) + '" placeholder="توضیحِ ردیف" style="margin-top:3px;font-size:.9em"></td>'
      + '<td><input name="' + nm + '[code]" value="' + esc(r.code) + '" dir="ltr" style="width:120px" placeholder="شناسهٔ ۱۳ رقمی"></td>'
      + '<td><input name="' + nm + '[qty]" value="' + esc(r.qty == null ? 1 : r.qty) + '" style="width:62px"></td>'
      + '<td><input name="' + nm + '[unit]" value="' + esc(r.unit || 'عدد') + '" list="docUnits" style="width:72px"></td>'
      + '<td><input class="money" name="' + nm + '[price]" value="' + (r.price ? C.money(r.price) : '') + '" style="width:120px"></td>'
      + '<td style="white-space:nowrap"><input name="' + nm + '[disc]" value="' + esc(r.disc || '') + '" style="width:80px"><select name="' + nm + '[disc_type]"><option value="amount">مبلغ</option><option value="%"' + (r.disc_type === '%' ? ' selected' : '') + '>٪</option></select></td>'
      + '<td><input type="checkbox" name="' + nm + '[taxable]" value="1"' + (r.taxable === 0 || r.taxable === false ? '' : ' checked') + ' title="مشمولِ مالیات"></td>'
      + '<td><input name="' + nm + '[usd]" value="' + esc(r.usd || '') + '" style="width:62px" title="قیمتِ دلاری (اختیاری)"><input name="' + nm + '[markup]" value="' + esc(r.fx && r.fx.markup || '') + '" style="width:44px" title="افزایش ٪"></td>'
      + '<td><button type="button" class="btn sm danger del">×</button></td>';
    tb.appendChild(tr);
    C.productPicker(tr.querySelector('.pq'), function (p, v) {
      tr.querySelector('[name$="[product_id]"]').value = p.id; tr.querySelector('.pq').value = p.name + (v ? ' — ' + v.title : '');
      var pr = tr.querySelector('[name$="[price]"]'); if (!C.toInt(pr.value)) pr.value = C.money(p.price + (v ? +v.price_delta : 0));
      if (p.unit) tr.querySelector('[name$="[unit]"]').value = p.unit; calc();
    });
  }
  rows.forEach(add); if (!rows.length) add();
  box.querySelector('.add').onclick = function () { add(); };
  box.addEventListener('click', function (e) { if (e.target.classList.contains('del')) { e.target.closest('tr').remove(); calc(); } });
  var dl = document.createElement('datalist'); dl.id = 'docUnits'; dl.innerHTML = units.map(function (u) { return '<option value="' + esc(u) + '">'; }).join(''); document.body.appendChild(dl);
  var timer;
  function calc() { clearTimeout(timer); timer = setTimeout(function () {
    var o = C.formObj(form); var out = document.getElementById('calc');
    C.api('doc_calc', o).then(function (t) { if (!t || !t.fmt) { out.textContent = ''; return; }
      out.innerHTML = 'ناخالص ' + t.fmt.sub + ' · تخفیف ' + t.fmt.disc + (t.vat_on ? ' · مالیات ' + t.fmt.vat : '') + (t.roundAdj ? ' · گرد کردن ' + t.fmt.adj : '') + ' · <b>قابلِ پرداخت ' + t.fmt.total + '</b><br><small>' + C.esc(t.words) + ' — شمارهٔ بعدی: ' + C.esc(C.fa(t.number)) + '</small>';
    }).catch(function (e) { out.textContent = e.message; }); }, 300); }
  form.addEventListener('input', calc); form.addEventListener('change', calc); calc();
  var cq = form.querySelector('[name=cust]'); if (cq) { C.customerPicker(cq, function (c) { form.customer_id.value = c.id; cq.value = c.name; calc(); }); cq.addEventListener('input', function () { if (!cq.value) form.customer_id.value = ''; }); }
})();
