<?php
// موتورِ ماژول و قالب: کشف، نصب از زیپ، روشن/خاموش، تکه‌های قالب
declare(strict_types=1);

function ext_dirs(string $kind): array { // kind: modules|themes
    return ['core' => ROOT . '/' . $kind, 'site' => DATA . '/ext/' . $kind];
}
/** همهٔ افزونه‌های شناخته‌شده (ماژول یا قالب) */
function ext_all(string $kind = 'modules'): array {
    $out = [];
    $file = $kind === 'themes' ? 'theme.json' : 'module.json';
    foreach (ext_dirs($kind) as $src => $dir) {
        if (!is_dir($dir)) continue;
        foreach (scandir($dir) as $id) {
            if ($id[0] === '.' || !is_file("$dir/$id/$file")) continue;
            $m = ext_read("$dir/$id", $file, $src);
            if ($m) $out[$m['id']] = $m; // نسخهٔ سایت بر نسخهٔ هسته مقدم است
        }
    }
    ksort($out);
    return $out;
}
function ext_read(string $dir, string $file, string $source): ?array {
    $j = json_decode((string)@file_get_contents("$dir/$file"), true);
    if (!is_array($j) || empty($j['id']) || !preg_match('/^[a-z0-9_\-]{2,40}$/', $j['id'])) return null;
    return $j + ['name' => $j['id'], 'version' => '0', 'description' => '', 'main' => 'main.php', 'dir' => $dir, 'source' => $source, 'requires' => []];
}
function ext_enabled(): array { return setting_json('ext_on', []); }
function ext_is_on(string $id): bool { $on = ext_enabled(); if (array_key_exists($id, $on)) return (bool)$on[$id]; $m = ext_all()[$id] ?? null; return (bool)($m['default_on'] ?? false); }
function ext_set(string $id, bool $on): void {
    $all = ext_all(); if (!isset($all[$id])) throw new UserError('ماژول یافت نشد');
    if ($on) foreach ((array)$all[$id]['requires'] as $req) if (!ext_is_on($req)) throw new UserError('این ماژول به «' . $req . '» نیاز دارد؛ اول آن را روشن کنید');
    $e = ext_enabled(); $e[$id] = $on ? 1 : 0; settings_save(['ext_on' => $e]);
    if ($on) { ext_load_one($all[$id]); do_action('ext_activated_' . $id); }
}
/** بارگذاریِ ماژول‌های روشن (در boot) */
function ext_load_all(): void {
    foreach (ext_all() as $id => $m) if (ext_is_on($id)) ext_load_one($m);
}
function ext_load_one(array $m): void {
    static $loaded = [];
    if (isset($loaded[$m['id']])) return;
    $f = $m['dir'] . '/' . basename($m['main']);
    if (!is_file($f)) return;
    $loaded[$m['id']] = 1;
    try { (static function () use ($f, $m) { $MODULE = $m; require $f; })(); }
    catch (Throwable $e) { error_log('module ' . $m['id'] . ': ' . $e->getMessage()); }
}

/** امنیتِ نصب (همان Weby ext-install.php): مسیرِ نسبیِ بی‌خطر — نه مطلق، نه «..»، نه بک‌اسلش و بایتِ تهی (zip-slip)، نه پروندهٔ پنهان */
function ext_safe_rel(string $p): ?string {
    if ($p === '' || strlen($p) > 200 || str_contains($p, "\0") || str_contains($p, '\\') || $p[0] === '/' || preg_match('#^[A-Za-z]:#', $p)) return null;
    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..' || $seg[0] === '.') return null;
        if (!preg_match('/^[A-Za-z0-9_\-. ()\x{0600}-\x{06FF}\x{200C}]+$/u', $seg)) return null;
    }
    return $p;
}
function ext_allowed_ext(): array { return ['php', 'json', 'css', 'js', 'map', 'md', 'txt', 'html', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'avif', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm']; }
function ext_danger_ext(string $p): bool { return in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), ['phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps', 'cgi', 'pl', 'py', 'sh', 'exe', 'asp', 'aspx', 'jsp', 'htaccess', 'ini', 'shtml'], true); }

