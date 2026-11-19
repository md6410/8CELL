/* خواندنِ QR/بارکد با دوربین: بسترِ امن، انتخابِ دوربینِ ماندگار، فوکوسِ پیوسته، سه نما، دو موتور (BarcodeDetector + ZXing)، ضدِتکرار، تیک/بوق/لرزش */
(function () {
  'use strict';
  var KEY = 'c8_scan_cam', MUTE = 'c8_scan_mute';
  var VIEWS = [[1, 1], [0.5, 2], [1 / 3, 3]];
  var st = null, audio = null;
  function ls(k, v) { try { if (v === undefined) return localStorage.getItem(k); if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) { return null; } }
  function errMsg(e) {
    var n = e && e.name;
    if (n === 'NotAllowedError' || n === 'SecurityError') return 'اجازهٔ دوربین داده نشد؛ از تنظیماتِ مرورگر اجازه بدهید یا کد را دستی بنویسید.';
    if (n === 'NotReadableError' || n === 'AbortError') return 'دوربین دستِ برنامهٔ دیگری است؛ آن را ببندید و دوباره امتحان کنید.';
    if (n === 'NotFoundError' || n === 'OverconstrainedError') return 'دوربینی پیدا نشد؛ کد را دستی بنویسید یا با اسکنرِ USB بخوانید.';
    return 'دوربین باز نشد؛ کد را دستی بنویسید.';
  }
  function beep(ok) {
    if (window.SCAN_SOUND === false || ls(MUTE) === '1') return;
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      var o = audio.createOscillator(), g = audio.createGain(); o.type = ok ? 'square' : 'sawtooth'; o.frequency.value = ok ? 1250 : 220;
      g.gain.value = 0.08; o.connect(g); g.connect(audio.destination); o.start(); o.stop(audio.currentTime + (ok ? 0.09 : 0.3));
    } catch (e) {}
    if (navigator.vibrate) navigator.vibrate(ok ? 60 : [80, 60, 80]);
  }
  document.addEventListener('touchstart', function () { try { audio = audio || new (window.AudioContext || window.webkitAudioContext)(); if (audio.state === 'suspended') audio.resume(); } catch (e) {} }, { once: true });
  function zxReader() {
    if (!window.ZXing) return null;
    var Z = window.ZXing, hints = new Map();
    hints.set(Z.DecodeHintType.POSSIBLE_FORMATS, [Z.BarcodeFormat.QR_CODE, Z.BarcodeFormat.CODE_128, Z.BarcodeFormat.EAN_13, Z.BarcodeFormat.EAN_8, Z.BarcodeFormat.CODE_39, Z.BarcodeFormat.DATA_MATRIX, Z.BarcodeFormat.UPC_A]);
    var r = new Z.MultiFormatReader(); r.setHints(hints); return r;
  }
  function frame(video, canvas, i) {
    var v = VIEWS[i % VIEWS.length], vw = video.videoWidth, vh = video.videoHeight; if (!vw) return null;
    var cw = Math.round(vw * v[0]), chh = Math.round(vh * v[0]), sx = Math.round((vw - cw) / 2), sy = Math.round((vh - chh) / 2);
    var scale = v[0] === 1 ? Math.min(1, 1100 / Math.max(cw, chh)) : 1; // سقفِ ۱۱۰۰ فقط برای کلِ قاب
    canvas.width = Math.round(cw * scale); canvas.height = Math.round(chh * scale);
    var ctx = canvas.getContext('2d', { willReadFrequently: true }); ctx.drawImage(video, sx, sy, cw, chh, 0, 0, canvas.width, canvas.height);
    return canvas;
  }
  function decodeZX(reader, canvas) {
    try { var Z = window.ZXing, src = new Z.HTMLCanvasElementLuminanceSource(canvas), bmp = new Z.BinaryBitmap(new Z.HybridBinarizer(src)); return reader.decode(bmp).getText(); }
    catch (e) { return null; } finally { try { reader.reset(); } catch (e2) {} }
  }
  function stop() {
    if (!st) return; st.stopped = true; clearTimeout(st.timer);
    if (st.stream) st.stream.getTracks().forEach(function (t) { t.stop(); });
    if (st.video) st.video.srcObject = null; st = null;
  }
  function start(opts) {
    stop();
    var box = opts.box, msg = opts.msg || function () {};
    if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { msg('دوربین فقط روی https کار می‌کند؛ کد را دستی بنویسید یا با اسکنرِ USB بخوانید.', true); return; }
    box.innerHTML = '<video playsinline muted></video><div class="guide"></div><div class="tick">✔</div>';
    var video = box.querySelector('video'), canvas = document.createElement('canvas');
    st = { video: video, i: 0, lastTxt: '', lastAt: 0, busy: false, stopped: false, reader: zxReader(), bd: null, bdDead: false };
    var saved = ls(KEY), cons = { audio: false, video: saved ? { deviceId: { exact: saved }, width: { ideal: 1920 }, height: { ideal: 1080 } } : { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } } };
    navigator.mediaDevices.getUserMedia(cons).catch(function (e) { if (saved) { ls(KEY, null); return navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } }); } throw e; })
      .then(function (stream) {
        if (!st) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
        st.stream = stream; video.srcObject = stream; video.play();
        var track = stream.getVideoTracks()[0];
        try { var cap = track.getCapabilities ? track.getCapabilities() : {}; if (cap.focusMode && cap.focusMode.indexOf('continuous') >= 0) track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }); } catch (e) {}
        camSelect(opts.camSelect, track);
        if ('BarcodeDetector' in window) window.BarcodeDetector.getSupportedFormats().then(function (f) { if (f && f.length) st.bd = new window.BarcodeDetector({ formats: f.filter(function (x) { return ['qr_code', 'code_128', 'ean_13', 'ean_8', 'code_39', 'data_matrix', 'upc_a'].indexOf(x) >= 0; }) }); }).catch(function () {});
        loop(opts, canvas);
        msg('دوربین آماده است؛ کد را داخلِ کادر بگیرید.');
      }).catch(function (e) { msg(errMsg(e), true); stop(); });
  }
  function camSelect(sel, track) {
    if (!sel || !navigator.mediaDevices.enumerateDevices) return;
    navigator.mediaDevices.enumerateDevices().then(function (ds) {
      var cams = ds.filter(function (d) { return d.kind === 'videoinput'; }); if (cams.length < 2) { sel.style.display = 'none'; return; }
      var cur = track.getSettings ? track.getSettings().deviceId : '';
      sel.innerHTML = cams.map(function (c, i) { return '<option value="' + c.deviceId + '"' + (c.deviceId === cur ? ' selected' : '') + '>' + (c.label || ('دوربینِ ' + (i + 1))) + '</option>'; }).join(''); sel.style.display = '';
      sel.onchange = function () { ls(KEY, sel.value); if (st && st.opts) start(st.opts); };
    });
  }
  function accept(txt, opts) {
    var now = Date.now();
    if (txt === st.lastTxt && now - st.lastAt < 1500) { st.lastAt = now; return; } // ضدِ تکرار
    st.lastTxt = txt; st.lastAt = now;
    var r = opts.onCode(txt);
    if (r && r.then) r.then(function (ok) { feedback(opts, ok !== false); }); else feedback(opts, r !== false);
    if (!opts.keep) stop();
  }
  function feedback(opts, ok) { beep(ok); if (opts.box && ok) { opts.box.classList.add('hit'); setTimeout(function () { if (opts.box) opts.box.classList.remove('hit'); }, 600); } }
  function loop(opts, canvas) {
    if (!st || st.stopped) return; st.opts = opts;
    st.timer = setTimeout(function () {
      if (!st || st.busy) return loop(opts, canvas);
      var c = frame(st.video, canvas, st.i); var turn = st.i++;
      if (!c) return loop(opts, canvas);
      if (st.bd && !st.bdDead && turn % 2 === 0) {
        st.busy = true;
        st.bd.detect(c).then(function (res) { st && (st.busy = false); if (res && res.length) accept(res[0].rawValue, opts); loop(opts, canvas); })
          .catch(function () { if (st) { st.busy = false; st.bdDead = true; } loop(opts, canvas); });
        return;
      }
      if (st.reader) { var t = decodeZX(st.reader, c); if (t) accept(t, opts); }
      loop(opts, canvas);
    }, 120);
  }
  window.Scanner = { start: start, stop: stop, beep: beep, mute: function (on) { ls(MUTE, on ? '1' : '0'); } };
})();
