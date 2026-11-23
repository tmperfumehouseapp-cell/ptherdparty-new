<?php
// Shiprocket connection managed from the panel (no editing of config.php):
//   - the API user's email and password are saved here (the password encrypted with a key made from config.php's api_key)
//   - automatic sync: every N minutes (or by cron) the panel asks Shiprocket where each open parcel is and marks orders delivered
//   - webhook: Shiprocket can tell the panel at once when a parcel is delivered
//   - a read-only test: see what Shiprocket answers, so nothing has to be guessed
// Nothing here creates, cancels or changes anything in Shiprocket: it only reads.

require_once __DIR__ . '/shiprocket_lib.php';

/** The panel's own small settings table (made the first time it is needed, never inside a transaction). */
function sr_conn_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS sr_connect (k VARCHAR(60) NOT NULL PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE IF NOT EXISTS sr_poll (order_id INT UNSIGNED NOT NULL PRIMARY KEY, checked_at DATETIME NOT NULL, last_status VARCHAR(120) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE IF NOT EXISTS sr_webhook_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            awb VARCHAR(60) NULL, status VARCHAR(120) NULL, result VARCHAR(255) NULL, raw TEXT NULL, KEY idx_time (received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (PDOException $e) {
        throw new RuntimeException('Could not prepare the Shiprocket settings tables.');
    }
    $done = true;
}

function sr_conn_get(PDO $pdo, string $k): ?string
{
    try {
        $st = $pdo->prepare('SELECT v FROM sr_connect WHERE k = ?');
        $st->execute([$k]);
    } catch (PDOException $e) {
        return null; // the table does not exist yet: nothing saved
    }
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function sr_conn_set(PDO $pdo, string $k, string $v): void
{
    $pdo->prepare('INSERT INTO sr_connect (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
}

/* ---------- the saved login (password encrypted) ---------- */

function sr_secret_key(): string
{
    global $config;
    return hash('sha256', 'sr-login|' . (string)($config['api_key'] ?? '') . '|' . (string)($config['db_name'] ?? ''), true);
}

function sr_secret_encrypt(string $plain): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('This server cannot encrypt the password (openssl is missing), so nothing was saved. Put the login in config.php instead.');
    }
    $iv = random_bytes(16);
    $c = openssl_encrypt($plain, 'aes-256-cbc', sr_secret_key(), OPENSSL_RAW_DATA, $iv);
    $mac = hash_hmac('sha256', $iv . $c, sr_secret_key(), true);
    return base64_encode($iv . $mac . $c);
}

function sr_secret_decrypt(string $blob): ?string
{
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 49 || !function_exists('openssl_decrypt')) {
        return null;
    }
    [$iv, $mac, $c] = [substr($raw, 0, 16), substr($raw, 16, 32), substr($raw, 48)];
    if (!hash_equals(hash_hmac('sha256', $iv . $c, sr_secret_key(), true), $mac)) {
        return null; // wrong key (api_key was changed) or damaged
    }
    $p = openssl_decrypt($c, 'aes-256-cbc', sr_secret_key(), OPENSSL_RAW_DATA, $iv);
    return $p === false ? null : $p;
}

/** The email or password saved on the settings page, or null. (Read every time: it can change within one request, right after "Save and connect".) */
function sr_saved_cred(string $k): ?string
{
    try {
        $pdo = db();
        if ($k === 'shiprocket_email') {
            return sr_conn_get($pdo, 'email');
        }
        $b = sr_conn_get($pdo, 'password_enc');
        return $b !== null && $b !== '' ? sr_secret_decrypt($b) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Where the login comes from now: 'config' (config.php), 'panel' (saved here) or '' (none). */
function sr_login_source(): string
{
    global $config;
    if (trim((string)($config['shiprocket_email'] ?? '')) !== '' && (string)($config['shiprocket_password'] ?? '') !== '') {
        return 'config';
    }
    return sr_enabled() ? 'panel' : '';
}

/** Saves the API user. A blank password keeps the one already saved. */
function sr_save_login(PDO $pdo, string $email, string $password): void
{
    sr_conn_ensure($pdo);
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter the email of your Shiprocket API user.');
    }
    if ($password === '' && sr_conn_get($pdo, 'password_enc') === null) {
        throw new RuntimeException('Enter the password of your Shiprocket API user.');
    }
    sr_conn_set($pdo, 'email', $email);
    if ($password !== '') {
        sr_conn_set($pdo, 'password_enc', sr_secret_encrypt($password));
    }
    sr_state_set($pdo, 'token', ''); // log in again with the new details
    sr_state_set($pdo, 'token_exp', '0');
}

/** Logs in once and says whether it worked. Returns ['ok' => bool, 'msg' => text]. */
function sr_test_login(PDO $pdo): array
{
    if (!sr_enabled()) {
        return ['ok' => false, 'msg' => 'No Shiprocket login is saved yet.'];
    }
    try {
        sr_token($pdo, true);
        sr_conn_ensure($pdo);
        sr_conn_set($pdo, 'login_ok_at', (string)time());
        return ['ok' => true, 'msg' => 'Connected: Shiprocket accepted the login.'];
    } catch (SrError $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

/* ---------- read-only test calls ---------- */

/** A GET path is only allowed to be a plain path on Shiprocket's API (never another site). */
function sr_valid_path(string $path): bool
{
    return (bool)preg_match('~^/[A-Za-z0-9/_\-.?&=,%:]{1,200}\z~D', $path) && strpos($path, '..') === false && strpos($path, '//') === false;
}

/** One read-only GET to Shiprocket. Returns ['code', 'summary' (what it contains), 'json' (first part, text)]. */
function sr_probe_get(PDO $pdo, string $path): array
{
    if (!sr_valid_path($path)) {
        throw new RuntimeException('That path is not allowed. It must start with / and contain only letters, numbers and / _ - . ? & = , % :');
    }
    $token = sr_token($pdo);
    [$code, $d] = sr_http('GET', sr_base() . $path, null, $token);
    return ['code' => $code, 'summary' => sr_summarise($d), 'json' => $d === null ? '(no JSON in the answer)' : mb_substr(json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 0, 3500)];
}

/** "what is in this answer": its top-level keys and, for a list, the fields of the first item. */
function sr_summarise(?array $d): string
{
    if ($d === null) {
        return 'No JSON in the answer.';
    }
    $s = 'Top-level fields: ' . (implode(', ', array_slice(array_keys($d), 0, 20)) ?: '(none)') . '.';
    foreach ($d as $k => $v) {
        if (is_array($v) && isset($v[0]) && is_array($v[0])) {
            $s .= ' "' . $k . '" is a list of ' . count($v) . ' item(s); an item has: ' . implode(', ', array_slice(array_keys($v[0]), 0, 30)) . '.';
            break;
        }
    }
    return $s;
}

/* ---------- parcel status ---------- */

/** The AWB numbers saved on an order (it may hold several, "AWB1, AWB2"). */
function sr_awbs_of(string $trackingId): array
{
    $out = [];
    foreach (preg_split('/[\s,;\/|]+/', trim($trackingId)) as $t) {
        $t = preg_replace('/[^A-Za-z0-9]/', '', $t);
        if (strlen($t) >= 8 && !in_array($t, $out, true)) {
            $out[] = $t;
        }
    }
    return $out;
}

/** True when a status text / code says the parcel was delivered. */
function sr_is_delivered($statusText, $statusCode = null): bool
{
    return preg_match('/^\s*delivered\s*$/i', (string)$statusText) === 1 || (int)$statusCode === 7;
}

/**
 * Asks Shiprocket about the AWBs of open panel orders (created / dispatched, with a tracking ID) and marks an order delivered
 * when EVERY AWB on it is delivered. Returns ['checked', 'delivered', 'errors', 'first_error'].
 */
function sr_poll_orders(PDO $pdo, int $maxOrders = 15, int $minMinutes = 20, int $budgetSeconds = 35): array
{
    require_once __DIR__ . '/ops_lib.php';
    require_once __DIR__ . '/order_tags_lib.php';
    sr_conn_ensure($pdo);
    srs_ensure($pdo);
    $r = ['checked' => 0, 'delivered' => 0, 'errors' => 0, 'first_error' => ''];
    $start = time();
    $st = $pdo->prepare(
        "SELECT o.id, o.tracking_id FROM orders o LEFT JOIN sr_poll p ON p.order_id = o.id
         WHERE o.status IN ('created','dispatched') AND o.tracking_id IS NOT NULL AND o.tracking_id <> ''
           AND (p.checked_at IS NULL OR p.checked_at < DATE_SUB(NOW(), INTERVAL " . (int)$minMinutes . " MINUTE))
         ORDER BY p.checked_at IS NOT NULL, p.checked_at, o.id LIMIT " . (int)$maxOrders
    );
    $st->execute();
    $mark = $pdo->prepare('INSERT INTO sr_poll (order_id, checked_at, last_status) VALUES (?, NOW(), ?) ON DUPLICATE KEY UPDATE checked_at = NOW(), last_status = VALUES(last_status)');
    foreach ($st->fetchAll() as $o) {
        if (time() - $start > $budgetSeconds) {
            break;
        }
        $awbs = sr_awbs_of((string)$o['tracking_id']);
        if (!$awbs) {
            $mark->execute([(int)$o['id'], 'no AWB']);
            continue;
        }
        $allDelivered = true;
        $last = '';
        $when = null;
        $failed = false;
        foreach ($awbs as $awb) {
            try {
                $d = sr_call($pdo, 'GET', '/courier/track/awb/' . rawurlencode($awb));
            } catch (SrError $e) {
                $failed = true;
                $r['errors']++;
                $r['first_error'] = $r['first_error'] ?: $e->getMessage();
                break;
            }
            $td = $d['tracking_data'] ?? [];
            $cs = (string)($td['shipment_track'][0]['current_status'] ?? $td['track_status'] ?? '');
            $last = $cs !== '' ? $cs : $last;
            if (sr_is_delivered($cs, $td['shipment_status'] ?? null)) {
                $dd = (string)($td['shipment_track'][0]['delivered_date'] ?? '');
                $when = $dd !== '' && strtotime($dd) ? date('Y-m-d', strtotime($dd)) : $when;
            } else {
                $allDelivered = false;
            }
        }
        if ($failed) {
            continue; // try this order again on the next run
        }
        $r['checked']++;
        $mark->execute([(int)$o['id'], mb_substr($last !== '' ? $last : '-', 0, 120)]);
        srs_save($pdo, (int)$o['id'], ['status' => $last, 'awb' => $awbs[0], 'delivered_on' => $allDelivered ? ($when ?? date('Y-m-d')) : '', 'source' => 'sync']);
        if ($allDelivered) {
            if (mark_delivered_on($pdo, (int)$o['id'], $when ?? date('Y-m-d'), null)) {
                $r['delivered']++;
            }
        }
    }
    return $r;
}

/* ---------- automatic sync ---------- */

function sr_auto_interval(PDO $pdo): int
{
    $m = (int)(sr_conn_get($pdo, 'interval_min') ?? 30);
    return in_array($m, [10, 15, 30, 60, 120], true) ? $m : 30;
}

/** True when automatic sync is on and the time since the last run is more than the chosen interval. */
function sr_auto_due(PDO $pdo): bool
{
    if (!sr_enabled() || sr_conn_get($pdo, 'auto_on') !== '1') {
        return false;
    }
    if ((int)(sr_conn_get($pdo, 'lock_until') ?? 0) > time()) {
        return false; // another request is running it
    }
    return time() - (int)(sr_conn_get($pdo, 'last_run_at') ?? 0) >= sr_auto_interval($pdo) * 60;
}

/** Runs one sync now and records the result. Returns the result array. */
function sr_auto_run(PDO $pdo, bool $manual): array
{
    sr_conn_ensure($pdo);
    sr_conn_set($pdo, 'lock_until', (string)(time() + 120));
    $t0 = microtime(true);
    try {
        $res = sr_poll_orders($pdo, $manual ? 25 : 15, $manual ? 0 : 20, $manual ? 45 : 35);
        $res['error'] = '';
    } catch (Throwable $e) {
        $res = ['checked' => 0, 'delivered' => 0, 'errors' => 1, 'first_error' => $e->getMessage(), 'error' => $e->getMessage()];
    }
    $res['ms'] = (int)round((microtime(true) - $t0) * 1000);
    $res['at'] = date('Y-m-d H:i:s');
    $res['how'] = $manual ? 'button' : 'automatic';
    sr_conn_set($pdo, 'last_run_at', (string)time());
    sr_conn_set($pdo, 'lock_until', '0');
    $log = json_decode((string)(sr_conn_get($pdo, 'runs_log') ?? '[]'), true);
    $log = array_slice(array_merge([$res], is_array($log) ? $log : []), 0, 15);
    sr_conn_set($pdo, 'runs_log', json_encode($log, JSON_UNESCAPED_UNICODE));
    return $res;
}

/**
 * Called at the end of every panel page: when a sync is due it runs AFTER the page was sent, so nobody waits for it.
 * (Needs a server that can finish the response early; otherwise only the cron link and the Sync now button run it.)
 */
function sr_auto_tick(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        if (!sr_auto_due($pdo)) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }
    if (!function_exists('fastcgi_finish_request') && !function_exists('litespeed_finish_request')) {
        return;
    }
    register_shutdown_function(static function () {
        if (function_exists('session_write_close')) {
            @session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            @litespeed_finish_request();
        }
        @ignore_user_abort(true);
        @set_time_limit(70);
        try {
            sr_auto_run(db(), false);
        } catch (Throwable $e) {
            error_log('sr_auto_tick: ' . $e->getMessage());
        }
    });
}

/* ---------- webhook ---------- */

/** First value found under any of these keys, at any depth. */
function sr_deep_find($data, array $keys)
{
    if (!is_array($data)) {
        return null;
    }
    foreach ($keys as $k) {
        if (array_key_exists($k, $data) && !is_array($data[$k]) && $data[$k] !== '' && $data[$k] !== null) {
            return $data[$k];
        }
    }
    foreach ($data as $v) {
        if (is_array($v) && ($f = sr_deep_find($v, $keys)) !== null) {
            return $f;
        }
    }
    return null;
}

/**
 * Handles one webhook message from Shiprocket (already decoded). Returns ['awb', 'status', 'result'] and marks the order delivered when the
 * message says DELIVERED and an order matches (by AWB, then Shiprocket order id). Anything else is only logged.
 */
function sr_webhook_handle(PDO $pdo, array $payload): array
{
    require_once __DIR__ . '/ops_lib.php';
    require_once __DIR__ . '/delivered_lib.php';
    $awb = (string)(sr_deep_find($payload, ['awb', 'awb_code', 'AWB', 'awb_no']) ?? '');
    $status = (string)(sr_deep_find($payload, ['current_status', 'shipment_status', 'status', 'Status']) ?? '');
    $code = sr_deep_find($payload, ['shipment_status_id', 'status_id']);
    $oid = (string)(sr_deep_find($payload, ['sr_order_id', 'channel_order_id', 'order_id']) ?? '');
    $when = (string)(sr_deep_find($payload, ['delivered_date', 'delivered_at', 'current_timestamp', 'scan_date']) ?? '');
    $hasSr = function_exists('orders_has_sr_id') ? orders_has_sr_id($pdo) : true;
    $found = ($awb !== '' || $oid !== '') ? dl_find_order($pdo, [$awb, $oid], $hasSr, false) : null;
    if ($found && $status !== '') { // the parcel status is kept on the order for the tags on the Orders page
        require_once __DIR__ . '/order_tags_lib.php';
        srs_ensure($pdo);
        srs_save($pdo, (int)$found[0]['id'], ['status' => $status, 'awb' => $awb, 'source' => 'webhook']);
    }
    if (!sr_is_delivered($status, $code)) {
        return ['awb' => $awb, 'status' => $status, 'result' => $found ? 'status saved on ' . order_no($found[0]['id']) . ' (not delivered)' : 'logged (status is not delivered)'];
    }
    if (!$found) {
        return ['awb' => $awb, 'status' => $status, 'result' => 'delivered, but no order matches this AWB / order id'];
    }
    [$o, $by] = $found;
    if (!in_array($o['status'], ['created', 'dispatched'], true)) {
        return ['awb' => $awb, 'status' => $status, 'result' => order_no($o['id']) . ' was already ' . $o['status']];
    }
    $date = $when !== '' && strtotime($when) ? date('Y-m-d', strtotime($when)) : date('Y-m-d');
    mark_delivered_on($pdo, (int)$o['id'], $date, $awb !== '' ? $awb : null);
    return ['awb' => $awb, 'status' => $status, 'result' => order_no($o['id']) . ' marked delivered (matched by ' . $by . ')'];
}

/** True when the token sent with the webhook is the saved one (checked in a way that cannot be guessed letter by letter). */
function sr_webhook_token_ok(PDO $pdo, string $sent): bool
{
    $saved = (string)(sr_conn_get($pdo, 'webhook_token') ?? '');
    return $saved !== '' && $sent !== '' && hash_equals($saved, $sent);
}

function sr_webhook_log(PDO $pdo, array $r, string $raw): void
{
    $pdo->prepare('INSERT INTO sr_webhook_log (awb, status, result, raw) VALUES (?, ?, ?, ?)')
        ->execute([mb_substr($r['awb'], 0, 60), mb_substr($r['status'], 0, 120), mb_substr($r['result'], 0, 250), mb_substr($raw, 0, 3000)]);
    $pdo->exec('DELETE FROM sr_webhook_log WHERE id < (SELECT m FROM (SELECT MAX(id) - 200 AS m FROM sr_webhook_log) x)'); // keep the last 200
}
