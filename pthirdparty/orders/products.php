<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/products_lib.php';
$user = require_admin();
$pdo = db();

/** WHERE clause for the current search / filter. Returns [sql, params]. */
function product_filter(string $q, string $filter): array
{
    $where = [];
    $params = [];
    $q = trim($q);
    if ($q !== '') {
        $where[] = '(p.name LIKE ? OR p.brand LIKE ? OR p.category LIKE ? OR p.external_id LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($filter === 'missing') {
        $where[] = '(p.party_id IS NULL OR NOT EXISTS (SELECT 1 FROM product_prices pp WHERE pp.product_id = p.id))';
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

$q = trim((string)($_GET['q'] ?? ''));
$filter = ($_GET['filter'] ?? '') === 'missing' ? 'missing' : '';
$qs = http_build_query(array_filter(['q' => $q, 'filter' => $filter]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'sync') {
        try {
            $url = (string)($config['website_products_url'] ?? DEFAULT_WEBSITE_URL);
            $items = fetch_website_products($url);
            $st = $pdo->prepare(
                'INSERT INTO products (external_id, name, category, brand, image_url) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), brand = VALUES(brand), image_url = VALUES(image_url)'
            );
            $added = $updated = 0;
            $pdo->beginTransaction();
            foreach ($items as $it) {
                $ext = trim((string)($it['id'] ?? ''));
                $name = trim((string)($it['name'] ?? ''));
                if ($ext === '' || $name === '') {
                    continue;
                }
                $cat = trim((string)($it['category'] ?? ''));
                $brand = trim((string)($it['brand'] ?? ''));
                $img = trim((string)($it['image'] ?? ''));
                $st->execute([
                    mb_substr($ext, 0, 100),
                    mb_substr($name, 0, 255),
                    $cat === '' ? null : mb_substr($cat, 0, 100),
                    $brand === '' ? null : mb_substr($brand, 0, 100),
                    $img === '' ? null : mb_substr($img, 0, 500),
                ]);
                $rc = $st->rowCount();
                if ($rc === 1) {
                    $added++;
                } elseif ($rc === 2) {
                    $updated++;
                }
            }
            $pdo->commit();
            $msg = 'Synced ' . count($items) . ' products from the website: ' . $added . ' new, ' . $updated . ' updated.';

            // the website's buying price is the only buying price: it is copied here, never typed here
            $res = sync_website_costs($pdo, $config, (int)$user['id'], 0, 40);
            if ($res['error'] !== '') {
                $msg .= ' Products synced, but buying prices could not be read: ' . $res['error'] . '.';
            } else {
                $msg .= ' Buying prices from website: ' . $res['changed'] . ' set or changed.';
            }
            flash('ok', $msg);
        } catch (RuntimeException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $ex->getMessage());
        }
    } elseif ($action === 'update') {
        // only the party can be changed here. The buying price comes from the website.
        $id = (int)($_POST['id'] ?? 0);
        $partyId = valid_party_id($_POST['party_id'] ?? 0);
        $pdo->prepare('UPDATE products SET party_id = ? WHERE id = ?')->execute([$partyId, $id]);
        flash('ok', 'Saved.');
    } elseif ($action === 'update_many') {
        // the common Save button: every changed party selection is saved in one go
        $validSet = array_flip(array_map('intval', $pdo->query('SELECT id FROM parties')->fetchAll(PDO::FETCH_COLUMN)));
        $curSt = $pdo->prepare('SELECT party_id FROM products WHERE id = ?');
        $upSt = $pdo->prepare('UPDATE products SET party_id = ? WHERE id = ?');
        $changed = 0;
        foreach ((array)($_POST['parties'] ?? []) as $pid => $party) {
            $pid = (int)$pid;
            $party = (int)$party;
            if ($pid <= 0) {
                continue;
            }
            $new = ($party > 0 && isset($validSet[$party])) ? $party : null;
            $curSt->execute([$pid]);
            $cur = $curSt->fetch();
            if (!$cur || ($cur['party_id'] === null ? null : (int)$cur['party_id']) === $new) {
                continue;
            }
            $upSt->execute([$new, $pid]);
            $changed++;
        }
        if ((string)($_POST['ajax'] ?? '') === '1') { // saved from the page: answer without reloading it
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'changed' => $changed]);
            exit;
        }
        flash('ok', $changed > 0 ? 'Saved ' . $changed . ' product' . ($changed === 1 ? '' : 's') . '.' : 'Nothing changed.');
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE products SET is_active = 1 - is_active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('ok', 'Product updated.');
    } elseif ($action === 'bulk') {
        // applies to every product matching the current search / filter (party only)
        $partyId = valid_party_id($_POST['party_id'] ?? 0);
        if ($partyId === null) {
            flash('error', 'Choose a party first.');
        } else {
            [$where, $params] = product_filter($q, $filter);
            $idq = $pdo->prepare('SELECT p.id FROM products p' . $where . ' LIMIT 5000');
            $idq->execute($params);
            $ids = $idq->fetchAll(PDO::FETCH_COLUMN);
            $setParty = $pdo->prepare('UPDATE products SET party_id = ? WHERE id = ?');
            $pdo->beginTransaction();
            foreach ($ids as $pid) {
                $setParty->execute([$partyId, (int)$pid]);
            }
            $pdo->commit();
            flash('ok', 'Updated ' . count($ids) . ' product(s).');
        }
    }
    redirect('products.php' . ($qs !== '' ? '?' . $qs : ''));
}

