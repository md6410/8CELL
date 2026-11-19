<?php
// دفترِ ممیزی: چه کسی، کی، کدام رکورد، پیش و پس. ثبت هرگز عملیات را متوقف نمی‌کند.
declare(strict_types=1);

function audit(string $tbl, $id, string $action, $before = null, $after = null, string $label = ''): void {
    try {
        $u = current_user();
        $summary = audit_summary($u['name'] ?? 'سیستم', $label ?: $tbl, $before, $after);
        ins('audit', ['at' => now(), 'user_id' => $u['id'] ?? null, 'user_name' => $u['name'] ?? 'سیستم', 'tbl' => $tbl, 'rec_id' => $id === null ? null : (string)$id,
            'action' => $action, 'summary' => mb_cut($summary, 500),
            'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE), 'ip' => client_ip()]);
    } catch (Throwable $e) { error_log('audit failed: ' . $e->getMessage()); }
}
/** «علی · قیمت · 100000 → 120000» — فقط فیلدهای تغییرکرده */
function audit_summary(string $who, string $label, $before, $after): string {
    $parts = [];
    if (is_array($before) && is_array($after)) {
        foreach ($after as $k => $v) {
            $o = $before[$k] ?? null;
            if (is_array($v) || is_array($o) || (string)$o === (string)$v) continue;
            $parts[] = audit_field_label($k) . ' · ' . mb_cut((string)$o, 60) . ' → ' . mb_cut((string)$v, 60);
        }
    }
    return $who . ' · ' . ($parts ? implode(' ، ', $parts) : $label);
}
function audit_field_label(string $k): string {
    $m = ['price' => 'قیمت', 'sale_price' => 'قیمتِ حراج', 'name' => 'نام', 'qty' => 'تعداد', 'amount' => 'مبلغ', 'status' => 'وضعیت', 'active' => 'فعال',
        'credit_limit' => 'سقفِ نسیه', 'max_discount_pct' => 'سقفِ تخفیف', 'base_salary' => 'حقوقِ پایه', 'mobile' => 'موبایل', 'title' => 'عنوان'];
    return $m[$k] ?? $k;
}
/** تفاوتِ دو ردیف (فقط ستون‌های عوض‌شده) */
function audit_diff(array $before, array $after): array {
    $b = []; $a = [];
    foreach ($after as $k => $v) if (array_key_exists($k, $before) && (string)$before[$k] !== (string)$v) { $b[$k] = $before[$k]; $a[$k] = $v; }
    return [$b, $a];
}
function audit_search(array $f, int $limit = 200): array {
    $w = ['1=1']; $p = [];
    if (!empty($f['tbl'])) { $w[] = 'tbl=?'; $p[] = $f['tbl']; }
    if (!empty($f['user'])) { $w[] = 'user_name LIKE ?'; $p[] = '%' . $f['user'] . '%'; }
    if (!empty($f['q'])) { $w[] = '(summary LIKE ? OR rec_id=?)'; $p[] = '%' . $f['q'] . '%'; $p[] = $f['q']; }
    if (!empty($f['from'])) { $w[] = 'at>=?'; $p[] = $f['from'] . ' 00:00:00'; }
    if (!empty($f['to'])) { $w[] = 'at<=?'; $p[] = $f['to'] . ' 23:59:59'; }
    return rows("SELECT * FROM audit WHERE " . implode(' AND ', $w) . " ORDER BY id DESC LIMIT " . (int)$limit, $p);
}
