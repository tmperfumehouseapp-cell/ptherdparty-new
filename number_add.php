<?php
// Adds an "order placed by number" from the New order page. Returns JSON.
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$pdo = db();
header('Content-Type: application/json; charset=utf-8');

function out(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['ok' => false, 'error' => 'Not allowed.'], 405);
}
$sent = $_POST['csrf'] ?? '';
if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
    out(['ok' => false, 'error' => 'Session expired. Refresh the page and try again.'], 400);
}

$label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 80);
$phone = trim(mb_substr(preg_replace('/[^\d+ ]+/', '', (string)($_POST['phone'] ?? '')), 0, 20));
$digits = preg_replace('/\D+/', '', $phone);

if ($label === '') {
    out(['ok' => false, 'error' => 'Enter a name for this number, for example "WhatsApp 3".']);
}
if (strlen($digits) < 10) {
    out(['ok' => false, 'error' => 'Enter the full mobile number (10 digits).']);
}

// the same mobile number is not added twice
foreach ($pdo->query('SELECT id, label, phone FROM order_numbers')->fetchAll() as $r) {
    if ($r['phone'] !== null && substr(preg_replace('/\D+/', '', $r['phone']), -10) === substr($digits, -10)) {
        out(['ok' => false, 'error' => 'This number is already added as "' . $r['label'] . '". Pick it from the list.']);
    }
}

$pdo->prepare('INSERT INTO order_numbers (label, phone) VALUES (?, ?)')->execute([$label, $phone]);
out(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'text' => $label . ' - ' . $phone]);