<?php
$f = report_filters($_GET); $w = report_where($f);
$q = trim(en_num((string)($_GET['q'] ?? '')));
$extra = $q !== '' ? " AND (s.number LIKE " . db()->quote('%' . $q . '%') . " OR c.name LIKE " . db()->quote('%' . $q . '%') . " OR c.mobile=" . db()->quote(norm_mobile($q)) . ")" : '';
echo ui_date_filter('sales', $f);
$rs = rows("SELECT s.*, c.name cname, u.name seller, COALESCE(ch.name,'حضوری') chn FROM sales s LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN users u ON u.id=s.seller_id LEFT JOIN channels ch ON ch.id=s.channel_id WHERE s.d BETWEEN ? AND ?" . $w[0] . $extra . " ORDER BY s.id DESC LIMIT 500", array_merge([$f['from'], $f['to']], $w[1]));
$fl = ['done' => 'تحویل‌شده', 'pending' => 'در صفِ خروج', 'picking' => 'در حالِ جمع‌آوری', 'shipped' => 'ارسال‌شده', 'hold' => 'نگه‌داری', 'await_pay' => 'منتظرِ تأییدِ واریز'];
echo ui_card('فروش‌ها (' . fa_num(count($rs)) . ')', ui_table(['number' => 'شماره', 'd' => ['تاریخ', 'date'], 'chn' => 'کانال', 'seller' => 'فروشنده', 'cname' => 'مشتری', 'total' => ['جمع', 'money'], 'fulfil' => ['وضعیت', fn($r) => ui_badge($fl[$r['fulfil']] ?? $r['fulfil'])]], $rs, ['filter' => 1, 'href' => fn($r) => page_url('sale', ['id' => $r['id']])]));
