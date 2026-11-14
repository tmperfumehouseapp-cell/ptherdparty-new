<?php
// Billing: you type in each dealer bill by hand. Dealer, bill number, date, total amount, notes.
// Saving a bill adds its amount to what you owe that dealer. Nothing else to approve or send.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/billing_lib.php'; // only used for bills made in the old billing screen
require_once __DIR__ . '/includes/simple_lib.php';
require_once __DIR__ . '/includes/order_check_lib.php';
$user = require_admin();
$pdo = db();
$hasDetails = simple_has_details($pdo);

/** A bill typed here has no product lines. Bills from the old screen have lines and stay read-only. */
function bill_is_manual(PDO $pdo, int $billId): ?array
{
    $st = $pdo->prepare("SELECT b.* FROM bills b WHERE b.id = ? AND b.status = 'approved' AND NOT EXISTS (SELECT 1 FROM bill_items bi WHERE bi.bill_id = b.id)");
    $st->execute([$billId]);
    return $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            if (!$hasDetails) {
                throw new RuntimeException('One small step is missing: run migrate_simple_billing.sql in phpMyAdmin first.');
            }
            $id = (int)($_POST['id'] ?? 0);
            $partyId = (int)($_POST['party_id'] ?? 0);
            $ps = $pdo->prepare('SELECT id, name FROM parties WHERE id = ?');
            $ps->execute([$partyId]);
            $party = $ps->fetch();
            if (!$party) {
                throw new RuntimeException('Choose the dealer.');
            }
            $no = mb_substr(trim((string)($_POST['bill_no'] ?? '')), 0, 60);
            if ($no === '') {
                throw new RuntimeException('Enter the bill number.');
            }
            $date = valid_date((string)($_POST['bill_date'] ?? ''));
            if ($date === null) {
                throw new RuntimeException('Enter the bill date.');
            }
            $amt = round(num($_POST['amount'] ?? 0), 2);
            if ($amt <= 0) {
                throw new RuntimeException('Enter the total bill amount.');
            }
            $details = mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['details'] ?? ''))), 0, 5000);
            $details = $details === '' ? null : $details;

            try {
                in_tx($pdo, function () use ($pdo, $user, $id, $partyId, $no, $date, $amt, $details) {
                    if ($id > 0) {
                        if (!bill_is_manual($pdo, $id)) {
                            throw new RuntimeException('This bill cannot be edited here.');
                        }
                        $pdo->prepare('UPDATE bills SET party_id = ?, bill_no = ?, bill_date = ?, total_amount = ?, details = ? WHERE id = ?')
                            ->execute([$partyId, $no, $date, $amt, $details, $id]);
                        $pdo->prepare("UPDATE party_ledger SET party_id = ?, entry_date = ?, amount = ?, note = ? WHERE bill_id = ? AND entry_type = 'bill'")
                            ->execute([$partyId, $date, $amt, 'Bill ' . $no, $id]);
                    } else {
                        $pdo->prepare("INSERT INTO bills (party_id, bill_no, bill_date, total_amount, status, details, decided_at, decided_by, created_by) VALUES (?, ?, ?, ?, 'approved', ?, NOW(), ?, ?)")
                            ->execute([$partyId, $no, $date, $amt, $details, (int)$user['id'], (int)$user['id']]);
                        $billId = (int)$pdo->lastInsertId();
                        $pdo->prepare("INSERT INTO party_ledger (party_id, entry_date, entry_type, amount, bill_id, note, created_by) VALUES (?, ?, 'bill', ?, ?, ?, ?)")
                            ->execute([$partyId, $date, $amt, $billId, 'Bill ' . $no, (int)$user['id']]);
                    }
                });
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23000') {
                    throw new RuntimeException($party['name'] . ' already has a bill numbered "' . $no . '". Check the number.');
                }
                throw $ex;
            }
            flash('ok', 'Bill ' . $no . ' saved. ' . $party['name'] . ' outstanding is now ' . inr(max(0.0, simple_balance($pdo, $partyId))) . '.');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $b = bill_is_manual($pdo, $id);
            if (!$b) {
                throw new RuntimeException('This bill cannot be deleted here.');
            }
            in_tx($pdo, function () use ($pdo, $id) {
                $pdo->prepare("DELETE FROM party_ledger WHERE bill_id = ? AND entry_type = 'bill'")->execute([$id]);
                $pdo->prepare('DELETE FROM bills WHERE id = ?')->execute([$id]);
            });
            flash('ok', 'Bill ' . $b['bill_no'] . ' deleted. The dealer outstanding went back down.');
        } elseif ($action === 'count') { // an unfinished bill from the old screen: count it
            $no = billing_approve($pdo, $user, (int)($_POST['id'] ?? 0));
            flash('ok', 'Bill ' . $no . ' is now counted.');
        } elseif ($action === 'remove') { // an unfinished bill from the old screen: throw it away
            $no = billing_close($pdo, $user, (int)($_POST['id'] ?? 0), 'cancelled', '');
            flash('ok', 'Bill ' . $no . ' removed.');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
        if ($action === 'save') {
            redirect('billing.php' . ((int)($_POST['id'] ?? 0) > 0 ? '?edit=' . (int)$_POST['id'] : ''));
        }
    }
    redirect('billing.php');
}

