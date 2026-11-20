<?php
$u = current_user(); $shift = shift_of_user((int)$u['id']);
if (!$shift) {
    $cbs = array_filter(cashbox_list(), fn($c) => branch_allowed((int)$c['branch_id']));
    $busy = array_column(rows("SELECT cashbox_id, u.name FROM shifts s JOIN users u ON u.id=s.user_id WHERE s.status='open'"), 'name', 'cashbox_id');
    $opts = []; foreach ($cbs as $c) $opts[$c['id']] = $c['branch'] . ' — ' . $c['name'] . (isset($busy[$c['id']]) ? ' (شیفتِ باز: ' . $busy[$c['id']] . ')' : '');
    echo ui_card('بازکردنِ شیفت', '<p>هیچ فروشی بی‌شیفتِ باز ثبت نمی‌شود. صندوق را انتخاب و موجودیِ نقدِ اولیه را وارد کنید.</p>' . ui_form('shift_open', ['cashbox_id' => ['صندوق', 'select', 1, $opts], 'opening' => ['موجودیِ اولیهٔ نقد (ریال)', 'money', 0]], [], ['submit' => 'بازکردنِ شیفت']));
    return;
}
$cust = $shift; ?>
<div id="pos" class="pos" data-methods="<?= h(json_encode(pay_methods(), JSON_UNESCAPED_UNICODE)) ?>">
<div>
  <section class="card"><div class="grid" style="grid-template-columns:1fr 90px auto">
    <input id="posScan" class="scan-in" placeholder="اسکن یا تایپِ کد و Enter…" autocomplete="off" data-scan-keep data-scan-confirm><input class="qty" value="۱" title="تعداد" inputmode="numeric"><button class="btn cam" type="button">📷 دوربین</button></div>
    <div class="scan-view" id="scanBox" hidden style="margin-top:8px"><video id="scanVideo" playsinline muted autoplay></video><div class="scan-frame"></div><button type="button" class="btn sm" onclick="stopScan()">بستنِ دوربین</button></div><div class="msg muted" style="margin:6px 0;min-height:1.6em"></div>
    <div class="pick" style="position:relative"><input class="prod-q" placeholder="جست‌وجوی نامِ کالا…"></div></section>
  <section class="card"><div class="tw"><table class="tbl cart"><thead><tr><th>کالا</th><th>تعداد</th><th>فی</th><th>تخفیف</th><th>مبلغ</th><th></th></tr></thead><tbody></tbody></table></div>
    <div class="actions"><span class="sums muted"></span><button class="btn sm docdisc" type="button">تخفیفِ فاکتور</button></div></section>
  <div class="done"></div>
</div>
<div>
  <section class="card"><header><h2>مشتری</h2><button class="btn sm cust-new" type="button">+ تازه</button></header><div style="position:relative"><input class="cust-q" placeholder="نام یا موبایل…"></div><p class="cust-info"></p>
    <div class="partner" style="display:none"><label class="field"><span>نوعِ سفارشِ همکار</span><select class="pmode"><?php foreach (partner_modes() as $k => $t) echo '<option value="' . h($k) . '">' . h($t) . '</option>'; ?></select></label></div>
    <label class="field"><span>سررسیدِ نسیه (اختیاری)</span><input class="due jd" placeholder="۱۴۰۵/۰۹/۳۰"></label></section>
  <section class="card"><div class="tot">۰</div><h2 style="margin-top:10px">دریافت</h2><div class="pays"></div><button class="btn sm addpay" type="button">+ روشِ دیگر</button><div class="remain"></div>
    <div class="actions"><button class="btn primary big checkout" type="button">ثبتِ فروش</button></div></section>
  <section class="card"><p class="muted">شیفتِ <?= fa_num($shift['id']) ?> · <?= h($shift['cbname']) ?> · از <?= jdate($shift['opened_at'], true) ?></p><a class="btn" href="<?= h(page_url('shifts')) ?>">بستنِ شیفت</a></section>
</div></div>
<script src="assets/vendor/zxing.min.js" defer></script><script src="assets/scan-feedback.js?v=<?= APP_VERSION ?>" defer></script><script src="assets/scan-box.js?v=<?= APP_VERSION ?>" defer></script><script src="assets/pos.js?v=<?= APP_VERSION ?>" defer></script>
