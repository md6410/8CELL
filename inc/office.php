<?php
// اعلانِ پنل (زنگوله)، جست‌وجوی سراسری، تیکت و نامهٔ اداری — روالِ Weby (panel-notices، admin-search، tickets، letter)
declare(strict_types=1);

// ── اعلانِ پنل: هر هشدارِ مدیران هم این‌جا می‌نشیند؛ هر کاربر فقط اعلان‌های توانایی‌هایش را می‌بیند
function pn_add(string $event, string $title, string $body = '', string $link = '', string $cap = 'notify.alerts'): int {
    $id = ins('panel_notices', ['event' => mb_cut($event, 40), 'title' => mb_cut($title, 200), 'body' => $body !== '' ? mb_cut($body, 2000) : null, 'link' => $link ?: null, 'cap' => $cap ?: null, 'created_at' => now()]);
    if ($id % 50 === 0) q("DELETE FROM panel_notices WHERE created_at<?", [date('Y-m-d H:i:s', strtotime('-90 day'))]);
    return $id;
}
function pn_visible(array $rows): array { return array_values(array_filter($rows, fn($r) => !$r['cap'] || can((string)$r['cap']))); }
function pn_latest(int $limit = 30): array { return array_slice(pn_visible(rows("SELECT * FROM panel_notices ORDER BY id DESC LIMIT 200")), 0, $limit); }
function pn_unseen_count(): int { $u = current_user(); if (!$u) return 0; return count(pn_visible(rows("SELECT id, cap FROM panel_notices WHERE id>? ORDER BY id DESC LIMIT 100", [(int)($u['notice_seen'] ?? 0)]))); }
function pn_mark_seen(): void { $u = current_user(); if ($u) { upd('users', ['notice_seen' => (int)val("SELECT COALESCE(MAX(id),0) FROM panel_notices")], 'id=?', [$u['id']]); } }

