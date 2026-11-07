<?php
// Small JSON endpoint for the order-done page: WhatsApp button pressed / "I have sent it" / is the order confirmed yet.
// Only tells the salesperson whether the order is confirmed. No verification details.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/orders_lib.php';
$user = require_login();
$pdo = db();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT id, salesperson_id, conf_user FROM orders WHERE id = ?');
$st->execute([$id]);
$o = $st->fetch();
$mine = $o && ($user['role'] === 'admin' || (int)$o['salesperson_id'] === (int)$user['id'] || (int)($o['conf_user'] ?? 0) === (int)$user['id']);
if (!$mine) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Order not found.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'opened') {
        conf_manual_opened($pdo, $id);
        echo json_encode(['ok' => true]);
    } elseif ($action === 'sent') {
        [$ok, $msg] = conf_manual_sent($pdo, $id);
        echo json_encode(['ok' => $ok, 'error' => $msg]);
    } elseif ($action === 'release' && $user['role'] === 'admin') {
        conf_set($pdo, $id, 'admin');
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    }
    exit;
}

$c = $pdo->prepare('SELECT conf_required, conf_at FROM orders WHERE id = ?');
$c->execute([$id]);
$r = $c->fetch();
echo json_encode(['ok' => true, 'confirmed' => !$r['conf_required'] || $r['conf_at'] !== null]);
