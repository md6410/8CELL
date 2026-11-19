<?php $d = dash_today(); $f = cw_followup(); ?>
<div class="stats"><?php foreach ($d['cards'] as $c) echo ui_stat($c['t'] . ($c['k'] === 'total' ? '' : ''), $c['v'], empty($c['n'])); ?></div>
<p class="muted"><?= h($d['text']) ?></p>
<div class="cols">
<?= ui_card('هشدارها', $d['alerts'] ? '<ul class="alerts">' . implode('', array_map(fn($a) => '<li>' . h($a['t']) . '</li>', $d['alerts'])) . '</ul>' : '<p class="empty">هشداری نیست.</p>') ?>
<?= ui_card('صندوق‌های باز', ui_table(['cb' => 'صندوق', 'un' => 'صندوق‌دار', 'opened_at' => ['از', 'datetime'], 'opening_cash' => ['اولیه', 'money']], $d['shifts'], ['empty' => 'صندوقِ بازی نیست.'])) ?>
<?= ui_card('فروشِ کانال‌ها', ui_table(['name' => 'کانال', 'cnt' => ['تعداد', 'num'], 'total' => ['مبلغ', 'money']], $d['by_channel'], ['empty' => 'امروز فروشِ کانالی نبوده است.'])) ?>
<?= ui_card('پیگیری‌های امروز', ui_table(['name' => 'مشتری', 'mobile' => ['موبایل', 'num'], 'note' => 'یادداشت'], $f['calls'], ['href' => fn($r) => page_url('customer', ['id' => $r['customer_id']]), 'empty' => 'پیگیری‌ای نیست.'])) ?>
</div>
