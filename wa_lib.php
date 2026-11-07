<?php
// WhatsApp order confirmation with verified delivery status (official WhatsApp Business Cloud API).
//
// How it works
//   1. A new order gets a unique confirmation code (TP-CNF-0084) and the message is sent through the Cloud API number
//      that is linked to the number the customer ordered on (order_numbers.wa_phone_id).
//   2. WhatsApp answers with a message id (wamid). That only means "accepted". The order stays PENDING.
//   3. WhatsApp then calls wa_webhook.php with statuses (sent, delivered, read, failed). Only those change the status.
//   4. Commission counts only when the status is sent or delivered (see order_earns() in order_auto_lib.php).
// Nobody can mark a message as sent by hand. The only manual action is "send again", which makes a new attempt.
//
// Secrets (access token, app secret, verify token) live in the server-only file wa_config.php, never in GitHub.

const WA_RANK = ['accepted' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 2, 'failed' => 3];

/** wa_config.php: ['enabled'=>true,'access_token'=>..., 'app_secret'=>..., 'verify_token'=>..., 'api_version'=>'v21.0', 'template'=>['name'=>..,'lang'=>'en']] */
function wa_cfg(): ?array
{
    if (isset($GLOBALS['WA_TEST_CFG'])) {
        return $GLOBALS['WA_TEST_CFG'];
    }
    static $cfg = false;
    if ($cfg === false) {
        $cfg = null;
        $f = dirname(__DIR__) . '/wa_config.php';
        if (is_file($f)) {
            $v = @include $f;
            if (is_array($v)) {
                $cfg = $v;
            }
        }
    }
    return $cfg;
}

/** The whole system is off until wa_config.php exists with enabled = true and an access token. */
function wa_enabled(): bool
{
    $c = wa_cfg();
    return $c !== null && !empty($c['enabled']) && !empty($c['access_token']) && function_exists('curl_init');
}

function wa_graph_url(array $cfg): string
{
    return rtrim((string)($cfg['graph_url'] ?? 'https://graph.facebook.com'), '/') . '/' . ($cfg['api_version'] ?? 'v21.0');
}

// ---------------------------------------------------------------------------------------------------------------
// database

function wa_migrate(PDO $pdo): bool
{
    static $done = false;
    if ($done) {
        return orders_has_wa($pdo);
    }
    $done = true;
    if (!orders_has_wa($pdo)) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN wa_enforced TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN wa_code VARCHAR(24) NULL, '
                . 'ADD COLUMN wa_status VARCHAR(10) NULL, ADD COLUMN wa_msg_id VARCHAR(100) NULL, ADD COLUMN wa_sent_at DATETIME NULL, '
                . 'ADD COLUMN wa_delivered_at DATETIME NULL, ADD COLUMN wa_error VARCHAR(255) NULL, ADD UNIQUE KEY uq_wa_code (wa_code)');
        } catch (Throwable $e) {
            error_log('wa_migrate orders: ' . $e->getMessage());
        }
    }
    try {
        if (!$pdo->query("SHOW COLUMNS FROM order_numbers LIKE 'wa_phone_id'")->fetch()) {
            $pdo->exec('ALTER TABLE order_numbers ADD COLUMN wa_phone_id VARCHAR(40) NULL');
        }
    } catch (Throwable $e) {
        error_log('wa_migrate numbers: ' . $e->getMessage());
    }
    $tables = [
        "CREATE TABLE IF NOT EXISTS wa_messages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, order_id INT UNSIGNED NOT NULL, attempt INT NOT NULL DEFAULT 1,
            phone_id VARCHAR(40) NULL, to_phone VARCHAR(20) NOT NULL, code VARCHAR(24) NOT NULL, mode VARCHAR(10) NOT NULL,
            wamid VARCHAR(100) NULL, status VARCHAR(10) NOT NULL, last_ts INT NOT NULL DEFAULT 0, error VARCHAR(255) NULL,
            created_at DATETIME NOT NULL, sent_at DATETIME NULL, delivered_at DATETIME NULL,
            UNIQUE KEY uq_wamid (wamid), KEY idx_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS wa_events (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, dedupe_key CHAR(40) NOT NULL, wamid VARCHAR(100) NOT NULL,
            status VARCHAR(12) NOT NULL, ts INT NOT NULL, error VARCHAR(255) NULL, applied TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL, UNIQUE KEY uq_dedupe (dedupe_key), KEY idx_wamid (wamid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS wa_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, order_id INT UNSIGNED NULL, kind VARCHAR(12) NOT NULL,
            msg VARCHAR(255) NOT NULL, actor INT NULL, created_at DATETIME NOT NULL, KEY idx_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            error_log('wa_migrate table: ' . $e->getMessage());
        }
    }
    return orders_has_wa($pdo);
}

