<?php
// Cron link:  sr_sync.php?key=<cron key from the Shiprocket settings page>
// Runs one Shiprocket sync (asks where each open parcel is, marks delivered ones). Meant for a Hostinger cron job, e.g. every 30 minutes.
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/sr_connect_lib.php';
header('Content-Type: application/json; charset=utf-8');
session_write_close();
$pdo = db();
$key = (string)($_GET['key'] ?? '');
$saved = (string)(sr_conn_get($pdo, 'cron_key') ?? '');
if ($saved === '' || $key === '' || !hash_equals($saved, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}
if (!sr_enabled()) {
    echo json_encode(['ok' => false, 'note' => 'Shiprocket login is not saved']);
    exit;
}
@set_time_limit(100);
$r = sr_auto_run($pdo, false);
echo json_encode(['ok' => $r['error'] === '', 'checked' => $r['checked'], 'delivered' => $r['delivered'], 'errors' => $r['errors']]);
