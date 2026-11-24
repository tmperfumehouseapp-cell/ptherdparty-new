<?php
// Small status tags on the Orders page (manager): where each parcel is and where its COD money is.
//
// The data comes from two places:
//   - the panel itself: order status (delivered, refunded), payment mode, COD money entered on the order (cod_receipts)
//   - Shiprocket, saved per order in sr_status: the parcel status (IN TRANSIT, CANCELED, RTO ...), and for COD the CRF id / UTR / remittance date.
//     It is filled by uploading Shiprocket's all-orders report (or the COD report) and kept fresh for open orders by the automatic sync.
//
// Ship tags:  Delivered | In transit | Not shipped yet | Cancelled / RTO | (no Shiprocket info)
// COD tags (COD orders only):
//   COD received      the COD money is entered on the order in the panel
//   COD remitted      Shiprocket has paid it out (UTR) but it is NOT entered in the panel yet  <- the ones to enter by hand
//   COD initiated     Shiprocket created the remittance (CRF id) but no UTR yet
//   COD pending       delivered, nothing from Shiprocket yet
// The SQL fragments and the PHP chips below follow the SAME rules (they are tested against each other).

function srs_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sr_status (
                order_id INT UNSIGNED NOT NULL PRIMARY KEY,
                status VARCHAR(100) NULL,
                pay VARCHAR(20) NULL,
                awb VARCHAR(60) NULL,
                delivered_on DATE NULL,
                crf VARCHAR(40) NULL,
                utr VARCHAR(80) NULL,
                remit_date DATE NULL,
                remit_amount DECIMAL(12,2) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                source VARCHAR(20) NULL,
                status_date DATE NULL,
                shipped_on DATE NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        // every COD payout line of Shiprocket's reports, whether or not the parcel is a panel order (so "COD received" matches Shiprocket exactly)
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sr_remit (
                ref VARCHAR(80) NOT NULL PRIMARY KEY,
                utr VARCHAR(80) NULL,
                crf VARCHAR(40) NULL,
                remit_date DATE NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                awb VARCHAR(60) NULL,
                order_id INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_date (remit_date),
                KEY idx_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        foreach (['status_date', 'shipped_on'] as $col) { // tables made by the first version get the date columns
            if (!$pdo->query("SHOW COLUMNS FROM sr_status LIKE '$col'")->fetch()) {
                $pdo->exec("ALTER TABLE sr_status ADD COLUMN $col DATE NULL");
            }
        }
    } catch (PDOException $e) {
        throw new RuntimeException('Could not prepare the Shiprocket status table.');
    }
    $done = true;
}

/** A cell value, or '' for the placeholders Shiprocket uses ("N/A", "-"). */
function srs_clean($v): string
{
    $v = trim((string)$v);
    return in_array(strtoupper($v), ['N/A', 'NA', '-', 'NULL', 'NONE'], true) ? '' : $v;
}

/**
 * Saves what Shiprocket says about one order. Empty values never overwrite what is already saved (a report without the COD columns must not erase them),
 * but the status always follows the latest information. $d: status, pay, awb, delivered_on (Y-m-d), crf, utr, remit_date (Y-m-d), remit_amount, source,
 * status_date (the date of Shiprocket's latest event for the parcel) and shipped_on (the day it was picked up / shipped).
 * status_date only ever moves forward, so a parcel delivered on the 20th keeps saying the 20th however often it is checked later.
 */
