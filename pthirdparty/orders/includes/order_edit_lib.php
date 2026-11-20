<?php
// Manager edit of an order's products (product, quantity, sell price, add / remove lines) after the order was created.
//
// Rules
//   - the buying price of a line that keeps its product is NEVER touched (the price saved on the order stays);
//     a changed or new line takes the product's current buying price from the product database (nobody types it)
//   - one order = one dealer (same rule as New order)
//   - subtotal, discount shares, total, base cost and profit are recalculated exactly like New order does
//   - money already received is not changed; a warning says so when a prepaid total no longer matches it
//   - dealer held money is re-synced; a dealer on profit-only mode gets its QR amount recalculated
//   - every edit is saved to order_edit_log (before and after)

require_once __DIR__ . '/order_dealer_lib.php';
require_once __DIR__ . '/vendor_lib.php';
require_once __DIR__ . '/profit_mode_lib.php';

function order_edit_log_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS order_edit_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                order_id INT UNSIGNED NOT NULL,
                changed_by INT UNSIGNED NULL,
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                before_json TEXT NULL,
                after_json TEXT NULL,
                KEY idx_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (PDOException $e) {
        throw new RuntimeException('Could not prepare the edit history table, so nothing was changed.');
    }
    $done = true;
}

/** The last edits of an order, newest first (empty when there are none or the table does not exist yet). */
function order_edit_history(PDO $pdo, int $orderId, int $limit = 5): array
{
    try {
        $st = $pdo->prepare('SELECT l.changed_at, l.before_json, l.after_json, u.name FROM order_edit_log l LEFT JOIN users u ON u.id = l.changed_by WHERE l.order_id = ? ORDER BY l.id DESC LIMIT ' . (int)$limit);
        $st->execute([$orderId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Product choices for the edit form: id => [label, buying price or null, dealer]. */
function order_edit_products(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT p.id, p.name, pa.name AS dealer,
                (SELECT pp.base_price FROM product_prices pp WHERE pp.product_id = p.id ORDER BY pp.id DESC LIMIT 1) AS base
         FROM products p LEFT JOIN parties pa ON pa.id = p.party_id WHERE p.is_active = 1 ORDER BY p.name"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['id']] = ['label' => $r['name'] . ' [#' . (int)$r['id'] . ']', 'name' => $r['name'], 'base' => $r['base'] !== null ? (float)$r['base'] : null, 'dealer' => $r['dealer']];
    }
    return $out;
}

/** Saves the edited lines. Returns a message (it may end with a warning). */
function order_edit_items(PDO $pdo, array $user, int $orderId, array $post): string
{
    $o = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $o->execute([$orderId]);
    $order = $o->fetch();
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    if ($order['status'] === 'refunded') {
        throw new RuntimeException('A refunded order cannot be edited.');
    }
    if (function_exists('sr_order_locked') && sr_order_locked($pdo, $orderId)) {
        throw new RuntimeException('This order is booked in Shiprocket. Press Cancel shipment first, then edit it.');
    }

    // current lines, by id
    $cur = $pdo->prepare('SELECT oi.id, oi.product_id, oi.party_id, oi.qty, oi.base_price, oi.sell_price, p.name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id');
    $cur->execute([$orderId]);
    $before = [];
    foreach ($cur->fetchAll() as $r) {
        $before[(int)$r['id']] = $r;
    }

    $prodSt = $pdo->prepare('SELECT id, name, party_id FROM products WHERE id = ?');
    $baseSt = $pdo->prepare('SELECT base_price FROM product_prices WHERE product_id = ? ORDER BY id DESC LIMIT 1');
    $raw = $post['items'] ?? [];
    $lines = [];
    foreach ((is_array($raw) ? $raw : []) as $it) {
        if (!is_array($it)) {
            continue;
        }
        $pid = (int)($it['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $prodSt->execute([$pid]);
        $p = $prodSt->fetch();
        if (!$p) {
            throw new RuntimeException('Choose each product from the list.');
        }
        $qty = (int)($it['qty'] ?? 0);
        if ($qty < 1 || $qty > 9999) {
            throw new RuntimeException('Enter a quantity for "' . $p['name'] . '".');
        }
        $sell = round(num($it['sell_price'] ?? 0), 2);
        if ($sell <= 0) {
            throw new RuntimeException('Enter the sell price for "' . $p['name'] . '".');
        }
        $itemId = (int)($it['item_id'] ?? 0);
        if ($itemId > 0 && isset($before[$itemId]) && (int)$before[$itemId]['product_id'] === $pid) {
            $base = (float)$before[$itemId]['base_price']; // same product: the saved buying price stays
        } else {
            $baseSt->execute([$pid]);
            $b = $baseSt->fetchColumn();
            if ($b === false || (float)$b <= 0) {
                throw new RuntimeException('"' . $p['name'] . '" has no buying price on the website yet. Sync Products, then try again.');
            }
            $base = round((float)$b, 2);
        }
        $lines[] = [
            'product_id' => $pid, 'name' => $p['name'], 'party_id' => $p['party_id'] !== null ? (int)$p['party_id'] : null,
            'qty' => $qty, 'base' => $base, 'sell' => $sell, 'line' => round($qty * $sell, 2),
        ];
    }
    if (!$lines) {
        throw new RuntimeException('An order needs at least one product. To remove the whole order, use Delete.');
    }
    $newDealer = order_single_dealer($pdo, $lines);

    $subtotal = 0.0;
    foreach ($lines as $l) {
        $subtotal += $l['line'];
    }
    $subtotal = round($subtotal, 2);
    $discount = array_key_exists('discount', $post) ? round(num($post['discount']), 2) : (float)$order['discount'];
    if ($discount < 0 || $discount > $subtotal) {
        throw new RuntimeException('Discount cannot be more than the order amount.');
    }
    $given = 0.0;
    $baseTotal = 0.0;
    $profit = 0.0;
    $lastIdx = count($lines) - 1;
    foreach ($lines as $i => &$l) {
        $share = ($i === $lastIdx) ? round($discount - $given, 2) : round($subtotal > 0 ? $discount * $l['line'] / $subtotal : 0, 2);
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
    if ($order['payment_mode'] === 'cod' && (float)$order['advance_amount'] >= $total) {
        throw new RuntimeException('The advance (' . inr($order['advance_amount']) . ') must stay below the new total (' . inr($total) . ').');
    }

    // table / column preparation must happen BEFORE the transaction (in MySQL creating a table inside one ends it)
    order_edit_log_ensure($pdo);
    ledger_audit_ensure($pdo);
    profit_mode_ready($pdo);

    $warn = '';
    in_tx($pdo, function () use ($pdo, $user, $orderId, $order, $before, $lines, $subtotal, $discount, $total, $baseTotal, $profit, $newDealer, &$warn) {
        $oldDealers = array_values(array_unique(array_map(static fn($r) => (int)$r['party_id'], $before)));

        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
        $ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, party_id, qty, base_price, sell_price, discount_share, profit) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($lines as $l) {
            $ins->execute([$orderId, $l['product_id'], $l['party_id'], $l['qty'], $l['base'], $l['sell'], $l['share'], $l['profit']]);
        }
        $pdo->prepare('UPDATE orders SET subtotal = ?, discount = ?, total = ?, base_total = ?, profit = ? WHERE id = ?')
            ->execute([$subtotal, $discount, $total, $baseTotal, $profit, $orderId]);

        // dealer money that follows the order
        sync_order_holds($pdo, (int)$user['id'], $orderId);
        if (profit_mode_ready($pdo)) {
            $ps = $pdo->prepare("SELECT DISTINCT pl.party_id FROM party_ledger pl JOIN account_ledger al ON al.id = pl.ledger_id WHERE al.order_id = ? AND pl.entry_type IN ('collected','returned')");
            $ps->execute([$orderId]);
            foreach ($ps->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                if (profit_mode_on($pdo, (int)$pid)) {
                    profit_mode_apply($pdo, $user, (int)$pid, true); // re-count that dealer's QR entries from the new profit
                }
            }
        }

        // warnings: things the edit cannot fix by itself
        $rec = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM account_ledger WHERE order_id = ? AND entry_type = 'prepaid' AND direction = 'in'");
        $rec->execute([$orderId]);
        $received = (float)$rec->fetchColumn();
        if ($order['payment_mode'] === 'prepaid' && abs($received - $total) > 0.004) {
            $warn .= ' Warning: the money received was ' . inr($received) . ' but the new total is ' . inr($total) . '. Money records are not changed.';
        }
        if ($oldDealers && !in_array($newDealer, $oldDealers, true)) {
            $warn .= ' Warning: the order now belongs to another dealer. If the payment sits in the old dealer\'s QR, use "Edit account" to move it.';
        }

        $snap = static fn(array $ls) => array_map(static fn($l) => ['product' => $l['name'], 'product_id' => (int)$l['product_id'], 'qty' => (int)$l['qty'], 'buying' => (float)($l['base'] ?? $l['base_price']), 'sell' => (float)($l['sell'] ?? $l['sell_price'])], array_values($ls));
        $pdo->prepare('INSERT INTO order_edit_log (order_id, changed_by, before_json, after_json) VALUES (?, ?, ?, ?)')->execute([
            $orderId, (int)$user['id'],
            json_encode(['total' => (float)$order['total'], 'discount' => (float)$order['discount'], 'profit' => (float)$order['profit'], 'items' => $snap($before)], JSON_UNESCAPED_UNICODE),
            json_encode(['total' => $total, 'discount' => $discount, 'profit' => $profit, 'items' => $snap($lines)], JSON_UNESCAPED_UNICODE),
        ]);
    });
    return 'Order products saved. Total ' . inr($total) . ', profit ' . inr($profit) . '.' . $warn;
}