$dealers = $pdo->query('SELECT id, name, is_active FROM parties ORDER BY is_active DESC, name')->fetchAll();
$filter = (int)($_GET['dealer'] ?? 0);

// ----- the form: new bill, or an existing one being edited -----
$edit = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $edit = bill_is_manual($pdo, $editId);
}
$f = [
    'id'       => $edit ? (int)$edit['id'] : 0,
    'party_id' => $edit ? (int)$edit['party_id'] : (int)($_GET['p'] ?? 0),
    'bill_no'  => $edit ? $edit['bill_no'] : '',
    'bill_date' => $edit ? $edit['bill_date'] : biz_today(),
    'amount'   => $edit ? rtrim(rtrim(number_format((float)$edit['total_amount'], 2, '.', ''), '0'), '.') : '',
    'details'  => ($edit && $hasDetails) ? (string)$edit['details'] : '',
];

// ----- last bill of every dealer -----
$last = $pdo->query(
    "SELECT p.id, p.name, b.bill_no, b.bill_date, b.total_amount
     FROM parties p LEFT JOIN bills b ON b.id = (SELECT b2.id FROM bills b2 WHERE b2.party_id = p.id AND b2.status = 'approved' ORDER BY b2.bill_date DESC, b2.id DESC LIMIT 1)
     WHERE p.is_active = 1 ORDER BY p.name"
)->fetchAll();

// ----- recent bills -----
$det = $hasDetails ? 'b.details' : 'NULL';
$sql = "SELECT b.id, b.bill_no, b.bill_date, b.total_amount, b.status, $det AS details, p.name AS dealer,
               (SELECT COUNT(*) FROM bill_items bi WHERE bi.bill_id = b.id) AS n_lines,
               (SELECT GROUP_CONCAT(CONCAT(pr.name, ' x ', bi.qty) SEPARATOR ', ') FROM bill_items bi JOIN products pr ON pr.id = bi.product_id WHERE bi.bill_id = b.id) AS old_items
        FROM bills b JOIN parties p ON p.id = b.party_id
        WHERE b.status IN ('approved','made','sent')" . ($filter > 0 ? ' AND b.party_id = ?' : '') . '
        ORDER BY b.bill_date DESC, b.id DESC LIMIT 100';
$st = $pdo->prepare($sql);
$st->execute($filter > 0 ? [$filter] : []);
$bills = $st->fetchAll();

