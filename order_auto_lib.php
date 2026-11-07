<?php
// Order automation: payment-screenshot time check, the 20-minute commission rule, official contacts for WhatsApp.
//
// Nothing here may stop an order from being created. Every step that talks to the outside world or to new
// database columns is wrapped so that, when it fails, the order is saved exactly as before.
//
// Commission rule: a salesperson earns commission on an order only when the order is submitted within
// AUTO_DEADLINE_MIN minutes of the EARLIEST valid payment time found on the screenshots.
//   commission_status: NULL (old order or check switched off), 'ok' (in time), 'late' (too late, commission 0),
//                      'review' (time missing / unclear / conflicting: the manager must check, commission held at 0),
//                      'approved' (manager accepted it), 'rejected' (manager confirmed no commission).
// Counted for commission: NULL, 'ok', 'approved'.

const AUTO_DEADLINE_MIN = 20;
const AUTO_MAX_SHOTS = 3;
const AUTO_MODEL = 'claude-opus-5-5';

/** The two official numbers customers are asked to save. */
function official_contacts(): array
{
    return [
        'parth'   => ['name' => 'Parth perfumes',   'phone' => '+919558046552', 'show' => '+91 9558046552'],
        'pradeep' => ['name' => 'pradeep perfumes', 'phone' => '+919081220882', 'show' => '+91 90812 20882'],
    ];
}

/** Public address of the contact-card page, e.g. https://pthirdparty.com/pthirdparty/orders/contact.php?c=parth */
function contact_card_url(string $key): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'pthirdparty.com';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/pthirdparty/orders/x.php')), '/');
    return 'https://' . $host . $dir . '/contact.php?c=' . rawurlencode($key);
}

/** vCard 3.0 text of one official contact (WhatsApp and phone contacts apps show it with an Add / Save button). */
function contact_vcard(string $key): ?string
{
    $c = official_contacts()[$key] ?? null;
    if (!$c) {
        return null;
    }
    $parts = explode(' ', $c['name'], 2);
    return "BEGIN:VCARD\r\nVERSION:3.0\r\n"
        . 'N:' . ($parts[1] ?? '') . ';' . $parts[0] . ";;;\r\n"
        . 'FN:' . $c['name'] . "\r\n"
        . 'TEL;TYPE=CELL,VOICE:' . $c['phone'] . "\r\n"
        . "END:VCARD\r\n";
}

/**
 * The customer's order confirmation message. One text, used for the WhatsApp Web button and for the automatic
 * WhatsApp API message, so both always say the same. The order ID is the real one of the order (e.g. #0104).
 */
function order_confirmation_text($orderId): string
{
    return "❤️ Thank you for shopping with 3RD PARTY!\n\n"
        . "Your order is confirmed! 🎉\n\n"
        . '📦 Order ID: ' . order_no($orderId) . "\n\n"
        . "🚚 Tracking details coming soon!\n\n"
        . "For any help or future orders, save our official contacts below. 👇\n\n"
        . "📞 Parth Perfumes – +91 9558046552\n"
        . "📞 Pradeep Perfumes – +91 90812 20882\n\n"
        . '✨ Thank you for choosing 3RD PARTY!';
}

function order_wa_confirm_text(array $o): string
{
    return order_confirmation_text($o['id']);
}

// ---------------------------------------------------------------------------------------------------------------
// database

