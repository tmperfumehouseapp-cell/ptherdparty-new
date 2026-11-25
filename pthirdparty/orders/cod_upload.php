<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/orders_lib.php';
require __DIR__ . '/includes/ops_lib.php';
$user = require_admin();
$pdo = db();

require_once __DIR__ . '/includes/order_dealer_lib.php';
require_once __DIR__ . '/includes/cod_report_lib.php';
require_once __DIR__ . '/includes/upload_ui_lib.php';
$accounts = $pdo->query('SELECT id, name FROM accounts WHERE is_active = 1 ORDER BY name')->fetchAll();
$codDefault = default_cod_account_id($pdo); // COD money normally comes into your own QR

// "Use this order": write the report's Shiprocket Order ID on the chosen order, save the COD money of that row and mark it delivered (answers JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'link') {
    csrf_verify();
    $ok = true;
    try {
        $acc = my_qr_id($pdo);
        if (!$acc) {
            throw new RuntimeException('Choose the account the money came into.');
        }
        $msg = cod_link_order($pdo, $user, (int)($_POST['order_id'] ?? 0), (string)($_POST['srid'] ?? ''), (string)($_POST['awb'] ?? ''), num($_POST['amount'] ?? 0),
            valid_date((string)($_POST['remit'] ?? '')) ?? biz_today(), valid_date((string)($_POST['delivered'] ?? '')), (int)$acc);
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
$usedAcc = $codDefault;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $accId = my_qr_id($pdo);
        if (!$accId) {
            throw new RuntimeException('Choose the account the money came into.');
        }
        $usedAcc = (int)$accId;
        if (empty($_FILES['sheet']) || $_FILES['sheet']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Choose an Excel (.xls, .xlsx) or CSV file to upload.');
        }
        $rows = read_sheet_rows($_FILES['sheet']['tmp_name'], (string)$_FILES['sheet']['name']);
        if (!$rows) {
            throw new RuntimeException('The file is empty.');
        }
        $res = cod_process_rows($pdo, $user, (int)$accId, $rows, valid_date((string)($_POST['remit_date'] ?? '')));
        $withoutSr = dl_orders_without_sr($pdo, 'cod_pending');
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
    <?php if ($res === null): ?>
      <div class="empty">Drop the whole Shiprocket COD report on the right. You will see at once how much COD money was saved, which parcels are not found in the panel, and which COD orders have no Shiprocket Order ID.</div>
    <?php else:
        $results = $res['results'];
        $kinds = array_count_values(array_column($results, 'kind'));
        $saved = $res['ok'];
        $already = $kinds['already'] ?? 0;
        $unmatched = $kinds['unmatched'] ?? 0;
        $needs = array_values(array_filter($results, static fn($r) => in_array($r['kind'], ['prepaid', 'pending', 'refunded', 'other'], true)));
        $reasons = cod_reason_summary($needs);
        $totalRows = count($results);
    ?>
      <?= ui_sentence('Your report has ' . $totalRows . ' parcel' . ($totalRows === 1 ? '' : 's') . ' in the panel period: ' . ($saved + $already) . ' COD saved ✓ (' . inr($res['sum']) . ' added now) · ' . $unmatched . ' not found in the panel ✗ · ' . count($needs) . ' need a look'
          . ($res['beforePanel'] > 0 ? '. (' . $res['beforePanel'] . ' older parcels from before your first order are ignored.)' : '.')) ?>
      <?= ui_big_numbers([
          ['✅', 'COD money saved', $saved + $already, inr($res['sum']) . ' added now · ' . $already . ' were already saved before', 'good'],
          ['❌', 'Not found in the panel', $unmatched, 'their Shiprocket Order ID is not on any order', $unmatched ? 'bad' : ''],
          ['⚠️', 'Need a look', count($needs), 'prepaid, amount too high, refunded...', $needs ? 'warn' : ''],
          ['📝', 'COD orders with no Shiprocket Order ID', $withoutSr === null ? 0 : $withoutSr['count'], 'still waiting for COD money, listed below', ($withoutSr !== null && $withoutSr['count']) ? 'warn' : ''],
      ]) ?>
      <p class="hint" style="margin:-6px 0 14px">
        <?php if ($res['delivered'] > 0): ?><?= (int)$res['delivered'] ?> parcel<?= $res['delivered'] === 1 ? '' : 's' ?> also marked delivered. <?php endif; ?>
        <?php if ($res['notRemitted'] > 0): ?><?= (int)$res['notRemitted'] ?> parcel<?= $res['notRemitted'] === 1 ? ' has' : 's have' ?> no COD money in the report yet. <?php endif; ?>
      </p>
      <?= ui_unmatched_table($results, 'cod', $usedAcc) ?>
      <?php if ($reasons): ?>
      <h3 style="margin:0 0 8px;color:#B45309">Need a look (<?= count($needs) ?>)</h3>
      <div class="table-wrap" style="margin-bottom:16px"><table class="tight" style="min-width:560px">
        <thead><tr><th>What is wrong</th><th class="num">Rows</th><th>Examples (row · Shiprocket Order ID · amount)</th></tr></thead>
        <tbody>
        <?php foreach ($reasons as $rs): ?>
          <tr><td><b><?= e($rs['label']) ?></b><?php if ($rs['hint'] !== ''): ?><div class="mini"><?= e($rs['hint']) ?></div><?php endif; ?></td>
            <td class="num"><span class="tag tag-warn"><?= (int)$rs['n'] ?></span></td>
            <td class="mini"><?php foreach ($rs['examples'] as $ex): ?><div><?= (int)$ex['row'] ?> · <?= e($ex['ref']) ?> · <?= e(inr($ex['amount'])) ?><?php if (strpos($rs['label'], 'more than') !== false): ?> · <?= e($ex['msg']) ?><?php endif; ?></div><?php endforeach; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
      <?php if (count($res['groups']) > 1 || (count($res['groups']) === 1 && key($res['groups']) !== '-')): ?>
      <h3 style="margin:0 0 8px">By remittance (match these with your bank)</h3>
      <div class="table-wrap" style="margin-bottom:16px"><table class="tight" style="min-width:560px">
        <thead><tr><th>CRF ID</th><th>UTR</th><th>Date</th><th class="num">Parcels</th><th class="num">Saved now</th><th class="num">Saved before</th><th class="num">Not saved</th><th class="num">Money in file</th><th class="num">Money saved now</th></tr></thead>
        <tbody>
        <?php foreach ($res['groups'] as $g): ?>
          <tr><td><?= e($g['crf'] !== '' ? $g['crf'] : '-') ?></td><td><?= e($g['utr'] !== '' ? $g['utr'] : '-') ?></td><td><?= e(date('d M Y', strtotime($g['date']))) ?></td>
            <td class="num"><?= (int)$g['rows'] ?></td><td class="num"><?= (int)$g['saved'] ?></td><td class="num"><?= (int)$g['already'] ?></td>
            <td class="num"><?= $g['skipped'] ? '<span class="tag tag-bad">' . (int)$g['skipped'] . '</span>' : '0' ?></td>
            <td class="num"><?= e(inr($g['in_file'])) ?></td><td class="num" style="font-weight:800"><?= e(inr($g['saved_amt'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
      <details<?= $totalRows <= 25 ? ' open' : '' ?>><summary class="btn btn-ghost btn-sm" style="display:inline-flex;cursor:pointer;margin-bottom:10px">Every row of the report (<?= $totalRows ?>)</summary>
      <div class="table-wrap"><table class="tight" style="min-width:480px">
        <thead><tr><th>Row</th><th>Shiprocket Order ID</th><th>AWB</th><th class="num">Amount</th><th>Result</th></tr></thead>
        <tbody>
        <?php $tagFor = ['ok' => ['tag-ok', 'Saved'], 'already' => ['tag-ok', 'Saved before'], 'unmatched' => ['tag-bad', 'Not found']]; ?>
        <?php foreach ($results as $r): [$tc, $tl] = $tagFor[$r['kind']] ?? ['tag-warn', 'Need a look']; ?>
          <tr><td><?= (int)$r['row'] ?></td><td><?= e($r['srid'] !== '' ? $r['srid'] : '-') ?></td><td><?= e($r['awb'] !== '' ? $r['awb'] : '-') ?></td><td class="num"><?= e(inr($r['amount'])) ?></td>
            <td><span class="tag <?= e($tc) ?>"><?= e($tl) ?></span> <span class="mini"><?= e($r['msg']) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div></details>
    <?php endif; ?>
  </section>

  <section class="card narrow">
    <h2>Upload COD money</h2>
    <form method="post" enctype="multipart/form-data" autocomplete="off">
      <?= csrf_field() ?>
      <div class="field"><label>Money came into</label><div class="mini" style="padding:12px 0;font-weight:700">My QR</div></div>
      <div class="field"><label for="remit_date">Remittance date (only if the file has none)</label><input id="remit_date" name="remit_date" type="date" style="width:100%">
        <div class="hint">Shiprocket's "AWB Reconciliation" export has no payment date. Type the date Shiprocket paid that remittance (the date on its COD Remittance page, for example 12 Nov). A file that has its own Remittance Date column ignores this box.</div></div>
      <div class="field"><label for="sheet">Shiprocket COD report (.xls, .xlsx or .csv)</label><input id="sheet" name="sheet" type="file" accept=".xls,.xlsx,.csv" required style="width:100%"></div>
      <button class="btn btn-accent btn-block" type="submit">Upload and save</button>
    </form>
    <div class="sheet-note" style="margin-top:14px">
      <b>How it works</b>
      <ol style="margin:6px 0 0 18px;padding:0">
        <li>Upload Shiprocket's COD report <b>exactly as you download it</b>. No editing.</li>
        <li>The <b>Shiprocket Order ID</b> (the one you type on New order) is the key. The AWB is the second key. The panel's own numbers like #0081 are only a reference and are not used.</li>
        <li>The COD money is the report's <b>Order Value</b> / <b>Remitted Amount</b>. The "COD Payble Amount" column is a charge and is never used as money.</li>
        <li>A parcel whose COD money was already saved before counts as done, not as a problem. The parcel is also marked delivered.</li>
        <li>A parcel that is <b>not found</b> is listed in red, with the orders it may belong to. Press <b>Use this order</b>: it writes the Shiprocket Order ID, saves the COD money and marks it delivered.</li>
        <li>It is safe to upload the same report again: nothing is counted twice.</li>
      </ol>
    </div>
  </section>
</div>
<?= ui_without_sr_card($withoutSr, 'COD orders with no Shiprocket Order ID', 'These COD orders are still waiting for their COD money and no report can match them. Open the order and type its Shiprocket Order ID.') ?>
<?= ui_link_script() ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
