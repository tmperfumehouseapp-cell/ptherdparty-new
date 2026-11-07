<?php
// Manager buttons on the Shipment box: retry, cancel, label.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/orders_lib.php';
require __DIR__ . '/includes/shiprocket_lib.php';
$user = require_admin();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}
csrf_verify();
$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$st = $pdo->prepare('SELECT order_id FROM shipments WHERE id = ?');
$st->execute([$id]);
$orderId = (int)$st->fetchColumn();
if (!$orderId) {
    redirect('orders.php');
}
@set_time_limit(90);
try {
    if ($action === 'retry') {
        sr_requeue($pdo, $id);
        sr_process($pdo, $id);
        $s = $pdo->prepare('SELECT status, error FROM shipments WHERE id = ?');
        $s->execute([$id]);
        $r = $s->fetch();
        if ($r['status'] === 'assigned') {
            flash('ok', 'Shipment created in Shiprocket.');
        } else {
            flash('error', (string)($r['error'] ?: 'Shiprocket did not accept it yet.'));
        }
    } elseif ($action === 'cancel') {
        sr_cancel($pdo, $id);
        flash('ok', 'Shipment cancelled in Shiprocket. You can edit or delete the order now.');
    } elseif ($action === 'label') {
        $url = sr_label($pdo, $id);
        if (preg_match('~^https?://~i', $url)) {
            header('Location: ' . $url);
            exit;
        }
    }
} catch (SrError $e) {
    flash('error', $e->getMessage());
}
redirect('order_view.php?id=' . $orderId);