// ── جست‌وجوی سراسری: هر بخش فقط با توانایی‌اش
function global_search(string $q): array {
    $q = trim(en_num($q)); if (mb_strlen($q) < 2) return [];
    $like = '%' . $q . '%'; $m = norm_mobile($q); $o = [];
    $add = function (string $title, string $cap, array $rs, callable $row) use (&$o) { if ($rs && can($cap)) $o[] = ['title' => $title, 'items' => array_map($row, $rs)]; };
    $add('مشتریان و همکاران', 'customers.view', rows("SELECT id, name, mobile, is_partner FROM customers WHERE merged_into IS NULL AND (name LIKE ? OR mobile LIKE ? OR national_id=?) LIMIT 8", [$like, '%' . ($m ?: $q) . '%', $q]), fn($r) => [$r['name'] . ($r['is_partner'] ? ' (همکار)' : ''), fa_num((string)$r['mobile']), page_url('customer', ['id' => $r['id']])]);
    $add('کالاها', 'products.view', rows("SELECT id, name, barcode FROM products WHERE name LIKE ? OR barcode=? OR base_code=? LIMIT 8", [$like, $q, $q]), fn($r) => [$r['name'], (string)$r['barcode'], page_url('product', ['id' => $r['id']])]);
    $u = wh_unit_by_code(scan_normalize($q)); if ($u) $add('دانه (کدِ ۳۳ نویسه)', 'stock.view', [$u], fn($r) => [(string)val("SELECT name FROM products WHERE id=?", [$r['product_id']]), 'وضعیت: ' . $r['status'], page_url('product', ['id' => $r['product_id']])]);
    $add('فروش‌ها', 'docs.view', rows("SELECT id, number, total, d FROM sales WHERE number LIKE ? ORDER BY id DESC LIMIT 8", [$like]), fn($r) => [fa_num($r['number']), fa_money($r['total']) . ' — ' . jdate($r['d']), page_url('sale', ['id' => $r['id']])]);
    $add('فاکتورها', 'docs.view', rows("SELECT id, number, kind, buyer FROM docs WHERE number LIKE ? OR buyer LIKE ? ORDER BY id DESC LIMIT 8", [$like, $like]), fn($r) => [(doc_kinds()[$r['kind']] ?? '') . ' ' . fa_num((string)$r['number']), (string)(json_decode((string)$r['buyer'], true)['name'] ?? ''), page_url('doc', ['id' => $r['id']])]);
    $add('تأمین‌کنندگان', 'purchase.view', rows("SELECT id, name, mobile FROM suppliers WHERE name LIKE ? OR mobile LIKE ? LIMIT 6", [$like, $like]), fn($r) => [$r['name'], fa_num((string)$r['mobile']), page_url('suppliers', ['id' => $r['id']])]);
    $add('ضمانت', 'warranty.manage', warranty_find($q), fn($r) => [$r['code'] . ' — ' . $r['product_title'], warranty_statuses()[$r['status']] ?? '', page_url('warranty_view', ['id' => $r['id']])]);
    $add('چک', 'cheques.manage', rows("SELECT id, number, party_name, amount FROM cheques WHERE number=? OR sayad=? LIMIT 6", [$q, $q]), fn($r) => ['چکِ ' . fa_num($r['number']) . ' — ' . $r['party_name'], fa_money($r['amount']), page_url('cheques')]);
    $add('کارکنان', 'hr.view', rows("SELECT id, name FROM employees WHERE name LIKE ? LIMIT 6", [$like]), fn($r) => [$r['name'], '', page_url('employee', ['id' => $r['id']])]);
    $add('نامه‌ها', 'letters.manage', rows("SELECT id, full_no, subject FROM letters WHERE subject LIKE ? OR full_no LIKE ? OR to_name LIKE ? ORDER BY id DESC LIMIT 6", [$like, $like, $like]), fn($r) => [$r['subject'], fa_num((string)$r['full_no']), page_url('letters', ['id' => $r['id']])]);
    $pg = []; foreach (pages_all() as $k => [$t, $cap, $grp, $show]) if ($show && mb_stripos($t, $q) !== false && ($cap === 'login' || can($cap))) $pg[] = [$t, $grp, page_url($k)];
    if ($pg) $o[] = ['title' => 'صفحه‌ها', 'items' => array_slice($pg, 0, 8)];
    return $o;
}

