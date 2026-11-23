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
 * Where an ID can be saved in the panel, all are checked, in this order of trust:
 *   1. the tracking ID on the order (also when several AWBs are saved together like "AWB1, AWB2")
 *   2. the AWB of a Shiprocket shipment booked by the panel (shipments table)
 *   3. the Shiprocket Order ID on the order (also inside a list)
 *   4. the Shiprocket order id of a shipment booked by the panel
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
    $byTrack = $lookup('tracking_id');
    $bySr = $hasSr ? $lookup('shiprocket_order_id') : null;
    $byShipAwb = $hasShip ? $pdo->prepare("SELECT o.id, o.status FROM shipments s JOIN orders o ON o.id = s.order_id WHERE s.awb = ? ORDER BY s.id DESC LIMIT 1") : null;
    $byShipOrder = $hasShip ? $pdo->prepare("SELECT o.id, o.status FROM shipments s JOIN orders o ON o.id = s.order_id WHERE s.sr_order_id = ? ORDER BY s.id DESC LIMIT 1") : null;
    $byId = $pdo->prepare('SELECT id, status FROM orders WHERE id = ?');

    foreach ($cands as $c) {
        if ($o = $find($byTrack, $c)) {
            return [$o, 'tracking ID'];
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
    if ($bySr) {
        foreach ($cands as $c) {
            if ($o = $find($bySr, $c)) {
                return [$o, 'Shiprocket order ID'];
            }
        }
    }
    if ($byShipOrder) {
        foreach ($cands as $c) {
            $byShipOrder->execute([$c]);
            if ($o = $byShipOrder->fetch()) {
                return [$o, 'Shiprocket order ID of the shipment'];
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
