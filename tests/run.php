<?php
// آزمون‌های مشخصات (ref همان tests در 8CELL.pm.json). اجرا:
//   TEST_DB=cell8_test TEST_USER=root TEST_PASS= php tests/run.php [--json out.json]
// هشدار: پایگاه‌دادهٔ آزمون در هر آزمون خالی می‌شود؛ هرگز نامِ پایگاه‌دادهٔ واقعی را ندهید.
declare(strict_types=1);
define('DATA', sys_get_temp_dir() . '/8cell-test-data');
$GLOBALS['CFG'] = ['db' => ['host' => getenv('TEST_HOST') ?: 'localhost', 'name' => getenv('TEST_DB') ?: 'cell8_test', 'user' => getenv('TEST_USER') ?: 'root', 'pass' => getenv('TEST_PASS') ?: ''], 'base_url' => 'https://shop.example.ir'];
if (!preg_match('/test/', $GLOBALS['CFG']['db']['name'])) { fwrite(STDERR, "نامِ پایگاه‌دادهٔ آزمون باید «test» داشته باشد\n"); exit(2); }
require dirname(__DIR__) . '/inc/boot.php';

function reset_db(): void {
    $GLOBALS['__hooks'] = []; setting('__flush'); $GLOBALS['__user'] = null;
    $pdo = db(); $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
    db_migrate(); setting('__flush');
    $id = ins('users', ['username' => 'admin', 'name' => 'مدیر', 'is_super' => 1, 'created_at' => now(), 'max_credit' => 0]);
    set_current_user($id);
}
function mk_user(string $u, string $name, array $roles, array $x = []): int {
    $id = ins('users', ['username' => $u, 'name' => $name, 'created_at' => now()] + $x);
    foreach ($roles as $r) q("INSERT INTO user_roles VALUES (?, (SELECT id FROM roles WHERE slug=?))", [$id, $r]);
    return $id;
}
function mk_product(string $name, int $price, int $stock = 0, int $cost = 0, bool $serial = false): int {
    $id = product_save(['name' => $name, 'price' => $price, 'item_type' => 'SAL', 'serial' => $serial ? 1 : 0]);
    if ($stock > 0) { $lot = wh_lot_ensure($id, null, 1); wh_stock_in($lot['code'], $stock, ['unit_cost' => $cost]); }
    return $id;
}
function set_vat(float $v): void { q("UPDATE fiscal_years SET vat_pct=?", [$v]); }
function open_shift(int $uid, int $cb = 1, int $opening = 0): int { $me = current_user(); set_current_user($uid); $id = shift_open($cb, $opening); set_current_user((int)$me['id']); return $id; }
function sell(array $lines, array $pay, array $x = []): int { return sale_commit(['cart_token' => bin2hex(random_bytes(16)), 'lines' => $lines, 'payments' => $pay] + $x); }
function err(callable $f): array { try { $f(); return ['ok' => true, 'msg' => '', 'http' => 0]; } catch (UserError $e) { return ['ok' => false, 'msg' => $e->getMessage(), 'http' => $e->http]; } }
function expect($cond, string $got) { return [$cond ? 'pass' : 'fail', $got]; }

$T = [];
$T['8C-SET-01'] = function () { $r = err(fn() => biz_profile_save(['biz_name' => 'x', 'biz_kind' => 'person', 'biz_national_id' => '1234567890'])); return expect(!$r['ok'] && $r['msg'] === 'شناسهٔ ملی معتبر نیست', $r['msg']); };
$T['8C-SET-02'] = function () { fiscal_lock('1405/07'); $r = err(fn() => journal_manual(['d' => '1405/07/10', 'descr' => 'اصلاحِ فروش', 'lines' => [['account_id' => acc_id('cash'), 'debit' => 1000], ['account_id' => acc_id('sales'), 'credit' => 1000]]])); return expect($r['msg'] === 'این ماه بسته شده است', $r['msg']); };
$T['8C-SET-03'] = function () { $p = mk_product('کالا', 100000); $ali = mk_user('ali', 'علی', ['admin']); set_current_user($ali); product_price_set($p, 120000); $s = (string)val("SELECT summary FROM audit WHERE tbl='products' AND rec_id=? AND action='update' ORDER BY id DESC LIMIT 1", [$p]); return expect(str_contains($s, 'علی · قیمت · 100000 → 120000'), $s); };
$T['8C-USR-01'] = function () { $u = mk_user('seller1', 'فروشنده', ['seller']); set_current_user($u); $cap = pages_all()['pl'][1]; $r = err(fn() => require_cap($cap)); $r2 = err(fn() => report_run('profit', report_filters([]))); return expect($r['http'] === 403 && $r2['http'] === 403, (string)$r['http']); };
$T['8C-USR-02'] = function () { $p = mk_product('گوشی', 1000000, 5); $u = mk_user('s2', 'فروشنده', ['seller'], ['max_discount_pct' => 5]); open_shift($u); set_current_user($u); $c = cart_open(); pos_scan((int)$c['id'], (string)val("SELECT base_code FROM products WHERE id=?", [$p]));
    $it = (int)val("SELECT id FROM cart_items WHERE cart_id=?", [$c['id']]); $r = err(fn() => pos_line_discount($it, '8%')); return expect(str_starts_with($r['msg'], 'بیش از سقفِ تخفیفِ شما'), $r['msg']); };
$T['8C-USR-03'] = function () { $a = mk_user('acc', 'حسابدار', ['accountant']); set_current_user($a); $hr = (int)val("SELECT id FROM roles WHERE slug='hr'"); $r = err(fn() => user_save(['username' => 'newu', 'name' => 'تازه', 'roles' => [$hr]])); return expect($r['msg'] === 'این دسترسی را ندارید', $r['msg']); };
$T['8C-PUR-01'] = function () { $a = mk_product('الف', 1); $b = mk_product('ب', 1); $s = supplier_save(['name' => 'تأمین']); $r = purchase_invoice_commit(['supplier_id' => $s, 'warehouse_id' => 1, 'overhead' => 100000, 'items' => [['product_id' => $a, 'qty' => 1, 'unit_price' => 800000], ['product_id' => $b, 'qty' => 1, 'unit_price' => 200000]]]);
    return expect($r['unit_costs'] === [880000, 220000], 'بهای واحد: ' . implode(' و ', $r['unit_costs'])); };
$T['8C-PUR-02'] = function () { $a = mk_product('الف', 1); $s = supplier_save(['name' => 'تأمین']); $po = po_save(['supplier_id' => $s, 'warehouse_id' => 1, 'confirm' => 1, 'items' => [['product_id' => $a, 'qty' => 10, 'unit_price' => 5]]]); $pi = (int)val("SELECT id FROM purchase_order_items WHERE po_id=?", [$po]);
    $r = goods_receipt(['po_id' => $po, 'items' => [$pi => ['qty' => 8]]]); return expect(str_contains($r['report'], 'کسری 2'), $r['report']); };
$T['8C-POS-01'] = function () { $r = err(fn() => pay_plan_validate(1250000, [['method' => 'cash', 'amount' => 500000], ['method' => 'card', 'amount' => 700000]])); return expect($r['msg'] === '۵۰٬۰۰۰ ریال باقی‌مانده', $r['msg']); };
$T['8C-POS-02'] = function () { $r = pay_plan_validate(980000, [['method' => 'cash', 'amount' => 1000000]]); return expect($r['change'] === 20000, (string)$r['change']); };
$T['8C-POS-03'] = function () { $c = calc_doc([['title' => 'x', 'qty' => 3, 'price' => 33333]], ['vat_pct' => 10, 'tax_round' => 'floor', 'total_round' => 0]); return expect($c['vat'] === 9999 && $c['total'] === 109998, 'مالیات ' . $c['vat'] . '؛ قابلِ پرداخت ' . $c['total']); };
$T['8C-POS-04'] = function () { set_vat(0); $p = mk_product('x', 1000, 10); open_shift(1); $tok = bin2hex(random_bytes(16)); $in = ['cart_token' => $tok, 'lines' => [['product_id' => $p, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1000]]];
    $a = sale_commit($in); $b = sale_commit($in); $n = (int)val("SELECT COUNT(*) FROM sales"); return expect($a === $b && $n === 1, $n . ' فروش'); };
