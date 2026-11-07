<?php
// Edit / delete of dealer payments (party_ledger rows of type 'payment') with a full audit trail.
//
// A payment lives in three places that must stay in step:
//   party_ledger     the dealer's ledger line (this is what the dealer balance is added up from)
//   account_ledger   the money that left your account ('party_payment')
//   payment_proofs   the optional screenshot (kind 'party_payment', ref = party_ledger.id)
// Balances are always added up from those rows, so changing or removing them re-calculates everything.
// Every edit and delete is written to ledger_audit first, so the old values can always be looked up.

/** Creates the audit table the first time it is needed. */
function ledger_audit_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ledger_audit (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                party_id INT UNSIGNED NOT NULL,
                ledger_id INT UNSIGNED NOT NULL,
                action VARCHAR(10) NOT NULL,
                before_json TEXT NULL,
                after_json TEXT NULL,
                changed_by INT UNSIGNED NULL,
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_party (party_id),
                KEY idx_ledger (ledger_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (PDOException $e) {
        throw new RuntimeException('Could not prepare the change history table, so nothing was changed.');
    }
    $done = true;
}

function ledger_audit_add(PDO $pdo, int $partyId, int $ledgerId, string $action, ?array $before, ?array $after, int $userId): void
{
    $pdo->prepare('INSERT INTO ledger_audit (party_id, ledger_id, action, before_json, after_json, changed_by) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([
            $partyId, $ledgerId, $action,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            $userId,
        ]);
}

