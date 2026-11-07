<?php
// Dealers: every dealer and how much you owe them right now. Nothing else.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/simple_lib.php';
$user = require_admin();
$pdo = db();

$rows = array_values(array_filter(simple_dealer_rows($pdo), static fn($r) => $r['is_active'] || abs((float)$r['balance']) > 0.004));
$total = 0.0;
foreach ($rows as $r) {
    $total += max(0.0, (float)$r['balance']);
}

$pageTitle = 'Dealers';
$active = 'dealers';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<style>
.dl{display:flex;flex-direction:column;gap:10px}
.dl a.row{display:flex;justify-content:space-between;align-items:center;gap:12px;background:var(--card);border:1px solid var(--line);border-radius:18px;padding:16px 18px;text-decoration:none;color:var(--ink)}
.dl a.row:hover{border-color:#CBD5E1}
.dl .nm{font-weight:800;font-size:16px}
.dl .amt{font-weight:800;font-size:20px;font-variant-numeric:tabular-nums;text-align:right}
.dl .amt small{display:block;font-size:12px;color:var(--muted);font-weight:700}
</style>
<div class="stats">
  <div class="stat"><div class="k">Total outstanding</div><div class="v"><?= e(inr($total)) ?></div><div class="hint" style="margin:2px 0 0">what you owe all dealers</div></div>
</div>

<?php if (!$rows): ?>
  <section class="card"><div class="empty">No dealers yet. Use "Manage dealers" to add one.</div></section>
<?php else: ?>
<div class="dl">
  <?php foreach ($rows as $r): $b = (float)$r['balance']; ?>
  <a class="row" href="party_ledger.php?id=<?= (int)$r['id'] ?>">
    <span class="nm"><?= e($r['name']) ?><?php if (!$r['is_active']): ?> <span class="tag tag-off">Off</span><?php endif; ?></span>
    <span class="amt <?= $b > 0.004 ? '' : 'pos' ?>"><?php if ($b > 0.004): ?><?= e(inr($b)) ?><small>outstanding</small><?php elseif ($b < -0.004): ?><?= e(inr(-$b)) ?><small>advance with dealer</small><?php else: ?>₹0<small>settled</small><?php endif; ?></span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<p class="hint" style="margin-top:16px">Tap a dealer to see their bills and payments, and to record a payment you made. Outstanding = bills, less payments, less customer money that came straight into the dealer's own QR.</p>
<p style="margin:12px 0 0"><a class="btn btn-ghost linkbtn" href="parties.php">Manage dealers</a></p>
<p class="hint" style="margin-top:6px">Add a dealer, set a group link, or turn on Shiprocket auto-ship.</p>
<?php require __DIR__ . '/includes/footer.php'; ?>