function srs_save(PDO $pdo, int $orderId, array $d): void
{
    $clean = [];
    foreach (['status', 'pay', 'awb', 'crf', 'utr', 'source'] as $k) {
        $v = srs_clean($d[$k] ?? '');
        if ($v !== '') {
            $clean[$k] = mb_substr($k === 'pay' ? strtolower($v) : $v, 0, $k === 'utr' ? 80 : 100);
        }
    }
    foreach (['delivered_on', 'remit_date', 'status_date', 'shipped_on'] as $k) {
        $v = srs_clean($d[$k] ?? '');
        if ($v !== '' && strtotime($v)) {
            $clean[$k] = date('Y-m-d', strtotime($v));
        }
    }
    $amt = srs_clean($d['remit_amount'] ?? '');
    if ($amt !== '' && (float)$amt > 0) {
        $clean['remit_amount'] = round((float)str_replace(',', '', $amt), 2);
    }
    if (!$clean) {
        return;
    }
    $st = $pdo->prepare('SELECT status, status_date FROM sr_status WHERE order_id = ?');
    $st->execute([$orderId]);
    $old = $st->fetch();
    if (isset($clean['delivered_on']) && !isset($clean['status_date'])) {
        $clean['status_date'] = $clean['delivered_on'];
    }
    if ($old !== false) {
        // a status that changed while we were watching, with no event date from Shiprocket: it changed today
        if (!isset($clean['status_date']) && isset($clean['status']) && trim((string)$old['status']) !== '' && strcasecmp(trim((string)$old['status']), $clean['status']) !== 0) {
            $clean['status_date'] = date('Y-m-d');
        }
        if (isset($clean['status_date']) && (string)$old['status_date'] !== '' && (string)$old['status_date'] > $clean['status_date']) {
            unset($clean['status_date']); // never move the date backwards
        }
        $set = [];
        foreach (array_keys($clean) as $k) {
            $set[] = "$k = ?";
        }
        $pdo->prepare('UPDATE sr_status SET ' . implode(', ', $set) . ', updated_at = ? WHERE order_id = ?')
            ->execute(array_merge(array_values($clean), [date('Y-m-d H:i:s'), $orderId]));
    } else {
        $clean['order_id'] = $orderId;
        $clean['updated_at'] = date('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO sr_status (' . implode(',', array_keys($clean)) . ') VALUES (' . implode(',', array_fill(0, count($clean), '?')) . ')')->execute(array_values($clean));
    }
}

/**
 * Saves one COD payout line from a Shiprocket report: $ref = Shiprocket Order ID (or the AWB when the report has none).
 * Saving the same line again (the same report, or a newer one) updates it, so nothing is counted twice.
 * $d: utr, crf, remit_date, amount, awb, order_id (panel order or null).
 */
function srr_save(PDO $pdo, string $ref, array $d): void
{
    $ref = mb_substr(trim($ref), 0, 80);
    $utr = mb_substr(srs_clean($d['utr'] ?? ''), 0, 80);
    $amt = (float)str_replace(',', '', srs_clean($d['amount'] ?? ''));
    $rd = srs_clean($d['remit_date'] ?? '');
    $date = $rd !== '' && strtotime($rd) ? date('Y-m-d', strtotime($rd)) : null;
    if ($ref === '' || ($utr === '' && $date === null) || $amt <= 0) {
        return; // not a payout line
    }
    $vals = [$utr, mb_substr(srs_clean($d['crf'] ?? ''), 0, 40), $date, round($amt, 2), mb_substr(srs_clean($d['awb'] ?? ''), 0, 60), isset($d['order_id']) ? (int)$d['order_id'] : null, date('Y-m-d H:i:s')];
    $st = $pdo->prepare('SELECT ref FROM sr_remit WHERE ref = ?');
    $st->execute([$ref]);
    if ($st->fetchColumn() !== false) {
        $pdo->prepare('UPDATE sr_remit SET utr = ?, crf = ?, remit_date = ?, amount = ?, awb = ?, order_id = COALESCE(?, order_id), updated_at = ? WHERE ref = ?')->execute(array_merge($vals, [$ref]));
    } else {
        $pdo->prepare('INSERT INTO sr_remit (utr, crf, remit_date, amount, awb, order_id, updated_at, ref) VALUES (?,?,?,?,?,?,?,?)')->execute(array_merge($vals, [$ref]));
    }
}

/**
 * The small line under the tags of one order: when Shiprocket last said something, and for a parcel on its way how many days it has been on the way.
 * $r needs: status, delivered_at, sr_status, sr_date (last Shiprocket event), sr_shipped (picked up / shipped day). Returns ['text', 'late'] or null.
 */