/** True when the new order columns exist (does not change anything). */
function orders_has_comm(PDO $pdo): bool
{
    static $has = false;
    if (!$has) {
        try {
            $has = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'commission_status'")->fetch();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** Adds the new columns and table the first time. Returns true when they are in place. */
function auto_migrate(PDO $pdo): bool
{
    static $done = false;
    if ($done) {
        return orders_has_comm($pdo);
    }
    $done = true;
    if (!orders_has_comm($pdo)) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN pay_time DATETIME NULL, ADD COLUMN pay_delay_min INT NULL, '
                . 'ADD COLUMN commission_status VARCHAR(12) NULL, ADD COLUMN commission_note VARCHAR(255) NULL, ADD COLUMN submitted_at DATETIME NULL');
        } catch (Throwable $e) {
            error_log('auto_migrate orders: ' . $e->getMessage());
        }
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS order_proof_reads (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                order_id INT UNSIGNED NOT NULL,
                slot TINYINT NOT NULL,
                file VARCHAR(80) NOT NULL,
                paid_at DATETIME NULL,
                status VARCHAR(10) NOT NULL,
                note VARCHAR(255) NULL,
                amount DECIMAL(12,2) NULL,
                KEY idx_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('auto_migrate reads: ' . $e->getMessage());
    }
    return orders_has_comm($pdo);
}

/** True when the WhatsApp verification columns exist (does not change anything). */
function orders_has_wa(PDO $pdo): bool
{
    static $has = false;
    if (!$has) {
        try {
            $has = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'wa_enforced'")->fetch();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** SQL piece: the orders (alias $a) whose profit counts for commission. $wa = also require the WhatsApp confirmation (when the order is under that rule). */
function comm_ok_sql(string $a = 'o', bool $wa = false): string
{
    $s = "($a.commission_status IS NULL OR $a.commission_status IN ('ok','approved'))";
    if ($wa) {
        $s = "($s AND ($a.wa_enforced = 0 OR $a.wa_status IN ('sent','delivered')))";
    }
    return $s;
}

function comm_counts(?string $status): bool
{
    return $status === null || in_array($status, ['ok', 'approved'], true);
}

/**
 * Does this order earn commission? Both rules must hold:
 *   1. created within 20 minutes of the payment time (commission_status null / ok / approved), and
 *   2. when the order is under the WhatsApp rule (wa_enforced), WhatsApp has confirmed the message as sent or delivered.
 * $o needs the keys commission_status, wa_enforced, wa_status (missing keys count as "no rule").
 */
function order_earns(array $o): bool
{
    if (!comm_counts($o['commission_status'] ?? null)) {
        return false;
    }
    if (!empty($o['wa_enforced']) && !in_array((string)($o['wa_status'] ?? ''), ['sent', 'delivered'], true)) {
        return false;
    }
    return true;
}

/** Small admin-only mark for an order list: green check when WhatsApp confirmed, cross when failed, hourglass while pending. */
function wa_badge(array $o): string
{
    if (empty($o['wa_enforced']) && empty($o['wa_status'])) {
        return '';
    }
    $s = (string)($o['wa_status'] ?? 'pending');
    if (in_array($s, ['sent', 'delivered'], true)) {
        return '<span title="WhatsApp confirmation ' . e($s) . '" style="margin-left:6px">✅</span>';
    }
    if ($s === 'failed') {
        return '<span title="WhatsApp confirmation failed" style="margin-left:6px">❌</span>';
    }
    return '<span title="WhatsApp confirmation pending" style="margin-left:6px">⏳</span>';
}

// ---------------------------------------------------------------------------------------------------------------
// reading the payment time from a screenshot

/** The API key: environment, config.php, or the server-only file anthropic_key.php (never put in GitHub). */
function auto_api_key(): string
{
    $k = (string)(getenv('ANTHROPIC_API_KEY') ?: '');
    if ($k === '') {
        global $config;
        $k = (string)($config['anthropic_key'] ?? '');
    }
    if ($k === '') {
        $f = dirname(__DIR__) . '/anthropic_key.php';
        if (is_file($f)) {
            $v = @include $f;
            $k = is_string($v) ? $v : '';
        }
    }
    return trim($k);
}

/** The time check is on only when a key is set. Without it, orders are saved and paid commission exactly as before. */
function auto_enabled(): bool
{
    return auto_api_key() !== '' && function_exists('curl_init');
}

/** [media_type, base64] of a stored screenshot, shrunk when it is large. Null when it cannot be sent. */
function auto_prepare(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    if (strncmp($raw, '%PDF', 4) === 0) {
        return strlen($raw) <= 24 * 1024 * 1024 ? ['application/pdf', base64_encode($raw)] : null;
    }
    $info = @getimagesizefromstring($raw);
    if (!$info) {
        return null;
    }
    $type = $info['mime'];
    if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        return null;
    }
    $big = max($info[0], $info[1]) > 2000 || strlen($raw) > 3500000;
    if ($big && function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $im = @imagecreatefromstring($raw);
        if ($im) {
            $w = imagesx($im);
            $h = imagesy($im);
            $s = min(1.0, 2000 / max($w, $h));
            $nw = max(1, (int)round($w * $s));
            $nh = max(1, (int)round($h * $s));
            $out = imagecreatetruecolor($nw, $nh);
            imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
            imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            imagejpeg($out, null, 88);
            $jpg = (string)ob_get_clean();
            imagedestroy($im);
            imagedestroy($out);
            if ($jpg !== '' && strlen($jpg) <= 4500000) {
                return ['image/jpeg', base64_encode($jpg)];
            }
        }
    }
    return strlen($raw) <= 4500000 ? [$type, base64_encode($raw)] : null;
}

function auto_prompt(): string
{
    return 'This is a payment screenshot from India (a UPI app, bank app or transfer receipt). All times are Indian Standard Time. '
        . 'Find the date and time the payment was made, exactly as printed. Do not guess. '
        . 'day = day of month, month = 1-12, year = four digits, or 0 if the screenshot does not show a year. '
        . 'time24 = the time as HH:MM:SS in 24-hour form (use :00 for seconds that are not shown), or an empty string if no time is visible. '
        . 'relative = "today" or "yesterday" when the screenshot says only that instead of a date, otherwise an empty string. '
        . 'found = true only when this is a payment screenshot AND both a date and a time are clearly readable. '
        . 'confidence = high, medium or low. amount = the amount paid, or 0 if not visible. note = one short sentence on anything unclear or odd '
        . '(edited look, cropped, two different times, not a payment).';
}

function auto_schema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'found' => ['type' => 'boolean'],
            'day' => ['type' => 'integer'],
            'month' => ['type' => 'integer'],
            'year' => ['type' => 'integer'],
            'time24' => ['type' => 'string'],
            'relative' => ['type' => 'string'],
            'amount' => ['type' => 'number'],
            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            'note' => ['type' => 'string'],
        ],
        'required' => ['found', 'day', 'month', 'year', 'time24', 'relative', 'amount', 'confidence', 'note'],
        'additionalProperties' => false,
    ];
}

