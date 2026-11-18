<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ops_lib.php';
require __DIR__ . '/includes/buying_lib.php';
require_once __DIR__ . '/includes/orders_lib.php';
require_once __DIR__ . '/includes/simple_lib.php';
require_once __DIR__ . '/includes/order_check_lib.php';
$user = require_login();
$pdo = db();
$isAdmin = $user['role'] === 'admin';

$status = (string)($_GET['status'] ?? '');
$mode = (string)($_GET['mode'] ?? '');
if (!in_array($mode, ['', 'prepaid', 'cod', 'cod_due', 'buying'], true)) {
    $mode = '';
}
// inside Prepaid Orders: all / only fully prepaid / only partial prepaid
$kind = in_array((string)($_GET['kind'] ?? ''), ['full', 'partial'], true) ? (string)$_GET['kind'] : '';
if ($mode !== 'prepaid') {
    $kind = '';
}
$from = valid_date((string)($_GET['from'] ?? '')) ?? '';
$to = valid_date((string)($_GET['to'] ?? '')) ?? '';
$q = trim((string)($_GET['q'] ?? ''));
$sales = $isAdmin ? (int)($_GET['sales'] ?? 0) : 0;
$num = (int)($_GET['num'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 50;

// daily check (manager): Verify an order, then mark its dealer Bill done
$chkReady = $isAdmin && order_check_ready($pdo);
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'check') {
    csrf_verify();
    $ckOk = true;
    try {
        $ckMsg = order_check_apply($pdo, (int)($_POST['id'] ?? 0), (string)($_POST['act'] ?? ''), (int)$user['id']);
    } catch (RuntimeException $ex) {
        $ckOk = false;
        $ckMsg = $ex->getMessage();
    }
    if ((string)($_POST['ajax'] ?? '') === '1') { // clicked on the page: update the row in place, no reload, no jump to the top
        $ckId = (int)($_POST['id'] ?? 0);
        $cs = $pdo->prepare('SELECT verified_at, billed_at FROM orders WHERE id = ?');
        $cs->execute([$ckId]);
        $crow = $cs->fetch() ?: ['verified_at' => null, 'billed_at' => null];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'   => $ckOk,
            'msg'  => $ckMsg,
            'v'    => $crow['verified_at'] !== null ? 1 : 0,
            'b'    => $crow['billed_at'] !== null ? 1 : 0,
            'html' => order_check_cell_html($ckId, $crow['verified_at'], $crow['billed_at'], (string)($_POST['qs'] ?? '')),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash($ckOk ? 'ok' : 'error', $ckMsg);
    parse_str((string)($_POST['qs'] ?? ''), $back);
    redirect('orders.php' . ($back ? '?' . http_build_query($back) : ''));
}
$chk = (string)($_GET['chk'] ?? '');
if (!$chkReady || !in_array($chk, ['unverified', 'verified', 'bill_pending', 'billed'], true)) {
    $chk = '';
}

if ($isAdmin && $mode === 'buying') {
    $bv = buying_prepare($pdo);
    $pageTitle = 'Orders';
    $active = 'orders';
    $extraCss = 'assets/pages.css';
    require __DIR__ . '/includes/header.php';
    echo orders_tabs($mode, $isAdmin);
    buying_body($bv);
    require __DIR__ . '/includes/footer.php';
    exit;
}

$where = [];
$params = [];
if (!$isAdmin) {
    $where[] = 'o.salesperson_id = ?';
    $params[] = (int)$user['id'];
} elseif ($sales > 0) {
    $where[] = 'o.salesperson_id = ?';
    $params[] = $sales;
}
if (in_array($status, ['created', 'dispatched', 'delivered', 'return_received', 'refunded'], true)) {
    $where[] = 'o.status = ?';
    $params[] = $status;
}
if ($chk === 'unverified') {
    $where[] = 'o.verified_at IS NULL';
} elseif ($chk === 'verified') {
    $where[] = 'o.verified_at IS NOT NULL';
} elseif ($chk === 'bill_pending') {
    $where[] = 'o.verified_at IS NOT NULL AND o.billed_at IS NULL';
} elseif ($chk === 'billed') {
    $where[] = 'o.billed_at IS NOT NULL';
}
// Prepaid Orders = money taken at order time: fully prepaid orders, and COD orders that carried an advance.
// COD Orders = the COD part only (total minus advance). A partial prepaid order is in both, but each tab counts only its own part.
// Refunded orders are left out of these three tabs.
if ($mode === 'prepaid') {
    $where[] = "((o.payment_mode = 'prepaid' OR o.advance_amount > 0.004) AND o.status <> 'refunded')";
}
if ($mode === 'cod') {
    $where[] = "(o.payment_mode = 'cod' AND o.total - o.advance_amount > 0.004 AND o.status <> 'refunded')";
}
if ($mode === 'cod_due') {
    $where[] = "(o.payment_mode = 'cod' AND o.status <> 'refunded' AND o.total - o.advance_amount - COALESCE((SELECT SUM(c.amount) FROM cod_receipts c WHERE c.order_id = o.id), 0) > 0.004)";
}
if ($num > 0) {
    $where[] = 'o.number_id = ?';
    $params[] = $num;
}
if ($from !== '') {
    $where[] = 'o.order_date >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $where[] = 'o.order_date <= ?';
    $params[] = $to;
}
if ($q !== '') {
    $hasSr = orders_has_sr_id($pdo);
    $where[] = '(o.customer_name LIKE ? OR o.customer_phone LIKE ? OR o.tracking_id LIKE ? OR o.id = ?' . ($hasSr ? ' OR o.shiprocket_order_id LIKE ?' : '') . ')';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, (int)preg_replace('/\D+/', '', $q));
    if ($hasSr) {
        $params[] = $like;
    }
}
$wTiles = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$paramsTiles = $params;
if ($kind === 'full') {
    $where[] = "o.payment_mode = 'prepaid'";
} elseif ($kind === 'partial') {
    $where[] = "o.payment_mode = 'cod'";
}
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$sum = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(o.total),0) AS t, COALESCE(SUM(o.profit),0) AS p FROM orders o' . $w);
$sum->execute($params);
$tot = $sum->fetch();

