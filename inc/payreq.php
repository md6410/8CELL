<?php
// «واریز به حساب» برای همکارِ فروش — روالِ Weby (partner-transfer.php + payreq.php + admin/pay-approvals.php):
//  - مجوزِ جدا برای هر همکار (customers.partner_xfer) و حسابِ تعیین‌شده (partner_xfer_acc)؛ حساب باید فعال بماند وگرنه گزینه بسته است.
//  - فروش ثبت می‌شود، مبلغ تا تأیید بدهیِ همکار است (نسیهٔ بی‌سقف فقط برای همین فروش) و کالا رزرو می‌ماند (fulfil=await_pay).
//  - فیش (بیش از یکی) روی درخواستِ پرداخت؛ حسابدار در صفِ تأیید تأیید یا رد می‌کند.
//  - تأیید = دریافت از همکار به همان حساب (ledger customer_pay) و آزادشدنِ سفارش برای خروج؛ رد = سفارش منتظر می‌ماند تا درخواستِ تازه.
//  - شمارهٔ حساب هرگز از ورودی نمی‌آید؛ فقط از bank_accounts خوانده می‌شود.
declare(strict_types=1);

const PAYREQ_MAX_FILES = 8;
const PAYREQ_MAX_SIZE = 5 * 1024 * 1024;

function payreq_status_label(string $s): string { return ['pending' => 'منتظرِ تأییدِ حسابداری', 'approved' => 'تأییدشده', 'rejected' => 'رد شد', 'canceled' => 'لغو شد'][$s] ?? $s; }

/** حسابِ مجاز برای واریزِ این همکار؛ null یعنی گزینه بسته است */
function ptr_account(int $customerId): ?array {
    $c = row("SELECT is_partner, partner_xfer, partner_xfer_acc FROM customers WHERE id=?", [$customerId]);
    if (!$c || !(int)$c['is_partner'] || !(int)$c['partner_xfer']) return null;
    return row("SELECT * FROM bank_accounts WHERE id=? AND active=1", [(int)$c['partner_xfer_acc']]) ?: null;
}
function ptr_ready(int $customerId): bool { return ptr_account($customerId) !== null; }
function ptr_save(int $customerId, bool $on, int $accId): void {
    $c = row("SELECT is_partner FROM customers WHERE id=?", [$customerId]);
    if (!$c || !(int)$c['is_partner']) throw new UserError('این مشتری همکارِ فروش نیست');
    if ($on && !row("SELECT id FROM bank_accounts WHERE id=? AND active=1", [$accId])) throw new UserError('یک حسابِ بانکیِ فعال انتخاب کنید');
    upd('customers', ['partner_xfer' => $on ? 1 : 0, 'partner_xfer_acc' => $accId ?: null], 'id=?', [$customerId]);
    audit('customers', $customerId, 'xfer', null, ['on' => $on, 'acc' => $accId], 'واریز به حسابِ همکار');
}
/** متنِ مشخصاتِ حساب برای همکار */
function ptr_account_text(array $a): string {
    $p = [$a['title']]; if ($a['bank']) $p[] = 'بانکِ ' . $a['bank']; if ($a['owner']) $p[] = 'به نامِ ' . $a['owner'];
    if ($a['card_no']) $p[] = 'کارت ' . $a['card_no']; if ($a['account_no']) $p[] = 'حساب ' . $a['account_no']; if ($a['iban']) $p[] = 'شبا ' . $a['iban'];
    return implode(' · ', $p);
}

function payreq_get(int $id): ?array { return row("SELECT r.*, s.number sale_number, s.total sale_total, s.fulfil, c.name cname, c.mobile cmobile FROM payreqs r JOIN sales s ON s.id=r.sale_id LEFT JOIN customers c ON c.id=r.customer_id WHERE r.id=?", [$id]); }
function payreq_for_sale(int $saleId): array { return rows("SELECT * FROM payreqs WHERE sale_id=? ORDER BY id DESC", [$saleId]); }
function payreq_open_for_sale(int $saleId): ?array { return row("SELECT * FROM payreqs WHERE sale_id=? AND status='pending' ORDER BY id DESC LIMIT 1", [$saleId]); }
function payreq_files(int $id): array { return rows("SELECT * FROM payreq_files WHERE payreq_id=? ORDER BY id", [$id]); }
function payreq_open_count(): int { return (int)val("SELECT COUNT(*) FROM payreqs WHERE status='pending'"); }

