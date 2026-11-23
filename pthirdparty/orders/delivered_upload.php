<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/orders_lib.php';
require __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/delivered_lib.php';
require_once __DIR__ . '/includes/upload_ui_lib.php';
$user = require_admin();
$pdo = db();

$results = null;
$colInfo = '';
$summary = null;
$withoutSr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        if (empty($_FILES['sheet']) || $_FILES['sheet']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Choose an Excel (.xls, .xlsx) or CSV file to upload.');
        }
        $rows = read_sheet_rows($_FILES['sheet']['tmp_name'], (string)$_FILES['sheet']['name']);
        if (!$rows) {
            throw new RuntimeException('The file is empty.');
        }

        $res = dl_process_rows($pdo, $rows);
        $colInfo = $res['colInfo'];
        $results = $res['results'];
        $summary = $res['counts'] + ['firstOrder' => $res['firstOrder']];
        $withoutSr = dl_orders_without_sr($pdo, 'undelivered');
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
        redirect('delivered_upload.php');
    }
}

$pageTitle = 'Upload delivered orders';
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
    <?php elseif (!$results && !$summary['before']): ?>
      <div class="empty">No rows found in the file.</div>
    <?php else: ?>
      <?php if ($colInfo !== ''): ?><p class="hint" style="margin:0 0 10px">Columns read from your file: <?= e($colInfo) ?></p><?php endif; ?>
      <div class="stats" style="margin-bottom:14px">
        <div class="stat"><div class="k">Marked delivered</div><div class="v pos"><?= (int)$summary['ok'] ?></div><div class="mini">matched and tagged delivered now</div></div>
        <div class="stat"><div class="k">Already delivered</div><div class="v"><?= (int)$summary['already'] ?></div><div class="mini">matched, nothing to change</div></div>
        <div class="stat"><div class="k">Unmatched</div><div class="v <?= $summary['unmatched'] ? 'neg' : '' ?>"><?= (int)$summary['unmatched'] ?></div><div class="mini">no order has this Shiprocket Order ID / AWB</div></div>
        <?php if ($summary['status'] > 0): ?><div class="stat"><div class="k">Not delivered yet</div><div class="v"><?= (int)$summary['status'] ?></div><div class="mini">the report status is not DELIVERED</div></div><?php endif; ?>
        <?php if ($summary['other'] > 0): ?><div class="stat"><div class="k">Returned / refunded</div><div class="v"><?= (int)$summary['other'] ?></div><div class="mini">left as they are</div></div><?php endif; ?>
        <?php if ($summary['before'] > 0): ?><div class="stat"><div class="k">From before the panel</div><div class="v"><?= (int)$summary['before'] ?></div><div class="mini">delivered before your first order (<?= e(date('d M Y', strtotime($summary['firstOrder']))) ?>)</div></div><?php endif; ?>
      </div>
      <?= ui_unmatched_table($results, false) ?>
      <details<?= count($results) <= 40 ? ' open' : '' ?>><summary class="btn btn-ghost btn-sm" style="display:inline-flex;cursor:pointer;margin-bottom:10px">Every row (<?= count($results) ?>)</summary>
      <div class="table-wrap"><table class="tight" style="min-width:480px">
        <thead><tr><th>Row</th><th>Shiprocket Order ID</th><th>AWB</th><th>Result</th></tr></thead>
        <tbody>
        <?php $tagFor = ['ok' => ['tag-ok', 'Delivered'], 'already' => ['tag-info', 'Already'], 'unmatched' => ['tag-bad', 'Unmatched'], 'status' => ['tag-warn', 'Not delivered'], 'other' => ['tag-warn', 'Left']]; ?>
        <?php foreach ($results as $r): [$tc, $tl] = $tagFor[$r['kind']] ?? ['tag-bad', 'Skipped']; ?>
          <tr><td><?= (int)$r['row'] ?></td><td><?= e($r['srid'] !== '' ? $r['srid'] : '-') ?></td><td><?= e($r['awb'] !== '' ? $r['awb'] : '-') ?></td>
            <td><span class="tag <?= e($tc) ?>"><?= e($tl) ?></span> <span class="mini"><?= e($r['msg']) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div></details>
      <p class="hint">Next: open <a href="dealers.php">Dealers</a> to see what you owe each dealer.</p>
    <?php endif; ?>
  </section>

  <section class="card narrow">
    <h2>Upload delivered list</h2>
    <form method="post" enctype="multipart/form-data" autocomplete="off">
      <?= csrf_field() ?>
      <div class="field"><label for="sheet">Excel (.xls, .xlsx) or CSV file</label><input id="sheet" name="sheet" type="file" accept=".xls,.xlsx,.csv" required style="width:100%"></div>
      <button class="btn btn-accent btn-block" type="submit">Upload and mark delivered</button>
    </form>
    <div class="sheet-note" style="margin-top:14px">
      Upload the <b>Shiprocket report exactly as you download it</b> (Excel or CSV), no editing. The columns are found by their names: <b>Order ID</b>, <b>AWB Code</b>, <b>Status</b>, <b>Order Delivered Date</b>.
      The <b>Shiprocket Order ID</b> you typed on New order is the main key; the <b>AWB</b> is the second. Only rows whose Status says <b>DELIVERED</b> are marked delivered; NEW ORDER, IN TRANSIT, CANCELED, RTO and the rest are left as they are.
      A file with no Status column counts every row as delivered. Without a header row: A order number, B tracking ID (either way round works), C status, D date.
      Orders already delivered, returned or refunded are skipped. A tracking ID is saved on the order if it has none. The result below shows the columns it read and how each row matched.
    </div>
  </section>
</div>
<?= ui_without_sr_card($withoutSr, 'Orders with no Shiprocket Order ID saved', 'These orders are still created or dispatched and cannot be matched by any report. Open the order and type its Shiprocket Order ID.') ?>
<?php require __DIR__ . '/includes/footer.php'; ?>