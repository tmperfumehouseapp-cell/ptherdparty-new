<?php
// Order operations: statuses, COD receipts, delivery, refunds, delete, spreadsheet reading.
require_once __DIR__ . '/vendor_lib.php';
require_once __DIR__ . '/proof_lib.php';

function status_label(string $s): string
{
    return [
        'created'         => 'Created',
        'dispatched'      => 'Dispatched',
        'delivered'       => 'Delivered',
        'return_received' => 'Return received',
        'refunded'        => 'Refunded',
    ][$s] ?? $s;
}

function status_tag_class(string $s): string
{
    return [
        'created'         => 'tag-info',
        'dispatched'      => 'tag-warn',
        'delivered'       => 'tag-ok',
        'return_received' => 'tag-warn',
        'refunded'        => 'tag-bad',
    ][$s] ?? '';
}

function order_cod_received(PDO $pdo, int $orderId): float
{
    $st = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM cod_receipts WHERE order_id = ?');
    $st->execute([$orderId]);
    return (float)$st->fetchColumn();
}

function order_refunded(PDO $pdo, int $orderId): float
{
    $st = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE order_id = ?');
    $st->execute([$orderId]);
    return (float)$st->fetchColumn();
}

/** Runs $fn inside a transaction (or inside the caller's one). */
function in_tx(PDO $pdo, callable $fn)
{
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function valid_date(string $d): ?string
{
    $d = trim($d);
    $t = DateTime::createFromFormat('Y-m-d', $d);
    return ($t && $t->format('Y-m-d') === $d) ? $d : null;
}

function active_account_id($raw): ?int
{
    $st = db()->prepare('SELECT id FROM accounts WHERE id = ? AND is_active = 1');
    $st->execute([(int)$raw]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

/** Records COD money that arrived (full or part). Returns the amount saved. */
function add_cod_receipt(PDO $pdo, array $user, int $orderId, float $amount, int $accountId, string $date, string $source = 'manual'): float
{
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new RuntimeException('Enter an amount more than 0.');
    }
    if (!active_account_id($accountId)) {
        throw new RuntimeException('Choose the account the money came into.');
    }
    $d = valid_date($date) ?? biz_today();
    $source = $source === 'excel' ? 'excel' : 'manual';

    in_tx($pdo, function () use ($pdo, $user, $orderId, $amount, $accountId, $d, $source) {
        // the order row is locked, so two people (or two clicks) cannot both pass the checks below
        $st = $pdo->prepare('SELECT id, total, advance_amount, payment_mode, status FROM orders WHERE id = ? FOR UPDATE');
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o) {
            throw new RuntimeException('Order ' . order_no($orderId) . ' not found.');
        }
        if ($o['payment_mode'] !== 'cod') {
            throw new RuntimeException('Order ' . order_no($orderId) . ' is prepaid, so there is no COD to receive.');
        }
        if ($o['status'] === 'refunded') {
            throw new RuntimeException('Order ' . order_no($orderId) . ' is refunded.');
        }
        // COD balance = total - advance paid at order time - COD money already received
        $pending = round((float)$o['total'] - (float)$o['advance_amount'] - order_cod_received($pdo, $orderId), 2);
        if ($pending <= 0) {
            throw new RuntimeException('Order ' . order_no($orderId) . ' is already fully received.');
        }
        if ($amount > $pending + 0.005) {
            throw new RuntimeException('Order ' . order_no($orderId) . ': only ' . inr($pending) . ' is pending, you entered ' . inr($amount) . '.');
        }
        // the same row twice (a double click, or the same file uploaded again) is not counted twice
        $dup = $pdo->prepare(
            'SELECT COUNT(*) FROM cod_receipts WHERE order_id = ? AND amount = ? AND account_id = ? AND received_date = ?'
            . ($source === 'excel' ? '' : ' AND created_at >= NOW() - INTERVAL 120 SECOND')
        );
        $dup->execute([$orderId, $amount, $accountId, $d]);
        if ((int)$dup->fetchColumn() > 0) {
            throw new RuntimeException('Order ' . order_no($orderId) . ': ' . inr($amount) . ' on ' . date('d M', strtotime($d)) . ' is already recorded. Not added twice.');
        }
        $pdo->prepare('INSERT INTO cod_receipts (order_id, amount, account_id, received_date, source, created_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$orderId, $amount, $accountId, $d, $source, (int)$user['id']]);
        post_account_entry($pdo, (int)$user['id'], $accountId, $d, 'in', $amount, 'cod', $orderId, 'COD for order ' . order_no($orderId));
        sync_order_holds($pdo, (int)$user['id'], $orderId);
    });
    return $amount;
}

function mark_delivered(PDO $pdo, int $orderId): void
{
    $pdo->prepare("UPDATE orders SET status = 'delivered', delivered_at = NOW() WHERE id = ? AND status IN ('created','dispatched')")
        ->execute([$orderId]);
}

/** Marks an order delivered on a given date (from an uploaded sheet) and saves its tracking ID if the order has none. */
function mark_delivered_on(PDO $pdo, int $orderId, ?string $date, ?string $trackingId): bool
{
    $at = $date !== null ? $date . ' 12:00:00' : date('Y-m-d H:i:s');
    $st = $pdo->prepare("UPDATE orders SET status = 'delivered', delivered_at = ?,
                                tracking_id = COALESCE(NULLIF(tracking_id, ''), ?)
                         WHERE id = ? AND status IN ('created','dispatched')");
    $st->execute([$at, $trackingId !== null && $trackingId !== '' ? mb_substr($trackingId, 0, 100) : null, $orderId]);
    return $st->rowCount() > 0;
}

/** Money that really came in for an order (prepaid, COD advance, COD received), before any refund. */
function order_money_in(PDO $pdo, int $orderId): float
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM account_ledger WHERE order_id = ? AND direction = 'in' AND entry_type IN ('prepaid','cod')");
    $st->execute([$orderId]);
    return round((float)$st->fetchColumn(), 2);
}

/** Return / cancel: money goes back out of one of your accounts. Only after delivery, and never more than the customer paid. */
function refund_order(PDO $pdo, array $user, int $orderId, float $amount, int $accountId, ?string $parcelDate, string $refundDate, string $note): void
{
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new RuntimeException('Enter the refund amount.');
    }
    if (!active_account_id($accountId)) {
        throw new RuntimeException('Choose the account the refund is paid from.');
    }
    $rd = valid_date($refundDate) ?? biz_today();
    $pd = $parcelDate !== null && $parcelDate !== '' ? valid_date($parcelDate) : null;

    in_tx($pdo, function () use ($pdo, $user, $orderId, $amount, $accountId, $pd, $rd, $note) {
        $st = $pdo->prepare('SELECT id, total, status FROM orders WHERE id = ? FOR UPDATE');
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o) {
            throw new RuntimeException('Order not found.');
        }
        if (!in_array($o['status'], ['delivered', 'return_received'], true)) {
            throw new RuntimeException('A return or cancel is possible only after the order is delivered.');
        }
        $already = order_refunded($pdo, $orderId);
        $received = order_money_in($pdo, $orderId);
        $left = round($received - $already, 2);
        if ($left <= 0.004) {
            throw new RuntimeException('Nothing left to refund: ' . inr($received) . ' was received for this order and ' . inr($already) . ' is already refunded.');
        }
        if ($amount > $left + 0.005) {
            throw new RuntimeException('You can refund at most ' . inr($left) . '. The customer has paid ' . inr($received) . ' so far' . ($already > 0 ? ' and ' . inr($already) . ' is already refunded' : '') . '.');
        }
        $dup = $pdo->prepare('SELECT COUNT(*) FROM refunds WHERE order_id = ? AND amount = ? AND account_id = ? AND created_at >= NOW() - INTERVAL 120 SECOND');
        $dup->execute([$orderId, $amount, $accountId]);
        if ((int)$dup->fetchColumn() > 0) {
            throw new RuntimeException('This refund was just saved. Not added twice.');
        }
        $pdo->prepare('INSERT INTO refunds (order_id, amount, account_id, parcel_received_date, refund_date, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$orderId, $amount, $accountId, $pd, $rd, $note === '' ? null : mb_substr($note, 0, 255), (int)$user['id']]);
        post_account_entry($pdo, (int)$user['id'], $accountId, $rd, 'out', $amount, 'refund', $orderId, 'Refund for order ' . order_no($orderId));
        sync_order_holds($pdo, (int)$user['id'], $orderId);
        // everything the customer paid has gone back: the order is closed as refunded
        if ($already + $amount >= $received - 0.005) {
            $pdo->prepare("UPDATE orders SET status = 'refunded' WHERE id = ?")->execute([$orderId]);
        } else {
            $pdo->prepare("UPDATE orders SET status = 'return_received' WHERE id = ? AND status = 'delivered'")->execute([$orderId]);
        }
    });
}

/** Deletes an order and everything it created (admin, within 1 hour). */
function delete_order(PDO $pdo, int $orderId): void
{
    try {
        $g = $pdo->prepare("SELECT COUNT(*) FROM shipments WHERE order_id = ? AND status IN ('working','assigned')");
        $g->execute([$orderId]);
        if ((int)$g->fetchColumn() > 0) {
            throw new RuntimeException('This order is booked in Shiprocket. Cancel the shipment first.');
        }
    } catch (PDOException $e) {
        // shipments table not created yet: nothing to check
    }
    // units of this order that a dealer bill already covers: deleting would leave the dealer billed for goods nobody ordered
    $stS = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
    $stS->execute([$orderId]);
    if ($stS->fetchColumn() !== 'refunded') {
        try {
            require_once __DIR__ . '/dealer_lib.php';
            $ordered = [];
            $billed = [];
            $mine = [];
            $names = [];
            foreach (dealer_items($pdo) as $it) {
                $key = $it['pk'] . ':' . $it['pid'];
                $ordered[$key] = ($ordered[$key] ?? 0) + $it['qty'];
                $billed[$key] = ($billed[$key] ?? 0) + $it['disp'];
                if ($it['order_id'] === $orderId) {
                    $mine[$key] = ($mine[$key] ?? 0) + $it['qty'];
                    $names[$key] = $it['pname'];
                }
            }
            foreach ($mine as $key => $q) {
                $short = ($billed[$key] ?? 0) - (($ordered[$key] ?? 0) - $q);
                if ($short > 0) {
                    throw new RuntimeException($short . ' unit' . ($short > 1 ? 's' : '') . ' of "' . $names[$key] . '" from this order ' . ($short > 1 ? 'are' : 'is') . ' already on a dealer bill. Cancel that bill first, then delete the order.');
                }
            }
        } catch (RuntimeException $e) {
            throw $e; // the real reason to stop: a dealer bill already covers this order
        } catch (Throwable $e) {
            // the bill check itself could not run (tables or code missing): it must never block deleting a mistaken order
            error_log('delete_order bill check skipped: ' . $e->getMessage());
        }
    }
    require_once __DIR__ . '/order_auto_lib.php';
    $refFile = ref_image_of($pdo, $orderId);
    in_tx($pdo, function () use ($pdo, $orderId) {
        delete_order_vendor_rows($pdo, $orderId); // party ledger rows and held-for-vendor rows first
        $pdo->prepare('DELETE FROM account_ledger WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM cod_receipts WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM refunds WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]); // items go with it
    });
    proof_delete_for($pdo, 'order', $orderId); // the payment screenshot and its file
    require_once __DIR__ . '/order_auto_lib.php';
    auto_delete_for($pdo, $orderId); // the extra screenshots and their payment-time reads
    proof_remove_file($refFile);     // the reference image
}

/**
 * COD money that reached you as one lump (courier remittance), not tied to one order.
 * $splits = [partyId => amount]: how much of it you set aside for each vendor. The rest stays yours.
 * If the account belongs to a party, that party simply holds the money and its ledger is credited in full.
 */
function add_remittance(PDO $pdo, array $user, int $accountId, float $amount, string $date, string $note, array $splits): int
{
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new RuntimeException('Enter the amount received.');
    }
    if (!active_account_id($accountId)) {
        throw new RuntimeException('Choose the account the money came into.');
    }
    $d = valid_date($date) ?? biz_today();
    $clean = [];
    $sum = 0.0;
    $chk = $pdo->prepare('SELECT id FROM parties WHERE id = ?');
    foreach ($splits as $pid => $v) {
        $a = round(num($v), 2);
        if ($a < 0) {
            throw new RuntimeException('A vendor amount cannot be minus.');
        }
        if ($a > 0) {
            $chk->execute([(int)$pid]);
            if (!$chk->fetchColumn()) {
                throw new RuntimeException('One of the vendors was not found.');
            }
            $clean[(int)$pid] = $a;
            $sum += $a;
        }
    }
    if ($sum > $amount + 0.005) {
        throw new RuntimeException('The vendor amounts add up to ' . inr($sum) . ', which is more than the ' . inr($amount) . ' received.');
    }
    $text = 'COD remittance' . ($note !== '' ? ' - ' . mb_substr($note, 0, 200) : '');
    $dupR = $pdo->prepare("SELECT COUNT(*) FROM account_ledger WHERE entry_type = 'cod' AND order_id IS NULL AND direction = 'in' AND account_id = ? AND amount = ? AND entry_date = ? AND created_at >= NOW() - INTERVAL 120 SECOND");
    $dupR->execute([$accountId, $amount, $d]);
    if ((int)$dupR->fetchColumn() > 0) {
        throw new RuntimeException('This remittance was just saved. Not added twice.');
    }

    return (int)in_tx($pdo, function () use ($pdo, $user, $accountId, $amount, $d, $text, $clean) {
        $ledgerId = post_account_entry($pdo, (int)$user['id'], $accountId, $d, 'in', $amount, 'cod', null, $text);
        if (account_party_id($pdo, $accountId) === null) {
            $ins = $pdo->prepare("INSERT INTO vendor_holds (party_id, entry_date, amount, source, ledger_id, note, created_by) VALUES (?, ?, ?, 'remit', ?, ?, ?)");
            foreach ($clean as $pid => $a) {
                $ins->execute([$pid, $d, $a, $ledgerId, $text, (int)$user['id']]);
            }
        }
        return $ledgerId;
    });
}

/** Deletes a COD remittance and what it created. Orders' own COD entries are not touched. */
function delete_remittance(PDO $pdo, int $ledgerId): void
{
    $st = $pdo->prepare("SELECT id FROM account_ledger WHERE id = ? AND entry_type = 'cod' AND order_id IS NULL");
    $st->execute([$ledgerId]);
    if (!$st->fetchColumn()) {
        throw new RuntimeException('Remittance not found.');
    }
    in_tx($pdo, function () use ($pdo, $ledgerId) {
        $pdo->prepare('DELETE FROM party_ledger WHERE ledger_id = ?')->execute([$ledgerId]);
        $pdo->prepare('DELETE FROM vendor_holds WHERE ledger_id = ?')->execute([$ledgerId]);
        $pdo->prepare('DELETE FROM account_ledger WHERE id = ?')->execute([$ledgerId]);
    });
}

/* ---------- reading Excel / CSV uploads ---------- */

function col_index(string $ref): int
{
    preg_match('/^([A-Z]+)/', strtoupper($ref), $m);
    $n = 0;
    foreach (str_split($m[1] ?? 'A') as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

/** Reads the first sheet of an .xlsx or a .csv into rows of strings. Throws RuntimeException if unreadable. */
function read_sheet_rows(string $path, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $rows = [];
    if ($ext === 'csv' || $ext === 'txt') {
        $fh = fopen($path, 'r');
        if (!$fh) {
            throw new RuntimeException('Could not open the file.');
        }
        while (($r = fgetcsv($fh)) !== false) {
            $rows[] = array_map(fn($v) => trim((string)$v), $r);
            if (count($rows) > 5000) {
                break;
            }
        }
        fclose($fh);
        if ($rows && isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);
        }
        return $rows;
    }
    if ($ext !== 'xlsx') {
        throw new RuntimeException('Upload an .xlsx or .csv file.');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('This server cannot read .xlsx. Save the sheet as .csv and upload that.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open the Excel file.');
    }
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $x = @simplexml_load_string($ss);
        if ($x) {
            foreach ($x->si as $si) {
                $t = '';
                if (isset($si->t)) {
                    $t = (string)$si->t;
                } else {
                    foreach ($si->r as $run) {
                        $t .= (string)$run->t;
                    }
                }
                $shared[] = $t;
            }
        }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('~^xl/worksheets/[^/]+\.xml$~', $n)) {
                $sheetXml = $zip->getFromIndex($i);
                break;
            }
        }
    }
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('No sheet found in the Excel file.');
    }
    $x = @simplexml_load_string($sheetXml);
    if (!$x || !isset($x->sheetData)) {
        throw new RuntimeException('Could not read the sheet.');
    }
    foreach ($x->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $idx = col_index((string)$c['r']);
            $type = (string)$c['t'];
            if ($type === 's') {
                $val = $shared[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string)$c->is->t;
            } else {
                $val = (string)$c->v;
            }
            $cells[$idx] = trim($val);
        }
        if ($cells) {
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) {
                $line[] = $cells[$i] ?? '';
            }
            $rows[] = $line;
        }
        if (count($rows) > 5000) {
            break;
        }
    }
    return $rows;
}

/** "45123" (Excel date number) or "2026-10-24" or "24/10/2026" -> Y-m-d, or null. */
function sheet_date(string $v): ?string
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (preg_match('/^\d{5}(\.\d+)?$/', $v)) {
        $ts = ((int)floor((float)$v) - 25569) * 86400;
        return gmdate('Y-m-d', $ts);
    }
    if (preg_match('~^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$~', $v, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    $t = strtotime($v);
    return $t ? date('Y-m-d', $t) : null;
}

/* ---------- weekly groups reminder ---------- */

function groups_report_due(PDO $pdo): bool
{
    try {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM community_groups WHERE is_active = 1')->fetchColumn();
        if ($n === 0) {
            return false;
        }
        $st = $pdo->prepare("SELECT v FROM settings WHERE k = 'groups_report_done'");
        $st->execute();
        $last = $st->fetchColumn();
        $monday = date('Y-m-d', strtotime('monday this week'));
        return !$last || $last < $monday;
    } catch (Throwable $e) {
        return false;
    }
}