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
        'remitted'  => "({$x['utr']} <> '' OR EXISTS (SELECT 1 FROM sr_status rs WHERE rs.order_id = o.id AND rs.remit_date IS NOT NULL))",
        'delivered' => "(o.status = 'delivered' OR {$x['stat']} = 'delivered')",
        'dead'      => "(o.status = 'refunded' OR {$x['cancel']})",
    ];
}

/**
 * What Shiprocket paid between $from and $to: ['days' => [[d, n, amount, groups => [[utr, crf, n, amount]]]], 'total', 'orders',
 *   'undated' => [n, amount]  (paid, but the report had no remittance date)].
 */
function cod_sr_received(PDO $pdo, string $from, string $to): array
{
    $amt = 'COALESCE(NULLIF(s.remit_amount, 0), o.total - o.advance_amount)';
    $st = $pdo->prepare(
        "SELECT s.remit_date AS d, COALESCE(s.utr, '') AS utr, COALESCE(s.crf, '') AS crf, COUNT(*) AS n, COALESCE(SUM($amt), 0) AS amount
         FROM sr_status s JOIN orders o ON o.id = s.order_id
         WHERE o.payment_mode = 'cod' AND s.remit_date BETWEEN ? AND ?
         GROUP BY s.remit_date, s.utr, s.crf ORDER BY s.remit_date DESC, amount DESC"
    );
    $st->execute([$from, $to]);
    $days = [];
    $total = 0.0;
    $orders = 0;
    foreach ($st->fetchAll() as $r) {
        $d = (string)$r['d'];
        $days[$d] = $days[$d] ?? ['d' => $d, 'n' => 0, 'amount' => 0.0, 'groups' => []];
        $days[$d]['n'] += (int)$r['n'];
        $days[$d]['amount'] += (float)$r['amount'];
        $days[$d]['groups'][] = ['utr' => $r['utr'], 'crf' => $r['crf'], 'n' => (int)$r['n'], 'amount' => (float)$r['amount']];
        $total += (float)$r['amount'];
        $orders += (int)$r['n'];
    }
    $u = $pdo->query(
        "SELECT COUNT(*) AS n, COALESCE(SUM($amt), 0) AS amount FROM sr_status s JOIN orders o ON o.id = s.order_id
         WHERE o.payment_mode = 'cod' AND s.remit_date IS NULL AND COALESCE(s.utr, '') <> ''"
    )->fetch();
    return ['days' => array_values($days), 'total' => round($total, 2), 'orders' => $orders, 'undated' => ['n' => (int)$u['n'], 'amount' => round((float)$u['amount'], 2)]];
}

/**
 * What Shiprocket still has to pay, from COD orders it has not remitted (cancelled / returned ones are left out):
 * delivered_initiated (remittance made, not paid yet), delivered_waiting (nothing yet) and on_the_way (not delivered yet). Each [n, amount].
 */
function cod_sr_pending(PDO $pdo): array
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
    foreach (['delivered_initiated', 'delivered_waiting', 'on_the_way'] as $k) {
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
    $any = (int)$pdo->query("SELECT COUNT(*) FROM sr_status WHERE COALESCE(utr, '') <> '' OR remit_date IS NOT NULL")->fetchColumn();
    return ['delivered' => (int)$r['delivered'], 'remitted' => (int)$r['remitted'], 'any' => $any];
}
