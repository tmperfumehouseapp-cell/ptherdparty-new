<?php
// Matching for "Upload delivered list" (Shiprocket report or your own sheet).
// A row is matched to an order by ANY of its ID columns, against: the tracking ID (AWB) saved on the order,
// the Shiprocket Order ID saved on the order, or our own order number (#0168 / 168).
// Every row of the list counts as delivered by default (it is a delivered list); only rows whose status clearly says
// RTO / return / cancel / undelivered / lost, or says the parcel is still on its way (in transit, out for delivery...), are skipped.

/** Cleans an ID read from a sheet: spaces, quotes, "123.0", and "1.68E+9" style numbers. */
function dl_norm(string $v): string
{
    $v = trim($v);
    $v = str_replace(["\xC2\xA0", "\xE2\x80\x8B", ' ', "'", '"', '`'], '', $v);
    if (preg_match('/^\d+\.0+$/', $v)) {
        $v = preg_replace('/\.0+$/', '', $v);
    } elseif (preg_match('/^\d+(\.\d+)?[eE]\+?\d+$/', $v)) {
        $v = sprintf('%.0f', (float)$v); // a long number that Excel turned into 1.68096739E+9
    }
    return $v;
}

/** True when the status text says the parcel did NOT reach the customer. */
function dl_bad_status(string $status): bool
{
    return $status !== '' && (bool)preg_match('/\b(rto|return(ed)?|cancel(l?ed)?|undeliver\w*|not\s+delivered|lost|damag\w*|failed|refus\w*|in\s*transit|out\s+for\s+delivery|picked(\s*up)?|pick\s*up|pending|shipped|dispatched|manifest\w*|awaiting|processing|ready\s+to\s+ship)\b/i', $status);
}

/** Finds the columns by their header names. Returns ['start' => first data row, 'order' => [cols], 'track' => [cols], 'status' => col|null, 'date' => col|null, 'names' => text]. */
function dl_detect_columns(array $rows): array
{
    $head = array_map(static fn($h) => strtolower(trim((string)$h)), $rows[0] ?? []);
    $order = $track = [];
    $status = $date = null;
    $hasHead = false;
    foreach ($head as $i => $h) {
        if ($h === '') {
            continue;
        }
        if (preg_match('/status/', $h)) {
            $status = $status ?? $i;
            $hasHead = true;
        } elseif (preg_match('/awb|tracking|waybill|way bill/', $h)) {
            $track[] = $i;
            $hasHead = true;
        } elseif (preg_match('/order|reference|ref no|ref\.?\s*id/', $h)) {
            $order[] = $i;
            $hasHead = true;
        } elseif (preg_match('/date|delivered/', $h)) {
            $date = $date ?? $i;
            $hasHead = true;
        }
    }
    if (!$hasHead) { // no header: A order number, B tracking ID, C status, D date
        return ['start' => 0, 'order' => [0], 'track' => [1], 'status' => 2, 'date' => 3, 'names' => 'no header row: A = order number, B = tracking ID, C = status, D = date'];
    }
    $names = static function (array $cols) use ($rows): string {
        return implode(', ', array_map(static fn($c) => '"' . trim((string)($rows[0][$c] ?? '')) . '"', $cols)) ?: 'none';
    };
    return [
        'start'  => 1,
        'order'  => $order,
        'track'  => $track,
        'status' => $status,
        'date'   => $date,
        'names'  => 'order ID: ' . $names($order) . ' | tracking: ' . $names($track) . ' | status: ' . ($status !== null ? $names([$status]) : 'none (every row counts as delivered)') . ' | date: ' . ($date !== null ? $names([$date]) : 'none'),
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