/** نصب از زیپ در data/ext/<kind>/<id> */
function ext_install_zip(string $zipPath, string $kind = 'modules'): array {
    if (!class_exists('ZipArchive')) throw new UserError('افزونهٔ zip روی سرور فعال نیست');
    $z = new ZipArchive(); if ($z->open($zipPath) !== true) throw new UserError('فایلِ زیپ خوانده نشد');
    $file = $kind === 'themes' ? 'theme.json' : 'module.json';
    $prefix = null; $manifest = null;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i);
        if (basename($n) === $file && substr_count(trim($n, '/'), '/') <= 1) { $prefix = dirname($n) === '.' ? '' : dirname($n) . '/'; $manifest = json_decode((string)$z->getFromIndex($i), true); break; }
    }
    if (!$manifest || empty($manifest['id']) || !preg_match('/^[a-z0-9_\-]{2,40}$/', $manifest['id'])) throw new UserError("$file معتبر در زیپ نیست");
    $dest = DATA . "/ext/$kind/" . $manifest['id'];
    $files = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i);
        if ($prefix && !str_starts_with($n, $prefix)) continue;
        $rel = ext_safe_rel(substr($n, strlen((string)$prefix)));
        if ($rel === null || str_ends_with($n, '/')) continue;
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (ext_danger_ext($rel) || !in_array($ext, ext_allowed_ext(), true)) throw new UserError('فایلِ غیرمجاز در زیپ: ' . $rel);
        $files[$rel] = $z->getFromIndex($i);
    }
    $z->close();
    foreach ($files as $rel => $c) if (str_ends_with($rel, '.php')) { try { token_get_all($c, TOKEN_PARSE); } catch (ParseError $e) { throw new UserError("خطای نحوی در $rel: " . $e->getMessage()); } }
    if (is_dir($dest)) ext_rrmdir($dest);
    foreach ($files as $rel => $c) { $p = "$dest/$rel"; if (!is_dir(dirname($p))) mkdir(dirname($p), 0755, true); file_put_contents($p, $c); }
    audit('ext', $manifest['id'], 'install', null, ['kind' => $kind, 'version' => $manifest['version'] ?? ''], 'نصبِ افزونه');
    return $manifest;
}
function ext_rrmdir(string $d): void { if (!is_dir($d) || is_link($d)) { if (is_file($d) || is_link($d)) @unlink($d); return; } foreach (scandir($d) ?: [] as $f) { if ($f === '.' || $f === '..') continue; ext_rrmdir("$d/$f"); } @rmdir($d); }

/** امکاناتِ داخلیِ هسته (هر امکانِ تازه = یک ردیف با پیش‌فرضِ رفتارِ امروز) */
function features_builtin(): array {
    return [
        'pos_camera_scan' => ['اسکن با دوربین در صندوق', 'فروش', true],
        'pos_partner_price' => ['قیمتِ همکار در صندوق', 'فروش', true],
        'sms_receipt' => ['پیامکِ رسیدِ فروش به مشتری', 'پیامک', false],
        'passkey_login' => ['ورود با Passkey', 'ورود', true],
        'otp_login' => ['ورود با کدِ پیامکی', 'ورود', true],
        'channels_api' => ['API کانال‌های فروش', 'کانال‌ها', true],
        'price_guard' => ['نگهبانِ نوسانِ قیمت', 'کالا', true],
        'warranty' => ['ضمانتِ فروشگاه (دانه‌ای)', 'کالا', true],
    ];
}
function feature_on(string $k): bool { $f = features_builtin()[$k] ?? null; $v = setting('feature_' . $k); return $v === null ? (bool)($f[2] ?? false) : $v === '1'; }

// ── قالب
function theme_active(): ?array { $id = setting('theme', ''); if (!$id) return null; return ext_all('themes')[$id] ?? null; }
/** زنجیرهٔ تکه: قالبِ فعال ← هسته */
function theme_part_path(string $part): string {
    $part = preg_replace('/[^a-z0-9_\-]/', '', $part);
    $t = theme_active();
    $p = ($t && is_file($t['dir'] . "/parts/$part.php")) ? $t['dir'] . "/parts/$part.php" : ROOT . "/parts/$part.php";
    return apply_filters('theme_part', $p, $part);
}
function theme_part(string $part, array $vars = []): void { $f = theme_part_path($part); if (is_file($f)) { extract($vars); include $f; } }
function theme_mods(): array { return setting_json('theme_mods', []) + ['primary' => setting('brand_color', '#0f766e'), 'radius' => '10', 'font_size' => '14']; }
function theme_mods_save(array $in): void {
    $m = [];
    if (isset($in['primary']) && preg_match('/^#[0-9a-f]{6}$/i', $in['primary'])) $m['primary'] = $in['primary'];
    if (isset($in['radius'])) $m['radius'] = (string)max(0, min(24, to_int($in['radius'])));
    if (isset($in['font_size'])) $m['font_size'] = (string)max(12, min(18, to_int($in['font_size'])));
    settings_save(['theme_mods' => $m]);
}
function theme_mods_reset(): void { settings_save(['theme_mods' => '']); }
