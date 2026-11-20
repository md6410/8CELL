<?php
// نقشهٔ مرکزیِ صفحه/عملیات ← cap (منو و API از همین‌جا). صفحهٔ تازه بی‌cap باز نمی‌شود.
declare(strict_types=1);

/** صفحه‌ها: key => [عنوان، cap، گروهِ منو، نمایش در منو] */
function pages_all(): array {
    return apply_filters('pages', [
        'dashboard' => ['داشبوردِ امروز', 'reports.dashboard', 'گزارش', 1],
        'pos' => ['صندوقِ فروش', 'pos.use', 'فروش', 1], 'shifts' => ['شیفت‌ها', 'pos.use', 'فروش', 1], 'sales' => ['فروش‌ها', 'docs.view', 'فروش', 1], 'sale' => ['فروش', 'docs.view', 'فروش', 0],
        'docs' => ['فاکتورها', 'docs.view', 'فروش', 1], 'doc' => ['سند', 'docs.view', 'فروش', 0], 'partners' => ['همکارانِ فروش', 'partner.manage', 'فروش', 1], 'channels' => ['کانال‌های فروش', 'channels.manage', 'فروش', 1],
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
        'product_price' => ['prices.edit', function ($in) { product_price_set((int)$in['id'], to_int($in['price'])); return ['ok' => true]; }, 'POST'],
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
        'cart_doc_disc' => ['pos.discount', fn($in) => api_cart_doc_disc($in), 'POST'],
        'cart_customer' => ['pos.use', function ($in) { cart_set_customer((int)$in['cart_id'], (int)($in['customer_id'] ?? 0) ?: null); return cart_view((int)$in['cart_id']); }, 'POST'],
        'pos_checkout' => ['pos.use', fn($in) => pos_checkout((int)$in['cart_id'], (array)($in['payments'] ?? []), $in), 'POST'],
        'sale_return' => ['pos.return', fn($in) => ['id' => return_commit((int)$in['sale_id'], (array)($in['items'] ?? []), (string)($in['reason'] ?? ''), (string)($in['method'] ?? 'cash'))], 'POST'],
        'sale_doc' => ['docs.edit', fn($in) => ['id' => doc_from_sale((int)$in['sale_id'], (string)($in['kind'] ?? 'invoice'))], 'POST'],
        'phold_release' => ['partner.manage', function ($in) { phold_release((int)$in['sale_id']); return ['ok' => true]; }, 'POST'],
        // فاکتور
        'doc_save' => ['docs.edit', fn($in) => ['id' => doc_save($in)], 'POST'],
        'doc_calc' => ['docs.edit', fn($in) => api_doc_calc($in), 'POST'],
        'doc_commit' => ['docs.issue', fn($in) => doc_commit((int)$in['id']), 'POST'],
        'doc_amend' => ['docs.issue', fn($in) => ['revision' => doc_amend((int)$in['id'], $in)], 'POST'],
        'doc_void' => ['docs.issue', function ($in) { doc_void((int)$in['id'], (string)($in['reason'] ?? '')); return ['ok' => true]; }, 'POST'],
        'doc_share' => ['docs.view', fn($in) => doc_share((int)$in['id'], (string)($in['pass'] ?? ''), to_int($in['days'] ?? 30)), 'POST'],
        'doc_issues' => ['docs.view', fn($in) => official_issues(doc_get((int)$in['id']) ?? []), 'GET'],
        'doc_confirm' => ['acc.share', function ($in) { acc_confirm((int)$in['id']); return ['ok' => true]; }, 'POST'],
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
        'cheque_save' => ['bank.manage', fn($in) => ['id' => cheque_save($in)], 'POST'],
        'cheque_status' => ['bank.manage', function ($in) { cheque_status((int)$in['id'], (string)$in['status'], (int)($in['bank_account_id'] ?? 0)); return ['ok' => true]; }, 'POST'],
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
    $allowed = ['vat_pct', 'tax_round', 'total_round', 'doc_pattern', 'fx_round', 'sms_test_mode', 'sms_retry_max', 'smsir_api_key', 'update_repo', 'update_branch', 'update_auto', 'update_interval', 'update_token', 'base_url',
        'reserve_minutes', 'staff_debt_pct', 'staff_debt_cap', 'shortage_to_staff', 'holiday_rule', 'thursday_off', 'holiday_url', 'fx_nav_key', 'fx_nav_unit', 'fx_max_gap', 'scan_sound', 'captcha_after', 'sess_ttl_min',
        'pos_require_unit', 'loss_sms_confirm', 'own_seller_code', 'sms_otp_ttl', 'sms_otp_cooldown', 'sms_otp_domain', 'sms_retry_gap', 'channel_price_tolerance', 'hr_rules', 'labor_rates'];
    $s = [];
    foreach ($allowed as $k) if (array_key_exists($k, $in)) { if (in_array($k, ['smsir_api_key', 'update_token'], true) && $in[$k] === '') continue; $s[$k] = is_array($in[$k]) ? $in[$k] : trim((string)$in[$k]); }
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
    $lines = [];
    foreach ((array)($in['lines'] ?? []) as $l) { if (trim((string)($l['title'] ?? '')) === '' && !(int)($l['product_id'] ?? 0)) continue; $u = (int)($l['unit_id'] ?? 0) ?: null; $lines[] = ['title' => $l['title'] ?? '', 'qty' => qty_in($l['qty'] ?? 1, $u), 'unit_id' => $u, 'price' => to_int($l['price'] ?? 0), 'disc' => to_int($l['disc'] ?? 0), 'tax_exempt' => !empty($l['tax_exempt'])]; }
    if (!$lines) return ['total' => 0];
    return calc_doc($lines, ['doc_disc' => to_int($in['doc_disc'] ?? 0), 'extra' => to_int($in['extra'] ?? 0)] + (isset($in['vat_pct']) && $in['vat_pct'] !== '' ? ['vat_pct' => to_float($in['vat_pct'])] : []));
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
