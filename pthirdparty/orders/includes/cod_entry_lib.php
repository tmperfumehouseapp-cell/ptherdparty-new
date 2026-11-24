<?php
// Edit and delete COD money entries (manager only): the ones saved on an order (cod_receipts) and the lump remittances added on the COD Amount page.
// Everything an entry created (account ledger, dealer ledger, vendor holds) is removed or re-made with it, in one transaction.
require_once __DIR__ . '/ops_lib.php';
require_once __DIR__ . '/vendor_lib.php';

/** COD entries received between $from and $to, newest first. kind: 'order' (id = cod_receipts.id) or 'lump' (id = account_ledger.id). */
function cod_entries(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare(
        "SELECT 'order' AS kind, c.id, c.received_date AS d, c.amount, c.account_id, a.name AS account, c.order_id, COALESCE(o.customer_name, '') AS name, c.source AS source, '' AS note
         FROM cod_receipts c JOIN accounts a ON a.id = c.account_id LEFT JOIN orders o ON o.id = c.order_id WHERE c.received_date BETWEEN ? AND ?
         UNION ALL
         SELECT 'lump', l.id, l.entry_date, l.amount, l.account_id, a.name, NULL, '', 'manual', COALESCE(l.note, '')
         FROM account_ledger l JOIN accounts a ON a.id = l.account_id
         WHERE l.entry_type = 'cod' AND l.order_id IS NULL AND l.direction = 'in' AND l.entry_date BETWEEN ? AND ?"
    );
    $st->execute([$from, $to, $from, $to]);
    $rows = $st->fetchAll();
    usort($rows, static fn($a, $b) => [$b['d'], $b['id']] <=> [$a['d'], $a['id']]);
    return $rows;
}

/** The account-ledger row an order COD receipt created (same order, account, amount and date; the newest one). */
function cod_receipt_ledger_id(PDO $pdo, array $rc): ?int
{
    $st = $pdo->prepare("SELECT id FROM account_ledger WHERE order_id = ? AND entry_type = 'cod' AND direction = 'in' AND account_id = ? AND amount = ? AND entry_date = ? ORDER BY id DESC LIMIT 1");
    $st->execute([(int)$rc['order_id'], (int)$rc['account_id'], $rc['amount'], $rc['received_date']]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

/** Removes one order COD receipt with everything it created. Returns the old row. Call inside a transaction. */
function cod_receipt_remove(PDO $pdo, array $user, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM cod_receipts WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    $rc = $st->fetch();
    if (!$rc) {
        throw new RuntimeException('That COD entry was not found. Refresh the page.');
    }
    $lid = cod_receipt_ledger_id($pdo, $rc);
    if ($lid !== null) {
        $pdo->prepare('DELETE FROM party_ledger WHERE ledger_id = ?')->execute([$lid]);
        $pdo->prepare('DELETE FROM vendor_holds WHERE ledger_id = ?')->execute([$lid]);
        $pdo->prepare('DELETE FROM account_ledger WHERE id = ?')->execute([$lid]);
    }
    $pdo->prepare('DELETE FROM cod_receipts WHERE id = ?')->execute([$id]);
    sync_order_holds($pdo, (int)$user['id'], (int)$rc['order_id']);
    return $rc;
}

function cod_receipt_delete(PDO $pdo, array $user, int $id): string
{
    $rc = null;
    in_tx($pdo, function () use ($pdo, $user, $id, &$rc) {
        $rc = cod_receipt_remove($pdo, $user, $id);
    });
    return 'Deleted ' . inr((float)$rc['amount']) . ' of order ' . order_no((int)$rc['order_id']) . '. The order COD balance is back.';
}

/** Changes the amount, date and account of an order COD receipt (it is removed and saved again, in one step: nothing changes if the new values are refused). */
function cod_receipt_edit(PDO $pdo, array $user, int $id, float $amount, string $date, int $accountId): string
{
    $msg = '';
    in_tx($pdo, function () use ($pdo, $user, $id, $amount, $date, $accountId, &$msg) {
        $rc = cod_receipt_remove($pdo, $user, $id);
        add_cod_receipt($pdo, $user, (int)$rc['order_id'], $amount, $accountId, $date, (string)$rc['source']);
        $msg = 'Saved: order ' . order_no((int)$rc['order_id']) . ' COD ' . inr(round($amount, 2)) . '.';
    });
    return $msg;
}

/** Edits a lump remittance (amount, date, account, note), keeping the amounts set aside for vendors. */
function cod_lump_edit(PDO $pdo, array $user, int $ledgerId, float $amount, string $date, int $accountId, string $note): string
{
    in_tx($pdo, function () use ($pdo, $user, $ledgerId, $amount, $date, $accountId, $note) {
        $st = $pdo->prepare("SELECT id FROM account_ledger WHERE id = ? AND entry_type = 'cod' AND order_id IS NULL AND direction = 'in' FOR UPDATE");
        $st->execute([$ledgerId]);
        if (!$st->fetchColumn()) {
            throw new RuntimeException('That entry was not found. Refresh the page.');
        }
        $h = $pdo->prepare("SELECT party_id, SUM(amount) AS a FROM vendor_holds WHERE ledger_id = ? AND source = 'remit' GROUP BY party_id");
        $h->execute([$ledgerId]);
        $splits = [];
        foreach ($h->fetchAll() as $r) {
            $splits[(int)$r['party_id']] = (float)$r['a'];
        }
        delete_remittance($pdo, $ledgerId);
        add_remittance($pdo, $user, $accountId, $amount, $date, $note, $splits);
    });
    return 'Saved: COD remittance ' . inr(round($amount, 2)) . '.';
}

/** A lump remittance's note without the "COD remittance - " the panel adds. */
function cod_lump_note(string $note): string
{
    return preg_replace('/^COD remittance( - )?/', '', $note);
}
