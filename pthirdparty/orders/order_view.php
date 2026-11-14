<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/products_lib.php';
require __DIR__ . '/includes/orders_lib.php';
require __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/proof_lib.php';
require_once __DIR__ . '/includes/dealer_send_lib.php';
require_once __DIR__ . '/includes/shiprocket_lib.php';
$user = require_login();
$pdo = db();
$isAdmin = $user['role'] === 'admin';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

function load_order(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT o.*, u.name AS salesperson_name FROM orders o JOIN users u ON u.id = o.salesperson_id WHERE o.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

$order = load_order($pdo, $id);
$numLabel = '';
if ($order && !empty($order['number_id'])) {
    $nq = $pdo->prepare('SELECT label, phone FROM order_numbers WHERE id = ?');
    $nq->execute([(int)$order['number_id']]);
    $nr = $nq->fetch();
    $numLabel = $nr ? $nr['label'] . ($nr['phone'] ? ' (' . $nr['phone'] . ')' : '') : '';
}
if (!$order || (!$isAdmin && (int)$order['salesperson_id'] !== (int)$user['id'])) {
    http_response_code(404);
    exit('Order not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'tracking') {
            $tid = trim((string)($_POST['tracking_id'] ?? ''));
            $link = trim((string)($_POST['tracking_link'] ?? ''));
            if ($link !== '' && !preg_match('~^https?://~i', $link)) {
                throw new RuntimeException('Tracking link must start with http:// or https://');
            }
            $newStatus = $order['status'];
            if ($order['status'] === 'created' && ($tid !== '' || $link !== '')) {
                $newStatus = 'dispatched';
            }
            if (array_key_exists('shiprocket_order_id', $_POST)) {
                save_shiprocket_order_id($pdo, $id, $_POST['shiprocket_order_id']);
            }
            $pdo->prepare('UPDATE orders SET tracking_id = ?, tracking_link = ?, status = ? WHERE id = ?')
                ->execute([$tid === '' ? null : mb_substr($tid, 0, 100), $link === '' ? null : mb_substr($link, 0, 500), $newStatus, $id]);
            flash('ok', 'Tracking saved.');
        } elseif (!$isAdmin) {
            http_response_code(403);
            exit('Admin only.');
        } elseif ($action === 'wa_resend') {
            $r = wa_send_confirmation($pdo, $id, (int)$user['id']);
            flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Confirmation sent to WhatsApp. The status updates when WhatsApp reports back.' : (string)($r['error'] ?? 'Could not send.'));
        } elseif ($action === 'paytime') {
            $r = auto_manual_time($pdo, $id, str_replace('T', ' ', trim((string)($_POST['paid_at'] ?? ''))));
            flash('ok', 'Payment time saved. ' . $r['note']);
        } elseif ($action === 'commission') {
            $to = (($_POST['decision'] ?? '') === 'approve') ? 'approved' : 'rejected';
            $pdo->prepare('UPDATE orders SET commission_status = ?, commission_note = ? WHERE id = ?')
                ->execute([$to, $to === 'approved' ? 'Approved by the manager. Commission counted.' : 'Confirmed by the manager. No commission.', $id]);
            flash('ok', $to === 'approved' ? 'Commission approved for this order.' : 'Commission set to ₹0 for this order.');
        } elseif ($action === 'proof') {
            $newFile = save_proof_upload($_FILES['pay_proof'] ?? null);
            try {
                proof_delete_for($pdo, 'order', $id);
                proof_add($pdo, 'order', $id, null, $newFile, (int)$user['id']);
            } catch (Throwable $ex) {
                proof_remove_file($newFile);
                throw $ex;
            }
            flash('ok', 'Payment screenshot saved.');
        } elseif ($action === 'delivered') {
            mark_delivered($pdo, $id);
            flash('ok', 'Marked as delivered.');
        } elseif ($action === 'cod') {
            $amt = add_cod_receipt($pdo, $user, $id, num($_POST['amount'] ?? 0), (int)($_POST['account_id'] ?? 0), (string)($_POST['received_date'] ?? ''), 'manual');
            flash('ok', 'COD payment of ' . inr($amt) . ' recorded.');
        } elseif ($action === 'refund') {
            refund_order($pdo, $user, $id, num($_POST['amount'] ?? 0), (int)($_POST['account_id'] ?? 0),
                (string)($_POST['parcel_date'] ?? ''), (string)($_POST['refund_date'] ?? ''), trim((string)($_POST['note'] ?? '')));
            flash('ok', 'Refund recorded.');
        } elseif ($action === 'edit') {
            if (sr_order_locked($pdo, $id)) {
                throw new RuntimeException('This order is booked in Shiprocket. Press Cancel shipment first, then edit it.');
            }
            $name = trim((string)($_POST['customer_name'] ?? ''));
            $phone = wa_phone((string)($_POST['customer_phone'] ?? ''));
            $pin = preg_replace('/\D+/', '', (string)($_POST['pincode'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            if ($name === '' || $phone === null || strlen($pin) !== 6 || mb_strlen($address) < 8) {
                throw new RuntimeException('Check the name, mobile number, PIN code and address.');
            }
            $newSales = (int)$order['salesperson_id'];
            if (!empty($_POST['salesperson_id'])) {
                $sq = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
                $sq->execute([(int)$_POST['salesperson_id']]);
                $found = $sq->fetchColumn();
                if (!$found) {
                    throw new RuntimeException('Choose a valid salesperson.');
                }
                $newSales = (int)$found;
            }
            $newNum = $order['number_id'] !== null ? (int)$order['number_id'] : null;
            if (isset($_POST['number_id']) && $_POST['number_id'] !== '') {
                $nq2 = $pdo->prepare('SELECT id FROM order_numbers WHERE id = ?');
                $nq2->execute([(int)$_POST['number_id']]);
                $foundNum = $nq2->fetchColumn();
                if (!$foundNum) {
                    throw new RuntimeException('Choose a valid number.');
                }
                $newNum = (int)$foundNum;
            }
            // reference: orders made with one keep it (notes cannot be emptied); older orders may add it
            $refNew = null;
            $refOld = null;
            $refNotes = null;
            if (orders_has_ref($pdo)) {
                $hadRef = trim((string)($order['ref_notes'] ?? '')) !== '' || !empty($order['ref_image']);
                $typed = trim((string)($_POST['ref_notes'] ?? ''));
                if ($hadRef || $typed !== '') {
                    $refNotes = ref_clean_notes($typed);
                }
                if (proof_uploaded($_FILES['ref_image'] ?? null)) {
                    $refNew = save_ref_image($_FILES['ref_image']);
                    $refOld = $order['ref_image'] ?? null;
                }
            }
            try {
                $pdo->prepare('UPDATE orders SET customer_name = ?, customer_phone = ?, pincode = ?, address = ?, salesperson_id = ?, number_id = ? WHERE id = ?')
                    ->execute([$name, $phone, $pin, $address, $newSales, $newNum, $id]);
                if ($refNotes !== null) {
                    $pdo->prepare('UPDATE orders SET ref_notes = ? WHERE id = ?')->execute([$refNotes, $id]);
                }
                if ($refNew !== null) {
                    $pdo->prepare('UPDATE orders SET ref_image = ? WHERE id = ?')->execute([$refNew, $id]);
                }
            } catch (Throwable $ex) {
                proof_remove_file($refNew);
                throw $ex;
            }
            if ($refNew !== null) {
                proof_remove_file($refOld); // the replaced image
            }
            flash('ok', 'Order details updated.');
        } elseif ($action === 'delete') {
            if (sr_order_locked($pdo, $id)) {
                throw new RuntimeException('This order is booked in Shiprocket. Press Cancel shipment first, then delete it.');
            }
            delete_order($pdo, $id);
            flash('ok', 'Order ' . order_no($id) . ' deleted. Its money entries were removed too.');
            redirect('orders.php');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('order_view.php?id=' . $id);
}

$items = $pdo->prepare(
    'SELECT oi.product_id, oi.qty, oi.sell_price, oi.base_price, oi.discount_share, oi.profit, p.name, p.image_url
     FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id'
);
$items->execute([$id]);
$lines = $items->fetchAll();

$cod = $pdo->prepare('SELECT c.amount, c.received_date, c.source, a.name AS account FROM cod_receipts c JOIN accounts a ON a.id = c.account_id WHERE c.order_id = ? ORDER BY c.id');
$cod->execute([$id]);
$codRows = $cod->fetchAll();
$codReceived = array_sum(array_column($codRows, 'amount'));
$advance = (float)$order['advance_amount'];
$codPending = $order['payment_mode'] === 'cod' ? round((float)$order['total'] - $advance - $codReceived, 2) : 0;

$rf = $pdo->prepare('SELECT r.amount, r.refund_date, r.parcel_received_date, r.note, a.name AS account FROM refunds r JOIN accounts a ON a.id = r.account_id WHERE r.order_id = ? ORDER BY r.id');
$rf->execute([$id]);
$refRows = $rf->fetchAll();
$refunded = array_sum(array_column($refRows, 'amount'));

$pf = $pdo->prepare("SELECT id, file FROM payment_proofs WHERE kind = 'order' AND ref_id = ? ORDER BY id DESC LIMIT 1");
$pf->execute([$id]);
$proof = $pf->fetch() ?: null;

// WhatsApp confirmation details: manager only
$waRow = null;
if ($isAdmin && orders_has_wa($pdo) && !empty($order['wa_enforced'])) {
    $wq = $pdo->prepare('SELECT o.*, u.name AS sales_name, n.label AS num_label, n.phone AS num_phone, n.wa_phone_id FROM orders o JOIN users u ON u.id = o.salesperson_id LEFT JOIN order_numbers n ON n.id = o.number_id WHERE o.id = ?');
    $wq->execute([$id]);
    $waRow = $wq->fetch() ?: null;
}

// payment-time check of the screenshots (only once the check has been used)
$timeReads = [];
$commStatus = $order['commission_status'] ?? null;
if (orders_has_comm($pdo)) {
    try {
        $tq = $pdo->prepare('SELECT id, slot, file, paid_at, status, note FROM order_proof_reads WHERE order_id = ? ORDER BY slot');
        $tq->execute([$id]);
        $timeReads = $tq->fetchAll();
    } catch (Throwable $e) {
        $timeReads = [];
    }
}

$accounts = $pdo->query('SELECT id, name FROM accounts WHERE is_active = 1 ORDER BY name')->fetchAll();
$accOpts = '';
foreach ($accounts as $a) {
    $accOpts .= '<option value="' . (int)$a['id'] . '">' . e($a['name']) . '</option>';
}

$hasTracking = !empty($order['tracking_id']) || !empty($order['tracking_link']);
$confirmUrl = wa_link($order['customer_phone'], order_wa_confirm_text($order));
$trackingUrl = $hasTracking ? wa_link($order['customer_phone'], order_wa_tracking_text($order)) : '';
$teamRows = $isAdmin ? $pdo->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll() : [];
$numberRows = $isAdmin ? $pdo->query('SELECT id, label, phone FROM order_numbers ORDER BY is_active DESC, label')->fetchAll() : [];
$canRefund = in_array($order['status'], ['delivered', 'return_received'], true) && $refunded < (float)$order['total'] - 0.005;
$dateInput = 'style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"';

$pageTitle = 'Order ' . order_no($order['id']);
$active = 'orders';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<p style="margin:-8px 0 16px"><a href="orders.php" class="mini" style="text-decoration:none">&larr; All orders</a></p>
<div class="grid">
  <div class="wide stack">

    <section class="card">
      <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:14px">
        <h2 style="margin:0"><?= e($order['customer_name']) ?></h2>
        <span class="tag <?= e(status_tag_class($order['status'])) ?>"><?= e(status_label($order['status'])) ?></span>
      </div>
      <div class="kv">
        <div><div class="k">Date</div><div class="v"><?= e(date('d M Y', strtotime($order['order_date']))) ?></div></div>
        <div><div class="k">Mobile</div><div class="v"><?= e($order['customer_phone']) ?></div></div>
        <div><div class="k">Our order ID</div><div class="v"><?= e(order_no($order['id'])) ?></div></div>
        <?php if (!empty($order['shiprocket_order_id'])): ?><div><div class="k">Shiprocket Order ID</div><div class="v"><?= e($order['shiprocket_order_id']) ?></div></div><?php endif; ?>
        <div><div class="k">Payment</div><div class="v"><?= $order['payment_mode'] === 'cod' ? 'COD' : 'Prepaid' ?></div></div>
        <div><div class="k">Salesperson</div><div class="v"><?= e($order['salesperson_name']) ?></div></div>
        <?php if ($numLabel !== ''): ?><div><div class="k">Placed on number</div><div class="v"><?= e($numLabel) ?></div></div><?php endif; ?>
      </div>
      <p class="hint" style="margin-top:10px"><?= e($order['address']) ?> - <?= e($order['pincode']) ?></p>
    </section>

    <section class="card">
      <h2>Items</h2>
      <div class="table-wrap">
        <table class="tight" style="min-width:420px">
          <thead><tr><th>Image</th><th>Product</th><th class="num">Qty</th><?php if ($isAdmin): ?><th class="num">Buying</th><?php endif; ?><th class="num">Price</th><th class="num">Amount</th><?php if ($isAdmin): ?><th class="num">Profit</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($lines as $l): ?>
            <tr>
              <td style="width:76px"><?php if (trim((string)$l['image_url']) !== ''): ?><a href="img_proxy.php?p=<?= (int)$l['product_id'] ?>" target="_blank" rel="noopener"><img src="img_proxy.php?p=<?= (int)$l['product_id'] ?>" alt="<?= e($l['name']) ?>" loading="lazy" style="width:68px;height:68px;object-fit:contain;border-radius:10px;border:1px solid var(--field-line);background:#fff;display:block"></a><?php else: ?><span class="mini">No image</span><?php endif; ?></td>
              <td><?= e($l['name']) ?></td>
              <td class="num"><?= (int)$l['qty'] ?></td>
              <?php if ($isAdmin): ?><td class="num"><?= e(inr($l['base_price'])) ?><?php if ((int)$l['qty'] > 1): ?><div class="mini">= <?= e(inr($l['qty'] * $l['base_price'])) ?></div><?php endif; ?></td><?php endif; ?>
              <td class="num"><?= e(inr($l['sell_price'])) ?></td>
              <td class="num"><?= e(inr($l['qty'] * $l['sell_price'])) ?></td>
              <?php if ($isAdmin): ?><td class="num <?= $l['profit'] < 0 ? 'neg' : 'pos' ?>"><?= e(inr($l['profit'])) ?></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="display:flex;flex-direction:column;gap:6px;margin-top:12px;align-items:flex-end;font-weight:700">
        <?php if ((float)$order['discount'] > 0): ?><div class="mini">Discount -<?= e(inr($order['discount'])) ?></div><?php endif; ?>
        <?php if ($isAdmin): ?><div class="mini">Total buying cost <?= e(inr(array_sum(array_map(static function ($x) { return $x['qty'] * $x['base_price']; }, $lines)))) ?></div><?php endif; ?>
        <div style="font-size:20px;font-weight:800">Total <?= e(inr($order['total'])) ?></div>
        <?php if ($isAdmin): ?><div class="mini">Profit after discount <?= e(inr($order['profit'])) ?></div><?php endif; ?>
      </div>
    </section>

    <?= shipment_block($pdo, $order, $isAdmin) ?>
    <?php if ($isAdmin && $order['status'] !== 'refunded') { echo dealer_send_block($pdo, $order); } ?>

    <?php if (orders_has_ref($pdo) && (trim((string)($order['ref_notes'] ?? '')) !== '' || !empty($order['ref_image']))): ?>
    <section class="card" id="reference">
      <h2>Reference</h2>
      <?php if (trim((string)($order['ref_notes'] ?? '')) !== ''): ?>
        <div style="white-space:pre-wrap;word-break:break-word;margin:0 0 12px"><?= e($order['ref_notes']) ?></div>
      <?php endif; ?>
      <?php if (!empty($order['ref_image'])): ?>
        <a href="proof.php?r=<?= (int)$id ?>" target="_blank" rel="noopener"><img src="proof.php?r=<?= (int)$id ?>" alt="Reference image" style="max-width:100%;max-height:420px;border-radius:12px;border:1px solid var(--field-line)"></a>
        <div class="hint">Tap the picture to open it full size.</div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="card">
      <h2>Payment screenshot</h2>
      <?php if ($proof && $proof['file']): ?>
        <?php if (proof_is_image($proof['file'])): ?>
          <a href="proof.php?id=<?= (int)$proof['id'] ?>" target="_blank" rel="noopener"><img src="proof.php?id=<?= (int)$proof['id'] ?>" alt="Payment screenshot" style="max-width:100%;max-height:420px;border-radius:12px;border:1px solid var(--field-line)"></a>
          <div class="hint">Tap the picture to open it full size.</div>
        <?php else: ?>
          <a class="btn btn-ghost linkbtn" href="proof.php?id=<?= (int)$proof['id'] ?>" target="_blank" rel="noopener">Open payment PDF</a>
        <?php endif; ?>
      <?php else: ?>
        <div class="empty" style="margin:0">No screenshot attached to this order.</div>
      <?php endif; ?>
      <?php if ($isAdmin): ?>
      <form method="post" enctype="multipart/form-data" style="margin-top:14px">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="proof">
        <div class="field"><label for="pay_proof"><?= $proof ? 'Replace screenshot' : 'Attach screenshot' ?></label><input id="pay_proof" name="pay_proof" type="file" accept="image/*,application/pdf" required></div>
        <button class="btn btn-ghost" type="submit">Save screenshot</button>
      </form>
      <?php endif; ?>
    </section>

    <?php if ($timeReads || $commStatus !== null): ?>
    <section class="card" id="paytime">
      <h2>Payment time check</h2>
      <?php if ($commStatus !== null): ?>
        <p style="margin:0 0 10px;font-weight:700;color:<?= comm_counts($commStatus) ? '#15803D' : '#B45309' ?>"><?= e(auto_status_text($commStatus)) ?></p>
        <?php if (!empty($order['commission_note'])): ?><div class="mini" style="margin-bottom:10px"><?= e($order['commission_note']) ?></div><?php endif; ?>
        <?php if (!empty($order['pay_time'])): ?><div class="mini">Payment time used: <b><?= e(date('d M Y, h:i A', strtotime($order['pay_time']))) ?></b> · order created <b><?= (int)($order['pay_delay_min'] ?? 0) ?> min</b> later (limit <?= (int)AUTO_DEADLINE_MIN ?>)</div><?php endif; ?>
      <?php endif; ?>
      <?php foreach ($timeReads as $tr): ?>
        <div class="mini" style="margin-top:8px">
          Screenshot <?= (int)$tr['slot'] ?>:
          <?php if ($tr['status'] === 'read' && $tr['paid_at']): ?><b><?= e(date('d M Y, h:i A', strtotime($tr['paid_at']))) ?></b><?php else: ?><span class="tag tag-warn">Time not clear</span><?php endif; ?>
          <?php if ((int)$tr['slot'] === 1 && $proof): ?><a href="proof.php?id=<?= (int)$proof['id'] ?>" target="_blank" rel="noopener">open</a>
          <?php elseif ((int)$tr['slot'] > 1): ?><a href="proof.php?x=<?= (int)$tr['id'] ?>" target="_blank" rel="noopener">open</a><?php endif; ?>
          <?php if ($tr['note']): ?><div><?= e($tr['note']) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($isAdmin && $commStatus !== null): ?>
      <form method="post" style="margin-top:14px" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="paytime">
        <div class="field"><label for="paid_at">Real payment date and time</label>
          <input id="paid_at" name="paid_at" type="datetime-local" required value="<?= !empty($order['pay_time']) ? e(date('Y-m-d\TH:i', strtotime($order['pay_time']))) : '' ?>" style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"></div>
        <button class="btn btn-ghost" type="submit">Save payment time</button>
        <div class="hint">Commission is worked out again from this time. It counts only if the order was created within <?= (int)AUTO_DEADLINE_MIN ?> minutes of it.</div>
      </form>
      <?php if (in_array($commStatus, ['review', 'late', 'rejected', 'approved'], true)): ?>
      <form method="post" style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="commission">
        <button class="btn btn-ghost btn-sm" type="submit" name="decision" value="approve">Approve commission anyway</button>
        <button class="btn btn-ghost btn-sm" type="submit" name="decision" value="reject">Set commission to ₹0</button>
      </form>
      <?php endif; ?>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($waRow): ?>
    <section class="card" id="wacheck">
      <h2>WhatsApp confirmation <?= wa_badge($waRow) ?></h2>
      <table class="tight" style="min-width:0;width:100%"><tbody>
        <tr><td>Order ID</td><td><b><?= e(order_no($waRow['id'])) ?></b></td></tr>
        <tr><td>Salesperson</td><td><?= e($waRow['sales_name']) ?></td></tr>
        <tr><td>Sent from (salesperson number)</td><td><?= e(trim(($waRow['num_label'] ?? '') . ' ' . ($waRow['num_phone'] ?? ''))) ?: '-' ?><?= empty($waRow['wa_phone_id']) ? ' <span class="tag tag-bad">API number not linked</span>' : '' ?></td></tr>
        <tr><td>Customer number</td><td><?= e($waRow['customer_phone']) ?></td></tr>
        <tr><td>Confirmation code</td><td><b><?= e($waRow['wa_code'] ?? '-') ?></b></td></tr>
        <tr><td>Status</td><td><b><?= e(wa_status_label($waRow['wa_status'])) ?></b><?php if ($waRow['wa_status'] === 'failed' && $waRow['wa_error']): ?><div class="mini"><?= e($waRow['wa_error']) ?></div><?php endif; ?></td></tr>
        <tr><td>Sent at</td><td><?= $waRow['wa_sent_at'] ? e(date('d M Y, h:i A', strtotime($waRow['wa_sent_at']))) : '-' ?></td></tr>
        <tr><td>Delivered at</td><td><?= $waRow['wa_delivered_at'] ? e(date('d M Y, h:i A', strtotime($waRow['wa_delivered_at']))) : '-' ?></td></tr>
        <tr><td>WhatsApp reference ID</td><td class="mini" style="word-break:break-all"><?= e($waRow['wa_msg_id'] ?? '-') ?></td></tr>
        <tr><td>Commission</td><td><b><?= e(wa_eligibility_text($waRow)) ?></b></td></tr>
      </tbody></table>
      <?php if (!in_array((string)$waRow['wa_status'], ['sent', 'delivered'], true)): ?>
      <form method="post" style="margin-top:12px">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="wa_resend">
        <button class="btn btn-ghost" type="submit">Send the confirmation again</button>
        <div class="hint">The status comes only from WhatsApp. It cannot be changed by hand.</div>
      </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($order['payment_mode'] === 'cod'): ?>
    <section class="card">
      <div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 style="margin:0">COD money</h2>
        <span class="tag <?= $codPending > 0 ? 'tag-warn' : 'tag-ok' ?>"><?= $codPending > 0 ? e(inr($codPending)) . ' pending' : 'Fully received' ?></span>
      </div>
      <?php if ($advance > 0): ?>
      <div class="kv" style="margin-bottom:12px">
        <div><div class="k">Order total</div><div class="v"><?= e(inr($order['total'])) ?></div></div>
        <div><div class="k">Advance paid</div><div class="v pos"><?= e(inr($advance)) ?></div></div>
        <div><div class="k">COD balance</div><div class="v"><?= e(inr((float)$order['total'] - $advance)) ?></div></div>
      </div>
      <?php endif; ?>
      <?php if ($codRows): ?>
      <div class="table-wrap"><table class="tight" style="min-width:380px"><thead><tr><th>Date</th><th>Account</th><th>How</th><th class="num">Amount</th></tr></thead><tbody>
        <?php foreach ($codRows as $c): ?>
          <tr><td><?= e(date('d M Y', strtotime($c['received_date']))) ?></td><td><?= e($c['account']) ?></td><td><?= $c['source'] === 'excel' ? 'Excel' : 'Manual' ?></td><td class="num"><?= e(inr($c['amount'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php else: ?><div class="empty">No COD money received yet.</div><?php endif; ?>

      <?php if ($isAdmin && $codPending > 0 && $order['status'] !== 'refunded'): ?>
      <form method="post" autocomplete="off" style="margin-top:16px">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
        <div class="twocol">
          <div><label for="cod_amount">Amount received (₹)</label><input id="cod_amount" name="amount" type="text" inputmode="decimal" value="<?= e((string)$codPending) ?>"></div>
          <div><label for="cod_acc">Into account</label><select id="cod_acc" name="account_id"><?= $accOpts ?></select></div>
          <div><label for="cod_date">Date</label><input id="cod_date" name="received_date" type="date" value="<?= e(biz_today()) ?>" <?= $dateInput ?>></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
          <button class="btn btn-accent" type="submit" name="action" value="cod">Save COD payment</button>
        </div>
        <div class="hint">You can save a part now and the rest later. To add many at once, use <a href="cod_upload.php">Upload from Excel</a>.</div>
      </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($refRows || ($isAdmin && $canRefund)): ?>
    <section class="card">
      <h2>Return, cancel and refund</h2>
      <?php if ($refRows): ?>
      <div class="table-wrap"><table class="tight" style="min-width:380px"><thead><tr><th>Date</th><th>From account</th><th>Note</th><th class="num">Refunded</th></tr></thead><tbody>
        <?php foreach ($refRows as $r): ?>
          <tr><td><?= e(date('d M Y', strtotime($r['refund_date']))) ?></td><td><?= e($r['account']) ?></td><td><?= e($r['note'] ?? '') ?></td><td class="num"><?= e(inr($r['amount'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
      <?php if ($isAdmin && $canRefund): ?>
      <form method="post" autocomplete="off" style="margin-top:16px">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="refund">
        <div class="twocol">
          <div><label for="rf_amount">Refund amount (₹)</label><input id="rf_amount" name="amount" type="text" inputmode="decimal" value="<?= e((string)round((float)$order['total'] - $refunded, 2)) ?>"></div>
          <div><label for="rf_acc">Pay from account</label><select id="rf_acc" name="account_id"><?= $accOpts ?></select></div>
          <div><label for="rf_parcel">Parcel received on</label><input id="rf_parcel" name="parcel_date" type="date" <?= $dateInput ?>></div>
          <div><label for="rf_date">Refund date</label><input id="rf_date" name="refund_date" type="date" value="<?= e(biz_today()) ?>" <?= $dateInput ?>></div>
        </div>
        <div class="field" style="margin-top:12px"><label for="rf_note">Note (optional)</label><input id="rf_note" name="note" type="text" placeholder="Reason, exchange, etc."></div>
        <button class="btn" type="submit" onclick="return confirm('Record this refund? Money goes out of the chosen account.');">Record refund</button>
        <div class="hint">A full refund marks the order as Refunded. A part refund keeps it open.</div>
      </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </div>

  <div class="narrow stack">
    <section class="card">
      <h2>Tracking and WhatsApp</h2>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="tracking">
        <?php if (orders_has_sr_id($pdo)): ?><div class="field"><label for="shiprocket_order_id">Shiprocket Order ID</label><input id="shiprocket_order_id" name="shiprocket_order_id" type="text" maxlength="60" value="<?= e($order['shiprocket_order_id'] ?? '') ?>" placeholder="Type it when Shiprocket gives it"></div><?php endif; ?>
        <div class="field"><label for="tracking_id">Tracking ID</label><input id="tracking_id" name="tracking_id" type="text" value="<?= e($order['tracking_id'] ?? '') ?>" placeholder="Add when dispatched"></div>
        <div class="field"><label for="tracking_link">Tracking link</label><input id="tracking_link" name="tracking_link" type="text" value="<?= e($order['tracking_link'] ?? '') ?>" placeholder="https://"></div>
        <button class="btn btn-ghost btn-block" type="submit">Save</button>
      </form>
      <div style="display:flex;flex-direction:column;gap:10px;margin-top:14px">
        <?php if ($hasTracking): ?>
          <a class="btn btn-accent linkbtn" style="justify-content:center" href="<?= e($trackingUrl) ?>" target="whatsapp_tab">Send tracking on WhatsApp Web</a>
        <?php endif; ?>
        <a class="btn btn-ghost linkbtn" style="justify-content:center" href="<?= e($confirmUrl) ?>" target="whatsapp_tab">Send order confirmation</a>
      </div>
    </section>

    <?php if ($isAdmin && in_array($order['status'], ['created', 'dispatched'], true)): ?>
    <section class="card">
      <h2>Delivery</h2>
      <form method="post" onsubmit="return confirm('Mark this order as delivered?');">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="delivered">
        <button class="btn btn-accent btn-block" type="submit">Mark as delivered</button>
        <div class="hint">After delivery you can record a return, cancel and refund if needed.</div>
      </form>
    </section>
    <?php elseif ($order['delivered_at']): ?>
    <section class="card"><h2>Delivery</h2><div class="hint" style="margin:0">Delivered on <?= e(date('d M Y, h:i A', strtotime($order['delivered_at']))) ?></div></section>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <section class="card">
      <h2>Edit or delete</h2>
        <form method="post" autocomplete="off" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="edit">
          <div class="field"><label for="e_name">Customer name</label><input id="e_name" name="customer_name" type="text" value="<?= e($order['customer_name']) ?>"></div>
          <div class="field"><label for="e_phone">Mobile</label><input id="e_phone" name="customer_phone" type="tel" value="<?= e($order['customer_phone']) ?>"></div>
          <div class="field"><label for="e_pin">PIN code</label><input id="e_pin" name="pincode" type="text" inputmode="numeric" value="<?= e($order['pincode']) ?>"></div>
          <div class="field"><label for="e_addr">Address</label><textarea id="e_addr" name="address" rows="3" style="width:100%;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:12px;font-family:inherit"><?= e($order['address']) ?></textarea></div>
          <div class="field"><label for="e_sales">Order placed by</label>
            <select id="e_sales" name="salesperson_id">
              <?php foreach ($teamRows as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === (int)$order['salesperson_id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
            </select></div>
          <?php if ($numberRows): ?>
          <div class="field"><label for="e_num">Order placed by number</label>
            <select id="e_num" name="number_id">
              <?php if ($order['number_id'] === null): ?><option value="">No number</option><?php endif; ?>
              <?php foreach ($numberRows as $n): ?><option value="<?= (int)$n['id'] ?>" <?= (int)$n['id'] === (int)($order['number_id'] ?? 0) ? 'selected' : '' ?>><?= e($n['label'] . ($n['phone'] ? ' - ' . $n['phone'] : '')) ?></option><?php endforeach; ?>
            </select></div>
          <?php endif; ?>
          <?php if (orders_has_ref($pdo)): ?>
          <div class="field"><label for="e_ref">Reference notes</label><textarea id="e_ref" name="ref_notes" rows="3" maxlength="2000" style="width:100%;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:12px;font-family:inherit"><?= e($order['ref_notes'] ?? '') ?></textarea></div>
          <div class="field"><label for="e_refimg"><?= !empty($order['ref_image']) ? 'Replace reference image' : 'Reference image' ?></label><input id="e_refimg" name="ref_image" type="file" accept="image/*"></div>
          <?php endif; ?>
          <button class="btn btn-ghost btn-block" type="submit">Save changes</button>
        </form>
        <form method="post" class="danger" style="margin-top:14px" onsubmit="return confirm('Delete this whole order for good? All its money entries, COD receipts, refunds and the screenshot are removed too. This cannot be undone.');">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="delete">
          <button class="btn btn-danger btn-block" type="submit">Delete order</button>
          <div class="hint">Only the Manager can edit or delete an order, at any time. To change products or prices, delete and create the order again.</div>
        </form>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>