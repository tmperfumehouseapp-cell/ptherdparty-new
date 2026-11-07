<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/orders_lib.php';
require __DIR__ . '/includes/ops_lib.php';
$user = require_admin();
$pdo = db();

$accounts = $pdo->query('SELECT id, name FROM accounts WHERE is_active = 1 ORDER BY name')->fetchAll();
$results = null;
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $accId = active_account_id($_POST['account_id'] ?? 0);
        if (!$accId) {
            throw new RuntimeException('Choose the account the money came into.');
        }
        if (empty($_FILES['sheet']) || $_FILES['sheet']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Choose an Excel (.xlsx) or CSV file to upload.');
        }
        $rows = read_sheet_rows($_FILES['sheet']['tmp_name'], (string)$_FILES['sheet']['name']);
        if (!$rows) {
            throw new RuntimeException('The file is empty.');
        }

        // find the columns: header names if present, else A = order or tracking ID, B = amount, C = date
        $colId = 0;
        $colAmt = 1;
        $colDate = 2;
        $start = 0;
        $head = array_map('strtolower', $rows[0]);
        $hasHead = false;
        foreach ($head as $i => $h) {
            if (preg_match('/order|awb|tracking|waybill/', $h)) {
                $colId = $i;
                $hasHead = true;
            } elseif (preg_match('/amount|cod|collect|remit|paid/', $h)) {
                $colAmt = $i;
                $hasHead = true;
            } elseif (preg_match('/date/', $h)) {
                $colDate = $i;
                $hasHead = true;
            }
        }
        if ($hasHead) {
            $start = 1;
        } elseif (!preg_match('/\d/', (string)($rows[0][$colAmt] ?? ''))) {
            $start = 1;
        }

        $byTrack = $pdo->prepare('SELECT id FROM orders WHERE tracking_id = ? LIMIT 1');
        $byId = $pdo->prepare('SELECT id FROM orders WHERE id = ?');
        $results = [];
        $okCount = 0;
        $okSum = 0.0;
        for ($i = $start; $i < count($rows); $i++) {
            $r = $rows[$i];
            $ref = trim((string)($r[$colId] ?? ''));
            $amt = num($r[$colAmt] ?? 0);
            if ($ref === '' && $amt == 0) {
                continue;
            }
            $date = sheet_date((string)($r[$colDate] ?? '')) ?? biz_today();
            $line = ['row' => $i + 1, 'ref' => $ref, 'amount' => $amt, 'ok' => false, 'msg' => ''];

            $orderId = null;
            $byTrack->execute([$ref]);
            $found = $byTrack->fetchColumn();
            if ($found) {
                $orderId = (int)$found;
            } else {
                $digits = (int)preg_replace('/\D+/', '', $ref);
                if ($digits > 0) {
                    $byId->execute([$digits]);
                    $found = $byId->fetchColumn();
                    $orderId = $found ? (int)$found : null;
                }
            }
            if (!$orderId) {
                $line['msg'] = 'No order with this ID or tracking ID.';
            } else {
                try {
                    add_cod_receipt($pdo, $user, $orderId, $amt, $accId, $date, 'excel');
                    $line['ok'] = true;
                    $line['msg'] = 'Saved for order ' . order_no($orderId);
                    $okCount++;
                    $okSum += $amt;
                } catch (RuntimeException $ex) {
                    $line['msg'] = $ex->getMessage();
                }
            }
            $results[] = $line;
        }
        $summary = ['ok' => $okCount, 'sum' => $okSum, 'bad' => count($results) - $okCount];
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
      <div class="stats" style="margin-bottom:14px">
        <div class="stat"><div class="k">Saved</div><div class="v pos"><?= (int)$summary['ok'] ?></div></div>
        <div class="stat"><div class="k">Money added</div><div class="v"><?= e(inr($summary['sum'])) ?></div></div>
        <div class="stat"><div class="k">Skipped</div><div class="v <?= $summary['bad'] ? 'neg' : '' ?>"><?= (int)$summary['bad'] ?></div></div>
      </div>
      <div class="table-wrap"><table class="tight" style="min-width:480px">
        <thead><tr><th>Row</th><th>Order / tracking ID</th><th class="num">Amount</th><th>Result</th></tr></thead>
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
          <?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label for="sheet">Excel (.xlsx) or CSV file</label><input id="sheet" name="sheet" type="file" accept=".xlsx,.csv" required style="width:100%"></div>
      <button class="btn btn-accent btn-block" type="submit">Upload and save</button>
    </form>
    <div class="sheet-note" style="margin-top:14px">
      Columns: <b>A</b> order number or tracking ID, <b>B</b> amount received, <b>C</b> date (optional). A header row is fine.
      Rows for orders that are not COD, already fully received, or more than pending are skipped and explained.
    </div>
  </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>