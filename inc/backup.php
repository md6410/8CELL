<?php
// پشتیبانِ کامل و ابزارِ داده — روالِ Weby (includes/backup.php، data-tools.php):
//  - تکه‌تکه: هر «گام» چند ثانیه کار می‌کند و وضعیت در پرونده می‌ماند (هاستِ اشتراکی وقتِ کوتاه می‌دهد)؛ از مرورگر یا cron ادامه می‌یابد.
//  - قالب: یک ‎.tar‎ با manifest.json، database.sql.gz (هر دستور یک خط) و files/<مسیر> (پوشهٔ data/uploads).
//  - پیش از هر بازگرداندن، از پایگاه‌دادهٔ فعلی پشتیبان گرفته می‌شود؛ config.local.php هرگز رونویسی نمی‌شود.
//  - ساختن/دانلود/بازگرداندن فقط با توانایی backup.manage؛ بازگرداندن و دانلود با تکرارِ رمزِ همان کاربر.
declare(strict_types=1);

function bk_types(): array { return ['db' => 'پایگاه‌داده', 'files' => 'پرونده‌های بارگذاری‌شده (فیش، عکس…)', 'all' => 'همه (پایگاه‌داده + پرونده‌ها)']; }
function bk_dir(): string { $d = DATA . '/backups-full'; if (!is_dir($d)) { @mkdir($d, 0750, true); @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n"); @file_put_contents($d . '/index.html', ''); } return $d; }
function bk_safe(string $n): string { $n = basename($n); if (!preg_match('/^8cell-[a-z]+-\d{8}-\d{6}(-[a-z0-9]+)?\.tar$/', $n)) throw new UserError('نامِ پشتیبان نامعتبر است'); return $n; }
function bk_job_file(): string { return bk_dir() . '/.job.json'; }
function bk_job(): ?array { $f = bk_job_file(); return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: null) : null; }
function bk_job_save(?array $j): void { if ($j === null) { @unlink(bk_job_file()); return; } file_put_contents(bk_job_file(), json_encode($j, JSON_UNESCAPED_UNICODE), LOCK_EX); }

// ── tar (ustar)
function bk_tar_header(string $name, int $size, int $mtime): string {
    if (strlen($name) > 99) { $pre = substr($name, 0, strrpos(substr($name, 0, 155), '/') ?: 0); $name = substr($name, strlen($pre) + 1); } else $pre = '';
    $h = str_pad($name, 100, "\0") . str_pad(sprintf('%07o', 0644), 8, "\0") . str_pad(sprintf('%07o', 0), 8, "\0") . str_pad(sprintf('%07o', 0), 8, "\0")
        . str_pad(sprintf('%011o', $size), 12, "\0") . str_pad(sprintf('%011o', $mtime), 12, "\0") . '        ' . '0' . str_repeat("\0", 100) . "ustar\0" . '00' . str_repeat("\0", 32) . str_repeat("\0", 32)
        . str_repeat("\0", 8) . str_repeat("\0", 8) . str_pad($pre, 155, "\0") . str_repeat("\0", 12);
    $sum = 0; for ($i = 0; $i < 512; $i++) $sum += ord($h[$i]);
    return substr_replace($h, str_pad(sprintf('%06o', $sum), 6, '0', STR_PAD_LEFT) . "\0 ", 148, 8);
}
function bk_tar_add($fh, string $name, ?string $src, string $data = ''): void {
    $size = $src !== null ? (int)filesize($src) : strlen($data);
    fwrite($fh, bk_tar_header($name, $size, $src !== null ? (int)filemtime($src) : time()));
    if ($src !== null) { $in = fopen($src, 'rb'); stream_copy_to_stream($in, $fh); fclose($in); } else fwrite($fh, $data);
    if ($size % 512) fwrite($fh, str_repeat("\0", 512 - $size % 512));
}
/** فهرستِ پرونده‌های درونِ tar: name => [offset, size] */
function bk_tar_index(string $path): array {
    $o = []; $fh = fopen($path, 'rb'); $off = 0;
    while (($h = fread($fh, 512)) !== false && strlen($h) === 512 && trim($h, "\0") !== '') {
        $name = rtrim(substr($h, 0, 100), "\0"); $pre = rtrim(substr($h, 345, 155), "\0"); if ($pre !== '') $name = $pre . '/' . $name;
        $size = octdec(trim(substr($h, 124, 12), "\0 ")); $o[$name] = [$off + 512, $size]; $off += 512 + (int)(ceil($size / 512) * 512); fseek($fh, $off);
    }
    fclose($fh); return $o;
}
function bk_tar_extract(string $tar, string $name, string $to): bool {
    $ix = bk_tar_index($tar); if (!isset($ix[$name])) return false; [$off, $size] = $ix[$name];
    $in = fopen($tar, 'rb'); fseek($in, $off); $out = fopen($to, 'wb'); $left = $size;
    while ($left > 0) { $c = fread($in, min(1 << 20, $left)); if ($c === '' || $c === false) break; fwrite($out, $c); $left -= strlen($c); }
    fclose($in); fclose($out); return $left === 0;
}
function bk_manifest(string $path): ?array { $ix = bk_tar_index($path); if (!isset($ix['manifest.json'])) return null; $fh = fopen($path, 'rb'); fseek($fh, $ix['manifest.json'][0]); $j = json_decode((string)fread($fh, $ix['manifest.json'][1]), true); fclose($fh); return $j ?: null; }

// ── ساختن
function bk_sql_val($v): string { return $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string)$v : db()->quote((string)$v)); }
function bk_start(string $type, string $label = 'دستی'): array {
    if (!isset(bk_types()[$type])) throw new UserError('نوعِ پشتیبان نامعتبر است');
    if (($j = bk_job()) && empty($j['done']) && (int)($j['at'] ?? 0) > time() - 900) throw new UserError('یک کارِ پشتیبان/بازگردانی در جریان است');
    $name = '8cell-' . $type . '-' . date('Ymd-His') . '.tar';
    $j = ['op' => 'backup', 'type' => $type, 'label' => $label, 'name' => $name, 'phase' => in_array($type, ['db', 'all'], true) ? 'db' : 'files', 'tables' => array_values(db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)),
        'ti' => 0, 'off' => 0, 'files' => [], 'fi' => -1, 'rows' => 0, 'at' => time(), 'log' => [], 'by' => current_user()['name'] ?? 'cron'];
    $fh = fopen(bk_dir() . '/' . $name . '.part', 'wb'); fclose($fh); @unlink(bk_dir() . '/' . $name . '.sql.gz');
    bk_job_save($j); return $j;
}
/** یک گام؛ تا بودجهٔ زمانی کار می‌کند */
function bk_step(float $budget = 12.0): array {
    $j = bk_job(); if (!$j || !empty($j['done'])) return $j ?: ['done' => true];
    $until = microtime(true) + $budget; @set_time_limit((int)$budget + 30);
    try { $j['op'] === 'backup' ? bk_backup_run($j, $until) : bk_restore_run($j, $until); }
    catch (Throwable $e) { $j['done'] = true; $j['error'] = $e->getMessage(); error_log('backup: ' . $e->getMessage()); }
    $j['at'] = time(); bk_job_save($j); return $j;
}
function bk_backup_run(array &$j, float $until): void {
    $dir = bk_dir(); $part = $dir . '/' . $j['name'] . '.part'; $sql = $dir . '/' . $j['name'] . '.sql.gz';
    if ($j['phase'] === 'db') {
        $gz = gzopen($sql, 'ab6');
        if ($j['ti'] === 0 && $j['off'] === 0) gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n");
        while ($j['ti'] < count($j['tables']) && microtime(true) < $until) {
            $t = $j['tables'][$j['ti']];
            if ($j['off'] === 0) { $c = db()->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1]; gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n" . str_replace("\n", ' ', $c) . ";\n"); }
            $rs = db()->query("SELECT * FROM `$t` LIMIT 500 OFFSET " . (int)$j['off'])->fetchAll(PDO::FETCH_ASSOC);
            if ($rs) { $cols = '`' . implode('`,`', array_keys($rs[0])) . '`'; $vals = []; foreach ($rs as $r) $vals[] = '(' . implode(',', array_map('bk_sql_val', array_values($r))) . ')';
                gzwrite($gz, "INSERT INTO `$t` ($cols) VALUES " . implode(',', $vals) . ";\n"); $j['rows'] += count($rs); }
            if (count($rs) < 500) { $j['ti']++; $j['off'] = 0; } else $j['off'] += 500;
        }
        gzclose($gz);
        if ($j['ti'] >= count($j['tables'])) { $fh = fopen($part, 'ab'); bk_tar_add($fh, 'database.sql.gz', $sql); fclose($fh); @unlink($sql); $j['phase'] = $j['type'] === 'all' ? 'files' : 'finish'; $j['log'][] = 'پایگاه‌داده: ' . fa_num((string)$j['rows']) . ' ردیف'; }
        if (microtime(true) >= $until) return;
    }
    if ($j['phase'] === 'files') {
        if ($j['fi'] < 0) { $j['files'] = []; $base = DATA . '/uploads'; if (is_dir($base)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $f) if ($f->isFile()) $j['files'][] = substr($f->getPathname(), strlen(DATA) + 1); $j['fi'] = 0; }
        $fh = fopen($part, 'ab');
        while ($j['fi'] < count($j['files']) && microtime(true) < $until) { $rel = $j['files'][$j['fi']]; if (is_file(DATA . '/' . $rel)) bk_tar_add($fh, 'files/' . $rel, DATA . '/' . $rel); $j['fi']++; }
        fclose($fh);
        if ($j['fi'] >= count($j['files'])) { $j['phase'] = 'finish'; $j['log'][] = 'پرونده‌ها: ' . fa_num((string)count($j['files'])); $j['files'] = []; }
    }
    if ($j['phase'] === 'finish') {
        $fh = fopen($part, 'ab'); bk_tar_add($fh, 'manifest.json', null, json_encode(['app' => '8CELL', 'version' => APP_VERSION, 'type' => $j['type'], 'label' => $j['label'], 'at' => date('c'), 'rows' => $j['rows'], 'by' => $j['by'], 'schema' => (int)setting('schema_version', '0')], JSON_UNESCAPED_UNICODE));
        fwrite($fh, str_repeat("\0", 1024)); fclose($fh); rename($part, $dir . '/' . $j['name']); $j['done'] = true; $j['phase'] = 'done'; bk_retention();
        audit('backups', $j['name'], 'create', null, ['type' => $j['type'], 'rows' => $j['rows']], 'پشتیبانِ کامل');
    }
}
function bk_retention(): void { $keep = max(2, (int)setting('backup_keep', '7')); $l = bk_list(); foreach (array_slice($l, $keep) as $b) @unlink(bk_dir() . '/' . $b['name']); }
function bk_list(): array {
    $o = []; foreach (glob(bk_dir() . '/8cell-*.tar') ?: [] as $f) $o[] = ['name' => basename($f), 'size' => (int)filesize($f), 'at' => date('Y-m-d H:i:s', (int)filemtime($f)), 'm' => bk_manifest($f)];
    usort($o, fn($a, $b) => strcmp($b['at'], $a['at'])); return $o;
}
function bk_size(int $b): string { return $b > 1048576 ? fa_num(number_format($b / 1048576, 1)) . ' مگابایت' : fa_num((string)max(1, (int)round($b / 1024))) . ' کیلوبایت'; }
function bk_unlocked(): void { if ((int)($_SESSION['bk_ok'] ?? 0) < time() - 600) throw new UserError('اول رمزِ خود را در همین صفحه تأیید کنید (۱۰ دقیقه اعتبار دارد)', 403); }
function bk_reauth(string $pass): void { $u = require_login(); $h = (string)val("SELECT pass_hash FROM users WHERE id=?", [$u['id']]); if ($h === '' || !password_verify($pass, $h)) throw new UserError('رمزِ شما درست نیست', 403); }

