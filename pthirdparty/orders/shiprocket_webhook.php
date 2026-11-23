<?php
// Shiprocket calls this address when a parcel changes status. It has no login (Shiprocket cannot log in), so it asks for the secret token
// from the Shiprocket settings page: in the address (?token=...), or in the x-api-key / Authorization header. Without it: 401.
// It only ever does one thing: when the message says DELIVERED and an order matches, that order is marked delivered. Everything is logged.
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/sr_connect_lib.php';
header('Content-Type: application/json; charset=utf-8');
session_write_close();
$pdo = db();

$h = static fn(string $n): string => (string)($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $n))] ?? '');
$sent = (string)($_GET['token'] ?? '');
if ($sent === '') {
    $sent = $h('X-Api-Key');
}
if ($sent === '') {
    $sent = trim((string)preg_replace('/^Bearer\s+/i', '', $h('Authorization')));
}
if (!sr_webhook_token_ok($pdo, $sent)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'bad token']);
    exit;
}

$raw = (string)file_get_contents('php://input', false, null, 0, 200000);
$payload = json_decode($raw, true);
if (!is_array($payload) && $_POST) {
    $payload = $_POST;
    $raw = json_encode($_POST);
}
if (!is_array($payload)) { // an empty "are you there" call
    echo json_encode(['ok' => true, 'note' => 'nothing to do']);
    exit;
}
try {
    sr_conn_ensure($pdo);
    $r = sr_webhook_handle($pdo, $payload);
    sr_webhook_log($pdo, $r, $raw);
    sr_conn_set($pdo, 'webhook_last_at', (string)time());
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('shiprocket_webhook: ' . $e->getMessage());
    echo json_encode(['ok' => true]); // never make Shiprocket retry forever because of a problem on our side
}
