<?php
// پشتیبانِ کامل (تکه‌تکه، با نوارِ پیشرفت) و ابزارِ داده
$j = bk_job(); $busy = $j && empty($j['done']);
$h = '<p class="muted">پشتیبان‌ها در پوشهٔ data (بیرون از دسترسِ وب) نگه داشته می‌شوند. پیش از هر بازگرداندن، از وضعِ فعلی پشتیبان گرفته می‌شود. config.local.php هیچ‌وقت عوض نمی‌شود.</p>'
    . '<div class="actions">' . implode(' ', array_map(fn($k, $t) => '<button type="button" class="btn bkgo" data-type="' . $k . '">پشتیبان: ' . h($t) . '</button>', array_keys(bk_types()), bk_types())) . '</div>'
    . '<div id="bkprog" class="muted">' . ($busy ? 'کارِ نیمه‌تمام: ' . h($j['op'] === 'backup' ? 'ساختن ' : 'بازگرداندن ') . h($j['name']) . ' — <button type="button" class="btn sm bkcont">ادامه</button>' : (!empty($j['error']) ? '<span class="err">آخرین کار خطا داد: ' . h($j['error']) . '</span>' : '')) . '</div>';
echo ui_card('پشتیبان‌گیری', $h);
$l = bk_list();
$open = (int)($_SESSION['bk_ok'] ?? 0) > time() - 600;
echo ui_card('پشتیبان‌ها (' . fa_num((string)count($l)) . ')', ($open ? '<p class="ok">دانلود، بازگرداندن و حذف تا ۱۰ دقیقه باز است.</p>' : ui_form('backup_unlock', ['pass' => ['رمزِ خودتان (برای دانلود، بازگرداندن یا حذف؛ ۱۰ دقیقه)', 'password', 1]], [], ['submit' => 'تأیید', 'then' => 'reload']))
    . ui_table(['name' => ['پرونده', fn($r) => '<span dir="ltr">' . h($r['name']) . '</span>'], 't' => ['نوع', fn($r) => h(bk_types()[$r['m']['type'] ?? ''] ?? '?') . ' — ' . h((string)($r['m']['label'] ?? ''))], 'at' => ['زمان', 'datetime'], 'size' => ['حجم', fn($r) => bk_size($r['size'])], 'v' => ['نسخه', fn($r) => h(fa_num((string)($r['m']['version'] ?? '')))],
        'a' => ['', fn($r) => $open ? '<a class="btn sm" href="' . h(page_url('backup', ['dl' => $r['name']])) . '">دانلود</a> '
            . ui_btn_api('بازگرداندن', 'backup_restore', ['name' => $r['name'], 'with_files' => 1], ['class' => 'sm danger', 'confirm' => 'همهٔ داده‌های فعلی با «' . $r['name'] . '» جایگزین شود؟ پیش از آن از وضعِ فعلی پشتیبان گرفته می‌شود.', 'then' => 'none']) . ' '
            . ui_btn_api('حذف', 'backup_delete', ['name' => $r['name']], ['class' => 'sm', 'confirm' => 'حذف شود؟']) : '']], $l, ['empty' => 'هنوز پشتیبانی نیست.'])
    . '<details><summary>بارگذاریِ پروندهٔ پشتیبان</summary>' . ui_form('backup_upload', [], [], ['multipart' => 1, 'submit' => 'بارگذاری', 'extra' => '<input type="file" name="file" accept=".tar" required>']) . '</details>');
echo ui_card('پشتیبانِ خودکار', ui_form('settings_save', ['backup_auto' => ['هر روز خودکار (با cron)', 'select', 1, ['1' => 'روشن', '0' => 'خاموش']], 'backup_auto_type' => ['نوع', 'select', 1, ['db' => 'پایگاه‌داده', 'all' => 'همه']], 'backup_keep' => ['چند پشتیبانِ آخر نگه داشته شود', 'int']],
    ['backup_auto' => setting('backup_auto', '1'), 'backup_auto_type' => setting('backup_auto_type', 'db'), 'backup_keep' => setting('backup_keep', '7')]));
$x = ''; foreach (ds_sets() as $k => $d) $x .= '<a class="btn sm" href="' . h(page_url('backup', ['export' => $k])) . '">' . h($d[0]) . ' (CSV)</a> ';
echo ui_card('ابزارِ داده', '<p>برون‌بری (بی رمز و دادهٔ حساس): ' . $x . '</p><h3>درون‌ریزی</h3><p class="muted">کالا با بارکد/نام و مشتری با موبایل شناخته و به‌روز می‌شوند؛ تازه‌ها ساخته می‌شوند. ستون‌ها همان ستون‌های برون‌بری‌اند. اول «آزمایشی» بزنید.</p>'
    . ui_form('ds_import', ['set' => ['نوع', 'select', 1, ['products' => 'کالاها', 'customers' => 'مشتریان']], 'dry' => ['آزمایشی (ذخیره نکن)', 'check']], ['dry' => 1], ['multipart' => 1, 'submit' => 'درون‌ریزی', 'then' => 'none', 'extra' => '<input type="file" name="file" accept=".csv" required>']));
?>
<script>document.addEventListener('DOMContentLoaded', function () { var C = window.Cell, box = document.getElementById('bkprog');
  function run() { C.api('backup_step', {}).then(function (j) { if (j.error) { box.innerHTML = '<span class="err">خطا: ' + C.esc(j.error) + '</span>'; return; }
      box.textContent = (j.op === 'backup' ? 'در حالِ ساختنِ ' : 'در حالِ بازگرداندنِ ') + j.name + ' — ' + (j.phase === 'db' ? 'پایگاه‌داده' : j.phase === 'files' ? 'پرونده‌ها' : j.phase) + (j.rows ? ' (' + C.fa(String(j.rows)) + ' ردیف)' : '') + (j.stmts ? ' (' + C.fa(String(j.stmts)) + ' دستور)' : '') + ' …';
      if (j.done) { box.innerHTML = '<span class="ok">✅ انجام شد: ' + C.esc((j.log || []).join('، ')) + '</span>'; setTimeout(function () { location.reload(); }, 1200); } else setTimeout(run, 300); }).catch(function (e) { box.textContent = e.message; }); }
  document.querySelectorAll('.bkgo').forEach(function (b) { b.onclick = function () { C.api('backup_start', { type: b.getAttribute('data-type') }).then(run).catch(function (e) { C.toast(e.message, 'e'); }); }; });
  var c = document.querySelector('.bkcont'); if (c) c.onclick = run;
  document.addEventListener('click', function (e) { var t = e.target; if (t.getAttribute && t.getAttribute('data-act') === 'backup_restore') setTimeout(function () { run(); }, 800); });
});</script>