// ── بازگرداندن
function bk_start_restore(string $name, bool $withFiles): array {
    $name = bk_safe($name); $p = bk_dir() . '/' . $name; if (!is_file($p)) throw new UserError('پشتیبان یافت نشد');
    $m = bk_manifest($p); if (!$m || ($m['app'] ?? '') !== '8CELL') throw new UserError('این پرونده پشتیبانِ 8CELL نیست');
    if ((int)($m['schema'] ?? 0) > count(pm_schema())) throw new UserError('این پشتیبان از نسخهٔ تازه‌تری است؛ اول سایت را به‌روز کنید');
    $ix = bk_tar_index($p); $hasDb = isset($ix['database.sql.gz']);
    if ($hasDb) { // پیش از هر بازگرداندن: پشتیبانِ خودکار از وضعِ فعلی (هم‌زمان؛ پایگاه‌دادهٔ فروشگاه کوچک است)
        bk_start('db', 'پیش از بازگرداندنِ ' . $name); do { $j = bk_step(20); } while (empty($j['done'])); if (!empty($j['error'])) throw new UserError('پشتیبانِ پیش از بازگرداندن نشد: ' . $j['error']);
        bk_tar_extract($p, 'database.sql.gz', bk_dir() . '/.restore.sql.gz'); }
    $j = ['op' => 'restore', 'name' => $name, 'phase' => $hasDb ? 'db' : 'files', 'gzoff' => 0, 'stmts' => 0, 'with_files' => $withFiles, 'fi' => 0, 'at' => time(), 'log' => [], 'by' => current_user()['name'] ?? ''];
    bk_job_save($j); return $j;
}
function bk_restore_run(array &$j, float $until): void {
    if ($j['phase'] === 'db') {
        $f = bk_dir() . '/.restore.sql.gz'; $gz = gzopen($f, 'rb'); if ($j['gzoff']) gzseek($gz, (int)$j['gzoff']);
        db()->exec('SET FOREIGN_KEY_CHECKS=0');
        while (microtime(true) < $until && ($line = gzgets($gz, 64 << 20)) !== false) { $line = rtrim($line, "\n"); if ($line !== '') { db()->exec($line); $j['stmts']++; } }
        $eof = gzeof($gz); $j['gzoff'] = gztell($gz); gzclose($gz);
        if ($eof) { @unlink($f); setting('__flush'); $j['phase'] = $j['with_files'] ? 'files' : 'finish'; $j['log'][] = 'پایگاه‌داده بازگشت (' . fa_num((string)$j['stmts']) . ' دستور)'; }
        if (microtime(true) >= $until) return;
    }
    if ($j['phase'] === 'files') {
        $p = bk_dir() . '/' . $j['name']; $names = array_values(array_filter(array_keys(bk_tar_index($p)), fn($n) => str_starts_with($n, 'files/uploads/')));
        while ($j['fi'] < count($names) && microtime(true) < $until) { $rel = substr($names[$j['fi']], 6); if (!str_contains($rel, '..')) { $to = DATA . '/' . $rel; if (!is_dir(dirname($to))) @mkdir(dirname($to), 0775, true); bk_tar_extract($p, $names[$j['fi']], $to); } $j['fi']++; }
        if ($j['fi'] >= count($names)) { $j['phase'] = 'finish'; $j['log'][] = 'پرونده‌ها: ' . fa_num((string)count($names)); }
    }
    if ($j['phase'] === 'finish') { $j['done'] = true; $j['phase'] = 'done'; db_migrate(); try { audit('backups', $j['name'], 'restore', null, ['by' => $j['by']], 'بازگرداندنِ پشتیبان'); } catch (Throwable $e) {} }
}
function bk_accept_upload(array $f): string {
    if (($f['error'] ?? 4) !== 0 || !is_uploaded_file((string)$f['tmp_name'])) throw new UserError('پرونده بارگذاری نشد');
    $tmp = (string)$f['tmp_name']; $m = bk_manifest($tmp); if (!$m || ($m['app'] ?? '') !== '8CELL') throw new UserError('این پرونده پشتیبانِ 8CELL نیست');
    $name = '8cell-' . preg_replace('/[^a-z]/', '', (string)($m['type'] ?? 'db')) . '-' . date('Ymd-His') . '-up.tar'; move_uploaded_file($tmp, bk_dir() . '/' . $name); return $name;
}
/** پشتیبانِ خودکارِ روزانه (cron) */
function backup_cron(): string {
    if (setting('backup_auto', '1') !== '1') return 'off';
    $j = bk_job();
    if ($j && empty($j['done'])) { bk_step(20); return 'step'; }
    $last = bk_list()[0]['at'] ?? ''; if ($last !== '' && strtotime($last) > time() - 86400 + 600) return 'fresh';
    bk_start(setting('backup_auto_type', 'db') === 'all' ? 'all' : 'db', 'خودکار'); bk_step(20); return 'started';
}

