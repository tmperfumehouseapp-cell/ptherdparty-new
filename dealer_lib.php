<?php
// Dealer desk helpers (manager only): daily dealer ledger, COD & profit, dispatch tracking, daily summary.
//
// Nothing here writes money. It only READS the numbers the panel already keeps, so nothing is counted twice:
//   buying   = approved bills                         (party_ledger 'bill')
//   prepaid  = customer money on the dealer's own QR  (party_ledger 'collected')
//   payments = what you paid the dealer               (party_ledger 'payment', tagged COD or Extra)
// Balance = opening + buying - prepaid on dealer QR - payments.
require_once __DIR__ . '/billing_lib.php';

/** True once migrate_dealer_ledger.sql has been run. */
function dealer_has_paykind(PDO $pdo): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool)$pdo->query("SHOW COLUMNS FROM party_ledger LIKE 'pay_kind'")->fetch();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

function dealer_list(PDO $pdo): array
{
    return $pdo->query('SELECT id, name, is_active FROM parties ORDER BY is_active DESC, name')->fetchAll();
}

/** What you owe each dealer right now: [partyId => balance] (negative = they owe you). */
function dealer_balances(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT party_id, SUM(CASE WHEN entry_type IN ('payment','collected') THEN -amount ELSE amount END) AS b FROM party_ledger GROUP BY party_id")->fetchAll() as $r) {
        $out[(int)$r['party_id']] = round((float)$r['b'], 2);
    }
    return $out;
}

/** Day-by-day ledger of one dealer with a running balance. Days come back oldest first. */
function dealer_ledger(PDO $pdo, int $pid, string $from, string $to): array
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type IN ('payment','collected') THEN -amount ELSE amount END), 0) FROM party_ledger WHERE party_id = ? AND entry_date < ?");
    $st->execute([$pid, $from]);
    $opening = round((float)$st->fetchColumn(), 2);

    $kind = dealer_has_paykind($pdo) ? 'l.pay_kind' : 'NULL';
    $st = $pdo->prepare(
        "SELECT l.id, l.entry_date, l.entry_type, l.amount, l.note, $kind AS pay_kind, a.name AS account, b.bill_no
         FROM party_ledger l LEFT JOIN accounts a ON a.id = l.account_id LEFT JOIN bills b ON b.id = l.bill_id
         WHERE l.party_id = ? AND l.entry_date BETWEEN ? AND ? ORDER BY l.entry_date, l.id"
    );
    $st->execute([$pid, $from, $to]);

    $days = [];
    $run = $opening;
    $tot = ['buy' => 0.0, 'prepaid' => 0.0, 'cod' => 0.0, 'extra' => 0.0, 'other' => 0.0];
    foreach ($st->fetchAll() as $r) {
        $d = $r['entry_date'];
        if (!isset($days[$d])) {
            $days[$d] = ['open' => $run, 'buy' => 0.0, 'prepaid' => 0.0, 'cod' => 0.0, 'extra' => 0.0, 'other' => 0.0, 'close' => $run, 'rows' => []];
        }
        $amt = (float)$r['amount'];
        switch ($r['entry_type']) {
            case 'bill':
                $field = 'buy';
                $run += $amt;
                break;
            case 'collected':
                $field = 'prepaid';
                $run -= $amt;
                break;
            case 'payment':
                $field = ($r['pay_kind'] === 'cod') ? 'cod' : 'extra';
                $run -= $amt;
                break;
            default: // opening, returned: these raise what you owe
                $field = 'other';
                $run += $amt;
        }
        $days[$d][$field] += $amt;
        $tot[$field] += $amt;
        $days[$d]['close'] = round($run, 2);
        $days[$d]['rows'][] = $r;
    }
    return [
        'opening' => $opening,
        'closing' => round($run, 2),
        'days'    => $days,
        'tot'     => $tot,
        'debit'   => $tot['buy'] + $tot['other'],
        'credit'  => $tot['prepaid'] + $tot['cod'] + $tot['extra'],
    ];
}

