<?php
// Shiprocket auto-ship. Only dealers (parties) with auto_ship = 1 are sent to Shiprocket.
// The courier is whatever your default rule in Shiprocket picks.

class SrError extends RuntimeException
{
    public $retry = false; // true = a temporary problem (network), try again automatically
}

function sr_cfg(string $k, $default = null)
{
    global $config;
    return $config[$k] ?? $default;
}

function sr_enabled(): bool
{
    return trim((string)sr_cfg('shiprocket_email', '')) !== '' && (string)sr_cfg('shiprocket_password', '') !== '';
}

function sr_base(): string
{
    return rtrim((string)sr_cfg('shiprocket_base', 'https://apiv2.shiprocket.in/v1/external'), '/');
}

/** True when the migration has been run. */
function sr_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)$pdo->query("SHOW TABLES LIKE 'shipments'")->fetchColumn()
                && (bool)$pdo->query("SHOW COLUMNS FROM parties LIKE 'auto_ship'")->fetch();
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

function sr_state_get(PDO $pdo, string $k): ?string
{
    $st = $pdo->prepare('SELECT v FROM shiprocket_state WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function sr_state_set(PDO $pdo, string $k, string $v): void
{
    $pdo->prepare('INSERT INTO shiprocket_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
}

/** One HTTP call. Returns [httpCode, decodedJson|null]. Throws SrError (retry = true) when the network fails. */
function sr_http(string $method, string $url, ?array $body, ?string $token): array
{
    $ch = curl_init($url);
    $h = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        $e = new SrError('Could not reach Shiprocket (' . $err . ').');
        $e->retry = true;
        throw $e;
    }
    $d = json_decode((string)$raw, true);
    return [$code, is_array($d) ? $d : null];
}

function sr_error_text(?array $d, int $code): string
{
    if (!$d) {
        return 'Shiprocket answered with an error (HTTP ' . $code . ').';
    }
    $parts = [];
    if (!empty($d['message']) && is_string($d['message'])) {
        $parts[] = $d['message'];
    }
    if (!empty($d['errors']) && is_array($d['errors'])) {
        foreach ($d['errors'] as $k => $v) {
            $parts[] = (is_string($k) ? $k . ': ' : '') . (is_array($v) ? implode(', ', array_map('strval', $v)) : (string)$v);
        }
    }
    $t = trim(implode(' | ', $parts));
    return mb_substr($t !== '' ? $t : 'Shiprocket answered with an error (HTTP ' . $code . ').', 0, 480);
}

/** First value for $key anywhere inside a nested array. */
function sr_find($arr, string $key)
{
    if (!is_array($arr)) {
        return null;
    }
    if (array_key_exists($key, $arr) && !is_array($arr[$key]) && $arr[$key] !== '' && $arr[$key] !== null) {
        return $arr[$key];
    }
    foreach ($arr as $v) {
        if (is_array($v)) {
            $f = sr_find($v, $key);
            if ($f !== null) {
                return $f;
            }
        }
    }
    return null;
}

function sr_token(PDO $pdo, bool $force = false): string
{
    if (!$force) {
        $t = sr_state_get($pdo, 'token');
        $exp = (int)sr_state_get($pdo, 'token_exp');
        if ($t && $exp > time() + 3600) {
            return $t;
        }
    }
    [$code, $d] = sr_http('POST', sr_base() . '/auth/login', [
        'email' => trim((string)sr_cfg('shiprocket_email', '')),
        'password' => (string)sr_cfg('shiprocket_password', ''),
    ], null);
    if ($code >= 400 || empty($d['token'])) {
        throw new SrError('Shiprocket login failed: ' . sr_error_text($d, $code) . ' Check the API user email and password in config.php.');
    }
    sr_state_set($pdo, 'token', (string)$d['token']);
    sr_state_set($pdo, 'token_exp', (string)(time() + 9 * 86400)); // Shiprocket tokens last 10 days
    return (string)$d['token'];
}

/** Calls Shiprocket with the saved login. Returns the decoded answer; throws SrError when Shiprocket refuses. */
function sr_call(PDO $pdo, string $method, string $path, ?array $body = null): array
{
    $token = sr_token($pdo);
    [$code, $d] = sr_http($method, sr_base() . $path, $body, $token);
    if ($code === 401) {
        $token = sr_token($pdo, true);
        [$code, $d] = sr_http($method, sr_base() . $path, $body, $token);
    }
    if ($code >= 500) {
        $e = new SrError('Shiprocket had a problem (HTTP ' . $code . ').');
        $e->retry = true;
        throw $e;
    }
    if ($code >= 400 || $d === null) {
        throw new SrError(sr_error_text($d, $code));
    }
    return $d;
}

/** City and state for a pincode (Shiprocket first, India Post as backup). Cached. */
function sr_pin_lookup(PDO $pdo, string $pin): array
{
    $c = sr_state_get($pdo, 'pin_' . $pin);
    if ($c !== null && strpos($c, '|') !== false) {
        return explode('|', $c, 2);
    }
    $city = $state = '';
    try {
        $d = sr_call($pdo, 'GET', '/open/postcode/details?postcode=' . urlencode($pin));
        $city = (string)(sr_find($d, 'city') ?? '');
        $state = (string)(sr_find($d, 'state') ?? '');
    } catch (SrError $e) {
        // try the backup below
    }
    if ($city === '' || $state === '') {
        try {
            [$code, $d] = sr_http('GET', 'https://api.postalpincode.in/pincode/' . urlencode($pin), null, null);
            $po = $d[0]['PostOffice'][0] ?? null;
            if ($po) {
                $city = (string)($po['District'] ?? $po['Block'] ?? '');
                $state = (string)($po['State'] ?? '');
            }
        } catch (SrError $e) {
            // handled below
        }
    }
    if ($city === '' || $state === '') {
        throw new SrError('Could not find the city and state for pincode ' . $pin . '. Check the pincode (Fix address), then Retry.');
    }
    sr_state_set($pdo, 'pin_' . $pin, $city . '|' . $state);
    return [$city, $state];
}

/** The Shiprocket pickup address name for a dealer: its own, else the config one, else Shiprocket's primary address. */
function sr_pickup_name(PDO $pdo, array $party): string
{
    $n = trim((string)($party['sr_pickup'] ?? ''));
    if ($n !== '') {
        return $n;
    }
    $n = trim((string)sr_cfg('sr_pickup', ''));
    if ($n !== '') {
        return $n;
    }
    $n = sr_state_get($pdo, 'primary_pickup');
    if ($n) {
        return $n;
    }
    $d = sr_call($pdo, 'GET', '/settings/company/pickup');
    $list = $d['data']['shipping_address'] ?? [];
    $pick = null;
    foreach ($list as $a) {
        if (!empty($a['is_primary_location']) || !empty($a['primary'])) {
            $pick = $a;
            break;
        }
    }
    $pick = $pick ?: ($list[0] ?? null);
    if (!$pick || empty($pick['pickup_location'])) {
        throw new SrError('No pickup address found in Shiprocket. Add one in Shiprocket → Settings → Pickup Addresses, or type its name on the dealer in Parties.');
    }
    sr_state_set($pdo, 'primary_pickup', (string)$pick['pickup_location']);
    return (string)$pick['pickup_location'];
}

/** Puts every auto-ship dealer of the order into the queue. Returns how many were added. */
function sr_queue_order(PDO $pdo, int $orderId): int
{
    if (!sr_ready($pdo)) {
        return 0;
    }
    $st = $pdo->prepare(
        'INSERT IGNORE INTO shipments (order_id, party_id, status)
         SELECT DISTINCT oi.order_id, oi.party_id, \'queued\' FROM order_items oi
         JOIN parties pa ON pa.id = oi.party_id AND pa.auto_ship = 1 WHERE oi.order_id = ?'
    );
    $st->execute([$orderId]);
    return $st->rowCount();
}

/** Right after an order is created: queue it and send it to Shiprocket as soon as the page has been sent. */
function sr_kick(PDO $pdo, int $orderId): void
{
    if (!sr_enabled()) {
        return;
    }
    try {
        if (sr_queue_order($pdo, $orderId) < 1) {
            return;
        }
    } catch (Throwable $e) {
        return; // never stop an order from being saved
    }
    register_shutdown_function(function () use ($pdo, $orderId) {
        ignore_user_abort(true);
        @set_time_limit(90);
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            sr_process_order($pdo, $orderId);
        } catch (Throwable $e) {
            // the cron job and the order page will pick it up
        }
    });
}

function sr_process_order(PDO $pdo, int $orderId): int
{
    $st = $pdo->prepare("SELECT id FROM shipments WHERE order_id = ? AND (status = 'queued' OR (status = 'working' AND locked_at < NOW() - INTERVAL 2 MINUTE)) AND (next_try IS NULL OR next_try <= NOW()) ORDER BY id");
    $st->execute([$orderId]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $n += sr_process($pdo, (int)$sid) ? 1 : 0;
    }
    return $n;
}

/** Everything waiting in the queue (used by the cron job). */
function sr_process_queue(PDO $pdo, int $limit = 10): int
{
    $st = $pdo->query("SELECT id FROM shipments WHERE (status = 'queued' OR (status = 'working' AND locked_at < NOW() - INTERVAL 2 MINUTE)) AND (next_try IS NULL OR next_try <= NOW()) ORDER BY id LIMIT " . (int)$limit);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $n += sr_process($pdo, (int)$sid) ? 1 : 0;
    }
    return $n;
}

