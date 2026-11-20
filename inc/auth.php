<?php
// کاربران، نقش‌ها، توانایی‌ها (cap)، نشست و سقف‌ها
declare(strict_types=1);

function caps_all(): array {
    $c = [
        'تنظیمات' => ['settings.view' => 'دیدنِ تنظیمات', 'settings.edit' => 'ویرایشِ تنظیمات', 'audit.view' => 'دفترِ ممیزی', 'fiscal.manage' => 'سالِ مالی و قفلِ ماه',
            'ext.manage' => 'ماژول‌ها و قالب', 'update.manage' => 'به‌روزرسانی', 'sms.manage' => 'پیامک'],
        'کاربران' => ['users.view' => 'دیدنِ کاربران', 'users.edit' => 'ساختن/ویرایشِ کاربر', 'roles.edit' => 'ویرایشِ نقش‌ها'],
        'کالا و انبار' => ['products.view' => 'دیدنِ کالا', 'products.edit' => 'ویرایشِ کالا', 'prices.edit' => 'تغییرِ قیمت', 'stock.view' => 'دیدنِ موجودی',
            'stock.in' => 'ورودِ انبار', 'stock.out' => 'خروجِ انبار و ارسال', 'stock.loss' => 'ضرر و عودت', 'labels.print' => 'چاپِ برچسب', 'labels.design' => 'طراحیِ برچسب', 'print.manage' => 'ایستگاه‌های چاپ'],
        'خرید' => ['purchase.view' => 'دیدنِ خرید', 'purchase.edit' => 'ثبتِ سفارش و فاکتورِ خرید', 'supplier.pay' => 'پرداخت به تأمین‌کننده'],
        'فروش' => ['pos.use' => 'صندوقِ فروش', 'pos.discount' => 'تخفیف در صندوق', 'pos.return' => 'مرجوعی', 'shift.manage' => 'مدیریتِ شیفت‌ها', 'partner.manage' => 'همکارانِ فروش',
            'docs.view' => 'دیدنِ فاکتورها', 'docs.edit' => 'ساختنِ فاکتور', 'docs.issue' => 'ثبت و شماره‌گذاریِ فاکتور', 'channels.manage' => 'کانال‌های فروش'],
        'کارکنان' => ['staff.ledger' => 'حسابِ کارکنان', 'commission.manage' => 'قاعدهٔ پورسانت', 'perf.view' => 'عملکردِ کارکنان', 'hr.view' => 'پروندهٔ کارکنان',
            'hr.edit' => 'ویرایشِ پرونده و قرارداد', 'hr.attendance' => 'حضور و مرخصی', 'hr.payroll' => 'حقوق و فیش'],
        'مشتریان' => ['customers.view' => 'دیدنِ مشتریان', 'customers.edit' => 'ویرایشِ مشتری', 'customers.credit' => 'نسیه و کیف پول'],
        'مالی' => ['ledger.view' => 'دفترِ کل', 'ledger.post' => 'سندِ دستی و سرفصل', 'bank.manage' => 'بانک، صندوق و چک', 'acc.share' => 'سهمِ شرکا'],
        'گزارش' => ['reports.dashboard' => 'داشبورد', 'reports.sales' => 'گزارشِ فروش', 'reports.profit' => 'سود و زیان', 'reports.export' => 'خروجیِ گزارش'],
    ];
    return apply_filters('caps', $c);
}
function caps_flat(): array { $o = []; foreach (caps_all() as $g) $o += $g; return $o; }
function roles_builtin(): array {
    return [
        'admin' => ['مدیر', array_keys(caps_flat())],
        'accountant' => ['حسابدار', ['ledger.view', 'ledger.post', 'bank.manage', 'acc.share', 'reports.dashboard', 'reports.sales', 'reports.profit', 'reports.export', 'docs.view', 'docs.edit', 'docs.issue',
            'customers.view', 'customers.credit', 'purchase.view', 'supplier.pay', 'staff.ledger', 'users.view', 'users.edit', 'fiscal.manage', 'audit.view']],
        'warehouse' => ['انباردار', ['products.view', 'stock.view', 'stock.in', 'stock.out', 'stock.loss', 'labels.print', 'purchase.view']],
        'seller' => ['فروشنده', ['pos.use', 'pos.discount', 'products.view', 'stock.view', 'customers.view', 'customers.edit', 'docs.view', 'docs.edit', 'labels.print']],
        'cashier' => ['صندوق‌دار', ['pos.use', 'pos.return', 'products.view', 'customers.view', 'customers.edit', 'docs.view']],
        'hr' => ['منابع انسانی', ['hr.view', 'hr.edit', 'hr.attendance', 'hr.payroll', 'perf.view', 'staff.ledger', 'commission.manage']],
    ];
}
function roles_seed(): void {
    foreach (roles_builtin() as $slug => [$name, $caps]) {
        $id = (int)val("SELECT id FROM roles WHERE slug=?", [$slug]);
        if (!$id) $id = ins('roles', ['slug' => $slug, 'name' => $name, 'builtin' => 1]);
        if (!(int)val("SELECT COUNT(*) FROM role_caps WHERE role_id=?", [$id])) foreach ($caps as $c) q("INSERT IGNORE INTO role_caps VALUES (?,?)", [$id, $c]);
    }
}