// money split of the orders in the current tab (Manager only). Prepaid part + COD part = order value, so nothing is counted twice.
$codExpr = "CASE WHEN o.payment_mode = 'cod' THEN o.total - o.advance_amount ELSE 0 END";
$preExpr = "CASE WHEN o.payment_mode = 'prepaid' THEN o.total ELSE o.advance_amount END";
$recExpr = '(SELECT COALESCE(SUM(c.amount), 0) FROM cod_receipts c WHERE c.order_id = o.id)';
$agg = null;
$lump = 0.0;
if ($isAdmin) {
    $q2 = $pdo->prepare(
        "SELECT SUM(o.payment_mode = 'prepaid') AS n_full, SUM(o.payment_mode = 'cod' AND o.advance_amount > 0.004) AS n_part, SUM(o.payment_mode = 'cod' AND o.advance_amount <= 0.004) AS n_cod,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'prepaid' THEN o.total END), 0) AS pre_full,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'cod' THEN o.advance_amount END), 0) AS pre_part,
                COALESCE(SUM($codExpr), 0) AS cod_total,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'cod' AND o.advance_amount <= 0.004 THEN o.total END), 0) AS cod_full,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'cod' AND o.advance_amount > 0.004 THEN o.total - o.advance_amount END), 0) AS cod_part,
                COALESCE(SUM(LEAST($codExpr, $recExpr)), 0) AS cod_recv
         FROM orders o" . $wTiles
    );
    $q2->execute($paramsTiles);
    $agg = $q2->fetch();
    $agg['cod_pending'] = max(0.0, round((float)$agg['cod_total'] - (float)$agg['cod_recv'], 2));
    // COD money that arrived as one lump without an order number: not part of the per-order numbers
    $lump = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM account_ledger WHERE entry_type = 'cod' AND order_id IS NULL AND direction = 'in'")->fetchColumn();
}
$cnt = $pdo->prepare("SELECT SUM(o.payment_mode = 'prepaid') AS a, SUM(o.payment_mode = 'cod' AND o.advance_amount > 0.004) AS b, SUM(o.payment_mode = 'cod' AND o.advance_amount <= 0.004) AS c FROM orders o" . $w);
$cnt->execute($params);
$kinds = $cnt->fetch();

