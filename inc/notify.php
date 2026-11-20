<?php
// هشدار به مدیران و پیام به مشتری: پیامک (SMS.IR با قالب) و بلهٔ اختیاری
//  - گیرندهٔ هشدار = هر کاربرِ فعال با توانایی notify.alerts (مدیرِ ارشد هم)؛ پیامک به موبایلش، بله به گفت‌وگوی متصل‌شده‌اش.
//  - بله (هم‌شکلِ API تلگرام، tapi.bale.ai) — روالِ Weby balebot.php: توکن خالی = خاموش؛ اتصال با کدی که کاربر/مشتری برای بات می‌فرستد.
//  - هر رویداد برای هر گیرنده یک بار (notify_log یکتا روی event_key+channel+target) — اجرای دوبارهٔ cron پیامِ تکراری نمی‌فرستد.
//  - شبکه فقط در cron یا با دکمه در پنل؛ مسیرِ کاربر هرگز منتظرِ بله یا پیامک نمی‌ماند.
declare(strict_types=1);

function bale_token(): string { return trim((string)setting('bale_bot_token', '')); }
function bale_on(): bool { return bale_token() !== ''; }
function bale_api(string $method, array $params = []): array {
    if (!bale_on()) return ['ok' => false, 'description' => 'بات بله تنظیم نشده است'];
    if (isset($GLOBALS['__bale_fake'])) return ($GLOBALS['__bale_fake'])($method, $params); // فقط در آزمون
    $ch = curl_init('https://tapi.bale.ai/bot' . bale_token() . '/' . $method);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 12, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);
    $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($raw === false) return ['ok' => false, 'description' => 'ارتباط با بله برقرار نشد: ' . $err];
    $j = json_decode((string)$raw, true); return is_array($j) ? $j : ['ok' => false, 'description' => 'پاسخِ نامعتبر از بله'];
}
function bale_send(string $chatId, string $text): array { $r = bale_api('sendMessage', ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 3900)]); return ['ok' => !empty($r['ok']), 'msg' => (string)($r['description'] ?? (!empty($r['ok']) ? 'رفت' : 'نرفت'))]; }
/** کدِ اتصال برای کاربر یا مشتری (همان کد تا وقتی وصل نشده) */
function bale_link_code(string $tbl, int $id): string {
    if (!in_array($tbl, ['users', 'customers'], true)) throw new UserError('نامعتبر');
    $c = (string)val("SELECT bale_code FROM `$tbl` WHERE id=?", [$id]);
    if ($c === '') { do { $c = ($tbl === 'users' ? 'U' : 'C') . random_int(10000, 99999); } while (val("SELECT id FROM users WHERE bale_code=?", [$c]) || val("SELECT id FROM customers WHERE bale_code=?", [$c])); upd($tbl, ['bale_code' => $c], 'id=?', [$id]); }
    return $c;
}
function bale_link_url(string $code): string { $u = trim((string)setting('bale_bot_username', ''), '@ '); return $u !== '' ? 'https://ble.ir/' . rawurlencode($u) . '?start=' . rawurlencode($code) : ''; }
/** خواندنِ پیام‌های تازهٔ بات: کدِ اتصال را پیدا و گفت‌وگو را وصل می‌کند */
function bale_poll(): int {
    if (!bale_on()) return 0;
    $r = bale_api('getUpdates', ['offset' => (int)setting('bale_offset', '0'), 'timeout' => 0, 'limit' => 100]); $n = 0;
    foreach ((array)($r['result'] ?? []) as $u) {
        settings_save(['bale_offset' => (string)((int)$u['update_id'] + 1)], false);
        $m = $u['message'] ?? null; if (!$m || empty($m['chat']['id'])) continue;
        if (!preg_match('/\b([UC]\d{5})\b/', strtoupper(en_num((string)($m['text'] ?? ''))), $x)) continue;
        $tbl = $x[1][0] === 'U' ? 'users' : 'customers'; $id = (int)val("SELECT id FROM `$tbl` WHERE bale_code=?", [$x[1]]); if (!$id) continue;
        upd($tbl, ['bale_chat_id' => (string)$m['chat']['id'], 'bale_code' => null], 'id=?', [$id]); $n++;
        audit($tbl, $id, 'bale_link', null, ['chat' => (string)$m['chat']['id']], 'اتصال به بله');
        bale_send((string)$m['chat']['id'], '✅ به ' . setting('biz_name', '8CELL') . ' وصل شدید. هشدارها و یادآوری‌ها از همین‌جا می‌آید.');
    }
    return $n;
}

