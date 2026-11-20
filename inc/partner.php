<?php
// همکارِ فروش: قیمتِ همکاری (تخفیفِ خطی روی قیمتِ نهایی)، سفارشِ همکار (ارسال/نگه‌داری/واریز) و گزارش
declare(strict_types=1);

function partner_modes(): array { return ['self' => 'خرید برای خودش', 'ship' => 'ارسال برای مشتریِ همکار', 'hold' => 'نگه‌داری در انبار', 'transfer' => 'واریز به حساب']; }
/** تخفیفِ خطی بینِ «تکی» (۱ عدد) و «بیشینه» (از max_qty به بالا) — هرگز گران‌تر از مشتریِ عادی */
function partner_disc_pct(float $single, float $max, int $maxQty, int $qty): float {
    // همان partner_pct در Weby: سقفِ ۹۰٪، «بیشینه ≤ تکی» یعنی پله‌ای نیست
    $one = max(0.0, min(90.0, $single)); $mx = max(0.0, min(90.0, $max)); $qty = max(1, $qty);
    if ($mx <= $one) return $one;
    if ($maxQty <= 1 || $qty >= $maxQty) return $mx;
    return $one + ($mx - $one) * (($qty - 1) / ($maxQty - 1));
}
function partner_price(int $pid, ?int $vid, int $gradeId, int $qty): int {
    $base = retail_final_price($pid, $vid);
    $r = row("SELECT * FROM product_grade_prices WHERE product_id=? AND grade_id=?", [$pid, $gradeId]);
    $pct = $r ? partner_disc_pct((float)$r['disc_single'], (float)$r['disc_max'], (int)$r['max_qty'], max(1, $qty)) : (float)val("SELECT disc_pct FROM grades WHERE id=?", [$gradeId]);
    $p = (int)round($base * (100 - max(0, min(90, $pct))) / 100);
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

// ── قیمتِ همکاری به‌صورتِ گروهی و فهرستِ قیمتِ همکار (روالِ Weby admin/partner-prices.php و partner_price_list)
function partner_grades(): array { return rows("SELECT * FROM grades WHERE kind='partner' ORDER BY id"); }
/** جدولِ کالا × گرید برای ویرایشِ یک‌جا */
function partner_prices_grid(array $f = []): array {
    $w = ["p.active=1"]; $p = [];
    if (!empty($f['q'])) { $w[] = "(p.name LIKE ? OR p.barcode=?)"; $p[] = '%' . $f['q'] . '%'; $p[] = $f['q']; }
    if (!empty($f['category_id'])) { $w[] = "p.category_id=?"; $p[] = (int)$f['category_id']; }
    if (!empty($f['only_rules'])) $w[] = "EXISTS (SELECT 1 FROM product_grade_prices g WHERE g.product_id=p.id)";
    $rs = rows("SELECT p.id, p.name FROM products p WHERE " . implode(' AND ', $w) . " ORDER BY p.name LIMIT " . max(1, min(500, (int)($f['limit'] ?? 200))), $p);
    $rules = []; if ($rs) foreach (rows("SELECT * FROM product_grade_prices WHERE product_id IN (" . in_list(array_column($rs, 'id')) . ")") as $r) $rules[$r['product_id']][$r['grade_id']] = $r;
    foreach ($rs as &$r) { $r['retail'] = retail_final_price((int)$r['id'], null); $r['rules'] = $rules[$r['id']] ?? []; }
    return $rs;
}
/** ذخیرهٔ یک‌جا؛ ردیفِ تمام‌صفر یعنی «بی‌قاعده» و پاک می‌شود */
function partner_prices_save_bulk(array $one, array $max, array $qty): int {
    $n = 0; $grades = array_column(partner_grades(), 'id');
    return tx(function () use ($one, $max, $qty, $grades, &$n) {
        foreach ($one as $pid => $byG) foreach ((array)$byG as $gid => $s) {
            $pid = (int)$pid; $gid = (int)$gid; if (!in_array($gid, array_map('intval', $grades), true)) continue;
            $sv = to_float($s); $mv = to_float($max[$pid][$gid] ?? 0); $qv = max(1, to_int($qty[$pid][$gid] ?? 1));
            if ($sv <= 0 && $mv <= 0) { if (q("DELETE FROM product_grade_prices WHERE product_id=? AND grade_id=?", [$pid, $gid])->rowCount()) $n++; continue; }
            if ($mv < $sv) $mv = $sv;
            $old = row("SELECT * FROM product_grade_prices WHERE product_id=? AND grade_id=?", [$pid, $gid]);
            if ($old && abs((float)$old['disc_single'] - $sv) < 1e-6 && abs((float)$old['disc_max'] - $mv) < 1e-6 && (int)$old['max_qty'] === $qv) continue;
            partner_grade_price_save($pid, $gid, $sv, $mv, $qv); $n++;
        }
        return $n;
    });
}
/** فهرستِ قیمتِ یک همکار: کالاهای دارای قاعده برای گریدِ او */
function partner_price_list(int $cid): array {
    $c = row("SELECT * FROM customers WHERE id=? AND is_partner=1", [$cid]); if (!$c || !$c['partner_grade_id']) return [];
    $o = [];
    foreach (rows("SELECT p.id, p.name, pg.disc_single, pg.disc_max, pg.max_qty FROM product_grade_prices pg JOIN products p ON p.id=pg.product_id WHERE pg.grade_id=? AND p.active=1 ORDER BY p.name", [$c['partner_grade_id']]) as $r) {
        $retail = retail_final_price((int)$r['id'], null); if ($retail <= 0) continue;
        $o[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'retail' => $retail, 'pct_one' => (float)$r['disc_single'], 'pct_max' => (float)$r['disc_max'], 'qty_max' => (int)$r['max_qty'],
            'price_one' => partner_price((int)$r['id'], null, (int)$c['partner_grade_id'], 1), 'price_max' => partner_price((int)$r['id'], null, (int)$c['partner_grade_id'], max(1, (int)$r['max_qty']))];
    }
    return $o;
}
/** شمارهٔ فهرست: یکی برای هر روز و هر همکار (PL-سال####) */
function partner_list_doc(int $cid, int $items): string {
    if ($no = val("SELECT doc_no FROM partner_lists WHERE customer_id=? AND DATE(created_at)=CURDATE() ORDER BY id DESC LIMIT 1", [$cid])) return (string)$no;
    $pre = 'PL-' . substr(jtoday(), 0, 4);
    for ($i = 0; $i < 20; $i++) {
        $last = (string)val("SELECT doc_no FROM partner_lists WHERE doc_no LIKE ? ORDER BY doc_no DESC LIMIT 1", [$pre . '%']);
        $no = $pre . str_pad((string)((preg_match('/(\d{4})$/', $last, $m) ? (int)$m[1] : 0) + 1), 4, '0', STR_PAD_LEFT);
        try { ins('partner_lists', ['doc_no' => $no, 'customer_id' => $cid, 'grade_id' => (int)val("SELECT partner_grade_id FROM customers WHERE id=?", [$cid]) ?: null, 'items' => $items, 'created_by' => current_user()['id'] ?? null, 'created_at' => now()]); return $no; }
        catch (PDOException $e) { continue; }
    }
    return $pre . date('His');
}
/** لینکِ امضاشدهٔ فهرستِ قیمت (۳۰ روز) */
function plist_token(int $cid): string { $b = base_convert((string)$cid, 10, 36) . '.' . base_convert((string)intdiv(time() + 30 * 86400, 86400), 10, 36); return $b . '.' . substr(rtrim(strtr(base64_encode(hash_hmac('sha256', 'plist|' . $b, app_secret(), true)), '+/', '-_'), '='), 0, 10); }
function plist_parse(string $t): ?int {
    if (!preg_match('/^([0-9a-z]+)\.([0-9a-z]+)\.([A-Za-z0-9_-]{10})$/', $t, $m)) return null; $b = $m[1] . '.' . $m[2];
    if (!hash_equals(substr(rtrim(strtr(base64_encode(hash_hmac('sha256', 'plist|' . $b, app_secret(), true)), '+/', '-_'), '='), 0, 10), $m[3])) return null;
    if ((int)base_convert($m[2], 36, 10) * 86400 + 86399 < time()) return null;
    return (int)base_convert($m[1], 36, 10);
}
function plist_url(int $cid): string { return rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/v.php?pl=' . plist_token($cid); }
/** فرستادنِ لینکِ فهرست به همکار(ها) — پیامک و/یا بله */
function partner_list_send(array $cids, bool $sms = true, bool $bale = true): array {
    $ok = 0; $fail = [];
    foreach ($cids as $cid) {
        $c = row("SELECT id, name, mobile, bale_chat_id FROM customers WHERE id=? AND is_partner=1", [(int)$cid]); if (!$c) continue;
        $url = plist_url((int)$cid); $sent = false;
        if ($sms && $c['mobile']) { $r = sms_send_tpl((string)$c['mobile'], 'partner_list', ['NAME' => (string)$c['name'], 'LINK' => $url], 'notify', ['ref_type' => 'customer', 'ref_id' => (int)$cid]); $sent = $sent || $r['ok']; if (!$r['ok']) $fail[] = $c['name'] . ': ' . $r['msg']; }
        if ($bale && bale_on() && $c['bale_chat_id']) { $r = bale_send((string)$c['bale_chat_id'], 'فهرستِ قیمتِ همکاریِ شما در ' . setting('biz_name', '') . " به‌روز شد:\n" . $url); $sent = $sent || $r['ok']; }
        if ($sent) $ok++;
    }
    return ['sent' => $ok, 'failed' => $fail];
}
