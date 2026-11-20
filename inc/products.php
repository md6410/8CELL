<?php
// کالا، ترکیب، واحد، قیمتِ نهایی، سود، کپی و لینکِ کاتالوگ
declare(strict_types=1);

function wh_item_types(): array { return ['SAL' => 'کالای فروشی', 'CIR' => 'کالای درگردش (امانی/برگشت‌پذیر)', 'REP' => 'قطعه و خدماتِ تعمیر', 'IMP' => 'مصرفیِ داخلی']; }
function wh_site_types(): array { return ['SAL', 'CIR', 'REP']; } // نوع‌هایی که قابلِ فروش‌اند
function product_type_groups(): array { return ['فروش' => ['SAL'], 'درگردش' => ['CIR'], 'خدمات' => ['REP'], 'داخلی' => ['IMP']]; }
function wh_cat_code_valid(string $c): bool { return (bool)preg_match('/^[A-Z]{4}$/', $c); }
function wh_suggest_cat_code(string $name): string {
    $map = ['ا' => 'A', 'آ' => 'A', 'ب' => 'B', 'پ' => 'P', 'ت' => 'T', 'ث' => 'S', 'ج' => 'J', 'چ' => 'C', 'ح' => 'H', 'خ' => 'K', 'د' => 'D', 'ذ' => 'Z', 'ر' => 'R', 'ز' => 'Z', 'ژ' => 'J', 'س' => 'S', 'ش' => 'S',
        'ص' => 'S', 'ض' => 'Z', 'ط' => 'T', 'ظ' => 'Z', 'ع' => 'A', 'غ' => 'G', 'ف' => 'F', 'ق' => 'Q', 'ک' => 'K', 'گ' => 'G', 'ل' => 'L', 'م' => 'M', 'ن' => 'N', 'و' => 'V', 'ه' => 'H', 'ی' => 'Y', 'ي' => 'Y', 'ك' => 'K'];
    $s = strtoupper(preg_replace('/[^A-Za-z]/', '', strtr($name, $map)));
    $s = substr($s . 'XXXX', 0, 4); $base = $s; $i = 1;
    while (val("SELECT id FROM categories WHERE code=?", [$s]) && $i < 676) { $s = substr($base, 0, 2) . chr(65 + intdiv($i, 26)) . chr(65 + $i % 26); $i++; }
    return $s;
}
function unit_of(?int $uid): array { static $c = []; if (!$uid) return ['id' => 0, 'name' => 'عدد', 'scale' => 1, 'sub_name' => '']; return $c[$uid] ??= (row("SELECT * FROM units WHERE id=?", [$uid]) ?: ['id' => 0, 'name' => 'عدد', 'scale' => 1, 'sub_name' => '']); }
/** مرزِ واحد: ورودیِ کاربر (۱٫۵ کیلو) ← عددِ صحیحِ پایه (۱۵۰۰ گرم) */
function qty_in($v, ?int $unitId): int { $u = unit_of($unitId); return (int)round(to_float($v) * (int)$u['scale']); }
function qty_show(int $q, ?int $unitId): string { $u = unit_of($unitId); $s = (int)$u['scale']; return fa_num($s > 1 ? rtrim(rtrim(number_format($q / $s, 3, '.', ''), '0'), '.') : (string)$q) . ' ' . $u['name']; }
/** مبلغِ ردیف: تعدادِ پایه × قیمتِ هر واحدِ نمایشی */
function line_amount(int $qtyBase, int $price, ?int $unitId): int { $s = (int)unit_of($unitId)['scale']; return (int)round($qtyBase * $price / max(1, $s)); }

function product_get(int $id): ?array { return row("SELECT p.*, c.code cat_code, c.name cat_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.id=?", [$id]); }
function variant_get(int $id): ?array { return row("SELECT * FROM product_variants WHERE id=?", [$id]); }
function product_variants(int $pid): array { return rows("SELECT * FROM product_variants WHERE product_id=? ORDER BY vcode", [$pid]); }

