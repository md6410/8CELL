<?php
// پاک‌سازی و شناساییِ کدِ اسکن‌شده (QR/بارکد) — هر ستون جدا پرسیده می‌شود تا ایندکس کار کند
declare(strict_types=1);

/** «https://x.ir/t?k=ab 12» → «AB12» ؛ «۱۲۳۴» → «1234» */
function scan_normalize(string $raw): string {
    $s = bidi_strip(en_num($raw));
    if ($s === '') return '';
    if (preg_match('#^https?://#i', $s)) {
        $qs = []; parse_str((string)parse_url($s, PHP_URL_QUERY), $qs);
        $pick = null; foreach (['k', 'c', 'code', 't', 'id', 'n'] as $k) if (!empty($qs[$k]) && is_string($qs[$k])) { $pick = $qs[$k]; break; }
        if ($pick === null) { $path = trim((string)parse_url($s, PHP_URL_PATH), '/'); $pick = $path === '' ? '' : basename($path); }
        $s = (string)$pick;
    }
    $s = preg_replace('/\s+/u', '', $s);
    return mb_substr(mb_strtoupper($s), 0, 80);
}
/** نوع + ردیف؛ ترتیبِ اولویت: دانه، محموله، ترکیب، کالا، فروش، فاکتور، مشتری */
function scan_lookup(string $code): array {
    $none = ['type' => 'none', 'row' => null, 'code' => $code, 'extra' => null];
    if ($code === '') return $none;
    $try = function (string $sql, array $p) { try { return row($sql, $p); } catch (Throwable $e) { return null; } };
    $r = null;
    $lv = code_level($code);
    if ($lv === 'unit' && ($r = $try("SELECT u.*, l.product_id, l.variant_id, l.warehouse_id FROM wh_units u JOIN lots l ON l.id=u.lot_id WHERE u.code=?", [$code]))) $res = ['type' => 'unit', 'row' => $r];
    elseif ($lv === 'lot' && ($r = $try("SELECT * FROM lots WHERE code=?", [$code]))) $res = ['type' => 'lot', 'row' => $r];
    elseif ($r = $try("SELECT * FROM product_variants WHERE code=?", [$code])) $res = ['type' => 'variant', 'row' => $r];
    elseif ($r = $try("SELECT * FROM product_variants WHERE barcode=?", [$code])) $res = ['type' => 'variant', 'row' => $r];
    elseif ($r = $try("SELECT * FROM products WHERE base_code=?", [$code])) $res = ['type' => 'product', 'row' => $r];
    elseif ($r = $try("SELECT * FROM products WHERE barcode=?", [$code])) $res = ['type' => 'product', 'row' => $r];
    elseif ($r = $try("SELECT * FROM sales WHERE number=?", [$code])) $res = ['type' => 'sale', 'row' => $r];
    elseif ($r = $try("SELECT * FROM docs WHERE number=? OR verify_token=?", [$code, strtolower($code)])) $res = ['type' => 'doc', 'row' => $r];
    elseif (preg_match('/^09\d{9}$/', $code) && ($r = $try("SELECT * FROM customers WHERE mobile=?", [$code]))) $res = ['type' => 'customer', 'row' => $r];
    else $res = ['type' => 'none', 'row' => null];
    $res += ['code' => $code, 'extra' => null];
    return apply_filters('scan_lookup', $res, $code);
}
function scan_type_label(string $t): string { return ['unit' => 'دانه', 'lot' => 'محموله', 'variant' => 'ترکیبِ کالا', 'product' => 'کالا', 'sale' => 'فروش', 'doc' => 'فاکتور', 'customer' => 'مشتری', 'none' => 'ناشناخته'][$t] ?? $t; }
