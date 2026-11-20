<?php
// راه‌اندازیِ هسته: پیکربندی، پایگاه‌داده، مهاجرت، نشست، ماژول‌ها
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
if (!defined('DATA')) define('DATA', ROOT . '/data');
require_once ROOT . '/inc/version.php';
foreach (['util', 'jalali', 'db', 'schema', 'settings', 'audit', 'fiscal', 'hooks', 'ext', 'update', 'auth', 'captcha', 'sms', 'otp', 'passkey', 'calendar', 'products', 'warehouse',
    'purchase', 'coding', 'scan', 'labels', 'printq', 'invoice', 'warranty', 'moadian', 'pos', 'amend', 'partner', 'seller', 'payreq', 'channels', 'staff', 'hr', 'customers', 'credit', 'notify', 'ship', 'ledger', 'accshare', 'reports', 'ui', 'routes'] as $__f) require_once ROOT . "/inc/$__f.php";
unset($__f);
date_default_timezone_set('Asia/Tehran');
mb_internal_encoding('UTF-8');

function app_installed(): bool { return is_file(ROOT . '/config.local.php') || !empty($GLOBALS['CFG']['db']); }
function app_boot(bool $session = true): void {
    if (empty($GLOBALS['CFG'])) $GLOBALS['CFG'] = is_file(ROOT . '/config.local.php') ? (require ROOT . '/config.local.php') : [];
    if (!empty($GLOBALS['CFG']['base_url'])) define('BASE_URL', $GLOBALS['CFG']['base_url']);
    if (!is_dir(DATA)) @mkdir(DATA, 0755, true);
    if (!is_file(DATA . '/.htaccess')) @file_put_contents(DATA . '/.htaccess', "Require all denied\nDeny from all\n");
    if (empty($GLOBALS['CFG']['debug'])) { ini_set('display_errors', '0'); }
    ini_set('log_errors', '1'); ini_set('error_log', DATA . '/php-error.log');
    db_migrate();
    if ($session) session_boot();
    ext_load_all();
    channels_boot();
    do_action('init');
}
