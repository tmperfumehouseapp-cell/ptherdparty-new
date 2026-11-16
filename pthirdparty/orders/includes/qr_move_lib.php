<?php
// Moves one "Customer money on your QR" entry from one dealer to another (manager only), for money recorded on the wrong dealer's QR.
//
// The entry lives in two places that must move together:
//   account_ledger  the money that came into a dealer's QR account  -> goes to the other dealer's QR account
//   party_ledger    the dealer's ledger line (this is what the dealer balance is added up from) -> goes to the other dealer
// The amount counted follows the receiving dealer's setting (whole amount, or profit only), starting from the real amount paid.
// The move is written to ledger_audit, so the old dealer, account and amount can always be looked up.

require_once __DIR__ . '/profit_mode_lib.php';

/** QR accounts the entry can be moved to: every active account that belongs to a dealer other than $exceptParty. */
function qr_move_targets(PDO $pdo, int $exceptParty): array
{
    $st = $pdo->prepare(
        'SELECT a.id, a.name AS account, p.name AS dealer FROM accounts a JOIN parties p ON p.id = a.party_id
         WHERE a.is_active = 1 AND a.party_id IS NOT NULL AND a.party_id <> ? ORDER BY p.name, a.name'
    );
    $st->execute([$exceptParty]);
    return $st->fetchAll();
}

/** Returns a sentence saying what was moved. */
function qr_move_entry(PDO $pdo, array $user, int $entryId, int $fromParty, int $toAccountId): string
{
    if (!profit_mode_ready($pdo)) {
        throw new RuntimeException('The small database update could not be added, so nothing was moved. Reload the page and try again.');
    }
    ledger_audit_ensure($pdo);
    $msg = '';
    in_tx($pdo, function () use ($pdo, $user, $entryId, $fromParty, $toAccountId, &$msg) {
        $st = $pdo->prepare(
            "SELECT pl.id, pl.entry_type, pl.amount, pl.orig_amount, pl.account_id, pl.ledger_id, al.order_id, pf.name AS from_name
             FROM party_ledger pl JOIN account_ledger al ON al.id = pl.ledger_id JOIN parties pf ON pf.id = pl.party_id
             WHERE pl.id = ? AND pl.party_id = ? AND pl.entry_type IN ('collected','returned') FOR UPDATE"
        );
        $st->execute([$entryId, $fromParty]);
        $row = $st->fetch();
        if (!$row) {
            throw new RuntimeException('This entry cannot be moved (it is not customer money on a dealer QR, or it was already moved). Refresh the page.');
        }
        $to = $pdo->prepare('SELECT a.id, a.name, a.party_id, p.name AS party_name FROM accounts a JOIN parties p ON p.id = a.party_id WHERE a.id = ? AND a.is_active = 1');
        $to->execute([$toAccountId]);
        $acc = $to->fetch();
        if (!$acc || (int)$acc['party_id'] === $fromParty) {
            throw new RuntimeException('Choose the other dealer\'s QR account to move it to.');
        }
        $newParty = (int)$acc['party_id'];

        // start from the real amount paid, then count it the way the receiving dealer is set up
        $real = $row['orig_amount'] !== null ? (float)$row['orig_amount'] : (float)$row['amount'];
        $counted = $real;
        $orig = null;
        if ($row['order_id'] && profit_mode_on($pdo, $newParty)) {
            $counted = profit_mode_counted($pdo, $newParty, (int)$row['order_id'], (string)$row['entry_type']);
            $orig = $real;
        }

        $pdo->prepare('UPDATE account_ledger SET account_id = ? WHERE id = ?')->execute([(int)$acc['id'], (int)$row['ledger_id']]);
        $pdo->prepare('UPDATE party_ledger SET party_id = ?, account_id = ?, amount = ?, orig_amount = ? WHERE id = ?')
            ->execute([$newParty, (int)$acc['id'], $counted, $orig, (int)$row['id']]);
        ledger_audit_add(
            $pdo, $fromParty, (int)$row['id'], 'move',
            ['party_id' => $fromParty, 'party' => $row['from_name'], 'account_id' => (int)$row['account_id'], 'amount' => (float)$row['amount'], 'real_amount' => $real],
            ['party_id' => $newParty, 'party' => $acc['party_name'], 'account_id' => (int)$acc['id'], 'amount' => $counted],
            (int)$user['id']
        );
        $msg = 'Moved ' . ($row['order_id'] ? 'the entry of order ' . order_no($row['order_id']) : 'the entry') . ' (customer paid ' . inr($real) . ') from ' . $row['from_name'] . ' to ' . $acc['party_name'] . '.';
    });
    return $msg;
}
