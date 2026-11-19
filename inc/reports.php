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
    foreach (low_stock(10) as $r) $a[] = ['w' => 'stock', 't' => 'کمبودِ موجودی: ' . $r['name'] . ' (' . fa_num($r['on_hand']) . ' از حداقلِ ' . fa_num($r['min_stock']) . ')'];
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
    if (in_array($k, ['d', 'due', 'due_date'], true)) return $display ? jdate((string)$v) : jdate((string)$v, false, false);
    if (is_numeric($v) && !in_array($k, ['number', 'mobile', 'code'], true)) return $display ? fa_money((int)$v) : (string)(int)$v;
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
