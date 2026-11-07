<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ops_lib.php';
$user = require_admin();
$pdo = db();

$date = valid_date((string)($_GET['date'] ?? $_POST['date'] ?? '')) ?? biz_today();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            flash('error', 'Enter the group name.');
        } else {
            try {
                $pdo->prepare('INSERT INTO community_groups (name) VALUES (?)')->execute([mb_substr($name, 0, 150)]);
                flash('ok', 'Group "' . $name . '" added.');
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23000') {
                    flash('error', 'A group with this name already exists.');
                } else {
                    throw $ex;
                }
            }
        }
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE community_groups SET is_active = 1 - is_active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('ok', 'Group updated.');
    } elseif ($action === 'save_day') {
        $added = $_POST['added'] ?? [];
        $left = $_POST['left'] ?? [];
        $up = $pdo->prepare(
            'INSERT INTO group_updates (group_id, update_date, added, left_count) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE added = VALUES(added), left_count = VALUES(left_count)'
        );
        $n = 0;
        $pdo->beginTransaction();
        foreach ($pdo->query('SELECT id FROM community_groups WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN) as $gid) {
            $a = max(0, (int)num($added[$gid] ?? 0));
            $l = max(0, (int)num($left[$gid] ?? 0));
            $up->execute([(int)$gid, $date, $a, $l]);
            $n++;
        }
        $pdo->commit();
        flash('ok', 'Saved ' . date('d M', strtotime($date)) . ' for ' . $n . ' group(s).');
    } elseif ($action === 'weekly_done') {
        $pdo->prepare("INSERT INTO settings (k, v) VALUES ('groups_report_done', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([biz_today()]);
        flash('ok', 'Weekly report marked done. The reminder comes back next Monday.');
    }
    redirect('groups.php?date=' . $date);
}

$groups = $pdo->query('SELECT id, name, is_active FROM community_groups ORDER BY is_active DESC, name')->fetchAll();

$dayRows = [];
$st = $pdo->prepare('SELECT group_id, added, left_count FROM group_updates WHERE update_date = ?');
$st->execute([$date]);
foreach ($st->fetchAll() as $r) {
    $dayRows[$r['group_id']] = $r;
}

// last 7 days (including today) per group + all time members net
$weekFrom = date('Y-m-d', strtotime(biz_today() . ' -6 days'));
$week = [];
$st = $pdo->prepare('SELECT group_id, SUM(added) AS a, SUM(left_count) AS l FROM group_updates WHERE update_date BETWEEN ? AND ? GROUP BY group_id');
$st->execute([$weekFrom, biz_today()]);
foreach ($st->fetchAll() as $r) {
    $week[$r['group_id']] = $r;
}
$allTime = [];
foreach ($pdo->query('SELECT group_id, SUM(added) AS a, SUM(left_count) AS l FROM group_updates GROUP BY group_id')->fetchAll() as $r) {
    $allTime[$r['group_id']] = $r;
}

$activeCount = count(array_filter($groups, fn($g) => $g['is_active']));
$due = groups_report_due($pdo);
$dateInput = 'style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"';

$pageTitle = 'Groups';
$active = 'groups';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<div class="grid">
  <div class="wide stack">
    <section class="card">
      <h2>Daily update</h2>
      <?php if (!$activeCount): ?>
        <div class="empty">Add your first group on the right.</div>
      <?php else: ?>
      <form method="get" class="filters" style="margin-bottom:10px">
        <div><label for="date">Day</label><input id="date" name="date" type="date" value="<?= e($date) ?>" <?= $dateInput ?>></div>
        <button class="btn btn-ghost" type="submit">Open day</button>
      </form>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_day"><input type="hidden" name="date" value="<?= e($date) ?>">
        <div class="table-wrap"><table class="tight" style="min-width:420px">
          <thead><tr><th>Group</th><th>Members added</th><th>Members left</th></tr></thead>
          <tbody>
          <?php foreach ($groups as $g): if (!$g['is_active']) continue; ?>
            <tr>
              <td><?= e($g['name']) ?></td>
              <td><input name="added[<?= (int)$g['id'] ?>]" type="number" min="0" value="<?= (int)($dayRows[$g['id']]['added'] ?? 0) ?>" style="width:110px"></td>
              <td><input name="left[<?= (int)$g['id'] ?>]" type="number" min="0" value="<?= (int)($dayRows[$g['id']]['left_count'] ?? 0) ?>" style="width:110px"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <button class="btn btn-accent" type="submit" style="margin-top:14px">Save <?= e(date('d M', strtotime($date))) ?></button>
        <div class="hint">Enter how many members joined each group today. Leaves are usually filled once a week.</div>
      </form>
      <?php endif; ?>
    </section>

    <section class="card">
      <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:12px">
        <h2 style="margin:0">Last 7 days</h2>
        <form method="post" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="action" value="weekly_done"><input type="hidden" name="date" value="<?= e($date) ?>">
          <button class="btn <?= $due ? 'btn-accent' : 'btn-ghost' ?> btn-sm" type="submit">Mark weekly report done</button>
        </form>
      </div>
      <?php if (!$groups): ?>
        <div class="empty">No groups yet.</div>
      <?php else: ?>
      <div class="table-wrap"><table class="tight" style="min-width:460px">
        <thead><tr><th>Group</th><th class="num">Added (7 days)</th><th class="num">Left (7 days)</th><th class="num">Net</th><th class="num">Net all time</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($groups as $g): ?>
          <?php
            $a = (int)($week[$g['id']]['a'] ?? 0);
            $l = (int)($week[$g['id']]['l'] ?? 0);
            $net = (int)($allTime[$g['id']]['a'] ?? 0) - (int)($allTime[$g['id']]['l'] ?? 0);
          ?>
          <tr>
            <td><?= e($g['name']) ?> <?php if (!$g['is_active']): ?><span class="tag tag-off">Off</span><?php endif; ?></td>
            <td class="num pos">+<?= $a ?></td>
            <td class="num neg">-<?= $l ?></td>
            <td class="num"><?= $a - $l >= 0 ? '+' : '' ?><?= $a - $l ?></td>
            <td class="num"><?= $net ?></td>
            <td class="num">
              <form class="inline" method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="date" value="<?= e($date) ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= $g['is_active'] ? 'Turn off' : 'Turn on' ?></button>
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
    <h2>Add group</h2>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="date" value="<?= e($date) ?>">
      <div class="field"><label for="gname">Group name</label><input id="gname" name="name" type="text" required></div>
      <button class="btn btn-accent btn-block" type="submit">Add group</button>
      <div class="hint">Active groups set the daily order target for commission: <b><?= (int)$activeCount ?></b> now. Orders at or above it earn 10%, below it 8%.</div>
    </form>
  </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>