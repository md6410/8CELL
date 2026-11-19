<?php
// به‌روزرسانیِ خودکار از گیت‌هاب: بررسی ← قفل ← دانلود ← اعتبارسنجی ← پشتیبان ← نصب ← سلامت ← بازگشت
declare(strict_types=1);

function df_upd_state(): array { $f = DATA . '/update.json'; return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : []; }
function df_upd_state_save(array $s): void { @file_put_contents(DATA . '/update.json', json_encode($s + df_upd_state(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); }
function df_upd_release(): array { $f = ROOT . '/inc/release.json'; return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : []; }
function df_upd_headers(): array {
    $h = ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];
    $t = (string)($GLOBALS['CFG']['github_token'] ?? setting('update_token', ''));
    if ($t !== '') $h['Authorization'] = 'Bearer ' . $t;
    return $h;
}
function df_upd_repo(): string { return (string)($GLOBALS['CFG']['update_repo'] ?? setting('update_repo', 'md6410/8cell')); }
function df_upd_branch(): string { return (string)($GLOBALS['CFG']['update_branch'] ?? setting('update_branch', 'main')); }

/** آخرین کامیتِ شاخه (بدونِ نصب) */
function df_upd_latest(): array {
    $r = http_req('GET', 'https://api.github.com/repos/' . df_upd_repo() . '/commits/' . rawurlencode(df_upd_branch()), null, df_upd_headers(), 10);
    if (!$r['ok']) throw new UserError('پاسخِ گیت‌هاب: ' . $r['code'] . ($r['code'] == 404 ? ' (مخزنِ خصوصی؟ توکن لازم است)' : ''));
    $j = json_decode($r['body'], true);
    $sha = (string)($j['sha'] ?? '');
    $ver = df_upd_remote_version($sha);
    $s = ['checked_at' => now(), 'latest_sha' => $sha, 'latest_msg' => mb_cut((string)($j['commit']['message'] ?? ''), 300), 'latest_version' => $ver];
    df_upd_state_save($s);
    return $s;
}
function df_upd_remote_version(string $sha): string {
    $r = http_req('GET', 'https://api.github.com/repos/' . df_upd_repo() . '/contents/inc/version.php?ref=' . $sha, null, array_merge(df_upd_headers(), ['Accept' => 'application/vnd.github.raw']), 10);
    return ($r['ok'] && preg_match("/APP_VERSION',\s*'([^']+)'/", $r['body'], $m)) ? $m[1] : '';
}
function df_upd_safe_rel(string $p): ?string {
    $p = trim(str_replace('\\', '/', $p));
    if ($p === '' || $p[0] === '#' || $p[0] === '/' || str_contains($p, '..') || str_contains($p, ':')) return null;
    if (str_starts_with($p, 'data/') || preg_match('/\.local\.php$/', $p) || $p === 'config.local.php') return null;
    if (!preg_match('#^[A-Za-z0-9_\-./]+$#', $p) || substr_count($p, '/') > 3) return null;
    return $p;
}
/** دانلود و اعتبارسنجی: فقط فایل‌هایِ فهرستِ deploy-files.txt */
function df_upd_fetch(string $sha): array {
    $r = http_req('GET', 'https://api.github.com/repos/' . df_upd_repo() . '/zipball/' . $sha, null, df_upd_headers(), 120);
    if (!$r['ok'] || strlen($r['body']) < 100) throw new UserError('دانلودِ نسخه ناموفق بود (' . $r['code'] . ')');
    if (!class_exists('ZipArchive')) throw new UserError('ZipArchive روی سرور نیست');
    $tmp = DATA . '/upd-' . substr($sha, 0, 12) . '.zip'; file_put_contents($tmp, $r['body']);
    $z = new ZipArchive(); if ($z->open($tmp) !== true) { @unlink($tmp); throw new UserError('زیپِ نسخه خراب است'); }
    $root = explode('/', (string)$z->getNameIndex(0))[0] . '/';
    $list = $z->getFromName($root . 'deploy-files.txt');
    if ($list === false) { $z->close(); @unlink($tmp); throw new UserError('deploy-files.txt در نسخه نیست'); }
    $files = [];
    foreach (preg_split('/\R/', $list) as $line) {
        $rel = df_upd_safe_rel($line); if ($rel === null) continue;
        $c = $z->getFromName($root . $rel);
        if ($c === false) { $z->close(); @unlink($tmp); throw new UserError("فایلِ $rel در فهرست هست ولی در نسخه نیست"); }
        if (str_ends_with($rel, '.php')) { try { token_get_all($c, TOKEN_PARSE); } catch (ParseError $e) { $z->close(); @unlink($tmp); throw new UserError("خطای نحوی در $rel: " . $e->getMessage()); } }
        $files[$rel] = $c;
    }
    $z->close(); @unlink($tmp);
    if (!isset($files['index.php'], $files['api.php'], $files['inc/version.php'])) throw new UserError('نسخه ناقص است (index/api/version)');
    return $files;
}
function df_upd_write(array $files, string $backupDir): void {
    foreach ($files as $rel => $c) {
        $dst = ROOT . '/' . $rel;
        if (is_file($dst)) { $b = "$backupDir/$rel"; if (!is_dir(dirname($b))) mkdir(dirname($b), 0755, true); copy($dst, $b); }
        else { $m = "$backupDir/.new"; file_put_contents($m, $rel . "\n", FILE_APPEND); }
        if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0755, true);
        $t = $dst . '.tmp' . getmypid(); file_put_contents($t, $c); rename($t, $dst);
        if (function_exists('opcache_invalidate')) @opcache_invalidate($dst, true);
    }
}
function df_upd_restore(string $backupDir): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backupDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { $rel = substr($f->getPathname(), strlen($backupDir) + 1); if ($rel === '.new') continue; copy($f->getPathname(), ROOT . '/' . $rel); if (function_exists('opcache_invalidate')) @opcache_invalidate(ROOT . '/' . $rel, true); }
    if (is_file("$backupDir/.new")) foreach (file("$backupDir/.new", FILE_IGNORE_NEW_LINES) as $rel) if ($rel && df_upd_safe_rel($rel)) @unlink(ROOT . '/' . $rel);
}
/** سلامت پس از نصب: index.php?health=1 → ok و api.php?a=me حاویِ "csrf" */
function df_upd_health(): array {
    $base = rtrim((string)($GLOBALS['CFG']['base_url'] ?? setting('base_url', '')) ?: app_url(''), '/');
    if (!preg_match('#^https?://#', $base)) return ['ok' => true, 'msg' => 'نشانیِ سایت معلوم نیست؛ بررسیِ HTTP رد شد'];
    $a = http_req('GET', $base . '/index.php?health=1&_=' . time(), null, [], 15);
    if (trim($a['body']) !== 'ok') return ['ok' => false, 'msg' => 'health: ' . $a['code'] . ' ' . mb_cut(strip_tags($a['body']), 200)];
    $b = http_req('GET', $base . '/api.php?a=me&_=' . time(), null, [], 15);
    if (!str_contains($b['body'], '"csrf"')) return ['ok' => false, 'msg' => 'api me: ' . $b['code']];
    return ['ok' => true, 'msg' => 'سالم'];
}
/** اجرا: force = حتی اگر sha همان باشد؛ install=false فقط بررسی */
function df_upd_run(bool $force = false, bool $install = true, string $by = 'auto'): array {
    $lock = DATA . '/update.lock';
    if (is_file($lock) && time() - filemtime($lock) < 600) return ['ok' => false, 'msg' => 'به‌روزرسانیِ دیگری در جریان است'];
    file_put_contents($lock, (string)time());
    try {
        $latest = df_upd_latest();
        $cur = df_upd_release()['sha'] ?? '';
        if (!$latest['latest_sha']) return ['ok' => false, 'msg' => 'شاخه یافت نشد'];
        if (!$force && $cur === $latest['latest_sha']) return ['ok' => true, 'msg' => 'نسخه به‌روز است', 'sha' => $cur];
        if (!$install) return ['ok' => true, 'msg' => 'نسخهٔ تازه موجود است', 'latest' => $latest];
        $files = df_upd_fetch($latest['latest_sha']);
        $bdir = DATA . '/backups/' . date('Ymd-His') . '-' . substr($cur ?: 'none', 0, 7);
        mkdir($bdir, 0755, true);
        $files['inc/release.json'] = json_encode(['sha' => $latest['latest_sha'], 'version' => $latest['latest_version'], 'installed_at' => now(), 'by' => $by], JSON_UNESCAPED_UNICODE);
        df_upd_write($files, $bdir);
        $h = df_upd_health();
        if (!$h['ok']) { df_upd_restore($bdir); df_upd_state_save(['last_result' => 'rollback', 'last_error' => $h['msg'], 'last_run' => now()]); return ['ok' => false, 'msg' => 'سلامت رد شد و نسخهٔ قبل برگشت: ' . $h['msg']]; }
        df_upd_state_save(['last_result' => 'installed', 'last_error' => '', 'last_run' => now(), 'installed_sha' => $latest['latest_sha']]);
        df_upd_prune_backups();
        return ['ok' => true, 'msg' => 'نسخهٔ ' . $latest['latest_version'] . ' نصب شد', 'files' => count($files)];
    } catch (Throwable $e) {
        df_upd_state_save(['last_result' => 'error', 'last_error' => $e->getMessage(), 'last_run' => now()]);
        return ['ok' => false, 'msg' => $e->getMessage()];
    } finally { @unlink($lock); }
}
function df_upd_prune_backups(int $keep = 5): void {
    $d = DATA . '/backups'; if (!is_dir($d)) return;
    $all = array_values(array_filter(scandir($d), fn($x) => $x[0] !== '.')); sort($all);
    while (count($all) > $keep) { $x = array_shift($all); ext_rrmdir("$d/$x"); }
}
/** بررسیِ زمان‌بندی‌شده (با بازدیدِ مدیر یا cron) — پس از ارسالِ پاسخ */
function df_upd_maybe_auto(): void {
    if (setting('update_auto', '1') !== '1') return;
    $s = df_upd_state(); $iv = max(5, (int)setting('update_interval', 10)) * 60;
    if (!empty($s['auto_at']) && time() - strtotime($s['auto_at']) < $iv) return;
    df_upd_state_save(['auto_at' => now()]);
    register_shutdown_function(function () { if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); @set_time_limit(300); df_upd_run(false, true, 'auto'); });
}
