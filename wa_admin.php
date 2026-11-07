<?php
// WhatsApp confirmation check (manager only): setup, links between your numbers and the WhatsApp API, every order's verified status, and the log.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/orders_lib.php';
require_once __DIR__ . '/includes/wa_lib.php';
$user = require_admin();
$pdo = db();

$cfg = wa_cfg();
$enabled = wa_enabled();
$ready = $enabled ? wa_migrate($pdo) : orders_has_wa($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'link' && $ready) {
        $nid = (int)($_POST['number_id'] ?? 0);
        $pid = preg_replace('/\D+/', '', (string)($_POST['wa_phone_id'] ?? ''));
        $pdo->prepare('UPDATE order_numbers SET wa_phone_id = ? WHERE id = ?')->execute([$pid === '' ? null : mb_substr($pid, 0, 40), $nid]);
        wa_log($pdo, null, 'setup', 'Number #' . $nid . ' ' . ($pid === '' ? 'unlinked from' : 'linked to') . ' WhatsApp phone number ID ' . $pid, (int)$user['id']);
        flash('ok', $pid === '' ? 'Number unlinked.' : 'Number linked to WhatsApp.');
    } elseif ($action === 'resend' && $enabled) {
        $r = wa_send_confirmation($pdo, (int)($_POST['order_id'] ?? 0), (int)$user['id']);
        flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Confirmation sent to WhatsApp. The status updates when WhatsApp reports back.' : (string)($r['error'] ?? 'Could not send.'));
    }
    redirect('wa_admin.php');
}

$numbers = [];
$orders = [];
$logs = [];
if ($ready) {
    $numbers = $pdo->query('SELECT n.id, n.label, n.phone, n.is_active, n.wa_phone_id, (SELECT COUNT(*) FROM orders o WHERE o.number_id = n.id) AS orders_count FROM order_numbers n ORDER BY n.is_active DESC, n.label')->fetchAll();
    $orders = $pdo->query(
        "SELECT o.*, u.name AS sales_name, n.label AS num_label, n.phone AS num_phone, n.wa_phone_id
         FROM orders o JOIN users u ON u.id = o.salesperson_id LEFT JOIN order_numbers n ON n.id = o.number_id
         WHERE o.wa_enforced = 1 ORDER BY o.id DESC LIMIT 100"
    )->fetchAll();
    try {
        $logs = $pdo->query('SELECT l.*, u.name AS who FROM wa_log l LEFT JOIN users u ON u.id = l.actor ORDER BY l.id DESC LIMIT 80')->fetchAll();
    } catch (Throwable $e) {
        $logs = [];
    }
}
$host = $_SERVER['HTTP_HOST'] ?? 'pthirdparty.com';
$dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$hookUrl = 'https://' . $host . $dir . '/wa_webhook.php';

$pageTitle = 'WhatsApp confirmation';
$active = 'wa_check';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<section class="card">
  <h2>Status of the system</h2>
  <?php if (!$enabled): ?>
    <p style="margin:0 0 8px"><span class="tag tag-warn">Off</span> The WhatsApp check is not switched on. Orders and commission work exactly as before.</p>
    <p class="hint" style="margin:0">To switch it on, create the file <b>wa_config.php</b> next to orders.php on the server (see the setup note), then open this page again.</p>
  <?php else: ?>
    <p style="margin:0 0 8px"><span class="tag tag-ok">On</span> New orders are confirmed through the WhatsApp API and their commission waits for the verified status.</p>
    <div class="mini">Webhook address to give WhatsApp: <b style="word-break:break-all"><?= e($hookUrl) ?></b></div>
    <div class="mini">Verify token: the value of <b>verify_token</b> in wa_config.php. Subscribe to the <b>messages</b> field.</div>
    <div class="mini"><?= !empty($cfg['app_secret']) ? 'App secret is set (callbacks are checked).' : '<b style="color:#B91C1C">App secret is missing: callbacks will be refused.</b>' ?>
      · Message type: <?= !empty($cfg['template']['name']) ? 'template ' . e($cfg['template']['name']) : 'plain text (customer must have messaged this number in the last 24 hours)' ?></div>
  <?php endif; ?>
