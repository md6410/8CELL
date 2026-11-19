<?php
// کپچا: سه بارِ اول بی‌کپچا؛ پس از آن تصویرِ عددی (GD)
declare(strict_types=1);

function cap_key(string $who): string { return 'otp:' . client_ip() . ':' . $who; }
function df_cap_count(string $who): int { return rate_count(cap_key($who), 1800) + rate_count('otpip:' . client_ip(), 1800); }
function df_cap_needed(string $who): bool { return df_cap_count($who) >= (int)setting('captcha_after', 3); }
function df_cap_new(): string { $c = (string)random_int(10000, 99999); $_SESSION['cap'] = ['c' => $c, 't' => time()]; return $c; }
function df_cap_check($answer): bool {
    $s = $_SESSION['cap'] ?? null; unset($_SESSION['cap']);
    return $s && time() - $s['t'] < 300 && hash_equals($s['c'], preg_replace('/\D/', '', en_num((string)$answer)));
}
function df_cap_image(): void {
    $code = df_cap_new();
    header('Content-Type: image/png'); header('Cache-Control: no-store');
    if (!function_exists('imagecreatetruecolor')) { echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII='); return; }
    $im = imagecreatetruecolor(130, 44); $bg = imagecolorallocate($im, 245, 247, 250); imagefill($im, 0, 0, $bg);
    for ($i = 0; $i < 6; $i++) { $c = imagecolorallocate($im, random_int(150, 220), random_int(150, 220), random_int(150, 220)); imageline($im, random_int(0, 130), random_int(0, 44), random_int(0, 130), random_int(0, 44), $c); }
    for ($i = 0; $i < strlen($code); $i++) { $c = imagecolorallocate($im, random_int(0, 90), random_int(0, 90), random_int(60, 140)); imagestring($im, 5, 14 + $i * 22 + random_int(-2, 2), 12 + random_int(-5, 5), $code[$i], $c); }
    imagepng($im); imagedestroy($im);
}
