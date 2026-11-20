<?php /* تکهٔ قالب: نوارِ بالای صفحه ($title، $user): جست‌وجوی سراسری، زنگولهٔ اعلان‌ها، حسابِ من. */ $pn = $user ? pn_unseen_count() : 0; ?>
<header class="top"><button class="btn icon menu-t" type="button" aria-label="منو">☰</button><h1><?= h($title) ?></h1>
<form class="gsearch" method="get" action="index.php"><input type="hidden" name="p" value="search"><input name="q" placeholder="جست‌وجو: مشتری، کالا، فروش، کدِ دانه…" value="<?= h((string)($_GET['p'] ?? '') === 'search' ? (string)($_GET['q'] ?? '') : '') ?>"></form>
<a class="bell" href="<?= h(page_url('notices')) ?>" title="اعلان‌ها">🔔<?= $pn ? '<b>' . fa_num((string)min(99, $pn)) . '</b>' : '' ?></a>
<div class="who"><?= h($user['name'] ?? '') ?> · <a href="<?= h(page_url('me')) ?>">حسابِ من</a> · <a href="#" data-act="logout" data-args="{}" data-then="go:index.php">خروج</a></div></header>