function product_save(array $in): int {
    $id = (int)($in['id'] ?? 0);
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') throw new UserError('نامِ کالا لازم است');
    $type = strtoupper((string)($in['item_type'] ?? 'SAL')); if (!isset(wh_item_types()[$type])) throw new UserError('نوعِ کالا نامعتبر است');
    $cat = (int)($in['category_id'] ?? 0) ?: (int)val("SELECT id FROM categories ORDER BY id LIMIT 1");
    $d = ['name' => $name, 'category_id' => $cat, 'brand' => trim((string)($in['brand'] ?? '')) ?: null, 'item_type' => $type, 'unit_id' => (int)($in['unit_id'] ?? 0) ?: null,
        'serial' => empty($in['serial']) ? 0 : 1, 'barcode' => trim(en_num((string)($in['barcode'] ?? ''))) ?: null, 'cost_extra' => to_int($in['cost_extra'] ?? 0),
        'seller_share_pct' => to_float($in['seller_share_pct'] ?? 0), 'price_guard_pct' => to_float($in['price_guard_pct'] ?? 0), 'description' => (string)($in['description'] ?? ''),
        'specs' => (string)($in['specs'] ?? ''), 'warranty' => trim((string)($in['warranty'] ?? '')) ?: null, 'weight_g' => to_int($in['weight_g'] ?? 0) ?: null,
        'dims' => trim((string)($in['dims'] ?? '')) ?: null, 'image' => trim((string)($in['image'] ?? '')) ?: null, 'min_stock' => to_int($in['min_stock'] ?? 0), 'active' => empty($in['inactive']) ? 1 : 0];
    $sale = trim((string)($in['sale_price'] ?? ''));
    $d['sale_price'] = $sale === '' ? null : to_int($sale);
    $d['sale_until'] = jparse($in['sale_until'] ?? '') ?: null;
    $price = to_int($in['price'] ?? 0);
    if ($price < 0) throw new UserError('قیمت منفی نمی‌شود');
    return tx(function () use ($id, $d, $price, $in) {
        if ($id) {
            $old = product_get($id); if (!$old) throw new UserError('کالا یافت نشد', 404);
            if ((int)$old['category_id'] !== (int)$d['category_id'] || $old['item_type'] !== $d['item_type']) {
                if (val("SELECT 1 FROM stock_moves WHERE product_id=? LIMIT 1", [$id])) throw new UserError('کدِ خانوادهٔ کالایی که گردشِ انبار دارد عوض نمی‌شود');
                $c = val("SELECT code FROM categories WHERE id=?", [$d['category_id']]);
                $d['base_code'] = code_family_next(code_type_for_item_type($d['item_type']), $c);
                foreach (product_variants($id) as $v) upd('product_variants', ['code' => sku_build($d['base_code'], ['VAR' => $v['vcode']])], 'id=?', [$v['id']]);
            }
            $d['updated_at'] = now();
            upd('products', $d, 'id=?', [$id]);
            [$b, $a] = audit_diff($old, $d); if ($a) audit('products', $id, 'update', $b, $a, 'کالا');
            if ((int)$old['price'] !== $price) product_price_set($id, $price);
            catalog_touch($id);
        } else {
            $d['price'] = $price; $d['created_at'] = now(); $d['link_token'] = substr(bin2hex(random_bytes(8)), 0, 16);
            $id = ins('products', $d);
            $c = val("SELECT code FROM categories WHERE id=?", [$d['category_id']]);
            upd('products', ['base_code' => code_family_next(code_type_for_item_type($d['item_type']), $c)], 'id=?', [$id]);
            audit('products', $id, 'create', null, ['name' => $d['name'], 'price' => $price], 'کالای تازه');
            catalog_touch($id);
        }
        if (isset($in['variants']) && is_array($in['variants'])) foreach ($in['variants'] as $vt) if (trim((string)$vt) !== '') variant_add($id, (string)$vt);
        return $id;
    });
}
/** ترکیب با کدِ محور (VAR.<۲ تا ۶ نویسه>)؛ کدِ خالی ← شمارهٔ بعدی (01، 02…) */
function variant_add(int $pid, string $title, int $delta = 0, ?string $barcode = null, string $vcode = ''): int {
    $p = product_get($pid); if (!$p) throw new UserError('کالا یافت نشد');
    $vc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', en_num($vcode)));
    if ($vc === '') { $n = (int)val("SELECT COUNT(*) FROM product_variants WHERE product_id=?", [$pid]) + 1; do { $vc = sprintf('%02d', $n++); } while (val("SELECT 1 FROM product_variants WHERE product_id=? AND vcode=?", [$pid, $vc])); }
    if (!code_axis_value_valid($vc)) throw new UserError('کدِ ترکیب ۲ تا ۶ حرف/رقمِ لاتین است');
    if (val("SELECT 1 FROM product_variants WHERE product_id=? AND vcode=?", [$pid, $vc])) throw new UserError('این کدِ ترکیب برای این کالا هست');
    $id = ins('product_variants', ['product_id' => $pid, 'vcode' => $vc, 'title' => trim($title), 'code' => sku_build($p['base_code'], ['VAR' => $vc]), 'barcode' => $barcode, 'price_delta' => $delta]);
    audit('product_variants', $id, 'create', null, ['title' => $title], 'ترکیبِ ' . $p['name']);
    return $id;
}
/** تغییرِ قیمت: تاریخچه، ممیزی، نگهبانِ نوسان */
function product_price_set(int $pid, int $new): void {
    $p = row("SELECT id, name, price, price_guard_pct FROM products WHERE id=?", [$pid]);
    $old = (int)$p['price']; if ($old === $new) return;
    upd('products', ['price' => $new, 'updated_at' => now()], 'id=?', [$pid]);
    ins('price_history', ['product_id' => $pid, 'old_price' => $old, 'new_price' => $new, 'at' => now(), 'user_id' => current_user()['id'] ?? null]);
    audit('products', $pid, 'update', ['price' => $old], ['price' => $new], 'قیمت');
    $g = (float)$p['price_guard_pct'];
    if (feature_on('price_guard') && $g > 0 && $old > 0 && abs($new - $old) * 100 / $old > $g) {
        upd('products', ['active' => 0], 'id=?', [$pid]);
        audit('products', $pid, 'guard', ['active' => 1], ['active' => 0], 'نگهبانِ نوسان: کالا غیرفعال شد (تغییرِ بیش از ' . $g . '٪)');
    }
    do_action('price_changed', $pid, $old, $new);
    catalog_touch($pid);
}
function catalog_touch(int $pid): void { ins('catalog_changes', ['product_id' => $pid, 'at' => now()]); }