$dateInput = 'style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"';
// order bill check: how many parcels of each dealer are verified and have the bill done, for one day or a range
$ckReady = order_check_ready($pdo);
$ckFrom = valid_date((string)($_GET['ckfrom'] ?? '')) ?? biz_today();
$ckTo = valid_date((string)($_GET['ckto'] ?? '')) ?? $ckFrom;
if ($ckTo < $ckFrom) {
    $ckTo = $ckFrom;
}
$ckRows = $ckReady ? order_check_by_dealer($pdo, $ckFrom, $ckTo) : [];
$pageTitle = 'Billing';
$active = 'billing';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<style>
.bf{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
.bf .field{margin:0}
.bf textarea{width:100%;min-height:130px;padding:12px 14px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);color:var(--ink);font:inherit;resize:vertical;line-height:1.5}
.bf textarea:focus{outline:2px solid var(--ink);outline-offset:1px}
.bf .full{grid-column:1/-1}
.lb{display:flex;flex-direction:column}
.lb .r{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:11px 0;border-top:1px solid var(--line);font-weight:600}
.lb .r:first-child{border-top:0}
.lb .r small{display:block;color:var(--muted);font-size:12px;font-weight:700}
.bl{display:flex;flex-direction:column;gap:10px}
.bl .b{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:14px 16px}
.bl .top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.bl .nm{font-weight:800}
.bl .meta{font-size:12px;color:var(--muted);font-weight:700;margin-top:2px}
.bl .amt{font-weight:800;font-size:18px;font-variant-numeric:tabular-nums;white-space:nowrap}
.bl .notes{margin-top:8px;font-size:14px;font-weight:600;white-space:pre-wrap;word-break:break-word;color:#334155}
.bl .acts{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;align-items:center}
.bl .acts form{display:inline}
</style>

<?php if (!$hasDetails): ?>
<div class="flash flash-error">One small step is missing: run <b>migrate_simple_billing.sql</b> in phpMyAdmin. Until then bills cannot be saved.</div>
<?php endif; ?>

<section class="card" id="form">
  <h2><?= $f['id'] ? 'Edit bill' : 'Add a bill' ?></h2>
  <form method="post" autocomplete="off" id="billform">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
    <div class="bf">
      <div class="field"><label for="party_id">Dealer name</label>
        <select id="party_id" name="party_id" required>
          <option value="">Choose dealer</option>
          <?php foreach ($dealers as $d): if (!$d['is_active'] && (int)$d['id'] !== $f['party_id']) { continue; } ?>
            <option value="<?= (int)$d['id'] ?>"<?= $f['party_id'] === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label for="bill_no">Bill number</label><input id="bill_no" name="bill_no" type="text" maxlength="60" value="<?= e($f['bill_no']) ?>" required></div>
      <div class="field"><label for="bill_date">Bill date</label><input id="bill_date" name="bill_date" type="date" value="<?= e($f['bill_date']) ?>" required <?= $dateInput ?>></div>
      <div class="field"><label for="amount">Total bill amount (₹)</label><input id="amount" name="amount" type="text" inputmode="decimal" value="<?= e($f['amount']) ?>" required></div>
      <div class="field full"><label for="details">Notes / product details</label>
        <textarea id="details" name="details" placeholder="Order #1001 – Dior Sauvage&#10;Order #1002 – Azzaro Most Wanted&#10;Order #1003 – JPG Ultra Male"><?= e($f['details']) ?></textarea></div>
    </div>
    <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap">
      <button class="btn btn-accent" type="submit" <?= $hasDetails ? '' : 'disabled' ?>><?= $f['id'] ? 'Save changes' : 'Save bill' ?></button>
      <?php if ($f['id']): ?><a class="btn btn-ghost linkbtn" href="billing.php">Cancel</a><?php endif; ?>
    </div>
    <div class="hint">Saving adds the bill amount to what you owe the dealer. Payments you make are recorded on the dealer's page.</div>
  </form>
</section>

<section class="card" style="margin-top:16px" id="order-check">
  <h2>Order bill check</h2>
  <form method="get" class="filters" style="margin-bottom:12px">
    <div><label for="ckfrom">From</label><input id="ckfrom" name="ckfrom" type="date" value="<?= e($ckFrom) ?>"></div>
    <div><label for="ckto">To</label><input id="ckto" name="ckto" type="date" value="<?= e($ckTo) ?>"></div>
    <button class="btn" type="submit">Show</button>
  </form>
  <?php if (!$ckReady): ?>
    <div class="empty">The check columns could not be added to the orders table yet. Open the Orders page once, or run the ALTER TABLE shown there.</div>
  <?php elseif (!$ckRows): ?>
    <div class="empty">No orders in this period.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="tight" style="min-width:420px">
      <thead><tr><th>Dealer</th><th class="num">Parcels</th><th class="num">Verified</th><th class="num">Bill done</th><th class="num">Bill pending</th></tr></thead>
      <tbody>
      <?php foreach ($ckRows as $cr): $pend = (int)$cr['parcels'] - (int)$cr['billed']; ?>
        <tr>
          <td><?= e($cr['dealer']) ?></td>
          <td class="num"><?= (int)$cr['parcels'] ?></td>
          <td class="num"><?= (int)$cr['verified'] ?></td>
          <td class="num"><?= (int)$cr['billed'] ?></td>
          <td class="num"><?= $pend > 0 ? '<span class="tag tag-warn">' . $pend . '</span>' : '<span class="tag tag-ok">0</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="hint">A parcel counts under every dealer it has an item from. Verify and Bill done are marked on the Orders page. Refunded orders are left out.</div>
  <?php endif; ?>
</section>

<section class="card" style="margin-top:16px">
  <h2>Last bill of each dealer</h2>
  <?php if (!$last): ?>
    <div class="empty">No dealers yet.</div>
  <?php else: ?>
  <div class="lb">
    <?php foreach ($last as $r): ?>
    <div class="r">
      <span><?= e($r['name']) ?><small><?= $r['bill_no'] !== null ? 'Bill ' . e($r['bill_no']) : 'No bill yet' ?></small></span>
      <span style="text-align:right"><?php if ($r['bill_no'] !== null): ?><b><?= e(date('d M Y', strtotime($r['bill_date']))) ?></b><small><?= e(inr($r['total_amount'])) ?></small><?php else: ?>-<?php endif; ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<section style="margin-top:16px">
  <form method="get" class="filters" style="margin-bottom:12px">
    <div><label for="dealer">Show bills of</label>
      <select id="dealer" name="dealer" onchange="this.form.submit()">
        <option value="0">All dealers</option>
        <?php foreach ($dealers as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $filter === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
      </select></div>
  </form>
  <?php if (!$bills): ?>
    <div class="card"><div class="empty">No bills yet.</div></div>
  <?php else: ?>
  <div class="bl">
    <?php foreach ($bills as $b): $manual = ((int)$b['n_lines'] === 0 && $b['status'] === 'approved'); ?>
    <div class="b">
      <div class="top">
        <div><div class="nm"><?= e($b['dealer']) ?> · Bill <?= e($b['bill_no']) ?></div>
          <div class="meta"><?= e(date('d M Y', strtotime($b['bill_date']))) ?><?php if ($b['status'] !== 'approved'): ?> · <span class="tag tag-warn">Old draft, not counted</span><?php elseif (!$manual): ?> · <span class="tag">Made in the old billing screen</span><?php endif; ?></div></div>
        <div class="amt"><?= e(inr($b['total_amount'])) ?></div>
      </div>
      <?php $notes = trim((string)($b['details'] ?? '')) !== '' ? (string)$b['details'] : (string)($b['old_items'] ?? ''); ?>
      <?php if ($notes !== ''): ?><div class="notes"><?= e($notes) ?></div><?php endif; ?>
      <?php if ($manual): ?>
      <div class="acts">
        <a class="btn btn-ghost btn-sm linkbtn" href="billing.php?edit=<?= (int)$b['id'] ?>#form">Edit</a>
        <form method="post" onsubmit="return confirm('Delete bill <?= e($b['bill_no']) ?>? The amount is taken off what you owe the dealer.');">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <button class="btn btn-ghost btn-sm" type="submit">Delete</button>
        </form>
      </div>
      <?php elseif ($b['status'] !== 'approved'): ?>
      <div class="acts">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="count"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn-sm" type="submit">Count this bill</button></form>
        <form method="post" onsubmit="return confirm('Remove this draft bill?');"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Remove</button></form>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if (count($bills) >= 100): ?><p class="hint">Showing the newest 100 bills. Pick a dealer to narrow it down.</p><?php endif; ?>
  <?php endif; ?>
</section>
<script>
(function () {
  var f = document.getElementById('billform');
  f.addEventListener('submit', function () {
    var b = f.querySelector('button[type=submit]');
    if (b) { setTimeout(function () { b.disabled = true; }, 0); } // one click saves once
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>