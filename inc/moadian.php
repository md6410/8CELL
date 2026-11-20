<?php
// ارسالِ صورتحسابِ رسمی به سامانهٔ مودیان (API نسخهٔ ۲ مالیاتی: requestsmanager)
//  - شمارهٔ مالیاتی (taxid) ۲۲ نویسه: شناسهٔ حافظه + روز (هگز ۵) + سریال (هگز ۱۰) + رقمِ کنترلِ Verhoeff
//  - توکن: JWS (RS256، x5c، sigT) از {nonce, clientId}؛ صورتحساب: JWS ← JWE (RSA-OAEP-256 + A256GCM) با کلیدِ عمومیِ سامانه
//  - فقط با دکمه در پنل به شبکه می‌رود (ارسال / استعلام)، نه در مسیرِ کاربر. هر درخواست و پاسخ در moadian_log.
//  - کلیدِ خصوصی فقط در تنظیمات (moadian_secret_key) و هرگز در صفحه نمایش داده نمی‌شود.
declare(strict_types=1);

function moadian_cfg(string $k) {
    $d = ['moadian_on' => '0', 'moadian_base' => 'https://tp.tax.gov.ir/requestsmanager/api/v2', 'moadian_units' => "عدد=1627", 'moadian_sstid_default' => '', 'moadian_timeout' => 20];
    return setting($k, $d[$k] ?? null);
}
function moadian_on(): bool { return moadian_cfg('moadian_on') === '1'; }
function moadian_memory_id(): string { return strtoupper(trim((string)setting('biz_memory_id', ''))); }

// ── کدگذاری
function mb64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function mb64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** رقمِ کنترلِ Verhoeff */
function verhoeff_digit(string $num): int {
    static $d = [[0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],[3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],[5,9,8,7,6,0,4,3,2,1],[6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],[9,8,7,6,5,4,3,2,1,0]];
    static $p = [[0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],[8,9,1,6,0,4,3,5,2,7],[9,4,5,3,1,2,7,6,8,0],[4,2,8,6,5,7,3,9,0,1],[2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8]];
    static $inv = [0,4,3,2,1,5,6,7,8,9];
    $c = 0; $r = strrev($num);
    for ($i = 0; $i < strlen($r); $i++) $c = $d[$c][$p[($i + 1) % 8][(int)$r[$i]]];
    return $inv[$c];
}
function verhoeff_ok(string $num): bool { return verhoeff_digit(substr($num, 0, -1)) === (int)substr($num, -1); }

/** شمارهٔ مالیاتیِ ۲۲ نویسه‌ای */
function moadian_tax_id(string $memoryId, int $unixSec, int $serial): string {
    $memoryId = strtoupper($memoryId);
    if (!preg_match('/^[A-Z0-9]{6}$/', $memoryId)) throw new UserError('شناسهٔ یکتای حافظهٔ مالیاتی باید ۶ نویسه باشد (پروفایلِ کسب‌وکار)');
    $days = intdiv($unixSec, 86400);
    $dec = ''; foreach (str_split($memoryId) as $ch) $dec .= ctype_digit($ch) ? $ch : (string)ord($ch);
    $ctrl = $dec . str_pad((string)$days, 6, '0', STR_PAD_LEFT) . str_pad((string)$serial, 12, '0', STR_PAD_LEFT);
    return strtoupper($memoryId . str_pad(dechex($days), 5, '0', STR_PAD_LEFT) . str_pad(dechex($serial), 10, '0', STR_PAD_LEFT) . verhoeff_digit($ctrl));
}

