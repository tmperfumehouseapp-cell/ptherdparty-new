<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/products_lib.php';
require __DIR__ . '/includes/orders_lib.php';
require_once __DIR__ . '/includes/shiprocket_lib.php';
require_once __DIR__ . '/includes/order_auto_lib.php';
$user = require_login();
$pdo = db();
$isAdmin = $user['role'] === 'admin';

$old = null; // posted values, kept when something is missing so nothing has to be typed again

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $orderId = create_order($pdo, $user, $_POST, $_FILES);
        conf_mark_required($pdo, $orderId, (int)$user['id']); // the confirmation to the customer is a must before anything else
        if (wa_enabled()) { // official WhatsApp confirmation; never blocks the order
            require_once __DIR__ . '/includes/wa_lib.php';
            wa_on_order_created($pdo, $orderId);
        }
        sr_kick($pdo, $orderId); // auto-ship dealers: goes to Shiprocket right after this page is sent
        redirect('order_done.php?id=' . $orderId);
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
        $old = $_POST;
    }
}

// buying prices come from the website only: copied in here every few minutes, never typed
$costSync = sync_website_costs($pdo, $config, (int)$user['id'], 300, 8);
$accounts = $pdo->query('SELECT id, name FROM accounts WHERE is_active = 1 ORDER BY name')->fetchAll();
$numbers = $pdo->query('SELECT id, label, phone FROM order_numbers WHERE is_active = 1 ORDER BY label')->fetchAll();
$groups = active_groups($pdo);
$refReady = ref_migrate($pdo); // the two columns for the reference notes and image

// products for the search box: id, name, party, current base price
$prodRows = $pdo->query(
    "SELECT p.id, p.name, pa.name AS party,
            (SELECT pp.base_price FROM product_prices pp WHERE pp.product_id = p.id ORDER BY pp.id DESC LIMIT 1) AS base
     FROM products p
     LEFT JOIN parties pa ON pa.id = p.party_id
     WHERE p.is_active = 1
     ORDER BY p.name"
)->fetchAll();
$products = [];
foreach ($prodRows as $r) {
    $products[] = [
        'id'    => (int)$r['id'],
        'name'  => $r['name'],
        'party' => $r['party'],
        // the base (buying) price is only sent to the Manager; salespeople just learn whether it is set
        'base'  => ($isAdmin && $r['base'] !== null) ? (float)$r['base'] : null,
        'hasBase' => $r['base'] !== null,
    ];
}

$today = biz_today();
$stats = today_stats($pdo, $today, $isAdmin ? null : (int)$user['id']);
if (!$isAdmin) {
    foreach ($stats as $k => $v) {
        $stats[$k] = ['count' => $v['count'], 'profit' => 0.0]; // salespeople never receive profit figures
    }
}
$team = [];
if ($isAdmin) {
    $team = $pdo->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();
}
$selectedSales = (int)($old['salesperson_id'] ?? $user['id']);

$oldMode = ($old['payment_mode'] ?? 'prepaid') === 'cod' ? 'cod' : 'prepaid';
$oldAccount = (int)($old['account_id'] ?? 0);
$oldNumber = (int)($old['number_id'] ?? 0);
$oldItems = [];
if ($old && !empty($old['items']) && is_array($old['items'])) {
    foreach ($old['items'] as $it) {
        if (is_array($it) && (int)($it['product_id'] ?? 0) > 0) {
            $oldItems[] = [
                'product_id' => (int)$it['product_id'],
                'qty'        => (int)($it['qty'] ?? 1),
                'sell_price' => (string)($it['sell_price'] ?? ''),
            ];
        }
    }
}

$data = [
    'products' => $products,
    'stats'    => (object)$stats,
    'groups'   => $groups,
    'selfId'   => (int)$user['id'],
    'isAdmin'  => $isAdmin,
    'old'      => $oldItems,
];

