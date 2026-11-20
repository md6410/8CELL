<?php
// کمک‌های رابطِ کاربری (سمتِ سرور). فرم‌ها با data-api از راهِ assets/app.js به api.php فرستاده می‌شوند.
declare(strict_types=1);

function page_url(string $p, array $q = []): string { return 'index.php?' . http_build_query(['p' => $p] + $q); }
function ui_money($n): string { return '<span class="num">' . fa_money($n) . '</span>'; }
function ui_badge(string $t, string $k = ''): string { return '<span class="badge ' . h($k) . '">' . h($t) . '</span>'; }
/** فیلدِ فرم: [label, type, required, options] */
function ui_field(string $name, array $f, $value = null): string {
    [$label, $type] = [$f[0], $f[1] ?? 'text']; $req = !empty($f[2]) ? ' required' : '';
    $opts = $f[3] ?? []; if ($opts instanceof Closure) $opts = $opts();
    $id = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
    $v = $value;
    switch ($type) {
        case 'hidden': return '<input type="hidden" name="' . h($name) . '" value="' . h($v) . '">';
        case 'textarea': $in = '<textarea id="' . $id . '" name="' . h($name) . '" rows="3"' . $req . '>' . h($v) . '</textarea>'; break;
        case 'select': $in = '<select id="' . $id . '" name="' . h($name) . '"' . $req . '>' . (!$req ? '<option value="">—</option>' : ''); foreach ($opts as $k => $t) $in .= '<option value="' . h($k) . '"' . ((string)$k === (string)$v ? ' selected' : '') . '>' . h($t) . '</option>'; $in .= '</select>'; break;
        case 'multi': $in = '<div class="checks">'; $vals = array_map('strval', (array)$v); foreach ($opts as $k => $t) $in .= '<label class="ck"><input type="checkbox" name="' . h($name) . '[]" value="' . h($k) . '"' . (in_array((string)$k, $vals, true) ? ' checked' : '') . '> ' . h($t) . '</label>'; $in .= '</div>'; break;
        case 'check': return '<label class="ck field"><input type="checkbox" name="' . h($name) . '" value="1"' . ($v ? ' checked' : '') . '> ' . h($label) . '</label>';
        case 'money': $in = '<input id="' . $id . '" class="money" inputmode="numeric" name="' . h($name) . '" value="' . h($v === null || $v === '' ? '' : fa_money($v)) . '"' . $req . '>'; break;
        case 'date': $in = '<input id="' . $id . '" class="jd" name="' . h($name) . '" placeholder="۱۴۰۵/۰۸/۲۸" value="' . h($v ? (preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$v) ? jdate((string)$v) : $v) : '') . '"' . $req . '>'; break;
        case 'color': $in = '<input type="color" id="' . $id . '" name="' . h($name) . '" value="' . h($v ?: '#0f766e') . '">'; break;
        case 'password': $in = '<input type="password" id="' . $id . '" name="' . h($name) . '" autocomplete="new-password"' . $req . '>'; break;
        case 'int': case 'float': $in = '<input id="' . $id . '" inputmode="decimal" name="' . h($name) . '" value="' . h($v === null ? '' : fa_num((string)$v)) . '"' . $req . '>'; break;
        default: $in = '<input id="' . $id . '" name="' . h($name) . '" value="' . h($v) . '"' . $req . (str_contains($type, 'ltr') ? ' dir="ltr"' : '') . '>';
    }
    return '<label class="field" for="' . $id . '"><span>' . h($label) . ($req ? ' *' : '') . '</span>' . $in . '</label>';
}
/** فرمِ API: then = reload | go:URL | none */
function ui_form(string $action, array $fields, array $values = [], array $o = []): string {
    $s = '<form class="form ' . h($o['class'] ?? '') . '" data-api="' . h($action) . '" data-then="' . h($o['then'] ?? 'reload') . '"' . (!empty($o['confirm']) ? ' data-confirm="' . h($o['confirm']) . '"' : '') . (!empty($o['multipart']) ? ' enctype="multipart/form-data"' : '') . '>';
    foreach ($o['hidden'] ?? [] as $k => $v) $s .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    $s .= '<div class="grid">'; foreach ($fields as $n => $f) $s .= ui_field($n, $f, $values[$n] ?? ($f[4] ?? null)); $s .= '</div>';
    $s .= ($o['extra'] ?? '') . '<div class="actions"><button class="btn primary" type="submit">' . h($o['submit'] ?? 'ذخیره') . '</button>' . ($o['after'] ?? '') . '</div></form>';
    return $s;
}
/** دکمهٔ API (مثلاً تأیید/قفل) */
function ui_btn_api(string $label, string $action, array $args, array $o = []): string {
    return '<button type="button" class="btn ' . h($o['class'] ?? '') . '" data-act="' . h($action) . '" data-args="' . h(json_encode($args, JSON_UNESCAPED_UNICODE)) . '"' . (!empty($o['confirm']) ? ' data-confirm="' . h($o['confirm']) . '"' : '') . (!empty($o['prompt']) ? ' data-prompt="' . h($o['prompt']) . '"' : '') . ' data-then="' . h($o['then'] ?? 'reload') . '">' . h($label) . '</button>';
}
/** جدول: cols = [key => [title, type]]؛ type: money | date | datetime | num | html | callable */
function ui_table(array $cols, array $rows, array $o = []): string {
    if (!$rows) return '<p class="empty">' . h($o['empty'] ?? 'موردی نیست.') . '</p>';
    $s = '<div class="tw"><table class="tbl' . (!empty($o['filter']) ? ' filterable' : '') . '"><thead><tr>';
    foreach ($cols as $c) $s .= '<th>' . h(is_array($c) ? $c[0] : $c) . '</th>'; $s .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $s .= '<tr' . (!empty($o['href']) ? ' class="link" data-href="' . h(($o['href'])($r)) . '"' : '') . '>';
        foreach ($cols as $k => $c) {
            $t = is_array($c) ? ($c[1] ?? '') : ''; $v = $r[$k] ?? '';
            if ($t instanceof Closure) $cell = $t($r);
            elseif ($t === 'money') $cell = ui_money((int)$v);
            elseif ($t === 'date') $cell = jdate((string)$v);
            elseif ($t === 'datetime') $cell = jdate((string)$v, true);
            elseif ($t === 'num') $cell = fa_num((string)$v);
            elseif ($t === 'code') $cell = '<code>' . h($v) . '</code>';
            else $cell = h(label_render_display((string)$v));
            $s .= '<td>' . $cell . '</td>';
        }
        $s .= '</tr>';
    }
    return $s . '</tbody></table></div>';
}
function ui_card(string $title, string $body, string $extra = ''): string { return '<section class="card"><header><h2>' . h($title) . '</h2>' . $extra . '</header>' . $body . '</section>'; }
function ui_stat(string $t, $v, bool $money = true): string { return '<div class="stat"><span>' . h($t) . '</span><b>' . ($money ? fa_money($v) : fa_num((string)$v)) . '</b></div>'; }
function ui_tabs(array $tabs, string $cur, string $page, array $q = []): string {
    $s = '<nav class="tabs">'; foreach ($tabs as $k => $t) $s .= '<a href="' . h(page_url($page, $q + ['tab' => $k])) . '"' . ($k === $cur ? ' class="on"' : '') . '>' . h($t) . '</a>'; return $s . '</nav>';
}
function ui_opts(string $sql, string $label = 'name'): array { return array_column(rows($sql), $label, 'id'); }
function ui_date_filter(string $page, array $f, array $extra = []): string {
    $s = '<form class="filters" method="get"><input type="hidden" name="p" value="' . h($page) . '">';
    foreach ($extra as $k => $v) $s .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    return $s . '<label>از <input class="jd" name="from" value="' . h(jdate($f['from'])) . '"></label><label>تا <input class="jd" name="to" value="' . h(jdate($f['to'])) . '"></label><button class="btn">نمایش</button></form>';
}
/** ویرایشگرِ ردیف‌ها (کالا/تعداد/قیمت) برای خرید و فاکتور */
function ui_lines(string $name, array $cols, array $rows = [], bool $productPick = true): string {
    $s = '<div class="lines-ed" data-name="' . h($name) . '" data-cols="' . h(json_encode($cols, JSON_UNESCAPED_UNICODE)) . '" data-rows="' . h(json_encode($rows, JSON_UNESCAPED_UNICODE)) . '" data-pick="' . ($productPick ? 1 : 0) . '"></div>';
    return $s;
}
function ui_layout_start(string $title, string $page): void {
    $u = current_user(); $m = theme_mods();
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' — ' . h(setting('biz_name', '8CELL')) . '</title>';
    echo '<link rel="stylesheet" href="assets/app.css?v=' . APP_VERSION . '"><style>:root{--pri:' . h($m['primary']) . ';--rad:' . (int)$m['radius'] . 'px;--fs:' . (int)$m['font_size'] . 'px}</style>';
    do_action('head');
    echo '</head><body data-page="' . h($page) . '"><div class="app">';
    theme_part('sidebar', ['page' => $page, 'user' => $u]);
    echo '<main>'; theme_part('topbar', ['title' => $title, 'user' => $u, 'page' => $page]); echo '<div class="content">';
}
function ui_layout_end(): void {
    echo '</div>'; theme_part('footer'); echo '</main></div><div id="toast"></div><script>window.CSRF=' . json_encode(csrf_token()) . ';window.WEBY_KEY="c8";window.__scanSound=' . (setting('scan_sound', '1') === '1' ? 'true' : 'false') . ';</script><script src="assets/app.js?v=' . APP_VERSION . '"></script>';
    do_action('footer');
    echo '</body></html>';
}
/** منو فقط موردهای مجاز را نشان می‌دهد */
function menu_items(): array {
    $g = [];
    foreach (pages_all() as $k => [$t, $cap, $grp, $show]) if ($show && ($cap === 'login' || can($cap))) $g[$grp][$k] = $t;
    return apply_filters('admin_menu', $g);
}
