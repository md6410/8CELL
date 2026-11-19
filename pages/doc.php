<?php
$id = req_int('id'); $d = $id ? doc_get($id) : null;
if ($id && !$d) throw new UserError('سند یافت نشد');
$editable = !$d || $d['status'] === 'draft';
if ($d && !$editable) {
    $iss = official_issues($d); $ps = doc_pay_state($d);
    $act = '<a class="btn sm" target="_blank" href="index.php?p=print_doc&id=' . $d['id'] . '">چاپ / PDF</a> ' . ui_btn_api('لینکِ رمزدار', 'doc_share', ['id' => $d['id']], ['class' => 'sm', 'prompt' => 'pass:رمزِ لینک (خالی = بی‌رمز)', 'then' => 'none'])
        . ($d['status'] === 'issued' && can('docs.issue') && !$d['confirmed'] ? ' ' . ui_btn_api('ابطال', 'doc_void', ['id' => $d['id']], ['class' => 'sm danger', 'prompt' => 'reason:دلیلِ ابطال']) : '')
        . (acc_confirmable($d) && !$d['confirmed'] && can('acc.share') ? ' ' . ui_btn_api('تأییدِ خریدار (برگشت‌ناپذیر)', 'doc_confirm', ['id' => $d['id']], ['class' => 'sm', 'confirm' => 'پس از تأیید فاکتور دیگر عوض نمی‌شود. ادامه؟']) : '');
    echo ui_card((doc_kinds()[$d['kind']] ?? '') . ' ' . fa_num($d['number']), '<dl class="kv"><dt>وضعیت</dt><dd>' . h($d['status'] === 'void' ? 'باطل' : 'ثبت‌شده') . ' · نسخهٔ ' . fa_num($d['revision']) . '</dd><dt>وضعیتِ پرداخت</dt><dd>' . h($ps['label']) . ' — مانده ' . ui_money($ps['remain']) . '</dd>'
        . ($d['kind'] === 'official' ? '<dt>سامانهٔ مودیان</dt><dd>' . h(moadian_states()[$d['moadian'] ?? 'none'] ?? '') . ' · موضوع: ' . h(doc_subjects()[$d['subject'] ?? 'main'] ?? '') . '</dd>' : '')
        . '<dt>تأییدِ اصالت</dt><dd><a target="_blank" href="v.php?t=' . h($d['verify_token']) . '">v.php?t=' . h($d['verify_token']) . '</a></dd></dl>' . ($iss['W'] ? '<p class="muted">هشدار: ' . h(implode('، ', $iss['W'])) . '</p>' : ''), $act);
    if ($d['confirmed'] && $ps['remain'] > 0 && can('acc.share')) echo ui_card('ثبتِ واریز', ui_form('doc_pay', ['amount' => ['مبلغ', 'money', 1, null, $ps['remain']], 'd' => ['تاریخ', 'date'], 'ref' => ['شمارهٔ پیگیری', 'text']], [], ['hidden' => ['id' => $d['id']]]));
    echo '<link rel="stylesheet" href="assets/print.css"><div style="background:#ddd;padding:10px;overflow:auto">' . doc_render_html($d) . '</div><script src="assets/vendor/qrcode.js"></script><script>document.querySelectorAll(".qr").forEach(function(e){var q=qrcode(0,"M");q.addData(e.getAttribute("data-qr"));q.make();e.innerHTML=q.createSvgTag({margin:0,scalable:true});});</script>';
    $revs = rows("SELECT revision, hash, at FROM doc_revisions WHERE doc_id=? ORDER BY revision", [$d['id']]);
    echo ui_card('نسخه‌ها', ui_table(['revision' => ['نسخه', 'num'], 'at' => ['زمان', 'datetime'], 'hash' => ['اثرِ انگشت (sha256)', 'code']], $revs));
    if ($d['status'] === 'issued' && !$d['confirmed'] && can('docs.issue')) echo '<p><a class="btn" href="' . h(page_url('doc', ['id' => $d['id'], 'amend' => 1])) . '">ساختنِ نسخهٔ اصلاحی</a></p>';
    if (!req_int('amend')) return;
}
$kind = $d['kind'] ?? (isset(doc_kinds()[$_GET['kind'] ?? '']) ? $_GET['kind'] : 'invoice');
$b = $d['buyer'] ?? []; $o = $d['opts'] ?? [];
$lines = array_map(fn($l) => ['product_id' => $l['product_id'] ?? '', 'title' => $l['title'], 'qty' => (int)$l['qty'] / max(1, (int)unit_of($l['unit_id'] ?? null)['scale']), 'price' => $l['price'], 'disc' => $l['disc'] ?? 0, 'usd' => $l['usd'] ?? ''], $d['lines'] ?? []);
$amend = $d && $d['status'] === 'issued';
$f = ['kind' => ['نوع', 'select', 1, doc_kinds()], 'd' => ['تاریخ', 'date', 1], 'customer_id' => ['', 'hidden'], 'cust' => ['مشتری (جست‌وجو؛ یا خریدارِ دستی پایین)', 'text'],
    'buyer_kind' => ['نوعِ خریدار', 'select', 0, ['person' => 'حقیقی', 'legal' => 'حقوقی']], 'buyer_name' => ['نامِ خریدار', 'text'], 'buyer_national_id' => ['کد/شناسهٔ ملی', 'text'], 'buyer_postal' => ['کدِ پستی', 'text'], 'buyer_mobile' => ['موبایل', 'text'], 'buyer_address' => ['نشانی', 'text'],
    'doc_disc' => ['تخفیفِ کل', 'money'], 'extra_title' => ['عنوانِ هزینهٔ سایر', 'text'], 'extra' => ['مبلغِ سایر', 'money'], 'vat_pct' => ['نرخِ مالیات ٪ (خالی = پیش‌فرض)', 'float'], 'note' => ['یادداشت', 'textarea']];