/**
 * Every ordered item (refunded orders left out) with what the dealer has dispatched of it.
 * A unit counts as dispatched when a live bill (made / sent / approved) covers it. Bills are not tied to one order,
 * so the billed units of a product are given to the oldest orders first. Units moved to another dealer count as handled.
 */
function dealer_items(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT oi.id, oi.order_id, oi.product_id, COALESCE(oi.party_id, 0) AS pk, oi.qty, oi.base_price, oi.sell_price, oi.discount_share, oi.profit,
                o.order_date, o.payment_mode, o.status, o.total, o.advance_amount, o.customer_name,
                p.name AS pname, pa.name AS dname,
                COALESCE((SELECT SUM(c.amount) FROM cod_receipts c WHERE c.order_id = o.id), 0) AS o_recv
         FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id
         LEFT JOIN parties pa ON pa.id = oi.party_id
         WHERE o.status <> 'refunded' ORDER BY oi.order_id, oi.id"
    )->fetchAll();

    $items = [];
    $byKey = [];
    foreach ($rows as $r) {
        $qty = (int)$r['qty'];
        $net = round($qty * (float)$r['sell_price'] - (float)$r['discount_share'], 2);
        $tot = (float)$r['total'];
        $frac = $tot > 0 ? $net / $tot : 0.0;
        $isCod = $r['payment_mode'] === 'cod';
        $dueO = $isCod ? max(0.0, $tot - (float)$r['advance_amount'] - (float)$r['o_recv']) : 0.0;
        $id = (int)$r['id'];
        $items[$id] = [
            'id' => $id, 'order_id' => (int)$r['order_id'], 'pid' => (int)$r['product_id'], 'pk' => (int)$r['pk'],
            'qty' => $qty, 'base' => (float)$r['base_price'], 'cost' => round($qty * (float)$r['base_price'], 2),
            'net' => $net, 'profit' => (float)$r['profit'],
            'date' => $r['order_date'], 'mode' => $r['payment_mode'], 'cod' => $isCod,
            'customer' => $r['customer_name'], 'pname' => $r['pname'], 'dname' => $r['dname'] ?: 'No dealer',
            'adv' => $isCod ? (float)$r['advance_amount'] * $frac : 0.0,
            'recv' => $isCod ? (float)$r['o_recv'] * $frac : 0.0,
            'due' => $dueO * $frac,
            'o_due' => $dueO,
            'disp' => 0, 'disp_date' => null,
        ];
        $byKey[(int)$r['pk'] . ':' . (int)$r['product_id']][] = $id;
    }

    // dispatch events per dealer and product: [date, bill id, units]
    $movedIn = [];
    foreach ($pdo->query('SELECT bill_id, product_id, SUM(qty) AS q FROM product_moves GROUP BY bill_id, product_id')->fetchAll() as $r) {
        $movedIn[(int)$r['bill_id']][(int)$r['product_id']] = (int)$r['q'];
    }
    $ev = [];
    foreach ($pdo->query('SELECT b.id AS bid, b.party_id, b.bill_date, bi.product_id, bi.qty FROM bill_items bi JOIN bills b ON b.id = bi.bill_id WHERE b.status IN ' . BILL_LIVE)->fetchAll() as $r) {
        $own = (int)$r['qty'] - ($movedIn[(int)$r['bid']][(int)$r['product_id']] ?? 0);
        if ($own > 0) {
            $ev[(int)$r['party_id'] . ':' . (int)$r['product_id']][] = [$r['bill_date'], (int)$r['bid'], $own];
        }
    }
    foreach ($pdo->query('SELECT m.from_party_id, m.product_id, m.qty, b.bill_date, b.id AS bid FROM product_moves m JOIN bills b ON b.id = m.bill_id')->fetchAll() as $r) {
        $ev[(int)($r['from_party_id'] ?? 0) . ':' . (int)$r['product_id']][] = [$r['bill_date'], (int)$r['bid'], (int)$r['qty']];
    }

    foreach ($ev as $key => $list) {
        if (empty($byKey[$key])) {
            continue;
        }
        usort($list, static fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $queue = $byKey[$key];
        $qi = 0;
        foreach ($list as [$date, , $units]) {
            while ($units > 0 && $qi < count($queue)) {
                $it = &$items[$queue[$qi]];
                $room = $it['qty'] - $it['disp'];
                if ($room <= 0) {
                    $qi++;
                    unset($it);
                    continue;
                }
                $take = min($room, $units);
                $it['disp'] += $take;
                $it['disp_date'] = $date;
                $units -= $take;
                unset($it);
            }
        }
    }
    return $items;
}