function wa_log(PDO $pdo, ?int $orderId, string $kind, string $msg, ?int $actor = null): void
{
    try {
        $pdo->prepare('INSERT INTO wa_log (order_id, kind, msg, actor, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$orderId, $kind, mb_substr($msg, 0, 250), $actor, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        error_log('wa_log: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------------------------------------------
// the message

function wa_code(int $orderId): string
{
    return 'TP-CNF-' . str_pad((string)$orderId, 4, '0', STR_PAD_LEFT);
}

function wa_message_text(array $o, string $code): string
{
    return order_confirmation_text($o['id']); // the confirmation code stays in the panel for verification; the customer text is the approved wording
}

/** One call to the Cloud API. Returns ['ok'=>bool, 'wamid'=>?string, 'error'=>?string, 'http'=>int]. Never throws. */
function wa_api_send(array $cfg, string $phoneId, array $payload): array
{
    try {
        $ch = curl_init(wa_graph_url($cfg) . '/' . rawurlencode($phoneId) . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['access_token'], 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $resp = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        $j = json_decode($resp, true);
        if ($http >= 200 && $http < 300 && is_array($j) && !empty($j['messages'][0]['id'])) {
            return ['ok' => true, 'wamid' => (string)$j['messages'][0]['id'], 'error' => null, 'http' => $http];
        }
        $err = is_array($j) && isset($j['error']) ? trim(($j['error']['message'] ?? 'error') . ' (code ' . ($j['error']['code'] ?? '?') . ')') : ($cerr !== '' ? $cerr : 'HTTP ' . $http);
        return ['ok' => false, 'wamid' => null, 'error' => mb_substr($err, 0, 250), 'http' => $http];
    } catch (Throwable $e) {
        return ['ok' => false, 'wamid' => null, 'error' => mb_substr($e->getMessage(), 0, 250), 'http' => 0];
    }
}

/**
 * Called right after an order is created. Marks the order as under the WhatsApp rule and sends the first message.
 * Never throws: the order is already saved and stays saved whatever happens here.
 */
function wa_on_order_created(PDO $pdo, int $orderId): void
{
    try {
        if (!wa_enabled() || !wa_migrate($pdo)) {
            return;
        }
        $pdo->prepare("UPDATE orders SET wa_enforced = 1, wa_status = 'pending', wa_code = COALESCE(wa_code, ?) WHERE id = ?")->execute([wa_code($orderId), $orderId]);
        wa_log($pdo, $orderId, 'order', 'Order created. WhatsApp confirmation required for commission.');
        wa_send_confirmation($pdo, $orderId, null);
    } catch (Throwable $e) {
        error_log('wa_on_order_created: ' . $e->getMessage());
    }
}

/** Sends (or sends again) the confirmation. $actor = admin user id for a manual resend. Never throws. */
function wa_send_confirmation(PDO $pdo, int $orderId, ?int $actor): array
{
    try {
        $cfg = wa_cfg();
        if (!wa_enabled() || !wa_migrate($pdo) || $cfg === null) {
            return ['ok' => false, 'error' => 'WhatsApp check is not set up.'];
        }
        $st = $pdo->prepare('SELECT o.*, n.wa_phone_id FROM orders o LEFT JOIN order_numbers n ON n.id = o.number_id WHERE o.id = ?');
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }
        if (in_array((string)$o['wa_status'], ['sent', 'delivered'], true)) {
            return ['ok' => false, 'error' => 'Already confirmed by WhatsApp.'];
        }
        $code = $o['wa_code'] ?: wa_code($orderId);
        if (!$o['wa_code']) {
            $pdo->prepare('UPDATE orders SET wa_code = ? WHERE id = ?')->execute([$code, $orderId]);
        }
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM wa_messages WHERE order_id = ?');
        $cnt->execute([$orderId]);
        $attempt = (int)$cnt->fetchColumn() + 1;
        $to = (string)$o['customer_phone'];
        $phoneId = trim((string)($o['wa_phone_id'] ?? ''));
        $now = date('Y-m-d H:i:s');
        $ins = $pdo->prepare('INSERT INTO wa_messages (order_id, attempt, phone_id, to_phone, code, mode, wamid, status, last_ts, error, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        if ($phoneId === '') {
            $err = 'No WhatsApp API number is linked to the number this order was placed on. Link it on the WhatsApp page.';
            $ins->execute([$orderId, $attempt, null, $to, $code, 'none', null, 'failed', time(), $err, $now]);
            wa_log($pdo, $orderId, 'send', 'Attempt ' . $attempt . ' failed: ' . $err, $actor);
            wa_recompute_order($pdo, $orderId);
            return ['ok' => false, 'error' => $err];
        }

        $tpl = $cfg['template'] ?? null;
        if (is_array($tpl) && !empty($tpl['name'])) {
            // the template body has one variable, {{1}}, which is the order ID (see the setup note)
            $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => [
                'name' => $tpl['name'], 'language' => ['code' => $tpl['lang'] ?? 'en'],
                'components' => [['type' => 'body', 'parameters' => [
                    ['type' => 'text', 'text' => order_no($orderId)],
                ]]],
            ]];
            $mode = 'template';
        } else {
            $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => wa_message_text($o, $code)]];
            $mode = 'text';
        }
        $r = wa_api_send($cfg, $phoneId, $payload);
        if ($r['ok']) {
            // accepted by WhatsApp only: the order stays pending until the sent/delivered callback arrives
            $ins->execute([$orderId, $attempt, $phoneId, $to, $code, $mode, $r['wamid'], 'accepted', 0, null, $now]);
            wa_log($pdo, $orderId, 'send', 'Attempt ' . $attempt . ' accepted by WhatsApp (' . $mode . '), waiting for delivery status. Ref ' . $r['wamid'], $actor);
            wa_process_events($pdo, (string)$r['wamid']); // a status that arrived before we saved the message
        } else {
            $ins->execute([$orderId, $attempt, $phoneId, $to, $code, $mode, null, 'failed', time(), $r['error'], $now]);
            wa_log($pdo, $orderId, 'send', 'Attempt ' . $attempt . ' failed: ' . $r['error'], $actor);
        }
        wa_recompute_order($pdo, $orderId);
        return $r;
    } catch (Throwable $e) {
        error_log('wa_send_confirmation: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not send (' . $e->getMessage() . ')'];
    }
}

