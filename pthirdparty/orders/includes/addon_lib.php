<?php
// Add-ons: products a customer agreed to add AFTER the order was created (for example after a confirmation call).
// The order is not cancelled or created again. Same shape as the HRMS "Add-on" form:
//   products (name, size in ml, qty) + total add-on price = prepaid amount (paid in advance) + COD amount (collect on delivery).
// The add-on total is added to the order total:
//   - prepaid part: booked in the order's payment account (needs a payment screenshot);
//                   on a COD order it counts as extra advance, so only the COD part is collected by the courier.
//   - COD part    : COD orders only. It stays in the COD amount, so the courier collects it.
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
        "CREATE TABLE IF NOT EXISTS order_addon_sets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            products TEXT NOT NULL,
            total DECIMAL(12,2) NOT NULL,
            prepaid DECIMAL(12,2) NOT NULL DEFAULT 0,
            cod DECIMAL(12,2) NOT NULL DEFAULT 0,
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

/** Add-ons of an order; each row gets 'items' = [['name'=>, 'size'=>, 'qty'=>], ...]. */
function addon_rows(PDO $pdo, int $orderId): array
{
    try {
        addon_migrate($pdo);
        $st = $pdo->prepare('SELECT a.*, u.name AS by_name FROM order_addon_sets a LEFT JOIN users u ON u.id = a.created_by WHERE a.order_id = ? ORDER BY a.id');
        $st->execute([$orderId]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as &$r) {
        $items = json_decode((string)$r['products'], true);
        $r['items'] = is_array($items) ? $items : [];
    }
    unset($r);
    return $rows;
}

function addon_total(array $rows): float
{
    return round(array_sum(array_map(static fn($a) => (float)$a['total'], $rows)), 2);
}

/** "Name 50 ml x2, Name 20 ml x1" for lists and messages. */
function addon_items_text(array $a): string
{
    $parts = [];
    foreach ($a['items'] as $it) {
        $parts[] = $it['name'] . (($it['size'] ?? '') !== '' ? ' ' . $it['size'] . ' ml' : '') . ' x' . (int)$it['qty'];
    }
    return implode(', ', $parts);
}

/** Adds one add-on to an order. Returns the add-on total. Throws RuntimeException with a message safe to show. */
function addon_add(PDO $pdo, array $user, int $orderId, array $post, ?array $file): float
{
    if (!can_addon($pdo, $user)) {
        throw new RuntimeException('Only the manager or the sales head can add an add-on.');
    }
    addon_migrate($pdo);

    // products: name + size (ml) + qty, empty rows are skipped
    $items = [];
    $names = (array)($post['addon_name'] ?? []);
    $sizes = (array)($post['addon_size'] ?? []);
    $qtys = (array)($post['addon_qty'] ?? []);
    foreach ($names as $i => $n) {
        $n = mb_substr(trim((string)$n), 0, 160);
        if ($n === '') {
            continue;
        }
        $q = (int)($qtys[$i] ?? 1);
        if ($q < 1 || $q > 999) {
            throw new RuntimeException('Enter the quantity for "' . $n . '".');
        }
        $items[] = ['name' => $n, 'size' => mb_substr(trim((string)($sizes[$i] ?? '')), 0, 20), 'qty' => $q];
    }
    if (!$items) {
        throw new RuntimeException('Write at least one product name.');
    }
    $total = round(num($post['addon_total'] ?? 0), 2);
    $prepaid = round(num($post['addon_prepaid'] ?? 0), 2);
    $cod = round(num($post['addon_cod'] ?? 0), 2);
    if ($total <= 0) {
        throw new RuntimeException('Enter the total add-on price.');
    }
    if ($prepaid < 0 || $cod < 0) {
        throw new RuntimeException('Amounts cannot be negative.');
    }
    if (abs($prepaid + $cod - $total) > 0.005) {
        throw new RuntimeException('Prepaid amount (' . inr($prepaid) . ') + COD amount (' . inr($cod) . ') must equal the total add-on price (' . inr($total) . ').');
    }

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
    if ($order['payment_mode'] !== 'cod' && $cod > 0) {
        throw new RuntimeException('This order is prepaid, so there is no COD amount. Put the full amount as prepaid.');
    }
    $proof = null;
    if ($prepaid > 0) {
        $proof = save_proof_upload($file); // throws "Attach the payment screenshot." when missing
    }
    $isCod = $order['payment_mode'] === 'cod';
    try {
        in_tx($pdo, function () use ($pdo, $user, $orderId, $order, $items, $total, $prepaid, $cod, $proof, $isCod) {
            $lk = $pdo->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE');
            $lk->execute([$orderId]);
            $ledgerId = null;
            if ($prepaid > 0) {
                if (empty($order['account_id'])) {
                    throw new RuntimeException('This order has no payment account. Choose it first in the order, then add the add-on.');
                }
                require_once __DIR__ . '/vendor_lib.php';
                $ledgerId = post_account_entry($pdo, (int)$user['id'], (int)$order['account_id'], biz_today(), 'in', $prepaid, 'prepaid', $orderId,
                    'Add-on for order ' . order_no($orderId));
            }
            $pdo->prepare(
                'INSERT INTO order_addon_sets (order_id, products, total, prepaid, cod, ledger_id, proof_file, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$orderId, json_encode($items, JSON_UNESCAPED_UNICODE), $total, $prepaid, $cod, $ledgerId, $proof, (int)$user['id']]);
            if ($isCod) {
                $pdo->prepare('UPDATE orders SET total = total + ?, subtotal = subtotal + ?, advance_amount = advance_amount + ? WHERE id = ?')
                    ->execute([$total, $total, $prepaid, $orderId]);
            } else {
                $pdo->prepare('UPDATE orders SET total = total + ?, subtotal = subtotal + ? WHERE id = ?')->execute([$total, $total, $orderId]);
            }
            if ($prepaid > 0) {
                sync_order_holds($pdo, (int)$user['id'], $orderId);
            }
        });
    } catch (Throwable $e) {
        proof_remove_file($proof);
        throw $e;
    }
    return $total;
}

/** Removes an add-on again (manager only): the order total, the COD amount and the money entry go back. */
function addon_remove(PDO $pdo, array $user, int $orderId, int $addonId): void
{
    if ($user['role'] !== 'admin') {
        throw new RuntimeException('Only the manager can remove an add-on.');
    }
    addon_migrate($pdo);
    $st = $pdo->prepare('SELECT * FROM order_addon_sets WHERE id = ? AND order_id = ?');
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
    $total = (float)$a['total'];
    $prepaid = (float)$a['prepaid'];
    $isCod = $order['payment_mode'] === 'cod';
    if ($isCod) {
        // COD still to collect after removal = (total - add-on) - (advance - its prepaid part) - COD money already received
        $newDue = round((float)$order['total'] - $total - ((float)$order['advance_amount'] - $prepaid) - order_cod_received($pdo, $orderId), 2);
        if ($newDue < -0.005) {
            throw new RuntimeException('COD money was already received on this order, so the add-on cannot be removed.');
        }
    }
    in_tx($pdo, function () use ($pdo, $user, $orderId, $a, $total, $prepaid, $isCod) {
        if ($prepaid > 0 && $a['ledger_id']) {
            $pdo->prepare('DELETE FROM party_ledger WHERE ledger_id = ?')->execute([(int)$a['ledger_id']]);
            $pdo->prepare('DELETE FROM account_ledger WHERE id = ?')->execute([(int)$a['ledger_id']]);
        }
        $pdo->prepare('DELETE FROM order_addon_sets WHERE id = ?')->execute([(int)$a['id']]);
        if ($isCod) {
            $pdo->prepare('UPDATE orders SET total = total - ?, subtotal = subtotal - ?, advance_amount = advance_amount - ? WHERE id = ?')
                ->execute([$total, $total, $prepaid, $orderId]);
        } else {
            $pdo->prepare('UPDATE orders SET total = total - ?, subtotal = subtotal - ? WHERE id = ?')->execute([$total, $total, $orderId]);
        }
        if ($prepaid > 0) {
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
        $pdo->prepare('DELETE FROM order_addon_sets WHERE order_id = ?')->execute([$orderId]);
    } catch (Throwable $e) {
        // table not created yet: nothing to delete
    }
}
