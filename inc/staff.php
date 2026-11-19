<?php
// کارمندانِ فروش: حسابِ نقدی و تنخواه (مانده = جمعِ ردیف‌ها)، سقف و مسدودی، پورسانت و عملکرد
declare(strict_types=1);

function stacc_kinds(): array { return ['shift_cash' => 'نقدِ شیفت', 'handover' => 'تحویلِ نقد', 'petty' => 'تنخواهِ دریافتی', 'petty_spend' => 'هزینه از تنخواه', 'debt' => 'بدهی', 'shortage' => 'کسریِ صندوق', 'repay' => 'بازپرداخت', 'adjust' => 'اصلاح']; }
function stacc_add(int $uid, int $amount, string $kind, ?string $rt = null, ?int $rid = null, string $note = ''): int {
    if (!isset(stacc_kinds()[$kind])) throw new UserError('نوعِ ردیف نامعتبر');
    return ins('staff_ledger', ['user_id' => $uid, 'amount' => $amount, 'kind' => $kind, 'ref_type' => $rt, 'ref_id' => $rid, 'note' => mb_cut($note, 300), 'by_user' => current_user()['id'] ?? null, 'at' => now()]);
}
/** مانده: مثبت = آنچه کارمند به کسب‌وکار بدهکار است (نقدِ دست + بدهی) */
function stacc_balance(int $uid, ?array $kinds = null): int {
    return (int)val("SELECT COALESCE(SUM(amount),0) FROM staff_ledger WHERE user_id=?" . ($kinds ? " AND kind IN ('" . implode("','", array_map('addslashes', $kinds)) . "')" : ''), [$uid]);
}
function stacc_salary(int $uid): int { return (int)val("SELECT base_salary FROM employees WHERE user_id=? AND active=1 LIMIT 1", [$uid]); }
/** سقف: بدهیِ بیش از ٪ حقوق ← مسدود؛ یا مسدودیِ دستی */
function stacc_cash_limit(int $uid): int { $sal = stacc_salary($uid); return $sal > 0 ? (int)floor($sal * (float)setting('staff_debt_pct', 20) / 100) : (int)setting('staff_debt_cap', 0); }
function stacc_blocked(int $uid): bool {
    if (setting('stacc_block_' . $uid) === '1') return true;
    $lim = stacc_cash_limit($uid); if ($lim <= 0) return false;
    return stacc_balance($uid) > $lim;
}
function stacc_block(int $uid, bool $on): void { settings_save(['stacc_block_' . $uid => $on ? '1' : '0']); }
/** تحویلِ نقد به بانک/گاوصندوق */
function stacc_handover(int $uid, int $amount, int $bankId, string $note = ''): int {
    if ($amount <= 0) throw new UserError('مبلغ لازم است');
    if ($amount > stacc_balance($uid, ['shift_cash', 'handover'])) throw new UserError('بیش از نقدِ دستِ کارمند است');
    return tx(function () use ($uid, $amount, $bankId, $note) {
        $id = stacc_add($uid, -$amount, 'handover', 'bank', $bankId, $note ?: 'واریز به بانک');
        ledger_post('cash_deposit', $id, ['amount' => $amount, 'bank_account_id' => $bankId]);
        return $id;
    });
}