/** Keep the items that match: dealer (0 = all), order date range ('' = open), type, pay ('paid' / 'due'). */
function dealer_filter(array $items, int $dealer, string $from, string $to, string $type = '', string $pay = ''): array
{
    $out = [];
    foreach ($items as $id => $it) {
        if ($dealer && $it['pk'] !== $dealer) {
            continue;
        }
        if ($from !== '' && $it['date'] < $from) {
            continue;
        }
        if ($to !== '' && $it['date'] > $to) {
            continue;
        }
        if ($type !== '' && $it['mode'] !== $type) {
            continue;
        }
        if ($pay === 'due' && $it['o_due'] <= 0.004) {
            continue;
        }
        if ($pay === 'paid' && $it['o_due'] > 0.004) {
            continue;
        }
        $out[$id] = $it;
    }
    return $out;
}

/** Sums over a set of items. */
function dealer_totals(array $items): array
{
    $t = ['orders' => 0, 'units' => 0, 'prepaid' => 0.0, 'cod' => 0.0, 'adv' => 0.0, 'recv' => 0.0, 'due' => 0.0, 'cost' => 0.0, 'profit' => 0.0,
          'disp' => 0, 'pend' => 0, 'pend_value' => 0.0];
    $orders = [];
    foreach ($items as $it) {
        $orders[$it['order_id']] = true;
        $t['units'] += $it['qty'];
        $t[$it['cod'] ? 'cod' : 'prepaid'] += $it['net'];
        $t['adv'] += $it['adv'];
        $t['recv'] += $it['recv'];
        $t['due'] += $it['due'];
        $t['cost'] += $it['cost'];
        $t['profit'] += $it['profit'];
        $t['disp'] += $it['disp'];
        $pend = $it['qty'] - $it['disp'];
        $t['pend'] += $pend;
        $t['pend_value'] += $pend * $it['base'];
    }
    $t['orders'] = count($orders);
    return $t;
}

/** Items grouped by order, each with its dispatch status: done / partial / pending. */
function dealer_orders(array $items): array
{
    $orders = [];
    foreach ($items as $it) {
        $o = $it['order_id'];
        if (!isset($orders[$o])) {
            $orders[$o] = ['id' => $o, 'date' => $it['date'], 'customer' => $it['customer'], 'mode' => $it['mode'],
                           'units' => 0, 'disp' => 0, 'pend_value' => 0.0, 'last' => null, 'items' => []];
        }
        $orders[$o]['units'] += $it['qty'];
        $orders[$o]['disp'] += $it['disp'];
        $orders[$o]['pend_value'] += ($it['qty'] - $it['disp']) * $it['base'];
        if ($it['disp_date'] !== null && ($orders[$o]['last'] === null || $it['disp_date'] > $orders[$o]['last'])) {
            $orders[$o]['last'] = $it['disp_date'];
        }
        $orders[$o]['items'][] = $it;
    }
    foreach ($orders as &$o) {
        $o['state'] = $o['disp'] >= $o['units'] ? 'done' : ($o['disp'] > 0 ? 'partial' : 'pending');
    }
    unset($o);
    krsort($orders);
    return $orders;
}

