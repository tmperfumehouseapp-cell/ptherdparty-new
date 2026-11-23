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

/**
 * Looks the candidate IDs up. Returns [order row (id, status), how it matched] or null.
 * Order of trust: tracking ID, Shiprocket Order ID, our own order number.
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
    $byTrack = $pdo->prepare("SELECT id, status FROM orders WHERE REPLACE(REPLACE(tracking_id, ' ', ''), CHAR(39), '') = ? ORDER BY id DESC LIMIT 1");
    $bySr = $hasSr ? $pdo->prepare("SELECT id, status FROM orders WHERE REPLACE(shiprocket_order_id, ' ', '') = ? ORDER BY id DESC LIMIT 1") : null;
    $byId = $pdo->prepare('SELECT id, status FROM orders WHERE id = ?');
    foreach ($cands as $c) {
        $byTrack->execute([$c]);
        if ($o = $byTrack->fetch()) {
            return [$o, 'tracking ID'];
        }
    }
    if ($bySr) {
        foreach ($cands as $c) {
            $bySr->execute([$c]);
            if ($o = $bySr->fetch()) {
                return [$o, 'Shiprocket order ID'];
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
