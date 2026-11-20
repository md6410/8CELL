<?php /* تکهٔ قالب: نوارِ بالای صفحه ($title، $user). */ ?>
<header class="top"><button class="btn icon menu-t" type="button" aria-label="منو">☰</button><h1><?= h($title) ?></h1><div class="who"><?= h($user['name'] ?? '') ?> · <a href="<?= h(page_url('me')) ?>">حسابِ من</a> · <a href="#" data-act="logout" data-args="{}" data-then="go:index.php">خروج</a></div></header>
