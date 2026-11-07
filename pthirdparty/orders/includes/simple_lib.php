<?php
// Simple numbers for the three main sections: Dealers, Billing, COD Amount.
// Nothing here is a new ledger. It only reads what the panel already keeps:
//   dealer owes you   = opening + bills - payments - customer money that landed on the dealer's own QR
//   COD still to come = COD orders (total - advance - COD received) - lump COD remittances

/** True once migrate_simple_billing.sql has been run. */
function simple_has_details(PDO $pdo): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool)$pdo->query("SHOW COLUMNS FROM bills LIKE 'details'")->fetch();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** Every dealer with what you owe now (negative = they hold your money). Biggest first. */
function simple_dealer_rows(PDO $pdo): array
{
    return $pdo->query(
        "SELECT p.id, p.name, p.is_active,
                COALESCE(SUM(CASE WHEN l.entry_type IN ('payment','collected') THEN -l.amount ELSE l.amount END), 0) AS balance
         FROM parties p LEFT JOIN party_ledger l ON l.party_id = p.id
         GROUP BY p.id, p.name, p.is_active ORDER BY balance DESC, p.name"
    )->fetchAll();
}

function simple_balance(PDO $pdo, int $partyId): float
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type IN ('payment','collected') THEN -amount ELSE amount END), 0) FROM party_ledger WHERE party_id = ?");
    $st->execute([$partyId]);
    return round((float)$st->fetchColumn(), 2);
}

/** COD money not received yet, and how many orders still wait for it. */
function simple_cod_pending(PDO $pdo): array
{
    $r = $pdo->query(
        "SELECT COALESCE(SUM(GREATEST(0, x.due)), 0) AS due, COALESCE(SUM(x.due > 0.004), 0) AS n FROM (
            SELECT o.total - o.advance_amount - COALESCE((SELECT SUM(c.amount) FROM cod_receipts c WHERE c.order_id = o.id), 0) AS due
            FROM orders o WHERE o.payment_mode = 'cod' AND o.status <> 'refunded'
         ) x"
    )->fetch();
    // a lump remittance (money that arrived without an order number) lowers what is still to come
    $lump = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM account_ledger WHERE entry_type = 'cod' AND order_id IS NULL AND direction = 'in'")->fetchColumn();
    return ['due' => max(0.0, round((float)$r['due'] - $lump, 2)), 'orders' => (int)$r['n']];
}

/** COD money received per date between $from and $to: [['d' => date, 'amount' => x, 'n' => entries], ...] newest first. */
function simple_cod_by_date(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare(
        "SELECT d, SUM(a) AS amount, COUNT(*) AS n FROM (
            SELECT received_date AS d, amount AS a FROM cod_receipts WHERE received_date BETWEEN ? AND ?
            UNION ALL
            SELECT entry_date, amount FROM account_ledger WHERE entry_type = 'cod' AND order_id IS NULL AND direction = 'in' AND entry_date BETWEEN ? AND ?
         ) x GROUP BY d ORDER BY d DESC"
    );
    $st->execute([$from, $to, $from, $to]);
    return $st->fetchAll();
}