$T['8C-POS-05'] = function () { set_vat(0); $p = mk_product('x', 1000, 10); open_shift(1); $c = cart_open(); pos_scan((int)$c['id'], (string)val("SELECT base_code FROM products WHERE id=?", [$p]));
    $res0 = (int)val("SELECT COUNT(*) FROM stock_reservations"); add_action('sale_before_commit', function () { throw new RuntimeException('شکستِ عمدی'); });
    try { pos_checkout((int)$c['id'], [['method' => 'cash', 'amount' => 1000]]); $thrown = false; } catch (Throwable $e) { $thrown = true; }
    remove_all_hooks('sale_before_commit');
    $s = (int)val("SELECT COUNT(*) FROM sales"); $r = (int)val("SELECT COUNT(*) FROM receipts"); $res = (int)val("SELECT COUNT(*) FROM stock_reservations"); $mv = (int)val("SELECT COUNT(*) FROM stock_moves WHERE kind='out'");
    return expect($thrown && $s === 0 && $r === 0 && $mv === 0 && $res === $res0 && $res0 > 0, "sales=$s receipts=$r reservations=$res/$res0"); };
$T['8C-POS-06'] = function () { $p = mk_product('دستگاه', 1000, 0, 0, true); $lot = wh_lot_ensure($p, null, 1); $codes = wh_units_add((int)$lot['id'], 1); wh_stock_in($codes[0]);
    ins('cashboxes', ['branch_id' => 1, 'name' => 'صندوقِ ۲']); $u1 = mk_user('c1', 'صندوق‌دار ۱', ['cashier']); $u2 = mk_user('c2', 'صندوق‌دار ۲', ['cashier']); open_shift($u1, 1); open_shift($u2, 2);
    set_current_user($u1); $c1 = cart_open(); pos_scan((int)$c1['id'], $codes[0]); set_current_user($u2); $c2 = cart_open(); $r = err(fn() => pos_scan((int)$c2['id'], $codes[0])); return expect(str_starts_with($r['msg'], 'در سبدِ صندوقِ دیگری است'), $r['msg']); };
$T['8C-POS-07'] = function () { open_shift(1); $c = cart_open(); $r = err(fn() => pos_scan((int)$c['id'], 'XYZ-0')); return expect(str_contains($r['msg'], 'ناشناخته'), $r['msg']); };
$T['8C-POS-08'] = function () { set_vat(0); $p = mk_product('x', 1000, 10); open_shift(1); $s = sell([['product_id' => $p, 'qty' => 3]], [['method' => 'cash', 'amount' => 3000]]); $it = (int)val("SELECT id FROM sale_items WHERE sale_id=?", [$s]);
    return_commit($s, [['sale_item_id' => $it, 'qty' => 2]], 'خراب'); $r = err(fn() => return_commit($s, [['sale_item_id' => $it, 'qty' => 2]], 'دوباره')); return expect($r['msg'] === 'حداکثر ۱ عدد', $r['msg']); };
$T['8C-POS-09'] = function () { set_vat(0); $a = mk_product('الف', 5200000, 5); $b = mk_product('ب', 300000, 5); $sh = open_shift(1, 1, 2000000);
    sell([['product_id' => $a, 'qty' => 1]], [['method' => 'cash', 'amount' => 5200000]]); $sb = sell([['product_id' => $b, 'qty' => 1]], [['method' => 'cash', 'amount' => 300000]]);
    return_commit($sb, [['sale_item_id' => (int)val("SELECT id FROM sale_items WHERE sale_id=?", [$sb]), 'qty' => 1]], 'انصراف', 'cash'); $r = shift_close($sh, 7150000);
    return expect($r['expected'] === 7200000 && $r['diff'] === -50000 && $r['diff_label'] === 'کسری 50000', $r['diff_label']); };
$T['8C-POS-10'] = function () { $p = mk_product('x', 1000, 10); $r = err(fn() => sell([['product_id' => $p, 'qty' => 1]], [['method' => 'cash', 'amount' => 1100]])); return expect($r['msg'] === 'ابتدا شیفت را باز کنید', $r['msg']); };
function mk_channel(string $name = 'فروشگاهِ آنلاین', array $x = []): array { return channel_register_module(['slug' => 'shop', 'name' => $name, 'kind' => 'online'] + $x); }
$T['8C-CH-01'] = function () { set_vat(0); $p = mk_product('x', 1000, 10); $c = mk_channel(); $o = ['ext_id' => 'WEB-55', 'items' => [['product_id' => $p, 'qty' => 1, 'price' => 1000]], 'customer' => ['mobile' => '09120000001', 'name' => 'خریدار']];
    $a = channel_order_commit($c, $o); set_current_user(1); $b = channel_order_commit($c, $o); $n = (int)val("SELECT COUNT(*) FROM sales"); return expect($a['sale_id'] === $b['sale_id'] && $b['duplicate'] && $n === 1, "sale_id={$a['sale_id']} sales=$n"); };
$T['8C-CH-02'] = function () { $c = mk_channel(); $ts = (string)time(); $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['HTTP_X_CHANNEL_KEY'] = $c['key_id']; $_SERVER['HTTP_X_TIMESTAMP'] = $ts; $_SERVER['HTTP_X_SIGNATURE'] = str_repeat('0', 64);
    $r = err(fn() => channel_auth('channel_order', '{}')); $_SERVER['HTTP_X_SIGNATURE'] = channel_sign($c['secret'], $ts, 'POST', 'channel_order', '{}'); $ok = err(fn() => channel_auth('channel_order', '{}'));
    return expect($r['http'] === 401 && $ok['ok'], (string)$r['http']); };
$T['8C-CH-03'] = function () { $p = mk_product('x', 1000, 10); $c = mk_channel('فروشگاهِ آنلاین', ['safety_stock' => 2]); stock_reserve(999, null, $p, null, 1, 3); return expect(channel_stock($c, $p, null) === 5, (string)channel_stock($c, $p, null)); };
$T['8C-CH-04'] = function () { set_vat(0); $a = mk_product('الف', 1000, 10); $b = mk_product('ب', 2000, 10); $c = mk_channel(); channel_order_commit($c, ['ext_id' => 'W1', 'items' => [['product_id' => $a, 'qty' => 1], ['product_id' => $b, 'qty' => 1]]]); set_current_user(1);
    $labels = array_column(perf_staff_rows(today(), today()), 'label'); return expect(in_array('فروشنده: فروشگاهِ آنلاین', $labels, true), implode('، ', $labels)); };
$T['8C-STF-01'] = function () { set_vat(0); $p = mk_product('x', 1000000, 5, 800000); commission_rule_save(['name' => '۵٪ سود', 'base' => 'profit', 'tiers' => [['from' => 0, 'pct' => 5]]]); open_shift(1); $s = sell([['product_id' => $p, 'qty' => 1]], [['method' => 'cash', 'amount' => 1000000]]);
    $a = (int)val("SELECT SUM(amount) FROM commission_lines WHERE sale_id=?", [$s]); return expect($a === 10000, (string)$a); };
function stf02(): int { set_vat(0); $p = mk_product('x', 500000, 30, 100000); commission_rule_save(['name' => '۵٪', 'tiers' => [['from' => 0, 'pct' => 5]]]); open_shift(1); $s = sell([['product_id' => $p, 'qty' => 25]], [['method' => 'cash', 'amount' => 12500000]]);
    $before = commission_month(1, jym(today())); return_commit($s, [['sale_item_id' => (int)val("SELECT id FROM sale_items WHERE sale_id=?", [$s]), 'qty' => 1]], 'برگشت'); return $before; }
$T['8C-STF-02'] = function () { $before = stf02(); $after = commission_month(1, jym(today())); return expect($before === 500000 && $after === 480000, "$before → $after"); };
$T['8C-STF-03'] = function () { set_vat(0); $p = mk_product('x', 1000, 5); $u = mk_user('st', 'کارمند', ['seller']); employee_save(['name' => 'کارمند', 'base_salary' => 100000000, 'user_id' => $u]); stacc_add($u, 21000000, 'debt', null, null, 'وام');
    stacc_add($u, 50000000, 'petty', null, null, 'تنخواه'); open_shift($u); set_current_user($u);
    $cash = err(fn() => sell([['product_id' => $p, 'qty' => 1]], [['method' => 'cash', 'amount' => 1000]])); $card = err(fn() => sell([['product_id' => $p, 'qty' => 1]], [['method' => 'card', 'amount' => 1000]]));
    return expect(stacc_blocked($u) && !$cash['ok'] && $card['ok'], 'مسدود=' . (stacc_blocked($u) ? 'true' : 'false') . ' · ' . $cash['msg']); };
$T['8C-HR-01'] = function () { $e = employee_save(['name' => 'رضا']); $jy = (int)substr(jtoday(), 0, 4); leave_request(['employee_id' => $e, 'kind' => 'paid', 'from' => "$jy/01/15", 'days' => 23]);
    $r = err(fn() => leave_request(['employee_id' => $e, 'kind' => 'paid', 'from' => "$jy/02/10", 'to' => "$jy/02/14"])); return expect($r['msg'] === 'مانده ۳ روز است', $r['msg']); };
