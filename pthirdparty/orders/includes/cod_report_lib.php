<?php
// Reading Shiprocket's COD report for "Upload COD from Excel": the COD remittance report (CRF ID, AWB, Delivered Date, Order Id,
// Order Value, Remittance Date, UTR ...) or the big all-orders report (Remitted Amount, COD Remittance Date, UTR No ...),
// exactly as downloaded, no editing.
//
// IMPORTANT: the money collected is "Order Value" (remittance report) / "Remitted Amount" (all-orders report).
// The column called "COD Payble Amount" in the all-orders report is the COD CHARGE (33.63, 246.40 ...), NOT the money: never used as the amount.

require_once __DIR__ . '/delivered_lib.php';

/**
 * Finds the columns by exact header names. Returns
 * ['start', 'order' => [cols], 'track' => [cols], 'amount', 'date', 'delivered', 'crf', 'utr', 'status', 'names', 'header'].
 */
function cod_detect_columns(array $rows): array
{
    $norm = static function ($h): string {
        $h = strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string)$h)));
        return trim(preg_replace('/[\s_]+/', ' ', $h));
    };
    $byName = [];
    $order = $track = [];
    foreach (array_map($norm, $rows[0] ?? []) as $i => $h) {
        if ($h === '') {
            continue;
        }
        $byName[$h] = $byName[$h] ?? $i;
        if (preg_match('/^(awb( code| no\.?| number)?|tracking( id| no\.?| number| code)?|waybill( no\.?| number)?|way bill( no\.?)?|lr no\.?)$/', $h)) {
            $track[] = $i;
        } elseif (preg_match('/^((shiprocket|channel|sr) )?order( id| no\.?| number)?$|^(reference|ref) (no\.?|number|id)$/', $h)) {
            $order[] = $i;
        }
    }
    $pick = static function (array $names) use ($byName): ?int {
        foreach ($names as $n) { // the first name in the list that the file has (the list is in order of preference)
            if (isset($byName[$n])) {
                return $byName[$n];
            }
        }
        return null;
    };
    $amount = $pick(['remitted amount', 'remitted amt', 'amount remitted', 'order value', 'cod amount', 'amount received', 'amount collected', 'collected amount', 'amount']);
    $date = $pick(['remittance date', 'cod remittance date', 'remitted date', 'remitted on', 'received date', 'date received', 'payment date', 'date']);
    $delivered = $pick(['delivered date', 'order delivered date', 'delivered on']);
    $crf = $pick(['crf id', 'crf', 'crf no', 'crf no.']);
    $utr = $pick(['utr', 'utr no', 'utr no.', 'utr number']);
    $status = $pick(['status', 'order status', 'shipment status']);
    $name = $pick(['customer name', 'buyer name', 'name']);
    $created = $pick(['channel created at', 'order date', 'created at', 'order created date', 'shiprocket created at']);

    if (!$order && !$track) { // no header row: A order number or tracking ID, B amount, C date (the old layout)
        return ['start' => preg_match('/\d/', (string)($rows[0][1] ?? '')) ? 0 : 1, 'header' => false, 'order' => [0], 'track' => [],
                'amount' => 1, 'date' => 2, 'delivered' => null, 'crf' => null, 'utr' => null, 'status' => null, 'name' => null, 'created' => null,
                'names' => 'no header row, so: column A = order number or tracking ID, column B = amount, column C = date'];
    }
    $label = static function (array $cols) use ($rows): string {
        return implode(', ', array_map(static fn($c) => 'column ' . dl_col_letter($c) . ' "' . trim(str_replace("\xEF\xBB\xBF", '', (string)($rows[0][$c] ?? ''))) . '"', $cols)) ?: 'none';
    };
    $one = static fn(?int $c) => $c === null ? 'none' : $label([$c]);
    return [
        'start' => 1, 'header' => true, 'order' => $order, 'track' => $track,
        'amount' => $amount, 'date' => $date, 'delivered' => $delivered, 'crf' => $crf, 'utr' => $utr, 'status' => $status, 'name' => $name, 'created' => $created,
        'names' => 'Order ID: ' . $label($order) . ' | AWB: ' . $label($track) . ' | COD amount: ' . $one($amount)
                 . ' | Remittance date: ' . $one($date) . ' | CRF ID: ' . $one($crf) . ' | UTR: ' . $one($utr)
                 . ' | Delivered date: ' . $one($delivered) . ($status !== null ? ' | Status: ' . $one($status) : ''),
    ];
}

