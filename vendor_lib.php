<?php
// Vendor money: accounts that belong to a vendor (party), and money held for vendors in your own accounts.
//
// Rule 1: money that comes into (or goes out of) an account owned by a party is money the party holds for you.
//         It is written to that party's ledger at once ("collected" lowers what you owe, "returned" raises it).
// Rule 2: money that comes into your own accounts stays out of every party ledger. Instead it is "held for vendors":
//         for an order, each party's share is its base cost, vendors first. COD remittances are split by hand.

/** Order number as shown everywhere: #0001. The database keeps the plain number 1. */
function order_no($id): string
{
    return '#' . str_pad((string)(int)$id, 4, '0', STR_PAD_LEFT);
}

/** The party an account belongs to, or null when it is one of your own accounts. */
function account_party_id(PDO $pdo, int $accountId): ?int
{
    $st = $pdo->prepare('SELECT party_id FROM accounts WHERE id = ?');
    $st->execute([$accountId]);
    $p = $st->fetchColumn();
    return $p ? (int)$p : null;
}

/**
 * Writes one row to the account ledger. If the account belongs to a party and the money is order money
 * (prepaid, cod, refund), the party ledger gets the matching row. Returns the account ledger id.
 */
function post_account_entry(PDO $pdo, int $userId, int $accountId, string $date, string $dir, float $amount, string $type, ?int $orderId, string $note): int
{
    $dir = $dir === 'out' ? 'out' : 'in';
    $pdo->prepare(
        'INSERT INTO account_ledger (account_id, entry_date, direction, amount, entry_type, order_id, note, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$accountId, $date, $dir, $amount, $type, $orderId, $note, $userId]);
    $ledgerId = (int)$pdo->lastInsertId();

    $partyId = account_party_id($pdo, $accountId);
    if ($partyId !== null && in_array($type, ['prepaid', 'cod', 'refund'], true)) {
        $pdo->prepare(
            'INSERT INTO party_ledger (party_id, entry_date, entry_type, amount, account_id, ledger_id, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $partyId, $date, $dir === 'in' ? 'collected' : 'returned', $amount, $accountId, $ledgerId,
            ($dir === 'in' ? 'Customer money on your QR' : 'Refund paid from your QR') . ($orderId ? ' - order ' . order_no($orderId) : ($type === 'cod' ? ' - COD remittance' : '')),
            $userId,
        ]);
    }
    return $ledgerId;
}

/**
 * Brings the "held for vendors" rows of one order in line with the money that reached your own accounts.
 * Safe to call again and again: it only writes the difference.
 */
function sync_order_holds(PDO $pdo, int $userId, int $orderId): void
{
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN l.direction = 'in' THEN l.amount ELSE -l.amount END), 0)
         FROM account_ledger l JOIN accounts a ON a.id = l.account_id
         WHERE l.order_id = ? AND a.party_id IS NULL AND l.entry_type IN ('prepaid','cod','refund')"
    );
    $st->execute([$orderId]);
    $received = max(0.0, (float)$st->fetchColumn());

    $st = $pdo->prepare('SELECT party_id, SUM(qty * base_price) AS cost FROM order_items WHERE order_id = ? GROUP BY party_id');
    $st->execute([$orderId]);
    $cost = [];
    $totalCost = 0.0;
    foreach ($st->fetchAll() as $r) {
        $totalCost += (float)$r['cost'];
        if ($r['party_id'] !== null) {
            $cost[(int)$r['party_id']] = (float)$r['cost'];
        }
    }
    $factor = $totalCost > 0 ? min(1.0, $received / $totalCost) : 0.0;

    $st = $pdo->prepare("SELECT party_id, SUM(amount) FROM vendor_holds WHERE order_id = ? AND source = 'order' GROUP BY party_id");
    $st->execute([$orderId]);
    $have = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
        $have[(int)$r[0]] = (float)$r[1];
    }

    $ins = $pdo->prepare("INSERT INTO vendor_holds (party_id, entry_date, amount, source, order_id, note, created_by) VALUES (?, ?, ?, 'order', ?, ?, ?)");
    foreach (array_unique(array_merge(array_keys($cost), array_keys($have))) as $pid) {
        $target = round(($cost[$pid] ?? 0.0) * $factor, 2);
        $delta = round($target - ($have[$pid] ?? 0.0), 2);
        if (abs($delta) > 0.004) {
            $ins->execute([$pid, biz_today(), $delta, $orderId, 'Order ' . order_no($orderId), $userId]);
        }
    }
}

/** Removes everything vendor-related that one order created (used when the order is deleted). */
function delete_order_vendor_rows(PDO $pdo, int $orderId): void
{
    $pdo->prepare('DELETE FROM party_ledger WHERE ledger_id IN (SELECT id FROM account_ledger WHERE order_id = ?)')->execute([$orderId]);
    $pdo->prepare('DELETE FROM vendor_holds WHERE order_id = ?')->execute([$orderId]);
}

/** Per party: what you owe, what is collected on its QR, and what is held for it in your own accounts. */
function vendor_summary(PDO $pdo): array
{
    return $pdo->query(
        "SELECT p.id, p.name, p.is_active,
                COALESCE((SELECT SUM(CASE WHEN l.entry_type IN ('payment','collected') THEN -l.amount ELSE l.amount END) FROM party_ledger l WHERE l.party_id = p.id), 0) AS owed,
                COALESCE((SELECT SUM(CASE WHEN l.entry_type = 'collected' THEN l.amount WHEN l.entry_type = 'returned' THEN -l.amount END) FROM party_ledger l WHERE l.party_id = p.id), 0) AS on_qr,
                COALESCE((SELECT SUM(h.amount) FROM vendor_holds h WHERE h.party_id = p.id), 0) AS held_all,
                COALESCE((SELECT SUM(l.amount) FROM party_ledger l WHERE l.party_id = p.id AND l.entry_type = 'payment'), 0) AS paid,
                COALESCE((SELECT SUM(oi.qty * oi.base_price) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                          WHERE oi.party_id = p.id AND o.status IN ('delivered','return_received')), 0) AS delivered_cost
         FROM parties p ORDER BY p.is_active DESC, p.name"
    )->fetchAll();
}