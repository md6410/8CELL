<?php
// قلاب‌ها (مثلِ وردپرس): هسته فقط نقطه اعلام می‌کند؛ ماژول‌ها وصل می‌شوند.
declare(strict_types=1);

$GLOBALS['__hooks'] = []; $GLOBALS['__did'] = []; $GLOBALS['__cur_hook'] = [];

function add_action(string $hook, callable $fn, int $prio = 10): void { $GLOBALS['__hooks'][$hook][$prio][] = $fn; }
function add_filter(string $hook, callable $fn, int $prio = 10): void { add_action($hook, $fn, $prio); }
function remove_all_hooks(string $hook): void { unset($GLOBALS['__hooks'][$hook]); }
function has_hook(string $hook): bool { return !empty($GLOBALS['__hooks'][$hook]); }
function do_action(string $hook, ...$args): void {
    $GLOBALS['__did'][$hook] = ($GLOBALS['__did'][$hook] ?? 0) + 1;
    if (empty($GLOBALS['__hooks'][$hook])) return;
    ksort($GLOBALS['__hooks'][$hook]);
    $GLOBALS['__cur_hook'][] = $hook;
    try { foreach ($GLOBALS['__hooks'][$hook] as $fns) foreach ($fns as $fn) $fn(...$args); }
    finally { array_pop($GLOBALS['__cur_hook']); }
}
function apply_filters(string $hook, $value, ...$args) {
    $GLOBALS['__did'][$hook] = ($GLOBALS['__did'][$hook] ?? 0) + 1;
    if (empty($GLOBALS['__hooks'][$hook])) return $value;
    ksort($GLOBALS['__hooks'][$hook]);
    $GLOBALS['__cur_hook'][] = $hook;
    try { foreach ($GLOBALS['__hooks'][$hook] as $fns) foreach ($fns as $fn) $value = $fn($value, ...$args); }
    finally { array_pop($GLOBALS['__cur_hook']); }
    return $value;
}
function did_action(string $hook): int { return $GLOBALS['__did'][$hook] ?? 0; }
function current_hook(): ?string { $c = $GLOBALS['__cur_hook']; return $c ? end($c) : null; }

/** فهرستِ رسمیِ قلاب‌های هسته — هر قلابِ تازه باید این‌جا نوشته شود */
function hooks_catalog(): array {
    return [
        'init' => ['action', 'پس از بارگذاریِ هسته و ماژول‌ها'],
        'admin_menu' => ['filter', 'فهرستِ منو: [key => [title, cap, group, icon]]'],
        'pages' => ['filter', 'صفحه‌ها: [key => [title, cap, file]] — ماژول صفحهٔ خودش را اضافه می‌کند'],
        'api_routes' => ['filter', 'مسیرهای API: [action => [cap|null, callable, method]]'],
        'caps' => ['filter', 'فهرستِ توانایی‌ها (cap) برای نقش‌ها'],
        'head' => ['action', 'داخلِ <head> صفحه‌های پنل'],
        'footer' => ['action', 'پیش از </body>'],
        'dashboard_cards' => ['filter', 'کارت‌های داشبورد'],
        'sale_before_commit' => ['action', 'داخلِ تراکنشِ ثبتِ فروش، پیش از نهایی‌شدن ($ctx) — خطا = لغوِ کل'],
        'sale_committed' => ['action', 'پس از ثبتِ قطعیِ فروش ($saleId)'],
        'sale_returned' => ['action', 'پس از ثبتِ برگشت ($returnId, $saleId)'],
        'stock_changed' => ['action', 'پس از تغییرِ موجودی ($productId, $variantId)'],
        'price_changed' => ['action', 'پس از تغییرِ قیمتِ کالا ($productId, $old, $new)'],
        'channel_register' => ['action', 'زمانِ ثبتِ کانال‌ها — ماژول با channel_register_module() خودش را ثبت می‌کند'],
        'channel_push' => ['action', 'تغییرِ کاتالوگِ کانال ($channelId, $productIds)'],
        'channel_order_committed' => ['action', 'سفارشِ کانال فروش شد ($channelId, $extId, $saleId)'],
        'sale_status_changed' => ['action', 'وضعیتِ فروش/ارسال عوض شد ($saleId, $status) — برای بازخورد به کانال'],
        'ledger_post' => ['filter', 'آرتیکل‌های سند پیش از ثبت ($lines, $event)'],
        'sms_params' => ['filter', 'پارامترهای قالبِ پیامک ($params, $tplKey, $ctx)'],
        'scan_lookup' => ['filter', 'شناساییِ کدِ اسکن‌شده: ماژول نوعِ تازه اضافه می‌کند ($result, $code)'],
        'label_values' => ['filter', 'مقدارهای برچسب ($vals, $kind, $data)'],
        'report_datasets' => ['filter', 'مجموعه‌داده‌های گزارش‌ساز'],
        'theme_part' => ['filter', 'مسیرِ تکهٔ قالب ($path, $part)'],
        'payment_methods' => ['filter', 'روش‌های پرداختِ صندوق'],
    ];
}