// ── رمزنگاری
function moadian_pem(string $b64, string $label = 'PUBLIC KEY'): string {
    $b64 = preg_replace('/-----[^-]+-----|\s+/', '', $b64);
    return "-----BEGIN $label-----\n" . chunk_split($b64, 64, "\n") . "-----END $label-----\n";
}
function moadian_cert_b64(string $cert): string { return preg_replace('/-----[^-]+-----|\s+/', '', $cert); }
/** JWS فشرده با سرآیندِ سامانه (x5c + sigT) */
function moadian_jws(string $payload, string $privPem, string $certPem, string $cty = 'text/plain'): string {
    $h = ['alg' => 'RS256', 'typ' => 'jose', 'x5c' => [moadian_cert_b64($certPem)], 'sigT' => gmdate('Y-m-d\TH:i:s\Z'), 'crit' => ['sigT'], 'cty' => $cty];
    $in = mb64u(json_encode($h, JSON_UNESCAPED_SLASHES)) . '.' . mb64u($payload);
    $k = openssl_pkey_get_private($privPem); if (!$k) throw new UserError('کلیدِ خصوصیِ سامانهٔ مودیان نامعتبر است');
    if (!openssl_sign($in, $sig, $k, OPENSSL_ALGO_SHA256)) throw new UserError('امضای صورتحساب نشد');
    return $in . '.' . mb64u($sig);
}
function moadian_mgf1(string $seed, int $len): string { $t = ''; for ($c = 0; strlen($t) < $len; $c++) $t .= hash('sha256', $seed . pack('N', $c), true); return substr($t, 0, $len); }
/** RSA-OAEP با SHA-256 (openssl ِ PHP فقط SHA-1 دارد؛ پس کدگذاری دستی + RSAِ خام) */
function moadian_oaep_encrypt(string $m, $pub): string {
    $k = (int)(openssl_pkey_get_details($pub)['bits'] / 8); $hl = 32;
    if (strlen($m) > $k - 2 * $hl - 2) throw new RuntimeException('کلید برای OAEP بلند است');
    $db = hash('sha256', '', true) . str_repeat("\0", $k - strlen($m) - 2 * $hl - 2) . "\x01" . $m;
    $seed = random_bytes($hl); $mdb = $db ^ moadian_mgf1($seed, $k - $hl - 1); $ms = $seed ^ moadian_mgf1($mdb, $hl);
    if (!openssl_public_encrypt("\0" . $ms . $mdb, $out, $pub, OPENSSL_NO_PADDING)) throw new RuntimeException('رمزگذاریِ کلید نشد');
    return $out;
}
function moadian_oaep_decrypt(string $c, $priv): string { // برای آزمون
    if (!openssl_private_decrypt($c, $em, $priv, OPENSSL_NO_PADDING)) throw new RuntimeException('rsa');
    $k = strlen($em); $hl = 32; $ms = substr($em, 1, $hl); $mdb = substr($em, 1 + $hl);
    $seed = $ms ^ moadian_mgf1($mdb, $hl); $db = $mdb ^ moadian_mgf1($seed, $k - $hl - 1);
    if (substr($db, 0, $hl) !== hash('sha256', '', true)) throw new RuntimeException('oaep');
    $i = strpos($db, "\x01", $hl); return substr($db, $i + 1);
}
/** JWE فشرده: RSA-OAEP-256 + A256GCM */
function moadian_jwe(string $plain, string $pubPem, string $kid): string {
    $pub = openssl_pkey_get_public($pubPem); if (!$pub) throw new UserError('کلیدِ عمومیِ سامانه نامعتبر است');
    $h = mb64u(json_encode(['alg' => 'RSA-OAEP-256', 'enc' => 'A256GCM', 'kid' => $kid], JSON_UNESCAPED_SLASHES));
    $cek = random_bytes(32); $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $cek, OPENSSL_RAW_DATA, $iv, $tag, $h, 16);
    return $h . '.' . mb64u(moadian_oaep_encrypt($cek, $pub)) . '.' . mb64u($iv) . '.' . mb64u((string)$ct) . '.' . mb64u($tag);
}
function moadian_jwe_decrypt(string $jwe, string $privPem): string { // برای آزمون
    [$h, $ek, $iv, $ct, $tag] = explode('.', $jwe);
    $cek = moadian_oaep_decrypt(mb64u_dec($ek), openssl_pkey_get_private($privPem));
    return (string)openssl_decrypt(mb64u_dec($ct), 'aes-256-gcm', $cek, OPENSSL_RAW_DATA, mb64u_dec($iv), mb64u_dec($tag), $h);
}

