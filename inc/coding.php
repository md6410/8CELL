<?php
// استانداردِ کدگذاریِ کالا — نسخهٔ ۲ (برگرفته از Weby: includes/coding.php)
//   SL-GENR-00042                      خانواده (نوع ۲ حرف - دسته ۴ حرف - شمارهٔ ۵ رقمی)
//   SL-GENR-00042-VAR.RED              ترکیب (SKU)
//   SL-GENR-00042-VAR.RED/OWN.TH01     محموله (فروشنده.انبار)
//   SL-GENR-00042-VAR.RED/OWN.TH01/000003  دانه
// قاعدهٔ اسکنر: فقط «/» را بشمار — ۰ ترکیب/کالا، ۱ محموله، ۲ دانه. بی‌برشِ موضعی.
declare(strict_types=1);

const CODE_LEN_TYPE = 2; const CODE_LEN_CAT = 4; const CODE_LEN_FAMSEQ = 5; const CODE_LEN_SELLER = 4; const CODE_LEN_STORE = 4; const CODE_LEN_UNIT = 6; const CODE_MAX_LEN = 72;

/** نوعِ کالای 8CELL ← حرفِ نوعِ کد */
function code_type_for_item_type(string $t): string { return ['SAL' => 'SL', 'CIR' => 'PL', 'REP' => 'SV', 'IMP' => 'IN'][strtoupper($t)] ?? 'SL'; }
function code_family_regex(): string { return '/^[A-Z]{' . CODE_LEN_TYPE . '}-[A-Z]{' . CODE_LEN_CAT . '}-\d{' . CODE_LEN_FAMSEQ . '}$/'; }
function code_family_valid(string $c): bool { return (bool)preg_match(code_family_regex(), strtoupper(trim($c))); }
function code_family_build(string $type, string $cat, int $seq): string { return strtoupper($type) . '-' . strtoupper($cat) . '-' . str_pad((string)max(1, min($seq, 10 ** CODE_LEN_FAMSEQ - 1)), CODE_LEN_FAMSEQ, '0', STR_PAD_LEFT); }
/** شمارنده از doc_counters (اتمی) — نه از شناسهٔ ردیف و نه ساعت */
function code_family_next(string $type, string $cat): string { return code_family_build($type, $cat, doc_seq_next('FAM:' . strtoupper($type . $cat))); }
function code_axis_value_valid(string $v): bool { return (bool)preg_match('/^[A-Z0-9]{2,6}$/', $v); }
function sku_build(string $family, array $axes = []): string {
    $parts = [strtoupper($family)];
    foreach ($axes as $p => $v) { $v = strtoupper(trim((string)$v)); if (!code_axis_value_valid($v)) throw new UserError('کدِ ترکیب «' . $v . '» نامعتبر است (۲ تا ۶ حرف/رقمِ لاتین)'); $parts[] = strtoupper($p) . '.' . $v; }
    $s = implode('-', $parts); if (strlen($s) > CODE_MAX_LEN) throw new UserError('طولِ کد از سقفِ ' . CODE_MAX_LEN . ' گذشت'); return $s;
}
function own_seller_code(): string { $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)setting('own_seller_code', 'OWN'))); return $c !== '' ? substr($c, 0, CODE_LEN_SELLER) : 'OWN'; }
function seller_code_clean(string $c): string { return substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c)), 0, CODE_LEN_SELLER); }
function lot_code(string $sku, string $seller, string $store): string {
    $seller = seller_code_clean($seller); $store = strtoupper(trim($store));
    if ($seller === '') throw new UserError('کدِ فروشنده نامعتبر است'); if ($store === '' || strlen($store) > CODE_LEN_STORE) throw new UserError('کدِ انبار نامعتبر است');
    return strtoupper($sku) . '/' . $seller . '.' . $store;
}
function unit_code(string $lot, int $seq): string { return $lot . '/' . str_pad((string)$seq, CODE_LEN_UNIT, '0', STR_PAD_LEFT); }
/** ۰ اسلش ← ترکیب/کالا، ۱ ← محموله، ۲ ← دانه */
function code_level(string $code): string { $n = substr_count(trim($code), '/'); return $n <= 0 ? 'variant' : ($n === 1 ? 'lot' : 'unit'); }
function unit_parse(string $code): array {
    $code = strtoupper(trim($code)); $lv = code_level($code); $p = explode('/', $code);
    $o = ['ok' => true, 'level' => $lv, 'sku' => $p[0], 'seller' => '', 'store' => '', 'seq' => 0, 'lot_code' => '', 'unit_code' => ''];
    if ($lv === 'variant') return $o;
    $seg = $p[1] ?? ''; $dot = strpos($seg, '.');
    if ($dot === false || $dot === 0 || $dot === strlen($seg) - 1) return ['ok' => false, 'level' => $lv];
    $o['seller'] = substr($seg, 0, $dot); $o['store'] = substr($seg, $dot + 1); $o['lot_code'] = $p[0] . '/' . $seg;
    if ($lv === 'unit') { if (!preg_match('/^\d{' . CODE_LEN_UNIT . '}$/', $p[2] ?? '')) return ['ok' => false, 'level' => $lv]; $o['seq'] = (int)$p[2]; $o['unit_code'] = $code; }
    return $o;
}
/** «SL-GENR-00042 — فروشنده OWN، انبار TH01 — دانهٔ ۳» */
function wh_pretty_code(string $code): string {
    $u = unit_parse($code); if (!$u['ok']) return $code;
    return $u['sku'] . ($u['seller'] !== '' ? ' — فروشنده ' . $u['seller'] . '، انبار ' . $u['store'] : '') . ($u['seq'] > 0 ? ' — دانهٔ ' . fa_num($u['seq']) : '');
}
