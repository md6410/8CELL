<?php
// فاکتورساز: محاسبه (یک تابع برای صندوق، فاکتور و گزارش)، اعتبارسنجی، شماره‌گذاری، قفل، نسخه، چاپِ A4 و لینکِ رمزدار
declare(strict_types=1);

function doc_kinds(): array { return ['proforma' => 'پیش‌فاکتور', 'invoice' => 'فاکتورِ فروش', 'official' => 'صورتحسابِ رسمی', 'return' => 'برگشت از فروش']; }
function doc_subjects(): array { return ['main' => 'اصلی', 'fix' => 'اصلاحی', 'void' => 'ابطالی', 'return' => 'برگشت از فروش']; }
function moadian_states(): array { return ['none' => 'ثبت‌نشده', 'sent' => 'ارسال‌شده', 'ok' => 'تأییدشده', 'rejected' => 'ردشده']; }

/** قیمتِ دلاری لحظهٔ صدور قفل می‌شود */
function fx_item_price(float $usd, int $rate, float $pct = 0, int $round = 0): int { $p = $usd * $rate * (1 + $pct / 100); return $round > 0 ? (int)(round($p / $round) * $round) : (int)round($p); }
function round_money(int $n, int $step): int { return $step > 0 ? (int)(round($n / $step) * $step) : $n; }

/**
 * ترتیبِ ثابت: ردیف ← تخفیفِ ردیف ← تخفیفِ کل (به نسبت) ← مالیات (جمعِ مالیاتِ ردیف‌ها) ← سایر ← گردکردنِ جمع
 * lines: [title, qty(پایه), unit_id, price, disc, tax_exempt]
 */
function calc_doc(array $lines, array $o = []): array {
    $vat = (float)($o['vat_pct'] ?? vat_pct()); $mode = (string)($o['tax_round'] ?? tax_round_mode());
    $out = []; $sub = 0; $lineDisc = 0;
    foreach (array_values($lines) as $i => $l) {
        $qty = (int)($l['qty'] ?? 0); $price = (int)($l['price'] ?? 0);
        if ($qty <= 0) throw new UserError('تعدادِ ردیفِ ' . fa_num($i + 1) . ' باید مثبت باشد');
        if ($price < 0) throw new UserError('قیمتِ ردیفِ ' . fa_num($i + 1) . ' منفی است');
        $gross = line_amount($qty, $price, isset($l['unit_id']) ? (int)$l['unit_id'] : null);
        $disc = max(0, (int)($l['disc'] ?? 0)); if ($disc > $gross) throw new UserError('تخفیفِ ردیفِ ' . fa_num($i + 1) . ' بیش از مبلغِ آن است');
        $out[$i] = $l + ['gross' => $gross]; $out[$i]['disc'] = $disc; $out[$i]['after'] = $gross - $disc;
        $sub += $gross; $lineDisc += $disc;
    }
    $afterLine = $sub - $lineDisc;
    $docDisc = max(0, (int)($o['doc_disc'] ?? 0)); if ($docDisc > $afterLine) throw new UserError('تخفیفِ فاکتور بیش از جمع است');
    $shares = overhead_split(array_map(fn($l) => $l['after'], $out), $docDisc);
    $base = 0; $tax = 0;
    foreach ($out as $i => $l) {
        $out[$i]['doc_disc_share'] = $shares[$i] ?? 0;
        $net = $l['after'] - ($shares[$i] ?? 0);
        $t = !empty($l['tax_exempt']) || $vat <= 0 ? 0 : round_by($net * $vat / 100, $mode);
        $out[$i]['net'] = $net; $out[$i]['tax'] = $t; $out[$i]['total'] = $net + $t;
        if (empty($l['tax_exempt'])) $base += $net;
        $tax += $t;
    }
    $afterDisc = $afterLine - $docDisc; $extra = (int)($o['extra'] ?? 0);
    $raw = $afterDisc + $tax + $extra;
    $total = round_money($raw, (int)($o['total_round'] ?? setting('total_round', 0)));
    return ['lines' => array_values($out), 'sub' => $sub, 'lineDisc' => $lineDisc, 'afterLine' => $afterLine, 'docDisc' => $docDisc, 'afterDisc' => $afterDisc,
        'taxableBase' => $base, 'vat' => $tax, 'vat_pct' => $vat, 'extra' => $extra, 'roundAdj' => $total - $raw, 'total' => $total, 'words' => money_words($total)];
}

