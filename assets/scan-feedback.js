/* ============================================================
   scan-feedback.js — بعد از هر اسکنِ موفق: تیکِ سبز + بوق

   خواسته: «وقتی یه QR اسکن می‌شود همان‌جا یک تیک سبز بیاید و تأیید کند
   درست انجام شد، ولی صفحه به‌خاطرش اسکرول نکند؛ و بعد از هر تأیید یک
   صدا — مثلِ بارکدخوانِ فروشگاه‌ها — که کارمند بتواند بی‌صدایش کند.»

   چرا اسکرول نمی‌کند: تیک یک لایهٔ ‎position:absolute‎ داخلِ همان کادرِ
   دوربین (یا ‎fixed‎ اگر کادر نبود) است، پس هیچ چیز را در جریانِ صفحه
   جابه‌جا نمی‌کند، فوکوس نمی‌گیرد و ‎scrollIntoView‎ هم صدا نمی‌زند.

   صدا: WebAudio، بی هیچ فایل. دو حالتِ خاموشی که با هم می‌خوانند:
     • تنظیمِ مدیر (staff_scan_sound) — کلِ فروشگاه؛ ‎window.__scanSound‎
     • انتخابِ خودِ کارمند — دکمهٔ 🔊/🔇 بالای پنل؛ در همین دستگاه
       (localStorage) می‌ماند.
   هر کدام خاموش باشد، بی‌صدا است؛ تیک سبز همیشه می‌آید.
   ============================================================ */
(function () {
  'use strict';
  var KEY = (window.WEBY_KEY || 'weby') + '-scan-mute';
  var ctx = null;

  function storeGet() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }
  function storeSet(v) { try { localStorage.setItem(KEY, v); } catch (e) {} }

  function muted() { return storeGet() === '1'; }
  function allowed() { return window.__scanSound !== false && !muted(); }

  /* مرورگر صدا را تا اولین لمس نمی‌دهد؛ اولین لمسِ صفحه آن را بیدار می‌کند
     (کارمند همیشه پیش از اسکن «روشن کردن دوربین» را می‌زند). */
  function audio() {
    if (ctx) return ctx;
    var C = window.AudioContext || window.webkitAudioContext;
    if (!C) return null;
    try { ctx = new C(); } catch (e) { ctx = null; }
    return ctx;
  }
  function unlock() {
    var c = audio();
    if (c && c.state === 'suspended' && c.resume) c.resume();
  }
  ['pointerdown', 'touchstart', 'keydown'].forEach(function (ev) {
    document.addEventListener(ev, unlock, { passive: true });
  });

  function tone(freq, start, dur, vol, type) {
    var c = audio(); if (!c) return;
    var o = c.createOscillator(), g = c.createGain();
    o.type = type || 'sine'; o.frequency.value = freq;
    o.connect(g); g.connect(c.destination);
    var t0 = c.currentTime + start;
    g.gain.setValueAtTime(0.0001, t0);
    g.gain.exponentialRampToValueAtTime(vol, t0 + 0.01);
    g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
    o.start(t0); o.stop(t0 + dur + 0.02);
  }

  /* «بیپ»ِ بارکدخوان: دو نتِ کوتاه و بالا */
  function beepOk()   { if (!allowed()) return; try { unlock(); tone(1568, 0, 0.09, 0.22, 'square'); tone(2093, 0.09, 0.11, 0.2, 'square'); } catch (e) {} }
  /* ناموفق: یک نتِ بم و بلندتر */
  function beepFail() { if (!allowed()) return; try { unlock(); tone(196, 0, 0.28, 0.25, 'sawtooth'); } catch (e) {} }

  var CROSS = '<svg viewBox="0 0 52 52" aria-hidden="true"><path d="M16 16l20 20M36 16L16 36" fill="none" '
            + 'stroke="#fff" stroke-width="5" stroke-linecap="round"/></svg>';
  var CHECK = '<svg viewBox="0 0 52 52" aria-hidden="true"><path d="M14 27l8 8 16-17" fill="none" '
            + 'stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  /**
   * تیکِ سبز روی کادرِ دوربین (anchor)، یا وسطِ صفحه اگر کادری نبود.
   * هیچ‌چیز را اسکرول یا فوکوس نمی‌کند.
   */
  function tick(anchor, text, bad) {
    var el = document.createElement('div');
    el.className = 'scan-tick' + (bad ? ' scan-tick--bad' : '');
    el.setAttribute('role', 'status');
    el.innerHTML = '<span class="scan-tick__c">' + (bad ? CROSS : CHECK) + '</span>'
                 + (text ? '<span class="scan-tick__t"></span>' : '');
    if (text) el.querySelector('.scan-tick__t').textContent = text;

    var host = anchor && anchor.isConnected && anchor.offsetParent !== null ? anchor : null;
    if (host) {
      if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
      host.appendChild(el);
    } else {
      el.classList.add('scan-tick--fixed');
      document.body.appendChild(el);
    }
    /* خطا کمی بیشتر می‌ماند تا کارمند ببیند */
    setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, bad ? 1900 : 1300);
  }

  window.scanFeedback = {
    ok: function (anchor, text, opts) {
      if (!opts || opts.overlay !== false) tick(anchor, text);
      beepOk();
    },
    /* ✕ قرمز + صدای خطا — برای کدی که سرور نپذیرفت (تکراری، ناموجود، …) */
    fail: function (anchor, text, opts) {
      if (!opts || opts.overlay !== false) tick(anchor, text, true);
      beepFail();
    },
    beepOk: beepOk, beepFail: beepFail,
    muted: muted,
    setMuted: function (m) { storeSet(m ? '1' : '0'); syncBtn(); },
    toggle: function () { this.setMuted(!muted()); if (!muted()) beepOk(); }
  };

  /* دکمهٔ بالای پنل */
  function syncBtn() {
    var b = document.getElementById('stfSound');
    if (!b) return;
    var m = muted() || window.__scanSound === false;
    b.textContent = m ? '🔇' : '🔊';
    b.setAttribute('aria-pressed', m ? 'true' : 'false');
    b.title = window.__scanSound === false ? 'صدای اسکن را مدیر خاموش کرده است'
            : (m ? 'صدای اسکن خاموش است — برای روشن‌کردن بزنید' : 'صدای اسکن روشن است — برای بی‌صداکردن بزنید');
  }
  document.addEventListener('DOMContentLoaded', function () {
    var b = document.getElementById('stfSound');
    if (b) b.addEventListener('click', function () {
      if (window.__scanSound === false) return;
      window.scanFeedback.toggle();
    });
    syncBtn();
  });
  if (document.readyState !== 'loading') syncBtn();
})();
