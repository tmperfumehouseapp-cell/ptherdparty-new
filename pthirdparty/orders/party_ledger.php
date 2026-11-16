<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/proof_lib.php';
require_once __DIR__ . '/includes/simple_lib.php';
require_once __DIR__ . '/includes/dealer_lib.php';
require_once __DIR__ . '/includes/ledger_edit_lib.php';
require_once __DIR__ . '/includes/profit_mode_lib.php';
require_once __DIR__ . '/includes/qr_move_lib.php';
$user = require_admin();
$pdo = db();

$partyId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT id, name, phone FROM parties WHERE id = ?');
$st->execute([$partyId]);
$party = $st->fetch();
if (!$party) {
    http_response_code(404);
    exit('Party not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'payment') {
            $acc = active_account_id($_POST['account_id'] ?? 0);
            $amt = round(num($_POST['amount'] ?? 0), 2);
            $d = valid_date((string)($_POST['entry_date'] ?? '')) ?? biz_today();
            $note = trim((string)($_POST['note'] ?? ''));
            if (!$acc || $amt <= 0) {
                throw new RuntimeException('Choose the account and enter an amount.');
            }
            $method = (($_POST['pay_method'] ?? '') === 'cash') ? 'cash' : 'online';
            // the same payment twice (a double click) is not counted twice
            $dupP = $pdo->prepare("SELECT COUNT(*) FROM party_ledger WHERE party_id = ? AND entry_type = 'payment' AND amount = ? AND account_id = ? AND entry_date = ? AND created_at >= NOW() - INTERVAL 120 SECOND");
            $dupP->execute([$partyId, $amt, $acc, $d]);
            if ((int)$dupP->fetchColumn() > 0) {
                throw new RuntimeException('This payment of ' . inr($amt) . ' was just saved. Not added twice.');
            }
            // online: the screenshot is optional but saved when attached; cash: just the amount
            $proofFile = ($method === 'online' && proof_uploaded($_FILES['pay_proof'] ?? null)) ? save_proof_upload($_FILES['pay_proof']) : null;
            try {
                in_tx($pdo, function () use ($pdo, $user, $partyId, $acc, $amt, $d, $note, $party, $method, $proofFile) {
                    $pdo->prepare("INSERT INTO account_ledger (account_id, entry_date, direction, amount, entry_type, party_id, note, created_by) VALUES (?, ?, 'out', ?, 'party_payment', ?, ?, ?)")
                        ->execute([$acc, $d, $amt, $partyId, 'Paid to ' . $party['name'] . ' (' . $method . ')' . ($note !== '' ? ' - ' . $note : ''), (int)$user['id']]);
                    $payKind = in_array((string)($_POST['pay_kind'] ?? ''), ['cod', 'extra'], true) ? (string)$_POST['pay_kind'] : null;
                    if ($payKind !== null && dealer_has_paykind($pdo)) {
                        $pdo->prepare("INSERT INTO party_ledger (party_id, entry_date, entry_type, amount, account_id, note, created_by, pay_kind) VALUES (?, ?, 'payment', ?, ?, ?, ?, ?)")
                            ->execute([$partyId, $d, $amt, $acc, $note === '' ? null : mb_substr($note, 0, 255), (int)$user['id'], $payKind]);
                    } else {
                        $pdo->prepare("INSERT INTO party_ledger (party_id, entry_date, entry_type, amount, account_id, note, created_by) VALUES (?, ?, 'payment', ?, ?, ?, ?)")
                            ->execute([$partyId, $d, $amt, $acc, $note === '' ? null : mb_substr($note, 0, 255), (int)$user['id']]);
                    }
                    proof_add($pdo, 'party_payment', (int)$pdo->lastInsertId(), $method, $proofFile, (int)$user['id']);
                });
            } catch (Throwable $ex) {
                proof_remove_file($proofFile);
                throw $ex;
            }
            flash('ok', 'Payment of ' . inr($amt) . ' saved.');
        } elseif ($action === 'move_entry') {
            // customer money recorded on the wrong dealer's QR: take it out of this dealer and put it into the chosen dealer's QR account
            flash('ok', qr_move_entry($pdo, $user, (int)($_POST['entry_id'] ?? 0), $partyId, (int)($_POST['to_account'] ?? 0)));
        } elseif ($action === 'profit_mode') {
            $on = (string)($_POST['mode'] ?? '') === 'on';
            $n = profit_mode_apply($pdo, $user, $partyId, $on);
            flash('ok', $on
                ? 'Profit-only mode is ON for ' . $party['name'] . '. ' . $n . ' QR entr' . ($n === 1 ? 'y' : 'ies') . ' recalculated.'
                : 'Profit-only mode is OFF for ' . $party['name'] . '. ' . $n . ' QR entr' . ($n === 1 ? 'y' : 'ies') . ' restored to the real amount.');
        } elseif ($action === 'opening_edit') {
            // fix the opening entry of this dealer: who owes, amount, date (amount 0 removes it)
            $amt = round(abs(num($_POST['amount'] ?? 0)), 2);
            if (($_POST['who'] ?? 'i_owe') === 'owes_me') {
                $amt = -$amt;
            }
            $d = valid_date((string)($_POST['entry_date'] ?? ''));
            if ($d === null) {
                throw new RuntimeException('Enter the opening date.');
            }
            ledger_opening_save($pdo, $user, $partyId, (int)($_POST['entry_id'] ?? 0), $amt, $d);
            flash('ok', 'Opening entry saved. The balance is recalculated.');
        }
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') {
            flash('error', 'Could not save. Try again.');
        } else {
            throw $ex;
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    $backTo = (string)($_POST['back'] ?? '');
    redirect(preg_match('~^(dealer_ledger|party_ledger)\.php(\?[A-Za-z0-9_=&%.\-]*)?$~', $backTo) ? $backTo : 'party_ledger.php?id=' . $partyId);
}

