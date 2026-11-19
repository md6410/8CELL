<?php
// 8CELL — توابعِ عمومی: ارقام، پول، خروجی، خطا، CSRF، HTTP
declare(strict_types=1);

/** خطایی که پیامش برای کاربر است (فارسی) */
class UserError extends RuntimeException {
    public int $http;
    public function __construct(string $msg, int $http = 400) { parent::__construct($msg); $this->http = $http; }
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** ارقامِ فارسی/عربی → لاتین */
function en_num($s): string {
    return strtr((string)$s, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٬'=>',','٫'=>'.']);
}
/** ارقامِ لاتین → فارسی (برای نمایش) */
function fa_num($s): string { return strtr((string)$s, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); }

/** حذفِ نویسه‌های جهت‌دهی و فاصله‌های اضافه */
function bidi_strip(string $s): string { return trim(preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $s)); }

/** عددِ صحیح از ورودیِ کاربر (ارقامِ فارسی، جداکننده) */
function to_int($v): int {
    $s = preg_replace('/[^0-9\-]/', '', en_num(bidi_strip((string)$v)));
    return ($s === '' || $s === '-') ? 0 : (int)$s;
}
/** درصد (اعشار مجاز) */
function to_float($v): float { $s = preg_replace('/[^0-9\.\-]/', '', en_num((string)$v)); return $s === '' ? 0.0 : (float)$s; }

/** جداکنندهٔ هزارگان (۱٬۲۵۰٬۰۰۰) */
function num_fmt($n): string { $n = (int)$n; $s = number_format(abs($n), 0, '.', '٬'); return ($n < 0 ? '−' : '') . $s; }
function fa_money($n): string { return fa_num(num_fmt($n)); }
function fa_rial($n): string { return fa_money($n) . ' ریال'; }

/** موبایلِ یکسان: 09xxxxxxxxx */
function norm_mobile($m): string {
    $s = preg_replace('/\D/', '', en_num(bidi_strip((string)$m)));
    if (str_starts_with($s, '0098')) $s = '0' . substr($s, 4);
    elseif (str_starts_with($s, '98') && strlen($s) == 12) $s = '0' . substr($s, 2);
    elseif (strlen($s) == 10 && $s[0] == '9') $s = '0' . $s;
    return $s;
}
function mobile_ok(string $m): bool { return (bool)preg_match('/^09\d{9}$/', $m); }

/** کدِ ملیِ ۱۰ رقمی */
function national_code_ok($c): bool {
    $c = preg_replace('/\D/', '', en_num((string)$c));
    if (!preg_match('/^\d{10}$/', $c) || preg_match('/^(\d)\1{9}$/', $c)) return false;
    $s = 0; for ($i = 0; $i < 9; $i++) $s += (int)$c[$i] * (10 - $i);
    $r = $s % 11; $chk = (int)$c[9];
    return ($r < 2 && $chk == $r) || ($r >= 2 && $chk == 11 - $r);
}
/** شناسهٔ ملیِ اشخاصِ حقوقی (۱۱ رقم) */
function legal_id_ok($c): bool {
    $c = preg_replace('/\D/', '', en_num((string)$c));
    if (!preg_match('/^\d{11}$/', $c) || preg_match('/^(\d)\1{10}$/', $c)) return false;
    $d = (int)$c[9] + 2; $coef = [29, 27, 23, 19, 17, 29, 27, 23, 19, 17]; $s = 0;
    for ($i = 0; $i < 10; $i++) $s += ((int)$c[$i] + $d) * $coef[$i];
    $r = $s % 11; if ($r == 10) $r = 0;
    return $r == (int)$c[10];
}
/** شبا: IR + ۲۴ رقم با mod 97 */
function iban_ok($s): bool {
    $s = strtoupper(preg_replace('/\s/', '', en_num((string)$s)));
    if (!preg_match('/^IR\d{24}$/', $s)) return false;
    if (preg_match('/^IR0+$/', $s)) return false;
    $r = substr($s, 4) . '1827' . substr($s, 2, 2); // I=18 R=27
    $m = 0; foreach (str_split($r, 7) as $p) $m = (int)(($m . $p)) % 97;
    return $m == 1;
}

function now(): string { return date('Y-m-d H:i:s'); }
function today(): string { return date('Y-m-d'); }

function json_out($data, int $code = 200): void {
    if (!headers_sent()) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) return '';
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    $t = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['_csrf'] ?? '');
    if (!$t || !hash_equals(csrf_token(), (string)$t)) throw new UserError('نشست منقضی شده است؛ صفحه را تازه کنید.', 419);
}

function req($k, $d = '') { return $_POST[$k] ?? $_GET[$k] ?? $d; }
function req_int($k, $d = 0): int { $v = req($k, null); return $v === null || $v === '' ? $d : to_int($v); }

/** HTTP ساده با cURL؛ هرگز در مسیرِ کاربر بدونِ timeout */
function http_req(string $method, string $url, $body = null, array $headers = [], int $timeout = 10): array {
    if (!function_exists('curl_init')) return ['ok' => false, 'code' => 0, 'body' => '', 'error' => 'cURL در دسترس نیست'];
    $ch = curl_init($url);
    $hs = [];
    foreach ($headers as $k => $v) $hs[] = is_int($k) ? $v : "$k: $v";
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_HTTPHEADER => $hs,
        CURLOPT_USERAGENT => '8CELL/' . APP_VERSION, CURLOPT_CUSTOMREQUEST => $method]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : $body);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok' => $out !== false && $code >= 200 && $code < 300, 'code' => $code, 'body' => $out === false ? '' : (string)$out, 'error' => $err];
}

function client_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'); }

/** برش امنِ UTF-8 */
function mb_cut(string $s, int $n): string { return mb_strlen($s) > $n ? mb_substr($s, 0, $n) : $s; }

/** نامِ نوعِ رکورد برای پیام‌ها */
function app_url(string $path = ''): string {
    $base = defined('BASE_URL') && BASE_URL ? BASE_URL : ((isset($_SERVER['HTTP_HOST']) ? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') : ''));
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

/** عدد به حروفِ فارسی */
function num_words(int $n): string {
    if ($n == 0) return 'صفر';
    if ($n < 0) return 'منفی ' . num_words(-$n);
    $ones = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
    $teens = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
    $tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    $hund = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
    $scale = ['', ' هزار', ' میلیون', ' میلیارد', ' هزار میلیارد'];
    $three = function (int $x) use ($ones, $teens, $tens, $hund): string {
        $p = []; $h = intdiv($x, 100); $r = $x % 100;
        if ($h) $p[] = $hund[$h];
        if ($r >= 10 && $r < 20) $p[] = $teens[$r - 10];
        else { if (intdiv($r, 10)) $p[] = $tens[intdiv($r, 10)]; if ($r % 10) $p[] = $ones[$r % 10]; }
        return implode(' و ', $p);
    };
    $parts = []; $i = 0;
    while ($n > 0) { $c = $n % 1000; if ($c) array_unshift($parts, $three($c) . $scale[$i]); $n = intdiv($n, 1000); $i++; }
    return implode(' و ', $parts);
}
function money_words(int $n): string { return num_words($n) . ' ریال'; }

/** گردکردنِ مالیات/مبلغ طبقِ قاعده */
function round_by(float $v, string $mode = 'floor'): int {
    if ($mode === 'round') return (int)round($v);
    if ($mode === 'ceil') return (int)ceil($v - 1e-9);
    return (int)floor($v + 1e-9);
}