/** شناسه‌ها */
function id_ok(string $kind, $v): bool { return $kind === 'legal' ? legal_id_ok($v) : national_code_ok($v); }
/** قواعدِ صورتحسابِ رسمی — همهٔ خطاها یک‌جا */
function official_issues(array $doc): array {
    $E = []; $W = []; $b = $doc['buyer'] ?? []; $s = $doc['seller'] ?? [];
    $off = ($doc['kind'] ?? '') === 'official';
    if (empty($s['national_id'])) { if ($off) $E[] = 'شناسهٔ ملیِ فروشنده در پروفایلِ کسب‌وکار ثبت نشده است'; else $W[] = 'شناسهٔ ملیِ فروشنده در پروفایلِ کسب‌وکار ثبت نشده است'; }
    elseif (!id_ok($s['kind'] ?? 'person', $s['national_id'])) $E[] = 'شناسهٔ ملیِ فروشنده معتبر نیست';
    if (empty($s['economic_code'])) $W[] = 'کدِ اقتصادیِ فروشنده خالی است';
    if (($doc['kind'] ?? '') === 'official') {
        if (empty($b['name'])) $E[] = 'نامِ خریدار لازم است';
        if (empty($b['national_id'])) $E[] = 'شناسه/کدِ ملیِ خریدار لازم است';
        elseif (!id_ok($b['kind'] ?? 'person', $b['national_id'])) $E[] = (($b['kind'] ?? 'person') === 'legal' ? 'شناسهٔ ملیِ' : 'کدِ ملیِ') . ' خریدار معتبر نیست';
        if (empty($b['postal_code']) || !preg_match('/^\d{10}$/', (string)$b['postal_code'])) $E[] = 'کدِ پستیِ ۱۰ رقمیِ خریدار لازم است';
        if (!isset(doc_subjects()[$doc['subject'] ?? 'main'])) $E[] = 'موضوعِ صورتحساب نامعتبر است';
        if (in_array($doc['subject'] ?? 'main', ['fix', 'void', 'return'], true) && empty($doc['ref_doc_id'])) $E[] = 'صورتحسابِ مرجع لازم است';
    }
    foreach (($doc['lines'] ?? []) as $i => $l) { if (trim((string)($l['title'] ?? '')) === '') $E[] = 'شرحِ ردیفِ ' . fa_num($i + 1) . ' خالی است'; }
    if (empty($doc['lines'])) $E[] = 'سند ردیف ندارد';
    return ['E' => $E, 'W' => $W];
}
function doc_counter_key(string $kind, string $d): string { return $kind . '-' . substr(jdate($d, false, false), 0, 4); }
function fmt_doc_number(string $pattern, string $d, int $seq, string $kind): string {
    $y = substr(jdate($d, false, false), 0, 4);
    $pre = ['proforma' => 'P', 'invoice' => '', 'official' => 'R', 'return' => 'B'][$kind] ?? '';
    return $pre . strtr($pattern, ['{y}' => $y, '{seq}' => (string)$seq, '{seq4}' => sprintf('%04d', $seq), '{seq5}' => sprintf('%05d', $seq), '{seq6}' => sprintf('%06d', $seq)]);
}
function peek_seq(string $kind, string $d): string { $k = doc_counter_key($kind, $d); $n = (int)val("SELECT last FROM doc_counters WHERE k=?", [$k]) + 1; return fmt_doc_number((string)setting('doc_pattern', '{y}-{seq5}'), $d, $n, $kind); }