function sr_ref(array $s, int $autoParties, string $orderNo): string
{
    $ref = $autoParties > 1 ? $orderNo . '-' . $s['party_id'] : $orderNo;
    return $ref . ((int)$s['redo'] > 0 ? '-R' . ((int)$s['redo'] + 1) : '');
}

/** What this dealer ships in the order: items, the amount to collect, and which dealers the order has. */
function sr_order_parts(PDO $pdo, array $order, int $partyId): array
{
    $st = $pdo->prepare(
        'SELECT oi.product_id, oi.qty, oi.sell_price, oi.discount_share, oi.party_id, p.name
         FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id'
    );
    $st->execute([(int)$order['id']]);
    $items = [];
    $portion = 0.0;
    $dealers = [];
    foreach ($st->fetchAll() as $r) {
        if ($r['party_id'] !== null) {
            $dealers[(int)$r['party_id']] = true;
        }
        if ((int)$r['party_id'] !== $partyId) {
            continue;
        }
        $net = (float)$r['sell_price'] * (int)$r['qty'] - (float)$r['discount_share'];
        $portion += $net;
        $items[] = [
            'name' => mb_substr((string)$r['name'], 0, 120), 'sku' => 'P' . (int)$r['product_id'],
            'units' => (int)$r['qty'], 'selling_price' => round(max($net, 0) / max((int)$r['qty'], 1), 2),
        ];
    }
    $portion = round($portion, 2);
    $collect = 0.0;
    if ($order['payment_mode'] === 'cod') {
        $collect = count($dealers) <= 1 ? max(0.0, round((float)$order['total'] - (float)$order['advance_amount'], 2)) : $portion;
    }
    return ['items' => $items, 'portion' => $portion, 'collect' => $collect, 'dealers' => count($dealers)];
}