/** Product by product: ordered, dispatched, pending and the buying value of what is pending. */
function dealer_products(array $items): array
{
    $rows = [];
    foreach ($items as $it) {
        $k = $it['pk'] . ':' . $it['pid'];
        if (!isset($rows[$k])) {
            $rows[$k] = ['name' => $it['pname'], 'dealer' => $it['dname'], 'ordered' => 0, 'disp' => 0, 'pend_value' => 0.0];
        }
        $rows[$k]['ordered'] += $it['qty'];
        $rows[$k]['disp'] += $it['disp'];
        $rows[$k]['pend_value'] += ($it['qty'] - $it['disp']) * $it['base'];
    }
    foreach ($rows as &$r) {
        $r['pend'] = $r['ordered'] - $r['disp'];
    }
    unset($r);
    uasort($rows, static fn($a, $b) => [$b['pend'], $b['ordered']] <=> [$a['pend'], $a['ordered']]);
    return $rows;
}

/**
 * Money you moved with dealers in a date range ('' = open): payments you made (split into COD and extra),
 * and customer money that landed straight on a dealer's QR. $dealer 0 = all dealers.
 */
function dealer_money(PDO $pdo, string $from, string $to, int $dealer): array
{
    $where = [];
    $args = [];
    if ($from !== '') {
        $where[] = 'entry_date >= ?';
        $args[] = $from;
    }
    if ($to !== '') {
        $where[] = 'entry_date <= ?';
        $args[] = $to;
    }
    if ($dealer) {
        $where[] = 'party_id = ?';
        $args[] = $dealer;
    }
    $kind = dealer_has_paykind($pdo) ? 'pay_kind' : 'NULL';
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN entry_type = 'payment' AND $kind = 'cod' THEN amount END), 0) AS cod_paid,
                COALESCE(SUM(CASE WHEN entry_type = 'payment' AND ($kind IS NULL OR $kind <> 'cod') THEN amount END), 0) AS extra_paid,
                COALESCE(SUM(CASE WHEN entry_type = 'collected' THEN amount END), 0) AS prepaid_dealer
         FROM party_ledger" . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    );
    $st->execute($args);
    $r = $st->fetch();
    return [
        'cod_paid' => (float)$r['cod_paid'], 'extra_paid' => (float)$r['extra_paid'],
        'paid' => (float)$r['cod_paid'] + (float)$r['extra_paid'], 'prepaid_dealer' => (float)$r['prepaid_dealer'],
    ];
}

/** COD money that already sits on dealers' own QRs for COD orders placed in the range (reduces what you must still pay them). */
function dealer_cod_on_qr(PDO $pdo, string $from, string $to, int $dealer): float
{
    $where = ["pl.entry_type = 'collected'", "o.payment_mode = 'cod'", "o.status <> 'refunded'"];
    $args = [];
    if ($from !== '') {
        $where[] = 'o.order_date >= ?';
        $args[] = $from;
    }
    if ($to !== '') {
        $where[] = 'o.order_date <= ?';
        $args[] = $to;
    }
    if ($dealer) {
        $where[] = 'pl.party_id = ?';
        $args[] = $dealer;
    }
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(pl.amount), 0) FROM party_ledger pl
         JOIN account_ledger al ON al.id = pl.ledger_id JOIN orders o ON o.id = al.order_id
         WHERE ' . implode(' AND ', $where)
    );
    $st->execute($args);
    return round((float)$st->fetchColumn(), 2);
}

/** Per day totals of COD orders: [date => totals] newest first. */
function dealer_cod_days(array $codItems): array
{
    $by = [];
    foreach ($codItems as $it) {
        $by[$it['date']][] = $it;
    }
    krsort($by);
    $out = [];
    foreach ($by as $d => $list) {
        $out[$d] = dealer_totals($list);
    }
    return $out;
}

function dealer_dlabel(string $d): string
{
    return date('D, d M Y', strtotime($d));
}
