<?php /* تکهٔ قالب: رسیدِ ۸۰ میلی‌متریِ صندوق ($s = sale_get، $b = biz()). */ ?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>رسید <?= h($s['number']) ?></title><link rel="stylesheet" href="assets/print.css"><style>@page{size:80mm auto;margin:3mm}</style></head><body class="pp"><div class="rcpt">
<h2><?= h($b['name']) ?></h2><p style="text-align:center"><?= h(fa_num($b['phone'])) ?><br>رسیدِ فروشِ <?= h(fa_num($s['number'])) ?> — <?= jdate($s['at'], true) ?></p>
<table><?php foreach ($s['items'] as $i): ?><tr><td><?= h($i['title']) ?><br><small><?= qty_show((int)$i['qty'], $i['unit_id'] ? (int)$i['unit_id'] : null) ?> × <?= fa_money($i['price']) ?></small></td><td style="text-align:left"><?= fa_money($i['net'] + $i['tax']) ?></td></tr><?php endforeach; ?>
<tr><td>تخفیف</td><td style="text-align:left"><?= fa_money($s['line_disc'] + $s['doc_disc']) ?></td></tr><tr><td>مالیات</td><td style="text-align:left"><?= fa_money($s['tax']) ?></td></tr><tr><td><b>جمع</b></td><td style="text-align:left"><b><?= fa_money($s['total']) ?></b></td></tr>
<?php foreach ($s['receipts'] as $r): ?><tr><td><?= h(pay_methods()[$r['method']] ?? $r['method']) ?></td><td style="text-align:left"><?= fa_money($r['amount']) ?></td></tr><?php endforeach; ?></table>
<p style="text-align:center">فروشنده: <?= h($s['chname'] ?: $s['seller_name']) ?><?= $s['cname'] ? '<br>مشتری: ' . h($s['cname']) : '' ?><br><?= h(setting('doc_footer', '')) ?></p></div>
<script>if(location.search.indexOf('autoprint=1')>0)setTimeout(function(){print()},300);</script></body></html>
