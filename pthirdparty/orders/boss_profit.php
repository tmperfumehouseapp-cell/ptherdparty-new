<?php
// Boss profit (manager only): how much profit you have earned, how much you passed on to your other company, and what is left with you.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/boss_profit_lib.php';
$user = require_admin();
$pdo = db();
boss_profit_ensure($pdo); // (outside any transaction)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            boss_profit_add($pdo, $user, (string)($_POST['company'] ?? ''), num($_POST['amount'] ?? 0), (string)($_POST['entry_date'] ?? ''), (string)($_POST['note'] ?? ''));
            flash('ok', 'Saved: profit given to ' . trim((string)$_POST['company']) . '.');
        } elseif ($action === 'edit') {
            boss_profit_edit($pdo, (int)($_POST['id'] ?? 0), (string)($_POST['company'] ?? ''), num($_POST['amount'] ?? 0), (string)($_POST['entry_date'] ?? ''), (string)($_POST['note'] ?? ''));
            flash('ok', 'Entry changed.');
        } elseif ($action === 'delete') {
            boss_profit_delete($pdo, (int)($_POST['id'] ?? 0));
            flash('ok', 'Entry deleted.');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('boss_profit.php');
}

$today = biz_today();
$sum = boss_profit_summary($pdo);
$entries = boss_profit_entries($pdo);
$given = round(array_sum(array_map(static fn($e) => (float)$e['amount'], $entries)), 2);
$left = round($sum['earned'] - $given, 2);
$givenByMonth = [];
$byCompany = [];
foreach ($entries as $e) {
    $m = substr((string)$e['entry_date'], 0, 7);
    $givenByMonth[$m] = ($givenByMonth[$m] ?? 0.0) + (float)$e['amount'];
    $byCompany[$e['company']] = ($byCompany[$e['company']] ?? 0.0) + (float)$e['amount'];
}
$allMonths = array_unique(array_merge(array_keys($sum['months']), array_keys($givenByMonth)));
rsort($allMonths);
$companies = array_keys($byCompany);
$lastCompany = $entries ? (string)$entries[0]['company'] : '';
$dateInput = 'style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"';

$pageTitle = 'Boss profit';
$active = 'boss_profit';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<style>
.ct{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:16px}
.ct .stat{min-width:0}
.ent{display:block;padding:10px 0;border-top:1px dashed var(--line);font-size:14px;font-weight:600}
.ent .top{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}
.ent .mini{display:block}
.ent form.inl{display:inline}
.bp-tbl{width:100%;border-collapse:collapse;font-size:14px}
.bp-tbl th,.bp-tbl td{padding:9px 6px;border-top:1px solid var(--line);text-align:right;font-variant-numeric:tabular-nums}
.bp-tbl th:first-child,.bp-tbl td:first-child{text-align:left}
</style>

<div class="ct">
  <div class="stat"><div class="k">Profit earned</div><div class="v pos"><?= e(inr($sum['earned'])) ?></div>
    <div class="mini">on delivered orders, after the dealers' share</div></div>
  <div class="stat"><div class="k">Given to your other company</div><div class="v"><?= e(inr($given)) ?></div>
    <div class="mini"><?= count($entries) ?> entr<?= count($entries) === 1 ? 'y' : 'ies' ?></div></div>
  <div class="stat"><div class="k">Profit left with you</div><div class="v" style="<?= $left < -0.004 ? 'color:#B42318' : ($left > 0.004 ? 'color:#B45309' : '') ?>"><?= e(inr($left)) ?></div>
    <div class="mini"><?= $left < -0.004 ? 'You gave more than the profit earned so far' : 'earned minus given' ?></div></div>
  <div class="stat"><div class="k">Profit still to come</div><div class="v"><?= e(inr($sum['coming'])) ?></div>
    <div class="mini">on orders not delivered yet</div></div>
</div>