// ── صورتحساب
/** نگاشتِ واحد به کدِ سامانه (تنظیمات: «نام=کد» در هر خط) */
function moadian_unit_codes(): array { $o = []; foreach (preg_split('/\R/', (string)moadian_cfg('moadian_units')) as $ln) if (str_contains($ln, '=')) { [$a, $b] = array_map('trim', explode('=', $ln, 2)); if ($a !== '' && $b !== '') $o[$a] = en_num($b); } return $o; }
function moadian_sstid(array $it): string {
    $c = preg_replace('/\D/', '', en_num((string)($it['code'] ?? ''))); if (strlen($c) === 13) return $c;
    if (!empty($it['product_id'])) { $s = (string)val("SELECT sstid FROM products WHERE id=?", [(int)$it['product_id']]); if (preg_match('/^\d{13}$/', $s)) return $s; }
    $d = preg_replace('/\D/', '', (string)moadian_cfg('moadian_sstid_default')); return strlen($d) === 13 ? $d : '';
}
/** سریالِ داخلیِ یکتا = شناسهٔ سند؛ taxid یک‌بار ساخته و نگه داشته می‌شود */
function moadian_doc_tax_id(array $d): string {
    if (!empty($d['tax_id'])) return (string)$d['tax_id'];
    $t = moadian_tax_id(moadian_memory_id(), strtotime((string)($d['issued_at'] ?: $d['d'] . ' 12:00:00')), (int)$d['id']);
    upd('docs', ['tax_id' => $t], 'id=? AND tax_id IS NULL', [$d['id']]);
    return (string)val("SELECT tax_id FROM docs WHERE id=?", [$d['id']]);
}
/** ساختنِ صورتحسابِ سامانه از سندِ رسمی؛ [json, خطاها] */
function moadian_invoice(array $d): array {
    $E = []; $o = $d['opts']; $t = doc_calc($d); $b = $d['buyer'] ?? [];
    if ($d['kind'] !== 'official' || $d['status'] !== 'issued') $E[] = 'فقط صورتحسابِ رسمیِ صادرشده فرستاده می‌شود.';
    $E = array_merge($E, official_issues($d)['E']);
    if (!preg_match('/^[A-Z0-9]{6}$/', moadian_memory_id())) $E[] = 'شناسهٔ یکتای حافظهٔ مالیاتی (۶ نویسه) در پروفایلِ کسب‌وکار وارد نشده است.';
    $units = moadian_unit_codes(); $body = [];
    foreach ($d['lines'] as $i => $it) {
        $x = $t['off'][$i] ?? null; if (!$x) continue;
        $sst = moadian_sstid($it); if ($sst === '') $E[] = 'ردیفِ ' . fa_num($i + 1) . ': شناسهٔ ۱۳ رقمیِ کالا/خدمت ندارد (کدِ ردیف، کالا یا پیش‌فرض در تنظیمات).';
        $u = trim((string)($it['unit'] ?? '')) ?: 'عدد'; if (!isset($units[$u])) $E[] = 'ردیفِ ' . fa_num($i + 1) . ': کدِ سامانه برای واحدِ «' . $u . '» تعریف نشده (تنظیمات ← فاکتور و سند).';
        $vra = (!empty($t['vat_on']) && !empty($it['taxable'])) ? (float)$t['vat_pct'] : 0.0;
        $body[] = ['sstid' => $sst, 'sstt' => mb_substr((string)$it['name'], 0, 400), 'am' => (float)$it['qty'], 'mu' => $units[$u] ?? '', 'fee' => (int)$it['price'], 'cfee' => null, 'cut' => null, 'exr' => null,
            'prdis' => (int)$x['gross'], 'dis' => (int)$x['disc'], 'adis' => (int)$x['net'], 'vra' => $vra, 'vam' => (int)$x['tax'], 'odt' => null, 'odr' => null, 'odam' => null, 'olt' => null, 'olr' => null, 'olam' => null,
            'consfee' => null, 'spro' => null, 'bros' => null, 'tcpbs' => null, 'cop' => null, 'vop' => null, 'bsrn' => null, 'tsstam' => (int)$x['total']];
    }
    if (!$body) $E[] = 'سند ردیف ندارد.';
    $ins = ['main' => 1, 'correction' => 2, 'cancel' => 3, 'return' => 4][$d['subject'] ?: 'main'] ?? 1;
    $ref = $ins > 1 && $d['ref_doc_id'] ? doc_get((int)$d['ref_doc_id']) : null;
    if ($ins > 1 && empty($ref['tax_id'])) $E[] = 'صورتحسابِ مرجع هنوز شمارهٔ مالیاتی ندارد (اول آن را بفرستید).';
    if ($E) return [null, array_values(array_unique($E))];
    $sum = fn($k) => array_sum(array_column($body, $k));
    $tbill = $sum('tsstam'); $settle = $o['settle'] ?? 'cash'; $cap = $settle === 'credit' ? 0 : ($settle === 'both' ? min($tbill, (int)($o['cash_part'] ?? 0)) : $tbill);
    $type2 = ($o['off_kind'] ?? 'type1') === 'type2'; $digits = fn($v) => preg_replace('/\D/', '', en_num((string)$v)) ?: null;
    $s = doc_seller();
    $ms = strtotime((string)($d['issued_at'] ?: $d['d'] . ' 12:00:00')) * 1000;
    $header = ['taxid' => moadian_doc_tax_id($d), 'indatim' => $ms, 'indati2m' => $ms, 'inty' => $type2 ? 2 : 1, 'inno' => str_pad((string)$d['id'], 10, '0', STR_PAD_LEFT), 'irtaxid' => $ref['tax_id'] ?? null,
        'inp' => 1, 'ins' => $ins, 'tins' => $digits($s['economic_code'] ?? '') ?: $digits($s['national_id'] ?? ''),
        'tob' => ['natural' => 1, 'legal' => 2, 'civil' => 3, 'foreign' => 4][$b['kind'] ?? ''] ?? null, 'bid' => $digits($b['national_id'] ?? ''), 'tinb' => $digits($b['economic_code'] ?? ''), 'sbc' => null,
        'bpc' => $digits($b['postal_code'] ?? ''), 'bbc' => null, 'ft' => null, 'bpn' => null, 'scln' => null, 'scc' => null, 'crn' => null, 'billid' => null,
        'tprdis' => $sum('prdis'), 'tdis' => $sum('dis'), 'tadis' => $sum('adis'), 'tvam' => $sum('vam'), 'todam' => 0, 'tbill' => $tbill,
        'setm' => ['cash' => 1, 'credit' => 2, 'both' => 3][$settle] ?? 1, 'cap' => $cap, 'insp' => $tbill - $cap, 'tvop' => null, 'tax17' => null];
    if ($type2) foreach (['tob', 'bid', 'tinb', 'bpc'] as $k) if (!$header[$k]) $header[$k] = null;
    return [['header' => $header, 'body' => $body, 'payments' => [], 'extension' => null], []];
}