$T['8C-HR-02'] = function () { stf02(); $e = employee_save(['name' => 'مدیر', 'base_salary' => 100000000, 'user_id' => 1]); $id = payslip_save($e, jym(today())); $t = payslip_text($id); return expect(str_contains($t, 'پورسانت ۴۸۰٬۰۰۰'), mb_substr($t, 0, 220)); };
$T['8C-CRM-01'] = function () { $a = customer_save(['name' => 'مشتری', 'mobile' => '09121234567']); $b = customer_find_or_create('۰۹۱۲۱۲۳۴۵۶۷'); $n = (int)val("SELECT COUNT(*) FROM customers"); return expect($a === $b && $n === 1, "id=$b count=$n"); };
$T['8C-CRM-02'] = function () { set_vat(0); $p = mk_product('x', 3000000, 2); $c = customer_save(['name' => 'نسیه‌ای', 'mobile' => '09121111111', 'credit_limit' => 10000000]); open_shift(1);
    sell([['product_id' => $p, 'qty' => 1]], [['method' => 'credit', 'amount' => 3000000]], ['customer_id' => $c]); customer_pay($c, 1200000, 'cash'); return expect(customer_balance($c) === 1800000, (string)customer_balance($c)); };
$T['8C-LED-01'] = function () { $r = err(fn() => journal_insert([L(acc_id('cash'), 1000000, 0), L(acc_id('sales'), 0, 999999)], today(), 'x', 'manual', 't1')); return expect($r['msg'] === 'سند تراز نیست', $r['msg']); };
function led02(): int { set_vat(10); $p = mk_product('x', 1000000, 2); $c = customer_save(['name' => 'م', 'mobile' => '09122222222', 'credit_limit' => 5000000]); open_shift(1);
    return sell([['product_id' => $p, 'qty' => 1]], [['method' => 'cash', 'amount' => 600000], ['method' => 'credit', 'amount' => 500000]], ['customer_id' => $c]); }
$T['8C-LED-02'] = function () { $s = led02(); $j = (int)val("SELECT id FROM journals WHERE source_type='sale' AND source_id=?", [$s]);
    $m = []; foreach (rows("SELECT a.system_key k, l.debit, l.credit FROM journal_lines l JOIN accounts a ON a.id=l.account_id WHERE journal_id=?", [$j]) as $l) $m[] = $l['k'] . ':' . $l['debit'] . '/' . $l['credit']; sort($m);
    $want = ['cash:600000/0', 'receivable:500000/0', 'sales:0/1000000', 'vat_out:0/100000']; return expect($m === $want, implode(' ', $m)); };
$T['8C-LED-03'] = function () { $s = led02(); $a = (int)val("SELECT id FROM journals WHERE source_type='sale' AND source_id=?", [$s]); $b = ledger_post('sale', $s); return expect($a === $b && (int)val("SELECT COUNT(*) FROM journals WHERE source_type='sale'") === 1, "$a = $b"); };
$T['8C-LED-04'] = function () { $r = err(fn() => bank_account_save(['title' => 'x', 'iban' => 'IR000000000000000000000000'])); return expect($r['msg'] === 'شبا معتبر نیست', $r['msg']); };
$T['8C-REP-01'] = function () { set_vat(0); $p = mk_product('x', 1000000, 3, 700000); $c = mk_channel(); commission_rule_save(['name' => '۲٪ فروش', 'base' => 'revenue', 'user_id' => $c['user_id'], 'tiers' => [['from' => 0, 'pct' => 2]]]);
    $r = channel_order_commit($c, ['ext_id' => 'R1', 'fee' => 30000, 'items' => [['product_id' => $p, 'qty' => 1]]]); set_current_user(1); whops_order_close($r['sale_id']);
    $it = row("SELECT * FROM sale_items WHERE sale_id=?", [$r['sale_id']]); $l = accp_line($it); return expect($l['profit'] === 250000, json_encode($l)); };
$T['8C-REP-02'] = function () { set_vat(0); $a = mk_product('الف', 5000000, 2); $b = mk_product('ب', 2000000, 2); open_shift(1); sell([['product_id' => $a, 'qty' => 1]], [['method' => 'card', 'amount' => 5000000]]);
    $c = mk_channel(); channel_order_commit($c, ['ext_id' => 'D1', 'items' => [['product_id' => $b, 'qty' => 1]]]); set_current_user(1); $d = dash_today(); return expect(str_contains($d['text'], 'جمع ۷٬۰۰۰٬۰۰۰'), $d['text']); };
foreach (['8C-SHOP-01', '8C-SHOP-02', '8C-SHOP-03'] as $k) $T[$k] = fn() => ['skip', 'ماژولِ فروشگاهِ آنلاین در این مرحله ساخته نشد'];
// ── آزمون‌های تکمیلی (بیرون از فایلِ مشخصات)
$T['X-CAPS'] = function () { $bad = []; foreach (pages_all() as $k => $p) if (empty($p[1])) $bad[] = "page:$k"; $public = ['me', 'time', 'login', 'otp_request', 'otp_verify', 'pk_login_options', 'pk_login', 'channel_catalog', 'channel_order', 'channel_status'];
    foreach (api_routes() as $k => $r) { if (empty($r[0])) $bad[] = "api:$k"; if ($r[0] === 'public' && !in_array($k, $public, true)) $bad[] = "public:$k"; if ($r[0] !== 'public' && $r[0] !== 'login' && array_diff(explode('|', $r[0]), array_keys(caps_flat()))) $bad[] = "cap:$k"; }
    foreach (pages_all() as $k => $p) if ($p[1] !== 'login' && array_diff(explode('|', $p[1]), array_keys(caps_flat()))) $bad[] = "pagecap:$k"; return expect(!$bad, implode(' ', $bad) ?: 'همهٔ صفحه‌ها و APIها cap دارند'); };
$T['X-JALALI'] = function () { $ok = jparse('1405/01/01') === '2026-03-21' && jdate('2026-03-21', false, false) === '1405/01/01' && jparse('۱۴۰۳/۱۲/۳۰') === '2025-03-20'; return expect($ok, jparse('1405/01/01') . ' ' . jparse('۱۴۰۳/۱۲/۳۰')); };
$T['X-SCAN'] = function () { $a = scan_normalize('https://x.ir/t?k=ab 12'); $b = scan_normalize('۱۲۳۴'); return expect($a === 'AB12' && $b === '1234' && scan_normalize('') === '', "$a $b"); };
$T['X-PARTNER'] = function () { $p = mk_product('x', 1000000); $g = (int)val("SELECT id FROM grades WHERE kind='partner'"); partner_grade_price_save($p, $g, 5, 15, 11); return expect(partner_price($p, null, $g, 1) === 950000 && partner_price($p, null, $g, 6) === 900000 && partner_price($p, null, $g, 20) === 850000, partner_price($p, null, $g, 6) . ''); };
$T['X-UPDATE'] = function () { return expect(df_upd_safe_rel('data/x.db') === null && df_upd_safe_rel('config.local.php') === null && df_upd_safe_rel('../x') === null && df_upd_safe_rel('inc/pos.php') === 'inc/pos.php', 'safe_rel'); };
$T['X-TRIAL'] = function () { led02(); $tb = trial_balance(); return expect($tb['ok'] && $tb['dr'] > 0, $tb['dr'] . '=' . $tb['cr']); };
$T['X-WORDS'] = function () { return expect(num_words(1250000) === 'یک میلیون و دویست و پنجاه هزار', num_words(1250000)); };
$T['X-OTP'] = function () { settings_save(['sms_test_mode' => '1']); $r = otp_generate('09123334455', 'panel'); $bad = otp_check('09123334455', (string)$r['test_code'], 'staff'); $ok = otp_check('09123334455', (string)$r['test_code'], 'panel'); $again = otp_check('09123334455', (string)$r['test_code'], 'panel');
    return expect(!$bad && $ok && !$again, 'هدف/یک‌بارمصرف'); };

$T['X-LOSS'] = function () { $p = mk_product('x', 1000, 0, 0, true); $lot = wh_lot_ensure($p, null, 1); $c = wh_units_add((int)$lot['id'], 2, ['unit_cost' => 400]); wh_stock_in($c[0]); wh_stock_in($c[1]);
    $id = stock_loss(['kind' => 'loss', 'code' => $c[0], 'reason' => 'شکست در انبار']); $j = (int)val("SELECT total FROM journals WHERE source_type='stock_loss' AND source_id=?", [$id]);
    return expect(stock_on_hand($p) === 1 && $j === 400 && wh_unit_by_code($c[0])['status'] === 'out', "on_hand=" . stock_on_hand($p) . " journal=$j"); };
