<?php
// COD Amount: how much COD money you have not received yet, and how much you received on each date.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/simple_lib.php';
$user = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            add_remittance(
                $pdo, $user, (int)($_POST['account_id'] ?? 0), num($_POST['amount'] ?? 0),
                (string)($_POST['entry_date'] ?? ''), trim((string)($_POST['note'] ?? '')), []
            );
            flash('ok', 'COD received saved.');
        } elseif ($action === 'delete') {
            delete_remittance($pdo, (int)($_POST['id'] ?? 0));
            flash('ok', 'Entry deleted.');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('cod_amount.php');
}

$today = biz_today();
$from = valid_date((string)($_GET['from'] ?? '')) ?? '';
$to = valid_date((string)($_GET['to'] ?? '')) ?? '';
if ($from === '' && $to === '') {
    $from = date('Y-m-d', strtotime($today . ' -29 days'));
    $to = $today;
} elseif ($to === '') {
    $to = $today;
} elseif ($from === '') {
    $from = date('Y-m-d', strtotime($to . ' -29 days'));
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$pending = simple_cod_pending($pdo);
$days = simple_cod_by_date($pdo, $from, $to);
$received = 0.0;
foreach ($days as $d) {
    $received += (float)$d['amount'];
}
$first = $pdo->query("SELECT MIN(d) FROM (SELECT MIN(received_date) AS d FROM cod_receipts UNION ALL SELECT MIN(entry_date) FROM account_ledger WHERE entry_type = 'cod') x")->fetchColumn() ?: $today;

$accounts = $pdo->query('SELECT a.id, a.name, p.name AS owner FROM accounts a LEFT JOIN parties p ON p.id = a.party_id WHERE a.is_active = 1 ORDER BY (a.party_id IS NOT NULL), a.name')->fetchAll();
// money entered here by hand (not tied to one order): the only entries that can be deleted from this page
$manual = $pdo->query(
    "SELECT l.id, l.entry_date, l.amount, l.note, a.name AS account FROM account_ledger l JOIN accounts a ON a.id = l.account_id
     WHERE l.entry_type = 'cod' AND l.order_id IS NULL AND l.direction = 'in' ORDER BY l.entry_date DESC, l.id DESC LIMIT 20"
)->fetchAll();

$chip = static function (string $label, string $f, string $t) use ($from, $to): string {
    $on = ($f === $from && $t === $to);
    return '<a class="btn ' . ($on ? 'btn-accent' : 'btn-ghost') . ' btn-sm linkbtn" href="cod_amount.php?' . e(http_build_query(['from' => $f, 'to' => $t])) . '">' . e($label) . '</a>';
};
$yest = date('Y-m-d', strtotime($today . ' -1 day'));
$dateInput = 'style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"';

$pageTitle = 'COD Amount';
$active = 'cod_amount';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<style>
.ct{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:16px}
.ct .stat{min-width:0}
.cd{display:flex;flex-direction:column}
.cd .r{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px 0;border-top:1px solid var(--line);font-weight:700}
.cd .r:first-child{border-top:0}
.cd .r small{color:var(--muted);font-weight:700;font-size:12px;margin-left:6px}
.cd .r b{font-variant-numeric:tabular-nums}
.cd .tot{border-top:2px solid var(--line);font-weight:800}
.ent{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:9px 0;border-top:1px dashed var(--line);font-size:14px;font-weight:600}
.ent .mini{display:block}
.ent form{display:inline}
</style>

<div class="ct">
  <div class="stat"><div class="k">Pending COD amount</div><div class="v" style="<?= $pending['due'] > 0.004 ? 'color:#B45309' : '' ?>"><?= e(inr($pending['due'])) ?></div>
    <div class="mini"><?= ($pending['due'] > 0.004 && $pending['orders']) ? (int)$pending['orders'] . ' order' . ($pending['orders'] === 1 ? '' : 's') . ' waiting · ' : '' ?><a class="rowlink" href="orders.php?mode=cod_due">see which</a></div></div>
  <div class="stat"><div class="k">COD received</div><div class="v pos"><?= e(inr($received)) ?></div>
    <div class="mini"><?= e($from === $to ? date('d M Y', strtotime($from)) : date('d M', strtotime($from)) . ' to ' . date('d M Y', strtotime($to))) ?></div></div>
</div>

<section class="card">
  <h2>COD received, date by date</h2>
  <form method="get" class="filters">
    <div><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($from) ?>" <?= $dateInput ?>></div>
    <div><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($to) ?>" <?= $dateInput ?>></div>
    <button class="btn" type="submit">Show</button>
  </form>
  <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
    <?= $chip('Today', $today, $today) ?>
    <?= $chip('Yesterday', $yest, $yest) ?>
    <?= $chip('Last 7 days', date('Y-m-d', strtotime($today . ' -6 days')), $today) ?>
    <?= $chip('This month', date('Y-m-01', strtotime($today)), $today) ?>
    <?= $chip('All time', $first, $today) ?>
  </div>
  <?php if (!$days): ?>
    <div class="empty">No COD money received in these dates.</div>
  <?php else: ?>
  <div class="cd">
    <?php foreach ($days as $d): ?>
    <div class="r"><span><?= e(date('D, d M Y', strtotime($d['d']))) ?><small><?= (int)$d['n'] ?> entr<?= (int)$d['n'] === 1 ? 'y' : 'ies' ?></small></span><b class="pos"><?= e(inr($d['amount'])) ?></b></div>
    <?php endforeach; ?>
    <div class="r tot"><span>Total</span><b><?= e(inr($received)) ?></b></div>
  </div>
  <?php endif; ?>
</section>

<details class="card" style="margin-top:16px" id="add">
  <summary style="cursor:pointer;font-size:17px;font-weight:800">Add COD received</summary>
  <form method="post" autocomplete="off" id="codform" style="margin-top:14px">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="field"><label for="entry_date">Date received</label><input id="entry_date" name="entry_date" type="date" value="<?= e($today) ?>" <?= $dateInput ?>></div>
    <div class="field"><label for="amount">Amount received (₹)</label><input id="amount" name="amount" type="text" inputmode="decimal" required></div>
    <div class="field"><label for="account_id">Came into</label>
      <select id="account_id" name="account_id">
        <?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?><?= $a['owner'] ? ' (' . e($a['owner']) . ')' : '' ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="note">Note (optional)</label><input id="note" name="note" type="text" placeholder="Shiprocket remittance, UTR ..."></div>
    <button class="btn btn-accent btn-block" type="submit">Save COD received</button>
    <div class="hint">Use this for money that arrives in one lump, for example a courier remittance. Per-order COD entered on an order, or uploaded from Excel, is counted here too.</div>
  </form>
  <p style="margin:12px 0 0"><a class="btn btn-ghost btn-sm linkbtn" href="cod_upload.php">Upload COD from Excel</a></p>
</details>

<?php if ($manual): ?>
<details class="card" style="margin-top:16px">
  <summary style="cursor:pointer;font-size:15px;font-weight:800">Entries added by hand (last <?= count($manual) ?>)</summary>
  <div style="margin-top:8px">
    <?php foreach ($manual as $m): ?>
    <div class="ent">
      <div><?= e(date('d M Y', strtotime($m['entry_date']))) ?> · <b><?= e(inr($m['amount'])) ?></b><span class="mini"><?= e($m['account']) ?><?= $m['note'] ? ' · ' . e($m['note']) : '' ?></span></div>
      <form method="post" onsubmit="return confirm('Delete this entry?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Delete</button></form>
    </div>
    <?php endforeach; ?>
  </div>
</details>
<?php endif; ?>
<script>
(function () {
  var f = document.getElementById('codform');
  f.addEventListener('submit', function () {
    var b = f.querySelector('button[type=submit]');
    if (b) { setTimeout(function () { b.disabled = true; }, 0); } // one click saves once
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
