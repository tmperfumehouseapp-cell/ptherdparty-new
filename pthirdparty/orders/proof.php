<?php
// Shows one payment screenshot. Only for logged-in users: admin sees all, a salesperson sees the screenshots of their own orders.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/proof_lib.php';
$user = require_login();
$pdo = db();

if (isset($_GET['r'])) {
    // the reference image of an order (orders.ref_image); same access rule as an order screenshot
    try {
        $st = $pdo->prepare("SELECT id, 'order' AS kind, id AS ref_id, ref_image AS file FROM orders WHERE id = ?");
        $st->execute([(int)$_GET['r']]);
    } catch (Throwable $e) {
        http_response_code(404);
        exit('Image not found.');
    }
} elseif (isset($_GET['x'])) {
    // a screenshot kept with the payment-time check (order_proof_reads); same access rule as an order screenshot
    $st = $pdo->prepare("SELECT id, 'order' AS kind, order_id AS ref_id, file FROM order_proof_reads WHERE id = ?");
    $st->execute([(int)$_GET['x']]);
} else {
    $st = $pdo->prepare('SELECT id, kind, ref_id, file FROM payment_proofs WHERE id = ?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
}
$p = $st->fetch();
$allowed = false;
if ($p && $p['file']) {
    if ($user['role'] === 'admin') {
        $allowed = true;
    } elseif ($p['kind'] === 'order') {
        $o = $pdo->prepare('SELECT salesperson_id FROM orders WHERE id = ?');
        $o->execute([(int)$p['ref_id']]);
        $allowed = ((int)$o->fetchColumn() === (int)$user['id']);
    }
}
$path = $allowed ? proof_dir() . '/' . $p['file'] : '';
if (!$allowed || !preg_match('~^\d{4}/[a-f0-9]{28}\.(jpg|png|webp|gif|pdf)$~', (string)$p['file']) || !is_file($path)) {
    http_response_code(404);
    exit('Screenshot not found.');
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf'];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($path);