// ── نشست
function session_boot(): void {
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) return;
    session_name('c8sid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
    $ttl = (int)setting('sess_ttl_min', 720) * 60;
    if (!empty($_SESSION['uid']) && !empty($_SESSION['seen']) && time() - $_SESSION['seen'] > $ttl) { $_SESSION = []; }
    $_SESSION['seen'] = time();
}
function login_as(int $uid): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION['uid'] = $uid; $GLOBALS['__user'] = null;
    upd('users', ['last_login' => now()], 'id=?', [$uid]);
}
function logout(): void { $_SESSION = []; $GLOBALS['__user'] = null; if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); }

/** کاربرِ جاری + نقش‌ها + capها + شعبه‌ها + سقف‌ها */
function current_user(): ?array {
    if (array_key_exists('__user', $GLOBALS) && $GLOBALS['__user'] !== null) return $GLOBALS['__user'] ?: null;
    $uid = (int)($_SESSION['uid'] ?? 0);
    $GLOBALS['__user'] = $uid ? (user_load($uid) ?: false) : false;
    return $GLOBALS['__user'] ?: null;
}
function set_current_user(?int $uid): void { $GLOBALS['__user'] = $uid ? (user_load($uid) ?: false) : false; }
function user_load(int $uid): ?array {
    $u = row("SELECT * FROM users WHERE id=? AND active=1", [$uid]);
    if (!$u) return null;
    unset($u['pass_hash']);
    $u['roles'] = array_column(rows("SELECT r.slug FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=?", [$uid]), 'slug');
    $u['caps'] = $u['is_super'] ? array_keys(caps_flat()) : array_column(rows("SELECT DISTINCT rc.cap FROM user_roles ur JOIN role_caps rc ON rc.role_id=ur.role_id WHERE ur.user_id=?", [$uid]), 'cap');
    $u['branches'] = array_map('intval', array_column(rows("SELECT branch_id FROM user_branches WHERE user_id=?", [$uid]), 'branch_id'));
    return $u;
}
/** «a|b» یعنی هرکدام کافی است */
function can(string $cap, ?array $u = null): bool { $u = $u ?: current_user(); if (!$u) return false; if ($u['is_super']) return true; foreach (explode('|', $cap) as $c) if (in_array($c, $u['caps'], true)) return true; return false; }
function require_login(): array { $u = current_user(); if (!$u) throw new UserError('ابتدا وارد شوید', 401); return $u; }
function require_cap(string $cap): void { require_login(); if (!can($cap)) throw new UserError('این دسترسی را ندارید', 403); }
/** کاربر به این شعبه دسترسی دارد؟ (فهرستِ خالی = همهٔ شعبه‌ها) */
function branch_allowed(int $branchId, ?array $u = null): bool { $u = $u ?: current_user(); if (!$u) return false; return $u['is_super'] || !$u['branches'] || in_array($branchId, $u['branches'], true); }
function user_limits(?array $u = null): array { $u = $u ?: current_user(); return ['discount_pct' => (float)($u['max_discount_pct'] ?? 0), 'credit' => (int)($u['max_credit'] ?? 0), 'super' => (bool)($u['is_super'] ?? false)]; }

function login_password(string $username, string $pass): array {
    $username = trim(en_num($username));
    $k = 'login:' . client_ip() . ':' . mb_strtolower($username);
    if (rate_count($k, 900) >= 8) throw new UserError('تلاشِ زیاد؛ ۱۵ دقیقهٔ دیگر امتحان کنید', 429);
    $u = row("SELECT * FROM users WHERE (username=? OR mobile=?) AND active=1", [$username, norm_mobile($username)]);
    if (!$u || !$u['pass_hash'] || !password_verify($pass, $u['pass_hash'])) { rate_hit($k); throw new UserError('نامِ کاربری یا رمز نادرست است', 401); }
    login_as((int)$u['id']);
    return current_user();
}

