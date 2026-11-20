<?php
// فاکتورساز — پورتِ PHPِ موتورِ آزموده‌شدهٔ Invora (app.html): calcDoc، شماره‌گذاری، پیش‌فاکتور↔فاکتور، فاکتورِ رسمی
// (سامانهٔ مودیان)، برگشت از فروش، قفل/نسخه/تأییدِ خریدار، قالب‌های چاپ (رسمی جدولی/نوار رنگی/ساده/قاب‌دار) و QRِ اصالت.
// برابریِ محاسبه با Invora با ۴۰۰ سندِ تصادفی آزموده می‌شود (tests/fixtures/invora_calc.json).
declare(strict_types=1);

// ── پول
function doc_currencies(): array { return ['IRR' => ['name' => 'ریال', 'sub' => '', 'dec' => 0], 'IRT' => ['name' => 'تومان', 'sub' => '', 'dec' => 0], 'USD' => ['name' => 'دلار', 'sub' => 'سنت', 'dec' => 2]]; }
function js_round(float $x): float { return floor($x + 0.5); } // Math.round
/** roundMoney اینورا: گردکردن به اعشارِ ارز با اپسیلون */
function money_round($n, string $cur = 'IRR'): float {
    $d = doc_currencies()[$cur]['dec'] ?? 0; $f = 10 ** $d; $n = (float)$n;
    return js_round($n * $f + 1e-9 * ($n <=> 0)) / $f;
}
function doc_money($n, string $cur = 'IRR', bool $fa = true): string {
    $d = doc_currencies()[$cur]['dec'] ?? 0; $v = money_round($n, $cur);
    $s = number_format(abs($v), $d, '.', ','); if ($d) $s = rtrim(rtrim($s, '0'), '.') === '' ? '0' : $s;
    $s = ($v < 0 ? '-' : '') . $s;
    return $fa ? strtr(fa_num($s), [',' => '٬', '.' => '٫']) : $s;
}
function doc_qty($q, bool $fa = true): string { $s = rtrim(rtrim(number_format((float)$q, 3, '.', ','), '0'), '.'); return $fa ? strtr(fa_num($s), [',' => '٬', '.' => '٫']) : $s; }
function money_words_cur($n, string $cur = 'IRR'): string {
    $c = doc_currencies()[$cur] ?? doc_currencies()['IRR']; $n = money_round($n, $cur); $neg = $n < 0; $n = abs($n);
    if ($c['dec']) { $w = (int)floor($n); $ct = (int)round(($n - $w) * 100); $s = num_words($w) . ' ' . $c['name'] . ($ct ? ' و ' . num_words($ct) . ' ' . $c['sub'] : ''); }
    else $s = num_words((int)$n) . ' ' . $c['name'];
    return ($neg ? 'منفی ' : '') . $s;
}
function doc_kinds(): array { return ['proforma' => 'پیش‌فاکتور', 'invoice' => 'فاکتورِ فروش', 'official' => 'صورتحسابِ رسمی', 'return' => 'برگشت از فروش']; }
function doc_subjects(): array { return ['main' => 'اصلی', 'correction' => 'اصلاحی', 'cancel' => 'ابطالی', 'return' => 'برگشت از فروش']; }
function buyer_kinds(): array { return ['legal' => 'حقوقی', 'natural' => 'حقیقی', 'civil' => 'مشارکت مدنی', 'foreign' => 'اتباع غیرایرانی']; }
function moadian_states(): array { return ['none' => 'ثبت‌نشده در سامانهٔ مودیان', 'sent' => 'ارسال‌شده به سامانه', 'accepted' => 'تأییدِ سامانهٔ مودیان', 'rejected' => 'ردشده در سامانه']; }
function doc_templates(): array { return ['formal' => 'رسمی جدولی', 'band' => 'نوار رنگی', 'minimal' => 'ساده و خلوت', 'frame' => 'قاب‌دار']; }
function doc_units(): array { return ['عدد', 'دستگاه', 'قطعه', 'بسته', 'کارتن', 'جعبه', 'جفت', 'دست', 'کیلوگرم', 'گرم', 'تن', 'متر', 'متر مربع', 'متر مکعب', 'لیتر', 'رول', 'شاخه', 'ورق', 'سرویس', 'ساعت', 'نفر ساعت', 'روز', 'ماه', 'مورد']; }
function doc_cfg(string $k, $def = null) { $d = ['doc_template' => 'formal', 'doc_color' => '#1f4e79', 'doc_font_size' => 10, 'doc_pat_invoice' => '{Y}-{N}', 'doc_pat_proforma' => 'P{Y}-{N}', 'doc_pat_official' => '{Y}{N}',
    'doc_pat_credit' => 'R{Y}-{N}', 'doc_pad' => 4, 'doc_start' => 1, 'doc_reset_yearly' => 1, 'proforma_valid_days' => 7, 'proforma_gap_days' => 1, 'doc_total_round' => 'fx', 'doc_total_round_dir' => 'down',
    'fx_round' => 1, 'doc_tax_round' => 'round', 'doc_show_seller' => 1, 'doc_show_bank' => 1, 'doc_show_bank_pf' => 1, 'doc_show_stamp' => 1, 'doc_show_sig' => 1, 'doc_show_watermark' => 1, 'doc_show_validity' => 1,
    'doc_show_words' => 1, 'doc_digits' => 'fa', 'invoice_title' => 'صورتحساب فروش کالا و خدمات', 'proforma_title' => 'پیش‌فاکتور', 'off_title' => 'صورتحساب فروش کالا و خدمات', 'doc_header_text' => '', 'vf_on' => 1, 'vf_name' => '', 'vf_mobile' => '',
    'doc_default_notes' => '', 'doc_default_terms' => '', 'doc_vat_on' => 1];
    $v = setting($k); return $v === null || $v === '' ? ($def ?? ($d[$k] ?? null)) : $v; }

/**
 * calcDoc اینورا (مو‌به‌مو) + تقسیمِ تخفیفِ کل و مالیات روی ردیف‌ها برای صندوق و برگشت.
 * lines: [qty, price, disc, disc_type ('%'|'amount'), taxable (پیش‌فرض true)، unit_id (اختیاری: تعدادِ پایهٔ صحیحِ صندوق)]
 * o: doc_disc, doc_disc_type, vat_on, vat_pct, official, extra, currency, total_round [u, mode]|null, tax_round (round|floor|ceil)
 */
function calc_doc(array $lines, array $o = []): array {
    $cur = (string)($o['currency'] ?? 'IRR'); $r = fn($v) => money_round($v, $cur);
    $taxMode = (string)($o['tax_round'] ?? 'round');
    $rt = function ($v) use ($cur, $taxMode, $r) { if ($taxMode === 'round') return $r($v); $d = doc_currencies()[$cur]['dec'] ?? 0; $f = 10 ** $d; return ($taxMode === 'ceil' ? ceil($v * $f - 1e-9) : floor($v * $f + 1e-9)) / $f; };
    $vatOn = array_key_exists('vat_on', $o) ? !empty($o['vat_on']) : true;
    $rate = (float)($o['vat_pct'] ?? vat_pct());
    $out = [];
    foreach (array_values($lines) as $i => $l) {
        $q = (float)($l['qty'] ?? 0); $p = (float)($l['price'] ?? 0);
        if ($q <= 0) throw new UserError('مقدارِ ردیفِ ' . fa_num($i + 1) . ' باید بیشتر از صفر باشد');
        if ($p < 0) throw new UserError('قیمتِ ردیفِ ' . fa_num($i + 1) . ' منفی است');
        $gross = !empty($l['unit_id']) ? (float)line_amount((int)$q, (int)$p, (int)$l['unit_id']) : $r($q * $p);
        $d = ($l['disc_type'] ?? 'amount') === '%' ? $gross * (float)($l['disc'] ?? 0) / 100 : (float)($l['disc'] ?? 0);
        $d = $r(min(max($d, 0), $gross));
        $taxable = !array_key_exists('taxable', $l) || !empty($l['taxable']); if (!empty($l['tax_exempt'])) $taxable = false;
        $out[$i] = $l + ['title' => $l['title'] ?? ($l['name'] ?? '')]; $out[$i]['gross'] = $gross; $out[$i]['disc'] = $d; $out[$i]['line_net'] = $r($gross - $d); $out[$i]['taxable'] = $taxable;
    }
    $sub = $r(array_sum(array_column($out, 'gross'))); $lineDisc = $r(array_sum(array_column($out, 'disc'))); $afterLine = $r($sub - $lineDisc);
    $docDisc = ($o['doc_disc_type'] ?? 'amount') === '%' ? $afterLine * (float)($o['doc_disc'] ?? 0) / 100 : (float)($o['doc_disc'] ?? 0);
    $docDisc = $r(min(max($docDisc, 0), $afterLine));
    $afterDisc = $r($afterLine - $docDisc);
    $factor = $afterLine ? $afterDisc / $afterLine : 0;
    $taxBase = $r(array_sum(array_map(fn($l) => $l['taxable'] ? $l['line_net'] : 0, $out)) * $factor);
    $vat = $vatOn ? $rt($taxBase * $rate / 100) : 0.0;
    $official = !empty($o['official']); $off = null;
    if ($official) { // تخفیفِ کل روی ردیف‌ها، مالیاتِ هر ردیف جدا گرد می‌شود (قاعدهٔ سامانهٔ مودیان)
        $rem = $docDisc; $n = count($out); $off = [];
        foreach ($out as $i => $l) {
            $a = $i === $n - 1 ? $rem : $r($afterLine ? $docDisc * $l['line_net'] / $afterLine : 0); $a = min(max($a, 0), $l['line_net']); $rem = $r($rem - $a);
            $net = $r($l['line_net'] - $a); $tax = $vatOn && $l['taxable'] ? $rt($net * $rate / 100) : 0.0;
            $off[] = ['gross' => $l['gross'], 'disc' => $r($l['disc'] + $a), 'net' => $net, 'tax' => $tax, 'total' => $r($net + $tax)];
            $out[$i]['doc_disc_share'] = $a; $out[$i]['net'] = $net; $out[$i]['tax'] = $tax;
        }
        $vat = $r(array_sum(array_column($off, 'tax')));
    } else { // سهمِ هر ردیف از تخفیفِ کل و مالیات (برای فروش/برگشت؛ جمع‌ها دقیقاً همان سند)
        $sh = overhead_split_f(array_map(fn($l) => $l['line_net'], $out), $docDisc, $cur);
        foreach ($out as $i => $l) { $out[$i]['doc_disc_share'] = $sh[$i] ?? 0; $out[$i]['net'] = $r($l['line_net'] - ($sh[$i] ?? 0)); }
        $tx = overhead_split_f(array_map(fn($l) => $l['taxable'] ? $l['net'] : 0, $out), $vat, $cur);
        foreach ($out as $i => $l) $out[$i]['tax'] = $tx[$i] ?? 0;
    }
    foreach ($out as $i => $l) { $out[$i]['after'] = $l['net']; $out[$i]['total'] = $r($l['net'] + $l['tax']); }
    $extra = $official ? 0.0 : $r((float)($o['extra'] ?? 0));
    $total = $r($afterDisc + $vat + $extra); $roundAdj = 0.0;
    $tr = $official ? null : ($o['total_round'] ?? null);
    if ($tr && (float)($tr['u'] ?? 0) > 1 && $total > 0) {
        $u = (float)$tr['u']; $t2 = ($tr['mode'] ?? 'down') === 'near' ? js_round($total / $u) * $u : floor($total / $u) * $u;
        if ($t2 > 0 && $t2 != $total) { $roundAdj = $r($t2 - $total); $total = $r($t2); }
    }
    $num = fn($v) => ($cur === 'USD') ? (float)$v : (int)round($v);
    foreach ($out as $i => $l) foreach (['gross', 'disc', 'line_net', 'doc_disc_share', 'net', 'tax', 'after', 'total'] as $k) $out[$i][$k] = $num($l[$k]);
    if ($off) foreach ($off as $i => $x) foreach ($x as $k => $v) $off[$i][$k] = $num($v);
    return ['lines' => array_values($out), 'sub' => $num($sub), 'lineDisc' => $num($lineDisc), 'afterLine' => $num($afterLine), 'docDisc' => $num($docDisc), 'afterDisc' => $num($afterDisc), 'taxableBase' => $num($taxBase),
        'vat' => $num($vat), 'vat_pct' => $rate, 'vat_on' => $vatOn, 'extra' => $num($extra), 'roundAdj' => $num($roundAdj), 'total' => $num($total), 'off' => $off, 'currency' => $cur, 'words' => money_words_cur($total, $cur)];
}
/** تقسیمِ یک مبلغ به نسبت؛ باقیمانده به بزرگ‌ترین (برای ارزِ اعشاری هم) */
function overhead_split_f(array $w, float $amount, string $cur = 'IRR'): array {
    $sum = array_sum($w); $out = array_map(fn() => 0.0, $w); if ($sum <= 0 || $amount == 0.0) return $out;
    $given = 0.0; $d = doc_currencies()[$cur]['dec'] ?? 0; $f = 10 ** $d;
    foreach ($w as $k => $x) { $out[$k] = floor($amount * $x / $sum * $f + 1e-9) / $f; $given += $out[$k]; }
    $big = array_keys($w, max($w))[0]; $out[$big] = money_round($out[$big] + ($amount - $given), $cur);
    return $out;
}
function fx_round_to(float $v, string $cur): float { if ($cur === 'USD') return money_round($v, 'USD'); $u = (float)doc_cfg('fx_round') / ($cur === 'IRT' ? 10 : 1); if ($u > 1 && $v > 0) $v = max($u, js_round($v / $u) * $u); return money_round($v, $cur); }
/** قیمتِ دلاری: دلار × نرخ × (۱ + افزایش٪)، گردشده — لحظهٔ صدور قفل می‌شود */
function fx_item_price(float $usd, float $rate, float $markup, string $cur): float { $rial = $usd * $rate * (1 + $markup / 100); return fx_round_to($cur === 'IRT' ? $rial / 10 : $rial, $cur); }
function fx_item_price_simple(float $usd, int $rate, float $pct = 0, int $round = 0): int { $p = $usd * $rate * (1 + $pct / 100); return $round > 0 ? (int)(round($p / $round) * $round) : (int)round($p); }
function round_money(int $n, int $step): int { return $step > 0 ? (int)(round($n / $step) * $step) : $n; }
/** totalRoundNow: گردکردنِ جمعِ پیش‌نویس طبقِ تنظیمات (off | fx: فقط سندِ دلاری | always) */
function doc_total_round_now(array $d): ?array {
    $m = (string)doc_cfg('doc_total_round'); $cur = $d['currency'] ?? 'IRR';
    if ($m === 'off' || $cur === 'USD') return null;
    if ($m === 'fx' && !array_filter($d['lines'] ?? [], fn($i) => !empty($i['usd']))) return null;
    $u = (float)doc_cfg('fx_round') / ($cur === 'IRT' ? 10 : 1);
    return $u > 1 ? ['u' => $u, 'mode' => doc_cfg('doc_total_round_dir') === 'near' ? 'near' : 'down'] : null;
}
/** محاسبهٔ یک سندِ ذخیره‌شده (سندِ ثبت‌شده همان گردکردنی را دارد که با آن صادر شد) */
function doc_calc(array $d): array {
    $o = $d['opts'] ?? [];
    $tr = $d['status'] !== 'draft' ? ($o['total_round'] ?? null) : doc_total_round_now($d);
    return calc_doc($d['lines'], ['doc_disc' => $o['doc_disc'] ?? 0, 'doc_disc_type' => $o['doc_disc_type'] ?? 'amount', 'vat_on' => $o['vat_on'] ?? 1, 'vat_pct' => $o['vat_pct'] ?? vat_pct($d['d']),
        'official' => $d['kind'] === 'official', 'extra' => $o['extra'] ?? 0, 'currency' => $d['currency'] ?? 'IRR', 'total_round' => $tr, 'tax_round' => $o['tax_round'] ?? doc_cfg('doc_tax_round')]);
}