$moveTargets = qr_move_targets($pdo, $partyId);
$pmReady = profit_mode_ready($pdo);
$pmOn = $pmReady && profit_mode_on($pdo, $partyId);
$entries = $pdo->prepare(
    "SELECT " . ($pmReady ? 'l.orig_amount, ' : 'NULL AS orig_amount, ') . "l.id, l.entry_date, l.entry_type, l.amount, l.note, l.bill_id, l.ledger_id, a.name AS account, b.bill_no, " . (simple_has_details($pdo) ? 'b.details' : 'NULL') . " AS details,
            pp.id AS proof_id, pp.method AS pay_method, pp.file AS proof_file
     FROM party_ledger l LEFT JOIN accounts a ON a.id = l.account_id LEFT JOIN bills b ON b.id = l.bill_id
     LEFT JOIN payment_proofs pp ON pp.kind = 'party_payment' AND pp.ref_id = l.id AND l.entry_type = 'payment'
     WHERE l.party_id = ? ORDER BY l.entry_date, l.id"
);
$entries->execute([$partyId]);
$entryRows = $entries->fetchAll();
$run = 0.0;
foreach ($entryRows as &$er) {
    $run += in_array($er['entry_type'], ['payment', 'collected'], true) ? -(float)$er['amount'] : (float)$er['amount'];
    $er['running'] = $run;
}
unset($er);
$balance = $run;
$entryRows = array_reverse($entryRows);

$billItems = $pdo->prepare(
    'SELECT bi.bill_id, bi.qty, bi.rate, bi.rate_mismatch, bi.excess_qty, p.name,
            (SELECT pp.base_price FROM product_prices pp WHERE pp.product_id = bi.product_id ORDER BY pp.id DESC LIMIT 1) AS base
     FROM bill_items bi JOIN bills b ON b.id = bi.bill_id JOIN products p ON p.id = bi.product_id WHERE b.party_id = ? ORDER BY bi.id'
);
$billItems->execute([$partyId]);
$itemsByBill = [];
foreach ($billItems->fetchAll() as $bi) {
    $itemsByBill[$bi['bill_id']][] = $bi;
}

$accounts = $pdo->query('SELECT id, name FROM accounts WHERE is_active = 1 ORDER BY name')->fetchAll();
$dateInput = 'style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"';

