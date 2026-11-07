<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/vendor_lib.php';
require_once __DIR__ . '/includes/ops_lib.php';
$user = require_login();
$pdo = db();

// salespeople only work from orders and commission, so they never land on the admin Home
if ($user['role'] !== 'admin') {
    redirect('new_order.php');
}

$accountsTotal = (float)$pdo->query(
    "SELECT COALESCE(SUM(a.opening_balance),0) + COALESCE((SELECT SUM(CASE WHEN l.direction='in' THEN l.amount ELSE -l.amount END) FROM account_ledger l JOIN accounts x ON x.id = l.account_id WHERE x.party_id IS NULL),0)
     FROM accounts a WHERE a.party_id IS NULL"
)->fetchColumn();
$partiesPayable = (float)$pdo->query(
    "SELECT COALESCE(SUM(CASE WHEN entry_type IN ('payment','collected') THEN -amount ELSE amount END),0) FROM party_ledger"
)->fetchColumn();
$partyCount = (int)$pdo->query('SELECT COUNT(*) FROM parties WHERE is_active = 1')->fetchColumn();
$salesCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'salesperson' AND is_active = 1")->fetchColumn();
// Business day: the whole panel counts a "day" from the time set here (default 00:00 = normal midnight day).
// Example: 14:00 means day 27 Oct runs from 27 Oct 2:00 PM to 28 Oct 2:00 PM.
$dayMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'day_start') {
    csrf_verify();
    $new = ($_POST['do'] ?? '') === 'reset' ? '00:00' : trim((string)($_POST['day_start'] ?? ''));
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $new)) {
        flash('error', 'Choose a valid time.');
    } else {
        [$nh, $nm] = array_map('intval', explode(':', $new));
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO settings (k, v) VALUES ('day_start', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$new]);
            // re-file every order under the new business day
            $pdo->prepare('UPDATE orders SET order_date = DATE(DATE_SUB(created_at, INTERVAL ? MINUTE))')->execute([$nh * 60 + $nm]);
            $pdo->commit();
            flash('ok', $new === '00:00' ? 'Day now runs midnight to midnight.' : 'Day now starts at ' . date('g:i A', strtotime($new)) . '. All orders, commission and counts follow it.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Could not save. Run the page again.');
        }
    }
    redirect('index.php');
}

$bizToday = biz_today();
$dayStart = day_start();
[$bizFrom, $bizTo] = biz_range($bizToday);
$timeSet = ($dayStart !== '00:00');
$timeLabel = $timeSet
    ? date('j M g:i A', strtotime($bizFrom)) . ' → ' . date('j M g:i A', strtotime($bizTo) + 1)
    : 'Today';
