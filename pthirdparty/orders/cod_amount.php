<?php
// COD Amount: how much COD money you have not received yet, and how much you received on each date.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/simple_lib.php';
require_once __DIR__ . '/includes/cod_sr_lib.php';
require_once __DIR__ . '/includes/orders_lib.php';
require_once __DIR__ . '/includes/cod_entry_lib.php';
$user = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            add_remittance(
                $pdo, $user, (int)($_POST['account_id'] ?? 0), num($_POST['amount'] ?? 0),
                (string)($_POST['entry_date'] ?? ''), trim((string)($_POST['note'] ?? '')), []
            );
            flash('ok', 'COD received saved.');
        } elseif ($action === 'mode') {
            require_once __DIR__ . '/includes/sr_connect_lib.php';
            sr_conn_ensure($pdo);
            sr_conn_set($pdo, 'cod_delivered_paid', !empty($_POST['on']) ? '1' : '0');
            flash('ok', !empty($_POST['on']) ? 'Delivered parcels are now counted as COD received.' : 'Delivered parcels are no longer counted as received automatically.');
        } elseif ($action === 'delete') {
            delete_remittance($pdo, (int)($_POST['id'] ?? 0));
            flash('ok', 'Entry deleted.');
        } elseif ($action === 'del_entry') { // manager only (this page is), order COD entry or lump remittance
            if (($_POST['kind'] ?? '') === 'lump') {
                delete_remittance($pdo, (int)($_POST['id'] ?? 0));
                flash('ok', 'COD remittance deleted.');
            } else {
                flash('ok', cod_receipt_delete($pdo, $user, (int)($_POST['id'] ?? 0)));
            }
        } elseif ($action === 'edit_entry') {
            $amt = num($_POST['amount'] ?? 0);
            $dt = valid_date((string)($_POST['entry_date'] ?? '')) ?? '';
            if ($dt === '') {
                throw new RuntimeException('Choose a valid date.');
            }
            if (($_POST['kind'] ?? '') === 'lump') {
                flash('ok', cod_lump_edit($pdo, $user, (int)($_POST['id'] ?? 0), $amt, $dt, (int)($_POST['account_id'] ?? 0), trim((string)($_POST['note'] ?? ''))));
            } else {
                flash('ok', cod_receipt_edit($pdo, $user, (int)($_POST['id'] ?? 0), $amt, $dt, (int)($_POST['account_id'] ?? 0)));
            }
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    $bf = valid_date((string)($_POST['from'] ?? ''));
    $bt = valid_date((string)($_POST['to'] ?? ''));
    $bv = in_array((string)($_POST['view'] ?? ''), ['received', 'delivered', 'way'], true) ? (string)$_POST['view'] : '';
    redirect('cod_amount.php' . ($bf && $bt ? '?' . http_build_query(array_filter(['from' => $bf, 'to' => $bt, 'view' => $bv])) : ''));
}

