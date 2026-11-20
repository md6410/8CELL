<?php
$q = trim((string)($_GET['q'] ?? '')); $rs = global_search($q);
echo '<form class="filters"><input type="hidden" name="p" value="search"><label>جست‌وجو<input name="q" value="' . h($q) . '" autofocus style="min-width:320px"></label><button class="btn">بیاب</button></form>';
if (mb_strlen($q) < 2) { echo '<p class="muted">دست‌کم دو نویسه بنویسید: نام یا موبایلِ مشتری، نام یا بارکدِ کالا، شمارهٔ فروش/فاکتور، کدِ دانه، کدِ ضمانت یا هولوگرام، شمارهٔ چک، نامِ صفحه…</p>'; return; }
if (!$rs) { echo '<p class="empty">چیزی پیدا نشد.</p>'; return; }
if (count($rs) === 1 && count($rs[0]['items']) === 1) { echo '<script>location.replace(' . json_encode($rs[0]['items'][0][2]) . ')</script>'; }
foreach ($rs as $sec) echo ui_card($sec['title'], '<ul class="slist">' . implode('', array_map(fn($i) => '<li><a href="' . h($i[2]) . '">' . h($i[0]) . '</a> <small class="muted">' . h($i[1]) . '</small></li>', $sec['items'])) . '</ul>');
