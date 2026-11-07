<?php
// Overall Report: numbers for the business dashboard (manager only).
//
// READ ONLY. Nothing in this file writes to the database. It re-uses the definitions the rest of the panel already uses,
// so a figure here matches the same figure on Home, Orders, Buying, Dealers and Commission:
//   sales            = orders.total of the period (by order_date), refunded orders left out
//   estimated profit = orders.profit (profit after discount, the moment the order was booked)
//   realized profit  = estimated profit x share of the money actually received x share not refunded   (same rule as Commission)
//   cash received    = real money that came into your accounts (account_ledger 'prepaid' + 'cod' rows) minus refunds paid out
//   buying           = approved dealer bills (party_ledger 'bill'), by bill date           (same as Orders > Buying)
//   dealer owed      = live dealer ledger balance (bills - payments - customer money on the dealer QR)  (same as Home)
//   COD pending      = COD orders: total - advance - COD received, minus lump remittances                (same as Home)

/** Runs a query block; a missing optional table/column must never take the whole dashboard down. */
function rep_try(callable $fn, $default)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        error_log('overall_report: ' . $e->getMessage());
        return $default;
    }
}

/** Filter keys => label. */
function rep_filters(): array
{
    return ['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'month' => 'This month', 'last_month' => 'Last month', 'custom' => 'Custom'];
}

/**
 * Works out the period asked for and the period to compare it with.
 * Returns ['key','from','to','label','prevFrom','prevTo','prevLabel','days'].
 * "This month" is compared with the SAME NUMBER OF DAYS of last month, so a half-finished month is not unfairly compared
 * with a whole one. The full previous months are always visible in the month-by-month table.
 */
function rep_period(string $today, string $key, string $from, string $to): array
{
    $d = static fn(string $base, string $mod): string => date('Y-m-d', strtotime($base . ' ' . $mod));
    if (!array_key_exists($key, rep_filters())) {
        $key = 'month';
    }
    switch ($key) {
        case 'today':
            $f = $t = $today;
            break;
        case 'yesterday':
            $f = $t = $d($today, '-1 day');
            break;
        case 'week':
            $f = $d($today, (int)date('N', strtotime($today)) === 1 ? '+0 day' : 'last monday');
            $t = $today;
            break;
        case 'last_month':
            $f = date('Y-m-01', strtotime(date('Y-m-01', strtotime($today)) . ' -1 month'));
            $t = date('Y-m-t', strtotime($f));
            break;
        case 'custom':
            $f = valid_date($from) ?? date('Y-m-01', strtotime($today));
            $t = valid_date($to) ?? $today;
            if ($f > $t) {
                [$f, $t] = [$t, $f];
            }
            break;
        default: // month
            $f = date('Y-m-01', strtotime($today));
            $t = $today;
    }
    $days = (int)round((strtotime($t) - strtotime($f)) / 86400) + 1;

    if ($key === 'month' || $key === 'last_month') {
        $pf = date('Y-m-01', strtotime($f . ' -1 month'));
        $pt = $d($pf, '+' . ($days - 1) . ' day');
        $pmEnd = date('Y-m-t', strtotime($pf));
        if ($pt > $pmEnd) {
            $pt = $pmEnd; // previous month is shorter
        }
        $plabel = $key === 'month' ? 'same days of ' . date('M Y', strtotime($pf)) : date('M Y', strtotime($pf));
    } elseif ($key === 'week') {
        // same weekdays of last week (Mon–today against last Mon–same weekday)
        $pf = $d($f, '-7 day');
        $pt = $d($t, '-7 day');
        $plabel = 'the same days last week';
    } else {
        $pt = $d($f, '-1 day');
        $pf = $d($pt, '-' . ($days - 1) . ' day');
        $plabel = $key === 'today' ? 'yesterday' : ($key === 'yesterday' ? 'the day before' : 'the previous ' . $days . ' days');
    }
    $label = $f === $t ? date('j M Y', strtotime($f)) : date('j M', strtotime($f)) . ' – ' . date('j M Y', strtotime($t));
    return ['key' => $key, 'from' => $f, 'to' => $t, 'label' => $label, 'prevFrom' => $pf, 'prevTo' => $pt, 'prevLabel' => $plabel, 'days' => $days];
}

/** Share of a COD/prepaid order's price that has actually been paid (0..1). */
const REP_PAID_SQL = "CASE WHEN o.payment_mode = 'cod'
                           THEN (CASE WHEN o.total > 0 THEN (CASE WHEN (o.advance_amount + COALESCE(cr.recv, 0)) >= o.total THEN 1.0 ELSE (o.advance_amount + COALESCE(cr.recv, 0)) / o.total END) ELSE 0 END)
                           ELSE 1.0 END";

/**
 * Sales-side numbers for one period (by order date, refunded orders left out).
 * All money columns are summed once, here, so every card on the page reads the same value.
 */
function rep_sales(PDO $pdo, string $from, string $to): array
{
    $paid = REP_PAID_SQL;
    $st = $pdo->prepare(
        "SELECT COUNT(*) AS orders,
                COUNT(DISTINCT NULLIF(o.customer_phone, '')) AS customers,
                COALESCE(SUM(o.total), 0) AS sales,
                COALESCE(SUM(o.profit), 0) AS est_profit,
                COALESCE(SUM(o.base_total), 0) AS goods_cost,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'prepaid' THEN 1 ELSE 0 END), 0) AS prepaid_orders,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'prepaid' THEN o.total ELSE 0 END), 0) AS prepaid_sales,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'cod' THEN 1 ELSE 0 END), 0) AS cod_orders,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'cod' THEN o.total ELSE 0 END), 0) AS cod_sales,
                COALESCE(SUM(o.profit * (CASE WHEN o.total > 0 THEN (CASE WHEN COALESCE(rf.ref, 0) >= o.total THEN 0 ELSE 1 - COALESCE(rf.ref, 0) / o.total END) ELSE 0 END) * ($paid)), 0) AS real_profit,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'cod' THEN
                        (CASE WHEN o.total - o.advance_amount - COALESCE(cr.recv, 0) > 0 THEN o.total - o.advance_amount - COALESCE(cr.recv, 0) ELSE 0 END)
                      ELSE 0 END), 0) AS cod_due,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'cod' THEN COALESCE(cr.recv, 0) ELSE 0 END), 0) AS cod_recv_orders
         FROM orders o
         LEFT JOIN (SELECT order_id, SUM(amount) AS recv FROM cod_receipts GROUP BY order_id) cr ON cr.order_id = o.id
         LEFT JOIN (SELECT order_id, SUM(amount) AS ref FROM refunds GROUP BY order_id) rf ON rf.order_id = o.id
         WHERE o.status <> 'refunded' AND o.order_date BETWEEN ? AND ?"
    );
    $st->execute([$from, $to]);
    $r = $st->fetch();
    $out = [];
    foreach ($r as $k => $v) {
        $out[$k] = in_array($k, ['orders', 'customers', 'prepaid_orders', 'cod_orders'], true) ? (int)$v : round((float)$v, 2);
    }
    return $out;
}