$T['X-WHOPS'] = function () { set_vat(0); $p = mk_product('دستگاه', 5000, 0, 0, true); $lot = wh_lot_ensure($p, null, 1); $c = wh_units_add((int)$lot['id'], 2, ['unit_cost' => 3000]); wh_stock_in($c[0]); wh_stock_in($c[1]);
    $ch = mk_channel(); $r = channel_order_commit($ch, ['ext_id' => 'S1', 'items' => [['product_id' => $p, 'qty' => 1]]]); set_current_user(1);
    $no = err(fn() => whops_order_close($r['sale_id'])); whops_order_scan_item($r['sale_id'], $c[1]); $dup = err(fn() => whops_order_scan_item($r['sale_id'], $c[1])); whops_order_close($r['sale_id']);
    $s = row("SELECT * FROM sales WHERE id=?", [$r['sale_id']]); return expect(!$no['ok'] && !$dup['ok'] && $s['fulfil'] === 'shipped' && (int)$s['cost'] === 3000 && wh_unit_by_code($c[1])['status'] === 'out' && stock_on_hand($p) === 1, $s['fulfil'] . ' cost=' . $s['cost']); };
$T['X-GUARD'] = function () { $p = mk_product('x', 100000); q("UPDATE products SET price_guard_pct=30 WHERE id=?", [$p]); product_price_set($p, 110000); $a = (int)val("SELECT active FROM products WHERE id=?", [$p]);
    $m = err(fn() => product_price_set($p, 1100000)); product_price_set($p, 200000, 'auto'); $b = row("SELECT active, price FROM products WHERE id=?", [$p]); product_price_set($p, 200000, 'manual', true);
    return expect($a === 1 && !$m['ok'] && (int)$b['active'] === 0 && (int)$b['price'] === 110000 && (int)val("SELECT price FROM products WHERE id=?", [$p]) === 200000, $m['msg']); };
$T['X-DUP'] = function () { $p = mk_product('ژله', 1000); variant_add($p, 'توت‌فرنگی'); variant_add($p, 'پرتقال'); $d = product_duplicate($p, 'ژلهٔ ویژه'); $a = product_get($p); $b = product_get($d);
    return expect($a['base_code'] !== $b['base_code'] && count(product_variants($d)) === 2 && code_family_valid($b['base_code']) && product_variants($d)[0]['code'] === $b['base_code'] . '-VAR.01', $b['base_code']); };
$T['X-ACCSHARE'] = function () { ins('acc_partners', ['name' => 'الف', 'coef' => 2]); ins('acc_partners', ['name' => 'ب', 'coef' => 1]); $ym = str_replace('/', '-', jym(today())); ins('acc_expenses', ['title' => 'اجاره', 'amount' => 1000, 'monthly' => 1, 'from_ym' => $ym, 'created_at' => now()]);
    $doc = ins('docs', ['kind' => 'invoice', 'status' => 'issued', 'd' => today(), 'lines' => '[]', 'buyer' => '{"name":"x"}', 'total' => 2001, 'confirmed' => 1, 'created_at' => now()]); doc_pay_add($doc, 2001, today());
    $c = acc_calc(); $e = array_column($c['partners'], 'earned', 'name'); return expect($e['الف'] === 668 && $e['ب'] === 333, json_encode($e, JSON_UNESCAPED_UNICODE)); };

$T['X-CODE'] = function () { $p = mk_product('گوشی', 1000); $v = variant_add($p, 'قرمز', 0, null, 'red'); $lot = wh_lot_ensure($p, $v, 1); $u = wh_units_add((int)$lot['id'], 2);
    $s = supplier_save(['name' => 'امانت‌گذار', 'seller_code' => 'ab1']); $c = wh_lot_ensure($p, null, 1, 'AB1', 'C', $s); $bad = err(fn() => wh_lot_ensure($p, null, 1, '', 'C'));
    $pu = unit_parse($u[1]); $ok = $lot['code'] === 'SL-GENR-00001-VAR.RED/OWN.TH01' && $u[1] === $lot['code'] . '/000002' && $pu['level'] === 'unit' && $pu['seq'] === 2 && $pu['store'] === 'TH01'
        && code_level($lot['code']) === 'lot' && $c['code'] === 'SL-GENR-00001/AB1.TH01' && !$bad['ok'] && scan_lookup($u[0])['type'] === 'unit' && scan_lookup($lot['code'])['type'] === 'lot' && scan_lookup('SL-GENR-00001-VAR.RED')['type'] === 'variant';
    return expect($ok, $u[1] . ' · ' . $c['code']); };

$T['X-HOLIDAY'] = function () { $j = json_encode([['event_name' => 'تاسوعا', 'is_holiday' => true, 'date' => ['type' => 'shamsi', 'date' => ['۴', 'تیر']]], ['event_name' => 'x', 'date' => ['type' => 'hijri', 'date' => [9, 1]]]], JSON_UNESCAPED_UNICODE);
    $r = hol_parse($j); $d = jparse('1405/04/04'); holidays_import([['d' => $d, 'title' => 'تاسوعا']], 'online'); $a = holiday_info($d)['off']; ins('holidays', ['d' => $d, 'title' => 'لغو', 'source' => 'manual', 'is_off' => 0]); $b = holiday_info($d)['off'];
    return expect(count($r) === 1 && $r[0]['jm'] === 4 && $r[0]['jd'] === 4 && $a && !$b, json_encode($r, JSON_UNESCAPED_UNICODE)); };
$T['X-FX'] = function () { $n = fx_parse_navasan('{"usd_sell":{"value":"95,000"}}', 'toman')['USD'] ?? 0; $t = fx_parse_tgju_profile('{"summary":{"price":{"plain":"960,000"}},"info":{"currency_unit":"ریال"}}');
    $p = fx_pick($n, $t, 20); $bad = fx_pick(950000, 95000, 20); return expect($n === 950000 && $t === 960000 && $p['rate'] === 960000 && $bad['rate'] === 0, "$n $t {$p['rate']}"); };

$T['X-SERVICE'] = function () { set_vat(0); $p = product_save(['name' => 'نصب و راه‌اندازی', 'price' => 500000, 'item_type' => 'REP', 'serial' => 0]); open_shift(1); $c = cart_open();
    pos_scan((int)$c['id'], (string)val("SELECT base_code FROM products WHERE id=?", [$p]), 2); $r = pos_checkout((int)$c['id'], [['method' => 'card', 'amount' => 1000000]]);
    return expect((int)val("SELECT total FROM sales WHERE id=?", [$r['sale_id']]) === 1000000, $r['number']); };

// ── فاکتورساز: برابری با اینورا و امکاناتش
$T['X-INV-PARITY'] = function () {
    $cases = json_decode((string)file_get_contents(__DIR__ . '/fixtures/invora_calc.json'), true); $bad = [];
    foreach ($cases as $k => $c) {
        $doc = $c['doc']; $g = $c['g']; $cur = $doc['currency'];
        $tr = null; if (!$doc['official'] && $g['totalRound'] !== 'off' && $cur !== 'USD' && !($g['totalRound'] === 'fx' && !array_filter($doc['items'], fn($i) => !empty($i['fx'])))) { $u = (float)$g['fxRound'] / ($cur === 'IRT' ? 10 : 1); if ($u > 1) $tr = ['u' => $u, 'mode' => $g['totalRoundMode'] === 'near' ? 'near' : 'down']; }
        $lines = array_map(fn($i) => ['qty' => $i['qty'], 'price' => $i['price'], 'disc' => $i['disc'], 'disc_type' => $i['discType'] === '%' ? '%' : 'amount', 'taxable' => $i['taxable']], $doc['items']);
        try { $t = calc_doc($lines, ['doc_disc' => $doc['disc'], 'doc_disc_type' => $doc['discType'] === '%' ? '%' : 'amount', 'vat_on' => $doc['vatOn'], 'vat_pct' => $doc['vatRate'], 'official' => $doc['official'], 'extra' => $doc['extra'], 'currency' => $cur, 'total_round' => $tr, 'tax_round' => 'round']); }
        catch (Throwable $e) { if (array_filter($doc['items'], fn($i) => $i['qty'] <= 0)) continue; $bad[] = "#$k " . $e->getMessage(); continue; }
        foreach (['sub', 'lineDisc', 'afterLine', 'docDisc', 'afterDisc', 'taxableBase', 'vat', 'extra', 'roundAdj', 'total'] as $f) if (abs((float)$t[$f] - (float)$c['t'][$f]) > 1e-6) { $bad[] = "#$k $f: " . $t[$f] . ' ≠ ' . $c['t'][$f]; break; }
        if ($c['t']['off']) foreach ($c['t']['off'] as $i => $r) foreach ($r as $f => $v) if (abs((float)$t['off'][$i][$f] - (float)$v) > 1e-6) { $bad[] = "#$k off[$i].$f"; break 2; }
        if (money_words_cur($t['total'], $cur) !== $c['words']) $bad[] = "#$k words: " . money_words_cur($t['total'], $cur) . ' ≠ ' . $c['words'];
        if (count($bad) > 5) break;
    }
    return expect(!$bad, $bad ? implode(' | ', $bad) : count($cases) . ' سند همان نتیجهٔ اینورا'); };
