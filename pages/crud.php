<?php
$defs = array_filter(crud_defs(), fn($d) => can($d[2]) || can('settings.view')); $e = (string)($_GET['e'] ?? array_key_first($defs));
echo '<nav class="tabs">' . implode('', array_map(fn($k, $d) => '<a href="' . h(page_url('crud', ['e' => $k])) . '"' . ($k === $e ? ' class="on"' : '') . '>' . h($d[0]) . '</a>', array_keys($defs), $defs)) . '</nav>';
if (!isset($defs[$e])) return; $d = $defs[$e]; $id = req_int('id'); $row = $id ? row("SELECT * FROM `{$d[1]}` WHERE id=?", [$id]) : null;
$cols = array_map(fn($f) => [$f[0], $f[1] === 'check' ? (fn($r) => '') : ($f[1] === 'money' ? 'money' : '')], $d[3]);
foreach ($d[3] as $k => $f) { if ($f[1] === 'check') $cols[$k] = [$f[0], fn($r) => !empty($r[$k]) ? '✔' : '']; if ($f[1] === 'select') { $o = $f[3] instanceof Closure ? $f[3]() : $f[3]; $cols[$k] = [$f[0], fn($r) => h($o[$r[$k]] ?? '')]; } }
if ($e === 'warehouses') $cols['code'] = ['کد', 'code'];
echo ui_card($d[0], ui_table($cols, rows("SELECT * FROM `{$d[1]}` ORDER BY id"), ['href' => fn($r) => page_url('crud', ['e' => $e, 'id' => $r['id']])]));
if (can($d[2])) echo ui_card($row ? 'ویرایش' : 'افزودن', ui_form('crud_save', $d[3], $row ?? ['active' => 1, 'scale' => 1], ['hidden' => ['_e' => $e] + ($row ? ['id' => $row['id']] : []), 'then' => 'go:' . page_url('crud', ['e' => $e]), 'after' => $row ? ui_btn_api(array_key_exists('active', $row) ? 'غیرفعال' : 'حذف', 'crud_delete', ['_e' => $e, 'id' => $row['id']], ['class' => 'danger', 'confirm' => 'مطمئنید؟', 'then' => 'go:' . page_url('crud', ['e' => $e])]) : '']));