function order_tag_dates(array $r, ?string $today = null): ?array
{
    $today = $today ?? date('Y-m-d');
    $days = static fn(string $from) => (int)floor((strtotime($today) - strtotime($from)) / 86400);
    $nice = static fn(string $d) => date('d M', strtotime($d));
    $ago = static fn(int $n) => $n <= 0 ? 'today' : ($n === 1 ? 'yesterday' : $n . ' days ago');
    $st = (string)$r['status'];
    $last = trim((string)($r['sr_date'] ?? ''));
    $ship = trim((string)($r['sr_shipped'] ?? ''));
    if ($st === 'delivered') {
        $dd = substr((string)($r['delivered_at'] ?? ''), 0, 10);
        return $dd !== '' && strtotime($dd) ? ['text' => 'Delivered ' . $nice($dd), 'late' => false] : null;
    }
    if (!in_array($st, ['created', 'dispatched'], true)) {
        return null;
    }
    $parts = [];
    $late = false;
    if ($last !== '' && strtotime($last)) {
        $parts[] = 'Last update ' . $nice($last) . ' (' . $ago($days($last)) . ')';
    }
    $srs = strtolower((string)($r['sr_status'] ?? ''));
    $moving = $srs !== '' && strpos($srs, 'new order') === false && strpos($srs, 'cancel') === false;
    if ($moving && $ship !== '' && strtotime($ship)) {
        $n = max(0, $days($ship));
        $parts[] = 'on the way ' . $n . ' day' . ($n === 1 ? '' : 's') . ' (since ' . $nice($ship) . ')';
        $late = $n >= 7;
    }
    return $parts ? ['text' => implode(' · ', $parts), 'late' => $late] : null;
}

/**
 * Setting "delivered = COD already received": the manager says the COD money of every delivered parcel has already reached the QR / account,
 * so delivered COD orders are treated as received (no "COD pending / initiated / remitted" for them). On by default; stored in sr_connect.
 */
function srs_delivered_paid(PDO $pdo): bool
{
    require_once __DIR__ . '/sr_connect_lib.php';
    try {
        sr_conn_ensure($pdo);
        $v = sr_conn_get($pdo, 'cod_delivered_paid');
        $on = $v === null ? true : $v === '1';
    } catch (Throwable $e) {
        $on = false;
    }
    $GLOBALS['srs_delivered_paid'] = $on;
    return $on;
}

/* ---------- the rules as SQL (alias o = orders) ---------- */

function order_tag_exprs(): array
{
    $one = static fn(string $col) => "COALESCE((SELECT s.$col FROM sr_status s WHERE s.order_id = o.id), '')";
    $stat = 'LOWER(' . $one('status') . ')';
    $like = static fn(array $w) => '(' . implode(' OR ', array_map(static fn($x) => "$stat LIKE '%$x%'", $w)) . ')';
    return [
        'stat'    => $stat,
        'utr'     => $one('utr'),
        'crf'     => $one('crf'),
        'bal'     => '(o.total - o.advance_amount - COALESCE((SELECT SUM(c.amount) FROM cod_receipts c WHERE c.order_id = o.id), 0))',
        'cancel'  => $like(['cancel', 'rto']),
        'transit' => $like(['transit', 'out for delivery', 'reached', 'shipped', 'pickup', 'picked', 'manifest', 'hub', 'undelivered']),
        'new'     => $like(['new order']),
    ];
}

/** The WHERE fragment of one tag, or null when the tag is unknown. */
function order_tag_sql(string $tag): ?string
{
    $x = order_tag_exprs();
    $open = "o.status IN ('created','dispatched')";
    $cod = "o.payment_mode = 'cod' AND o.status <> 'refunded'";
    $notCancelled = "NOT {$x['cancel']}";
    $paid = !empty($GLOBALS['srs_delivered_paid']);
    $notDel = $paid ? " AND o.status <> 'delivered'" : '';
    switch ($tag) {
        case 'delivered':     return "o.status = 'delivered'";
        case 'transit':       return "($open AND {$x['transit']} AND $notCancelled)";
        case 'notshipped':    return "($open AND {$x['new']} AND NOT {$x['transit']} AND $notCancelled)";
        case 'cancelled':     return "(o.status = 'refunded' OR ($open AND {$x['cancel']}))";
        case 'nodata':        return "($open AND {$x['stat']} = '')";
        case 'cod_received':  return "($cod AND ({$x['bal']} <= 0.004" . ($paid ? " OR o.status = 'delivered'" : '') . '))';
        case 'cod_remitted':  return "($cod AND {$x['bal']} > 0.004 AND {$x['utr']} <> ''$notDel)";
        case 'cod_initiated': return "($cod AND {$x['bal']} > 0.004 AND {$x['utr']} = '' AND {$x['crf']} <> ''$notDel)";
        case 'cod_pending':   return "($cod AND o.status = 'delivered' AND {$x['bal']} > 0.004 AND {$x['utr']} = '' AND {$x['crf']} = ''$notDel)";
    }
    return null;
}

