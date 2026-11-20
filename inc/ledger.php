<?php
// دفترِ مالیِ مرکزی: کدینگ، سندِ خودکارِ دوطرفه از هر رویداد (هر رویداد دقیقاً یک سند)، بانک/صندوق/چک، دفترِ کل و اشخاص
declare(strict_types=1);

function ledger_chart(): array {
    // [code, name, parent, nature, kind, system_key]
    return [
        ['1', 'دارایی‌ها', null, 'D', 'asset', null], ['11', 'دارایی‌های جاری', '1', 'D', 'asset', null],
        ['1101', 'صندوق', '11', 'D', 'asset', 'cash'], ['1102', 'بانک', '11', 'D', 'asset', 'bank'], ['1103', 'حساب‌های دریافتنی (مشتریان)', '11', 'D', 'asset', 'receivable'],
        ['1104', 'اسنادِ دریافتنی (چک)', '11', 'D', 'asset', 'cheque_in'], ['1105', 'موجودیِ کالا', '11', 'D', 'asset', 'inventory'], ['1106', 'طلب از کانال‌های فروش', '11', 'D', 'asset', 'channel'],
        ['1107', 'حساب و تنخواهِ کارکنان', '11', 'D', 'asset', 'staff'], ['1108', 'مالیاتِ خرید (اعتبار)', '11', 'D', 'asset', 'vat_in'], ['1109', 'کالای تهاتری (انبارِ بلاتکلیف)', '11', 'D', 'asset', 'offset'],
        ['2', 'بدهی‌ها', null, 'C', 'liability', null], ['21', 'بدهی‌های جاری', '2', 'C', 'liability', null],
        ['2101', 'حساب‌های پرداختنی (تأمین‌کنندگان)', '21', 'C', 'liability', 'payable'], ['2102', 'مالیات بر ارزش افزودهٔ فروش', '21', 'C', 'liability', 'vat_out'],
        ['2103', 'حقوقِ پرداختنی', '21', 'C', 'liability', 'salary_payable'], ['2104', 'کیف پولِ مشتریان (پیش‌دریافت)', '21', 'C', 'liability', 'wallet'], ['2105', 'اسنادِ پرداختنی', '21', 'C', 'liability', 'cheque_out'],
        ['2107', 'بیمهٔ پرداختنی', '21', 'C', 'liability', 'ins_payable'], ['2109', 'جابه‌جاییِ فروش (تهاترِ مرجوعی)', '21', 'C', 'liability', 'exchange'], ['2110', 'بدهی به پیک‌ها', '21', 'C', 'liability', 'courier'], ['2108', 'مالیاتِ حقوقِ پرداختنی', '21', 'C', 'liability', 'tax_payable'],
        ['3', 'حقوقِ صاحبانِ سهام', null, 'C', 'equity', null], ['3101', 'سرمایه', '3', 'C', 'equity', 'capital'], ['3102', 'سود و زیانِ انباشته', '3', 'C', 'equity', 'retained'],
        ['4', 'درآمدها', null, 'C', 'income', null], ['4101', 'فروشِ کالا و خدمات', '4', 'C', 'income', 'sales'], ['4102', 'برگشت از فروش', '4', 'D', 'income', 'sales_return'],
        ['4201', 'درآمدِ اضافیِ صندوق', '4', 'C', 'income', 'cash_over'],
        ['5', 'بهای تمام‌شده', null, 'D', 'cogs', null], ['5101', 'بهای تمام‌شدهٔ کالای فروش‌رفته', '5', 'D', 'cogs', 'cogs'],
        ['6', 'هزینه‌ها', null, 'D', 'expense', null], ['6101', 'حقوق و دستمزد', '6', 'D', 'expense', 'salary'], ['6102', 'پورسانت', '6', 'D', 'expense', 'commission'],
        ['6103', 'کارمزدِ کانال‌ها', '6', 'D', 'expense', 'channel_fee'], ['6104', 'ضایعات و کسریِ کالا', '6', 'D', 'expense', 'stock_loss'], ['6105', 'کسریِ صندوق', '6', 'D', 'expense', 'cash_short'],
        ['6106', 'هزینه‌های عمومی', '6', 'D', 'expense', 'general'], ['6107', 'هزینهٔ ارسال و پیک', '6', 'D', 'expense', 'shipping'], ['6108', 'هزینهٔ ضمانت و تعمیر', '6', 'D', 'expense', 'warranty'], ['6109', 'مواد بسته‌بندی', '6', 'D', 'expense', 'packaging'],
    ];
}
function ledger_seed_accounts(): void {
    if ((int)val("SELECT COUNT(*) FROM accounts")) { // پس از به‌روزرسانی: فقط سرفصل‌های سیستمیِ تازه
        foreach (ledger_chart() as [$code, $name, $parent, $nat, $kind, $key]) if ($key && !val("SELECT id FROM accounts WHERE system_key=?", [$key]) && !val("SELECT id FROM accounts WHERE code=?", [$code]))
            ins('accounts', ['code' => $code, 'name' => $name, 'parent_id' => $parent ? (int)val("SELECT id FROM accounts WHERE code=?", [$parent]) ?: null : null, 'level' => strlen($code) == 1 ? 'group' : (strlen($code) == 2 ? 'kol' : 'moein'), 'nature' => $nat, 'kind' => $kind, 'system_key' => $key]);
        return;
    }
    $ids = [];
    foreach (ledger_chart() as [$code, $name, $parent, $nat, $kind, $key])
        $ids[$code] = ins('accounts', ['code' => $code, 'name' => $name, 'parent_id' => $parent ? $ids[$parent] : null, 'level' => strlen($code) == 1 ? 'group' : (strlen($code) == 2 ? 'kol' : 'moein'), 'nature' => $nat, 'kind' => $kind, 'system_key' => $key]);
}
function acc_id(string $key): int { static $c = []; if (isset($c[$key])) return $c[$key]; $id = (int)val("SELECT id FROM accounts WHERE system_key=?", [$key]); if (!$id) throw new RuntimeException("account $key missing"); return $c[$key] = $id; }
function account_save(array $in): int {
    $code = preg_replace('/\D/', '', en_num((string)($in['code'] ?? ''))); $name = trim((string)($in['name'] ?? ''));
    if ($code === '' || $name === '') throw new UserError('کد و نامِ سرفصل لازم است');
    $parent = (int)($in['parent_id'] ?? 0) ?: null;
    if ($parent) { $p = row("SELECT * FROM accounts WHERE id=?", [$parent]); if (!$p || !str_starts_with($code, $p['code'])) throw new UserError('کدِ سرفصل باید با کدِ والد شروع شود'); }
    $id = (int)($in['id'] ?? 0);
    if (val("SELECT id FROM accounts WHERE code=? AND id<>?", [$code, $id])) throw new UserError('این کد قبلاً ثبت شده است');
    $p = $parent ? row("SELECT * FROM accounts WHERE id=?", [$parent]) : null;
    $d = ['code' => $code, 'name' => $name, 'parent_id' => $parent, 'level' => $in['level'] ?? 'tafsili', 'nature' => $p['nature'] ?? (($in['nature'] ?? 'D') === 'C' ? 'C' : 'D'), 'kind' => $p['kind'] ?? ($in['kind'] ?? 'asset')];
    if ($id) { if (val("SELECT system_key FROM accounts WHERE id=?", [$id])) unset($d['code']); upd('accounts', $d, 'id=?', [$id]); return $id; }
    return ins('accounts', $d);
}
/** حسابِ مقصدِ هر روشِ پرداخت */
function pay_account(string $method, ?int $cashboxId = null, int $bankId = 0): array {
    $cb = $cashboxId ? row("SELECT * FROM cashboxes WHERE id=?", [$cashboxId]) : null;
    switch ($method) {
        case 'cash': return [acc_id('cash'), 'cashbox', $cashboxId];
        case 'card': return [acc_id('bank'), 'bank', $bankId ?: (int)($cb['bank_account_id'] ?? 0) ?: null];
        case 'transfer': case 'bank': return [acc_id('bank'), 'bank', $bankId ?: (int)($cb['transfer_account_id'] ?? $cb['bank_account_id'] ?? 0) ?: null];
        case 'credit': return [acc_id('receivable'), null, null];
        case 'wallet': return [acc_id('wallet'), null, null];
        case 'cheque': return [acc_id('cheque_in'), null, null];
        case 'channel': return [acc_id('channel'), null, null];
        case 'offset': return [acc_id('offset'), null, null];
        case 'exchange': return [acc_id('exchange'), null, null];
    }
    throw new UserError('روشِ پرداختِ بی‌حساب: ' . $method);
}
function L(int $acc, int $dr, int $cr, ?string $pt = null, $pid = null, string $descr = ''): array { return ['account_id' => $acc, 'debit' => $dr, 'credit' => $cr, 'party_type' => $pt, 'party_id' => $pid ? (int)$pid : null, 'descr' => $descr]; }

