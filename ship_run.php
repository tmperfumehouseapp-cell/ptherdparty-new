<?php
// 1) cron job:  ship_run.php?key=<cron_key>   sends anything waiting to Shiprocket and checks parcel status
// 2) order page: ship_run.php?order=<id>      (logged in) used by the "Sending…" box to update itself
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/orders_lib.php';
require __DIR__ . '/includes/shiprocket_lib.php';
header('Content-Type: application/json');
$pdo = db();

if (!sr_enabled() || !sr_ready($pdo)) {
    echo json_encode(['ok' => false, 'pending' => false, 'note' => 'Shiprocket is not set up']);
    exit;
}

$key = (string)($_GET['key'] ?? '');
$cronKey = (string)sr_cfg('cron_key', '');
if ($key !== '') {
    if ($cronKey === '' || !hash_equals($cronKey, $key)) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
    }
    session_write_close();
    @set_time_limit(100);
    $sent = sr_process_queue($pdo, 10);
    $checked = sr_sync($pdo, 20);
    echo json_encode(['ok' => true, 'sent' => $sent, 'checked' => $checked]);
    exit;
}

$user = require_login();
$orderId = (int)($_GET['order'] ?? 0);
$st = $pdo->prepare('SELECT id, salesperson_id FROM orders WHERE id = ?');
$st->execute([$orderId]);
$o = $st->fetch();
if (!$o || ($user['role'] !== 'admin' && (int)$o['salesperson_id'] !== (int)$user['id'])) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}
session_write_close();
@set_time_limit(90);
sr_process_order($pdo, $orderId);
$pending = false;
foreach (sr_status_rows($pdo, $orderId) as $s) {
    if (in_array($s['status'], ['queued', 'working'], true)) {
        $pending = true;
    }
}
echo json_encode(['ok' => true, 'pending' => $pending]);
