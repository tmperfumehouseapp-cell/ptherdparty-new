<?php
// Set all COD received to zero (manager only), so it can be entered again by hand. Everything removed is backed up and can be undone.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/cod_reset_lib.php';
$user = require_admin();
$pdo = db();

if (isset($_GET['csv'])) { // the removed COD money as a list (Excel opens it)
    try {
        $csv = cod_reset_csv($pdo, (int)$_GET['csv']);
    } catch (RuntimeException $ex) {
        http_response_code(404);
        exit($ex->getMessage());
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cod-received-before-reset-' . (int)$_GET['csv'] . '.csv"');
    echo $csv;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'reset') {
            if (strtoupper(trim((string)($_POST['confirm'] ?? ''))) !== 'ZERO') {
                throw new RuntimeException('Type ZERO in the box to confirm. Nothing was changed.');
            }
            $id = cod_reset_apply($pdo, $user);
            flash('ok', 'All COD received is now zero. A backup (#' . $id . ') was saved: you can download the list below, or undo the reset.');
        } elseif ($action === 'restore') {
            cod_reset_restore($pdo, $user, (int)($_POST['id'] ?? 0));
            flash('ok', 'The reset was undone: the COD money is back.');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('cod_reset.php');
}

$d = cod_reset_collect($pdo);
$logs = cod_reset_logs($pdo);
$pageTitle = 'Reset COD received';
$active = 'cod_amount';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<p style="margin:-8px 0 16px"><a href="cod_amount.php" class="mini" style="text-decoration:none">&larr; COD Amount</a></p>
<div class="grid">
  <section class="card wide">
    <h2>Set all COD received to zero</h2>
    <p style="margin:0 0 12px">Use this when you want to enter COD money again by hand: open each order, check that it is delivered and the COD has come, then save the COD amount on that order.</p>
    <div class="stats" style="margin-bottom:12px">
      <div class="stat"><div class="k">COD money on orders</div><div class="v"><?= e(inr($d['amount'])) ?></div><div class="mini"><?= (int)$d['n_receipts'] ?> receipt<?= $d['n_receipts'] === 1 ? '' : 's' ?> on <?= count(array_unique(array_column($d['cod_receipts'], 'order_id'))) ?> orders</div></div>
      <div class="stat"><div class="k">Lump remittances</div><div class="v"><?= e(inr($d['lump_amount'])) ?></div><div class="mini"><?= (int)$d['n_lump'] ?> entr<?= $d['n_lump'] === 1 ? 'y' : 'ies' ?> typed on the COD Amount page</div></div>
      <div class="stat"><div class="k">Dealer ledger lines</div><div class="v"><?= (int)$d['n_party'] ?></div><div class="mini">COD that came into a dealer's QR</div></div>
    </div>
    <?php if ($d['n_receipts'] === 0 && $d['n_ledger'] === 0): ?>
      <div class="empty">COD received is already zero.</div>
    <?php else: ?>
    <div class="form-err" role="alert" style="margin:0 0 12px"><b>What happens:</b> every COD receipt, every COD entry in your accounts (so the money in your accounts goes down by the same amount),
      the dealer ledger lines of that COD money, and the money held for vendors from it are removed together. Orders stay delivered. Prepaid money, advances, bills, payments and refunds are not touched.
      <?php if ($d['kept'] > 0): ?><br><b><?= (int)$d['kept'] ?> refunded order<?= $d['kept'] === 1 ? '' : 's' ?></b> keep their COD records, so their refunds stay correct.<?php endif; ?>
      <br>A backup is saved first. You can download the list and undo the reset.</div>
    <form method="post" onsubmit="return confirm('Set ALL COD received to zero now? A backup is saved first.');" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset">
      <div class="field" style="margin:0;flex:0 1 240px"><label for="confirm">Type ZERO to confirm</label><input id="confirm" name="confirm" type="text" autocomplete="off" placeholder="ZERO"></div>
      <button class="btn btn-accent" type="submit" style="background:#B91C1C;border-color:#B91C1C">Set COD received to zero</button>
    </form>
    <?php endif; ?>
  </section>

  <section class="card narrow">
    <h2>Resets so far</h2>
    <?php if (!$logs): ?>
      <div class="empty">No reset yet.</div>
    <?php else: foreach ($logs as $l): ?>
      <div style="padding:10px 0;border-top:1px solid var(--line,#E2E8F0)">
        <b>#<?= (int)$l['id'] ?></b> · <?= e(date('d M Y H:i', strtotime($l['created_at']))) ?><?= $l['name'] ? ' · ' . e($l['name']) : '' ?>
        <div class="mini"><?= (int)$l['receipts'] ?> receipts · <?= e(inr($l['amount'])) ?> removed<?= $l['restored_at'] ? ' · <b>undone ' . e(date('d M H:i', strtotime($l['restored_at']))) . '</b>' : '' ?></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
          <a class="btn btn-ghost btn-sm linkbtn" href="cod_reset.php?csv=<?= (int)$l['id'] ?>">Download list</a>
          <?php if (!$l['restored_at']): ?>
          <form method="post" style="margin:0" onsubmit="return confirm('Undo this reset and bring the COD money back?');">
            <?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit">Undo this reset</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; endif; ?>
    <div class="hint" style="margin-top:10px">Undo works only while COD received is still zero. Once you enter new COD money, delete it first or keep the new entries.</div>
  </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
