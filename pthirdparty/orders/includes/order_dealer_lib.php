<?php
// One order = one dealer. The payment account is decided by the products, not by whoever fills the form.
//
//   - every product must have a dealer, and an order can hold the products of ONE dealer only
//     (a customer who buys a watch and a perfume gets two orders, each paid on its own dealer's QR)
//   - the payment account is that dealer's QR account (the first active account linked to the dealer)
//   - a salesperson cannot choose another account; the manager can
// Used by create_order(), so the rule holds even if the page script is bypassed.

/** The dealer's QR account [id, name], or null when the dealer has none. */
function order_dealer_qr(PDO $pdo, int $partyId): ?array
{
    $st = $pdo->prepare('SELECT id, name FROM accounts WHERE party_id = ? AND is_active = 1 ORDER BY id LIMIT 1');
    $st->execute([$partyId]);
    $r = $st->fetch();
    return $r ? ['id' => (int)$r['id'], 'name' => (string)$r['name']] : null;
}

/**
 * Checks the lines (each with product_id, party_id, name) and returns the account id the money must go to.
 * $posted = the account sent by the form (0 if none); only the manager's choice is honoured.
 */
function order_resolve_account(PDO $pdo, array $user, array $lines, int $posted): int
{
    $party = null;
    foreach ($lines as $l) {
        $pid = $l['party_id'] ?? null;
        if ($pid === null) {
            throw new RuntimeException('"' . ($l['name'] ?? 'A product') . '" has no dealer set. Ask the manager to set its dealer in Products.');
        }
        if ($party === null) {
            $party = (int)$pid;
        } elseif ($party !== (int)$pid) {
            $nm = $pdo->prepare('SELECT name FROM parties WHERE id IN (?, ?)');
            $nm->execute([$party, (int)$pid]);
            throw new RuntimeException('One order can have the products of one dealer only (' . implode(' and ', $nm->fetchAll(PDO::FETCH_COLUMN))
                . '). Make a separate order for "' . ($l['name'] ?? 'this product') . '".');
        }
    }
    if ($party === null) {
        throw new RuntimeException('Add at least one product.');
    }
    $qr = order_dealer_qr($pdo, $party);
    if (!$qr) {
        $pn = $pdo->prepare('SELECT name FROM parties WHERE id = ?');
        $pn->execute([$party]);
        throw new RuntimeException('Dealer "' . (string)$pn->fetchColumn() . '" has no QR account yet. Create it on the Accounts page (linked to the dealer), then try again.');
    }
    if (($user['role'] ?? '') === 'admin' && $posted > 0) {
        $ok = $pdo->prepare('SELECT id FROM accounts WHERE id = ? AND is_active = 1');
        $ok->execute([$posted]);
        if ($ok->fetchColumn()) {
            return $posted; // the manager chose another account on purpose
        }
    }
    return $qr['id'];
}
