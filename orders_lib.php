<?php
// Order helpers: WhatsApp links, commission rate, order creation.
require_once __DIR__ . '/vendor_lib.php';
require_once __DIR__ . '/proof_lib.php';
require_once __DIR__ . '/products_lib.php';
require_once __DIR__ . '/order_auto_lib.php';
require_once __DIR__ . '/wa_lib.php';

/** Indian mobile -> 91XXXXXXXXXX (digits only), or null if it does not look valid. */
function wa_phone(string $raw): ?string
{
    $d = preg_replace('/\D+/', '', $raw);
    if (strlen($d) === 10) {
        return '91' . $d;
    }
    if (strlen($d) === 11 && $d[0] === '0') {
        return '91' . substr($d, 1);
    }
    if (strlen($d) === 12 && substr($d, 0, 2) === '91') {
        return $d;
    }
    return null;
}

/** Opens the customer's chat in WhatsApp Web with the message ready to send. */
function wa_link(string $phone, string $text): string
{
    return 'https://web.whatsapp.com/send?phone=' . $phone . '&text=' . rawurlencode($text);
}

/**
 * 8% while the salesperson's orders for the day are below the number of groups,
 * 10% once they reach the number of groups (or more).
 * With no groups added yet, the rate stays 8%.
 */
function commission_rate(int $ordersToday, int $groups): float
{
    return ($groups > 0 && $ordersToday >= $groups) ? 0.10 : 0.08;
}

function active_groups(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM community_groups WHERE is_active = 1')->fetchColumn();
}

/** Orders and profit per salesperson for one day: [userId => ['count' => n, 'profit' => x]] */
function today_stats(PDO $pdo, string $date, ?int $onlyUserId = null): array
{
    $profitSql = orders_has_comm($pdo) ? 'COALESCE(SUM(CASE WHEN ' . comm_ok_sql('orders', orders_has_wa($pdo)) . ' THEN profit ELSE 0 END), 0)' : 'COALESCE(SUM(profit), 0)';
    $sql = 'SELECT salesperson_id, COUNT(*) AS c, ' . $profitSql . ' AS p FROM orders WHERE order_date = ?';
    $params = [$date];
    if ($onlyUserId !== null) {
        $sql .= ' AND salesperson_id = ?';
        $params[] = $onlyUserId;
    }
    $sql .= ' GROUP BY salesperson_id';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int)$r['salesperson_id']] = ['count' => (int)$r['c'], 'profit' => (float)$r['p']];
    }
    return $out;
}

function order_wa_tracking_text(array $o): string
{
    $t = 'Hi ' . $o['customer_name'] . ', your order ' . order_no($o['id']) . ' has been dispatched.';
    if (!empty($o['tracking_id'])) {
        $t .= ' Tracking ID: ' . $o['tracking_id'] . '.';
    }
    if (!empty($o['tracking_link'])) {
        $t .= ' Track here: ' . $o['tracking_link'];
    }
    return $t;
}

/** True once migrate_shiprocket_order_id.sql has been run. */
function orders_has_sr_id(PDO $pdo): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'shiprocket_order_id'")->fetch();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/**
 * Cleans a typed Shiprocket Order ID. Returns null for empty. Throws RuntimeException when it looks wrong or is
 * already used on another order (so one Shiprocket order is never linked to two of ours).
 */
function clean_shiprocket_id(PDO $pdo, $raw, int $exceptOrderId = 0): ?string
{
    $v = trim(preg_replace('/\s+/', ' ', (string)$raw));
    if ($v === '') {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9#_\-\/ ]{1,60}$/', $v)) {
        throw new RuntimeException('The Shiprocket Order ID can have only letters, numbers, - _ / and #.');
    }
    if (!orders_has_sr_id($pdo)) {
        throw new RuntimeException('One small step is missing: run migrate_shiprocket_order_id.sql in phpMyAdmin first.');
    }
    $st = $pdo->prepare('SELECT id FROM orders WHERE shiprocket_order_id = ? AND id <> ? LIMIT 1');
    $st->execute([$v, $exceptOrderId]);
    $other = $st->fetchColumn();
    if ($other) {
        throw new RuntimeException('Shiprocket Order ID ' . $v . ' is already on order ' . order_no((int)$other) . '.');
    }
    return $v;
}

/** Saves (or clears) the Shiprocket Order ID of an existing order. */
function save_shiprocket_order_id(PDO $pdo, int $orderId, $raw): void
{
    $v = clean_shiprocket_id($pdo, $raw, $orderId);
    if (!orders_has_sr_id($pdo)) {
        return;
    }
    $pdo->prepare('UPDATE orders SET shiprocket_order_id = ? WHERE id = ?')->execute([$v, $orderId]);
}

/**
 * Validates and saves a full order: order, items, and (for prepaid) the account ledger entry.
 * Everything is recalculated here from the database. Prices typed in the browser are never trusted
 * except the sell price, and a base price for a product that has none saved yet.
 * Throws RuntimeException with a message that is safe to show the user.
 */