// buying cost per dealer (party) for the orders shown: pieces x the buying price saved on each order item. Manager only, All tab.
$buyRows = [];
if ($isAdmin && $mode === '') {
    try {
        $bq = $pdo->prepare(
            "SELECT COALESCE(pa.name, 'Dealer not set') AS dealer, COALESCE(SUM(oi.qty), 0) AS pcs, COALESCE(SUM(oi.qty * oi.base_price), 0) AS buy
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             LEFT JOIN parties pa ON pa.id = oi.party_id" . $w . "
             GROUP BY oi.party_id, pa.name
             ORDER BY buy DESC"
        );
        $bq->execute($params);
        $buyRows = $bq->fetchAll();
    } catch (Throwable $e) {
        $buyRows = [];
    }
}
$pages = max(1, (int)ceil($tot['c'] / $per));
$page = min($page, $pages);

$st = $pdo->prepare(
    'SELECT o.id, o.order_date, o.customer_name, o.customer_phone, o.payment_mode, o.total, o.advance_amount, o.profit, o.status, o.tracking_id,
            u.name AS sales_name, n.label AS num_label,' . ($isAdmin && orders_has_wa($pdo) ? ' o.wa_enforced, o.wa_status,' : '') . ($chkReady ? ' o.verified_at, o.billed_at,' : '') . '
            COALESCE((SELECT SUM(c.amount) FROM cod_receipts c WHERE c.order_id = o.id), 0) AS cod_received,
            (' . $preExpr . ') AS prepaid_amt, (' . $codExpr . ') AS cod_amt
     FROM orders o JOIN users u ON u.id = o.salesperson_id LEFT JOIN order_numbers n ON n.id = o.number_id' . $w .
    ' ORDER BY o.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per)
);
$st->execute($params);
$rows = $st->fetchAll();

$salesList = $isAdmin ? $pdo->query("SELECT id, name FROM users ORDER BY name")->fetchAll() : [];
$numList = $pdo->query('SELECT id, label, phone FROM order_numbers ORDER BY is_active DESC, label')->fetchAll();

$qsArr = array_filter(['status' => $status, 'mode' => $mode, 'kind' => $kind, 'from' => $from, 'to' => $to, 'q' => $q, 'sales' => $sales ?: '', 'num' => $num ?: '']);
$qs = http_build_query($qsArr);

$pageTitle = 'Orders';
$active = 'orders';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<?= orders_tabs($mode, $isAdmin) ?>
<?php if ($mode === 'prepaid'): ?>
<div class="kchips">
  <?php foreach (['' => 'All prepaid', 'full' => 'Fully prepaid', 'partial' => 'Partial prepaid'] as $kk => $kl):
      $kq = $qsArr; unset($kq['kind'], $kq['page']); if ($kk !== '') { $kq['kind'] = $kk; } ?>
    <a class="btn <?= $kind === $kk ? 'btn-accent' : 'btn-ghost' ?> btn-sm linkbtn" href="orders.php?<?= e(http_build_query($kq)) ?>"><?= e($kl) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<style>
