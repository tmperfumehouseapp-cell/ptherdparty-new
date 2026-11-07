<?php
// Commission numbers for the Commission page: per day and salesperson (the existing table), and per phone number.
//
// The rate (8% / 10%) depends on how many orders a salesperson made that DAY, so the day's order count always uses ALL
// of that salesperson's orders of the day. Filtering by phone only decides which orders are shown and added up.
// Per order:  eligible = profit after discount x share of refunds kept x share of the money received (COD arrives later)
//             commission = eligible x the day's rate, but only when the order earns (20-minute rule + WhatsApp, see order_earns()).

/**
 * $rows: orders with id, order_date, salesperson_id, sales_name, number_id, num_label, num_phone, payment_mode, total,
 *        advance_amount, profit, refunded, cod_received, commission_status, wa_enforced, wa_status.
 * $numFilter: 0 = all phones, -1 = orders with no phone number, otherwise a number id.
 */
function commission_build(array $rows, int $groups, int $numFilter = 0): array
{
    $dayCount = [];
    foreach ($rows as $o) {
        $k = $o['order_date'] . '|' . $o['salesperson_id'];
        $dayCount[$k] = ($dayCount[$k] ?? 0) + 1;
    }
    $days = [];
    $phones = [];
    foreach ($rows as $o) {
        $nid = $o['number_id'] === null ? 0 : (int)$o['number_id'];
        if ($numFilter === -1 && $nid !== 0) {
            continue;
        }
        if ($numFilter > 0 && $nid !== $numFilter) {
            continue;
        }
        $k = $o['order_date'] . '|' . $o['salesperson_id'];
        $rate = commission_rate($dayCount[$k], $groups);
        $total = (float)$o['total'];
        $profit = order_earns($o) ? max((float)$o['profit'], 0) : 0.0; // late / unconfirmed orders earn nothing but still count as orders
        $keep = $total > 0 ? max(0, 1 - (float)$o['refunded'] / $total) : 0;
        $paid = 1.0;
        if ($o['payment_mode'] === 'cod') {
            $paid = $total > 0 ? min(1, ((float)$o['advance_amount'] + (float)$o['cod_received']) / $total) : 0;
        }
        $kept = $profit * $keep;
        $eligible = $kept * $paid;
        $pending = $kept * (1 - $paid);

        if (!isset($days[$k])) {
            $days[$k] = ['date' => $o['order_date'], 'sales' => $o['sales_name'], 'count' => $dayCount[$k], 'shown' => 0, 'profit' => 0.0, 'rate' => $rate, 'earn' => 0.0, 'later' => 0.0];
        }
        $days[$k]['shown']++;
        $days[$k]['profit'] += $kept;
        $days[$k]['earn'] += $eligible * $rate;
        $days[$k]['later'] += $pending * $rate;

        if (!isset($phones[$nid])) {
            $phones[$nid] = ['id' => $nid, 'label' => $nid === 0 ? 'No phone number' : (string)$o['num_label'], 'phone' => $nid === 0 ? '' : (string)($o['num_phone'] ?? ''),
                             'orders' => 0, 'profit' => 0.0, 'earn' => 0.0, 'later' => 0.0, 'people' => []];
        }
        $phones[$nid]['orders']++;
        $phones[$nid]['profit'] += $kept;
        $phones[$nid]['earn'] += $eligible * $rate;
        $phones[$nid]['later'] += $pending * $rate;
        $who = (string)$o['sales_name'];
        $phones[$nid]['people'][$who] = ($phones[$nid]['people'][$who] ?? 0) + 1;
    }
    $totEarn = 0.0;
    $totPending = 0.0;
    $totOrders = 0;
    foreach ($days as $d) {
        $totEarn += $d['earn'];
        $totPending += $d['later'];
        $totOrders += $d['shown'];
    }
    uasort($phones, static fn($a, $b) => [$b['earn'], $b['orders']] <=> [$a['earn'], $a['orders']]);
    return ['days' => $days, 'phones' => array_values($phones), 'totEarn' => $totEarn, 'totPending' => $totPending, 'totOrders' => $totOrders];
}