/** Turns what the model returned into ['status' => 'read'|'unclear', 'paid_at' => 'Y-m-d H:i:s'|null, 'amount', 'note'] */
function auto_interpret(?array $j, int $nowTs): array
{
    if (!$j) {
        return ['status' => 'unclear', 'paid_at' => null, 'amount' => null, 'note' => 'Could not read this screenshot.'];
    }
    $note = mb_substr(trim((string)($j['note'] ?? '')), 0, 200);
    $amount = isset($j['amount']) && (float)$j['amount'] > 0 ? (float)$j['amount'] : null;
    $bad = static fn(string $why) => ['status' => 'unclear', 'paid_at' => null, 'amount' => $amount, 'note' => $why . ($note !== '' ? ' ' . $note : '')];
    if (empty($j['found'])) {
        return $bad('Payment date and time not clearly visible.');
    }
    if (($j['confidence'] ?? 'low') === 'low') {
        return $bad('Low confidence reading.');
    }
    if (trim((string)($j['relative'] ?? '')) !== '') {
        return $bad('Shows only "' . $j['relative'] . '" instead of a date.');
    }
    $d = (int)($j['day'] ?? 0);
    $m = (int)($j['month'] ?? 0);
    $y = (int)($j['year'] ?? 0);
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', trim((string)($j['time24'] ?? '')), $tm) || $d < 1 || $d > 31 || $m < 1 || $m > 12) {
        return $bad('Date or time could not be read.');
    }
    $sec = isset($tm[3]) ? (int)$tm[3] : 0;
    if ($y < 2000) {
        // no year printed: take this year, or last year when that would be in the future
        $y = (int)date('Y', $nowTs);
        $try = mktime((int)$tm[1], (int)$tm[2], $sec, $m, $d, $y);
        if ($try > $nowTs + 86400) {
            $y--;
        }
    }
    if (!checkdate($m, $d, $y)) {
        return $bad('Not a real date.');
    }
    $ts = mktime((int)$tm[1], (int)$tm[2], $sec, $m, $d, $y);
    return ['status' => 'read', 'paid_at' => date('Y-m-d H:i:s', $ts), 'amount' => $amount, 'note' => $note];
}