.otiles{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));margin-bottom:16px}
.otiles .stat{min-width:0;padding:16px 18px;border-radius:18px}
.otiles .stat .v{font-size:24px;word-break:break-word}
.kchips{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}
@media (max-width:520px){.otiles{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.otiles .stat .v{font-size:19px}}
</style>
<?php
$tile = static function (string $k, string $v, string $sub = '', string $style = ''): string {
    return '<div class="stat"><div class="k">' . e($k) . '</div><div class="v"' . ($style !== '' ? ' style="' . e($style) . '"' : '') . '>' . $v . '</div>'
        . ($sub !== '' ? '<div class="mini">' . $sub . '</div>' : '') . '</div>';
};
$plural = static fn(int $n, string $w): string => $n . ' ' . $w . ($n === 1 ? '' : 's');
?>
<div class="otiles">
<?php if (!$isAdmin): ?>
  <?= $tile('Orders shown', (string)(int)$tot['c']) ?>
<?php elseif ($mode === 'prepaid'): ?>
  <?= $tile('Fully prepaid orders', (string)(int)$agg['n_full'], 'paid the whole amount') ?>
  <?= $tile('Partial prepaid orders', (string)(int)$agg['n_part'], 'advance paid, rest is COD') ?>
  <?= $tile('Prepaid received · fully prepaid', e(inr($agg['pre_full']))) ?>
  <?= $tile('Prepaid received · partial prepaid', e(inr($agg['pre_part'])), 'advances only') ?>
  <?= $tile('Total prepaid received', e(inr((float)$agg['pre_full'] + (float)$agg['pre_part'])), 'all prepaid money', 'color:var(--good)') ?>
<?php elseif ($mode === 'cod'): ?>
  <?= $tile('Total COD amount', e(inr($agg['cod_total'])), $plural((int)$agg['n_cod'] + (int)$agg['n_part'], 'order') . ' · COD part only') ?>
  <?= $tile('From fully COD orders', e(inr($agg['cod_full'])), $plural((int)$agg['n_cod'], 'order')) ?>
  <?= $tile('From partial prepaid orders', e(inr($agg['cod_part'])), $plural((int)$agg['n_part'], 'order')) ?>
  <?= $tile('COD received', e(inr($agg['cod_recv'])), 'recorded so far', 'color:var(--good)') ?>
  <?= $tile('COD still pending', e(inr($agg['cod_pending'])), '', $agg['cod_pending'] > 0.004 ? 'color:#B45309' : '') ?>
<?php elseif ($mode === 'cod_due'): ?>
  <?= $tile('COD pending money', e(inr($agg['cod_pending'])), $plural((int)$tot['c'], 'order') . ' waiting', $agg['cod_pending'] > 0.004 ? 'color:#B45309' : '') ?>
  <?= $tile('COD amount of these orders', e(inr($agg['cod_total']))) ?>
  <?= $tile('Already received on them', e(inr($agg['cod_recv'])), '', 'color:var(--good)') ?>
<?php else: ?>
  <?= $tile('Orders shown', (string)(int)$tot['c'], e((int)$kinds['a'] . ' fully prepaid · ' . (int)$kinds['b'] . ' partial prepaid · ' . (int)$kinds['c'] . ' fully COD')) ?>
  <?= $tile('Order value', e(inr($tot['t']))) ?>
  <?= $tile('Prepaid part', e(inr((float)$agg['pre_full'] + (float)$agg['pre_part'])), 'money taken at order time') ?>
  <?= $tile('COD part', e(inr($agg['cod_total'])), 'to collect on delivery') ?>
  <?= $tile('Profit', e(inr($tot['p']))) ?>
<?php endif; ?>
</div>
<?php if ($isAdmin && $mode === '' && $buyRows): ?>
<?php
$buyTotal = 0.0;
$buyPcs = 0;
foreach ($buyRows as $br) {
    $buyTotal += (float)$br['buy'];
    $buyPcs += (int)$br['pcs'];
}
$buyDay = $from !== '' && $to !== '' && $from === $to ? date('d M Y', strtotime($from))
    : ($from !== '' || $to !== '' ? ($from !== '' ? date('d M Y', strtotime($from)) : 'start') . ' to ' . ($to !== '' ? date('d M Y', strtotime($to)) : 'today') : 'all dates');
?>
<h3 style="margin:4px 0 10px;font-size:16px">Buying cost by dealer <span class="mini" style="font-weight:600">· <?= e($buyDay) ?></span></h3>
<div class="otiles">
  <?php foreach ($buyRows as $br): ?>
    <?= $tile($br['dealer'] . ' buying', e(inr($br['buy'])), e($plural((int)$br['pcs'], 'piece')), 'color:#0F766E') ?>
  <?php endforeach; ?>
  <?= $tile('Total buying', e(inr($buyTotal)), e($plural($buyPcs, 'piece')), 'color:#0F766E') ?>
</div>
<p class="hint" style="margin:-4px 0 12px">Buying = pieces x the buying price saved on each order, grouped by the dealer set on the product. It follows the date, status and salesperson filters above.</p>
<?php endif; ?>
<?php if ($isAdmin && $mode === 'cod_due' && $lump > 0.004): ?>
<p class="hint" style="margin:-4px 0 12px"><?= e(inr($lump)) ?> of COD money came as one lump without an order number. It is not in these per-order numbers. The COD Amount page takes it off the total.</p>
<?php endif; ?>
<?php if ($isAdmin && $mode === ''): ?>
<p class="hint" style="margin:-4px 0 12px">Prepaid part + COD part = order value. Refunded orders are counted here but left out of the Prepaid, COD and COD Pending tabs.</p>
<?php endif; ?>
<?php if ($isAdmin && in_array($mode, ['prepaid', 'cod', 'cod_due'], true)): ?>
<p class="hint" style="margin:-4px 0 12px">A partial prepaid order shows in both Prepaid and COD, but each tab counts only its own part. Refunded orders are left out.</p>
<?php endif; ?>

<?php if ($chkReady): ?>
<?php
$ck = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(o.verified_at IS NOT NULL), 0) AS v, COALESCE(SUM(o.billed_at IS NOT NULL), 0) AS b FROM orders o' . $w);
$ck->execute($params);
$ckr = $ck->fetch();
?>
<div class="kchips" style="align-items:center">
  <span class="tag tag-ok">Verified <b data-k="v"><?= (int)$ckr['v'] ?></b> of <?= (int)$ckr['c'] ?></span>
  <span class="tag <?= (int)$ckr['c'] - (int)$ckr['v'] > 0 ? 'tag-warn' : 'tag-ok' ?>" data-w="nv">Not verified <b data-k="nv"><?= (int)$ckr['c'] - (int)$ckr['v'] ?></b></span>
  <span class="tag tag-info">Bill done <b data-k="b"><?= (int)$ckr['b'] ?></b></span>
  <span class="tag <?= (int)$ckr['v'] - (int)$ckr['b'] > 0 ? 'tag-warn' : 'tag-ok' ?>" data-w="p">Verified, bill pending <b data-k="p"><?= max(0, (int)$ckr['v'] - (int)$ckr['b']) ?></b></span>
