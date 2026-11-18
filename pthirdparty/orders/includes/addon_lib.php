<?php
// Add-ons: products a customer agreed to add AFTER the order was created (for example after a confirmation call).
// The order is not cancelled or created again. The add-on amount is added to the order total:
//   - paid add-on  : the money is booked in the order's payment account (needs a payment screenshot);
//                    on a COD order it counts as extra advance, so the COD amount to collect does not change.
//   - unpaid add-on: COD orders only. It is added to the COD amount, so the courier collects it.
// Who can add: the manager (admin) and the "sales head" logins (marked on the Team page).

require_once __DIR__ . '/ops_lib.php';
require_once __DIR__ . '/proof_lib.php';

function addon_migrate(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_addons (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            name VARCHAR(160) NOT NULL,
            size VARCHAR(40) NOT NULL DEFAULT '',
            qty INT NOT NULL DEFAULT 1,
            price DECIMAL(12,2) NOT NULL,
            details VARCHAR(300) NOT NULL DEFAULT '',
            paid TINYINT(1) NOT NULL DEFAULT 0,
            ledger_id INT UNSIGNED NULL,
            proof_file VARCHAR(80) NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $done = true;
}

/** User ids marked as sales head (stored in settings, k = 'sales_heads', comma separated). */
function sales_head_ids(PDO $pdo): array
{
    try {
        $st = $pdo->prepare("SELECT v FROM settings WHERE k = 'sales_heads'");
        $st->execute();
        $v = (string)$st->fetchColumn();
    } catch (Throwable $e) {
        return [];
    }
    return array_values(array_filter(array_map('intval', explode(',', $v))));
}

function is_sales_head(PDO $pdo, array $user): bool
{
    return in_array((int)$user['id'], sales_head_ids($pdo), true);
}

function sales_head_toggle(PDO $pdo, int $userId): bool
{
    $ids = sales_head_ids($pdo);
    $on = !in_array($userId, $ids, true);
    $ids = $on ? array_merge($ids, [$userId]) : array_diff($ids, [$userId]);
    $pdo->prepare("INSERT INTO settings (k, v) VALUES ('sales_heads', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
        ->execute([implode(',', array_unique($ids))]);
    return $on;
}

/** Manager or sales head: may add an add-on. */
function can_addon(PDO $pdo, array $user): bool
{
    return $user['role'] === 'admin' || is_sales_head($pdo, $user);
}

function addon_rows(PDO $pdo, int $orderId): array
{
    try {
        addon_migrate($pdo);
        $st = $pdo->prepare('SELECT a.*, u.name AS by_name FROM order_addons a LEFT JOIN users u ON u.id = a.created_by WHERE a.order_id = ? ORDER BY a.id');
        $st->execute([$orderId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function addon_line_total(array $a): float
{
    return round((float)$a['price'] * (int)$a['qty'], 2);
}

function addon_total(array $rows): float
{
    return round(array_sum(array_map('addon_line_total', $rows)), 2);
}

/** Adds one add-on to an order. Returns the add-on amount. Throws RuntimeException with a message safe to show. */
function addon_add(PDO $pdo, array $user, int $orderId, array $post, ?array $file): float
{
    if (!can_addon($pdo, $user)) {
        throw new RuntimeException('Only the manager or the sales head can add an add-on.');
    }
    addon_migrate($pdo);
    $name = trim((string)($post['addon_name'] ?? ''));
    $size = mb_substr(trim((string)($post['addon_size'] ?? '')), 0, 40);
    $details = mb_substr(trim((string)($post['addon_details'] ?? '')), 0, 300);
    $qty = (int)($post['addon_qty'] ?? 1);
    $price = round(num($post['addon_price'] ?? 0), 2);
    $paid = (($post['addon_paid'] ?? '') === 'paid');
    if ($name === '' || mb_strlen($name) > 160) {
        throw new RuntimeException('Write the perfume name.');
    }
    if ($qty < 1 || $qty > 999) {
        throw new RuntimeException('Enter the quantity.');
    }
    if ($price <= 0) {
        throw new RuntimeException('Enter the price (more than 0).');
    }
    $amount = round($price * $qty, 2);

    $o = $pdo->prepare('SELECT id, status, payment_mode, account_id FROM orders WHERE id = ?');
    $o->execute([$orderId]);
    $order = $o->fetch();
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    if (!in_array($order['status'], ['created', 'dispatched'], true)) {
        throw new RuntimeException('An add-on can only be added before the order is delivered.');
    }
    if (function_exists('sr_order_locked') && sr_order_locked($pdo, $orderId)) {
        throw new RuntimeException('This order is booked in Shiprocket. Press Cancel shipment first, then add the add-on, so the courier gets the right amount and products.');
    }
    if ($order['payment_mode'] !== 'cod' && !$paid) {
        throw new RuntimeException('This order is prepaid, so the add-on must be paid now. Choose "Already paid" and attach the screenshot.');
    }
    $proof = null;
    if ($paid) {
        $proof = save_proof_upload($file); // throws "Attach the payment screenshot." when missing
    }
    try {
        in_tx($pdo, function () use ($pdo, $user, $orderId, $order, $name, $size, $details, $qty, $price, $amount, $paid, $proof) {
            $lk = $pdo->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE');
            $lk->execute([$orderId]);
            $ledgerId = null;
            if ($paid) {
                if (empty($order['account_id'])) {
                    throw new RuntimeException('This order has no payment account. Choose it first in the order, then add the add-on.');
                }
                require_once __DIR__ . '/vendor_lib.php';
                $ledgerId = post_account_entry($pdo, (int)$user['id'], (int)$order['account_id'], biz_today(), 'in', $amount, 'prepaid', $orderId,
                    'Add-on for order ' . order_no($orderId));
            }
            $pdo->prepare(
                'INSERT INTO order_addons (order_id, name, size, qty, price, details, paid, ledger_id, proof_file, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$orderId, $name, $size, $qty, $price, $details, $paid ? 1 : 0, $ledgerId, $proof, (int)$user['id']]);
            $pdo->prepare('UPDATE orders SET total = total + ?, subtotal = subtotal + ?' . (($paid && $order['payment_mode'] === 'cod') ? ', advance_amount = advance_amount + ?' : '') . ' WHERE id = ?')
                ->execute(($paid && $order['payment_mode'] === 'cod') ? [$amount, $amount, $amount, $orderId] : [$amount, $amount, $orderId]);
            if ($paid) {
                sync_order_holds($pdo, (int)$user['id'], $orderId);
            }
        });
    } catch (Throwable $e) {
        proof_remove_file($proof);
        throw $e;
    }
    return $amount;
}

/** Removes an add-on again (manager only): the order total, the COD amount and the money entry go back. */
function addon_remove(PDO $pdo, array $user, int $orderId, int $addonId): void
{
    if ($user['role'] !== 'admin') {
        throw new RuntimeException('Only the manager can remove an add-on.');
    }
    addon_migrate($pdo);
    $st = $pdo->prepare('SELECT * FROM order_addons WHERE id = ? AND order_id = ?');
    $st->execute([$addonId, $orderId]);
    $a = $st->fetch();
    if (!$a) {
        throw new RuntimeException('Add-on not found.');
    }
    $o = $pdo->prepare('SELECT status, payment_mode, total, advance_amount FROM orders WHERE id = ?');
    $o->execute([$orderId]);
    $order = $o->fetch();
    if (!$order || !in_array($order['status'], ['created', 'dispatched'], true)) {
        throw new RuntimeException('An add-on can only be removed before the order is delivered.');
    }
    if (function_exists('sr_order_locked') && sr_order_locked($pdo, $orderId)) {
        throw new RuntimeException('This order is booked in Shiprocket. Press Cancel shipment first.');
    }
    $amount = addon_line_total($a);
    if ($order['payment_mode'] === 'cod') {
        $newDue = round((float)$order['total'] - $amount - (float)$order['advance_amount'] + ($a['paid'] ? $amount : 0) - order_cod_received($pdo, $orderId), 2);
        if ($newDue < -0.005) {
            throw new RuntimeException('COD money was already received on this order, so the add-on cannot be removed.');
        }
    }
    in_tx($pdo, function () use ($pdo, $user, $orderId, $a, $order, $amount) {
        if ($a['paid'] && $a['ledger_id']) {
            $pdo->prepare('DELETE FROM party_ledger WHERE ledger_id = ?')->execute([(int)$a['ledger_id']]);
            $pdo->prepare('DELETE FROM account_ledger WHERE id = ?')->execute([(int)$a['ledger_id']]);
        }
        $pdo->prepare('DELETE FROM order_addons WHERE id = ?')->execute([(int)$a['id']]);
        $pdo->prepare('UPDATE orders SET total = total - ?, subtotal = subtotal - ?' . (($a['paid'] && $order['payment_mode'] === 'cod') ? ', advance_amount = advance_amount - ?' : '') . ' WHERE id = ?')
            ->execute(($a['paid'] && $order['payment_mode'] === 'cod') ? [$amount, $amount, $amount, $orderId] : [$amount, $amount, $orderId]);
        if ($a['paid']) {
            require_once __DIR__ . '/vendor_lib.php';
            sync_order_holds($pdo, (int)$user['id'], $orderId);
        }
    });
    proof_remove_file($a['proof_file']);
}

/** Used by delete_order: forgets the add-ons of an order and their screenshots. */
function addon_delete_for(PDO $pdo, int $orderId): void
{
    foreach (addon_rows($pdo, $orderId) as $a) {
        proof_remove_file($a['proof_file']);
    }
    try {
        $pdo->prepare('DELETE FROM order_addons WHERE order_id = ?')->execute([$orderId]);
    } catch (Throwable $e) {
        // table not created yet: nothing to delete
    }
}