/** قیمتِ «واقعیِ همین الان» — همهٔ مسیرها از همین تابع */
function final_price(array $p, ?array $v = null, ?string $d = null): array {
    $d = $d ?: today();
    $list = (int)$p['price'];
    $cur = ($p['sale_price'] !== null && (int)$p['sale_price'] > 0 && (!$p['sale_until'] || $p['sale_until'] >= $d)) ? (int)$p['sale_price'] : $list;
    if ($v) { $list += (int)$v['price_delta']; $cur += (int)$v['price_delta']; }
    return ['price' => $cur, 'list' => $list, 'discount' => max(0, $list - $cur)];
}
function retail_final_price(int $pid, ?int $vid = null): int { $p = product_get($pid); return $p ? final_price($p, $vid ? variant_get($vid) : null)['price'] : 0; }
/** سهمِ سود: (قیمت − بها − هزینهٔ جانبی) × درصدِ فروشنده — در لحظهٔ فروش قفل می‌شود */
function profit_split(int $price, int $cost, array $p): array {
    $profit = $price - $cost - (int)$p['cost_extra'];
    $seller = (int)floor(max(0, $profit) * (float)$p['seller_share_pct'] / 100);
    return ['profit' => $profit, 'seller' => $seller, 'store' => $profit - $seller];
}
/** کارتِ کالا — یک جواب برای همه‌جا */
function product_card(int $pid, ?int $vid = null): array {
    $p = product_get($pid); if (!$p) return [];
    $v = $vid ? variant_get($vid) : null; $fp = final_price($p, $v);
    return ['id' => $pid, 'variant_id' => $vid, 'name' => $p['name'] . ($v ? ' — ' . $v['title'] : ''), 'code' => $v ? $v['code'] : $p['base_code'], 'price' => $fp['price'], 'list' => $fp['list'],
        'discount' => $fp['discount'], 'stock' => sellable_stock($pid, $vid), 'unit' => unit_of($p['unit_id'] ? (int)$p['unit_id'] : null)['name'], 'image' => $p['image'], 'warranty' => $p['warranty'], 'active' => (int)$p['active']];
}
/** دوپلیکیت: کالای تازه با هویتِ تازه و همان ترکیب‌ها */
function product_duplicate(int $pid, string $newName = ''): int {
    $p = product_get($pid); if (!$p) throw new UserError('کالا یافت نشد');
    $in = $p; unset($in['id'], $in['base_code']);
    $in['name'] = $newName ?: $p['name'] . ' (کپی)'; $in['inactive'] = $p['active'] ? 0 : 1;
    $in['sale_until'] = $p['sale_until'] ? jdate($p['sale_until'], false, false) : '';
    $in['variants'] = array_column(product_variants($pid), 'title');
    return product_save($in);
}
function product_link(int $pid): string { $t = val("SELECT link_token FROM products WHERE id=?", [$pid]); if (!$t) { $t = substr(bin2hex(random_bytes(8)), 0, 16); upd('products', ['link_token' => $t], 'id=?', [$pid]); } return app_url('index.php?p=catalog&t=' . $t); }
function plink_send_sms(int $pid, string $mobile, string $name = ''): array {
    return sms_send_tpl($mobile, 'catalog_link', ['NAME' => $name ?: 'مشتری', 'LINK' => product_link($pid)], 'notify', ['ref_type' => 'product', 'ref_id' => $pid]);
}
function product_search(string $q, int $limit = 20): array {
    $q = trim(en_num($q)); if ($q === '') return [];
    $like = '%' . $q . '%';
    return rows("SELECT p.id, p.name, p.base_code, p.price, p.sale_price, p.sale_until, p.unit_id, p.active FROM products p WHERE p.active=1 AND (p.name LIKE ? OR p.base_code=? OR p.barcode=? OR p.brand LIKE ?) ORDER BY p.name LIMIT " . (int)$limit, [$like, strtoupper($q), $q, $like]);
}
