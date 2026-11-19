<?php $d = doc_get(req_int('id')); if (!$d) exit('یافت نشد'); echo doc_print_page($d);
