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
                source VARCHAR(20) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
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
 * but the status always follows the latest information. $d: status, pay, awb, delivered_on (Y-m-d), crf, utr, remit_date (Y-m-d), remit_amount, source.
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
    foreach (['delivered_on', 'remit_date'] as $k) {
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
    $st = $pdo->prepare('SELECT order_id FROM sr_status WHERE order_id = ?');
    $st->execute([$orderId]);
    if ($st->fetchColumn() !== false) {
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
    switch ($tag) {
        case 'delivered':     return "o.status = 'delivered'";
        case 'transit':       return "($open AND {$x['transit']} AND $notCancelled)";
        case 'notshipped':    return "($open AND {$x['new']} AND NOT {$x['transit']} AND $notCancelled)";
        case 'cancelled':     return "(o.status = 'refunded' OR ($open AND {$x['cancel']}))";
        case 'nodata':        return "($open AND {$x['stat']} = '')";
        case 'cod_received':  return "($cod AND {$x['bal']} <= 0.004)";
        case 'cod_remitted':  return "($cod AND {$x['bal']} > 0.004 AND {$x['utr']} <> '')";
        case 'cod_initiated': return "($cod AND {$x['bal']} > 0.004 AND {$x['utr']} = '' AND {$x['crf']} <> '')";
        case 'cod_pending':   return "($cod AND o.status = 'delivered' AND {$x['bal']} > 0.004 AND {$x['utr']} = '' AND {$x['crf']} = '')";
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
        if ($bal <= 0.004) {
            $out[] = ['COD received', 'tag-ok', ''];
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
