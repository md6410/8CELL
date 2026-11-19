# 8CELL — یادداشت برای مشارکت‌کنندگان (و Claude)

- PHP 8 + MySQL/MariaDB روی cPanel؛ بی‌فریم‌ورک. ورودی‌ها: `index.php` (صفحه‌ها `pages/*.php`)، `api.php` (مسیرها در `inc/routes.php`)، `v.php` (اصالت/لینکِ رمزدار)، `install.php`.
- هر تغییرِ منتشرشونده: ۱) نسخه در `inc/version.php`؛ ۲) هر فایلِ تازهٔ قابلِ نصب در `deploy-files.txt` (هرگز `data/` یا `*.local.php`).
- push به `main` = انتشار؛ سایت‌ها خودکار نصب می‌کنند (`inc/update.php`). `index.php?health=1` (چاپِ `ok`) و `api.php?a=me` (JSON با `"csrf"`) باید کار کنند وگرنه به‌روزرسانی برمی‌گردد.
- هر صفحه و هر API یک cap دارد (`pages_all()`/`api_routes()`)؛ تغییر فقط با POST و CSRF. آزمونِ `X-CAPS` پوشش را می‌سنجد.
- هر فروش فقط از `sale_commit()`؛ هر رویدادِ مالی دقیقاً یک سند با `ledger_post()` (یکتا روی `source_type+source_id`). مانده‌ها همیشه جمعِ ردیف‌ها. پول = ریالِ صحیح (BIGINT).
- هسته ماژول‌ها را نمی‌شناسد؛ قلابِ تازه را در `hooks_catalog()` بنویسید. مهاجرتِ تازه = عنصرِ تازه در انتهای `pm_schema()`.
- JS بدونِ `?.`. پیش از push: `php -l` همهٔ فایل‌ها، `node --check assets/*.js`، و `php tests/run.php` روی پایگاه‌دادهٔ آزمون.
