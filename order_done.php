<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/products_lib.php';
require __DIR__ . '/includes/orders_lib.php';
require_once __DIR__ . '/includes/vendor_lib.php';
require_once __DIR__ . '/includes/dealer_send_lib.php';
require_once __DIR__ . '/includes/shiprocket_lib.php';
$user = require_login();
$pdo = db();
$isAdmin = $user['role'] === 'admin';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare(
    'SELECT o.*, u.name AS salesperson_name FROM orders o JOIN users u ON u.id = o.salesperson_id WHERE o.id = ?'
);
$st->execute([$id]);
$order = $st->fetch();

// salespeople can open only their own orders
if (!$order || (!$isAdmin && (int)$order['salesperson_id'] !== (int)$user['id'])) {
    http_response_code(404);
    exit('Order not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $tid = trim((string)($_POST['tracking_id'] ?? ''));
    $link = trim((string)($_POST['tracking_link'] ?? ''));
    if ($link !== '' && !preg_match('~^https?://~i', $link)) {
        flash('error', 'Tracking link must start with http:// or https://');
    } else {
        $newStatus = $order['status'];
        if ($order['status'] === 'created' && ($tid !== '' || $link !== '')) {
            $newStatus = 'dispatched';
        }
        if (array_key_exists('shiprocket_order_id', $_POST)) {
            try {
                save_shiprocket_order_id($pdo, $id, $_POST['shiprocket_order_id']);
            } catch (RuntimeException $ex) {
                flash('error', $ex->getMessage());
                redirect('order_done.php?id=' . $id);
            }
        }
        $pdo->prepare('UPDATE orders SET tracking_id = ?, tracking_link = ?, status = ? WHERE id = ?')
            ->execute([
                $tid === '' ? null : mb_substr($tid, 0, 100),
                $link === '' ? null : mb_substr($link, 0, 500),
                $newStatus,
                $id,
            ]);
        flash('ok', 'Tracking saved. Use the WhatsApp button to tell the customer.');
    }
    redirect('order_done.php?id=' . $id);
}

$items = $pdo->prepare(
    'SELECT oi.qty, oi.sell_price, p.name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id'
);
$items->execute([$id]);
$lines = $items->fetchAll();

// day numbers for the salesperson who placed it
$groups = active_groups($pdo);
$stats = today_stats($pdo, $order['order_date'], (int)$order['salesperson_id']);
$dayCount = $stats[(int)$order['salesperson_id']]['count'] ?? 0;
$dayProfit = $stats[(int)$order['salesperson_id']]['profit'] ?? 0.0;
$rate = commission_rate($dayCount, $groups);
$commStatus = $order['commission_status'] ?? null; // payment-time check (null for old orders)
$earns = order_earns($order); // both rules: in time, and (when switched on) the WhatsApp confirmation verified
$earnThis = $earns ? max((float)$order['profit'], 0) * $rate : 0.0;
$waAuto = wa_enabled(); // the official confirmation goes out by itself; no status is shown here
// the lock: until the customer has been sent the confirmation, nothing else in the panel opens
$confOn = orders_has_conf($pdo) && !empty($order['conf_required']);
$confirmed = !$confOn || $order['conf_at'] !== null;
$showManual = !$waAuto || (string)($order['wa_status'] ?? '') === 'failed' || (string)($order['wa_status'] ?? '') === '';
$alreadyOpened = !empty($order['conf_opened_at']);
$earnToday = max($dayProfit, 0) * $rate;
$pct = $groups > 0 ? min(100, $dayCount / $groups * 100) : 0;

$hasTracking = !empty($order['tracking_id']) || !empty($order['tracking_link']);
$confirmUrl = wa_link($order['customer_phone'], order_wa_confirm_text($order));
$trackingUrl = $hasTracking ? wa_link($order['customer_phone'], order_wa_tracking_text($order)) : '';


$pageTitle = 'Order ' . order_no($order['id']);
$active = 'new_order';
$extraCss = 'assets/order.css';
require __DIR__ . '/includes/header.php';
?>
<div class="done-wrap">

  <section class="hero" aria-live="polite">
    <div class="confetti" aria-hidden="true">
      <i style="left:8%;top:14%;width:10px;height:10px;background:var(--accent);transform:rotate(20deg)"></i>
      <i style="left:18%;top:62%;width:8px;height:8px;border-radius:50%;background:#FBBF24"></i>
      <i style="left:30%;top:10%;width:7px;height:16px;background:#38BDF8;transform:rotate(-35deg)"></i>
      <i style="right:9%;top:16%;width:12px;height:12px;background:#FBBF24;transform:rotate(40deg)"></i>
      <i style="right:20%;top:60%;width:9px;height:9px;border-radius:50%;background:var(--accent)"></i>
      <i style="right:31%;top:9%;width:7px;height:16px;background:#F472B6;transform:rotate(30deg)"></i>
    </div>
    <div class="tick">
      <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#0F172A" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
    </div>
    <h2>Order created</h2>
    <?php if (!empty($order['shiprocket_order_id'])): ?><div class="sub">Shiprocket Order ID <b><?= e($order['shiprocket_order_id']) ?></b></div><?php endif; ?>
    <div class="sub">Order <?= e(order_no($order['id'])) ?> for <?= e($order['customer_name']) ?> · <?= e(inr($order['total'])) ?> · <?= $order['payment_mode'] === 'cod' ? 'COD' : 'Prepaid' ?></div>
    <?php if ($order['payment_mode'] === 'cod' && (float)$order['advance_amount'] > 0): ?>
      <div class="sub">Advance <?= e(inr($order['advance_amount'])) ?> received · <?= e(inr((float)$order['total'] - (float)$order['advance_amount'])) ?> to collect on delivery</div>
    <?php endif; ?>

    <?php if (!$earns && comm_counts($commStatus)): ?>
      <div class="sub" style="color:#FDE68A;font-weight:700">Commission is added once the order is fully verified.</div>
    <?php endif; ?>
    <?php if ($commStatus !== null && !empty($order['commission_note'])): ?>
      <div class="sub" style="color:<?= comm_counts($commStatus) ? '#86EFAC' : '#FDE68A' ?>;font-weight:700"><?= e(auto_status_text($commStatus)) ?> <?= e($order['commission_note']) ?></div>
    <?php endif; ?>
    <div class="tiles">
      <div class="tile"><div class="k">You earned</div><div class="v acc">+<?= e(inr($earnThis)) ?></div></div>
      <?php if ($isAdmin): ?><div class="tile"><div class="k">Profit</div><div class="v"><?= e(inr($order['profit'])) ?></div></div><?php endif; ?>
      <div class="tile"><div class="k">Earned today</div><div class="v"><?= e(inr($earnToday)) ?></div></div>
    </div>

    <div class="hero-meter">
      <div class="row">
        <span><?php
          if ($groups <= 0) { echo 'Add groups to unlock the 10% rate'; }
          elseif ($dayCount >= $groups) { echo '10% unlocked on today\'s orders'; }
          else { $left = $groups - $dayCount; echo e($left . ($left === 1 ? ' more order unlocks' : ' more orders unlock') . ' 10%'); }
        ?></span>
        <span style="color:#CBD5E1"><?= $groups > 0 ? (int)$dayCount . ' / ' . (int)$groups : (int)$dayCount . ' today' ?></span>
      </div>
      <div class="track"><div class="fill" style="width:<?= e(round($pct, 1)) ?>%"></div></div>
    </div>
  </section>

<?php if (!$confirmed): ?>
  <section class="card" id="confbox" style="border:2px solid #F59E0B">
    <h2 style="margin:0 0 6px">Send the order confirmation to the customer</h2>
    <p class="hint" style="margin:0 0 12px">This is a must. The panel stays locked, and <b>Create next order</b> opens only after the customer has been sent the confirmation.</p>
    <?php if ($waAuto): ?><div id="auto-wait" class="hint" style="font-weight:700;margin:0 0 10px">Sending the confirmation to the customer automatically…</div><?php endif; ?>
    <div id="manual-box"<?= $showManual ? '' : ' hidden' ?>>
      <div style="display:flex;flex-direction:column;gap:10px">
        <a class="wa-btn" id="wa-send" href="<?= e($confirmUrl) ?>" target="whatsapp_tab">Send order confirmation on WhatsApp Web</a>
        <button type="button" id="wa-done" class="btn btn-accent" disabled>I have sent it</button>
        <div class="hint" id="conf-msg" style="margin:0">Press the green button, press Send in WhatsApp, then come back here and press "I have sent it".</div>
      </div>
    </div>
    <?php if ($isAdmin): ?><div style="margin-top:12px"><button type="button" id="conf-release" class="btn btn-ghost btn-sm">Manager: unlock without sending</button></div><?php endif; ?>
  </section>
  <script>
  (function () {
    var id = <?= (int)$order['id'] ?>, token = <?= json_encode(csrf_token()) ?>;
    var $ = function (x) { return document.getElementById(x); };
    function post(action) {
      var fd = new FormData(); fd.append('csrf', token); fd.append('id', id); fd.append('action', action);
      return fetch('order_confirm.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    var send = $('wa-send'), done = $('wa-done'), msg = $('conf-msg'), box = $('manual-box'), wait = $('auto-wait'), rel = $('conf-release');
    if (<?= $alreadyOpened ? 'true' : 'false' ?>) { done.disabled = false; }
    send.addEventListener('click', function () {
      post('opened').then(function () { setTimeout(function () { done.disabled = false; }, 4500); });
    });
    done.addEventListener('click', function () {
      done.disabled = true;
      post('sent').then(function (d) {
        if (d.ok) { location.reload(); return; }
        msg.textContent = d.error || 'Not confirmed yet.'; msg.style.color = '#B91C1C';
        setTimeout(function () { done.disabled = false; }, 2000);
      }).catch(function () { msg.textContent = 'Could not check. Try again.'; done.disabled = false; });
    });
    if (rel) {
      rel.addEventListener('click', function () {
        if (confirm('Unlock the panel without a confirmation sent to the customer?')) { post('release').then(function () { location.reload(); }); }
      });
    }
    // automatic confirmation: look every 3 seconds, and after 24 seconds also offer sending it by hand
    if (wait) {
      var n = 0;
      var iv = setInterval(function () {
        n++;
        fetch('order_confirm.php?id=' + id, { credentials: 'same-origin', cache: 'no-store' })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (d.confirmed) { clearInterval(iv); location.reload(); } })
          .catch(function () {});
        if (n === 8 && box.hidden) { box.hidden = false; wait.textContent = 'Still waiting for the automatic confirmation. You can send it yourself:'; }
      }, 3000);
    }
  })();
  </script>
<?php endif; ?>

  <section class="card">
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px">
      <h2 style="margin:0">Tell the customer</h2>
    </div>
    <form method="post" autocomplete="off" class="track-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
      <?php if (orders_has_sr_id($pdo)): ?><div style="grid-column:1/-1"><label for="shiprocket_order_id">Shiprocket Order ID</label><input id="shiprocket_order_id" name="shiprocket_order_id" type="text" maxlength="60" placeholder="Type it when Shiprocket gives it" value="<?= e($order['shiprocket_order_id'] ?? '') ?>"></div><?php endif; ?>
      <div><label for="tracking_id">Tracking ID</label><input id="tracking_id" name="tracking_id" type="text" placeholder="Add when dispatched" value="<?= e($order['tracking_id'] ?? '') ?>"></div>
      <div><label for="tracking_link">Tracking link</label><input id="tracking_link" name="tracking_link" type="text" placeholder="https://" value="<?= e($order['tracking_link'] ?? '') ?>"></div>
      <div style="grid-column:1/-1"><button class="btn btn-ghost" type="submit">Save</button></div>
    </form>

    <div style="display:flex;flex-direction:column;gap:10px;margin-top:16px">
      <?php if ($waAuto): ?>
        <div class="hint" style="margin:0">The order confirmation is sent to the customer automatically.</div>
        <?php if ($hasTracking): ?><a class="wa-btn" href="<?= e($trackingUrl) ?>" target="whatsapp_tab">Send tracking on WhatsApp Web</a><?php endif; ?>
      <?php elseif ($hasTracking): ?>
        <a class="wa-btn" href="<?= e($trackingUrl) ?>" target="whatsapp_tab">Send tracking on WhatsApp Web</a>
        <a class="wa-btn alt" href="<?= e($confirmUrl) ?>" target="whatsapp_tab">Send order confirmation instead</a>
      <?php else: ?>
        <a class="wa-btn" href="<?= e($confirmUrl) ?>" target="whatsapp_tab">Send order confirmation on WhatsApp Web</a>
      <?php endif; ?>
      <div class="hint" style="text-align:center;margin:0">Opens the customer's chat with the message ready. Press Send in WhatsApp.</div>
    </div>
    <div style="margin-top:16px;border-top:1px solid var(--line);padding-top:14px">
      <b>Official contact cards</b>
      <div class="hint" style="margin:4px 0 10px">The message already carries a tap-to-save link for each number. To send the contact card itself, download it here and attach it in WhatsApp (paperclip, then Document). The customer taps it and chooses Save.</div>
      <div style="display:flex;flex-wrap:wrap;gap:10px">
        <?php foreach (official_contacts() as $ck => $cc): ?>
          <a class="btn btn-ghost" style="text-decoration:none" href="contact.php?c=<?= e($ck) ?>" download><?= e($cc['name']) ?> (.vcf)</a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="card">
    <h2>Order <?= e(order_no($order['id'])) ?></h2>
    <ul class="order-lines">
      <?php foreach ($lines as $l): ?>
        <li><span><?= e($l['name']) ?> × <?= (int)$l['qty'] ?></span><span><?= e(inr($l['qty'] * $l['sell_price'])) ?></span></li>
      <?php endforeach; ?>
      <?php if ((float)$order['discount'] > 0): ?>
        <li><span>Discount</span><span>-<?= e(inr($order['discount'])) ?></span></li>
      <?php endif; ?>
      <li style="border-top:1px solid var(--line);padding-top:8px;font-weight:800"><span>Total</span><span><?= e(inr($order['total'])) ?></span></li>
    </ul>
    <p class="hint" style="margin-bottom:0"><?= e($order['customer_name']) ?>, <?= e($order['address']) ?> - <?= e($order['pincode']) ?></p>
  </section>

  <?= shipment_block($pdo, $order, $isAdmin) ?>
  <?php if ($isAdmin) { echo dealer_send_block($pdo, $order); } ?>

  <div style="display:flex;flex-wrap:wrap;gap:12px">
    <?php if ($confirmed): ?>
    <a class="btn btn-accent" style="flex:1 1 220px;display:flex;align-items:center;justify-content:center;text-decoration:none;min-height:54px;font-size:16px" href="new_order.php">Create next order</a>
    <a class="btn btn-ghost" style="flex:1 1 160px;display:flex;align-items:center;justify-content:center;text-decoration:none;min-height:54px;font-size:16px" href="index.php">Home</a>
    <?php else: ?>
    <span class="btn btn-accent" aria-disabled="true" style="flex:1 1 220px;display:flex;align-items:center;justify-content:center;min-height:54px;font-size:16px;opacity:.45;cursor:not-allowed">🔒 Create next order</span>
    <span class="btn btn-ghost" aria-disabled="true" style="flex:1 1 160px;display:flex;align-items:center;justify-content:center;min-height:54px;font-size:16px;opacity:.45;cursor:not-allowed">🔒 Home</span>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>