<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/orders_lib.php';
require __DIR__ . '/includes/ops_lib.php';
$user = require_admin();
$pdo = db();

require_once __DIR__ . '/includes/order_dealer_lib.php';
require_once __DIR__ . '/includes/cod_report_lib.php';
$accounts = $pdo->query('SELECT id, name FROM accounts WHERE is_active = 1 ORDER BY name')->fetchAll();
$codDefault = default_cod_account_id($pdo); // COD money normally comes into your own QR
$results = null;
$summary = null;
$colInfo = '';
$groups = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $accId = active_account_id($_POST['account_id'] ?? 0);
        if (!$accId) {
            throw new RuntimeException('Choose the account the money came into.');
        }
        if (empty($_FILES['sheet']) || $_FILES['sheet']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Choose an Excel (.xls, .xlsx) or CSV file to upload.');
        }
        $rows = read_sheet_rows($_FILES['sheet']['tmp_name'], (string)$_FILES['sheet']['name']);
        if (!$rows) {
            throw new RuntimeException('The file is empty.');
        }

        $res = cod_process_rows($pdo, $user, (int)$accId, $rows);
        $colInfo = $res['colInfo'];
        $results = $res['results'];
        $groups = $res['groups'];
        $okCount = $res['ok'];
        $okSum = $res['sum'];
        $notRemitted = $res['notRemitted'];
        $delivCount = $res['delivered'];
        $summary = ['ok' => $okCount, 'sum' => $okSum, 'bad' => count($results) - $okCount, 'notRemitted' => $notRemitted, 'delivered' => $delivCount];
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
        redirect('cod_upload.php');
    }
}

$pageTitle = 'Upload COD from Excel';
$active = 'orders';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<p style="margin:-8px 0 16px"><a href="orders.php" class="mini" style="text-decoration:none">&larr; All orders</a></p>
<div class="grid">
  <section class="card wide">
    <h2>Result</h2>
    <?php if ($results === null): ?>
      <div class="empty">Choose a file on the right. The result of every row shows here.</div>
    <?php elseif (!$results): ?>
      <div class="empty">No rows found in the file.</div>
    <?php else: ?>
      <?php if ($colInfo !== ''): ?><p class="hint" style="margin:0 0 10px">Columns read from your file: <?= e($colInfo) ?></p><?php endif; ?>
      <div class="stats" style="margin-bottom:14px">
        <div class="stat"><div class="k">Saved</div><div class="v pos"><?= (int)$summary['ok'] ?></div></div>
        <div class="stat"><div class="k">Money added</div><div class="v"><?= e(inr($summary['sum'])) ?></div></div>
        <div class="stat"><div class="k">Skipped</div><div class="v <?= $summary['bad'] ? 'neg' : '' ?>"><?= (int)$summary['bad'] ?></div></div>
        <?php if ($summary['delivered'] > 0): ?><div class="stat"><div class="k">Also marked delivered</div><div class="v"><?= (int)$summary['delivered'] ?></div></div><?php endif; ?>
        <?php if ($summary['notRemitted'] > 0): ?><div class="stat"><div class="k">Not remitted yet</div><div class="v"><?= (int)$summary['notRemitted'] ?></div><div class="mini">no COD money in the file for these</div></div><?php endif; ?>
      </div>
      <?php if (count($groups) > 1 || (count($groups) === 1 && key($groups) !== '-')): ?>
      <h3 style="margin:0 0 8px">By remittance (match these with your bank)</h3>
      <div class="table-wrap" style="margin-bottom:16px"><table class="tight" style="min-width:560px">
        <thead><tr><th>CRF ID</th><th>UTR</th><th>Date</th><th class="num">Parcels</th><th class="num">Saved</th><th class="num">Skipped</th><th class="num">Money in file</th><th class="num">Money saved</th></tr></thead>
        <tbody>
        <?php foreach ($groups as $g): ?>
          <tr><td><?= e($g['crf'] !== '' ? $g['crf'] : '-') ?></td><td><?= e($g['utr'] !== '' ? $g['utr'] : '-') ?></td><td><?= e(date('d M Y', strtotime($g['date']))) ?></td>
            <td class="num"><?= (int)$g['rows'] ?></td><td class="num"><?= (int)$g['saved'] ?></td><td class="num"><?= $g['skipped'] ? '<span class="tag tag-bad">' . (int)$g['skipped'] . '</span>' : '0' ?></td>
            <td class="num"><?= e(inr($g['in_file'])) ?></td><td class="num" style="font-weight:800"><?= e(inr($g['saved_amt'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <h3 style="margin:0 0 8px">Every row</h3>
      <?php endif; ?>
      <div class="table-wrap"><table class="tight" style="min-width:480px">
        <thead><tr><th>Row</th><th>AWB / order ID</th><th class="num">Amount</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
          <tr><td><?= (int)$r['row'] ?></td><td><?= e($r['ref']) ?></td><td class="num"><?= e(inr($r['amount'])) ?></td>
            <td><span class="tag <?= $r['ok'] ? 'tag-ok' : 'tag-bad' ?>"><?= $r['ok'] ? 'Saved' : 'Skipped' ?></span> <span class="mini"><?= e($r['msg']) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </section>

  <section class="card narrow">
    <h2>Upload COD money</h2>
    <form method="post" enctype="multipart/form-data" autocomplete="off">
      <?= csrf_field() ?>
      <div class="field"><label for="account_id">Money came into</label>
        <select id="account_id" name="account_id" required>
          <?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$a['id'] === $codDefault ? ' selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label for="sheet">Excel (.xls, .xlsx) or CSV file</label><input id="sheet" name="sheet" type="file" accept=".xls,.xlsx,.csv" required style="width:100%"></div>
      <button class="btn btn-accent btn-block" type="submit">Upload and save</button>
    </form>
    <div class="sheet-note" style="margin-top:14px">
      Upload <b>Shiprocket's COD report exactly as you download it</b> (.xls, .xlsx or .csv), no editing. Columns are found by their names:
      <b>AWB</b> and <b>Order Id</b> (to find the order), <b>Order Value</b> / <b>Remitted Amount</b> (the COD money), <b>Remittance Date</b>, <b>CRF ID</b>, <b>UTR</b>, <b>Delivered Date</b>.
      The COD <i>charge</i> column ("COD Payble Amount") is never used as money. Without a header row: A order number or tracking ID, B amount, C date.
      Rows for orders that are not COD, already fully received, or more than pending are skipped and explained. A parcel still showing created / dispatched is marked delivered when its COD money is saved.
      Uploading the same report again is safe: money already saved is not counted twice.
    </div>
  </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>