/** Same as rep_sales, but when the refunds table is missing it falls back to a version without refunds. */
function rep_sales_safe(PDO $pdo, string $from, string $to): array
{
    static $memo = []; // one query per period, however many cards use it
    $k = $from . '|' . $to;
    if (isset($memo[$k])) {
        return $memo[$k];
    }
    try {
        return $memo[$k] = rep_sales($pdo, $from, $to);
    } catch (Throwable $e) {
        error_log('overall_report (sales, retrying without refunds): ' . $e->getMessage());
        return $memo[$k] = rep_sales_norefund($pdo, $from, $to);
    }
}

function rep_sales_norefund(PDO $pdo, string $from, string $to): array
{
    $paid = REP_PAID_SQL;
    $st = $pdo->prepare(
        "SELECT COUNT(*) AS orders, COUNT(DISTINCT NULLIF(o.customer_phone, '')) AS customers,
                COALESCE(SUM(o.total), 0) AS sales, COALESCE(SUM(o.profit), 0) AS est_profit, COALESCE(SUM(o.base_total), 0) AS goods_cost,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'prepaid' THEN 1 ELSE 0 END), 0) AS prepaid_orders,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'prepaid' THEN o.total ELSE 0 END), 0) AS prepaid_sales,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'cod' THEN 1 ELSE 0 END), 0) AS cod_orders,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'cod' THEN o.total ELSE 0 END), 0) AS cod_sales,
                COALESCE(SUM(o.profit * ($paid)), 0) AS real_profit,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'cod' THEN (CASE WHEN o.total - o.advance_amount - COALESCE(cr.recv, 0) > 0 THEN o.total - o.advance_amount - COALESCE(cr.recv, 0) ELSE 0 END) ELSE 0 END), 0) AS cod_due,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'cod' THEN COALESCE(cr.recv, 0) ELSE 0 END), 0) AS cod_recv_orders
         FROM orders o
         LEFT JOIN (SELECT order_id, SUM(amount) AS recv FROM cod_receipts GROUP BY order_id) cr ON cr.order_id = o.id
         WHERE o.status <> 'refunded' AND o.order_date BETWEEN ? AND ?"
    );
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetch() as $k => $v) {
        $out[$k] = in_array($k, ['orders', 'customers', 'prepaid_orders', 'cod_orders'], true) ? (int)$v : round((float)$v, 2);
    }
    return $out;
}