<section class="card">
  <h2>Give profit to your other company</h2>
  <form method="post" autocomplete="off" id="bpform">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="field"><label for="entry_date">Date</label><input id="entry_date" name="entry_date" type="date" value="<?= e($today) ?>" <?= $dateInput ?>></div>
    <div class="field"><label for="amount">Amount (₹)</label><input id="amount" name="amount" type="text" inputmode="decimal" required></div>
    <div class="field"><label for="company">Given to (company name)</label><input id="company" name="company" type="text" list="bpcompanies" value="<?= e($lastCompany) ?>" required>
      <datalist id="bpcompanies"><?php foreach ($companies as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist></div>
    <div class="field"><label for="note">Note (optional)</label><input id="note" name="note" type="text" placeholder="for example: November profit, UPI ref ..."></div>
    <button class="btn btn-accent btn-block" type="submit">Save entry</button>
    <div class="hint">This is a record only. It does not move money in any account, QR or dealer figure.</div>
  </form>
</section>

<section class="card" style="margin-top:16px">
  <h2>Month by month</h2>
  <?php if (!$allMonths): ?><div class="empty">Nothing yet. Profit shows here once orders are delivered.</div><?php else: ?>
  <div style="overflow-x:auto"><table class="bp-tbl"><thead><tr><th>Month</th><th>Orders</th><th>Profit earned</th><th>Given</th><th>Difference</th></tr></thead><tbody>
    <?php foreach ($allMonths as $m): $ea = $sum['months'][$m]['earned'] ?? 0.0; $gv = $givenByMonth[$m] ?? 0.0; ?>
    <tr><td><?= e(date('M Y', strtotime($m . '-01'))) ?></td><td><?= (int)($sum['months'][$m]['orders'] ?? 0) ?></td><td><b><?= e(inr($ea)) ?></b></td><td><?= e(inr($gv)) ?></td><td><?= e(inr($ea - $gv)) ?></td></tr>
    <?php endforeach; ?>
    <tr><td><b>Total</b></td><td></td><td><b><?= e(inr($sum['earned'])) ?></b></td><td><b><?= e(inr($given)) ?></b></td><td><b><?= e(inr($left)) ?></b></td></tr>
  </tbody></table></div>
  <div class="hint">Profit is counted in the month the order was delivered.</div>
  <?php endif; ?>
</section>

<section class="card" style="margin-top:16px">
  <h2>What you gave</h2>
  <?php if (count($byCompany) > 1): ?><div class="mini" style="margin-bottom:8px"><?php foreach ($byCompany as $c => $a): ?><span style="margin-right:12px"><b><?= e($c) ?></b>: <?= e(inr($a)) ?></span><?php endforeach; ?></div><?php endif; ?>
  <?php if (!$entries): ?><div class="empty">No entry yet. Add the first one above.</div><?php endif; ?>
  <?php foreach ($entries as $en): ?>
  <div class="ent">
    <div class="top">
      <div><?= e(date('d M Y', strtotime($en['entry_date']))) ?> · <b><?= e($en['company']) ?></b><?= $en['note'] !== '' ? '<span class="mini">' . e($en['note']) . '</span>' : '' ?></div>
      <div style="display:flex;gap:6px;align-items:center"><b><?= e(inr($en['amount'])) ?></b>
        <button class="btn btn-ghost btn-sm" type="button" onclick="var f=document.getElementById('bpe<?= (int)$en['id'] ?>');f.style.display=f.style.display==='none'?'flex':'none'">Edit</button>
        <form method="post" class="inl" onsubmit="return confirm('Delete this entry of <?= e(inr($en['amount'])) ?>?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$en['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Delete</button></form></div>
    </div>
    <form method="post" id="bpe<?= (int)$en['id'] ?>" style="display:none;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int)$en['id'] ?>">
      <div class="field" style="margin:0"><label>Amount (₹)</label><input name="amount" type="text" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format((float)$en['amount'], 2, '.', ''), '0'), '.')) ?>" style="width:120px"></div>
      <div class="field" style="margin:0"><label>Date</label><input name="entry_date" type="date" value="<?= e($en['entry_date']) ?>"></div>
      <div class="field" style="margin:0"><label>Company</label><input name="company" type="text" value="<?= e($en['company']) ?>"></div>
      <div class="field" style="margin:0"><label>Note</label><input name="note" type="text" value="<?= e($en['note']) ?>"></div>
      <button class="btn btn-accent btn-sm" type="submit">Save</button></form>
  </div>
  <?php endforeach; ?>
</section>
<script>
(function () {
  var f = document.getElementById('bpform');
  f.addEventListener('submit', function () {
    var b = f.querySelector('button[type=submit]');
    if (b) { setTimeout(function () { b.disabled = true; }, 0); } // one click saves once
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
