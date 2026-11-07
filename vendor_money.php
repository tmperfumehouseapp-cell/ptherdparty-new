<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ops_lib.php';
$user = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'remit') {
            $splits = is_array($_POST['split'] ?? null) ? $_POST['split'] : [];
            add_remittance(
                $pdo, $user, (int)($_POST['account_id'] ?? 0), num($_POST['amount'] ?? 0),
                (string)($_POST['entry_date'] ?? ''), trim((string)($_POST['note'] ?? '')), $splits
            );
            flash('ok', 'COD remittance saved.');
        } elseif ($action === 'delete_remit') {
            delete_remittance($pdo, (int)($_POST['id'] ?? 0));
            flash('ok', 'COD remittance deleted.');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('vendor_money.php');
}

$summary = vendor_summary($pdo);
$totOwed = 0.0;
$totHeld = 0.0;
foreach ($summary as &$s) {
    $s['held'] = max(0.0, (float)$s['held_all'] - (float)$s['paid']);
    // what is due for orders that reached the customer: their cost, less what you already paid or the vendor already holds
    $s['due_delivered'] = max(0.0, (float)$s['delivered_cost'] - (float)$s['paid'] - (float)$s['on_qr']);
    $totOwed += (float)$s['owed'];
    $totHeld += $s['held'];
}
unset($s);

$ownMoney = (float)$pdo->query(
    "SELECT COALESCE(SUM(a.opening_balance),0) + COALESCE((SELECT SUM(CASE WHEN l.direction='in' THEN l.amount ELSE -l.amount END) FROM account_ledger l JOIN accounts x ON x.id = l.account_id WHERE x.party_id IS NULL),0)
     FROM accounts a WHERE a.party_id IS NULL"
)->fetchColumn();

$accounts = $pdo->query('SELECT a.id, a.name, p.name AS owner FROM accounts a LEFT JOIN parties p ON p.id = a.party_id WHERE a.is_active = 1 ORDER BY (a.party_id IS NOT NULL), a.name')->fetchAll();
$activeParties = array_values(array_filter($summary, fn($s) => $s['is_active']));

$remits = $pdo->query(
    "SELECT l.id, l.entry_date, l.amount, l.note, a.name AS account,
            (SELECT GROUP_CONCAT(CONCAT(p.name, ' ', FORMAT(h.amount, 0)) SEPARATOR ', ') FROM vendor_holds h JOIN parties p ON p.id = h.party_id WHERE h.ledger_id = l.id) AS split_text
     FROM account_ledger l JOIN accounts a ON a.id = l.account_id
     WHERE l.entry_type = 'cod' AND l.order_id IS NULL AND l.direction = 'in'
     ORDER BY l.entry_date DESC, l.id DESC LIMIT 50"
)->fetchAll();

$filterParty = (int)($_GET['party'] ?? 0);
$hq = "SELECT h.entry_date, h.amount, h.source, h.order_id, h.note, p.name AS party
       FROM vendor_holds h JOIN parties p ON p.id = h.party_id" . ($filterParty > 0 ? ' WHERE h.party_id = ?' : '') .
      ' ORDER BY h.entry_date DESC, h.id DESC LIMIT 150';
$hst = $pdo->prepare($hq);
$hst->execute($filterParty > 0 ? [$filterParty] : []);
$holds = $hst->fetchAll();

$dateInput = 'style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"';

$pageTitle = 'Vendor money';
$active = 'vendor_money';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<div class="stats">
  <div class="stat"><div class="k">You owe vendors</div><div class="v"><?= e(inr($totOwed)) ?></div></div>
  <div class="stat"><div class="k">Held for vendors</div><div class="v"><?= e(inr($totHeld)) ?></div><div class="hint" style="margin:2px 0 0">inside your own accounts</div></div>
  <div class="stat"><div class="k">Your own accounts</div><div class="v"><?= e(inr($ownMoney)) ?></div></div>
  <div class="stat"><div class="k">Free after vendors</div><div class="v <?= $ownMoney - $totHeld < 0 ? 'neg' : 'pos' ?>"><?= e(inr($ownMoney - $totHeld)) ?></div></div>
</div>

