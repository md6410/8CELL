/* ============================================================
   scan-box.js — اسکن با دوربین در صفحه‌های انبار

   ------------------------------------------------------------
   چه چیزی خراب بود — دورِ اول

   صفحه‌های ورود و خروج کالا هرکدام نسخهٔ خودشان از همین کد را داشتند
   (کپیِ تقریباً یکسان)، و هر دو سه اشکال داشتند:

   ۱) هیچ بررسی‌ای برای HTTPS نبود. مرورگر getUserMedia را روی http
      اصلاً در دسترس قرار نمی‌دهد — نه اینکه اجازه بخواهد و رد شود؛
      خودِ API وجود ندارد.

   ۲) خطاها تفکیک نمی‌شدند. «اجازه ندادی»، «دوربین نداری» و «دوربین
      دست برنامهٔ دیگری است» هر سه یک پیام می‌گرفتند.

   ۳) stopScan() فقط reader.reset() می‌زد، پس چراغ دوربین روشن می‌ماند
      و دفعهٔ بعد دوربین «مشغول» بود.

   ۴) alert() استفاده می‌شد و روی موبایل جریان کار را قطع می‌کرد.

   ------------------------------------------------------------
   چه چیزی خراب بود — دورِ دوم (همین بازنویسی)

   گزارش انباردار: «تصویری که می‌بینم برای یک دوربین است ولی اسکن از
   دوربین دیگری انجام می‌شود.» علتش دو تا بود و روی هم می‌نشستند:

   ۱) دو مرجع برای یک عنصر ویدیو. کد قبلی اول خودش استریم را روی
      <video> می‌نشاند (showStream) و بعد همان استریم را به ZXing
      می‌داد. ولی در همین نسخهٔ کتابخانه:

          decodeFromStream(t,e,r){ this.reset(); ... attachStreamToVideo(t,e) ... }

      یعنی decodeFromStream *اول* reset() می‌زند — و reset() هم
      stopStreams() صدا می‌کند (تراک‌های this.stream را می‌بندد) و هم
      _destroyVideoElement() (که srcObject را برمی‌دارد). پس ترتیب
      واقعی این بود: ما وصل می‌کردیم ← کتابخانه قطع می‌کرد ← دوباره
      وصل می‌کرد. روی دستگاه کند یا در دومین باز کردنِ دوربین، قابِ
      نمایش روی حالت قبلی گیر می‌کرد در حالی که کدخوان از استریم تازه
      می‌خواند. از بیرون دقیقاً شبیه «تصویر از یک دوربین، اسکن از
      دوربین دیگر» دیده می‌شود.

      حالا فقط یک نفر وصل می‌کند: خودِ ZXing. ما هیچ srcObject ست
      نمی‌کنیم.

   ۲) reader بین دو بار اسکن بازاستفاده می‌شد، در حالی که this.stream
      دیگر مرده بود. حالا هر بار خواننده از نو ساخته می‌شود.

   و یک اضافهٔ جدا: گوشی‌های امروزی چند دوربین پشت دارند (عریض،
   فوق‌عریض، تله) و facingMode:'environment' فقط می‌گوید «یکی از
   پشتی‌ها» — کدامش دست مرورگر است و اغلب فوق‌عریضِ بی‌فوکوس را
   می‌دهد که بارکد را نمی‌خواند. حالا فهرست دوربین‌ها نشان داده
   می‌شود، انباردار یک بار دوربین درست را انتخاب می‌کند و انتخابش
   ذخیره می‌ماند.

   ------------------------------------------------------------
   چه چیزی خراب بود — دورِ سوم

   گزارش: «اسکن بارکد کپسول شارژی خیلی بد جواب می‌دهد.»

   ۱) ZXing با تنظیمات پیش‌فرض کار می‌کرد: همهٔ قالب‌ها را امتحان
      می‌کرد (کند)، TRY_HARDER نداشت، و هر ۵۰۰ میلی‌ثانیه فقط یک قاب
      می‌دید. برچسبِ کوچکی که روی بدنهٔ گرد و براقِ کپسول چسبیده،
      فقط در چند قابِ خوش‌شانس خواناست؛ با دو قاب در ثانیه، آن
      قاب‌ها از دست می‌رفتند.
   ۲) از دوربین فوکوس پیوسته خواسته نمی‌شد، و وضوح ۷۲۰ بود.

   ۳) کدخوان فقط کلِ قاب را می‌دید. برچسبِ کپسول در قاب کوچک می‌افتد
      (گوشی را نمی‌شود خیلی نزدیکِ بدنهٔ گرد برد) و در کلِ قاب بین
      تاری گم می‌شد. حالا یک قاب در میان، وسطِ تصویر دو برابر
      بزرگ‌شده دیده می‌شود — همان جایی که کادرِ سفیدِ راهنماست.

   حالا: ZXing با قالب‌های محدود و ۴۰ میلی‌ثانیه (TRY_HARDER نه —
   شرحش پایین‌تر، بالای zxReader)، و اگر مرورگر کدخوانِ خودش را دارد
   (BarcodeDetector — کروم اندروید) آن هم، یک قاب در میان — نه به‌جای
   ZXing. چرا «در کنار» و نه «به‌جای»: دورِ چهارم، داخلِ attach().
   دوربین با ۱۰۸۰ و فوکوس پیوسته خواسته می‌شود.

   و نیمهٔ دیگرِ همین گزارش در خودِ برچسب بود — شرحش در
   staff/label.php (حاشیهٔ سفیدِ QR).

   ------------------------------------------------------------
   دو رفتارِ اختیاری، روی خودِ کادرِ کد

     data-scan-submit  بعد از خواندن، فرم خودش فرستاده می‌شود (مثل
                       setScanAutoSubmit ولی فقط برای همین کادر)
     data-scan-keep    دوربین بعد از خواندن باز می‌ماند — برای
                       صندوق، که پشت سر هم ده کالا اسکن می‌شود

   ------------------------------------------------------------
   قرار کار

   رابط بیرونی همان است که بود — startScan(id, boxId, vidId) و
   stopScan() — تا صفحه‌ها لازم نباشد عوض شوند. اگر این فایل بار نشود،
   دکمه‌ها کار نمی‌کنند ولی ورود دستی کد سر جایش است.
   ============================================================ */
