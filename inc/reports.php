<?php
// گزارش‌ها: داشبوردِ روز با هشدار، سودِ هر قلم، صورتِ سود و زیان، گزارش‌سازِ یکسان با خروجی
declare(strict_types=1);

function dash_today(?string $d = null): array {
    $d = $d ?: today();
    $pos = (int)val("SELECT COALESCE(SUM(total),0) FROM sales WHERE d=? AND channel_id IS NULL", [$d]);
    $byCh = rows("SELECT ch.name, COALESCE(SUM(s.total),0) total, COUNT(*) cnt FROM sales s JOIN channels ch ON ch.id=s.channel_id WHERE s.d=? GROUP BY ch.id, ch.name", [$d]);
    $online = array_sum(array_map(fn($r) => (int)$r['total'], $byCh));
    $ret = (int)val("SELECT COALESCE(SUM(amount+tax),0) FROM sale_returns WHERE d=?", [$d]);
    $cnt = (int)val("SELECT COUNT(*) FROM sales WHERE d=?", [$d]);
    $cards = [['k' => 'pos', 't' => 'فروشِ حضوری', 'v' => $pos], ['k' => 'channels', 't' => 'فروشِ کانال‌ها', 'v' => $online], ['k' => 'total', 't' => 'جمع', 'v' => $pos + $online],
        ['k' => 'returns', 't' => 'برگشتی', 'v' => $ret], ['k' => 'count', 't' => 'تعدادِ فروش', 'v' => $cnt, 'n' => 1], ['k' => 'avg', 't' => 'میانگینِ سبد', 'v' => $cnt ? intdiv($pos + $online, $cnt) : 0]];
    $cards = apply_filters('dashboard_cards', $cards, $d);
    return ['d' => $d, 'cards' => $cards, 'by_channel' => $byCh, 'pos' => $pos, 'online' => $online, 'total' => $pos + $online,
        'text' => 'حضوری ' . fa_money($pos) . ' · کانال‌ها ' . fa_money($online) . ' · جمع ' . fa_money($pos + $online),
        'shifts' => rows("SELECT s.*, c.name cb, u.name un FROM shifts s JOIN cashboxes c ON c.id=s.cashbox_id JOIN users u ON u.id=s.user_id WHERE s.status='open'"), 'alerts' => dash_alerts()];
}
/** مدیر پیش از مشکل خبردار می‌شود */
function dash_alerts(): array {
    $a = [];
    $ls = low_stock_report(['only' => 'low']); foreach (array_slice($ls, 0, 8) as $r) $a[] = ['w' => 'stock', 't' => ($r['state'] === 'تمام شده' ? 'تمام شده: ' : 'رو به اتمام: ') . $r['name'] . ' (آزاد ' . fa_num($r['free']) . ($r['days_left'] !== null ? '، حدودِ ' . fa_num($r['days_left']) . ' روز' : '') . ')'];
    if (count($ls) > 8) $a[] = ['w' => 'stock', 't' => '… و ' . fa_num(count($ls) - 8) . ' کالای دیگر — گزارشِ «کالاهای رو به اتمام»'];
    foreach (promises_open() as $p) if ($p['due_date'] <= today()) $a[] = ['w' => 'credit', 't' => 'موعدِ قولِ پرداختِ ' . $p['name'] . ': ' . fa_money($p['amount']) . ' — ' . jdate($p['due_date']) . ($p['due_date'] < today() ? ' (گذشته)' : ' (امروز)')];
    foreach (rows("SELECT pi.id, s.name, pi.due_date, pi.total - COALESCE((SELECT SUM(amount) FROM supplier_payments sp WHERE sp.pi_id=pi.id),0) rem FROM purchase_invoices pi JOIN suppliers s ON s.id=pi.supplier_id WHERE pi.due_date<=? HAVING rem>0 ORDER BY pi.due_date LIMIT 10", [date('Y-m-d', strtotime('+3 day'))]) as $r)
        $a[] = ['w' => 'payable', 't' => 'سررسیدِ بدهی به ' . $r['name'] . ': ' . fa_money($r['rem']) . ' — ' . jdate($r['due_date'])];
    foreach (cheques_due(3) as $c) $a[] = ['w' => 'cheque', 't' => 'چکِ ' . ($c['direction'] === 'in' ? 'دریافتنی' : 'پرداختنی') . ' ' . fa_num($c['number']) . ': ' . fa_money($c['amount']) . ' — ' . jdate($c['due_date'])];
    foreach (rows("SELECT id, cashbox_id, opened_at FROM shifts WHERE status='open' AND opened_at<?", [date('Y-m-d H:i:s', time() - 16 * 3600)]) as $s) $a[] = ['w' => 'shift', 't' => 'شیفتِ ' . fa_num($s['id']) . ' بیش از ۱۶ ساعت باز است'];
    foreach (rows("SELECT id, diff FROM shifts WHERE status='closed' AND diff<>0 AND closed_at>=?", [date('Y-m-d 00:00:00')]) as $s) $a[] = ['w' => 'diff', 't' => 'مغایرتِ شیفتِ ' . fa_num($s['id']) . ': ' . fa_money($s['diff'])];
    $f = cw_followup(); if ($f['calls']) $a[] = ['w' => 'crm', 't' => fa_num(count($f['calls'])) . ' پیگیریِ امروز'];
    foreach (rows("SELECT u.id, u.name FROM users u WHERE u.active=1 AND u.kind='staff'") as $u) if (stacc_blocked((int)$u['id'])) $a[] = ['w' => 'staff', 't' => 'حسابِ ' . $u['name'] . ' مسدود است (بدهیِ بیش از سقف)'];
    return $a;
}
/** سودِ هر قلم = خالصِ فروش − بها − سهمِ کارمزدِ کانال − پورسانت */
function accp_line(array $it, ?array $sale = null): array {
    $sale = $sale ?: row("SELECT * FROM sales WHERE id=?", [$it['sale_id']]);
    $sumNet = (int)val("SELECT COALESCE(SUM(net),0) FROM sale_items WHERE sale_id=?", [$it['sale_id']]);
    $fee = $sumNet > 0 ? intdiv((int)$sale['channel_fee'] * (int)$it['net'], $sumNet) : 0;
    $comm = (int)val("SELECT COALESCE(SUM(amount),0) FROM commission_lines WHERE sale_item_id=?", [$it['id']]);
    $retNet = (int)val("SELECT COALESCE(SUM(amount),0) FROM sale_return_items WHERE sale_item_id=?", [$it['id']]);
    $retCost = (int)val("SELECT COALESCE(SUM(cost),0) FROM sale_return_items WHERE sale_item_id=?", [$it['id']]);
    $rev = (int)$it['net'] - $retNet; $cost = (int)$it['cost'] - $retCost;
    return ['revenue' => $rev, 'cost' => $cost, 'fee' => $fee, 'commission' => $comm, 'profit' => $rev - $cost - $fee - $comm];
}
function accp_report(string $from, string $to, string $by = 'product', array $f = []): array {
    $w = report_where($f, 's');
    $items = rows("SELECT si.*, s.channel_fee, s.channel_id, s.branch_id, s.seller_id, p.category_id, p.name pname FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id WHERE s.d BETWEEN ? AND ?" . $w[0], array_merge([$from, $to], $w[1]));
    $g = []; $names = ['product' => fn($i) => $i['pname'], 'channel' => fn($i) => $i['channel_id'] ? (string)val("SELECT name FROM channels WHERE id=?", [$i['channel_id']]) : 'صندوقِ حضوری',
        'branch' => fn($i) => (string)val("SELECT name FROM branches WHERE id=?", [$i['branch_id']]), 'category' => fn($i) => (string)val("SELECT name FROM categories WHERE id=?", [$i['category_id']]), 'seller' => fn($i) => (string)val("SELECT name FROM users WHERE id=?", [$i['seller_id']])];
    $fn = $names[$by] ?? $names['product'];
    foreach ($items as $it) {
        $l = accp_line($it, ['channel_fee' => $it['channel_fee'], 'id' => $it['sale_id']]); $k = $fn($it);
        $g[$k] ??= ['name' => $k, 'qty' => 0, 'revenue' => 0, 'cost' => 0, 'fee' => 0, 'commission' => 0, 'profit' => 0];
        $g[$k]['qty'] += (int)$it['qty'] - (int)$it['returned_qty']; foreach (['revenue', 'cost', 'fee', 'commission', 'profit'] as $c) $g[$k][$c] += $l[$c];
    }
    usort($g, fn($a, $b) => $b['profit'] <=> $a['profit']);
    return array_values($g);
}
/** صورتِ سود و زیان از همان دفترِ کل */
function pl_statement(string $from, string $to): array {
    $tb = trial_balance($from, $to); $o = ['income' => 0, 'returns' => 0, 'cogs' => 0, 'expense' => 0, 'lines' => []];
    foreach ($tb['rows'] as $r) {
        if ($r['kind'] === 'income') { if ($r['nature'] === 'D') { $o['returns'] += (int)$r['dr'] - (int)$r['cr']; } else $o['income'] += (int)$r['cr'] - (int)$r['dr']; $o['lines'][] = $r; }
        elseif ($r['kind'] === 'cogs') { $o['cogs'] += (int)$r['dr'] - (int)$r['cr']; $o['lines'][] = $r; }
        elseif ($r['kind'] === 'expense') { $o['expense'] += (int)$r['dr'] - (int)$r['cr']; $o['lines'][] = $r; }
    }
    $o['net_sales'] = $o['income'] - $o['returns']; $o['gross'] = $o['net_sales'] - $o['cogs']; $o['net'] = $o['gross'] - $o['expense'];
    return $o;
}
// ── روند و مقایسه (افزایشی/کاهشی بودنِ فروش و سود)
/** جمعِ فروشِ خالص/بها/سود به تفکیکِ یک کلید با SQL (برگشت‌ها کم می‌شوند؛ کارمزدِ کانال و پورسانت هم) */
function sales_agg(string $from, string $to, string $by = 'product', array $f = []): array {
    $key = ['product' => 'p.id', 'category' => 'p.category_id', 'channel' => 'COALESCE(s.channel_id,0)', 'seller' => 's.seller_id', 'customer' => 'COALESCE(s.customer_id,0)', 'branch' => 'COALESCE(s.branch_id,0)', 'all' => "'all'"][$by] ?? 'p.id';
    $name = ['product' => 'MAX(p.name)', 'category' => "MAX(COALESCE(cat.name,'—'))", 'channel' => "MAX(COALESCE(ch.name,'صندوقِ حضوری'))", 'seller' => "MAX(COALESCE(u.name,'—'))", 'customer' => "MAX(COALESCE(c.name,'گذری'))", 'branch' => "MAX(COALESCE(b.name,'—'))", 'all' => "'همه'"][$by] ?? 'MAX(p.name)';
    $w = report_where($f, 's');
    $rs = rows("SELECT $key k, $name name, SUM(si.qty - si.returned_qty) qty, SUM(si.net) net, SUM(si.cost) cost, COUNT(DISTINCT s.id) cnt,
            SUM((SELECT COALESCE(SUM(r.amount),0) FROM sale_return_items r WHERE r.sale_item_id=si.id)) ret_net, SUM((SELECT COALESCE(SUM(r.cost),0) FROM sale_return_items r WHERE r.sale_item_id=si.id)) ret_cost,
            SUM((SELECT COALESCE(SUM(cl.amount),0) FROM commission_lines cl WHERE cl.sale_item_id=si.id)) comm,
            SUM(CASE WHEN s.channel_fee>0 THEN s.channel_fee * si.net / NULLIF((SELECT SUM(x.net) FROM sale_items x WHERE x.sale_id=s.id),0) ELSE 0 END) fee
        FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id LEFT JOIN categories cat ON cat.id=p.category_id LEFT JOIN channels ch ON ch.id=s.channel_id
        LEFT JOIN users u ON u.id=s.seller_id LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN branches b ON b.id=s.branch_id
        WHERE s.d BETWEEN ? AND ? AND s.status='done'" . $w[0] . (!empty($f['q']) ? " AND p.name LIKE " . db()->quote('%' . $f['q'] . '%') : '') . " GROUP BY $key", array_merge([$from, $to], $w[1]));
    $o = [];
    foreach ($rs as $r) { $rev = (int)$r['net'] - (int)$r['ret_net']; $cost = (int)$r['cost'] - (int)$r['ret_cost']; $profit = $rev - $cost - (int)round((float)$r['fee']) - (int)$r['comm'];
        $o[(string)$r['k']] = ['k' => (string)$r['k'], 'name' => (string)$r['name'], 'qty' => (int)$r['qty'], 'cnt' => (int)$r['cnt'], 'revenue' => $rev, 'cost' => $cost, 'profit' => $profit, 'margin' => $rev > 0 ? round($profit * 100 / $rev, 1) : 0.0]; }
    return $o;
}
function pct_change(float $now, float $prev): ?float { if (abs($prev) < 1e-9) return $now > 0 ? null : 0.0; return round(($now - $prev) * 100 / abs($prev), 1); }
function trend_label(?float $c): string { if ($c === null) return '🆕 تازه'; if ($c >= 10) return '▲ افزایشی'; if ($c <= -10) return '▼ کاهشی'; return '● ثابت'; }
/** مقایسهٔ این دوره با دورهٔ هم‌اندازهٔ قبلی */
function trend_compare(array $f, string $by = 'product'): array {
    $days = (int)((strtotime($f['to']) - strtotime($f['from'])) / 86400) + 1;
    $pf = date('Y-m-d', strtotime($f['from'] . " -$days day")); $pt = date('Y-m-d', strtotime($f['from'] . ' -1 day'));
    $now = sales_agg($f['from'], $f['to'], $by, $f); $prev = sales_agg($pf, $pt, $by, $f); $o = [];
    foreach (array_unique(array_merge(array_keys($now), array_keys($prev))) as $k) {
        $a = $now[$k] ?? ['name' => $prev[$k]['name'], 'qty' => 0, 'revenue' => 0, 'profit' => 0, 'margin' => 0.0]; $b = $prev[$k] ?? ['qty' => 0, 'revenue' => 0, 'profit' => 0, 'margin' => 0.0];
        $rc = pct_change($a['revenue'], $b['revenue']); $pc = pct_change($a['profit'], $b['profit']);
        $o[] = ['name' => $a['name'], 'qty' => $a['qty'], 'qty_prev' => $b['qty'], 'revenue' => $a['revenue'], 'revenue_prev' => $b['revenue'], 'rev_change' => $rc === null ? 'تازه' : fa_num((string)$rc) . '٪',
            'profit' => $a['profit'], 'profit_prev' => $b['profit'], 'profit_change' => $pc === null ? 'تازه' : fa_num((string)$pc) . '٪', 'margin' => fa_num((string)$a['margin']) . '٪', 'margin_prev' => fa_num((string)$b['margin']) . '٪',
            'trend' => trend_label($rc), 'profit_trend' => trend_label($pc), '_rc' => $rc ?? 1e9, '_pc' => $pc ?? 1e9];
    }
    usort($o, fn($x, $y) => $y['revenue'] <=> $x['revenue']);
    return $o;
}
/** روندِ ماه‌به‌ماه (۱۲ ماهِ شمسیِ اخیر): فروش، سود، حاشیه، برگشتی و تغییر نسبت به ماهِ قبل */
function monthly_trend(int $months = 12, array $f = []): array {
    [$jy, $jm] = array_map('intval', explode('-', str_replace('/', '-', jym(today()))));
    $list = []; for ($i = $months - 1; $i >= 0; $i--) { $m = $jm - $i; $y = $jy; while ($m < 1) { $m += 12; $y--; } $list[] = sprintf('%04d-%02d', $y, $m); }
    $o = []; $prev = null;
    foreach ($list as $ym) {
        [$a, $b] = jmonth_range($ym); $t = sales_agg($a, $b, 'all', $f)['all'] ?? ['revenue' => 0, 'cost' => 0, 'profit' => 0, 'qty' => 0, 'cnt' => 0, 'margin' => 0.0];
        $ret = (int)val("SELECT COALESCE(SUM(amount),0) FROM sale_returns WHERE d BETWEEN ? AND ?", [$a, $b]);
        $row = ['month' => fa_num(str_replace('-', '/', $ym)), 'cnt' => $t['cnt'], 'qty' => $t['qty'], 'revenue' => $t['revenue'], 'cost' => $t['cost'], 'profit' => $t['profit'], 'margin' => fa_num((string)$t['margin']) . '٪',
            'returns' => $ret, 'avg' => $t['cnt'] ? intdiv($t['revenue'], $t['cnt']) : 0, 'rev_change' => $prev === null ? '—' : (($c = pct_change($t['revenue'], $prev['revenue'])) === null ? 'تازه' : fa_num((string)$c) . '٪'),
            'profit_change' => $prev === null ? '—' : (($c = pct_change($t['profit'], $prev['profit'])) === null ? 'تازه' : fa_num((string)$c) . '٪'), '_rev' => $t['revenue'], '_profit' => $t['profit']];
        $o[] = $row; $prev = $t;
    }
    return $o;
}
/** کالاهای رو به اتمام: موجودی، حداقل، میانگینِ فروشِ روزانه (۳۰ روز) و چند روز دیگر کافی است */
function low_stock_report(array $f = []): array {
    $cover = max(1, (int)setting('stock_cover_days', '7')); $from = date('Y-m-d', strtotime('-30 day'));
    $rs = rows("SELECT p.id, p.name, p.min_stock, cat.name cat, COALESCE((SELECT SUM(m.qty) FROM stock_moves m WHERE m.product_id=p.id),0) on_hand,
            COALESCE((SELECT SUM(r.qty) FROM stock_reservations r WHERE r.product_id=p.id),0) reserved,
            COALESCE((SELECT SUM(si.qty - si.returned_qty) FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE si.product_id=p.id AND s.d>=?),0) sold30
        FROM products p LEFT JOIN categories cat ON cat.id=p.category_id WHERE p.active=1 AND p.item_type IN ('SAL','CIR','IMP')" . (!empty($f['q']) ? " AND p.name LIKE " . db()->quote('%' . $f['q'] . '%') : ''), [$from]);
    $o = [];
    foreach ($rs as $r) {
        $free = (int)$r['on_hand'] - (int)$r['reserved']; $avg = (int)$r['sold30'] / 30; $days = $avg > 0 ? (int)floor(max(0, $free) / $avg) : null;
        $st = $free <= 0 ? ($r['sold30'] > 0 || $r['min_stock'] > 0 ? 'out' : '') : (($r['min_stock'] > 0 && $free < (int)$r['min_stock']) || ($days !== null && $days <= $cover) ? 'low' : '');
        if ($st === '') continue; if (($f['only'] ?? '') === 'low' && !in_array($st, ['low', 'out'], true)) continue;
        $need = max((int)$r['min_stock'], (int)ceil($avg * $cover * 2)) - $free;
        $o[] = ['name' => $r['name'], 'cat' => $r['cat'], 'on_hand' => (int)$r['on_hand'], 'reserved' => (int)$r['reserved'], 'free' => $free, 'min_stock' => (int)$r['min_stock'], 'sold30' => (int)$r['sold30'],
            'days_left' => $days, 'days' => $days === null ? '—' : fa_num((string)$days), 'state' => $st === 'out' ? 'تمام شده' : 'رو به اتمام', 'suggest' => max(0, $need)];
    }
    usort($o, fn($a, $b) => [$a['state'] === 'تمام شده' ? 0 : 1, $a['days_left'] ?? 1e9] <=> [$b['state'] === 'تمام شده' ? 0 : 1, $b['days_left'] ?? 1e9]);
    return $o;
}
/** دریافت‌ها به تفکیکِ روش */
function pay_method_summary(array $f): array {
    return array_map(fn($r) => $r + ['title' => pay_methods()[$r['method']] ?? $r['method']], rows("SELECT method, COUNT(*) cnt, SUM(amount) total FROM receipts WHERE at BETWEEN ? AND ? GROUP BY method ORDER BY total DESC", [$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59']));
}
/** هزینه‌ها به تفکیکِ سرفصل (از دفترِ کل) */
function expense_summary(array $f): array {
    return rows("SELECT a.code, a.name, SUM(l.debit-l.credit) total FROM journal_lines l JOIN journals j ON j.id=l.journal_id JOIN accounts a ON a.id=l.account_id WHERE a.kind='expense' AND j.d BETWEEN ? AND ? GROUP BY a.id, a.code, a.name HAVING total<>0 ORDER BY total DESC", [$f['from'], $f['to']]);
}
/** یک الگوی فیلتر برای همهٔ گزارش‌ها */
function report_filters(array $in): array {
    $from = jparse($in['from'] ?? '') ?: date('Y-m-01'); $to = jparse($in['to'] ?? '') ?: today();
    return ['from' => $from, 'to' => $to, 'branch_id' => (int)($in['branch_id'] ?? 0), 'seller_id' => (int)($in['seller_id'] ?? 0), 'channel' => (string)($in['channel'] ?? ''), 'q' => trim((string)($in['q'] ?? ''))];
}
function report_where(array $f, string $a = 's'): array {
    $w = ''; $p = [];
    if (!empty($f['branch_id'])) { $w .= " AND $a.branch_id=?"; $p[] = $f['branch_id']; }
    if (!empty($f['seller_id'])) { $w .= " AND $a.seller_id=?"; $p[] = $f['seller_id']; }
    if (($f['channel'] ?? '') === 'pos') $w .= " AND $a.channel_id IS NULL"; elseif (ctype_digit((string)($f['channel'] ?? ''))) { $w .= " AND $a.channel_id=?"; $p[] = (int)$f['channel']; }
    return [$w, $p];
}
/** مجموعه‌داده‌های گزارش‌ساز: [عنوان، cap، ستون‌ها، تابعِ ردیف‌ها] */
function report_datasets(): array {
    $ds = [
        'sales' => ['فروش‌ها', 'reports.sales', ['number' => 'شماره', 'd' => 'تاریخ', 'branch' => 'شعبه', 'channel' => 'کانال', 'seller' => 'فروشنده', 'customer' => 'مشتری', 'subtotal' => 'ناخالص', 'disc' => 'تخفیف', 'tax' => 'مالیات', 'total' => 'جمع'],
            function ($f) { $w = report_where($f); return rows("SELECT s.number, s.d, b.name branch, COALESCE(ch.name,'صندوقِ حضوری') channel, u.name seller, COALESCE(c.name,'گذری') customer, s.subtotal, s.line_disc+s.doc_disc disc, s.tax, s.total
                FROM sales s LEFT JOIN branches b ON b.id=s.branch_id LEFT JOIN channels ch ON ch.id=s.channel_id LEFT JOIN users u ON u.id=s.seller_id LEFT JOIN customers c ON c.id=s.customer_id WHERE s.d BETWEEN ? AND ?" . $w[0] . " ORDER BY s.id DESC LIMIT 5000", array_merge([$f['from'], $f['to']], $w[1])); }],
        'sellers' => ['فروش به تفکیکِ فروشنده', 'reports.sales', ['label' => 'فروشنده', 'cnt' => 'تعداد', 'total' => 'جمع', 'avg' => 'میانگینِ سبد', 'disc' => 'تخفیفِ داده‌شده', 'returns' => 'برگشت', 'commission' => 'پورسانت', 'rank' => 'رتبه'],
            fn($f) => perf_staff_rows($f['from'], $f['to'])],
        'profit' => ['سودِ هر کالا', 'reports.profit', ['name' => 'کالا', 'qty' => 'تعداد', 'revenue' => 'فروشِ خالص', 'cost' => 'بها', 'fee' => 'کارمزد', 'commission' => 'پورسانت', 'profit' => 'سود'], fn($f) => accp_report($f['from'], $f['to'], 'product', $f)],
        'profit_channel' => ['سود به تفکیکِ کانال', 'reports.profit', ['name' => 'کانال', 'qty' => 'تعداد', 'revenue' => 'فروشِ خالص', 'cost' => 'بها', 'fee' => 'کارمزد', 'commission' => 'پورسانت', 'profit' => 'سود'], fn($f) => accp_report($f['from'], $f['to'], 'channel', $f)],
        'profit_branch' => ['سود به تفکیکِ شعبه', 'reports.profit', ['name' => 'شعبه', 'qty' => 'تعداد', 'revenue' => 'فروشِ خالص', 'cost' => 'بها', 'profit' => 'سود'], fn($f) => accp_report($f['from'], $f['to'], 'branch', $f)],
        'stock' => ['موجودیِ انبار', 'stock.view', ['code' => 'کدِ محموله', 'name' => 'کالا', 'vtitle' => 'ترکیب', 'wname' => 'انبار', 'on_hand' => 'موجودی', 'reserved' => 'رزرو', 'labeled' => 'برچسبِ واردنشده', 'unit_cost' => 'بهای واحد'],
            fn($f) => wh_overview(['q' => $f['q']])],
        'customers' => ['ماندهٔ مشتریان', 'customers.view', ['name' => 'مشتری', 'mobile' => 'موبایل', 'bal' => 'بدهیِ نسیه', 'due' => 'سررسید'], fn($f) => customer_dues(5000)],
        'payables' => ['بدهی به تأمین‌کنندگان', 'purchase.view', ['sname' => 'تأمین‌کننده', 'number' => 'فاکتور', 'd' => 'تاریخ', 'due_date' => 'سررسید', 'total' => 'مبلغ', 'remain' => 'مانده'], fn($f) => payables()],
        'partners' => ['فروشِ همکاران', 'partner.manage', ['name' => 'همکار', 'self_total' => 'خریدِ خودش', 'ship_total' => 'برای مشتری‌اش', 'hold_total' => 'نگه‌داری', 'cnt' => 'تعداد'], fn($f) => psales_rows($f['from'], $f['to'])],
        'trend_product' => ['روندِ فروش و سودِ هر کالا (این دوره در برابرِ دورهٔ قبل)', 'reports.profit', ['name' => 'کالا', 'trend' => 'روندِ فروش', 'qty' => 'تعداد', 'qty_prev' => 'تعدادِ دورهٔ قبل', 'revenue' => 'فروشِ خالص', 'revenue_prev' => 'فروشِ دورهٔ قبل', 'rev_change' => 'تغییرِ فروش',
            'profit' => 'سود', 'profit_prev' => 'سودِ دورهٔ قبل', 'profit_change' => 'تغییرِ سود', 'profit_trend' => 'روندِ سود', 'margin' => 'حاشیه', 'margin_prev' => 'حاشیهٔ قبل'], fn($f) => trend_compare($f, 'product')],
        'trend_category' => ['روندِ فروش و سودِ هر دسته', 'reports.profit', ['name' => 'دسته', 'trend' => 'روند', 'revenue' => 'فروش', 'revenue_prev' => 'دورهٔ قبل', 'rev_change' => 'تغییر', 'profit' => 'سود', 'profit_prev' => 'سودِ قبل', 'profit_change' => 'تغییرِ سود', 'margin' => 'حاشیه'], fn($f) => trend_compare($f, 'category')],
        'trend_customer' => ['رتبه و روندِ مشتریان و همکاران', 'reports.profit', ['name' => 'مشتری', 'trend' => 'روند', 'revenue' => 'خرید', 'revenue_prev' => 'دورهٔ قبل', 'rev_change' => 'تغییر', 'profit' => 'سودِ ما', 'margin' => 'حاشیه'], fn($f) => trend_compare($f, 'customer')],
        'trend_seller' => ['روندِ فروشِ فروشندگان', 'reports.sales', ['name' => 'فروشنده', 'trend' => 'روند', 'revenue' => 'فروش', 'revenue_prev' => 'دورهٔ قبل', 'rev_change' => 'تغییر', 'profit' => 'سود', 'profit_change' => 'تغییرِ سود'], fn($f) => trend_compare($f, 'seller')],
        'trend_channel' => ['روندِ کانال‌ها (حضوری/آنلاین)', 'reports.sales', ['name' => 'کانال', 'trend' => 'روند', 'revenue' => 'فروش', 'revenue_prev' => 'دورهٔ قبل', 'rev_change' => 'تغییر', 'profit' => 'سود', 'profit_change' => 'تغییرِ سود'], fn($f) => trend_compare($f, 'channel')],
        'monthly' => ['روندِ ماه‌به‌ماه (۱۲ ماه)', 'reports.profit', ['month' => 'ماه', 'cnt' => 'تعدادِ فروش', 'qty' => 'تعدادِ کالا', 'revenue' => 'فروشِ خالص', 'rev_change' => 'تغییرِ فروش', 'cost' => 'بها', 'profit' => 'سود', 'profit_change' => 'تغییرِ سود', 'margin' => 'حاشیه', 'returns' => 'برگشتی', 'avg' => 'میانگینِ سبد'], fn($f) => monthly_trend(12, $f)],
        'low_stock' => ['کالاهای رو به اتمام و تمام‌شده', 'stock.view', ['name' => 'کالا', 'cat' => 'دسته', 'state' => 'وضعیت', 'free' => 'آزاد', 'reserved' => 'رزرو', 'min_stock' => 'حداقل', 'sold30' => 'فروشِ ۳۰ روز', 'days' => 'کافی برای (روز)', 'suggest' => 'پیشنهادِ خرید'], fn($f) => low_stock_report($f)],
        'credit_accounts' => ['حساب‌های نسیه (مشتری و همکار)', 'customers.view', ['name' => 'نام', 'mobile' => 'موبایل', 'bal' => 'بدهی', 'cheques' => 'چکِ در جریان', 'due' => 'موعد', 'late_days' => 'روزِ دیرکرد', 'credit_limit' => 'سقف'], fn($f) => credit_accounts()],
        'aging' => ['سن‌بندیِ بدهیِ مشتریان', 'customers.view', ['name' => 'نام', 'mobile' => 'موبایل', 'bal' => 'کلِ بدهی', '0_30' => '۰ تا ۳۰ روز', '31_60' => '۳۱ تا ۶۰', '61_90' => '۶۱ تا ۹۰', '90p' => 'بیش از ۹۰'], fn($f) => credit_aging()],
        'cheques' => ['چک‌ها', 'bank.manage', ['direction' => 'نوع', 'number' => 'شماره', 'sayad' => 'صیادی', 'party_name' => 'طرف', 'amount' => 'مبلغ', 'due_date' => 'سررسید', 'st' => 'وضعیت'], fn($f) => array_map(fn($r) => ['direction' => $r['direction'] === 'in' ? 'دریافتنی' : 'پرداختنی', 'st' => cheque_statuses()[$r['status']] ?? $r['status']] + $r, rows("SELECT * FROM cheques WHERE due_date BETWEEN ? AND ? ORDER BY due_date", [$f['from'], $f['to']]))],
        'pay_methods' => ['دریافت‌ها به تفکیکِ روش', 'reports.sales', ['title' => 'روش', 'cnt' => 'تعداد', 'total' => 'مبلغ'], fn($f) => pay_method_summary($f)],
        'expenses' => ['هزینه‌ها به تفکیکِ سرفصل', 'ledger.view', ['code' => 'کد', 'name' => 'سرفصل', 'total' => 'مبلغ'], fn($f) => expense_summary($f)],
        'shipping' => ['هزینه و آمارِ ارسال', 'reports.sales', ['title' => 'شرکت/روش', 'cnt' => 'تعداد', 'shop_cost' => 'هزینهٔ فروشگاه', 'cust_cost' => 'پس‌کرایه', 'cod' => 'پرداخت در محل', 'delivered' => 'رسیده', 'returned' => 'برگشتی'], fn($f) => ship_cost_summary($f['from'], $f['to'])],
        'journal' => ['دفترِ روزنامه', 'ledger.view', ['number' => 'شماره', 'd' => 'تاریخ', 'descr' => 'شرح', 'total' => 'مبلغ', 'source_type' => 'منبع'], fn($f) => rows("SELECT number, d, descr, total, source_type FROM journals WHERE d BETWEEN ? AND ? ORDER BY d, id LIMIT 5000", [$f['from'], $f['to']])],
    ];
    return apply_filters('report_datasets', $ds);
}
function report_run(string $key, array $f, array $cols = []): array {
    $ds = report_datasets()[$key] ?? null; if (!$ds) throw new UserError('گزارش یافت نشد', 404);
    require_cap($ds[1]);
    $all = $ds[2]; $cols = array_values(array_intersect($cols ?: array_keys($all), array_keys($all))) ?: array_keys($all);
    $rows = ($ds[3])($f);
    return ['title' => $ds[0], 'cols' => array_intersect_key($all, array_flip($cols)), 'rows' => $rows];
}
function report_cell($k, $v, bool $display = true): string {
    if ($v === null) return '';
    if (in_array($k, ['d', 'due', 'due_date'], true)) return $display ? jdate((string)$v) : jdate((string)$v, false, false);
    if (is_numeric($v) && !in_array($k, ['number', 'mobile', 'code', 'sayad'], true)) return $display ? fa_money((int)$v) : (string)(int)$v;
    return $display ? (string)$v : (string)$v;
}
/** خروجی همان عددهای صفحه: CSV (ارقامِ لاتین، BOM) یا اکسل */
function an_export(array $rep, string $fmt): void {
    $name = 'report-' . date('Ymd-His');
    if ($fmt === 'xls') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8'); header("Content-Disposition: attachment; filename=$name.xls");
        echo "\xEF\xBB\xBF<html><head><meta charset=\"utf-8\"></head><body dir=\"rtl\"><table border=\"1\"><tr>";
        foreach ($rep['cols'] as $t) echo '<th>' . h($t) . '</th>'; echo '</tr>';
        foreach ($rep['rows'] as $r) { echo '<tr>'; foreach ($rep['cols'] as $k => $t) echo '<td>' . h(report_cell($k, $r[$k] ?? '', false)) . '</td>'; echo '</tr>'; }
        echo '</table></body></html>'; return;
    }
    header('Content-Type: text/csv; charset=utf-8'); header("Content-Disposition: attachment; filename=$name.csv");
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, array_values($rep['cols']));
    foreach ($rep['rows'] as $r) fputcsv($o, array_map(fn($k) => report_cell($k, $r[$k] ?? '', false), array_keys($rep['cols'])));
    fclose($o);
}