function mk_doc(array $x = []): int { return doc_save($x + ['kind' => 'invoice', 'buyer_name' => 'شرکت نمونه', 'lines' => [['name' => 'کالا', 'qty' => 3, 'price' => 100000]]]); }
$T['X-INV-NUM'] = function () { settings_save(['doc_pat_invoice' => 'INV-{Y}{M}-{N}', 'doc_pad' => 3]); // پیش‌شماره برای همه
    $a = doc_commit(mk_doc(['d' => '1405/02/15'])); $m = mk_doc(['d' => '1405/02/15', 'number' => 'INV-140502-005']); $mc = doc_commit($m); $b = doc_commit(mk_doc(['d' => '1405/02/16']));
    $dup = err(fn() => doc_commit(mk_doc(['number' => 'INV-140502-005']))); $pf = doc_commit(mk_doc(['kind' => 'proforma', 'd' => '1405/02/15']));
    return expect($a['number'] === 'INV-140502-001' && $mc['number'] === 'INV-140502-005' && $b['number'] === 'INV-140502-002' && !$dup['ok'] && $pf['number'] === 'P1405-001' && strlen((string)$a['token']) === 22 && (settings_save(['doc_pad' => 4, 'doc_pat_invoice' => '{Y}-{N}']) || true), "{$a['number']} {$b['number']} {$pf['number']}"); };
$T['X-INV-PF'] = function () { settings_save(['proforma_gap_days' => 1]); $p = mk_doc(['kind' => 'proforma', 'd' => '1405/02/16', 'valid_days' => 7]); // ۱۶ اردیبهشت = چهارشنبه ← ۱۷ پنجشنبه (روزِ کاری)؛ با گپِ ۲ روز جمعه ← شنبه
    $inv = doc_convert($p); $i = doc_get($inv); $pd = doc_get($p); doc_commit($inv); $pd = doc_get($p);
    return expect($i['kind'] === 'invoice' && jdate($i['d'], false, false) === '1405/02/17' && (function () use ($p) { settings_save(['proforma_gap_days' => 2]); $x = doc_get(doc_convert(mk_doc(['kind' => 'proforma', 'd' => '1405/02/16']))); settings_save(['proforma_gap_days' => 1]); return jdate($x['d'], false, false) === '1405/02/19'; })() && (int)$i['linked_id'] === $p && (int)$pd['linked_id'] === $inv && doc_status($pd)[0] === 'تبدیل به فاکتور شد' && doc_link_check(doc_get($inv))[0] === 'info', jdate($i['d'], false, false) . ' ' . doc_status($pd)[0]); };
$T['X-INV-OFF'] = function () { $d = doc_save(['kind' => 'official', 'buyer_name' => 'خریدار', 'lines' => [['name' => 'کالا', 'qty' => 1, 'price' => 1000]]]); $e1 = official_issues(doc_get($d))['E'];
    $c = err(fn() => doc_commit($d)); settings_save(['biz_name' => 'فروشگاه', 'biz_national_id' => '10101010101', 'biz_postal_code' => '1234567890', 'biz_address' => 'تهران']);
    $d2 = doc_save(['kind' => 'official', 'off_kind' => 'type2', 'lines' => [['name' => 'کالا', 'qty' => 3, 'price' => 33333]], 'doc_disc' => 10]); $r = doc_commit($d2); $t = doc_calc(doc_get($d2));
    $irt = doc_save(['kind' => 'invoice', 'currency' => 'IRT', 'lines' => [['name' => 'x', 'qty' => 1, 'price' => 5000]], 'extra' => 7]); doc_make_official($irt); $o = doc_get($irt);
    return expect(count($e1) >= 3 && !$c['ok'] && $r['number'] !== '' && $t['off'][0]['tax'] === 9999 && $o['currency'] === 'IRR' && (float)$o['lines'][0]['price'] === 50000.0 && (float)$o['opts']['extra'] === 0.0, implode('؛ ', $e1)); };
$T['X-INV-CREDIT'] = function () { $d = mk_doc(); doc_commit($d); $c1 = doc_credit_create($d, [0 => 2], 'خراب'); $e = err(fn() => doc_credit_create($d, [0 => 2], 'دوباره')); $cd = doc_get($c1);
    return expect($cd['kind'] === 'return' && str_starts_with((string)$cd['number'], 'R') && str_starts_with($e['msg'], 'حداکثر') && acc_doc_rial(doc_get($d)) === 100000 + (int)floor(100000 * vat_pct() / 100 + .5) - ((int)$cd['total'] - (int)round(200000 * 1.1)) - 0 ? true : acc_doc_rial(doc_get($d)) === (int)doc_get($d)['total'] - (int)$cd['total'], $e['msg'] . ' · ' . $cd['number']); };
$T['X-INV-AMEND'] = function () { $d = mk_doc(); doc_commit($d); $r = doc_amend($d, ['reason' => 'اصلاحِ قیمت', 'buyer_name' => 'شرکت نمونه', 'lines' => [['name' => 'کالا', 'qty' => 3, 'price' => 120000]]]);
    $noReason = err(fn() => doc_amend($d, ['lines' => [['name' => 'x', 'qty' => 1, 'price' => 1]]])); acc_confirm($d, 'تأییدِ تلفنی'); $after = err(fn() => doc_amend($d, ['reason' => 'باز', 'lines' => [['name' => 'x', 'qty' => 1, 'price' => 1]]])); $v = err(fn() => doc_void($d, 'x'));
    $dd = doc_get($d); return expect($r === 2 && !$noReason['ok'] && !$after['ok'] && !$v['ok'] && (int)val("SELECT COUNT(*) FROM doc_revisions WHERE doc_id=?", [$d]) === 2 && (float)$dd['lines'][0]['price'] === 120000.0 && $dd['vf_html'] !== null, $after['msg']); };
$T['X-INV-SHARE'] = function () { $d = mk_doc(); doc_commit($d); $s = doc_share($d, 30, 0, '12345'); $_SESSION = []; $t = substr($s['url'], strpos($s['url'], 's=') + 2);
    $a = doc_share_open($t, ''); $short = doc_share_open($t, '12'); $ok = doc_share_open($t, '12345'); $_SESSION = [];
    for ($i = 0; $i < 5; $i++) $last = doc_share_open($t, '99999'); $after = doc_share_open($t, '12345');
    return expect(!empty($a['need_pass']) && !empty($short['need_pass']) && (int)val("SELECT fails FROM doc_shares WHERE id=?", [$s['id']]) === 5 && !empty($ok['doc']) && !empty($after['err']) && $after['code'] === 410, $after['err'] ?? ''); };
$T['X-VF'] = function () { settings_save(['sms_test_mode' => '1']); $d = mk_doc(['buyer_mobile' => '09121112233']); doc_commit($d); $doc = doc_get($d); $_SESSION = [];
    [$ok, $m, $code] = vf_send_code($doc); $bad = vf_check_pin($doc, $code === '00000' ? '11111' : '00000'); $pass0 = vf_pass_ok($doc); $good = vf_check_pin($doc, $code); $pass = vf_pass_ok($doc);
    settings_save(['vf_mobile' => '']); $nd = mk_doc(); doc_commit($nd); upd('docs', ['contact_mobile' => null], 'id=?', [$nd]); $none = vf_send_code(doc_get($nd));
    $log = array_column(rows("SELECT event FROM vf_log WHERE doc_id=?", [$d]), 'event');
    return expect($ok && $doc['contact_mobile'] === '09121112233' && !$bad[0] && !$pass0 && $good[0] && $pass && !$none[0] && in_array('vf_ok', $log, true) && in_array('vf_bad', $log, true), $m . ' · ' . $none[1]); };
