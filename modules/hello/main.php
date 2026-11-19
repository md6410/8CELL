<?php
// ماژولِ نمونه — الگوی ماژولِ «فروشگاهِ آنلاین» (modules/shop) در فازِ بعد.
// هسته این ماژول را نمی‌شناسد؛ فقط از قلاب‌های hooks_catalog() استفاده می‌شود.
declare(strict_types=1);

// ۱) صفحهٔ تازه با cap
add_filter('pages', function (array $p) { $p['hello'] = ['ماژولِ نمونه', 'channels.manage', 'تنظیمات', 1]; return $p; });
add_filter('page_file', fn($f, $p) => $p === 'hello' ? __DIR__ . '/page.php' : $f, 10);
// ۲) API تازه (cap دارد؛ سرور مرجع است)
add_filter('api_routes', function (array $r) { $r['hello_ping'] = ['channels.manage', fn($in) => ['pong' => true, 'at' => now()], 'POST']; return $r; });
// ۳) کارتِ داشبورد
add_filter('dashboard_cards', function (array $c) { $c[] = ['k' => 'hello', 't' => 'نمونه: سفارش‌های کانال‌ها (امروز)', 'v' => (int)val("SELECT COUNT(*) FROM channel_orders WHERE created_at>=?", [today()]), 'n' => 1]; return $c; });
// ۴) ثبت به‌عنوانِ کانالِ فروش (فروشگاهِ آنلاین دقیقاً همین را می‌کند)
add_action('channel_register', function () { try { channel_register_module(['slug' => 'hello', 'name' => 'کانالِ نمونه', 'kind' => 'other', 'module' => 'hello']); } catch (Throwable $e) { error_log($e->getMessage()); } });
// ۵) شنیدنِ رویدادهای هسته
add_action('sale_status_changed', function (int $saleId, string $status) { /* ماژولِ فروشگاه این‌جا وضعیتِ سفارشِ سایت را به‌روز می‌کند */ });
