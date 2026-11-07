<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/orders_lib.php';
require __DIR__ . '/includes/ops_lib.php';
$user = require_admin();
$pdo = db();

$results = null;
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

        // columns by header name; without a header: A = order number, B = tracking ID, C = status, D = date
        $colOrder = 0;
        $colTrack = 1;
        $colStatus = null;
        $colDate = null;
        $start = 0;
        $head = array_map('strtolower', $rows[0]);
        $hasHead = false;
        $orderSet = false;
        $trackSet = false;
        foreach ($head as $i => $h) {
            if (preg_match('/awb|tracking|waybill/', $h)) {
                $colTrack = $i;
                $trackSet = true;
                $hasHead = true;
            } elseif (preg_match('/order/', $h) && !$orderSet) {
                $colOrder = $i;
                $orderSet = true;
                $hasHead = true;
            } elseif (preg_match('/status/', $h)) {
                $colStatus = $i;
                $hasHead = true;
            } elseif (preg_match('/date|delivered/', $h) && $colDate === null) {
                $colDate = $i;
                $hasHead = true;
            }
        }
        if ($hasHead) {
            $start = 1;
            if ($trackSet && !$orderSet) {
                $colOrder = $colTrack; // only a tracking column: look orders up by it
            }
        } else {
            $colStatus = 2;
            $colDate = 3;
        }

        $byTrack = $pdo->prepare('SELECT id, status FROM orders WHERE tracking_id = ? LIMIT 1');
        $byId = $pdo->prepare('SELECT id, status FROM orders WHERE id = ?');
        $results = [];
        $done = 0;
        for ($i = $start; $i < count($rows); $i++) {
            $r = $rows[$i];
            $ref = trim((string)($r[$colOrder] ?? ''));
            $track = trim((string)($r[$colTrack] ?? ''));
            if ($ref === '' && $track === '') {
                continue;
            }
            $statusText = $colStatus !== null ? trim((string)($r[$colStatus] ?? '')) : '';
            $date = $colDate !== null ? sheet_date((string)($r[$colDate] ?? '')) : null;
            $line = ['row' => $i + 1, 'ref' => $ref !== '' ? $ref : $track, 'ok' => false, 'msg' => ''];

            // only rows that say delivered count; a sheet with no status column is a delivered list
            if ($statusText !== '' && !preg_match('/^\s*delivered\b/i', $statusText)) {
                $line['msg'] = 'Status is "' . $statusText . '", not delivered.';
                $results[] = $line;
                continue;
            }

            $o = null;
            foreach ([$track, $ref] as $t) {
                if ($t === '') {
                    continue;
                }
                $byTrack->execute([$t]);
                $o = $byTrack->fetch() ?: null;
                if ($o) {
                    break;
                }
            }
            if (!$o) {
                $digits = (int)preg_replace('/\D+/', '', $ref);
                if ($digits > 0 && strlen(preg_replace('/\D+/', '', $ref)) <= 9) {
                    $byId->execute([$digits]);
                    $o = $byId->fetch() ?: null;
                }
            }
            if (!$o) {
                $line['msg'] = 'No order with this order number or tracking ID.';
            } elseif (!in_array($o['status'], ['created', 'dispatched'], true)) {
                $line['msg'] = order_no($o['id']) . ' is already ' . strtolower(status_label($o['status'])) . '.';
            } else {
                mark_delivered_on($pdo, (int)$o['id'], $date, $track);
                $line['ok'] = true;
                $line['msg'] = order_no($o['id']) . ' marked delivered.';
                $done++;
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
      Columns with a header row: <b>Order ID</b>, <b>Tracking ID / AWB</b>, <b>Status</b> (optional), <b>Delivered date</b> (optional).
      Without a header: A order number, B tracking ID, C status, D date.
      Only rows whose status says Delivered are used; with no status column every row counts as delivered.
      Orders already delivered, returned or refunded are skipped. A tracking ID is saved on the order if it has none.
    </div>
  </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>