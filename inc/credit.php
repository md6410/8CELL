<?php
// حسابِ نسیهٔ مشتری و همکار + قولِ پرداخت + چکِ دریافتی
//  - بدهی همان customer_ledger است (فروشِ نسیه در صندوق، برگشت، دریافت)؛ مانده همیشه جمعِ ردیف‌ها.
//  - «قولِ پرداخت»: مبلغ و تاریخی که مشتری/همکار قول داده؛ یک روز پیش (قابلِ تنظیم) و سرِ موعد به مدیران هشدار
//    (داشبورد + بله + پیامک) و اگر خواسته شد به خودِ مشتری پیامک/بله. با صفر شدنِ بدهی خودکار «عمل شد».
//  - چکِ دریافتی از مشتری/همکار: با ثبت، از بدهی‌اش کم می‌شود (اسنادِ دریافتنی)؛ برگشت/عودت بدهی را برمی‌گرداند؛ وصول به بانک می‌رود.
declare(strict_types=1);

function promise_statuses(): array { return ['open' => 'در انتظار', 'kept' => 'عمل شد', 'broken' => 'عمل نشد', 'canceled' => 'لغو']; }
function promise_save(array $in): int {
    $cid = (int)($in['customer_id'] ?? 0); $c = $cid ? row("SELECT id FROM customers WHERE id=?", [$cid]) : null; if (!$c) throw new UserError('مشتری لازم است');
    $amount = to_int($in['amount'] ?? 0); $due = jparse((string)($in['due'] ?? ''));
    if ($amount <= 0) $amount = max(0, customer_balance($cid));
    if ($amount <= 0) throw new UserError('این شخص بدهیِ باز ندارد؛ مبلغ را وارد کنید');
    if (!$due) throw new UserError('تاریخِ قولِ پرداخت لازم است');
    $id = ins('credit_promises', ['customer_id' => $cid, 'amount' => $amount, 'due_date' => $due, 'note' => mb_cut((string)($in['note'] ?? ''), 300) ?: null, 'sms_customer' => empty($in['sms_customer']) ? 0 : 1,
        'bale_customer' => empty($in['bale_customer']) ? 0 : 1, 'created_by' => current_user()['id'] ?? null, 'created_at' => now()]);
    audit('credit_promises', $id, 'create', null, ['amount' => $amount, 'due' => $due], 'قولِ پرداخت');
    return $id;
}
function promise_set(int $id, string $st): void {
    if (!isset(promise_statuses()[$st])) throw new UserError('وضعیتِ نامعتبر');
    $p = row("SELECT * FROM credit_promises WHERE id=?", [$id]); if (!$p) throw new UserError('یافت نشد');
    upd('credit_promises', ['status' => $st, 'closed_at' => $st === 'open' ? null : now()], 'id=?', [$id]);
    audit('credit_promises', $id, 'status', ['status' => $p['status']], ['status' => $st], 'قولِ پرداخت');
}
/** قول‌های باز که بدهیِ صاحبش صفر شده = عمل شد */
function promise_autoclose(): int {
    $n = 0; foreach (rows("SELECT id, customer_id FROM credit_promises WHERE status='open'") as $p) if (customer_balance((int)$p['customer_id']) <= 0) { upd('credit_promises', ['status' => 'kept', 'closed_at' => now()], 'id=?', [$p['id']]); $n++; }
    return $n;
}
function promises_open(?int $cid = null): array {
    return rows("SELECT p.*, c.name, c.mobile, c.is_partner FROM credit_promises p JOIN customers c ON c.id=p.customer_id WHERE p.status='open'" . ($cid ? " AND p.customer_id=" . (int)$cid : '') . " ORDER BY p.due_date");
}
/** یادآوری‌ها: پیش از موعد و سرِ موعد (و دیرکرد) — هرکدام یک بار */
function promise_reminders(?string $today = null): int {
    $today = $today ?: today(); promise_autoclose(); $before = max(0, (int)setting('credit_remind_days', '1')); $n = 0;
    foreach (promises_open() as $p) {
        $bal = customer_balance((int)$p['customer_id']); $amt = min((int)$p['amount'], max(0, $bal)) ?: (int)$p['amount'];
        $who = ($p['is_partner'] ? 'همکار ' : 'مشتری ') . $p['name']; $dj = jdate($p['due_date']);
        $sms = ['NAME' => $p['name'], 'AMOUNT' => fa_money($amt), 'DATE' => $dj];
        if ($p['due_date'] > $today && !$p['pre_reminded_at'] && $before > 0 && $p['due_date'] <= date('Y-m-d', strtotime("$today +$before day"))) {
            $n += notify_managers('promise_pre:' . $p['id'], "یادآوری: {$who} قول داده {$dj} مبلغِ " . fa_money($amt) . ' ریال بپردازد. ماندهٔ بدهی: ' . fa_money($bal), 'promise_due', $sms);
            $n += notify_customer('promise_pre:' . $p['id'], (int)$p['customer_id'], 'سلام ' . $p['name'] . "، یادآوری: موعدِ پرداختِ " . fa_money($amt) . " ریال {$dj} است. " . setting('biz_name', ''), (bool)$p['sms_customer'], (bool)$p['bale_customer'], 'promise_remind', $sms);
            upd('credit_promises', ['pre_reminded_at' => now()], 'id=?', [$p['id']]);
        }
        if ($p['due_date'] <= $today && !$p['reminded_at']) {
            $late = $p['due_date'] < $today ? ' (' . fa_num((string)((strtotime($today) - strtotime($p['due_date'])) / 86400)) . ' روز گذشته)' : ' (امروز)';
            $n += notify_managers('promise_due:' . $p['id'], "⏰ موعدِ قولِ پرداختِ {$who}{$late}: " . fa_money($amt) . ' ریال. ماندهٔ بدهی: ' . fa_money($bal) . ($p['mobile'] ? ' · ' . fa_num($p['mobile']) : ''), 'promise_due', $sms);
            $n += notify_customer('promise_due:' . $p['id'], (int)$p['customer_id'], 'سلام ' . $p['name'] . '، امروز موعدِ پرداختِ ' . fa_money($amt) . ' ریال است. ' . setting('biz_name', ''), (bool)$p['sms_customer'], (bool)$p['bale_customer'], 'promise_remind', $sms);
            upd('credit_promises', ['reminded_at' => now()], 'id=?', [$p['id']]);
        }
    }
    return $n;
}
/** حساب‌های نسیه: مانده، نزدیک‌ترین قول، دیرکرد */
function credit_accounts(string $who = '', bool $onlyDebt = true): array {
    $w = $who === 'partner' ? ' AND c.is_partner=1' : ($who === 'customer' ? ' AND c.is_partner=0' : '');
    $rs = rows("SELECT c.id, c.name, c.mobile, c.is_partner, c.credit_limit, COALESCE(SUM(l.amount),0) bal, MIN(CASE WHEN l.amount>0 THEN l.due_date END) sale_due,
        (SELECT MIN(p.due_date) FROM credit_promises p WHERE p.customer_id=c.id AND p.status='open') promise_due,
        (SELECT COALESCE(SUM(q.amount),0) FROM cheques q WHERE q.party_type='customer' AND q.party_id=c.id AND q.direction='in' AND q.status='pending') cheques
        FROM customers c LEFT JOIN customer_ledger l ON l.customer_id=c.id WHERE c.merged_into IS NULL$w GROUP BY c.id, c.name, c.mobile, c.is_partner, c.credit_limit" . ($onlyDebt ? " HAVING bal>0" : '') . " ORDER BY bal DESC");
    $t = today();
    foreach ($rs as &$r) { $r['bal'] = (int)$r['bal']; $d = $r['promise_due'] ?: $r['sale_due']; $r['due'] = $d; $r['late_days'] = $d && $d < $t ? (int)((strtotime($t) - strtotime($d)) / 86400) : 0; }
    return $rs;
}
/** سن‌بندیِ بدهی (۰–۳۰، ۳۱–۶۰، ۶۱–۹۰، بیش از ۹۰ روز) — FIFO روی ردیف‌های بدهکار */
function credit_aging(): array {
    $o = []; $t = strtotime(today());
    foreach (credit_accounts() as $a) {
        $left = $a['bal']; $b = ['0_30' => 0, '31_60' => 0, '61_90' => 0, '90p' => 0];
        foreach (rows("SELECT amount, at FROM customer_ledger WHERE customer_id=? AND amount>0 ORDER BY at DESC", [$a['id']]) as $l) {
            if ($left <= 0) break; $x = min($left, (int)$l['amount']); $left -= $x; $age = (int)(($t - strtotime(substr($l['at'], 0, 10))) / 86400);
            $b[$age <= 30 ? '0_30' : ($age <= 60 ? '31_60' : ($age <= 90 ? '61_90' : '90p'))] += $x;
        }
        $o[] = ['name' => $a['name'] . ($a['is_partner'] ? ' (همکار)' : ''), 'mobile' => $a['mobile'], 'bal' => $a['bal']] + $b;
    }
    return $o;
}

// ── چکِ دریافتی از مشتری/همکار
function cheque_receive(array $in): int {
    $cid = (int)($in['customer_id'] ?? 0); $c = $cid ? row("SELECT id, name FROM customers WHERE id=?", [$cid]) : null; if (!$c) throw new UserError('مشتری/همکار لازم است');
    $sayad = preg_replace('/\D/', '', en_num((string)($in['sayad'] ?? ''))); if ($sayad !== '' && strlen($sayad) !== 16) throw new UserError('شناسهٔ صیادی ۱۶ رقم است');
    if ($sayad !== '' && val("SELECT id FROM cheques WHERE sayad=? AND status NOT IN ('returned','bounced')", [$sayad])) throw new UserError('این چک (شناسهٔ صیادی) قبلاً ثبت شده است');
    return tx(function () use ($in, $cid, $c, $sayad) {
        $id = cheque_save(['direction' => 'in', 'number' => $in['number'] ?? '', 'bank' => $in['bank'] ?? null, 'amount' => $in['amount'] ?? 0, 'due' => $in['due'] ?? '', 'party_type' => 'customer', 'party_id' => $cid, 'party_name' => $c['name'],
            'bank_account_id' => (int)($in['bank_account_id'] ?? 0), 'note' => $in['note'] ?? null]);
        upd('cheques', ['sayad' => $sayad ?: null, 'drawer' => mb_cut(trim((string)($in['drawer'] ?? '')), 160) ?: null, 'created_by' => current_user()['id'] ?? null, 'status_at' => now()], 'id=?', [$id]);
        $ch = row("SELECT * FROM cheques WHERE id=?", [$id]);
        customer_ledger_add($cid, -(int)$ch['amount'], 'cheque', 'cheque', $id, 'چکِ ' . $ch['number'] . ' — سررسید ' . jdate($ch['due_date']));
        ledger_post('cheque_recv', $id);
        return $id;
    });
}
/** برگشت (برگشتی از بانک) یا عودت به صاحبش: بدهی برمی‌گردد */
function cheque_reverse(int $id, string $status, string $note = ''): void {
    $c = row("SELECT * FROM cheques WHERE id=?", [$id]); if (!$c) throw new UserError('چک یافت نشد');
    if ($c['status'] !== 'pending') throw new UserError('فقط چکِ در جریان برگشت/عودت می‌شود');
    tx(function () use ($c, $id, $status, $note) {
        upd('cheques', ['status' => $status, 'status_at' => now(), 'note' => mb_cut(trim(($c['note'] ?? '') . ' ' . $note), 300) ?: null], 'id=?', [$id]);
        if ($c['direction'] === 'in' && $c['party_type'] === 'customer' && $c['party_id']) {
            customer_ledger_add((int)$c['party_id'], (int)$c['amount'], 'cheque_back', 'cheque', $id, ($status === 'bounced' ? 'برگشتِ چکِ ' : 'عودتِ چکِ ') . $c['number']);
            if (val("SELECT id FROM journals WHERE source_type='cheque_recv' AND source_id=?", [(string)$id])) ledger_post('cheque_back', $id);
        }
        audit('cheques', $id, 'status', ['status' => 'pending'], ['status' => $status], $status === 'bounced' ? 'برگشتِ چک' : 'عودتِ چک');
    });
    if ($status === 'bounced') notify_managers('cheque_bounce:' . $id, '❗ چکِ ' . fa_num($c['number']) . ' از ' . ($c['party_name'] ?: '—') . ' به مبلغِ ' . fa_money($c['amount']) . ' برگشت خورد؛ به بدهی‌اش برگشت.', 'cheque_due', ['NAME' => (string)$c['party_name'], 'NUMBER' => (string)$c['number'], 'AMOUNT' => fa_money($c['amount']), 'DATE' => 'برگشتی']);
}
function cheque_statuses(): array { return ['pending' => 'در جریان', 'cleared' => 'وصول شد', 'bounced' => 'برگشتی', 'returned' => 'عودت شد']; }
/** یادآوریِ سررسیدِ چک‌ها (دریافتنی و پرداختنی): N روز پیش و روزِ سررسید */
function cheque_reminders(?string $today = null): int {
    $today = $today ?: today(); $before = max(0, (int)setting('cheque_remind_days', '2')); $n = 0;
    foreach (rows("SELECT * FROM cheques WHERE status='pending' AND due_date<=? ORDER BY due_date", [date('Y-m-d', strtotime("$today +$before day"))]) as $c) {
        $in = $c['direction'] === 'in'; $dj = jdate($c['due_date']);
        $when = $c['due_date'] > $today ? 'سررسید ' . $dj : ($c['due_date'] === $today ? 'سررسیدِ امروز' : 'سررسیدِ گذشته (' . $dj . ')');
        $key = 'cheque:' . $c['id'] . ':' . ($c['due_date'] > $today ? 'pre' : 'due');
        $n += notify_managers($key, ($in ? '💳 چکِ دریافتنی' : '📤 چکِ پرداختنی') . ' ' . fa_num($c['number']) . ' — ' . ($c['party_name'] ?: '—') . ' — ' . fa_money($c['amount']) . ' ریال — ' . $when . ($in ? '. به بانک بدهید.' : '. موجودیِ حساب را تأمین کنید.'),
            'cheque_due', ['NAME' => (string)($c['party_name'] ?: '—'), 'NUMBER' => (string)$c['number'], 'AMOUNT' => fa_money($c['amount']), 'DATE' => $dj]);
    }
    return $n;
}