// ── شناسه‌ها و قواعدِ صورتحسابِ رسمی
function id_ok($v): bool { return national_code_ok($v) || legal_id_ok($v); }
function digits_of($v): string { return preg_replace('/\D/', '', en_num((string)$v)); }
/** officialIssues اینورا: همهٔ خطاها و هشدارها یک‌جا */
function official_issues(array $d): array {
    $E = []; $W = []; $s = $d['seller'] ?: doc_seller(); $b = $d['buyer'] ?? []; $o = $d['opts'] ?? [];
    if (($d['currency'] ?? 'IRR') !== 'IRR') $E[] = 'مبالغِ فاکتورِ رسمی باید به ریال باشد.';
    if (trim((string)($s['name'] ?? '')) === '') $E[] = 'نامِ فروشنده وارد نشده است.';
    if (!digits_of($s['economic_code'] ?? '') && !digits_of($s['national_id'] ?? '')) $E[] = 'شمارهٔ اقتصادی یا شناسهٔ ملیِ فروشنده وارد نشده است (پروفایلِ کسب‌وکار).';
    elseif (digits_of($s['national_id'] ?? '') && !id_ok($s['national_id'])) $W[] = 'شناسه/کدِ ملیِ فروشنده با الگوی رسمی جور نیست؛ دوباره بررسی کنید.';
    if (strlen(digits_of($s['postal_code'] ?? '')) !== 10) $E[] = 'کدِ پستیِ ۱۰ رقمیِ فروشنده وارد نشده است.';
    if (trim((string)($s['address'] ?? '')) === '') $E[] = 'نشانیِ فروشنده وارد نشده است.';
    if (trim((string)($s['memory_id'] ?? '')) === '') $W[] = '«شناسهٔ یکتای حافظهٔ مالیاتی» وارد نشده است؛ برای ثبت در سامانهٔ مودیان لازم است.';
    if (($o['off_kind'] ?? 'type1') !== 'type2') {
        if (trim((string)($b['name'] ?? '')) === '') $E[] = 'نامِ خریدار وارد نشده است.';
        if (!digits_of($b['national_id'] ?? '') && !digits_of($b['economic_code'] ?? '')) $E[] = 'شناسه/کدِ ملی یا شمارهٔ اقتصادیِ خریدار وارد نشده است (در صورتحسابِ نوعِ اول الزامی است).';
        elseif (digits_of($b['national_id'] ?? '') && !id_ok($b['national_id'])) $W[] = 'شناسه/کدِ ملیِ خریدار با الگوی رسمی جور نیست؛ دوباره بررسی کنید.';
        if (strlen(digits_of($b['postal_code'] ?? '')) !== 10) $E[] = 'کدِ پستیِ ۱۰ رقمیِ خریدار وارد نشده است.';
        if (trim((string)($b['address'] ?? '')) === '') $W[] = 'نشانیِ خریدار وارد نشده است.';
    }
    $items = array_values(array_filter($d['lines'] ?? [], fn($i) => trim((string)($i['name'] ?? $i['title'] ?? '')) !== '' || (float)($i['price'] ?? 0)));
    if (!$items) $E[] = 'هیچ ردیفِ کالا یا خدماتی وارد نشده است.';
    foreach ($items as $k => $it) {
        if (trim((string)($it['name'] ?? $it['title'] ?? '')) === '') $E[] = 'شرحِ ردیفِ ' . fa_num($k + 1) . ' خالی است.';
        if (!((float)($it['qty'] ?? 0) > 0)) $E[] = 'مقدارِ ردیفِ ' . fa_num($k + 1) . ' باید بیشتر از صفر باشد.';
        if (!preg_match('/^\d{13}$/', digits_of($it['code'] ?? ''))) $W[] = 'شناسهٔ ۱۳ رقمیِ کالا/خدمتِ ردیفِ ' . fa_num($k + 1) . ' وارد نشده است.';
    }
    if (($d['subject'] ?? 'main') !== 'main') { $r = !empty($d['ref_doc_id']) ? row("SELECT kind, status FROM docs WHERE id=?", [(int)$d['ref_doc_id']]) : null; if (!$r || $r['kind'] !== 'official' || $r['status'] !== 'issued') $E[] = 'برای صورتحسابِ ' . (doc_subjects()[$d['subject']] ?? '') . '، صورتحسابِ اصلیِ مرجع را انتخاب کنید.'; }
    if (trim((string)($o['tax_id'] ?? '')) !== '' && !preg_match('/^[A-Za-z0-9]{22}$/', trim(en_num((string)$o['tax_id'])))) $W[] = '«شمارهٔ منحصربه‌فردِ مالیاتی» معمولاً ۲۲ نویسهٔ انگلیسی/عدد است؛ دوباره بررسی کنید.';
    return ['E' => $E, 'W' => $W];
}

// ── شماره‌گذاری (بی‌برخورد: max(شمارنده، بزرگ‌ترین شمارهٔ مصرف‌شده + ۱))
function doc_seq_type(array $d): string { return $d['kind'] === 'return' ? 'credit' : $d['kind']; }
function doc_pattern(string $t): string { return (string)doc_cfg(['credit' => 'doc_pat_credit', 'proforma' => 'doc_pat_proforma', 'official' => 'doc_pat_official'][$t] ?? 'doc_pat_invoice'); }
function doc_counter_key(string $type, string $d): string { return $type . '-' . ((int)doc_cfg('doc_reset_yearly') ? substr(jdate($d, false, false), 0, 4) : 'all'); }
function fmt_doc_number(string $pattern, int $seq, string $d, ?int $pad = null): string {
    [$jy, $jm, $jd] = array_map('intval', explode('/', jdate($d, false, false)));
    return strtr($pattern ?: '{N}', ['{Y}' => (string)$jy, '{y}' => substr((string)$jy, -2), '{M}' => sprintf('%02d', $jm), '{D}' => sprintf('%02d', $jd), '{N}' => str_pad((string)$seq, max(1, $pad ?? (int)doc_cfg('doc_pad')), '0', STR_PAD_LEFT)]);
}
function doc_peek_seq(string $type, string $d): int {
    $k = doc_counter_key($type, $d);
    $used = (int)val("SELECT COALESCE(MAX(seq),0) FROM docs WHERE counter_key=?", [$k]);
    $cnt = val("SELECT last FROM doc_counters WHERE k=?", ['doc:' . $k]);
    return max($cnt === null ? (int)doc_cfg('doc_start') : (int)$cnt + 1, $used + 1);
}
function peek_seq(string $kind, string $d): string { $t = $kind === 'return' ? 'credit' : $kind; return fmt_doc_number(doc_pattern($t), doc_peek_seq($t, $d), $d); }
function doc_dup_number(array $d, string $num): bool { return (bool)val("SELECT 1 FROM docs WHERE number=? AND id<>? AND status<>'draft' AND kind=?", [$num, (int)($d['id'] ?? 0), $d['kind']]); }

