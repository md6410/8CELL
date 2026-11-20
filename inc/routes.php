<?php
// نقشهٔ مرکزیِ صفحه/عملیات ← cap (منو و API از همین‌جا). صفحهٔ تازه بی‌cap باز نمی‌شود.
declare(strict_types=1);

/** صفحه‌ها: key => [عنوان، cap، گروهِ منو، نمایش در منو] */
function pages_all(): array {
    return apply_filters('pages', [
        'dashboard' => ['داشبوردِ امروز', 'reports.dashboard', 'گزارش', 1],
        'pos' => ['صندوقِ فروش', 'pos.use', 'فروش', 1], 'shifts' => ['شیفت‌ها', 'pos.use', 'فروش', 1], 'sales' => ['فروش‌ها', 'docs.view', 'فروش', 1], 'sale' => ['فروش', 'docs.view', 'فروش', 0],
        'docs' => ['فاکتورها', 'docs.view', 'فروش', 1], 'doc' => ['سند', 'docs.view', 'فروش', 0], 'partners' => ['همکارانِ فروش', 'partner.manage', 'فروش', 1], 'payreqs' => ['تأییدِ واریزها', 'bank.manage', 'مالی', 1], 'warranty' => ['ضمانتِ فروشگاه', 'warranty.manage|warranty.void', 'فروش', 1], 'warranty_view' => ['پروندهٔ ضمانت', 'warranty.manage|warranty.void', 'فروش', 0], 'offsets' => ['انبارِ بلاتکلیف (تهاتر)', 'offset.decide', 'کالا و انبار', 1], 'seller' => ['پنلِ فروشنده', 'seller.portal', 'پنلِ فروشنده', 1], 'backup' => ['پشتیبان و ابزارِ داده', 'backup.manage', 'تنظیمات', 1], 'notices' => ['اعلان‌ها', 'login', 'تنظیمات', 0], 'search' => ['جست‌وجو', 'login', 'تنظیمات', 0], 'tickets' => ['تیکت‌ها', 'login', 'ارتباط', 1], 'letters' => ['نامه‌های اداری', 'letters.manage', 'ارتباط', 1], 'credit' => ['حساب‌های نسیه', 'customers.view', 'مالی', 1], 'cheques' => ['مدیریتِ چک', 'cheques.manage|bank.manage', 'مالی', 1],
        'shipments' => ['ارسال و پیک', 'ship.manage', 'فروش', 1], 'partner_prices' => ['قیمتِ همکاری (گریدها)', 'partner.manage', 'فروش', 1], 'trends' => ['روندِ فروش و سود', 'reports.profit', 'گزارش', 1], 'notify' => ['هشدارها و بله', 'login', 'تنظیمات', 1], 'payreq_file' => ['فیش', 'docs.view', 'فروش', 0], 'channels' => ['کانال‌های فروش', 'channels.manage', 'فروش', 1],
        'products' => ['کالاها', 'products.view', 'کالا و انبار', 1], 'product' => ['کالا', 'products.view', 'کالا و انبار', 0], 'stock' => ['موجودی و ورود/خروج', 'stock.view', 'کالا و انبار', 1],
        'whops' => ['خروجِ سفارش‌ها', 'stock.out', 'کالا و انبار', 1], 'losses' => ['ضرر و عودت', 'stock.loss', 'کالا و انبار', 1], 'labels' => ['برچسب', 'labels.print', 'کالا و انبار', 1], 'print' => ['چاپگرِ مرکزی', 'print.manage', 'کالا و انبار', 1],
        'purchase' => ['خرید', 'purchase.view', 'خرید', 1], 'suppliers' => ['تأمین‌کنندگان', 'purchase.view', 'خرید', 1],
        'customers' => ['مشتریان', 'customers.view', 'مشتریان', 1], 'customer' => ['پروندهٔ مشتری', 'customers.view', 'مشتریان', 0], 'followup' => ['پیگیری‌های امروز', 'customers.view', 'مشتریان', 1],
        'staff' => ['حساب و پورسانتِ کارکنان', 'staff.ledger', 'کارکنان', 1], 'perf' => ['عملکردِ کارکنان', 'perf.view', 'کارکنان', 1], 'hr' => ['پروندهٔ کارکنان', 'hr.view', 'کارکنان', 1],
        'employee' => ['کارمند', 'hr.view', 'کارکنان', 0], 'attendance' => ['حضور و غیاب', 'hr.attendance', 'کارکنان', 1], 'payroll' => ['حقوق و فیش', 'hr.payroll', 'کارکنان', 1],
        'ledger' => ['دفترِ روزنامه و تراز', 'ledger.view', 'مالی', 1], 'accounts' => ['سرفصل‌ها', 'ledger.view', 'مالی', 1], 'gl' => ['دفترِ کل و اشخاص', 'ledger.view', 'مالی', 1],
        'banks' => ['بانک، صندوق و چک', 'bank.manage', 'مالی', 1], 'accshare' => ['واریز و سهمِ شرکا', 'acc.share', 'مالی', 1],
        'reports' => ['گزارش‌ساز', 'reports.sales', 'گزارش', 1], 'pl' => ['سود و زیان', 'reports.profit', 'گزارش', 1],
        'settings' => ['تنظیمات', 'settings.view', 'تنظیمات', 1], 'crud' => ['اطلاعاتِ پایه', 'settings.view', 'تنظیمات', 1], 'calendar' => ['تقویم و نرخ', 'settings.view', 'تنظیمات', 1],
        'users' => ['کاربران و نقش‌ها', 'users.view', 'تنظیمات', 1], 'sms' => ['پیامک', 'sms.manage', 'تنظیمات', 1], 'ext' => ['ماژول‌ها و قالب', 'ext.manage', 'تنظیمات', 1],
        'update' => ['به‌روزرسانی', 'update.manage', 'تنظیمات', 1], 'audit' => ['دفترِ ممیزی', 'audit.view', 'تنظیمات', 1], 'me' => ['حسابِ من', 'login', 'تنظیمات', 0],
    ]);
}
/** API: action => [cap ('login' = فقط واردشده، 'public' = بی‌ورود)، تابع، روش] */
function api_routes(): array {
    $r = [
        'me' => ['public', fn($in) => api_me(), 'GET'],
        'time' => ['public', fn($in) => tehran_now(to_int($in['client_ms'] ?? 0) ?: null), 'GET'],
        'login' => ['public', fn($in) => api_user_public(login_password((string)($in['username'] ?? ''), (string)($in['password'] ?? ''))), 'POST'],
        'logout' => ['login', function ($in) { logout(); return ['ok' => true]; }, 'POST'],
        'otp_request' => ['public', fn($in) => feature_on('otp_login') ? otp_login_request((string)($in['mobile'] ?? ''), (string)($in['captcha'] ?? '')) : throw new UserError('ورود با پیامک خاموش است'), 'POST'],
        'otp_verify' => ['public', fn($in) => api_user_public(otp_login_verify((string)($in['mobile'] ?? ''), (string)($in['code'] ?? ''))), 'POST'],
        'otp_confirm' => ['login', fn($in) => otp_generate((string)(current_user()['mobile'] ?? ''), 'confirm'), 'POST'],
        'pk_login_options' => ['public', fn($in) => pk_login_options(), 'POST'],
        'pk_login' => ['public', fn($in) => api_user_public(pk_login_verify($in)), 'POST'],
        'pk_reg_options' => ['login', fn($in) => pk_reg_options(), 'POST'],
        'pk_register' => ['login', fn($in) => ['id' => pk_reg_verify($in)], 'POST'],
        'pk_remove' => ['login', function ($in) { df_dev_remove((int)$in['id']); return ['ok' => true]; }, 'POST'],
        'password_change' => ['login', fn($in) => api_password_change($in), 'POST'],
        // تنظیمات
        'biz_save' => ['settings.edit', function ($in) { biz_profile_save($in); return ['ok' => true]; }, 'POST'],
        'settings_save' => ['settings.edit', fn($in) => api_settings_save($in), 'POST'],
        'fiscal_save' => ['fiscal.manage', fn($in) => ['id' => fiscal_year_save($in)], 'POST'],
        'fiscal_lock' => ['fiscal.manage', function ($in) { fiscal_lock((string)$in['ym'], !empty($in['on'])); return ['ok' => true]; }, 'POST'],
        'crud_save' => ['login', fn($in) => ['id' => crud_save((string)$in['_e'], $in)], 'POST'],
        'crud_delete' => ['login', function ($in) { crud_delete((string)$in['_e'], (int)$in['id']); return ['ok' => true]; }, 'POST'],
        'holidays_import' => ['settings.edit', fn($in) => ['n' => holidays_import(parse_holiday_text((string)($in['text'] ?? '')))], 'POST'],
        'holidays_fetch' => ['settings.edit', fn($in) => ['n' => holidays_fetch(to_int($in['year'] ?? substr(jtoday(), 0, 4)))], 'POST'],
        'holiday_check' => ['login', fn($in) => shift_off(jparse($in['d'] ?? '') ?: today(), $in['rule'] ?? null) + holiday_info(jparse($in['d'] ?? '') ?: today()), 'GET'],
        'rate_manual' => ['settings.edit', fn($in) => ['id' => rate_manual(to_int($in['rate'] ?? 0))], 'POST'],
        'rate_fetch' => ['settings.edit', fn($in) => rate_fetch(), 'POST'],
        'rate_today' => ['login', fn($in) => rate_today() ?: ['rate' => 0], 'GET'],
        'ext_toggle' => ['ext.manage', function ($in) { ext_set((string)$in['id'], !empty($in['on'])); return ['ok' => true]; }, 'POST'],
        'ext_upload' => ['ext.manage', fn($in) => api_ext_upload(), 'POST'],
        'feature_toggle' => ['ext.manage', function ($in) { if (!isset(features_builtin()[$in['key'] ?? ''])) throw new UserError('امکانِ ناشناخته'); settings_save(['feature_' . $in['key'] => !empty($in['on']) ? '1' : '0']); return ['ok' => true]; }, 'POST'],
        'theme_set' => ['ext.manage', function ($in) { settings_save(['theme' => (string)($in['id'] ?? '')]); if (isset($in['primary'])) theme_mods_save($in); return ['ok' => true]; }, 'POST'],
        'theme_reset' => ['ext.manage', function ($in) { theme_mods_reset(); return ['ok' => true]; }, 'POST'],
        'update_check' => ['update.manage', fn($in) => df_upd_run(false, false, 'admin'), 'POST'],
        'update_run' => ['update.manage', fn($in) => df_upd_run(!empty($in['force']), true, (string)(current_user()['username'] ?? 'admin')), 'POST'],
        // کاربران
        'user_save' => ['users.edit', fn($in) => ['id' => user_save($in)], 'POST'],
        'role_save' => ['roles.edit', fn($in) => ['id' => role_save($in)], 'POST'],
        // پیامک
        'sms_tpl_save' => ['sms.manage', function ($in) { $t = []; foreach (sms_tpl_catalog() as $k => $c) $t[$k] = ['id' => preg_replace('/\D/', '', en_num((string)($in['tpl'][$k] ?? ''))), 'on' => empty($in['off'][$k]) ? 1 : 0]; settings_save(['sms_templates' => $t]); return ['ok' => true]; }, 'POST'],
        'sms_test' => ['sms.manage', fn($in) => sms_send_tpl((string)$in['mobile'], (string)$in['key'], (array)($in['params'] ?? []), 'test'), 'POST'],
        'sms_retry' => ['sms.manage', fn($in) => sms_retry((int)$in['id']), 'POST'],
        // کالا
        'product_save' => ['products.edit', fn($in) => ['id' => product_save($in)], 'POST'],
        'product_price' => ['prices.edit', function ($in) { product_price_set((int)$in['id'], to_int($in['price']), 'manual', !empty($in['confirm'])); return ['ok' => true]; }, 'POST'],
        'variant_add' => ['products.edit', fn($in) => ['id' => variant_add((int)$in['product_id'], (string)$in['title'], to_int($in['price_delta'] ?? 0), trim((string)($in['barcode'] ?? '')) ?: null, (string)($in['vcode'] ?? ''))], 'POST'],
        'product_duplicate' => ['products.edit', fn($in) => ['id' => product_duplicate((int)$in['id'], (string)($in['name'] ?? ''))], 'POST'],
        'product_link_sms' => ['products.view', fn($in) => plink_send_sms((int)$in['id'], (string)$in['mobile'], (string)($in['name'] ?? '')), 'POST'],
        'product_search' => ['login', fn($in) => array_map(fn($p) => product_card((int)$p['id']) + ['variants' => product_variants((int)$p['id'])], product_search((string)($in['q'] ?? ''))), 'GET'],
        'product_card' => ['login', fn($in) => product_card((int)$in['id'], (int)($in['variant_id'] ?? 0) ?: null), 'GET'],
        'grade_price_save' => ['partner.manage', function ($in) { partner_grade_price_save((int)$in['product_id'], (int)$in['grade_id'], $in['single'] ?? 0, $in['max'] ?? 0, $in['max_qty'] ?? 1); return ['ok' => true]; }, 'POST'],
        // انبار
        'stock_in' => ['stock.in', fn($in) => wh_stock_in(scan_normalize((string)$in['code']), max(1, to_int($in['qty'] ?? 1)), ['note' => $in['note'] ?? null]), 'POST'],
        'stock_out' => ['stock.out', fn($in) => api_stock_out($in), 'POST'],
        'lot_create' => ['stock.in', fn($in) => api_lot_create($in), 'POST'],
        'units_add' => ['stock.in', fn($in) => ['codes' => wh_units_add((int)$in['lot_id'], to_int($in['qty']))], 'POST'],
        'stock_loss' => ['stock.loss', fn($in) => ['id' => stock_loss($in)], 'POST'],
        'whops_scan' => ['stock.out', fn($in) => whops_order_scan_item((int)$in['sale_id'], (string)$in['code']), 'POST'],
        'whops_close' => ['stock.out', fn($in) => whops_order_close((int)$in['sale_id']), 'POST'],
        'whops_reset' => ['stock.out', function ($in) { whops_order_reset((int)$in['sale_id'], (string)($in['reason'] ?? '')); return ['ok' => true]; }, 'POST'],
        'scan_lookup' => ['login', fn($in) => api_scan_lookup((string)($in['code'] ?? '')), 'GET'],
        // برچسب و چاپ
        'label_tpl_save' => ['labels.design', fn($in) => ['id' => label_template_save($in)], 'POST'],
        'print_station_create' => ['print.manage', fn($in) => print_station_create((string)($in['name'] ?? '')), 'POST'],
        'print_job' => ['labels.print', fn($in) => print_job_create($in), 'POST'],
        'print_jobs' => ['labels.print', fn($in) => array_map('print_job_public', rows("SELECT * FROM print_jobs ORDER BY id DESC LIMIT 30")), 'GET'],
        // خرید
        'supplier_save' => ['purchase.edit', fn($in) => ['id' => supplier_save($in)], 'POST'],
        'po_save' => ['purchase.edit', fn($in) => ['id' => po_save($in)], 'POST'],
        'po_confirm' => ['purchase.edit', function ($in) { po_confirm((int)$in['id']); return ['ok' => true]; }, 'POST'],
        'goods_receipt' => ['stock.in', fn($in) => goods_receipt($in), 'POST'],
        'purchase_invoice' => ['purchase.edit', fn($in) => purchase_invoice_commit($in), 'POST'],
        'supplier_pay' => ['supplier.pay', fn($in) => ['id' => supplier_pay($in)], 'POST'],
        // صندوق
        'shift_open' => ['pos.use', fn($in) => ['id' => shift_open((int)$in['cashbox_id'], to_int($in['opening'] ?? 0))], 'POST'],
        'shift_close' => ['pos.use', fn($in) => shift_close((int)$in['shift_id'], to_int($in['counted'] ?? 0), $in), 'POST'],
        'cart_open' => ['pos.use', fn($in) => cart_view((int)cart_open()['id']), 'POST'],
        'cart_view' => ['pos.use', fn($in) => cart_view((int)$in['cart_id']), 'GET'],
        'pos_scan' => ['pos.use', fn($in) => pos_scan((int)$in['cart_id'], (string)$in['code'], max(1, to_int($in['qty'] ?? 1))) + cart_view((int)$in['cart_id']), 'POST'],
        'cart_add' => ['pos.use', fn($in) => api_cart_add($in), 'POST'],
        'cart_qty' => ['pos.use', function ($in) { cart_set_qty((int)$in['item_id'], $in['qty']); return cart_view((int)$in['cart_id']); }, 'POST'],
        'cart_remove' => ['pos.use', function ($in) { cart_remove((int)$in['item_id']); return cart_view((int)$in['cart_id']); }, 'POST'],
        'cart_disc' => ['pos.discount', function ($in) { pos_line_discount((int)$in['item_id'], $in['disc'] ?? 0, (string)($in['note'] ?? '')); return cart_view((int)$in['cart_id']); }, 'POST'],
        'cart_adjust' => ['pos.use', fn($in) => cart_adjust((int)$in['cart_id'], $in['pct'] ?? 0), 'POST'],
        'cart_doc_disc' => ['pos.discount', fn($in) => api_cart_doc_disc($in), 'POST'],
        'cart_customer' => ['pos.use', function ($in) { cart_set_customer((int)$in['cart_id'], (int)($in['customer_id'] ?? 0) ?: null); return cart_view((int)$in['cart_id']); }, 'POST'],
        'pos_checkout' => ['pos.use', fn($in) => pos_checkout((int)$in['cart_id'], (array)($in['payments'] ?? []), $in), 'POST'],
        'sale_return' => ['pos.return', fn($in) => ['id' => return_commit((int)$in['sale_id'], (array)($in['items'] ?? []), (string)($in['reason'] ?? ''), (string)($in['method'] ?? 'cash'))], 'POST'],
        'sale_doc' => ['docs.edit', fn($in) => ['id' => doc_from_sale((int)$in['sale_id'], (string)($in['kind'] ?? 'invoice'))], 'POST'],
        'payreq_create' => ['pos.use|partner.manage', fn($in) => ['id' => payreq_create((int)$in['sale_id'], $in)], 'POST'],
        'payreq_files' => ['pos.use|partner.manage|bank.manage', fn($in) => ['n' => payreq_add_files((int)$in['id'], $_FILES['receipt'] ?? []), 'msg' => 'فیش ثبت شد'], 'POST'],
        'payreq_approve' => ['bank.manage', fn($in) => ['receipt_id' => payreq_approve((int)$in['id'], (string)($in['note'] ?? ''))], 'POST'],
        'payreq_reject' => ['bank.manage', function ($in) { payreq_reject((int)$in['id'], (string)($in['note'] ?? '')); return ['ok' => true]; }, 'POST'],
        'ptr_save' => ['partner.manage', function ($in) { ptr_save((int)$in['customer_id'], !empty($in['on']), (int)($in['acc'] ?? 0)); return ['ok' => true]; }, 'POST'],
        'ptr_check' => ['pos.use', fn($in) => ['xfer' => ptr_ready((int)($in['customer_id'] ?? 0))]],
        'phold_release' => ['partner.manage', function ($in) { phold_release((int)$in['sale_id']); return ['ok' => true]; }, 'POST'],
        // فاکتور
        'doc_save' => ['docs.edit', fn($in) => ['id' => doc_save($in)], 'POST'],
        'doc_calc' => ['docs.edit', fn($in) => api_doc_calc($in), 'POST'],
        'doc_commit' => ['docs.issue', fn($in) => doc_commit((int)$in['id']), 'POST'],
        'doc_amend' => ['docs.issue', fn($in) => ['revision' => doc_amend((int)$in['id'], $in)], 'POST'],
        'doc_restore' => ['docs.issue', fn($in) => ['revision' => doc_restore((int)$in['id'], (int)$in['rev'])], 'POST'],
        'doc_void' => ['docs.issue', function ($in) { doc_void((int)$in['id'], (string)($in['reason'] ?? '')); return ['ok' => true]; }, 'POST'],
        'doc_convert' => ['docs.edit', fn($in) => ['id' => doc_convert((int)$in['id'])], 'POST'],
        'doc_clone' => ['docs.edit', fn($in) => ['id' => doc_clone((int)$in['id'], isset(doc_kinds()[$in['kind'] ?? '']) ? ['kind' => $in['kind']] : [])], 'POST'],
        'doc_official' => ['docs.edit', function ($in) { doc_make_official((int)$in['id']); return ['ok' => true]; }, 'POST'],
        'moadian_send' => ['docs.issue', fn($in) => ['ref' => moadian_send((int)$in['id']), 'msg' => 'به سامانه فرستاده شد؛ چند دقیقه بعد «استعلام» بزنید'], 'POST'],
        'moadian_inquiry' => ['docs.issue', fn($in) => ['state' => $st = moadian_inquiry((int)$in['id']), 'msg' => moadian_states()[$st]], 'POST'],
        'moadian_preview' => ['docs.issue', function ($in) { [$j, $e] = moadian_invoice(doc_get((int)$in['id']) ?: throw new UserError('سند یافت نشد')); return ['errors' => $e, 'json' => $j]; }],
        'doc_moadian' => ['docs.issue', function ($in) { doc_set_moadian((int)$in['id'], (string)$in['state'], (string)($in['tax_id'] ?? '')); return ['ok' => true]; }, 'POST'],
        'doc_credit' => ['docs.issue', fn($in) => ['id' => doc_credit_create((int)$in['id'], (array)($in['q'] ?? []), (string)($in['reason'] ?? ''), (string)($in['note'] ?? ''))], 'POST'],
        'doc_contact' => ['docs.edit', function ($in) { $m = norm_mobile($in['mobile'] ?? ''); if ($m !== '' && !mobile_ok($m)) throw new UserError('موبایل معتبر نیست'); upd('docs', ['contact_name' => trim((string)($in['name'] ?? '')) ?: null, 'contact_mobile' => $m ?: null], 'id=?', [(int)$in['id']]); audit('docs', $in['id'], 'contact', null, ['mobile' => $m], 'رابطِ اصالت'); return ['ok' => true]; }, 'POST'],
        'doc_share' => ['docs.view', fn($in) => doc_share((int)$in['id'], to_int($in['days'] ?? 30), to_int($in['max'] ?? 0), (string)($in['pin'] ?? '')), 'POST'],
        'doc_share_revoke' => ['docs.view', function ($in) { doc_share_revoke((int)$in['id']); return ['ok' => true]; }, 'POST'],
        'doc_share_sms' => ['docs.view', fn($in) => doc_share_send_sms((int)$in['id'], (string)$in['mobile']), 'POST'],
        'doc_issues' => ['docs.view', fn($in) => official_issues(doc_get((int)$in['id']) ?? ['lines' => []]), 'GET'],
        'doc_confirm' => ['docs.issue', function ($in) { acc_confirm((int)$in['id'], (string)($in['note'] ?? '')); return ['ok' => true]; }, 'POST'],
        'doc_pay' => ['acc.share', fn($in) => ['id' => doc_pay_add((int)$in['id'], to_int($in['amount']), jparse($in['d'] ?? ''), (string)($in['ref'] ?? ''))], 'POST'],
        'acc_settle' => ['acc.share', fn($in) => ['id' => acc_settle((int)$in['partner_id'], to_int($in['amount']), jparse($in['d'] ?? ''), (string)($in['note'] ?? ''))], 'POST'],
        // کانال‌ها (مدیریت)
        'channel_save' => ['channels.manage', fn($in) => ['id' => channel_save($in)], 'POST'],
        'channel_rotate' => ['channels.manage', fn($in) => ['secret' => channel_rotate_secret((int)$in['id'])], 'POST'],
        'channel_settle' => ['channels.manage', fn($in) => ['id' => channel_settle((int)$in['id'], to_int($in['amount']), (int)($in['bank_account_id'] ?? 0), (string)($in['note'] ?? ''))], 'POST'],
        // API کانال (HMAC، بدونِ نشست) — مسیرِ ثابت برای ماژولِ فروشگاه
        'channel_catalog' => ['public', fn($in) => channel_catalog(channel_auth('channel_catalog', ''), !empty($in['since']) ? (string)$in['since'] : null), 'GET'],
        'channel_order' => ['public', fn($in) => channel_order_commit(channel_auth('channel_order', (string)file_get_contents('php://input')), $in), 'POST'],
        'channel_status' => ['public', fn($in) => api_channel_status($in), 'GET'],
        // مشتری
        'customer_save' => ['customers.edit', fn($in) => ['id' => customer_save($in)], 'POST'],
        'customer_quick' => ['customers.edit', fn($in) => ['id' => customer_find_or_create((string)$in['mobile'], (string)($in['name'] ?? ''))], 'POST'],
        'customer_search' => ['customers.view', fn($in) => customer_search((string)($in['q'] ?? '')), 'GET'],
        'customer_person' => ['customers.edit', fn($in) => ['id' => customer_person_add((int)$in['customer_id'], (string)$in['name'], (string)($in['role'] ?? ''), (string)($in['mobile'] ?? ''))], 'POST'],
        'customer_merge' => ['customers.edit', function ($in) { customers_merge((int)$in['keep'], (int)$in['drop']); return ['ok' => true]; }, 'POST'],
        'customer_pay' => ['customers.credit', fn($in) => ['id' => customer_pay((int)$in['customer_id'], to_int($in['amount']), (string)($in['method'] ?? 'cash'), (string)($in['target'] ?? 'credit'), (int)($in['bank_account_id'] ?? 0), (string)($in['note'] ?? ''))], 'POST'],
        'call_add' => ['customers.edit', fn($in) => ['id' => call_add($in)], 'POST'],
        // کارکنان
        'stacc_add' => ['staff.ledger', fn($in) => ['id' => stacc_add((int)$in['user_id'], to_int($in['amount']), (string)$in['kind'], 'manual', null, (string)($in['note'] ?? ''))], 'POST'],
        'stacc_handover' => ['staff.ledger', fn($in) => ['id' => stacc_handover((int)$in['user_id'], to_int($in['amount']), (int)$in['bank_account_id'], (string)($in['note'] ?? ''))], 'POST'],
        'stacc_block' => ['staff.ledger', function ($in) { stacc_block((int)$in['user_id'], !empty($in['on'])); return ['ok' => true]; }, 'POST'],
        'commission_rule_save' => ['commission.manage', fn($in) => ['id' => commission_rule_save($in)], 'POST'],
        'target_set' => ['perf.view', function ($in) { staff_target_set((int)$in['user_id'], (string)$in['ym'], to_int($in['target'])); return ['ok' => true]; }, 'POST'],
        'employee_save' => ['hr.edit', fn($in) => ['id' => employee_save($in)], 'POST'],
        'contract_save' => ['hr.edit', fn($in) => ['id' => contract_save($in)], 'POST'],
        'contract_sign' => ['hr.edit', fn($in) => contract_sign((int)$in['id'], (string)($in['method'] ?? 'manual'), (string)($in['otp'] ?? '')), 'POST'],
        'punch' => ['hr.attendance', fn($in) => attendance_punch((int)$in['employee_id'], !empty($in['at']) ? (jparse($in['at']) . ' ' . substr(en_num((string)($in['time'] ?? '00:00')), 0, 5) . ':00') : null, !empty($in['at']) ? 'manual' : 'card'), 'POST'],
        'leave_request' => ['hr.attendance', fn($in) => ['id' => leave_request($in)], 'POST'],
        'payslip_calc' => ['hr.payroll', fn($in) => ['id' => payslip_save((int)$in['employee_id'], (string)$in['ym'], $in)], 'POST'],
        'payslip_post' => ['hr.payroll', function ($in) { payslip_post((int)$in['id']); return ['ok' => true]; }, 'POST'],
        'payslip_pay' => ['hr.payroll', function ($in) { payslip_pay((int)$in['id'], (int)$in['bank_account_id']); return ['ok' => true]; }, 'POST'],
        // مالی
        'account_save' => ['ledger.post', fn($in) => ['id' => account_save($in)], 'POST'],
        'journal_manual' => ['ledger.post', fn($in) => ['id' => journal_manual($in)], 'POST'],
        'bank_save' => ['bank.manage', fn($in) => ['id' => bank_account_save($in)], 'POST'],
        'promise_save' => ['customers.credit', fn($in) => ['id' => promise_save($in)], 'POST'],
        'promise_set' => ['customers.credit', function ($in) { promise_set((int)$in['id'], (string)$in['status']); return ['ok' => true]; }, 'POST'],
        'cheque_receive' => ['cheques.manage|customers.credit', fn($in) => ['id' => cheque_receive($in), 'msg' => 'چک ثبت و از بدهی کم شد'], 'POST'],
        'cheque_reverse' => ['cheques.manage', function ($in) { cheque_reverse((int)$in['id'], ($in['status'] ?? '') === 'returned' ? 'returned' : 'bounced', (string)($in['note'] ?? '')); return ['ok' => true]; }, 'POST'],
        'notify_run' => ['notify.alerts|settings.edit', fn($in) => notify_run() + ['msg' => 'یادآوری‌ها اجرا شد'], 'POST'],
        'bale_link' => ['login', function ($in) { $t = ($in['kind'] ?? '') === 'customer' ? 'customers' : 'users'; $id = $t === 'users' ? (int)current_user()['id'] : (int)$in['id']; if ($t === 'customers') require_cap('customers.edit'); $c = bale_link_code($t, $id); return ['code' => $c, 'url' => bale_link_url($c), 'msg' => 'کدِ ' . $c . ' را برای باتِ بله بفرستید' . (bale_link_url($c) ? ': ' . bale_link_url($c) : '')]; }, 'POST'],
        'bale_unlink' => ['login', function ($in) { $t = ($in['kind'] ?? '') === 'customer' ? 'customers' : 'users'; $id = $t === 'users' ? (int)current_user()['id'] : (int)$in['id']; if ($t === 'customers') require_cap('customers.edit'); upd($t, ['bale_chat_id' => null], 'id=?', [$id]); return ['ok' => true]; }, 'POST'],
        'bale_test' => ['login', function ($in) { $u = current_user(); if (empty($u['bale_chat_id'])) throw new UserError('هنوز به بله وصل نشده‌اید'); $r = bale_send((string)$u['bale_chat_id'], '✅ آزمایشِ هشدارِ ' . setting('biz_name', '')); if (!$r['ok']) throw new UserError($r['msg']); return ['msg' => 'پیام رفت']; }, 'POST'],
        'ship_save' => ['ship.manage', fn($in) => ['id' => ship_save($in), 'msg' => 'ارسال ثبت شد'], 'POST'],
        'ship_status' => ['ship.manage', function ($in) { ship_status((int)$in['id'], (string)$in['status']); return ['ok' => true]; }, 'POST'],
        'ship_sms' => ['ship.manage', function ($in) { $r = ship_sms((int)$in['id']); if (!$r['ok']) throw new UserError($r['msg']); return ['msg' => 'پیامک رفت']; }, 'POST'],
        'courier_save' => ['ship.manage', fn($in) => ['id' => courier_save($in)], 'POST'],
        'addr_link' => ['ship.manage|customers.edit|pos.use', fn($in) => addr_link_make($in), 'POST'],
        'address_save' => ['customers.edit|ship.manage', fn($in) => ['id' => address_save((int)$in['customer_id'], $in)], 'POST'],
        'address_delete' => ['customers.edit', function ($in) { q("DELETE FROM customer_addresses WHERE id=? AND customer_id=?", [(int)$in['id'], (int)$in['customer_id']]); return ['ok' => true]; }, 'POST'],
        'addresses' => ['pos.use|ship.manage|customers.view', fn($in) => array_map(fn($a) => ['id' => (int)$a['id'], 'text' => address_text($a), 'receiver' => $a['receiver_name'], 'mobile' => $a['receiver_mobile'], 'postal' => $a['postal_code']], addresses_of((int)$in['customer_id'])), 'GET'],
        'partner_prices_save' => ['partner.manage', fn($in) => ['n' => partner_prices_save_bulk((array)($in['one'] ?? []), (array)($in['max'] ?? []), (array)($in['qty'] ?? [])), 'msg' => 'ذخیره شد'], 'POST'],
        'partner_list_send' => ['partner.manage', function ($in) { $ids = !empty($in['grade_id']) ? array_column(rows("SELECT id FROM customers WHERE is_partner=1 AND partner_grade_id=?", [(int)$in['grade_id']]), 'id') : [(int)$in['customer_id']];
            $r = partner_list_send($ids, !empty($in['sms']) || !isset($in['sms']), !empty($in['bale']) || !isset($in['bale'])); return ['msg' => fa_num((string)$r['sent']) . ' همکار' . ($r['failed'] ? ' — ' . implode('؛ ', $r['failed']) : '')]; }, 'POST'],
        'partner_list_link' => ['partner.manage', fn($in) => ['url' => plist_url((int)$in['customer_id'])], 'POST'],
        'warranty_type_save' => ['warranty.void', fn($in) => ['id' => warranty_type_save($in)], 'POST'],
        'warranty_otp_send' => ['warranty.manage', fn($in) => warranty_otp_send((int)$in['id'], (string)($in['mobile'] ?? ''), (string)($in['name'] ?? '')), 'POST'],
        'warranty_activate' => ['warranty.manage', fn($in) => warranty_activate((int)$in['id'], $in) + ['msg' => 'ضمانت فعال شد'], 'POST'],
        'warranty_void' => ['warranty.void', function ($in) { warranty_void((int)$in['id'], (string)($in['note'] ?? '')); return ['ok' => true]; }, 'POST'],
        'warranty_cancel_reject' => ['warranty.void', function ($in) { warranty_cancel_reject((int)$in['id']); return ['ok' => true]; }, 'POST'],
        'warranty_claim_open' => ['warranty.manage', fn($in) => ['id' => warranty_claim_open((int)$in['id'], (string)($in['problem'] ?? ''))], 'POST'],
        'warranty_claim_close' => ['warranty.manage', function ($in) { warranty_claim_close((int)$in['id'], (string)($in['action'] ?? ''), to_int($in['cost'] ?? 0), (string)($in['method'] ?? 'cash'), (int)($in['bank_account_id'] ?? 0)); return ['ok' => true]; }, 'POST'],
        'unit_hologram' => ['warranty.manage|stock.in', function ($in) { unit_set_hologram((string)$in['unit'], (string)$in['hologram']); return ['msg' => 'هولوگرام ثبت شد']; }, 'POST'],
        'sale_amend' => ['pos.amend|shift.manage', fn($in) => sale_amend((int)$in['sale_id'], $in), 'POST'],
        'offset_decide' => ['offset.decide', fn($in) => offset_decide((int)$in['id'], (string)$in['state'], (string)($in['note'] ?? '')), 'POST'],
        'offset_cost' => ['pos.offset', fn($in) => ['cost' => offset_unit_cost((int)$in['product_id'], (int)($in['variant_id'] ?? 0) ?: null)], 'GET'],
        'seller_invite' => ['seller.invite', fn($in) => seller_invite((int)$in['supplier_id'], !empty($in['sms'])), 'POST'],
        'seller_toggle' => ['seller.invite', function ($in) { q("UPDATE users SET active=1-active WHERE id=? AND kind='seller'", [(int)$in['id']]); return ['ok' => true]; }, 'POST'],
        'seller_pay_confirm' => ['seller.portal', function ($in) { seller_pay_confirm((int)$in['id'], (string)($in['note'] ?? '')); return ['msg' => 'تأیید شد']; }, 'POST'],
        'seller_profile_save' => ['seller.portal', function ($in) { seller_profile_save($in); return ['msg' => 'ذخیره شد']; }, 'POST'],
        'seller_contract_accept' => ['seller.portal', function ($in) { seller_contract_accept(); return ['ok' => true]; }, 'POST'],
        'backup_start' => ['backup.manage', fn($in) => bk_start((string)($in['type'] ?? 'db')), 'POST'],
        'backup_step' => ['backup.manage', fn($in) => array_diff_key(bk_step(10), ['tables' => 1, 'files' => 1]), 'POST'],
        'backup_unlock' => ['backup.manage', function ($in) { bk_reauth((string)($in['pass'] ?? '')); $_SESSION['bk_ok'] = time(); return ['msg' => 'تا ۱۰ دقیقه دانلود باز است']; }, 'POST'],
        'backup_restore' => ['backup.manage', function ($in) { bk_unlocked(); return array_diff_key(bk_start_restore((string)$in['name'], !empty($in['with_files'])), ['tables' => 1]); }, 'POST'],
        'backup_delete' => ['backup.manage', function ($in) { bk_unlocked(); @unlink(bk_dir() . '/' . bk_safe((string)$in['name'])); audit('backups', (string)$in['name'], 'delete', null, null, 'حذفِ پشتیبان'); return ['ok' => true]; }, 'POST'],
        'backup_upload' => ['backup.manage', fn($in) => ['name' => bk_accept_upload($_FILES['file'] ?? []), 'msg' => 'بارگذاری شد'], 'POST'],
        'ds_import' => ['backup.manage', fn($in) => ds_import((string)$in['set'], (string)($_FILES['file']['tmp_name'] ?? ''), !empty($in['dry'])), 'POST'],
        'notices_seen' => ['login', function ($in) { pn_mark_seen(); return ['ok' => true]; }, 'POST'],
        'notices_count' => ['login', fn($in) => ['n' => pn_unseen_count()], 'GET'],
        'ticket_open' => ['login', fn($in) => ['id' => ticket_open($in, $_FILES['file'] ?? []), 'msg' => 'تیکت ثبت شد'], 'POST'],
        'ticket_reply' => ['login', function ($in) { ticket_reply((int)$in['id'], $in, $_FILES['file'] ?? []); return ['ok' => true]; }, 'POST'],
        'ticket_close' => ['login', function ($in) { ticket_close((int)$in['id']); return ['ok' => true]; }, 'POST'],
        'letter_save' => ['letters.manage', fn($in) => ['id' => letter_save($in)], 'POST'],
        'letter_final' => ['letters.manage', fn($in) => ['no' => letter_finalize((int)$in['id']), 'msg' => 'شماره خورد'], 'POST'],
        'letter_delete' => ['letters.manage', function ($in) { if (val("SELECT status FROM letters WHERE id=?", [(int)$in['id']]) !== 'draft') throw new UserError('نامهٔ نهایی حذف نمی‌شود'); q("DELETE FROM letters WHERE id=?", [(int)$in['id']]); return ['ok' => true]; }, 'POST'],
        'cheque_save' => ['bank.manage', fn($in) => ['id' => cheque_save($in)], 'POST'],
        'cheque_status' => ['cheques.manage|bank.manage', function ($in) { cheque_status((int)$in['id'], (string)$in['status'], (int)($in['bank_account_id'] ?? 0)); return ['ok' => true]; }, 'POST'],
        'bank_reconcile' => ['bank.manage', fn($in) => bank_reconcile((int)$in['bank_account_id'], jparse($in['d'] ?? '') ?: today(), to_int($in['statement'] ?? 0), (string)($in['note'] ?? '')), 'POST'],
        'expense' => ['bank.manage', fn($in) => ['id' => expense_record($in)], 'POST'],
        // گزارش
        'dash' => ['reports.dashboard', fn($in) => dash_today(), 'GET'],
    ];
    return apply_filters('api_routes', $r);
}
function api_user_public(?array $u): array { return $u ? ['id' => (int)$u['id'], 'name' => $u['name'], 'username' => $u['username'], 'caps' => $u['caps'], 'is_super' => (bool)$u['is_super']] : []; }
function api_me(): array { $u = current_user(); return ['csrf' => csrf_token(), 'version' => APP_VERSION, 'user' => $u ? api_user_public($u) : null, 'time' => tehran_now()]; }
function api_password_change(array $in): array {
    $u = require_login(); $h = (string)val("SELECT pass_hash FROM users WHERE id=?", [$u['id']]);
    if ($h && !password_verify((string)($in['old'] ?? ''), $h)) throw new UserError('رمزِ فعلی نادرست است');
    if (mb_strlen((string)($in['new'] ?? '')) < 6) throw new UserError('رمزِ تازه دست‌کم ۶ نویسه');
    upd('users', ['pass_hash' => password_hash((string)$in['new'], PASSWORD_DEFAULT)], 'id=?', [$u['id']]); audit('users', $u['id'], 'password', null, null, 'تغییرِ رمز');
    return ['ok' => true];
}
/** فقط کلیدهای مجاز ذخیره می‌شوند */
function api_settings_save(array $in): array {
    $allowed = ['vat_pct', 'tax_round', 'total_round', 'fx_round', 'doc_template', 'doc_color', 'doc_font_size', 'doc_pat_invoice', 'doc_pat_proforma', 'doc_pat_official', 'doc_pat_credit', 'doc_pad', 'doc_start', 'doc_reset_yearly', 'proforma_valid_days', 'proforma_gap_days', 'doc_total_round', 'doc_total_round_dir', 'doc_tax_round', 'doc_show_seller', 'doc_show_bank', 'doc_show_bank_pf', 'doc_show_stamp', 'doc_show_sig', 'doc_show_watermark', 'doc_show_validity', 'doc_show_words', 'doc_digits', 'invoice_title', 'proforma_title', 'off_title', 'doc_header_text', 'vf_on', 'vf_name', 'vf_mobile', 'doc_default_notes', 'doc_default_terms', 'doc_vat_on', 'biz_memory_id', 'biz_branch_code', 'biz_province', 'biz_city', 'biz_fax', 'biz_email', 'biz_website', 'sms_test_mode', 'sms_retry_max', 'smsir_api_key', 'update_repo', 'update_branch', 'update_auto', 'update_interval', 'update_token', 'base_url',
        'reserve_minutes', 'staff_debt_pct', 'staff_debt_cap', 'shortage_to_staff', 'holiday_rule', 'thursday_off', 'holiday_url', 'fx_nav_key', 'fx_nav_unit', 'fx_max_gap', 'scan_sound', 'captcha_after', 'sess_ttl_min',
        'pos_require_unit', 'loss_sms_confirm', 'own_seller_code', 'sms_otp_ttl', 'sms_otp_cooldown', 'sms_otp_domain', 'sms_retry_gap', 'channel_price_tolerance', 'hr_rules', 'labor_rates', 'ticket_depts', 'letter_no_start', 'letter_no_format', 'letter_tagline', 'letter_signer', 'backup_auto', 'backup_auto_type', 'backup_keep', 'seller_contract_text', 'pos_amend_hours', 'warranty_holo_required', 'adj_round', 'credit_remind_days', 'cheque_remind_days', 'stock_cover_days', 'notify_sms', 'notify_bale', 'notify_stock', 'bale_bot_token', 'bale_bot_username', 'moadian_on', 'moadian_base', 'moadian_cert', 'moadian_secret_key', 'moadian_units', 'moadian_sstid_default'];
    $s = [];
    foreach ($allowed as $k) if (array_key_exists($k, $in)) { if (in_array($k, ['smsir_api_key', 'update_token', 'moadian_secret_key', 'bale_bot_token'], true) && $in[$k] === '') continue;
        if ($k === 'moadian_secret_key' && !openssl_pkey_get_private((string)$in[$k])) throw new UserError('کلیدِ خصوصیِ سامانهٔ مودیان معتبر نیست (PEM)');
        if ($k === 'moadian_cert' && trim((string)$in[$k]) !== '' && !openssl_x509_read(moadian_pem((string)$in[$k], 'CERTIFICATE'))) throw new UserError('گواهیِ سامانهٔ مودیان معتبر نیست'); $s[$k] = is_array($in[$k]) ? $in[$k] : trim((string)$in[$k]); }
    if (isset($s['hr_rules'])) $s['hr_rules'] = array_map(fn($v) => is_numeric(en_num((string)$v)) ? to_float($v) : $v, $s['hr_rules']);
    if (isset($s['labor_rates'])) $s['labor_rates'] = array_map(fn($v) => to_float($v), $s['labor_rates']);
    settings_save($s);
    return ['ok' => true];
}
function api_ext_upload(): array {
    if (empty($_FILES['zip']['tmp_name']) || !is_uploaded_file($_FILES['zip']['tmp_name'])) throw new UserError('فایلِ زیپ انتخاب نشده است');
    return ext_install_zip($_FILES['zip']['tmp_name'], ($_POST['kind'] ?? 'modules') === 'themes' ? 'themes' : 'modules');
}
function api_stock_out(array $in): array {
    $code = scan_normalize((string)$in['code']); $qty = max(1, to_int($in['qty'] ?? 1));
    $sel = code_level($code) === 'unit' ? ['unit_code' => $code] : ['lot_id' => (int)val("SELECT id FROM lots WHERE code=?", [$code])];
    if (empty($sel['unit_code']) && empty($sel['lot_id'])) throw new UserError('این کد تعریف نشده است');
    return ['out' => wh_stock_out($sel, $qty, ['kind' => 'out', 'note' => $in['note'] ?? 'خروجِ دستی', 'ref_type' => 'manual'])];
}
function api_lot_create(array $in): array {
    $lot = wh_lot_ensure((int)$in['product_id'], (int)($in['variant_id'] ?? 0) ?: null, (int)$in['warehouse_id'], (string)($in['seller_code'] ?? ''), ($in['ownership'] ?? 'O') === 'C' ? 'C' : 'O', (int)($in['supplier_id'] ?? 0) ?: null);
    $out = ['lot' => $lot];
    if (to_int($in['qty'] ?? 0) > 0) {
        $serial = (int)val("SELECT serial FROM products WHERE id=?", [$lot['product_id']]);
        if ($serial) { wh_lot_cost_in($lot, 0, to_int($in['unit_cost'] ?? 0)); $out['codes'] = wh_units_add((int)$lot['id'], to_int($in['qty']), ['unit_cost' => to_int($in['unit_cost'] ?? 0)]); }
        else $out['in'] = wh_stock_in($lot['code'], to_int($in['qty']), ['unit_cost' => to_int($in['unit_cost'] ?? 0)]);
    }
    return $out;
}
function api_scan_lookup(string $raw): array {
    $c = scan_normalize($raw); $r = scan_lookup($c);
    $o = ['type' => $r['type'], 'label' => scan_type_label($r['type']), 'code' => $c];
    if (in_array($r['type'], ['unit', 'lot', 'variant', 'product'], true)) {
        $pid = $r['type'] === 'product' ? (int)$r['row']['id'] : (int)$r['row']['product_id']; $vid = $r['type'] === 'variant' ? (int)$r['row']['id'] : ($r['type'] === 'product' ? null : ($r['row']['variant_id'] ?? null));
        $o['card'] = product_card($pid, $vid ? (int)$vid : null);
        if ($r['type'] === 'unit') $o['status'] = wh_unit_status_label($r['row']['status']);
    } elseif ($r['row']) $o['row'] = ['id' => $r['row']['id'], 'name' => $r['row']['name'] ?? ($r['row']['number'] ?? '')];
    return $o;
}
function api_cart_add(array $in): array {
    $cid = (int)$in['cart_id']; $pid = (int)$in['product_id']; $vid = (int)($in['variant_id'] ?? 0) ?: null;
    $code = $vid ? (string)val("SELECT code FROM product_variants WHERE id=?", [$vid]) : (string)val("SELECT base_code FROM products WHERE id=?", [$pid]);
    return pos_scan($cid, $code, max(1, to_int($in['qty'] ?? 1))) + cart_view($cid);
}
function api_cart_doc_disc(array $in): array {
    $c = cart_get((int)$in['cart_id']); $v = cart_view((int)$c['id']);
    $s = trim(en_num((string)($in['disc'] ?? '0'))); $after = (int)$v['totals']['sub'] - (int)$v['totals']['lineDisc'];
    $disc = str_ends_with($s, '%') ? (int)floor($after * to_float($s) / 100) : to_int($s);
    discount_assert((int)$v['totals']['sub'], (int)$v['totals']['lineDisc'] + $disc);
    upd('carts', ['doc_disc' => max(0, $disc)], 'id=?', [$c['id']]);
    return cart_view((int)$c['id']);
}
function api_doc_calc(array $in): array {
    $cur = in_array($in['currency'] ?? 'IRR', ['IRR', 'IRT', 'USD'], true) ? ($in['currency'] ?? 'IRR') : 'IRR'; $kind = (string)($in['kind'] ?? 'invoice');
    $lines = doc_lines_in((array)($in['lines'] ?? []), $cur); if (!$lines) return ['total' => 0];
    $o = ['doc_disc' => to_float($in['doc_disc'] ?? 0), 'doc_disc_type' => ($in['doc_disc_type'] ?? '') === '%' ? '%' : 'amount', 'vat_on' => empty($in['vat_on']) ? 0 : 1, 'vat_pct' => isset($in['vat_pct']) && $in['vat_pct'] !== '' ? to_float($in['vat_pct']) : vat_pct(), 'extra' => to_float($in['extra'] ?? 0)];
    $c = doc_calc(['kind' => $kind, 'status' => 'draft', 'currency' => $cur, 'd' => jparse($in['d'] ?? '') ?: today(), 'lines' => $lines, 'opts' => $o]);
    $f = fn($n) => doc_money($n, $cur); return array_diff_key($c, ['lines' => 1, 'off' => 1]) + ['fmt' => ['sub' => $f($c['sub']), 'disc' => $f($c['lineDisc'] + $c['docDisc']), 'vat' => $f($c['vat']), 'total' => $f($c['total']), 'adj' => $f($c['roundAdj'])], 'number' => peek_seq($kind, jparse($in['d'] ?? '') ?: today())];
}
function api_channel_status(array $in): array {
    $c = channel_auth('channel_status', '');
    $o = row("SELECT co.*, s.number, s.fulfil, s.status FROM channel_orders co LEFT JOIN sales s ON s.id=co.sale_id WHERE co.channel_id=? AND co.ext_id=?", [$c['id'], (string)($in['ext_id'] ?? '')]);
    if (!$o) throw new UserError('سفارش یافت نشد', 404);
    return ['ext_id' => $o['ext_id'], 'sale_id' => (int)$o['sale_id'], 'number' => $o['number'], 'fulfil' => $o['fulfil'], 'returned' => (int)val("SELECT COUNT(*) FROM sale_returns WHERE sale_id=?", [$o['sale_id']]) > 0];
}

