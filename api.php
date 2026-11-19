<?php
// نقطهٔ ورودِ API: api.php?a=<action>. تغییر فقط با POST و CSRF؛ هر عمل یک cap دارد و سرور مرجع است.
declare(strict_types=1);
require __DIR__ . '/inc/boot.php';
try {
    if (!app_installed()) { json_out(['ok' => false, 'error' => 'نصب نشده است', 'csrf' => ''], 503); exit; }
    app_boot();
    $a = (string)($_GET['a'] ?? '');
    $routes = api_routes();
    if (!isset($routes[$a])) throw new UserError('عملیاتِ ناشناخته', 404);
    [$cap, $fn, $method] = $routes[$a];
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST' && $m !== 'POST') throw new UserError('این عملیات فقط با POST انجام می‌شود', 405);
    $in = $_GET;
    if ($m === 'POST') {
        $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_contains($ct, 'application/json')) { $j = json_decode((string)file_get_contents('php://input'), true); $in = array_merge($in, is_array($j) ? $j : []); }
        else $in = array_merge($in, $_POST);
        $noCsrf = str_starts_with($a, 'agent_') || str_starts_with($a, 'channel_') && in_array($a, ['channel_order', 'channel_catalog', 'channel_status'], true);
        if (!$noCsrf) csrf_check();
    }
    unset($in['a']);
    if ($cap === 'login') require_login(); elseif ($cap !== 'public') require_cap($cap);
    $data = $fn($in);
    if (is_array($data) && array_key_exists('csrf', $data) && $a === 'me') { json_out($data + ['ok' => true]); exit; }
    json_out(['ok' => true, 'data' => $data]);
} catch (UserError $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], $e->http);
} catch (Throwable $e) {
    error_log('api ' . ($_GET['a'] ?? '') . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    json_out(['ok' => false, 'error' => !empty($GLOBALS['CFG']['debug']) ? $e->getMessage() : 'خطای داخلی؛ جزئیات در لاگِ سرور ثبت شد'], 500);
}
