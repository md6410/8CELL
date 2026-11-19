<?php
$f = ['name' => ['نام', 'text', 1], 'national_id' => ['کدِ ملی', 'text'], 'mobile' => ['موبایل', 'text'], 'unit' => ['واحد', 'text'], 'position' => ['سمت', 'text'], 'contract_type' => ['نوعِ قرارداد', 'select', 1, ['full' => 'تمام‌وقت', 'part' => 'پاره‌وقت', 'hourly' => 'ساعتی', 'project' => 'پروژه‌ای']],
    'base_salary' => ['حقوقِ پایهٔ ماهانه', 'money'], 'hire_date' => ['تاریخِ استخدام', 'date'], 'married' => ['متأهل', 'check'], 'children' => ['تعدادِ فرزند', 'int'], 'uninsured' => ['بدونِ بیمه', 'check'], 'iban' => ['شبا', 'text'],
    'user_id' => ['کاربرِ سامانه', 'select', 0, ui_opts("SELECT id, name FROM users WHERE kind='staff' ORDER BY name")]];
if (can('hr.edit')) echo ui_card('کارمندِ تازه', ui_form('employee_save', $f, ['contract_type' => 'full'], ['then' => 'go:index.php?p=employee&id={id}']));
echo ui_card('کارکنان', ui_table(['name' => 'نام', 'position' => 'سمت', 'unit' => 'واحد', 'base_salary' => ['حقوقِ پایه', 'money'], 'un' => 'کاربر', 'lb' => ['ماندهٔ مرخصی', fn($r) => fa_num((string)leave_balance((int)$r['id'])) . ' روز']],
    rows("SELECT e.*, u.name un FROM employees e LEFT JOIN users u ON u.id=e.user_id WHERE e.active=1 ORDER BY e.name"), ['href' => fn($r) => page_url('employee', ['id' => $r['id']])]));