/** Groups the row results by remittance (CRF ID, else UTR) for the summary table. */
function cod_group_results(array $results): array
{
    $g = [];
    foreach ($results as $r) {
        $key = $r['crf'] !== '' ? $r['crf'] : ($r['utr'] !== '' ? $r['utr'] : '-');
        if (!isset($g[$key])) {
            $g[$key] = ['crf' => $r['crf'], 'utr' => $r['utr'], 'date' => $r['date'], 'rows' => 0, 'saved' => 0, 'already' => 0, 'skipped' => 0, 'in_file' => 0.0, 'saved_amt' => 0.0];
        }
        $g[$key]['rows']++;
        $g[$key]['in_file'] += (float)$r['amount'];
        if ($r['ok']) {
            $g[$key]['saved']++;
            $g[$key]['saved_amt'] += (float)$r['amount'];
        } elseif (($r['kind'] ?? '') === 'already') {
            $g[$key]['already']++;
        } else {
            $g[$key]['skipped']++;
        }
    }
    krsort($g);
    return $g;
}

/**
 * Saves the COD money of a Shiprocket COD report (rows from read_sheet_rows) as COD receipts into account $accId.
 * Returns ['colInfo', 'results' (one entry per row), 'groups' (per CRF / UTR), 'ok', 'sum', 'notRemitted', 'delivered'].
 * Needs add_cod_receipt / mark_delivered_on / sheet_date / orders_has_sr_id (ops_lib, orders_lib).
 */
function cod_process_rows(PDO $pdo, array $user, int $accId, array $rows): array
{
    require_once __DIR__ . '/order_tags_lib.php';
    srs_ensure($pdo); // (outside any transaction)
    $cols = cod_detect_columns($rows);
    $colInfo = $cols['names'];
    $hasSr = orders_has_sr_id($pdo);
    $cell = static fn(array $r, ?int $c): string => $c === null ? '' : trim((string)($r[$c] ?? ''));
    $results = [];
    $okCount = 0;
    $okSum = 0.0;
    $notRemitted = 0;
    $delivCount = 0;
    $beforePanel = 0;
    $used = [];
    $firstOrder = (string)$pdo->query('SELECT MIN(order_date) FROM orders')->fetchColumn(); // parcels delivered before this cannot be panel orders
    for ($i = $cols['start']; $i < count($rows); $i++) {
        $r = $rows[$i];
        $cands = [];
        foreach (array_merge($cols['order'], $cols['track']) as $c) { // the Shiprocket Order ID is the main key; the AWB is the second
            $cands[] = (string)($r[$c] ?? '');
        }
        $srid = '';
        foreach ($cols['order'] as $c) {
            if (dl_norm((string)($r[$c] ?? '')) !== '') {
                $srid = dl_norm((string)$r[$c]);
                break;
            }
        }
        $awb = '';
        foreach ($cols['track'] as $c) {
            if (dl_norm((string)($r[$c] ?? '')) !== '') {
                $awb = dl_norm((string)$r[$c]);
                break;
            }
        }
        $ref = $srid !== '' ? $srid : $awb;
        $amt = $cols['amount'] !== null ? num($cell($r, $cols['amount'])) : 0.0;
        if ($ref === '' && $amt == 0) {
            continue;
        }
        $remitText = $cell($r, $cols['date']);
        if ($amt <= 0 && $remitText === '' && $cols['header']) { // the all-orders report: parcels with no COD money remitted yet
            $notRemitted++;
            continue;
        }
        $date = sheet_date($remitText) ?? biz_today();
        $line = ['row' => $i + 1, 'ref' => $ref, 'srid' => $srid, 'awb' => $awb, 'amount' => $amt, 'date' => $date, 'delivered' => sheet_date($cell($r, $cols['delivered'])),
                 'crf' => dl_norm($cell($r, $cols['crf'])), 'utr' => $cell($r, $cols['utr']), 'ok' => false, 'msg' => '', 'kind' => '',
                 'name' => $cell($r, $cols['name']), 'created' => $cols['created'] !== null ? sheet_date($cell($r, $cols['created'])) : null, 'order_id' => null, 'suggest' => []];

        $found = $ref !== '' ? dl_find_order($pdo, $cands, $hasSr, !$cols['header']) : null;
        if (!$found) {
            $deliveredOn = sheet_date($cell($r, $cols['delivered'])) ?? $date;
            if ($firstOrder !== '' && $deliveredOn < $firstOrder) { // from before the panel existed: not a failure, just not a panel order
                $beforePanel++;
                continue;
            }
            $line['kind'] = 'unmatched';
            $line['msg'] = 'No order with this AWB, Shiprocket order ID or order number.';
        } else {
            [$o, $by] = $found;
            $orderId = (int)$o['id'];
            $used[$orderId] = true;
            $line['order_id'] = $orderId;
            // Shiprocket has remitted this parcel's COD: remember the CRF / UTR / date so the Orders page can tag it
            srs_save($pdo, $orderId, ['status' => $cell($r, $cols['status']), 'awb' => $awb, 'delivered_on' => $cell($r, $cols['delivered']), 'crf' => $line['crf'], 'utr' => $line['utr'],
                'remit_date' => $remitText, 'remit_amount' => $amt, 'pay' => 'cod', 'source' => 'cod report']);
            try {
                add_cod_receipt($pdo, $user, $orderId, $amt, $accId, $date, 'excel');
                $line['ok'] = true;
                $line['kind'] = 'ok';
                $line['msg'] = 'Saved for order ' . order_no($orderId) . ' (matched by ' . $by . ')';
                $okCount++;
                $okSum += $amt;
                // COD money only arrives after delivery: a parcel still "created / dispatched" is marked delivered
                if (in_array($o['status'], ['created', 'dispatched'], true)) {
                    $dd = sheet_date($cell($r, $cols['delivered'])) ?? $date;
                    $trk = '';
                    foreach ($cols['track'] as $c) {
                        if (dl_norm((string)($r[$c] ?? '')) !== '') {
                            $trk = dl_norm((string)$r[$c]);
                            break;
                        }
                    }
                    if (mark_delivered_on($pdo, $orderId, $dd, $trk !== '' ? $trk : null)) {
                        $line['msg'] .= ' · marked delivered';
                        $delivCount++;
                    }
                }
            } catch (RuntimeException $ex) {
                $line['msg'] = $ex->getMessage();
                $k = cod_reason_key($line['msg']);
                $line['kind'] = $k === 'received' ? 'already' : $k; // the money of this parcel was saved before: that is fine, not a problem
            }
        }
        $results[] = $line;
    }
    $groups = cod_group_results($results);
    if (array_filter($results, static fn($r) => $r['kind'] === 'unmatched')) { // "maybe this order" for every unmatched row
        $pool = dl_order_pool($pdo, $used);
        foreach ($results as &$l) {
            if ($l['kind'] === 'unmatched') {
                $l['suggest'] = dl_suggest_orders($l, $pool, true);
            }
        }
        unset($l);
    }
    return ['colInfo' => $colInfo, 'results' => $results, 'groups' => $groups, 'ok' => $okCount, 'sum' => $okSum, 'notRemitted' => $notRemitted, 'delivered' => $delivCount, 'beforePanel' => $beforePanel, 'firstOrder' => $firstOrder];
}

