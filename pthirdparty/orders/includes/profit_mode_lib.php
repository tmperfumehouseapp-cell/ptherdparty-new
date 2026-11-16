<?php
// "Profit only" mode for one dealer (party), switched on by the manager on that dealer's ledger page.
//
// Normally, when a customer pays on a dealer's own QR, the dealer's balance goes down by the WHOLE amount ('collected').
// In profit-only mode it goes down by the ORDER PROFIT only, once per order (the first QR entry of the order carries the
// profit, later QR entries of the same order carry 0). A refund paid from the dealer's QR ('returned') works the same way, in reverse.
// The real amount of every changed entry is kept in party_ledger.orig_amount and in ledger_audit, so Turn off restores it exactly.
// Other dealers are never touched. Entries without an order (lump COD money) are left as they are.

require_once __DIR__ . '/ledger_edit_lib.php';

function profit_mode_ready(PDO $pdo): bool
{
    static $tried = false;
    $has = static function (PDO $pdo): bool {
        try {
            return (bool)$pdo->query("SHOW COLUMNS FROM parties LIKE 'profit_only'")->fetch()
                && (bool)$pdo->query("SHOW COLUMNS FROM party_ledger LIKE 'orig_amount'")->fetch();
        } catch (Throwable $e) {
            return false;
        }
    };
    if ($has($pdo)) {
        return true;
    }
    if (!$tried) {
        $tried = true;
        try {
            if (!$pdo->query("SHOW COLUMNS FROM parties LIKE 'profit_only'")->fetch()) {
                $pdo->exec('ALTER TABLE parties ADD COLUMN profit_only TINYINT(1) NOT NULL DEFAULT 0');
            }
            if (!$pdo->query("SHOW COLUMNS FROM party_ledger LIKE 'orig_amount'")->fetch()) {
                $pdo->exec('ALTER TABLE party_ledger ADD COLUMN orig_amount DECIMAL(14,2) NULL');
            }
        } catch (Throwable $e) {
            error_log('profit_mode_ready: ' . $e->getMessage());
        }
    }
    return $has($pdo);
}

function profit_mode_on(PDO $pdo, int $partyId): bool
{
    if (!profit_mode_ready($pdo)) {
        return false;
    }
    $st = $pdo->prepare('SELECT profit_only FROM parties WHERE id = ?');
    $st->execute([$partyId]);
    return (int)$st->fetchColumn() === 1;
}

/** What a new QR entry of this order counts for the dealer: the order profit the first time, 0 after that. */
function profit_mode_counted(PDO $pdo, int $partyId, int $orderId, string $entryType): float
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM party_ledger pl JOIN account_ledger al ON al.id = pl.ledger_id
         WHERE pl.party_id = ? AND al.order_id = ? AND pl.entry_type = ?'
    );
    $st->execute([$partyId, $orderId, $entryType]);
    if ((int)$st->fetchColumn() > 0) {
        return 0.0;
    }
    $st = $pdo->prepare('SELECT profit FROM orders WHERE id = ?');
    $st->execute([$orderId]);
    return max(0.0, round((float)$st->fetchColumn(), 2));
}

/** Turns the mode on (recalculates this dealer's existing QR entries) or off (restores the real amounts). Returns how many entries changed. */
function profit_mode_apply(PDO $pdo, array $user, int $partyId, bool $on): int
{
    if (!profit_mode_ready($pdo)) {
        throw new RuntimeException('The two small database columns could not be added. Nothing was changed.');
    }
    ledger_audit_ensure($pdo);
    $changed = 0;
    in_tx($pdo, function () use ($pdo, $user, $partyId, $on, &$changed) {
        $pdo->prepare('UPDATE parties SET profit_only = ? WHERE id = ?')->execute([$on ? 1 : 0, $partyId]);
        if ($on) {
            $st = $pdo->prepare(
                "SELECT pl.id, pl.entry_type, pl.amount, pl.orig_amount, al.order_id, o.profit
                 FROM party_ledger pl JOIN account_ledger al ON al.id = pl.ledger_id LEFT JOIN orders o ON o.id = al.order_id
                 WHERE pl.party_id = ? AND pl.entry_type IN ('collected','returned') AND al.order_id IS NOT NULL
                 ORDER BY al.order_id, pl.entry_type, pl.id FOR UPDATE"
            );
            $st->execute([$partyId]);
            $seen = [];
            $up = $pdo->prepare('UPDATE party_ledger SET amount = ?, orig_amount = COALESCE(orig_amount, ?) WHERE id = ?');
            foreach ($st->fetchAll() as $r) {
                $key = $r['order_id'] . ':' . $r['entry_type'];
                $new = isset($seen[$key]) ? 0.0 : max(0.0, round((float)$r['profit'], 2));
                $seen[$key] = true;
                $real = $r['orig_amount'] !== null ? (float)$r['orig_amount'] : (float)$r['amount'];
                if (abs((float)$r['amount'] - $new) < 0.005) {
                    continue;
                }
                $up->execute([$new, $real, (int)$r['id']]);
                ledger_audit_add($pdo, $partyId, (int)$r['id'], 'profit_on', ['amount' => (float)$r['amount']], ['amount' => $new], (int)$user['id']);
                $changed++;
            }
        } else {
            $st = $pdo->prepare('SELECT id, amount, orig_amount FROM party_ledger WHERE party_id = ? AND orig_amount IS NOT NULL FOR UPDATE');
            $st->execute([$partyId]);
            $up = $pdo->prepare('UPDATE party_ledger SET amount = ?, orig_amount = NULL WHERE id = ?');
            foreach ($st->fetchAll() as $r) {
                $up->execute([(float)$r['orig_amount'], (int)$r['id']]);
                ledger_audit_add($pdo, $partyId, (int)$r['id'], 'profit_off', ['amount' => (float)$r['amount']], ['amount' => (float)$r['orig_amount']], (int)$user['id']);
                $changed++;
            }
        }
    });
    return $changed;
}