// ---------------------------------------------------------------------------------------------------------------
// status callbacks

/**
 * Stores one status event from WhatsApp. The same event twice is stored once (dedupe key). Returns true when it was new.
 */
function wa_store_event(PDO $pdo, string $wamid, string $status, int $ts, ?string $error): bool
{
    $status = strtolower($status);
    if ($wamid === '' || !isset(WA_RANK[$status]) || $status === 'accepted') {
        return false;
    }
    try {
        $pdo->prepare('INSERT INTO wa_events (dedupe_key, wamid, status, ts, error, applied, created_at) VALUES (?, ?, ?, ?, ?, 0, ?)')
            ->execute([sha1($wamid . '|' . $status . '|' . $ts), $wamid, $status, $ts, $error === null ? null : mb_substr($error, 0, 250), date('Y-m-d H:i:s')]);
        return true;
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return false; // duplicate callback
        }
        throw $e;
    }
}

/** Applies the stored events of one message in time order. Events for a message we do not know yet wait (applied = 0). */
function wa_process_events(PDO $pdo, string $wamid): void
{
    $m = $pdo->prepare('SELECT id, order_id, status, last_ts, sent_at, delivered_at FROM wa_messages WHERE wamid = ?');
    $m->execute([$wamid]);
    $msg = $m->fetch();
    if (!$msg) {
        return;
    }
    $ev = $pdo->prepare('SELECT id, status, ts, error FROM wa_events WHERE wamid = ? AND applied = 0 ORDER BY ts, id');
    $ev->execute([$wamid]);
    $status = (string)$msg['status'];
    $lastTs = (int)$msg['last_ts'];
    $sentAt = $msg['sent_at'];
    $delAt = $msg['delivered_at'];
    $err = null;
    $changed = false;
    foreach ($ev->fetchAll() as $e) {
        $new = $e['status'] === 'read' ? 'delivered' : $e['status'];
        $ts = (int)$e['ts'];
        $when = date('Y-m-d H:i:s', $ts);
        if ($new === 'sent' || $new === 'delivered') {
            $sentAt = $sentAt ?: $when;
        }
        if ($new === 'delivered') {
            $delAt = $delAt ?: $when;
        }
        // a status never goes backwards (delivered stays delivered); a late failure only counts if it is newer than what we know
        $rankNew = WA_RANK[$new];
        $rankOld = WA_RANK[$status];
        $take = $rankNew > $rankOld && !($new === 'failed' && $rankOld >= 1 && $ts < $lastTs);
        if ($take) {
            wa_log($pdo, (int)$msg['order_id'], 'status', 'WhatsApp status ' . $status . ' -> ' . $new . ' (' . $when . ')' . ($new === 'failed' && $e['error'] ? ': ' . $e['error'] : ''));
            $status = $new;
            $changed = true;
            if ($new === 'failed') {
                $err = $e['error'] ?: 'WhatsApp could not deliver the message.';
            }
        }
        $lastTs = max($lastTs, $ts);
        $pdo->prepare('UPDATE wa_events SET applied = 1 WHERE id = ?')->execute([(int)$e['id']]);
    }
    $pdo->prepare('UPDATE wa_messages SET status = ?, last_ts = ?, sent_at = ?, delivered_at = ?, error = COALESCE(?, error) WHERE id = ?')
        ->execute([$status, $lastTs, $sentAt, $delAt, $err, (int)$msg['id']]);
    if ($changed || $sentAt !== $msg['sent_at'] || $delAt !== $msg['delivered_at']) {
        wa_recompute_order($pdo, (int)$msg['order_id']);
    }
}