</div>
<?php endif; ?>
<section class="card">
  <form method="get" class="filters">
    <div><label for="q">Search</label><input id="q" name="q" type="text" value="<?= e($q) ?>" placeholder="Name, phone, tracking ID or order no."></div>
    <div><label for="status">Status</label>
      <select id="status" name="status">
        <option value="">All</option>
        <?php foreach (['created', 'dispatched', 'delivered', 'return_received', 'refunded'] as $s): ?>
          <option value="<?= e($s) ?>"<?= $status === $s ? ' selected' : '' ?>><?= e(status_label($s)) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label for="mode">Payment</label>
      <select id="mode" name="mode">
        <option value="">All orders</option>
        <option value="prepaid"<?= $mode === 'prepaid' ? ' selected' : '' ?>>Prepaid orders</option>
        <option value="cod"<?= $mode === 'cod' ? ' selected' : '' ?>>COD orders</option>
        <option value="cod_due"<?= $mode === 'cod_due' ? ' selected' : '' ?>>COD pending money</option>
      </select></div>
    <div><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($from) ?>" style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"></div>
    <div><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($to) ?>" style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"></div>
    <?php if ($isAdmin): ?>
    <div><label for="sales">Salesperson</label>
      <select id="sales" name="sales"><option value="0">All</option>
        <?php foreach ($salesList as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $sales === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <div><label for="num">Order placed by number</label>
      <select id="num" name="num"><option value="0">All numbers</option>
        <?php foreach ($numList as $n): ?><option value="<?= (int)$n['id'] ?>"<?= $num === (int)$n['id'] ? ' selected' : '' ?>><?= e($n['label'] . ($n['phone'] ? ' - ' . $n['phone'] : '')) ?></option><?php endforeach; ?>
      </select></div>
    <?php if ($chkReady): ?>
    <div><label for="chk">Check</label>
      <select id="chk" name="chk">
        <option value="">All</option>
        <option value="unverified"<?= $chk === 'unverified' ? ' selected' : '' ?>>Not verified</option>
        <option value="verified"<?= $chk === 'verified' ? ' selected' : '' ?>>Verified</option>
        <option value="bill_pending"<?= $chk === 'bill_pending' ? ' selected' : '' ?>>Verified, bill pending</option>
        <option value="billed"<?= $chk === 'billed' ? ' selected' : '' ?>>Bill done</option>
      </select></div>
    <?php endif; ?>
    <?php if ($kind !== ''): ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><?php endif; ?>
    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-ghost linkbtn" href="orders.php<?= $mode !== '' ? '?mode=' . e($mode) : '' ?>">Clear</a>
    <?php if ($isAdmin): ?><a class="btn btn-ghost linkbtn" href="delivered_upload.php">Upload delivered CSV</a>
    <a class="btn btn-ghost linkbtn" href="cod_upload.php">Upload COD from Excel</a><?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">No orders match. <a href="new_order.php">Create an order</a>.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="tight" style="min-width:<?= $mode === "" ? 760 : 900 ?>px">
      <thead><tr><th>#</th><th>Date</th><th>Customer</th><th>Payment</th><th class="num">Total</th><?php if ($mode === 'prepaid'): ?><th class="num">Prepaid received</th><?php elseif ($mode === 'cod' || $mode === 'cod_due'): ?><th class="num">COD amount</th><th class="num">COD pending</th><?php endif; ?><th>Status</th><?php if ($isAdmin): ?><th>By</th><?php endif; ?><?php if ($chkReady): ?><th>Check</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $isCod = $r['payment_mode'] === 'cod';
          $adv = (float)$r['advance_amount'];
          $codAmt = $isCod ? round((float)$r['total'] - $adv, 2) : 0.0;
          $due = $isCod ? round($codAmt - (float)$r['cod_received'], 2) : 0.0;
          $refunded = $r['status'] === 'refunded';
          $partial = $isCod && $adv > 0.004;
        ?>
        <tr>
          <td><a class="rowlink" href="order_view.php?id=<?= (int)$r['id'] ?>"><?= e(order_no($r['id'])) ?></a></td>
          <td><?= e(date('d M', strtotime($r['order_date']))) ?></td>
          <td><?= e($r['customer_name']) ?><div class="mini"><?= e($r['customer_phone']) ?><?= $r['tracking_id'] ? ' · ' . e($r['tracking_id']) : '' ?><?= $r['num_label'] ? ' · ' . e($r['num_label']) : '' ?></div></td>
          <td>
            <?php if (!$isCod): ?>
              <span class="tag tag-ok">Fully prepaid</span>
            <?php elseif ($partial): ?>
              <span class="tag tag-info">Partial prepaid</span>
              <div class="mini">Prepaid <?= e(inr($adv)) ?> · COD <?= e(inr($codAmt)) ?><?= $refunded ? '' : ($due > 0.004 ? ' · ' . e(inr($due)) . ' pending' : ' · received') ?></div>
            <?php else: ?>
              <span class="tag <?= $due > 0.004 && !$refunded ? 'tag-warn' : 'tag-ok' ?>">COD</span>
              <div class="mini">COD <?= e(inr($codAmt)) ?><?= $refunded ? '' : ($due > 0.004 ? ' · ' . e(inr($due)) . ' pending' : ' · received') ?></div>
            <?php endif; ?>
          </td>
          <td class="num"><?= e(inr($r['total'])) ?></td>
          <?php if ($mode === 'prepaid'): ?><td class="num"><?= e(inr($r['prepaid_amt'])) ?></td>
          <?php elseif ($mode === 'cod' || $mode === 'cod_due'): ?><td class="num"><?= e(inr($codAmt)) ?></td><td class="num"><?= $refunded || $due <= 0.004 ? '-' : '<span class="tag tag-warn">' . e(inr($due)) . '</span>' ?></td>
          <?php endif; ?>
          <td><span class="tag <?= e(status_tag_class($r['status'])) ?>"><?= e(status_label($r['status'])) ?></span><?= $isAdmin ? wa_badge($r) : '' ?></td>
          <?php if ($isAdmin): ?><td><?= e($r['sales_name']) ?></td><?php endif; ?>
          <?php if ($chkReady): ?>
          <td class="chk-cell" style="white-space:nowrap" data-v="<?= $r['verified_at'] === null ? 0 : 1 ?>" data-b="<?= $r['billed_at'] === null ? 0 : 1 ?>"><?= order_check_cell_html((int)$r['id'], $r['verified_at'], $r['billed_at'], (string)($_SERVER['QUERY_STRING'] ?? '')) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="pager">
      <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm linkbtn" href="orders.php?<?= e($qs . ($qs ? '&' : '') . 'page=' . ($page - 1)) ?>">Newer</a><?php endif; ?>
      <span class="mini" style="align-self:center">Page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm linkbtn" href="orders.php?<?= e($qs . ($qs ? '&' : '') . 'page=' . ($page + 1)) ?>">Older</a><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</section>
<?php if ($chkReady): ?>
<script>
// Verify / Bill done / Undo save in the background and change only that row, so the page never reloads or jumps to the top.
(function () {
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form || !form.classList || !form.classList.contains('chk-form')) { return; }
    var btn = ev.submitter;
    if (!btn || !btn.value || !window.fetch) { return; } // old browser: normal submit still works
    ev.preventDefault();
    var cell = form.closest('.chk-cell');
    var fd = new FormData(form);
    fd.set('act', btn.value);
    fd.set('ajax', '1');
    var buttons = form.querySelectorAll('button');
    buttons.forEach(function (b) { b.disabled = true; });
    fetch('orders.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { buttons.forEach(function (b) { b.disabled = false; }); window.alert(d.msg || 'Could not save.'); return; }
        var v1 = parseInt(cell.getAttribute('data-v'), 10) || 0, b1 = parseInt(cell.getAttribute('data-b'), 10) || 0;
        cell.innerHTML = d.html;
        cell.setAttribute('data-v', d.v); cell.setAttribute('data-b', d.b);
        var delta = { v: d.v - v1, nv: -(d.v - v1), b: d.b - b1, p: (d.v - d.b) - (v1 - b1) };
        Object.keys(delta).forEach(function (k) {
          var n = document.querySelector('[data-k="' + k + '"]');
          if (!n) { return; }
          var val = Math.max(0, (parseInt(n.textContent, 10) || 0) + delta[k]);
          n.textContent = val;
          var w = n.closest('[data-w]');
          if (w) { w.className = 'tag ' + (val > 0 ? 'tag-warn' : 'tag-ok'); }
        });
      })
      .catch(function () {
        buttons.forEach(function (b) { b.disabled = false; });
        window.alert('Could not save. Check your internet and try again.');
      });
  });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>