// ── تیکت: یک جدول برای کارمند و فروشنده؛ پشتیبان (tickets.manage) همه را می‌بیند
function ticket_depts(): array { $o = array_values(array_filter(array_map('trim', explode(',', (string)setting('ticket_depts', 'فنی,مالی,انبار,عمومی'))))); return $o ?: ['عمومی']; }
function ticket_statuses(): array { return ['open' => 'باز', 'answered' => 'پاسخ داده شد', 'pending' => 'در انتظارِ پشتیبان', 'closed' => 'بسته']; }
function ticket_can_view(array $t): bool { $u = current_user(); return $u && ((int)$t['opener_id'] === (int)$u['id'] || can('tickets.manage')); }
function ticket_get(int $id): array { $t = row("SELECT t.*, u.name opener FROM tickets t JOIN users u ON u.id=t.opener_id WHERE t.id=?", [$id]); if (!$t || !ticket_can_view($t)) throw new UserError('تیکت یافت نشد', 404); return $t; }
function ticket_file_in(array $f): ?string {
    if (($f['error'] ?? 4) === 4) return null; if (($f['error'] ?? 1) !== 0 || (int)$f['size'] > PAYREQ_MAX_SIZE) throw new UserError('پیوست بارگذاری نشد (حداکثر ۵ مگابایت)');
    $tmp = (string)$f['tmp_name']; if (!is_uploaded_file($tmp) && !defined('PAYREQ_TEST')) throw new UserError('پیوست نامعتبر'); $mime = payreq_sniff($tmp); if (!$mime) throw new UserError('پیوست فقط تصویر یا PDF');
    $dir = DATA . '/uploads/tickets'; if (!is_dir($dir)) @mkdir($dir, 0775, true); $name = bin2hex(random_bytes(10)) . '.' . ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime];
    defined('PAYREQ_TEST') ? copy($tmp, "$dir/$name") : move_uploaded_file($tmp, "$dir/$name"); return 'tickets/' . $name;
}
function ticket_open(array $in, array $file = []): int {
    $u = require_login(); $subj = trim((string)($in['subject'] ?? '')); $body = trim((string)($in['body'] ?? ''));
    if ($subj === '' || $body === '') throw new UserError('موضوع و متن لازم است');
    $dept = in_array($in['dept'] ?? '', ticket_depts(), true) ? $in['dept'] : ticket_depts()[0];
    return tx(function () use ($u, $subj, $body, $dept, $in, $file) {
        $no = 'T' . substr(jtoday(), 2, 2) . str_pad((string)((int)val("SELECT COUNT(*) FROM tickets") + 1), 5, '0', STR_PAD_LEFT);
        $id = ins('tickets', ['no' => $no, 'subject' => mb_cut($subj, 200), 'dept' => $dept, 'priority' => in_array($in['priority'] ?? '', ['low', 'normal', 'high'], true) ? $in['priority'] : 'normal', 'status' => 'pending',
            'opener_id' => $u['id'], 'opener_kind' => $u['kind'] === 'seller' ? 'seller' : 'staff', 'created_at' => now(), 'updated_at' => now()]);
        ins('ticket_msgs', ['ticket_id' => $id, 'user_id' => $u['id'], 'body' => $body, 'file' => ticket_file_in($file), 'created_at' => now()]);
        pn_add('ticket', 'تیکتِ تازه ' . $no . ' از ' . $u['name'] . ': ' . $subj, '', page_url('tickets', ['id' => $id]), 'tickets.manage');
        return $id;
    });
}
function ticket_reply(int $tid, array $in, array $file = []): void {
    $t = ticket_get($tid); $u = current_user(); $body = trim((string)($in['body'] ?? '')); if ($body === '') throw new UserError('متن لازم است');
    $support = can('tickets.manage') && (int)$t['opener_id'] !== (int)$u['id']; $internal = $support && !empty($in['internal']);
    ins('ticket_msgs', ['ticket_id' => $tid, 'user_id' => $u['id'], 'body' => $body, 'internal' => $internal ? 1 : 0, 'file' => ticket_file_in($file), 'created_at' => now()]);
    if (!$internal) upd('tickets', ['status' => $support ? 'answered' : 'pending', 'updated_at' => now(), 'closed_at' => null], 'id=?', [$tid]);
    if ($support && !$internal) pn_add('ticket_ans', 'پاسخِ تیکتِ ' . $t['no'], '', page_url('tickets', ['id' => $tid]), '');
    elseif (!$support) pn_add('ticket', 'پاسخِ تازه در تیکتِ ' . $t['no'], '', page_url('tickets', ['id' => $tid]), 'tickets.manage');
}
function ticket_close(int $tid): void { ticket_get($tid); upd('tickets', ['status' => 'closed', 'closed_at' => now(), 'updated_at' => now()], 'id=?', [$tid]); }
function tickets_list(string $st = ''): array {
    $u = current_user(); $w = can('tickets.manage') ? '1=1' : 't.opener_id=' . (int)$u['id'];
    return rows("SELECT t.*, u.name opener FROM tickets t JOIN users u ON u.id=t.opener_id WHERE $w" . ($st ? " AND t.status=" . db()->quote($st) : " AND t.status<>'closed'") . " ORDER BY FIELD(t.priority,'high','normal','low'), t.updated_at DESC LIMIT 300");
}
function ticket_messages(int $tid): array { $all = can('tickets.manage'); return rows("SELECT m.*, u.name, u.kind FROM ticket_msgs m JOIN users u ON u.id=m.user_id WHERE m.ticket_id=?" . ($all ? '' : ' AND m.internal=0') . " ORDER BY m.id", [$tid]); }

