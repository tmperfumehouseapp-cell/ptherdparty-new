<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ops_lib.php';
$user = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'entry') {
            $acc = active_account_id($_POST['account_id'] ?? 0);
            $amt = round(num($_POST['amount'] ?? 0), 2);
            $dir = ($_POST['direction'] ?? '') === 'out' ? 'out' : 'in';
            $note = trim((string)($_POST['note'] ?? ''));
            if (!$acc || $amt <= 0) {
                throw new RuntimeException('Choose the account and enter an amount.');
            }
            $pdo->prepare("INSERT INTO account_ledger (account_id, entry_date, direction, amount, entry_type, note, created_by) VALUES (?, ?, ?, ?, 'other', ?, ?)")
                ->execute([$acc, valid_date((string)($_POST['entry_date'] ?? '')) ?? biz_today(), $dir, $amt, $note === '' ? null : mb_substr($note, 0, 255), (int)$user['id']]);
            flash('ok', 'Entry saved.');
        } elseif ($action === 'transfer') {
            $from = active_account_id($_POST['from_id'] ?? 0);
            $to = active_account_id($_POST['to_id'] ?? 0);
            $amt = round(num($_POST['amount'] ?? 0), 2);
            if (!$from || !$to || $from === $to || $amt <= 0) {
                throw new RuntimeException('Choose two different accounts and enter an amount.');
            }
            $d = valid_date((string)($_POST['entry_date'] ?? '')) ?? biz_today();
            $names = $pdo->query('SELECT id, name FROM accounts')->fetchAll(PDO::FETCH_KEY_PAIR);
            in_tx($pdo, function () use ($pdo, $user, $from, $to, $amt, $d, $names) {
                $ins = $pdo->prepare("INSERT INTO account_ledger (account_id, entry_date, direction, amount, entry_type, note, created_by) VALUES (?, ?, ?, ?, 'other', ?, ?)");
                $ins->execute([$from, $d, 'out', $amt, 'Transfer to ' . $names[$to], (int)$user['id']]);
                $ins->execute([$to, $d, 'in', $amt, 'Transfer from ' . $names[$from], (int)$user['id']]);
            });
            flash('ok', 'Transfer saved.');
        } elseif ($action === 'delete') {
            $pdo->prepare("DELETE FROM account_ledger WHERE id = ? AND entry_type = 'other'")->execute([(int)($_POST['id'] ?? 0)]);
            flash('ok', 'Entry deleted. For a transfer, delete the other side too.');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('ledger.php');
}

$accId = (int)($_GET['account'] ?? 0);
$type = (string)($_GET['type'] ?? '');
$from = valid_date((string)($_GET['from'] ?? '')) ?? '';
$to = valid_date((string)($_GET['to'] ?? '')) ?? '';

$where = [];
$params = [];
if ($accId > 0) {
    $where[] = 'l.account_id = ?';
    $params[] = $accId;
}
if (in_array($type, ['prepaid', 'cod', 'party_payment', 'refund', 'other'], true)) {
    $where[] = 'l.entry_type = ?';
    $params[] = $type;
}
if ($from !== '') {
    $where[] = 'l.entry_date >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $where[] = 'l.entry_date <= ?';
    $params[] = $to;
}
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$tot = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN l.direction='in' THEN l.amount END),0) AS i, COALESCE(SUM(CASE WHEN l.direction='out' THEN l.amount END),0) AS o FROM account_ledger l" . $w);
$tot->execute($params);
$t = $tot->fetch();

$st = $pdo->prepare(
    'SELECT l.id, l.entry_date, l.direction, l.amount, l.entry_type, l.order_id, l.note, a.name AS account, pt.name AS party
     FROM account_ledger l JOIN accounts a ON a.id = l.account_id LEFT JOIN parties pt ON pt.id = l.party_id' . $w .
    ' ORDER BY l.entry_date DESC, l.id DESC LIMIT 300'
);
$st->execute($params);
$rows = $st->fetchAll();

$balances = $pdo->query(
    "SELECT a.id, a.name, a.is_active, pt.name AS owner,
            a.opening_balance + COALESCE((SELECT SUM(CASE WHEN l.direction='in' THEN l.amount ELSE -l.amount END) FROM account_ledger l WHERE l.account_id = a.id), 0) AS bal
     FROM accounts a LEFT JOIN parties pt ON pt.id = a.party_id ORDER BY a.is_active DESC, a.name"
)->fetchAll();
$accounts = array_filter($balances, fn($a) => $a['is_active']);
$accOpts = '';
foreach ($accounts as $a) {
    $accOpts .= '<option value="' . (int)$a['id'] . '">' . e($a['name']) . '</option>';
}
$typeNames = ['prepaid' => 'Prepaid order', 'cod' => 'COD received', 'party_payment' => 'Party payment', 'refund' => 'Refund', 'other' => 'Other / transfer'];
$dateInput = 'style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"';

