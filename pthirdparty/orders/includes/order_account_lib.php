<?php
// Changes the account (own account or a dealer's QR) that received an order's payment (manager only).
//
// The order-time payment lives in several places that must change together:
//   account_ledger  the money that came in (entry 'prepaid')            -> new account
//   orders.account_id                                                    -> new account (when it still points at the old one)
//   party_ledger    'collected' line, only when the account is a dealer's QR:
//        dealer QR -> other dealer QR : the line moves to the other dealer (amount follows that dealer's profit-only setting)
//        dealer QR -> own account     : the line is removed (the money is then "held for vendors" again)
//        own account -> dealer QR     : a line is added to that dealer
//   vendor_holds    re-synced from the money now sitting in own accounts
// The change is written to ledger_audit.

require_once __DIR__ . '/vendor_lib.php';
require_once __DIR__ . '/profit_mode_lib.php';

/** The payments received at order time with their account and dealer (if the account is a dealer's QR). */
function order_pay_rows(PDO $pdo, int $orderId): array
{
    $st = $pdo->prepare(
        "SELECT al.id, al.entry_date, al.amount, a.id AS account_id, a.name AS account, p.name AS dealer
         FROM account_ledger al JOIN accounts a ON a.id = al.account_id LEFT JOIN parties p ON p.id = a.party_id
         WHERE al.order_id = ? AND al.entry_type = 'prepaid' AND al.direction = 'in' ORDER BY al.id"
    );
    $st->execute([$orderId]);
    return $st->fetchAll();
}

/** Every active account for the choice list: own accounts and dealer QRs, each labelled with its dealer. */
function order_pay_accounts(PDO $pdo): array
{
    return $pdo->query(
        'SELECT a.id, a.name, p.name AS dealer FROM accounts a LEFT JOIN parties p ON p.id = a.party_id WHERE a.is_active = 1 ORDER BY (a.party_id IS NULL) DESC, p.name, a.name'
    )->fetchAll();
}

/** Returns a sentence saying what changed. */
function order_pay_account_change(PDO $pdo, array $user, int $orderId, int $ledgerId, int $newAccountId): string
{
    if (!profit_mode_ready($pdo)) {
        throw new RuntimeException('The small database update could not be added, so nothing was changed. Reload the page and try again.');
    }
    ledger_audit_ensure($pdo);
    $msg = '';
    in_tx($pdo, function () use ($pdo, $user, $orderId, $ledgerId, $newAccountId, &$msg) {
        $st = $pdo->prepare("SELECT id, account_id, amount, entry_date FROM account_ledger WHERE id = ? AND order_id = ? AND entry_type = 'prepaid' AND direction = 'in' FOR UPDATE");
        $st->execute([$ledgerId, $orderId]);
        $al = $st->fetch();
        if (!$al) {
            throw new RuntimeException('That payment was not found on this order. Refresh the page.');
        }
        $acc = $pdo->prepare('SELECT a.id, a.name, a.party_id, a.is_active, p.name AS dealer FROM accounts a LEFT JOIN parties p ON p.id = a.party_id WHERE a.id = ?');
        $acc->execute([(int)$al['account_id']]);
        $old = $acc->fetch();
        $acc->execute([$newAccountId]);
        $new = $acc->fetch();
        if (!$new || !(int)$new['is_active']) {
            throw new RuntimeException('Choose an active account.');
        }
        if ((int)$new['id'] === (int)$al['account_id']) {
            throw new RuntimeException('The payment is already in that account.');
        }
        $oldP = $old && $old['party_id'] !== null ? (int)$old['party_id'] : null;
        $newP = $new['party_id'] !== null ? (int)$new['party_id'] : null;
        $amount = (float)$al['amount'];

        $pl = $pdo->prepare("SELECT id, amount, orig_amount FROM party_ledger WHERE ledger_id = ? AND entry_type = 'collected' FOR UPDATE");
        $pl->execute([$ledgerId]);
        $line = $pl->fetch() ?: null;

        $count = function (int $party) use ($pdo, $orderId, $amount): array { // [counted amount, real amount or null]
            return profit_mode_on($pdo, $party)
                ? [profit_mode_counted($pdo, $party, $orderId, 'collected'), $amount]
                : [$amount, null];
        };

        if ($oldP !== null && $newP !== null) {
            if ($line && $oldP === $newP) {
                $pdo->prepare('UPDATE party_ledger SET account_id = ? WHERE id = ?')->execute([$newAccountId, (int)$line['id']]);
            } elseif ($line) {
                [$counted, $orig] = $count($newP);
                $pdo->prepare('UPDATE party_ledger SET party_id = ?, account_id = ?, amount = ?, orig_amount = ? WHERE id = ?')->execute([$newP, $newAccountId, $counted, $orig, (int)$line['id']]);
            }
        } elseif ($oldP !== null && $newP === null) {
            if ($line) {
                $pdo->prepare('DELETE FROM party_ledger WHERE id = ?')->execute([(int)$line['id']]);
            }
        } elseif ($oldP === null && $newP !== null) {
            [$counted, $orig] = $count($newP);
            $pdo->prepare("INSERT INTO party_ledger (party_id, entry_date, entry_type, amount, account_id, ledger_id, note, created_by) VALUES (?, ?, 'collected', ?, ?, ?, ?, ?)")
                ->execute([$newP, $al['entry_date'], $counted, $newAccountId, $ledgerId, 'Customer money on your QR - order ' . order_no($orderId), (int)$user['id']]);
            if ($orig !== null) {
                $pdo->prepare('UPDATE party_ledger SET orig_amount = ? WHERE id = ?')->execute([$orig, (int)$pdo->lastInsertId()]);
            }
        }

        $pdo->prepare('UPDATE account_ledger SET account_id = ? WHERE id = ?')->execute([$newAccountId, $ledgerId]);
        $pdo->prepare('UPDATE orders SET account_id = ? WHERE id = ? AND account_id = ?')->execute([$newAccountId, $orderId, (int)$al['account_id']]);
        sync_order_holds($pdo, (int)$user['id'], $orderId);
        ledger_audit_add(
            $pdo, $newP ?? $oldP ?? 0, $ledgerId, 'acct_edit',
            ['order' => $orderId, 'account_id' => (int)$al['account_id'], 'account' => $old['name'] ?? null, 'dealer' => $old['dealer'] ?? null, 'amount' => $amount],
            ['order' => $orderId, 'account_id' => $newAccountId, 'account' => $new['name'], 'dealer' => $new['dealer']],
            (int)$user['id']
        );
        $msg = 'Payment of ' . inr($amount) . ' moved from ' . ($old['name'] ?? 'the old account') . ' to ' . $new['name'] . '.';
    });
    return $msg;
}