function create_order(PDO $pdo, array $user, array $post, array $files = []): int
{
    $submitTs = time(); // the moment the salesperson pressed Create: the 20-minute rule is measured to here
    $isAdmin = $user['role'] === 'admin';
    $salesId = (int)$user['id'];

    if ($isAdmin && !empty($post['salesperson_id'])) {
        $st = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
        $st->execute([(int)$post['salesperson_id']]);
        $found = $st->fetchColumn();
        if (!$found) {
            throw new RuntimeException('Choose a valid salesperson.');
        }
        $salesId = (int)$found;
    }

    $name = trim((string)($post['customer_name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Enter the customer name.');
    }
    $phone = wa_phone((string)($post['customer_phone'] ?? ''));
    if ($phone === null) {
        throw new RuntimeException('Enter a valid 10-digit mobile number.');
    }
    $pin = preg_replace('/\D+/', '', (string)($post['pincode'] ?? ''));
    if (strlen($pin) !== 6) {
        throw new RuntimeException('PIN code must be 6 digits.');
    }
    $address = trim((string)($post['address'] ?? ''));
    if (mb_strlen($address) < 8) {
        throw new RuntimeException('Enter the full address.');
    }
    // optional: the order ID Shiprocket gave this order (our own #number is made automatically below)
    $srId = clean_shiprocket_id($pdo, $post['shiprocket_order_id'] ?? '');

    // Prepaid = full amount received now. COD = an advance received now, the rest on delivery.
    // There is no full COD: a COD order must have an advance.
    $mode = (($post['payment_mode'] ?? '') === 'cod') ? 'cod' : 'prepaid';
    $st = $pdo->prepare('SELECT id FROM accounts WHERE id = ? AND is_active = 1');
    $st->execute([(int)($post['account_id'] ?? 0)]);
    $accountId = $st->fetchColumn();
    if (!$accountId) {
        throw new RuntimeException($mode === 'cod'
            ? 'Choose the account the advance came into.'
            : 'Choose the account the money came into.');
    }
    $accountId = (int)$accountId;

    // which of your numbers the order was placed on (required once you have added numbers)
    $numberIds = array_map('intval', $pdo->query('SELECT id FROM order_numbers WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN));
    $numberId = null;
    if ($numberIds) {
        $numberId = (int)($post['number_id'] ?? 0);
        if (!in_array($numberId, $numberIds, true)) {
            throw new RuntimeException('Choose the number this order was placed on.');
        }
    }
    $advance = $mode === 'cod' ? round(num($post['advance_amount'] ?? 0), 2) : 0.0;

    // ----- lines -----
    // The buying price is the one on your website. Take the latest before pricing the order (at most once a minute,
    // and a slow or unreachable website never blocks an order: the last copied prices are used).
    global $config;
    sync_website_costs($pdo, $config, (int)$user['id'], 60, 6);
    $rawItems = $post['items'] ?? [];
    if (!is_array($rawItems)) {
        $rawItems = [];
    }
    $prodSt = $pdo->prepare('SELECT id, name, party_id FROM products WHERE id = ? AND is_active = 1');
    $baseSt = $pdo->prepare('SELECT base_price FROM product_prices WHERE product_id = ? ORDER BY id DESC LIMIT 1');

    $lines = [];
    foreach ($rawItems as $it) {
        if (!is_array($it)) {
            continue;
        }
        $pid = (int)($it['product_id'] ?? 0);
        if ($pid <= 0) {
            continue; // empty row
        }
        $prodSt->execute([$pid]);
        $p = $prodSt->fetch();
        if (!$p) {
            throw new RuntimeException('One of the products is not available any more.');
        }
        $qty = (int)($it['qty'] ?? 0);
        if ($qty < 1 || $qty > 9999) {
            throw new RuntimeException('Enter a quantity for "' . $p['name'] . '".');
        }
        $sell = round(num($it['sell_price'] ?? 0), 2);
        if ($sell <= 0) {
            throw new RuntimeException('Enter the sell price for "' . $p['name'] . '".');
        }
        // the buying price cannot be typed here, by anyone: it must already be on the website
        $baseSt->execute([$pid]);
        $base = $baseSt->fetchColumn();
        if ($base === false || (float)$base <= 0) {
            throw new RuntimeException('"' . $p['name'] . '" has no buying price on the website yet. Add it in the product details on the website, then try again.');
        }
        $lines[] = [
            'product_id' => $pid,
            'party_id'   => $p['party_id'] !== null ? (int)$p['party_id'] : null,
            'qty'        => $qty,
            'base'       => round((float)$base, 2),
            'sell'       => $sell,
            'line'       => round($qty * $sell, 2),
        ];
    }
    if (!$lines) {
        throw new RuntimeException('Add at least one product.');
    }

    $subtotal = 0.0;
    foreach ($lines as $l) {
        $subtotal += $l['line'];
    }
    $subtotal = round($subtotal, 2);

    $discount = round(num($post['discount'] ?? 0), 2);
    if ($discount < 0 || $discount > $subtotal) {
        throw new RuntimeException('Discount cannot be more than the order amount.');
    }

    // split the discount across lines in proportion to their amount; the last line takes the rounding rest
    $given = 0.0;
    $baseTotal = 0.0;
    $profit = 0.0;
    $lastIdx = count($lines) - 1;
    foreach ($lines as $i => &$l) {
        $share = ($i === $lastIdx)
            ? round($discount - $given, 2)
            : round($subtotal > 0 ? $discount * $l['line'] / $subtotal : 0, 2);
        $given += $share;
        $l['share'] = $share;
        $l['profit'] = round($l['line'] - $share - $l['qty'] * $l['base'], 2);
        $baseTotal += $l['qty'] * $l['base'];
        $profit += $l['profit'];
    }
    unset($l);
    $baseTotal = round($baseTotal, 2);
    $profit = round($profit, 2);
    $total = round($subtotal - $discount, 2);

    if ($mode === 'cod') {
        if ($advance <= 0) {
            throw new RuntimeException('A COD order needs an advance. Enter the amount the customer paid now.');
        }
        if ($advance >= $total) {
            throw new RuntimeException('The advance (' . inr($advance) . ') must be less than the order total (' . inr($total) . '). For the full amount choose Prepaid.');
        }
    }

    // The reference (notes + image) is required on every order.
    $refNotes = ref_clean_notes($post['ref_notes'] ?? '');
    if (!proof_uploaded($files['ref_image'] ?? null)) {
        throw new RuntimeException('Attach the reference image. It is required to create the order.');
    }

    // The payment screenshot is required: money received now (full amount or COD advance) must have proof.
    $proofFile = save_proof_upload($files['pay_proof'] ?? null);
    // up to two more screenshots (optional); the earliest payment time on any of them is used for the 20-minute rule
    $shotFiles = [1 => $proofFile];
    try {
        foreach ([2 => 'pay_proof2', 3 => 'pay_proof3'] as $slot => $field) {
            if (proof_uploaded($files[$field] ?? null)) {
                $shotFiles[$slot] = save_proof_upload($files[$field]);
            }
        }
    } catch (Throwable $e) {
        foreach ($shotFiles as $f) {
            proof_remove_file($f);
        }
        throw $e;
    }
    // the reference image
    $refCols = ref_migrate($pdo);
    $refFile = null;
    try {
        $refFile = save_ref_image($files['ref_image'] ?? null);
    } catch (Throwable $e) {
        foreach ($shotFiles as $f) {
            proof_remove_file($f);
        }
        throw $e;
    }
    // read the payment time(s). This never blocks the order: when it fails, the order is saved and flagged for the manager.
    $autoOn = false;
    $reads = [];
    $verdict = null;
    try {
        if (auto_enabled() && auto_migrate($pdo)) {
            $autoOn = true;
            if (function_exists('set_time_limit')) {
                @set_time_limit(90);
            }
            $reads = auto_read_screenshots($shotFiles, $submitTs);
            $verdict = auto_evaluate($reads, $submitTs);
        }
    } catch (Throwable $e) {
        error_log('order time check: ' . $e->getMessage());
        $autoOn = false;
    }

    // ----- save -----
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO orders (order_date, salesperson_id, number_id, customer_name, customer_phone, address, pincode,
                                 payment_mode, account_id, subtotal, discount, total, advance_amount, base_total, profit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([biz_today(), $salesId, $numberId, $name, $phone, $address, $pin, $mode, $accountId,
                    $subtotal, $discount, $total, $advance, $baseTotal, $profit]);
        $orderId = (int)$pdo->lastInsertId();
        if ($srId !== null) {
            $pdo->prepare('UPDATE orders SET shiprocket_order_id = ? WHERE id = ?')->execute([$srId, $orderId]);
        }

        $ins = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, party_id, qty, base_price, sell_price, discount_share, profit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $l) {
            $ins->execute([$orderId, $l['product_id'], $l['party_id'], $l['qty'], $l['base'], $l['sell'], $l['share'], $l['profit']]);
        }

        // prepaid: the whole total comes in now; COD: only the advance comes in now
        $paidNow = $mode === 'prepaid' ? $total : $advance;
        post_account_entry($pdo, (int)$user['id'], $accountId, biz_today(), 'in', $paidNow, 'prepaid', $orderId,
            $mode === 'prepaid' ? 'Order ' . order_no($orderId) : 'Advance for COD order ' . order_no($orderId));
        sync_order_holds($pdo, (int)$user['id'], $orderId);
        proof_add($pdo, 'order', $orderId, null, $proofFile, (int)$user['id']);
        if ($refCols) {
            $pdo->prepare('UPDATE orders SET ref_notes = ?, ref_image = ? WHERE id = ?')->execute([$refNotes, $refFile, $orderId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($shotFiles as $f) {
            proof_remove_file($f);
        }
        proof_remove_file($refFile);
        throw $e;
    }
    if (!$refCols) { // the two columns could not be added: the order is kept, the manager sees a warning on the New order page
        error_log('order ' . $orderId . ': reference could not be saved (ref columns missing)');
        proof_remove_file($refFile);
    }
    if ($autoOn && $verdict !== null) {
        auto_save($pdo, $orderId, $submitTs, $verdict, $reads, $shotFiles);
    }
    return $orderId;
}