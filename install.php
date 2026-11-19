<?php
// نصبِ اولیه روی cPanel: اطلاعاتِ پایگاه‌داده ← config.local.php ← ساختِ جدول‌ها ← مدیرِ ارشد. پس از نصب قفل می‌شود.
declare(strict_types=1);
require __DIR__ . '/inc/boot.php';
$err = ''; $done = false;
if (app_installed()) { header('Location: index.php'); exit; }
if (version_compare(PHP_VERSION, '8.0', '<')) $err = 'PHP 8 یا بالاتر لازم است (فعلی: ' . PHP_VERSION . ')';
foreach (['pdo_mysql', 'mbstring', 'json', 'openssl', 'curl', 'zip'] as $ext) if (!extension_loaded($ext)) $err .= ' افزونهٔ ' . $ext . ' فعال نیست.';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {
    try {
        $cfg = ['db' => ['host' => trim($_POST['host'] ?: 'localhost'), 'port' => trim($_POST['port'] ?? ''), 'name' => trim($_POST['name']), 'user' => trim($_POST['user']), 'pass' => (string)$_POST['pass']],
            'base_url' => rtrim(trim((string)$_POST['base_url']), '/'), 'github_token' => trim((string)($_POST['token'] ?? '')), 'update_repo' => 'md6410/8cell', 'update_branch' => 'main', 'debug' => false];
        $GLOBALS['CFG'] = $cfg; db(); db_migrate();
        $un = trim(en_num((string)$_POST['admin_user'])); $pw = (string)$_POST['admin_pass'];
        if (!preg_match('/^[A-Za-z0-9_.\-]{3,60}$/', $un) || mb_strlen($pw) < 8) throw new UserError('نامِ کاربریِ لاتین و رمزِ دست‌کم ۸ نویسه لازم است');
        $mob = norm_mobile($_POST['admin_mobile'] ?? '');
        if (!val("SELECT id FROM users WHERE username=?", [$un])) ins('users', ['username' => $un, 'name' => trim((string)$_POST['admin_name']) ?: 'مدیر', 'mobile' => mobile_ok($mob) ? $mob : null, 'pass_hash' => password_hash($pw, PASSWORD_DEFAULT), 'is_super' => 1, 'created_at' => now()]);
        if (trim((string)$_POST['biz_name']) !== '') settings_save(['biz_name' => trim((string)$_POST['biz_name'])], false);
        settings_save(['base_url' => $cfg['base_url'], 'cron_key' => bin2hex(random_bytes(12))], false);
        $php = "<?php\n// ساخته‌شده با install.php — به‌روزرسانی هرگز این فایل را لمس نمی‌کند\nreturn " . var_export($cfg, true) . ";\n";
        if (@file_put_contents(ROOT . '/config.local.php', $php) === false) throw new UserError('نوشتنِ config.local.php ممکن نشد؛ مجوزِ پوشه را بررسی کنید');
        @chmod(ROOT . '/config.local.php', 0640);
        $done = true;
    } catch (Throwable $e) { $err = $e instanceof UserError ? $e->getMessage() : 'خطا: ' . $e->getMessage(); }
}
$guess = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>نصبِ 8CELL</title><link rel="stylesheet" href="assets/app.css"></head>
<body class="pub"><main class="pubbox"><h1>نصبِ 8CELL</h1>
<?php if ($done): ?><p class="ok">نصب انجام شد. <a class="btn primary" href="index.php">ورود</a></p>
<p class="muted">برای به‌روزرسانیِ زمان‌بندی‌شده در cPanel یک Cron بسازید (هر ۱۰ دقیقه):<br><code dir="ltr">curl -s "<?= h($guess) ?>/index.php?p=cron&k=<?= h(setting('cron_key')) ?>"</code></p>
<?php else: ?><?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
<form method="post" class="form"><div class="grid">
<label class="field"><span>میزبانِ پایگاه‌داده</span><input name="host" value="localhost" dir="ltr"></label><label class="field"><span>پورت (اختیاری)</span><input name="port" dir="ltr"></label>
<label class="field"><span>نامِ پایگاه‌داده *</span><input name="name" required dir="ltr" placeholder="cpuser_8cell"></label><label class="field"><span>کاربرِ پایگاه‌داده *</span><input name="user" required dir="ltr"></label>
<label class="field"><span>رمزِ پایگاه‌داده</span><input name="pass" type="password" dir="ltr"></label><label class="field"><span>نشانیِ سایت</span><input name="base_url" value="<?= h($guess) ?>" dir="ltr"></label>
<label class="field"><span>توکنِ گیت‌هاب (برای مخزنِ خصوصی؛ اختیاری)</span><input name="token" dir="ltr"></label><label class="field"><span>نامِ کسب‌وکار</span><input name="biz_name"></label>
<label class="field"><span>نامِ مدیر</span><input name="admin_name" value="مدیر"></label><label class="field"><span>نامِ کاربریِ مدیر *</span><input name="admin_user" required dir="ltr" value="admin"></label>
<label class="field"><span>رمزِ مدیر (۸+) *</span><input name="admin_pass" type="password" required></label><label class="field"><span>موبایلِ مدیر</span><input name="admin_mobile" dir="ltr"></label>
</div><div class="actions"><button class="btn primary">نصب</button></div></form><?php endif; ?></main></body></html>
