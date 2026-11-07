<?php
// Open one recorded dealer payment: Edit Entry / Delete Entry, and the history of changes (admin only).
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/proof_lib.php';
require_once __DIR__ . '/includes/dealer_lib.php';
require_once __DIR__ . '/includes/ledger_edit_lib.php';
$user = require_admin();
$pdo = db();

/** Only come back to one of the two ledger pages (never to an outside address). */
function le_back(string $raw, int $partyId): string
{
    return preg_match('~^(dealer_ledger|party_ledger)\.php(\?[A-Za-z0-9_=&%.\-]*)?$~', $raw) ? $raw : 'party_ledger.php?id=' . $partyId;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$back = (string)($_GET['back'] ?? $_POST['back'] ?? '');

// ----- change history of one dealer -----
if (isset($_GET['history'])) {
    $hp = (int)$_GET['history'];
    $st = $pdo->prepare('SELECT id, name FROM parties WHERE id = ?');
    $st->execute([$hp]);
    $party = $st->fetch();
    if (!$party) {
        http_response_code(404);
        exit('Dealer not found.');
    }
    $rows = [];
    try {
        $q = $pdo->prepare('SELECT a.*, u.name AS who FROM ledger_audit a LEFT JOIN users u ON u.id = a.changed_by WHERE a.party_id = ? ORDER BY a.id DESC LIMIT 200');
        $q->execute([$hp]);
        $rows = $q->fetchAll();
    } catch (PDOException $e) {
        $rows = []; // nothing has been changed yet, so the history table does not exist yet
    }
    $pageTitle = 'Change history';
    $active = 'dealers';
    $extraCss = 'assets/pages.css';
    require __DIR__ . '/includes/header.php';
    ?>
<p style="margin:-8px 0 16px"><a href="party_ledger.php?id=<?= (int)$hp ?>" class="mini" style="text-decoration:none">&larr; <?= e($party['name']) ?> ledger</a></p>
<section class="card">
  <h2>Edited and deleted payments · <?= e($party['name']) ?></h2>
  <?php if (!$rows): ?>
    <div class="empty">Nothing has been edited or deleted.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="tight" style="min-width:520px">
    <thead><tr><th>When</th><th>What</th><th>Before</th><th>After</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $b = json_decode((string)$r['before_json'], true) ?: [];
        $a = json_decode((string)$r['after_json'], true) ?: [];
        $fmt = static function (array $x): string {
            if (!$x) {
                return '-';
            }
            $s = inr($x['amount'] ?? 0) . ' · ' . ($x['entry_date'] ?? '') . ' · ' . (($x['method'] ?? '') === 'cash' ? 'Cash' : 'Online');
            if (!empty($x['note'])) {
                $s .= ' · ' . $x['note'];
            }
            return $s;
        }; ?>
      <tr>
        <td><?= e(date('d M Y H:i', strtotime($r['changed_at']))) ?><div class="mini"><?= e($r['who'] ?? '') ?></div></td>
        <td><span class="tag <?= $r['action'] === 'delete' ? 'tag-bad' : 'tag-info' ?>"><?= $r['action'] === 'delete' ? 'Deleted' : 'Edited' ?></span><div class="mini">entry #<?= (int)$r['ledger_id'] ?></div></td>
        <td class="mini"><?= e($fmt($b)) ?></td>
        <td class="mini"><?= e($fmt($a)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ----- save / delete -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $pp = ledger_payment_load($pdo, $id);
    $partyForBack = $pp ? (int)$pp['party_id'] : 0;
    $backTo = le_back($back, $partyForBack);
    $newFile = null;
    try {
        if (!$pp) {
            throw new RuntimeException('Payment not found. It may already be deleted.');
        }
        if ($action === 'save') {
            $acc = (int)($_POST['account_id'] ?? 0);
            $okAcc = $pdo->prepare('SELECT COUNT(*) FROM accounts WHERE id = ? AND (is_active = 1 OR id = ?)');
            $okAcc->execute([$acc, (int)$pp['account_id']]);
            $amt = round(num($_POST['amount'] ?? 0), 2);
            $d = valid_date((string)($_POST['entry_date'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));
            $method = (($_POST['pay_method'] ?? '') === 'cash') ? 'cash' : 'online';
            $kind = in_array((string)($_POST['pay_kind'] ?? ''), ['cod', 'extra'], true) ? (string)$_POST['pay_kind'] : null;
            if ((int)$okAcc->fetchColumn() === 0) {
                throw new RuntimeException('Choose the account the money was paid from.');
            }
            if ($amt <= 0) {
                throw new RuntimeException('Enter the amount.');
            }
            if ($d === null) {
                throw new RuntimeException('Enter a valid date.');
            }
            if ($method === 'online' && proof_uploaded($_FILES['pay_proof'] ?? null)) {
                $newFile = save_proof_upload($_FILES['pay_proof']);
            }
            try {
                $warn = ledger_payment_update($pdo, $user, $id, $acc, $amt, $d, $method, $note, $kind, $newFile);
            } catch (Throwable $ex) {
                proof_remove_file($newFile);
                throw $ex;
            }
            flash('ok', 'Entry corrected. The dealer balance is updated.' . ($warn !== '' ? ' ' . $warn : ''));
            redirect($backTo);
        } elseif ($action === 'delete') {
            $warn = ledger_payment_delete($pdo, $user, $id);
            flash('ok', 'Entry deleted. The dealer balance is updated. The old values are kept in the change history.' . ($warn !== '' ? ' ' . $warn : ''));
            redirect($backTo);
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('ledger_entry.php?id=' . $id . ($back !== '' ? '&back=' . rawurlencode($backTo) : ''));
}

// ----- the page -----
$p = ledger_payment_load($pdo, $id);
if (!$p) {
    http_response_code(404);
    exit('Payment not found. It may already be deleted. <a href="dealers.php">Back to dealers</a>');
}
$backTo = le_back($back, (int)$p['party_id']);
$editable = $p['ledger_id'] === null;
$accounts = $pdo->query('SELECT id, name FROM accounts WHERE is_active = 1 OR id = ' . (int)$p['account_id'] . ' ORDER BY name')->fetchAll();
$hasKind = dealer_has_paykind($pdo);
$method = $p['pay_method'] ?? 'online';
$dateInput = 'style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"';

$hist = [];
try {
    $q = $pdo->prepare('SELECT a.*, u.name AS who FROM ledger_audit a LEFT JOIN users u ON u.id = a.changed_by WHERE a.ledger_id = ? ORDER BY a.id DESC');
    $q->execute([$id]);
    $hist = $q->fetchAll();
} catch (PDOException $e) {
    $hist = [];
}

$pageTitle = 'Payment entry';
$active = 'dealers';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<p style="margin:-8px 0 16px"><a href="<?= e($backTo) ?>" class="mini" style="text-decoration:none">&larr; Back to ledger</a></p>
<div class="grid">
  <section class="card wide">
    <h2>Payment to <?= e($p['party_name']) ?></h2>
    <p style="margin:0 0 14px;font-weight:600"><?= e(inr($p['amount'])) ?> on <?= e(date('d M Y', strtotime($p['entry_date']))) ?> · from <?= e($p['account_name'] ?? '') ?> ·
      <?= $method === 'cash' ? 'Cash' : 'Online' ?><?php if ($p['proof_file']): ?> · <a href="proof.php?id=<?= (int)$p['proof_id'] ?>" target="_blank" rel="noopener">View screenshot</a><?php endif; ?></p>
    <?php if (!$editable): ?>
      <div class="empty">This entry comes from an order, so it is changed from that order.</div>
    <?php else: ?>
    <h3 style="margin:0 0 10px">Edit entry</h3>
    <form method="post" autocomplete="off" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="back" value="<?= e($backTo) ?>">
      <?php if ($hasKind): ?>
      <div class="field"><label for="x_kind">This payment is for</label>
        <select id="x_kind" name="pay_kind">
          <option value="cod" <?= ($p['pay_kind'] ?? '') === 'cod' ? 'selected' : '' ?>>COD buying cost</option>
          <option value="extra" <?= ($p['pay_kind'] ?? '') !== 'cod' ? 'selected' : '' ?>>Extra / old balance</option>
        </select></div>
      <?php endif; ?>
      <div class="field"><label for="x_acc">Paid from account</label>
        <select id="x_acc" name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === (int)$p['account_id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="x_amount">Amount (₹)</label><input id="x_amount" name="amount" type="text" inputmode="decimal" required value="<?= e(rtrim(rtrim(number_format((float)$p['amount'], 2, '.', ''), '0'), '.')) ?>"></div>
      <div class="field"><label for="x_method">How paid</label>
        <select id="x_method" name="pay_method"><option value="online" <?= $method !== 'cash' ? 'selected' : '' ?>>Online</option><option value="cash" <?= $method === 'cash' ? 'selected' : '' ?>>Cash</option></select></div>
      <div class="field"><label for="x_date">Date</label><input id="x_date" name="entry_date" type="date" value="<?= e($p['entry_date']) ?>" <?= $dateInput ?>></div>
      <div class="field"><label for="x_note">Note</label><input id="x_note" name="note" type="text" value="<?= e($p['note'] ?? '') ?>" placeholder="UTR / reference"></div>
      <div class="field" id="x_proof_box"><label for="x_proof">Replace screenshot (optional)</label><input id="x_proof" name="pay_proof" type="file" accept="image/*,application/pdf"></div>
      <button class="btn btn-accent btn-block" type="submit">Save changes</button>
      <div class="hint">The dealer balance and the account are corrected automatically. The old values are kept in the change history.</div>
    </form>

    <form method="post" class="danger" style="margin-top:18px" onsubmit="return confirm('Delete this payment of <?= e(inr($p['amount'])) ?> to <?= e(addslashes($p['party_name'])) ?>? The money goes back to the account and the dealer balance is updated. The old values stay in the change history.');">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="back" value="<?= e($backTo) ?>">
      <button class="btn btn-danger btn-block" type="submit">Delete entry</button>
    </form>
    <?php endif; ?>
  </section>

  <section class="card narrow">
    <h2>History</h2>
    <?php if (!$hist): ?>
      <div class="empty">Not changed yet.</div>
    <?php else: foreach ($hist as $h):
        $b = json_decode((string)$h['before_json'], true) ?: [];
        $a = json_decode((string)$h['after_json'], true) ?: []; ?>
      <div class="mini" style="margin-bottom:10px">
        <b><?= $h['action'] === 'delete' ? 'Deleted' : 'Edited' ?></b> · <?= e(date('d M Y H:i', strtotime($h['changed_at']))) ?> · <?= e($h['who'] ?? '') ?>
        <div>Before: <?= e(inr($b['amount'] ?? 0)) ?> · <?= e($b['entry_date'] ?? '') ?></div>
        <?php if ($a): ?><div>After: <?= e(inr($a['amount'] ?? 0)) ?> · <?= e($a['entry_date'] ?? '') ?></div><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
    <p style="margin:12px 0 0"><a class="mini" href="ledger_entry.php?history=<?= (int)$p['party_id'] ?>">All changes for <?= e($p['party_name']) ?></a></p>
  </section>
</div>
<script>
(function () {
  var s = document.getElementById('x_method'), b = document.getElementById('x_proof_box');
  if (!s || !b) { return; }
  function sync() { b.style.display = s.value === 'cash' ? 'none' : ''; }
  s.addEventListener('change', sync); sync();
  var f = s.form;
  f.addEventListener('submit', function () {
    var btn = f.querySelector('button[type=submit]');
    if (btn) { btn.disabled = true; }
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