/** One payment with its dealer, account and screenshot, or null when it is not an editable payment. */
function ledger_payment_load(PDO $pdo, int $id, bool $lock = false): ?array
{
    $kind = dealer_has_paykind($pdo) ? 'l.pay_kind' : 'NULL';
    $st = $pdo->prepare(
        "SELECT l.id, l.party_id, l.entry_date, l.entry_type, l.amount, l.account_id, l.note, l.ledger_id, l.created_at, $kind AS pay_kind,
                p.name AS party_name, a.name AS account_name,
                pp.id AS proof_id, pp.method AS pay_method, pp.file AS proof_file
         FROM party_ledger l
         JOIN parties p ON p.id = l.party_id
         LEFT JOIN accounts a ON a.id = l.account_id
         LEFT JOIN payment_proofs pp ON pp.kind = 'party_payment' AND pp.ref_id = l.id
         WHERE l.id = ? AND l.entry_type = 'payment'" . ($lock ? ' FOR UPDATE' : '')
    );
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/** The matching "money out" line on the account (it has no link to the ledger line, so it is matched by its values). */
function ledger_account_row(PDO $pdo, array $p): ?int
{
    $st = $pdo->prepare(
        "SELECT id FROM account_ledger
         WHERE entry_type = 'party_payment' AND direction = 'out' AND party_id = ? AND account_id = ? AND entry_date = ? AND amount = ?
         ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_at, ?)) LIMIT 1"
    );
    $st->execute([(int)$p['party_id'], (int)$p['account_id'], $p['entry_date'], $p['amount'], $p['created_at']]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

/** Only hand-entered dealer payments can be changed here. Bills, opening balances and QR money are managed where they come from. */
function ledger_payment_guard(?array $p): array
{
    if (!$p) {
        throw new RuntimeException('Payment not found. It may already be deleted.');
    }
    if ($p['ledger_id'] !== null) {
        throw new RuntimeException('This entry comes from an order. Change it from that order.');
    }
    return $p;
}

function ledger_snapshot(array $p): array
{
    return [
        'entry_date' => $p['entry_date'], 'amount' => (float)$p['amount'], 'account' => $p['account_name'],
        'method' => $p['pay_method'], 'note' => $p['note'], 'pay_kind' => $p['pay_kind'], 'screenshot' => $p['proof_file'],
    ];
}

/**
 * Saves a corrected payment. Returns a warning text when something could not be matched, '' when all is well.
 * $newProof: an already saved screenshot file name, or null to keep the current one.
 */
function ledger_payment_update(PDO $pdo, array $user, int $id, int $account, float $amount, string $date, string $method, string $note, ?string $kind, ?string $newProof): string
{
    ledger_audit_ensure($pdo);
    $warn = '';
    $oldFile = null;
    in_tx($pdo, function () use ($pdo, $user, $id, $account, $amount, $date, $method, $note, $kind, $newProof, &$warn, &$oldFile) {
        $p = ledger_payment_guard(ledger_payment_load($pdo, $id, true));
        $before = ledger_snapshot($p);
        $accRow = ledger_account_row($pdo, $p);

        $sets = 'entry_date = ?, amount = ?, account_id = ?, note = ?';
        $args = [$date, $amount, $account, $note === '' ? null : mb_substr($note, 0, 255)];
        if ($kind !== null && dealer_has_paykind($pdo)) {
            $sets .= ', pay_kind = ?';
            $args[] = $kind;
        }
        $args[] = $id;
        $pdo->prepare("UPDATE party_ledger SET $sets WHERE id = ?")->execute($args);

        if ($accRow !== null) {
            $pdo->prepare('UPDATE account_ledger SET account_id = ?, entry_date = ?, amount = ?, note = ? WHERE id = ?')
                ->execute([$account, $date, $amount, 'Paid to ' . $p['party_name'] . ' (' . $method . ')' . ($note !== '' ? ' - ' . $note : ''), $accRow]);
        } else {
            $warn = 'The matching line on the account was not found, so only the dealer ledger was corrected. Check the account page.';
        }

        $file = $p['proof_file'];
        if ($p['proof_id'] !== null) {
            if ($newProof !== null) {
                $oldFile = $p['proof_file'];
                $file = $newProof;
                $pdo->prepare('UPDATE payment_proofs SET method = ?, file = ? WHERE id = ?')->execute([$method, $newProof, (int)$p['proof_id']]);
            } else {
                $pdo->prepare('UPDATE payment_proofs SET method = ? WHERE id = ?')->execute([$method, (int)$p['proof_id']]);
            }
        } else {
            proof_add($pdo, 'party_payment', $id, $method, $newProof, (int)$user['id']);
            $file = $newProof;
        }

        $after = [
            'entry_date' => $date, 'amount' => $amount, 'account_id' => $account, 'method' => $method,
            'note' => $note === '' ? null : $note, 'pay_kind' => $kind ?? $p['pay_kind'], 'screenshot' => $file,
        ];
        ledger_audit_add($pdo, (int)$p['party_id'], $id, 'edit', $before, $after, (int)$user['id']);
    });
    if ($oldFile) {
        proof_remove_file($oldFile);
    }
    return $warn;
}

/** Deletes a payment everywhere it was written. The values are kept in ledger_audit. Returns a warning text or ''. */
function ledger_payment_delete(PDO $pdo, array $user, int $id): string
{
    ledger_audit_ensure($pdo);
    $warn = '';
    $file = null;
    in_tx($pdo, function () use ($pdo, $user, $id, &$warn, &$file) {
        $p = ledger_payment_guard(ledger_payment_load($pdo, $id, true));
        $accRow = ledger_account_row($pdo, $p);
        ledger_audit_add($pdo, (int)$p['party_id'], $id, 'delete', ledger_snapshot($p), null, (int)$user['id']);
        if ($accRow !== null) {
            $pdo->prepare('DELETE FROM account_ledger WHERE id = ?')->execute([$accRow]);
        } else {
            $warn = 'The matching line on the account was not found. Check the account page.';
        }
        $file = $p['proof_file'];
        $pdo->prepare("DELETE FROM payment_proofs WHERE kind = 'party_payment' AND ref_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM party_ledger WHERE id = ? AND entry_type = 'payment'")->execute([$id]);
    });
    if ($file) {
        proof_remove_file($file);
    }
    return $warn;
}