function sr_payload(PDO $pdo, array $s, array $order, array $party, string $ref): array
{
    $parts = sr_order_parts($pdo, $order, (int)$s['party_id']);
    if (!$parts['items']) {
        throw new SrError('This order has no products for ' . $party['name'] . ' any more.');
    }
    [$city, $state] = sr_pin_lookup($pdo, (string)$order['pincode']);
    $name = trim((string)$order['customer_name']);
    $np = preg_split('/\s+/', $name, 2);
    $phone = substr(preg_replace('/\D+/', '', (string)$order['customer_phone']), -10);
    $cod = $order['payment_mode'] === 'cod' && $parts['collect'] > 0;
    return [
        'order_id' => $ref,
        'order_date' => date('Y-m-d H:i', strtotime((string)$order['created_at'])),
        'pickup_location' => sr_pickup_name($pdo, $party),
        'billing_customer_name' => $np[0],
        'billing_last_name' => $np[1] ?? '',
        'billing_address' => mb_substr((string)$order['address'], 0, 250),
        'billing_city' => $city,
        'billing_pincode' => (string)$order['pincode'],
        'billing_state' => $state,
        'billing_country' => 'India',
        'billing_email' => (string)sr_cfg('sr_email', 'orders@pthirdparty.com'),
        'billing_phone' => $phone,
        'shipping_is_billing' => true,
        'order_items' => $parts['items'],
        'payment_method' => $cod ? 'COD' : 'Prepaid',
        'shipping_charges' => 0,
        'giftwrap_charges' => 0,
        'transaction_charges' => 0,
        'total_discount' => 0,
        'sub_total' => $cod ? $parts['collect'] : $parts['portion'],
        'length' => (float)sr_cfg('sr_length', 10),
        'breadth' => (float)sr_cfg('sr_breadth', 10),
        'height' => (float)sr_cfg('sr_height', 10),
        'weight' => (float)sr_cfg('sr_weight', 0.5),
    ];
}

