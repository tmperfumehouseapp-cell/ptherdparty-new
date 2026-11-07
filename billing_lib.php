<?php
// Bills made on the old Billing screen.
// The original billing_lib.php was lost (the copy uploaded to GitHub was a duplicate of buying_lib.php, which redeclared
// orders_tabs()/buying_*() and crashed pages). This file holds what the rest of the panel still needs from it.

/** Bill statuses that count as "live" (made, sent to the dealer, or approved). Used in SQL: WHERE status IN BILL_LIVE */
if (!defined('BILL_LIVE')) {
    define('BILL_LIVE', "('made','sent','approved')");
}

/** An unfinished bill from the old screen: count it. Adds it to what you owe the dealer. Returns the bill number. */
function billing_approve(PDO $pdo, array $user, int $billId): string
{
    return in_tx($pdo, function () use ($pdo, $user, $billId) {
        $st = $pdo->prepare("SELECT id, party_id, bill_no, bill_date, total_amount FROM bills WHERE id = ? AND status IN ('made','sent') FOR UPDATE");
        $st->execute([$billId]);
        $b = $st->fetch();
        if (!$b) {
            throw new RuntimeException('This bill is not waiting any more.');
        }
        $pdo->prepare("UPDATE bills SET status = 'approved', decided_at = NOW(), decided_by = ? WHERE id = ?")->execute([(int)$user['id'], $billId]);
        $has = $pdo->prepare("SELECT COUNT(*) FROM party_ledger WHERE bill_id = ? AND entry_type = 'bill'");
        $has->execute([$billId]);
        if ((int)$has->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO party_ledger (party_id, entry_date, entry_type, amount, bill_id, note, created_by) VALUES (?, ?, 'bill', ?, ?, ?, ?)")
                ->execute([(int)$b['party_id'], $b['bill_date'], $b['total_amount'], $billId, 'Bill ' . $b['bill_no'], (int)$user['id']]);
        }
        return (string)$b['bill_no'];
    });
}

/** An unfinished bill from the old screen: close it without counting it ($status is 'cancelled'). Returns the bill number. */
function billing_close(PDO $pdo, array $user, int $billId, string $status, string $note = ''): string
{
    return in_tx($pdo, function () use ($pdo, $user, $billId, $status) {
        $st = $pdo->prepare("SELECT id, bill_no FROM bills WHERE id = ? AND status IN ('made','sent') FOR UPDATE");
        $st->execute([$billId]);
        $b = $st->fetch();
        if (!$b) {
            throw new RuntimeException('This bill is not waiting any more.');
        }
        $pdo->prepare('UPDATE bills SET status = ?, decided_at = NOW(), decided_by = ? WHERE id = ?')->execute([$status === 'cancelled' ? 'cancelled' : $status, (int)$user['id'], $billId]);
        return (string)$b['bill_no'];
    });
}
