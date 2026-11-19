<?php
// همکارِ فروش: قیمتِ همکاری (تخفیفِ خطی روی قیمتِ نهایی)، سفارشِ همکار (ارسال/نگه‌داری/واریز) و گزارش
declare(strict_types=1);

function partner_modes(): array { return ['self' => 'خرید برای خودش', 'ship' => 'ارسال برای مشتریِ همکار', 'hold' => 'نگه‌داری در انبار', 'transfer' => 'واریز به حساب']; }
/** تخفیفِ خطی بینِ «تکی» (۱ عدد) و «بیشینه» (از max_qty به بالا) — هرگز گران‌تر از مشتریِ عادی */
function partner_disc_pct(float $single, float $max, int $maxQty, int $qty): float {
    if ($maxQty <= 1 || $qty >= $maxQty) return $qty >= $maxQty ? $max : $single;
    if ($qty <= 1) return $single;
    return $single + ($max - $single) * ($qty - 1) / ($maxQty - 1);
}
function partner_price(int $pid, ?int $vid, int $gradeId, int $qty): int {
    $base = retail_final_price($pid, $vid);
    $r = row("SELECT * FROM product_grade_prices WHERE product_id=? AND grade_id=?", [$pid, $gradeId]);
    $pct = $r ? partner_disc_pct((float)$r['disc_single'], (float)$r['disc_max'], (int)$r['max_qty'], max(1, $qty)) : (float)val("SELECT disc_pct FROM grades WHERE id=?", [$gradeId]);
    $p = (int)floor($base * (100 - max(0, min(100, $pct))) / 100);
    return min($p, $base);
}
function partner_grade_price_save(int $pid, int $gid, $single, $max, $maxQty): void {
    $s = to_float($single); $m = to_float($max); $mq = max(1, to_int($maxQty));
    if ($s < 0 || $m < $s || $m > 100) throw new UserError('درصدها نامعتبر است (بیشینه ≥ تکی)');
    q("INSERT INTO product_grade_prices VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE disc_single=VALUES(disc_single), disc_max=VALUES(disc_max), max_qty=VALUES(max_qty)", [$pid, $gid, $s, $m, $mq]);
    audit('product_grade_prices', "$pid-$gid", 'update', null, ['single' => $s, 'max' => $m, 'max_qty' => $mq], 'قیمتِ همکاری');
}
/** پیامکِ سفارشِ ارسالِ همکار به مشتریِ خودِ همکار می‌رود (فقط برای سفارشِ همین همکار) */
function pship_sms_route(int $saleId): ?array {
    $s = row("SELECT s.*, c.name pname FROM sales s JOIN customers c ON c.id=s.customer_id WHERE s.id=? AND s.partner_mode='ship'", [$saleId]);
    if (!$s) return null; $ship = json_decode((string)$s['ship_to'], true) ?: [];
    if (empty($ship['mobile'])) return null;
    return sms_send_tpl($ship['mobile'], 'partner_ship', ['NAME' => $ship['name'] ?? '', 'NUMBER' => $s['number'], 'PARTNER' => $s['pname']], 'notify', ['ref_type' => 'sale', 'ref_id' => $saleId]);
}
/** آزادکردنِ سفارشِ نگه‌داری برای خروج — رزرو همان می‌ماند تا خروجِ واقعی */
function phold_release(int $saleId, array $shipTo = []): void {
    $s = row("SELECT * FROM sales WHERE id=?", [$saleId]);
    if (!$s || $s['partner_mode'] !== 'hold' || $s['fulfil'] !== 'hold') throw new UserError('این سفارش در حالتِ نگه‌داری نیست');
    upd('sales', ['fulfil' => 'pending', 'ship_to' => $shipTo ? json_encode($shipTo, JSON_UNESCAPED_UNICODE) : $s['ship_to']], 'id=?', [$saleId]);
    audit('sales', $saleId, 'release', ['fulfil' => 'hold'], ['fulfil' => 'pending'], 'آزادسازیِ نگه‌داری');
}
function phold_is_release(array $s): bool { return $s['partner_mode'] === 'hold' && $s['fulfil'] !== 'hold'; }
/** گزارش: خریدِ خودش در برابرِ فروش برای مشتری‌اش */
function psales_rows(string $from, string $to): array {
    return rows("SELECT c.id, c.name, SUM(CASE WHEN COALESCE(s.partner_mode,'self') IN ('self','transfer') THEN s.total ELSE 0 END) self_total,
        SUM(CASE WHEN s.partner_mode='ship' THEN s.total ELSE 0 END) ship_total, SUM(CASE WHEN s.partner_mode='hold' THEN s.total ELSE 0 END) hold_total, COUNT(*) cnt
        FROM sales s JOIN customers c ON c.id=s.customer_id WHERE c.is_partner=1 AND s.d BETWEEN ? AND ? GROUP BY c.id, c.name ORDER BY SUM(s.total) DESC", [$from, $to]);
}