$st = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(total), 0) FROM orders WHERE order_date = ?');
$st->execute([$bizToday]);
[$todayOrders, $todayValue] = $st->fetch(PDO::FETCH_NUM);
$todayOrders = (int)$todayOrders;
$todayValue = (float)$todayValue;
$timeErr = '';
$codDue = (float)$pdo->query(
    "SELECT COALESCE(SUM(o.total - o.advance_amount - COALESCE((SELECT SUM(c.amount) FROM cod_receipts c WHERE c.order_id = o.id), 0)), 0)
     FROM orders o WHERE o.payment_mode = 'cod' AND o.status <> 'refunded'"
)->fetchColumn();
// lump COD remittances (not tied to one order) reduce what is still to come
$codDue -= (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM account_ledger WHERE entry_type = 'cod' AND order_id IS NULL AND direction = 'in'")->fetchColumn();
$codDue = max(0.0, $codDue);

// ---- Prepaid & COD section: one business day, split by how the customer pays ----
$pcDay = valid_date((string)($_GET['pd'] ?? '')) ?? $bizToday;
$pcTab = in_array(($_GET['pc'] ?? ''), ['prepaid', 'cod'], true) ? $_GET['pc'] : 'all';
$pcSum = static function (PDO $pdo, string $day, string $mode): array {
    $st = $pdo->prepare(
        "SELECT COUNT(*) AS c, COALESCE(SUM(o.total), 0) AS t, COALESCE(SUM(o.advance_amount), 0) AS adv,
                COALESCE(SUM(COALESCE((SELECT SUM(r.amount) FROM cod_receipts r WHERE r.order_id = o.id), 0)), 0) AS got,
                COALESCE(SUM(GREATEST(0, o.total - o.advance_amount - COALESCE((SELECT SUM(r.amount) FROM cod_receipts r WHERE r.order_id = o.id), 0))), 0) AS pend
         FROM orders o WHERE o.order_date = ? AND o.payment_mode = ? AND o.status <> 'refunded'"
    );
    $st->execute([$day, $mode]);
    $row = $st->fetch();
    $st = $pdo->prepare(
        "SELECT a.name, a.party_id, SUM(l.amount) AS amt
         FROM account_ledger l JOIN orders o ON o.id = l.order_id JOIN accounts a ON a.id = l.account_id
         WHERE o.order_date = ? AND o.payment_mode = ? AND o.status <> 'refunded' AND l.entry_type = 'prepaid' AND l.direction = 'in'
         GROUP BY a.id, a.name, a.party_id ORDER BY amt DESC"
    );
    $st->execute([$day, $mode]);
    $row['accounts'] = $st->fetchAll();
    return $row;
};
$pcPre = $pcSum($pdo, $pcDay, 'prepaid');
$pcCod = $pcSum($pdo, $pcDay, 'cod');
[$pcFrom, $pcTo] = biz_range($pcDay);
$pcSpan = ($dayStart === '00:00')
    ? date('j M Y', strtotime($pcDay))
    : date('j M g:i A', strtotime($pcFrom)) . ' → ' . date('j M g:i A', strtotime($pcTo) + 1);
$pcLink = static function (string $tab) use ($pcDay): string {
    return 'index.php?pc=' . $tab . '&pd=' . urlencode($pcDay);
};

$pageTitle = 'Home';
$active = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<style>
.time-btn{margin-top:8px;padding:6px 12px;border:1px solid var(--field-line,#CBD5E1);border-radius:999px;background:#fff;font-family:inherit;font-weight:700;font-size:12px;color:#0F172A;cursor:pointer}
.time-btn.on{background:#0F172A;color:#fff;border-color:#0F172A}
.time-pop{position:absolute;top:100%;left:-40px;margin-top:8px;width:300px;max-width:calc(100vw - 48px);background:#fff;border:1px solid #E2E8F0;border-radius:16px;box-shadow:0 18px 40px rgba(15,23,42,.18);padding:16px;z-index:20}
.time-pop[hidden]{display:none}
.time-pop h4{margin:0 0 10px;font-size:14px}
.time-g{display:grid;grid-template-columns:1fr 1fr;gap:10px}.time-g .field{margin:0}.time-g input{width:100%;box-sizing:border-box}
.time-row{display:flex;gap:8px;margin-top:12px}.time-row .btn{flex:1;height:42px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none}
@media(max-width:700px){.time-pop{left:0}}
</style>
<div class="stats">
  <div class="stat"><div class="k">Money across accounts</div><div class="v"><?= e(inr($accountsTotal)) ?></div></div>
  <div class="stat"><div class="k">You owe dealers</div><div class="v"><?= e(inr($partiesPayable)) ?></div><div class="hint" style="margin:2px 0 0"><a href="dealers.php">Dealers</a></div></div>
  <div class="stat" style="position:relative">
    <div class="k">Orders · <?= e($timeLabel) ?></div><div class="v"><?= $todayOrders ?></div><div class="hint" style="margin:2px 0 0"><?= e(inr($todayValue)) ?></div>
    <button type="button" id="time-btn" class="time-btn<?= $timeSet ? ' on' : '' ?>"><?= $timeSet ? 'Change day start' : 'Set day start' ?></button>
    <?php if ($timeErr): ?><div class="hint" style="margin:6px 0 0;color:#B91C1C"><?= e($timeErr) ?></div><?php endif; ?>
    <form id="time-pop" class="time-pop" method="post" action="index.php" hidden>
      <?= csrf_field() ?><input type="hidden" name="action" value="day_start">
      <h4>Day starts at</h4>
      <div class="field" style="margin:0"><label for="day_start">Start time</label><input id="day_start" name="day_start" type="time" value="<?= e($dayStart) ?>" style="width:100%;box-sizing:border-box"></div>
      <div class="hint" style="margin:8px 0 0">Your day runs from this time to the same time the next day. Example: 2:00 PM means day 27 = 27 Oct 2:00 PM → 28 Oct 2:00 PM. This changes the whole panel: orders, commission, order counts and default dates.</div>
      <div class="time-row"><button type="submit" name="do" value="reset" class="btn btn-ghost">Reset</button><button type="submit" name="do" value="save" class="btn btn-accent">Apply</button></div>
    </form>
  </div>
  <div class="stat"><div class="k">COD money pending</div><div class="v"><?= e(inr($codDue)) ?></div><div class="hint" style="margin:2px 0 0"><a href="cod_amount.php">COD Amount</a></div></div>
  <div class="stat"><div class="k">Active dealers</div><div class="v"><?= $partyCount ?></div></div>
  <div class="stat"><div class="k">Salespeople</div><div class="v"><?= $salesCount ?></div></div>
</div>
<section class="pc-wrap">
  <div class="pc-bar">
    <div class="pc-seg" role="tablist" aria-label="Payment type">
      <a href="<?= e($pcLink('all')) ?>" class="<?= $pcTab === 'all' ? 'on' : '' ?>">All</a>
      <a href="<?= e($pcLink('prepaid')) ?>" class="<?= $pcTab === 'prepaid' ? 'on' : '' ?>">Prepaid</a>
      <a href="<?= e($pcLink('cod')) ?>" class="<?= $pcTab === 'cod' ? 'on' : '' ?>">COD</a>
    </div>
    <form method="get" action="index.php" class="pc-day">
      <input type="hidden" name="pc" value="<?= e($pcTab) ?>">
      <div class="field" style="margin:0"><label for="pd">Day</label><input id="pd" name="pd" type="date" value="<?= e($pcDay) ?>" onchange="this.form.submit()"></div>
    </form>
    <div class="hint" style="margin:0"><?= e($pcSpan) ?></div>
  </div>
  <div class="pc-two">
    <?php if ($pcTab !== 'cod'): ?>
    <details class="pc-box" open>
      <summary><div><div class="pc-t">Prepaid</div><div class="pc-big"><?= e(inr((float)$pcPre['t'])) ?></div><div class="pc-s"><?= (int)$pcPre['c'] ?> order<?= (int)$pcPre['c'] === 1 ? '' : 's' ?> · full amount received</div></div><span class="pc-chev" aria-hidden="true"></span></summary>
      <div class="pc-body">
        <div class="pc-sub">Received in account</div>
        <?php if (!$pcPre['accounts']): ?><div class="pc-r"><span>No prepaid orders</span><b>-</b></div><?php endif; ?>
        <?php foreach ($pcPre['accounts'] as $ac): ?>
          <div class="pc-r"><span><?= e($ac['name']) ?><?= $ac['party_id'] !== null ? ' <small>(vendor)</small>' : '' ?></span><b><?= e(inr((float)$ac['amt'])) ?></b></div>
        <?php endforeach; ?>
      </div>
    </details>
    <?php endif; ?>
    <?php if ($pcTab !== 'prepaid'): ?>
    <details class="pc-box" open>
      <summary><div><div class="pc-t">COD</div><div class="pc-big"><?= e(inr((float)$pcCod['t'])) ?></div><div class="pc-s"><?= (int)$pcCod['c'] ?> order<?= (int)$pcCod['c'] === 1 ? '' : 's' ?> · order value</div></div><span class="pc-chev" aria-hidden="true"></span></summary>
      <div class="pc-body">
        <div class="pc-r"><span>Advance received now</span><b><?= e(inr((float)$pcCod['adv'])) ?></b></div>
        <div class="pc-sub">Advance came into</div>
        <?php if (!$pcCod['accounts']): ?><div class="pc-r"><span>No COD orders</span><b>-</b></div><?php endif; ?>
        <?php foreach ($pcCod['accounts'] as $ac): ?>
          <div class="pc-r"><span><?= e($ac['name']) ?><?= $ac['party_id'] !== null ? ' <small>(vendor)</small>' : '' ?></span><b><?= e(inr((float)$ac['amt'])) ?></b></div>
        <?php endforeach; ?>
        <div class="pc-sub">Balance on delivery</div>
        <div class="pc-r"><span>To collect</span><b><?= e(inr((float)$pcCod['t'] - (float)$pcCod['adv'])) ?></b></div>
        <div class="pc-r"><span>Collected so far</span><b><?= e(inr((float)$pcCod['got'])) ?></b></div>
        <div class="pc-r"><span>Still pending</span><b><span class="tag <?= (float)$pcCod['pend'] > 0.004 ? 'tag-warn' : 'tag-ok' ?>"><?= e(inr((float)$pcCod['pend'])) ?></span></b></div>
      </div>
    </details>
    <?php endif; ?>
  </div>
</section>
<style>
.pc-wrap{margin:0 0 16px}
.pc-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:0 0 12px}
.pc-seg{display:flex;background:#E5E7EB;border-radius:12px;padding:4px}
.pc-seg a{padding:8px 16px;border-radius:9px;font-weight:700;font-size:13px;color:#475569;text-decoration:none}
.pc-seg a.on{background:#0F172A;color:#fff}
.pc-day input{height:40px;border-radius:10px;border:1px solid var(--field-line,#CBD5E1);background:#fff;padding:0 10px}
.pc-two{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}
.pc-box{background:#fff;border:1px solid #E5E7EB;border-radius:18px;overflow:hidden}
.pc-box summary{list-style:none;cursor:pointer;display:flex;justify-content:space-between;align-items:center;padding:16px 18px}
.pc-box summary::-webkit-details-marker{display:none}
.pc-t{font-weight:800;font-size:15px}.pc-big{font-size:26px;font-weight:800}.pc-s{font-size:12px;color:#64748B;margin-top:2px}
.pc-chev{width:10px;height:10px;border-right:2px solid #64748B;border-bottom:2px solid #64748B;transform:rotate(-135deg);margin-right:4px;transition:transform .15s}
.pc-box:not([open]) .pc-chev{transform:rotate(45deg)}
.pc-body{border-top:1px solid #EEF0F3;padding:6px 18px 14px}
.pc-r{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid #F1F3F5;font-size:14px}
.pc-r:last-child{border:0}.pc-r b{font-weight:800}.pc-r small{color:#64748B}
.pc-sub{font-size:11px;font-weight:800;color:#6B7280;text-transform:uppercase;letter-spacing:.06em;margin:10px 0 2px}
@media(max-width:800px){.pc-two{grid-template-columns:1fr}}
</style>
<div class="card">
  <h2>Quick links</h2>
  <p style="margin:0;display:flex;flex-wrap:wrap;gap:10px">
    <a class="btn btn-accent" style="display:inline-flex;align-items:center;text-decoration:none" href="new_order.php">New order</a>
    <a class="btn btn-ghost" style="display:inline-flex;align-items:center;text-decoration:none" href="orders.php">Orders</a>
    <a class="btn btn-ghost" style="display:inline-flex;align-items:center;text-decoration:none" href="products.php">Products</a>
    <a class="btn btn-ghost" style="display:inline-flex;align-items:center;text-decoration:none" href="groups.php">Groups</a>
  </p>
</div>
<script>
(function () {
  var b = document.getElementById('time-btn'), p = document.getElementById('time-pop');
  if (!b || !p) return;
  b.addEventListener('click', function (e) { e.stopPropagation(); p.hidden = !p.hidden; });
  p.addEventListener('click', function (e) { e.stopPropagation(); });
  document.addEventListener('click', function () { p.hidden = true; });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') p.hidden = true; });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>