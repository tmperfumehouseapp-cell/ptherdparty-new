<?php
// Matching for "Upload delivered list" (Shiprocket report or your own sheet).
// A row is matched to an order by ANY of its ID columns, against: the tracking ID (AWB) saved on the order,
// the Shiprocket Order ID saved on the order, or our own order number (#0168 / 168).
// If the file has a Status column only rows that say Delivered count (a full Shiprocket report also holds NEW ORDER, IN TRANSIT,
// CANCELED, RTO ... rows). If it has none, it is a delivered list and every row counts.

/**
 * Cleans an ID read from a sheet: spaces, quotes, "123.0", and "1.68E+9" style numbers.
 * A long number that Excel / the CSV turned into 1.9042E+13 has LOST its digits: it is returned as '' (unusable),
 * unless the written digits are enough to give the full number back (1.680967393E+9 is exactly 1680967393).
 */
function dl_norm(string $v): string
{
    $v = trim($v);
    $v = str_replace(["\xEF\xBB\xBF", "\xC2\xA0", "\xE2\x80\x8B", ' ', "'", '"', '`'], '', $v);
    if (preg_match('/^\d+\.0+$/', $v)) {
        return preg_replace('/\.0+$/', '', $v);
    }
    if (preg_match('/^(\d+)(?:\.(\d+))?[eE]\+?(\d+)$/', $v, $m)) {
        $mantissaDigits = strlen($m[1] . ($m[2] ?? ''));
        return $mantissaDigits >= (int)$m[3] + 1 ? sprintf('%.0f', (float)$v) : '';
    }
    return $v;
}

/**
 * Why a row must NOT be marked delivered, or '' when it can be. Rule:
 *   - the file has a Status column  -> only a status that says Delivered counts (a full Shiprocket report also holds NEW ORDER,
 *     IN TRANSIT, OUT FOR DELIVERY, CANCELED, RTO ... rows, which must stay as they are)
 *   - the file has no Status column -> it is a delivered list, every row counts
 */
function dl_skip_reason(?int $statusCol, string $status): string
{
    if ($statusCol === null) {
        return '';
    }
    if (preg_match('/^\s*delivered\b/i', $status) && !preg_match('/\b(rto|undeliver\w*|not\s+delivered)\b/i', $status)) {
        return '';
    }
    return $status === '' ? 'Status is empty, so it was not marked delivered.' : 'Status is "' . $status . '", not delivered.';
}

/** Excel column letter of a 0-based index: 0 = A, 25 = Z, 26 = AA. */
function dl_col_letter(int $i): string
{
    $l = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $l = chr(65 + ($i - 1) % 26) . $l;
    }
    return $l;
}

/**
 * Finds the columns by their EXACT header names (a Shiprocket report has 127 columns, many with "order" or "awb" in the name,
 * so loose matching would read the wrong ones). Returns ['start' => first data row, 'order' => [cols], 'track' => [cols],
 * 'status' => col|null, 'date' => col|null, 'names' => text, 'header' => bool].
 */
function dl_detect_columns(array $rows): array
{
    $norm = static function ($h): string {
        $h = strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string)$h)));
        return trim(preg_replace('/[\s_]+/', ' ', $h));
    };
    $head = array_map($norm, $rows[0] ?? []);
    $order = $track = [];
    $status = $date = null;
    foreach ($head as $i => $h) {
        if ($h === '') {
            continue;
        }
        if (preg_match('/^(order |shipment |delivery |current )?status$/', $h)) {
            $status = $status ?? $i;
        } elseif (preg_match('/^(awb( code| no\.?| number)?|tracking( id| no\.?| number| code)?|waybill( no\.?| number)?|way bill( no\.?)?|lr no\.?)$/', $h)) {
            $track[] = $i;
        } elseif (preg_match('/^((shiprocket|channel|sr) )?order( id| no\.?| number)?$|^(reference|ref) (no\.?|number|id)$/', $h)) {
            $order[] = $i;
        } elseif (preg_match('/^((order|shipment|parcel) )?delivered( on| date| at| time)?$|^delivery date$/', $h)) {
            $date = $date ?? $i;
        }
    }
    $label = static function (array $cols) use ($rows): string {
        return implode(', ', array_map(static fn($c) => 'column ' . dl_col_letter($c) . ' "' . trim(str_replace("\xEF\xBB\xBF", '', (string)($rows[0][$c] ?? ''))) . '"', $cols)) ?: 'none';
    };
    if (!$order && !$track) { // no header row: A order number, B tracking ID, C status, D date (the IDs are matched whichever way round they are)
        return ['start' => 0, 'order' => [0], 'track' => [1], 'status' => 2, 'date' => 3, 'header' => false,
                'names' => 'no header row found, so: column A = order number, column B = tracking ID, column C = status, column D = date'];
    }
    return [
        'start'  => 1, 'header' => true,
        'order'  => $order, 'track' => $track, 'status' => $status, 'date' => $date,
        'names'  => 'Order ID: ' . $label($order) . ' | Tracking / AWB: ' . $label($track)
                  . ' | Status: ' . ($status !== null ? $label([$status]) : 'none (every row counts as delivered)')
                  . ' | Delivered date: ' . ($date !== null ? $label([$date]) : 'none'),
    ];
}

