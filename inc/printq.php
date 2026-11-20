<?php
// چاپگرِ مرکزی: سایت صف نگه می‌دارد؛ ایستگاه خودش می‌پرسد (poll) — سایت هرگز تماس نمی‌گیرد
declare(strict_types=1);

function print_station_create(string $name): array {
    $token = bin2hex(random_bytes(20));
    $id = ins('print_stations', ['name' => trim($name) ?: 'ایستگاهِ چاپ', 'token_hash' => hash('sha256', $token)]);
    audit('print_stations', $id, 'create', null, ['name' => $name], 'ایستگاهِ چاپ');
    return ['id' => $id, 'token' => $token]; // توکن فقط یک بار نمایش داده می‌شود
}
function print_station(int $id): ?array { $s = row("SELECT * FROM print_stations WHERE id=?", [$id]); if ($s) $s['printers'] = json_decode((string)$s['printers'], true) ?: []; return $s; }
function print_stations(): array { return array_map(fn($s) => print_station((int)$s['id']) + ['online' => print_station_online($s)], rows("SELECT * FROM print_stations WHERE active=1 ORDER BY id")); }
function print_station_online(array $s): bool { return !empty($s['last_seen']) && time() - strtotime($s['last_seen']) < 90; }
function print_station_by_token(string $t): ?array { return $t ? row("SELECT * FROM print_stations WHERE token_hash=? AND active=1", [hash('sha256', $t)]) : null; }
/** کاغذهای یک پرینتر (چندکاغذی ← همه؛ وگرنه کاغذِ پیش‌فرض) — از Weby */
function print_clean_papers($rows): array {
    $out = [];
    foreach ((array)$rows as $r) { if (!is_array($r)) continue; $out[] = ['id' => mb_cut(trim((string)($r['id'] ?? '')), 40), 'paper' => mb_cut(trim((string)($r['paper'] ?? '')), 60), 'paper_w' => (float)($r['paper_w'] ?? 0), 'paper_h' => (float)($r['paper_h'] ?? 0), 'tolerance' => max(0, (float)($r['tolerance'] ?? 3))]; if (count($out) >= 20) break; }
    return $out;
}
function print_printer_sizes(array $pr): array {
    $c = (!empty($pr['multi_paper']) && !empty($pr['papers'])) ? $pr['papers'] : [$pr]; $o = [];
    foreach ($c as $x) { $w = round((float)($x['paper_w'] ?? 0)); $h = round((float)($x['paper_h'] ?? 0)); if ($w > 0 && $h > 0) $o[] = [$w, $h]; }
    return $o;
}
/** کاغذِ پرینتر با کاغذِ لازم می‌خواند؟ (چرخیده هم قبول؛ اندازهٔ تعریف‌نشده جلوی چاپ را نمی‌گیرد) */
function print_printer_fits(array $pr, float $w, float $h): bool {
    if ($w <= 0 || $h <= 0) return true;
    $c = (!empty($pr['multi_paper']) && !empty($pr['papers'])) ? $pr['papers'] : [$pr];
    foreach ($c as $x) {
        $pw = (float)($x['paper_w'] ?? 0); $ph = (float)($x['paper_h'] ?? 0);
        if ($pw <= 0 || $ph <= 0) { if (count($c) === 1) return true; continue; }
        $tol = (float)($x['tolerance'] ?? $pr['tolerance'] ?? 3);
        if ((abs($pw - $w) <= $tol && abs($ph - $h) <= $tol) || (abs($pw - $h) <= $tol && abs($ph - $w) <= $tol)) return true;
    }
    return false;
}
/** کاری که ایستگاه نمی‌تواند چاپ کند اصلاً وارد صف نمی‌شود — '' یعنی مشکلی نیست */
function print_paper_check(array $st, string $printer, float $w, float $h): string {
    if ($w <= 0 || $h <= 0) return '';
    $ps = $st['printers'] ?: []; if (!$ps) return '';
    $t = null; foreach ($ps as $p) { if ($printer === '' && !empty($p['default'])) { $t = $p; break; } if ($printer !== '' && ($p['name'] ?? '') === $printer) { $t = $p; break; } }
    if ($printer === '' && !$t) $t = $ps[0];
    if (!$t) return 'پرینترِ «' . $printer . '» روی ایستگاهِ «' . $st['name'] . '» پیدا نشد.';
    if (print_printer_fits($t, $w, $h)) return '';
    $have = array_map(fn($x) => fa_num($x[0]) . '×' . fa_num($x[1]), print_printer_sizes($t));
    return 'کاغذِ لازم ' . fa_num(round($w)) . '×' . fa_num(round($h)) . ' میلی‌متر است ولی پرینترِ «' . ($t['name'] ?? '') . '» کاغذِ ' . implode('، ', $have) . ' دارد. کاغذ را عوض کنید یا ایستگاهِ دیگری انتخاب کنید.';
}
function print_job_status_label(string $s): string { return ['queued' => 'در صف', 'claimed' => 'گرفته شد', 'printing' => 'در حالِ چاپ', 'done' => 'چاپ شد', 'error' => 'خطا', 'cancel' => 'لغو'][$s] ?? $s; }
function print_job_in_flight(string $dedupe): ?array { return $dedupe === '' ? null : row("SELECT * FROM print_jobs WHERE dedupe=? AND status IN ('queued','claimed','printing') ORDER BY id DESC LIMIT 1", [$dedupe]); }
/** ثبتِ کار با نگهبانِ چندبارچاپ و کاغذ */
function print_job_create(array $job): array {
    $st = print_station((int)($job['station_id'] ?? 0));
    if (!$st || !$st['active']) throw new UserError('ایستگاهِ چاپ یافت نشد');
    $w = (float)($job['paper_w'] ?? 0); $h = (float)($job['paper_h'] ?? 0);
    if ($w <= 0 || $h <= 0) throw new UserError('اندازهٔ کاغذ مشخص نیست');
    $printer = (string)($job['printer'] ?? '');
    if (($why = print_paper_check($st, $printer, $w, $h)) !== '') throw new UserError($why);
    $url = (string)($job['url'] ?? ''); if (!preg_match('#^index\.php\?#', $url)) throw new UserError('نشانیِ صفحهٔ چاپ نامعتبر است');
    $dedupe = mb_cut((string)($job['dedupe'] ?? $url), 120);
    if ($f = print_job_in_flight($dedupe)) throw new UserError('این سند همین الان در صفِ چاپ است (' . print_job_status_label($f['status']) . ')');
    $done = row("SELECT id, done_at FROM print_jobs WHERE dedupe=? AND status='done' ORDER BY id DESC LIMIT 1", [$dedupe]);
    if ($done && empty($job['confirm'])) return ['need_confirm' => true, 'msg' => 'این سند ' . jdate($done['done_at'], true) . ' چاپ شده است؛ دوباره چاپ شود؟'];
    $token = bin2hex(random_bytes(16));
    $id = ins('print_jobs', ['station_id' => $st['id'], 'printer' => $printer ?: null, 'url' => $url, 'paper_w' => $w, 'paper_h' => $h, 'copies' => max(1, min(50, (int)($job['copies'] ?? 1))),
        'doc_type' => mb_cut((string)($job['doc_type'] ?? 'doc'), 20), 'paper_name' => mb_cut((string)($job['paper'] ?? label_paper_std($w, $h)), 60), 'title' => mb_cut((string)($job['title'] ?? ''), 200), 'dedupe' => $dedupe, 'token' => $token, 'created_by' => current_user()['id'] ?? null, 'created_at' => now()]);
    return ['id' => $id, 'status' => 'queued'];
}
function print_job_public(array $j): array { return ['id' => (int)$j['id'], 'status' => $j['status'], 'label' => print_job_status_label($j['status']), 'error' => $j['error'], 'title' => $j['title'], 'at' => jdate($j['created_at'], true)]; }

/**
 * صفحهٔ چاپ با توکنِ کار (pjob + pk) فقط برای *همان سندی* باز می‌شود که کار برایش ساخته شده —
 * وگرنه با عوض‌کردنِ id هر سندِ دیگری خوانده می‌شد (درسِ Weby).
 */
function print_job_grant(): ?array {
    $id = (int)($_GET['pjob'] ?? 0); $pk = trim((string)($_GET['pk'] ?? ''));
    if (!$id || $pk === '') return null;
    $j = row("SELECT * FROM print_jobs WHERE id=?", [$id]);
    if (!$j || !hash_equals((string)$j['token'], $pk) || in_array($j['status'], ['done', 'error', 'cancel'], true) || strtotime($j['created_at']) < time() - 86400) return null;
    $norm = static function (string $path, array $q): string { unset($q['pjob'], $q['pk']); ksort($q); return strtolower(basename($path)) . '?' . http_build_query($q); };
    $p = parse_url((string)$j['url']); if (!$p || empty($p['path'])) return null;
    $jq = []; parse_str($p['query'] ?? '', $jq);
    return hash_equals($norm($p['path'], $jq), $norm((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? 'index.php'), PHP_URL_PATH) ?: 'index.php', $_GET)) ? $j : null;
}