// ── سند
function doc_get(int $id): ?array {
    $d = row("SELECT * FROM docs WHERE id=?", [$id]); if (!$d) return null;
    foreach (['buyer', 'seller', 'lines', 'opts', 'totals'] as $k) $d[$k] = json_decode((string)$d[$k], true) ?: [];
    $d['currency'] = $d['currency'] ?: 'IRR';
    return $d;
}
function doc_seller(): array {
    $b = biz();
    return ['name' => $b['name'], 'kind' => setting('biz_kind', 'person') === 'legal' ? 'legal' : 'natural', 'national_id' => $b['national_id'], 'economic_code' => $b['economic_code'], 'reg_no' => $b['reg_no'],
        'postal_code' => $b['postal_code'], 'address' => $b['address'], 'phone' => $b['phone'], 'fax' => (string)setting('biz_fax', ''), 'mobile' => $b['mobile'], 'email' => (string)setting('biz_email', ''),
        'website' => (string)setting('biz_website', ''), 'memory_id' => (string)setting('biz_memory_id', ''), 'branch_code' => (string)setting('biz_branch_code', ''), 'province' => (string)setting('biz_province', ''), 'city' => (string)setting('biz_city', '')];
}
function doc_buyer_in(array $in): array {
    $k = (string)($in['buyer_kind'] ?? 'legal'); if (!isset(buyer_kinds()[$k])) $k = $k === 'person' ? 'natural' : 'legal';
    return ['kind' => $k, 'name' => trim((string)($in['buyer_name'] ?? '')), 'national_id' => digits_of($in['buyer_national_id'] ?? ''), 'economic_code' => digits_of($in['buyer_economic_code'] ?? ''),
        'reg_no' => trim(en_num((string)($in['buyer_reg_no'] ?? ''))), 'postal_code' => digits_of($in['buyer_postal'] ?? ''), 'province' => trim((string)($in['buyer_province'] ?? '')), 'city' => trim((string)($in['buyer_city'] ?? '')),
        'address' => trim((string)($in['buyer_address'] ?? '')), 'phone' => trim(en_num((string)($in['buyer_phone'] ?? ''))), 'mobile' => norm_mobile($in['buyer_mobile'] ?? '')];
}
function customer_snapshot(int $id): array {
    $c = customer_get($id) ?: [];
    return ['id' => $id, 'kind' => ($c['kind'] ?? 'person') === 'org' ? 'legal' : 'natural', 'name' => $c['name'] ?? '', 'national_id' => $c['national_id'] ?? '', 'economic_code' => $c['economic_code'] ?? '',
        'reg_no' => '', 'postal_code' => $c['postal_code'] ?? '', 'province' => '', 'city' => '', 'address' => $c['address'] ?? '', 'phone' => $c['phone'] ?? '', 'mobile' => $c['mobile'] ?? ''];
}
/** ردیف‌های ورودی ← ردیف‌های سند (همان newItem اینورا) */
function doc_lines_in(array $rows, string $cur): array {
    $lines = [];
    foreach ($rows as $l) {
        $name = trim((string)($l['name'] ?? $l['title'] ?? '')); $pid = (int)($l['product_id'] ?? 0) ?: null;
        if ($name === '' && !$pid && !to_float($l['price'] ?? 0) && empty($l['usd'])) continue;
        if ($name === '' && $pid) $name = (string)val("SELECT name FROM products WHERE id=?", [$pid]);
        $price = to_float($l['price'] ?? 0); $fx = null;
        if (!empty($l['usd']) && to_float($l['usd']) > 0) {
            $rate = to_int($l['rate'] ?? 0) ?: (int)(rate_today()['rate'] ?? 0); if (!$rate) throw new UserError('نرخِ دلار ثبت نشده است (تقویم و نرخ)');
            $fx = ['usd' => to_float($l['usd']), 'rate' => $rate, 'markup' => to_float($l['markup'] ?? 0)];
            $price = $cur === 'USD' ? $fx['usd'] : fx_item_price($fx['usd'], $rate, $fx['markup'], $cur);
        }
        $lines[] = ['product_id' => $pid, 'sale_item_id' => (int)($l['sale_item_id'] ?? 0) ?: null, 'name' => $name, 'desc' => trim((string)($l['desc'] ?? '')), 'code' => digits_of($l['code'] ?? '') ?: trim(en_num((string)($l['code'] ?? ''))),
            'qty' => round(to_float($l['qty'] ?? 1), 3), 'unit' => trim((string)($l['unit'] ?? 'عدد')), 'price' => money_round($price, $cur), 'disc_type' => ($l['disc_type'] ?? 'amount') === '%' ? '%' : 'amount',
            'disc' => to_float($l['disc'] ?? 0), 'taxable' => !array_key_exists('taxable', $l) || !empty($l['taxable']) ? 1 : 0, 'usd' => $fx ? $fx['usd'] : null, 'fx' => $fx, 'orig_line' => isset($l['orig_line']) ? (int)$l['orig_line'] : null];
    }
    return $lines;
}
function doc_total_rial(array $d, array $calc): int {
    $t = (float)$calc['total']; $cur = $d['currency'] ?? 'IRR';
    if ($cur === 'IRT') return (int)round($t * 10);
    if ($cur === 'USD') { $r = (int)($d['opts']['usd_rate'] ?? (rate_today()['rate'] ?? 0)); return (int)round($t * $r); }
    return (int)round($t);
}
/** پیش‌نویس (سندِ ثبت‌شده فقط با «نسخهٔ اصلاحی» عوض می‌شود) */
function doc_save(array $in, bool $amend = false): int {
    $id = (int)($in['id'] ?? 0); $old = $id ? doc_get($id) : null;
    if ($id && !$old) throw new UserError('سند یافت نشد', 404);
    if ($old && $old['status'] !== 'draft' && !$amend) throw new UserError('سندِ ثبت‌شده قفل است؛ برای تغییر نسخهٔ اصلاحی بسازید');
    $kind = $old && $amend ? $old['kind'] : (isset(doc_kinds()[$in['kind'] ?? '']) ? $in['kind'] : 'invoice');
    $cur = in_array($in['currency'] ?? 'IRR', ['IRR', 'IRT', 'USD'], true) ? ($in['currency'] ?? 'IRR') : 'IRR';
    if ($kind === 'official' && $cur !== 'IRR') throw new UserError('مبالغِ فاکتورِ رسمی باید به ریال باشد');
    $d = jparse($in['d'] ?? '') ?: today();
    $lines = doc_lines_in((array)($in['lines'] ?? []), $cur);
    $cust = (int)($in['customer_id'] ?? 0) ?: null;
    $buyer = $cust ? customer_snapshot($cust) : doc_buyer_in($in);
    if ($cust) foreach (['postal_code' => 'buyer_postal', 'national_id' => 'buyer_national_id', 'economic_code' => 'buyer_economic_code', 'address' => 'buyer_address'] as $bk => $ik) if (empty($buyer[$bk]) && !empty($in[$ik])) $buyer[$bk] = $bk === 'address' ? trim((string)$in[$ik]) : digits_of($in[$ik]);
    $ff = !empty($in['_form']); // فرم: نبودنِ تیک یعنی «خاموش»
    $o = ['doc_disc' => to_float($in['doc_disc'] ?? 0), 'doc_disc_type' => ($in['doc_disc_type'] ?? 'amount') === '%' ? '%' : 'amount', 'vat_on' => $ff || array_key_exists('vat_on', $in) ? (empty($in['vat_on']) ? 0 : 1) : (int)doc_cfg('doc_vat_on'),
        'vat_pct' => isset($in['vat_pct']) && $in['vat_pct'] !== '' ? to_float($in['vat_pct']) : vat_pct($d), 'extra' => $kind === 'official' ? 0 : to_float($in['extra'] ?? 0), 'extra_label' => trim((string)($in['extra_label'] ?? $in['extra_title'] ?? '')),
        'notes' => trim((string)($in['notes'] ?? $in['note'] ?? '')), 'terms' => trim((string)($in['terms'] ?? '')), 'valid_days' => $kind === 'proforma' ? max(0, to_int($in['valid_days'] ?? doc_cfg('proforma_valid_days'))) : 0,
        'settle' => in_array($in['settle'] ?? 'cash', ['cash', 'credit', 'both'], true) ? ($in['settle'] ?? 'cash') : 'cash', 'off_kind' => ($in['off_kind'] ?? 'type1') === 'type2' ? 'type2' : 'type1', 'tax_id' => trim(en_num((string)($in['tax_id'] ?? ''))),
        'stamp_on' => $ff || array_key_exists('stamp_on', $in) ? (empty($in['stamp_on']) ? 0 : 1) : null, 'sig_on' => $ff || array_key_exists('sig_on', $in) ? (empty($in['sig_on']) ? 0 : 1) : null,
        'bank_ids' => array_values(array_map('intval', (array)($in['bank_ids'] ?? []))), 'tax_round' => $old['opts']['tax_round'] ?? doc_cfg('doc_tax_round'), 'usd_rate' => $cur === 'USD' ? (int)(rate_today()['rate'] ?? 0) : null];
    if ($old) foreach (['total_round', 'from_sale', 'credit_reason'] as $k) if (isset($old['opts'][$k])) $o[$k] = $old['opts'][$k];
    $tmp = ['kind' => $kind, 'status' => $old['status'] ?? 'draft', 'currency' => $cur, 'd' => $d, 'lines' => $lines, 'opts' => $o];
    $calc = $lines ? doc_calc($tmp) : ['total' => 0];
    $num = trim(en_num((string)($in['number'] ?? '')));
    $row = ['kind' => $kind, 'currency' => $cur, 'd' => $d, 'customer_id' => $cust, 'buyer' => json_encode($buyer, JSON_UNESCAPED_UNICODE), 'seller' => json_encode($old && $amend ? $old['seller'] : doc_seller(), JSON_UNESCAPED_UNICODE),
        'lines' => json_encode($lines, JSON_UNESCAPED_UNICODE), 'opts' => json_encode($o, JSON_UNESCAPED_UNICODE), 'totals' => json_encode($lines ? array_diff_key($calc, ['lines' => 1]) : [], JSON_UNESCAPED_UNICODE),
        'total' => $lines ? doc_total_rial($tmp, $calc) : 0, 'subject' => $kind === 'official' ? (isset(doc_subjects()[$in['subject'] ?? '']) ? $in['subject'] : 'main') : null,
        'ref_doc_id' => (int)($in['ref_doc_id'] ?? 0) ?: null, 'moadian' => $kind === 'official' ? ($old['moadian'] ?? 'none') : null,
        'contact_name' => trim((string)($in['contact_name'] ?? ($old['contact_name'] ?? ''))) ?: null, 'contact_mobile' => norm_mobile($in['contact_mobile'] ?? ($old['contact_mobile'] ?? '')) ?: null];
    if (!$old || $old['status'] === 'draft') { $row['manual_number'] = $num !== '' ? 1 : 0; $row['number'] = $num !== '' ? $num : null; $row['linked_id'] = (int)($in['linked_id'] ?? ($old['linked_id'] ?? 0)) ?: null; }
    if ($id) { upd('docs', $row, 'id=?', [$id]); return $id; }
    $row += ['status' => 'draft', 'created_by' => current_user()['id'] ?? null, 'created_at' => now()];
    $id = ins('docs', $row); audit('docs', $id, 'create', null, ['kind' => $kind, 'total' => $row['total']], 'پیش‌نویسِ سند');
    return $id;
}
/** ثبت (commitDoc): شمارهٔ بی‌برخورد یا دستیِ بی‌تکرار، قفل، گردکردنِ قفل‌شده، توکنِ اصالت، نسخهٔ ۱، نسخهٔ نمایشیِ اصالت */
function doc_commit(int $id): array {
    return tx(function () use ($id) {
        $d = doc_get($id); if (!$d) throw new UserError('سند یافت نشد', 404);
        if ($d['status'] !== 'draft') throw new UserError('این سند قبلاً ثبت شده است');
        if (!$d['lines']) throw new UserError('سند ردیف ندارد');
        fiscal_assert_open($d['d']);
        $iss = $d['kind'] === 'official' ? official_issues($d) : ['E' => [], 'W' => []];
        if ($iss['E']) throw new UserError(implode(' | ', $iss['E']));
        $t = doc_seq_type($d); $k = doc_counter_key($t, $d['d']);
        if ($d['manual_number'] && $d['number']) { if (doc_dup_number($d, $d['number'])) throw new UserError('شمارهٔ «' . $d['number'] . '» برای سندِ دیگری از همین نوع ثبت شده است'); $seq = null; $num = $d['number']; }
        else { $seq = doc_peek_seq($t, $d['d']); $num = fmt_doc_number(doc_pattern($t), $seq, $d['d']); q("INSERT INTO doc_counters (k,last) VALUES (?,?) ON DUPLICATE KEY UPDATE last=GREATEST(last,VALUES(last))", ['doc:' . $k, $seq]); }
        $o = $d['opts']; $o['total_round'] = $d['kind'] === 'official' ? null : doc_total_round_now($d);
        $tmp = $d; $tmp['opts'] = $o; $tmp['status'] = 'issued'; $calc = doc_calc($tmp);
        $tok = doc_cfg('vf_on') ? b64u_enc(random_bytes(16)) : null; // ۲۲ نویسه، مثلِ اینورا
        $cm = $d['contact_mobile'] ?: ($d['buyer']['mobile'] ?? '') ?: (string)doc_cfg('vf_mobile'); $cn = $d['contact_name'] ?: ($d['contact_mobile'] ? '' : (($d['buyer']['mobile'] ?? '') ? ($d['buyer']['name'] ?? '') : (string)doc_cfg('vf_name')));
        upd('docs', ['status' => 'issued', 'number' => $num, 'seq' => $seq, 'counter_key' => $seq ? $k : null, 'verify_token' => $tok, 'issued_at' => now(), 'opts' => json_encode($o, JSON_UNESCAPED_UNICODE),
            'totals' => json_encode(array_diff_key($calc, ['lines' => 1]), JSON_UNESCAPED_UNICODE), 'total' => doc_total_rial($tmp, $calc), 'contact_mobile' => $cm ?: null, 'contact_name' => $cn ?: null, 'issued_by' => current_user()['id'] ?? null], 'id=?', [$id]);
        if ($d['linked_id']) q("UPDATE docs SET linked_id=? WHERE id=? AND linked_id IS NULL", [$id, $d['linked_id']]);
        if ($d['kind'] === 'official' && $d['subject'] === 'cancel' && $d['ref_doc_id']) upd('docs', ['off_canceled' => $id], 'id=?', [$d['ref_doc_id']]);
        doc_revision($id, 1, 'صدور');
        vf_snapshot($id);
        audit('docs', $id, 'issue', ['status' => 'draft'], ['status' => 'issued', 'number' => $num], 'ثبتِ سند');
        return ['number' => $num, 'token' => $tok, 'warnings' => $iss['W']];
    });
}
function doc_revision(int $id, int $rev, string $reason = ''): void {
    $d = row("SELECT * FROM docs WHERE id=?", [$id]); unset($d['vf_html']); $data = json_encode($d, JSON_UNESCAPED_UNICODE);
    ins('doc_revisions', ['doc_id' => $id, 'revision' => $rev, 'reason' => mb_cut($reason, 300) ?: null, 'data' => $data, 'hash' => hash('sha256', $data), 'user_id' => current_user()['id'] ?? null, 'at' => now()]);
}
/** قفل: ثبت‌شده؛ تأییدِ خریدار = قفلِ همیشگی (docFinal) */
function doc_locked(array $d): bool { return $d['status'] !== 'draft'; }
function doc_final(array $d): bool { return (bool)$d['confirmed']; }
/** نسخهٔ اصلاحی (باز کردنِ قفلِ مدیر + snapshot با دلیل) — شماره عوض نمی‌شود */
function doc_amend(int $id, array $in): int {
    $d = doc_get($id); if (!$d || $d['status'] !== 'issued') throw new UserError('فقط سندِ ثبت‌شده اصلاح می‌شود');
    if (doc_final($d)) throw new UserError('خریدار این سند را تأیید کرده؛ دیگر هرگز تغییر نمی‌کند');
    $reason = trim((string)($in['reason'] ?? '')); if (mb_strlen($reason) < 2) throw new UserError('دلیلِ اصلاح را بنویسید');
    fiscal_assert_open($d['d']);
    return tx(function () use ($id, $d, $in, $reason) {
        $in['id'] = $id; $in['d'] = $in['d'] ?? jdate($d['d'], false, false);
        doc_save($in, true);
        $n = doc_get($id); $calc = doc_calc($n);
        upd('docs', ['totals' => json_encode(array_diff_key($calc, ['lines' => 1]), JSON_UNESCAPED_UNICODE), 'total' => doc_total_rial($n, $calc), 'revision' => (int)$d['revision'] + 1, 'edited_at' => now()], 'id=?', [$id]);
        doc_revision($id, (int)$d['revision'] + 1, $reason);
        vf_snapshot($id);
        audit('docs', $id, 'amend', ['total' => $d['total']], ['total' => val("SELECT total FROM docs WHERE id=?", [$id]), 'reason' => $reason], 'اصلاحِ سند (نسخهٔ ' . ((int)$d['revision'] + 1) . ')');
        return (int)$d['revision'] + 1;
    });
}
/** بازگرداندنِ یک نسخهٔ قبلی (docRestore) — خودش یک نسخهٔ تازه است */
function doc_restore(int $id, int $rev): int {
    $d = doc_get($id); if (!$d || $d['status'] !== 'issued' || doc_final($d)) throw new UserError('این سند قابلِ بازگردانی نیست');
    $r = row("SELECT data FROM doc_revisions WHERE doc_id=? AND revision=?", [$id, $rev]); if (!$r) throw new UserError('نسخه یافت نشد');
    $s = json_decode($r['data'], true);
    return tx(function () use ($id, $d, $s, $rev) {
        upd('docs', ['lines' => $s['lines'], 'opts' => $s['opts'], 'buyer' => $s['buyer'], 'totals' => $s['totals'], 'total' => $s['total'], 'd' => $s['d'], 'currency' => $s['currency'] ?? 'IRR', 'revision' => (int)$d['revision'] + 1, 'edited_at' => now()], 'id=?', [$id]);
        doc_revision($id, (int)$d['revision'] + 1, 'بازگردانیِ نسخهٔ ' . $rev); vf_snapshot($id);
        audit('docs', $id, 'restore', null, ['revision' => $rev], 'بازگردانیِ نسخه'); return (int)$d['revision'] + 1;
    });
}
function doc_void(int $id, string $reason): void {
    $d = doc_get($id); if (!$d) throw new UserError('سند یافت نشد'); if (doc_final($d)) throw new UserError('فاکتورِ تأییدشده باطل نمی‌شود');
    if (trim($reason) === '') throw new UserError('دلیلِ ابطال لازم است');
    fiscal_assert_open($d['d']);
    upd('docs', ['status' => 'void'], 'id=?', [$id]); vf_snapshot($id);
    audit('docs', $id, 'void', ['status' => $d['status']], ['status' => 'void', 'reason' => $reason], 'ابطالِ سند');
}
function doc_set_moadian(int $id, string $st, string $taxId = ''): void {
    $d = doc_get($id); if (!$d || $d['kind'] !== 'official') throw new UserError('فقط صورتحسابِ رسمی');
    if (!isset(moadian_states()[$st])) throw new UserError('وضعیتِ نامعتبر');
    $o = $d['opts']; if ($taxId !== '') $o['tax_id'] = trim(en_num($taxId));
    upd('docs', ['moadian' => $st, 'opts' => json_encode($o, JSON_UNESCAPED_UNICODE)], 'id=?', [$id]); vf_snapshot($id);
    audit('docs', $id, 'moadian', null, ['state' => $st], 'وضعیتِ سامانهٔ مودیان');
}
/** وضعیتِ نمایشی (docStatus) */
function doc_status(array $d): array {
    if ($d['status'] === 'draft') return ['پیش‌نویس', 'warn'];
    if ($d['status'] === 'void') return ['باطل', 'err'];
    if ($d['kind'] === 'return') return ['برگشت از فروش', ''];
    if ($d['kind'] === 'official') { if ($d['off_canceled']) return ['ابطال شده', 'err']; $m = $d['moadian'] ?: 'none'; return ['رسمی — ' . moadian_states()[$m], $m === 'accepted' ? 'ok' : ($m === 'sent' ? '' : 'warn')]; }
    if ($d['kind'] === 'proforma') {
        if ($d['linked_id'] && val("SELECT 1 FROM docs WHERE id=? AND kind IN ('invoice','official') AND status='issued'", [$d['linked_id']])) return ['تبدیل به فاکتور شد', ''];
        $v = doc_valid_until($d); if ($v && $v < today()) return ['اعتبار تمام شده', 'err']; return [$v ? 'معتبر تا ' . jdate($v) : 'صادر شده', 'ok'];
    }
    return ['صادر شده', 'ok'];
}
function doc_valid_until(array $d): ?string { $n = (int)($d['opts']['valid_days'] ?? 0); return $d['kind'] === 'proforma' ? date('Y-m-d', strtotime($d['d'] . " +$n day")) : null; }
/** linkCheck: پیش‌فاکتور دست‌کم یک روز پیش از فاکتور و فاکتور پیش از پایانِ اعتبار */
function doc_link_check(array $d): ?array {
    if (!$d['linked_id']) return null; $o = doc_get((int)$d['linked_id']); if (!$o) return null;
    [$inv, $pf] = $d['kind'] === 'proforma' ? [$o, $d] : [$d, $o];
    $msg = 'مرتبط با ' . ($d['kind'] === 'proforma' ? 'فاکتورِ' : 'پیش‌فاکتورِ') . ' شمارهٔ ' . fa_num($o['number'] ?: '—') . ' به تاریخ ' . jdate($o['d']) . '.';
    $gap = (int)round((strtotime($inv['d']) - strtotime($pf['d'])) / 86400);
    if ($gap < 1) return ['warn', $msg . ' تاریخِ پیش‌فاکتور باید دست‌کم یک روز پیش از تاریخِ فاکتور باشد.'];
    $v = doc_valid_until($pf); if ($v && $inv['d'] > $v) return ['warn', $msg . ' تاریخِ فاکتور بعد از پایانِ اعتبارِ پیش‌فاکتور است.'];
    return ['info', $msg . ' فاصله: ' . fa_num($gap) . ' روز.'];
}
/** cloneDoc: نسخهٔ تازه بی‌شماره و بی‌قفل */
function doc_clone(int $srcId, array $over = []): int {
    $s = doc_get($srcId); if (!$s) throw new UserError('سند یافت نشد');
    $o = $s['opts']; unset($o['total_round'], $o['from_sale']);
    $row = ['kind' => $over['kind'] ?? $s['kind'], 'status' => 'draft', 'currency' => $s['currency'], 'd' => $over['d'] ?? today(), 'customer_id' => $s['customer_id'], 'buyer' => json_encode($s['buyer'], JSON_UNESCAPED_UNICODE),
        'seller' => json_encode(doc_seller(), JSON_UNESCAPED_UNICODE), 'lines' => json_encode($over['lines'] ?? $s['lines'], JSON_UNESCAPED_UNICODE), 'opts' => json_encode(($over['opts'] ?? []) + $o, JSON_UNESCAPED_UNICODE),
        'totals' => '{}', 'total' => 0, 'linked_id' => $over['linked_id'] ?? null, 'subject' => $over['subject'] ?? null, 'ref_doc_id' => $over['ref_doc_id'] ?? null, 'credit_ref' => $over['credit_ref'] ?? null,
        'moadian' => ($over['kind'] ?? $s['kind']) === 'official' ? 'none' : null, 'contact_name' => $s['contact_name'], 'contact_mobile' => $s['contact_mobile'], 'created_by' => current_user()['id'] ?? null, 'created_at' => now()];
    $id = ins('docs', $row);
    $n = doc_get($id); if ($n['lines']) { $c = doc_calc($n); upd('docs', ['totals' => json_encode(array_diff_key($c, ['lines' => 1]), JSON_UNESCAPED_UNICODE), 'total' => doc_total_rial($n, $c)], 'id=?', [$id]); }
    return $id;
}
/** convertDoc: پیش‌فاکتور ← فاکتور (تاریخ + فاصله، به روزِ کاریِ بعد) یا برعکس؛ سند ابتدا ثبت می‌شود */
function doc_convert(int $id): int {
    $d = doc_get($id); if (!$d) throw new UserError('سند یافت نشد');
    if (!in_array($d['kind'], ['proforma', 'invoice'], true)) throw new UserError('فقط پیش‌فاکتور و فاکتور به هم تبدیل می‌شوند');
    if ($d['status'] === 'draft') { doc_commit($id); $d = doc_get($id); }
    if ($d['status'] === 'void') throw new UserError('سندِ باطل تبدیل نمی‌شود');
    $gap = (int)doc_cfg('proforma_gap_days');
    if ($d['kind'] === 'proforma') { $nd = shift_off(date('Y-m-d', strtotime($d['d'] . " +$gap day")), 'next')['date']; return doc_clone($id, ['kind' => 'invoice', 'd' => $nd, 'linked_id' => $id, 'opts' => ['valid_days' => 0]]); }
    $nd = shift_off(date('Y-m-d', strtotime($d['d'] . " -$gap day")), 'prev')['date'];
    return doc_clone($id, ['kind' => 'proforma', 'd' => $nd, 'linked_id' => $id, 'opts' => ['valid_days' => (int)doc_cfg('proforma_valid_days')]]);
}
/** makeOfficial: تومان ← ریال (×۱۰)؛ دلار با نرخِ روز؛ «سایر» صفر */
function doc_make_official(int $id): void {
    $d = doc_get($id); if (!$d || $d['status'] !== 'draft') throw new UserError('فقط پیش‌نویس رسمی می‌شود');
    $lines = $d['lines']; $o = $d['opts'];
    if ($d['currency'] === 'IRT') { foreach ($lines as &$l) { $l['price'] = money_round($l['price'] * 10, 'IRR'); if ($l['disc_type'] === 'amount') $l['disc'] = money_round($l['disc'] * 10, 'IRR'); } unset($l); if (($o['doc_disc_type'] ?? 'amount') === 'amount') $o['doc_disc'] = ($o['doc_disc'] ?? 0) * 10; }
    elseif ($d['currency'] === 'USD') {
        $r = (int)(rate_today()['rate'] ?? 0); if (!$r) throw new UserError('برای فاکتورِ رسمی مبالغ باید ریالی باشد ولی نرخِ دلار مشخص نیست (تقویم و نرخ)');
        foreach ($lines as &$l) { $usd = $l['fx']['usd'] ?? (float)$l['price']; $l['fx'] = ['usd' => $usd, 'rate' => $r, 'markup' => $l['fx']['markup'] ?? 0]; $l['usd'] = $usd; $l['price'] = fx_item_price($usd, $r, (float)$l['fx']['markup'], 'IRR'); if ($l['disc_type'] === 'amount') $l['disc'] = fx_round_to($l['disc'] * $r, 'IRR'); } unset($l);
        if (($o['doc_disc_type'] ?? 'amount') === 'amount') $o['doc_disc'] = fx_round_to(($o['doc_disc'] ?? 0) * $r, 'IRR');
    }
    $o['extra'] = 0;
    upd('docs', ['kind' => 'official', 'currency' => 'IRR', 'lines' => json_encode($lines, JSON_UNESCAPED_UNICODE), 'opts' => json_encode($o, JSON_UNESCAPED_UNICODE), 'subject' => 'main', 'moadian' => 'none'], 'id=?', [$id]);
}
/** فاکتور از فروشِ صندوق — همان عددهای قفل‌شدهٔ فروش */
function doc_from_sale(int $saleId, string $kind = 'invoice'): int {
    $ex = val("SELECT id FROM docs WHERE sale_id=? AND kind=? AND status<>'void'", [$saleId, $kind]); if ($ex) return (int)$ex;
    $s = row("SELECT * FROM sales WHERE id=?", [$saleId]); if (!$s) throw new UserError('فروش یافت نشد');
    $items = rows("SELECT si.*, p.unit_id FROM sale_items si JOIN products p ON p.id=si.product_id WHERE si.sale_id=? ORDER BY si.id", [$saleId]);
    $lines = array_map(function ($i) { $u = unit_of($i['unit_id'] ? (int)$i['unit_id'] : null); $sc = max(1, (int)$u['scale']);
        return ['product_id' => (int)$i['product_id'], 'sale_item_id' => (int)$i['id'], 'name' => $i['title'], 'desc' => '', 'code' => '', 'qty' => round((int)$i['qty'] / $sc, 3), 'unit' => $u['name'], 'price' => (int)$i['price'], 'disc_type' => 'amount', 'disc' => (int)$i['disc'], 'taxable' => (int)$i['tax'] > 0 || (int)$i['net'] === 0 ? 1 : 0, 'usd' => null, 'fx' => null, 'orig_line' => null]; }, $items);
    $o = ['doc_disc' => (int)$s['doc_disc'], 'doc_disc_type' => 'amount', 'vat_on' => 1, 'vat_pct' => vat_pct($s['d']), 'extra' => 0, 'from_sale' => 1, 'tax_round' => tax_round_mode($s['d']), 'valid_days' => 0, 'settle' => 'cash', 'off_kind' => 'type1', 'notes' => '', 'terms' => '', 'bank_ids' => []];
    $id = ins('docs', ['kind' => $kind, 'status' => 'draft', 'currency' => 'IRR', 'd' => $s['d'], 'customer_id' => $s['customer_id'], 'buyer' => json_encode($s['customer_id'] ? customer_snapshot((int)$s['customer_id']) : ['name' => 'مشتریِ گذری', 'kind' => 'natural'], JSON_UNESCAPED_UNICODE),
        'seller' => json_encode(doc_seller(), JSON_UNESCAPED_UNICODE), 'lines' => json_encode($lines, JSON_UNESCAPED_UNICODE), 'opts' => json_encode($o, JSON_UNESCAPED_UNICODE), 'totals' => '{}', 'total' => (int)$s['total'], 'sale_id' => $saleId,
        'created_by' => current_user()['id'] ?? null, 'created_at' => now()]);
    doc_commit($id);
    return $id;
}
// ── برگشت از فروش (crReturned / crBuild)
/** مقدارِ برگشت‌خوردهٔ هر ردیفِ فاکتور: [orig_line => qty] */
function doc_credit_returned(int $origId): array {
    $m = [];
    foreach (rows("SELECT `lines` FROM docs WHERE credit_ref=? AND status='issued'", [$origId]) as $r) foreach (json_decode($r['lines'], true) ?: [] as $l) if (isset($l['orig_line'])) $m[$l['orig_line']] = ($m[$l['orig_line']] ?? 0) + (float)$l['qty'];
    return $m;
}
/** سندِ برگشت با مقدارهای انتخابی؛ تخفیفِ مبلغی به نسبتِ مقدار؛ تخفیفِ کل به نسبتِ ناخالص */
function doc_credit_create(int $origId, array $qtys, string $reason, string $note = ''): int {
    $o = doc_get($origId); if (!$o || $o['status'] !== 'issued' || !in_array($o['kind'], ['invoice', 'official'], true)) throw new UserError('برگشت فقط از فاکتورِ ثبت‌شده');
    if (mb_strlen(trim($reason)) < 2) throw new UserError('دلیلِ برگشت لازم است');
    $done = doc_credit_returned($origId); $lines = []; $og = 0; $rg = 0;
    foreach ($o['lines'] as $i => $it) {
        $og += (float)$it['qty'] * (float)$it['price'];
        $q = min(to_float($qtys[$i] ?? 0), (float)$it['qty'] - ($done[$i] ?? 0)); if ($q <= 0) continue;
        if (to_float($qtys[$i] ?? 0) > (float)$it['qty'] - ($done[$i] ?? 0) + 1e-9) throw new UserError('حداکثر ' . doc_qty((float)$it['qty'] - ($done[$i] ?? 0)) . ' از ردیفِ ' . fa_num($i + 1));
        $c = $it; $c['qty'] = $q; $c['orig_line'] = $i; $c['fx'] = null; $c['usd'] = null;
        if ($it['disc_type'] !== '%' && (float)$it['qty']) $c['disc'] = (float)$it['disc'] * $q / (float)$it['qty'];
        $lines[] = $c; $rg += $q * (float)$it['price'];
    }
    if (!$lines) throw new UserError('ردیفی برای برگشت انتخاب نشده است');
    $op = $o['opts']; $dd = ($op['doc_disc_type'] ?? 'amount') === '%' ? ($op['doc_disc'] ?? 0) : ($og ? ($op['doc_disc'] ?? 0) * $rg / $og : 0);
    $id = doc_clone($origId, ['kind' => 'return', 'd' => today(), 'lines' => $lines, 'credit_ref' => $origId, 'opts' => ['doc_disc' => $dd, 'extra' => 0, 'notes' => 'برگشت از فروشِ فاکتورِ شمارهٔ ' . fa_num($o['number']) . ' — ' . trim($reason) . ($note ? "\n" . $note : ''), 'credit_reason' => trim($reason), 'valid_days' => 0]]);
    doc_commit($id);
    return $id;
}
// ── لینکِ دانلود با رمز (اینورا d.php): رمزِ ۵ رقمی، ۵ اشتباه = قفل، سقفِ دفعات، مهلت، لغو، گزارش
function doc_share(int $id, int $days = 30, int $max = 0, string $pin = ''): array {
    $d = row("SELECT id, status FROM docs WHERE id=?", [$id]); if (!$d || $d['status'] === 'draft') throw new UserError('فقط سندِ ثبت‌شده لینک می‌گیرد');
    $pin = preg_replace('/\D/', '', en_num($pin)); if ($pin === '') $pin = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    if (!preg_match('/^\d{4,5}$/', $pin)) throw new UserError('رمز ۴ یا ۵ رقم است');
    $t = b64u_enc(random_bytes(16));
    $sid = ins('doc_shares', ['doc_id' => $id, 'token' => $t, 'pass_hash' => password_hash($pin, PASSWORD_DEFAULT), 'expires_at' => $days > 0 ? date('Y-m-d H:i:s', time() + 86400 * $days) : null, 'max_uses' => max(0, $max), 'created_by' => current_user()['id'] ?? null, 'created_at' => now()]);
    audit('doc_shares', $sid, 'create', null, ['doc' => $id, 'days' => $days], 'لینکِ رمزدار');
    return ['url' => app_url('v.php?s=' . $t), 'pin' => $pin, 'id' => $sid, 'msg' => 'لینک: ' . app_url('v.php?s=' . $t) . ' — رمز: ' . $pin];
}
function doc_share_revoke(int $sid): void { upd('doc_shares', ['dead' => 'revoked'], 'id=?', [$sid]); audit('doc_shares', $sid, 'revoke', null, null, 'لغوِ لینک'); }
function doc_share_send_sms(int $sid, string $mobile): array {
    $s = row("SELECT s.*, d.number, d.buyer FROM doc_shares s JOIN docs d ON d.id=s.doc_id WHERE s.id=?", [$sid]); if (!$s) throw new UserError('لینک یافت نشد');
    $b = json_decode((string)$s['buyer'], true) ?: [];
    return sms_send_tpl($mobile, 'doc_link', ['NAME' => $b['name'] ?? 'مشتری', 'NUMBER' => (string)$s['number'], 'LINK' => app_url('v.php?s=' . $s['token'])], 'notify', ['ref_type' => 'doc', 'ref_id' => (int)$s['doc_id']]);
}
// ── QRِ اصالت (اینورا verify.php): نسخهٔ نمایشی در لحظهٔ صدور/اصلاح ذخیره می‌شود
function vf_snapshot(int $id): void { $d = doc_get($id); if ($d && $d['verify_token']) upd('docs', ['vf_html' => doc_render_html($d)], 'id=?', [$id]); }
function vf_url(string $tok): string { return app_url('v.php?t=' . $tok); }
function vf_log(int $docId, string $ev, array $x = []): void {
    try { ins('vf_log', ['doc_id' => $docId, 'share_id' => $x['share_id'] ?? null, 'event' => $ev, 'ip' => client_ip(), 'ua' => mb_cut((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 250), 'info' => $x ? json_encode($x, JSON_UNESCAPED_UNICODE) : null, 'at' => now()]); } catch (Throwable $e) { error_log($e->getMessage()); }
}
function vf_log_label(string $e): string { return ['vf_open' => 'اسکنِ QR', 'vf_sms' => 'فرستادنِ رمز', 'vf_sms_fail' => 'پیامک نرفت', 'vf_bad' => 'رمزِ اشتباه', 'vf_ok' => 'دیدنِ سند', 'vf_dead' => 'اسکنِ سندِ باطل', 'sh_open' => 'باز شدنِ لینک', 'sh_bad' => 'رمزِ لینکِ اشتباه', 'sh_ok' => 'دیدن با لینک', 'sh_blocked' => 'لینکِ بسته', 'sh_locked' => 'قفلِ لینک (۵ اشتباه)'][$e] ?? $e; }
function sms_mask(string $m): string { return strlen($m) >= 11 ? substr($m, 0, 4) . '***' . substr($m, -4) : $m; }
const VF_PASS_MIN = 30; const VF_CODE_MIN = 10; const VF_SMS_GAP = 60; const VF_SMS_HOUR = 5; const VF_SMS_DAY = 15; const VF_IP_HOUR = 10;
/** رمزِ یک‌بارمصرف به رابطِ سند (با سقف‌ها) ← [ok, پیام, رمزِ حالتِ آزمایشی] */
function vf_send_code(array $d): array {
    $m = (string)$d['contact_mobile']; $h = 'vf' . $d['id'];
    if ($m === '') return [false, 'برای این سند رابطی با شمارهٔ موبایل ثبت نشده است؛ با صادرکنندهٔ سند تماس بگیرید.', ''];
    $last = (int)($_SESSION['vf'][$h]['at'] ?? 0);
    if ($last > time() - VF_SMS_GAP) return [false, 'رمز همین الان فرستاده شد؛ ' . fa_num(VF_SMS_GAP - (time() - $last)) . ' ثانیهٔ دیگر می‌توانید دوباره بخواهید.', ''];
    if (rate_count('vfip:' . client_ip(), 3600) >= VF_IP_HOUR) return [false, 'از این اینترنت در یک ساعتِ گذشته رمزِ زیادی خواسته شده؛ کمی بعد امتحان کنید.', ''];
    if (rate_count('vfdoc:' . $d['id'], 3600) >= VF_SMS_HOUR || rate_count('vfdoc:' . $d['id'], 86400) >= VF_SMS_DAY) return [false, 'برای این سند رمزِ زیادی فرستاده شده؛ بعداً امتحان کنید یا مستقیم با رابط تماس بگیرید.', ''];
    rate_hit('vfip:' . client_ip()); rate_hit('vfdoc:' . $d['id']);
    $code = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    $tpl = sms_tpl_id('vcode') !== '' || sms_tpl_id('otp') === '' ? 'vcode' : 'otp';
    $res = sms_send_tpl($m, $tpl, ['NAME' => mb_cut((string)($d['contact_name'] ?? ''), 24), 'CODE' => $code, 'VERIFICATIONCODE' => otp_webotp_suffix($code)], 'otp', ['ref_type' => 'doc', 'ref_id' => (int)$d['id']]);
    if (!$res['ok']) { vf_log((int)$d['id'], 'vf_sms_fail', ['msg' => $res['msg']]); return [false, 'پیامک فرستاده نشد. با رابط یا صادرکنندهٔ سند تماس بگیرید.', '']; }
    $old = $_SESSION['vf'][$h] ?? null; // رمزهای قبلیِ همین مرورگر تا پایانِ مهلت معتبر می‌مانند
    $hs = is_array($old) && (int)($old['exp'] ?? 0) > time() ? array_slice((array)($old['hs'] ?? []), -2) : [];
    $hs[] = password_hash($code, PASSWORD_DEFAULT);
    $_SESSION['vf'][$h] = ['hs' => $hs, 'exp' => time() + VF_CODE_MIN * 60, 'tries' => 0, 'at' => time()];
    vf_log((int)$d['id'], 'vf_sms', ['to' => sms_mask($m)]);
    return [true, '✓ رمز فرستاده شد.', sms_test_mode() ? $code : ''];
}
function vf_check_pin(array $d, string $pin): array {
    $h = 'vf' . $d['id']; $pin = preg_replace('/\D/', '', en_num($pin));
    if (rate_count('vfbad:' . client_ip(), 3600) >= 20) return [false, 'تعدادِ تلاش‌های ناموفق از این اینترنت زیاد است؛ یک ساعتِ دیگر امتحان کنید.'];
    $o = $_SESSION['vf'][$h] ?? null;
    if (!preg_match('/^\d{5}$/', $pin)) return [false, 'رمزِ ۵ رقمی را کامل وارد کنید.'];
    if (!is_array($o) || (int)$o['exp'] < time()) return [false, 'رمزی برای این مرورگر فرستاده نشده یا مهلتش تمام شده؛ «فرستادنِ رمز» را بزنید.'];
    if ((int)$o['tries'] >= 5) return [false, 'رمز ۵ بار اشتباه زده شد؛ رمزِ تازه بخواهید.'];
    foreach ((array)$o['hs'] as $x) if (password_verify($pin, (string)$x)) { unset($_SESSION['vf'][$h]); $_SESSION['vfok'][$h] = time(); vf_log((int)$d['id'], 'vf_ok'); return [true, '']; }
    $_SESSION['vf'][$h]['tries'] = (int)$o['tries'] + 1; rate_hit('vfbad:' . client_ip()); vf_log((int)$d['id'], 'vf_bad');
    $left = 5 - (int)$_SESSION['vf'][$h]['tries'];
    return [false, $left > 0 ? 'رمز اشتباه است. ' . fa_num($left) . ' بارِ دیگر می‌توانید امتحان کنید.' : 'رمز ۵ بار اشتباه زده شد؛ رمزِ تازه بخواهید.'];
}
function vf_pass_ok(array $d): bool { $t = (int)($_SESSION['vfok']['vf' . $d['id']] ?? 0); return $t && time() - $t < VF_PASS_MIN * 60; }
/** باز کردنِ لینکِ رمزدار: رمزِ ناقص تلاش حساب نمی‌شود؛ ۵ اشتباه ← قفل */
function doc_share_open(string $t, string $pin): array {
    $s = row("SELECT * FROM doc_shares WHERE token=?", [$t]); if (!$s) return ['err' => 'این لینک وجود ندارد.', 'code' => 404];
    if ($s['dead']) { vf_log((int)$s['doc_id'], 'sh_blocked', ['share_id' => $s['id']]); return ['err' => $s['dead'] === 'locked' ? 'این لینک به دلیلِ ۵ بار رمزِ اشتباه غیرفعال شده است؛ از فرستنده لینکِ تازه بخواهید.' : 'این لینک توسطِ فرستنده غیرفعال شده است.', 'code' => 410]; }
    if ($s['expires_at'] && $s['expires_at'] < now()) return ['err' => 'اعتبارِ این لینک تمام شده است.', 'code' => 410];
    $key = 'sh' . $s['id'];
    if (!empty($_SESSION['shok'][$key]) && time() - (int)$_SESSION['shok'][$key] < VF_PASS_MIN * 60) return ['doc' => doc_get((int)$s['doc_id']), 'share' => $s];
    if ($pin === '') { vf_log((int)$s['doc_id'], 'sh_open', ['share_id' => $s['id']]); return ['need_pass' => true]; }
    $pin = preg_replace('/\D/', '', en_num($pin));
    if (!preg_match('/^\d{4,5}$/', $pin)) return ['need_pass' => true, 'err' => 'رمز را کامل وارد کنید (این تلاش حساب نشد).'];
    if (rate_count('shbad:' . client_ip(), 3600) >= 20) return ['err' => 'تعدادِ تلاش‌های ناموفق از این اینترنت زیاد است؛ یک ساعتِ دیگر امتحان کنید.', 'code' => 429];
    if ((int)$s['max_uses'] > 0 && (int)$s['views'] >= (int)$s['max_uses']) return ['err' => 'سقفِ دفعاتِ استفاده از این لینک پر شده است.', 'code' => 410];
    if (password_verify($pin, (string)$s['pass_hash'])) { q("UPDATE doc_shares SET views=views+1, last_at=? WHERE id=?", [now(), $s['id']]); $_SESSION['shok'][$key] = time(); vf_log((int)$s['doc_id'], 'sh_ok', ['share_id' => $s['id']]); return ['doc' => doc_get((int)$s['doc_id']), 'share' => $s]; }
    rate_hit('shbad:' . client_ip()); $f = (int)$s['fails'] + 1;
    upd('doc_shares', ['fails' => $f, 'dead' => $f >= 5 ? 'locked' : null], 'id=?', [$s['id']]);
    vf_log((int)$s['doc_id'], $f >= 5 ? 'sh_locked' : 'sh_bad', ['share_id' => $s['id']]);
    return $f >= 5 ? ['err' => 'رمز ۵ بار اشتباه زده شد و لینک غیرفعال شد.', 'code' => 410] : ['need_pass' => true, 'err' => 'رمز اشتباه است. ' . fa_num(5 - $f) . ' بارِ دیگر می‌توانید امتحان کنید.'];
}

// ── چاپ: قالب‌های اینورا (buildCtx، itemsTable، totalsBox، tFormal/tBand/tMinimal/tFrame/tOfficial)
function doc_ctx(array $d): array {
    $s = $d['seller'] ?: doc_seller(); $cur = $d['currency'] ?? 'IRR'; $fa = doc_cfg('doc_digits') !== 'en';
    $t = doc_calc($d); $o = $d['opts'];
    $D = fn($x) => $fa ? fa_num((string)$x) : (string)$x;
    $f = fn($n) => doc_money($n, $cur, $fa); $q = fn($n) => doc_qty($n, $fa);
    $isPf = $d['kind'] === 'proforma';
    $title = $isPf ? (string)doc_cfg('proforma_title') : ($d['kind'] === 'return' ? 'فاکتورِ برگشت از فروش' : (string)doc_cfg('invoice_title'));
    $v = $isPf && doc_cfg('doc_show_validity') && (int)($o['valid_days'] ?? 0) > 0 ? doc_valid_until($d) : null;
    $number = $d['number'] ?: ($d['status'] === 'draft' ? peek_seq($d['kind'], $d['d']) : '');
    $hasDisc = (bool)array_filter($t['lines'], fn($l) => $l['disc'] > 0); $hasUnit = (bool)array_filter($d['lines'], fn($i) => trim((string)($i['unit'] ?? '')) !== '');
    $curName = doc_currencies()[$cur]['name'];
    $rows = [['جمع کل', $f($t['sub'])]];
    if ($t['lineDisc']) $rows[] = ['تخفیفِ ردیف‌ها', $f($t['lineDisc'])];
    if ($t['docDisc']) $rows[] = ['تخفیف' . (($o['doc_disc_type'] ?? '') === '%' ? ' (' . $D($o['doc_disc']) . '٪)' : ''), $f($t['docDisc'])];
    if ($t['lineDisc'] || $t['docDisc']) $rows[] = ['جمع پس از تخفیف', $f($t['afterDisc'])];
    if ($t['vat_on']) $rows[] = ['مالیات و عوارضِ ارزش افزوده (' . $D($t['vat_pct']) . '٪)', $f($t['vat'])];
    if ($t['extra']) $rows[] = [($o['extra_label'] ?? '') ?: 'سایر هزینه‌ها', $f($t['extra'])];
    if ($t['roundAdj']) $rows[] = [$t['roundAdj'] < 0 ? 'کسر بابتِ گرد کردن' : 'افزوده بابتِ گرد کردن', $f(abs($t['roundAdj']))];
    $grand = ['مبلغِ قابلِ پرداخت (' . $curName . ')', $f($t['total'])];
    $seller = array_values(array_filter([['شناسهٔ ملی', $s['national_id'] ?? ''], ['کدِ اقتصادی', $s['economic_code'] ?? ''], ['شمارهٔ ثبت', $s['reg_no'] ?? ''], ['کدِ پستی', $s['postal_code'] ?? ''], ['تلفن', $s['phone'] ?? ''], ['نمابر', $s['fax'] ?? '']], fn($x) => trim((string)$x[1]) !== ''));
    $b = $d['buyer'] ?: [];
    $buyer = array_values(array_filter([['شناسه / کدِ ملی', $b['national_id'] ?? ''], ['کدِ اقتصادی', $b['economic_code'] ?? ''], ['کدِ پستی', $b['postal_code'] ?? ''], ['تلفن', ($b['phone'] ?? '') ?: ($b['mobile'] ?? '')]], fn($x) => trim((string)$x[1]) !== ''));
    $contact = array_values(array_filter([$s['address'] ?? '', ($s['phone'] ?? '') ? 'تلفن ' . $s['phone'] : '', ($s['mobile'] ?? '') ? 'همراه ' . $s['mobile'] : '', $s['email'] ?? '', $s['website'] ?? '']));
    return compact('d', 's', 't', 'o', 'D', 'f', 'q', 'isPf', 'title', 'v', 'number', 'hasDisc', 'hasUnit', 'curName', 'rows', 'grand', 'seller', 'buyer', 'contact') + ['words' => doc_cfg('doc_show_words') ? money_words_cur($t['total'], $cur) : '', 'foot' => h((string)setting('doc_footer', ''))];
}
function doc_kvs(array $arr, callable $D): string { return implode('', array_map(fn($x) => '<div class="kv"><span>' . h($x[0]) . ':</span> <span class="ltr" style="color:inherit">' . h($D($x[1])) . '</span></div>', $arr)); }
function doc_items_table(array $c, bool $withFoot): string {
    ['d' => $d, 't' => $t, 'D' => $D, 'f' => $f, 'q' => $q, 'hasDisc' => $hd, 'hasUnit' => $hu, 'curName' => $cn] = $c;
    $cols = 3 + ($hu ? 1 : 0) + 2 + ($hd ? 2 : 0);
    $h = '<table class="items"><thead><tr><th class="n">ردیف</th><th>شرحِ کالا / خدمات</th><th class="n">مقدار</th>' . ($hu ? '<th class="n">واحد</th>' : '') . '<th class="n">مبلغِ واحد<br><small>(' . h($cn) . ')</small></th><th class="n">مبلغِ کل<br><small>(' . h($cn) . ')</small></th>' . ($hd ? '<th class="n">تخفیف</th><th class="n">پس از تخفیف</th>' : '') . '</tr></thead><tbody>';
    foreach ($d['lines'] as $i => $it) { $l = $t['lines'][$i];
        $h .= '<tr data-amt="' . ($hd ? $l['line_net'] : $l['gross']) . '"><td class="n">' . $D($i + 1) . '</td><td><div>' . h($it['name'] ?? '') . '</div>' . (trim((string)($it['desc'] ?? '')) !== '' ? '<div class="idesc">' . h($it['desc']) . '</div>' : '') . doc_fx_note($it) . '</td><td class="n">' . $q($it['qty']) . '</td>'
            . ($hu ? '<td class="n">' . h($it['unit'] ?? '') . '</td>' : '') . '<td class="m">' . $f($it['price']) . '</td><td class="m">' . $f($l['gross']) . '</td>' . ($hd ? '<td class="m">' . ($l['disc'] ? $f($l['disc']) : '—') . '</td><td class="m">' . $f($l['line_net']) . '</td>' : '') . '</tr>'; }
    $h .= '</tbody>';
    if ($withFoot) { $span = $cols - 1; $h .= '<tfoot>' . implode('', array_map(fn($r) => '<tr><td colspan="' . $span . '" class="l">' . h($r[0]) . '</td><td class="m">' . $r[1] . '</td></tr>', $c['rows'])) . '<tr class="grand"><td colspan="' . $span . '" class="l">' . h($c['grand'][0]) . '</td><td class="m">' . $c['grand'][1] . '</td></tr></tfoot>'; }
    return $h . '</table>';
}
function doc_fx_note(array $it): string { return !empty($it['fx']) ? '<small class="fxnote">' . fa_num(rtrim(rtrim(number_format((float)$it['fx']['usd'], 2), '0'), '.')) . ' دلار × نرخ ' . fa_money($it['fx']['rate']) . (!empty($it['fx']['markup']) ? ' + ' . fa_num((string)$it['fx']['markup']) . '٪' : '') . '</small>' : ''; }
function doc_totals_box(array $c, string $cls): string { return '<div class="' . $cls . '">' . implode('', array_map(fn($r) => '<div class="row"><span>' . h($r[0]) . '</span><b>' . $r[1] . '</b></div>', $c['rows'])) . '<div class="row grand"><span>' . h($c['grand'][0]) . '</span><b>' . $c['grand'][1] . '</b></div></div>'; }
function doc_notes_html(array $c): string { $o = $c['o']; $h = ''; if (trim((string)($o['notes'] ?? '')) !== '') $h .= '<div class="notes"><b>توضیحات</b>' . h($o['notes']) . '</div>'; if (trim((string)($o['terms'] ?? '')) !== '') $h .= '<div class="notes"><b>شرایط و ملاحظات</b>' . h($o['terms']) . '</div>'; return $h; }
function doc_bank_html(array $c): string {
    if (!doc_cfg('doc_show_bank') || ($c['isPf'] && !doc_cfg('doc_show_bank_pf'))) return '';
    $ids = $c['o']['bank_ids'] ?? []; $bs = $ids ? rows("SELECT * FROM bank_accounts WHERE id IN (" . in_list($ids) . ")") : rows("SELECT * FROM bank_accounts WHERE active=1 ORDER BY id LIMIT 1");
    $rows = [];
    foreach ($bs as $b) { $a = array_filter([['صاحبِ حساب', $b['owner']], ['شمارهٔ حساب', $b['account_no']], ['شمارهٔ شبا', $b['iban']], ['شمارهٔ کارت', $b['card_no']]], fn($x) => trim((string)$x[1]) !== '');
        if (!$b['bank'] && !$a) continue; $rows[] = '<div class="acc">' . ($b['bank'] ? '<div><span>بانک:</span> <b>' . h($c['D']($b['bank'])) . '</b></div>' : '') . implode('', array_map(fn($x) => '<div><span>' . $x[0] . ':</span> <b class="ltr">' . h($c['D']($x[1])) . '</b></div>', $a)) . '</div>'; }
    return $rows ? '<div class="bank' . (count($rows) > 1 ? ' multi' : '') . '">' . implode('', $rows) . '</div>' : '';
}
function doc_valid_html(array $c): string { return $c['isPf'] && $c['v'] ? '<div class="valid">این پیش‌فاکتور تا تاریخِ ' . jdate($c['v']) . ' (' . $c['D']($c['o']['valid_days']) . ' روز) معتبر است.</div>' : ''; }
function doc_seal_on(array $c, string $k): bool { $v = $c['o'][$k . '_on'] ?? null; return $v === null ? (bool)doc_cfg($k === 'stamp' ? 'doc_show_stamp' : 'doc_show_sig') : (bool)$v; }
function doc_signs_html(array $c): string {
    $d = $c['d']; $qr = $d['status'] !== 'draft' && $d['verify_token'] ? '<div class="vqr"><div class="qr" data-qr="' . h(vf_url($d['verify_token'])) . '"></div><span>تأییدِ اصالتِ سند:<br>اسکن کنید</span></div>' : '';
    $st = setting('biz_stamp', ''); $sg = setting('biz_sign', '');
    return '<div class="signs' . ($qr ? ' has-qr' : '') . '">' . $qr . '<div class="sign"><div class="lab">مهر و امضای فروشنده</div>' . (doc_seal_on($c, 'stamp') && $st ? '<img class="stamp" src="' . h($st) . '" alt="">' : '') . (doc_seal_on($c, 'sig') && $sg ? '<img class="sig" src="' . h($sg) . '" alt="">' : '') . '</div><div class="sign"><div class="lab">مهر و امضای خریدار</div></div></div>';
}
function doc_wm(array $c): string {
    $d = $c['d'];
    if ($d['status'] === 'void') return '<div class="wm">باطل</div>';
    if ($d['status'] === 'draft') return '<div class="wm">پیش‌نویس</div>';
    return $c['isPf'] && doc_cfg('doc_show_watermark') ? '<div class="wm">' . h((string)doc_cfg('proforma_title')) . '</div>' : '';
}
function doc_logo(): string { $l = setting('biz_logo', ''); return $l ? '<img src="' . h($l) . '" alt="">' : ''; }
function doc_meta(array $c): array { $m = [['شماره', $c['number']], ['تاریخ', jdate($c['d']['d'], false, false)]]; if ($c['isPf'] && $c['v']) $m[] = ['اعتبار تا', jdate($c['v'], false, false)]; return $m; }
function t_formal(array $c): string {
    ['s' => $s, 'd' => $d, 'D' => $D] = $c; $b = $d['buyer'];
    return '<header class="f-head"><div class="f-logo">' . doc_logo() . '</div><div class="f-title"><h1>' . h($c['title']) . '</h1><div class="f-co">' . h($s['name']) . '</div>' . (doc_cfg('doc_header_text') ? '<div class="f-sub">' . h((string)doc_cfg('doc_header_text')) . '</div>' : '') . '</div>'
        . '<table class="f-meta">' . implode('', array_map(fn($m) => '<tr><th>' . $m[0] . '</th><td><span class="ltr">' . h($D($m[1])) . '</span></td></tr>', doc_meta($c))) . '</table></header>'
        . (doc_cfg('doc_show_seller') ? '<section class="f-box"><div class="f-cap">فروشنده</div><div class="f-grid"><div class="kv"><span>نام:</span> <b>' . h($s['name']) . '</b></div>' . doc_kvs($c['seller'], $D) . (($s['address'] ?? '') ? '<div class="kv wide"><span>نشانی:</span> ' . h($D($s['address'])) . '</div>' : '') . '</div></section>' : '')
        . '<section class="f-box"><div class="f-cap">خریدار</div><div class="f-grid"><div class="kv"><span>نام:</span> <b>' . h(($b['name'] ?? '') ?: '—') . '</b></div>' . doc_kvs($c['buyer'], $D) . (($b['address'] ?? '') ? '<div class="kv wide"><span>نشانی:</span> ' . h($D($b['address'])) . '</div>' : '') . '</div></section>'
        . '<div class="f-strip">مشخصاتِ کالا یا خدماتِ موردِ معامله</div>' . doc_items_table($c, true)
        . ($c['words'] ? '<div class="f-words">مبلغ به حروف: ' . h($c['words']) . '</div>' : '')
        . (($x = doc_valid_html($c) . doc_notes_html($c) . doc_bank_html($c)) !== '' ? '<div class="f-notes">' . $x . '</div>' : '')
        . '<div class="push"></div>' . doc_signs_html($c) . ($c['foot'] || $c['contact'] ? '<footer class="f-foot">' . ($c['foot'] ? $c['foot'] . '<br>' : '') . h($D(implode(' · ', $c['contact']))) . '</footer>' : '');
}
function t_band(array $c): string {
    ['s' => $s, 'd' => $d, 'D' => $D] = $c; $b = $d['buyer'];
    return '<header class="b-band"><div class="b-brand"><div class="b-logo">' . (doc_logo() ?: h(mb_substr(trim($s['name'] ?: '?'), 0, 1))) . '</div><div><h1>' . h($s['name']) . '</h1>' . (doc_cfg('doc_header_text') ? '<div class="b-slogan">' . h((string)doc_cfg('doc_header_text')) . '</div>' : '') . '</div></div>'
        . '<div class="b-doc"><div class="b-type">' . h($c['title']) . '</div><div class="b-pills">' . implode('', array_map(fn($m) => '<span>' . $m[0] . ': <b class="ltr">' . h($D($m[1])) . '</b></span>', doc_meta($c))) . '</div></div></header><div class="b-body">'
        . '<div class="b-parties">' . (doc_cfg('doc_show_seller') ? '<div class="b-party"><h3>فروشنده</h3><div class="nm">' . h($s['name']) . '</div>' . doc_kvs($c['seller'], $D) . (($s['address'] ?? '') ? '<div class="kv"><span>نشانی:</span> ' . h($D($s['address'])) . '</div>' : '') . '</div>' : '<div></div>')
        . '<div class="b-party"><h3>خریدار</h3><div class="nm">' . h(($b['name'] ?? '') ?: '—') . '</div>' . doc_kvs($c['buyer'], $D) . (($b['address'] ?? '') ? '<div class="kv"><span>نشانی:</span> ' . h($D($b['address'])) . '</div>' : '') . '</div></div>'
        . doc_items_table($c, false) . '<div class="b-sum"><div class="b-left">' . ($c['words'] ? '<div class="b-words">مبلغ به حروف: ' . h($c['words']) . '</div>' : '') . doc_valid_html($c) . doc_notes_html($c) . doc_bank_html($c) . '</div>' . doc_totals_box($c, 'b-totals') . '</div>'
        . '<div class="push"></div>' . doc_signs_html($c) . '</div><footer class="b-foot"><span>' . h($D(implode(' · ', $c['contact']))) . '</span>' . ($c['foot'] ? '<span>' . $c['foot'] . '</span>' : '') . '</footer>';
}
function t_minimal(array $c): string {
    ['s' => $s, 'd' => $d, 'D' => $D] = $c; $b = $d['buyer'];
    return '<header class="m-head"><div><div class="m-name">' . h($s['name']) . '</div>' . (doc_cfg('doc_header_text') ? '<div class="m-sub">' . h((string)doc_cfg('doc_header_text')) . '</div>' : '') . ($c['contact'] ? '<div class="m-contact">' . h($D(implode(' · ', $c['contact']))) . '</div>' : '') . '</div><div class="m-logo">' . doc_logo() . '</div></header>'
        . '<div class="m-titlerow"><div class="m-title">' . h($c['title']) . '</div><div class="m-meta">' . implode('', array_map(fn($m) => '<div><span>' . $m[0] . ' </span><b class="ltr">' . h($D($m[1])) . '</b></div>', doc_meta($c))) . '</div></div>'
        . '<div class="m-parties"><div class="m-party"><div class="m-lab">خریدار</div><span class="nm">' . h(($b['name'] ?? '') ?: '—') . '</span>' . doc_kvs($c['buyer'], $D) . (($b['address'] ?? '') ? '<div class="kv">' . h($D($b['address'])) . '</div>' : '') . '</div>'
        . (doc_cfg('doc_show_seller') ? '<div class="m-party"><div class="m-lab">فروشنده</div>' . doc_kvs(array_values(array_filter($c['seller'], fn($x) => !preg_match('/تلفن|نمابر/u', $x[0]))), $D) . '</div>' : '<div></div>') . '</div>'
        . doc_items_table($c, false) . '<div class="m-sum"><div class="stack" style="gap:2.4mm">' . ($c['words'] ? '<div class="m-words">' . h($c['words']) . '</div>' : '') . doc_valid_html($c) . doc_notes_html($c) . doc_bank_html($c) . '</div>' . doc_totals_box($c, 'm-totals') . '</div>'
        . '<div class="push"></div>' . doc_signs_html($c) . ($c['foot'] ? '<footer class="m-foot">' . $c['foot'] . '</footer>' : '');
}
function t_frame(array $c): string {
    ['s' => $s, 'd' => $d, 'D' => $D] = $c; $b = $d['buyer']; $ss = (bool)doc_cfg('doc_show_seller');
    return '<div class="fr-in"><header class="fr-head">' . doc_logo() . '<div class="fr-name">' . h($s['name']) . '</div>' . (doc_cfg('doc_header_text') ? '<div class="fr-sub">' . h((string)doc_cfg('doc_header_text')) . '</div>' : '') . '<div class="fr-label">' . h($c['title']) . '</div></header>'
        . '<div class="fr-meta">' . implode('', array_map(fn($m) => '<div><span>' . $m[0] . ': </span><b class="ltr">' . h($D($m[1])) . '</b></div>', doc_meta($c))) . '</div>'
        . '<div class="fr-parties">' . ($ss ? '<div class="fr-box"><h3>فروشنده</h3><div class="nm">' . h($s['name']) . '</div>' . doc_kvs($c['seller'], $D) . (($s['address'] ?? '') ? '<div class="kv"><span>نشانی:</span> ' . h($D($s['address'])) . '</div>' : '') . '</div>' : '')
        . '<div class="fr-box"' . ($ss ? '' : ' style="grid-column:1/-1"') . '><h3>خریدار</h3><div class="nm">' . h(($b['name'] ?? '') ?: '—') . '</div>' . doc_kvs($c['buyer'], $D) . (($b['address'] ?? '') ? '<div class="kv"><span>نشانی:</span> ' . h($D($b['address'])) . '</div>' : '') . '</div></div>'
        . doc_items_table($c, false) . '<div class="fr-bottom"><div class="fr-left">' . ($c['words'] ? '<div class="fr-words">مبلغ به حروف: ' . h($c['words']) . '</div>' : '') . doc_valid_html($c) . doc_notes_html($c) . doc_bank_html($c) . '</div>' . doc_totals_box($c, 'fr-totals') . '</div>'
        . '<div class="push"></div>' . doc_signs_html($c) . ($c['foot'] || $c['contact'] ? '<footer class="fr-foot">' . ($c['foot'] ? $c['foot'] . '<br>' : '') . h($D(implode(' · ', $c['contact']))) . '</footer>' : '') . '</div>';
}
function t_official(array $c): string {
    ['s' => $s, 'd' => $d, 'D' => $D, 't' => $t, 'f' => $f, 'o' => $o] = $c; $b = $d['buyer']; $ol = $t['off'] ?? [];
    $subj = ($d['subject'] ?? 'main') !== 'main' ? (doc_subjects()[$d['subject']] ?? '') : '';
    $ref = $d['ref_doc_id'] ? doc_get((int)$d['ref_doc_id']) : null;
    $meta = [['شمارهٔ سریال', $c['number']], ['تاریخِ صدور', jdate($d['d'], false, false)]]; if (trim((string)($o['tax_id'] ?? '')) !== '') $meta[] = ['شمارهٔ منحصربه‌فردِ مالیاتی', $o['tax_id']];
    $kv = fn($l, $v, $w = false) => trim((string)$v) !== '' ? '<div class="kv' . ($w ? ' wide' : '') . '"><span>' . $l . ':</span> ' . ($w ? h($D($v)) : '<span class="ltr" style="color:inherit">' . h($D($v)) . '</span>') . '</div>' : '';
    $place = fn($x) => implode('، ', array_filter([$x['province'] ?? '', $x['city'] ?? '']));
    $sum = fn($k) => array_sum(array_column($ol, $k));
    $rows = '';
    foreach ($d['lines'] as $i => $it) { $x = $ol[$i] ?? ['gross' => 0, 'disc' => 0, 'net' => 0, 'tax' => 0, 'total' => 0];
        $rows .= '<tr data-amt="' . $x['total'] . '"><td class="n">' . $D($i + 1) . '</td><td class="n" style="direction:ltr;word-break:break-all;white-space:normal;font-size:.95em">' . h($D($it['code'] ?? '')) . '</td><td><div>' . h($it['name']) . '</div>' . (trim((string)($it['desc'] ?? '')) !== '' ? '<div class="idesc">' . h($it['desc']) . '</div>' : '') . '</td><td class="n">' . $c['q']($it['qty']) . '</td><td class="n">' . h($it['unit'] ?? '') . '</td><td class="m">' . $f($it['price']) . '</td><td class="m">' . $f($x['gross']) . '</td><td class="m">' . ($x['disc'] ? $f($x['disc']) : $D(0)) . '</td><td class="m">' . $f($x['net']) . '</td><td class="m">' . $f($x['tax']) . '</td><td class="m">' . $f($x['total']) . '</td></tr>'; }
    $chk = fn($on) => '<span style="display:inline-block;width:3.2mm;height:3.2mm;border:.25mm solid #333;margin:0 1mm;vertical-align:middle;text-align:center;line-height:3mm;font-size:.8em">' . ($on ? '✓' : '') . '</span>';
    $type2 = ($o['off_kind'] ?? 'type1') === 'type2';
    return '<header class="f-head"><div class="f-logo">' . doc_logo() . '</div><div class="f-title"><h1>' . h((string)doc_cfg('off_title') . ($subj ? ' (' . $subj . ')' : '')) . '</h1><div class="f-sub">' . ($type2 ? 'صورتحسابِ نوعِ دوم' : 'صورتحسابِ نوعِ اول') . ($ref ? ' · مرجع: صورتحسابِ شمارهٔ ' . h($D($ref['number'])) . ' مورخ ' . jdate($ref['d']) . (!empty($ref['opts']['tax_id']) ? ' (شمارهٔ مالیاتی ' . h($ref['opts']['tax_id']) . ')' : '') : '') . '</div></div>'
        . '<table class="f-meta">' . implode('', array_map(fn($m) => '<tr><th>' . $m[0] . '</th><td><span class="ltr">' . h($D($m[1])) . '</span></td></tr>', $meta)) . '</table></header>'
        . '<section class="f-box"><div class="f-cap">مشخصاتِ فروشنده</div><div class="f-grid"><div class="kv"><span>نامِ شخصِ حقیقی/حقوقی:</span> <b>' . h($s['name']) . '</b></div>' . $kv('شمارهٔ اقتصادی', $s['economic_code'] ?? '') . $kv(($s['kind'] ?? '') === 'natural' ? 'کدِ ملی' : 'شناسهٔ ملی', $s['national_id'] ?? '')
        . $kv('شمارهٔ ثبت', $s['reg_no'] ?? '') . $kv('استان/شهر', $place($s)) . $kv('کدِ پستی', $s['postal_code'] ?? '') . $kv('تلفن/نمابر', implode(' / ', array_filter([$s['phone'] ?? '', $s['fax'] ?? '']))) . $kv('کدِ شعبه', $s['branch_code'] ?? '') . $kv('نشانی', $s['address'] ?? '', true) . '</div></section>'
        . '<section class="f-box"><div class="f-cap">مشخصاتِ خریدار</div><div class="f-grid">' . ($type2 ? '<div class="kv wide"><b>فروش به مصرف‌کنندهٔ نهایی</b>' . (trim((string)($b['name'] ?? '')) !== '' ? ' — ' . h($b['name']) : '') . '</div>'
        : '<div class="kv"><span>نامِ شخصِ حقیقی/حقوقی:</span> <b>' . h(($b['name'] ?? '') ?: '—') . '</b></div>' . $kv('نوع', buyer_kinds()[$b['kind'] ?? ''] ?? '') . $kv('شمارهٔ اقتصادی', $b['economic_code'] ?? '') . $kv(($b['kind'] ?? '') === 'natural' ? 'کدِ ملی' : 'شناسهٔ ملی', $b['national_id'] ?? '') . $kv('شمارهٔ ثبت', $b['reg_no'] ?? '') . $kv('استان/شهر', $place($b)) . $kv('کدِ پستی', $b['postal_code'] ?? '') . $kv('تلفن', ($b['phone'] ?? '') ?: ($b['mobile'] ?? '')) . $kv('نشانی', $b['address'] ?? '', true)) . '</div></section>'
        . '<div class="f-strip">مشخصاتِ کالا یا خدماتِ موردِ معامله</div><table class="items off-items"><colgroup><col style="width:6mm"><col style="width:19mm"><col><col style="width:9mm"><col style="width:10mm"><col style="width:17mm"><col style="width:20mm"><col style="width:14mm"><col style="width:20mm"><col style="width:17mm"><col style="width:21mm"></colgroup>'
        . '<thead><tr><th class="n">ردیف</th><th class="n">شناسهٔ کالا/خدمت</th><th>شرحِ کالا یا خدمات</th><th class="n">مقدار</th><th class="n">واحد</th><th class="n">مبلغِ واحد (ریال)</th><th class="n">مبلغِ کل (ریال)</th><th class="n">مبلغِ تخفیف</th><th class="n">مبلغِ کل پس از تخفیف</th><th class="n">جمعِ مالیات و عوارض</th><th class="n">جمعِ کل پس از تخفیف، مالیات و عوارض</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody><tfoot><tr class="grand"><td colspan="6" class="l">جمعِ کل</td><td class="m">' . $f($sum('gross')) . '</td><td class="m">' . $f($sum('disc')) . '</td><td class="m">' . $f($sum('net')) . '</td><td class="m">' . $f($sum('tax')) . '</td><td class="m">' . $f($sum('total')) . '</td></tr></tfoot></table>'
        . '<div class="f-words">مبلغِ قابلِ پرداخت: ' . $f($t['total']) . ' ریال' . ($c['words'] ? ' — به حروف: ' . h($c['words']) : '') . ($t['vat_on'] ? ' · نرخِ مالیات و عوارض: ' . $D($t['vat_pct']) . '٪' : '') . '</div>'
        . '<div class="f-notes"><div>شرایط و نحوهٔ فروش: نقدی ' . $chk(($o['settle'] ?? 'cash') !== 'credit') . ' غیرنقدی ' . $chk(($o['settle'] ?? 'cash') !== 'cash') . '</div>' . doc_notes_html($c) . doc_bank_html($c) . '</div>'
        . '<div class="push"></div>' . doc_signs_html($c) . ($c['foot'] ? '<footer class="f-foot">' . $c['foot'] . '</footer>' : '');
}
function doc_style_vars(): string { return '--c:' . h((string)doc_cfg('doc_color')) . ';--ft:\'Vazirmatn\';--fb:\'Vazirmatn\';--fs:' . (float)doc_cfg('doc_font_size') . 'pt'; }
/** renderPaper0: یک برگهٔ کامل؛ صفحه‌بندیِ A4 را doc-paper.js در مرورگر انجام می‌دهد (همان pgSplit اینورا) */
function doc_render_html(array $d): string {
    $c = doc_ctx($d); $cur = $d['currency'] ?? 'IRR';
    $data = ' data-cur="' . h($cur) . '" data-fa="' . (doc_cfg('doc_digits') !== 'en' ? 1 : 0) . '"';
    if ($d['kind'] === 'official') return '<div class="paper t-formal t-off" style="' . doc_style_vars() . '"' . $data . '>' . ($d['off_canceled'] ? '<div class="wm">ابطال شده</div>' : ($d['status'] !== 'issued' ? doc_wm($c) : '')) . t_official($c) . '</div>';
    $tpl = (string)doc_cfg('doc_template'); $fn = ['formal' => 't_formal', 'band' => 't_band', 'minimal' => 't_minimal', 'frame' => 't_frame'][$tpl] ?? 't_formal';
    $cls = ['formal' => 'formal', 'band' => 'band', 'minimal' => 'min', 'frame' => 'frame'][$tpl] ?? 'formal';
    return '<div class="paper t-' . $cls . '" style="' . doc_style_vars() . '"' . $data . '>' . doc_wm($c) . $fn($c) . '</div>';
}
function doc_assets_html(): string { return '<script src="assets/vendor/qrcode.js"></script><script src="assets/doc-paper.js?v=' . APP_VERSION . '"></script>'; }
function doc_print_page(array $d, string $html = ''): string {
    return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h(($d['number'] ?? '') ?: 'سند') . '</title><style>@font-face{font-family:Vazirmatn;src:url(assets/fonts/Vazirmatn.woff2) format("woff2");font-weight:100 900}</style>'
        . '<link rel="stylesheet" href="assets/doc.css?v=' . APP_VERSION . '"></head><body class="docs">' . ($html ?: doc_render_html($d)) . doc_assets_html()
        . '<script>if(location.search.indexOf("autoprint=1")>0)window.addEventListener("load",function(){setTimeout(function(){print()},500)});</script></body></html>';
}
