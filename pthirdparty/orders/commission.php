<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/orders_lib.php';
require __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/order_auto_lib.php';
require_once __DIR__ . '/includes/commission_lib.php';
$user = require_login();
$pdo = db();
$isAdmin = $user['role'] === 'admin';

$from = valid_date((string)($_GET['from'] ?? '')) ?? substr(biz_today(), 0, 8) . '01';
$to = valid_date((string)($_GET['to'] ?? '')) ?? biz_today();
$sales = $isAdmin ? (int)($_GET['sales'] ?? 0) : (int)$user['id'];
// phone filter (manager): 0 = all phones, -1 = orders with no phone number, otherwise the number's id
$numRaw = (string)($_GET['num'] ?? '');
$num = $isAdmin ? ($numRaw === 'none' ? -1 : max(0, (int)$numRaw)) : 0;

$groups = active_groups($pdo);

$hasComm = orders_has_comm($pdo);
$hasWa = orders_has_wa($pdo);
$sql = "SELECT " . ($hasComm ? 'o.commission_status, ' : 'NULL AS commission_status, ') . ($hasWa ? 'o.wa_enforced, o.wa_status, ' : '0 AS wa_enforced, NULL AS wa_status, ') . "o.number_id, n.label AS num_label, n.phone AS num_phone, o.id, o.order_date, o.salesperson_id, o.payment_mode, o.total, o.advance_amount, o.profit, o.status, u.name AS sales_name,
               COALESCE((SELECT SUM(c.amount) FROM cod_receipts c WHERE c.order_id = o.id), 0) AS cod_received,
               COALESCE((SELECT SUM(r.amount) FROM refunds r WHERE r.order_id = o.id), 0) AS refunded
        FROM orders o JOIN users u ON u.id = o.salesperson_id LEFT JOIN order_numbers n ON n.id = o.number_id
        WHERE o.order_date BETWEEN ? AND ?";
$params = [$from, $to];
if ($sales > 0) {
    $sql .= ' AND o.salesperson_id = ?';
    $params[] = $sales;
}
$sql .= ' ORDER BY o.order_date DESC, o.id';
$st = $pdo->prepare($sql);
$st->execute($params);

// per day and salesperson, and per phone number (the day's rate always comes from the salesperson's whole day)
$res = commission_build($st->fetchAll(), $groups, $num);
$days = $res['days'];
$phones = $res['phones'];
$totEarn = $res['totEarn'];
$totPending = $res['totPending'];
$totOrders = $res['totOrders'];
$numList = $isAdmin ? $pdo->query('SELECT id, label, phone FROM order_numbers ORDER BY is_active DESC, label')->fetchAll() : [];
$numName = '';
foreach ($numList as $nl) {
    if ((int)$nl['id'] === $num) {
        $numName = trim($nl['label'] . ' ' . ($nl['phone'] ?? ''));
    }
}
if ($num === -1) {
    $numName = 'No phone number';
}
$qs = static function (array $over) use ($from, $to, $sales, $num): string {
    $q = array_merge(['from' => $from, 'to' => $to, 'sales' => $sales, 'num' => $num === -1 ? 'none' : $num], $over);
    return 'commission.php?' . http_build_query(array_filter($q, static fn($v) => $v !== 0 && $v !== '' && $v !== null));
};

$salesList = $isAdmin ? $pdo->query('SELECT id, name FROM users ORDER BY name')->fetchAll() : [];
$dateInput = 'style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"';

// manager: orders whose payment time must be checked by hand
$reviewRows = [];
if ($isAdmin && $hasComm) {
    $reviewRows = $pdo->query("SELECT o.id, o.order_date, o.customer_name, u.name AS sales_name, o.commission_note FROM orders o JOIN users u ON u.id = o.salesperson_id WHERE o.commission_status = 'review' ORDER BY o.id DESC LIMIT 50")->fetchAll();
}

// manager: orders whose commission waits for the WhatsApp confirmation
$waHeld = [];
if ($isAdmin && $hasWa) {
    $waHeld = $pdo->query("SELECT o.id, o.customer_name, o.wa_status, u.name AS sales_name FROM orders o JOIN users u ON u.id = o.salesperson_id WHERE o.wa_enforced = 1 AND (o.wa_status IS NULL OR o.wa_status NOT IN ('sent','delivered')) ORDER BY o.id DESC LIMIT 50")->fetchAll();
}

$pageTitle = 'Commission';
$active = 'commission';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<div class="stats">
  <div class="stat"><div class="k">Earned (money received)</div><div class="v pos"><?= e(inr($totEarn)) ?></div></div>
  <div class="stat"><div class="k">Coming when COD arrives</div><div class="v"><?= e(inr($totPending)) ?></div></div>
  <div class="stat"><div class="k">Orders</div><div class="v"><?= (int)$totOrders ?></div></div>
</div>

<?php if ($isAdmin && !auto_enabled()): ?>
<section class="card">
  <h2>Payment time check is off</h2>
  <p class="hint" style="margin:0">Orders are saved and commission is paid as before. To switch on the automatic 20-minute check, put the Claude API key in the file <b>anthropic_key.php</b> next to orders.php on the server (see the setup note), then create the next order.</p>
</section>
<?php endif; ?>

<?php if ($waHeld): ?>
<section class="card">
  <h2>Waiting for WhatsApp confirmation (<?= count($waHeld) ?>)</h2>
  <p class="hint" style="margin:0 0 10px">Commission on these orders is on hold until WhatsApp confirms the message was sent.</p>
  <?php foreach ($waHeld as $wh): ?>
    <div style="display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-top:1px solid var(--line)">
      <div><b><?= e(order_no($wh['id'])) ?></b> · <?= e($wh['customer_name']) ?> · <?= e($wh['sales_name']) ?> · <?= e($wh['wa_status'] ?? 'pending') ?></div>
      <a class="btn btn-ghost btn-sm linkbtn" href="order_view.php?id=<?= (int)$wh['id'] ?>#wacheck">Check</a>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($reviewRows): ?>