$T['X-INV-SALERET'] = function () { set_vat(0); $p = mk_product('x', 100000, 5); open_shift(1); $s = sell([['product_id' => $p, 'qty' => 3]], [['method' => 'cash', 'amount' => 300000]]); $inv = doc_from_sale($s);
    return_commit($s, [['sale_item_id' => (int)val("SELECT id FROM sale_items WHERE sale_id=?", [$s]), 'qty' => 1]], 'خراب'); $cr = row("SELECT * FROM docs WHERE credit_ref=?", [$inv]);
    return expect($cr && $cr['kind'] === 'return' && (int)$cr['total'] === 100000 && acc_doc_rial(doc_get($inv)) === 200000, $cr['number'] ?? 'بی‌سند'); };

// ── حقوق: مالیاتِ پلکانی و سقفِ بیمه
$T['X-PAYTAX'] = function () { $L = labor_rates(); $L = ['tax_exempt' => 400000000, 'tax_pct' => 10, 'tax_s2_from' => 600000000, 'tax_s2_pct' => 15, 'tax_s3_from' => 900000000, 'tax_s3_pct' => 20] + $L;
    $a = payroll_tax(1000000000, $L); $b = payroll_tax(350000000, $L); $c = payroll_tax(500000000, $L); $cap = payroll_ins_base(2000000000, $L); $nocap = payroll_ins_base(2000000000, ['ins_cap_factor' => 0] + $L);
    return expect($a === 85000000 && $b === 0 && $c === 10000000 && $cap === 7 * (int)$L['min_monthly'] && $nocap === 2000000000, "$a $b $c $cap"); };
// ── واریز به حسابِ همکار
$T['X-PTR'] = function () { if (!defined('PAYREQ_TEST')) define('PAYREQ_TEST', 1); set_vat(0);
    $bank = ins('bank_accounts', ['title' => 'ملت جاری', 'bank' => 'ملت', 'iban' => 'IR120120000000001234567890', 'owner' => 'فروشگاه', 'active' => 1]);
    $cid = customer_save(['kind' => 'person', 'name' => 'همکارِ واریزی', 'mobile' => '09125550001', 'is_partner' => 1, 'credit_limit' => 0]);
    $p = mk_product('گوشی', 1000000, 5); open_shift(1);
    $no = err(fn() => sell([['product_id' => $p, 'qty' => 1]], [], ['customer_id' => $cid, 'partner_mode' => 'transfer']));
    ptr_save($cid, true, $bank); $s = sell([['product_id' => $p, 'qty' => 2]], [], ['customer_id' => $cid, 'partner_mode' => 'transfer']); $sale = row("SELECT * FROM sales WHERE id=?", [$s]);
    $r = payreq_open_for_sale($s); $inQ = in_array($s, array_map('intval', array_column(whops_out_queue(), 'id')), true); $res = (int)val("SELECT COALESCE(SUM(qty),0) FROM stock_reservations WHERE sale_id=?", [$s]);
    $noFile = err(fn() => payreq_approve((int)$r['id']));
    $tmp = tempnam(sys_get_temp_dir(), 'rc'); file_put_contents($tmp, "\xFF\xD8\xFF\xE0" . str_repeat('x', 100)); $bad = tempnam(sys_get_temp_dir(), 'rc'); file_put_contents($bad, '<?php echo 1;');
    $badUp = err(fn() => payreq_add_files((int)$r['id'], ['name' => ['a.jpg'], 'tmp_name' => [$bad], 'error' => [0], 'size' => [13]]));
    $n = payreq_add_files((int)$r['id'], ['name' => ['a.jpg', 'b.jpg'], 'tmp_name' => [$tmp, $tmp], 'error' => [0, 0], 'size' => [104, 104]]);
    $bal0 = customer_balance($cid); payreq_approve((int)$r['id'], 'رسید'); $after = row("SELECT fulfil FROM sales WHERE id=?", [$s])['fulfil'];
    $j = (int)val("SELECT COUNT(*) FROM journals WHERE source_type='customer_pay'");
    $s2 = sell([['product_id' => $p, 'qty' => 1]], [], ['customer_id' => $cid, 'partner_mode' => 'transfer']); $r2 = payreq_open_for_sale($s2); $noReason = err(fn() => payreq_reject((int)$r2['id'], ''));
    payreq_reject((int)$r2['id'], 'فیش ناخوانا'); $again = payreq_create($s2); $card = payreq_sale_card(sale_get($s2)); $cardOk = str_contains($card, 'ملت جاری') && str_contains($card, 'فیش ناخوانا') && str_contains($card, 'payreq_files');
    return expect(!$no['ok'] && $sale['fulfil'] === 'await_pay' && $r && (int)$r['amount'] === 2000000 && !$inQ && $res === 2 && !$noFile['ok'] && !$badUp['ok'] && $n === 2 && $bal0 === 2000000 + 0 && customer_balance($cid) === 1000000
        && $after === 'pending' && $j >= 1 && !$noReason['ok'] && $again > 0 && $cardOk && row("SELECT fulfil FROM sales WHERE id=?", [$s2])['fulfil'] === 'await_pay', "$no[msg] · $badUp[msg] · bal0=$bal0 · $after"); };

// ── سامانهٔ مودیان: شمارهٔ مالیاتی، امضا/رمزنگاری و ارسالِ کامل به سامانهٔ ساختگی (tests/fixtures/moadian_mock.php روی php -S)
$T['X-MOADIAN'] = function () { $F = __DIR__ . '/fixtures/';
    $v = verhoeff_digit('236') === 3 && verhoeff_ok('2363') && !verhoeff_ok('2364');
    $tid = moadian_tax_id('A11216', 1700000000, 77); $okTid = strlen($tid) === 22 && str_starts_with($tid, 'A11216') && hexdec(substr($tid, 6, 5)) === 19675 && hexdec(substr($tid, 11, 10)) === 77
        && verhoeff_ok('6511216' . '019675' . '000000000077' . substr($tid, -1)); // A ← ۶۵ (کدِ ASCII)
    $j = moadian_jwe(moadian_jws('{"a":"ب"}', file_get_contents($F . 'moadian_client_test.key'), file_get_contents($F . 'moadian_client_test.crt')), file_get_contents($F . 'moadian_srv_test.pub'), 'k1');
    $back = explode('.', moadian_jwe_decrypt($j, file_get_contents($F . 'moadian_srv_test.key'))); $rt = mb64u_dec($back[1]) === '{"a":"ب"}';
    settings_save(['biz_name' => 'فروشگاه', 'biz_national_id' => '10101010101', 'biz_economic_code' => '10101010101', 'biz_postal_code' => '1234567890', 'biz_address' => 'تهران', 'biz_memory_id' => 'A11216']);
    $d = doc_save(['kind' => 'official', 'buyer_kind' => 'legal', 'buyer_name' => 'خریدار', 'buyer_national_id' => '10101010101', 'buyer_postal' => '1234567890', 'lines' => [['name' => 'گوشی', 'qty' => 2, 'price' => 50000000, 'unit' => 'عدد'], ['name' => 'نصب', 'qty' => 1, 'price' => 1000000, 'unit' => 'سرویس', 'code' => '2720000000017']], 'doc_disc' => 1000000]);
    doc_commit($d); $e1 = moadian_invoice(doc_get($d))[1];
    settings_save(['moadian_sstid_default' => '2330001234567', 'moadian_units' => "عدد=1627\nسرویس=1631"]); [$inv, $e2] = moadian_invoice(doc_get($d));
    $okInv = $inv && !$e2 && $inv['header']['tbill'] === (int)doc_get($d)['total'] && $inv['body'][1]['sstid'] === '2720000000017' && $inv['body'][0]['mu'] === '1627' && $inv['header']['tob'] === 2 && $inv['header']['ins'] === 1;
    $srv = @file_get_contents('http://127.0.0.1:8088/tests/fixtures/moadian_mock.php/nonce'); if (!$srv) return ['skip', 'سرورِ آزمون (php -S روی 8088) در دسترس نیست — بخشِ رمزنگاری: ' . ($v && $okTid && $rt && $okInv ? 'درست' : 'نادرست') . ' · ' . implode('؛ ', $e1)];
    settings_save(['moadian_on' => '1', 'moadian_base' => 'http://127.0.0.1:8088/tests/fixtures/moadian_mock.php', 'moadian_secret_key' => file_get_contents($F . 'moadian_client_test.key'), 'moadian_cert' => file_get_contents($F . 'moadian_client_test.crt'), 'moadian_srv_key' => '']);
    $ref = moadian_send($d); $twice = err(fn() => moadian_send($d)); $st = moadian_inquiry($d); $dd = doc_get($d);
    return expect($v && $okTid && $rt && count($e1) >= 2 && $okInv && str_starts_with($ref, 'REF-') && !$twice['ok'] && $st === 'accepted' && $dd['tax_id'] === $inv['header']['taxid'] && (int)val("SELECT COUNT(*) FROM moadian_log WHERE doc_id=?", [$d]) >= 2,
        "tid=$tid ref=$ref st=$st · " . ($dd['moadian_msg'] ?? '') . ' · ' . implode('؛ ', $e1)); };

