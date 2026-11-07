<?php
// Called by WhatsApp (Meta), not by a browser. No login: every POST is checked against the app secret signature.
// GET = Meta's one-time verification of this address. POST = message status callbacks (sent, delivered, read, failed).
define('NO_SESSION', true);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/order_auto_lib.php';
require __DIR__ . '/includes/wa_lib.php';

$raw = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = (string)file_get_contents('php://input', false, null, 0, 1048576);
}
try {
    [$code, $body] = wa_handle_webhook(
        db(),
        $_SERVER['REQUEST_METHOD'],
        $_GET,
        $raw,
        (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '')
    );
} catch (Throwable $e) {
    error_log('wa_webhook: ' . $e->getMessage());
    [$code, $body] = [500, 'error']; // WhatsApp will retry
}
http_response_code($code);
header('Content-Type: text/plain; charset=utf-8');
echo $body;