function doc_get(int $id): ?array {
    $d = row("SELECT * FROM docs WHERE id=?", [$id]); if (!$d) return null;
    foreach (['buyer', 'seller', 'lines', 'opts', 'totals'] as $k) $d[$k] = json_decode((string)$d[$k], true) ?: [];
    return $d;
}
/** ذخیرهٔ پیش‌نویس (سندِ ثبت‌شده قفل است) */
function doc_save(array $in): int {
    $id = (int)($in['id'] ?? 0);
    if ($id) { $o = doc_get($id); if (!$o) throw new UserError('سند یافت نشد', 404); if ($o['status'] !== 'draft') throw new UserError('سندِ ثبت‌شده قفل است؛ برای تغییر نسخهٔ اصلاحی بسازید'); }
    $kind = isset(doc_kinds()[$in['kind'] ?? '']) ? $in['kind'] : 'invoice';
    $d = jparse($in['d'] ?? '') ?: today();
    $lines = [];
    foreach ((array)($in['lines'] ?? []) as $l) {
        if (trim((string)($l['title'] ?? '')) === '' && !(int)($l['product_id'] ?? 0)) continue;
        $unit = (int)($l['unit_id'] ?? 0) ?: ((int)($l['product_id'] ?? 0) ? ((int)val("SELECT unit_id FROM products WHERE id=?", [(int)$l['product_id']]) ?: null) : null); $price = to_int($l['price'] ?? 0);
        if (trim((string)($l['title'] ?? '')) === '' && (int)($l['product_id'] ?? 0)) $l['title'] = (string)val("SELECT name FROM products WHERE id=?", [(int)$l['product_id']]);
        if (!empty($l['usd'])) { $rate = to_int($l['rate'] ?? 0) ?: (int)(rate_today()['rate'] ?? 0); if (!$rate) throw new UserError('نرخِ دلار ثبت نشده است'); $price = fx_item_price(to_float($l['usd']), $rate, to_float($l['fx_pct'] ?? 0), (int)setting('fx_round', 0)); }
        $lines[] = ['product_id' => (int)($l['product_id'] ?? 0) ?: null, 'title' => trim((string)($l['title'] ?? '')), 'qty' => qty_in($l['qty'] ?? 1, $unit), 'unit_id' => $unit, 'price' => $price,
            'disc' => to_int($l['disc'] ?? 0), 'tax_exempt' => empty($l['tax_exempt']) ? 0 : 1, 'usd' => !empty($l['usd']) ? to_float($l['usd']) : null, 'rate' => !empty($l['usd']) ? ($rate ?? null) : null];
    }
    $opts = ['doc_disc' => to_int($in['doc_disc'] ?? 0), 'extra' => to_int($in['extra'] ?? 0), 'extra_title' => trim((string)($in['extra_title'] ?? '')), 'note' => trim((string)($in['note'] ?? '')), 'total_round' => to_int($in['total_round'] ?? setting('total_round', 0))];
    $calc = calc_doc($lines, $opts + ['vat_pct' => isset($in['vat_pct']) && $in['vat_pct'] !== '' ? to_float($in['vat_pct']) : vat_pct($d)]);
    $cust = (int)($in['customer_id'] ?? 0) ?: null;
    $buyer = $cust ? customer_snapshot($cust) : ['kind' => $in['buyer_kind'] ?? 'person', 'name' => trim((string)($in['buyer_name'] ?? '')), 'national_id' => preg_replace('/\D/', '', en_num((string)($in['buyer_national_id'] ?? ''))),
        'postal_code' => preg_replace('/\D/', '', en_num((string)($in['buyer_postal'] ?? ''))), 'address' => trim((string)($in['buyer_address'] ?? '')), 'mobile' => norm_mobile($in['buyer_mobile'] ?? ''), 'economic_code' => ''];
    $row = ['kind' => $kind, 'd' => $d, 'customer_id' => $cust, 'buyer' => json_encode($buyer, JSON_UNESCAPED_UNICODE), 'seller' => json_encode(doc_seller(), JSON_UNESCAPED_UNICODE),
        'lines' => json_encode($lines, JSON_UNESCAPED_UNICODE), 'opts' => json_encode($opts, JSON_UNESCAPED_UNICODE), 'totals' => json_encode(array_diff_key($calc, ['lines' => 1]), JSON_UNESCAPED_UNICODE), 'total' => $calc['total'],
        'subject' => $kind === 'official' ? ($in['subject'] ?? 'main') : null, 'ref_doc_id' => (int)($in['ref_doc_id'] ?? 0) ?: null, 'moadian' => $kind === 'official' ? 'none' : null];
    if ($id) { upd('docs', $row, 'id=?', [$id]); return $id; }
    $row += ['status' => 'draft', 'created_by' => current_user()['id'] ?? null, 'created_at' => now()];
    $id = ins('docs', $row); audit('docs', $id, 'create', null, ['kind' => $kind, 'total' => $calc['total']], 'پیش‌نویسِ سند');
    return $id;
}
function doc_seller(): array { $b = biz(); return ['name' => $b['name'], 'kind' => setting('biz_kind', 'person'), 'national_id' => $b['national_id'], 'economic_code' => $b['economic_code'], 'reg_no' => $b['reg_no'], 'postal_code' => $b['postal_code'], 'address' => $b['address'], 'phone' => $b['phone']]; }
/** ثبت: اعتبارسنجی ← شمارهٔ یکتا ← قفل ← نسخهٔ ۱ ← توکنِ اصالت */
function doc_commit(int $id): array {
    return tx(function () use ($id) {
        $d = doc_get($id); if (!$d) throw new UserError('سند یافت نشد', 404);
        if ($d['status'] !== 'draft') throw new UserError('این سند قبلاً ثبت شده است');
        fiscal_assert_open($d['d']);
        $iss = official_issues($d);
        if ($d['kind'] === 'official' && $iss['E']) throw new UserError(implode(' | ', $iss['E']));
        $k = doc_counter_key($d['kind'], $d['d']); $seq = doc_seq_next($k);
        $num = fmt_doc_number((string)setting('doc_pattern', '{y}-{seq5}'), $d['d'], $seq, $d['kind']);
        $tok = substr(bin2hex(random_bytes(12)), 0, 24);
        upd('docs', ['status' => 'issued', 'number' => $num, 'seq' => $seq, 'counter_key' => $k, 'verify_token' => $tok, 'issued_at' => now()], 'id=?', [$id]);
        doc_revision($id, 1);
        audit('docs', $id, 'issue', ['status' => 'draft'], ['status' => 'issued', 'number' => $num], 'ثبتِ سند');
        return ['number' => $num, 'token' => $tok, 'warnings' => $iss['W']];
    });
}
function doc_revision(int $id, int $rev): void {
    $d = row("SELECT * FROM docs WHERE id=?", [$id]); $data = json_encode($d, JSON_UNESCAPED_UNICODE);
    ins('doc_revisions', ['doc_id' => $id, 'revision' => $rev, 'data' => $data, 'hash' => hash('sha256', $data), 'user_id' => current_user()['id'] ?? null, 'at' => now()]);
}
/** نسخهٔ اصلاحی برای سندِ ثبت‌شدهٔ تأییدنشده: تغییر با ردپا */
function doc_amend(int $id, array $in): int {
    $d = doc_get($id); if (!$d || $d['status'] !== 'issued') throw new UserError('فقط سندِ ثبت‌شده اصلاح می‌شود');
    if ($d['confirmed']) throw new UserError('فاکتورِ تأییدشده دیگر عوض نمی‌شود');
    fiscal_assert_open($d['d']);
    return tx(function () use ($id, $d, $in) {
        upd('docs', ['status' => 'draft'], 'id=?', [$id]);
        $in['id'] = $id; $in['kind'] = $d['kind']; $in['d'] = jdate($d['d'], false, false);
        doc_save($in);
        $rev = (int)$d['revision'] + 1;
        upd('docs', ['status' => 'issued', 'revision' => $rev], 'id=?', [$id]);
        doc_revision($id, $rev);
        audit('docs', $id, 'amend', ['total' => $d['total']], ['total' => val("SELECT total FROM docs WHERE id=?", [$id])], 'اصلاحِ سند (نسخهٔ ' . $rev . ')');
        return $rev;
    });
}
function doc_void(int $id, string $reason): void {
    $d = doc_get($id); if (!$d) throw new UserError('سند یافت نشد'); if ($d['confirmed']) throw new UserError('فاکتورِ تأییدشده باطل نمی‌شود');
    if (trim($reason) === '') throw new UserError('دلیلِ ابطال لازم است');
    fiscal_assert_open($d['d']);
    upd('docs', ['status' => 'void'], 'id=?', [$id]); audit('docs', $id, 'void', ['status' => $d['status']], ['status' => 'void', 'reason' => $reason], 'ابطالِ سند');
}
/** فاکتور از فروش — هیچ عددی دوباره حساب نمی‌شود */
function doc_from_sale(int $saleId, string $kind = 'invoice'): int {
    $ex = val("SELECT id FROM docs WHERE sale_id=? AND kind=? AND status<>'void'", [$saleId, $kind]); if ($ex) return (int)$ex;
    $s = row("SELECT * FROM sales WHERE id=?", [$saleId]); if (!$s) throw new UserError('فروش یافت نشد');
    $items = rows("SELECT si.*, p.unit_id FROM sale_items si JOIN products p ON p.id=si.product_id WHERE si.sale_id=?", [$saleId]);
    $lines = array_map(fn($i) => ['product_id' => (int)$i['product_id'], 'title' => $i['title'], 'qty' => (int)$i['qty'], 'unit_id' => $i['unit_id'] ? (int)$i['unit_id'] : null, 'price' => (int)$i['price'], 'disc' => (int)$i['disc'],
        'doc_disc_share' => (int)$i['doc_disc_share'], 'net' => (int)$i['net'], 'tax' => (int)$i['tax'], 'gross' => (int)$i['qty'] * 0 + (int)$i['net'] + (int)$i['disc'] + (int)$i['doc_disc_share'], 'total' => (int)$i['net'] + (int)$i['tax']], $items);
    $totals = ['sub' => (int)$s['subtotal'], 'lineDisc' => (int)$s['line_disc'], 'docDisc' => (int)$s['doc_disc'], 'afterDisc' => (int)$s['subtotal'] - (int)$s['line_disc'] - (int)$s['doc_disc'], 'vat' => (int)$s['tax'],
        'roundAdj' => (int)$s['round_adj'], 'extra' => 0, 'total' => (int)$s['total'], 'words' => money_words((int)$s['total']), 'vat_pct' => vat_pct($s['d'])];
    $id = ins('docs', ['kind' => $kind, 'status' => 'draft', 'd' => $s['d'], 'customer_id' => $s['customer_id'], 'buyer' => json_encode($s['customer_id'] ? customer_snapshot((int)$s['customer_id']) : ['name' => 'مشتریِ گذری'], JSON_UNESCAPED_UNICODE),
        'seller' => json_encode(doc_seller(), JSON_UNESCAPED_UNICODE), 'lines' => json_encode($lines, JSON_UNESCAPED_UNICODE), 'opts' => json_encode(['doc_disc' => (int)$s['doc_disc'], 'from_sale' => 1]), 'totals' => json_encode($totals, JSON_UNESCAPED_UNICODE),
        'total' => (int)$s['total'], 'sale_id' => $saleId, 'created_by' => current_user()['id'] ?? null, 'created_at' => now()]);
    doc_commit($id);
    return $id;
}
/** لینکِ دانلود با رمز */
function doc_share(int $id, string $pass = '', int $days = 30): array {
    $d = row("SELECT id, status FROM docs WHERE id=?", [$id]); if (!$d || $d['status'] === 'draft') throw new UserError('فقط سندِ ثبت‌شده لینک می‌گیرد');
    $t = bin2hex(random_bytes(16));
    ins('doc_shares', ['doc_id' => $id, 'token' => $t, 'pass_hash' => $pass !== '' ? password_hash(en_num($pass), PASSWORD_DEFAULT) : null, 'expires_at' => date('Y-m-d H:i:s', time() + 86400 * max(1, $days)), 'created_by' => current_user()['id'] ?? null, 'created_at' => now()]);
    return ['url' => app_url('v.php?s=' . $t), 'token' => $t];
}
function doc_share_open(string $t, string $pass): ?array {
    $s = row("SELECT * FROM doc_shares WHERE token=? AND (expires_at IS NULL OR expires_at>=?)", [$t, now()]); if (!$s) return null;
    if ($s['pass_hash'] && !password_verify(en_num($pass), $s['pass_hash'])) return ['need_pass' => true];
    q("UPDATE doc_shares SET views=views+1 WHERE id=?", [$s['id']]);
    return ['doc' => doc_get((int)$s['doc_id'])];
}