// ── v1.4: نسیه و قولِ پرداخت، بله، چک، رو به اتمام، روند، ٪ همهٔ اقلام، قیمتِ همکاری، ارسال، لینکِ نشانی، دسترسیِ تک‌کاربر
function fake_bale(): void { $GLOBALS['__bale_sent'] = []; $GLOBALS['__bale_fake'] = function ($m, $p) { if ($m === 'sendMessage') { $GLOBALS['__bale_sent'][] = $p; return ['ok' => true]; } if ($m === 'getUpdates') return ['ok' => true, 'result' => $GLOBALS['__bale_updates'] ?? []]; return ['ok' => true]; }; }
function sms_tpls_on(array $keys): void { $t = []; foreach ($keys as $i => $k) $t[$k] = ['id' => (string)(1000 + $i), 'on' => 1]; settings_save(['sms_templates' => $t, 'sms_test_mode' => '1']); }
$T['X-CREDIT'] = function () { set_vat(0); fake_bale(); settings_save(['bale_bot_token' => 'x:y']); sms_tpls_on(['promise_due', 'promise_remind']);
    upd('users', ['mobile' => '09120000009', 'bale_chat_id' => '555'], 'id=?', [1]); set_current_user(1);
    $p = mk_product('x', 100000, 5); open_shift(1); $cid = customer_save(['kind' => 'person', 'name' => 'علی', 'mobile' => '09121234567', 'credit_limit' => 1000000]);
    sell([['product_id' => $p, 'qty' => 3]], [['method' => 'credit', 'amount' => 300000]], ['customer_id' => $cid]);
    $pid = promise_save(['customer_id' => $cid, 'due' => jdate(date('Y-m-d', strtotime('+1 day')), false, false), 'sms_customer' => 1]);
    $n1 = promise_reminders(); $n1b = promise_reminders(); $baleN = count($GLOBALS['__bale_sent']);
    $n2 = promise_reminders(date('Y-m-d', strtotime('+1 day'))); $logs = (int)val("SELECT COUNT(*) FROM notify_log WHERE ok=1");
    $alerts = array_filter(dash_alerts(), fn($a) => $a['w'] === 'credit');
    $pz = err(fn() => promise_save(['customer_id' => customer_save(['kind' => 'person', 'name' => 'بی‌بدهی', 'mobile' => '09121234500']), 'due' => '1405/09/01']));
    customer_pay($cid, 300000, 'card'); promise_autoclose(); $st = val("SELECT status FROM credit_promises WHERE id=?", [$pid]);
    $acc = credit_accounts();
    return expect($n1 === 3 && $n1b === 0 && $baleN === 1 && $n2 === 3 && $logs === 6 && !$pz['ok'] && $st === 'kept' && !$acc, "pre=$n1 again=$n1b due=$n2 logs=$logs bale=$baleN st=$st"); };
$T['X-BALELINK'] = function () { fake_bale(); settings_save(['bale_bot_token' => 'x:y']); $c = bale_link_code('users', 1); $GLOBALS['__bale_updates'] = [['update_id' => 7, 'message' => ['chat' => ['id' => 9001], 'text' => '/start ' . $c]]];
    $n = bale_poll(); $u = row("SELECT bale_chat_id, bale_code FROM users WHERE id=1"); return expect($n === 1 && $u['bale_chat_id'] === '9001' && $u['bale_code'] === null && setting('bale_offset') === '8' && count($GLOBALS['__bale_sent']) === 1, json_encode($u)); };
$T['X-CHEQUE'] = function () { set_vat(0); fake_bale(); settings_save(['bale_bot_token' => 'x:y']); upd('users', ['bale_chat_id' => '555'], 'id=?', [1]); set_current_user(1);
    $p = mk_product('x', 1000000, 5); open_shift(1); $cid = customer_save(['kind' => 'person', 'name' => 'همکار', 'mobile' => '09121234567', 'credit_limit' => 9000000, 'is_partner' => 1]);
    sell([['product_id' => $p, 'qty' => 3]], [['method' => 'credit', 'amount' => 3000000]], ['customer_id' => $cid]);
    $c1 = cheque_receive(['customer_id' => $cid, 'number' => '123', 'sayad' => '1234567890123456', 'amount' => 2000000, 'due' => jdate(date('Y-m-d', strtotime('+2 day')), false, false)]); $b1 = customer_balance($cid);
    $dup = err(fn() => cheque_receive(['customer_id' => $cid, 'number' => '124', 'sayad' => '1234567890123456', 'amount' => 1, 'due' => '1405/10/01']));
    $rem = cheque_reminders(); cheque_reverse($c1, 'bounced', 'کسریِ موجودی'); $b2 = customer_balance($cid);
    $c2 = cheque_receive(['customer_id' => $cid, 'number' => '125', 'amount' => 1000000, 'due' => '1405/10/01']); $bank = bank_account_save(['title' => 'ملت']); cheque_status($c2, 'cleared', $bank);
    $tb = fn($k) => (int)val("SELECT COALESCE(SUM(debit-credit),0) FROM journal_lines WHERE account_id=?", [acc_id($k)]);
    return expect($b1 === 1000000 && !$dup['ok'] && $rem >= 1 && $b2 === 3000000 && customer_balance($cid) === 2000000 && $tb('cheque_in') === 0 && $tb('receivable') === 2000000 && bank_balance($bank) === 1000000 && (int)val("SELECT COUNT(*) FROM journals WHERE source_type='cheque_back'") === 1, "b1=$b1 b2=$b2 rem=$rem recv=" . $tb('receivable')); };
$T['X-LOWSTOCK'] = function () { set_vat(0); $a = mk_product('کم', 1000, 2); upd('products', ['min_stock' => 5], 'id=?', [$a]); $b = mk_product('پرفروش', 1000, 40); $c = mk_product('راکد', 1000, 3); $d = mk_product('تمام', 1000, 1); open_shift(1);
    sell([['product_id' => $b, 'qty' => 35]], [['method' => 'cash', 'amount' => 35000]]); sell([['product_id' => $d, 'qty' => 1]], [['method' => 'cash', 'amount' => 1000]]);
    $r = array_column(low_stock_report(), null, 'name'); $dig = 0; fake_bale(); settings_save(['bale_bot_token' => 'x:y']); upd('users', ['bale_chat_id' => '5'], 'id=?', [1]); $dig = stock_low_digest(); $dig2 = stock_low_digest();
    return expect(isset($r['کم'], $r['پرفروش'], $r['تمام']) && !isset($r['راکد']) && $r['پرفروش']['days_left'] <= 7 && $r['تمام']['state'] === 'تمام شده' && $r['کم']['suggest'] >= 3 && $dig === 1 && $dig2 === 0, json_encode(array_map(fn($x) => [$x['state'], $x['days']], $r), JSON_UNESCAPED_UNICODE)); };
$T['X-TREND'] = function () { set_vat(0); $a = mk_product('رو به رشد', 100000, 50, 60000); $b = mk_product('رو به افت', 100000, 50, 60000); open_shift(1);
    $s1 = sell([['product_id' => $a, 'qty' => 1], ['product_id' => $b, 'qty' => 5]], [['method' => 'cash', 'amount' => 600000]]); upd('sales', ['d' => date('Y-m-d', strtotime('-40 day'))], 'id=?', [$s1]);
    sell([['product_id' => $a, 'qty' => 4], ['product_id' => $b, 'qty' => 1]], [['method' => 'cash', 'amount' => 500000]]);
    $t = array_column(trend_compare(['from' => date('Y-m-d', strtotime('-29 day')), 'to' => today()]), null, 'name'); $m = monthly_trend(12);
    return expect(str_contains($t['رو به رشد']['trend'], 'افزایشی') && str_contains($t['رو به افت']['trend'], 'کاهشی') && $t['رو به رشد']['profit'] === 160000 && $t['رو به رشد']['rev_change'] === '۳۰۰٪' && count($m) === 12 && end($m)['_rev'] >= 500000,
        $t['رو به رشد']['trend'] . ' ' . $t['رو به رشد']['rev_change'] . ' / ' . $t['رو به افت']['trend'] . ' ' . $t['رو به افت']['rev_change']); };
