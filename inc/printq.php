<?php
// چاپگرِ مرکزی: سایت صف نگه می‌دارد؛ ایستگاه خودش می‌پرسد (poll) — سایت هرگز تماس نمی‌گیرد
declare(strict_types=1);

function print_station_create(string $name): array {
    $token = bin2hex(random_bytes(20));
    $id = ins('print_stations', ['name' => trim($name) ?: 'ایستگاهِ چاپ', 'token_hash' => hash('sha256', $token)]);
    audit('print_stations', $id, 'create', null, ['name' => $name], 'ایستگاهِ چاپ');
    return ['id' => $id, 'token' => $token]; // توکن فقط یک بار نمایش داده می‌شود
}
function print_station(int $id): ?array { $s = row("SELECT * FROM print_stations WHERE id=?", [$id]); if ($s) { $s['printers'] = json_decode((string)$s['printers'], true) ?: []; $s['papers'] = json_decode((string)$s['papers'], true) ?: []; } return $s; }
function print_stations(): array { return array_map(fn($s) => print_station((int)$s['id']) + ['online' => print_station_online($s)], rows("SELECT * FROM print_stations WHERE active=1 ORDER BY id")); }
function print_station_online(array $s): bool { return !empty($s['last_seen']) && time() - strtotime($s['last_seen']) < 90; }
function print_station_by_token(string $t): ?array { return $t ? row("SELECT * FROM print_stations WHERE token_hash=? AND active=1", [hash('sha256', $t)]) : null; }
/** کاغذِ ایستگاه با اندازهٔ کار جور است؟ */
function print_paper_check(array $st, float $w, float $h): bool {
    $papers = $st['papers'] ?: [];
    if (!$papers) return true; // ایستگاه هنوز کاغذ اعلام نکرده
    foreach ($papers as $p) if (abs((float)$p['w'] - $w) < 1.5 && abs((float)$p['h'] - $h) < 1.5) return true;
    return false;
}
function print_job_status_label(string $s): string { return ['queued' => 'در صف', 'claimed' => 'گرفته شد', 'printing' => 'در حالِ چاپ', 'done' => 'چاپ شد', 'error' => 'خطا', 'cancel' => 'لغو'][$s] ?? $s; }
function print_job_in_flight(string $dedupe): ?array { return $dedupe === '' ? null : row("SELECT * FROM print_jobs WHERE dedupe=? AND status IN ('queued','claimed','printing') ORDER BY id DESC LIMIT 1", [$dedupe]); }
/** ثبتِ کار با نگهبانِ چندبارچاپ و کاغذ */
function print_job_create(array $job): array {
    $st = print_station((int)($job['station_id'] ?? 0));
    if (!$st || !$st['active']) throw new UserError('ایستگاهِ چاپ یافت نشد');
    $w = (float)($job['paper_w'] ?? 0); $h = (float)($job['paper_h'] ?? 0);
    if ($w <= 0 || $h <= 0) throw new UserError('اندازهٔ کاغذ مشخص نیست');
    if (!print_paper_check($st, $w, $h)) throw new UserError('ایستگاه کاغذِ ' . label_paper_name($w, $h) . ' ندارد');
    $printer = (string)($job['printer'] ?? '');
    if ($printer !== '' && $st['printers'] && !in_array($printer, array_column($st['printers'], 'name'), true)) throw new UserError('این پرینتر در ایستگاه نیست');
    $url = (string)($job['url'] ?? ''); if (!preg_match('#^index\.php\?#', $url)) throw new UserError('نشانیِ صفحهٔ چاپ نامعتبر است');
    $dedupe = mb_cut((string)($job['dedupe'] ?? $url), 120);
    if ($f = print_job_in_flight($dedupe)) throw new UserError('این سند همین الان در صفِ چاپ است (' . print_job_status_label($f['status']) . ')');
    $done = row("SELECT id, done_at FROM print_jobs WHERE dedupe=? AND status='done' ORDER BY id DESC LIMIT 1", [$dedupe]);
    if ($done && empty($job['confirm'])) return ['need_confirm' => true, 'msg' => 'این سند ' . jdate($done['done_at'], true) . ' چاپ شده است؛ دوباره چاپ شود؟'];
    $token = bin2hex(random_bytes(16));
    $id = ins('print_jobs', ['station_id' => $st['id'], 'printer' => $printer ?: null, 'url' => $url, 'paper_w' => $w, 'paper_h' => $h, 'copies' => max(1, min(50, (int)($job['copies'] ?? 1))),
        'doc_type' => mb_cut((string)($job['doc_type'] ?? 'doc'), 20), 'title' => mb_cut((string)($job['title'] ?? ''), 200), 'dedupe' => $dedupe, 'token' => $token, 'created_by' => current_user()['id'] ?? null, 'created_at' => now()]);
    return ['id' => $id, 'status' => 'queued'];
}
function print_job_public(array $j): array { return ['id' => (int)$j['id'], 'status' => $j['status'], 'label' => print_job_status_label($j['status']), 'error' => $j['error'], 'title' => $j['title'], 'at' => jdate($j['created_at'], true)]; }

