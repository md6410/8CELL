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
vmsg('یافت نشد', 404, 'یافت نشد');