(function () {
  'use strict';

  var reader  = null;     /* ZXing — هر بار از نو ساخته می‌شود */
  var stream  = null;     /* MediaStream — جدا نگه داشته می‌شود تا بشود قطعش کرد */
  var target  = null;     /* کادری که کد داخلش می‌نشیند */
  var box     = null;     /* کادر پیش‌نمایش */
  var videoEl = null;
  var autoSubmit = false;
  var loopId  = 0;        /* شمارهٔ دورِ کدخوانِ مرورگر؛ عوض‌شدنش یعنی «بایست» */
  var lastTxt = '', lastAt = 0;   /* در حالت keep، یک کد دو بار پشت هم شمرده نشود */
  var PREF = (window.WEBY_KEY || 'weby') + '_scan_cam';   /* دوربینِ انتخاب‌شده، بین جلسه‌ها می‌ماند */

  /* ---------- پیام‌ها داخل خود کادر، نه در alert ---------- */
  function hint(msg, bad) {
    if (!box) return;
    var h = box.querySelector('.scan-hint');
    if (!h) {
      h = document.createElement('p');
      h.className = 'scan-hint';
      h.style.cssText = 'margin:8px 0 0;font-size:13px;line-height:1.9';
      box.appendChild(h);
    }
    h.style.color = bad ? '#8C2126' : '';
    h.innerHTML = msg;
  }

  function savedCam() {
    try { return localStorage.getItem(PREF) || ''; } catch (e) { return ''; }
  }
  function saveCam(id) {
    try { id ? localStorage.setItem(PREF, id) : localStorage.removeItem(PREF); } catch (e) {}
  }

  /* دوربین فقط در بستر امن کار می‌کند. این را *پیش از* هر چیز بررسی
     می‌کنیم، چون روی http خودِ navigator.mediaDevices وجود ندارد. */
  function secureOk() {
    return window.isSecureContext
        || location.protocol === 'https:'
        || location.hostname === 'localhost'
        || location.hostname === '127.0.0.1';
  }

  function releaseCamera() {
    if (stream) {
      try { stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
      stream = null;
    }
    /* srcObject را خودمان ست نمی‌کنیم، ولی ZXing کرده — پاکش می‌کنیم
       تا قابِ آخر روی صفحه یخ نزند. */
    if (videoEl) { try { videoEl.srcObject = null; } catch (e) {} }
    if (box) box.classList.remove('live');
  }

  /* ------------------------------------------------------------------
     انتخاب‌گر دوربین.

     فقط *بعد از* گرفتن اجازه ساخته می‌شود: پیش از آن مرورگر برچسب
     دوربین‌ها را خالی برمی‌گرداند و فهرست «دوربین ۱، دوربین ۲» هیچ
     کمکی نمی‌کند.
     ------------------------------------------------------------------ */
  function buildPicker() {
    if (!box || !navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return;

    navigator.mediaDevices.enumerateDevices().then(function (list) {
      var cams = list.filter(function (d) { return d.kind === 'videoinput'; });
      var old  = box.querySelector('.scan-cam');
      if (cams.length < 2) { if (old) old.remove(); return; }

      var track = stream && stream.getVideoTracks ? stream.getVideoTracks()[0] : null;
      var cur   = (track && track.getSettings && track.getSettings().deviceId) || savedCam();

      var sel = old;
      if (!sel) {
        sel = document.createElement('select');
        sel.className = 'scan-cam';
        /* جا و شکلش در staff.css (.scan-cam): بالای تصویر، نه زیرش —
           کادرِ دوربین نسبتِ ثابت و overflow:hidden دارد و هرچه زیرِ
           ویدیو بیاید بریده می‌شود. همان‌جا بود که «فهرستِ پایین»ی که
           پیام می‌گفت هیچ‌وقت دیده نمی‌شد. */
        sel.setAttribute('aria-label', 'انتخاب دوربین');
        sel.addEventListener('change', function () {
          saveCam(sel.value);
          /* دوربین را عوض کن بدون اینکه کاربر مجبور شود از نو شروع کند */
          var tId = target  ? target.id  : '';
          var bId = box     ? box.id     : '';
          var vId = videoEl ? videoEl.id : '';
          window.stopScan();
          window.startScan(tId, bId, vId);
        });
        box.appendChild(sel);
      }

      sel.innerHTML = '';
      var nBack = 0, nFront = 0;
      cams.forEach(function (d, i) {
        var o = document.createElement('option');
        o.value = d.deviceId;
        o.textContent = camName(d.label, i, function (front) {
          return front ? ++nFront : ++nBack;
        });
        if (d.deviceId === cur) o.selected = true;
        sel.appendChild(o);
      });
    }).catch(function () {});
  }

  /* «camera2 0, facing back» برای انباردار چیزی نمی‌گوید؛ «پشت ۱» و
     «پشت ۲» می‌گوید که دو دوربینِ پشت هست و یکی را باید امتحان کرد. */
  function camName(label, i, count) {
    var fa = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
    var l = String(label || '').toLowerCase();
    if (/back|rear|environment|پشت/.test(l))  return 'دوربین پشت ' + fa(count(false));
    if (/front|user|facetime|جلو/.test(l))    return 'دوربین جلو' + (function (n) { return n > 1 ? ' ' + fa(n) : ''; })(count(true));
    return label || ('دوربین ' + fa(i + 1));
  }

  function onText(txt) {
    if (!txt || !target) return;
    var keep = target.hasAttribute('data-scan-keep');

    /* دوربینِ باز همان کد را چند بار در ثانیه می‌بیند؛ در حالت keep
       فقط یک بار شمرده می‌شود، مگر کالا از جلوی دوربین رفته و برگشته
       باشد (یک و نیم ثانیه). */
    var now = Date.now();
    if (keep && txt === lastTxt && now - lastAt < 1500) { lastAt = now; return; }
    lastTxt = txt; lastAt = now;

    target.value = txt;
    /* رویداد دستی: تنها راهی که کدهای صفحه از تغییرِ برنامه‌ایِ value
       باخبر شوند — مرورگر برای ست‌کردن دستیِ .value رویداد نمی‌سازد. */
    target.dispatchEvent(new Event('input',  { bubbles: true }));
    target.dispatchEvent(new Event('change', { bubbles: true }));
    if (navigator.vibrate) navigator.vibrate(60);
    /* تیکِ سبز + بوق: روی کادرِ دوربین اگر باز می‌ماند، وگرنه روی خودِ
       کادرِ کد — لایهٔ شناور، پس صفحه اسکرول نمی‌شود. */
    /* data-scan-confirm: صفحه خودش بعد از جوابِ سرور تیک یا ✕ می‌زند (فروش سریع) —
       تیکِ پیش از جواب، روی کدِ ردشده هم «موفق» نشان می‌داد. */
    if (window.scanFeedback && !target.hasAttribute('data-scan-confirm')) {
      window.scanFeedback.ok(keep ? box : (target.closest && target.closest('.field')) || null, txt.length > 28 ? txt.slice(0, 28) + '…' : txt);
    }
    if (keep) { hint('خوانده شد: ' + txt.replace(/</g, '&lt;') + ' — کالای بعدی را بگیرید.'); return; }

    var f = target.form;
    var submit = autoSubmit || target.hasAttribute('data-scan-submit');
    window.stopScan();
    if (submit && f) {
      /* requestSubmit تا اعتبارسنجیِ فرم و onsubmit هم اجرا شوند */
      if (f.requestSubmit) f.requestSubmit(); else f.submit();
    }
  }

  /* ZXing محدود به قالب‌هایی که در این فروشگاه چاپ یا روی کالا
     دیده می‌شوند. امتحانِ همهٔ قالب‌ها هر قاب را کند می‌کرد.

     TRY_HARDER عمداً *نیست*: در همین نسخهٔ کتابخانه، روشن کردنش
     خواندنِ QR را کاملاً از کار می‌انداخت — با دوربینِ آزمایشی، برچسبی
     که بی آن در ۲۵۰ میلی‌ثانیه خوانده می‌شد، با آن در ده ثانیه هم
     خوانده نشد. */
  function zxReader() {
    var Z = window.ZXing, r = new Z.MultiFormatReader();
    try {
      var h = new Map(), F = Z.BarcodeFormat;
      h.set(Z.DecodeHintType.POSSIBLE_FORMATS,
            [F.QR_CODE, F.CODE_128, F.EAN_13, F.EAN_8, F.CODE_39, F.UPC_A, F.DATA_MATRIX]);
      r.setHints(h);
    } catch (e) {}
    return r;
  }
  function zxDecode(r, cvs) {
    var Z = window.ZXing;
    try {
      var bmp = new Z.BinaryBitmap(new Z.HybridBinarizer(new Z.HTMLCanvasElementLuminanceSource(cvs)));
      return r.decodeWithState(bmp).getText();
    } catch (e) { return null; }   /* NotFound در هر قابِ بی‌کد — عادی است */
  }

  /* ------------------------------------------------------------------
     قاب، به چند شکل — به نوبت.

     کل تصویر (کوچک‌شده تا ۱۱۰۰ پیکسل) برای کدی که نزدیک است، و وسطِ
     تصویر دو برابر بزرگ‌شده برای کدی که دور یا کوچک است. برچسبِ کپسول
     روی بدنهٔ گرد است و کارمند نمی‌تواند گوشی را خیلی نزدیک ببرد بی
     آنکه فوکوس برود؛ پس QR در قاب کوچک می‌افتد، و کدخوان روی کلِ قاب
     آن را بین تاری گم می‌کرد. «وسط» همان جایی است که کادرِ سفیدِ
     راهنما (.scan-frame) نشان می‌دهد.
     ------------------------------------------------------------------ */
  var cvs = null, cctx = null;
  /* ------------------------------------------------------------------
     سه نما، به نوبت: کلِ قاب، وسطِ نیمه‌اندازه ×۲، وسطِ یک‌سوم ×۳.

     دورِ پنجم — گزارش: «اسکن سفارش درست شد ولی کپسول کار نمی‌کند.»
     نسخهٔ قبل «وسط ×۲» را اعلام کرده بود ولی سقفِ ۱۱۰۰ پیکسلیِ بوم
     روی همهٔ نماها می‌نشست: وسطِ ۹۶۰ پیکسلی به‌جای ×۲ فقط ×۱٫۱۵ بزرگ
     می‌شد، و «وسطِ یک‌سوم ×۳» که آن دور آزموده شد هم در عمل ×۱٫۷ بود
     — برای همین «چیزی اضافه نخواند». ZXing برای QR دست‌کم ۶–۷ پیکسل
     برای هر خانه می‌خواهد؛ برچسبِ کپسول از فاصله‌ای که گوشی فوکوس
     می‌کند حدودِ ۴ پیکسل است.

     با دوربینِ آزمایشی (QR برچسبِ کپسول، تار، روی بدنهٔ گرد، با
     انعکاس)، ۱۲ قاب برای هر نما:

       اندازهٔ QR در قاب   ×۱٫۱۵ (قبلی)   ×۲ واقعی   ×۳ واقعی
       ۹۰ پیکسل            ۰              ۱۲         ۱۲
       ۷۵ پیکسل            ۰              ۸          ۱۱

     سقف حالا فقط برای کلِ قاب است (که کوچک می‌شود نه بزرگ). بومِ
     وسط‌ها حداکثر ۱۹۲۰ پیکسل است — همان پهنای یک قابِ ۱۰۸۰. jsQR هم
     آزموده شد و در هیچ حالتی بیش از ZXing نخواند، پس نیامد.
     ------------------------------------------------------------------ */
  var VIEWS = [[1, 1], [0.5, 2], [1 / 3, 3]];
  function frame(view) {
    var vw = videoEl.videoWidth, vh = videoEl.videoHeight;
    if (!vw || !vh) return null;
    if (!cvs) { cvs = document.createElement('canvas'); cctx = cvs.getContext('2d', { willReadFrequently: true }); }
    var part = VIEWS[view][0], zoom = VIEWS[view][1];
    var sw = Math.round(vw * part), sh = Math.round(vh * part);
    var sx = (vw - sw) >> 1, sy = (vh - sh) >> 1;
    var k = zoom > 1 ? Math.min(zoom, 1920 / sw) : Math.min(1, 1100 / sw);
    cvs.width = Math.round(sw * k); cvs.height = Math.round(sh * k);
    cctx.drawImage(videoEl, sx, sy, sw, sh, 0, 0, cvs.width, cvs.height);
    return cvs;
  }

  /* کدخوانِ خودِ مرورگر، اگر QR را می‌شناسد. null یعنی نیست. */
  function nativeDetector() {
    if (!('BarcodeDetector' in window) || !window.BarcodeDetector.getSupportedFormats) {
      return Promise.resolve(null);
    }
    return window.BarcodeDetector.getSupportedFormats().then(function (have) {
      var want = ['qr_code', 'code_128', 'ean_13', 'ean_8', 'code_39', 'upc_a', 'data_matrix']
        .filter(function (f) { return have.indexOf(f) >= 0; });
      return want.indexOf('qr_code') >= 0 ? new window.BarcodeDetector({ formats: want }) : null;
    }).catch(function () { return null; });
  }

  /* فوکوس پیوسته — گوشی‌هایی که پیش‌فرضشان فوکوسِ یک‌باره است، بعد
     از اولین قاب روی بدنهٔ کپسول تار می‌ماندند. */
  function wantFocus(s) {
    try {
      var t = s.getVideoTracks()[0];
      var c = t && t.getCapabilities ? t.getCapabilities() : {};
      if (c.focusMode && c.focusMode.indexOf('continuous') >= 0) {
        t.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(function () {});
      }
    } catch (e) {}
  }

  /* ------------------------------------------------------------------
     شروع کدخوانی.

     ترتیب اینجا مهم است و همان چیزی است که باگ «تصویر یک دوربین،
     اسکن دوربینِ دیگر» را می‌ساخت: هیچ‌جا خودمان videoEl.srcObject را
     ست نمی‌کنیم. استریم را می‌گیریم و بلافاصله به ZXing می‌دهیم؛
     نمایش و کدخوانی هر دو از همان یک اتصال می‌آیند.
     ------------------------------------------------------------------ */
  function attach(s) {
    stream = s;
    wantFocus(s);
    if (box) box.classList.add('live');   /* CSS: .scan-view.live video{display:block} */

    return nativeDetector().then(function (det) {
      if (stream !== s) return;           /* در همین فاصله بسته شد */

      if (!det && !window.ZXing) {
        releaseCamera();
        hint('کتابخانهٔ اسکن بارگیری نشد. صفحه را دوباره بارگیری کنید یا کد را دستی بنویسید.', true);
        return;
      }

      /* فقط یک نفر ویدیو را وصل می‌کند — خودمان. decodeFromStream ِ
         ZXing دیگر به کار نمی‌رود، پس مشکلِ «دو مرجع برای یک ویدیو»
         (دورِ دوم، بالا) اصلاً پیش نمی‌آید. */
      videoEl.srcObject = s;
      var pl = videoEl.play(); if (pl && pl.catch) pl.catch(function () {});

      /* ------------------------------------------------------------------
         دو کدخوان به نوبت، نه یکی به‌جای دیگری — دورِ چهارم.

         گزارش: «بارکد سفارش را از آخرین تغییر اصلاً نمی‌خواند.» نسخهٔ
         قبل اگر BarcodeDetector بود، ZXing را کنار می‌گذاشت. ولی کدخوانِ
         کروم اندروید به ماژولِ خدماتِ گوگل‌پلی تکیه دارد؛ روی گوشی‌ای که
         آن ماژول را ندارد یا نتوانسته بگیرد (در ایران رایج)،
         getSupportedFormats همچنان qr_code می‌گوید و detect() همیشه
         فهرستِ خالی برمی‌گرداند — نه خطا، نه هشدار. آن‌وقت هیچ کدی
         خوانده نمی‌شد، در حالی که ZXing همان قاب را می‌خواند.

         حالا ZXing همیشه هست و یک قاب در میان کار می‌کند؛ کدخوانِ
         مرورگر اگر سالم باشد زودتر می‌رسد، و اگر نباشد چیزی را قفل
         نمی‌کند. آزمون: tests/t_scanfocus.php بندِ SF-Nx، و دوربینِ
         آزمایشی با کدخوانِ «مرده».
         ------------------------------------------------------------------ */
      var zx = window.ZXing ? zxReader() : null;
      var my = ++loopId, view = -1, turn = 0;

      (function tick() {
        if (my !== loopId || stream !== s) return;
        if (videoEl.readyState < 2) { setTimeout(tick, 80); return; }
        view = (view + 1) % VIEWS.length;
        var c = frame(view);              /* به نوبت: کلِ قاب، وسطِ بزرگ‌شده */
        if (!c) { setTimeout(tick, 80); return; }

        /* نوبتِ کدخوان پس از یک دورِ کاملِ نماها عوض می‌شود تا هر
           کدخوان همهٔ نماها را ببیند */
        var useNative = det && (!zx || (Math.floor(turn++ / VIEWS.length) % 2 === 0));
        if (useNative) {
          det.detect(c).then(function (list) {
            if (my !== loopId) return;
            if (list && list.length) onText(list[0].rawValue);
            setTimeout(tick, 40);
          }).catch(function () { setTimeout(tick, 150); });
          return;
        }
        var txt = zxDecode(zx, c);
        if (txt) onText(txt);
        setTimeout(tick, 40);
      })();
    });
  }

  function fallbackAny(msgWhileTrying) {
    hint(msgWhileTrying);
    return navigator.mediaDevices.getUserMedia({ video: true, audio: false })
      .then(attach)
      .then(function () { hint('کد را داخل کادر بگیرید.'); buildPicker(); })
      .catch(function () {
        releaseCamera();
        hint('دسترسی به دوربین ممکن نشد. کد را دستی بنویسید.', true);
      });
  }

  /* ------------------------------------------------------------------
     کادر پیش‌نمایش، اگر در صفحه نباشد، همین‌جا ساخته می‌شود.

     گزارش: «در صفحهٔ عودت کالا اسکن کار می‌کند ولی تصویر نمی‌آید.»

     علتش این بود: صفحهٔ ورود انبار چند زبانه دارد و کادر دوربین فقط
     در نشانهٔ یکی از آن‌ها نوشته شده بود. زبانه‌ها سمت *سرور* انتخاب
     می‌شوند، پس در زبانه‌های دیگر آن <div> اصلاً در صفحه نیست —
     نه پنهان، بلکه نبود. آن‌وقت videoEl می‌شد null و ZXing، که برای
     videoSourceِ خالی خودش یک <video> بیرون از صفحه می‌سازد، استریم
     را همان‌جا پخش می‌کرد: کدخوانی درست کار می‌کرد و هیچ تصویری
     دیده نمی‌شد. هیچ خطایی هم در کار نبود.

     می‌شد در آن صفحه یک کادر دیگر هم گذاشت، ولی همان اشکال دفعهٔ
     بعد در صفحهٔ بعدی تکرار می‌شد. پس مسئولیت به خودِ اسکنر منتقل
     شد: هر جا کادر نباشد، ساخته می‌شود — درست بالای همان فیلدی که
     قرار است کد در آن بنشیند.
     ------------------------------------------------------------------ */
  function buildBox(boxId, vidId) {
    var b = document.createElement('div');
    b.className = 'scan-view';
    b.id = boxId || 'scanBox';
    b.hidden = true;

    var v = document.createElement('video');
    v.id = vidId || 'scanVideo';
    v.setAttribute('playsinline', '');
    v.muted = true;
    v.autoplay = true;

    var f = document.createElement('div');
    f.className = 'scan-frame';

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn btn--ghost btn-sm';
    close.textContent = 'بستن دوربین';
    close.addEventListener('click', function () { window.stopScan(); });

    b.appendChild(v);
    b.appendChild(f);
    b.appendChild(close);
    return b;
  }

  /** جای درستِ کادر: بالای کادرِ فیلدی که کد در آن می‌نشیند */
  function placeBox(b, input) {
    var anchor = input && (input.closest('.field') || input.closest('form') || input);
    if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(b, anchor);
    else document.body.appendChild(b);
  }

  /* ترتیب پارامترها عمداً (id, boxId, vidId) است — همان چیزی که
     صفحهٔ خروج کالا از قبل صدا می‌زد. */
  window.startScan = function (id, boxId, vidId) {
    target  = document.getElementById(id);
    box     = document.getElementById(boxId || 'scanBox');
    videoEl = document.getElementById(vidId || 'scanVideo');

    /* کادری که هست ولی ویدیو ندارد (نشانهٔ ناقص) هم درست می‌شود —
       بدون اینکه کادر دومی ساخته شود. */
    if (box && box.isConnected && (!videoEl || !box.contains(videoEl))) {
      videoEl = box.querySelector('video');
      if (!videoEl) {
        videoEl = buildBox(boxId, vidId).querySelector('video');
        box.insertBefore(videoEl, box.firstChild);
      }
    }

    /* isConnected هم سنجیده می‌شود نه فقط وجود: کادری که خودمان در
       بارِ قبلی ساخته‌ایم ممکن است با رفتن صفحه از DOM جدا شده باشد. */
    if (!box || !box.isConnected) {
      box     = buildBox(boxId, vidId);
      videoEl = box.querySelector('video');
      placeBox(box, target);
    }

    box.hidden = false;

    if (!secureOk()) {
      hint('دوربین فقط روی <b>HTTPS</b> کار می‌کند و این صفحه با http باز شده. '
         + 'آدرس سایت را با <code>https://</code> باز کنید، یا کد را دستی بنویسید.', true);
      return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      hint('مرورگر شما دوربین را پشتیبانی نمی‌کند. کد را دستی بنویسید.', true);
      return;
    }
    if (!window.ZXing && !('BarcodeDetector' in window)) {
      hint('کتابخانهٔ اسکن بارگیری نشد. صفحه را دوباره بارگیری کنید یا کد را دستی بنویسید.', true);
      return;
    }

    hint('در حال روشن کردن دوربین…');

    /* اگر انباردار قبلاً دوربین درست را انتخاب کرده، دقیقاً همان را
       می‌خواهیم (exact) — نه «یکی از پشتی‌ها». */
    var pick = savedCam();
    var want = pick
      ? { deviceId: { exact: pick }, width: { ideal: 1920 }, height: { ideal: 1080 } }
      : { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } };

    navigator.mediaDevices.getUserMedia({ video: want, audio: false })
      .then(attach)
      .then(function () {
        hint('کد را داخل کادر بگیرید. نخواند؟ دوربینِ دیگری را از فهرستِ بالا انتخاب کنید.');
        buildPicker();
      })
      .catch(function (e) {
        var n = e && e.name;
        releaseCamera();

        /* دوربینِ ذخیره‌شده دیگر وجود ندارد (گوشی عوض شده، یا مرورگر
           deviceId را از نو ساخته). انتخاب را دور می‌ریزیم و یک بار
           با هر دوربینی تلاش می‌کنیم. */
        if (pick) {
          saveCam('');
          fallbackAny('دوربین ذخیره‌شده در دسترس نیست، با دوربین دیگری تلاش می‌کنم…');
          return;
        }

        if (n === 'OverconstrainedError') {
          /* دوربین پشت وجود ندارد (مثلاً لپ‌تاپ) — با هر دوربینی دوباره
             تلاش می‌کنیم به‌جای اینکه خطا بدهیم. */
          fallbackAny('دوربین پشت پیدا نشد، با دوربین جلو تلاش می‌کنم…');
          return;
        }

        /* پیام بر اساس علتِ واقعی — چون راهِ حلِ هرکدام فرق دارد. */
        var m = 'دسترسی به دوربین ممکن نشد. کد را دستی بنویسید.';
        if (n === 'NotAllowedError' || n === 'PermissionDeniedError') {
          m = 'اجازهٔ دوربین داده نشد. از نوار آدرس مرورگر (نشان قفل) '
            + 'اجازهٔ دوربین را بدهید و دوباره تلاش کنید.';
        } else if (n === 'NotFoundError' || n === 'DevicesNotFoundError') {
          m = 'دوربینی روی این دستگاه پیدا نشد.';
        } else if (n === 'NotReadableError' || n === 'TrackStartError') {
          m = 'دوربین در اختیار برنامهٔ دیگری است. آن برنامه را ببندید و دوباره تلاش کنید.';
        }
        hint(m, true);
      });
  };

  window.stopScan = function () {
    /* ترتیب مهم است: اول reader را متوقف کن، بعد track را ببند. */
    loopId++;                             /* دورِ کدخوانِ مرورگر هم می‌ایستد */
    lastTxt = '';
    if (reader) { try { reader.reset(); } catch (e) {} reader = null; }
    releaseCamera();
    if (box) {
      box.hidden = true;
      var h = box.querySelector('.scan-hint');
      if (h) h.innerHTML = '';
      var s = box.querySelector('.scan-cam');
      if (s) s.remove();
    }
  };

  /* صفحهٔ خروج کالا بعد از خواندن کد، فرم را می‌فرستد. */
  window.setScanAutoSubmit = function (v) { autoSubmit = !!v; };

  /* اگر کاربر صفحه را ترک کند یا تب را عوض کند، دوربین نباید روشن
     بماند — هم مصرف باتری، هم اینکه دفعهٔ بعد «مشغول» می‌شود. */
  window.addEventListener('pagehide', function () { window.stopScan(); });
  document.addEventListener('visibilitychange', function () {
    if (document.hidden && stream) window.stopScan();
  });
})();