/** True when a table exists (cached). */
function dl_has_table(PDO $pdo, string $t): bool
{
    static $c = [];
    if (!isset($c[$t])) {
        try {
            $pdo->query('SELECT 1 FROM ' . $t . ' LIMIT 1');
            $c[$t] = true;
        } catch (Throwable $e) {
            $c[$t] = false;
        }
    }
    return $c[$t];
}

/**
 * Looks the candidate IDs up. Returns [order row (id, status), how it matched] or null.
 * THE SHIPROCKET ORDER ID IS THE MAIN KEY (the one typed in "Shiprocket Order ID" on New order). In order of trust:
 *   1. the Shiprocket Order ID saved on the order (also one item of a list)
 *   2. the Shiprocket order id of a shipment the panel booked (shipments table)
 *   3. the tracking ID / AWB saved on the order (also one item of a list like "AWB1, AWB2")
 *   4. the AWB of a shipment the panel booked
 *   5. our own order number (short numbers only)
 * A long ID is only accepted when it points to ONE order.
 */
function dl_find_order(PDO $pdo, array $candidates, bool $hasSr): ?array
{
    $cands = [];
    foreach ($candidates as $c) {
        $c = dl_norm((string)$c);
        if ($c !== '' && !in_array($c, $cands, true)) {
            $cands[] = $c;
        }
    }
    $hasShip = dl_has_table($pdo, 'shipments');
    // "t" = the saved text without spaces and quotes. Exact match first; then one item of a comma / slash separated list.
    $lookup = static function (string $col) use ($pdo): array {
        $t = "REPLACE(REPLACE($col, ' ', ''), CHAR(39), '')";
        return [
            $pdo->prepare("SELECT id, status FROM orders WHERE $t = ? ORDER BY id DESC LIMIT 1"),
            $pdo->prepare("SELECT id, status FROM orders WHERE $t LIKE ? OR $t LIKE ? OR $t LIKE ? ORDER BY id DESC LIMIT 2"),
        ];
    };
    $find = static function (array $q, string $c): ?array {
        $q[0]->execute([$c]);
        if ($o = $q[0]->fetch()) {
            return $o;
        }
        if (strlen($c) < 8) { // short IDs are never searched inside a list: too many false matches
            return null;
        }
        $q[1]->execute([$c . ',%', '%,' . $c, '%,' . $c . ',%']);
        $rows = $q[1]->fetchAll();
        return count($rows) === 1 ? $rows[0] : null; // inside a list it must point to ONE order
    };
    $bySr = $hasSr ? $lookup('shiprocket_order_id') : null;
    $byTrack = $lookup('tracking_id');
    $byShipOrder = $hasShip ? $pdo->prepare("SELECT o.id, o.status FROM shipments s JOIN orders o ON o.id = s.order_id WHERE s.sr_order_id = ? ORDER BY s.id DESC LIMIT 1") : null;
    $byShipAwb = $hasShip ? $pdo->prepare("SELECT o.id, o.status FROM shipments s JOIN orders o ON o.id = s.order_id WHERE s.awb = ? ORDER BY s.id DESC LIMIT 1") : null;
    $byId = $pdo->prepare('SELECT id, status FROM orders WHERE id = ?');

    if ($bySr) {
        foreach ($cands as $c) {
            if ($o = $find($bySr, $c)) {
                return [$o, 'Shiprocket Order ID'];
            }
        }
    }
    if ($byShipOrder) {
        foreach ($cands as $c) {
            $byShipOrder->execute([$c]);
            if ($o = $byShipOrder->fetch()) {
                return [$o, 'Shiprocket Order ID of the shipment'];
            }
        }
    }
    foreach ($cands as $c) {
        if ($o = $find($byTrack, $c)) {
            return [$o, 'tracking ID (AWB)'];
        }
    }
    if ($byShipAwb) {
        foreach ($cands as $c) {
            $byShipAwb->execute([$c]);
            if ($o = $byShipAwb->fetch()) {
                return [$o, 'AWB of the Shiprocket shipment'];
            }
        }
    }
    foreach ($cands as $c) {
        if (preg_match('/^#?0*(\d{1,7})$/', $c, $m) && (int)$m[1] > 0) { // short numbers only: a long number is a tracking / Shiprocket ID, never ours
            $byId->execute([(int)$m[1]]);
            if ($o = $byId->fetch()) {
                return [$o, 'order number'];
            }
        }
    }
    return null;
}

