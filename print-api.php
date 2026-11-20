<?php
// API ایستگاهِ چاپ — همان پروتکلِ Weby تا برنامهٔ ایستگاهِ Weby (tools/print-agent) بی‌تغییر به 8CELL هم وصل شود.
//   POST ?do=hello   معرفی + پرینترها و کاغذِ هرکدام
//   GET  ?do=claim   کارِ بعدی
//   POST ?do=status  printing | done | error
// احراز هویت: هدرِ X-Print-Token (یا token در بدنه/نشانی).
declare(strict_types=1);
require __DIR__ . '/inc/boot.php';
function papi_out(array $d, int $code = 200): void { json_out($d, $code); exit; }
try { app_boot(false); } catch (Throwable $e) { papi_out(['ok' => false, 'error' => 'سایت در دسترس نیست'], 503); }
$body = json_decode((string)file_get_contents('php://input'), true); if (!is_array($body)) $body = [];
$token = trim((string)($_SERVER['HTTP_X_PRINT_TOKEN'] ?? ($body['token'] ?? ($_GET['token'] ?? ''))));
$st = print_station_by_token($token);
if (!$st) papi_out(['ok' => false, 'error' => 'توکن ایستگاه معتبر نیست.'], 401);
$sid = (int)$st['id'];
// do از نشانی، بدنهٔ JSON یا فرم (ایستگاه do را در نشانی و بدنه را JSON می‌فرستد)
$do = trim((string)($_GET['do'] ?? '')) ?: trim((string)($body['do'] ?? '')) ?: trim((string)($_POST['do'] ?? ''));
if ($do === 'hello') {
    $printers = [];
    foreach ((array)($body['printers'] ?? []) as $p) {
        if (!is_array($p) || trim((string)($p['name'] ?? '')) === '') continue;
        $printers[] = ['name' => mb_cut(trim((string)$p['name']), 120), 'label' => mb_cut(trim((string)($p['label'] ?? $p['name'])), 80), 'paper' => mb_cut(trim((string)($p['paper'] ?? '')), 60),
            'paper_w' => (float)($p['paper_w'] ?? 0), 'paper_h' => (float)($p['paper_h'] ?? 0), 'tolerance' => max(0, (float)($p['tolerance'] ?? 3)), 'default' => !empty($p['default']) ? 1 : 0,
            'kind' => in_array($p['kind'] ?? '', ['thermal', 'paper'], true) ? $p['kind'] : 'paper', 'multi_paper' => !empty($p['multi_paper']) ? 1 : 0, 'papers' => print_clean_papers($p['papers'] ?? [])];
    }
    upd('print_stations', ['printers' => json_encode($printers, JSON_UNESCAPED_UNICODE), 'agent_version' => mb_cut((string)($body['version'] ?? ''), 20) ?: null, 'last_seen' => now()], 'id=?', [$sid]);
    papi_out(['ok' => true, 'station' => $st['name'], 'poll' => 5]);
}
if ($do === 'claim') {
    upd('print_stations', ['last_seen' => now()], 'id=?', [$sid]);
    // «گرفته‌شده ولی شروع‌نشده» امن برمی‌گردد؛ «در حالِ چاپ» هرگز دوباره چاپ نمی‌شود (خطا تا آدم تصمیم بگیرد)؛ سقفِ سه تلاش
    q("UPDATE print_jobs SET status='queued' WHERE station_id=? AND status='claimed' AND attempts<3 AND claimed_at<?", [$sid, date('Y-m-d H:i:s', time() - 300)]);
    q("UPDATE print_jobs SET status='error', done_at=?, error=? WHERE station_id=? AND ((status='printing' AND printing_at<?) OR (status='claimed' AND attempts>=3 AND claimed_at<?))",
        [now(), 'ایستگاه کار را گرفت ولی نتیجهٔ چاپ را اعلام نکرد؛ اگر روی کاغذ نیامده دوباره بفرستید.', $sid, date('Y-m-d H:i:s', time() - 1200), date('Y-m-d H:i:s', time() - 300)]);
    $j = row("SELECT * FROM print_jobs WHERE station_id=? AND status='queued' ORDER BY id LIMIT 1", [$sid]);
    if (!$j) papi_out(['ok' => true, 'job' => null]);
    if (q("UPDATE print_jobs SET status='claimed', claimed_at=?, attempts=attempts+1, tries=tries+1 WHERE id=? AND status='queued'", [now(), $j['id']])->rowCount() === 0) papi_out(['ok' => true, 'job' => null]);
    $sep = str_contains($j['url'], '?') ? '&' : '?';
    papi_out(['ok' => true, 'job' => ['id' => (int)$j['id'], 'printer' => $j['printer'], 'type' => $j['doc_type'], 'title' => $j['title'],
        'url' => app_url($j['url'] . $sep . 'pjob=' . (int)$j['id'] . '&pk=' . $j['token']), 'paper_w' => (float)$j['paper_w'], 'paper_h' => (float)$j['paper_h'], 'paper' => (string)$j['paper_name'], 'copies' => (int)$j['copies']]]);
}
if ($do === 'status') {
    $id = (int)($body['id'] ?? 0); $s = (string)($body['status'] ?? ''); $err = mb_cut(trim((string)($body['error'] ?? '')), 400);
    if (!in_array($s, ['printing', 'done', 'error'], true)) papi_out(['ok' => false, 'error' => 'وضعیت نامعتبر است.'], 400);
    $j = row("SELECT id, status FROM print_jobs WHERE id=? AND station_id=?", [$id, $sid]);
    if (!$j) papi_out(['ok' => false, 'error' => 'این کار مالِ این ایستگاه نیست.'], 404);
    if ($j['status'] === 'done') papi_out(['ok' => true, 'already' => true]); // کارِ چاپ‌شده هرگز برنمی‌گردد
    if ($s === 'printing') upd('print_jobs', ['status' => 'printing', 'printing_at' => now()], 'id=?', [$id]);
    else upd('print_jobs', ['status' => $s, 'error' => $s === 'error' ? ($err ?: 'خطای نامعلوم') : null, 'done_at' => now()], 'id=?', [$id]);
    upd('print_stations', ['last_seen' => now()], 'id=?', [$sid]);
    papi_out(['ok' => true]);
}
papi_out(['ok' => false, 'error' => 'دستورِ نامعتبر: ' . ($do !== '' ? $do : '(خالی)') . '. دستورهای معتبر: hello، claim، status.'], 400);
