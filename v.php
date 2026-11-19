<?php
// تأییدِ اصالتِ سند (t=…) و لینکِ دریافتِ فاکتور با رمز (s=…) — بدونِ ورود
declare(strict_types=1);
require __DIR__ . '/inc/boot.php';
app_boot();
$out = function (string $body) { echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>سند</title><link rel="stylesheet" href="assets/app.css"></head><body class="pub"><main class="pubbox">' . $body . '</main></body></html>'; };
if (!empty($_GET['t'])) {
    $d = row("SELECT * FROM docs WHERE verify_token=? AND status IN ('issued','void')", [preg_replace('/[^a-f0-9]/', '', (string)$_GET['t'])]);
    if (!$d) { http_response_code(404); $out('<h1>سند یافت نشد</h1><p>این سند در سامانه ثبت نشده است.</p>'); exit; }
    $s = json_decode($d['seller'], true) ?: [];
    $out('<h1>' . ($d['status'] === 'void' ? '⚠️ این سند باطل شده است' : '✅ سندِ معتبر') . '</h1><table class="tbl"><tr><th>صادرکننده</th><td>' . h($s['name'] ?? '') . '</td></tr><tr><th>نوع</th><td>' . h(doc_kinds()[$d['kind']] ?? '') . '</td></tr><tr><th>شماره</th><td>' . h(fa_num($d['number'])) . '</td></tr><tr><th>تاریخ</th><td>' . jdate($d['d']) . '</td></tr><tr><th>مبلغ</th><td>' . fa_rial($d['total']) . '</td></tr><tr><th>نسخه</th><td>' . fa_num($d['revision']) . '</td></tr></table><p class="muted">جزئیاتِ اقلام فقط با لینکِ رمزدارِ دریافت دیده می‌شود.</p>');
    exit;
}
if (!empty($_GET['s'])) {
    $t = preg_replace('/[^a-f0-9]/', '', (string)$_GET['s']);
    $r = doc_share_open($t, (string)($_POST['pass'] ?? ''));
    if (!$r) { http_response_code(404); $out('<h1>لینک نامعتبر یا منقضی است</h1>'); exit; }
    if (!empty($r['need_pass'])) { $out('<h1>دریافتِ فاکتور</h1><form method="post" class="form"><label class="field"><span>رمزِ لینک</span><input name="pass" type="password" autofocus></label><button class="btn primary">نمایش</button></form>' . (!empty($_POST['pass']) ? '<p class="err">رمز نادرست است.</p>' : '')); exit; }
    echo doc_print_page($r['doc']); exit;
}
http_response_code(404); $out('<h1>یافت نشد</h1>');
