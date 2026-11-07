<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();
$pdo = db();
require_once __DIR__ . '/includes/dealer_send_lib.php';
$hasGL = parties_have_group_link($pdo);
$hasSR = (bool)$pdo->query("SHOW COLUMNS FROM parties LIKE 'auto_ship'")->fetch();

function clean_group_link(string $l)
{
    $l = trim($l);
    if ($l === '') {
        return null;
    }
    return preg_match('~^https://~i', $l) ? mb_substr($l, 0, 300) : false;
}

// "owes_me" -> party owes you (advance paid) -> stored as negative; "i_owe" -> you owe the party -> positive
function signed_opening(string $who, $amount): float
{
    $amt = abs(num($amount));
    return $who === 'owes_me' ? -$amt : $amt;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $glink = clean_group_link((string)($_POST['group_link'] ?? ''));
        $opening = signed_opening((string)($_POST['who'] ?? 'i_owe'), $_POST['opening_balance'] ?? 0);
        if ($name === '') {
            flash('error', 'Enter the party name.');
        } elseif ($glink === false) {
            flash('error', 'The group link must start with https://');
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO parties (name, phone) VALUES (?, ?)')->execute([$name, $phone === '' ? null : $phone]);
                $partyId = (int)$pdo->lastInsertId();
                if ($hasGL && $glink !== null) {
                    $pdo->prepare('UPDATE parties SET group_link = ? WHERE id = ?')->execute([$glink, $partyId]);
                }
                if ($opening != 0.0) {
                    $pdo->prepare(
                        "INSERT INTO party_ledger (party_id, entry_date, entry_type, amount, note, created_by)
                         VALUES (?, CURDATE(), 'opening', ?, 'Opening balance', ?)"
                    )->execute([$partyId, $opening, $user['id']]);
                }
                $pdo->commit();
                flash('ok', 'Party "' . $name . '" created.');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($ex->getCode() === '23000') {
                    flash('error', 'A party with that name already exists.');
                } else {
                    throw $ex;
                }
            }
        }
    } elseif ($action === 'fix_opening') {
        // correct the opening balance of an existing party (only the opening entry is replaced)
        $partyId = (int)($_POST['id'] ?? 0);
        $opening = signed_opening((string)($_POST['who'] ?? 'i_owe'), $_POST['opening_balance'] ?? 0);
        $chk = $pdo->prepare('SELECT id FROM parties WHERE id = ?');
        $chk->execute([$partyId]);
        if (!$chk->fetch()) {
            flash('error', 'Party not found.');
        } else {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM party_ledger WHERE party_id = ? AND entry_type = 'opening'")->execute([$partyId]);
            if ($opening != 0.0) {
                $pdo->prepare(
                    "INSERT INTO party_ledger (party_id, entry_date, entry_type, amount, note, created_by)
                     VALUES (?, CURDATE(), 'opening', ?, 'Opening balance', ?)"
                )->execute([$partyId, $opening, $user['id']]);
            }
            $pdo->commit();
            flash('ok', 'Opening balance updated.');
        }
    } elseif ($action === 'group_link') {
        $glink = clean_group_link((string)($_POST['group_link'] ?? ''));
        $pid = (int)($_POST['id'] ?? 0);
        if (!$hasGL) {
            flash('error', 'Run the group-link SQL first.');
        } elseif ($glink === false) {
            flash('error', 'The group link must start with https://');
        } else {
            $pdo->prepare('UPDATE parties SET group_link = ? WHERE id = ?')->execute([$glink, $pid]);
            if ($hasSR && isset($_POST['auto_ship'])) {
                $pk = trim((string)($_POST['sr_pickup'] ?? ''));
                $pdo->prepare('UPDATE parties SET auto_ship = ?, sr_pickup = ? WHERE id = ?')
                    ->execute([$_POST['auto_ship'] === '1' ? 1 : 0, $pk === '' ? null : mb_substr($pk, 0, 100), $pid]);
            }
            flash('ok', 'Dealer settings saved.');
        }
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE parties SET is_active = 1 - is_active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('ok', 'Party updated.');
    }
    redirect('parties.php');
}

$rows = $pdo->query(
    "SELECT p.id, p.name, p.phone, p.is_active, " . ($hasGL ? 'p.group_link' : 'NULL') . " AS group_link, " . ($hasSR ? 'p.auto_ship' : '0') . " AS auto_ship, " . ($hasSR ? 'p.sr_pickup' : 'NULL') . " AS sr_pickup,
            COALESCE(SUM(CASE WHEN l.entry_type IN ('payment','collected') THEN -l.amount ELSE l.amount END), 0) AS balance
     FROM parties p
     LEFT JOIN party_ledger l ON l.party_id = p.id
     GROUP BY p.id, p.name, p.phone, p.is_active, group_link, auto_ship, sr_pickup
     ORDER BY p.is_active DESC, p.name"
)->fetchAll();

$editRow = null;
$editBal = 0.0;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare("SELECT p.id, p.name, COALESCE(SUM(CASE WHEN l.entry_type='opening' THEN l.amount END), 0) AS opening
                         FROM parties p LEFT JOIN party_ledger l ON l.party_id = p.id WHERE p.id = ? GROUP BY p.id, p.name");
    $st->execute([(int)$_GET['edit']]);
    $editRow = $st->fetch() ?: null;
    if ($editRow) {
        $editBal = (float)$editRow['opening'];
    }
}

$glRow = null;
if ($hasGL && isset($_GET['glink'])) {
    foreach ($rows as $r) {
        if ((int)$r['id'] === (int)$_GET['glink']) {
            $glRow = $r;
        }
    }
}

