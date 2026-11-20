/* صفحه‌بندیِ A4 سند — پورتِ pgDo/pgShrink/pgSplit اینورا: اندازه‌گیری در خودِ مرورگر؛
   اول کوچک‌کردنِ قلم (تا ۸۵٪) و اگر جا نشد، شکستنِ ردیف‌ها با «جمع نقل از/به صفحهٔ بعد». بدونِ ?. */
(function () {
  'use strict';
  var PX = 96 / 25.4, H = 293.5, FIT = [1, .97, .94, .91, .88, .85], PARTIES = '.f-box,.b-parties,.m-parties,.fr-parties';
  var FA = '۰۱۲۳۴۵۶۷۸۹';
  function fa(s) { return String(s).replace(/[0-9]/g, function (d) { return FA[+d]; }); }
  function money(v, cur, isFa) { var dec = cur === 'USD' ? 2 : 0; var s = Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: dec }); return isFa ? fa(s).replace(/,/g, '٬').replace(/\./g, '٫') : s; }
  function mm(el) { return el.getBoundingClientRect().height / PX; }
  function host() { var h = document.getElementById('pgHost'); if (!h) { h = document.createElement('div'); h.id = 'pgHost'; h.style.cssText = 'position:absolute;left:-12000px;top:0;width:210mm;visibility:hidden;pointer-events:none'; document.body.appendChild(h); } return h; }
  function free(p) { p.style.minHeight = '0'; p.style.height = 'auto'; }
  function shrink(root, p) {
    var fs0 = parseFloat(p.style.getPropertyValue('--fs')) || 10, ok = false; root.appendChild(p); free(p);
    for (var i = 0; i < FIT.length; i++) { p.style.setProperty('--fs', (fs0 * FIT[i]).toFixed(2) + 'pt'); p.classList.add('pg-fit'); if (mm(p) <= H) { ok = true; break; } }
    if (!ok) { p.style.setProperty('--fs', fs0 + 'pt'); p.classList.remove('pg-fit'); }
    p.remove(); p.style.minHeight = ''; p.style.height = ''; return ok;
  }
  function split(root, paper) {
    var tbl = paper.querySelector('table.items'); if (!tbl || !tbl.tBodies[0]) return null;
    var rows = Array.prototype.slice.call(tbl.tBodies[0].rows), n = rows.length; if (n < 2) return null;
    var box = tbl.parentElement, kids = Array.prototype.slice.call(box.children), ti = kids.indexOf(tbl);
    var pi = -1; for (var k = ti + 1; k < kids.length; k++) if (kids[k].classList.contains('push')) { pi = k; break; } if (pi < 0) pi = kids.length;
    var hr = tbl.tHead && tbl.tHead.rows[tbl.tHead.rows.length - 1], ncols = 0; if (hr) for (var c = 0; c < hr.cells.length; c++) ncols += hr.cells[c].colSpan || 1; if (!ncols) ncols = 2;
    var amts = rows.map(function (r) { return parseFloat(r.getAttribute('data-amt')) || 0; });
    var cur = paper.getAttribute('data-cur') || 'IRR', isFa = paper.getAttribute('data-fa') !== '0';
    function sumTo(k) { var t = 0; for (var i = 0; i < k; i++) t += amts[i]; return t; }
    function carry(lab, v) { return '<tr class="pg-carry"><td colspan="' + Math.max(1, ncols - 1) + '" class="l">' + lab + '</td><td class="m">' + money(v, cur, isFa) + '</td></tr>'; }
    function build(a, b, first, last, no, of) {
      var p = paper.cloneNode(true), t = p.querySelector('table.items'), bx = t.parentElement, ks = Array.prototype.slice.call(bx.children), tb = t.tBodies[0]; tb.textContent = '';
      if (!first) Array.prototype.forEach.call(p.querySelectorAll(PARTIES), function (e) { e.remove(); });
      if (!last) { if (t.tFoot) t.tFoot.remove(); ks.slice(ti + 1, pi).forEach(function (e) { e.remove(); }); t.insertAdjacentHTML('afterend', '<div class="pg-cont">ادامه در صفحهٔ بعد</div>'); }
      if (!first) tb.insertAdjacentHTML('beforeend', carry('جمع نقل از صفحهٔ قبل', sumTo(a)));
      for (var i = a; i < b; i++) tb.appendChild(rows[i].cloneNode(true));
      if (!last) tb.insertAdjacentHTML('beforeend', carry('جمع نقل به صفحهٔ بعد', sumTo(b)));
      p.classList.add('pg'); p.insertAdjacentHTML('beforeend', '<div class="pg-no">صفحهٔ ' + (isFa ? fa(no) : no) + ' از ' + (isFa ? fa(of) : of) + '</div>'); return p;
    }
    function fits(p) { root.appendChild(p); free(p); var ok = mm(p) <= H; p.remove(); p.style.minHeight = ''; p.style.height = ''; return ok; }
    var plan = [], i = 0;
    while (i < n && plan.length < 400) {
      var first = !plan.length;
      if (fits(build(i, n, first, true, 88, 88))) { plan.push([i, n, first, true]); i = n; break; }
      var lo = 1, hi = n - i, best = 0;
      while (lo <= hi) { var m = (lo + hi) >> 1; if (fits(build(i, i + m, first, false, 88, 88))) { best = m; lo = m + 1; } else hi = m - 1; }
      best = Math.max(1, best);
      if (i + best >= n) { if (n - i > 1) best = n - i - 1; else { plan.push([i, n, first, false]); plan.push([n, n, false, true]); i = n; break; } }
      plan.push([i, i + best, first, false]); i += best;
    }
    return plan.map(function (x, k) { var p = build(x[0], x[1], x[2], x[3], k + 1, plan.length); if (!fits(p)) shrink(root, p); return p; });
  }
  function layout(paper) {
    var root = host(); var probe = paper.cloneNode(true); root.appendChild(probe); free(probe);
    if (mm(probe) <= H) { probe.remove(); return; }
    probe.remove(); var one = paper.cloneNode(true);
    if (shrink(root, one)) { paper.replaceWith(one); return; }
    var pages = split(root, paper); if (!pages) { paper.replaceWith(one); return; }
    var frag = document.createDocumentFragment(); pages.forEach(function (p) { frag.appendChild(p); }); paper.replaceWith(frag);
  }
  function qr() { if (!window.qrcode) return; Array.prototype.forEach.call(document.querySelectorAll('.qr[data-qr]'), function (e) { if (e.firstChild) return; var q = qrcode(0, 'M'); q.addData(e.getAttribute('data-qr')); q.make(); e.innerHTML = q.createSvgTag({ margin: 0, scalable: true }); }); }
  function run() { qr(); Array.prototype.slice.call(document.querySelectorAll('.paper:not(.pg)')).forEach(function (p) { try { layout(p); } catch (e) { if (window.console) console.warn('page layout', e); } }); }
  window.DocPaper = { run: run };
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { setTimeout(run, 30); }); else window.addEventListener('load', run);
})();