/**
 * Reads up to three screenshots at the same time. $files = [slot => stored file name relative to uploads/proofs].
 * Returns [slot => ['status', 'paid_at', 'amount', 'note']]. Never throws.
 */
function auto_read_screenshots(array $files, int $nowTs): array
{
    $out = [];
    try {
        $key = auto_api_key();
        $mh = curl_multi_init();
        $handles = [];
        foreach ($files as $slot => $name) {
            $prep = auto_prepare(proof_dir() . '/' . $name);
            if ($prep === null) {
                $out[$slot] = ['status' => 'unclear', 'paid_at' => null, 'amount' => null, 'note' => 'This file could not be read (type or size).'];
                continue;
            }
            [$mime, $b64] = $prep;
            $block = $mime === 'application/pdf'
                ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]]
                : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]];
            $body = [
                'model' => AUTO_MODEL,
                'max_tokens' => 2000,
                'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => auto_schema()]],
                'messages' => [['role' => 'user', 'content' => [$block, ['type' => 'text', 'text' => auto_prompt()]]]],
            ];
            $ch = curl_init(rtrim((string)(getenv('ANTHROPIC_BASE_URL') ?: 'https://api.anthropic.com'), '/') . '/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 40,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2026-10-21'],
                CURLOPT_POSTFIELDS => json_encode($body),
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$slot] = $ch;
        }
        $running = 0;
        do {
            curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0);
        foreach ($handles as $slot => $ch) {
            $resp = (string)curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $json = null;
            if ($code === 200) {
                $r = json_decode($resp, true);
                if (is_array($r) && (($r['stop_reason'] ?? '') !== 'refusal')) {
                    foreach ($r['content'] ?? [] as $blk) {
                        if (($blk['type'] ?? '') === 'text') {
                            $dec = json_decode((string)$blk['text'], true);
                            if (is_array($dec)) {
                                $json = $dec;
                            }
                        }
                    }
                }
            } else {
                error_log('auto_read HTTP ' . $code . ' ' . substr($resp, 0, 300));
            }
            $out[$slot] = auto_interpret($json, $nowTs);
        }
        curl_multi_close($mh);
    } catch (Throwable $e) {
        error_log('auto_read: ' . $e->getMessage());
    }
    foreach ($files as $slot => $name) {
        $out[$slot] ??= ['status' => 'unclear', 'paid_at' => null, 'amount' => null, 'note' => 'Could not read this screenshot.'];
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------------------------
// the 20-minute rule

/** Verdict for an order submitted at $submitTs. Pure function, no database. */
function auto_evaluate(array $reads, int $submitTs): array
{
    $valid = [];
    $unclear = [];
    foreach ($reads as $slot => $r) {
        if (($r['status'] ?? '') === 'read' && !empty($r['paid_at'])) {
            $valid[$slot] = strtotime($r['paid_at']);
        } else {
            $unclear[] = $slot;
        }
    }
    if (!$valid) {
        return ['status' => 'review', 'pay_time' => null, 'delay' => null, 'note' => 'No payment time could be read from the screenshot(s).'];
    }
    $earliest = min($valid);
    $latest = max($valid);
    $delay = (int)floor(($submitTs - $earliest) / 60);
    $payTime = date('Y-m-d H:i:s', $earliest);
    $limit = AUTO_DEADLINE_MIN * 60;

    if ($latest > $submitTs + 300) {
        return ['status' => 'review', 'pay_time' => $payTime, 'delay' => $delay, 'note' => 'A screenshot shows a payment time in the future. Please verify.'];
    }
    if ($latest - $earliest > 86400) {
        return ['status' => 'review', 'pay_time' => $payTime, 'delay' => $delay, 'note' => 'The screenshots are more than a day apart. Please verify.'];
    }
    if ($submitTs - $earliest > $limit) {
        // already too late on the earliest time we could read: an unreadable screenshot can only be older, so the verdict stands
        return ['status' => 'late', 'pay_time' => $payTime, 'delay' => $delay,
                'note' => 'Order created ' . (int)ceil(($submitTs - $earliest) / 60) . ' min after the payment (limit ' . AUTO_DEADLINE_MIN . ').'];
    }
    if ($unclear) {
        return ['status' => 'review', 'pay_time' => $payTime, 'delay' => $delay, 'note' => 'Screenshot ' . implode(', ', $unclear) . ' has no clear time. Please verify.'];
    }
    return ['status' => 'ok', 'pay_time' => $payTime, 'delay' => max(0, $delay),
            'note' => 'Order created ' . max(0, $delay) . ' min after the payment (limit ' . AUTO_DEADLINE_MIN . ').'];
}

/** Stores the verdict and the per-screenshot reads. Never throws. $files = [slot => name]. */
function auto_save(PDO $pdo, int $orderId, int $submitTs, array $verdict, array $reads, array $files): void
{
    try {
        $pdo->prepare('UPDATE orders SET pay_time = ?, pay_delay_min = ?, commission_status = ?, commission_note = ?, submitted_at = ? WHERE id = ?')
            ->execute([$verdict['pay_time'], $verdict['delay'], $verdict['status'], mb_substr($verdict['note'], 0, 250), date('Y-m-d H:i:s', $submitTs), $orderId]);
        $ins = $pdo->prepare('INSERT INTO order_proof_reads (order_id, slot, file, paid_at, status, note, amount) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($files as $slot => $name) {
            $r = $reads[$slot] ?? ['status' => 'unclear', 'paid_at' => null, 'note' => '', 'amount' => null];
            $ins->execute([$orderId, (int)$slot, $name, $r['paid_at'], $r['status'], $r['note'] !== '' ? mb_substr($r['note'], 0, 250) : null, $r['amount']]);
        }
    } catch (Throwable $e) {
        error_log('auto_save order ' . $orderId . ': ' . $e->getMessage());
    }
}

/** Removes the stored reads and the extra screenshot files of an order (used when the order is deleted). Never throws. */
function auto_delete_for(PDO $pdo, int $orderId): void
{
    try {
        $st = $pdo->prepare('SELECT file FROM order_proof_reads WHERE order_id = ?');
        $st->execute([$orderId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $f) {
            proof_remove_file($f);
        }
        $pdo->prepare('DELETE FROM order_proof_reads WHERE order_id = ?')->execute([$orderId]);
    } catch (Throwable $e) {
        // table not created yet: nothing to remove
    }
}

/** Manager types the real payment time: the verdict is worked out again against the time the order was submitted. */
function auto_manual_time(PDO $pdo, int $orderId, string $paidAt): array
{
    $st = $pdo->prepare('SELECT submitted_at, created_at FROM orders WHERE id = ?');
    $st->execute([$orderId]);
    $o = $st->fetch();
    if (!$o) {
        throw new RuntimeException('Order not found.');
    }
    $submit = strtotime((string)($o['submitted_at'] ?: $o['created_at'] ?? ''));
    $paid = strtotime($paidAt);
    if (!$submit || !$paid) {
        throw new RuntimeException('Enter a valid payment date and time.');
    }
    $delay = (int)floor(($submit - $paid) / 60);
    $ok = ($submit - $paid) <= AUTO_DEADLINE_MIN * 60;
    $status = $ok ? 'approved' : 'rejected';
    $note = 'Payment time set by the manager. Order created ' . max(0, $delay) . ' min after the payment (limit ' . AUTO_DEADLINE_MIN . ').';
    $pdo->prepare('UPDATE orders SET pay_time = ?, pay_delay_min = ?, commission_status = ?, commission_note = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s', $paid), max(0, $delay), $status, $note, $orderId]);
    return ['status' => $status, 'note' => $note];
}

