<?php
// پیامک فقط با قالبِ SMS.IR (verify)؛ دفترِ کامل، ارسالِ دوباره و حالتِ آزمایشی در تنها گلوگاه
declare(strict_types=1);

/** کلیدهای قالب: [عنوان، پارامترها، پارامترهای دقیق (بریدن ممنوع)] */
function sms_tpl_catalog(): array {
    return apply_filters('sms_tpl_catalog', [
        'otp' => ['کدِ ورود (خطِ آخرِ الگو: @دامنه #VERIFICATIONCODE#)', ['NAME', 'CODE', 'VERIFICATIONCODE'], ['CODE', 'VERIFICATIONCODE']],
        'vcode' => ['رمزِ دیدنِ سند (QRِ اصالت)', ['NAME', 'CODE', 'VERIFICATIONCODE'], ['CODE', 'VERIFICATIONCODE']],
        'sale_receipt' => ['رسیدِ فروش (پیامکِ نهایی)', ['NAME', 'NUMBER', 'AMOUNT', 'LINK'], ['NUMBER', 'AMOUNT', 'LINK']],
        'doc_link' => ['لینکِ فاکتور', ['NAME', 'NUMBER', 'LINK'], ['NUMBER', 'LINK']],
        'credit_due' => ['یادآوریِ بدهی', ['NAME', 'AMOUNT'], ['AMOUNT']],
        'catalog_link' => ['لینکِ کاتالوگ', ['NAME', 'LINK'], ['LINK']],
        'partner_ship' => ['ارسال برای مشتریِ همکار', ['NAME', 'NUMBER', 'PARTNER'], ['NUMBER']],
        'stock_loss_confirm' => ['تأییدِ ثبتِ ضرر', ['CODE'], ['CODE']],
    ]);
}
function sms_templates(): array { return setting_json('sms_templates', []); }
function sms_tpl_id(string $key): string { return (string)(sms_templates()[$key]['id'] ?? ''); }
function sms_tpl_enabled(string $key): bool { $t = sms_templates()[$key] ?? null; return $t && !empty($t['id']) && ($t['on'] ?? 1); }
function sms_test_mode(): bool { return setting('sms_test_mode', '1') === '1'; }
function sms_retry_max(): int { return max(0, min(20, (int)setting('sms_retry_max', 6))); }
function sms_retry_gap(): int { return max(1, min(720, (int)setting('sms_retry_gap', 5))); }
/** صفِ تلاشِ دوباره (cron یا بازدیدِ پنل): فقط شکست‌های قابلِ تکرار، نه OTP و نه آزمون؛ تلاشِ n‌ام n×فاصله صبر می‌کند */
function sms_retry_due(int $limit = 20): int {
    $n = 0; $max = sms_retry_max(); if ($max <= 0) return 0;
    foreach (rows("SELECT * FROM sms_log WHERE ok=0 AND retryable=1 AND kind NOT IN ('otp','test') AND tries<=? ORDER BY id LIMIT " . (int)$limit, [$max]) as $r) {
        if (time() - strtotime($r['at']) < (int)$r['tries'] * sms_retry_gap() * 60) continue;
        try { sms_retry((int)$r['id']); $n++; } catch (Throwable $e) { upd('sms_log', ['retryable' => 0], 'id=?', [$r['id']]); }
    }
    return $n;
}
function sms_param_max(): int { return 25; }
/** پارامترهایی که بریدنشان معنا را خراب می‌کند (از Weby) */
function sms_exact_params(): array { return ['LINK', 'CODE', 'TOKEN', 'ORDER', 'ORDER_NUMBER', 'NUMBER', 'AMOUNT', 'FEE', 'CARD', 'HOLO', 'DATE', 'TRACK', 'MOBILE', 'VERIFICATIONCODE']; }
function sms_gate_why(string $key): string { if (!sms_tpl_id($key)) return 'قالبِ «' . (sms_tpl_catalog()[$key][0] ?? $key) . '» در تنظیماتِ پیامک ثبت نشده است'; if (!sms_tpl_enabled($key)) return 'این پیامک خاموش است'; return ''; }

