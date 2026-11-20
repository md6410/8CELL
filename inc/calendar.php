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
    $rs = rows("SELECT title, is_off, source FROM holidays WHERE d=? OR jmd=?", [$g, $jmd]);
    foreach ($rs as $r) if ($r['source'] === 'manual' && !(int)$r['is_off']) return ['off' => false, 'titles' => []]; // دستیِ مدیر حرفِ آخر است
    foreach ($rs as $r) if ((int)$r['is_off']) $titles[] = $r['title'];
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
/** سرویسِ پیش‌فرض (همان Weby): داده از time.ir؛ شکلِ خروجی تضمینی ندارد پس تجزیه بردبار است */
function hol_api_url(): string { return (string)(setting('holiday_url', '') ?: 'https://basemax.github.io/persian-holidays-api/holidays.json'); }
function hol_month_no(string $name): int { $i = array_search(trim($name), array_slice(jalali_months(), 1), true); if ($i !== false) return $i + 1; $n = (int)en_num($name); return $n >= 1 && $n <= 12 ? $n : 0; }
/** فقط رکوردهای شمسی (قمریِ بی‌سال را نمی‌شود درست نشاند) ← [jm, jd, title, is_holiday] */
function hol_parse(string $json): array {
    $data = json_decode($json, true); if (!is_array($data)) return [];
    if (isset($data['holidays']) && is_array($data['holidays'])) $data = $data['holidays'];
    $out = [];
    foreach ($data as $row) {
        if (!is_array($row)) continue;
        $t = trim((string)($row['event_name'] ?? $row['title'] ?? $row['event'] ?? '')); $d = $row['date'] ?? null;
        if ($t === '' || !is_array($d)) continue;
        if (!in_array(strtolower((string)($d['type'] ?? 'shamsi')), ['shamsi', 'jalali', 'persian'], true)) continue;
        $p = $d['date'] ?? null; if (!is_array($p) || count($p) < 2) continue;
        $jd = (int)en_num((string)$p[0]); $jm = hol_month_no((string)$p[1]);
        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) continue;
        $out[] = ['jm' => $jm, 'jd' => $jd, 'title' => mb_cut($t, 190), 'is_holiday' => !empty($row['is_holiday']) ? 1 : 0];
    }
    return $out;
}
/** همگام‌سازیِ یک سال: هیچ‌چیز پاک نمی‌شود و ردیفِ دستی دست نمی‌خورد */
function holidays_fetch(int $jy): int {
    $url = str_replace('{y}', (string)$jy, hol_api_url());
    $r = http_req('GET', $url, null, ['Accept' => 'application/json'], 12);
    if (!$r['ok']) throw new UserError('سرویسِ تعطیلات در دسترس نبود (' . $r['code'] . ')؛ می‌توانید متنِ تعطیلات را بچسبانید');
    $rows = hol_parse($r['body']); $list = [];
    if ($rows) { foreach ($rows as $x) if ($x['is_holiday']) { [$gy, $gm, $gd] = j2g($jy, $x['jm'], $x['jd']); $list[] = ['d' => sprintf('%04d-%02d-%02d', $gy, $gm, $gd), 'title' => $x['title']]; } }
    else { // قالبِ ساده: [{date, title}]
        foreach ((array)(json_decode($r['body'], true) ?: []) as $it) { if (!is_array($it)) continue; $d = jparse((string)($it['date'] ?? $it['jdate'] ?? '')); if ($d) $list[] = ['d' => $d, 'title' => (string)($it['title'] ?? 'تعطیل')]; }
    }
    if (!$list) throw new UserError('پاسخِ سرویس خوانده نشد یا تعطیلیِ شمسی نداشت');
    $manual = array_column(rows("SELECT d FROM holidays WHERE source='manual'"), 'd');
    return holidays_import(array_values(array_filter($list, fn($h) => !in_array($h['d'], $manual, true))), 'online');
}
// ── نرخِ دلار (ریال)
function rate_today(string $cur = 'USD'): ?array { return row("SELECT * FROM fx_rates WHERE cur=? ORDER BY d DESC, id DESC LIMIT 1", [$cur]); }
function rate_manual(int $rate, string $cur = 'USD', ?string $d = null): int {
    if ($rate <= 0) throw new UserError('نرخ باید بزرگ‌تر از صفر باشد');
    $id = ins('fx_rates', ['d' => $d ?: today(), 'cur' => $cur, 'rate' => $rate, 'source' => 'manual', 'created_at' => now()]);
    audit('fx_rates', $id, 'create', null, ['rate' => $rate], 'نرخِ دستی');
    return $id;
}
/** ارزها و کلیدِ هر منبع (همان Weby fx.php) */
function fx_currencies(): array { return ['USD' => ['دلار آمریکا', 'usd_sell', 'price_dollar_rl'], 'EUR' => ['یورو', 'eur', 'price_eur'], 'AED' => ['درهم امارات', 'aed_sell', 'price_aed'], 'CNY' => ['یوان چین', 'cny', 'price_cny']]; }
function fx_num($v): int { $s = strtr(en_num((string)$v), ['٬' => '', ',' => '', ' ' => '']); return preg_match('/^\d+(\.\d+)?$/', $s) ? (int)round((float)$s) : 0; }
/** نوسان (با کلید): پاسخ به تومان یا ریال */
function fx_parse_navasan(string $json, string $unit = 'toman'): array {
    $j = json_decode($json, true); if (!is_array($j)) return []; $o = [];
    foreach (fx_currencies() as $c => $x) { $row = $j[$x[1]] ?? null; $v = is_array($row) ? fx_num($row['value'] ?? '') : fx_num($row ?? ''); if ($v > 0) $o[$c] = $unit === 'rial' ? $v : $v * 10; }
    return $o; // ریال
}
/** TGJU (بی‌کلید): پیش‌فرضش ریال است */
function fx_parse_tgju_profile(string $json): int {
    $j = json_decode($json, true); if (!is_array($j)) return 0;
    $v = fx_num($j['summary']['price']['plain'] ?? ($j['data']['summary']['price']['plain'] ?? '')); if ($v <= 0) return 0;
    $u = mb_strtolower(trim((string)($j['info']['currency_unit'] ?? ($j['data']['info']['currency_unit'] ?? ''))));
    return in_array($u, ['تومان', 'toman'], true) ? $v * 10 : $v;
}
/** دو منبع: اگر بیش از max_gap٪ فاصله داشتند نرخ عوض نمی‌شود (یکی خراب است) */
function fx_pick(int $nav, int $tgju, int $gap): array {
    if ($nav <= 0 && $tgju <= 0) return ['rate' => 0, 'src' => '', 'err' => 'هیچ منبعی نرخ نداد'];
    if ($nav <= 0) return ['rate' => $tgju, 'src' => 'tgju', 'err' => '']; if ($tgju <= 0) return ['rate' => $nav, 'src' => 'navasan', 'err' => ''];
    $hi = max($nav, $tgju); $lo = min($nav, $tgju);
    if (($hi - $lo) * 100 > $gap * $lo) return ['rate' => 0, 'src' => '', 'err' => 'دو منبع بیش از ' . fa_num($gap) . '٪ فاصله دارند — نرخ عوض نشد'];
    return ['rate' => $hi, 'src' => $nav >= $tgju ? 'navasan' : 'tgju', 'err' => ''];
}
function rate_fetch(string $cur = 'USD'): array {
    $x = fx_currencies()[$cur] ?? null; if (!$x) throw new UserError('ارزِ ناشناخته');
    $nav = 0; $key = trim((string)setting('fx_nav_key', ''));
    if ($key !== '') { $r = http_req('GET', 'https://api.navasan.tech/latest/?api_key=' . rawurlencode($key), null, [], 15); if ($r['ok']) $nav = fx_parse_navasan($r['body'], (string)setting('fx_nav_unit', 'toman'))[$cur] ?? 0; }
    $r = http_req('GET', 'https://api.tgju.org/v1/market/indicator/profile/' . rawurlencode($x[2]), null, ['Accept' => 'application/json'], 15);
    $tg = $r['ok'] ? fx_parse_tgju_profile($r['body']) : 0;
    $p = fx_pick($nav, $tg, max(1, (int)setting('fx_max_gap', 20)));
    if (!$p['rate']) throw new UserError($p['err'] . '؛ نرخ را دستی وارد کنید');
    $id = ins('fx_rates', ['d' => today(), 'cur' => $cur, 'rate' => $p['rate'], 'source' => $p['src'], 'created_at' => now()]);
    return ['id' => $id, 'rate' => $p['rate'], 'source' => $p['src']];
}