// ── پورسانت
/** پلکانی: هر پله درصدی روی بخشی از پایه که در آن پله است */
function commission_calc(int $base, array $tiers): int {
    if ($base <= 0) return 0;
    usort($tiers, fn($a, $b) => (int)$a['from'] <=> (int)$b['from']); $tot = 0.0;
    foreach ($tiers as $i => $t) {
        $from = (int)$t['from']; $to = isset($tiers[$i + 1]) ? (int)$tiers[$i + 1]['from'] : PHP_INT_MAX;
        if ($base <= $from) break;
        $tot += (min($base, $to) - $from) * (float)$t['pct'] / 100;
    }
    return (int)floor($tot);
}
function commission_rule_for(int $uid, ?int $catId, ?int $channelId): ?array {
    $rs = rows("SELECT * FROM commission_rules WHERE active=1 AND (user_id IS NULL OR user_id=?) AND (category_id IS NULL OR category_id<=>?) AND (channel_id IS NULL OR channel_id<=>?)
        ORDER BY (user_id IS NOT NULL) + (category_id IS NOT NULL) + (channel_id IS NOT NULL) DESC, id DESC LIMIT 1", [$uid, $catId, $channelId]);
    return $rs[0] ?? null;
}
function commission_rule_save(array $in): int {
    $tiers = [];
    foreach ((array)($in['tiers'] ?? []) as $t) if (isset($t['pct']) && $t['pct'] !== '') $tiers[] = ['from' => to_int($t['from'] ?? 0), 'pct' => to_float($t['pct'])];
    if (!$tiers) throw new UserError('دست‌کم یک پله لازم است');
    $d = ['name' => trim((string)($in['name'] ?? '')) ?: 'پورسانت', 'base' => ($in['base'] ?? 'profit') === 'revenue' ? 'revenue' : 'profit', 'user_id' => (int)($in['user_id'] ?? 0) ?: null,
        'category_id' => (int)($in['category_id'] ?? 0) ?: null, 'channel_id' => (int)($in['channel_id'] ?? 0) ?: null, 'tiers' => json_encode($tiers), 'active' => 1];
    if (!empty($in['id'])) { upd('commission_rules', $d, 'id=?', [(int)$in['id']]); return (int)$in['id']; }
    $id = ins('commission_rules', $d); audit('commission_rules', $id, 'create', null, $d, 'قاعدهٔ پورسانت'); return $id;
}
/** پورسانتِ هر ردیفِ فروش (هر ریال به یک ردیف برمی‌گردد) — تکرار بی‌اثر */
function commission_for_sale(int $saleId): int {
    $s = row("SELECT * FROM sales WHERE id=?", [$saleId]); if (!$s) return 0;
    if (val("SELECT 1 FROM commission_lines WHERE sale_id=? AND return_id IS NULL LIMIT 1", [$saleId])) return 0;
    $tot = 0; $ym = str_replace('/', '-', jym($s['d']));
    foreach (rows("SELECT si.*, p.category_id FROM sale_items si JOIN products p ON p.id=si.product_id WHERE si.sale_id=?", [$saleId]) as $it) {
        $r = commission_rule_for((int)$s['seller_id'], $it['category_id'] ? (int)$it['category_id'] : null, $s['channel_id'] ? (int)$s['channel_id'] : null);
        if (!$r) continue;
        $base = $r['base'] === 'revenue' ? (int)$it['net'] : (int)$it['net'] - (int)$it['cost'];
        $a = commission_calc($base, json_decode($r['tiers'], true) ?: []);
        if ($a <= 0) continue;
        ins('commission_lines', ['user_id' => $s['seller_id'], 'sale_id' => $saleId, 'sale_item_id' => $it['id'], 'amount' => $a, 'ym' => $ym, 'rule_id' => $r['id'], 'at' => now()]);
        $tot += $a;
    }
    return $tot;
}
/** برگشت پورسانت را به نسبت کم می‌کند (در ماهِ برگشت) */
function commission_reverse(int $saleItemId, int $qty, int $returnId): int {
    $it = row("SELECT * FROM sale_items WHERE id=?", [$saleItemId]);
    $orig = (int)val("SELECT COALESCE(SUM(amount),0) FROM commission_lines WHERE sale_item_id=? AND return_id IS NULL", [$saleItemId]);
    if (!$it || $orig <= 0) return 0;
    $a = intdiv($orig * $qty, (int)$it['qty']); if ($a <= 0) return 0;
    $uid = (int)val("SELECT user_id FROM commission_lines WHERE sale_item_id=? LIMIT 1", [$saleItemId]);
    ins('commission_lines', ['user_id' => $uid, 'sale_id' => $it['sale_id'], 'sale_item_id' => $saleItemId, 'return_id' => $returnId, 'amount' => -$a, 'ym' => str_replace('/', '-', jym(today())), 'at' => now()]);
    return $a;
}
function commission_month(int $uid, string $ym): int { return (int)val("SELECT COALESCE(SUM(amount),0) FROM commission_lines WHERE user_id=? AND ym=?", [$uid, str_replace('/', '-', en_num($ym))]); }

// ── عملکرد
function perf_staff_rows(string $from, string $to): array {
    $rs = rows("SELECT u.id, u.name, u.kind, COUNT(s.id) cnt, COALESCE(SUM(s.total),0) total, COALESCE(SUM(s.line_disc+s.doc_disc),0) disc,
        COALESCE((SELECT SUM(r.amount+r.tax) FROM sale_returns r JOIN sales s2 ON s2.id=r.sale_id WHERE s2.seller_id=u.id AND r.d BETWEEN ? AND ?),0) returns,
        COALESCE((SELECT SUM(cl.amount) FROM commission_lines cl WHERE cl.user_id=u.id AND cl.at BETWEEN ? AND ?),0) commission
        FROM users u JOIN sales s ON s.seller_id=u.id AND s.d BETWEEN ? AND ? GROUP BY u.id, u.name, u.kind ORDER BY total DESC", [$from, $to, $from . ' 00:00:00', $to . ' 23:59:59', $from, $to]);
    foreach ($rs as $i => &$r) { $r['rank'] = $i + 1; $r['avg'] = $r['cnt'] ? intdiv((int)$r['total'], (int)$r['cnt']) : 0; $r['label'] = 'فروشنده: ' . $r['name']; }
    return $rs;
}
function staff_target_set(int $uid, string $ym, int $target): void { q("INSERT INTO staff_targets (user_id, ym, target) VALUES (?,?,?) ON DUPLICATE KEY UPDATE target=VALUES(target)", [$uid, str_replace('/', '-', en_num($ym)), $target]); }
function staff_target_progress(int $uid, string $ym): array {
    $ym = str_replace('/', '-', en_num($ym)); [$a, $b] = jmonth_range($ym);
    $t = (int)val("SELECT target FROM staff_targets WHERE user_id=? AND ym=?", [$uid, $ym]);
    $s = (int)val("SELECT COALESCE(SUM(total),0) FROM sales WHERE seller_id=? AND d BETWEEN ? AND ?", [$uid, $a, $b]);
    return ['target' => $t, 'sales' => $s, 'pct' => $t > 0 ? round($s * 100 / $t, 1) : null];
}
