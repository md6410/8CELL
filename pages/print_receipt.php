<?php $s = sale_get(req_int('id')); if (!$s) exit('یافت نشد'); $b = biz(); ?>
<?php theme_part('receipt', ['s' => $s, 'b' => $b]); ?>
