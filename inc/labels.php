<?php
// برچسب: قالب و چیدمان (کشیدن و رها کردن)، مقدارها از یک تابع، صفحهٔ دقیقِ اندازه
declare(strict_types=1);

function label_presets(): array { return [['40×25', 40, 25], ['50×30', 50, 30], ['60×40', 60, 40], ['70×50', 70, 50], ['100×150 (ارسال)', 100, 150], ['A6', 105, 148]]; }
function label_papers(): array { return ['A4' => [210, 297], 'A5' => [148, 210], 'A6' => [105, 148]]; }
function label_paper_name($w, $h): string { foreach (label_papers() as $n => [$pw, $ph]) if (abs($pw - $w) < 1 && abs($ph - $h) < 1) return $n; return fa_num(rtrim(rtrim((string)$w, '0'), '.')) . '×' . fa_num(rtrim(rtrim((string)$h, '0'), '.')) . ' م‌م'; }
function label_kinds(): array { return ['item' => 'کالا / دانه', 'lot' => 'محموله (ورودِ انبار)', 'ship' => 'ارسالِ سفارش', 'shelf' => 'قیمتِ قفسه']; }
function label_el_types(): array { return ['text' => 'متن', 'field' => 'فیلد', 'qr' => 'QR', 'barcode' => 'بارکد', 'logo' => 'لوگو', 'line' => 'خط', 'box' => 'کادر', 'cut' => 'خطِ برش']; }
/** فیلدهای هر نوع */
function label_fields(string $kind): array {
    $common = ['biz_name' => 'نامِ کسب‌وکار', 'biz_phone' => 'تلفنِ کسب‌وکار', 'date' => 'تاریخ'];
    $k = ['item' => ['name' => 'نامِ کالا', 'price' => 'قیمت', 'code' => 'کد', 'code_pretty' => 'کد (خوانا)', 'warranty' => 'گارانتی', 'variant' => 'ترکیب'],
        'lot' => ['name' => 'نامِ کالا', 'code' => 'کدِ محموله', 'code_pretty' => 'کد (خوانا)', 'warehouse' => 'انبار', 'qty' => 'تعداد'],
        'ship' => ['customer' => 'گیرنده', 'mobile' => 'موبایل', 'address' => 'نشانی', 'number' => 'شمارهٔ فروش', 'items' => 'اقلام', 'carrier' => 'حامل', 'code' => 'کد'],
        'shelf' => ['name' => 'نامِ کالا', 'price' => 'قیمت', 'list' => 'قیمتِ قبل', 'code' => 'کد']];
    return ($k[$kind] ?? []) + $common;
}
function label_default_layout(string $kind, float $w, float $h): array {
    if ($kind === 'ship') return [['t' => 'field', 'f' => 'biz_name', 'x' => 4, 'y' => 4, 'w' => $w - 8, 'h' => 8, 'fs' => 4, 'b' => 1],
        ['t' => 'field', 'f' => 'customer', 'x' => 4, 'y' => 16, 'w' => $w - 8, 'h' => 8, 'fs' => 4.5, 'b' => 1], ['t' => 'field', 'f' => 'mobile', 'x' => 4, 'y' => 25, 'w' => $w - 8, 'h' => 6, 'fs' => 3.5],
        ['t' => 'field', 'f' => 'address', 'x' => 4, 'y' => 32, 'w' => $w - 8, 'h' => 30, 'fs' => 3.5], ['t' => 'qr', 'f' => 'code', 'x' => $w / 2 - 20, 'y' => 70, 'w' => 40, 'h' => 40], ['t' => 'field', 'f' => 'number', 'x' => 4, 'y' => 115, 'w' => $w - 8, 'h' => 8, 'fs' => 4, 'al' => 'c']];
    $q = min($h - 4, $w * 0.4);
    return [['t' => 'qr', 'f' => 'code', 'x' => 2, 'y' => 2, 'w' => $q, 'h' => $q], ['t' => 'field', 'f' => 'name', 'x' => $q + 4, 'y' => 2, 'w' => $w - $q - 6, 'h' => $h * 0.45, 'fs' => 2.8, 'b' => 1],
        ['t' => 'field', 'f' => $kind === 'lot' ? 'warehouse' : 'price', 'x' => $q + 4, 'y' => $h * 0.5, 'w' => $w - $q - 6, 'h' => $h * 0.2, 'fs' => 2.8], ['t' => 'field', 'f' => 'code_pretty', 'x' => 2, 'y' => $h - 5, 'w' => $w - 4, 'h' => 4, 'fs' => 1.8, 'mono' => 1]];
}
function labels_seed(): void {
    if ((int)val("SELECT COUNT(*) FROM label_templates")) return;
    foreach ([['برچسبِ کالا ۵۰×۳۰', 'item', 50, 30], ['برچسبِ محموله ۶۰×۴۰', 'lot', 60, 40], ['برچسبِ ارسال ۱۰۰×۱۵۰', 'ship', 100, 150], ['قیمتِ قفسه ۴۰×۲۵', 'shelf', 40, 25]] as [$n, $k, $w, $h])
        ins('label_templates', ['name' => $n, 'kind' => $k, 'w_mm' => $w, 'h_mm' => $h, 'layout' => json_encode(label_default_layout($k, $w, $h)), 'is_default' => 1]);
}
function label_template_save(array $in): int {
    $w = to_float($in['w_mm'] ?? 0); $h = to_float($in['h_mm'] ?? 0);
    if ($w < 10 || $h < 10 || $w > 300 || $h > 400) throw new UserError('اندازهٔ برچسب نامعتبر است');
    $layout = is_string($in['layout'] ?? null) ? json_decode($in['layout'], true) : ($in['layout'] ?? null);
    if (!is_array($layout)) $layout = label_default_layout($in['kind'] ?? 'item', $w, $h);
    $clean = [];
    foreach ($layout as $el) { if (!isset(label_el_types()[$el['t'] ?? ''])) continue; $e = ['t' => $el['t']]; foreach (['x', 'y', 'w', 'h', 'fs'] as $k) $e[$k] = round((float)($el[$k] ?? 0), 1); foreach (['f', 'txt', 'al'] as $k) if (isset($el[$k])) $e[$k] = mb_cut((string)$el[$k], 200); foreach (['b', 'mono'] as $k) if (!empty($el[$k])) $e[$k] = 1; $clean[] = $e; }
    $d = ['name' => trim((string)($in['name'] ?? '')) ?: 'قالب', 'kind' => isset(label_kinds()[$in['kind'] ?? '']) ? $in['kind'] : 'item', 'w_mm' => $w, 'h_mm' => $h, 'layout' => json_encode($clean, JSON_UNESCAPED_UNICODE)];
    $id = (int)($in['id'] ?? 0);
    if ($id) { upd('label_templates', $d, 'id=?', [$id]); audit('label_templates', $id, 'update', null, ['name' => $d['name']], 'قالبِ برچسب'); return $id; }
    return ins('label_templates', $d);
}
function label_template(int $id): ?array { $t = row("SELECT * FROM label_templates WHERE id=?", [$id]); if ($t) $t['layout'] = json_decode($t['layout'], true) ?: []; return $t; }
/** مقدارهای برچسب — یک تابع برای همهٔ چیدمان‌ها */
function label_values(string $kind, array $data): array {
    $b = biz();
    $v = ['biz_name' => $b['name'], 'biz_phone' => $b['phone'], 'date' => jdate(today(), false, false)];
    if ($kind === 'item' || $kind === 'shelf') {
        $p = product_get((int)$data['product_id']); $var = !empty($data['variant_id']) ? variant_get((int)$data['variant_id']) : null; $fp = $p ? final_price($p, $var) : ['price' => 0, 'list' => 0];
        $code = $data['code'] ?? ($var['code'] ?? $p['base_code'] ?? '');
        $v += ['name' => ($p['name'] ?? '') . ($var ? ' ' . $var['title'] : ''), 'variant' => $var['title'] ?? '', 'price' => num_fmt($fp['price']) . ' ریال', 'list' => $fp['list'] > $fp['price'] ? num_fmt($fp['list']) : '',
            'code' => $code, 'code_pretty' => wh_pretty_code($code), 'warranty' => $p['warranty'] ?? ''];
    } elseif ($kind === 'lot') {
        $l = row("SELECT l.*, p.name, w.name wname, v.title vt FROM lots l JOIN products p ON p.id=l.product_id JOIN warehouses w ON w.id=l.warehouse_id LEFT JOIN product_variants v ON v.id=l.variant_id WHERE l.id=?", [(int)$data['lot_id']]);
        $v += ['name' => ($l['name'] ?? '') . (!empty($l['vt']) ? ' ' . $l['vt'] : ''), 'code' => $l['code'] ?? '', 'code_pretty' => wh_pretty_code($l['code'] ?? ''), 'warehouse' => $l['wname'] ?? '', 'qty' => (string)($data['qty'] ?? '')];
    } elseif ($kind === 'ship') {
        $s = row("SELECT s.*, c.name cname, c.mobile cm, c.address ca FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.id=?", [(int)$data['sale_id']]);
        $ship = json_decode((string)($s['ship_to'] ?? ''), true) ?: [];
        $v += ['customer' => $ship['name'] ?? $s['cname'] ?? 'مشتری', 'mobile' => $ship['mobile'] ?? $s['cm'] ?? '', 'address' => $ship['address'] ?? $s['ca'] ?? '', 'number' => $s['number'] ?? '',
            'items' => implode('، ', array_map(fn($i) => $i['title'] . ' ×' . $i['qty'], rows("SELECT title, qty FROM sale_items WHERE sale_id=?", [(int)$data['sale_id']]))), 'carrier' => $ship['carrier'] ?? '', 'code' => $s['number'] ?? ''];
    }
    return apply_filters('label_values', $v, $kind, $data);
}
/** رندرِ متن: ارقامِ متن و فیلد فارسی؛ QR و کدها خام */
function label_render_display(string $s): string { return label_fa_digits($s); }
function label_fa_digits(string $s): string { return preg_replace_callback('/[A-Za-z0-9]*[0-9][A-Za-z0-9]*/', fn($m) => preg_match('/[A-Za-z]/', $m[0]) ? $m[0] : fa_num($m[0]), $s); }
function label_scissors_svg(): string { return '<svg viewBox="0 0 24 24" width="10" height="10"><path d="M9.6 7.2a3 3 0 1 0-1.2 1.6L11 12l-2.6 3.2a3 3 0 1 0 1.2 1.6L12 14l6 7h3L9.6 7.2zM6 8a1 1 0 1 1 0-2 1 1 0 0 1 0 2zm0 10a1 1 0 1 1 0-2 1 1 0 0 1 0 2zm8-8 4-5h3l-5.5 6.5z" fill="#000"/></svg>'; }
function label_el_html(array $el, array $vals): string {
    $st = sprintf('left:%smm;top:%smm;width:%smm;height:%smm;', $el['x'], $el['y'], $el['w'], $el['h']);
    $t = $el['t'];
    if ($t === 'qr' || $t === 'barcode') return '<div class="el qr" style="' . $st . '" data-qr="' . h($vals[$el['f'] ?? 'code'] ?? '') . '"></div>';
    if ($t === 'line') return '<div class="el" style="' . $st . 'border-top:0.3mm solid #000;height:0"></div>';
    if ($t === 'box') return '<div class="el" style="' . $st . 'border:0.3mm solid #000"></div>';
    if ($t === 'cut') return '<div class="el" style="' . $st . 'border-top:0.3mm dashed #000;height:0">' . label_scissors_svg() . '</div>';
    if ($t === 'logo') { $src = setting('biz_logo', ''); return $src ? '<img class="el" style="' . $st . 'object-fit:contain" src="' . h($src) . '">' : ''; }
    $txt = $t === 'field' ? (string)($vals[$el['f'] ?? ''] ?? '') : (string)($el['txt'] ?? '');
    $css = 'font-size:' . (float)($el['fs'] ?: 3) . 'mm;' . (!empty($el['b']) ? 'font-weight:700;' : '') . 'text-align:' . (['c' => 'center', 'l' => 'left', 'r' => 'right'][$el['al'] ?? 'r'] ?? 'right') . ';' . (!empty($el['mono']) ? 'font-family:monospace;direction:ltr;' : '');
    $show = !empty($el['mono']) ? $txt : label_render_display($txt);
    return '<div class="el txt" style="' . $st . $css . '">' . h($show) . '</div>';
}
/** صفحهٔ چاپ: @page = اندازهٔ برچسب، مارژینِ صفر، یک برچسب در هر صفحه */
function label_page_html(array $tpl, array $valsList): string {
    $w = (float)$tpl['w_mm']; $h = (float)$tpl['h_mm'];
    $o = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>برچسب</title><style>@font-face{font-family:V;src:url(assets/fonts/Vazirmatn.woff2) format("woff2")}'
        . "@page{size:{$w}mm {$h}mm;margin:0}html,body{margin:0;padding:0}body{font-family:V,Tahoma,sans-serif}.lb{position:relative;width:{$w}mm;height:{$h}mm;overflow:hidden;page-break-after:always;break-after:page}"
        . '.el{position:absolute;box-sizing:border-box;overflow:hidden;line-height:1.25}.qr svg{width:100%;height:100%}</style></head><body>';
    foreach ($valsList as $vals) { $o .= '<div class="lb">'; foreach ($tpl['layout'] as $el) $o .= label_el_html($el, $vals); $o .= '</div>'; }
    $o .= '<script src="assets/vendor/qrcode.js"></script><script>document.querySelectorAll(".qr").forEach(function(e){var t=e.getAttribute("data-qr");if(!t)return;var q=qrcode(0,"M");q.addData(t);q.make();e.innerHTML=q.createSvgTag({margin:0,scalable:true});});'
        . 'window.__labelReady=true;if(location.search.indexOf("autoprint=1")>0)setTimeout(function(){window.print()},300);</script></body></html>';
    return $o;
}