/** The tag filters, in the order shown: key => label. */
function order_tag_filters(): array
{
    return [
        'delivered' => 'Delivered', 'transit' => 'In transit', 'notshipped' => 'Not shipped yet', 'cancelled' => 'Cancelled / RTO', 'nodata' => 'No Shiprocket info',
        'cod_received' => 'COD received', 'cod_remitted' => 'COD remitted, enter it', 'cod_initiated' => 'COD initiated', 'cod_pending' => 'COD pending',
    ];
}

/** How many orders carry each tag (all orders of the panel), plus the total and how many have Shiprocket info. */
function order_tag_counts(PDO $pdo): array
{
    $cols = ['COUNT(*) AS total', 'COALESCE(SUM(CASE WHEN EXISTS (SELECT 1 FROM sr_status s WHERE s.order_id = o.id) THEN 1 ELSE 0 END), 0) AS with_sr'];
    foreach (array_keys(order_tag_filters()) as $k) {
        $cols[] = 'COALESCE(SUM(CASE WHEN ' . order_tag_sql($k) . ' THEN 1 ELSE 0 END), 0) AS ' . $k;
    }
    return array_map('intval', $pdo->query('SELECT ' . implode(', ', $cols) . ' FROM orders o')->fetch());
}

/* ---------- the same rules for one row, as little chips ---------- */

/**
 * Chips for one orders-list row. $r needs: status, payment_mode, total, advance_amount, cod_received, sr_status, sr_utr, sr_crf.
 * Returns [[label, css class, title], ...].
 */
function order_tag_list(array $r): array
{
    $out = [];
    $st = (string)$r['status'];
    $srs = strtolower(trim((string)($r['sr_status'] ?? '')));
    $has = static fn(array $w) => (bool)array_filter($w, static fn($x) => strpos($srs, $x) !== false);
    $cancel = $has(['cancel', 'rto']);
    $open = in_array($st, ['created', 'dispatched'], true);
    if ($st === 'refunded' || ($open && $cancel)) {
        $out[] = ['Cancelled / RTO', 'tag-bad', $r['sr_status'] ?: 'refunded'];
    } elseif ($st === 'delivered') {
        $out[] = ['Delivered', 'tag-ok', ''];
    } elseif ($open && $has(['transit', 'out for delivery', 'reached', 'shipped', 'pickup', 'picked', 'manifest', 'hub', 'undelivered'])) {
        $out[] = ['In transit', 'tag-warn', (string)$r['sr_status']];
    } elseif ($open && $has(['new order'])) {
        $out[] = ['Not shipped yet', 'tag-info', (string)$r['sr_status']];
    } elseif ($open && $srs === '') {
        $out[] = ['No Shiprocket info', '', 'Upload the Shiprocket report, or save the tracking ID'];
    }
    if ($r['payment_mode'] === 'cod' && $st !== 'refunded') {
        $bal = (float)$r['total'] - (float)$r['advance_amount'] - (float)$r['cod_received'];
        $utr = trim((string)($r['sr_utr'] ?? ''));
        $crf = trim((string)($r['sr_crf'] ?? ''));
        $paidMode = !empty($GLOBALS['srs_delivered_paid']) && $st === 'delivered';
        if ($bal <= 0.004 || $paidMode) {
            $out[] = ['COD received', 'tag-ok', $bal > 0.004 ? 'Delivered: counted as received (setting on the COD Amount page)' : ''];
        } elseif ($utr !== '') {
            $out[] = ['COD remitted: enter it', 'tag-info', 'Shiprocket paid it (UTR ' . $utr . '). It is not entered on this order yet.'];
        } elseif ($crf !== '') {
            $out[] = ['COD initiated', 'tag-warn', 'Shiprocket created the remittance (CRF ' . $crf . '), not paid yet'];
        } elseif ($st === 'delivered') {
            $out[] = ['COD pending', 'tag-warn', 'Delivered, no COD information from Shiprocket yet'];
        }
    }
    return $out;
}