/** Real money movement in a period, by the date the money moved (not by order date). */
function rep_cash(PDO $pdo, string $from, string $to): array
{
    static $memo = [];
    $mk = $from . '|' . $to;
    if (isset($memo[$mk])) {
        return $memo[$mk];
    }
    $out = ['prepaid_in' => 0.0, 'cod_in' => 0.0, 'refund_out' => 0.0, 'dealer_paid' => 0.0, 'bought' => 0.0, 'bills' => 0];
    $st = $pdo->prepare(
        "SELECT entry_type, direction, COALESCE(SUM(amount), 0) AS amt FROM account_ledger
         WHERE entry_date BETWEEN ? AND ? AND entry_type IN ('prepaid', 'cod', 'refund') GROUP BY entry_type, direction"
    );
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $r) {
        $a = (float)$r['amt'];
        if ($r['entry_type'] === 'prepaid' && $r['direction'] === 'in') {
            $out['prepaid_in'] += $a;
        } elseif ($r['entry_type'] === 'prepaid') {
            $out['prepaid_in'] -= $a;
        } elseif ($r['entry_type'] === 'cod' && $r['direction'] === 'in') {
            $out['cod_in'] += $a;
        } elseif ($r['entry_type'] === 'cod') {
            $out['cod_in'] -= $a;
        } elseif ($r['entry_type'] === 'refund' && $r['direction'] === 'out') {
            $out['refund_out'] += $a;
        } elseif ($r['entry_type'] === 'refund') {
            $out['refund_out'] -= $a;
        }
    }
    $st = $pdo->prepare(
        "SELECT entry_type, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amt FROM party_ledger
         WHERE entry_date BETWEEN ? AND ? AND entry_type IN ('bill', 'payment') GROUP BY entry_type"
    );
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $r) {
        if ($r['entry_type'] === 'bill') {
            $out['bought'] = (float)$r['amt'];
            $out['bills'] = (int)$r['n'];
        } else {
            $out['dealer_paid'] = (float)$r['amt'];
        }
    }
    $out['cash_in'] = round($out['prepaid_in'] + $out['cod_in'], 2);
    $out['net_cash'] = round($out['cash_in'] - $out['refund_out'], 2);
    foreach (['prepaid_in', 'cod_in', 'refund_out', 'dealer_paid', 'bought'] as $k) {
        $out[$k] = round($out[$k], 2);
    }
    return $memo[$mk] = $out;
}

/**
 * Per dealer: bought in the period (approved bills), paid in the period, and what is owed right now (all time).
 * Dealers with no activity and a zero balance are left out. Sorted by buying, biggest first.
 */