</section>

<?php if ($ready): ?>
<section class="card">
  <h2>Your numbers and the WhatsApp API</h2>
  <p class="hint" style="margin:0 0 10px">The confirmation is sent from the number the customer ordered on. Link each number to its <b>Phone number ID</b> (Meta WhatsApp Manager). A number with no link cannot send, and its orders stay without commission.</p>
  <?php foreach ($numbers as $n): ?>
    <form method="post" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:8px 0;border-top:1px solid var(--line)">
      <?= csrf_field() ?><input type="hidden" name="action" value="link"><input type="hidden" name="number_id" value="<?= (int)$n['id'] ?>">
      <div style="flex:1 1 200px"><b><?= e($n['label']) ?></b> <span class="mini"><?= e($n['phone'] ?? '') ?> · <?= (int)$n['orders_count'] ?> orders<?= $n['is_active'] ? '' : ' · inactive' ?></span></div>
      <input name="wa_phone_id" type="text" inputmode="numeric" placeholder="Phone number ID" value="<?= e($n['wa_phone_id'] ?? '') ?>" style="flex:1 1 180px;height:44px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px">
      <button class="btn btn-ghost btn-sm" type="submit">Save</button>
      <?= empty($n['wa_phone_id']) ? '<span class="tag tag-bad">Not linked</span>' : '<span class="tag tag-ok">Linked</span>' ?>
    </form>
  <?php endforeach; ?>
</section>

<section class="card">
  <h2>Orders under the WhatsApp rule</h2>
  <?php if (!$orders): ?>
    <div class="empty">No order has been confirmed through WhatsApp yet.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="tight" style="min-width:980px">
    <thead><tr><th>Order</th><th>Salesperson</th><th>Sent from</th><th>Customer</th><th>Code</th><th>Status</th><th>Sent</th><th>Delivered</th><th>Reference ID</th><th>Commission</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
      <tr>
        <td><a class="rowlink" href="order_view.php?id=<?= (int)$o['id'] ?>#wacheck"><?= e(order_no($o['id'])) ?></a> <?= wa_badge($o) ?></td>
        <td><?= e($o['sales_name']) ?></td>
        <td><?= e(trim(($o['num_label'] ?? '') . ' ' . ($o['num_phone'] ?? ''))) ?: '-' ?></td>
        <td><?= e($o['customer_phone']) ?></td>
        <td><?= e($o['wa_code'] ?? '-') ?></td>
        <td><?= e(wa_status_label($o['wa_status'])) ?><?php if ($o['wa_status'] === 'failed' && $o['wa_error']): ?><div class="mini"><?= e($o['wa_error']) ?></div><?php endif; ?></td>
        <td><?= $o['wa_sent_at'] ? e(date('d M h:i A', strtotime($o['wa_sent_at']))) : '-' ?></td>
        <td><?= $o['wa_delivered_at'] ? e(date('d M h:i A', strtotime($o['wa_delivered_at']))) : '-' ?></td>
        <td class="mini" style="word-break:break-all;max-width:180px"><?= e($o['wa_msg_id'] ?? '-') ?></td>
        <td><?= e(wa_eligibility_text($o)) ?></td>
        <td><?php if (!in_array((string)$o['wa_status'], ['sent', 'delivered'], true) && $enabled): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Send again</button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Log</h2>
  <?php if (!$logs): ?>
    <div class="empty">Nothing logged yet.</div>
  <?php else: foreach ($logs as $l): ?>
    <div class="mini" style="padding:4px 0;border-top:1px solid var(--line)">
      <?= e(date('d M h:i:s A', strtotime($l['created_at']))) ?> · <b><?= e($l['kind']) ?></b><?= $l['order_id'] ? ' · ' . e(order_no($l['order_id'])) : '' ?> · <?= e($l['msg']) ?><?= $l['who'] ? ' (' . e($l['who']) . ')' : '' ?>
    </div>
  <?php endforeach; endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
