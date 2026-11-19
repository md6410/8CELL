/* طراحِ برچسب: کشیدن و رها کردن، تغییرِ اندازه، ویژگی‌ها، ذخیرهٔ چیدمانِ JSON (واحد: میلی‌متر) */
(function () {
  'use strict';
  var C = window.Cell, root = document.getElementById('lbl'); if (!root) return;
  var tpl = JSON.parse(root.getAttribute('data-tpl')), fields = JSON.parse(root.getAttribute('data-fields')), types = JSON.parse(root.getAttribute('data-types'));
  var PX = 5, cv = root.querySelector('.lbl-canvas'), panel = root.querySelector('.props'), sel = -1, L = tpl.layout || [];
  function size() { tpl.w_mm = parseFloat(C.en(root.querySelector('[name=w_mm]').value)) || tpl.w_mm; tpl.h_mm = parseFloat(C.en(root.querySelector('[name=h_mm]').value)) || tpl.h_mm; PX = Math.max(2, Math.min(8, 520 / tpl.w_mm)); cv.style.width = tpl.w_mm * PX + 'px'; cv.style.height = tpl.h_mm * PX + 'px'; }
  function label(e) { if (e.t === 'field') return '[' + (fields[e.f] || e.f) + ']'; if (e.t === 'text') return e.txt || 'متن'; return types[e.t] || e.t; }
  function draw() {
    size(); cv.innerHTML = '';
    L.forEach(function (e, i) {
      var d = document.createElement('div'); d.className = 'el' + (i === sel ? ' sel' : ''); d.setAttribute('data-i', i);
      d.style.cssText = 'left:' + e.x * PX + 'px;top:' + e.y * PX + 'px;width:' + e.w * PX + 'px;height:' + e.h * PX + 'px;font-size:' + (e.fs || 3) * PX + 'px;' + (e.b ? 'font-weight:700;' : '') + 'text-align:' + ({ c: 'center', l: 'left' }[e.al] || 'right') + ';' + (e.t === 'line' || e.t === 'cut' ? 'border-top:2px ' + (e.t === 'cut' ? 'dashed' : 'solid') + ' #000;' : '') + (e.t === 'box' ? 'border:2px solid #000;' : '') + (e.t === 'qr' ? 'background:repeating-linear-gradient(45deg,#ddd 0 4px,#fff 4px 8px);' : '');
      d.innerHTML = (e.t === 'qr' ? '<small>QR: ' + C.esc(fields[e.f] || e.f) + '</small>' : C.esc(label(e))) + '<span class="rs"></span>';
      cv.appendChild(d);
    });
    props();
  }
  function props() {
    if (sel < 0 || !L[sel]) { panel.innerHTML = '<p class="muted">یک عنصر را انتخاب کنید.</p>'; return; }
    var e = L[sel], fo = Object.keys(fields).map(function (k) { return '<option value="' + k + '"' + (e.f === k ? ' selected' : '') + '>' + C.esc(fields[k]) + '</option>'; }).join('');
    panel.innerHTML = '<b>' + C.esc(types[e.t]) + '</b>' + (e.t === 'field' || e.t === 'qr' ? '<label class="field"><span>فیلد</span><select data-k="f">' + fo + '</select></label>' : '') + (e.t === 'text' ? '<label class="field"><span>متن</span><input data-k="txt" value="' + C.esc(e.txt || '') + '"></label>' : '') +
      ['x', 'y', 'w', 'h', 'fs'].map(function (k) { return '<label class="field"><span>' + { x: 'x (م‌م)', y: 'y', w: 'پهنا', h: 'بلندی', fs: 'اندازهٔ قلم' }[k] + '</span><input data-k="' + k + '" value="' + (e[k] || 0) + '"></label>'; }).join('') +
      '<label class="field"><span>تراز</span><select data-k="al"><option value="r">راست</option><option value="c"' + (e.al === 'c' ? ' selected' : '') + '>وسط</option><option value="l"' + (e.al === 'l' ? ' selected' : '') + '>چپ</option></select></label>' +
      '<label class="ck"><input type="checkbox" data-k="b"' + (e.b ? ' checked' : '') + '> درشت</label> <label class="ck"><input type="checkbox" data-k="mono"' + (e.mono ? ' checked' : '') + '> کد (لاتین)</label><div class="actions"><button type="button" class="btn sm danger delel">حذف</button></div>';
  }
  panel.addEventListener('input', function (ev) { var k = ev.target.getAttribute('data-k'); if (!k || sel < 0) return; var e = L[sel]; if (ev.target.type === 'checkbox') e[k] = ev.target.checked ? 1 : 0; else if (['x', 'y', 'w', 'h', 'fs'].indexOf(k) >= 0) e[k] = parseFloat(C.en(ev.target.value)) || 0; else e[k] = ev.target.value; var keep = document.activeElement; draw(); });
  panel.addEventListener('change', function () { draw(); });
  panel.addEventListener('click', function (ev) { if (ev.target.classList.contains('delel')) { L.splice(sel, 1); sel = -1; draw(); } });
  root.querySelectorAll('[data-add]').forEach(function (b) { b.onclick = function () { var t = b.getAttribute('data-add'); L.push({ t: t, f: Object.keys(fields)[0], txt: t === 'text' ? 'متن' : '', x: 2, y: 2, w: t === 'qr' ? 15 : Math.min(30, tpl.w_mm - 4), h: t === 'qr' ? 15 : (t === 'line' || t === 'cut' ? 0.5 : 6), fs: 3 }); sel = L.length - 1; draw(); }; });
  var drag = null;
  cv.addEventListener('pointerdown', function (ev) {
    var d = ev.target.closest('.el'); if (!d) { sel = -1; draw(); return; }
    sel = +d.getAttribute('data-i'); var e = L[sel]; drag = { rs: ev.target.classList.contains('rs'), x: ev.clientX, y: ev.clientY, ox: e.x, oy: e.y, ow: e.w, oh: e.h }; draw(); ev.preventDefault();
  });
  document.addEventListener('pointermove', function (ev) {
    if (!drag || sel < 0) return; var e = L[sel], dx = (ev.clientX - drag.x) / PX, dy = (ev.clientY - drag.y) / PX, r = function (v) { return Math.round(v * 2) / 2; };
    if (drag.rs) { e.w = Math.max(1, r(drag.ow - dx)); e.x = r(drag.ox + dx); e.h = Math.max(0.5, r(drag.oh + dy)); } else { e.x = Math.max(0, r(drag.ox + dx)); e.y = Math.max(0, r(drag.oy + dy)); }
    var d = cv.querySelector('[data-i="' + sel + '"]'); if (d) { d.style.left = e.x * PX + 'px'; d.style.top = e.y * PX + 'px'; d.style.width = e.w * PX + 'px'; d.style.height = e.h * PX + 'px'; }
  });
  document.addEventListener('pointerup', function () { if (drag) { drag = null; props(); } });
  root.querySelectorAll('[name=w_mm],[name=h_mm]').forEach(function (i) { i.addEventListener('change', draw); });
  var form = root.querySelector('form'); form.__extend = function (o) { o.layout = JSON.stringify(L); return o; };
  draw();
})();
