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
    foreach (api_routes() as $k => $r) { if (empty($r[0])) $bad[] = "api:$k"; if ($r[0] === 'public' && !in_array($k, $public, true)) $bad[] = "public:$k"; if ($r[0] !== 'public' && $r[0] !== 'login' && !isset(caps_flat()[$r[0]])) $bad[] = "cap:$k"; }
    foreach (pages_all() as $k => $p) if ($p[1] !== 'login' && !isset(caps_flat()[$p[1]])) $bad[] = "pagecap:$k"; return expect(!$bad, implode(' ', $bad) ?: 'همهٔ صفحه‌ها و APIها cap دارند'); };
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
$T['X-INV-NUM'] = function () { settings_save(['doc_pat_invoice' => 'INV-{Y}{M}-{N}', 'doc_pad' => 3]); // پیش‌شماره برای همه $d = jparse('1405/02/15');
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

$res = []; $pass = 0; $fail = 0; $skip = 0;
foreach ($T as $ref => $fn) {
    try { reset_db(); [$st, $got] = $fn(); } catch (Throwable $e) { $st = 'fail'; $got = get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(); }
    $res[$ref] = ['status' => $st, 'got' => $got]; ${$st}++;
    printf("%-11s %-4s %s\n", $ref, strtoupper($st), $got);
}
echo "\npass=$pass fail=$fail skip=$skip\n";
$i = array_search('--json', $argv, true); if ($i !== false && !empty($argv[$i + 1])) file_put_contents($argv[$i + 1], json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
exit($fail ? 1 : 0);