$pageTitle = 'Ledger';
$active = 'ledger';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<div class="stats">
  <?php foreach ($balances as $b): if (!$b['is_active']) continue; ?>
    <div class="stat"><div class="k"><?= e($b['name']) ?><?= $b['owner'] ? ' · ' . e($b['owner']) : '' ?></div><div class="v <?= $b['bal'] < 0 ? 'neg' : '' ?>"><?= e(inr($b['bal'])) ?></div></div>
  <?php endforeach; ?>
</div>

<div class="grid">
  <section class="card wide">
    <h2>All money in and out</h2>
    <form method="get" class="filters">
      <div><label for="account">Account</label>
        <select id="account" name="account"><option value="0">All</option>
          <?php foreach ($balances as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $accId === (int)$b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label for="type">Type</label>
        <select id="type" name="type"><option value="">All</option>
          <?php foreach ($typeNames as $k => $v): ?><option value="<?= e($k) ?>"<?= $type === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select></div>
      <div><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($from) ?>" <?= $dateInput ?>></div>
      <div><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($to) ?>" <?= $dateInput ?>></div>
      <button class="btn" type="submit">Filter</button>
      <a class="btn btn-ghost linkbtn" href="ledger.php">Clear</a>
    </form>
    <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:10px;font-weight:800">
      <span class="pos">In <?= e(inr($t['i'])) ?></span><span class="neg">Out <?= e(inr($t['o'])) ?></span><span>Net <?= e(inr($t['i'] - $t['o'])) ?></span>
    </div>
    <?php if (!$rows): ?>
      <div class="empty">No entries yet.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="tight" style="min-width:620px">
      <thead><tr><th>Date</th><th>Account</th><th>What</th><th class="num">Amount</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(date('d M Y', strtotime($r['entry_date']))) ?></td>
          <td><?= e($r['account']) ?></td>
          <td>
            <span class="tag"><?= e($typeNames[$r['entry_type']] ?? $r['entry_type']) ?></span>
            <?php if ($r['order_id']): ?> <a class="rowlink" href="order_view.php?id=<?= (int)$r['order_id'] ?>"><?= e(order_no($r['order_id'])) ?></a><?php endif; ?>
            <?php if ($r['party']): ?> <span class="mini"><?= e($r['party']) ?></span><?php endif; ?>
            <?php if ($r['note']): ?><div class="mini"><?= e($r['note']) ?></div><?php endif; ?>
          </td>
          <td class="num <?= $r['direction'] === 'in' ? 'pos' : 'neg' ?>"><?= $r['direction'] === 'in' ? '+' : '-' ?><?= e(inr($r['amount'])) ?></td>
          <td class="num">
            <?php if ($r['entry_type'] === 'other'): ?>
            <form class="inline" method="post" onsubmit="return confirm('Delete this entry?');">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-ghost btn-sm" type="submit">Delete</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="hint">Showing the latest 300 matching entries. Order, COD, refund and party entries are created from their own pages.</p>
    <?php endif; ?>
  </section>

  <div class="narrow stack">
    <section class="card">
      <h2>Move money between accounts</h2>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="transfer">
        <div class="field"><label for="from_id">From</label><select id="from_id" name="from_id"><?= $accOpts ?></select></div>
        <div class="field"><label for="to_id">To</label><select id="to_id" name="to_id"><?= $accOpts ?></select></div>
        <div class="field"><label for="t_amount">Amount (₹)</label><input id="t_amount" name="amount" type="text" inputmode="decimal"></div>
        <div class="field"><label for="t_date">Date</label><input id="t_date" name="entry_date" type="date" value="<?= e(biz_today()) ?>" <?= $dateInput ?>></div>
        <button class="btn btn-accent btn-block" type="submit">Save transfer</button>
      </form>
    </section>
    <section class="card">
      <h2>Other entry</h2>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="entry">
        <div class="field"><label for="e_acc">Account</label><select id="e_acc" name="account_id"><?= $accOpts ?></select></div>
        <div class="field"><label for="e_dir">Money</label><select id="e_dir" name="direction"><option value="in">Came in</option><option value="out">Went out</option></select></div>
        <div class="field"><label for="e_amount">Amount (₹)</label><input id="e_amount" name="amount" type="text" inputmode="decimal"></div>
        <div class="field"><label for="e_date">Date</label><input id="e_date" name="entry_date" type="date" value="<?= e(biz_today()) ?>" <?= $dateInput ?>></div>
        <div class="field"><label for="e_note">Note</label><input id="e_note" name="note" type="text" placeholder="Courier charges, ad spend ..."></div>
        <button class="btn btn-ghost btn-block" type="submit">Save entry</button>
      </form>
    </section>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>