// ── نامهٔ اداری: شماره از عددِ پایه (۵ رقمی)، فقط پس از «نهایی»؛ نامهٔ نهایی ویرایش نمی‌شود
function letter_full_no(int $no, string $d): string { $j = jdate($d, false, false); return strtr((string)setting('letter_no_format', '{no}/{y}'), ['{no}' => (string)$no, '{y}' => substr($j, 0, 4), '{yy}' => substr($j, 2, 2), '{m}' => substr($j, 5, 2)]); }
function letter_save(array $in): int {
    $id = (int)($in['id'] ?? 0); if ($id && val("SELECT status FROM letters WHERE id=?", [$id]) !== 'draft') throw new UserError('نامهٔ نهایی ویرایش نمی‌شود');
    $d = ['d' => jparse((string)($in['d'] ?? '')) ?: today(), 'subject' => trim((string)($in['subject'] ?? '')), 'to_name' => trim((string)($in['to_name'] ?? '')) ?: null, 'to_title' => trim((string)($in['to_title'] ?? '')) ?: null,
        'body' => trim((string)($in['body'] ?? '')), 'cc' => trim((string)($in['cc'] ?? '')) ?: null, 'attach_note' => trim((string)($in['attach_note'] ?? '')) ?: null, 'sign_on' => empty($in['sign_on']) ? 0 : 1, 'stamp_on' => empty($in['stamp_on']) ? 0 : 1];
    if ($d['subject'] === '' || $d['body'] === '') throw new UserError('موضوع و متنِ نامه لازم است');
    if ($id) { upd('letters', $d, 'id=?', [$id]); return $id; }
    return ins('letters', $d + ['created_by' => current_user()['id'] ?? null, 'created_at' => now()]);
}
function letter_finalize(int $id): string {
    return tx(function () use ($id) { $l = row("SELECT * FROM letters WHERE id=? FOR UPDATE", [$id]); if (!$l || $l['status'] !== 'draft') throw new UserError('این نامه پیش‌نویس نیست');
        $no = max((int)setting('letter_no_start', '10250'), (int)val("SELECT COALESCE(MAX(no),0)+1 FROM letters")); $full = letter_full_no($no, $l['d']);
        upd('letters', ['no' => $no, 'full_no' => $full, 'status' => 'final', 'finalized_at' => now()], 'id=?', [$id]); audit('letters', $id, 'final', null, ['no' => $full], 'نامهٔ اداری'); return $full; });
}
function letter_html(array $l): string {
    $b = biz(); $dj = jdate($l['d']); $img = fn($u, $c) => $u ? '<img class="' . $c . '" src="' . h($u) . '" alt="">' : '';
    return '<div class="paper letter"><header class="lh">' . $img($b['logo'], 'lg') . '<div><b>' . h($b['name']) . '</b><small>' . h((string)setting('letter_tagline', '')) . '</small></div><dl><dt>شماره</dt><dd>' . h(fa_num((string)($l['full_no'] ?: '— پیش‌نویس —'))) . '</dd><dt>تاریخ</dt><dd>' . h($dj) . '</dd><dt>پیوست</dt><dd>' . h($l['attach_note'] ?: 'ندارد') . '</dd></dl></header>'
        . '<main>' . ($l['to_name'] ? '<p class="to">' . h($l['to_name']) . ($l['to_title'] ? '<br>' . h($l['to_title']) : '') . '</p>' : '') . '<p class="sb"><b>موضوع:</b> ' . h($l['subject']) . '</p><div class="bd">' . nl2br(h($l['body'])) . '</div>'
        . '<div class="sg">' . ($l['stamp_on'] ? $img($b['stamp'], 'st') : '') . ($l['sign_on'] ? $img($b['sign'], 'si') : '') . '<p>' . h((string)setting('letter_signer', '')) . '</p></div>' . ($l['cc'] ? '<p class="cc">رونوشت: ' . h($l['cc']) . '</p>' : '') . '</main>'
        . '<footer>' . h(trim(($b['address'] ?? '') . ' · ' . fa_num((string)($b['phone'] ?? '')) . ' · ' . (string)setting('biz_website', ''), ' ·')) . '</footer></div>';
}