[$where, $params] = product_filter($q, $filter);

$cnt = $pdo->prepare('SELECT COUNT(*) FROM products p' . $where);
$cnt->execute($params);
$matchCount = (int)$cnt->fetchColumn();

$st = $pdo->prepare(
    "SELECT p.id, p.name, p.external_id, p.category, p.brand, p.party_id, p.is_active,
            (SELECT pp.base_price FROM product_prices pp WHERE pp.product_id = p.id ORDER BY pp.id DESC LIMIT 1) AS base_price
     FROM products p" . $where . ' ORDER BY p.is_active DESC, p.name LIMIT 300'
);
$st->execute($params);
$rows = $st->fetchAll();

// price history for the listed products (one query)
$history = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $h = $pdo->prepare("SELECT product_id, base_price, created_at FROM product_prices WHERE product_id IN ($in) ORDER BY id DESC");
    $h->execute($ids);
    foreach ($h->fetchAll() as $r) {
        $history[$r['product_id']][] = $r;
    }
}

$parties = $pdo->query('SELECT id, name FROM parties WHERE is_active = 1 ORDER BY name')->fetchAll();
$totalProducts = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();

$pageTitle = 'Products';
$active = 'products';
require __DIR__ . '/includes/header.php';

function party_options(array $parties, ?int $selected, string $first = 'No party yet'): string
{
    $out = '<option value="0">' . e($first) . '</option>';
    foreach ($parties as $p) {
        $out .= '<option value="' . (int)$p['id'] . '"' . ((int)$p['id'] === (int)$selected ? ' selected' : '') . '>' . e($p['name']) . '</option>';
    }
    return $out;
}
?>
<div class="grid">
  <section class="card wide">
    <h2>Products <span class="tag"><?= $totalProducts ?></span></h2>
    <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search name, brand, category or ID" aria-label="Search products" style="flex:1 1 220px">
      <select name="filter" aria-label="Filter" style="flex:0 1 220px">
        <option value="">All products</option>
        <option value="missing"<?= $filter === 'missing' ? ' selected' : '' ?>>Missing party or buying price</option>
      </select>
      <button class="btn btn-ghost" type="submit">Search</button>
      <button class="btn btn-accent" type="submit" form="party-form" id="party-save" disabled>Save changes</button>
    </form>
    <form id="party-form" method="post" style="display:none">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_many">
      <input type="hidden" name="ajax" value="0">
    </form>
    <div class="hint" id="party-msg" style="margin:-6px 0 12px" aria-live="polite">Choose the party on any products, then press Save changes once.</div>

    <?php if (!$rows): ?>
      <div class="empty"><?= $totalProducts ? 'No products match.' : 'No products yet. Click "Sync from website" on the right.' ?></div>
    <?php else: ?>
    <div class="table-wrap">
      <table style="min-width:640px">
        <thead><tr><th>Product</th><th>Party</th><th>Buying price (website)</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <?= e($r['name']) ?> <?php if (!$r['is_active']): ?><span class="tag tag-off">Off</span><?php endif; ?>
              <div class="hint" style="margin:2px 0 0"><?= e(implode(' · ', array_filter([$r['brand'], $r['category'], $r['external_id'] ? 'ID ' . $r['external_id'] : '']))) ?></div>
              <?php if (!empty($history[$r['id']]) && count($history[$r['id']]) > 1): ?>
                <details style="margin-top:4px"><summary class="hint" style="cursor:pointer">Price history</summary>
                  <?php foreach (array_slice($history[$r['id']], 0, 6) as $h): ?>
                    <div class="hint" style="margin:2px 0"><?= e(inr($h['base_price'])) ?> · <?= e(date('d M Y', strtotime($h['created_at']))) ?></div>
                  <?php endforeach; ?>
                </details>
              <?php endif; ?>
            </td>
            <td colspan="3" style="padding:0;border-top:1px solid var(--line)">
              <div style="display:flex;gap:8px;align-items:center;padding:8px 10px">
                <select name="parties[<?= (int)$r['id'] ?>]" form="party-form" class="party-sel" data-orig="<?= (int)($r['party_id'] ?? 0) ?>" aria-label="Party for <?= e($r['name']) ?>" style="height:40px;flex:1 1 0;min-width:120px"><?= party_options($parties, $r['party_id'] !== null ? (int)$r['party_id'] : null) ?></select>
                <div style="flex:0 0 120px;font-weight:800"><?= $r['base_price'] !== null ? e(inr($r['base_price'])) : '<span class="tag tag-warn">Not on website</span>' ?></div>
                <form method="post" style="margin:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit" name="action" value="toggle"><?= $r['is_active'] ? 'Off' : 'On' ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">
      <?= $matchCount > count($rows) ? 'Showing the first ' . count($rows) . ' of ' . $matchCount . '. Search to narrow the list. ' : '' ?>
      The buying price is read from your website and cannot be changed here. To change it, edit the product on the website; it appears here and in New order within a few minutes. Old orders keep the price they were made with.
    </p>
    <?php endif; ?>
  </section>

  <div class="narrow" style="display:flex;flex-direction:column;gap:20px">
    <section class="card">
      <h2>Sync from website</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="sync">
        <button class="btn btn-accent btn-block" type="submit">Sync from website</button>
        <div class="hint">Brings in every product and its buying price from your website. Your party is never changed. This also happens by itself every few minutes when someone opens New order. A changed website price applies to new orders only.</div>
      </form>
    </section>

    <?php if ($matchCount > 0): ?>
    <section class="card">
      <h2>Set party for all <?= $matchCount ?> shown</h2>
      <form method="post" onsubmit="return confirm('Apply to all <?= $matchCount ?> matching products?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk">
        <div class="field"><label for="bulk_party">Party</label><select id="bulk_party" name="party_id"><?= party_options($parties, null, 'Do not change') ?></select></div>
        <button class="btn btn-block" type="submit">Apply to all <?= $matchCount ?></button>
        <div class="hint">Sets the party for every product matching your current search, not only the ones listed. Search first, for example a brand name.</div>
      </form>
    </section>
    <?php endif; ?>
  </div>
