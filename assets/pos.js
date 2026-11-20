/* صندوقِ فروش: اسکن/جست‌وجو، سبد، مشتری، تخفیف، پرداختِ چندگانه، ثبت */
(function () {
  'use strict';
  var C = window.Cell, root = document.getElementById('pos'); if (!root) return;
  var V = null, methods = JSON.parse(root.getAttribute('data-methods')), busy = false;
  var $ = function (s) { return root.querySelector(s); };
  function render(v) {
    V = v; var h = '';
    v.items.forEach(function (i) {
      var c = i.calc || {};
      h += '<tr><td>' + C.esc(i.name + (i.vt ? ' — ' + i.vt : '')) + (i.ucode ? '<br><code>' + C.esc(i.ucode) + '</code>' : '') + '</td>' +
        '<td>' + (i.unit_id ? C.esc(i.qty_show) : '<input class="q" data-id="' + i.id + '" value="' + C.fa(i.qty) + '" style="width:64px"' + (i.ucode ? ' disabled' : '') + '>') + '</td>' +
        '<td class="num">' + C.money(i.price) + '</td><td><button class="btn sm dsc" data-id="' + i.id + '">' + (i.disc > 0 ? C.money(i.disc) : 'تخفیف') + '</button></td>' +
        '<td class="num">' + C.money((c.net || 0) + (c.tax || 0)) + '</td><td><button class="btn sm danger rm" data-id="' + i.id + '">×</button></td></tr>';
    });
    $('.cart tbody').innerHTML = h || '<tr><td colspan="6" class="muted">سبد خالی است؛ کد را اسکن کنید.</td></tr>';
    var t = v.totals || {};
    $('.sums').innerHTML = 'ناخالص ' + C.money(t.sub || 0) + ' · تخفیف ' + C.money((t.lineDisc || 0) + (t.docDisc || 0)) + ' · مالیات ' + C.money(t.vat || 0) + (v.cart && +v.cart.adj_pct ? ' · <b>' + C.fa(String(+v.cart.adj_pct)) + '٪ روی همهٔ اقلام</b>' : '');
    $('.tot').textContent = C.money(t.total || 0) + ' ریال';
    var cu = v.customer;
    $('.cust-info').innerHTML = cu ? '<b>' + C.esc(cu.name) + '</b> ' + C.fa(cu.mobile || '') + (cu.is_partner == 1 ? ' <span class="badge warn">همکار</span>' : '') + '<br><small>بدهیِ نسیه: ' + C.money(cu.balance) + ' · سقف: ' + C.money(cu.credit_limit) + ' · کیف پول: ' + C.money(cu.wallet) + '</small> <button class="btn sm cust-x">حذف</button>' : '<span class="muted">مشتریِ گذری</span>';
    $('.partner').style.display = cu && cu.is_partner == 1 ? '' : 'none';
    var xo = $('.pmode') ? $('.pmode').querySelector('option[value=transfer]') : null;
    if (xo) { xo.disabled = !(cu && cu.xfer); xo.hidden = xo.disabled; if (xo.disabled && $('.pmode').value === 'transfer') $('.pmode').value = 'self'; }
    remain();
  }
  function call(a, data) { if (busy) return Promise.resolve(); busy = true; data.cart_id = V.cart.id; return C.api(a, data).then(function (d) { if (d && d.items) render(d); if (d && d.msg) C.toast(d.msg, 's'); return d; }).catch(function (e) { C.toast(e.message, 'e'); throw e; }).then(function (d) { busy = false; return d; }, function (e) { busy = false; throw e; }); }
  function scan(code) { if (!code) return Promise.resolve(false); $('.msg').textContent = 'در حالِ بررسی: ' + code; var box = function () { return document.getElementById('scanBox'); };
    return call('pos_scan', { code: code, qty: C.toInt($('.qty').value) || 1 }).then(function (d) { $('.msg').textContent = d && d.msg ? 'خوانده شد: ' + d.msg + ' — کالای بعدی' : ''; $('.qty').value = '۱'; if (window.scanFeedback) window.scanFeedback.ok(box() && !box().hidden ? box() : null, code); return true; },
      function (e) { $('.msg').textContent = e.message; if (window.scanFeedback) window.scanFeedback.fail(box() && !box().hidden ? box() : null, e.message); return false; }); }
  // دوربین (scan-box.js) مقدار را با رویدادِ ساختگی می‌گذارد؛ تایپِ دستی با Enter
  $('.scan-in').addEventListener('input', function (e) { if (e.isTrusted) return; var v = this.value.trim(); this.value = ''; scan(v); });
  // اسکنرِ USB یا تایپ: Enter
  $('.scan-in').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); var v = this.value.trim(); this.value = ''; scan(v); } });
  root.addEventListener('change', function (e) { if (e.target.classList.contains('q')) call('cart_qty', { item_id: e.target.getAttribute('data-id'), qty: C.en(e.target.value) }); });
  root.addEventListener('click', function (e) {
    var t = e.target;
    if (t.classList.contains('rm')) call('cart_remove', { item_id: t.getAttribute('data-id') });
    if (t.classList.contains('dsc')) { var d = prompt('تخفیفِ ردیف (مبلغ یا درصد مثلِ ۵٪):'); if (d !== null) call('cart_disc', { item_id: t.getAttribute('data-id'), disc: C.en(d).replace('٪', '%') }); }
    if (t.classList.contains('adjall')) { var ap = prompt('درصدِ تغییرِ قیمتِ همهٔ اقلام (مثلاً ۵ یا ۳-؛ صفر = برگشت به قیمتِ اصلی):', V && V.cart ? C.fa(String(+V.cart.adj_pct || 0)) : '0'); if (ap !== null) call('cart_adjust', { pct: C.en(ap).replace('٪', '') }); }
    if (t.classList.contains('docdisc')) { var dd = prompt('تخفیفِ کلِ فاکتور (مبلغ یا درصد):'); if (dd !== null) call('cart_doc_disc', { disc: C.en(dd).replace('٪', '%') }); }
    if (t.classList.contains('cust-x')) call('cart_customer', { customer_id: 0 });
    if (t.classList.contains('cust-new')) { var m = prompt('موبایلِ مشتری:'); if (!m) return; var n = prompt('نام:') || ''; C.api('customer_quick', { mobile: C.en(m), name: n }).then(function (r) { call('cart_customer', { customer_id: r.id }); }).catch(function (er) { C.toast(er.message, 'e'); }); }
    if (t.classList.contains('addpay')) addPay('card', 0);
    if (t.classList.contains('delpay')) { t.closest('.pay-row').remove(); remain(); }
    if (t.classList.contains('fill')) { var row = t.closest('.pay-row'); row.querySelector('.pa').value = ''; var r = left(); row.querySelector('.pa').value = C.money(Math.max(0, r)); remain(); }
    if (t.classList.contains('cam')) { var b = document.getElementById('scanBox'); if (b && !b.hidden) window.stopScan(); else if (window.startScan) window.startScan('posScan'); }
    if (t.classList.contains('checkout')) checkout();
  });
  C.customerPicker($('.cust-q'), function (c) { $('.cust-q').value = ''; call('cart_customer', { customer_id: c.id }); });
  C.productPicker($('.prod-q'), function (p, v) { $('.prod-q').value = ''; call('cart_add', { product_id: p.id, variant_id: v ? v.id : 0, qty: C.toInt($('.qty').value) || 1 }); });
  function addPay(m, a) {
    var d = document.createElement('div'); d.className = 'pay-row';
    d.innerHTML = '<select class="pm">' + Object.keys(methods).filter(function (k) { return k !== 'channel' && k !== 'offset' && k !== 'exchange'; }).map(function (k) { return '<option value="' + k + '"' + (k === m ? ' selected' : '') + '>' + methods[k] + '</option>'; }).join('') + '</select><input class="pa money" value="' + (a ? C.money(a) : '') + '" placeholder="مبلغ"><button type="button" class="btn sm fill" title="باقی‌مانده">=</button>';
    $('.pays').appendChild(d); d.querySelector('.pa').addEventListener('input', remain); d.querySelector('.pm').addEventListener('change', remain);
  }
  var offs = [];
  function offSum() { var s = 0; offs.forEach(function (o) { s += o.value; }); return s; }
  function drawOffs() { var el = $('.offs'); if (!el) return; el.innerHTML = offs.map(function (o, i) { return '<div>تهاتر: ' + C.esc(o.title) + ' × ' + C.fa(String(o.qty)) + ' = <b>' + C.money(o.value) + '</b> <button type="button" class="btn sm offdel" data-i="' + i + '">×</button></div>'; }).join(''); remain(); }
  if ($('.offadd')) {
    var oq = document.createElement('input'); oq.className = 'offq'; oq.placeholder = 'کالای تهاتری (جست‌وجو)…'; oq.style.display = 'none'; $('.offadd').parentNode.insertBefore(oq, $('.offs'));
    $('.offadd').addEventListener('click', function () { oq.style.display = ''; oq.focus(); });
    function addOff(pid, vid, title, cost) { var q = parseInt(C.en(prompt('تعداد:', '1') || '0'), 10); if (!q) return; var v = C.toInt(prompt('ارزشِ کل به قیمتِ خرید (ریال):', cost ? C.money(cost * q) : '') || '0'); if (!v) return; offs.push({ product_id: pid || '', variant_id: vid || '', title: title, qty: q, value: v }); oq.value = ''; oq.style.display = 'none'; drawOffs(); }
    C.productPicker(oq, function (p, v) { C.api('offset_cost', { product_id: p.id, variant_id: v ? v.id : '' }).then(function (r) { addOff(p.id, v ? v.id : '', p.name + (v ? ' — ' + v.title : ''), r.cost); }); });
    oq.addEventListener('keydown', function (e) { if (e.key === 'Enter' && oq.value.trim()) { e.preventDefault(); addOff('', '', oq.value.trim(), 0); } });
    root.addEventListener('click', function (e) { if (e.target.classList.contains('offdel')) { offs.splice(+e.target.getAttribute('data-i'), 1); drawOffs(); } });
  }
  function pays() { var o = []; root.querySelectorAll('.pay-row').forEach(function (r) { var a = C.toInt(r.querySelector('.pa').value); if (a) o.push({ method: r.querySelector('.pm').value, amount: a }); }); return o; }
  function left() { var tot = (V && V.totals.total) || 0, s = offSum(); pays().forEach(function (p) { s += p.amount; }); return tot - s; }
  function remain() { var r = left(), el = $('.remain'); if (r > 0) { el.textContent = C.money(r) + ' ریال باقی‌مانده'; el.className = 'remain bad'; } else if (r < 0) { el.textContent = 'باقیِ پولِ نقد: ' + C.money(-r); el.className = 'remain good'; } else { el.textContent = 'جمع برابر است'; el.className = 'remain good'; } }
  function checkout() {
    if (!V || !V.items.length) return C.toast('سبد خالی است', 'e');
    var p = pays(); if (!p.length && !offs.length) { addPay('cash', V.totals.total); p = pays(); } if (offs.length) p.push({ method: 'offset', amount: offSum() });
    var data = { offsets: offs, payments: p, partner_mode: $('.pmode') ? $('.pmode').value : '' };
    if (data.partner_mode === 'ship') { data.ship_to = { name: prompt('نامِ گیرنده:') || '', mobile: C.en(prompt('موبایلِ گیرنده:') || ''), address: prompt('نشانی:') || '' }; data.defer_stock = 1; }
    if (data.partner_mode === 'hold') data.defer_stock = 1;
    if (data.partner_mode === 'transfer') { if (!confirm('واریز به حساب: ' + ((V.customer && V.customer.xfer) || '') + '\nسفارش تا تأییدِ فیش در انبار رزرو می‌ماند. ثبت شود؟')) return; data.payments = []; data.defer_stock = 1; }
    var due = $('.due') ? $('.due').value : ''; if (due) data.due = due;
    $('.checkout').disabled = true;
    call('pos_checkout', data).then(function (r) {
      var html = '<p class="ok">فروشِ <b>' + C.esc(C.fa(r.number)) + '</b> ثبت شد.' + (r.change ? ' باقیِ پول: <b>' + C.money(r.change) + '</b> ریال' : '') + '</p><div class="actions"><a class="btn" target="_blank" href="index.php?p=print_receipt&id=' + r.sale_id + '&autoprint=1">چاپِ رسید</a><a class="btn" href="index.php?p=sale&id=' + r.sale_id + '">فاکتور / جزئیات</a></div>';
      $('.done').innerHTML = html; root.querySelectorAll('.pay-row').forEach(function (x) { x.remove(); }); offs = []; drawOffs();
      return C.api('cart_open', {}).then(render);
    }).catch(function () {}).then(function () { $('.checkout').disabled = false; $('.scan-in').focus(); });
  }
  C.api('cart_open', {}).then(function (v) { render(v); addPay('cash', 0); $('.scan-in').focus(); }).catch(function (e) { C.toast(e.message, 'e'); });
})();