// ── API ایستگاه (با توکنِ ایستگاه، بدونِ نشست)
function agent_auth(): array {
    $t = (string)($_SERVER['HTTP_X_STATION_TOKEN'] ?? ($_GET['st'] ?? ''));
    $s = print_station_by_token($t); if (!$s) throw new UserError('توکنِ ایستگاه نامعتبر است', 401);
    upd('print_stations', ['last_seen' => now(), 'agent_version' => mb_cut((string)($_SERVER['HTTP_X_AGENT_VERSION'] ?? ''), 20) ?: null], 'id=?', [$s['id']]);
    return $s;
}
function agent_hello(array $in): array {
    $s = agent_auth();
    upd('print_stations', ['printers' => json_encode(array_values((array)($in['printers'] ?? [])), JSON_UNESCAPED_UNICODE), 'papers' => json_encode(array_values((array)($in['papers'] ?? [])))], 'id=?', [$s['id']]);
    return ['ok' => true, 'station' => $s['name']];
}
/** گرفتنِ کار: queued ← claimed (کارِ گیرکرده بیش از ۱۰ دقیقه دوباره صف می‌شود) */
function agent_poll(): array {
    $s = agent_auth();
    q("UPDATE print_jobs SET status='queued' WHERE station_id=? AND status='claimed' AND claimed_at<?", [$s['id'], date('Y-m-d H:i:s', time() - 600)]);
    return tx(function () use ($s) {
        $j = row("SELECT * FROM print_jobs WHERE station_id=? AND status='queued' ORDER BY id LIMIT 1 FOR UPDATE", [$s['id']]);
        if (!$j) return ['job' => null];
        upd('print_jobs', ['status' => 'claimed', 'claimed_at' => now(), 'tries' => (int)$j['tries'] + 1], 'id=?', [$j['id']]);
        $sep = str_contains($j['url'], '?') ? '&' : '?';
        return ['job' => ['id' => (int)$j['id'], 'url' => app_url($j['url'] . $sep . 'pj=' . $j['token']), 'printer' => $j['printer'], 'paper_w' => (float)$j['paper_w'], 'paper_h' => (float)$j['paper_h'],
            'copies' => (int)$j['copies'], 'doc_type' => $j['doc_type'], 'title' => $j['title']]];
    });
}
/** گزارشِ وضعیت — کارِ چاپ‌شده هرگز دوباره چاپ نمی‌شود */
function agent_report(array $in): array {
    $s = agent_auth();
    $j = row("SELECT * FROM print_jobs WHERE id=? AND station_id=?", [(int)($in['job_id'] ?? 0), $s['id']]);
    if (!$j) throw new UserError('کار یافت نشد', 404);
    if ($j['status'] === 'done') return ['ok' => true, 'already' => true];
    $st = in_array($in['status'] ?? '', ['printing', 'done', 'error'], true) ? $in['status'] : 'error';
    upd('print_jobs', ['status' => $st, 'error' => $st === 'error' ? mb_cut((string)($in['error'] ?? 'خطای نامعلوم'), 500) : null, 'done_at' => $st === 'done' ? now() : null], 'id=?', [$j['id']]);
    return ['ok' => true];
}
/** صفحهٔ چاپ با توکنِ کار باز می‌شود (برای کرومِ بی‌سرِ ایستگاه) */
function print_job_by_token(string $t): ?array { return $t ? row("SELECT * FROM print_jobs WHERE token=? AND status IN ('claimed','printing')", [$t]) : null; }
