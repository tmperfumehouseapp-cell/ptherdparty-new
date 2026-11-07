<?php
// Public page (no login): sends one official contact as a vCard, so a phone offers "Add contact" with the name and number filled in.
require __DIR__ . '/includes/order_auto_lib.php';
$key = (string)($_GET['c'] ?? '');
$card = contact_vcard($key);
if ($card === null) {
    http_response_code(404);
    exit('Contact not found.');
}
$name = preg_replace('/[^A-Za-z0-9]+/', '_', official_contacts()[$key]['name']);
header('Content-Type: text/vcard; charset=utf-8');
header('Content-Disposition: inline; filename="' . $name . '.vcf"');
header('Content-Length: ' . strlen($card));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');
echo $card;
