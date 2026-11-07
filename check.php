<?php
// Temporary checker. Open it in the browser, send a screenshot, then DELETE this file from the server.
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

function e2($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// file => text that only the newest version contains ('' = the file only has to exist)
$files = [
    'config.php' => 'db_host',
    'login.php' => ['password_verify', 'lf_'], 'logout.php' => 'session', 'products.php' => 'fetch_website_products', 'users.php' => 'password_hash', 'groups.php' => 'community_groups', 'commission.php' => 'advance_amount',
    'index.php' => ['dealers.php', 'day_start', 'pc-box'],
    'new_order.php' => ['shiprocket_order_id', 'sync_website_costs', 'advance_amount', 'account-label', 'sum-cod', '?v=', 'pay_proof', 'number_id', 'addnum-box'],
    'order_done.php' => ['order_no', 'shiprocket_order_id'],
    'order_view.php' => ['shiprocket_order_id', 'whatsapp_tab', 'proof_delete_for', 'e_sales'],
    'orders.php' => ['delivered_upload', 'num_label', 'orders_tabs', 'buying_prepare', 'kchips'],
    'tracking.php' => 'order_numbers',
    'numbers.php' => 'order_numbers',
    'number_add.php' => 'order_numbers',
    'accounts.php' => 'owner_party_id',
    'parties.php' => "'payment','collected'",
    'party_ledger.php' => ['Collected on your QR', 'pay_method', 'payform', 'simple_has_details'],
    'billing.php' => ['simple_has_details', 'billform'], 'dealers.php' => 'simple_dealer_rows', 'cod_amount.php' => 'simple_cod_pending', 'includes/simple_lib.php' => ['simple_balance', 'simple_cod_by_date'],
    'proof.php' => 'payment_proofs',
    'cod_upload.php' => 'order_no',
    'delivered_upload.php' => 'Upload delivered list',
    'includes/bootstrap.php' => ['function csrf_verify', 'function biz_today'], 'includes/footer.php' => '', 'includes/products_lib.php' => ['set_base_price', 'valid_party_id', 'sync_website_costs'],
    'includes/header.php' => ['tracking.php', 'numbers.php', 'billing.php', 'cod_amount.php', 'dealers.php'],
    'includes/billing_lib.php' => ['billing_pending_matrix', 'billing_approve', 'billing_close'],
    'includes/orders_lib.php' => ['clean_shiprocket_id', 'sync_website_costs', 'post_account_entry', 'save_proof_upload', 'order_numbers', 'has no buying price on the website'],
    'includes/proof_lib.php' => 'save_proof_upload',
    'includes/ops_lib.php' => ['add_remittance', 'proof_delete_for', 'order_money_in'],
    'includes/vendor_lib.php' => 'delivered_cost',
    'assets/style.css' => '', 'assets/pages.css' => '', 'assets/order.css' => ['cta:disabled', 'pbuy'],
    'assets/new_order.js' => ['pbuy', 'advance-box', 'sum-adv', 'account-label', 'pay_proof'],
    'img_proxy.php' => 'img_allow_private', 'includes/buying_lib.php' => ['orders_tabs', 'buying_body', 'COD Pending Money'], 'includes/shiprocket_lib.php' => ['sr_process', 'sr_sync', 'shipment_block'], 'ship_run.php' => 'sr_process_queue', 'ship_action.php' => 'sr_cancel', 'includes/dealer_send_lib.php' => 'dealer_send_block', 'assets/send_dealer.js' => 'sd-data',
];

// ---- who is looking (admin of the panel) ----
$isAdmin = false;
$pdo = null;
$dbError = '';
try {
    $config = require __DIR__ . '/config.php';
    $dsn = 'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $ex) {
    $dbError = $ex->getMessage();
}
session_name('ordersess');
@session_start();
if ($pdo && !empty($_SESSION['uid'])) {
    try {
        $st = $pdo->prepare("SELECT role FROM users WHERE id = ? AND is_active = 1");
        $st->execute([(int)$_SESSION['uid']]);
        $isAdmin = ($st->fetchColumn() === 'admin');
    } catch (Throwable $ex) {
        $isAdmin = false;
    }
}

// ---- duplicates like "new_order (2).php": list, and replace the original when asked ----
function find_dupes(array $files): array
{
    $out = [];
    foreach (['', 'includes', 'assets'] as $sub) {
        $dir = __DIR__ . ($sub !== '' ? '/' . $sub : '');
        if (!is_dir($dir)) {
            continue;
        }
        foreach (scandir($dir) as $f) {
            if (preg_match('/^(.+) \((\d+)\)(\.[A-Za-z0-9]+)$/', $f, $m)) {
                $orig = ($sub !== '' ? $sub . '/' : '') . $m[1] . $m[3];
                $out[] = ['copy' => ($sub !== '' ? $sub . '/' : '') . $f, 'orig' => $orig, 'known' => array_key_exists($orig, $files)];
            }
        }
    }
    return $out;
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fix_dupes') {
    $sent = (string)($_POST['csrf'] ?? '');
    if (!$isAdmin) {
        $msg = 'Log in to the panel as admin in this browser first, then try again.';
    } elseif (empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $sent)) {
        $msg = 'Session expired. Reload this page and try again.';
    } else {
        $done = 0;
        foreach (find_dupes($files) as $d) {
            if ($d['known'] && is_file(__DIR__ . '/' . $d['copy'])) {
                if (@rename(__DIR__ . '/' . $d['copy'], __DIR__ . '/' . $d['orig'])) {
                    $done++;
                }
            }
        }
        $msg = $done . ' duplicate copy file(s) moved over the original. Reload the check below.';
    }
}

