<?php
// Refunds (manager only): every refund that went back to a customer, split into Prepaid orders and COD orders,
// plus the returned parcels that are still waiting for their refund. A refund is made from the Orders list (Refund button)
// or from the order page; this page only shows them.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ops_lib.php';
$user = require_admin();
$pdo = db();

$tab = in_array((string)($_GET['tab'] ?? ''), ['prepaid', 'cod'], true) ? (string)$_GET['tab'] : '';
$today = biz_today();
$from = valid_date((string)($_GET['from'] ?? '')) ?? date('Y-m-01', strtotime($today));
$to = valid_date((string)($_GET['to'] ?? '')) ?? $today;
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$qs = static function (array $over) use ($tab, $from, $to): string {
    $q = array_filter(array_merge(['tab' => $tab, 'from' => $from, 'to' => $to], $over), static fn($v) => $v !== '' && $v !== null);
    return 'refunds.php?' . http_build_query($q);
};

// totals for the dates (always both kinds, so the tiles do not change with the tab)
$sum = $pdo->prepare(
    "SELECT o.payment_mode AS mode, COUNT(*) AS n, COALESCE(SUM(r.amount), 0) AS amt
     FROM refunds r JOIN orders o ON o.id = r.order_id WHERE r.refund_date BETWEEN ? AND ? GROUP BY o.payment_mode"
);
$sum->execute([$from, $to]);
$tot = ['prepaid' => ['n' => 0, 'amt' => 0.0], 'cod' => ['n' => 0, 'amt' => 0.0]];
foreach ($sum->fetchAll() as $r) {
    $tot[$r['mode'] === 'cod' ? 'cod' : 'prepaid'] = ['n' => (int)$r['n'], 'amt' => (float)$r['amt']];
}

$sql = "SELECT r.id, r.refund_date, r.amount, r.note, r.parcel_received_date, o.id AS order_id, o.customer_name, o.payment_mode, o.total,
               a.name AS account, p.name AS dealer, u.name AS by_name
        FROM refunds r JOIN orders o ON o.id = r.order_id JOIN accounts a ON a.id = r.account_id
        LEFT JOIN parties p ON p.id = a.party_id LEFT JOIN users u ON u.id = r.created_by
        WHERE r.refund_date BETWEEN ? AND ?" . ($tab !== '' ? ' AND o.payment_mode = ?' : '') . ' ORDER BY r.id DESC LIMIT 500';
$st = $pdo->prepare($sql);
$st->execute($tab !== '' ? [$from, $to, $tab] : [$from, $to]);
$rows = $st->fetchAll();

// returned parcels waiting for their refund
$wait = $pdo->query(
    "SELECT o.id, o.order_date, o.customer_name, o.payment_mode, o.total,
            (SELECT COALESCE(SUM(al.amount), 0) FROM account_ledger al WHERE al.order_id = o.id AND al.direction = 'in' AND al.entry_type IN ('prepaid','cod'))
          - (SELECT COALESCE(SUM(rf.amount), 0) FROM refunds rf WHERE rf.order_id = o.id) AS left_amt
     FROM orders o WHERE o.status = 'return_received' ORDER BY o.id DESC LIMIT 100"
)->fetchAll();
$wait = array_values(array_filter($wait, static fn($w) => (float)$w['left_amt'] > 0.004));

$pageTitle = 'Refunds';
$active = 'refunds';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
$dateInput = 'style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"';
?>
<div style="display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px">
  <?php foreach (['' => 'All refunds', 'prepaid' => 'Prepaid refunds', 'cod' => 'COD refunds'] as $k => $l): ?>
    <a class="btn <?= $tab === $k ? 'btn-accent' : 'btn-ghost' ?>" style="display:inline-flex;align-items:center;text-decoration:none;height:38px" href="<?= e($qs(['tab' => $k])) ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</div>