// ── شبکه (فقط با دکمه)
function moadian_keys(): array {
    $pk = (string)setting('moadian_secret_key', ''); $cert = (string)setting('moadian_cert', '');
    if (!$pk || !$cert) throw new UserError('کلیدِ خصوصی و گواهیِ سامانهٔ مودیان در تنظیمات وارد نشده است');
    return [$pk, $cert];
}
function moadian_http(string $method, string $path, $body = null, ?string $token = null, ?int $docId = null): array {
    $url = rtrim((string)moadian_cfg('moadian_base'), '/') . '/' . ltrim($path, '/');
    $tr = bin2hex(random_bytes(16)); $h = ['Accept: application/json', 'requestTraceId: ' . $tr, 'timestamp: ' . (int)(microtime(true) * 1000)];
    if ($token) $h[] = 'Authorization: Bearer ' . $token;
    $ch = curl_init($url); $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, $json); }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => (int)moadian_cfg('moadian_timeout'), CURLOPT_CONNECTTIMEOUT => 8]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    ins('moadian_log', ['doc_id' => $docId, 'action' => mb_cut($method . ' ' . strtok($path, '?'), 20), 'http' => $code, 'request' => $json !== null ? mb_cut($json, 60000) : $path, 'response' => mb_cut($res === false ? $err : (string)$res, 60000), 'at' => now()]);
    if ($res === false) throw new UserError('سامانهٔ مودیان پاسخ نداد: ' . $err);
    $j = json_decode((string)$res, true);
    if ($code >= 400) throw new UserError('خطای سامانهٔ مودیان (' . $code . '): ' . mb_cut(is_array($j) ? (string)json_encode($j['errors'] ?? $j['message'] ?? $j, JSON_UNESCAPED_UNICODE) : (string)$res, 300));
    return is_array($j) ? $j : [];
}
function moadian_token(): string {
    [$pk, $cert] = moadian_keys();
    $n = moadian_http('GET', 'nonce?timeToLive=20'); if (empty($n['nonce'])) throw new UserError('nonce از سامانه نیامد');
    return moadian_jws(json_encode(['nonce' => $n['nonce'], 'clientId' => moadian_memory_id()], JSON_UNESCAPED_SLASHES), $pk, $cert);
}
/** کلیدِ عمومیِ سامانه (یک روز نگه داشته می‌شود) */
function moadian_server_key(string $token): array {
    $c = setting_json('moadian_srv_key', []);
    if (!empty($c['key']) && (int)($c['at'] ?? 0) > time() - 86400) return $c;
    $r = moadian_http('GET', 'server-information', null, $token); $k = $r['publicKeys'][0] ?? null;
    if (!$k || empty($k['key'])) throw new UserError('کلیدِ عمومیِ سامانه دریافت نشد');
    $c = ['key' => moadian_pem((string)$k['key']), 'id' => (string)$k['id'], 'at' => time()]; settings_save(['moadian_srv_key' => $c], false);
    return $c;
}
/** ارسال؛ برمی‌گرداند شمارهٔ مرجع */
function moadian_send(int $id): string {
    if (!moadian_on()) throw new UserError('ارسال به سامانهٔ مودیان در تنظیمات خاموش است');
    $d = doc_get($id); if (!$d) throw new UserError('سند یافت نشد');
    if (in_array($d['moadian'], ['sent', 'accepted'], true)) throw new UserError('این صورتحساب قبلاً فرستاده شده است؛ «استعلام» بزنید');
    [$inv, $E] = moadian_invoice($d); if ($E) throw new UserError(implode(' ', $E));
    [$pk, $cert] = moadian_keys(); $tok = moadian_token(); $srv = moadian_server_key($tok);
    $packet = moadian_jwe(moadian_jws(json_encode($inv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $pk, $cert, 'text/plain'), $srv['key'], $srv['id']);
    $r = moadian_http('POST', 'invoice', [['payload' => $packet, 'header' => ['requestTraceId' => bin2hex(random_bytes(16)), 'fiscalId' => moadian_memory_id()]]], $tok, $id);
    $res = $r['result'][0] ?? null;
    if (!$res || empty($res['referenceNumber'])) { $m = (string)json_encode($res['errorDetail'] ?? $res ?? $r, JSON_UNESCAPED_UNICODE); upd('docs', ['moadian' => 'rejected', 'moadian_msg' => mb_cut($m, 2000), 'moadian_at' => now()], 'id=?', [$id]); throw new UserError('سامانه نپذیرفت: ' . mb_cut($m, 300)); }
    upd('docs', ['moadian' => 'sent', 'moadian_ref' => (string)$res['referenceNumber'], 'moadian_at' => now(), 'moadian_msg' => null], 'id=?', [$id]);
    $o = $d['opts']; $o['tax_id'] = $inv['header']['taxid']; upd('docs', ['opts' => json_encode($o, JSON_UNESCAPED_UNICODE)], 'id=?', [$id]); vf_snapshot($id);
    audit('docs', $id, 'moadian_send', null, ['ref' => $res['referenceNumber'], 'taxid' => $inv['header']['taxid']], 'ارسال به سامانهٔ مودیان');
    return (string)$res['referenceNumber'];
}
/** استعلامِ نتیجه؛ برمی‌گرداند وضعیتِ تازه */
function moadian_inquiry(int $id): string {
    $d = doc_get($id); if (!$d || !$d['moadian_ref']) throw new UserError('این صورتحساب هنوز فرستاده نشده است');
    $r = moadian_http('GET', 'inquiry-by-reference-id?referenceIds=' . rawurlencode((string)$d['moadian_ref']), null, moadian_token(), $id);
    $x = $r[0] ?? ($r['result'][0] ?? ($r['data'][0] ?? null)); $st = strtoupper((string)($x['status'] ?? ''));
    $msgs = []; foreach (['error' => 'خطا', 'warning' => 'هشدار'] as $k => $t) foreach ((array)($x['data'][$k] ?? []) as $e) $msgs[] = $t . ' ' . ($e['code'] ?? '') . ': ' . ($e['message'] ?? '');
    $new = $st === 'SUCCESS' ? 'accepted' : ($st === 'FAILED' ? 'rejected' : 'sent');
    upd('docs', ['moadian' => $new, 'moadian_msg' => $msgs ? mb_cut(implode("\n", $msgs), 2000) : ($new === 'sent' ? 'در صفِ پردازشِ سامانه (' . $st . ')' : null), 'moadian_at' => now()], 'id=?', [$id]);
    if ($new !== $d['moadian']) { vf_snapshot($id); audit('docs', $id, 'moadian', ['state' => $d['moadian']], ['state' => $new], 'نتیجهٔ سامانهٔ مودیان'); }
    return $new;
}