$pageTitle = 'New order';
$active = 'new_order';
$extraCss = 'assets/order.css';
require __DIR__ . '/includes/header.php';
?>
<script>document.body.classList.add('no-page');</script>
<link rel="stylesheet" href="assets/new_order_mobile.css?v=<?= (int)@filemtime(__DIR__ . '/assets/new_order_mobile.css') ?>" media="(max-width: 760px)">
<section class="meter-card" aria-label="Today progress">
  <div class="meter-main">
    <div class="meter-row">
      <div class="meter-msg" id="meter-msg">Loading your day…</div>
      <div class="meter-count" id="meter-count"></div>
    </div>
    <div class="meter-track"><div class="meter-fill" id="meter-fill"></div></div>
  </div>
  <div class="meter-nums">
    <div><div class="k">Rate now</div><div class="v" id="meter-rate">8%</div></div>
    <div><div class="k">Orders today</div><div class="v" id="meter-orders">0</div></div>
  </div>
</section>

<form id="order-form" method="post" autocomplete="off" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="grid">
    <div class="wide order-col">

      <section class="card">
        <div class="step"><span class="step-n">1</span><h2>Payment</h2></div>
        <div class="pay-row">
          <div class="seg" role="radiogroup" aria-label="Payment type">
            <input type="radio" name="payment_mode" id="pm-prepaid" value="prepaid" <?= $oldMode === 'prepaid' ? 'checked' : '' ?>><label for="pm-prepaid">Prepaid</label>
            <input type="radio" name="payment_mode" id="pm-cod" value="cod" <?= $oldMode === 'cod' ? 'checked' : '' ?>><label for="pm-cod">COD</label>
          </div>
          <div id="account-box" class="account-box">
            <div class="mini" id="account-label"><?= $oldMode === 'cod' ? 'Advance received in' : 'Money received in' ?></div>
            <?php if (!$accounts): ?>
              <div class="hint">No accounts yet. <?= $isAdmin ? '<a href="accounts.php">Create one</a>' : 'Ask the admin to create one.' ?></div>
            <?php else: ?>
              <div class="chips">
                <?php foreach ($accounts as $i => $a): ?>
                  <input type="radio" name="account_id" id="acc-<?= (int)$a['id'] ?>" value="<?= (int)$a['id'] ?>" <?= ($oldAccount === (int)$a['id'] || (!$oldAccount && $i === 0)) ? 'checked' : '' ?>>
                  <label for="acc-<?= (int)$a['id'] ?>"><?= e($a['name']) ?></label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div id="advance-box" class="field" style="margin:16px 0 0;max-width:280px" <?= $oldMode === 'cod' ? '' : 'hidden' ?>>
          <label for="advance_amount">Advance paid now (₹)</label>
          <input id="advance_amount" name="advance_amount" type="text" inputmode="decimal" placeholder="Amount customer paid in advance" value="<?= e($old['advance_amount'] ?? '') ?>">
          <div class="hint" id="advance-hint">COD orders need an advance. The rest is collected on delivery.</div>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:16px;margin:16px 0 0">
          <?php if ($isAdmin && $team): ?>
          <div class="field" style="margin:0;flex:1 1 240px;max-width:300px">
            <label for="salesperson_id">Order placed by</label>
            <select id="salesperson_id" name="salesperson_id">
              <?php foreach ($team as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === $selectedSales ? 'selected' : '' ?>><?= e($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="field" style="margin:0;flex:1 1 240px;max-width:300px">
            <label for="number_id">Order placed by number</label>
            <select id="number_id" name="number_id"<?= $numbers ? " required" : "" ?>>
              <option value="">Choose number</option>
              <?php foreach ($numbers as $n): ?>
                <option value="<?= (int)$n['id'] ?>" <?= $oldNumber === (int)$n['id'] ? 'selected' : '' ?>><?= e($n['label'] . ($n['phone'] ? ' - ' . $n['phone'] : '')) ?></option>
              <?php endforeach; ?>
              <option value="__new">+ Add new number…</option>
            </select>
          </div>
        </div>
        <div id="addnum-box" hidden style="margin-top:12px;padding:14px;border:1.5px dashed #94A3B8;border-radius:14px;background:#F8FAFC">
          <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
            <div class="field" style="margin:0;flex:1 1 150px"><label for="addnum-label">Name for this number</label><input id="addnum-label" type="text" maxlength="80" placeholder="e.g. WhatsApp 3"></div>
            <div class="field" style="margin:0;flex:1 1 150px"><label for="addnum-phone">Mobile number</label><input id="addnum-phone" type="tel" inputmode="numeric" maxlength="20" placeholder="10 digit mobile"></div>
            <button type="button" id="addnum-save" class="btn btn-ghost" style="height:46px">Save number</button>
          </div>
          <div class="hint" id="addnum-msg" style="margin-top:8px">Saved instantly and selected for this order. It is also added to the Numbers page.</div>
        </div>
        <div id="proof-box" class="field" style="margin:16px 0 0;max-width:420px">
          <label for="pay_proof">Payment screenshot <span style="color:#B91C1C">(required)</span></label>
          <input id="pay_proof" name="pay_proof" type="file" accept="image/*,application/pdf">
          <img id="proof-preview" alt="Screenshot preview" hidden style="display:none;margin-top:10px;max-width:100%;max-height:220px;border-radius:12px;border:1px solid var(--field-line)">
          <div class="hint" id="proof-hint">Attach the screenshot of the money received now. The order cannot be created without it.<?= $old ? ' <b>Attach it again, the browser clears it after a message.</b>' : '' ?></div>
          <div class="field" style="margin:14px 0 0"><label for="pay_proof2">Second payment screenshot <span style="color:var(--muted)">(optional)</span></label>
            <input id="pay_proof2" name="pay_proof2" type="file" accept="image/*,application/pdf"></div>
          <div class="field" style="margin:12px 0 0"><label for="pay_proof3">Third payment screenshot <span style="color:var(--muted)">(optional)</span></label>
            <input id="pay_proof3" name="pay_proof3" type="file" accept="image/*,application/pdf"></div>
          <div class="hint" style="margin-top:10px">Up to 3 screenshots. The payment time is read from them automatically, and the earliest time is used.
            <b>Create the order within <?= (int)AUTO_DEADLINE_MIN ?> minutes of the payment time to earn commission.</b> A later order is still saved, with commission ₹0.</div>
        </div>
      </section>

      <section class="card">
        <div class="step"><span class="step-n">2</span><h2>Customer</h2></div>
        <div class="cust-grid">
          <div><label for="our_order_id">Our order ID</label><input id="our_order_id" type="text" value="Automatic" readonly tabindex="-1" style="background:#EEF1F5;font-weight:700"><div class="hint" style="margin-top:6px">Made by the panel when you create the order.</div></div>
          <div><label for="shiprocket_order_id">Shiprocket Order ID <span style="color:var(--muted);font-weight:600">(optional)</span></label>
            <input id="shiprocket_order_id" name="shiprocket_order_id" type="text" maxlength="60" placeholder="Type it when Shiprocket gives it" <?= orders_has_sr_id($pdo) ? '' : 'disabled' ?> value="<?= e($old['shiprocket_order_id'] ?? '') ?>">
            <?php if (!orders_has_sr_id($pdo)): ?><div class="hint" style="margin-top:6px;color:#B91C1C">Run migrate_shiprocket_order_id.sql to turn this on.</div><?php endif; ?></div>
          <div><label for="customer_name">Name</label><input id="customer_name" name="customer_name" type="text" required value="<?= e($old['customer_name'] ?? '') ?>"></div>
          <div><label for="customer_phone">WhatsApp number</label><input id="customer_phone" name="customer_phone" type="tel" inputmode="numeric" placeholder="10 digit mobile" required value="<?= e($old['customer_phone'] ?? '') ?>"></div>
          <div><label for="pincode">PIN code</label><input id="pincode" name="pincode" type="text" inputmode="numeric" maxlength="6" placeholder="6 digits" required value="<?= e($old['pincode'] ?? '') ?>"></div>
          <div class="span2"><label for="address">Full address</label><input id="address" name="address" type="text" placeholder="House, street, area, city" required value="<?= e($old['address'] ?? '') ?>"></div>
        </div>
      </section>

      <section class="card">
        <div class="step"><span class="step-n">3</span><h2>Products</h2></div>
        <?php if ($isAdmin): ?>
          <?php $ago = $costSync['at'] > 0 ? max(0, (int)floor((time() - $costSync['at']) / 60)) : null; ?>
          <div class="hint" style="margin:-6px 0 12px">
            Buying prices come from your website and cannot be changed here.
            <?php if ($ago !== null): ?>Last copied <?= $ago < 1 ? 'just now' : $ago . ' min ago' ?>.<?php endif; ?>
            <?php if ($costSync['error'] !== ''): ?><b style="color:var(--warn)">Could not reach the website just now (<?= e($costSync['error']) ?>), so the last copied prices are shown.</b><?php endif; ?>
          </div>
        <?php endif; ?>
        <div id="rows" class="rows"></div>
        <div class="rows-foot">
          <button type="button" id="add-row" class="btn btn-ghost">+ Add product</button>
          <div class="disc">
            <label for="discount">Overall discount (₹)</label>
            <input id="discount" name="discount" type="text" inputmode="decimal" value="<?= e($old['discount'] ?? '0') ?>">
          </div>
        </div>
        <?php if (!$products): ?>
          <div class="hint" style="margin-top:12px">No products yet. <?= $isAdmin ? '<a href="products.php">Add products first</a>.' : 'Ask the admin to add products.' ?></div>
        <?php endif; ?>
      </section>

      <section class="card" id="ref-card">
        <div class="step"><span class="step-n">4</span><h2>Add Reference</h2></div>
        <?php if ($isAdmin && !$refReady): ?>
          <div class="form-err" role="alert" style="margin-bottom:12px"><b>One database update is missing:</b> the reference could not be added to orders. Run <code>ALTER TABLE orders ADD COLUMN ref_notes TEXT NULL, ADD COLUMN ref_image VARCHAR(80) NULL;</code> in phpMyAdmin. Until then orders are saved without the reference.</div>
        <?php endif; ?>
        <div class="field" style="margin:0">
          <label for="ref_notes">Reference notes <span style="color:#B91C1C">(required)</span></label>
          <textarea id="ref_notes" name="ref_notes" rows="4" maxlength="2000" required placeholder="Write the reference details or any important information about this order" style="width:100%;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:12px;font-family:inherit"><?= e($old['ref_notes'] ?? '') ?></textarea>
        </div>
        <div class="field" style="margin:16px 0 0;max-width:420px">
          <label for="ref_image">Reference image <span style="color:#B91C1C">(required)</span></label>
          <input id="ref_image" name="ref_image" type="file" accept="image/*" required>
          <img id="ref-preview" alt="Reference preview" hidden style="display:none;margin-top:10px;max-width:100%;max-height:220px;border-radius:12px;border:1px solid var(--field-line)">
          <div class="hint" id="ref-hint">Attach a photo or screenshot for this order. The order cannot be created without it.<?= $old ? ' <b>Attach it again, the browser clears it after a message.</b>' : '' ?></div>
        </div>
      </section>
    </div>

    <aside class="summary narrow" aria-label="Order summary">
      <div class="sum-top">New order</div>
      <div>
        <div class="sum-k">Customer pays</div>
        <div class="sum-big" id="sum-total">₹0</div>
      </div>
      <div class="sum-lines" id="sum-cod" style="display:none">
        <div><span>Advance now</span><span id="sum-adv">₹0</span></div>
        <div><span>On delivery</span><span id="sum-bal">₹0</span></div>
      </div>
      <div class="sum-lines">
        <div><span id="sum-items">0 items</span><span id="sum-sub">₹0</span></div>
        <div><span>Discount</span><span id="sum-disc">-₹0</span></div>
        <?php if ($isAdmin): ?><div><span>Base cost</span><span id="sum-base">₹0</span></div><?php endif; ?>
      </div>
      <?php if ($isAdmin): ?>
      <div class="sum-profit">
        <div><div class="sum-k">Profit</div><div class="sum-pv" id="sum-profit">-</div></div>
        <div class="right"><div class="sum-k" id="sum-earn-k">You earn (8%)</div><div class="sum-ev" id="sum-earn">-</div></div>
      </div>
      <?php endif; ?>
      <div class="form-err" id="form-err" role="alert" hidden></div>
      <div class="sum-note" id="proof-note" style="color:#FDE68A;font-weight:700">Attach the payment screenshot to create the order</div>
      <button type="submit" id="submit-btn" class="cta" disabled>Create order
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
      <div class="sum-note">You can edit or delete this order for 1 hour</div>
    </aside>
  </div>
</form>

<script>window.ORDER_DATA = <?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script>
<?php
// if the page script is missing or from an old version, say so on the page (Add product depends on it)
$jsPath = __DIR__ . '/assets/new_order.js';
$jsText = is_file($jsPath) ? (string)@file_get_contents($jsPath) : '';
$jsProblem = $jsText === '' ? 'The file assets/new_order.js is missing on the server, so Add product cannot work. Upload it again from your backup into the assets folder.'
    : (strpos($jsText, 'add-row') === false ? 'The file assets/new_order.js on the server is not the right version (it does not handle Add product). Upload the newest copy.' : '');
?>
<?php if ($jsProblem !== ''): ?>
<div class="form-err" role="alert" style="margin:12px 0"><b>Problem on the server:</b> <?= e($jsProblem) ?></div>
<?php endif; ?>
<script>
// shows the real reason on the page when a script fails, so it can be photographed instead of guessed
window.addEventListener('error', function (ev) {
  var b = document.getElementById('form-err');
  if (!b) { return; }
  b.hidden = false;
  b.textContent = 'Page script problem: ' + (ev.message || (ev.target && (ev.target.src || ev.target.href) ? 'could not load ' + (ev.target.src || ev.target.href) : 'unknown error'));
}, true);
</script>
<script src="assets/new_order.js?v=<?= (int)@filemtime(__DIR__ . '/assets/new_order.js') ?>"></script>
<script>
(function () {
  var sel = document.getElementById('number_id'), box = document.getElementById('addnum-box');
  if (!sel || !box) return;
  var lab = document.getElementById('addnum-label'), ph = document.getElementById('addnum-phone');
  var msg = document.getElementById('addnum-msg'), btn = document.getElementById('addnum-save');
  var prev = sel.value === '__new' ? '' : sel.value;
  var hint = msg.textContent;
  sel.addEventListener('change', function () {
    if (sel.value === '__new') { box.hidden = false; lab.focus(); }
    else { box.hidden = true; prev = sel.value; }
  });
  btn.addEventListener('click', function () {
    var fd = new FormData();
    fd.append('csrf', document.querySelector('#order-form input[name=csrf]').value);
    fd.append('label', lab.value); fd.append('phone', ph.value);
    btn.disabled = true; msg.style.color = ''; msg.textContent = 'Saving…';
    fetch('number_add.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (!d.ok) { msg.style.color = '#B91C1C'; msg.textContent = d.error; return; }
        var o = document.createElement('option');
        o.value = d.id; o.textContent = d.text;
        sel.insertBefore(o, sel.querySelector('option[value="__new"]'));
        sel.value = String(d.id); prev = sel.value;
        lab.value = ''; ph.value = ''; msg.textContent = hint; box.hidden = true;
      })
      .catch(function () { btn.disabled = false; msg.style.color = '#B91C1C'; msg.textContent = 'Could not save. Check your connection and try again.'; });
  });
  document.getElementById('order-form').addEventListener('submit', function (e) {
    if (sel.value === '__new') { e.preventDefault(); msg.style.color = '#B91C1C'; msg.textContent = 'Save the new number first, or choose an existing one.'; box.hidden = false; }
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>