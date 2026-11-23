<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/orders_lib.php';
require __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/delivered_lib.php';
$user = require_admin();
$pdo = db();

$results = null;
$colInfo = '';
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        if (empty($_FILES['sheet']) || $_FILES['sheet']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Choose an Excel (.xlsx) or CSV file to upload.');
        }
        $rows = read_sheet_rows($_FILES['sheet']['tmp_name'], (string)$_FILES['sheet']['name']);
        if (!$rows) {
            throw new RuntimeException('The file is empty.');
        }

        $cols = dl_detect_columns($rows);
        $colInfo = $cols['names'];
        $hasSr = orders_has_sr_id($pdo);
        $results = [];
        $done = 0;
        for ($i = $cols['start']; $i < count($rows); $i++) {
            $r = $rows[$i];
            $cands = [];
            foreach (array_merge($cols['track'], $cols['order']) as $c) { // tracking columns first, then every order-ID column
                $cands[] = (string)($r[$c] ?? '');
            }
            $track = '';
            foreach ($cols['track'] as $c) {
                if (dl_norm((string)($r[$c] ?? '')) !== '') {
                    $track = dl_norm((string)$r[$c]);
                    break;
                }
            }
            $shown = '';
            foreach ($cands as $c) {
                if (dl_norm($c) !== '') {
                    $shown = dl_norm($c);
                    break;
                }
            }
            if ($shown === '') {
                continue;
            }
            $statusText = $cols['status'] !== null ? trim((string)($r[$cols['status']] ?? '')) : '';
            $date = $cols['date'] !== null ? sheet_date((string)($r[$cols['date']] ?? '')) : null;
            $line = ['row' => $i + 1, 'ref' => $shown, 'ok' => false, 'msg' => ''];

            // with a Status column only "Delivered" rows count; without one every row of the list is delivered
            $why = dl_skip_reason($cols['status'], $statusText);
            if ($why !== '') {
                $line['msg'] = $why;
                $results[] = $line;
                continue;
            }

            $found = dl_find_order($pdo, $cands, $hasSr);
            if (!$found) {
                $line['msg'] = 'No order with this tracking ID, Shiprocket order ID or order number.';
            } else {
                [$o, $by] = $found;
                if (!in_array($o['status'], ['created', 'dispatched'], true)) {
                    $line['msg'] = order_no($o['id']) . ' is already ' . strtolower(status_label($o['status'])) . '.';
                } else {
                    mark_delivered_on($pdo, (int)$o['id'], $date, $track !== '' ? $track : null);
                    $line['ok'] = true;
                    $line['msg'] = order_no($o['id']) . ' marked delivered (matched by ' . $by . ').';
                    $done++;
                }
            }
            $results[] = $line;
        }
        $summary = ['ok' => $done, 'bad' => count($results) - $done];
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
    <?php elseif (!$results): ?>
      <div class="empty">No rows found in the file.</div>
    <?php else: ?>
      <?php if ($colInfo !== ''): ?><p class="hint" style="margin:0 0 10px">Columns read from your file: <?= e($colInfo) ?></p><?php endif; ?>
      <div class="stats" style="margin-bottom:14px">
        <div class="stat"><div class="k">Marked delivered</div><div class="v pos"><?= (int)$summary['ok'] ?></div></div>
        <div class="stat"><div class="k">Skipped</div><div class="v <?= $summary['bad'] ? 'neg' : '' ?>"><?= (int)$summary['bad'] ?></div></div>
      </div>
      <div class="table-wrap"><table class="tight" style="min-width:480px">
        <thead><tr><th>Row</th><th>Order / tracking ID</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
          <tr><td><?= (int)$r['row'] ?></td><td><?= e($r['ref']) ?></td>
            <td><span class="tag <?= $r['ok'] ? 'tag-ok' : 'tag-bad' ?>"><?= $r['ok'] ? 'Delivered' : 'Skipped' ?></span> <span class="mini"><?= e($r['msg']) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="hint">Next: open <a href="dealers.php">Dealers</a> to see what you owe each dealer.</p>
    <?php endif; ?>
  </section>

  <section class="card narrow">
    <h2>Upload delivered list</h2>
    <form method="post" enctype="multipart/form-data" autocomplete="off">
      <?= csrf_field() ?>
      <div class="field"><label for="sheet">Excel (.xlsx) or CSV file</label><input id="sheet" name="sheet" type="file" accept=".xlsx,.csv" required style="width:100%"></div>
      <button class="btn btn-accent btn-block" type="submit">Upload and mark delivered</button>
    </form>
    <div class="sheet-note" style="margin-top:14px">
      Upload the <b>Shiprocket report exactly as you download it</b> (Excel or CSV), no editing. The columns are found by their names: <b>Order ID</b>, <b>AWB Code</b>, <b>Status</b>, <b>Order Delivered Date</b>.
      Each row is matched by its <b>AWB</b>, its <b>Shiprocket Order ID</b>, or our <b>order number</b>. Only rows whose Status says <b>DELIVERED</b> are marked delivered; NEW ORDER, IN TRANSIT, CANCELED, RTO and the rest are left as they are.
      A file with no Status column counts every row as delivered. Without a header row: A order number, B tracking ID (either way round works), C status, D date.
      Orders already delivered, returned or refunded are skipped. A tracking ID is saved on the order if it has none. The result below shows the columns it read and how each row matched.
    </div>
  </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>