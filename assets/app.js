/* 8CELL — اسکریپتِ مشترک (بدونِ ?. برای مرورگرهای قدیمی) */
(function () {
  'use strict';
  var FA = '۰۱۲۳۴۵۶۷۸۹';
  function fa(s) { return String(s).replace(/[0-9]/g, function (d) { return FA[+d]; }); }
  function en(s) { return String(s == null ? '' : s).replace(/[۰-۹]/g, function (d) { return FA.indexOf(d); }).replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }); }
  function money(n) { n = parseInt(n, 10) || 0; var s = Math.abs(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '٬'); return (n < 0 ? '−' : '') + fa(s); }
  function toInt(v) { var s = en(v).replace(/[^0-9\-]/g, ''); return s === '' || s === '-' ? 0 : parseInt(s, 10); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(msg, kind) {
    var t = document.getElementById('toast'); if (!t) { alert(msg); return; }
    var d = document.createElement('div'); d.className = kind || ''; d.textContent = msg; t.appendChild(d);
    setTimeout(function () { if (d.parentNode) d.parentNode.removeChild(d); }, kind === 'e' ? 6500 : 3500);
  }
  function api(a, data, method) {
    method = method || 'POST';
    var url = 'api.php?a=' + encodeURIComponent(a), opt = { method: method, credentials: 'same-origin', headers: { 'X-CSRF': window.CSRF || '' } };
    if (method === 'GET') { var q = []; for (var k in (data || {})) q.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k])); if (q.length) url += '&' + q.join('&'); }
    else if (data instanceof FormData) opt.body = data;
    else { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(data || {}); }
    return fetch(url, opt).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'پاسخِ نامعتبر از سرور (' + r.status + ')' }; }); })
      .then(function (j) { if (!j.ok) { var e = new Error(j.error || 'خطا'); e.api = j; throw e; } return j.data; });
  }
  /** FormData با نام‌های براکتی → شیء */
  function formObj(form) {
    var o = {}, fd = new FormData(form);
    fd.forEach(function (v, name) {
      if (typeof v !== 'string') return;
      var m = name.match(/^([^\[]+)((?:\[[^\]]*\])*)$/); if (!m) return;
      var keys = [m[1]]; (m[2].match(/\[[^\]]*\]/g) || []).forEach(function (x) { keys.push(x.slice(1, -1)); });
      var cur = o;
      for (var i = 0; i < keys.length; i++) {
        var k = keys[i], last = i === keys.length - 1;
        if (last) { if (k === '') cur.push(v); else cur[k] = v; }
        else { if (cur[k] == null || typeof cur[k] !== 'object') cur[k] = keys[i + 1] === '' ? [] : {}; cur = cur[k]; }
      }
    });
    return o;
  }
  function then(how, data) {
    how = how || 'reload';
    if (how === 'none') return;
    if (how.indexOf('go:') === 0) { var u = how.slice(3); if (data && data.id) u = u.replace('{id}', data.id); location.href = u; return; }
    if (how.indexOf('fn:') === 0) { var f = window[how.slice(3)]; if (f) f(data); return; }
    setTimeout(function () { location.reload(); }, 400);
  }
  function showResult(d) {
    if (!d) return toast('انجام شد', 's');
    if (d.msg) return toast(d.msg, d.ok === false ? 'e' : 's');
    if (d.report) return toast('رسید: ' + d.report, 's');
    if (d.token && d.id) return prompt('توکن را در برنامهٔ ایستگاه وارد کنید (فقط همین یک بار نمایش داده می‌شود):', d.token);
    if (d.url) return prompt('لینک:', d.url);
    if (d.secret) return prompt('کلیدِ تازه (فقط همین یک بار):', d.secret);
    if (d.test_code) toast('حالتِ آزمایشی — کد: ' + d.test_code, 's');
    toast('انجام شد', 's');
  }
  document.addEventListener('submit', function (ev) {
    var f = ev.target; if (!f.getAttribute || !f.getAttribute('data-api')) return;
    ev.preventDefault();
    if (f.getAttribute('data-confirm') && !confirm(f.getAttribute('data-confirm'))) return;
    var btn = f.querySelector('[type=submit]'); if (btn) btn.disabled = true;
    var hasFile = f.querySelector('input[type=file]'); var data = hasFile ? new FormData(f) : formObj(f);
    if (f.__extend) data = f.__extend(data);
    api(f.getAttribute('data-api'), data).then(function (d) {
      if (d && d.need_confirm) { if (confirm(d.msg)) { var o = formObj(f); o.confirm = 1; return api(f.getAttribute('data-api'), o).then(function (d2) { showResult(d2); then(f.getAttribute('data-then'), d2); }); } return; }
      showResult(d); then(f.getAttribute('data-then'), d);
    }).catch(function (e) { toast(e.message, 'e'); }).then(function () { if (btn) btn.disabled = false; });
  });
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest ? ev.target.closest('[data-act]') : null;
    if (el) {
      ev.preventDefault();
      if (el.getAttribute('data-confirm') && !confirm(el.getAttribute('data-confirm'))) return;
      var args = {}; try { args = JSON.parse(el.getAttribute('data-args') || '{}'); } catch (e) {}
      var pr = el.getAttribute('data-prompt');
      if (pr) { var p = pr.split(':'); var v = prompt(p.slice(1).join(':') || 'مقدار'); if (v === null) return; args[p[0]] = v; }
      el.disabled = true;
      api(el.getAttribute('data-act'), args).then(function (d) {
        if (d && d.need_confirm) { if (confirm(d.msg)) { args.confirm = 1; return api(el.getAttribute('data-act'), args).then(function (d2) { showResult(d2); then(el.getAttribute('data-then'), d2); }); } return; }
        showResult(d); then(el.getAttribute('data-then'), d);
      }).catch(function (e) { toast(e.message, 'e'); }).then(function () { el.disabled = false; });
      return;
    }
    var tr = ev.target.closest ? ev.target.closest('tr[data-href]') : null;
    if (tr && !ev.target.closest('a,button,input')) location.href = tr.getAttribute('data-href');
    if (ev.target.closest && ev.target.closest('.menu-t')) { var s = document.querySelector('.side'); if (s) s.classList.toggle('open'); }
  });
  // قالب‌بندیِ مبلغ هنگامِ تایپ
  document.addEventListener('input', function (ev) {
    var t = ev.target; if (!t.classList || !t.classList.contains('money')) return;
    var raw = en(t.value).replace(/[^0-9\-%]/g, ''); if (raw.indexOf('%') >= 0 || raw === '' || raw === '-') { t.value = fa(raw); return; }
    t.value = money(parseInt(raw, 10));
  });
  // ── ویرایشگرِ ردیف‌ها
  function linesEditor(box) {
    var name = box.getAttribute('data-name'), cols = JSON.parse(box.getAttribute('data-cols')), rows = JSON.parse(box.getAttribute('data-rows') || '[]'), pick = box.getAttribute('data-pick') === '1';
    var tb, n = 0;
    box.innerHTML = '<table class="tbl"><thead><tr>' + (pick ? '<th>کالا</th>' : '') + cols.map(function (c) { return '<th>' + esc(c.t) + '</th>'; }).join('') + '<th></th></tr></thead><tbody></tbody></table><button type="button" class="btn sm add">+ ردیف</button>';
    tb = box.querySelector('tbody');
    function addRow(r) {
      r = r || {}; var i = n++, tr = document.createElement('tr'), h = '';
      if (pick) h += '<td class="pick"><input class="pq" placeholder="نام یا کدِ کالا…" value="' + esc(r.title || '') + '"><input type="hidden" name="' + name + '[' + i + '][product_id]" value="' + esc(r.product_id || '') + '"><input type="hidden" name="' + name + '[' + i + '][variant_id]" value="' + esc(r.variant_id || '') + '"></td>';
      cols.forEach(function (c) { var v = r[c.k] != null ? r[c.k] : (c.d || ''); h += '<td><input name="' + name + '[' + i + '][' + c.k + ']" class="' + (c.type === 'money' ? 'money' : '') + '" value="' + esc(c.type === 'money' && v !== '' ? money(v) : v) + '"' + (c.w ? ' style="width:' + c.w + '"' : '') + '></td>'; });
      h += '<td><button type="button" class="btn sm danger del">×</button></td>'; tr.innerHTML = h; tb.appendChild(tr);
      if (pick) productPicker(tr.querySelector('.pq'), function (p, v) {
        tr.querySelector('[name$="[product_id]"]').value = p.id; tr.querySelector('[name$="[variant_id]"]').value = v ? v.id : '';
        var t = tr.querySelector('[name$="[title]"]'); if (t) t.value = p.name + (v ? ' — ' + v.title : '');
        var pr = tr.querySelector('[name$="[price]"]'); if (pr && !toInt(pr.value)) pr.value = money(p.price + (v ? +v.price_delta : 0));
        box.dispatchEvent(new Event('lines-change', { bubbles: true }));
      });
    }
    rows.forEach(addRow); if (!rows.length) addRow();
    box.querySelector('.add').onclick = function () { addRow(); };
    box.addEventListener('click', function (e) { if (e.target.classList.contains('del')) { var tr = e.target.closest('tr'); tr.parentNode.removeChild(tr); box.dispatchEvent(new Event('lines-change', { bubbles: true })); } });
    box.addEventListener('change', function () { box.dispatchEvent(new Event('lines-change', { bubbles: true })); });
  }
  /** جست‌وجوی کالا (با ترکیب‌ها) */
  function productPicker(input, onPick) {
    var ac = document.createElement('div'), timer; ac.className = 'ac'; ac.style.display = 'none'; input.parentNode.style.position = 'relative'; input.parentNode.appendChild(ac);
    input.addEventListener('input', function () {
      clearTimeout(timer); var q = input.value.trim(); if (q.length < 2) { ac.style.display = 'none'; return; }
      timer = setTimeout(function () {
        api('product_search', { q: q }, 'GET').then(function (list) {
          var h = ''; list.forEach(function (p, i) { if (p.variants && p.variants.length) p.variants.forEach(function (v, j) { h += '<div data-i="' + i + '" data-v="' + j + '">' + esc(p.name) + ' — ' + esc(v.title) + ' <small>' + money(p.price + (+v.price_delta)) + '</small></div>'; }); else h += '<div data-i="' + i + '">' + esc(p.name) + ' <small>' + money(p.price) + ' · موجودی ' + fa(p.stock) + '</small></div>'; });
          ac.innerHTML = h || '<div>یافت نشد</div>'; ac.style.display = 'block';
          ac.onclick = function (e) { var d = e.target.closest('[data-i]'); if (!d) return; var p = list[+d.getAttribute('data-i')], v = d.hasAttribute('data-v') ? p.variants[+d.getAttribute('data-v')] : null; input.value = p.name + (v ? ' — ' + v.title : ''); ac.style.display = 'none'; onPick(p, v); };
        }).catch(function (e) { toast(e.message, 'e'); });
      }, 250);
    });
    input.addEventListener('blur', function () { setTimeout(function () { ac.style.display = 'none'; }, 200); });
  }
  /** جست‌وجوی مشتری */
  function customerPicker(input, onPick) {
    var ac = document.createElement('div'), timer; ac.className = 'ac'; ac.style.display = 'none'; input.parentNode.style.position = 'relative'; input.parentNode.appendChild(ac);
    input.addEventListener('input', function () {
      clearTimeout(timer); var q = input.value.trim(); if (q.length < 2) { ac.style.display = 'none'; return; }
      timer = setTimeout(function () { api('customer_search', { q: q }, 'GET').then(function (list) {
        ac.innerHTML = list.map(function (c, i) { return '<div data-i="' + i + '">' + esc(c.name) + ' <small>' + fa(c.mobile || '') + (c.is_partner == 1 ? ' · همکار' : '') + '</small></div>'; }).join('') || '<div class="muted">یافت نشد</div>';
        ac.style.display = 'block'; ac.onclick = function (e) { var d = e.target.closest('[data-i]'); if (!d) return; ac.style.display = 'none'; onPick(list[+d.getAttribute('data-i')]); };
      }); }, 250);
    });
    input.addEventListener('blur', function () { setTimeout(function () { ac.style.display = 'none'; }, 200); });
  }
  document.querySelectorAll('.lines-ed').forEach(linesEditor);
  document.querySelectorAll('input.ppick').forEach(function (i) { productPicker(i, function (p, v) { var f = i.form; if (f.product_id) f.product_id.value = p.id; if (f.variant_id) f.variant_id.value = v ? v.id : ''; }); });
  document.querySelectorAll('input.cpick').forEach(function (i) { customerPicker(i, function (c) { var f = i.form; if (f.customer_id) f.customer_id.value = c.id; i.value = c.name; }); });
  document.querySelectorAll('table.filterable').forEach(function (t) {
    var inp = document.createElement('input'); inp.placeholder = 'جست‌وجو در جدول…'; inp.style.maxWidth = '260px'; inp.style.margin = '0 0 8px'; t.parentNode.parentNode.insertBefore(inp, t.parentNode);
    inp.oninput = function () { var q = en(inp.value).toLowerCase(); t.querySelectorAll('tbody tr').forEach(function (r) { r.style.display = en(r.textContent).toLowerCase().indexOf(q) >= 0 ? '' : 'none'; }); };
  });
  // اختلافِ ساعتِ دستگاه با تهران (برای سندهایی که ساعتِ کلاینت را نشان می‌دهند)
  if (window.CSRF) api('time', { client_ms: Date.now() }, 'GET').then(function (t) { window.CLOCK_OFFSET = t.offset_ms || 0; }).catch(function () {});
  window.Cell = { api: api, toast: toast, fa: fa, en: en, money: money, toInt: toInt, esc: esc, formObj: formObj, productPicker: productPicker, customerPicker: customerPicker, then: then, showResult: showResult };
})();