/** درخواستِ پرداخت برای فروشِ منتظرِ واریز (پس از ثبتِ فروش یا پس از ردِ درخواستِ قبلی) */
function payreq_create(int $saleId, array $in = []): int {
    $s = row("SELECT * FROM sales WHERE id=?", [$saleId]);
    if (!$s || $s['partner_mode'] !== 'transfer' || $s['fulfil'] !== 'await_pay') throw new UserError('این فروش منتظرِ واریز نیست');
    if (payreq_open_for_sale($saleId)) throw new UserError('برای این فروش یک درخواستِ باز هست');
    $acc = ptr_account((int)$s['customer_id']); if (!$acc) throw new UserError('واریز به حساب برای این همکار بسته است یا حسابش غیرفعال شده');
    $paid = (int)val("SELECT COALESCE(SUM(amount),0) FROM payreqs WHERE sale_id=? AND status='approved'", [$saleId]);
    $id = ins('payreqs', ['sale_id' => $saleId, 'customer_id' => $s['customer_id'], 'bank_account_id' => $acc['id'], 'amount' => (int)$s['total'] - $paid,
        'deadline' => !empty($in['deadline']) ? jparse((string)$in['deadline']) : null, 'note' => mb_cut((string)($in['note'] ?? ''), 300) ?: null, 'created_by' => current_user()['id'] ?? null, 'created_at' => now()]);
    audit('payreqs', $id, 'create', null, ['sale' => $saleId], 'درخواستِ پرداخت');
    return $id;
}

/** افزودنِ فیش‌ها (تصویر یا PDF، حداکثر ۸ و هرکدام ۵ مگابایت) — $files همان ساختارِ $_FILES['receipt'] */
function payreq_add_files(int $id, array $files): int {
    $r = payreq_get($id); if (!$r || $r['status'] !== 'pending') throw new UserError('درخواستِ باز یافت نشد');
    $names = (array)($files['name'] ?? []); $tmps = (array)($files['tmp_name'] ?? []); $errs = (array)($files['error'] ?? []); $sizes = (array)($files['size'] ?? []);
    $have = count(payreq_files($id)); $n = 0;
    $dir = DATA . '/uploads/payreq'; if (!is_dir($dir)) { @mkdir($dir, 0775, true); @file_put_contents(DATA . '/uploads/.htaccess', "Require all denied\nDeny from all\n"); }
    foreach ($names as $k => $name) {
        if ((int)($errs[$k] ?? 4) === 4) continue;
        if ((int)$errs[$k] !== 0) throw new UserError('بارگذاریِ «' . $name . '» ناقص ماند');
        if ($have + $n >= PAYREQ_MAX_FILES) throw new UserError('حداکثر ' . fa_num(PAYREQ_MAX_FILES) . ' فیش');
        if ((int)$sizes[$k] > PAYREQ_MAX_SIZE) throw new UserError('«' . $name . '» بزرگ‌تر از ۵ مگابایت است');
        $tmp = (string)$tmps[$k];
        if (!is_uploaded_file($tmp) && !(defined('PAYREQ_TEST') && is_file($tmp))) throw new UserError('فایل نامعتبر');
        $mime = payreq_sniff($tmp); if (!$mime) throw new UserError('«' . $name . '» تصویر (JPG/PNG/WEBP) یا PDF نیست');
        $file = $id . '-' . bin2hex(random_bytes(8)) . '.' . ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime];
        if (!(defined('PAYREQ_TEST') ? copy($tmp, "$dir/$file") : move_uploaded_file($tmp, "$dir/$file"))) throw new UserError('ذخیرهٔ فایل نشد');
        ins('payreq_files', ['payreq_id' => $id, 'path' => "payreq/$file", 'name' => mb_cut(basename((string)$name), 200), 'mime' => $mime, 'size' => (int)$sizes[$k], 'by_user' => current_user()['id'] ?? null, 'at' => now()]);
        $n++;
    }
    if (!$n) throw new UserError('فیشی انتخاب نشده است');
    audit('payreqs', $id, 'receipt', null, ['files' => $n], 'فیشِ واریز');
    return $n;
}
/** نوعِ فایل از روی محتوا، نه نام */
function payreq_sniff(string $path): string {
    $h = (string)@file_get_contents($path, false, null, 0, 12);
    if (str_starts_with($h, "\xFF\xD8\xFF")) return 'image/jpeg';
    if (str_starts_with($h, "\x89PNG")) return 'image/png';
    if (str_starts_with($h, 'RIFF') && substr($h, 8, 4) === 'WEBP') return 'image/webp';
    if (str_starts_with($h, '%PDF')) return 'application/pdf';
    return '';
}

