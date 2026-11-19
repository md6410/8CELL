<?php
$sid = req_int('id');
if ($sid && ($s = sale_get($sid))) {
    $sc = whops_order_scanned_units($sid); $ship = json_decode((string)$s['ship_to'], true) ?: [];
    echo ui_card('خروجِ سفارشِ ' . fa_num($s['number']), '<p>' . h(($ship['name'] ?? $s['cname'] ?? '') . ' · ' . fa_num($ship['mobile'] ?? $s['cmobile'] ?? '') . ' · ' . ($ship['address'] ?? '')) . '</p>'
        . ui_table(['title' => 'قلم', 'qty' => ['تعداد', 'num'], 'p' => ['اسکن‌شده', fn($r) => fa_num(count(array_filter($sc, fn($u) => (int)$u['product_id'] === (int)$r['product_id'] && (string)$u['variant_id'] === (string)$r['variant_id'])))]], $s['items'])
        . '<form class="form" data-api="whops_scan" data-then="reload"><input type="hidden" name="sale_id" value="' . $sid . '"><div class="grid">' . ui_field('code', ['اسکنِ دانه', 'text', 1]) . '</div><div class="actions"><button class="btn">ثبت</button></div></form>'
        . '<div class="actions">' . ui_btn_api('بستن و خروج', 'whops_close', ['sale_id' => $sid], ['class' => 'primary', 'confirm' => 'همهٔ اقلام خارج شوند؟', 'then' => 'go:' . page_url('whops')]) . ' ' . ui_btn_api('بازنشانیِ اسکن‌ها', 'whops_reset', ['sale_id' => $sid], ['class' => 'danger', 'prompt' => 'reason:دلیل']) . ' <a class="btn" target="_blank" href="' . h(page_url('labels', ['sale_id' => $sid])) . '">برچسبِ ارسال</a></div>'
        . '<p class="muted">دانه‌ها: <code>' . h(implode(' ', array_column($sc, 'code'))) . '</code></p>');
    echo '<script>document.addEventListener("DOMContentLoaded",function(){var i=document.querySelector("[name=code]");i.focus();});</script>';
}
echo '<form class="filters"><input type="hidden" name="p" value="whops"><label>شمارهٔ سفارش را اسکن کنید<input name="q" autofocus></label><button class="btn">باز کن</button></form>';
if (!empty($_GET['q']) && ($f = whops_find_sale((string)$_GET['q']))) { echo '<script>location.href=' . json_encode(page_url('whops', ['id' => $f['id']])) . '</script>'; }
echo ui_card('صفِ خروج', ui_table(['number' => 'شماره', 'd' => ['تاریخ', 'date'], 'chname' => 'کانال', 'cname' => 'مشتری', 'fulfil' => 'وضعیت', 'total' => ['مبلغ', 'money']], whops_out_queue(), ['href' => fn($r) => page_url('whops', ['id' => $r['id']]), 'empty' => 'صفِ خروج خالی است.']));
