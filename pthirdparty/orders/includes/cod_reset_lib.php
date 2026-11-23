<?php
// "Set all COD received to zero" (manager only), so COD money can be entered again by hand, order by order.
//
// What counts as COD received, and is removed together (they are one money movement written in several places):
//   cod_receipts                      the COD money saved on an order (page, Excel upload)
//   account_ledger  entry 'cod'       the same money coming into an account, and lump remittances typed on the COD Amount page
//   party_ledger    lines of those    a dealer QR's "collected" line, when the COD money came into a dealer's QR
//   vendor_holds    of those          money held for vendors from a lump remittance; the per-order holds are re-calculated
// NOT touched: prepaid money and advances, orders' delivered status, bills, payments, refunds.
// Orders that have a refund (or are refunded) KEEP their COD records, otherwise the refund would pay out more than came in.
// Everything removed is saved first in cod_reset_log, so the reset can be undone, and a list can be downloaded as CSV.

require_once __DIR__ . '/ops_lib.php';

function cod_reset_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS cod_reset_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_by INT UNSIGNED NULL,
                receipts INT NOT NULL DEFAULT 0,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                payload LONGTEXT NOT NULL,
                restored_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (PDOException $e) {
        throw new RuntimeException('Could not prepare the backup table, so nothing was changed.');
    }
    $done = true;
}