if ($kind === 'official') $f += ['subject' => ['موضوع', 'select', 1, doc_subjects()], 'ref_doc_id' => ['شناسهٔ صورتحسابِ مرجع (برای اصلاحی/ابطالی)', 'int']];
$vals = ['kind' => $kind, 'd' => $d['d'] ?? today(), 'customer_id' => $d['customer_id'] ?? '', 'cust' => $d && $d['customer_id'] ? ($b['name'] ?? '') : '', 'buyer_kind' => $b['kind'] ?? 'person', 'buyer_name' => $d && !$d['customer_id'] ? ($b['name'] ?? '') : '',
    'buyer_national_id' => $b['national_id'] ?? '', 'buyer_postal' => $b['postal_code'] ?? '', 'buyer_mobile' => $b['mobile'] ?? '', 'buyer_address' => $b['address'] ?? '', 'doc_disc' => $o['doc_disc'] ?? '', 'extra' => $o['extra'] ?? '', 'extra_title' => $o['extra_title'] ?? '', 'note' => $o['note'] ?? '', 'subject' => $d['subject'] ?? 'main', 'ref_doc_id' => $d['ref_doc_id'] ?? ''];
$ed = ui_lines('lines', [['k' => 'title', 't' => 'شرح'], ['k' => 'qty', 't' => 'تعداد', 'd' => '1', 'w' => '70px'], ['k' => 'price', 't' => 'فی (ریال)', 'type' => 'money'], ['k' => 'disc', 't' => 'تخفیف', 'type' => 'money', 'w' => '110px'], ['k' => 'usd', 't' => 'دلار (اختیاری)', 'w' => '90px']], $lines);
echo ui_card($amend ? 'نسخهٔ اصلاحیِ ' . fa_num($d['number']) : ($d ? 'پیش‌نویس' : 'سندِ تازه') . ' — شمارهٔ بعدی: ' . h(fa_num(peek_seq($kind, today()))),
    ui_form($amend ? 'doc_amend' : 'doc_save', $f, $vals, ['hidden' => $d ? ['id' => $d['id']] : [], 'extra' => '<h3>ردیف‌ها</h3>' . $ed . '<p id="calc" class="muted"></p>', 'then' => $amend ? 'go:' . page_url('doc', ['id' => $d['id']]) : 'go:index.php?p=doc&id={id}', 'submit' => $amend ? 'ثبتِ نسخهٔ اصلاحی' : 'ذخیرهٔ پیش‌نویس']));
if ($d && $d['status'] === 'draft' && can('docs.issue')) {
    $iss = official_issues($d);
    echo ui_card('ثبت و شماره‌گذاری', ($iss['E'] ? '<ul class="err">' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $iss['E'])) . '</ul>' : '') . ($iss['W'] ? '<ul class="muted">' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $iss['W'])) . '</ul>' : '') . ui_btn_api('ثبتِ نهایی (قفل و شماره)', 'doc_commit', ['id' => $d['id']], ['class' => 'primary', 'confirm' => 'پس از ثبت، سند قفل می‌شود. ادامه؟']));
}
?>
<script>document.addEventListener('DOMContentLoaded',function(){var C=window.Cell,f=document.querySelector('form[data-api]');var cq=f.querySelector('[name=cust]');if(cq)C.customerPicker(cq,function(c){f.customer_id.value=c.id;cq.value=c.name;});cq.addEventListener('input',function(){if(!cq.value)f.customer_id.value='';});
function calc(){var o=C.formObj(f);C.api('doc_calc',o).then(function(t){document.getElementById('calc').innerHTML=t.total?('ناخالص '+C.money(t.sub)+' · تخفیف '+C.money(t.lineDisc+t.docDisc)+' · مالیات '+C.money(t.vat)+' · <b>قابلِ پرداخت '+C.money(t.total)+'</b> — '+C.esc(t.words)):'';}).catch(function(e){document.getElementById('calc').textContent=e.message;});}
f.addEventListener('lines-change',calc);f.addEventListener('change',calc);calc();});</script>
