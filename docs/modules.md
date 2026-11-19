# ساختِ ماژول (و جای ماژولِ فروشگاهِ آنلاین)

هسته هیچ ماژولی را نمی‌شناسد؛ ماژول‌ها با **قلاب** روی آن می‌نشینند (مثلِ وردپرس).

## جای ماژول
- `modules/<id>/` — همراهِ هسته (با به‌روزرسانی می‌آید؛ در `deploy-files.txt` بیاید).
- `data/ext/modules/<id>/` — نصب‌شده در همان سایت از «ماژول‌ها و قالب ← نصب از زیپ» (به‌روزرسانی دست نمی‌زند). نسخهٔ سایت بر نسخهٔ هسته مقدم است.

هر ماژول: `module.json` (`id`, `name`, `version`, `description`, `main`, `default_on`, `requires`) و فایلِ `main.php`. نصب از زیپ فقط پسوندهای امن و مسیرهای نسبیِ امن را می‌پذیرد و نحوِ PHP را پیش از نوشتن بررسی می‌کند.

## قلاب‌ها
فهرستِ کامل در `inc/hooks.php` ← `hooks_catalog()` و در صفحهٔ «ماژول‌ها و قالب». مهم‌ترین‌ها:

| قلاب | کاربرد |
|---|---|
| `pages`، `page_file` | صفحهٔ تازه با cap |
| `api_routes` | عملیاتِ API تازه با cap |
| `caps` | توانایی‌های تازه برای نقش‌ها |
| `dashboard_cards`، `report_datasets` | داشبورد و گزارش‌ساز |
| `channel_register` | ثبتِ ماژول به‌عنوانِ «کانالِ فروش» با `channel_register_module([...])` |
| `sale_committed`، `sale_status_changed`، `sale_returned`، `channel_order_committed` | گوش‌دادن به رویدادهای فروش |
| `stock_changed`، `price_changed`، `channel_push` | همگام‌سازیِ ویترین |
| `payment_methods`، `sms_tpl_catalog`، `sms_params`، `scan_lookup`، `label_values`، `ledger_post` | گسترشِ هسته |

نمونهٔ کامل: `modules/hello/`.

## فروشگاهِ آنلاین (فازِ بعد) — آنچه از همین حالا آماده است
1. **کانالِ فروش:** ماژول در `channel_register` خودش را با `slug=shop` ثبت می‌کند ← یک کاربرِ «فروشنده» به نامِ کانال، کلید/رمزِ امضا، انبارِ تأمین، کارمزد و حاشیهٔ امنِ موجودی.
2. **کاتالوگ:** `channel_catalog($channel, $since)` (یا API امضاشده) قیمت و موجودیِ قابلِ عرضه را می‌دهد (موجودی − رزرو − حاشیهٔ امن). تغییرات از `catalog_changes` با `since`.
3. **سفارش:** سفارشِ پرداخت‌شده با `channel_order_commit($channel, $order)` (یا `POST api.php?a=channel_order`) — همان `sale_commit` صندوق: تراکنشِ اتمی، ضدِ تکرار با `ext_id`، رزرو تا خروج، سند در دفترِ کل، طلب در حسابِ کانال، کارمزد.
4. **ارسال:** سفارش وارد «خروجِ سفارش‌ها» می‌شود (اسکنِ دانه ← بستن ← سندِ بهای تمام‌شده و پورسانت). قلابِ `sale_status_changed` وضعیت را به سایت برمی‌گرداند.
5. **مرجوعی:** با `return_commit` در هسته؛ حسابِ کانال خودکار اصلاح می‌شود.
6. **پرداختِ آنلاین، ارسال، نقشه، ترب:** داخلِ خودِ ماژول (`modules/shop`، `modules/pay-*`، …) با قلابِ `payment_methods` و جدول‌های خودشان (مهاجرت در `init` با `CREATE TABLE IF NOT EXISTS`).
