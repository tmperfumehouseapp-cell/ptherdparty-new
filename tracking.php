<?php
// Send tracking: orders that still need a tracking ID, with two filters (order placed by person, order placed by number).
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/orders_lib.php';
require __DIR__ . '/includes/ops_lib.php';
$user = require_login();
$pdo = db();
$isAdmin = $user['role'] === 'admin';

// ---- filters (kept after saving) ----
$sales = $isAdmin ? (int)($_REQUEST['sales'] ?? 0) : 0;
$num = (int)($_REQUEST['num'] ?? 0);               // 0 = all numbers, -1 = orders with no number
$show = (($_REQUEST['show'] ?? 'pending') === 'all') ? 'all' : 'pending';
$q = trim((string)($_REQUEST['q'] ?? ''));
$keep = array_filter(['sales' => $sales ?: '', 'num' => $num ?: '', 'show' => $show === 'all' ? 'all' : '', 'q' => $q]);
$back = 'tracking.php' . ($keep ? '?' . http_build_query($keep) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare('SELECT id, salesperson_id, status FROM orders WHERE id = ?');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o || (!$isAdmin && (int)$o['salesperson_id'] !== (int)$user['id'])) {
        http_response_code(404);
        exit('Order not found.');
    }
    $tid = trim((string)($_POST['tracking_id'] ?? ''));
    $link = trim((string)($_POST['tracking_link'] ?? ''));
    if ($link !== '' && !preg_match('~^https?://~i', $link)) {
        flash('error', 'Tracking link must start with http:// or https://');
    } else {
        $newStatus = $o['status'];
        if ($o['status'] === 'created' && ($tid !== '' || $link !== '')) {
            $newStatus = 'dispatched';
        }
        $pdo->prepare('UPDATE orders SET tracking_id = ?, tracking_link = ?, status = ? WHERE id = ?')
            ->execute([$tid === '' ? null : mb_substr($tid, 0, 100), $link === '' ? null : mb_substr($link, 0, 500), $newStatus, $id]);
        flash('ok', 'Tracking saved for ' . order_no($id) . '. Use the WhatsApp button to send it.');
    }
    redirect($back);
}

$where = ["o.status IN ('created','dispatched')"];
$params = [];
if (!$isAdmin) {
    $where[] = 'o.salesperson_id = ?';
    $params[] = (int)$user['id'];
} elseif ($sales > 0) {
    $where[] = 'o.salesperson_id = ?';
    $params[] = $sales;
}
if ($num > 0) {
    $where[] = 'o.number_id = ?';
    $params[] = $num;
} elseif ($num === -1) {
    $where[] = 'o.number_id IS NULL';
}
if ($show === 'pending') {
    $where[] = "o.status = 'created' AND (o.tracking_id IS NULL OR o.tracking_id = '') AND (o.tracking_link IS NULL OR o.tracking_link = '')";
}
if ($q !== '') {
    $where[] = '(o.customer_name LIKE ? OR o.customer_phone LIKE ? OR o.tracking_id LIKE ? OR o.id = ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, (int)preg_replace('/\D+/', '', $q));
}
$w = ' WHERE ' . implode(' AND ', $where);

$cnt = $pdo->prepare('SELECT COUNT(*) FROM orders o' . $w);
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();

$st = $pdo->prepare(
    'SELECT o.id, o.order_date, o.customer_name, o.customer_phone, o.tracking_id, o.tracking_link, o.status,
            u.name AS sales_name, n.label AS num_label, n.phone AS num_phone,
            (SELECT GROUP_CONCAT(CONCAT(p.name, " x", oi.qty) SEPARATOR ", ") FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = o.id) AS items
     FROM orders o JOIN users u ON u.id = o.salesperson_id LEFT JOIN order_numbers n ON n.id = o.number_id' . $w .
    ' ORDER BY o.id DESC LIMIT 100'
);
$st->execute($params);
$rows = $st->fetchAll();