function rep_dealers(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare(
        "SELECT p.id, p.name, p.is_active,
                COALESCE(SUM(CASE WHEN l.entry_type = 'bill' AND l.entry_date BETWEEN ? AND ? THEN l.amount END), 0) AS buy,
                COALESCE(SUM(CASE WHEN l.entry_type = 'bill' AND l.entry_date BETWEEN ? AND ? THEN 1 END), 0) AS bills,
                COALESCE(SUM(CASE WHEN l.entry_type = 'payment' AND l.entry_date BETWEEN ? AND ? THEN l.amount END), 0) AS paid,
                COALESCE(SUM(CASE WHEN l.entry_type IN ('payment', 'collected') THEN -l.amount ELSE l.amount END), 0) AS balance
         FROM parties p LEFT JOIN party_ledger l ON l.party_id = p.id
         GROUP BY p.id, p.name, p.is_active"
    );
    $st->execute([$from, $to, $from, $to, $from, $to]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'is_active' => (int)$r['is_active'], 'buy' => round((float)$r['buy'], 2),
              'bills' => (int)$r['bills'], 'paid' => round((float)$r['paid'], 2), 'balance' => round((float)$r['balance'], 2)];
        if ($r['buy'] == 0.0 && $r['paid'] == 0.0 && abs($r['balance']) < 0.005) {
            continue;
        }
        $rows[] = $r;
    }
    usort($rows, static fn($a, $b) => [$b['buy'], $b['balance']] <=> [$a['buy'], $a['balance']]);
    return $rows;
}

/** Live snapshot (not tied to the date filter): COD still to come in, how old it is, dealer dues, money in accounts. */
function rep_snapshot(PDO $pdo, string $today): array
{
    $out = ['cod_pending' => 0.0, 'cod_orders' => 0, 'aging' => [], 'owed' => 0.0, 'owed_gross' => 0.0, 'advance' => 0.0, 'accounts' => 0.0];

    // COD still to come: per order, exactly as Home counts it
    $st = $pdo->query(
        "SELECT o.order_date, (o.total - o.advance_amount - COALESCE(cr.recv, 0)) AS due
         FROM orders o LEFT JOIN (SELECT order_id, SUM(amount) AS recv FROM cod_receipts GROUP BY order_id) cr ON cr.order_id = o.id
         WHERE o.payment_mode = 'cod' AND o.status <> 'refunded'"
    );
    $buckets = ['0-7 days' => 0.0, '8-15 days' => 0.0, '16-30 days' => 0.0, 'Over 30 days' => 0.0];
    $gross = 0.0;
    foreach ($st->fetchAll() as $r) {
        $due = (float)$r['due'];
        $gross += $due;
        if ($due <= 0.004) {
            continue;
        }
        $out['cod_orders']++;
        $age = (int)floor((strtotime($today) - strtotime((string)$r['order_date'])) / 86400);
        $k = $age <= 7 ? '0-7 days' : ($age <= 15 ? '8-15 days' : ($age <= 30 ? '16-30 days' : 'Over 30 days'));
        $buckets[$k] += $due;
    }
    // lump COD remittances (not tied to one order) reduce what is still to come, same as Home
    $lump = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM account_ledger WHERE entry_type = 'cod' AND order_id IS NULL AND direction = 'in'")->fetchColumn();
    $pending = max(0.0, array_sum($buckets) - $lump);
    // the lump is not tied to an age, so it is taken off the oldest money first
    $left = min($lump, array_sum($buckets));
    foreach (array_reverse(array_keys($buckets)) as $k) {
        $take = min($left, $buckets[$k]);
        $buckets[$k] -= $take;
        $left -= $take;
    }
    $out['cod_pending'] = round($pending, 2);
    $out['aging'] = array_map(static fn($v) => round(max(0.0, $v), 2), $buckets);

    foreach ($pdo->query("SELECT SUM(CASE WHEN entry_type IN ('payment','collected') THEN -amount ELSE amount END) AS b FROM party_ledger GROUP BY party_id")->fetchAll() as $r) {
        $b = (float)$r['b'];
        if ($b > 0.004) {
            $out['owed_gross'] += $b;
        } elseif ($b < -0.004) {
            $out['advance'] += -$b;
        }
    }
    $out['owed'] = round($out['owed_gross'] - $out['advance'], 2); // same number as "You owe dealers" on Home
    $out['owed_gross'] = round($out['owed_gross'], 2);
    $out['advance'] = round($out['advance'], 2);

    $out['accounts'] = round((float)$pdo->query(
        "SELECT COALESCE(SUM(a.opening_balance),0) + COALESCE((SELECT SUM(CASE WHEN l.direction='in' THEN l.amount ELSE -l.amount END) FROM account_ledger l JOIN accounts x ON x.id = l.account_id WHERE x.party_id IS NULL),0)
         FROM accounts a WHERE a.party_id IS NULL"
    )->fetchColumn(), 2);
    return $out;
}

