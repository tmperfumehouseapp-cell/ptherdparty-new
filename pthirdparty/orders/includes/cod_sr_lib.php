<?php
// COD Amount page, Shiprocket view: what Shiprocket has paid out (UTR / remittance date) and what it still owes.
// Data: sr_status (filled by the all-orders / COD reports and the sync). See order_tags_lib.php.
require_once __DIR__ . '/order_tags_lib.php';

/** SQL pieces (alias o = orders). */
function cod_sr_exprs(): array
{
    $x = order_tag_exprs();
    return [
        'x'         => $x,
        'amt'       => '(o.total - o.advance_amount)',
        'remitted'  => "({$x['utr']} <> '' OR EXISTS (SELECT 1 FROM sr_status rs WHERE rs.order_id = o.id AND rs.remit_date IS NOT NULL) OR EXISTS (SELECT 1 FROM sr_remit rr WHERE rr.order_id = o.id))",
        'delivered' => "(o.status = 'delivered' OR {$x['stat']} = 'delivered')",
        'dead'      => "(o.status = 'refunded' OR {$x['cancel']})",
    ];
}

/**
 * What Shiprocket paid between $from and $to: ['days' => [[d, n, amount, groups => [[utr, crf, n, amount]]]], 'total', 'orders',
 *   'undated' => [n, amount] (paid, but the report had no remittance date), 'panel' => how many of the paid parcels are panel orders].
 * Source: every payout line of the uploaded reports (sr_remit), plus panel orders whose payout was saved the older way (sr_status) and are not in sr_remit.
 */
function cod_sr_received(PDO $pdo, string $from, string $to, bool $assumeDelivered = false): array
{
    $lines = $pdo->prepare(
        "SELECT r.remit_date AS d, COALESCE(r.utr, '') AS utr, COALESCE(r.crf, '') AS crf, r.amount AS amount, r.order_id AS order_id, r.ref AS ref, COALESCE(r.awb, '') AS awb, COALESCE(o.customer_name, '') AS name
         FROM sr_remit r LEFT JOIN orders o ON o.id = r.order_id WHERE r.remit_date BETWEEN ? AND ?
         UNION ALL
         SELECT s.remit_date, COALESCE(s.utr, ''), COALESCE(s.crf, ''), COALESCE(NULLIF(s.remit_amount, 0), o.total - o.advance_amount), s.order_id, '', COALESCE(s.awb, ''), COALESCE(o.customer_name, '')
         FROM sr_status s JOIN orders o ON o.id = s.order_id
         WHERE o.payment_mode = 'cod' AND s.remit_date BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM sr_remit r WHERE r.order_id = s.order_id)"
    );
    $lines->execute([$from, $to, $from, $to]);
    $rows = $lines->fetchAll();
    $assumed = ['n' => 0, 'amount' => 0.0];
    if ($assumeDelivered) { // delivered COD parcels with no payout line: the money is taken as already in the account, dated by the delivery day
        $e = cod_sr_exprs();
        $srId = function_exists('orders_has_sr_id') && orders_has_sr_id($pdo) ? "COALESCE(o.shiprocket_order_id, '')" : "''";
        $q = $pdo->prepare(
            "SELECT SUBSTR(o.delivered_at, 1, 10) AS d, '' AS utr, '' AS crf, {$e['amt']} AS amount, o.id AS order_id, $srId AS ref, COALESCE(o.tracking_id, '') AS awb, COALESCE(o.customer_name, '') AS name
             FROM orders o WHERE o.payment_mode = 'cod' AND o.status = 'delivered' AND NOT {$e['remitted']} AND NOT {$e['dead']}
               AND o.delivered_at IS NOT NULL AND SUBSTR(o.delivered_at, 1, 10) BETWEEN ? AND ?"
        );
        $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $r) {
            $r['assumed'] = true;
            $rows[] = $r;
            $assumed['n']++;
            $assumed['amount'] += (float)$r['amount'];
        }
    }
    $days = [];
    $total = 0.0;
    $orders = 0;
    $panel = 0;
    foreach ($rows as $r) {
        $d = (string)$r['d'];
        $days[$d] = $days[$d] ?? ['d' => $d, 'n' => 0, 'amount' => 0.0, 'g' => [], 'lines' => []];
        $k = $r['utr'] . '|' . $r['crf'];
        $days[$d]['g'][$k] = $days[$d]['g'][$k] ?? ['utr' => $r['utr'], 'crf' => $r['crf'], 'n' => 0, 'amount' => 0.0];
        $days[$d]['g'][$k]['n']++;
        $days[$d]['g'][$k]['amount'] += (float)$r['amount'];
        $days[$d]['lines'][] = ['order_id' => $r['order_id'] !== null ? (int)$r['order_id'] : null, 'name' => (string)$r['name'], 'ref' => (string)$r['ref'], 'awb' => (string)$r['awb'],
                                'utr' => (string)$r['utr'], 'crf' => (string)$r['crf'], 'amount' => (float)$r['amount'], 'assumed' => !empty($r['assumed'])];
        $days[$d]['n']++;
        $days[$d]['amount'] += (float)$r['amount'];
        $total += (float)$r['amount'];
        $orders++;
        $panel += $r['order_id'] !== null ? 1 : 0;
    }
    krsort($days);
    foreach ($days as &$day) {
        $day['groups'] = array_values($day['g']);
        usort($day['groups'], static fn($a, $b) => $b['amount'] <=> $a['amount']);
        usort($day['lines'], static fn($a, $b) => $b['amount'] <=> $a['amount']);
        unset($day['g']);
    }
    unset($day);
    $u = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount FROM sr_remit WHERE remit_date IS NULL")->fetch();
    return ['days' => array_values($days), 'total' => round($total, 2), 'orders' => $orders, 'panel' => $panel, 'assumed' => ['n' => $assumed['n'], 'amount' => round($assumed['amount'], 2)], 'undated' => ['n' => (int)$u['n'], 'amount' => round((float)$u['amount'], 2)]];
}

