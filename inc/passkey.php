<?php
// ورودِ بی‌رمز با Passkey (WebAuthn) بدونِ کتابخانهٔ بیرونی؛ فقط روی «دستگاهِ مورد اعتماد»
declare(strict_types=1);

function b64u_enc(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** رمزگشای کوچکِ CBOR (برای attestationObject و کلیدِ COSE) */
function cbor_decode(string $d, int &$o = 0) {
    $ib = ord($d[$o++]); $mt = $ib >> 5; $ai = $ib & 31;
    $len = function () use ($d, &$o, $ai) {
        if ($ai < 24) return $ai;
        $n = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$ai] ?? 0; if (!$n) throw new RuntimeException('cbor');
        $v = 0; for ($i = 0; $i < $n; $i++) $v = ($v << 8) | ord($d[$o++]); return $v;
    };
    switch ($mt) {
        case 0: return $len();
        case 1: return -1 - $len();
        case 2: case 3: $l = $len(); $s = substr($d, $o, $l); $o += $l; return $s;
        case 4: $l = $len(); $a = []; for ($i = 0; $i < $l; $i++) $a[] = cbor_decode($d, $o); return $a;
        case 5: $l = $len(); $m = []; for ($i = 0; $i < $l; $i++) { $k = cbor_decode($d, $o); $m[$k] = cbor_decode($d, $o); } return $m;
        case 7: return [20 => false, 21 => true, 22 => null][$ai] ?? null;
    }
    throw new RuntimeException('cbor type');
}
function der_len(int $l): string { if ($l < 128) return chr($l); $s = ''; while ($l > 0) { $s = chr($l & 255) . $s; $l >>= 8; } return chr(0x80 | strlen($s)) . $s; }
function der(int $tag, string $c): string { return chr($tag) . der_len(strlen($c)) . $c; }
function der_int(string $b): string { $b = ltrim($b, "\0"); if ($b === '' || ord($b[0]) & 0x80) $b = "\0" . $b; return der(0x02, $b); }
/** کلیدِ COSE → PEM */
function cose_to_pem(array $k): array {
    if (($k[1] ?? 0) == 2) { // EC2 P-256
        if ((int)($k[3] ?? 0) !== -7 || (int)($k[-1] ?? 0) !== 1) throw new UserError('الگوریتمِ کلیدِ این دستگاه پشتیبانی نمی‌شود');
        $spki = der(0x30, der(0x30, der(0x06, hex2bin('2a8648ce3d0201')) . der(0x06, hex2bin('2a8648ce3d030107'))) . der(0x03, "\0\x04" . $k[-2] . $k[-3]));
        $alg = -7;
    } elseif (($k[1] ?? 0) == 3) { // RSA
        if ((int)($k[3] ?? 0) !== -257) throw new UserError('الگوریتمِ کلیدِ این دستگاه پشتیبانی نمی‌شود');
        $spki = der(0x30, der(0x30, der(0x06, hex2bin('2a864886f70d010101')) . der(0x05, '')) . der(0x03, "\0" . der(0x30, der_int($k[-1]) . der_int($k[-2]))));
        $alg = -257;
    } else throw new UserError('نوعِ کلیدِ این دستگاه پشتیبانی نمی‌شود');
    return ["-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n", $alg];
}
function pk_rp_id(): string { return strtolower((string)(parse_url(app_url(), PHP_URL_HOST) ?: 'localhost')); }
/** مبدأهای پذیرفته: دقیقاً همین سایت با https (www و بی‌www هر دو؛ مرورگر مبدأ را عینِ نوارِ نشانی می‌گذارد) — از Weby */
function pk_origins(): array {
    $rp = pk_rp_id(); $hosts = [$rp, str_starts_with($rp, 'www.') ? substr($rp, 4) : 'www.' . $rp];
    $o = array_map(fn($h) => 'https://' . $h, $hosts);
    if ($rp === 'localhost' || $rp === '127.0.0.1') $o[] = 'http://' . $rp . ((string)parse_url(app_url(), PHP_URL_PORT) !== '' ? ':' . parse_url(app_url(), PHP_URL_PORT) : '');
    return $o;
}
function pk_origin_ok(string $origin): bool { return in_array(rtrim($origin, '/'), pk_origins(), true); }