echo '<body style="font-family:system-ui;padding:20px;max-width:900px"><h2>Order Desk checker</h2>';
echo '<p>PHP version: <b>' . e2(PHP_VERSION) . '</b> &middot; Admin logged in here: <b>' . ($isAdmin ? 'yes' : 'no') . '</b></p>';
if ($msg !== '') {
    echo '<p style="background:#fef3c7;padding:10px;border-radius:8px"><b>' . e2($msg) . '</b></p>';
}

echo '<h3>Files</h3><table border="1" cellpadding="6" style="border-collapse:collapse"><tr><th>File</th><th>Size</th><th>Result</th></tr>';
$problems = 0;
foreach ($files as $f => $marker) {
    $p = __DIR__ . '/' . $f;
    if (!is_file($p)) {
        echo '<tr style="background:#fecaca"><td>' . e2($f) . '</td><td>-</td><td><b>MISSING - upload it</b></td></tr>';
        $problems++;
        continue;
    }
    $res = 'ok';
    $color = '#dcfce7';
    $body = file_get_contents($p);
    if (substr($f, -4) === '.php') {
        try {
            token_get_all($body, TOKEN_PARSE);
        } catch (Throwable $ex) {
            $res = 'ERROR line ' . $ex->getLine() . ': ' . $ex->getMessage();
            $color = '#fecaca';
            $problems++;
        }
    }
    if ($color === '#dcfce7' && $marker !== '') {
        $missingMarker = false;
        foreach ((array)$marker as $m) {
            if (strpos($body, $m) === false) {
                $missingMarker = true;
            }
        }
        if ($missingMarker) {
            $res = 'OLD VERSION - upload the new file from the zip';
            $color = '#fed7aa';
            $problems++;
        } else {
            $res = 'ok (new version)';
        }
    }
    echo '<tr style="background:' . $color . '"><td>' . e2($f) . '</td><td>' . filesize($p) . ' &middot; ' . e2(date('d M H:i', filemtime($p))) . '</td><td>' . e2($res) . '</td></tr>';
}
echo '</table>';