/** تأییدِ حسابدار: دریافت از همکار به حسابِ تعیین‌شده و آزادشدنِ سفارش برای خروج */
function payreq_approve(int $id, string $note = ''): int {
    $r = payreq_get($id); if (!$r || $r['status'] !== 'pending') throw new UserError('این درخواست باز نیست');
    if (!payreq_files($id)) throw new UserError('هنوز فیشی برای این درخواست ثبت نشده است');
    $rid = tx(function () use ($r, $id, $note) {
        $u = current_user();
        $rid = ins('receipts', ['sale_id' => $r['sale_id'], 'method' => 'transfer', 'amount' => (int)$r['amount'], 'customer_id' => $r['customer_id'], 'ref' => 'واریزِ همکار #' . $id, 'at' => now()]);
        customer_ledger_add((int)$r['customer_id'], -(int)$r['amount'], 'payment', 'receipt', $rid, 'واریز به حساب — فروشِ ' . $r['sale_number']);
        ledger_post('customer_pay', $rid, ['amount' => (int)$r['amount'], 'method' => 'transfer', 'customer_id' => (int)$r['customer_id'], 'bank_account_id' => (int)$r['bank_account_id']]);
        upd('payreqs', ['status' => 'approved', 'decided_by' => $u['id'] ?? null, 'decided_at' => now(), 'decision_note' => mb_cut($note, 300) ?: null, 'receipt_id' => $rid], 'id=?', [$id]);
        upd('sales', ['fulfil' => 'pending'], 'id=? AND fulfil=?', [$r['sale_id'], 'await_pay']);
        audit('payreqs', $id, 'approve', ['status' => 'pending'], ['status' => 'approved', 'receipt' => $rid], 'تأییدِ واریز');
        return $rid;
    });
    payreq_notify($id, 'ok');
    return $rid;
}
function payreq_reject(int $id, string $note): void {
    $r = payreq_get($id); if (!$r || $r['status'] !== 'pending') throw new UserError('این درخواست باز نیست');
    if (trim($note) === '') throw new UserError('دلیلِ رد را بنویسید');
    upd('payreqs', ['status' => 'rejected', 'decided_by' => current_user()['id'] ?? null, 'decided_at' => now(), 'decision_note' => mb_cut($note, 300)], 'id=?', [$id]);
    audit('payreqs', $id, 'reject', ['status' => 'pending'], ['status' => 'rejected', 'note' => $note], 'ردِ واریز');
    payreq_notify($id, 'no');
}
function payreq_notify(int $id, string $kind): void {
    $r = payreq_get($id); if (!$r || !$r['cmobile']) return;
    $key = $kind === 'ok' ? 'payreq_ok' : 'payreq_no';
    if (!sms_tpl_enabled($key)) return;
    try { sms_send_tpl((string)$r['cmobile'], $key, ['NAME' => $r['cname'], 'NUMBER' => $r['sale_number'], 'AMOUNT' => fa_money($r['amount']), 'NOTE' => (string)$r['decision_note']], 'notify', ['ref_type' => 'payreq', 'ref_id' => $id]); }
    catch (Throwable $e) { error_log('payreq sms: ' . $e->getMessage()); }
}
function payreq_queue(string $status = 'pending'): array {
    return rows("SELECT r.*, s.number sale_number, c.name cname, b.title acc, (SELECT COUNT(*) FROM payreq_files f WHERE f.payreq_id=r.id) files
        FROM payreqs r JOIN sales s ON s.id=r.sale_id LEFT JOIN customers c ON c.id=r.customer_id LEFT JOIN bank_accounts b ON b.id=r.bank_account_id
        WHERE r.status=? ORDER BY r.id" . ($status === 'pending' ? '' : ' DESC LIMIT 200'), [$status]);
}
/** نمایشِ فیش (فقط با دسترسی) */
function payreq_file_out(int $fileId): void {
    $f = row("SELECT * FROM payreq_files WHERE id=?", [$fileId]); $p = $f ? DATA . '/uploads/' . $f['path'] : '';
    if (!$f || !is_file($p) || str_contains($f['path'], '..')) { http_response_code(404); exit('یافت نشد'); }
    header('Content-Type: ' . $f['mime']); header('Content-Length: ' . filesize($p)); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=3600');
    header('Content-Disposition: inline; filename="receipt-' . (int)$f['id'] . '.' . pathinfo($f['path'], PATHINFO_EXTENSION) . '"');
    readfile($p); exit;
}
/** کادرِ واریز روی صفحهٔ فروش */
function payreq_sale_card(array $s): string {
    if ($s['partner_mode'] !== 'transfer') return '';
    $h = ''; $acc = $s['customer_id'] ? ptr_account((int)$s['customer_id']) : null;
    if ($s['fulfil'] === 'await_pay') $h .= '<p class="warn">این سفارش تا تأییدِ واریز در انبار رزرو می‌ماند و خارج نمی‌شود.</p>';
    if ($acc) $h .= '<p>حسابِ واریز: <b>' . h(ptr_account_text($acc)) . '</b></p>';
    foreach (payreq_for_sale((int)$s['id']) as $r) {
        $fs = payreq_files((int)$r['id']);
        $h .= '<div class="card sub"><p>' . ui_badge(payreq_status_label($r['status']), $r['status'] === 'approved' ? 'ok' : ($r['status'] === 'rejected' ? 'err' : 'warn')) . ' مبلغ <b>' . fa_money($r['amount']) . '</b> ریال'
            . ($r['deadline'] ? ' · مهلت ' . jdate($r['deadline']) : '') . ($r['decision_note'] ? ' · ' . h($r['decision_note']) : '') . '</p>';
        if ($fs) { $h .= '<p>'; foreach ($fs as $f) $h .= '<a class="btn sm" target="_blank" href="' . h(page_url('payreq_file', ['id' => $f['id']])) . '">🧾 ' . h($f['name']) . '</a> '; $h .= '</p>'; }
        if ($r['status'] === 'pending') {
            if (can('pos.use') || can('partner.manage')) $h .= ui_form('payreq_files', [], [], ['hidden' => ['id' => $r['id']], 'multipart' => 1, 'submit' => 'افزودنِ فیش', 'extra' => '<label class="field"><span>فیش‌ها (تصویر یا PDF، چندتایی)</span><input type="file" name="receipt[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf"></label>']);
            if (can('bank.manage')) $h .= '<div class="actions">' . ui_btn_api('تأییدِ واریز', 'payreq_approve', ['id' => $r['id']], ['class' => 'primary', 'confirm' => 'واریز تأیید و سفارش برای خروج آزاد شود؟'])
                . ' ' . ui_btn_api('رد', 'payreq_reject', ['id' => $r['id']], ['prompt' => 'note:دلیلِ رد']) . '</div>';
        }
        $h .= '</div>';
    }
    if ($s['fulfil'] === 'await_pay' && !payreq_open_for_sale((int)$s['id']) && (can('pos.use') || can('partner.manage'))) $h .= ui_btn_api('درخواستِ پرداختِ تازه', 'payreq_create', ['sale_id' => $s['id']]);
    return ui_card('واریز به حساب', $h);
}