/** ساختن/ویرایشِ کاربر — کسی بیش از اختیارِ خودش نقش نمی‌دهد */
function user_save(array $in): int {
    $me = require_login();
    $id = (int)($in['id'] ?? 0);
    $roleIds = array_map('intval', (array)($in['roles'] ?? []));
    if (!$me['is_super']) {
        $want = array_column(rows("SELECT DISTINCT cap FROM role_caps WHERE role_id IN (" . in_list($roleIds) . ")"), 'cap');
        foreach ((array)($in['caps'] ?? []) as $c) $want[] = $c;
        foreach ($want as $c) if (!in_array($c, $me['caps'], true)) throw new UserError('این دسترسی را ندارید', 403);
        if (!empty($in['is_super'])) throw new UserError('این دسترسی را ندارید', 403);
    }
    $username = trim(en_num($in['username'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_.\-]{3,60}$/', $username)) throw new UserError('نامِ کاربری فقط حروفِ لاتین و عدد (۳ نویسه به بالا)');
    $mobile = norm_mobile($in['mobile'] ?? '');
    if ($mobile !== '' && !mobile_ok($mobile)) throw new UserError('شمارهٔ موبایل معتبر نیست');
    if (val("SELECT id FROM users WHERE username=? AND id<>?", [$username, $id])) throw new UserError('این نامِ کاربری قبلاً ثبت شده است');
    if ($mobile && val("SELECT id FROM users WHERE mobile=? AND id<>?", [$mobile, $id])) throw new UserError('این موبایل برای کاربرِ دیگری ثبت شده است');
    $d = ['username' => $username, 'name' => trim($in['name'] ?? '') ?: $username, 'mobile' => $mobile ?: null, 'active' => empty($in['inactive']) ? 1 : 0,
        'max_discount_pct' => to_float($in['max_discount_pct'] ?? 0), 'max_credit' => to_int($in['max_credit'] ?? 0), 'kind' => in_array($in['kind'] ?? 'staff', ['staff', 'channel']) ? ($in['kind'] ?? 'staff') : 'staff'];
    if ($me['is_super']) $d['is_super'] = empty($in['is_super']) ? 0 : 1;
    if (!empty($in['password'])) { if (mb_strlen($in['password']) < 6) throw new UserError('رمز دست‌کم ۶ نویسه'); $d['pass_hash'] = password_hash($in['password'], PASSWORD_DEFAULT); }
    return tx(function () use ($id, $d, $roleIds, $in) {
        if ($id) { $old = row("SELECT * FROM users WHERE id=?", [$id]); unset($old['pass_hash']); upd('users', $d, 'id=?', [$id]); $nd = $d; unset($nd['pass_hash']); [$b, $a] = audit_diff($old, $nd); audit('users', $id, 'update', $b, $a, 'کاربر'); }
        else { $d['created_at'] = now(); $id = ins('users', $d); audit('users', $id, 'create', null, ['username' => $d['username']], 'کاربرِ تازه'); }
        q("DELETE FROM user_roles WHERE user_id=?", [$id]);
        foreach ($roleIds as $r) q("INSERT IGNORE INTO user_roles VALUES (?,?)", [$id, $r]);
        if (array_key_exists('branches', $in)) { q("DELETE FROM user_branches WHERE user_id=?", [$id]); foreach ((array)$in['branches'] as $b) q("INSERT IGNORE INTO user_branches VALUES (?,?)", [$id, (int)$b]); }
        return $id;
    });
}
function role_save(array $in): int {
    $me = require_login();
    $caps = array_values(array_intersect((array)($in['caps'] ?? []), array_keys(caps_flat())));
    if (!$me['is_super']) foreach ($caps as $c) if (!in_array($c, $me['caps'], true)) throw new UserError('این دسترسی را ندارید', 403);
    $id = (int)($in['id'] ?? 0); $name = trim($in['name'] ?? '');
    if ($name === '') throw new UserError('نامِ نقش لازم است');
    return tx(function () use ($id, $name, $caps) {
        if (!$id) $id = ins('roles', ['slug' => 'r' . substr(bin2hex(random_bytes(4)), 0, 8), 'name' => $name]);
        else upd('roles', ['name' => $name], 'id=?', [$id]);
        $old = array_column(rows("SELECT cap FROM role_caps WHERE role_id=?", [$id]), 'cap');
        q("DELETE FROM role_caps WHERE role_id=?", [$id]);
        foreach ($caps as $c) q("INSERT INTO role_caps VALUES (?,?)", [$id, $c]);
        audit('roles', $id, 'update', ['caps' => implode(',', $old)], ['caps' => implode(',', $caps)], 'نقشِ ' . $name);
        return $id;
    });
}

// ── شمارشِ درخواست‌ها (محدودیتِ نرخ)
function rate_hit(string $k): void { ins('rate_hits', ['k' => mb_cut($k, 120), 'at' => now()]); if (random_int(1, 50) == 1) q("DELETE FROM rate_hits WHERE at < ?", [date('Y-m-d H:i:s', time() - 86400)]); }
function rate_count(string $k, int $sec): int { return (int)val("SELECT COUNT(*) FROM rate_hits WHERE k=? AND at>=?", [mb_cut($k, 120), date('Y-m-d H:i:s', time() - $sec)]); }