<div class="grid">
  <div class="wide stack">
    <section class="card">
      <h2>Each vendor</h2>
      <?php if (!$summary): ?>
        <div class="empty">No parties yet.</div>
      <?php else: ?>
      <div class="table-wrap"><table class="tight" style="min-width:760px">
        <thead><tr><th>Vendor</th><th class="num">You owe</th><th class="num">Delivered orders (cost)</th><th class="num">Due for delivered</th><th class="num">Collected on their QR</th><th class="num">Held in your accounts</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($summary as $s): ?>
          <tr>
            <td><?= e($s['name']) ?></td>
            <td class="num <?= $s['owed'] < 0 ? 'pos' : '' ?>"><?= $s['owed'] > 0 ? e(inr($s['owed'])) : ($s['owed'] < 0 ? 'They owe ' . e(inr(-$s['owed'])) : 'Settled') ?></td>
            <td class="num"><?= e(inr($s['delivered_cost'])) ?></td>
            <td class="num"><b><?= e(inr($s['due_delivered'])) ?></b></td>
            <td class="num"><?= e(inr($s['on_qr'])) ?></td>
            <td class="num"><a class="rowlink" href="vendor_money.php?party=<?= (int)$s['id'] ?>"><?= e(inr($s['held'])) ?></a></td>
            <td class="num"><a class="btn btn-ghost btn-sm linkbtn" href="party_ledger.php?id=<?= (int)$s['id'] ?>">Ledger</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="hint">
        <b>Delivered orders (cost):</b> the base cost of this vendor's items on orders marked delivered (upload your delivered CSV on the Orders page). <b>Due for delivered:</b> that cost minus what you have paid the vendor and what the vendor already holds on their QR. Old dues from before the panel are not in this number.<br>
        <b>Collected on their QR:</b> customer money that came into that vendor's own account. It is already taken off "You owe".<br>
        <b>Held in your accounts:</b> money that came into your own accounts and belongs to that vendor (their cost share of each order, vendors first, plus the splits you enter below). It goes down when you pay that vendor. Their ledger changes only when you actually pay them.
      </p>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Held for vendors: history<?php if ($filterParty): ?> <a class="mini" href="vendor_money.php" style="text-decoration:none">(show all)</a><?php endif; ?></h2>
      <?php if (!$holds): ?>
        <div class="empty">Nothing held yet. It fills as orders are paid into your own accounts.</div>
      <?php else: ?>
      <div class="table-wrap"><table class="tight" style="min-width:480px">
        <thead><tr><th>Date</th><th>Vendor</th><th>From</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($holds as $h): ?>
          <tr>
            <td><?= e(date('d M Y', strtotime($h['entry_date']))) ?></td>
            <td><?= e($h['party']) ?></td>
            <td><?php if ($h['order_id']): ?><a class="rowlink" href="order_view.php?id=<?= (int)$h['order_id'] ?>">Order <?= e(order_no($h['order_id'])) ?></a><?php else: ?>COD remittance<?php endif; ?></td>
            <td class="num <?= $h['amount'] < 0 ? 'neg' : 'pos' ?>"><?= $h['amount'] < 0 ? '-' : '+' ?><?= e(inr(abs((float)$h['amount']))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>COD remittances</h2>
      <?php if (!$remits): ?>
        <div class="empty">No COD remittance entered yet.</div>
      <?php else: ?>
      <div class="table-wrap"><table class="tight" style="min-width:520px">
        <thead><tr><th>Date</th><th>Account</th><th>Set aside for</th><th class="num">Amount</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($remits as $r): ?>
          <tr>
            <td><?= e(date('d M Y', strtotime($r['entry_date']))) ?></td>
            <td><?= e($r['account']) ?></td>
            <td><?= $r['split_text'] ? e($r['split_text']) : '<span class="mini">Nothing set aside</span>' ?><?php if ($r['note']): ?><div class="mini"><?= e($r['note']) ?></div><?php endif; ?></td>
            <td class="num pos">+<?= e(inr($r['amount'])) ?></td>
            <td class="num">
              <form class="inline" method="post" onsubmit="return confirm('Delete this remittance? The money entry and its vendor split are removed.');">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete_remit"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
  </div>

  <section class="card narrow">
    <h2>COD money received</h2>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="remit">
      <div class="field"><label for="r_amount">Amount received (₹)</label><input id="r_amount" name="amount" type="text" inputmode="decimal" required></div>
      <div class="field"><label for="r_acc">Came into</label>
        <select id="r_acc" name="account_id">
          <?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?><?= $a['owner'] ? ' (' . e($a['owner']) . ')' : '' ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label for="r_date">Date</label><input id="r_date" name="entry_date" type="date" value="<?= e(biz_today()) ?>" <?= $dateInput ?>></div>
      <div class="field"><label for="r_note">Note (optional)</label><input id="r_note" name="note" type="text" placeholder="Shiprocket remittance, UTR ..."></div>
      <?php if ($activeParties): ?>
      <div style="font-weight:800;margin:14px 0 8px">Set aside for vendors (₹)</div>
      <?php foreach ($activeParties as $s): ?>
        <div class="field" style="margin-bottom:10px"><label for="sp-<?= (int)$s['id'] ?>"><?= e($s['name']) ?></label><input id="sp-<?= (int)$s['id'] ?>" name="split[<?= (int)$s['id'] ?>]" type="text" inputmode="decimal" placeholder="0"></div>
      <?php endforeach; ?>
      <div class="hint">Put any amount against any vendor, it does not have to match the orders. What you leave is yours. If you pick a vendor's own account, that vendor is credited the full amount automatically and these boxes are ignored.</div>
      <?php endif; ?>
      <button class="btn btn-accent btn-block" type="submit" style="margin-top:12px">Save COD received</button>
    </form>
  </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>