$pageTitle = $party['name'];
$active = 'dealers';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<p style="margin:-8px 0 16px"><a href="dealers.php" class="mini" style="text-decoration:none">&larr; Dealers</a></p>
<div class="stats">
  <div class="stat"><div class="k">Balance</div>
    <div class="v <?= $balance < 0 ? 'pos' : '' ?>"><?= $balance > 0 ? 'You owe ' . e(inr($balance)) : ($balance < 0 ? 'They owe you ' . e(inr(-$balance)) : 'Settled') ?></div></div>
</div>

<div class="grid">
  <div class="wide stack">
    <section class="card">
      <h2>Bills</h2>
      <p style="margin:0 0 12px;font-weight:600">Each bill you add on the Billing page is added to what you owe this dealer.</p>
      <a class="btn btn-accent linkbtn" href="billing.php?p=<?= (int)$partyId ?>#form">Add a bill for <?= e($party['name']) ?></a>
      <a class="btn btn-ghost linkbtn" href="billing.php?dealer=<?= (int)$partyId ?>">See all bills</a>
    </section>

    <section class="card" id="profit-mode">
      <h2>Customer money on this dealer's QR</h2>
      <?php if (!$pmReady): ?>
        <div class="mini">The small database update for this could not be added yet. Reload the page once.</div>
      <?php else: ?>
      <p style="margin:0 0 10px;font-weight:600">
        <?= $pmOn ? 'Profit only: when a customer pays on this dealer\'s QR, the balance goes down by the order profit, once per order.'
                  : 'Whole amount: when a customer pays on this dealer\'s QR, the balance goes down by the full amount paid.' ?>
        <span class="tag <?= $pmOn ? 'tag-ok' : '' ?>"><?= $pmOn ? 'Profit only: ON' : 'OFF' ?></span>
      </p>
      <form method="post" onsubmit="return confirm('<?= $pmOn ? 'Turn profit-only OFF for ' . e($party['name']) . '? All its QR entries go back to the full amount paid.' : 'Turn profit-only ON for ' . e($party['name']) . '? Its QR entries are recalculated to the order profit. The real amounts are saved and can be restored.' ?>');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="profit_mode">
        <input type="hidden" name="id" value="<?= (int)$partyId ?>">
        <input type="hidden" name="mode" value="<?= $pmOn ? 'off' : 'on' ?>">
        <button class="btn <?= $pmOn ? 'btn-ghost' : 'btn-accent' ?> btn-sm" type="submit"><?= $pmOn ? 'Turn off' : 'Turn on profit only' ?></button>
      </form>
      <div class="hint">Only this dealer is affected. Every recalculated entry keeps its real amount, so turning it off restores everything.</div>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Ledger</h2>
      <p style="margin:-6px 0 10px"><a class="mini" href="ledger_entry.php?history=<?= (int)$partyId ?>">Edited / deleted entries</a></p>
      <?php if (!$entryRows): ?>
        <div class="empty">No entries yet.</div>
      <?php else: ?>
      <div class="table-wrap"><table class="tight" style="min-width:520px">
        <thead><tr><th>Date</th><th>Entry</th><th class="num">Amount</th><th class="num">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($entryRows as $r): ?>
          <?php $flagged = false; foreach ($itemsByBill[$r['bill_id']] ?? [] as $bi) { if ($bi['rate_mismatch'] || $bi['excess_qty']) { $flagged = true; } } ?>
          <tr>
            <td><?= e(date('d M Y', strtotime($r['entry_date']))) ?></td>
            <td>
              <span class="tag <?= in_array($r['entry_type'], ['payment', 'collected'], true) ? 'tag-ok' : ($r['entry_type'] === 'bill' ? 'tag-info' : '') ?>"><?= e(['opening' => 'Opening', 'bill' => 'Bill', 'payment' => 'Payment', 'collected' => 'Collected on your QR', 'returned' => 'Refund from your QR'][$r['entry_type']] ?? $r['entry_type']) ?></span>
              <?php if ($flagged): ?><span class="tag tag-bad">Flagged</span><?php endif; ?>
              <?php if (!empty($r['pay_method'])): ?><span class="tag"><?= $r['pay_method'] === 'cash' ? 'Cash' : 'Online' ?></span><?php endif; ?>
              <?php if (!empty($r['proof_file'])): ?><a class="mini" href="proof.php?id=<?= (int)$r['proof_id'] ?>" target="_blank" rel="noopener">View screenshot</a><?php endif; ?>
              <?php if ($r['account']): ?><span class="mini"> <?= in_array($r['entry_type'], ['collected', 'returned'], true) ? 'on' : 'from' ?> <?= e($r['account']) ?></span><?php endif; ?>
              <?php if ($r['note']): ?><div class="mini"><?= e($r['note']) ?></div><?php endif; ?>
              <?php if ($r['orig_amount'] !== null && abs((float)$r['orig_amount'] - (float)$r['amount']) > 0.004): ?><div class="mini">Customer paid <?= e(inr($r['orig_amount'])) ?> · counted: profit only</div><?php endif; ?>
              <?php if (in_array($r['entry_type'], ['collected', 'returned'], true) && $r['ledger_id'] !== null): ?>
              <details style="margin-top:6px"><summary class="btn btn-ghost btn-sm" style="display:inline-flex;cursor:pointer">Move to another dealer</summary>
                <?php if (!$moveTargets): ?>
                  <div class="hint">No other dealer has a QR account yet. Create one on the Accounts page first.</div>
                <?php else: ?>
                <form method="post" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-top:10px" onsubmit="return confirm('Move this entry (<?= e(inr($r['orig_amount'] ?? $r['amount'])) ?>) to the dealer you chose? It is removed from <?= e($party['name']) ?> and added to the other dealer.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="move_entry">
                  <input type="hidden" name="id" value="<?= (int)$partyId ?>">
                  <input type="hidden" name="entry_id" value="<?= (int)$r['id'] ?>">
                  <div class="field" style="margin:0;flex:1 1 220px"><label>Move to this dealer's QR</label>
                    <select name="to_account" required><option value="">Choose dealer</option>
                      <?php foreach ($moveTargets as $mt): ?><option value="<?= (int)$mt['id'] ?>"><?= e($mt['dealer'] . ' - ' . $mt['account']) ?></option><?php endforeach; ?>
                    </select></div>
                  <button class="btn btn-accent btn-sm" type="submit">Move</button>
                </form>
                <div class="hint">The money also moves to that dealer's QR account. The amount counted follows the other dealer's setting (whole amount or profit only). Kept in the change history.</div>
                <?php endif; ?>
              </details>
              <?php endif; ?>
              <?php if ($r['entry_type'] === 'opening'): ?>
              <details style="margin-top:6px"><summary class="btn btn-ghost btn-sm" style="display:inline-flex;cursor:pointer">Edit opening</summary>
                <form method="post" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-top:10px">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="opening_edit">
                  <input type="hidden" name="id" value="<?= (int)$partyId ?>">
                  <input type="hidden" name="entry_id" value="<?= (int)$r['id'] ?>">
                  <div class="field" style="margin:0;flex:1 1 150px"><label>Who owes?</label>
                    <select name="who"><option value="i_owe"<?= (float)$r['amount'] >= 0 ? ' selected' : '' ?>>I owe the dealer</option><option value="owes_me"<?= (float)$r['amount'] < 0 ? ' selected' : '' ?>>The dealer owes me</option></select></div>
                  <div class="field" style="margin:0;flex:1 1 130px"><label>Amount (₹)</label><input name="amount" type="text" inputmode="decimal" value="<?= e((string)abs((float)$r['amount'])) ?>"></div>
                  <div class="field" style="margin:0;flex:1 1 160px"><label>Opening date</label><input name="entry_date" type="date" value="<?= e($r['entry_date']) ?>" <?= $dateInput ?>></div>
                  <button class="btn btn-accent btn-sm" type="submit">Save</button>
                </form>
                <div class="hint">Set the amount to 0 to remove the opening entry. Every change is kept in the change history.</div>
              </details>
              <?php endif; ?>
              <?php if ($r['entry_type'] === 'payment' && $r['ledger_id'] === null): ?>
              <div class="acts" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
                <a class="btn btn-ghost btn-sm linkbtn" href="ledger_entry.php?id=<?= (int)$r['id'] ?>&amp;back=<?= e(rawurlencode('party_ledger.php?id=' . $partyId)) ?>">Edit Payment</a>
                <form method="post" action="ledger_entry.php" onsubmit="return confirm('Delete this payment of <?= e(inr($r['amount'])) ?>? The amount is added back to what you owe the dealer.');">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= e('party_ledger.php?id=' . $partyId) ?>">
                  <button class="btn btn-ghost btn-sm" type="submit">Delete Payment</button>
                </form>
              </div>
              <?php endif; ?>
              <?php if ($r['entry_type'] === 'bill' && trim((string)($r['details'] ?? '')) !== ''): ?><div class="mini" style="white-space:pre-wrap;margin-top:4px"><?= e($r['details']) ?></div><?php endif; ?>
              <?php if ($r['bill_id'] && !empty($itemsByBill[$r['bill_id']])): ?>
                <details style="margin-top:4px"><summary class="mini" style="cursor:pointer">Bill lines</summary>
                  <?php foreach ($itemsByBill[$r['bill_id']] as $bi): ?>
                    <div class="mini" style="margin:3px 0">
                      <?= e($bi['name']) ?> × <?= (int)$bi['qty'] ?> at <?= e(inr($bi['rate'])) ?>
                      <?php if ($bi['rate_mismatch']): ?><span class="tag tag-bad">Rate differs<?= $bi['base'] !== null ? ' (base ' . e(inr($bi['base'])) . ')' : '' ?></span><?php endif; ?>
                      <?php if ($bi['excess_qty']): ?><span class="tag tag-warn">Excess <?= (int)$bi['excess_qty'] ?></span><?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </details>
              <?php endif; ?>
            </td>
            <td class="num <?= in_array($r['entry_type'], ['payment', 'collected'], true) ? 'pos' : '' ?>"><?= in_array($r['entry_type'], ['payment', 'collected'], true) ? '-' : '+' ?><?= e(inr($r['amount'])) ?></td>
            <td class="num"><?= $r['running'] > 0 ? e(inr($r['running'])) . ' owed' : ($r['running'] < 0 ? e(inr(-$r['running'])) . ' advance' : 'Settled') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
  </div>

  <section class="card narrow">
    <h2>Record a payment</h2>
    <form method="post" autocomplete="off" enctype="multipart/form-data" id="payform" data-owed="<?= e(number_format(max(0.0, $balance), 2, '.', '')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="payment"><input type="hidden" name="id" value="<?= (int)$partyId ?>">
      <div class="field"><label for="p_acc">Paid from account</label>
        <select id="p_acc" name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="p_method">How paid</label>
        <select id="p_method" name="pay_method"><option value="online">Online</option><option value="cash">Cash</option></select></div>
      <div class="field"><label for="p_amount">Amount (₹)</label><input id="p_amount" name="amount" type="text" inputmode="decimal" required></div>
      <div class="field"><label for="p_date">Date</label><input id="p_date" name="entry_date" type="date" value="<?= e(biz_today()) ?>" <?= $dateInput ?>></div>
      <div class="field"><label for="p_note">Note</label><input id="p_note" name="note" type="text" placeholder="UTR / reference"></div>
      <div class="field" id="proof-field"><label for="p_proof">Payment screenshot (optional)</label><input id="p_proof" name="pay_proof" type="file" accept="image/*,application/pdf"></div>
      <button class="btn btn-accent btn-block" type="submit">Save payment</button>
      <div class="hint">Money goes out of the chosen account and the amount is deducted from what you owe.</div>
    </form>
  </section>
</div>
<script>
(function () {
  var sel = document.getElementById('p_method'), box = document.getElementById('proof-field');
  function sync() { box.style.display = sel.value === 'cash' ? 'none' : ''; }
  sel.addEventListener('change', sync);
  sync();
  // one click saves once, and a payment bigger than what you owe asks first (catches an extra zero)
  var f = document.getElementById('payform');
  f.addEventListener('submit', function (e) {
    var amt = parseFloat(String(document.getElementById('p_amount').value).replace(/[^0-9.]/g, '')) || 0;
    var owed = parseFloat(f.getAttribute('data-owed')) || 0;
    if (amt > owed + 0.5 && !confirm('You owe ' + owed.toLocaleString('en-IN') + ' but are paying ' + amt.toLocaleString('en-IN') + '. The dealer will then owe you the difference. Save this payment?')) {
      e.preventDefault();
      return;
    }
    var b = f.querySelector('button[type=submit]');
    if (b) { b.disabled = true; }
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>