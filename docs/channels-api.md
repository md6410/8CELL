# API کانال‌های فروش (قراردادِ اتصالِ ماژول‌ها و بازارگاه‌ها)

هر درخواست امضا می‌شود؛ نشست و CSRF لازم نیست.

| سربرگ | مقدار |
|---|---|
| `X-Channel-Key` | کلیدِ کانال (صفحهٔ «کانال‌های فروش») |
| `X-Timestamp` | زمانِ یونیکس (ثانیه)؛ اختلاف بیش از ۵ دقیقه = رد |
| `X-Signature` | `hex(HMAC-SHA256(secret, ts + "\n" + METHOD + "\n" + action + "\n" + body))` |

امضای نادرست ← `401`. قیمتِ کهنه یا موجودیِ ناکافی ← `409`.

## کاتالوگ
`GET api.php?a=channel_catalog[&since=2026-11-19 10:00:00]` (body خالی در امضا)

پاسخ: `{"ok":true,"data":{"channel":"shop","at":"…","full":true,"items":[{"product_id":1,"code":"…","name":"…","price":1500000,"stock":5,"variants":[…]}]}}`

## ثبتِ سفارش
`POST api.php?a=channel_order` با بدنهٔ JSON:
```json
{"ext_id":"WEB-55","items":[{"product_id":1,"variant_id":null,"qty":2,"price":1500000}],
 "customer":{"mobile":"09121234567","name":"…","address":"…"},
 "paid":3300000,"fee":0,"ship_to":{"name":"…","mobile":"…","address":"…"}}
```
- `paid` باید دقیقاً برابرِ جمعِ سفارش (با مالیات) باشد.
- تکرارِ همان `ext_id` همان فروش را برمی‌گرداند (`duplicate: true`).
- پاسخ: `{"sale_id":12,"number":"C1405-00012","duplicate":false}`

## وضعیت
`GET api.php?a=channel_status&ext_id=WEB-55` ← `fulfil`: `pending|picking|shipped`، `returned`.

## نمونهٔ PHP
```php
$ts = (string)time(); $body = json_encode($order, JSON_UNESCAPED_UNICODE);
$sig = hash_hmac('sha256', "$ts\nPOST\nchannel_order\n$body", $secret);
// curl با سربرگ‌های X-Channel-Key, X-Timestamp, X-Signature و Content-Type: application/json
```