/** هماهنگیِ پارامترها با سقفِ پنل: دقیق‌ها بریده نمی‌شوند (خطا)، متن‌ها با «…» */
function sms_fit_params(array $params, array $exact = []): array {
    $out = []; $max = sms_param_max(); $exact = array_merge($exact, sms_exact_params());
    foreach ($params as $k => $v) {
        $v = trim(preg_replace('/\s+/u', ' ', (string)$v));
        if (mb_strlen($v) > $max) {
            if (in_array($k, $exact, true)) return ['params' => $params, 'error' => "پارامترِ $k بلندتر از $max نویسه است و نمی‌توان آن را برید"];
            $v = mb_substr($v, 0, $max - 1) . '…';
        }
        $out[$k] = $v;
    }
    return ['params' => $out, 'error' => ''];
}
/** تنها گلوگاهِ شبکه */
function smsir_request(string $path, array $payload): array {
    if (sms_test_mode()) return ['ok' => true, 'msg' => 'حالتِ آزمایشی — پیامک واقعاً ارسال نشد.', 'raw' => '', 'retryable' => false, 'test' => true];
    $key = trim((string)setting('smsir_api_key', ''));
    if ($key === '') return ['ok' => false, 'msg' => 'کلید API پنلِ پیامک ثبت نشده است.', 'raw' => '', 'retryable' => false];
    if (!function_exists('curl_init')) return ['ok' => false, 'msg' => 'cURL روی سرور نیست', 'raw' => '', 'retryable' => false];
    $ch = curl_init('https://api.sms.ir/v1/' . ltrim($path, '/'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'x-api-key: ' . $key, 'Expect:'],
        // کاربر پشتِ فرمِ ورود منتظر است؛ IPv6 روی بعضی هاست‌ها چند ثانیه تأخیر می‌اندازد
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);
    $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($raw === false) return ['ok' => false, 'msg' => 'ارتباط با سرورِ پیامک برقرار نشد: ' . $err, 'raw' => '', 'retryable' => true];
    $j = json_decode((string)$raw, true); $ok = (int)($j['status'] ?? 0) === 1;
    // هر شکست در خودِ تماس با پنل (شبکه، اعتبارِ تمام‌شده…) قابلِ تکرار است
    return ['ok' => $ok, 'msg' => (string)($j['message'] ?? ($ok ? 'ارسال شد' : 'پاسخِ نامعتبر از پنلِ پیامک')), 'raw' => mb_cut((string)$raw, 800), 'retryable' => !$ok];
}
function sms_send_template(string $mobile, string $tplId, array $params): array {
    $p = []; foreach ($params as $k => $v) $p[] = ['name' => (string)$k, 'value' => (string)$v];
    return smsir_request('send/verify', ['mobile' => en_num($mobile), 'templateId' => (int)$tplId, 'parameters' => $p]);
}
/** ارسال با کلیدِ قالب: همهٔ بن‌بست‌ها پیش از شبکه با پیامِ مشخص */
function sms_send_tpl(string $mobile, string $key, array $params, string $kind = 'notify', array $ctx = []): array {
    $mobile = norm_mobile($mobile);
    $cat = sms_tpl_catalog()[$key] ?? null;
    $params = apply_filters('sms_params', $params, $key, $ctx);
    $res = null;
    if (!$cat) $res = ['ok' => false, 'msg' => 'کلیدِ قالبِ ناشناخته', 'raw' => '', 'retryable' => false];
    elseif (!mobile_ok($mobile)) $res = ['ok' => false, 'msg' => 'شمارهٔ موبایل معتبر نیست', 'raw' => '', 'retryable' => false];
    elseif (($why = sms_gate_why($key)) !== '' && !sms_test_mode()) $res = ['ok' => false, 'msg' => $why, 'raw' => '', 'retryable' => false];
    else {
        $fit = sms_fit_params($params, $cat[2]);
        if ($fit['error']) $res = ['ok' => false, 'msg' => $fit['error'], 'raw' => '', 'retryable' => false];
        else { $params = $fit['params']; $res = sms_send_template($mobile, sms_tpl_id($key) ?: '0', $params); }
    }
    $res['log_id'] = sms_write_log($mobile, $kind, $key, $params, $res, $ctx);
    return $res;
}
function sms_write_log(string $mobile, string $kind, string $key, array $params, array $res, array $ctx): int {
    try {
        return ins('sms_log', ['at' => now(), 'mobile' => $mobile, 'kind' => $kind, 'ref_type' => $ctx['ref_type'] ?? null, 'ref_id' => $ctx['ref_id'] ?? null,
            'tpl_key' => $key, 'tpl_id' => sms_tpl_id($key), 'params' => $kind === 'otp' ? '{"CODE":"*****"}' : json_encode($params, JSON_UNESCAPED_UNICODE),
            'ok' => $res['ok'] ? 1 : 0, 'msg' => mb_cut((string)$res['msg'], 300), 'raw' => $res['raw'] ?? '', 'retryable' => !empty($res['retryable']) ? 1 : 0]);
    } catch (Throwable $e) { error_log('sms log: ' . $e->getMessage()); return 0; }
}
/** ارسالِ دوباره: فقط ناموفق، غیرِ OTP، زیرِ سقف */
function sms_retry(int $logId): array {
    $r = row("SELECT * FROM sms_log WHERE id=?", [$logId]);
    if (!$r) throw new UserError('ردیف یافت نشد');
    if ($r['ok']) throw new UserError('این پیامک قبلاً رفته است');
    if ($r['kind'] === 'otp') throw new UserError('کدِ یک‌بارمصرف دوباره فرستاده نمی‌شود؛ کدِ تازه بخواهید');
    if ((int)$r['tries'] > sms_retry_max()) throw new UserError('سقفِ تلاشِ دوباره پر شده است');
    $params = json_decode((string)$r['params'], true) ?: [];
    $cat = sms_tpl_catalog()[$r['tpl_key']] ?? [null, [], []];
    $fit = sms_fit_params($params, $cat[2]);
    $res = $fit['error'] ? ['ok' => false, 'msg' => $fit['error'], 'raw' => '', 'retryable' => false] : sms_send_template($r['mobile'], sms_tpl_id($r['tpl_key']) ?: '0', $fit['params']);
    upd('sms_log', ['ok' => $res['ok'] ? 1 : 0, 'msg' => mb_cut($res['msg'], 300), 'raw' => $res['raw'] ?? '', 'retryable' => !empty($res['retryable']) ? 1 : 0, 'tries' => (int)$r['tries'] + 1, 'at' => now()], 'id=?', [$logId]);
    return $res;
}
function sms_ref_log(string $type, int $id): array { return rows("SELECT * FROM sms_log WHERE ref_type=? AND ref_id=? ORDER BY id DESC", [$type, $id]); }

/** پیامکِ نهاییِ فروش — مشتری فقط یک پیامک می‌گیرد */
function sms_notify_sale(int $saleId): ?array {
    if (!feature_on('sms_receipt')) return null;
    $s = row("SELECT s.*, c.name cname, c.mobile cmobile FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.id=?", [$saleId]);
    if (!$s || !$s['cmobile']) return null;
    if (val("SELECT id FROM sms_log WHERE ref_type='sale' AND ref_id=? AND tpl_key='sale_receipt' AND ok=1", [$saleId])) return null;
    $doc = row("SELECT verify_token FROM docs WHERE sale_id=? AND status='issued' ORDER BY id DESC LIMIT 1", [$saleId]);
    return sms_send_tpl($s['cmobile'], 'sale_receipt', ['NAME' => $s['cname'], 'NUMBER' => $s['number'], 'AMOUNT' => (string)$s['total'],
        'LINK' => $doc ? app_url('v.php?t=' . $doc['verify_token']) : ''], 'notify', ['ref_type' => 'sale', 'ref_id' => $saleId]);
}
