<?php
// Gives the browser a product photo from this same site, so the order photo can be drawn and saved.
require __DIR__ . '/includes/bootstrap.php';
require_admin();
session_write_close();

function img_fail(): void
{
    http_response_code(404);
    exit;
}

$pid = (int)($_GET['p'] ?? 0);
$st = db()->prepare('SELECT image_url FROM products WHERE id = ?');
$st->execute([$pid]);
$u = trim((string)$st->fetchColumn());
if ($u === '') {
    img_fail();
}
if (strpos($u, '//') === 0) {
    $u = 'https:' . $u;
}
if (!preg_match('#^https?://#i', $u)) {
    $pu = parse_url((string)($config['website_products_url'] ?? 'https://fourpirates.tmfragrance.com/visitor/product-api/get_products.php'));
    if (empty($pu['scheme']) || empty($pu['host'])) {
        img_fail();
    }
    $u = $pu['scheme'] . '://' . $pu['host'] . (isset($pu['port']) ? ':' . $pu['port'] : '') . '/' . ltrim($u, '/');
}
$host = parse_url($u, PHP_URL_HOST);
if (!$host) {
    img_fail();
}
if (empty($config['img_allow_private'])) {
    $ip = gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        img_fail();
    }
}

$ch = curl_init($u);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_USERAGENT => 'OrderDesk/1.0',
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($body === false || $code !== 200 || strlen($body) > 8000000) {
    img_fail();
}
$info = @getimagesizefromstring($body);
if (!$info || strpos((string)$info['mime'], 'image/') !== 0) {
    img_fail();
}
header('Content-Type: ' . $info['mime']);
header('Cache-Control: private, max-age=86400');
echo $body;