/** Sends one queued shipment to Shiprocket: create order, assign courier and AWB, pickup, label. Safe to repeat. */
function sr_process(PDO $pdo, int $shipId): bool
{
    $claim = $pdo->prepare("UPDATE shipments SET status = 'working', locked_at = NOW(), attempts = attempts + 1
                            WHERE id = ? AND (status = 'queued' OR (status = 'working' AND locked_at < NOW() - INTERVAL 2 MINUTE))");
    $claim->execute([$shipId]);
    if ($claim->rowCount() < 1) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT * FROM shipments WHERE id = ?');
        $st->execute([$shipId]);
        $s = $st->fetch();
        $o = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $o->execute([(int)$s['order_id']]);
        $order = $o->fetch();
        $p = $pdo->prepare('SELECT * FROM parties WHERE id = ?');
        $p->execute([(int)$s['party_id']]);
        $party = $p->fetch();
        if (!$order || !$party) {
            throw new SrError('Order or dealer not found.');
        }
        $n = $pdo->prepare("SELECT COUNT(DISTINCT oi.party_id) FROM order_items oi JOIN parties pa ON pa.id = oi.party_id AND pa.auto_ship = 1 WHERE oi.order_id = ?");
        $n->execute([(int)$order['id']]);
        $ref = sr_ref($s, (int)$n->fetchColumn(), ltrim(order_no($order['id']), '#'));

        if (empty($s['sr_shipment_id'])) {
            $d = sr_call($pdo, 'POST', '/orders/create/adhoc', sr_payload($pdo, $s, $order, $party, $ref));
            $srOrder = sr_find($d, 'order_id');
            $srShip = sr_find($d, 'shipment_id');
            if (!$srShip) {
                throw new SrError('Shiprocket did not create the order: ' . sr_error_text($d, 200));
            }
            $pdo->prepare('UPDATE shipments SET sr_ref = ?, sr_order_id = ?, sr_shipment_id = ? WHERE id = ?')->execute([$ref, $srOrder, $srShip, $shipId]);
            $s['sr_shipment_id'] = $srShip;
            $awb0 = sr_find($d, 'awb_code');
            if ($awb0) {
                $pdo->prepare('UPDATE shipments SET awb = ?, courier = ? WHERE id = ?')->execute([(string)$awb0, (string)(sr_find($d, 'courier_name') ?? ''), $shipId]);
                $s['awb'] = $awb0;
            }
        }
        if (empty($s['awb'])) {
            $d = sr_call($pdo, 'POST', '/courier/assign/awb', ['shipment_id' => (int)$s['sr_shipment_id']]);
            $awb = sr_find($d, 'awb_code');
            if (!$awb) {
                $why = sr_find($d, 'awb_assign_error') ?? sr_find($d, 'message') ?? 'no courier could be assigned';
                throw new SrError('Shiprocket could not assign a courier: ' . mb_substr((string)$why, 0, 400));
            }
            $pdo->prepare('UPDATE shipments SET awb = ?, courier = ? WHERE id = ?')->execute([(string)$awb, (string)(sr_find($d, 'courier_name') ?? ''), $shipId]);
        }
        try {
            sr_call($pdo, 'POST', '/courier/generate/pickup', ['shipment_id' => [(int)$s['sr_shipment_id']]]);
        } catch (SrError $e) {
            // pickup may already be scheduled by Shiprocket; the parcel is still booked
        }
        try {
            $d = sr_call($pdo, 'POST', '/courier/generate/label', ['shipment_id' => [(int)$s['sr_shipment_id']]]);
            $lab = sr_find($d, 'label_url');
            if ($lab) {
                $pdo->prepare('UPDATE shipments SET label_url = ? WHERE id = ?')->execute([mb_substr((string)$lab, 0, 500), $shipId]);
            }
        } catch (SrError $e) {
            // the label can be made later with the Shiprocket label button
        }
        $pdo->prepare("UPDATE shipments SET status = 'assigned', assigned_at = NOW(), error = NULL, next_try = NULL WHERE id = ?")->execute([$shipId]);
        sr_fill_tracking($pdo, (int)$order['id']);
        return true;
    } catch (SrError $e) {
        $row = $pdo->prepare('SELECT attempts FROM shipments WHERE id = ?');
        $row->execute([$shipId]);
        $att = (int)$row->fetchColumn();
        if ($e->retry && $att < 3) {
            $pdo->prepare("UPDATE shipments SET status = 'queued', error = ?, next_try = NOW() + INTERVAL ? SECOND WHERE id = ?")
                ->execute([mb_substr($e->getMessage(), 0, 480), 20 * $att, $shipId]);
        } else {
            $pdo->prepare("UPDATE shipments SET status = 'failed', error = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 480), $shipId]);
        }
        return false;
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE shipments SET status = 'failed', error = ? WHERE id = ?")->execute([mb_substr('Unexpected problem: ' . $e->getMessage(), 0, 480), $shipId]);
        return false;
    }
}

function sr_track_url(string $awb): string
{
    return 'https://shiprocket.co/tracking/' . rawurlencode($awb);
}

/** Copies the AWB(s) into the order's tracking ID and link, and marks the order dispatched. */
function sr_fill_tracking(PDO $pdo, int $orderId): void
{
    $st = $pdo->prepare("SELECT awb FROM shipments WHERE order_id = ? AND status = 'assigned' AND awb IS NOT NULL AND awb <> '' ORDER BY id");
    $st->execute([$orderId]);
    $awbs = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$awbs) {
        return;
    }
    $o = $pdo->prepare('SELECT tracking_id, tracking_link, status FROM orders WHERE id = ?');
    $o->execute([$orderId]);
    $order = $o->fetch();
    $tid = trim((string)$order['tracking_id']);
    foreach ($awbs as $a) {
        if (strpos($tid, (string)$a) === false) {
            $tid = $tid === '' ? (string)$a : $tid . ', ' . $a;
        }
    }
    $link = trim((string)$order['tracking_link']);
    if ($link === '') {
        $link = sr_track_url((string)$awbs[0]);
    }
    $status = $order['status'] === 'created' ? 'dispatched' : $order['status'];
    $pdo->prepare('UPDATE orders SET tracking_id = ?, tracking_link = ?, status = ? WHERE id = ?')
        ->execute([mb_substr($tid, 0, 100), mb_substr($link, 0, 500), $status, $orderId]);
    // the Shiprocket Order ID is filled in too, unless you already typed one
    try {
        $srid = $pdo->prepare("SELECT sr_order_id FROM shipments WHERE order_id = ? AND status = 'assigned' AND sr_order_id IS NOT NULL ORDER BY id LIMIT 1");
        $srid->execute([$orderId]);
        $v = $srid->fetchColumn();
        if ($v) {
            $pdo->prepare("UPDATE orders SET shiprocket_order_id = ? WHERE id = ? AND (shiprocket_order_id IS NULL OR shiprocket_order_id = '')")->execute([(string)$v, $orderId]);
        }
    } catch (Throwable $e) {
        // column not added yet, or the ID is already on another order: leave it
    }
}

