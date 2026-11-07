<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 80);
    $phone = mb_substr(preg_replace('/[^\d+ ]+/', '', (string)($_POST['phone'] ?? '')), 0, 20);
    if ($action === 'create') {
        if ($label === '') {
            flash('error', 'Enter a name for the number, for example "WhatsApp 1".');
        } else {
            $pdo->prepare('INSERT INTO order_numbers (label, phone) VALUES (?, ?)')->execute([$label, $phone === '' ? null : trim($phone)]);
            flash('ok', 'Number "' . $label . '" added.');
        }
    } elseif ($action === 'save') {
        if ($label === '') {
            flash('error', 'Enter a name for the number.');
        } else {
            $pdo->prepare('UPDATE order_numbers SET label = ?, phone = ? WHERE id = ?')->execute([$label, $phone === '' ? null : trim($phone), (int)($_POST['id'] ?? 0)]);
            flash('ok', 'Number saved.');
        }
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE order_numbers SET is_active = 1 - is_active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('ok', 'Number updated.');
    }
    redirect('numbers.php');
}

$rows = $pdo->query(
    'SELECT n.id, n.label, n.phone, n.is_active, (SELECT COUNT(*) FROM orders o WHERE o.number_id = n.id) AS orders_count
     FROM order_numbers n ORDER BY n.is_active DESC, n.label'
)->fetchAll();

$editRow = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT id, label, phone FROM order_numbers WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $editRow = $st->fetch() ?: null;
}

$pageTitle = 'Numbers';
$active = 'numbers';
require __DIR__ . '/includes/header.php';
?>
<div class="grid">
  <section class="card wide">
    <h2>Your order numbers</h2>
    <?php if (!$rows): ?>
      <div class="empty">No numbers yet. Add the numbers you take orders on, for example WhatsApp 1, on the right. Until you add one, the New order page does not ask for a number.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Number</th><th class="num">Orders</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['label']) ?><?php if ($r['phone']): ?> <span class="mini"><?= e($r['phone']) ?></span><?php endif; ?> <?php if (!$r['is_active']): ?><span class="tag tag-off">Off</span><?php endif; ?></td>
            <td class="num"><?= (int)$r['orders_count'] ?></td>
            <td class="num">
              <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center">
                <a class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;text-decoration:none" href="numbers.php?edit=<?= (int)$r['id'] ?>">Edit</a>
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
    <p class="hint">A number that is turned off no longer shows in the New order list. Old orders keep it.</p>
    <?php endif; ?>
  </section>

  <section class="card narrow">
    <?php if ($editRow): ?>
    <h2>Edit: <?= e($editRow['label']) ?></h2>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
      <div class="field"><label for="label">Name</label><input id="label" name="label" type="text" value="<?= e($editRow['label']) ?>" required></div>
      <div class="field"><label for="phone">Phone number (optional)</label><input id="phone" name="phone" type="text" inputmode="tel" value="<?= e($editRow['phone'] ?? '') ?>"></div>
      <button class="btn btn-accent btn-block" type="submit">Save</button>
      <a href="numbers.php" style="display:block;text-align:center;margin-top:12px;font-weight:700;color:var(--muted);text-decoration:none">Cancel</a>
    </form>
    <?php else: ?>
    <h2>Add a number</h2>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="field"><label for="label">Name</label><input id="label" name="label" type="text" placeholder="WhatsApp 1" required></div>
      <div class="field"><label for="phone">Phone number (optional)</label><input id="phone" name="phone" type="text" inputmode="tel" placeholder="98xxxxxxxx"></div>
      <button class="btn btn-accent btn-block" type="submit">Add number</button>
    </form>
    <?php endif; ?>
  </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>