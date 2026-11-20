<?php
// نقطهٔ ورودِ صفحه‌ها: index.php?p=<page>  ·  index.php?health=1 → ok
declare(strict_types=1);
require __DIR__ . '/inc/boot.php';
if (isset($_GET['health'])) {
    header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store');
    try { if (!app_installed()) { echo 'ok'; exit; } app_boot(false); val("SELECT 1"); echo 'ok'; } catch (Throwable $e) { http_response_code(500); echo 'fail'; }
    exit;
}
if (!app_installed()) { header('Location: install.php'); exit; }
try { app_boot(); } catch (Throwable $e) { http_response_code(500); error_log($e->getMessage()); echo '<!doctype html><meta charset="utf-8"><p dir="rtl">اتصال به پایگاه‌داده برقرار نشد. config.local.php را بررسی کنید.</p>'; exit; }

$p = preg_replace('/[^a-z_]/', '', (string)($_GET['p'] ?? ''));
// صفحه‌های عمومی
if ($p === 'catalog') { require ROOT . '/pages/catalog.php'; exit; }
if ($p === 'captcha') { df_cap_image(); exit; }
if ($p === 'cron') { if (hash_equals((string)setting('cron_key', ''), (string)($_GET['k'] ?? '')) && setting('cron_key', '') !== '') { $sm = 0; $nt = []; try { $sm = sms_retry_due(); } catch (Throwable $e) { error_log($e->getMessage()); } try { $nt = notify_run(); } catch (Throwable $e) { error_log($e->getMessage()); } try { $nt['backup'] = backup_cron(); } catch (Throwable $e) { error_log($e->getMessage()); } echo json_encode(df_upd_run(false, true, 'cron') + ['sms_retried' => $sm, 'notify' => $nt], JSON_UNESCAPED_UNICODE); } else http_response_code(403); exit; }
// صفحه‌های چاپ (با نشست یا توکنِ کارِ چاپ برای ایستگاه)
if (in_array($p, ['print_doc', 'print_label', 'print_receipt', 'print_shift', 'print_payslip'], true)) {
    $job = print_job_grant();
    if (!$job && !current_user()) { http_response_code(401); exit('ابتدا وارد شوید'); }
    if ($job) set_current_user((int)$job['created_by'] ?: null);
    require ROOT . "/pages/$p.php"; exit;
}
$u = current_user();
if (!$u || $p === 'login') { require ROOT . '/pages/login.php'; exit; }
$pages = pages_all();
if ($p === '') { $p = ($u['kind'] ?? '') === 'seller' ? 'seller' : (can('reports.dashboard') ? 'dashboard' : (can('pos.use') ? 'pos' : 'me')); }
if (!isset($pages[$p])) { http_response_code(404); $p = 'me'; }
[$title, $cap] = $pages[$p];
if ($cap !== 'login' && !can($cap)) { http_response_code(403); ui_layout_start('دسترسی ندارید', $p); echo '<p class="empty">این دسترسی را ندارید.</p>'; ui_layout_end(); exit; }
if ($p === 'payreq_file') { payreq_file_out(req_int('id')); }
if ($p === 'tickets' && isset($_GET['file'])) { $m = row("SELECT m.file, m.ticket_id FROM ticket_msgs m WHERE m.id=?", [req_int('file')]); if (!$m || !$m['file']) { http_response_code(404); exit; } ticket_get((int)$m['ticket_id']); $fp = DATA . '/uploads/' . $m['file'];
    if (!is_file($fp) || str_contains((string)$m['file'], '..')) { http_response_code(404); exit; } header('Content-Type: ' . payreq_sniff($fp)); header('X-Content-Type-Options: nosniff'); readfile($fp); exit; }
if ($p === 'letters' && isset($_GET['print'])) { $l = row("SELECT * FROM letters WHERE id=?", [req_int('print')]); if (!$l) exit('یافت نشد');
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>نامه ' . h((string)$l['full_no']) . '</title><link rel="stylesheet" href="assets/doc.css"><style>@page{size:A4;margin:0}body{margin:0;background:#eee;font-family:Vazirmatn,Tahoma}.letter{width:210mm;min-height:297mm;margin:10px auto;background:#fff;padding:14mm 18mm;box-sizing:border-box;display:flex;flex-direction:column}.lh{display:flex;gap:12px;align-items:center;border-bottom:2px solid #1f4e79;padding-bottom:8px}.lh>div{flex:1}.lh small{display:block;color:#555}.lh .lg{max-height:22mm}.lh dl{display:grid;grid-template-columns:auto auto;gap:2px 8px;font-size:12px;margin:0}.lh dt{color:#555}main{flex:1;padding-top:10mm;font-size:14px;line-height:2}.sb{margin:6mm 0}.sg{text-align:left;margin-top:12mm;position:relative;min-height:30mm}.sg img{max-height:28mm;margin-inline-start:6mm}.cc{font-size:12px;color:#444}footer{border-top:1px solid #ccc;font-size:11px;color:#555;padding-top:4px;text-align:center}@media print{body{background:#fff}.letter{margin:0}.np{display:none}}</style></head><body>'
        . '<p class="np" style="text-align:center"><button onclick="print()">چاپ / PDF</button></p>' . letter_html($l) . '</body></html>'; exit; }
if ($p === 'backup' && isset($_GET['dl'])) { // دانلودِ پشتیبان: با رمزِ تازه‌واردشده در همین نشست (۱۰ دقیقه)
    if ((int)($_SESSION['bk_ok'] ?? 0) < time() - 600) { http_response_code(403); exit('اول رمز را در صفحهٔ پشتیبان تأیید کنید'); }
    $f = bk_dir() . '/' . bk_safe((string)$_GET['dl']); if (!is_file($f)) { http_response_code(404); exit; }
    audit('backups', basename($f), 'download', null, null, 'دانلودِ پشتیبان'); header('Content-Type: application/x-tar'); header('Content-Length: ' . filesize($f)); header('Content-Disposition: attachment; filename="' . basename($f) . '"'); readfile($f); exit; }
if ($p === 'backup' && isset($_GET['export'])) { ds_export((string)$_GET['export']); exit; }
if (can('update.manage')) df_upd_maybe_auto();
$file = apply_filters('page_file', ROOT . "/pages/$p.php", $p);
try {
    ob_start();
    ui_layout_start($title, $p);
    require $file;
    ui_layout_end();
    ob_end_flush();
} catch (UserError $e) {
    ob_end_clean(); ui_layout_start($title, $p); echo '<p class="err">' . h($e->getMessage()) . '</p>'; ui_layout_end();
}