/** Daily series for the chart: [date => [sales, profit, orders]] for every day of the range (zero days included). */
function rep_daily(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare(
        "SELECT order_date AS d, COUNT(*) AS n, COALESCE(SUM(total), 0) AS s, COALESCE(SUM(profit), 0) AS p
         FROM orders WHERE status <> 'refunded' AND order_date BETWEEN ? AND ? GROUP BY order_date"
    );
    $st->execute([$from, $to]);
    $got = [];
    foreach ($st->fetchAll() as $r) {
        $got[$r['d']] = ['sales' => (float)$r['s'], 'profit' => (float)$r['p'], 'orders' => (int)$r['n']];
    }
    $out = [];
    for ($t = strtotime($from), $end = strtotime($to); $t <= $end; $t = strtotime('+1 day', $t)) {
        $d = date('Y-m-d', $t);
        $out[$d] = $got[$d] ?? ['sales' => 0.0, 'profit' => 0.0, 'orders' => 0];
    }
    return $out;
}

/** Last N whole calendar months ending with the month of $today: sales, profit, margin, AOV, orders, buying. Oldest first. */
function rep_months(PDO $pdo, string $today, int $n = 6): array
{
    $out = [];
    $firstMonth = date('Y-m-01', strtotime(date('Y-m-01', strtotime($today)) . ' -' . ($n - 1) . ' month'));
    for ($i = 0; $i < $n; $i++) {
        $f = date('Y-m-01', strtotime($firstMonth . ' +' . $i . ' month'));
        $t = date('Y-m-t', strtotime($f));
        $isNow = substr($f, 0, 7) === substr($today, 0, 7);
        $to = $isNow ? $today : $t;
        $s = rep_sales_safe($pdo, $f, $to);
        $buy = (float)rep_try(static function () use ($pdo, $f, $to) {
            $st = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM party_ledger WHERE entry_type = 'bill' AND entry_date BETWEEN ? AND ?");
            $st->execute([$f, $to]);
            return $st->fetchColumn();
        }, 0.0);
        $out[] = [
            'key' => substr($f, 0, 7), 'label' => date('M y', strtotime($f)), 'partial' => $isNow, 'from' => $f, 'to' => $to,
            'orders' => $s['orders'], 'sales' => $s['sales'], 'profit' => $s['est_profit'], 'real_profit' => $s['real_profit'],
            'margin' => $s['sales'] > 0 ? $s['est_profit'] / $s['sales'] * 100 : 0.0,
            'aov' => $s['orders'] > 0 ? $s['sales'] / $s['orders'] : 0.0,
            'buying' => $buy,
        ];
    }
    return $out;
}

/** Order status mix for the period. */
function rep_status_mix(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare('SELECT status, COUNT(*) AS n, COALESCE(SUM(total), 0) AS amt FROM orders WHERE order_date BETWEEN ? AND ? GROUP BY status');
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(string)$r['status']] = ['n' => (int)$r['n'], 'amt' => (float)$r['amt']];
    }
    return $out;
}

/** Best sellers of the period by profit. */
function rep_top_products(PDO $pdo, string $from, string $to, int $limit = 5): array
{
    $st = $pdo->prepare(
        "SELECT p.name, SUM(oi.qty) AS units, SUM(oi.profit) AS profit,
                SUM(oi.qty * oi.sell_price - oi.discount_share) AS sales
         FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id
         WHERE o.status <> 'refunded' AND o.order_date BETWEEN ? AND ?
         GROUP BY p.id, p.name ORDER BY profit DESC, units DESC LIMIT " . (int)$limit
    );
    $st->execute([$from, $to]);
    return $st->fetchAll();
}

