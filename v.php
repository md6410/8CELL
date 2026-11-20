<?php
// تأییدِ اصالتِ سند (t=…، مقصدِ QR) و لینکِ دریافت با رمز (s=…) — همان روالِ Invora (v.php / d.php):
//  - با باز شدنِ QR، رمزی یک‌بارمصرف به موبایلِ «رابطِ» سند پیامک می‌شود؛ صفحه فقط نامِ رابط و بخشی از شماره را نشان می‌دهد.
//  - تا رمزِ درست زده نشود هیچ‌چیز از محتوای سند (حتی شماره و مبلغ) نمایش داده نمی‌شود.
//  - هر اسکن، فرستادنِ رمز، رمزِ درست یا اشتباه و دیدنِ سند با IP و دستگاه ثبت می‌شود.
declare(strict_types=1);
require __DIR__ . '/inc/boot.php';
app_boot();
header('X-Robots-Tag: noindex, nofollow'); header('Referrer-Policy: no-referrer'); header('Cache-Control: no-store');
function vpage(string $title, string $body, int $code = 200): void {
    http_response_code($code);
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . h($title) . '</title><link rel="stylesheet" href="assets/app.css"></head><body class="pub"><main class="pubbox login">' . $body . '</main></body></html>';
    exit;
}
function vmsg(string $msg, int $code = 200, string $title = 'تأییدِ اصالتِ سند'): void { vpage($title, '<h1>' . h($title) . '</h1><p class="err">' . h($msg) . '</p>', $code); }
function vcsrf_ok(): bool { $t = (string)($_POST['csrf'] ?? ''); return $t !== '' && hash_equals(csrf_token(), $t); }
$csrf = h(csrf_token());
$pinJs = '<script>(function(){var pi=document.querySelector("input[name=pin]");function nm(x){var s=String(x||"").replace(/[۰-۹]/g,function(d){return d.charCodeAt(0)-1776}).replace(/[٠-٩]/g,function(d){return d.charCodeAt(0)-1632});var m=s.match(/\d{4,5}/g);return m?m[m.length-1]:""}'
    . 'if(pi){pi.addEventListener("input",function(){if(/[^0-9]/.test(pi.value)||pi.value.length>5){var c=nm(pi.value);if(c)pi.value=c}});if("OTPCredential" in window&&navigator.credentials&&pi.getAttribute("data-otp")){navigator.credentials.get({otp:{transport:["sms"]}}).then(function(o){var c=nm(o&&o.code);if(c){pi.value=c;pi.form.submit()}}).catch(function(){})}}})();</script>';