/** صفحه‌بندیِ A4: «ادامه در صفحهٔ بعد» و جمعِ نقل‌شده */
function doc_paginate(array $lines, int $first = 14, int $next = 22): array {
    $pages = []; $i = 0; $n = count($lines);
    while ($i < $n || !$pages) { $cap = $pages ? $next : $first; $pages[] = array_slice($lines, $i, $cap, true); $i += $cap; if ($i >= $n) break; }
    return $pages;
}
function doc_render_html(array $d): string {
    $kinds = doc_kinds(); $t = $d['totals']; $b = $d['buyer']; $s = $d['seller'];
    $title = $kinds[$d['kind']] ?? 'سند'; if ($d['status'] === 'draft') $title .= ' (پیش‌نویس)'; if ($d['status'] === 'void') $title .= ' — باطل';
    $pages = doc_paginate($d['lines']); $np = count($pages); $carry = 0; $o = '';
    $verify = $d['verify_token'] ? app_url('v.php?t=' . $d['verify_token']) : '';
    foreach ($pages as $pi => $rows) {
        $o .= '<section class="a4"><header class="dh"><div>' . (setting('biz_logo') ? '<img src="' . h(setting('biz_logo')) . '" class="logo">' : '') . '<b>' . h($s['name'] ?? '') . '</b></div><h1>' . h($title) . '</h1><div class="meta">'
            . 'شماره: <b dir="ltr">' . h(fa_num($d['number'] ?: peek_seq($d['kind'], $d['d']) . '؟')) . '</b><br>تاریخ: ' . jdate($d['d']) . '<br>صفحهٔ ' . fa_num($pi + 1) . ' از ' . fa_num($np) . '</div></header>';
        if ($pi == 0) $o .= '<table class="parties"><tr><th>فروشنده</th><td>' . h($s['name'] ?? '') . ' — شناسهٔ ملی: ' . h(fa_num($s['national_id'] ?? '')) . ' — کدِ اقتصادی: ' . h(fa_num($s['economic_code'] ?? '')) . '<br>' . h($s['address'] ?? '') . ' ' . h(fa_num($s['phone'] ?? '')) . '</td></tr>'
            . '<tr><th>خریدار</th><td>' . h($b['name'] ?? 'مشتریِ گذری') . (!empty($b['national_id']) ? ' — شناسه: ' . h(fa_num($b['national_id'])) : '') . (!empty($b['postal_code']) ? ' — کدِ پستی: ' . h(fa_num($b['postal_code'])) : '') . '<br>' . h($b['address'] ?? '') . ' ' . h(fa_num($b['mobile'] ?? '')) . '</td></tr></table>';
        $o .= '<table class="lines"><thead><tr><th>#</th><th>شرح</th><th>تعداد</th><th>فی</th><th>تخفیف</th><th>مالیات</th><th>مبلغ</th></tr></thead><tbody>';
        if ($pi > 0) $o .= '<tr class="carry"><td colspan="6">نقل از صفحهٔ قبل</td><td>' . fa_money($carry) . '</td></tr>';
        foreach ($rows as $k => $l) {
            $tot = (int)($l['total'] ?? 0); $carry += $tot;
            $o .= '<tr><td>' . fa_num($k + 1) . '</td><td class="t">' . h($l['title']) . '</td><td>' . qty_show((int)$l['qty'], $l['unit_id'] ?? null) . '</td><td>' . fa_money($l['price']) . '</td><td>' . fa_money((int)$l['disc'] + (int)($l['doc_disc_share'] ?? 0)) . '</td><td>' . fa_money($l['tax'] ?? 0) . '</td><td>' . fa_money($tot) . '</td></tr>';
        }
        if ($pi < $np - 1) $o .= '<tr class="carry"><td colspan="6">جمع تا این صفحه — ادامه در صفحهٔ بعد</td><td>' . fa_money($carry) . '</td></tr></tbody></table></section>';
        else {
            $o .= '</tbody></table><table class="tot"><tr><th>جمعِ ناخالص</th><td>' . fa_money($t['sub'] ?? 0) . '</td></tr><tr><th>تخفیف</th><td>' . fa_money(($t['lineDisc'] ?? 0) + ($t['docDisc'] ?? 0)) . '</td></tr>'
                . '<tr><th>مالیات بر ارزش افزوده (' . fa_num((string)($t['vat_pct'] ?? '')) . '٪)</th><td>' . fa_money($t['vat'] ?? 0) . '</td></tr>' . (!empty($t['extra']) ? '<tr><th>' . h($d['opts']['extra_title'] ?? 'سایر') . '</th><td>' . fa_money($t['extra']) . '</td></tr>' : '')
                . (!empty($t['roundAdj']) ? '<tr><th>گردکردن</th><td>' . fa_money($t['roundAdj']) . '</td></tr>' : '') . '<tr class="g"><th>قابلِ پرداخت</th><td>' . fa_rial($t['total'] ?? 0) . '</td></tr></table>'
                . '<p class="words">به حروف: ' . h(money_words((int)($t['total'] ?? 0))) . '</p>' . (!empty($d['opts']['note']) ? '<p>' . h($d['opts']['note']) . '</p>' : '')
                . '<footer class="df"><div>' . (setting('biz_stamp') ? '<img src="' . h(setting('biz_stamp')) . '" class="stamp">' : '') . (setting('biz_sign') ? '<img src="' . h(setting('biz_sign')) . '" class="stamp">' : '') . '<br>مهر و امضای فروشنده</div><div>امضای خریدار</div>'
                . ($verify ? '<div class="qrbox"><div class="qr" data-qr="' . h($verify) . '"></div><small>تأییدِ اصالت</small></div>' : '') . '</footer><p class="foot">' . h(setting('doc_footer', '')) . '</p></section>';
        }
    }
    return $o;
}
function doc_print_page(array $d): string {
    return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>' . h(($d['number'] ?? '') ?: 'سند') . '</title><link rel="stylesheet" href="assets/print.css"></head><body class="pp">' . doc_render_html($d)
        . '<script src="assets/vendor/qrcode.js"></script><script>document.querySelectorAll(".qr").forEach(function(e){var q=qrcode(0,"M");q.addData(e.getAttribute("data-qr"));q.make();e.innerHTML=q.createSvgTag({margin:0,scalable:true});});if(location.search.indexOf("autoprint=1")>0)setTimeout(function(){print()},300);</script></body></html>';
}