<section class="card">
  <h2>Payment time to check (<?= count($reviewRows) ?>)</h2>
  <p class="hint" style="margin:0 0 10px">The payment time on these orders could not be confirmed, so their commission is on hold. Open each one and set the real payment time.</p>
  <?php foreach ($reviewRows as $rv): ?>
    <div style="display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-top:1px solid var(--line)">
      <div><b><?= e(order_no($rv['id'])) ?></b> · <?= e($rv['customer_name']) ?> · <?= e($rv['sales_name']) ?><div class="mini"><?= e($rv['commission_note']) ?></div></div>
      <a class="btn btn-ghost btn-sm linkbtn" href="order_view.php?id=<?= (int)$rv['id'] ?>#paytime">Check</a>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card">
  <form method="get" class="filters">
    <div><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($from) ?>" <?= $dateInput ?>></div>
    <div><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($to) ?>" <?= $dateInput ?>></div>
    <?php if ($isAdmin): ?>
    <div><label for="sales">Salesperson</label>
      <select id="sales" name="sales"><option value="0">Everyone</option>
        <?php foreach ($salesList as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $sales === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label for="num">Phone</label>
      <select id="num" name="num"><option value="0">All phones</option>
        <?php foreach ($numList as $nl): ?><option value="<?= (int)$nl['id'] ?>"<?= $num === (int)$nl['id'] ? ' selected' : '' ?>><?= e(trim($nl['label'] . ' ' . ($nl['phone'] ?? ''))) ?></option><?php endforeach; ?>
        <option value="none"<?= $num === -1 ? ' selected' : '' ?>>No phone number</option>
      </select></div>
    <?php endif; ?>
    <button class="btn" type="submit">Show</button>
  </form>
  <?php if ($isAdmin && $num !== 0): ?>
    <p class="hint" style="margin:0 0 12px">Showing only orders from <b><?= e($numName) ?></b>. The daily rate still counts all of that salesperson's orders of the day. <a href="<?= e($qs(['num' => 0])) ?>">Show all phones</a></p>
  <?php endif; ?>

  <?php if (!$days): ?>
    <div class="empty">No orders in this period.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="tight" style="min-width:640px">
      <thead><tr><th>Day</th><?php if ($isAdmin): ?><th>Salesperson</th><?php endif; ?><th class="num">Orders</th><th class="num">Rate</th><?php if ($isAdmin): ?><th class="num">Profit</th><?php endif; ?><th class="num">Earned</th><th class="num">Coming</th></tr></thead>
      <tbody>
      <?php foreach ($days as $d): ?>
        <tr>
          <td><?= e(date('d M Y', strtotime($d['date']))) ?></td>
          <?php if ($isAdmin): ?><td><?= e($d['sales']) ?></td><?php endif; ?>
          <td class="num"><?php if ($num !== 0): ?><?= (int)$d['shown'] ?> <span class="mini">of <?= (int)$d['count'] ?></span><?php else: ?><?= (int)$d['count'] ?><?= $groups > 0 ? ' / ' . (int)$groups : '' ?><?php endif; ?></td>
          <td class="num"><span class="tag <?= $d['rate'] >= 0.1 ? 'tag-ok' : '' ?>"><?= (int)round($d['rate'] * 100) ?>%</span></td>
          <?php if ($isAdmin): ?><td class="num"><?= e(inr($d['profit'])) ?></td><?php endif; ?>
          <td class="num pos"><?= e(inr($d['earn'])) ?></td>
          <td class="num"><?= e(inr($d['later'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <p class="hint">
    Rate is 8% while the day's orders are below the number of active groups (<?= (int)$groups ?> now), and 10% once they reach it. It applies to all of that day's profit after discount.
    Prepaid orders count at once. COD orders count as the money is received. Refunded value is removed.
    An order created more than <?= (int)AUTO_DEADLINE_MIN ?> minutes after the payment time on its screenshot earns ₹0 (it is still saved and still counts as an order for the daily rate).
  </p>
</section>

<?php if ($isAdmin && $phones): ?>
<section class="card" id="phones">
  <h2>Commission by phone<?= $num !== 0 ? ' (' . e($numName) . ')' : '' ?></h2>
  <p class="hint" style="margin:0 0 10px">Each phone number the orders were placed on, for the dates and salesperson chosen above. Tap a phone to see only its orders.</p>
  <div class="table-wrap">
    <table class="tight" style="min-width:640px">
      <thead><tr><th>Phone</th><th>Salespeople</th><th class="num">Orders</th><th class="num">Profit</th><th class="num">Earned</th><th class="num">Coming</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($phones as $ph): ?>
        <tr>
          <td><b><?= e($ph['label']) ?></b><?php if ($ph['phone'] !== ''): ?><div class="mini"><?= e($ph['phone']) ?></div><?php endif; ?></td>
          <td class="mini"><?= e(implode(', ', array_map(static fn($n, $k) => $n . ' (' . $k . ')', array_keys($ph['people']), $ph['people']))) ?></td>
          <td class="num"><?= (int)$ph['orders'] ?></td>
          <td class="num"><?= e(inr($ph['profit'])) ?></td>
          <td class="num pos"><?= e(inr($ph['earn'])) ?></td>
          <td class="num"><?= e(inr($ph['later'])) ?></td>
          <td class="num"><a class="btn btn-ghost btn-sm linkbtn" href="<?= e($qs(['num' => $ph['id'] === 0 ? 'none' : $ph['id']])) ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
