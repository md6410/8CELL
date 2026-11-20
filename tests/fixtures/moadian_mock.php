<?php
// سامانهٔ مودیانِ ساختگی برای آزمون (فقط با php -S؛ در deploy-files نیست). کلیدها فقط برای آزمون‌اند.
declare(strict_types=1);
class UserError extends Exception {}
require __DIR__ . '/../../inc/moadian.php';
header('Content-Type: application/json');
$p = $_SERVER['PATH_INFO'] ?? '/'; $log = sys_get_temp_dir() . '/moadian_mock.json'; $st = is_file($log) ? json_decode(file_get_contents($log), true) : [];
$auth = function () { $t = preg_replace('/^Bearer /', '', $_SERVER['HTTP_AUTHORIZATION'] ?? ''); $x = explode('.', $t); if (count($x) !== 3) return false;
    $h = json_decode(mb64u_dec($x[0]), true); $cert = moadian_pem($h['x5c'][0] ?? '', 'CERTIFICATE');
    return openssl_verify($x[0] . '.' . $x[1], mb64u_dec($x[2]), openssl_pkey_get_public($cert), OPENSSL_ALGO_SHA256) === 1 && ($h['crit'] ?? []) === ['sigT'] ? json_decode(mb64u_dec($x[1]), true) : false; };
if ($p === '/nonce') { echo json_encode(['nonce' => 'n-' . bin2hex(random_bytes(4)), 'expDate' => date('c', time() + 20)]); exit; }
if (!($tok = $auth())) { http_response_code(401); echo json_encode(['message' => 'bad token']); exit; }
if ($p === '/server-information') { echo json_encode(['serverTime' => time() * 1000, 'publicKeys' => [['key' => preg_replace('/-----[^-]+-----|\s+/', '', file_get_contents(__DIR__ . '/moadian_srv_test.pub')), 'id' => 'kid-test-1', 'algorithm' => 'RSA', 'purpose' => 1]]]); exit; }
if ($p === '/invoice') {
    $b = json_decode(file_get_contents('php://input'), true); $pk = $b[0]['payload'] ?? ''; $hdr = json_decode(mb64u_dec(explode('.', $pk)[0]), true);
    $jws = moadian_jwe_decrypt($pk, file_get_contents(__DIR__ . '/moadian_srv_test.key')); $x = explode('.', $jws); $jh = json_decode(mb64u_dec($x[0]), true);
    $okSig = openssl_verify($x[0] . '.' . $x[1], mb64u_dec($x[2]), openssl_pkey_get_public(moadian_pem($jh['x5c'][0], 'CERTIFICATE')), OPENSSL_ALGO_SHA256) === 1;
    $inv = json_decode(mb64u_dec($x[1]), true); $tid = (string)($inv['header']['taxid'] ?? '');
    $errs = []; if (!$okSig) $errs[] = 'sig'; if ($hdr['alg'] !== 'RSA-OAEP-256' || $hdr['enc'] !== 'A256GCM' || $hdr['kid'] !== 'kid-test-1') $errs[] = 'jwe-header';
    if (strlen($tid) !== 22 || !str_starts_with($tid, $tok['clientId']) || ($b[0]['header']['fiscalId'] ?? '') !== $tok['clientId']) $errs[] = 'taxid';
    $sum = array_sum(array_column($inv['body'], 'tsstam')); if ($sum !== $inv['header']['tbill']) $errs[] = 'tbill';
    $ref = 'REF-' . bin2hex(random_bytes(4)); $st[$ref] = ['errs' => $errs, 'taxid' => $tid, 'inv' => $inv]; file_put_contents($log, json_encode($st));
    echo json_encode(['timestamp' => time() * 1000, 'result' => [['uid' => 'u1', 'referenceNumber' => $ref, 'errorCode' => null, 'errorDetail' => null]]]); exit;
}
if ($p === '/inquiry-by-reference-id') { $r = $st[$_GET['referenceIds'] ?? ''] ?? null;
    echo json_encode([['referenceNumber' => $_GET['referenceIds'] ?? '', 'status' => $r && !$r['errs'] ? 'SUCCESS' : 'FAILED', 'data' => ['error' => $r ? array_map(fn($e) => ['code' => 'T-' . $e, 'message' => $e], $r['errs']) : [['code' => 'NF', 'message' => 'not found']], 'warning' => []]]]); exit; }
http_response_code(404); echo '{}';