/** Cancel in Shiprocket and take the AWB off the order. */
function sr_cancel(PDO $pdo, int $shipId): void
{
    $st = $pdo->prepare('SELECT * FROM shipments WHERE id = ?');
    $st->execute([$shipId]);
    $s = $st->fetch();
    if (!$s) {
        throw new SrError('Shipment not found.');
    }
    if (!empty($s['sr_order_id'])) {
        sr_call($pdo, 'POST', '/orders/cancel', ['ids' => [(int)$s['sr_order_id']]]);
    }
    $pdo->prepare("UPDATE shipments SET status = 'cancelled', error = NULL, redo = redo + 1 WHERE id = ?")->execute([$shipId]);
    if (!empty($s['awb'])) {
        $o = $pdo->prepare('SELECT tracking_id, tracking_link FROM orders WHERE id = ?');
        $o->execute([(int)$s['order_id']]);
        $order = $o->fetch();
        $ids = array_filter(array_map('trim', explode(',', (string)$order['tracking_id'])), function ($x) use ($s) {
            return $x !== '' && $x !== $s['awb'];
        });
        $link = (string)$order['tracking_link'];
        if ($link === sr_track_url((string)$s['awb'])) {
            $link = '';
        }
        $pdo->prepare('UPDATE orders SET tracking_id = ?, tracking_link = ? WHERE id = ?')
            ->execute([$ids ? implode(', ', $ids) : null, $link !== '' ? $link : null, (int)$s['order_id']]);
    }
}