<div class="stats">
  <div class="stat"><div class="k">Refunded in these dates</div><div class="v" style="color:#B91C1C"><?= e(inr($tot['prepaid']['amt'] + $tot['cod']['amt'])) ?></div><div class="mini"><?= (int)$tot['prepaid']['n'] + (int)$tot['cod']['n'] ?> refund<?= ($tot['prepaid']['n'] + $tot['cod']['n']) === 1 ? '' : 's' ?></div></div>
  <div class="stat"><div class="k">Prepaid orders</div><div class="v"><?= e(inr($tot['prepaid']['amt'])) ?></div><div class="mini"><?= (int)$tot['prepaid']['n'] ?> refund<?= $tot['prepaid']['n'] === 1 ? '' : 's' ?></div></div>
  <div class="stat"><div class="k">COD orders</div><div class="v"><?= e(inr($tot['cod']['amt'])) ?></div><div class="mini"><?= (int)$tot['cod']['n'] ?> refund<?= $tot['cod']['n'] === 1 ? '' : 's' ?> · advance and COD money</div></div>
  <div class="stat"><div class="k">Waiting for refund</div><div class="v" style="<?= $wait ? 'color:#B45309' : '' ?>"><?= count($wait) ?></div><div class="mini">returned parcels</div></div>
</div>

<section class="card">
  <form method="get" class="filters" style="margin-bottom:12px">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($from) ?>" <?= $dateInput ?>></div>
    <div><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($to) ?>" <?= $dateInput ?>></div>
    <button class="btn" type="submit">Show</button>
    <a class="btn btn-ghost linkbtn" href="refunds.php<?= $tab !== '' ? '?tab=' . e($tab) : '' ?>">This month</a>
  </form>
  <?php if (!$rows): ?>
    <div class="empty">No refunds in these dates.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="tight" style="min-width:760px">
    <thead><tr><th>Date</th><th>Order</th><th>Customer</th><th>Order type</th><th>Paid back from</th><th class="num">Order total</th><th class="num">Refunded</th><th>Note</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e(date('d M Y', strtotime($r['refund_date']))) ?></td>
        <td><a class="rowlink" href="order_view.php?id=<?= (int)$r['order_id'] ?>"><?= e(order_no($r['order_id'])) ?></a></td>
        <td><?= e($r['customer_name']) ?></td>
        <td><span class="tag <?= $r['payment_mode'] === 'cod' ? 'tag-warn' : 'tag-ok' ?>"><?= $r['payment_mode'] === 'cod' ? 'COD' : 'Prepaid' ?></span></td>
        <td><?= e($r['account']) ?><?php if ($r['dealer']): ?><div class="mini">dealer <?= e($r['dealer']) ?></div><?php endif; ?></td>
        <td class="num"><?= e(inr($r['total'])) ?></td>
        <td class="num" style="font-weight:800;color:#B91C1C">-<?= e(inr($r['amount'])) ?></td>
        <td><?= e((string)$r['note']) ?></td>
        <td><?= e((string)$r['by_name']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<?php if ($wait): ?>
<section class="card">
  <h2>Waiting for refund</h2>
  <div class="table-wrap"><table class="tight" style="min-width:520px">
    <thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Order type</th><th class="num">Still to refund</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($wait as $w): ?>
      <tr>
        <td><a class="rowlink" href="order_view.php?id=<?= (int)$w['id'] ?>"><?= e(order_no($w['id'])) ?></a></td>
        <td><?= e(date('d M Y', strtotime($w['order_date']))) ?></td>
        <td><?= e($w['customer_name']) ?></td>
        <td><span class="tag <?= $w['payment_mode'] === 'cod' ? 'tag-warn' : 'tag-ok' ?>"><?= $w['payment_mode'] === 'cod' ? 'COD' : 'Prepaid' ?></span></td>
        <td class="num"><?= e(inr($w['left_amt'])) ?></td>
        <td class="num"><a class="btn btn-ghost btn-sm linkbtn" href="order_view.php?id=<?= (int)$w['id'] ?>">Open order</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