/**
 * What Shiprocket still has to pay, from COD orders it has not remitted (cancelled / returned ones are left out):
 * delivered_initiated (remittance made, not paid yet), delivered_waiting (nothing yet) and on_the_way (not delivered yet). Each [n, amount].
 */
function cod_sr_pending(PDO $pdo, bool $assumeDelivered = false): array
{
    $e = cod_sr_exprs();
    $base = "o.payment_mode = 'cod' AND NOT {$e['remitted']} AND NOT {$e['dead']}";
    $one = static function (string $cond) use ($pdo, $base, $e): array {
        $r = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM({$e['amt']}), 0) AS amount FROM orders o WHERE $base AND $cond")->fetch();
        return ['n' => (int)$r['n'], 'amount' => round((float)$r['amount'], 2)];
    };
    $out = [
        'delivered_initiated' => $one("{$e['delivered']} AND {$e['x']['crf']} <> ''"),
        'delivered_waiting'   => $one("{$e['delivered']} AND {$e['x']['crf']} = ''"),
        'on_the_way'          => $one("NOT {$e['delivered']} AND o.status IN ('created','dispatched')"),
    ];
    $out['total'] = ['n' => 0, 'amount' => 0.0];
    foreach ($assumeDelivered ? ['on_the_way'] : ['delivered_initiated', 'delivered_waiting', 'on_the_way'] as $k) { // delivered parcels count as received when the setting is on
        $out['total']['n'] += $out[$k]['n'];
        $out['total']['amount'] = round($out['total']['amount'] + $out[$k]['amount'], 2);
    }
    return $out;
}

/** How much Shiprocket remittance information the panel has: delivered COD orders in total, and how many of them have a UTR / remittance date. */
function cod_sr_coverage(PDO $pdo): array
{
    $e = cod_sr_exprs();
    $r = $pdo->query(
        "SELECT COUNT(*) AS delivered, COALESCE(SUM(CASE WHEN {$e['remitted']} THEN 1 ELSE 0 END), 0) AS remitted
         FROM orders o WHERE o.payment_mode = 'cod' AND {$e['delivered']} AND NOT {$e['dead']}"
    )->fetch();
    $any = (int)$pdo->query("SELECT COUNT(*) FROM sr_remit")->fetchColumn() + (int)$pdo->query("SELECT COUNT(*) FROM sr_status WHERE COALESCE(utr, '') <> '' OR remit_date IS NOT NULL")->fetchColumn();
    $last = $pdo->query('SELECT MAX(remit_date) FROM sr_remit')->fetchColumn();
    return ['delivered' => (int)$r['delivered'], 'remitted' => (int)$r['remitted'], 'any' => $any, 'last' => $last ?: null];
}

/**
 * The orders behind a pending figure, oldest delivery first.
 * $kind: 'delivered' (delivered, Shiprocket has not paid: includes the initiated ones) or 'way' (still on the way).
 * Each row: id, order_date, name, amount, delivered_on, days (since delivery), crf, sr_status, sr_order_id, awb.
 */
function cod_sr_pending_lines(PDO $pdo, string $kind, bool $hasSr, int $limit = 400): array
{
    $e = cod_sr_exprs();
    $cond = $kind === 'way' ? "NOT {$e['delivered']} AND o.status IN ('created','dispatched')" : $e['delivered'];
    $srId = $hasSr ? "COALESCE(o.shiprocket_order_id, '')" : "''";
    $rows = $pdo->query(
        "SELECT o.id, o.order_date, o.customer_name AS name, {$e['amt']} AS amount, o.delivered_at, COALESCE(o.tracking_id, '') AS awb, $srId AS sr_order_id,
                {$e['x']['crf']} AS crf, {$e['x']['stat']} AS sr_status
         FROM orders o WHERE o.payment_mode = 'cod' AND NOT {$e['remitted']} AND NOT {$e['dead']} AND $cond
         ORDER BY o.delivered_at IS NULL, o.delivered_at, o.id LIMIT " . (int)$limit
    )->fetchAll();
    $today = strtotime(date('Y-m-d'));
    foreach ($rows as &$r) {
        $dd = substr((string)$r['delivered_at'], 0, 10);
        $r['delivered_on'] = $dd !== '' && strtotime($dd) ? $dd : null;
        $r['days'] = $r['delivered_on'] ? max(0, (int)floor(($today - strtotime($r['delivered_on'])) / 86400)) : null;
        $r['amount'] = (float)$r['amount'];
    }
    unset($r);
    return $rows;
}
