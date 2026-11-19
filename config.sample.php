<?php
// این فایل را به config.local.php کپی کنید (یا install.php را اجرا کنید). config.local.php هرگز با به‌روزرسانی بازنویسی نمی‌شود.
return [
    'db' => ['host' => 'localhost', 'port' => '', 'name' => 'cpuser_8cell', 'user' => 'cpuser_8cell', 'pass' => '******'],
    'base_url' => 'https://example.ir/8cell',   // نشانیِ کاملِ سایت (برای لینک‌ها، passkey و بررسیِ سلامتِ به‌روزرسانی)
    'github_token' => '',                         // برای مخزنِ خصوصی: توکنِ فقط‌خواندنی (fine-grained, Contents: read)
    'update_repo' => 'md6410/8cell',
    'update_branch' => 'main',
    'debug' => false,
];
