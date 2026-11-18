<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $username = strtolower(trim($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        // "manager" gets full access (stored as role admin); "salesperson" gets orders and commission only
        $isManager = ($_POST['role'] ?? 'salesperson') === 'manager';
        if ($name === '' || $username === '') {
            flash('error', 'Enter the name and username.');
        } elseif (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
            flash('error', 'Username: 3 to 50 letters, numbers, dot, dash or underscore.');
        } elseif (strlen($pass) < 8) {
            flash('error', 'Password must be at least 8 characters.');
        } else {
            try {
                $pdo->prepare('INSERT INTO users (name, username, password_hash, role) VALUES (?, ?, ?, ?)')
                    ->execute([$name, $username, password_hash($pass, PASSWORD_DEFAULT), $isManager ? 'admin' : 'salesperson']);
                flash('ok', ($isManager ? 'Manager "' : 'Salesperson "') . $name . '" created.');
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23000') {
                    flash('error', 'That username is already taken.');
                } else {
                    throw $ex;
                }
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$user['id']) {
            flash('error', 'You cannot turn off your own login.');
        } else {
            $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            flash('ok', 'Login updated.');
        }
    } elseif ($action === 'reset') {
        $id = (int)($_POST['id'] ?? 0);
        $pass = (string)($_POST['password'] ?? '');
        if (strlen($pass) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            flash('ok', 'Password changed.');
        }
    }
    redirect('users.php');
}

$rows = $pdo->query('SELECT id, name, username, role, is_active FROM users ORDER BY role, name')->fetchAll();

$pageTitle = 'Team';
$active = 'users';
require __DIR__ . '/includes/header.php';
?>
<div class="grid">
  <section class="card wide">
    <h2>Logins</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>New password</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['name']) ?> <?php if (!$r['is_active']): ?><span class="tag tag-off">Off</span><?php endif; ?></td>
            <td><?= e($r['username']) ?></td>
            <td><span class="tag"><?= $r['role'] === 'admin' ? 'Manager' : 'Salesperson' ?></span></td>
            <td>
              <form class="inline" method="post" autocomplete="off" style="display:flex;gap:6px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="password" name="password" placeholder="8+ characters" aria-label="New password for <?= e($r['name']) ?>" style="height:36px;min-width:130px">
                <button class="btn btn-ghost btn-sm" type="submit">Set</button>
              </form>
            </td>
            <td class="num">
              <?php if ((int)$r['id'] !== (int)$user['id']): ?>
              <form class="inline" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= $r['is_active'] ? 'Turn off' : 'Turn on' ?></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card narrow">
    <h2>Add login</h2>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="field"><label for="role">Role</label>
        <select id="role" name="role">
          <option value="salesperson">Salesperson (orders + commission only)</option>
          <option value="manager">Manager (full access)</option>
        </select>
      </div>
      <div class="field"><label for="name">Name</label><input id="name" name="name" type="text" required></div>
      <div class="field"><label for="username">Username</label><input id="username" name="username" type="text" required></div>
      <div class="field"><label for="password">Password (8+ characters)</label><input id="password" name="password" type="password" required></div>
      <button class="btn btn-accent btn-block" type="submit">Create login</button>
      <div class="hint">Salespeople see only their own orders and commission. Managers see everything.</div>
    </form>
  </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