/** کوکیِ دستگاه */
function df_dev_current(): ?array {
    $t = $_COOKIE['c8dev'] ?? ''; if (!$t) return null;
    return row("SELECT * FROM devices WHERE token_hash=?", [hash('sha256', $t)]);
}
function df_dev_ensure(int $uid): int {
    $d = df_dev_current();
    if ($d && (int)$d['user_id'] === $uid) return (int)$d['id'];
    $t = bin2hex(random_bytes(24));
    $id = ins('devices', ['user_id' => $uid, 'token_hash' => hash('sha256', $t), 'name' => mb_cut((string)($_SERVER['HTTP_USER_AGENT'] ?? 'دستگاه'), 160), 'created_at' => now()]);
    if (!headers_sent()) setcookie('c8dev', $t, ['expires' => time() + 86400 * 400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    $_COOKIE['c8dev'] = $t;
    return $id;
}
function df_dev_remove(int $passkeyId): void {
    $u = require_login();
    $p = row("SELECT * FROM passkeys WHERE id=?", [$passkeyId]);
    if (!$p || ((int)$p['user_id'] !== (int)$u['id'] && !can('users.edit'))) throw new UserError('یافت نشد', 404);
    q("DELETE FROM passkeys WHERE id=?", [$passkeyId]);
    if ($p['device_id']) q("DELETE FROM devices WHERE id=?", [$p['device_id']]);
    audit('passkeys', $passkeyId, 'delete', ['name' => $p['name']], null, 'حذفِ passkey');
}

function pk_reg_options(): array {
    $u = require_login();
    $ch = random_bytes(32); $_SESSION['pk_reg'] = b64u_enc($ch);
    $ex = array_map(fn($r) => ['type' => 'public-key', 'id' => $r['cred_id']], rows("SELECT cred_id FROM passkeys WHERE user_id=?", [$u['id']]));
    return ['challenge' => b64u_enc($ch), 'rp' => ['id' => pk_rp_id(), 'name' => setting('biz_name', '8CELL')],
        'user' => ['id' => b64u_enc('u' . $u['id']), 'name' => $u['username'], 'displayName' => $u['name']],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
        'authenticatorSelection' => ['userVerification' => 'required', 'residentKey' => 'preferred'], 'attestation' => 'none', 'timeout' => 60000, 'excludeCredentials' => $ex];
}
function pk_parse_authdata(string $ad): array {
    if (strlen($ad) < 37) throw new UserError('دادهٔ دستگاه ناقص است');
    $r = ['rpIdHash' => substr($ad, 0, 32), 'flags' => ord($ad[32]), 'count' => unpack('N', substr($ad, 33, 4))[1]];
    if ($r['flags'] & 0x40) {
        $l = unpack('n', substr($ad, 53, 2))[1];
        $r['credId'] = substr($ad, 55, $l); $o = 0; $cose = substr($ad, 55 + $l);
        $r['cose'] = cbor_decode($cose, $o);
    }
    return $r;
}
function pk_reg_verify(array $in): int {
    $u = require_login();
    $chal = $_SESSION['pk_reg'] ?? ''; unset($_SESSION['pk_reg']);
    $cd = json_decode(b64u_dec((string)($in['clientDataJSON'] ?? '')), true);
    if (!$chal || !$cd || ($cd['type'] ?? '') !== 'webauthn.create' || !hash_equals($chal, (string)($cd['challenge'] ?? '')) || !pk_origin_ok((string)($cd['origin'] ?? '')))
        throw new UserError('پاسخِ دستگاه معتبر نیست');
    $o = 0; $att = cbor_decode(b64u_dec((string)$in['attestationObject']), $o);
    $ad = pk_parse_authdata((string)($att['authData'] ?? ''));
    if (!hash_equals(hash('sha256', pk_rp_id(), true), $ad['rpIdHash'])) throw new UserError('دامنهٔ passkey با سایت یکی نیست');
    if (!($ad['flags'] & 0x01) || !($ad['flags'] & 0x04)) throw new UserError('اثرِ انگشت/چهره تأیید نشد');
    if (empty($ad['credId'])) throw new UserError('کلیدی از دستگاه نیامد');
    [$pem, $alg] = cose_to_pem($ad['cose']);
    $dev = df_dev_ensure((int)$u['id']);
    $id = ins('passkeys', ['user_id' => $u['id'], 'cred_id' => b64u_enc($ad['credId']), 'pubkey' => $pem, 'alg' => $alg, 'sign_count' => $ad['count'],
        'name' => mb_cut(trim((string)($in['name'] ?? '')) ?: 'دستگاهِ من', 120), 'device_id' => $dev, 'created_at' => now()]);
    audit('passkeys', $id, 'create', null, ['user' => $u['username']], 'ثبتِ passkey');
    return $id;
}
function pk_login_options(): array {
    $ch = random_bytes(32); $_SESSION['pk_login'] = b64u_enc($ch);
    $dev = df_dev_current();
    $allow = $dev ? array_map(fn($r) => ['type' => 'public-key', 'id' => $r['cred_id']], rows("SELECT cred_id FROM passkeys WHERE device_id=?", [$dev['id']])) : [];
    return ['challenge' => b64u_enc($ch), 'rpId' => pk_rp_id(), 'userVerification' => 'required', 'timeout' => 60000, 'allowCredentials' => $allow, 'trusted' => (bool)$allow];
}
function pk_login_verify(array $in): array {
    $chal = $_SESSION['pk_login'] ?? ''; unset($_SESSION['pk_login']);
    $p = row("SELECT * FROM passkeys WHERE cred_id=?", [(string)($in['id'] ?? '')]);
    if (!$p) throw new UserError('این passkey شناخته نشد', 401);
    $dev = df_dev_current();
    if (!$dev || (int)$dev['id'] !== (int)$p['device_id']) throw new UserError('این دستگاه مورد اعتماد نیست؛ یک بار با کدِ پیامکی وارد شوید', 401);
    $cdRaw = b64u_dec((string)($in['clientDataJSON'] ?? '')); $cd = json_decode($cdRaw, true);
    if (!$chal || !$cd || ($cd['type'] ?? '') !== 'webauthn.get' || !hash_equals($chal, (string)($cd['challenge'] ?? '')) || !pk_origin_ok((string)($cd['origin'] ?? '')))
        throw new UserError('پاسخِ دستگاه معتبر نیست', 401);
    $adRaw = b64u_dec((string)($in['authenticatorData'] ?? '')); $ad = pk_parse_authdata($adRaw);
    if (!hash_equals(hash('sha256', pk_rp_id(), true), $ad['rpIdHash']) || !($ad['flags'] & 0x04)) throw new UserError('تأییدِ اثرِ انگشت/چهره انجام نشد', 401);
    $ok = openssl_verify($adRaw . hash('sha256', $cdRaw, true), b64u_dec((string)($in['signature'] ?? '')), $p['pubkey'], OPENSSL_ALGO_SHA256);
    if ($ok !== 1) throw new UserError('امضای دستگاه نادرست است', 401);
    // شمارندهٔ عقب‌رفته = کلیدِ کپی‌شده؛ آیفون و کلیدهای همگام‌شونده همیشه صفر می‌فرستند (آن نشانهٔ چیزی نیست)
    $was = (int)$p['sign_count']; $nowc = (int)$ad['count'];
    if ($was > 0 && $nowc > 0 && $nowc <= $was) throw new UserError('این کلید معتبر نیست؛ با پیامک وارد شوید و دستگاه را دوباره ثبت کنید', 401);
    if (!(int)val("SELECT active FROM users WHERE id=?", [$p['user_id']])) throw new UserError('این حساب غیرفعال است', 401);
    upd('passkeys', ['sign_count' => max($was, $nowc), 'last_used' => now()], 'id=?', [$p['id']]);
    upd('devices', ['last_seen' => now()], 'id=?', [$dev['id']]);
    login_as((int)$p['user_id']);
    return current_user() ?? [];
}
