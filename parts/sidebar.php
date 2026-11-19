<?php /* تکهٔ قالب: نوارِ کناری. قالب می‌تواند parts/sidebar.php خودش را داشته باشد. */ ?>
<aside class="side"><a class="brand" href="index.php"><?php if (setting('biz_logo')): ?><img src="<?= h(setting('biz_logo')) ?>" alt=""><?php endif; ?><span><?= h(setting('biz_name', '8CELL')) ?></span></a>
<nav><?php foreach (menu_items() as $grp => $items): ?><div class="grp"><b><?= h($grp) ?></b><?php foreach ($items as $k => $t): ?><a href="<?= h(page_url($k)) ?>"<?= $k === $page ? ' class="on"' : '' ?>><?= h($t) ?></a><?php endforeach; ?></div><?php endforeach; ?></nav>
<small class="ver">8CELL <?= h(fa_num(APP_VERSION)) ?></small></aside>
