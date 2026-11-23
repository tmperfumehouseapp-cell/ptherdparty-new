<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/orders_lib.php';
require __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/delivered_lib.php';
require_once __DIR__ . '/includes/upload_ui_lib.php';
$user = require_admin();
$pdo = db();

// "Use this order": write the report's Shiprocket Order ID on the chosen order and mark it delivered (answers JSON, the page does not reload)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'link') {
    csrf_verify();
    $ok = true;
    try {
        $msg = in_tx($pdo, static fn() => dl_link_order($pdo, (int)($_POST['order_id'] ?? 0), (string)($_POST['srid'] ?? ''), (string)($_POST['awb'] ?? ''), valid_date((string)($_POST['delivered'] ?? ''))));
    } catch (RuntimeException $ex) {
        $ok = false;
        $msg = $ex->getMessage();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$res = null;
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
    <?php if ($res === null): ?>
      <div class="empty">Drop the whole Shiprocket report on the right. You will see at once how many parcels are delivered, which ones are not found in the panel, and which orders have no Shiprocket Order ID.</div>
    <?php else:
        $c = $res['counts'];
        $delivered = $c['ok'] + $c['already'];
        $total = $delivered + $c['unmatched'] + $c['status'] + $c['other'];
    ?>
      <?= ui_sentence('Your report has ' . $total . ' parcel' . ($total === 1 ? '' : 's') . ': ' . $delivered . ' delivered ✓ · ' . $c['unmatched'] . ' not found in the panel ✗ · ' . $c['status'] . ' not delivered yet'
          . ($c['before'] > 0 ? '. (' . $c['before'] . ' older parcels from before your first order are ignored.)' : '.')) ?>
      <?= ui_big_numbers([
          ['✅', 'Delivered', $delivered, $c['ok'] . ' marked delivered now · ' . $c['already'] . ' were already delivered', 'good'],
          ['❌', 'Not found in the panel', $c['unmatched'], 'their Shiprocket Order ID is not on any order', $c['unmatched'] ? 'bad' : ''],
          ['⏳', 'Not delivered yet', $c['status'], 'in transit, new, cancelled, RTO...', ''],
          ['📝', 'Orders with no Shiprocket Order ID', $withoutSr === null ? 0 : $withoutSr['count'], 'created / dispatched orders, listed below', ($withoutSr !== null && $withoutSr['count']) ? 'warn' : ''],
      ] + ($c['other'] > 0 ? [4 => ['↩', 'Returned / refunded', $c['other'], 'left as they are', '']] : [])) ?>
      <?= ui_unmatched_table($res['results'], 'delivered') ?>
      <details<?= count($res['results']) <= 25 ? ' open' : '' ?>><summary class="btn btn-ghost btn-sm" style="display:inline-flex;cursor:pointer;margin-bottom:10px">Every row of the report (<?= count($res['results']) ?>)</summary>
      <div class="table-wrap"><table class="tight" style="min-width:480px">
        <thead><tr><th>Row</th><th>Shiprocket Order ID</th><th>AWB</th><th>Result</th></tr></thead>
        <tbody>
        <?php $tagFor = ['ok' => ['tag-ok', 'Delivered now'], 'already' => ['tag-ok', 'Delivered'], 'unmatched' => ['tag-bad', 'Not found'], 'status' => ['tag-warn', 'Not delivered'], 'other' => ['tag-warn', 'Left']]; ?>
        <?php foreach ($res['results'] as $r): [$tc, $tl] = $tagFor[$r['kind']] ?? ['tag-bad', 'Skipped']; ?>
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
      <div class="field"><label for="sheet">Shiprocket report (.xls, .xlsx or .csv)</label><input id="sheet" name="sheet" type="file" accept=".xls,.xlsx,.csv" required style="width:100%"></div>
      <button class="btn btn-accent btn-block" type="submit">Upload and mark delivered</button>
    </form>
    <div class="sheet-note" style="margin-top:14px">
      <b>How it works</b>
      <ol style="margin:6px 0 0 18px;padding:0">
        <li>Upload the report <b>exactly as Shiprocket gives it</b>. No editing.</li>
        <li>The <b>Shiprocket Order ID</b> (the one you type on New order) is the key. The AWB is the second key. The panel's own numbers like #0081 are only a reference and are not used.</li>
        <li>Rows that say <b>DELIVERED</b> get the delivered tag. Parcels already delivered stay delivered, that is fine. NEW ORDER, IN TRANSIT, CANCELED, RTO are left alone.</li>
        <li>A parcel that is <b>not found</b> is listed in red, with the orders it may belong to. Press <b>Use this order</b> to fix it in one click.</li>
        <li>It is safe to upload the same report again: nothing is counted twice.</li>
      </ol>
    </div>
  </section>
</div>
<?= ui_without_sr_card($withoutSr, 'Orders with no Shiprocket Order ID', 'These orders are still created or dispatched and no report can match them. Open the order and type its Shiprocket Order ID.') ?>
<?= ui_link_script() ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
