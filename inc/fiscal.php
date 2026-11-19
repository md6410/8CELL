<?php
// سالِ مالی، مالیات و قفلِ ماه
declare(strict_types=1);

function fiscal_current(?string $d = null): ?array {
    $d = $d ?: today();
    return row("SELECT * FROM fiscal_years WHERE active=1 AND ? BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1", [$d]);
}
function vat_pct(?string $d = null): float { $f = fiscal_current($d); return $f ? (float)$f['vat_pct'] : (float)setting('vat_pct', 10); }
function tax_round_mode(?string $d = null): string { $f = fiscal_current($d); return $f ? $f['rounding'] : (string)setting('tax_round', 'floor'); }
function fiscal_year_save(array $in): int {
    $s = jparse($in['start'] ?? ''); $e = jparse($in['end'] ?? '');
    if (!$s || !$e || $e <= $s) throw new UserError('آغاز و پایانِ سالِ مالی را درست وارد کنید');
    $over = val("SELECT id FROM fiscal_years WHERE active=1 AND id<>? AND NOT (end_date<? OR start_date>?)", [(int)($in['id'] ?? 0), $s, $e]);
    if ($over) throw new UserError('این بازه با سالِ مالیِ دیگری هم‌پوشانی دارد');
    $d = ['title' => trim($in['title'] ?? '') ?: 'سالِ مالی', 'start_date' => $s, 'end_date' => $e, 'vat_pct' => to_float($in['vat_pct'] ?? 10),
        'rounding' => in_array($in['rounding'] ?? '', ['floor', 'round', 'ceil']) ? $in['rounding'] : 'floor', 'currency' => 'IRR'];
    if (!empty($in['id'])) { $old = row("SELECT * FROM fiscal_years WHERE id=?", [(int)$in['id']]); upd('fiscal_years', $d, 'id=?', [(int)$in['id']]); audit('fiscal_years', $in['id'], 'update', $old, $d, 'سالِ مالی'); return (int)$in['id']; }
    $id = ins('fiscal_years', $d); audit('fiscal_years', $id, 'create', null, $d, 'سالِ مالی'); return $id;
}
/** قفلِ ماهِ جلالی «1405-07» */
function fiscal_lock(string $ym, bool $on = true): void {
    $ym = str_replace('/', '-', en_num($ym));
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) throw new UserError('ماه را به‌شکلِ ۱۴۰۵/۰۷ وارد کنید');
    if ($on) { q("INSERT IGNORE INTO fiscal_locks (ym, locked_by, locked_at) VALUES (?,?,?)", [$ym, current_user()['id'] ?? null, now()]); audit('fiscal_locks', $ym, 'lock', null, ['ym' => $ym], 'قفلِ ماه ' . $ym); }
    else { q("DELETE FROM fiscal_locks WHERE ym=?", [$ym]); audit('fiscal_locks', $ym, 'unlock', ['ym' => $ym], null, 'بازکردنِ ماه ' . $ym); }
}
function fiscal_is_locked(string $gdate): bool {
    $ym = str_replace('/', '-', jym($gdate));
    return (bool)val("SELECT 1 FROM fiscal_locks WHERE ym=?", [$ym]);
}
/** هر ثبت/ویرایشِ مالی پیش از کار این را صدا می‌زند */
function fiscal_assert_open(string $gdate): void { if (fiscal_is_locked($gdate)) throw new UserError('این ماه بسته شده است'); }
function fiscal_locks(): array { return rows("SELECT * FROM fiscal_locks ORDER BY ym DESC"); }