// ── QRِ اصالت
if (isset($_GET['t'])) {
    $t = (string)$_GET['t'];
    if (!preg_match('/^[A-Za-z0-9_-]{16,32}$/', $t)) vmsg('این کد معتبر نیست؛ این سند در سامانه ثبت نشده است.', 404);
    $r = row("SELECT id FROM docs WHERE verify_token=?", [$t]);
    if (!$r) vmsg('این کد در سامانه پیدا نشد؛ اصالتِ این سند تأیید نمی‌شود.', 404);
    $d = doc_get((int)$r['id']);
    if ($d['status'] === 'void') { vf_log((int)$d['id'], 'vf_dead'); vmsg('این سند در سامانهٔ صادرکننده باطل شده است و معتبر نیست.', 410); }
    $err = ''; $info = ''; $test = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !vf_pass_ok($d)) {
        if (!vcsrf_ok()) $err = empty($_COOKIE[session_name()]) ? 'این مرورگر کوکی را نمی‌پذیرد، برای همین رمز ثبت نمی‌شود. صفحه را در مرورگرِ اصلیِ گوشی (Chrome، Safari…) باز کنید.' : 'مهلتِ صفحه تمام شده بود؛ دوباره امتحان کنید.';
        elseif (($_POST['act'] ?? '') === 'send') { [$ok, $m, $test] = vf_send_code($d); if ($ok) $info = $m; else $err = $m; }
        elseif (($_POST['act'] ?? '') === 'pin') { [$ok, $m] = vf_check_pin($d, (string)($_POST['pin'] ?? '')); if ($ok) { header('Location: v.php?t=' . rawurlencode($t)); exit; } $err = $m; }
    }
    if (!vf_pass_ok($d)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') vf_log((int)$d['id'], 'vf_open');
        $m = (string)$d['contact_mobile'];
        $sent = !empty($_SESSION['vf']['vf' . $d['id']]['hs']) && (int)($_SESSION['vf']['vf' . $d['id']]['exp'] ?? 0) > time();
        $who = $m === '' ? '' : ($d['contact_name'] ? '<b>' . h($d['contact_name']) . '</b>' : 'رابطِ این سند') . ' به شمارهٔ <b dir="ltr" style="unicode-bidi:isolate">' . h(fa_num(sms_mask($m))) . '</b>';
        $b = '<h1>تأییدِ اصالتِ سند</h1><p>این کد در سامانهٔ صادرکنندهٔ سند ثبت شده است. برای دیدنِ خودِ سند و مقایسه با نسخه‌ای که در دست دارید، رمزِ پیامک‌شده را وارد کنید.</p>'
            . ($err ? '<p class="err">' . h($err) . '</p>' : '') . ($info ? '<p class="ok">' . h($info) . '</p>' : '') . ($test !== '' ? '<p class="muted">حالتِ آزمایشیِ پیامک: رمز ' . h(fa_num($test)) . '</p>' : '');
        if ($m === '') $b .= '<p class="err">برای این سند رابطی با شمارهٔ موبایل ثبت نشده است؛ برای دیدنِ سند با صادرکنندهٔ آن تماس بگیرید.</p>';
        else $b .= '<p>رمز به ' . $who . ' پیامک ' . ($sent || $info ? 'شد' : 'می‌شود') . '. رمز را از ایشان بگیرید.</p>'
            . '<form method="post" autocomplete="off" class="form"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="act" value="pin"><label class="field"><span>رمزِ ۵ رقمی</span><input name="pin" inputmode="numeric" maxlength="40" autocomplete="one-time-code" data-otp="1" required' . ($sent || $info ? ' autofocus' : '') . '></label><div class="actions"><button class="btn primary">دیدنِ سند</button></div></form>'
            . '<form id="sf" method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="act" value="send"><button class="btn" style="width:100%">' . ($sent || $info ? 'فرستادنِ دوبارهٔ رمز' : 'فرستادنِ رمز') . '</button></form>'
            . '<p class="muted">رمز ' . fa_num(VF_CODE_MIN) . ' دقیقه اعتبار دارد. هر اسکن و هر بار دیدنِ سند با آی‌پی و مشخصاتِ دستگاه ثبت می‌شود.</p>'
            . ($sent || $info || $_SERVER['REQUEST_METHOD'] === 'POST' ? '' : '<script>(function(){var k="vfs' . (int)$d['id'] . '";try{if(!sessionStorage.getItem(k)){sessionStorage.setItem(k,"1");document.getElementById("sf").submit()}}catch(e){}})();</script>');
        vpage('تأییدِ اصالتِ سند', $b . $pinJs);
    }
    $st = doc_status($d);
    $head = '<div class="noprint" style="width:210mm;max-width:100%;background:#fff;border-radius:10px;padding:10px 14px;font:14px Vazirmatn,Tahoma"><b style="color:' . ($d['off_canceled'] ? '#b91c1c' : '#047857') . '">' . ($d['off_canceled'] ? '⚠️ این صورتحساب ابطال شده است' : '✅ این سند در سامانهٔ صادرکننده ثبت شده و معتبر است') . '</b> — ' . h($st[0]) . ' · نسخهٔ ' . fa_num($d['revision']) . ($d['confirmed'] ? ' · تأییدشده توسطِ خریدار' : '')
        . ' · <button onclick="print()" style="font:inherit">چاپ</button></div>';
    echo doc_print_page($d, $head . ($d['vf_html'] ?: doc_render_html($d))); exit;
}
// ── لینکِ رمزدار
if (isset($_GET['s'])) {
    $t = (string)$_GET['s'];
    if (!preg_match('/^([a-f0-9]{32}|[A-Za-z0-9_-]{22})$/', $t)) vmsg('لینک نامعتبر است.', 404, 'دریافتِ فایل');
    $pin = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { if (!vcsrf_ok()) vpage('دریافتِ فایل', '<h1>دریافتِ فایل</h1><p class="err">' . (empty($_COOKIE[session_name()]) ? 'این مرورگر کوکی را نمی‌پذیرد؛ لینک را در مرورگرِ اصلیِ گوشی یا رایانه باز کنید. این تلاش اشتباه حساب نشد.' : 'مهلتِ صفحه تمام شده بود؛ دوباره رمز را وارد کنید. این تلاش اشتباه حساب نشد.') . '</p><p><a href="v.php?s=' . h($t) . '">دوباره</a></p>'); $pin = trim((string)($_POST['pin'] ?? '')) ?: 'x'; }
    $r = doc_share_open($t, $pin);
    if (!empty($r['err']) && empty($r['need_pass'])) vmsg($r['err'], (int)($r['code'] ?? 400), 'دریافتِ فایل');
    if (!empty($r['need_pass'])) vpage('دریافتِ فایل', '<h1>دریافتِ فایل</h1><p>برای دیدنِ فایل، رمزی را که فرستنده جداگانه داده وارد کنید.</p>' . (!empty($r['err']) ? '<p class="err">' . h($r['err']) . '</p>' : '')
        . '<form method="post" class="form" autocomplete="off"><input type="hidden" name="csrf" value="' . $csrf . '"><label class="field"><span>رمز</span><input name="pin" inputmode="numeric" maxlength="40" autofocus required></label><div class="actions"><button class="btn primary">نمایش</button></div></form>' . $pinJs);
    echo doc_print_page($r['doc'], ($r['doc']['vf_html'] ?? '') ?: doc_render_html($r['doc'])); exit;
}
// ── لینکِ ثبتِ نشانی (روالِ Weby addr-link): بی‌ورود، با توکنِ امضاشدهٔ ۷ روزه
if (isset($_GET['a'])) {
    $L = addr_link_parse((string)$_GET['a']);
    if (!$L) vmsg('این لینک معتبر نیست.', 404, 'ثبتِ نشانی'); if (!empty($L['expired'])) vmsg('مهلتِ این لینک تمام شده است؛ از فروشگاه لینکِ تازه بخواهید.', 410, 'ثبتِ نشانی');
    $c = $L['customer']; $err = ''; $done = false; $in = $_POST;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { if (!vcsrf_ok()) $err = 'مهلتِ صفحه تمام شده بود؛ دوباره بفرستید.'; else { try { address_save((int)$c['id'], $in, 'link', $L['postal']); $done = true; } catch (UserError $e) { $err = $e->getMessage(); } } }
    if ($done) vpage('ثبتِ نشانی', '<h1>✅ نشانی ثبت شد</h1><p>' . h($c['name']) . ' عزیز، نشانیِ شما در ' . h((string)setting('biz_name', '')) . ' ذخیره شد. سپاس.</p>');
    $v = fn($k) => h((string)($in[$k] ?? ''));
    $b = '<h1>ثبتِ نشانی</h1><p>' . h($c['name']) . ' عزیز، نشانیِ تحویل را بنویسید' . ($L['postal'] ? ' (نشانیِ کامل و کدِ پستی لازم است)' : '') . '. با دکمهٔ «موقعیتِ من» محلِ دقیق را هم بفرستید تا پیک راحت پیدا کند.</p>' . ($err ? '<p class="err">' . h($err) . '</p>' : '')
        . '<form method="post" class="form" autocomplete="on"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="lat" id="lat" value="' . $v('lat') . '"><input type="hidden" name="lng" id="lng" value="' . $v('lng') . '">'
        . '<label class="field"><span>گیرنده</span><input name="receiver_name" value="' . ($v('receiver_name') ?: h($c['name'])) . '"></label><label class="field"><span>موبایلِ گیرنده</span><input name="receiver_mobile" dir="ltr" inputmode="tel" value="' . ($v('receiver_mobile') ?: h((string)$c['mobile'])) . '"></label>'
        . '<label class="field"><span>استان</span><input name="province" value="' . $v('province') . '"></label><label class="field"><span>شهر *</span><input name="city" value="' . $v('city') . '"' . ($L['postal'] ? ' required' : '') . '></label>'
        . '<label class="field"><span>نشانی *</span><textarea name="address" rows="3"' . ($L['postal'] ? ' required' : '') . '>' . $v('address') . '</textarea></label><label class="field"><span>پلاک</span><input name="plaque" value="' . $v('plaque') . '"></label><label class="field"><span>واحد</span><input name="unit" value="' . $v('unit') . '"></label>'
        . '<label class="field"><span>کدِ پستی' . ($L['postal'] ? ' *' : '') . '</span><input name="postal_code" dir="ltr" inputmode="numeric" maxlength="12" value="' . $v('postal_code') . '"' . ($L['postal'] ? ' required' : '') . '></label>'
        . '<p><button type="button" class="btn" id="geo">📍 موقعیتِ من</button> <span id="geos" class="muted">' . ($v('lat') ? 'موقعیت گرفته شد' : '') . '</span></p><div class="actions"><button class="btn primary">ثبتِ نشانی</button></div></form>'
        . '<script>document.getElementById("geo").onclick=function(){var s=document.getElementById("geos");if(!navigator.geolocation){s.textContent="مرورگر موقعیت نمی‌دهد";return}s.textContent="در حالِ گرفتن…";navigator.geolocation.getCurrentPosition(function(p){document.getElementById("lat").value=p.coords.latitude;document.getElementById("lng").value=p.coords.longitude;s.textContent="✅ موقعیت گرفته شد (دقت حدود "+Math.round(p.coords.accuracy)+" متر)"},function(){s.textContent="اجازهٔ موقعیت داده نشد؛ نشانی را بنویسید"},{enableHighAccuracy:true,timeout:15000})};</script>';
    vpage('ثبتِ نشانی', $b);
}
// ── کارتِ عمومیِ ضمانت (کدِ تصادفی، نه ترتیبی) — استعلام با کدِ کارت یا شمارهٔ هولوگرام
if (isset($_GET['w'])) {
    $q = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$_GET['w']));
    $w = $q !== '' ? row("SELECT * FROM warranty_items WHERE code=? OR hologram=?", [$q, $q]) : null;
    if (!$w) vmsg('ضمانتی با این کد یا هولوگرام در سامانهٔ فروشگاه ثبت نشده است.', 404, 'استعلامِ ضمانت');
    $t = $w['type_id'] ? row("SELECT terms FROM warranty_types WHERE id=?", [$w['type_id']]) : null; $ok = $w['status'] === 'active';
    vpage('استعلامِ ضمانت', '<h1>' . ($ok ? '✅ ' : '') . 'ضمانتِ ' . h((string)setting('biz_name', '')) . '</h1><dl class="kv"><dt>کالا</dt><dd>' . h($w['product_title']) . '</dd><dt>وضعیت</dt><dd><b class="' . ($ok ? 'ok' : 'err') . '">' . h(warranty_statuses()[$w['status']] ?? '') . '</b></dd>'
        . '<dt>نوع</dt><dd>' . h($w['type_name']) . ' — ' . fa_num((string)$w['months']) . ' ماه</dd>' . ($w['activated_at'] ? '<dt>شروع</dt><dd>' . jdate($w['activated_at']) . '</dd><dt>پایان</dt><dd>' . jdate((string)$w['expires_at']) . '</dd>' : '')
        . '<dt>هولوگرام</dt><dd dir="ltr">' . h($w['hologram'] ? substr($w['hologram'], 0, 2) . str_repeat('•', max(0, strlen($w['hologram']) - 4)) . substr($w['hologram'], -2) : '—') . '</dd><dt>صاحب</dt><dd dir="ltr">' . h(fa_num(sms_mask((string)$w['buyer_mobile']))) . '</dd></dl>'
        . ($t && $t['terms'] ? '<h3>شرایط</h3><div style="white-space:pre-wrap">' . h($t['terms']) . '</div>' : ''));
}
// ── پذیرشِ دعوتِ فروشنده (لینکِ امضاشدهٔ ۷ روزه)
if (isset($_GET['sj'])) {
    $sid = seller_invite_parse((string)$_GET['sj']); $sup = $sid ? row("SELECT * FROM suppliers WHERE id=?", [$sid]) : null;
    if (!$sup) vmsg('این لینکِ دعوت نامعتبر است یا مهلتش گذشته؛ از فروشگاه لینکِ تازه بخواهید.', 404, 'پنلِ فروشنده');
    $err = ''; $ex = row("SELECT username FROM users WHERE supplier_id=? AND kind='seller'", [$sid]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { if (!vcsrf_ok()) $err = 'مهلتِ صفحه تمام شده بود؛ دوباره بفرستید.'; elseif (($_POST['p1'] ?? '') !== ($_POST['p2'] ?? '')) $err = 'دو رمز یکی نیستند';
        else { try { seller_join($sid, (string)($_POST['u'] ?? ''), (string)$_POST['p1']); vpage('پنلِ فروشنده', '<h1>✅ آماده است</h1><p>اکنون با نامِ کاربری و رمزِ خود وارد شوید.</p><p><a class="btn primary" href="index.php?p=login">ورود</a></p>'); } catch (UserError $e) { $err = $e->getMessage(); } } }
    vpage('پنلِ فروشنده', '<h1>پنلِ فروشنده — ' . h((string)setting('biz_name', '')) . '</h1><p>' . h($sup['name']) . ' عزیز، نامِ کاربری و رمز برای ورود به پنلِ خود بگذارید.</p>' . ($err ? '<p class="err">' . h($err) . '</p>' : '')
        . '<form method="post" class="form"><input type="hidden" name="csrf" value="' . $csrf . '"><label class="field"><span>نامِ کاربری (لاتین)</span><input name="u" dir="ltr" required value="' . h((string)($_POST['u'] ?? ($ex['username'] ?? ''))) . '"></label>'
        . '<label class="field"><span>رمز (۸ نویسه به بالا)</span><input type="password" name="p1" required minlength="8" autocomplete="new-password"></label><label class="field"><span>تکرارِ رمز</span><input type="password" name="p2" required autocomplete="new-password"></label><div class="actions"><button class="btn primary">ذخیره</button></div></form>');
}
// ── فهرستِ قیمتِ همکار (لینکِ امضاشدهٔ ۳۰ روزه)
if (isset($_GET['pl'])) {
    $cid = plist_parse((string)$_GET['pl']); $c = $cid ? row("SELECT * FROM customers WHERE id=? AND is_partner=1", [$cid]) : null;
    if (!$c) vmsg('این لینک معتبر نیست یا مهلتش تمام شده است.', 404, 'فهرستِ قیمت');
    $pl = partner_price_list($cid); $no = partner_list_doc($cid, count($pl)); $g = (string)val("SELECT name FROM grades WHERE id=?", [(int)$c['partner_grade_id']]);
    $rows = ''; foreach ($pl as $r) $rows .= '<tr><td>' . h($r['name']) . '</td><td class="num">' . fa_money($r['retail']) . '</td><td class="num"><b>' . fa_money($r['price_one']) . '</b></td><td class="num">' . ($r['qty_max'] > 1 && $r['price_max'] < $r['price_one'] ? fa_money($r['price_max']) . ' <small>(از ' . fa_num((string)$r['qty_max']) . ' عدد)</small>' : '—') . '</td></tr>';
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>فهرستِ قیمتِ همکاری</title><link rel="stylesheet" href="assets/app.css"></head><body class="pub"><main class="pubbox" style="max-width:900px">'
        . '<h1>فهرستِ قیمتِ همکاری — ' . h((string)setting('biz_name', '')) . '</h1><p>' . h($c['name']) . ($g ? ' · گریدِ ' . h($g) : '') . ' · شمارهٔ فهرست <b dir="ltr">' . h(fa_num($no)) . '</b> · ' . jdate(today()) . ' · <button onclick="print()" class="btn sm">چاپ</button></p>'
        . '<p class="muted">قیمت‌ها به ریال و روزِ صدور معتبرند؛ تخفیفِ همکاری با تعدادِ هر کالا تا ستونِ آخر بیشتر می‌شود.</p><table class="tbl"><tr><th>کالا</th><th>قیمتِ مصرف‌کننده</th><th>قیمتِ همکار</th><th>با تعدادِ بیشتر</th></tr>' . ($rows ?: '<tr><td colspan="4">کالایی برای گریدِ شما تعریف نشده است.</td></tr>') . '</table></main></body></html>';
    exit;
}
vmsg('یافت نشد', 404, 'یافت نشد');