/** The order's status is the best result of all its attempts. Logs a change of commission eligibility. */
function wa_recompute_order(PDO $pdo, int $orderId): void
{
    $beforeSt = $pdo->prepare('SELECT ' . (orders_has_comm($pdo) ? 'commission_status' : 'NULL AS commission_status') . ', wa_enforced, wa_status FROM orders WHERE id = ?');
    $beforeSt->execute([$orderId]);
    $before = $beforeSt->fetch();
    if (!$before) {
        return;
    }
    $ms = $pdo->prepare('SELECT status, wamid, sent_at, delivered_at, error FROM wa_messages WHERE order_id = ? ORDER BY id');
    $ms->execute([$orderId]);
    $rows = $ms->fetchAll();
    $best = null;
    $result = 'pending';
    foreach (['delivered', 'sent'] as $want) {
        foreach ($rows as $r) {
            if ($r['status'] === $want && $best === null) {
                $best = $r;
                $result = $want;
            }
        }
    }
    if ($best === null) {
        $accepted = array_filter($rows, fn($r) => $r['status'] === 'accepted');
        $failed = array_filter($rows, fn($r) => $r['status'] === 'failed');
        if ($accepted) {
            $best = end($accepted);
            $result = 'pending';
        } elseif ($failed) {
            $best = end($failed);
            $result = 'failed';
        }
    }
    $pdo->prepare('UPDATE orders SET wa_status = ?, wa_msg_id = ?, wa_sent_at = ?, wa_delivered_at = ?, wa_error = ? WHERE id = ?')
        ->execute([$result, $best['wamid'] ?? null, $best['sent_at'] ?? null, $best['delivered_at'] ?? null, $result === 'failed' ? ($best['error'] ?? null) : null, $orderId]);

    if (in_array($result, ['sent', 'delivered'], true)) {
        conf_set($pdo, $orderId, 'api'); // WhatsApp confirmed it: the order is no longer locked
    }
    $after = $before;
    $after['wa_status'] = $result;
    $was = order_earns($before);
    $now = order_earns($after);
    if ($was !== $now && !empty($before['wa_enforced'])) {
        wa_log($pdo, $orderId, 'commission', $now ? 'WhatsApp confirmation verified (' . $result . '): commission now counts (if the 20-minute rule is also met).' : 'WhatsApp confirmation is ' . $result . ': commission on hold.');
    }
}