/** Failed → try again from where it stopped. Cancelled → start fresh. */
function sr_requeue(PDO $pdo, int $shipId): void
{
    // the IF() checks must see the old status, so status is set last
    $pdo->prepare("UPDATE shipments SET attempts = 0, next_try = NULL, error = NULL,
                          sr_order_id = IF(status = 'cancelled', NULL, sr_order_id),
                          sr_shipment_id = IF(status = 'cancelled', NULL, sr_shipment_id),
                          awb = IF(status = 'cancelled', NULL, awb), courier = IF(status = 'cancelled', NULL, courier),
                          label_url = IF(status = 'cancelled', NULL, label_url), last_status = NULL, delivered = 0,
                          status = 'queued'
                   WHERE id = ? AND status IN ('failed','cancelled')")->execute([$shipId]);
}

/** Label link (made on first use). */
function sr_label(PDO $pdo, int $shipId): string
{
    $st = $pdo->prepare('SELECT sr_shipment_id, label_url FROM shipments WHERE id = ?');
    $st->execute([$shipId]);
    $s = $st->fetch();
    if (!$s || empty($s['sr_shipment_id'])) {
        throw new SrError('This shipment is not in Shiprocket yet.');
    }
    if (!empty($s['label_url'])) {
        return (string)$s['label_url'];
    }
    $d = sr_call($pdo, 'POST', '/courier/generate/label', ['shipment_id' => [(int)$s['sr_shipment_id']]]);
    $lab = sr_find($d, 'label_url');
    if (!$lab) {
        throw new SrError('Shiprocket has not made the label yet. Try again in a minute.');
    }
    $pdo->prepare('UPDATE shipments SET label_url = ? WHERE id = ?')->execute([mb_substr((string)$lab, 0, 500), $shipId]);
    return (string)$lab;
}

/** Hourly: ask Shiprocket where each parcel is; mark an order delivered when every parcel in it has been. */
function sr_sync(PDO $pdo, int $max = 20): int
{
    $st = $pdo->query("SELECT id, order_id, awb FROM shipments WHERE status = 'assigned' AND delivered = 0 AND awb IS NOT NULL AND awb <> ''
                       AND (checked_at IS NULL OR checked_at < NOW() - INTERVAL 55 MINUTE) ORDER BY checked_at IS NOT NULL, checked_at LIMIT " . (int)$max);
    $n = 0;
    foreach ($st->fetchAll() as $s) {
        try {
            $d = sr_call($pdo, 'GET', '/courier/track/awb/' . rawurlencode((string)$s['awb']));
        } catch (SrError $e) {
            $pdo->prepare('UPDATE shipments SET checked_at = NOW() WHERE id = ?')->execute([(int)$s['id']]);
            continue;
        }
        $td = $d['tracking_data'] ?? [];
        $cs = (string)($td['shipment_track'][0]['current_status'] ?? $td['track_status'] ?? '');
        $delivered = (preg_match('/^delivered$/i', trim($cs)) === 1 || (int)($td['shipment_status'] ?? 0) === 7) ? 1 : 0;
        $pdo->prepare('UPDATE shipments SET last_status = ?, checked_at = NOW(), delivered = ? WHERE id = ?')
            ->execute([$cs !== '' ? mb_substr($cs, 0, 120) : null, $delivered, (int)$s['id']]);
        $n++;
        if ($delivered) {
            sr_maybe_deliver($pdo, (int)$s['order_id']);
        }
    }
    return $n;
}

/** An order becomes delivered by itself only when ALL of its products ship through Shiprocket and every parcel is delivered. */
function sr_maybe_deliver(PDO $pdo, int $orderId): void
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM order_items oi LEFT JOIN parties pa ON pa.id = oi.party_id WHERE oi.order_id = ? AND (pa.auto_ship IS NULL OR pa.auto_ship = 0)");
    $q->execute([$orderId]);
    if ((int)$q->fetchColumn() > 0) {
        return;
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM shipments WHERE order_id = ? AND status <> 'cancelled' AND delivered = 0");
    $q->execute([$orderId]);
    if ((int)$q->fetchColumn() > 0) {
        return;
    }
    require_once __DIR__ . '/ops_lib.php';
    mark_delivered_on($pdo, $orderId, date('Y-m-d'), null);
}

/** True when this order has a parcel in Shiprocket that must be cancelled before the order can be changed. */
function sr_order_locked(PDO $pdo, int $orderId): bool
{
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM shipments WHERE order_id = ? AND status IN ('working','assigned')");
        $q->execute([$orderId]);
        return (int)$q->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false; // table not there yet
    }
}

function sr_status_rows(PDO $pdo, int $orderId): array
{
    $st = $pdo->prepare('SELECT s.*, pa.name AS party_name FROM shipments s JOIN parties pa ON pa.id = s.party_id WHERE s.order_id = ? ORDER BY s.id');
    $st->execute([$orderId]);
    return $st->fetchAll();
}

/** The "Shipment" card on the order pages. */
function shipment_block(PDO $pdo, array $order, bool $isAdmin): string
{
    if (!sr_enabled() || !sr_ready($pdo)) {
        return '';
    }
    $rows = sr_status_rows($pdo, (int)$order['id']);
    $q = $pdo->prepare("SELECT DISTINCT pa.name FROM order_items oi LEFT JOIN parties pa ON pa.id = oi.party_id WHERE oi.order_id = ? AND (pa.auto_ship IS NULL OR pa.auto_ship = 0)");
    $q->execute([(int)$order['id']]);
    $selfShip = array_values(array_filter($q->fetchAll(PDO::FETCH_COLUMN)));
    $oid = (int)$order['id'];
    $pending = false;
    ob_start();
    ?>
<section class="card" id="ship-card">
  <?php if (!$rows): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px"><h2 style="margin:0">Shipment</h2><span class="tag">Dealer ships by himself</span></div>
    <p class="hint" style="margin:6px 0 0"><?= $selfShip ? e(implode(', ', $selfShip)) . ' is not on auto-ship. ' : '' ?>Add the tracking on the Tracking page as usual.</p>
  <?php else: foreach ($rows as $s):
      $isPending = in_array($s['status'], ['queued', 'working'], true);
      $pending = $pending || $isPending;
      $tag = ['queued' => ['tag-warn', 'Sending to Shiprocket…'], 'working' => ['tag-warn', 'Sending to Shiprocket…'], 'assigned' => ['tag-ok', 'AWB assigned'], 'failed' => ['tag-bad', 'Failed'], 'cancelled' => ['', 'Cancelled']][$s['status']];
  ?>
    <div style="<?= $s !== $rows[0] ? 'border-top:1px solid var(--line);margin-top:14px;padding-top:14px' : '' ?>">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px">
        <h2 style="margin:0">Shipment<?= count($rows) > 1 ? ' · ' . e($s['party_name']) : '' ?></h2>
        <span class="tag <?= e($tag[0]) ?>"><?= e($tag[1]) ?></span>
      </div>
      <?php if ($isPending): ?>
        <p class="hint" style="margin:0">Usually done within 25 seconds. This page updates by itself.</p>
      <?php elseif ($s['status'] === 'assigned'): ?>
        <div class="kv">
          <div><div class="k">Pickup from</div><div class="v"><?= e($s['party_name']) ?></div></div>
          <div><div class="k">Courier</div><div class="v"><?= e($s['courier'] ?: '-') ?></div></div>
          <div><div class="k">AWB</div><div class="v"><?= e($s['awb']) ?></div></div>
          <?php if ($s['last_status']): ?><div><div class="k">Status</div><div class="v"><?= e($s['last_status']) ?></div></div><?php endif; ?>
        </div>
        <p class="hint" style="margin:10px 0">Tracking ID and link were filled in automatically.<?= $selfShip ? ' ' . e(implode(', ', $selfShip)) . ' ships by the dealer: add that tracking by hand on the Tracking page.' : '' ?></p>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <a class="btn btn-accent btn-sm" style="text-decoration:none;display:inline-flex;align-items:center" href="<?= e(sr_track_url((string)$s['awb'])) ?>" target="_blank" rel="noopener">Track parcel</a>
          <?php if ($isAdmin): ?>
            <form method="post" action="ship_action.php" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="action" value="label"><button class="btn btn-ghost btn-sm" type="submit">Shiprocket label</button></form>
            <form method="post" action="ship_action.php" class="inline" onsubmit="return confirm('Cancel this shipment in Shiprocket?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-ghost btn-sm" type="submit">Cancel shipment</button></form>
          <?php endif; ?>
        </div>
      <?php elseif ($s['status'] === 'failed'): ?>
        <p style="margin:0 0 10px;font-weight:700;color:#b91c1c"><?= e($s['error'] ?: 'Shiprocket refused this order.') ?></p>
        <?php if ($isAdmin): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <form method="post" action="ship_action.php" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="action" value="retry"><button class="btn btn-accent btn-sm" type="submit">Retry</button></form>
          <a class="btn btn-ghost btn-sm" style="text-decoration:none;display:inline-flex;align-items:center" href="order_view.php?id=<?= $oid ?>">Fix address</a>
        </div>
        <?php else: ?><p class="hint" style="margin:0">Tell the manager. He can fix and retry it.</p><?php endif; ?>
      <?php else: ?>
        <p class="hint" style="margin:0">This shipment was cancelled in Shiprocket.</p>
        <?php if ($isAdmin): ?>
          <form method="post" action="ship_action.php" class="inline" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="action" value="retry"><button class="btn btn-ghost btn-sm" type="submit">Send to Shiprocket again</button></form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</section>
<?php if ($pending): ?>
<script>
(function () {
  var n = 0;
  function poll() {
    n++;
    fetch('ship_run.php?order=<?= $oid ?>', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.pending) { location.reload(); return; }
      if (n < 60) { setTimeout(poll, 3000); }
    }).catch(function () { if (n < 60) { setTimeout(poll, 5000); } });
  }
  setTimeout(poll, 4000);
})();
</script>
<?php endif; ?>
    <?php
    return (string)ob_get_clean();
}