$salesList = $isAdmin ? $pdo->query('SELECT id, name FROM users ORDER BY name')->fetchAll() : [];
$numList = $pdo->query('SELECT id, label, phone, is_active FROM order_numbers ORDER BY is_active DESC, label')->fetchAll();

$pageTitle = 'Tracking';
$active = 'tracking';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<section class="card">
  <form method="get" class="filters">
    <?php if ($isAdmin): ?>
    <div><label for="sales">Order placed by</label>
      <select id="sales" name="sales"><option value="0">All people</option>
        <?php foreach ($salesList as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $sales === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <div><label for="num">Order placed by number</label>
      <select id="num" name="num"><option value="0">All numbers</option>
        <?php foreach ($numList as $n): ?><option value="<?= (int)$n['id'] ?>"<?= $num === (int)$n['id'] ? ' selected' : '' ?>><?= e($n['label'] . ($n['phone'] ? ' - ' . $n['phone'] : '') . ($n['is_active'] ? '' : ' (off)')) ?></option><?php endforeach; ?>
        <option value="-1"<?= $num === -1 ? ' selected' : '' ?>>No number saved</option>
      </select></div>
    <div><label for="show">Show</label>
      <select id="show" name="show">
        <option value="pending"<?= $show === 'pending' ? ' selected' : '' ?>>Needs tracking ID</option>
        <option value="all"<?= $show === 'all' ? ' selected' : '' ?>>All open orders</option>
      </select></div>
    <div><label for="q">Search</label><input id="q" name="q" type="text" value="<?= e($q) ?>" placeholder="Name, phone, order no."></div>
    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-ghost linkbtn" href="tracking.php">Clear</a>
  </form>
  <p class="hint" style="margin:10px 0 0"><?= (int)$total ?> order<?= $total === 1 ? '' : 's' ?><?= $total > 100 ? ', showing the latest 100' : '' ?>.</p>

  <?php if (!$rows): ?>
    <div class="empty">Nothing to send for this filter.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="tight" style="min-width:760px">
      <thead><tr><th>Order</th><th>Customer</th><th>Placed by</th><th>Tracking ID</th><th>Tracking link</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $has = ($r['tracking_id'] ?? '') !== '' || ($r['tracking_link'] ?? '') !== '';
          $wa = $has ? wa_link($r['customer_phone'], order_wa_tracking_text($r)) : '';
        ?>
        <tr>
          <td><a class="rowlink" href="order_view.php?id=<?= (int)$r['id'] ?>"><?= e(order_no($r['id'])) ?></a><div class="mini"><?= e(date('d M', strtotime($r['order_date']))) ?></div></td>
          <td><?= e($r['customer_name']) ?><div class="mini"><?= e($r['customer_phone']) ?></div><?php if ($r['items']): ?><div class="mini"><?= e($r['items']) ?></div><?php endif; ?></td>
          <td><?= e($r['sales_name']) ?><div class="mini"><?= $r['num_label'] ? e($r['num_label'] . ($r['num_phone'] ? ' - ' . $r['num_phone'] : '')) : 'No number' ?></div></td>
          <td>
            <form id="f-<?= (int)$r['id'] ?>" method="post" autocomplete="off">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <?php foreach ($keep as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
            </form>
            <input form="f-<?= (int)$r['id'] ?>" name="tracking_id" type="text" value="<?= e($r['tracking_id'] ?? '') ?>" placeholder="Tracking ID" style="min-width:110px;width:100%">
          </td>
          <td><input form="f-<?= (int)$r['id'] ?>" name="tracking_link" type="text" value="<?= e($r['tracking_link'] ?? '') ?>" placeholder="https://" style="min-width:140px;width:100%"></td>
          <td class="num">
            <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center">
              <button form="f-<?= (int)$r['id'] ?>" class="btn btn-ghost btn-sm" type="submit">Save</button>
              <?php if ($has): ?><a class="btn btn-accent btn-sm linkbtn" href="<?= e($wa) ?>" target="whatsapp_tab">WhatsApp</a><?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint">Type the tracking ID and link, click Save, then click WhatsApp to open the customer's chat with the message ready. Press Send in WhatsApp.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>