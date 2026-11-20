<?php
// مشتریان: پروندهٔ یکتا (موبایلِ یکسان‌شده)، سازمان و افراد، گرید، نسیه و کیف پول (مانده = جمعِ ردیف‌ها)، تماس و پیگیری
declare(strict_types=1);

function customer_get(int $id): ?array { return row("SELECT * FROM customers WHERE id=?", [$id]); }
function customer_find_or_create(string $mobile, string $name = '', string $address = ''): int {
    $m = norm_mobile($mobile); if (!mobile_ok($m)) throw new UserError('شمارهٔ موبایل معتبر نیست');
    $id = val("SELECT id FROM customers WHERE mobile=?", [$m]); if ($id) return (int)$id;
    $pid = val("SELECT customer_id FROM customer_people WHERE mobile=? LIMIT 1", [$m]); if ($pid) return (int)$pid;
    $id = ins('customers', ['name' => trim($name) ?: 'مشتریِ ' . $m, 'mobile' => $m, 'address' => trim($address) ?: null, 'grade_id' => (int)val("SELECT id FROM grades WHERE kind='customer' ORDER BY id LIMIT 1") ?: null, 'created_at' => now()]);
    audit('customers', $id, 'create', null, ['mobile' => $m], 'مشتریِ تازه');
    return $id;
}
function customer_save(array $in): int {
    $id = (int)($in['id'] ?? 0); $name = trim((string)($in['name'] ?? '')); if ($name === '') throw new UserError('نامِ مشتری لازم است');
    $kind = ($in['kind'] ?? 'person') === 'org' ? 'org' : 'person';
    $m = norm_mobile($in['mobile'] ?? '');
    if ($m !== '' && !mobile_ok($m)) throw new UserError('شمارهٔ موبایل معتبر نیست');
    if ($m && ($dup = val("SELECT id FROM customers WHERE mobile=? AND id<>?", [$m, $id]))) throw new UserError('این موبایل برای مشتریِ دیگری ثبت شده است (پروندهٔ ' . fa_num($dup) . ')');
    $nid = preg_replace('/\D/', '', en_num((string)($in['national_id'] ?? '')));
    if ($nid !== '' && !($kind === 'org' ? legal_id_ok($nid) : national_code_ok($nid))) throw new UserError($kind === 'org' ? 'شناسهٔ ملی معتبر نیست' : 'کدِ ملی معتبر نیست');
    $pc = preg_replace('/\D/', '', en_num((string)($in['postal_code'] ?? ''))); if ($pc !== '' && strlen($pc) !== 10) throw new UserError('کدِ پستی باید ۱۰ رقم باشد');
    $d = ['kind' => $kind, 'name' => $name, 'mobile' => $m ?: null, 'national_id' => $nid ?: null, 'economic_code' => preg_replace('/\D/', '', en_num((string)($in['economic_code'] ?? ''))) ?: null,
        'postal_code' => $pc ?: null, 'address' => trim((string)($in['address'] ?? '')) ?: null, 'phone' => trim(en_num((string)($in['phone'] ?? ''))) ?: null, 'grade_id' => (int)($in['grade_id'] ?? 0) ?: null, 'notes' => $in['notes'] ?? null];
    if (can('customers.credit')) { $d['credit_limit'] = to_int($in['credit_limit'] ?? 0); $d['is_partner'] = empty($in['is_partner']) ? 0 : 1; $d['partner_grade_id'] = (int)($in['partner_grade_id'] ?? 0) ?: null; }
    if ($id) { $o = customer_get($id); upd('customers', $d, 'id=?', [$id]); [$b, $a] = audit_diff($o, $d); if ($a) audit('customers', $id, 'update', $b, $a, 'مشتری'); return $id; }
    $d['created_at'] = now(); $id = ins('customers', $d); audit('customers', $id, 'create', null, ['name' => $name], 'مشتریِ تازه'); return $id;
}
function customer_person_add(int $cid, string $name, string $role = '', string $mobile = ''): int {
    $m = norm_mobile($mobile); if ($m !== '' && !mobile_ok($m)) throw new UserError('موبایلِ فرد معتبر نیست');
    return ins('customer_people', ['customer_id' => $cid, 'name' => trim($name), 'role' => trim($role) ?: null, 'mobile' => $m ?: null]);
}
/** یک آدم، یک پرونده: همه‌چیزِ پروندهٔ دوم به اولی منتقل می‌شود */
function customers_merge(int $keep, int $drop): void {
    if ($keep === $drop) throw new UserError('دو پروندهٔ متفاوت انتخاب کنید');
    tx(function () use ($keep, $drop) {
        foreach (['sales' => 'customer_id', 'docs' => 'customer_id', 'customer_ledger' => 'customer_id', 'wallet_ledger' => 'customer_id', 'calls' => 'customer_id', 'customer_people' => 'customer_id', 'receipts' => 'customer_id', 'carts' => 'customer_id'] as $t => $c)
            q("UPDATE `$t` SET `$c`=? WHERE `$c`=?", [$keep, $drop]);
        $d = customer_get($drop);
        if ($d['mobile'] && !val("SELECT mobile FROM customers WHERE id=?", [$keep])) { upd('customers', ['mobile' => null], 'id=?', [$drop]); upd('customers', ['mobile' => $d['mobile']], 'id=?', [$keep]); }
        elseif ($d['mobile']) { upd('customers', ['mobile' => null], 'id=?', [$drop]); customer_person_add($keep, $d['name'], 'ادغام‌شده', $d['mobile']); }
        upd('customers', ['merged_into' => $keep, 'name' => $d['name'] . ' (ادغام‌شده)'], 'id=?', [$drop]);
        audit('customers', $drop, 'merge', null, ['into' => $keep], 'ادغامِ مشتری');
    });
}
function customer_search(string $q, int $limit = 20): array {
    $q = trim(en_num($q)); if ($q === '') return [];
    $m = norm_mobile($q);
    return rows("SELECT c.id, c.name, c.mobile, c.kind, c.is_partner FROM customers c WHERE c.merged_into IS NULL AND (c.name LIKE ? OR c.mobile=? OR c.national_id=? OR c.id IN (SELECT customer_id FROM customer_people WHERE name LIKE ? OR mobile=?)) ORDER BY c.name LIMIT " . (int)$limit,
        ['%' . $q . '%', $m, $q, '%' . $q . '%', $m]);
}
function customer_history(int $id, int $limit = 50): array { return rows("SELECT s.id, s.number, s.d, s.total, s.channel_id, u.name seller FROM sales s LEFT JOIN users u ON u.id=s.seller_id WHERE s.customer_id=? ORDER BY s.id DESC LIMIT " . (int)$limit, [$id]); }
function customer_ledger_add(int $cid, int $amount, string $kind, ?string $rt, ?int $rid, string $note = '', ?string $due = null): int {
    return ins('customer_ledger', ['customer_id' => $cid, 'amount' => $amount, 'kind' => $kind, 'ref_type' => $rt, 'ref_id' => $rid, 'note' => mb_cut($note, 300), 'due_date' => $due, 'at' => now()]);
}
/** ماندهٔ نسیه (مثبت = مشتری بدهکار) — همیشه از جمعِ ردیف‌ها */
function customer_balance(int $cid): int { return (int)val("SELECT COALESCE(SUM(amount),0) FROM customer_ledger WHERE customer_id=?", [$cid]); }
function wallet_add(int $cid, int $amount, string $kind, ?string $rt, ?int $rid, string $note = ''): int { return ins('wallet_ledger', ['customer_id' => $cid, 'amount' => $amount, 'kind' => $kind, 'ref_type' => $rt, 'ref_id' => $rid, 'note' => mb_cut($note, 300), 'at' => now()]); }
function wallet_balance(int $cid): int { return (int)val("SELECT COALESCE(SUM(amount),0) FROM wallet_ledger WHERE customer_id=?", [$cid]); }
/** دریافت از مشتری (بدهیِ نسیه) یا شارژِ کیف پول */
function customer_pay(int $cid, int $amount, string $method, string $target = 'credit', int $bankId = 0, string $note = ''): int {
    if ($amount <= 0) throw new UserError('مبلغ لازم است');
    if ($method === 'cheque') throw new UserError('چک را از فرمِ «دریافتِ چک» ثبت کنید تا سررسید و وصولش پیگیری شود');
    if (!in_array($method, ['cash', 'card', 'transfer'], true)) throw new UserError('روشِ دریافت نامعتبر');
    $u = require_login(); $shift = shift_of_user((int)$u['id']);
    if ($method === 'cash' && !$shift) throw new UserError('برای دریافتِ نقد شیفتِ باز لازم است');
    if ($target === 'credit' && $amount > customer_balance($cid)) throw new UserError('مبلغ بیش از بدهیِ مشتری (' . fa_money(customer_balance($cid)) . ') است');
    return tx(function () use ($cid, $amount, $method, $target, $bankId, $note, $shift) {
        $rid = ins('receipts', ['shift_id' => $shift['id'] ?? null, 'cashbox_id' => $shift['cashbox_id'] ?? null, 'method' => $method, 'amount' => $amount, 'customer_id' => $cid, 'ref' => mb_cut($note, 120) ?: null, 'at' => now()]);
        if ($target === 'wallet') wallet_add($cid, $amount, 'topup', 'receipt', $rid, $note ?: 'شارژِ کیف پول');
        else customer_ledger_add($cid, -$amount, 'payment', 'receipt', $rid, $note ?: 'دریافت');
        ledger_post($target === 'wallet' ? 'wallet_topup' : 'customer_pay', $rid, ['amount' => $amount, 'method' => $method, 'customer_id' => $cid, 'bank_account_id' => $bankId, 'cashbox_id' => $shift['cashbox_id'] ?? null]);
        audit('receipts', $rid, 'create', null, ['amount' => $amount, 'target' => $target], 'دریافت از مشتری');
        return $rid;
    });
}
function customer_dues(int $limit = 30): array {
    return rows("SELECT c.id, c.name, c.mobile, SUM(l.amount) bal, MIN(CASE WHEN l.amount>0 THEN l.due_date END) due FROM customer_ledger l JOIN customers c ON c.id=l.customer_id GROUP BY c.id, c.name, c.mobile HAVING bal>0 ORDER BY due IS NULL, due LIMIT " . (int)$limit);
}
// ── تماس و پیگیری
function call_results(): array { return ['answered' => 'پاسخ داد', 'no_answer' => 'پاسخ نداد', 'busy' => 'مشغول', 'callback' => 'تماسِ دوباره', 'sold' => 'خرید کرد', 'not_interested' => 'تمایل ندارد']; }
function call_add(array $in): int {
    $cid = (int)($in['customer_id'] ?? 0); if (!$cid) throw new UserError('مشتری لازم است');
    $res = isset(call_results()[$in['result'] ?? '']) ? $in['result'] : 'answered';
    q("UPDATE calls SET done=1 WHERE customer_id=? AND done=0", [$cid]);
    $id = ins('calls', ['customer_id' => $cid, 'user_id' => current_user()['id'] ?? null, 'at' => now(), 'direction' => ($in['direction'] ?? 'out') === 'in' ? 'in' : 'out', 'result' => $res,
        'note' => $in['note'] ?? null, 'internal' => empty($in['internal']) ? 0 : 1, 'next_at' => jparse($in['next_at'] ?? '') ?: null, 'done' => 0]);
    return $id;
}
/** فهرستِ امروز: یادآوری‌های سررسیده + بدهی‌های سررسیده */
function cw_followup(?string $d = null): array {
    $d = $d ?: today();
    $calls = rows("SELECT k.*, c.name, c.mobile, u.name uname FROM calls k JOIN customers c ON c.id=k.customer_id LEFT JOIN users u ON u.id=k.user_id WHERE k.done=0 AND k.next_at IS NOT NULL AND k.next_at<=? ORDER BY k.next_at", [$d]);
    $dues = rows("SELECT c.id customer_id, c.name, c.mobile, SUM(l.amount) bal FROM customer_ledger l JOIN customers c ON c.id=l.customer_id WHERE c.id IN (SELECT customer_id FROM customer_ledger WHERE due_date IS NOT NULL AND due_date<=?) GROUP BY c.id, c.name, c.mobile HAVING bal>0", [$d]);
    return ['calls' => $calls, 'dues' => $dues];
}