// ── ابزارِ داده: برون‌بری و درون‌ریزیِ CSV (بی رمز و دادهٔ حساس)
function ds_sets(): array {
    return ['products' => ['کالاها', "SELECT p.id, p.name, p.barcode, c.name category, p.brand, p.price, p.sale_price, p.min_stock, p.warranty, p.active FROM products p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.id"],
        'customers' => ['مشتریان و همکاران', "SELECT id, kind, name, mobile, national_id, economic_code, postal_code, address, phone, credit_limit, is_partner FROM customers WHERE merged_into IS NULL ORDER BY id"],
        'suppliers' => ['تأمین‌کنندگان', "SELECT id, name, national_id, mobile, phone, iban, pay_terms_days, seller_code, address FROM suppliers ORDER BY id"],
        'stock' => ['موجودی', "SELECT p.name, l.code lot, w.name warehouse, COALESCE(SUM(m.qty),0) qty, l.unit_cost FROM lots l JOIN products p ON p.id=l.product_id JOIN warehouses w ON w.id=l.warehouse_id LEFT JOIN stock_moves m ON m.lot_id=l.id GROUP BY l.id, p.name, l.code, w.name, l.unit_cost HAVING qty<>0"],
        'sales' => ['فروش‌ها', "SELECT s.number, s.d, s.total, s.tax, s.subtotal, c.name customer FROM sales s LEFT JOIN customers c ON c.id=s.customer_id ORDER BY s.id"]];
}
function ds_export(string $set): void {
    $ds = ds_sets()[$set] ?? null; if (!$ds) throw new UserError('مجموعه نامعتبر است');
    $rs = rows($ds[1]); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="8cell-' . $set . '-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); if ($rs) { fputcsv($o, array_keys($rs[0])); foreach ($rs as $r) fputcsv($o, $r); } fclose($o);
}
/** درون‌ریزیِ کالا (با بارکد یا نام) یا مشتری (با موبایل): به‌روز یا تازه */
function ds_import(string $set, string $csvPath, bool $dry = false): array {
    if (!in_array($set, ['products', 'customers'], true)) throw new UserError('فقط کالا و مشتری درون‌ریزی می‌شوند');
    $fh = fopen($csvPath, 'r'); $head = fgetcsv($fh); if (!$head) throw new UserError('پرونده خالی است'); $head = array_map(fn($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h)), $head);
    $new = 0; $upd = 0; $err = []; $line = 1;
    $run = function () use ($fh, $head, $set, &$new, &$upd, &$err, &$line) {
        while (($r = fgetcsv($fh)) !== false) { $line++; if (count(array_filter($r, fn($x) => trim((string)$x) !== '')) === 0) continue; $row = array_combine($head, array_pad(array_slice($r, 0, count($head)), count($head), ''));
            try {
                if ($set === 'products') { $id = !empty($row['barcode']) ? (int)val("SELECT id FROM products WHERE barcode=?", [en_num((string)$row['barcode'])]) : 0; if (!$id && !empty($row['id'])) $id = (int)val("SELECT id FROM products WHERE id=?", [(int)$row['id']]); if (!$id) $id = (int)val("SELECT id FROM products WHERE name=?", [trim((string)$row['name'])]);
                    $cur = $id ? product_get($id) : []; $cat = !empty($row['category']) ? ((int)val("SELECT id FROM categories WHERE name=?", [trim((string)$row['category'])]) ?: null) : null;
                    product_save(array_filter(['id' => $id ?: null, 'name' => $row['name'] ?? ($cur['name'] ?? ''), 'barcode' => $row['barcode'] ?? ($cur['barcode'] ?? ''), 'brand' => $row['brand'] ?? ($cur['brand'] ?? ''), 'price' => $row['price'] ?? ($cur['price'] ?? 0),
                        'category_id' => $cat ?: ($cur['category_id'] ?? null), 'min_stock' => $row['min_stock'] ?? ($cur['min_stock'] ?? 0), 'item_type' => $cur['item_type'] ?? 'SAL', 'serial' => $cur['serial'] ?? 0, 'warranty' => $row['warranty'] ?? ($cur['warranty'] ?? ''), 'unit_id' => $cur['unit_id'] ?? null], fn($v) => $v !== null)); $id ? $upd++ : $new++; }
                else { $m = norm_mobile($row['mobile'] ?? ''); $id = $m ? (int)val("SELECT id FROM customers WHERE mobile=?", [$m]) : 0; $cur = $id ? customer_get($id) : [];
                    customer_save(['id' => $id ?: null, 'kind' => ($row['kind'] ?? '') === 'org' ? 'org' : ($cur['kind'] ?? 'person'), 'name' => trim((string)($row['name'] ?? '')) ?: ($cur['name'] ?? $m), 'mobile' => $m, 'national_id' => $row['national_id'] ?? ($cur['national_id'] ?? ''), 'address' => $row['address'] ?? ($cur['address'] ?? ''), 'postal_code' => $row['postal_code'] ?? ($cur['postal_code'] ?? '')]); $id ? $upd++ : $new++; }
            } catch (UserError $e) { $err[] = 'خطِ ' . fa_num((string)$line) . ': ' . $e->getMessage(); if (count($err) > 30) break; }
        }
        if ($err) throw new UserError(implode(' | ', array_slice($err, 0, 10)));
    };
    try { tx(function () use ($run, $dry) { $run(); if ($dry) throw new RuntimeException('__dry'); }); } catch (RuntimeException $e) { if ($e->getMessage() !== '__dry') throw $e; }
    fclose($fh);
    return ['new' => $new, 'updated' => $upd, 'dry' => $dry, 'msg' => ($dry ? 'آزمایشی (چیزی ذخیره نشد): ' : '') . fa_num((string)$new) . ' تازه، ' . fa_num((string)$upd) . ' به‌روز'];
}