/**
 * Orders of the panel that have no Shiprocket Order ID saved, so no report can ever match them.
 * $only = 'undelivered' (created / dispatched) or 'cod_pending' (COD orders still waiting for COD money). Returns ['count', 'rows'] or null.
 */
function dl_orders_without_sr(PDO $pdo, string $only, int $limit = 60): ?array
{
    try {
        $where = "(shiprocket_order_id IS NULL OR TRIM(shiprocket_order_id) = '') AND status <> 'refunded'";
        if ($only === 'undelivered') {
            $where .= " AND status IN ('created','dispatched')";
        } else {
            $where .= " AND payment_mode = 'cod' AND total - advance_amount - COALESCE((SELECT SUM(c.amount) FROM cod_receipts c WHERE c.order_id = orders.id), 0) > 0.004";
        }
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE $where")->fetchColumn();
        $rows = $pdo->query("SELECT id, order_date, customer_name, status, total, advance_amount FROM orders WHERE $where ORDER BY id DESC LIMIT " . (int)$limit)->fetchAll();
        return ['count' => $cnt, 'rows' => $rows];
    } catch (Throwable $e) {
        return null; // the Shiprocket Order ID column is not there yet
    }
}

/**
 * Marks the parcels of a Shiprocket report delivered. Returns
 * ['colInfo', 'results' (a line per row: row, ref, srid, awb, date, kind, ok, msg), 'counts', 'firstOrder'].
 * kind: ok = marked delivered | already = found, but already delivered | unmatched = no order has this Shiprocket Order ID / AWB |
 *       status = the report says it is not delivered | other = found, but returned / refunded.
 * Parcels delivered before the panel's first order are only counted (counts['before']), they cannot be panel orders.
 */
function dl_process_rows(PDO $pdo, array $rows): array
{
    $cols = dl_detect_columns($rows);
    $hasSr = function_exists('orders_has_sr_id') ? orders_has_sr_id($pdo) : false;
    $firstOrder = (string)$pdo->query('SELECT MIN(order_date) FROM orders')->fetchColumn();
    $first = static function (array $r, array $idx): string {
        foreach ($idx as $c) {
            $v = dl_norm((string)($r[$c] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    };
    $counts = ['ok' => 0, 'already' => 0, 'unmatched' => 0, 'status' => 0, 'other' => 0, 'before' => 0];
    $results = [];
    for ($i = $cols['start']; $i < count($rows); $i++) {
        $r = $rows[$i];
        $cands = [];
        foreach (array_merge($cols['order'], $cols['track']) as $c) {
            $cands[] = (string)($r[$c] ?? '');
        }
        $srid = $first($r, $cols['order']);
        $awb = $first($r, $cols['track']);
        if ($srid === '' && $awb === '') {
            continue;
        }
        $statusText = $cols['status'] !== null ? trim((string)($r[$cols['status']] ?? '')) : '';
        $date = $cols['date'] !== null ? sheet_date((string)($r[$cols['date']] ?? '')) : null;
        $line = ['row' => $i + 1, 'ref' => $srid !== '' ? $srid : $awb, 'srid' => $srid, 'awb' => $awb, 'date' => $date, 'kind' => '', 'ok' => false, 'msg' => ''];

        $why = dl_skip_reason($cols['status'], $statusText);
        if ($why !== '') {
            $line['kind'] = 'status';
            $line['msg'] = $why;
            $counts['status']++;
            $results[] = $line;
            continue;
        }
        $found = dl_find_order($pdo, $cands, $hasSr);
        if (!$found) {
            if ($firstOrder !== '' && $date !== null && $date < $firstOrder) {
                $counts['before']++;
                continue;
            }
            $line['kind'] = 'unmatched';
            $line['msg'] = 'Not in the panel: no order has this Shiprocket Order ID or AWB.';
            $counts['unmatched']++;
        } else {
            [$o, $by] = $found;
            if (in_array($o['status'], ['created', 'dispatched'], true)) {
                mark_delivered_on($pdo, (int)$o['id'], $date, $awb !== '' ? $awb : null);
                $line['kind'] = 'ok';
                $line['ok'] = true;
                $line['msg'] = order_no($o['id']) . ' marked delivered (matched by ' . $by . ').';
                $counts['ok']++;
            } elseif ($o['status'] === 'delivered') {
                $line['kind'] = 'already';
                $line['msg'] = order_no($o['id']) . ' is already delivered (matched by ' . $by . ').';
                $counts['already']++;
            } else {
                $line['kind'] = 'other';
                $line['msg'] = order_no($o['id']) . ' is ' . strtolower(status_label($o['status'])) . ', so it was left as it is.';
                $counts['other']++;
            }
        }
        $results[] = $line;
    }
    return ['colInfo' => $cols['names'], 'results' => $results, 'counts' => $counts, 'firstOrder' => $firstOrder];
}
