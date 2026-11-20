<?php
// انبارِ بلاتکلیف: کالاهای تهاترشده در فروش، تا مدیر تصمیم بگیرد
$st = isset(offset_states()[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'pending';
echo ui_tabs(offset_states(), $st, 'offsets');
echo '<p class="muted">کالایی که مشتری به‌جای بخشی از پول داده، به ارزشِ خرید در «کالای تهاتری» نشسته است. «ورود به انبار» موجودی می‌سازد (کالای دانه‌ای: دانه‌های تازه برای برچسب) و «اسقاط» آن را ضرر می‌کند.</p>';
echo ui_card('اقلام — ' . offset_states()[$st], ui_table(['d' => ['تاریخ', 'date'], 'number' => ['فروش', fn($r) => '<a href="' . h(page_url('sale', ['id' => $r['sale_id']])) . '">' . h(fa_num($r['number'])) . '</a>'], 'title' => 'کالا', 'qty' => ['تعداد', 'num'], 'value' => ['ارزش', 'money'], 'note' => 'یادداشت',
    'a' => ['', fn($r) => $r['state'] === 'pending' ? ui_btn_api($r['product_id'] ? 'ورود به انبار' : 'ثبتِ ورود (دستی)', 'offset_decide', ['id' => $r['id'], 'state' => 'stocked'], ['class' => 'sm', 'confirm' => 'وارد انبار شود؟']) . ' ' . ui_btn_api('اسقاط', 'offset_decide', ['id' => $r['id'], 'state' => 'scrapped'], ['class' => 'sm danger', 'prompt' => 'note:علت (اختیاری)']) : '']],
    offsets_limbo($st), ['empty' => 'موردی نیست.']));
