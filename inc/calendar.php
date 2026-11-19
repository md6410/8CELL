<?php
// تقویم: ساعتِ تهران، تعطیلات، جابه‌جاییِ تاریخ به روزِ کاری، نرخِ دلار
declare(strict_types=1);

/** ساعتِ سرور به وقتِ تهران + اختلافِ ساعتِ مرورگر (برای جبران در کلاینت) */
function tehran_now(?int $clientMs = null): array {
    $t = time();
    $o = ['ts' => $t, 'g' => date('Y-m-d', $t), 'j' => jdate(date('Y-m-d', $t), false, false), 'time' => date('H:i:s', $t), 'weekday' => jweekday_name(date('Y-m-d', $t))];
    if ($clientMs) $o['offset_ms'] = $t * 1000 - $clientMs;
    return $o;
}
/** تعطیلاتِ ثابتِ خورشیدی (ماه-روز جلالی) */
function holidays_fixed(): array {
    return ['01-01' => 'عید نوروز', '01-02' => 'عید نوروز', '01-03' => 'عید نوروز', '01-04' => 'عید نوروز', '01-12' => 'روز جمهوری اسلامی', '01-13' => 'روز طبیعت',
        '03-14' => 'رحلت امام خمینی', '03-15' => 'قیام ۱۵ خرداد', '11-22' => 'پیروزی انقلاب اسلامی', '12-29' => 'ملی شدن صنعت نفت'];
}
function holiday_info(string $g): array {
    $titles = [];
    $wd = jweekday($g);
    if ($wd == 6) $titles[] = 'جمعه';
    if ($wd == 5 && setting('thursday_off', '0') === '1') $titles[] = 'پنجشنبه';
    $jmd = substr(jdate($g, false, false), 5, 5); $jmd = str_replace('/', '-', $jmd);
    if (isset(holidays_fixed()[$jmd])) $titles[] = holidays_fixed()[$jmd];
    foreach (rows("SELECT title FROM holidays WHERE d=? OR jmd=?", [$g, $jmd]) as $r) $titles[] = $r['title'];
    return ['off' => (bool)$titles, 'titles' => array_values(array_unique($titles))];
}
/** قاعده: next (روزِ کاریِ بعد) | prev | warn */
function shift_off(string $g, ?string $rule = null): array {
    $rule = $rule ?: (string)setting('holiday_rule', 'next');
    $info = holiday_info($g);
    if (!$info['off']) return ['date' => $g, 'moved' => false, 'warn' => ''];
    if ($rule === 'warn') return ['date' => $g, 'moved' => false, 'warn' => 'این روز تعطیل است: ' . implode('، ', $info['titles'])];
    $step = $rule === 'prev' ? -1 : 1; $d = $g;
    for ($i = 0; $i < 30; $i++) { $d = date('Y-m-d', strtotime("$d $step day")); if (!holiday_info($d)['off']) break; }
    return ['date' => $d, 'moved' => true, 'warn' => 'تاریخ به روزِ کاری منتقل شد'];
}
/** متنِ تعطیلات: هر خط «۱۴۰۵/۰۱/۰۱ عنوان» */
function parse_holiday_text(string $txt): array {
    $out = [];
    foreach (preg_split('/\R/u', $txt) as $line) {
        $line = trim(en_num($line)); if ($line === '') continue;
        if (preg_match('/^(\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2})\s*[:\-–]?\s*(.+)$/u', $line, $m) && ($g = jparse($m[1]))) $out[] = ['d' => $g, 'title' => trim($m[2])];
    }
    return $out;
}
function holidays_import(array $list, string $source = 'manual'): int {
    $n = 0;
    foreach ($list as $h) {
        if (val("SELECT id FROM holidays WHERE d=? AND title=?", [$h['d'], $h['title']])) continue;
        ins('holidays', ['d' => $h['d'], 'title' => mb_cut($h['title'], 200), 'source' => $source]); $n++;
    }
    return $n;
}
/** دریافتِ برخط: نشانیِ قالب‌دار با {y}؛ پاسخ JSON [{date,title}] (date جلالی یا میلادی) */
function holidays_fetch(int $jy): int {
    $url = (string)setting('holiday_url', '');
    if ($url === '') throw new UserError('نشانیِ سرویسِ تعطیلات در تنظیمات ثبت نشده است؛ می‌توانید متنِ تعطیلات را بچسبانید');
    $r = http_req('GET', str_replace('{y}', (string)$jy, $url), null, ['Accept' => 'application/json'], 10);
    if (!$r['ok']) throw new UserError('سرویسِ تعطیلات پاسخ نداد (' . $r['code'] . ')');
    $j = json_decode($r['body'], true); $list = [];
    $items = $j['days'] ?? $j['holidays'] ?? $j['data'] ?? $j;
    foreach ((array)$items as $it) {
        if (!is_array($it)) continue;
        $d = jparse((string)($it['date'] ?? $it['jdate'] ?? $it['d'] ?? '')); $t = (string)($it['title'] ?? $it['description'] ?? $it['event'] ?? 'تعطیل');
        if ($d && (($it['holiday'] ?? $it['is_holiday'] ?? true))) $list[] = ['d' => $d, 'title' => $t];
    }
    return holidays_import($list, 'online');
}

// ── نرخِ دلار (ریال)
function rate_today(string $cur = 'USD'): ?array { return row("SELECT * FROM fx_rates WHERE cur=? ORDER BY d DESC, id DESC LIMIT 1", [$cur]); }
function rate_manual(int $rate, string $cur = 'USD', ?string $d = null): int {
    if ($rate <= 0) throw new UserError('نرخ باید بزرگ‌تر از صفر باشد');
    $id = ins('fx_rates', ['d' => $d ?: today(), 'cur' => $cur, 'rate' => $rate, 'source' => 'manual', 'created_at' => now()]);
    audit('fx_rates', $id, 'create', null, ['rate' => $rate], 'نرخِ دستی');
    return $id;
}
/** نشانی و مسیرِ JSON از تنظیمات (rate_url، rate_path مثل data.usd.price، rate_unit=toman|rial) */
function rate_fetch(string $cur = 'USD'): array {
    $url = (string)setting('rate_url', '');
    if ($url === '') throw new UserError('نشانیِ سرویسِ نرخ ثبت نشده است؛ نرخ را دستی وارد کنید');
    $r = http_req('GET', $url, null, ['Accept' => 'application/json'], 8);
    if (!$r['ok']) throw new UserError('سرویسِ نرخ پاسخ نداد');
    $v = json_decode($r['body'], true);
    foreach (array_filter(explode('.', (string)setting('rate_path', ''))) as $k) $v = is_array($v) ? ($v[$k] ?? null) : null;
    $rate = to_int(is_scalar($v) ? (string)$v : '');
    if ($rate <= 0) throw new UserError('نرخ در پاسخِ سرویس پیدا نشد');
    if (setting('rate_unit', 'toman') === 'toman') $rate *= 10;
    $id = ins('fx_rates', ['d' => today(), 'cur' => $cur, 'rate' => $rate, 'source' => 'auto', 'created_at' => now()]);
    return ['id' => $id, 'rate' => $rate, 'source' => 'auto'];
}