/** Counts the skipped rows by reason, with a few examples of each, so the page can say what to fix. */
function cod_reason_summary(array $results): array
{
    $cat = [
        'not_found' => ['No order in the panel has this AWB or Shiprocket Order ID', 'Save the Shiprocket Order ID (or the full tracking ID) on those orders, then upload again.'],
        'prepaid'   => ['The order is prepaid in the panel', 'A prepaid order has no COD money to receive.'],
        'received'  => ['The COD money of the order was already saved', 'Nothing to do: it is not counted twice.'],
        'pending'   => ['The amount in the report is more than the COD still pending in the panel', 'Compare the order total and advance in the panel with the report.'],
        'refunded'  => ['The order is refunded', ''],
        'other'     => ['Other', ''],
    ];
    $out = [];
    foreach ($results as $r) {
        if ($r['ok']) {
            continue;
        }
        $m = (string)$r['msg'];
        $k = cod_reason_key($m);
        if (!isset($out[$k])) {
            $out[$k] = ['label' => $cat[$k][0], 'hint' => $cat[$k][1], 'n' => 0, 'examples' => []];
        }
        $out[$k]['n']++;
        if (count($out[$k]['examples']) < 3) {
            $out[$k]['examples'][] = ['row' => $r['row'], 'ref' => $r['ref'], 'amount' => $r['amount'], 'msg' => $m];
        }
    }
    uasort($out, static fn($a, $b) => $b['n'] <=> $a['n']);
    return $out;
}

/** Which kind of skip a message from the COD save is. */
function cod_reason_key(string $m): string
{
    return preg_match('/^No order/', $m) ? 'not_found'
        : (strpos($m, 'is prepaid') !== false ? 'prepaid'
        : (strpos($m, 'fully received') !== false || strpos($m, 'already recorded') !== false ? 'received'
        : (strpos($m, 'pending, you entered') !== false ? 'pending'
        : (strpos($m, 'is refunded') !== false ? 'refunded' : 'other'))));
}

/**
 * One-click fix of an unmatched COD row: writes the report's Shiprocket Order ID on the chosen order, saves the COD money of the row
 * into account $accId, and marks the parcel delivered. Returns a sentence.
 */
function cod_link_order(PDO $pdo, array $user, int $orderId, string $srid, string $awb, float $amount, string $remitDate, ?string $deliveredOn, int $accId): string
{
    return in_tx($pdo, function () use ($pdo, $user, $orderId, $srid, $awb, $amount, $remitDate, $deliveredOn, $accId) {
        $msg = dl_link_order($pdo, $orderId, $srid, $awb, $deliveredOn ?? $remitDate);
        add_cod_receipt($pdo, $user, $orderId, $amount, $accId, $remitDate, 'excel');
        return rtrim($msg, '.') . '; COD ' . inr($amount) . ' saved.';
    });
}