$today = biz_today();
$from = valid_date((string)($_GET['from'] ?? '')) ?? '';
$to = valid_date((string)($_GET['to'] ?? '')) ?? '';
if ($from === '' && $to === '') {
    $from = date('Y-m-d', strtotime($today . ' -29 days'));
    $to = $today;
} elseif ($to === '') {
    $to = $today;
} elseif ($from === '') {
    $from = date('Y-m-d', strtotime($to . ' -29 days'));
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$pending = simple_cod_pending($pdo);
$days = simple_cod_by_date($pdo, $from, $to);
$received = 0.0;
foreach ($days as $d) {
    $received += (float)$d['amount'];
}
// Shiprocket's own COD figures (UTR / remittance date), saved from its reports; the panel's hand-entered figures stay below for comparison
$srOk = false;
$assume = false;
$sr = ['days' => [], 'total' => 0.0, 'orders' => 0, 'assumed' => ['n' => 0, 'amount' => 0.0], 'undated' => ['n' => 0, 'amount' => 0.0]];
$sp = null;
$cov = ['delivered' => 0, 'remitted' => 0, 'any' => 0];
try {
    srs_ensure($pdo);
    $assume = srs_delivered_paid($pdo);
    $sr = cod_sr_received($pdo, $from, $to, $assume);
    $sp = cod_sr_pending($pdo, $assume);
    $cov = cod_sr_coverage($pdo);
    $srOk = true;
} catch (Throwable $ex) {
    $srOk = false;
}
$view = (string)($_GET['view'] ?? 'received');
if (!in_array($view, ['received', 'delivered', 'way'], true) || !$srOk) {
    $view = 'received';
}
$plist = [];
if ($srOk && $view !== 'received') {
    $plist = cod_sr_pending_lines($pdo, $view, orders_has_sr_id($pdo));
}
$first = $pdo->query("SELECT MIN(d) FROM (SELECT MIN(received_date) AS d FROM cod_receipts UNION ALL SELECT MIN(entry_date) FROM account_ledger WHERE entry_type = 'cod') x")->fetchColumn() ?: $today;

require_once __DIR__ . '/includes/order_dealer_lib.php';
$codDefault = default_cod_account_id($pdo); // COD money normally comes into your own QR
$accounts = $pdo->query('SELECT a.id, a.name, p.name AS owner FROM accounts a LEFT JOIN parties p ON p.id = a.party_id WHERE a.is_active = 1 ORDER BY (a.party_id IS NOT NULL), a.name')->fetchAll();
// money entered here by hand (not tied to one order): the only entries that can be deleted from this page
$manual = $pdo->query(
    "SELECT l.id, l.entry_date, l.amount, l.note, a.name AS account FROM account_ledger l JOIN accounts a ON a.id = l.account_id
     WHERE l.entry_type = 'cod' AND l.order_id IS NULL AND l.direction = 'in' ORDER BY l.entry_date DESC, l.id DESC LIMIT 20"
)->fetchAll();

$chip = static function (string $label, string $f, string $t) use ($from, $to): string {
    $on = ($f === $from && $t === $to);
    return '<a class="btn ' . ($on ? 'btn-accent' : 'btn-ghost') . ' btn-sm linkbtn" href="cod_amount.php?' . e(http_build_query(['from' => $f, 'to' => $t])) . '">' . e($label) . '</a>';
};
$yest = date('Y-m-d', strtotime($today . ' -1 day'));
$dateInput = 'style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"';

$pageTitle = 'COD Amount';
$active = 'cod_amount';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<style>
.ct{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:16px}
.ct .stat{min-width:0}
.cd{display:flex;flex-direction:column}
.cd .r{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px 0;border-top:1px solid var(--line);font-weight:700}
.cd .r:first-child{border-top:0}
.cd .r small{color:var(--muted);font-weight:700;font-size:12px;margin-left:6px}
.cd .r b{font-variant-numeric:tabular-nums}
.cd .tot{border-top:2px solid var(--line);font-weight:800}
.ent{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:9px 0;border-top:1px dashed var(--line);font-size:14px;font-weight:600}
.ent .mini{display:block}
.ent form{display:inline}
</style>

<div class="ct">
  <?php if ($srOk): ?>
  <div class="stat"><div class="k">COD received <span class="mini">(Shiprocket paid)</span></div><div class="v pos"><?= e(inr($sr['total'])) ?></div>
    <div class="mini"><?= (int)$sr['orders'] ?> parcel<?= $sr['orders'] === 1 ? '' : 's' ?><?= $sr['orders'] > $sr['panel'] ? ' (' . (int)$sr['panel'] . ' are panel orders)' : '' ?> · <?= e($from === $to ? date('d M Y', strtotime($from)) : date('d M', strtotime($from)) . ' to ' . date('d M Y', strtotime($to))) ?></div>
    <?php if ($assume && $sr['assumed']['n'] > 0): ?><div class="mini"><?= (int)$sr['assumed']['n'] ?> delivered parcel<?= $sr['assumed']['n'] === 1 ? '' : 's' ?> (<?= e(inr($sr['assumed']['amount'])) ?>) counted as received, dated by delivery day</div><?php endif; ?>
    <div class="mini">Entered in the panel in these dates: <b><?= e(inr($received)) ?></b><?= abs($sr['total'] - $received) > 0.5 ? ' · difference ' . e(inr(abs($sr['total'] - $received))) . ($sr['total'] > $received ? ' (not entered yet)' : '') : ' · matches' ?></div></div>
  <div class="stat"><div class="k">COD pending <span class="mini">(<?= $assume ? 'still on the way' : 'Shiprocket still to pay' ?>)</span></div><div class="v" style="<?= $sp['total']['amount'] > 0.004 ? 'color:#B45309' : '' ?>"><?= e(inr($sp['total']['amount'])) ?></div>
    <div class="mini"><?= (int)$sp['total']['n'] ?> order<?= $sp['total']['n'] === 1 ? '' : 's' ?> waiting</div>
    <?php if ($assume): ?><div class="mini">Delivered parcels are counted as received (their money is already in your QR). <a class="rowlink" href="orders.php?tag=transit">On the way</a>: <b><?= e(inr($sp['on_the_way']['amount'])) ?></b> (<?= (int)$sp['on_the_way']['n'] ?>)</div>
    <?php else: ?><div class="mini"><a class="rowlink" href="orders.php?tag=cod_pending">Delivered, nothing yet</a>: <b><?= e(inr($sp['delivered_waiting']['amount'])) ?></b> (<?= (int)$sp['delivered_waiting']['n'] ?>)
      · <a class="rowlink" href="orders.php?tag=cod_initiated">Initiated</a>: <b><?= e(inr($sp['delivered_initiated']['amount'])) ?></b> (<?= (int)$sp['delivered_initiated']['n'] ?>)
      · <a class="rowlink" href="orders.php?tag=transit">On the way</a>: <b><?= e(inr($sp['on_the_way']['amount'])) ?></b> (<?= (int)$sp['on_the_way']['n'] ?>)</div><?php endif; ?></div>
  <?php else: ?>
  <div class="stat"><div class="k">Pending COD amount</div><div class="v" style="<?= $pending['due'] > 0.004 ? 'color:#B45309' : '' ?>"><?= e(inr($pending['due'])) ?></div>
    <div class="mini"><?= ($pending['due'] > 0.004 && $pending['orders']) ? (int)$pending['orders'] . ' order' . ($pending['orders'] === 1 ? '' : 's') . ' waiting · ' : '' ?><a class="rowlink" href="orders.php?mode=cod_due">see which</a></div></div>
  <div class="stat"><div class="k">COD received</div><div class="v pos"><?= e(inr($received)) ?></div>
    <div class="mini"><?= e($from === $to ? date('d M Y', strtotime($from)) : date('d M', strtotime($from)) . ' to ' . date('d M Y', strtotime($to))) ?></div></div>
  <?php endif; ?>
</div>

<?php if ($srOk):
    $vq = static fn(string $v) => 'cod_amount.php?' . http_build_query(['view' => $v, 'from' => $from, 'to' => $to]);
    $notRecv = $sp['delivered_waiting']['n'] + $sp['delivered_initiated']['n'];
    $notRecvAmt = $sp['delivered_waiting']['amount'] + $sp['delivered_initiated']['amount'];
?>
<div style="display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px">
  <a class="btn btn-sm linkbtn <?= $view === 'received' ? 'btn-accent' : 'btn-ghost' ?>" href="<?= e($vq('received')) ?>">COD received in my account · <?= e(inr($sr['total'])) ?></a>
  <a class="btn btn-sm linkbtn <?= $view === 'delivered' ? 'btn-accent' : 'btn-ghost' ?>" href="<?= e($vq('delivered')) ?>"><?= $assume ? 'Delivered, no Shiprocket payout line' : 'Delivered, COD not received yet' ?> · <?= (int)$notRecv ?> · <?= e(inr($notRecvAmt)) ?></a>
  <a class="btn btn-sm linkbtn <?= $view === 'way' ? 'btn-accent' : 'btn-ghost' ?>" href="<?= e($vq('way')) ?>">Still on the way · <?= (int)$sp['on_the_way']['n'] ?> · <?= e(inr($sp['on_the_way']['amount'])) ?></a>
</div>
<?php endif; ?>

<?php if ($srOk && $view !== 'received'): ?>
<section class="card">
  <h2><?= $view === 'delivered' ? ($assume ? 'Delivered, but no payout line from Shiprocket (counted as received)' : 'Delivered, COD not received yet') : 'Still on the way (COD comes after delivery)' ?></h2>
  <div class="hint" style="margin:0 0 10px"><?= $view === 'delivered' ? 'Parcels Shiprocket shows as delivered whose COD payout is not in the reports yet, oldest delivery first. "Initiated" means Shiprocket has made the remittance but not paid it.' : 'Parcels not delivered yet, so no COD can come for them.' ?> Total <b><?= e(inr(array_sum(array_column($plist, 'amount')))) ?></b> for <?= count($plist) ?> order<?= count($plist) === 1 ? '' : 's' ?>.</div>
  <?php if (!$plist): ?><div class="empty">Nothing here.</div><?php else: ?>
  <div style="overflow-x:auto"><table class="tbl"><thead><tr><th>Order</th><th>Customer</th><th>Shiprocket order ID</th><th>AWB</th><th><?= $view === 'delivered' ? 'Delivered' : 'Status' ?></th><th style="text-align:right">COD</th></tr></thead><tbody>
    <?php foreach ($plist as $r): ?><tr>
      <td><a href="order_view.php?id=<?= (int)$r['id'] ?>"><?= e(order_no($r['id'])) ?></a><div class="mini"><?= e(date('d M', strtotime($r['order_date']))) ?></div></td>
      <td><?= e($r['name']) ?></td><td><?= e($r['sr_order_id'] !== '' ? $r['sr_order_id'] : '-') ?></td><td><?= e($r['awb'] !== '' ? $r['awb'] : '-') ?></td>
      <td><?php if ($view === 'delivered'): ?><?= $r['delivered_on'] ? e(date('d M', strtotime($r['delivered_on']))) . '<div class="mini" style="' . ($r['days'] >= 10 ? 'color:#B42318;font-weight:600' : '') . '">' . (int)$r['days'] . ' day' . ($r['days'] === 1 ? '' : 's') . ' ago</div>' : '-' ?><?= $r['crf'] !== '' ? '<div class="mini">Initiated (CRF ' . e($r['crf']) . ')</div>' : '' ?><?php else: ?><?= e($r['sr_status'] !== '' ? $r['sr_status'] : 'no Shiprocket info') ?><?php endif; ?></td>
      <td style="text-align:right"><b><?= e(inr($r['amount'])) ?></b></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>
<?php else: ?>
<section class="card">
  <h2>COD received<?= $srOk ? ' by Shiprocket' : '' ?>, date by date</h2>
  <form method="get" class="filters">
    <div><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($from) ?>" <?= $dateInput ?>></div>
    <div><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($to) ?>" <?= $dateInput ?>></div>
    <button class="btn" type="submit">Show</button>
  </form>
  <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
    <?= $chip('Today', $today, $today) ?>
    <?= $chip('Yesterday', $yest, $yest) ?>
    <?= $chip('Last 7 days', date('Y-m-d', strtotime($today . ' -6 days')), $today) ?>
    <?= $chip('This month', date('Y-m-01', strtotime($today)), $today) ?>
    <?= $chip('All time', $first, $today) ?>
  </div>
  <?php if ($srOk): ?>
    <?php if ($cov['any'] === 0): ?>
      <div class="hint" style="margin:0 0 12px">The panel has no Shiprocket payout information yet. Upload Shiprocket's all-orders report (or the COD remittance report) on <a href="delivered_upload.php">Upload delivered CSV</a>: it has the UTR number, remittance date and remitted amount, and this page fills by itself.</div>
    <?php else: ?>
      <div class="hint" style="margin:0 0 12px">Everything here comes from the Shiprocket reports you uploaded<?= $cov['last'] ? ' (latest payout date in them: <b>' . e(date('d M Y', strtotime($cov['last']))) . '</b>)' : '' ?>, so it is exactly what Shiprocket says it paid, after its COD charge. To bring it up to today, download the latest all-orders report from Shiprocket and upload it on <a href="delivered_upload.php">Upload delivered CSV</a>; uploading the same report again never counts anything twice. <b><?= (int)$cov['delivered'] - (int)$cov['remitted'] ?></b> of your <?= (int)$cov['delivered'] ?> delivered COD orders have no payout in them yet.</div>
    <?php endif; ?>
    <?php if ($sr['undated']['n'] > 0): ?><div class="hint" style="margin:0 0 12px"><?= (int)$sr['undated']['n'] ?> paid orders (<?= e(inr($sr['undated']['amount'])) ?>) have a UTR but no remittance date in the report, so they are not in a date below.</div><?php endif; ?>
    <?php if (!$sr['days']): ?>
      <div class="empty">Shiprocket has paid nothing in these dates.</div>
    <?php else: ?>
    <div class="cd">
      <?php foreach ($sr['days'] as $d): ?>
      <details class="r" style="display:block"<?= $from === $to ? ' open' : '' ?>><summary style="display:flex;justify-content:space-between;gap:12px;cursor:pointer;list-style:none"><span><?= e(date('D, d M Y', strtotime($d['d']))) ?><small><?= (int)$d['n'] ?> order<?= (int)$d['n'] === 1 ? '' : 's' ?></small></span><b class="pos"><?= e(inr($d['amount'])) ?></b></summary>
        <div style="margin-top:6px">
          <?php foreach ($d['lines'] as $l): ?><div class="mini" style="display:flex;justify-content:space-between;gap:10px;font-weight:600;padding:3px 0;border-top:1px dashed var(--line)">
            <span><?php if ($l['order_id']): ?><a href="order_view.php?id=<?= (int)$l['order_id'] ?>"><?= e(order_no($l['order_id'])) ?></a><?php else: ?><span title="Not an order of this panel">not in panel</span><?php endif; ?>
              <?= $l['name'] !== '' ? ' · ' . e($l['name']) : '' ?> · Shiprocket <?= e($l['ref'] !== '' ? $l['ref'] : ($l['awb'] !== '' ? $l['awb'] : '-')) ?> <?= !empty($l['assumed']) ? ' · delivered, counted as received' : ' · UTR ' . e($l['utr'] !== '' ? $l['utr'] : '-') ?></span>
            <span><?= e(inr($l['amount'])) ?></span></div><?php endforeach; ?>
        </div>
      </details>
      <?php endforeach; ?>
      <div class="r tot"><span>Total</span><b><?= e(inr($sr['total'])) ?></b></div>
    </div>
    <?php endif; ?>
    <form method="post" style="margin:14px 0 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="action" value="mode"><input type="hidden" name="on" value="<?= $assume ? '0' : '1' ?>">
      <span class="mini">Setting: delivered parcels are <b><?= $assume ? 'counted as COD received (money already in my QR)' : 'not counted until Shiprocket pays' ?></b></span>
      <button class="btn btn-ghost btn-sm" type="submit"><?= $assume ? 'Turn off' : 'Turn on' ?></button></form>
    <h3 style="margin:22px 0 4px;font-size:15px">What you entered in the panel (for comparison)</h3>
  <?php endif; ?>
  <?php $ents = cod_entries($pdo, $from, $to); $byDay = []; foreach ($ents as $en) { $byDay[$en['d']][] = $en; } ?>
  <?php if (!$ents): ?>
    <div class="empty">No COD money received in these dates.</div>
  <?php else: ?>
  <div class="hint" style="margin:0 0 6px">Click a date to see each entry. You can change or delete an entry here: the order's COD balance, your account and the dealer figures follow.</div>
  <div class="cd">
    <?php foreach ($byDay as $dd => $list): $sum = array_sum(array_column($list, 'amount')); ?>
    <details class="r" style="display:block"<?= $from === $to ? ' open' : '' ?>><summary style="display:flex;justify-content:space-between;gap:12px;cursor:pointer;list-style:none"><span><?= e(date('D, d M Y', strtotime($dd))) ?><small><?= count($list) ?> entr<?= count($list) === 1 ? 'y' : 'ies' ?></small></span><b class="pos"><?= e(inr($sum)) ?></b></summary>
      <?php foreach ($list as $en): ?>
      <div class="ent" style="display:block">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
          <div><?php if ($en['kind'] === 'order'): ?><a href="order_view.php?id=<?= (int)$en['order_id'] ?>"><?= e(order_no($en['order_id'])) ?></a><?= $en['name'] !== '' ? ' · ' . e($en['name']) : '' ?><?php else: ?>COD remittance<?= $en['note'] !== '' ? ' · ' . e(cod_lump_note($en['note'])) : '' ?><?php endif; ?>
            <span class="mini"><?= e($en['account']) ?><?= $en['source'] === 'excel' ? ' · from Excel' : '' ?></span></div>
          <div style="display:flex;gap:6px;align-items:center"><b><?= e(inr($en['amount'])) ?></b>
            <button class="btn btn-ghost btn-sm" type="button" onclick="var f=document.getElementById('ed<?= e($en['kind']) ?><?= (int)$en['id'] ?>');f.style.display=f.style.display==='none'?'flex':'none'">Edit</button>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this COD entry of <?= e(inr($en['amount'])) ?>?');"><?= csrf_field() ?><input type="hidden" name="action" value="del_entry"><input type="hidden" name="kind" value="<?= e($en['kind']) ?>"><input type="hidden" name="id" value="<?= (int)$en['id'] ?>"><input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>"><button class="btn btn-ghost btn-sm" type="submit">Delete</button></form></div>
        </div>
        <form method="post" id="ed<?= e($en['kind']) ?><?= (int)$en['id'] ?>" style="display:none;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="edit_entry"><input type="hidden" name="kind" value="<?= e($en['kind']) ?>"><input type="hidden" name="id" value="<?= (int)$en['id'] ?>"><input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
          <div class="field" style="margin:0"><label>Amount (₹)</label><input name="amount" type="text" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format((float)$en['amount'], 2, '.', ''), '0'), '.')) ?>" style="width:120px"></div>
          <div class="field" style="margin:0"><label>Date</label><input name="entry_date" type="date" value="<?= e($en['d']) ?>"></div>
          <div class="field" style="margin:0"><label>Account</label><select name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$a['id'] === (int)$en['account_id'] ? ' selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
          <?php if ($en['kind'] === 'lump'): ?><div class="field" style="margin:0"><label>Note</label><input name="note" type="text" value="<?= e(cod_lump_note($en['note'])) ?>"></div><?php endif; ?>
          <button class="btn btn-accent btn-sm" type="submit">Save</button></form>
      </div>
      <?php endforeach; ?>
    </details>
    <?php endforeach; ?>
    <div class="r tot"><span>Total</span><b><?= e(inr(array_sum(array_column($ents, 'amount')))) ?></b></div>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<details class="card" style="margin-top:16px" id="add">
  <summary style="cursor:pointer;font-size:17px;font-weight:800">Add COD received</summary>
  <form method="post" autocomplete="off" id="codform" style="margin-top:14px">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="field"><label for="entry_date">Date received</label><input id="entry_date" name="entry_date" type="date" value="<?= e($today) ?>" <?= $dateInput ?>></div>
    <div class="field"><label for="amount">Amount received (₹)</label><input id="amount" name="amount" type="text" inputmode="decimal" required></div>
    <div class="field"><label for="account_id">Came into</label>
      <select id="account_id" name="account_id">
        <?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$a['id'] === $codDefault ? ' selected' : '' ?>><?= e($a['name']) ?><?= $a['owner'] ? ' (' . e($a['owner']) . ')' : '' ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="note">Note (optional)</label><input id="note" name="note" type="text" placeholder="Shiprocket remittance, UTR ..."></div>
    <button class="btn btn-accent btn-block" type="submit">Save COD received</button>
    <div class="hint">Use this for money that arrives in one lump, for example a courier remittance. Per-order COD entered on an order, or uploaded from Excel, is counted here too.</div>
  </form>
  <p style="margin:12px 0 0"><a class="btn btn-ghost btn-sm linkbtn" href="cod_upload.php">Upload COD from Excel</a></p>
</details>

<script>
(function () {
  var f = document.getElementById('codform');
  f.addEventListener('submit', function () {
    var b = f.querySelector('button[type=submit]');
    if (b) { setTimeout(function () { b.disabled = true; }, 0); } // one click saves once
  });
})();
</script>
<p class="hint" style="margin-top:18px">Want to enter COD money again by hand, order by order? <a href="cod_reset.php">Set all COD received to zero</a> (a backup is saved first, and it can be undone).</p>
<?php require __DIR__ . '/includes/footer.php'; ?>
