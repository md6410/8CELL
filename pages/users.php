<?php
$id = req_int('id'); $u = $id ? row("SELECT * FROM users WHERE id=?", [$id]) : null;
$roles = ui_opts("SELECT id, name FROM roles ORDER BY id"); $br = ui_opts("SELECT id, name FROM branches WHERE active=1");
if (can('users.edit')) { $v = $u ? $u + ['roles' => array_column(rows("SELECT role_id FROM user_roles WHERE user_id=?", [$id]), 'role_id'), 'branches' => array_column(rows("SELECT branch_id FROM user_branches WHERE user_id=?", [$id]), 'branch_id'), 'inactive' => !$u['active']] : [];
    $f = ['username' => ['نامِ کاربری (لاتین)', 'text', 1], 'name' => ['نام', 'text', 1], 'mobile' => ['موبایل', 'text'], 'password' => ['رمز' . ($u ? ' (خالی = بی‌تغییر)' : ''), 'password'], 'roles' => ['نقش‌ها', 'multi', 0, $roles], 'branches' => ['شعبه‌های مجاز (هیچ = همه)', 'multi', 0, $br],
        'max_discount_pct' => ['سقفِ تخفیف ٪', 'float'], 'max_credit' => ['سقفِ نسیه (ریال)', 'money'], 'inactive' => ['غیرفعال', 'check']];
    if (current_user()['is_super']) $f['is_super'] = ['مدیرِ ارشد (همهٔ دسترسی‌ها)', 'check'];
    echo ui_card($u ? 'ویرایشِ ' . $u['name'] : 'کاربرِ تازه', ui_form('user_save', $f, $v, ['hidden' => $u ? ['id' => $id] : [], 'then' => 'go:' . page_url('users')])); }
echo ui_card('کاربران', ui_table(['username' => ['نامِ کاربری', 'code'], 'name' => 'نام', 'kind' => ['نوع', fn($r) => $r['kind'] === 'channel' ? 'کانال' : 'کارکنان'], 'rl' => 'نقش‌ها', 'max_discount_pct' => ['سقفِ تخفیف', 'num'], 'last_login' => ['آخرین ورود', 'datetime'], 'active' => ['', fn($r) => $r['active'] ? ($r['is_super'] ? ui_badge('ارشد', 'warn') : '') : ui_badge('غیرفعال', 'err')]],
    rows("SELECT u.*, (SELECT GROUP_CONCAT(r.name SEPARATOR '، ') FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id) rl FROM users u ORDER BY u.kind, u.id"), ['href' => fn($r) => page_url('users', ['id' => $r['id']])]));
$rid = req_int('role'); $role = $rid ? row("SELECT * FROM roles WHERE id=?", [$rid]) : null;
$caps = []; foreach (caps_all() as $g => $cs) foreach ($cs as $k => $t) $caps[$k] = $g . ' › ' . $t;
echo ui_card('نقش‌ها', ui_table(['name' => 'نقش', 'n' => ['تعدادِ توانایی', 'num']], rows("SELECT r.*, (SELECT COUNT(*) FROM role_caps WHERE role_id=r.id) n FROM roles r"), ['href' => fn($r) => page_url('users', ['role' => $r['id']])])
    . (can('roles.edit') ? ui_form('role_save', ['name' => ['نامِ نقش', 'text', 1], 'caps' => ['توانایی‌ها (cap)', 'multi', 0, $caps]], $role ? $role + ['caps' => array_column(rows("SELECT cap FROM role_caps WHERE role_id=?", [$rid]), 'cap')] : [], ['hidden' => $role ? ['id' => $rid] : [], 'submit' => $role ? 'ذخیرهٔ نقش' : 'نقشِ تازه']) : ''));
