<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();
$pdo = db();

// "mine" -> money is there for you (plus); "owe" -> you owe this account (minus)
function signed_account_opening(string $who, $amount): float
{
    $amt = abs(num($amount));
    return $who === 'owe' ? -$amt : $amt;
}

/** The party an account belongs to (0 or unknown = none, it is one of your own accounts). */
function owner_party_id(PDO $pdo, $raw): ?int
{
    $st = $pdo->prepare('SELECT id FROM parties WHERE id = ?');
    $st->execute([(int)$raw]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $opening = signed_account_opening((string)($_POST['who'] ?? 'mine'), $_POST['opening_balance'] ?? 0);
        $ownerId = owner_party_id($pdo, $_POST['party_id'] ?? 0);
        if ($name === '') {
            flash('error', 'Enter an account name.');
        } else {
            try {
                $pdo->prepare('INSERT INTO accounts (name, opening_balance, party_id) VALUES (?, ?, ?)')->execute([$name, $opening, $ownerId]);
                flash('ok', 'Account "' . $name . '" created.');
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23000') {
                    flash('error', 'An account with that name already exists.');
                } else {
                    throw $ex;
                }
            }
        }
    } elseif ($action === 'fix_opening') {
        // correct only the opening balance of an existing account
        $opening = signed_account_opening((string)($_POST['who'] ?? 'mine'), $_POST['opening_balance'] ?? 0);
        $pdo->prepare('UPDATE accounts SET opening_balance = ?, party_id = ? WHERE id = ?')
            ->execute([$opening, owner_party_id($pdo, $_POST['party_id'] ?? 0), (int)($_POST['id'] ?? 0)]);
        flash('ok', 'Account saved. Money that already came in is not changed; the owner applies to new entries.');
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE accounts SET is_active = 1 - is_active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('ok', 'Account updated.');
    }
    redirect('accounts.php');
}

$rows = $pdo->query(
    "SELECT a.id, a.name, a.is_active, a.opening_balance, a.party_id, pt.name AS owner,
            a.opening_balance + COALESCE(SUM(CASE WHEN l.direction='in' THEN l.amount WHEN l.direction='out' THEN -l.amount END), 0) AS balance
     FROM accounts a
     LEFT JOIN account_ledger l ON l.account_id = a.id
     LEFT JOIN parties pt ON pt.id = a.party_id
     GROUP BY a.id, a.name, a.is_active, a.opening_balance, a.party_id, pt.name
     ORDER BY a.is_active DESC, a.name"
)->fetchAll();

$editRow = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT id, name, opening_balance, party_id FROM accounts WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $editRow = $st->fetch() ?: null;
}

$partyList = $pdo->query('SELECT id, name FROM parties WHERE is_active = 1 ORDER BY name')->fetchAll();
function owner_select(array $partyList, $selected): string
{
    $h = '<div class="field"><label for="party_id">Belongs to a vendor?</label><select id="party_id" name="party_id"><option value="0">No, it is my own account</option>';
    foreach ($partyList as $p) {
        $h .= '<option value="' . (int)$p['id'] . '"' . ((int)$selected === (int)$p['id'] ? ' selected' : '') . '>' . e($p['name']) . '\'s QR / account</option>';
    }
    return $h . '</select><div class="hint">Customer money that comes into a vendor\'s account is taken off what you owe that vendor. Keep the opening balance 0 for these.</div></div>';
}

$pageTitle = 'Accounts';
$active = 'accounts';
require __DIR__ . '/includes/header.php';
?>
<div class="grid">
  <section class="card wide">
    <h2>Your payment accounts</h2>
    <?php if (!$rows): ?>
      <div class="empty">No accounts yet. Add QR 1, Bank, Cash on the right.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Account</th><th class="num">Balance</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['name']) ?> <?php if ($r['owner']): ?><span class="tag tag-info"><?= e($r['owner']) ?>'s account</span><?php endif; ?> <?php if (!$r['is_active']): ?><span class="tag tag-off">Off</span><?php endif; ?></td>
            <td class="num <?= $r['balance'] < 0 ? 'neg' : '' ?>"><?= e(inr($r['balance'])) ?><?php if ($r['balance'] < 0): ?> <small>(you owe)</small><?php endif; ?></td>
            <td class="num">
              <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center">
                <a class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;text-decoration:none" href="accounts.php?edit=<?= (int)$r['id'] ?>">Edit</a>
                <form class="inline" method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit"><?= $r['is_active'] ? 'Turn off' : 'Turn on' ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">A balance can go minus when you pay out of an account. "Edit" changes the starting amount and which vendor the account belongs to.</p>
    <?php endif; ?>
  </section>

  <section class="card narrow">
    <?php if ($editRow): ?>
    <h2>Edit: <?= e($editRow['name']) ?></h2>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="fix_opening">
      <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
      <div class="field"><label for="who">Opening balance type</label>
        <select id="who" name="who">
          <option value="mine"<?= $editRow['opening_balance'] >= 0 ? ' selected' : '' ?>>Money is there for me (+)</option>
          <option value="owe"<?= $editRow['opening_balance'] < 0 ? ' selected' : '' ?>>I owe this account (-)</option>
        </select>
      </div>
      <div class="field"><label for="opening_balance">Amount (₹)</label><input id="opening_balance" name="opening_balance" type="text" inputmode="decimal" value="<?= e((string)abs((float)$editRow['opening_balance'])) ?>">
        <div class="hint">Enter the amount without a minus sign.</div></div>
      <?= owner_select($partyList, $editRow['party_id']) ?>
      <button class="btn btn-accent btn-block" type="submit">Save</button>
      <a href="accounts.php" style="display:block;text-align:center;margin-top:12px;font-weight:700;color:var(--muted);text-decoration:none">Cancel</a>
    </form>
    <?php else: ?>
    <h2>Create account</h2>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="field"><label for="name">Account name</label><input id="name" name="name" type="text" placeholder="QR 1, Bank, Cash" required></div>
      <div class="field"><label for="who">Opening balance type</label>
        <select id="who" name="who">
          <option value="mine">Money is there for me (+)</option>
          <option value="owe">I owe this account (-)</option>
        </select>
      </div>
      <div class="field"><label for="opening_balance">Amount (₹)</label><input id="opening_balance" name="opening_balance" type="text" inputmode="decimal" value="0">
        <div class="hint">Enter the amount without a minus sign. Leave 0 if nothing is there yet.</div></div>
      <?= owner_select($partyList, 0) ?>
      <button class="btn btn-accent btn-block" type="submit">Create account</button>
    </form>
    <?php endif; ?>
  </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
