<?php
$tab = $_GET['tab'] ?? 'po';
echo ui_tabs(['po' => 'سفارشِ خرید', 'gr' => 'رسیدِ کالا', 'pi' => 'فاکتورِ خرید', 'pay' => 'بدهی و پرداخت'], $tab, 'purchase');
$sup = ui_opts("SELECT id, name FROM suppliers WHERE active=1 ORDER BY name"); $whs = array_column(warehouse_list(), 'name', 'id');
$lineCols = [['k' => 'qty', 't' => 'تعداد', 'd' => '1', 'w' => '80px'], ['k' => 'unit_price', 't' => 'قیمتِ واحد (ریال)', 'type' => 'money']];
if ($tab === 'po') {
    if (can('purchase.edit')) echo ui_card('سفارشِ خریدِ تازه', ui_form('po_save', ['supplier_id' => ['تأمین‌کننده', 'select', 1, $sup], 'warehouse_id' => ['انبارِ مقصد', 'select', 1, $whs], 'd' => ['تاریخ', 'date'], 'note' => ['یادداشت', 'text'], 'confirm' => ['تأیید و نهایی', 'check']], [], ['extra' => ui_lines('items', $lineCols)]));
    $st = ['draft' => 'پیش‌نویس', 'confirmed' => 'تأییدشده', 'partial' => 'رسیدِ ناقص', 'received' => 'رسیده'];
    echo ui_card('سفارش‌ها', ui_table(['number' => 'شماره', 'sn' => 'تأمین‌کننده', 'd' => ['تاریخ', 'date'], 'status' => ['وضعیت', fn($r) => ui_badge($st[$r['status']] ?? $r['status'])], 'items' => ['اقلام', 'num'],
        'a' => ['', fn($r) => ($r['status'] === 'draft' && can('purchase.edit') ? ui_btn_api('تأیید', 'po_confirm', ['id' => $r['id']], ['class' => 'sm']) : '') . (in_array($r['status'], ['confirmed', 'partial'], true) ? ' <a class="btn sm" href="' . h(page_url('purchase', ['tab' => 'gr', 'po' => $r['id']])) . '">رسیدِ کالا</a>' : '')]],
        rows("SELECT po.*, s.name sn, (SELECT COUNT(*) FROM purchase_order_items WHERE po_id=po.id) items FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id ORDER BY po.id DESC LIMIT 200")));
} elseif ($tab === 'gr') {
    $po = req_int('po');
    if ($po && ($p = row("SELECT * FROM purchase_orders WHERE id=?", [$po]))) {
        $h = '<form class="form" data-api="goods_receipt" data-then="go:' . h(page_url('purchase', ['tab' => 'gr'])) . '"><input type="hidden" name="po_id" value="' . $po . '"><table class="tbl"><tr><th>کالا</th><th>سفارش</th><th>رسیده تا حالا</th><th>رسیدِ این بار</th></tr>';
        foreach (rows("SELECT i.*, p.name, (SELECT COALESCE(SUM(qty),0) FROM goods_receipt_items g WHERE g.po_item_id=i.id) got FROM purchase_order_items i JOIN products p ON p.id=i.product_id WHERE i.po_id=?", [$po]) as $i)
            $h .= '<tr><td>' . h($i['name']) . '</td><td>' . fa_num($i['qty']) . '</td><td>' . fa_num($i['got']) . '</td><td><input name="items[' . $i['id'] . '][qty]" value="' . fa_num(max(0, $i['qty'] - $i['got'])) . '" style="width:80px"></td></tr>';
        echo ui_card('رسیدِ کالا برای ' . fa_num($p['number']), $h . '</table><div class="actions"><button class="btn primary">ثبتِ رسید</button></div></form><p class="muted">انبار فقط آنچه واقعاً رسیده را وارد می‌کند؛ کسری/اضافه گزارش می‌شود.</p>');
    }
    echo ui_card('رسیدها', ui_table(['id' => ['#', 'num'], 'sn' => 'تأمین‌کننده', 'd' => ['تاریخ', 'date'], 'report' => 'تطبیق', 'status' => 'وضعیت', 'a' => ['', fn($r) => $r['status'] === 'open' && can('purchase.edit') ? '<a class="btn sm" href="' . h(page_url('purchase', ['tab' => 'pi', 'gr' => $r['id']])) . '">فاکتورِ خرید</a>' : '']],
        rows("SELECT g.*, s.name sn FROM goods_receipts g JOIN suppliers s ON s.id=g.supplier_id ORDER BY g.id DESC LIMIT 200")));
} elseif ($tab === 'pi') {
    $gr = req_int('gr');
    $f = ['number' => ['شمارهٔ فاکتورِ فروشنده', 'text'], 'd' => ['تاریخ', 'date'], 'due' => ['سررسید (خالی = طبقِ شرایطِ پرداخت)', 'date'], 'overhead' => ['سربار: حمل/بیمه/گمرک (پخش به نسبتِ مبلغ)', 'money'], 'tax' => ['مالیاتِ خرید', 'money'], 'consign' => ['کالای امانی (مالکیتِ فروشنده)', 'check'], 'direct_in' => ['ورودِ مستقیم به انبار (بی‌اسکنِ دانه)', 'check']];
    if ($gr && ($g = row("SELECT * FROM goods_receipts WHERE id=?", [$gr]))) {
        $h = '<table class="tbl"><tr><th>کالا</th><th>تعداد</th><th>قیمتِ واحد</th></tr>'; foreach (rows("SELECT gi.*, p.name FROM goods_receipt_items gi JOIN products p ON p.id=gi.product_id WHERE gr_id=? AND qty>0", [$gr]) as $i) $h .= '<tr><td>' . h($i['name']) . '</td><td>' . fa_num($i['qty']) . '</td><td><input class="money" name="prices[' . $i['id'] . ']" value="' . fa_money($i['unit_price']) . '"></td></tr>';
        echo ui_card('فاکتورِ خرید برای رسیدِ ' . fa_num($gr), ui_form('purchase_invoice', $f, [], ['hidden' => ['gr_id' => $gr], 'extra' => $h . '</table>', 'then' => 'go:' . page_url('purchase', ['tab' => 'pay'])]));
    } else echo ui_card('فاکتورِ خرید (بی‌سفارش)', ui_form('purchase_invoice', ['supplier_id' => ['تأمین‌کننده', 'select', 1, $sup], 'warehouse_id' => ['انبار', 'select', 1, $whs]] + $f, [], ['extra' => ui_lines('items', $lineCols)]));
    echo ui_card('فاکتورهای خرید', ui_table(['id' => ['#', 'num'], 'sn' => 'تأمین‌کننده', 'number' => 'شماره', 'd' => ['تاریخ', 'date'], 'subtotal' => ['مبلغ', 'money'], 'overhead' => ['سربار', 'money'], 'tax' => ['مالیات', 'money'], 'total' => ['جمع', 'money'], 'due_date' => ['سررسید', 'date']],
        rows("SELECT pi.*, s.name sn FROM purchase_invoices pi JOIN suppliers s ON s.id=pi.supplier_id ORDER BY pi.id DESC LIMIT 200")));
    $last = row("SELECT id FROM purchase_invoices ORDER BY id DESC LIMIT 1");
    if ($last) echo ui_card('بهای تمام‌شدهٔ آخرین فاکتور', ui_table(['name' => 'کالا', 'qty' => ['تعداد', 'num'], 'unit_price' => ['قیمت', 'money'], 'overhead_share' => ['سهمِ سربار', 'money'], 'unit_cost' => ['بهای واحد', 'money'], 'lb' => ['', fn($r) => '<a class="btn sm" href="' . h(page_url('labels', ['lot_id' => $r['lot_id']])) . '">برچسبِ دانه‌ها</a>']], rows("SELECT i.*, p.name FROM purchase_invoice_items i JOIN products p ON p.id=i.product_id WHERE pi_id=?", [$last['id']])));
} else {
    if (can('supplier.pay')) echo ui_card('پرداخت به تأمین‌کننده', ui_form('supplier_pay', ['supplier_id' => ['تأمین‌کننده', 'select', 1, $sup], 'pi_id' => ['فاکتور (اختیاری)', 'int'], 'amount' => ['مبلغ', 'money', 1], 'method' => ['روش', 'select', 1, ['bank' => 'بانک', 'cash' => 'نقد', 'cheque' => 'چک']],
        'bank_account_id' => ['حسابِ بانکی', 'select', 0, ui_opts("SELECT id, title name FROM bank_accounts WHERE active=1")], 'cheque_no' => ['شمارهٔ چک', 'text'], 'cheque_due' => ['سررسیدِ چک', 'date'], 'd' => ['تاریخ', 'date'], 'note' => ['یادداشت', 'text']]));
    echo ui_card('بدهی‌های باز (به ترتیبِ سررسید)', ui_table(['id' => ['فاکتور', 'num'], 'sname' => 'تأمین‌کننده', 'd' => ['تاریخ', 'date'], 'due_date' => ['سررسید', 'date'], 'total' => ['مبلغ', 'money'], 'remain' => ['مانده', 'money']], payables()));
    $bal = rows("SELECT id, name FROM suppliers WHERE active=1"); foreach ($bal as &$b) $b['bal'] = supplier_balance((int)$b['id']);
    echo ui_card('ماندهٔ تأمین‌کنندگان', ui_table(['name' => 'تأمین‌کننده', 'bal' => ['بدهیِ ما', 'money']], array_values(array_filter($bal, fn($b) => $b['bal'] != 0))));
}