$pageTitle = 'Parties';
$active = 'parties';
require __DIR__ . '/includes/header.php';
?>
<div class="grid">
  <section class="card wide">
    <h2>Your parties</h2>
    <?php if (!$rows): ?>
      <div class="empty">No parties yet. Create your first party on the right.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Party</th><th>Phone</th><th class="num">Balance</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <?php $bal = (float)$r['balance']; ?>
          <tr>
            <td><?= e($r['name']) ?> <?php if (!$r['is_active']): ?><span class="tag tag-off">Off</span><?php endif; ?></td>
            <td><?= e($r['phone'] ?? '') ?><?php if ($hasGL): ?><br><a class="mini" style="text-decoration:none" href="parties.php?glink=<?= (int)$r['id'] ?>"><?= $r['group_link'] ? 'Group link set' : 'Add group link' ?></a><?php if ($hasSR): ?> <a class="mini" style="text-decoration:none" href="parties.php?glink=<?= (int)$r['id'] ?>"><?= $r['auto_ship'] ? '· Auto-ship ON' : '· Auto-ship off' ?></a><?php endif; ?><?php endif; ?></td>
            <td class="num <?= $bal < 0 ? 'pos' : '' ?>">
              <?php if ($bal > 0): ?>You owe <?= e(inr($bal)) ?>
              <?php elseif ($bal < 0): ?>They owe you <?= e(inr(-$bal)) ?>
              <?php else: ?>Settled<?php endif; ?>
            </td>
            <td class="num">
              <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center">
                <a class="btn btn-accent btn-sm" style="display:inline-flex;align-items:center;text-decoration:none" href="party_ledger.php?id=<?= (int)$r['id'] ?>">Ledger</a>
                <a class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;text-decoration:none" href="parties.php?edit=<?= (int)$r['id'] ?>">Fix opening</a>
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
    <p class="hint">Open a party's Ledger to add bills and payments. "Fix opening" changes only the starting balance.</p>
    <?php endif; ?>
  </section>

  <section class="card narrow">
    <?php if ($glRow): ?>
    <h2>Dealer settings: <?= e($glRow['name']) ?></h2>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="group_link">
      <input type="hidden" name="id" value="<?= (int)$glRow['id'] ?>">
      <div class="field"><label for="gl">WhatsApp group link</label><input id="gl" name="group_link" type="text" placeholder="https://chat.whatsapp.com/..." value="<?= e($glRow['group_link'] ?? '') ?>">
        <div class="hint">In WhatsApp open the group, Group info, Invite via link, Copy link. Leave empty to remove.</div></div>
      <?php if ($hasSR): ?>
      <div class="field"><label for="auto_ship">Shiprocket auto-ship</label>
        <select id="auto_ship" name="auto_ship">
          <option value="1"<?= $glRow['auto_ship'] ? ' selected' : '' ?>>ON - create shipments in Shiprocket for this dealer's products</option>
          <option value="0"<?= !$glRow['auto_ship'] ? ' selected' : '' ?>>OFF - this dealer ships by himself</option>
        </select></div>
      <div class="field"><label for="sr_pickup">Shiprocket pickup name (optional)</label><input id="sr_pickup" name="sr_pickup" type="text" value="<?= e($glRow['sr_pickup'] ?? '') ?>" placeholder="As shown in Shiprocket → Pickup Addresses">
        <div class="hint">Leave empty to use your main pickup address in Shiprocket.</div></div>
      <?php endif; ?>
      <button class="btn btn-accent btn-block" type="submit">Save</button>
      <a href="parties.php" style="display:block;text-align:center;margin-top:12px;font-weight:700;color:var(--muted);text-decoration:none">Cancel</a>
    </form>
    <?php elseif ($editRow): ?>
    <h2>Fix opening: <?= e($editRow['name']) ?></h2>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="fix_opening">
      <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
      <div class="field"><label for="who">Opening balance, who owes?</label>
        <select id="who" name="who">
          <option value="owes_me"<?= $editBal < 0 ? ' selected' : '' ?>>They owe me (I paid in advance)</option>
          <option value="i_owe"<?= $editBal >= 0 ? ' selected' : '' ?>>I owe them</option>
        </select>
      </div>
      <div class="field"><label for="opening_balance">Amount (₹)</label><input id="opening_balance" name="opening_balance" type="text" inputmode="decimal" value="<?= e((string)abs($editBal)) ?>">
        <div class="hint">Enter the amount without a minus sign.</div></div>
      <button class="btn btn-accent btn-block" type="submit">Save</button>
      <a href="parties.php" style="display:block;text-align:center;margin-top:12px;font-weight:700;color:var(--muted);text-decoration:none">Cancel</a>
    </form>
    <?php else: ?>
    <h2>Create party</h2>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="field"><label for="name">Party name</label><input id="name" name="name" type="text" required></div>
      <div class="field"><label for="phone">Phone (optional)</label><input id="phone" name="phone" type="tel"></div>
      <?php if ($hasGL): ?><div class="field"><label for="group_link">WhatsApp group link (optional)</label><input id="group_link" name="group_link" type="text" placeholder="https://chat.whatsapp.com/..."></div><?php endif; ?>
      <div class="field"><label for="who">Opening balance, who owes?</label>
        <select id="who" name="who">
          <option value="owes_me">They owe me (I paid in advance)</option>
          <option value="i_owe">I owe them</option>
        </select>
      </div>
      <div class="field"><label for="opening_balance">Amount (₹)</label><input id="opening_balance" name="opening_balance" type="text" inputmode="decimal" value="0">
        <div class="hint">Enter the amount without a minus sign. Leave 0 if nothing is pending.</div></div>
      <button class="btn btn-accent btn-block" type="submit">Create party</button>
    </form>
    <?php endif; ?>
  </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