/** Short words for the screen. */
function auto_status_text(?string $s): string
{
    return [
        'ok' => 'In time. Commission counted.',
        'late' => 'Created after 20 minutes. Commission ₹0.',
        'review' => 'Payment time needs checking by the manager. Commission is on hold.',
        'approved' => 'Approved by the manager. Commission counted.',
        'rejected' => 'Confirmed late by the manager. Commission ₹0.',
    ][(string)$s] ?? '';
}

// ---------------------------------------------------------------------------------------------------------------
// the confirmation lock: after creating an order the panel stays on that order until the customer has been sent
// the confirmation. Columns: conf_required (1 = the lock applies), conf_user (who must do it), conf_at (done),
// conf_mode ('api' = WhatsApp itself confirmed it, 'manual' = sent by hand through WhatsApp Web, 'admin' = released).
// Orders from before this feature have conf_required = 0, so nothing is locked for them.

const CONF_MIN_SECONDS = 4; // how long after opening WhatsApp the "I have sent it" step is accepted

function orders_has_conf(PDO $pdo): bool
{
    static $has = false;
    if (!$has) {
        try {
            $has = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'conf_required'")->fetch();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

function conf_migrate(PDO $pdo): bool
{
    static $done = false;
    if (!$done && !orders_has_conf($pdo)) {
        $done = true;
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN conf_required TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN conf_user INT NULL, '
                . 'ADD COLUMN conf_opened_at DATETIME NULL, ADD COLUMN conf_at DATETIME NULL, ADD COLUMN conf_mode VARCHAR(10) NULL');
        } catch (Throwable $e) {
            error_log('conf_migrate: ' . $e->getMessage());
        }
    }
    return orders_has_conf($pdo);
}

/** Puts a new order under the lock. If anything fails the order is simply not locked (infrastructure must never trap a salesperson). */
function conf_mark_required(PDO $pdo, int $orderId, int $userId): void
{
    try {
        if (conf_migrate($pdo)) {
            $pdo->prepare('UPDATE orders SET conf_required = 1, conf_user = ? WHERE id = ?')->execute([$userId, $orderId]);
        }
    } catch (Throwable $e) {
        error_log('conf_mark_required: ' . $e->getMessage());
    }
}

/** The oldest order of this user that still waits for its confirmation, or null. */
function conf_pending_order(PDO $pdo, int $userId): ?int
{
    if (!orders_has_conf($pdo)) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT id FROM orders WHERE conf_required = 1 AND conf_user = ? AND conf_at IS NULL ORDER BY id LIMIT 1');
        $st->execute([$userId]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    } catch (Throwable $e) {
        return null;
    }
}

/** Marks the confirmation as done (only the first time). */
function conf_set(PDO $pdo, int $orderId, string $mode): void
{
    if (!orders_has_conf($pdo)) {
        return;
    }
    $pdo->prepare('UPDATE orders SET conf_at = ?, conf_mode = ? WHERE id = ? AND conf_required = 1 AND conf_at IS NULL')
        ->execute([date('Y-m-d H:i:s'), $mode, $orderId]);
}

/** The salesperson pressed the WhatsApp button. */
function conf_manual_opened(PDO $pdo, int $orderId): void
{
    $pdo->prepare('UPDATE orders SET conf_opened_at = ? WHERE id = ? AND conf_required = 1 AND conf_at IS NULL AND conf_opened_at IS NULL')
        ->execute([date('Y-m-d H:i:s'), $orderId]);
}

/** "I have sent it": accepted only after WhatsApp was opened a few seconds ago. Returns [ok, message]. */
function conf_manual_sent(PDO $pdo, int $orderId, ?int $nowTs = null): array
{
    $st = $pdo->prepare('SELECT conf_required, conf_opened_at, conf_at FROM orders WHERE id = ?');
    $st->execute([$orderId]);
    $o = $st->fetch();
    if (!$o || !$o['conf_required']) {
        return [true, ''];
    }
    if ($o['conf_at'] !== null) {
        return [true, ''];
    }
    if ($o['conf_opened_at'] === null) {
        return [false, 'Press the WhatsApp button first and send the message to the customer.'];
    }
    $wait = CONF_MIN_SECONDS - (($nowTs ?? time()) - strtotime((string)$o['conf_opened_at']));
    if ($wait > 0) {
        return [false, 'Send the message in WhatsApp first, then press this button.'];
    }
    conf_set($pdo, $orderId, 'manual');
    return [true, ''];
}

/** Pages a locked user may still use: the order itself, its helpers, and log out. */
function conf_allowed_script(): bool
{
    $s = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return in_array($s, ['order_done.php', 'order_confirm.php', 'logout.php', 'login.php', 'proof.php', 'img_proxy.php', 'ship_run.php', 'ship_action.php', 'contact.php', 'wa_webhook.php'], true);
}

/** Called for every logged-in page: a user with an unconfirmed order is sent back to it. */
function confirm_lock_check(array $user): void
{
    if (conf_allowed_script()) {
        return;
    }
    $id = conf_pending_order(db(), (int)$user['id']);
    if ($id !== null) {
        flash('error', 'Send the order confirmation to the customer first. The panel opens again after that.');
        redirect('order_done.php?id=' . $id);
    }
}

// ---------------------------------------------------------------------------------------------------------------
// "Add Reference": notes + one image, required on every new order and kept with the order (orders.ref_notes, orders.ref_image)

const REF_NOTES_MAX = 2000;

function orders_has_ref(PDO $pdo): bool
{
    static $has = false;
    if (!$has) {
        try {
            $has = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'ref_image'")->fetch();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** Adds the two columns the first time. Returns true when they exist. */
function ref_migrate(PDO $pdo): bool
{
    static $tried = false;
    if (!$tried && !orders_has_ref($pdo)) {
        $tried = true;
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN ref_notes TEXT NULL, ADD COLUMN ref_image VARCHAR(80) NULL');
        } catch (Throwable $e) {
            error_log('ref_migrate: ' . $e->getMessage());
        }
    }
    return orders_has_ref($pdo);
}

/** The typed notes, or a RuntimeException with the message to show. */
function ref_clean_notes($raw): string
{
    $n = trim(str_replace("\r\n", "\n", (string)$raw));
    if ($n === '') {
        throw new RuntimeException('Add the reference notes. They are required to create the order.');
    }
    if (mb_strlen($n) > REF_NOTES_MAX) {
        throw new RuntimeException('The reference notes are too long (most ' . REF_NOTES_MAX . ' characters).');
    }
    return $n;
}

/** Stores the reference image (photo only, not a PDF). Returns the stored file name. */
function save_ref_image(?array $f): string
{
    require_once __DIR__ . '/proof_lib.php';
    if (!proof_uploaded($f)) {
        throw new RuntimeException('Attach the reference image. It is required to create the order.');
    }
    $name = save_proof_upload($f);
    if (!proof_is_image($name)) {
        proof_remove_file($name);
        throw new RuntimeException('The reference must be a photo or screenshot (jpg, png, webp), not a PDF.');
    }
    return $name;
}

/** The reference image file of an order (to remove it when the order is deleted), or null. */
function ref_image_of(PDO $pdo, int $orderId): ?string
{
    if (!orders_has_ref($pdo)) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT ref_image FROM orders WHERE id = ?');
        $st->execute([$orderId]);
        $v = $st->fetchColumn();
        return $v ? (string)$v : null;
    } catch (Throwable $e) {
        return null;
    }
}