</div>
<script>
// One common Save: remembers which party selections were changed, saves them all together in the background (no page reload).
(function () {
  var form = document.getElementById('party-form'), btn = document.getElementById('party-save'), msg = document.getElementById('party-msg');
  if (!form || !btn) { return; }
  var sels = Array.prototype.slice.call(document.querySelectorAll('.party-sel'));
  var base = msg ? msg.textContent : '';
  function changed() { return sels.filter(function (s) { return s.value !== s.getAttribute('data-orig'); }); }
  function refresh() {
    var n = changed().length;
    btn.disabled = n === 0;
    btn.textContent = n ? 'Save changes (' + n + ')' : 'Save changes';
    sels.forEach(function (s) { s.style.outline = s.value !== s.getAttribute('data-orig') ? '2px solid #16A34A' : ''; });
  }
  sels.forEach(function (s) { s.addEventListener('change', function () { refresh(); if (msg) { msg.style.color = ''; msg.textContent = base; } }); });
  form.addEventListener('submit', function (ev) {
    if (!window.fetch) { return; } // old browser: normal submit still works
    ev.preventDefault();
    var todo = changed();
    if (!todo.length) { return; }
    var fd = new FormData(form);
    fd.set('ajax', '1');
    btn.disabled = true; btn.textContent = 'Saving…';
    fetch('products.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { throw new Error('no'); }
        todo.forEach(function (s) { s.setAttribute('data-orig', s.value); });
        refresh();
        if (msg) { msg.style.color = '#15803D'; msg.textContent = 'Saved ' + todo.length + ' product' + (todo.length === 1 ? '' : 's') + '.'; }
      })
      .catch(function () {
        refresh();
        if (msg) { msg.style.color = '#B91C1C'; msg.textContent = 'Could not save. Check your internet and press Save changes again.'; }
      });
  });
  refresh();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>