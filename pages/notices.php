<?php
$rs = pn_latest(100); $seen = (int)(current_user()['notice_seen'] ?? 0);
echo ui_card('اعلان‌ها', '<div class="actions">' . ui_btn_api('همه را دیدم', 'notices_seen', [], ['class' => 'sm']) . '</div>' . ui_table(['created_at' => ['زمان', 'datetime'], 'title' => ['اعلان', fn($r) => ($r['id'] > $seen ? '<b>● </b>' : '') . ($r['link'] ? '<a href="' . h($r['link']) . '">' . h($r['title']) . '</a>' : nl2br(h($r['title'])))]], $rs, ['empty' => 'اعلانی نیست.']));