/** Order ids that keep their COD records: refunded orders and orders with any refund. */
function cod_reset_kept_orders(PDO $pdo): array
{
    $ids = $pdo->query("SELECT id FROM orders WHERE status = 'refunded'")->fetchAll(PDO::FETCH_COLUMN);
    try {
        $ids = array_merge($ids, $pdo->query('SELECT DISTINCT order_id FROM refunds')->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        // no refunds table yet
    }
    return array_flip(array_map('intval', $ids));
}

/** Everything a reset would remove right now: the rows, and the numbers for the page. */
function cod_reset_collect(PDO $pdo): array
{
    $kept = cod_reset_kept_orders($pdo);
    $rec = array_values(array_filter($pdo->query('SELECT * FROM cod_receipts ORDER BY id')->fetchAll(), static fn($r) => !isset($kept[(int)$r['order_id']])));
    $led = array_values(array_filter(
        $pdo->query("SELECT * FROM account_ledger WHERE entry_type = 'cod' ORDER BY id")->fetchAll(),
        static fn($r) => $r['order_id'] === null || !isset($kept[(int)$r['order_id']])
    ));
    $ids = array_map(static fn($r) => (int)$r['id'], $led);
    $party = [];
    $holds = [];
    foreach (array_chunk($ids, 400) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $q = $pdo->prepare("SELECT * FROM party_ledger WHERE ledger_id IN ($in)");
        $q->execute($chunk);
        $party = array_merge($party, $q->fetchAll());
        $q = $pdo->prepare("SELECT * FROM vendor_holds WHERE ledger_id IN ($in)");
        $q->execute($chunk);
        $holds = array_merge($holds, $q->fetchAll());
    }
    $orders = array_values(array_unique(array_filter(array_map(static fn($r) => $r['order_id'] === null ? null : (int)$r['order_id'], array_merge($rec, $led)))));
    $lump = array_filter($led, static fn($r) => $r['order_id'] === null);
    return [
        'cod_receipts' => $rec, 'account_ledger' => $led, 'party_ledger' => $party, 'vendor_holds' => $holds, 'orders' => $orders,
        'n_receipts' => count($rec), 'amount' => round(array_sum(array_map(static fn($r) => (float)$r['amount'], $rec)), 2),
        'n_lump' => count($lump), 'lump_amount' => round(array_sum(array_map(static fn($r) => (float)$r['amount'], $lump)), 2),
        'n_ledger' => count($led), 'n_party' => count($party), 'n_holds' => count($holds), 'n_orders' => count($orders), 'kept' => count($kept),
    ];
}

/** Sets all COD received to zero. Returns the backup id. */
function cod_reset_apply(PDO $pdo, array $user): int
{
    cod_reset_ensure($pdo);
    $logId = 0;
    in_tx($pdo, function () use ($pdo, $user, &$logId) {
        $d = cod_reset_collect($pdo);
        if ($d['n_receipts'] === 0 && $d['n_ledger'] === 0) {
            throw new RuntimeException('COD received is already zero. Nothing to reset.');
        }
        $payload = ['cod_receipts' => $d['cod_receipts'], 'account_ledger' => $d['account_ledger'], 'party_ledger' => $d['party_ledger'], 'vendor_holds' => $d['vendor_holds'], 'orders' => $d['orders']];
        $pdo->prepare('INSERT INTO cod_reset_log (created_by, receipts, amount, payload) VALUES (?, ?, ?, ?)')
            ->execute([(int)$user['id'], $d['n_receipts'], $d['amount'] + $d['lump_amount'], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)]);
        $logId = (int)$pdo->lastInsertId();
        $del = static function (string $table, array $ids) use ($pdo): void {
            foreach (array_chunk($ids, 400) as $chunk) {
                $pdo->prepare("DELETE FROM $table WHERE id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')')->execute($chunk);
            }
        };
        $col = static fn(array $rows): array => array_map(static fn($r) => (int)$r['id'], $rows);
        $del('party_ledger', $col($d['party_ledger']));
        $del('vendor_holds', $col($d['vendor_holds']));
        $del('account_ledger', $col($d['account_ledger']));
        $del('cod_receipts', $col($d['cod_receipts']));
        foreach ($d['orders'] as $oid) { // the money held for vendors on each order follows the money that is left
            sync_order_holds($pdo, (int)$user['id'], (int)$oid);
        }
    });
    return $logId;
}

/** Puts a reset back. Only possible while COD received is still zero (nothing entered since), otherwise money would be counted twice. */
function cod_reset_restore(PDO $pdo, array $user, int $logId): void
{
    cod_reset_ensure($pdo);
    in_tx($pdo, function () use ($pdo, $user, $logId) {
        $st = $pdo->prepare('SELECT * FROM cod_reset_log WHERE id = ? FOR UPDATE');
        $st->execute([$logId]);
        $log = $st->fetch();
        if (!$log) {
            throw new RuntimeException('That backup was not found.');
        }
        if ($log['restored_at'] !== null) {
            throw new RuntimeException('This reset was already undone.');
        }
        $now = cod_reset_collect($pdo);
        if ($now['n_receipts'] > 0 || $now['n_ledger'] > 0) {
            throw new RuntimeException('COD money has been entered since this reset (' . $now['n_receipts'] . ' receipt' . ($now['n_receipts'] === 1 ? '' : 's') . '). Undo would count it twice. Delete the new COD entries first, or keep the new ones.');
        }
        $p = json_decode((string)$log['payload'], true);
        if (!is_array($p)) {
            throw new RuntimeException('The backup is damaged, so it cannot be restored.');
        }
        // The rows get NEW ids (an old id may have been used again since). The ledger goes first, because the party lines and holds point to it.
        $insert = static function (string $table, array $row) use ($pdo): int {
            unset($row['id']);
            $cols = array_keys($row);
            $pdo->prepare("INSERT INTO $table (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
            return (int)$pdo->lastInsertId();
        };
        $map = [];
        foreach ($p['account_ledger'] ?? [] as $row) {
            $map[(int)$row['id']] = $insert('account_ledger', $row);
        }
        foreach ($p['cod_receipts'] ?? [] as $row) {
            $insert('cod_receipts', $row);
        }
        foreach (['party_ledger', 'vendor_holds'] as $table) {
            foreach ($p[$table] ?? [] as $row) {
                if (isset($row['ledger_id']) && isset($map[(int)$row['ledger_id']])) {
                    $row['ledger_id'] = $map[(int)$row['ledger_id']];
                }
                $insert($table, $row);
            }
        }
        foreach ($p['orders'] ?? [] as $oid) {
            sync_order_holds($pdo, (int)$user['id'], (int)$oid);
        }
        $pdo->prepare('UPDATE cod_reset_log SET restored_at = NOW() WHERE id = ?')->execute([$logId]);
    });
}

/** The resets so far, newest first. */
function cod_reset_logs(PDO $pdo): array
{
    try {
        return $pdo->query('SELECT l.id, l.created_at, l.receipts, l.amount, l.restored_at, u.name FROM cod_reset_log l LEFT JOIN users u ON u.id = l.created_by ORDER BY l.id DESC LIMIT 20')->fetchAll();
    } catch (Throwable $e) {
        return []; // nothing reset yet: the table is made on the first reset
    }
}

/** The removed COD money as CSV text, to look at while entering it again by hand. */
function cod_reset_csv(PDO $pdo, int $logId): string
{
    $st = $pdo->prepare('SELECT payload FROM cod_reset_log WHERE id = ?');
    $st->execute([$logId]);
    $p = json_decode((string)$st->fetchColumn(), true);
    if (!is_array($p)) {
        throw new RuntimeException('Backup not found.');
    }
    $ord = $pdo->query('SELECT o.id, o.customer_name, o.total, o.advance_amount, o.shiprocket_order_id, o.status FROM orders o')->fetchAll(PDO::FETCH_UNIQUE);
    $acc = $pdo->query('SELECT id, name FROM accounts')->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['Order', 'Customer', 'Shiprocket Order ID', 'Status now', 'Order total', 'Advance', 'COD received (before reset)', 'Date received', 'Account', 'Source']);
    foreach ($p['cod_receipts'] ?? [] as $r) {
        $o = $ord[(int)$r['order_id']] ?? [];
        fputcsv($out, [order_no($r['order_id']), $o['customer_name'] ?? '', $o['shiprocket_order_id'] ?? '', $o['status'] ?? '', $o['total'] ?? '', $o['advance_amount'] ?? '',
            $r['amount'], $r['received_date'], $acc[(int)$r['account_id']] ?? '', $r['source'] ?? '']);
    }
    foreach ($p['account_ledger'] ?? [] as $r) {
        if ($r['order_id'] === null) { // lump remittances typed on the COD Amount page
            fputcsv($out, ['(no order)', $r['note'] ?? 'COD remittance', '', '', '', '', $r['amount'], $r['entry_date'], $acc[(int)$r['account_id']] ?? '', 'remittance']);
        }
    }
    rewind($out);
    return "\xEF\xBB\xBF" . stream_get_contents($out);
}