/** Customers in the period who had already ordered before it (repeat buyers). */
function rep_repeat_customers(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare(
        "SELECT COUNT(DISTINCT o.customer_phone) FROM orders o
         WHERE o.status <> 'refunded' AND o.order_date BETWEEN ? AND ? AND o.customer_phone <> ''
           AND EXISTS (SELECT 1 FROM orders x WHERE x.customer_phone = o.customer_phone AND x.order_date < ? AND x.status <> 'refunded')"
    );
    $st->execute([$from, $to, $from]);
    return ['repeat' => (int)$st->fetchColumn()];
}

/** Refunds paid in the period (by refund date) and orders closed as fully refunded. */
function rep_returns(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare('SELECT COUNT(DISTINCT order_id) AS n, COALESCE(SUM(amount), 0) AS amt FROM refunds WHERE refund_date BETWEEN ? AND ?');
    $st->execute([$from, $to]);
    $r = $st->fetch();
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status = 'refunded' AND order_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    return ['orders' => (int)$r['n'], 'amount' => round((float)$r['amt'], 2), 'closed' => (int)$st->fetchColumn()];
}

/** Ratios used by several cards, from a rep_sales() row. */
function rep_ratios(array $s): array
{
    $o = max(1, $s['orders']);
    return [
        'aov'        => $s['orders'] > 0 ? $s['sales'] / $s['orders'] : 0.0,
        'margin'     => $s['sales'] > 0 ? $s['est_profit'] / $s['sales'] * 100 : 0.0,
        'real_margin' => $s['sales'] > 0 ? $s['real_profit'] / $s['sales'] * 100 : 0.0,
        'profit_per_order' => $s['orders'] > 0 ? $s['est_profit'] / $o : 0.0,
        'per_customer' => $s['customers'] > 0 ? $s['sales'] / $s['customers'] : 0.0,
        'orders_per_customer' => $s['customers'] > 0 ? $s['orders'] / $s['customers'] : 0.0,
        'cod_share'  => $s['sales'] > 0 ? $s['cod_sales'] / $s['sales'] * 100 : 0.0,
    ];
}

/** Change from $prev to $cur: ['pct' => float|null, 'dir' => 'up'|'down'|'flat'|'new']. */
function rep_delta(float $cur, float $prev): array
{
    if (abs($prev) < 0.005) {
        return ['pct' => null, 'dir' => abs($cur) < 0.005 ? 'flat' : 'new'];
    }
    $pct = ($cur - $prev) / abs($prev) * 100;
    return ['pct' => $pct, 'dir' => abs($pct) < 0.5 ? 'flat' : ($pct > 0 ? 'up' : 'down')];
}

/**
 * Groups a daily series so the chart stays readable: days up to 45, weeks up to 180 days, months beyond.
 * Returns [['label','full','sales','profit','orders'], ...].
 */
function rep_bucket(array $daily): array
{
    $n = count($daily);
    $mode = $n <= 45 ? 'day' : ($n <= 180 ? 'week' : 'month');
    $out = [];
    foreach ($daily as $d => $v) {
        $t = strtotime($d);
        if ($mode === 'day') {
            $key = $d;
            $label = date('j', $t);
            $full = date('D j M', $t);
        } elseif ($mode === 'week') {
            $mon = date('Y-m-d', strtotime((int)date('N', $t) === 1 ? $d : 'last monday', $t));
            $key = $mon;
            $label = date('j M', strtotime($mon));
            $full = 'Week of ' . date('j M', strtotime($mon));
        } else {
            $key = substr($d, 0, 7);
            $label = date('M', $t);
            $full = date('M Y', $t);
        }
        if (!isset($out[$key])) {
            $out[$key] = ['label' => $label, 'full' => $full, 'sales' => 0.0, 'profit' => 0.0, 'orders' => 0];
        }
        $out[$key]['sales'] += $v['sales'];
        $out[$key]['profit'] += $v['profit'];
        $out[$key]['orders'] += $v['orders'];
    }
    return array_values($out);
}