$T['X-ADJ'] = function () { set_vat(0); $p = mk_product('x', 123456, 10); $q = mk_product('y', 200000, 10); open_shift(1); $c = cart_open();
    cart_add_line_test((int)$c['id'], $p); cart_add_line_test((int)$c['id'], $q); $v = cart_adjust((int)$c['id'], '10');
    $prices = array_column($v['items'], 'price'); $r = pos_checkout((int)$c['id'], [['method' => 'cash', 'amount' => 400000]]); $s = sale_get($r['sale_id']);
    $sid = mk_user('sel', 'فروشنده', ['seller'], ['max_discount_pct' => 3]); $cb2 = ins('cashboxes', ['branch_id' => (int)val("SELECT id FROM branches LIMIT 1"), 'name' => 'صندوقِ ۲']); open_shift($sid, $cb2); set_current_user($sid); $c2 = cart_open(); $big = err(fn() => cart_adjust((int)$c2['id'], '-5')); $ok = err(fn() => cart_adjust((int)$c2['id'], '-3')); set_current_user(1);
    return expect($prices == [136000, 220000] && (int)$s['total'] === 356000 && (float)$s['adj_pct'] === 10.0 && count($s['items']) === 2 && !$big['ok'] && $ok['ok'], json_encode($prices) . ' total=' . $s['total'] . ' ' . $big['msg']); };
function cart_add_line_test(int $cart, int $pid): void { api_cart_add(['cart_id' => $cart, 'product_id' => $pid, 'qty' => 1]); }
$T['X-PGRID'] = function () { $g = ins('grades', ['name' => 'طلایی', 'kind' => 'partner', 'disc_pct' => 0]); $p = mk_product('گوشی', 1000000, 5); $q = mk_product('قاب', 100000, 5);
    $n = partner_prices_save_bulk([$p => [$g => '5'], $q => [$g => '10']], [$p => [$g => '20'], $q => [$g => '0']], [$p => [$g => '11'], $q => [$g => '']]);
    $cid = customer_save(['kind' => 'person', 'name' => 'همکار', 'mobile' => '09121112222', 'is_partner' => 1, 'partner_grade_id' => $g]); $pl = array_column(partner_price_list($cid), null, 'name');
    $n2 = partner_prices_save_bulk([$q => [$g => '0']], [$q => [$g => '0']], [$q => [$g => '']]); $t = plist_token($cid); $bad = plist_parse(substr($t, 0, -1) . (substr($t, -1) === 'A' ? 'B' : 'A'));
    $d1 = partner_list_doc($cid, 2); $d2 = partner_list_doc($cid, 2);
    return expect($n === 2 && $pl['گوشی']['price_one'] === 950000 && $pl['گوشی']['price_max'] === 800000 && $pl['قاب']['price_one'] === 90000 && $n2 === 1 && count(partner_price_list($cid)) === 1 && plist_parse($t) === $cid && $bad === null && $d1 === $d2 && str_starts_with($d1, 'PL-'), json_encode($pl, JSON_UNESCAPED_UNICODE)); };
$T['X-SHIP'] = function () { set_vat(0); sms_tpls_on(['ship_track']); $p = mk_product('x', 100000, 5); open_shift(1); $cid = customer_save(['kind' => 'person', 'name' => 'رضا', 'mobile' => '09121234567']);
    $a = address_save($cid, ['city' => 'تهران', 'address' => 'خیابانِ آزادی', 'plaque' => '۱۲', 'postal_code' => '1234567890']); $s = sell([['product_id' => $p, 'qty' => 1]], [['method' => 'cash', 'amount' => 100000]], ['customer_id' => $cid]);
    $k = courier_save(['name' => 'پیک ۱', 'mobile' => '09129990000', 'carrier' => 'peyk']); $noAddr = err(fn() => ship_save(['carrier' => 'post']));
    $id = ship_save(['sale_id' => $s, 'carrier' => 'snapp', 'courier_id' => $k, 'tracking' => 'SN-1', 'address_id' => $a, 'cost' => 85000, 'payer' => 'shop', 'paid_from' => 'cash', 'sms' => 1]);
    $sh = row("SELECT * FROM shipments WHERE id=?", [$id]); ship_status($id, 'delivered'); $exp = (int)val("SELECT COALESCE(SUM(debit),0) FROM journal_lines WHERE account_id=?", [acc_id('shipping')]);
    $id2 = ship_save(['sale_number' => (string)val("SELECT number FROM sales WHERE id=?", [$s]), 'carrier' => 'post', 'tracking' => 'P1', 'cost' => 50000, 'payer' => 'customer']); $sum = array_column(ship_cost_summary(date('Y-m-d'), date('Y-m-d')), null, 'carrier');
    return expect(!$noAddr['ok'] && str_contains((string)$sh['address'], 'آزادی') && $sh['receiver_mobile'] === '09121234567' && $exp === 85000 && (int)val("SELECT COUNT(*) FROM sms_log WHERE tpl_key='ship_track'") === 1 && row("SELECT status FROM shipments WHERE id=?", [$id])['status'] === 'delivered' && (int)$sum['post']['cust_cost'] === 50000 && (int)$sum['snapp']['shop_cost'] === 85000, (string)$sh['address']); };
$T['X-ADDR'] = function () { sms_tpls_on(['addr_link']); $r = addr_link_make(['mobile' => '09125556666', 'name' => 'نازنین', 'sms' => 1, 'postal' => 1]); $L = addr_link_parse($r['token']);
    $tamper = addr_link_parse(str_replace('p.', 'm.', $r['token'])); $old = addr_link_parse(addr_link_token($r['customer_id'], false, time() - 2 * 86400));
    $noPost = err(fn() => address_save($r['customer_id'], ['city' => 'کرج', 'address' => 'بلوار'], 'link', true)); $a1 = address_save($r['customer_id'], ['city' => 'کرج', 'address' => 'بلوار', 'postal_code' => '۳۱۴۵۶۷۸۹۰۱', 'lat' => '35.8', 'lng' => '50.9'], 'link', true);
    $a2 = address_save($r['customer_id'], ['city' => 'کرج', 'address' => 'بلوار', 'postal_code' => '3145678901'], 'link', true); $geoOnly = address_save($r['customer_id'], ['lat' => '35.7', 'lng' => '51.4']);
    $c = row("SELECT * FROM customers WHERE id=?", [$r['customer_id']]);
    return expect($L && $L['postal'] && (int)$L['customer']['id'] === $r['customer_id'] && $tamper === null && !empty($old['expired']) && !$noPost['ok'] && $a1 === $a2 && $geoOnly > $a1 && str_contains((string)$c['address'], 'بلوار') && $c['postal_code'] === '3145678901' && str_contains($r['url'], 'v.php?a=') && (int)val("SELECT COUNT(*) FROM sms_log WHERE tpl_key='addr_link'") === 1, $r['url']); };
$T['X-UCAPS'] = function () { $u = mk_user('kar', 'کارمند', []); user_save(['id' => $u, 'username' => 'kar', 'name' => 'کارمند', 'caps' => ['ship.manage', 'reports.profit', 'settings.edit'], '_caps_form' => 1]);
    $L = user_load($u); $mid = mk_user('mid', 'سرپرست', [], []); q("INSERT INTO user_caps VALUES (?, 'users.edit'), (?, 'ship.manage')", [$mid, $mid]); set_current_user($mid);
    $deny = err(fn() => user_save(['id' => $u, 'username' => 'kar', 'name' => 'کارمند', 'caps' => ['ledger.post'], '_caps_form' => 1]));
    $keep = err(fn() => user_save(['id' => $u, 'username' => 'kar', 'name' => 'کارمند', 'caps' => [], '_caps_form' => 1])); set_current_user(1); $L2 = user_load($u);
    return expect(can('ship.manage', $L) && can('settings.edit', $L) && !can('pos.use', $L) && !$deny['ok'] && $keep['ok'] && !can('ship.manage', $L2) && can('settings.edit', $L2) && can('reports.profit', $L2), implode(',', $L2['caps'])); };

$res = []; $pass = 0; $fail = 0; $skip = 0;
foreach ($T as $ref => $fn) {
    try { reset_db(); [$st, $got] = $fn(); } catch (Throwable $e) { $st = 'fail'; $got = get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(); }
    $res[$ref] = ['status' => $st, 'got' => $got]; ${$st}++;
    printf("%-11s %-4s %s\n", $ref, strtoupper($st), $got);
}
echo "\npass=$pass fail=$fail skip=$skip\n";
$i = array_search('--json', $argv, true); if ($i !== false && !empty($argv[$i + 1])) file_put_contents($argv[$i + 1], json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
exit($fail ? 1 : 0);
