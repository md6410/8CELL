<?php
// ضمانتِ فروشگاه: فهرست به تفکیکِ وضعیت، جست‌وجو (کد، هولوگرام، کدِ دانه، شمارهٔ فروش، موبایل)، خرابی‌های باز، نوع‌ها، هولوگرامِ دانه
$st = isset(warranty_statuses()[$_GET['tab'] ?? '']) ? $_GET['tab'] : ''; $q = trim((string)($_GET['q'] ?? '')); $cn = warranty_counts();
echo ui_tabs(['' => 'همه'] + array_combine(array_keys(warranty_statuses()), array_map(fn($k, $t) => $t . ' (' . fa_num((string)$cn[$k]) . ')', array_keys(warranty_statuses()), warranty_statuses())), $st, 'warranty');
echo '<form class="filters"><input type="hidden" name="p" value="warranty"><label>جست‌وجو<input name="q" value="' . h($q) . '" placeholder="کدِ ضمانت، هولوگرام، کدِ دانه، شمارهٔ فروش یا موبایل" class="scan-in" style="min-width:320px"></label><button class="btn">بیاب</button></form>';
$rs = warranty_list($st, $q);
echo ui_card('ضمانت‌ها', ui_table(['code' => ['کد', 'code'], 'product_title' => 'کالا', 'unit_code' => ['دانه', fn($r) => '<small dir="ltr">' . h($r['unit_code']) . '</small>'], 'hologram' => ['هولوگرام', 'code'], 'buyer_name' => ['صاحب', fn($r) => h((string)$r['buyer_name']) . '<br><small>' . h(fa_num((string)$r['buyer_mobile'])) . '</small>'],
    'type_name' => ['نوع', fn($r) => h($r['type_name']) . ' (' . fa_num((string)$r['months']) . ' ماه)'], 'expires_at' => ['انقضا', fn($r) => $r['expires_at'] ? jdate($r['expires_at']) : '—'], 'claims_count' => ['خرابی', 'num'],
    'status' => ['وضعیت', fn($r) => ui_badge(warranty_statuses()[$r['status']] ?? $r['status'], ['active' => 'ok', 'pending' => 'warn', 'cancel' => 'err', 'void' => 'err'][$r['status']] ?? '')]], $rs, ['href' => fn($r) => page_url('warranty_view', ['id' => $r['id']]), 'empty' => 'ضمانتی نیست.']));
$cl = rows("SELECT c.*, w.code, w.product_title, w.buyer_name, w.id wid FROM warranty_claims c JOIN warranty_items w ON w.id=c.warranty_id WHERE c.status='open' ORDER BY c.opened_at");
if ($cl) echo ui_card('خرابی‌های باز', ui_table(['code' => ['ضمانت', 'code'], 'product_title' => 'کالا', 'buyer_name' => 'صاحب', 'problem' => 'مشکل', 'opened_at' => ['پذیرش', 'datetime']], $cl, ['href' => fn($r) => page_url('warranty_view', ['id' => $r['wid']])]));
echo '<div class="cols">';
if (can('warranty.manage') || can('stock.in')) echo ui_card('هولوگرامِ دانه', '<p class="muted">برچسبِ هولوگرام را هنگامِ ورود یا پیش از فروش به دانه بچسبانید؛ هنگامِ فعال‌سازی خودکار روی ضمانت می‌آید.</p>' . ui_form('unit_hologram', ['unit' => ['کدِ دانه (اسکن)', 'ltr', 1], 'hologram' => ['شمارهٔ هولوگرام', 'ltr', 1]], [], ['submit' => 'ثبت', 'then' => 'none']));
if (can('warranty.void')) { $tid = req_int('type'); $t = $tid ? row("SELECT * FROM warranty_types WHERE id=?", [$tid]) : null;
    echo ui_card('نوع‌های ضمانت', ui_table(['name' => 'نام', 'months' => ['ماه', 'num'], 'active' => ['', fn($r) => $r['active'] ? '' : ui_badge('غیرفعال')]], rows("SELECT * FROM warranty_types ORDER BY id"), ['href' => fn($r) => page_url('warranty', ['type' => $r['id']])])
        . ui_form('warranty_type_save', ['name' => ['نام (مثلاً ضمانتِ طلاییِ ۱۸ ماهه)', 'text', 1], 'months' => ['مدت (ماه)', 'int', 1], 'terms' => ['شرایط (روی کارتِ ضمانت)', 'textarea'], 'inactive' => ['غیرفعال', 'check']], $t ? $t + ['inactive' => !$t['active']] : ['months' => 12], ['hidden' => $t ? ['id' => $t['id']] : [], 'submit' => $t ? 'ذخیره' : 'افزودنِ نوع'])
        . '<p class="muted">نوع را در صفحهٔ کالا (کالای دانه‌ای) انتخاب کنید؛ با هر فروش، برای هر دانه یک ضمانتِ «در انتظارِ فعال‌سازی» ساخته می‌شود. تغییرِ مدت روی ضمانت‌های فروخته‌شده اثر ندارد.</p>'); }
echo '</div>';