$dupes = find_dupes($files);
echo '<h3>Duplicate copies like "name (2).php"</h3>';
if (!$dupes) {
    echo '<p style="color:#15803d"><b>None found.</b></p>';
} else {
    $problems += count($dupes);
    echo '<table border="1" cellpadding="6" style="border-collapse:collapse"><tr><th>Copy on server</th><th>Would replace</th></tr>';
    foreach ($dupes as $d) {
        echo '<tr style="background:#fed7aa"><td>' . e2($d['copy']) . '</td><td>' . ($d['known'] ? e2($d['orig']) : '<i>not one of the panel files, leave it</i>') . '</td></tr>';
    }
    echo '</table>';
    if ($isAdmin) {
        echo '<form method="post" style="margin-top:10px"><input type="hidden" name="action" value="fix_dupes"><input type="hidden" name="csrf" value="' . e2($_SESSION['csrf'] ?? '') . '">'
           . '<button style="padding:10px 18px;font-weight:700">Replace the originals with these copies</button></form>';
    } else {
        echo '<p>To fix these in one click: log in to the panel as admin in this browser, then reload this page.</p>';
    }
}

echo '<h3>Database</h3>';
if ($pdo) {
    echo '<p>Database connection: ok</p>';
    $need = [
        ['orders', 'advance_amount'], ['accounts', 'party_id'], ['party_ledger', 'ledger_id'],
        ['products', 'category'], ['products', 'brand'], ['products', 'image_url'], ['orders', 'number_id'],
        ['bills', 'status'], ['bill_items', 'price_action'], ['bill_items', 'switch_dealer'],
    ];
    echo '<table border="1" cellpadding="6" style="border-collapse:collapse"><tr><th>Needed</th><th>Result</th></tr>';
    foreach ($need as [$t, $c]) {
        try {
            $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
            $q->execute([$t, $c]);
            $ok = (int)$q->fetchColumn() > 0;
        } catch (Throwable $ex) {
            $ok = false;
        }
        if (!$ok) {
            $problems++;
        }
        echo '<tr style="background:' . ($ok ? '#dcfce7' : '#fecaca') . '"><td>' . e2($t . '.' . $c) . '</td><td>' . ($ok ? 'ok' : 'MISSING - run the SQL') . '</td></tr>';
    }
    try {
        $q = $pdo->query("SHOW TABLES LIKE 'vendor_holds'");
        $ok = (bool)$q->fetchColumn();
    } catch (Throwable $ex) {
        $ok = false;
    }
    if (!$ok) {
        $problems++;
    }
    echo '<tr style="background:' . ($ok ? '#dcfce7' : '#fecaca') . '"><td>table vendor_holds</td><td>' . ($ok ? 'ok' : 'MISSING - run the SQL') . '</td></tr>';
    try {
        $q = $pdo->query("SHOW TABLES LIKE 'order_numbers'");
        $ok = (bool)$q->fetchColumn();
    } catch (Throwable $ex) {
        $ok = false;
    }
    if (!$ok) {
        $problems++;
    }
    echo '<tr style="background:' . ($ok ? '#dcfce7' : '#fecaca') . '"><td>table order_numbers</td><td>' . ($ok ? 'ok' : 'MISSING - run the SQL') . '</td></tr>';
    try {
        $q = $pdo->query("SHOW TABLES LIKE 'payment_proofs'");
        $ok = (bool)$q->fetchColumn();
    } catch (Throwable $ex) {
        $ok = false;
    }
    if (!$ok) {
        $problems++;
    }
    echo '<tr style="background:' . ($ok ? '#dcfce7' : '#fecaca') . '"><td>table payment_proofs</td><td>' . ($ok ? 'ok' : 'MISSING - run the SQL') . '</td></tr>';
    try {
        $q = $pdo->query("SHOW TABLES LIKE 'product_moves'");
        $ok = (bool)$q->fetchColumn();
    } catch (Throwable $ex) {
        $ok = false;
    }
    if (!$ok) {
        $problems++;
    }
    echo '<tr style="background:' . ($ok ? '#dcfce7' : '#fecaca') . '"><td>table product_moves</td><td>' . ($ok ? 'ok' : 'MISSING - run the SQL') . '</td></tr>';
    try {
        $ok = (bool)$pdo->query("SHOW COLUMNS FROM parties LIKE 'group_link'")->fetch();
    } catch (Throwable $ex) {
        $ok = false;
    }
    if (!$ok) {
        $problems++;
    }
    echo '<tr style="background:' . ($ok ? '#dcfce7' : '#fecaca') . '"><td>parties.group_link</td><td>' . ($ok ? 'ok' : 'MISSING - run the SQL') . '</td></tr>';
    foreach ([['orders.shiprocket_order_id (run migrate_shiprocket_order_id.sql)', "SHOW COLUMNS FROM orders LIKE 'shiprocket_order_id'"], ['bills.details (run migrate_simple_billing.sql)', "SHOW COLUMNS FROM bills LIKE 'details'"], ['parties.auto_ship', "SHOW COLUMNS FROM parties LIKE 'auto_ship'"], ['table shipments', "SHOW TABLES LIKE 'shipments'"], ['table shiprocket_state', "SHOW TABLES LIKE 'shiprocket_state'"]] as $chk) {
        try {
            $ok = (bool)$pdo->query($chk[1])->fetch();
        } catch (Throwable $ex) {
            $ok = false;
        }
        if (!$ok) {
            $problems++;
        }
        echo '<tr style="background:' . ($ok ? '#dcfce7' : '#fecaca') . '"><td>' . e2($chk[0]) . '</td><td>' . ($ok ? 'ok' : 'MISSING - run the SQL') . '</td></tr>';
    }
    $srEmail = trim((string)($config['shiprocket_email'] ?? ''));
    if ($srEmail === '' || !is_file(__DIR__ . '/includes/shiprocket_lib.php')) {
        echo '<tr style="background:#fef3c7"><td>Shiprocket login</td><td>not set up yet (add shiprocket_email, shiprocket_password and cron_key to config.php)</td></tr>';
    } else {
        require_once __DIR__ . '/includes/shiprocket_lib.php';
        try {
            sr_token($pdo, true);
            $ok = true;
            $msg = 'ok - logged in to Shiprocket';
        } catch (Throwable $ex) {
            $ok = false;
            $msg = $ex->getMessage();
            $problems++;
        }
        echo '<tr style="background:' . ($ok ? '#dcfce7' : '#fecaca') . '"><td>Shiprocket login</td><td>' . e2($msg) . '</td></tr>';
        $ck = trim((string)($config['cron_key'] ?? ''));
        if ($ck === '') {
            $problems++;
        }
        echo '<tr style="background:' . ($ck !== '' ? '#dcfce7' : '#fecaca') . '"><td>cron_key in config.php</td><td>' . ($ck !== '' ? 'ok' : 'MISSING - add a long random cron_key') . '</td></tr>';
    }
    $upOk = false;
    $upDir = __DIR__ . '/uploads';
    if (!is_dir($upDir)) {
        @mkdir($upDir, 0755, true);
    }
    $upOk = is_dir($upDir) && is_writable($upDir);
    if (!$upOk) {
        $problems++;
    }
    echo '<tr style="background:' . ($upOk ? '#dcfce7' : '#fecaca') . '"><td>uploads folder can be written</td><td>' . ($upOk ? 'ok' : 'NOT WRITABLE - create a folder named uploads inside orders and set permission 755') . '</td></tr>';
    try {
        $row = $pdo->query("SHOW COLUMNS FROM party_ledger LIKE 'entry_type'")->fetch(PDO::FETCH_ASSOC);
        $ok = $row && strpos((string)$row['Type'], 'collected') !== false;
    } catch (Throwable $ex) {
        $ok = false;
    }
    if (!$ok) {
        $problems++;
    }
    echo '<tr style="background:' . ($ok ? '#dcfce7' : '#fecaca') . '"><td>party_ledger entry types</td><td>' . ($ok ? 'ok' : 'MISSING - run the SQL') . '</td></tr>';
    echo '</table>';
} else {
    $problems++;
    echo '<p style="color:#b91c1c"><b>' . e2($dbError) . '</b></p>';
}

echo '<h3>Summary</h3>';
echo $problems === 0
    ? '<p style="color:#15803d;font-size:20px"><b>Everything is up to date.</b></p>'
    : '<p style="color:#b91c1c;font-size:20px"><b>' . (int)$problems . ' thing(s) to fix, see the red and orange rows above.</b></p>';
echo '<p style="color:#b91c1c"><b>Delete check.php from the server when everything is green.</b></p></body>';