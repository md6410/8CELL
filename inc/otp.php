<?php
// کدِ یک‌بارمصرف (OTP) با WebOTP و محدودیتِ هدف
declare(strict_types=1);

function otp_purposes(): array { return ['panel' => 'پنلِ مدیریت', 'staff' => 'کارکنان', 'confirm' => 'تأییدِ عملیات']; }
function otp_ttl(): int { return max(60, (int)setting('sms_otp_ttl', 180)); }
function otp_cooldown(): int { return max(30, (int)setting('sms_otp_cooldown', 90)); }
function otp_cooldown_left(string $mobile, string $purpose): int {
    $last = val("SELECT created_at FROM otp_codes WHERE mobile=? AND purpose=? ORDER BY id DESC LIMIT 1", [$mobile, $purpose]);
    return $last ? max(0, otp_cooldown() - (time() - strtotime($last))) : 0;
}
function otp_webotp_host(): string { $h = trim((string)setting('sms_otp_domain', '')) ?: (string)parse_url(app_url(), PHP_URL_HOST); $h = ltrim(preg_replace('~^https?://~i', '', $h), '@/'); return trim(explode('/', $h)[0]) ?: 'localhost'; }
/** WebOTP اندروید: خطِ آخرِ الگو «@دامنه #VERIFICATIONCODE#»؛ دامنه ثابت در الگوست و این پارامتر «#12345» است (دو # پشتِ هم الگو را خراب می‌کند) */
function otp_webotp_suffix(string $code): string { return '#' . $code; }

/** تولید و ارسال؛ اگر پیامک نرفت ردیف برمی‌گردد */
function otp_generate(string $mobile, string $purpose): array {
    $mobile = norm_mobile($mobile);
    if (!mobile_ok($mobile)) throw new UserError('شمارهٔ موبایل معتبر نیست');
    if (!isset(otp_purposes()[$purpose])) throw new UserError('هدفِ نامعتبر');
    $left = otp_cooldown_left($mobile, $purpose);
    if ($left > 0) throw new UserError('برای کدِ تازه ' . fa_num($left) . ' ثانیه صبر کنید', 429);
    $code = (string)random_int(10000, 99999);
    q("UPDATE otp_codes SET used_at=? WHERE mobile=? AND used_at IS NULL", [now(), $mobile]);
    $id = ins('otp_codes', ['mobile' => $mobile, 'purpose' => $purpose, 'code_hash' => password_hash($code, PASSWORD_BCRYPT, ['cost' => 8]),
        'expires_at' => date('Y-m-d H:i:s', time() + otp_ttl()), 'created_at' => now()]);
    $name = (string)(val("SELECT name FROM users WHERE mobile=?", [$mobile]) ?: val("SELECT name FROM customers WHERE mobile=?", [$mobile]) ?: '');
    $res = sms_send_tpl($mobile, 'otp', ['NAME' => mb_cut($name, 24), 'CODE' => $code, 'VERIFICATIONCODE' => otp_webotp_suffix($code)], 'otp', ['ref_type' => 'otp', 'ref_id' => $id]);
    if (!$res['ok']) { q("DELETE FROM otp_codes WHERE id=?", [$id]); throw new UserError('ارسالِ پیامک از سمتِ ما انجام نشد (' . $res['msg'] . '). همین حالا می‌توانید دوباره تلاش کنید.'); }
    $out = ['id' => $id, 'expires_in' => otp_ttl()];
    if (sms_test_mode()) $out['test_code'] = $code; // فقط در حالتِ آزمایشی
    return $out;
}
/** سنجش: یک‌بارمصرف، محدودِ هدف، ۵ تلاش */
function otp_check(string $mobile, string $code, string $purpose): bool {
    $mobile = norm_mobile($mobile); $code = preg_replace('/\D/', '', en_num($code));
    $r = row("SELECT * FROM otp_codes WHERE mobile=? AND purpose=? AND used_at IS NULL AND expires_at>=? ORDER BY id DESC LIMIT 1", [$mobile, $purpose, now()]);
    if (!$r) return false;
    if ((int)$r['tries'] >= 5) { upd('otp_codes', ['used_at' => now()], 'id=?', [$r['id']]); return false; }
    q("UPDATE otp_codes SET tries=tries+1 WHERE id=?", [$r['id']]);
    if (!password_verify($code, $r['code_hash'])) return false;
    upd('otp_codes', ['used_at' => now()], 'id=?', [$r['id']]);
    return true;
}
/** درخواستِ کدِ ورود با کپچا پس از سه بار */
function otp_login_request(string $mobile, string $captcha = ''): array {
    $mobile = norm_mobile($mobile);
    if (df_cap_needed($mobile)) {
        if ($captcha === '') return ['need_captcha' => true];
        if (!df_cap_check($captcha)) return ['need_captcha' => true, 'error' => 'کدِ تصویر درست نیست'];
    }
    rate_hit(cap_key($mobile)); rate_hit('otpip:' . client_ip());
    $u = row("SELECT id FROM users WHERE mobile=? AND active=1", [$mobile]);
    if (!$u) return ['sent' => true, 'expires_in' => otp_ttl()]; // نشتِ اطلاعات نه
    return ['sent' => true] + otp_generate($mobile, 'panel');
}
function otp_login_verify(string $mobile, string $code): array {
    $mobile = norm_mobile($mobile);
    if (!otp_check($mobile, $code, 'panel')) throw new UserError('کد نادرست یا منقضی است', 401);
    $u = row("SELECT id FROM users WHERE mobile=? AND active=1", [$mobile]);
    if (!$u) throw new UserError('کاربری با این شماره نیست', 401);
    login_as((int)$u['id']);
    return current_user();
}