/**
 * The webhook, as a function so it can be tested. Returns [httpCode, body].
 * GET: Meta's one-time check. POST: signed status callbacks.
 */
function wa_handle_webhook(PDO $pdo, string $method, array $query, string $rawBody, string $sigHeader): array
{
    $cfg = wa_cfg();
    if ($cfg === null) {
        return [404, 'not set up'];
    }
    if ($method === 'GET') {
        $ok = ($query['hub_mode'] ?? '') === 'subscribe' && !empty($cfg['verify_token'])
            && hash_equals((string)$cfg['verify_token'], (string)($query['hub_verify_token'] ?? ''));
        return $ok ? [200, (string)($query['hub_challenge'] ?? '')] : [403, 'forbidden'];
    }
    if ($method !== 'POST') {
        return [405, 'method not allowed'];
    }
    $secret = (string)($cfg['app_secret'] ?? '');
    $calc = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    if ($secret === '' || !hash_equals($calc, $sigHeader)) {
        return [403, 'bad signature'];
    }
    $j = json_decode($rawBody, true);
    if (!is_array($j)) {
        return [200, 'ignored'];
    }
    wa_migrate($pdo);
    $touched = [];
    foreach ($j['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $ch) {
            foreach ($ch['value']['statuses'] ?? [] as $s) {
                $wamid = (string)($s['id'] ?? '');
                $err = null;
                if (!empty($s['errors'][0])) {
                    $err = trim(($s['errors'][0]['title'] ?? $s['errors'][0]['message'] ?? 'error') . ' (code ' . ($s['errors'][0]['code'] ?? '?') . ')');
                }
                if (wa_store_event($pdo, $wamid, (string)($s['status'] ?? ''), (int)($s['timestamp'] ?? time()), $err)) {
                    $touched[$wamid] = true;
                }
            }
        }
    }
    foreach (array_keys($touched) as $wamid) {
        wa_process_events($pdo, $wamid);
    }
    return [200, 'ok'];
}

/** Words for the manager: does this order earn commission, and if not, why. */
function wa_eligibility_text(array $o): string
{
    if (!comm_counts($o['commission_status'] ?? null)) {
        return 'Not eligible: ' . (($o['commission_status'] ?? '') === 'review' ? 'payment time not confirmed yet' : 'created after the 20-minute limit');
    }
    if (!empty($o['wa_enforced']) && !in_array((string)($o['wa_status'] ?? ''), ['sent', 'delivered'], true)) {
        return 'On hold: WhatsApp confirmation is ' . ($o['wa_status'] ?: 'pending');
    }
    return 'Eligible';
}

function wa_status_label(?string $s): string
{
    return ['pending' => 'Pending', 'sent' => 'Sent', 'delivered' => 'Delivered', 'failed' => 'Failed'][(string)$s] ?? 'Pending';
}