/** اطلاعاتِ پایه (CRUD عمومی) */
function crud_defs(): array {
    $wh = fn() => array_column(rows("SELECT id, name FROM warehouses WHERE active=1"), 'name', 'id');
    $br = fn() => array_column(rows("SELECT id, name FROM branches WHERE active=1"), 'name', 'id');
    $bk = fn() => ['' => '—'] + array_column(rows("SELECT id, title FROM bank_accounts WHERE active=1"), 'title', 'id');
    return apply_filters('crud_defs', [
        'branches' => ['شعبه‌ها', 'branches', 'settings.edit', ['name' => ['نام', 'text', 1], 'address' => ['نشانی', 'text'], 'phone' => ['تلفن', 'text'], 'default_wh_id' => ['انبارِ پیش‌فرض', 'select', 0, $wh], 'active' => ['فعال', 'check']]],
        'warehouses' => ['انبارها', 'warehouses', 'settings.edit', ['name' => ['نام', 'text', 1], 'branch_id' => ['شعبه', 'select', 0, $br], 'province' => ['استان', 'select', 1, fn() => wh_provinces()], 'num' => ['شمارهٔ انبار (۲ رقم)', 'text', 1], 'kind' => ['نوع', 'select', 0, fn() => ['own' => 'فروشگاه', 'seller' => 'فروشنده (امانی)']], 'active' => ['فعال', 'check']]],
        'cashboxes' => ['صندوق‌ها', 'cashboxes', 'settings.edit', ['name' => ['نام', 'text', 1], 'branch_id' => ['شعبه', 'select', 1, $br], 'pos_terminal' => ['کارت‌خوان', 'text'], 'bank_account_id' => ['حسابِ کارت‌خوان', 'select', 0, $bk], 'transfer_account_id' => ['حسابِ کارت‌به‌کارت', 'select', 0, $bk], 'active' => ['فعال', 'check']]],
        'categories' => ['دسته‌های کالا', 'categories', 'products.edit', ['name' => ['نام', 'text', 1], 'code' => ['کدِ ۴ حرفِ لاتین (بی‌رقم، مثلِ PHON)', 'text', 1]]],
        'units' => ['واحدها', 'units', 'products.edit', ['name' => ['نام', 'text', 1], 'scale' => ['گام (۱ = صحیح، ۱۰۰۰ = سه رقمِ اعشار)', 'int', 1], 'sub_name' => ['نامِ زیرواحد', 'text']]],
        'grades' => ['گریدهای قیمت', 'grades', 'partner.manage', ['name' => ['نام', 'text', 1], 'kind' => ['نوع', 'select', 1, fn() => ['customer' => 'مشتری', 'partner' => 'همکار']], 'disc_pct' => ['تخفیفِ پیش‌فرض ٪', 'float']]],
        'acc_partners' => ['شرکا', 'acc_partners', 'acc.share', ['name' => ['نام', 'text', 1], 'coef' => ['ضریبِ سهم', 'float', 1], 'active' => ['فعال', 'check']]],
        'acc_expenses' => ['هزینه‌های ماهانه/موردی', 'acc_expenses', 'acc.share', ['title' => ['عنوان', 'text', 1], 'amount' => ['مبلغ (ریال)', 'money', 1], 'monthly' => ['ماهانه', 'check'], 'ym' => ['ماهِ موردی (۱۴۰۵-۰۷)', 'ym'], 'from_ym' => ['از ماه', 'ym'], 'to_ym' => ['تا ماه', 'ym']]],
    ]);
}
function crud_save(string $e, array $in): int {
    $def = crud_defs()[$e] ?? null; if (!$def) throw new UserError('بخشِ ناشناخته');
    require_cap($def[2]);
    $d = [];
    foreach ($def[3] as $k => $f) {
        $t = $f[1]; $v = $in[$k] ?? null;
        if ($t === 'check') { $d[$k] = empty($v) ? 0 : 1; continue; }
        if ($t === 'int') $v = to_int($v); elseif ($t === 'money') $v = to_int($v); elseif ($t === 'float') $v = to_float($v); elseif ($t === 'ym') $v = ($s = str_replace('/', '-', en_num(trim((string)$v)))) === '' ? null : (preg_match('/^\d{4}-\d{2}$/', $s) ? $s : throw new UserError('ماه را به‌شکلِ ۱۴۰۵-۰۷ بنویسید'));
        else { $v = trim((string)$v); if ($v === '' && $t === 'select') $v = null; }
        if (!empty($f[2]) && ($v === '' || $v === null)) throw new UserError('«' . $f[0] . '» لازم است');
        $d[$k] = $v === '' ? null : $v;
    }
    if ($e === 'warehouses') { $d['num'] = sprintf('%02d', to_int($d['num'])); $d['code'] = wh_code((string)$d['province'], $d['num']); if (val("SELECT id FROM warehouses WHERE code=? AND id<>?", [$d['code'], (int)($in['id'] ?? 0)])) throw new UserError('انباری با کدِ ' . $d['code'] . ' هست'); }
    if ($e === 'categories') { $d['code'] = strtoupper((string)$d['code']); if (!wh_cat_code_valid($d['code'])) throw new UserError('کدِ دسته دقیقاً ۴ حرفِ لاتین است'); }
    if ($e === 'units' && $d['scale'] < 1) throw new UserError('گام دست‌کم ۱');
    if ($e === 'acc_expenses') $d['created_at'] = now();
    $id = (int)($in['id'] ?? 0); $t = $def[1];
    if ($id) { $o = row("SELECT * FROM `$t` WHERE id=?", [$id]); if ($e === 'acc_expenses') unset($d['created_at']); if ($e === 'categories' && $o['code'] !== $d['code'] && val("SELECT 1 FROM products WHERE category_id=? LIMIT 1", [$id])) throw new UserError('کدِ دسته‌ای که کالا دارد عوض نمی‌شود'); upd($t, $d, 'id=?', [$id]); [$b, $a] = audit_diff($o, $d); audit($t, $id, 'update', $b, $a, $def[0]); return $id; }
    $id = ins($t, $d); audit($t, $id, 'create', null, $d, $def[0]); return $id;
}
function crud_delete(string $e, int $id): void {
    $def = crud_defs()[$e] ?? null; if (!$def) throw new UserError('بخشِ ناشناخته'); require_cap($def[2]);
    $t = $def[1]; $o = row("SELECT * FROM `$t` WHERE id=?", [$id]); if (!$o) return;
    if (array_key_exists('active', $o)) { upd($t, ['active' => 0], 'id=?', [$id]); audit($t, $id, 'deactivate', ['active' => 1], ['active' => 0], $def[0]); return; }
    try { q("DELETE FROM `$t` WHERE id=?", [$id]); audit($t, $id, 'delete', $o, null, $def[0]); } catch (PDOException $ex) { throw new UserError('این ردیف استفاده شده و حذف نمی‌شود'); }
}