/** نگاشتِ رویداد ← آرتیکل‌ها */
function ledger_rules(string $ev, $id, array $x): array {
    $lines = []; $d = $x['d'] ?? today(); $descr = '';
    switch ($ev) {
        case 'sale':
            $s = row("SELECT * FROM sales WHERE id=?", [$id]); $d = $s['d']; $descr = 'فروشِ ' . $s['number'];
            foreach (rows("SELECT method, SUM(amount) a, MAX(cashbox_id) cb FROM receipts WHERE sale_id=? AND return_id IS NULL GROUP BY method", [$id]) as $r) {
                [$acc, $pt, $pid] = pay_account($r['method'], $r['cb'] ? (int)$r['cb'] : null);
                if ($r['method'] === 'credit' || $r['method'] === 'wallet') { $pt = 'customer'; $pid = $s['customer_id']; }
                if ($r['method'] === 'channel') { $pt = 'channel'; $pid = $s['channel_id']; }
                $lines[] = L($acc, (int)$r['a'], 0, $pt, $pid);
            }
            $lines[] = L(acc_id('sales'), 0, (int)$s['total'] - (int)$s['tax'], $s['channel_id'] ? 'channel' : null, $s['channel_id']);
            if ((int)$s['tax']) $lines[] = L(acc_id('vat_out'), 0, (int)$s['tax']);
            break;
        case 'sale_cogs':
            $s = row("SELECT * FROM sales WHERE id=?", [$id]); $d = $s['d']; $descr = 'بهای تمام‌شدهٔ ' . $s['number'];
            if ((int)$s['cost'] > 0) { $lines[] = L(acc_id('cogs'), (int)$s['cost'], 0); $lines[] = L(acc_id('inventory'), 0, (int)$s['cost']); }
            break;
        case 'return':
            $r = row("SELECT r.*, s.number, s.customer_id, s.channel_id FROM sale_returns r JOIN sales s ON s.id=r.sale_id WHERE r.id=?", [$id]); $d = $r['d']; $descr = 'برگشت از فروشِ ' . $r['number'];
            $lines[] = L(acc_id('sales_return'), (int)$r['amount'], 0); if ((int)$r['tax']) $lines[] = L(acc_id('vat_out'), (int)$r['tax'], 0);
            $refund = (int)$r['amount'] + (int)$r['tax'];
            if ($r['channel_id'] && $r['refund_method'] === 'cash' && !$r['shift_id']) { $lines[] = L(acc_id('channel'), 0, $refund, 'channel', $r['channel_id']); }
            else { [$acc, $pt, $pid] = pay_account($r['refund_method'], (int)val("SELECT cashbox_id FROM shifts WHERE id=?", [$r['shift_id']]) ?: null); if (in_array($r['refund_method'], ['credit', 'wallet'], true)) { $pt = 'customer'; $pid = $r['customer_id']; } $lines[] = L($acc, 0, $refund, $pt, $pid); }
            if ((int)$r['cost']) { $lines[] = L(acc_id('inventory'), (int)$r['cost'], 0); $lines[] = L(acc_id('cogs'), 0, (int)$r['cost']); }
            break;
        case 'purchase':
            $descr = 'فاکتورِ خرید ' . $id;
            $lines[] = L(acc_id('inventory'), (int)$x['amount'], 0); if (!empty($x['tax'])) $lines[] = L(acc_id('vat_in'), (int)$x['tax'], 0);
            $lines[] = L(acc_id('payable'), 0, (int)$x['amount'] + (int)($x['tax'] ?? 0), 'supplier', $x['supplier_id']);
            break;
        case 'supplier_pay':
            $descr = 'پرداخت به تأمین‌کننده';
            $lines[] = L(acc_id('payable'), (int)$x['amount'], 0, 'supplier', $x['supplier_id']);
            $lines[] = $x['method'] === 'cheque' ? L(acc_id('cheque_out'), 0, (int)$x['amount'], 'supplier', $x['supplier_id']) : ($x['method'] === 'cash' ? L(acc_id('cash'), 0, (int)$x['amount']) : L(acc_id('bank'), 0, (int)$x['amount'], 'bank', $x['bank_account_id'] ?: null));
            break;
        case 'stock_loss': $descr = 'ضرر/کسریِ کالا'; $lines[] = L(acc_id('stock_loss'), (int)$x['amount'], 0); $lines[] = L(acc_id('inventory'), 0, (int)$x['amount']); break;
        case 'stock_return': $descr = 'عودت به تأمین‌کننده'; $lines[] = L(acc_id('payable'), (int)$x['amount'], 0, 'supplier', $x['supplier_id']); $lines[] = L(acc_id('inventory'), 0, (int)$x['amount']); break;
        case 'buyout': $descr = 'خرید از انبارِ امانی'; $lines[] = L(acc_id('inventory'), (int)$x['amount'], 0); $lines[] = L(acc_id('payable'), 0, (int)$x['amount'], 'supplier', $x['supplier_id']); break;
        case 'shift_close':
            $descr = 'مغایرتِ شیفتِ ' . $id; $df = (int)$x['diff'];
            if ($df < 0) { $lines[] = setting('shortage_to_staff', '1') === '1' ? L(acc_id('staff'), -$df, 0, 'user', $x['user_id']) : L(acc_id('cash_short'), -$df, 0); $lines[] = L(acc_id('cash'), 0, -$df, 'cashbox', $x['cashbox_id']); }
            elseif ($df > 0) { $lines[] = L(acc_id('cash'), $df, 0, 'cashbox', $x['cashbox_id']); $lines[] = L(acc_id('cash_over'), 0, $df); }
            break;
        case 'customer_pay': case 'wallet_topup':
            $descr = $ev === 'wallet_topup' ? 'شارژِ کیف پول' : 'دریافت از مشتری';
            [$acc, $pt, $pid] = pay_account($x['method'], $x['cashbox_id'] ?? null, (int)($x['bank_account_id'] ?? 0));
            $lines[] = L($acc, (int)$x['amount'], 0, $pt, $pid);
            $lines[] = L(acc_id($ev === 'wallet_topup' ? 'wallet' : 'receivable'), 0, (int)$x['amount'], 'customer', $x['customer_id']);
            break;
        case 'channel_fee': $descr = 'کارمزدِ کانال'; $lines[] = L(acc_id('channel_fee'), (int)$x['amount'], 0); $lines[] = L(acc_id('channel'), 0, (int)$x['amount'], 'channel', $x['channel_id']); break;
        case 'channel_settle': $descr = 'تسویهٔ کانال'; $lines[] = L(acc_id('bank'), (int)$x['amount'], 0, 'bank', $x['bank_account_id'] ?: null); $lines[] = L(acc_id('channel'), 0, (int)$x['amount'], 'channel', $x['channel_id']); break;
        case 'cash_deposit': $descr = 'واریزِ نقدِ صندوق به بانک'; $lines[] = L(acc_id('bank'), (int)$x['amount'], 0, 'bank', $x['bank_account_id'] ?: null); $lines[] = L(acc_id('cash'), 0, (int)$x['amount']); break;
        case 'payroll':
            $p = row("SELECT * FROM payslips WHERE id=?", [$id]); $descr = 'سندِ حقوقِ ' . fa_num(str_replace('-', '/', $p['ym'])); [$a, $b] = jmonth_range($p['ym']); $d = $b;
            $comm = 0; $ins = 0; $tax = 0; $debt = 0; foreach (json_decode($p['items'], true) ?: [] as $i) { if (!empty($i['commission'])) $comm += (int)$i['a']; $k = $i['acc'] ?? ''; if ($k === 'ins') $ins += (int)$i['a']; if ($k === 'tax') $tax += (int)$i['a']; if ($k === 'debt') $debt += (int)$i['a']; }
            $lines[] = L(acc_id('salary'), (int)$p['gross'] - $comm, 0, 'employee', $p['employee_id']); if ($comm) $lines[] = L(acc_id('commission'), $comm, 0, 'employee', $p['employee_id']);
            $lines[] = L(acc_id('salary_payable'), 0, (int)$p['net'], 'employee', $p['employee_id']); if ($ins) $lines[] = L(acc_id('ins_payable'), 0, $ins); if ($tax) $lines[] = L(acc_id('tax_payable'), 0, $tax);
            if ($debt) $lines[] = L(acc_id('staff'), 0, $debt, 'user', (int)val("SELECT user_id FROM employees WHERE id=?", [$p['employee_id']]));
            break;
        case 'payroll_pay': $descr = 'پرداختِ حقوق'; $lines[] = L(acc_id('salary_payable'), (int)$x['amount'], 0, 'employee', $x['employee_id']); $lines[] = L(acc_id('bank'), 0, (int)$x['amount'], 'bank', $x['bank_account_id'] ?: null); break;
        case 'cheque_clear':
            $c = row("SELECT * FROM cheques WHERE id=?", [$id]); $descr = 'وصولِ چکِ ' . $c['number'];
            if ($c['direction'] === 'in') { $lines[] = L(acc_id('bank'), (int)$c['amount'], 0, 'bank', $c['bank_account_id']); $lines[] = L(acc_id('cheque_in'), 0, (int)$c['amount'], $c['party_type'], $c['party_id']); }
            else { $lines[] = L(acc_id('cheque_out'), (int)$c['amount'], 0, $c['party_type'], $c['party_id']); $lines[] = L(acc_id('bank'), 0, (int)$c['amount'], 'bank', $c['bank_account_id']); }
            break;
        case 'cheque_recv': case 'cheque_back': // چکِ مشتری: با ثبت از طلب کم و به اسنادِ دریافتنی می‌رود؛ برگشت/عودت برعکس
            $c = row("SELECT * FROM cheques WHERE id=?", [$id]); $a = (int)$c['amount'];
            $descr = ($ev === 'cheque_recv' ? 'دریافتِ چکِ ' : 'برگشت/عودتِ چکِ ') . $c['number'] . ' از ' . ($c['party_name'] ?: '—');
            if ($ev === 'cheque_recv') { $lines[] = L(acc_id('cheque_in'), $a, 0, 'customer', $c['party_id']); $lines[] = L(acc_id('receivable'), 0, $a, 'customer', $c['party_id']); }
            else { $lines[] = L(acc_id('receivable'), $a, 0, 'customer', $c['party_id']); $lines[] = L(acc_id('cheque_in'), 0, $a, 'customer', $c['party_id']); }
            break;
        case 'ship_cost': $s = row("SELECT * FROM shipments WHERE id=?", [$id]); $descr = 'هزینهٔ ارسالِ ' . ($s['tracking'] ?: '#' . $id); $lines[] = L(acc_id('shipping'), (int)$s['cost'], 0);
            $lines[] = $s['paid_from'] === 'courier' ? L(acc_id('courier'), 0, (int)$s['cost'], 'courier', $s['courier_id']) : ($s['paid_from'] === 'bank' ? L(acc_id('bank'), 0, (int)$s['cost'], 'bank', (int)($x['bank_account_id'] ?? 0) ?: null) : L(acc_id('cash'), 0, (int)$s['cost'], 'cashbox', (int)($x['cashbox_id'] ?? 0) ?: null)); break;
        case 'courier_settle': $c = row("SELECT * FROM courier_settles WHERE id=?", [$id]); $descr = 'تسویه با پیک'; $lines[] = L(acc_id('courier'), (int)$c['amount'], 0, 'courier', $c['courier_id']);
            $lines[] = $c['method'] === 'bank' ? L(acc_id('bank'), 0, (int)$c['amount'], 'bank', $c['bank_account_id']) : L(acc_id('cash'), 0, (int)$c['amount'], 'cashbox', (int)($x['cashbox_id'] ?? 0) ?: null); break;
        case 'packaging': $pk = row("SELECT * FROM shipment_packs WHERE id=?", [$id]); $descr = 'مواد بسته‌بندیِ ارسالِ ' . $pk['shipment_id']; $lines[] = L(acc_id('packaging'), (int)$pk['cost'], 0); $lines[] = L(acc_id('inventory'), 0, (int)$pk['cost']); break;
        case 'warranty_cost': $c = row("SELECT c.*, w.code, w.product_title FROM warranty_claims c JOIN warranty_items w ON w.id=c.warranty_id WHERE c.id=?", [$id]); $descr = 'هزینهٔ ضمانتِ ' . $c['code'] . ' — ' . $c['product_title'];
            $lines[] = L(acc_id('warranty'), (int)$c['cost'], 0); $lines[] = ($x['method'] ?? 'cash') === 'bank' ? L(acc_id('bank'), 0, (int)$c['cost'], 'bank', (int)($x['bank_account_id'] ?? 0) ?: null) : L(acc_id('cash'), 0, (int)$c['cost']); break;
        case 'offset_stock': case 'offset_scrap': $o = row("SELECT * FROM sale_offsets WHERE id=?", [$id]); $descr = ($ev === 'offset_stock' ? 'ورودِ کالای تهاتری به انبار: ' : 'اسقاطِ کالای تهاتری: ') . $o['title'];
            $lines[] = L(acc_id($ev === 'offset_stock' ? 'inventory' : 'stock_loss'), (int)$o['value'], 0); $lines[] = L(acc_id('offset'), 0, (int)$o['value']); break;
        case 'exchange_payout': $descr = 'پرداختِ مابه‌التفاوتِ اصلاحِ فروش'; [$acc, $pt, $pid] = pay_account($x['method'], $x['cashbox_id'] ?? null, (int)($x['bank_account_id'] ?? 0)); if (in_array($x['method'], ['credit', 'wallet'], true)) { $pt = 'customer'; $pid = $x['customer_id'] ?? null; }
            $lines[] = L(acc_id('exchange'), (int)$x['amount'], 0); $lines[] = L($acc, 0, (int)$x['amount'], $pt, $pid); break;
        case 'expense': $descr = (string)($x['descr'] ?? 'هزینه'); $lines[] = L((int)($x['account_id'] ?? acc_id('general')), (int)$x['amount'], 0); $lines[] = $x['method'] === 'cash' ? L(acc_id('cash'), 0, (int)$x['amount']) : L(acc_id('bank'), 0, (int)$x['amount'], 'bank', $x['bank_account_id'] ?: null); break;
        case 'manual': $descr = (string)($x['descr'] ?? 'سندِ دستی'); $lines = $x['lines']; break;
        default: throw new UserError('رویدادِ مالیِ ناشناخته: ' . $ev);
    }
    return ['lines' => array_values(array_filter($lines, fn($l) => $l['debit'] || $l['credit'])), 'd' => $d, 'descr' => $descr];
}
/** ثبتِ سند: تراز، یکتا (همان رویداد = همان سند)، دورهٔ باز */
function ledger_post(string $ev, $id, array $x = []): int {
    $src = $ev === 'sale_cogs' ? 'sale_cogs' : $ev;
    if ($ex = val("SELECT id FROM journals WHERE source_type=? AND source_id=?", [$src, (string)$id])) return (int)$ex;
    $r = ledger_rules($ev, $id, $x);
    $lines = apply_filters('ledger_post', $r['lines'], $ev);
    if (!$lines) return 0;
    return journal_insert($lines, $r['d'], $r['descr'], $src, (string)$id);
}
function ledger_post_cogs(int $saleId): int { return ledger_post('sale_cogs', $saleId); }
function journal_insert(array $lines, string $d, string $descr, string $src, string $srcId): int {
    $dr = 0; $cr = 0;
    foreach ($lines as $l) { if ($l['debit'] < 0 || $l['credit'] < 0) throw new UserError('مبلغِ منفی در سند'); $dr += $l['debit']; $cr += $l['credit']; }
    if ($dr !== $cr || $dr <= 0) throw new UserError('سند تراز نیست');
    fiscal_assert_open($d);
    return tx(function () use ($lines, $d, $descr, $src, $srcId, $dr) {
        $n = doc_seq_next('journal-' . substr(jdate($d, false, false), 0, 4));
        $jid = ins('journals', ['number' => $n, 'd' => $d, 'descr' => mb_cut($descr, 400), 'source_type' => $src, 'source_id' => $srcId, 'total' => $dr, 'user_id' => current_user()['id'] ?? null, 'created_at' => now()]);
        foreach ($lines as $l) ins('journal_lines', ['journal_id' => $jid] + $l);
        return $jid;
    });
}
/** سندِ دستی (فقط حسابدار) */
function journal_manual(array $in): int {
    $lines = [];
    foreach ((array)($in['lines'] ?? []) as $l) { $a = (int)($l['account_id'] ?? 0); $dr = to_int($l['debit'] ?? 0); $cr = to_int($l['credit'] ?? 0); if (!$a || (!$dr && !$cr)) continue; $lines[] = L($a, $dr, $cr, $l['party_type'] ?? null, $l['party_id'] ?? null, (string)($l['descr'] ?? '')); }
    $d = jparse($in['d'] ?? '') ?: today();
    $id = journal_insert($lines, $d, trim((string)($in['descr'] ?? '')) ?: 'سندِ دستی', 'manual', uniqid('m', true));
    audit('journals', $id, 'create', null, ['lines' => count($lines)], 'سندِ دستی');
    return $id;
}
function ledger_balance(int $accId, ?string $from = null, ?string $to = null): int {
    $a = row("SELECT code, nature FROM accounts WHERE id=?", [$accId]);
    $r = row("SELECT COALESCE(SUM(l.debit),0) dr, COALESCE(SUM(l.credit),0) cr FROM journal_lines l JOIN journals j ON j.id=l.journal_id JOIN accounts a ON a.id=l.account_id WHERE a.code LIKE ?" . ($from ? " AND j.d>=" . db()->quote($from) : '') . ($to ? " AND j.d<=" . db()->quote($to) : ''), [$a['code'] . '%']);
    return $a['nature'] === 'D' ? (int)$r['dr'] - (int)$r['cr'] : (int)$r['cr'] - (int)$r['dr'];
}
/** ترازِ آزمایشی: جمعِ بدهکار = جمعِ بستانکار */
function trial_balance(?string $from = null, ?string $to = null): array {
    $w = ($from ? " AND j.d>=" . db()->quote($from) : '') . ($to ? " AND j.d<=" . db()->quote($to) : '');
    $rs = rows("SELECT a.id, a.code, a.name, a.nature, a.kind, COALESCE(SUM(x.debit),0) dr, COALESCE(SUM(x.credit),0) cr FROM accounts a
        LEFT JOIN (SELECT l.account_id, l.debit, l.credit FROM journal_lines l JOIN journals j ON j.id=l.journal_id WHERE 1=1 $w) x ON x.account_id=a.id
        GROUP BY a.id, a.code, a.name, a.nature, a.kind HAVING dr>0 OR cr>0 ORDER BY a.code");
    $tdr = 0; $tcr = 0; foreach ($rs as &$r) { $r['dr'] = (int)$r['dr']; $r['cr'] = (int)$r['cr']; $r['bal'] = $r['nature'] === 'D' ? $r['dr'] - $r['cr'] : $r['cr'] - $r['dr']; $tdr += $r['dr']; $tcr += $r['cr']; }
    return ['rows' => $rs, 'dr' => $tdr, 'cr' => $tcr, 'ok' => $tdr === $tcr];
}
function party_types(): array { return ['customer' => 'مشتری', 'supplier' => 'تأمین‌کننده', 'employee' => 'کارمند', 'user' => 'کاربر/کارمند', 'channel' => 'کانال', 'bank' => 'بانک', 'cashbox' => 'صندوق']; }
/** صورت‌حسابِ هر شخص با ماندهٔ جاری */
function party_statement(string $type, int $id, ?string $from = null, ?string $to = null): array {
    $rs = rows("SELECT j.d, j.number, j.descr, a.name acc, l.debit, l.credit FROM journal_lines l JOIN journals j ON j.id=l.journal_id JOIN accounts a ON a.id=l.account_id WHERE l.party_type=? AND l.party_id=?"
        . ($from ? " AND j.d>=" . db()->quote($from) : '') . ($to ? " AND j.d<=" . db()->quote($to) : '') . " ORDER BY j.d, j.id", [$type, $id]);
    $bal = 0; foreach ($rs as &$r) { $bal += (int)$r['debit'] - (int)$r['credit']; $r['bal'] = $bal; }
    return ['rows' => $rs, 'balance' => $bal];
}
function general_ledger(int $accId, ?string $from, ?string $to): array {
    $a = row("SELECT * FROM accounts WHERE id=?", [$accId]);
    $open = $from ? ledger_balance($accId, null, date('Y-m-d', strtotime($from . ' -1 day'))) : 0;
    $rs = rows("SELECT j.d, j.number, j.descr, l.debit, l.credit, l.party_type, l.party_id FROM journal_lines l JOIN journals j ON j.id=l.journal_id JOIN accounts a ON a.id=l.account_id WHERE a.code LIKE ?"
        . ($from ? " AND j.d>=" . db()->quote($from) : '') . ($to ? " AND j.d<=" . db()->quote($to) : '') . " ORDER BY j.d, j.id LIMIT 3000", [$a['code'] . '%']);
    $bal = $open; foreach ($rs as &$r) { $bal += $a['nature'] === 'D' ? $r['debit'] - $r['credit'] : $r['credit'] - $r['debit']; $r['bal'] = $bal; }
    return ['account' => $a, 'opening' => $open, 'rows' => $rs, 'closing' => $bal];
}
// ── بانک، صندوق و چک
function bank_iban_valid(string $iban): bool { return iban_ok($iban); }
function bank_account_save(array $in): int {
    $iban = strtoupper(preg_replace('/\s/', '', en_num((string)($in['iban'] ?? ''))));
    if ($iban !== '' && !bank_iban_valid($iban)) throw new UserError('شبا معتبر نیست');
    $card = preg_replace('/\D/', '', en_num((string)($in['card_no'] ?? ''))); if ($card !== '' && strlen($card) !== 16) throw new UserError('شمارهٔ کارت ۱۶ رقم است');
    $d = ['title' => trim((string)($in['title'] ?? '')) ?: 'حسابِ بانکی', 'bank' => trim((string)($in['bank'] ?? '')) ?: null, 'iban' => $iban ?: null, 'card_no' => $card ?: null,
        'account_no' => trim(en_num((string)($in['account_no'] ?? ''))) ?: null, 'owner' => trim((string)($in['owner'] ?? '')) ?: null, 'active' => empty($in['inactive']) ? 1 : 0];
    $id = (int)($in['id'] ?? 0);
    if ($id) { upd('bank_accounts', $d, 'id=?', [$id]); audit('bank_accounts', $id, 'update', null, $d, 'حسابِ بانکی'); return $id; }
    $id = ins('bank_accounts', $d); audit('bank_accounts', $id, 'create', null, $d, 'حسابِ بانکی'); return $id;
}
function bank_balance(int $bankId): int { return (int)val("SELECT COALESCE(SUM(debit-credit),0) FROM journal_lines WHERE account_id=? AND party_type='bank' AND party_id=?", [acc_id('bank'), $bankId]); }
function cashbox_balance(int $cbId): int { return (int)val("SELECT COALESCE(SUM(debit-credit),0) FROM journal_lines WHERE account_id=? AND party_type='cashbox' AND party_id=?", [acc_id('cash'), $cbId]); }
function cheque_save(array $in, bool $post = true): int {
    $amount = to_int($in['amount'] ?? 0); $due = jparse($in['due'] ?? '');
    if ($amount <= 0 || !$due || trim((string)($in['number'] ?? '')) === '') throw new UserError('شماره، مبلغ و سررسیدِ چک لازم است');
    $id = ins('cheques', ['direction' => ($in['direction'] ?? 'in') === 'out' ? 'out' : 'in', 'number' => trim(en_num((string)$in['number'])), 'bank' => $in['bank'] ?? null, 'amount' => $amount, 'due_date' => $due,
        'party_type' => $in['party_type'] ?? null, 'party_id' => (int)($in['party_id'] ?? 0) ?: null, 'party_name' => $in['party_name'] ?? null, 'bank_account_id' => (int)($in['bank_account_id'] ?? 0) ?: null, 'note' => $in['note'] ?? null, 'created_at' => now()]);
    audit('cheques', $id, 'create', null, ['amount' => $amount], 'چک');
    return $id;
}
function cheque_status(int $id, string $status, int $bankId = 0): void {
    $c = row("SELECT * FROM cheques WHERE id=?", [$id]); if (!$c) throw new UserError('چک یافت نشد');
    if (!in_array($status, ['cleared', 'bounced', 'pending', 'returned'], true)) throw new UserError('وضعیتِ نامعتبر');
    if ($c['status'] === 'cleared') throw new UserError('چکِ وصول‌شده تغییر نمی‌کند');
    if (in_array($status, ['bounced', 'returned'], true)) { cheque_reverse($id, $status); return; }
    tx(function () use ($c, $id, $status, $bankId) {
        if ($bankId) upd('cheques', ['bank_account_id' => $bankId], 'id=?', [$id]);
        upd('cheques', ['status' => $status, 'status_at' => now()], 'id=?', [$id]);
        if ($status === 'cleared') ledger_post('cheque_clear', $id);
        audit('cheques', $id, 'status', ['status' => $c['status']], ['status' => $status], 'وضعیتِ چک');
    });
}
function cheques_due(int $days = 7): array { return rows("SELECT * FROM cheques WHERE status='pending' AND due_date<=? ORDER BY due_date", [date('Y-m-d', strtotime("+$days day"))]); }
/** تطبیقِ بانک: ماندهٔ صورت‌حساب در برابرِ ماندهٔ دفتر */
function bank_reconcile(int $bankId, string $d, int $statement, string $note = ''): array {
    $book = (int)val("SELECT COALESCE(SUM(l.debit-l.credit),0) FROM journal_lines l JOIN journals j ON j.id=l.journal_id WHERE l.account_id=? AND l.party_type='bank' AND l.party_id=? AND j.d<=?", [acc_id('bank'), $bankId, $d]);
    $id = ins('bank_reconcile', ['bank_account_id' => $bankId, 'd' => $d, 'statement_balance' => $statement, 'book_balance' => $book, 'diff' => $statement - $book, 'note' => $note, 'user_id' => current_user()['id'] ?? null, 'created_at' => now()]);
    return ['id' => $id, 'book' => $book, 'diff' => $statement - $book];
}
function expense_record(array $in): int {
    $amount = to_int($in['amount'] ?? 0); if ($amount <= 0) throw new UserError('مبلغ لازم است');
    $d = jparse($in['d'] ?? '') ?: today();
    return ledger_post('expense', uniqid('e'), ['amount' => $amount, 'method' => ($in['method'] ?? 'bank') === 'cash' ? 'cash' : 'bank', 'bank_account_id' => (int)($in['bank_account_id'] ?? 0), 'account_id' => (int)($in['account_id'] ?? 0) ?: acc_id('general'), 'descr' => trim((string)($in['descr'] ?? '')) ?: 'هزینه', 'd' => $d]);
}