/** گیرندگانِ هشدار: کاربرانِ فعال با توانایی notify.alerts */
function notify_recipients(): array {
    $o = [];
    foreach (rows("SELECT id FROM users WHERE active=1 AND kind='staff'") as $r) { $u = user_load((int)$r['id']); if ($u && can('notify.alerts', $u)) $o[] = $u; }
    return $o;
}
/** یک بار برای هر رویداد و گیرنده و کانال */
function notify_once(string $key, string $channel, string $target, callable $send, string $text = ''): ?bool {
    if ($target === '') return null;
    if (val("SELECT ok FROM notify_log WHERE event_key=? AND channel=? AND target=? AND ok=1", [$key, $channel, $target]) !== null) return null;
    try { $r = $send(); } catch (Throwable $e) { $r = ['ok' => false, 'msg' => $e->getMessage()]; }
    q("INSERT INTO notify_log (event_key, channel, target, ok, msg, text, at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE ok=VALUES(ok), msg=VALUES(msg), at=VALUES(at)",
        [mb_cut($key, 120), $channel, mb_cut($target, 60), !empty($r['ok']) ? 1 : 0, mb_cut((string)($r['msg'] ?? ''), 500), mb_cut($text, 2000), now()]);
    return !empty($r['ok']);
}
/** هشدار به مدیران: متنِ کامل در بله، پیامکِ قالبی (اگر قالبش تعریف و روشن باشد) */
function notify_managers(string $key, string $text, string $smsKey = '', array $smsParams = []): int {
    $n = 0; notify_once($key, 'panel', 'all', fn() => ['ok' => (bool)pn_add(strtok($key, ':'), $text)], $text); // زنگولهٔ پنل هم
    foreach (notify_recipients() as $u) {
        if (bale_on() && setting('notify_bale', '1') === '1' && !empty($u['bale_chat_id'])) $n += (int)(bool)notify_once($key, 'bale', 'u' . $u['id'], fn() => bale_send((string)$u['bale_chat_id'], '🔔 ' . $text), $text);
        if ($smsKey && setting('notify_sms', '1') === '1' && !empty($u['mobile']) && sms_tpl_enabled($smsKey)) $n += (int)(bool)notify_once($key, 'sms', (string)$u['mobile'], fn() => sms_send_tpl((string)$u['mobile'], $smsKey, $smsParams, 'notify', ['ref_type' => 'notify', 'ref_id' => null]), $text);
    }
    return $n;
}
/** پیام به مشتری/همکار */
function notify_customer(string $key, int $cid, string $text, bool $sms, bool $bale, string $smsKey = '', array $smsParams = []): int {
    $c = row("SELECT id, name, mobile, bale_chat_id FROM customers WHERE id=?", [$cid]); if (!$c) return 0; $n = 0;
    if ($bale && bale_on() && $c['bale_chat_id']) $n += (int)(bool)notify_once($key, 'bale', 'c' . $cid, fn() => bale_send((string)$c['bale_chat_id'], $text), $text);
    if ($sms && $smsKey && $c['mobile'] && sms_tpl_enabled($smsKey)) $n += (int)(bool)notify_once($key, 'sms', (string)$c['mobile'], fn() => sms_send_tpl((string)$c['mobile'], $smsKey, $smsParams, 'notify', ['ref_type' => 'customer', 'ref_id' => $cid]), $text);
    return $n;
}

/** کارِ زمان‌بندی‌شده: cron یا دکمهٔ «اجرای یادآوری‌ها» */
function notify_run(): array {
    $o = ['bale_links' => 0, 'promises' => 0, 'cheques' => 0, 'stock' => 0];
    try { $o['bale_links'] = bale_poll(); } catch (Throwable $e) { error_log('bale poll: ' . $e->getMessage()); }
    try { $o['promises'] = promise_reminders(); } catch (Throwable $e) { error_log('promises: ' . $e->getMessage()); }
    try { $o['cheques'] = cheque_reminders(); } catch (Throwable $e) { error_log('cheques: ' . $e->getMessage()); }
    try { $o['warranty_expired'] = warranty_expire_due(); } catch (Throwable $e) { error_log('warranty expire: ' . $e->getMessage()); }
    try { $o['stock'] = stock_low_digest(); } catch (Throwable $e) { error_log('stock digest: ' . $e->getMessage()); }
    settings_save(['notify_last_run' => now()], false);
    return $o;
}
/** خلاصهٔ روزانهٔ کالاهای رو به اتمام (یک بار در روز) */
function stock_low_digest(): int {
    if (setting('notify_stock', '1') !== '1') return 0;
    $rows = low_stock_report(['only' => 'low']); if (!$rows) return 0;
    $t = 'کالاهای رو به اتمام (' . fa_num(count($rows)) . "):\n" . implode("\n", array_map(fn($r) => '• ' . $r['name'] . ' — موجودی ' . fa_num($r['on_hand']) . ($r['days_left'] !== null ? '، حدودِ ' . fa_num($r['days_left']) . ' روز' : ''), array_slice($rows, 0, 30)));
    return notify_managers('stock:' . today(), $t, 'stock_low', ['COUNT' => (string)count($rows)]);
}
