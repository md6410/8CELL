<?php
// حسابداریِ واریز، هزینه و سهمِ شرکا: تأییدِ برگشت‌ناپذیرِ فاکتور، واریز، صورتِ ماهانه و تسویهٔ شریک
declare(strict_types=1);

/** فقط فاکتورِ ثبت‌شده (نه پیش‌فاکتور/برگشت/ابطال) تأییدپذیر است */
/** accConfirmable اینورا: فاکتورِ ثبت‌شده، نه برگشت و نه صورتحسابِ ابطالی/برگشتی */
function acc_confirmable(array $d): bool { return $d['status'] === 'issued' && in_array($d['kind'], ['invoice', 'official'], true) && !($d['kind'] === 'official' && in_array($d['subject'] ?? 'main', ['cancel', 'return'], true)); }
function acc_confirm(int $id, string $note = ''): void {
    $d = doc_get($id); if (!$d) throw new UserError('سند یافت نشد');
    if (!acc_confirmable($d)) throw new UserError('فقط فاکتورِ ثبت‌شده تأیید می‌شود');
    if ($d['confirmed']) throw new UserError('این فاکتور قبلاً تأیید شده و برگشت‌ناپذیر است');
    if (empty($d['buyer']['name'])) throw new UserError('نامِ خریدار روی فاکتور نیست');
    upd('docs', ['confirmed' => 1, 'confirmed_at' => now(), 'confirm_note' => mb_cut(trim($note), 300) ?: null], 'id=?', [$id]);
    audit('docs', $id, 'confirm', ['confirmed' => 0], ['confirmed' => 1], 'تأییدِ خریدار');
}
/** مبلغِ ریالیِ پس از برگشت */
function acc_doc_rial(array $d): int { return max(0, (int)$d['total'] - (int)val("SELECT COALESCE(SUM(total),0) FROM docs WHERE credit_ref=? AND kind='return' AND status='issued'", [$d['id']])); }
function acc_paid(int $docId): int { return (int)val("SELECT COALESCE(SUM(amount),0) FROM doc_payments WHERE doc_id=?", [$docId]); }
function doc_pay_state(array $d): array {
    $rial = acc_doc_rial($d); $paid = acc_paid((int)$d['id']); $rem = $rial - $paid;
    $label = !acc_confirmable($d) ? ($d['status'] === 'draft' ? 'پیش‌نویس' : '—') : (!$d['confirmed'] ? 'منتظرِ تأییدِ خریدار' : ($rem > 0 ? 'منتظرِ واریز' : 'واریز شد'));
    return ['rial' => $rial, 'paid' => $paid, 'remain' => $rem, 'label' => $label];
}
function doc_pay_add(int $docId, int $amount, ?string $d, string $ref = ''): int {
    $doc = doc_get($docId); if (!$doc || !$doc['confirmed']) throw new UserError('واریز فقط برای فاکتورِ تأییدشده ثبت می‌شود');
    if ($amount <= 0) throw new UserError('مبلغ لازم است');
    $st = doc_pay_state($doc); if ($amount > $st['remain']) throw new UserError('بیش از ماندهٔ فاکتور (' . fa_money($st['remain']) . ')');
    $id = ins('doc_payments', ['doc_id' => $docId, 'amount' => $amount, 'd' => $d ?: today(), 'ref' => $ref ?: null, 'created_by' => current_user()['id'] ?? null, 'created_at' => now()]);
    audit('doc_payments', $id, 'create', null, ['amount' => $amount], 'واریزِ فاکتور');
    return $id;
}
function ym_of(string $g): string { return str_replace('/', '-', jym($g)); }
function ym_next(string $ym): string { [$y, $m] = array_map('intval', explode('-', $ym)); $m++; if ($m > 12) { $m = 1; $y++; } return sprintf('%04d-%02d', $y, $m); }
/** هر واریز اول هزینهٔ ماه را می‌پوشاند؛ مابقی بینِ شرکا به ضریب (ریزِ تقسیم به بزرگ‌ترین ضریب) */
function acc_calc(): array {
    $partners = rows("SELECT * FROM acc_partners WHERE active=1 ORDER BY coef DESC, id");
    $deps = []; foreach (rows("SELECT d, amount FROM doc_payments") as $p) { $k = ym_of($p['d']); $deps[$k] = ($deps[$k] ?? 0) + (int)$p['amount']; }
    $exps = rows("SELECT * FROM acc_expenses");
    $first = $deps ? min(array_keys($deps)) : ym_of(today()); $last = ym_of(today());
    foreach ($exps as $e) { $s = $e['monthly'] ? $e['from_ym'] : $e['ym']; if ($s && $s < $first) $first = $s; }
    $months = []; $pending = 0; $share = array_fill_keys(array_column($partners, 'id'), 0);
    for ($ym = $first, $i = 0; $ym <= $last && $i < 240; $ym = ym_next($ym), $i++) {
        $exp = 0; foreach ($exps as $e) { if ($e['monthly'] ? ($ym >= $e['from_ym'] && (!$e['to_ym'] || $ym <= $e['to_ym'])) : $e['ym'] === $ym) $exp += (int)$e['amount']; }
        $credit = $deps[$ym] ?? 0; $need = $pending + $exp;
        $cover = min($credit, $need); $pending = $need - $cover; $rest = $credit - $cover;
        $sh = acc_split($rest, $partners); foreach ($sh as $pid => $a) $share[$pid] += $a;
        $months[] = ['ym' => $ym, 'credit' => $credit, 'expense' => $exp, 'covered' => $cover, 'pending' => $pending, 'income' => $rest, 'shares' => $sh];
    }
    $out = [];
    foreach ($partners as $p) { $set = (int)val("SELECT COALESCE(SUM(amount),0) FROM acc_settles WHERE partner_id=?", [$p['id']]); $out[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'coef' => (float)$p['coef'], 'earned' => $share[$p['id']] ?? 0, 'settled' => $set, 'balance' => ($share[$p['id']] ?? 0) - $set]; }
    return ['months' => $months, 'partners' => $out, 'depRes' => $pending];
}
function acc_split(int $amount, array $partners): array {
    $out = []; if ($amount <= 0 || !$partners) { foreach ($partners as $p) $out[(int)$p['id']] = 0; return $out; }
    $sum = array_sum(array_map(fn($p) => (float)$p['coef'], $partners)); $given = 0;
    foreach ($partners as $p) { $out[(int)$p['id']] = (int)floor($amount * (float)$p['coef'] / $sum); $given += $out[(int)$p['id']]; }
    $out[(int)$partners[0]['id']] += $amount - $given; // ریز به بزرگ‌ترین ضریب
    return $out;
}
function acc_settle(int $partnerId, int $amount, ?string $d, string $note = ''): int {
    if ($amount <= 0) throw new UserError('مبلغ لازم است');
    $id = ins('acc_settles', ['partner_id' => $partnerId, 'amount' => $amount, 'd' => $d ?: today(), 'note' => $note ?: null, 'created_at' => now()]);
    audit('acc_settles', $id, 'create', null, ['amount' => $amount], 'تسویهٔ شریک'); return $id;
}
