<?php
// تقویمِ جلالی بدونِ کتابخانه؛ نمایش جلالی، ذخیره میلادی
declare(strict_types=1);

function g2j(int $gy, int $gm, int $gd): array {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053)); $days %= 12053;
    $jy += 4 * intdiv($days, 1461); $days %= 1461;
    if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
    if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + ($days % 31); }
    else { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + (($days - 186) % 30); }
    return [$jy, $jm, $jd];
}
function j2g(int $jy, int $jm, int $jd): array {
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * intdiv($days, 146097); $days %= 146097;
    if ($days > 36524) { $gy += 100 * intdiv(--$days, 36524); $days %= 36524; if ($days >= 365) $days++; }
    $gy += 4 * intdiv($days, 1461); $days %= 1461;
    if ($days > 365) { $gy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
    $gd = $days + 1;
    $sal = [0, 31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for ($gm = 1; $gm < 13 && $gd > $sal[$gm]; $gm++) $gd -= $sal[$gm];
    return [$gy, $gm, $gd];
}
function jalali_month_days(int $jy, int $jm): int {
    if ($jm <= 6) return 31; if ($jm <= 11) return 30;
    [$gy, $gm, $gd] = j2g($jy + 1, 1, 1); $t = mktime(0, 0, 0, $gm, $gd, $gy) - 86400;
    return (int)g2j((int)date('Y', $t), (int)date('n', $t), (int)date('j', $t))[2];
}
function jalali_months(): array { return ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند']; }

/** میلادی (Y-m-d [H:i:s]) → جلالی «۱۴۰۵/۰۸/۲۸» */
function jdate(?string $g, bool $time = false, bool $fa = true): string {
    if (!$g || str_starts_with($g, '0000')) return '';
    $t = strtotime($g); if ($t === false) return '';
    [$y, $m, $d] = g2j((int)date('Y', $t), (int)date('n', $t), (int)date('j', $t));
    $s = sprintf('%04d/%02d/%02d', $y, $m, $d) . ($time ? ' ' . date('H:i', $t) : '');
    return $fa ? fa_num($s) : $s;
}
/** «۱۴۰۵/۰۷» */
function jym(?string $g): string { $j = jdate($g, false, false); return $j ? substr($j, 0, 7) : ''; }
/** ورودیِ جلالی (۱۴۰۵/۰۸/۲۸ یا 1405-8-28) → میلادی Y-m-d ؛ اگر ورودی خودش میلادی بود همان */
function jparse($s): ?string {
    $s = trim(en_num(bidi_strip((string)$s)));
    if ($s === '') return null;
    if (!preg_match('/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})/', $s, $m)) return null;
    $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
    if ($y > 1700) return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    if ($mo < 1 || $mo > 12 || $d < 1 || $d > jalali_month_days($y, $mo)) return null;
    [$gy, $gm, $gd] = j2g($y, $mo, $d);
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}
/** آغاز و پایانِ ماهِ جلالی «1405-07» به میلادی */
function jmonth_range(string $ym): array {
    [$y, $m] = array_map('intval', preg_split('/[\/\-]/', en_num($ym)));
    [$a, $b, $c] = j2g($y, $m, 1); [$x, $yy, $z] = j2g($y, $m, jalali_month_days($y, $m));
    return [sprintf('%04d-%02d-%02d', $a, $b, $c), sprintf('%04d-%02d-%02d', $x, $yy, $z)];
}
function jtoday(): string { return jdate(today(), false, false); }
/** روزِ هفته: ۰=شنبه … ۶=جمعه */
function jweekday(string $g): int { return ((int)date('w', strtotime($g)) + 1) % 7; }
function jweekday_name(string $g): string { return ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'][jweekday($g)]; }
