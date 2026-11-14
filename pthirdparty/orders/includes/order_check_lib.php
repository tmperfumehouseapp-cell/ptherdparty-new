<?php
// Daily order check (manager only): 1) Verify the order, 2) mark "Bill done" once the dealer has billed that parcel.
// Four optional columns on orders, added automatically the first time (same way as the reference notes columns).

function order_check_has(PDO $pdo): bool
{
    static $has = false;
    if (!$has) {
        try {
            $has = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'billed_at'")->fetch();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

function order_check_ready(PDO $pdo): bool
{
    static $tried = false;
    if (!$tried && !order_check_has($pdo)) {
        $tried = true;
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN verified_at DATETIME NULL, ADD COLUMN verified_by INT NULL, ADD COLUMN billed_at DATETIME NULL, ADD COLUMN billed_by INT NULL');
        } catch (Throwable $e) {
            error_log('order_check_ready: ' . $e->getMessage());
        }
    }
    return order_check_has($pdo);
}

/** verify | unverify | bill | unbill. A bill can only be marked on a verified order; un-verifying also clears the bill mark. */
function order_check_apply(PDO $pdo, int $orderId, string $act, int $userId): string
{
    if (!order_check_ready($pdo)) {
        throw new RuntimeException('The check columns could not be added to the orders table. Run: ALTER TABLE orders ADD COLUMN verified_at DATETIME NULL, ADD COLUMN verified_by INT NULL, ADD COLUMN billed_at DATETIME NULL, ADD COLUMN billed_by INT NULL;');
    }
    $st = $pdo->prepare('SELECT verified_at, billed_at FROM orders WHERE id = ?');
    $st->execute([$orderId]);
    $o = $st->fetch();
    if (!$o) {
        throw new RuntimeException('Order not found.');
    }
    if ($act === 'verify') {
        $pdo->prepare('UPDATE orders SET verified_at = COALESCE(verified_at, NOW()), verified_by = COALESCE(verified_by, ?) WHERE id = ?')->execute([$userId, $orderId]);
        return 'Order verified.';
    }
    if ($act === 'unverify') {
        $pdo->prepare('UPDATE orders SET verified_at = NULL, verified_by = NULL, billed_at = NULL, billed_by = NULL WHERE id = ?')->execute([$orderId]);
        return 'Verification removed.';
    }
    if ($act === 'bill') {
        if ($o['verified_at'] === null) {
            throw new RuntimeException('Verify the order first, then mark the bill done.');
        }
        $pdo->prepare('UPDATE orders SET billed_at = COALESCE(billed_at, NOW()), billed_by = COALESCE(billed_by, ?) WHERE id = ?')->execute([$userId, $orderId]);
        return 'Bill marked done.';
    }
    if ($act === 'unbill') {
        $pdo->prepare('UPDATE orders SET billed_at = NULL, billed_by = NULL WHERE id = ?')->execute([$orderId]);
        return 'Bill mark removed.';
    }
    throw new RuntimeException('Unknown action.');
}

/** Per dealer: parcels (orders with an item from that dealer), how many are verified and how many have the bill done. Refunded orders are left out. */
function order_check_by_dealer(PDO $pdo, string $from, string $to): array
{
    if (!order_check_has($pdo)) {
        return [];
    }
    $st = $pdo->prepare(
        "SELECT COALESCE(pa.name, 'Dealer not set') AS dealer,
                COUNT(DISTINCT o.id) AS parcels,
                COUNT(DISTINCT CASE WHEN o.verified_at IS NOT NULL THEN o.id END) AS verified,
                COUNT(DISTINCT CASE WHEN o.billed_at IS NOT NULL THEN o.id END) AS billed
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         LEFT JOIN parties pa ON pa.id = oi.party_id
         WHERE o.status <> 'refunded' AND o.order_date BETWEEN ? AND ?
         GROUP BY oi.party_id, pa.name
         ORDER BY parcels DESC"
    );
    $st->execute([$from, $to]);
    return $st->fetchAll();
}
