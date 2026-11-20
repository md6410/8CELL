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
if ($p === 'cron') { if (hash_equals((string)setting('cron_key', ''), (string)($_GET['k'] ?? '')) && setting('cron_key', '') !== '') { $sm = 0; try { $sm = sms_retry_due(); } catch (Throwable $e) { error_log($e->getMessage()); } echo json_encode(df_upd_run(false, true, 'cron') + ['sms_retried' => $sm], JSON_UNESCAPED_UNICODE); } else http_response_code(403); exit; }
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
if ($p === '') { $p = can('reports.dashboard') ? 'dashboard' : (can('pos.use') ? 'pos' : 'me'); }
if (!isset($pages[$p])) { http_response_code(404); $p = 'me'; }
[$title, $cap] = $pages[$p];
if ($cap !== 'login' && !can($cap)) { http_response_code(403); ui_layout_start('دسترسی ندارید', $p); echo '<p class="empty">این دسترسی را ندارید.</p>'; ui_layout_end(); exit; }
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
