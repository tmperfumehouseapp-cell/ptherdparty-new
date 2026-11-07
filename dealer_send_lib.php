<?php
// "Send to dealer": one square photo per dealer with the order's products, ready to send on WhatsApp.

function parties_have_group_link(PDO $pdo): bool
{
    static $has = null;
    if ($has === null) {
        $has = (bool)$pdo->query("SHOW COLUMNS FROM parties LIKE 'group_link'")->fetch();
    }
    return $has;
}

/** HTML for the "Send to dealer" card (manager only). Empty string when there is nothing to send. */
function dealer_send_block(PDO $pdo, array $order): string
{
    $gl = parties_have_group_link($pdo) ? 'pa.group_link' : 'NULL';
    $st = $pdo->prepare(
        "SELECT oi.product_id, oi.qty, oi.sell_price, oi.discount_share, oi.party_id, p.name,
                pa.name AS party_name, pa.phone AS party_phone, $gl AS group_link
         FROM order_items oi
         JOIN products p ON p.id = oi.product_id
         LEFT JOIN parties pa ON pa.id = oi.party_id
         WHERE oi.order_id = ? ORDER BY oi.id"
    );
    $st->execute([(int)$order['id']]);
    $rows = $st->fetchAll();
    if (!$rows) {
        return '';
    }

    $dealers = [];
    $noDealer = [];
    foreach ($rows as $r) {
        if ($r['party_id'] === null) {
            $noDealer[] = $r['name'];
            continue;
        }
        $k = (int)$r['party_id'];
        if (!isset($dealers[$k])) {
            $ph = preg_replace('/\D+/', '', (string)$r['party_phone']);
            $dealers[$k] = [
                'id' => $k, 'name' => (string)$r['party_name'],
                'phone' => $ph !== '' ? '91' . substr($ph, -10) : '',
                'group' => (string)($r['group_link'] ?? ''),
                'items' => [], 'portion' => 0.0,
            ];
        }
        $dealers[$k]['items'][] = ['pid' => (int)$r['product_id'], 'n' => (string)$r['name'], 'q' => (int)$r['qty']];
        $dealers[$k]['portion'] += (float)$r['sell_price'] * (int)$r['qty'] - (float)$r['discount_share'];
    }
    if (!$dealers) {
        return '<section class="card"><h2>Send to dealer</h2><p class="hint" style="margin:0">None of the products in this order has a dealer yet, so there is nothing to send.</p></section>';
    }

    $isCod = $order['payment_mode'] === 'cod';
    $orderCollect = $isCod ? max(0.0, (float)$order['total'] - (float)$order['advance_amount']) : 0.0;
    foreach ($dealers as &$d) {
        $d['collect'] = !$isCod ? 0.0 : (count($dealers) === 1 ? $orderCollect : round($d['portion'], 2));
        unset($d['portion']);
    }
    unset($d);

    try {
        $sq = $pdo->prepare("SELECT party_id, awb, courier, label_url FROM shipments WHERE order_id = ? AND status = 'assigned' AND awb IS NOT NULL");
        $sq->execute([(int)$order['id']]);
        foreach ($sq->fetchAll() as $sr) {
            if (isset($dealers[(int)$sr['party_id']])) {
                $dealers[(int)$sr['party_id']]['ship'] = ['awb' => (string)$sr['awb'], 'courier' => (string)$sr['courier'], 'label' => (string)$sr['label_url']];
            }
        }
    } catch (Throwable $e) {
        // shipments table not created yet
    }

    $data = [
        'order' => [
            'no' => order_no($order['id']), 'name' => (string)$order['customer_name'], 'phone' => (string)$order['customer_phone'],
            'address' => (string)$order['address'], 'pin' => (string)$order['pincode'], 'cod' => $isCod,
        ],
        'dealers' => array_values($dealers),
    ];

    ob_start();
    ?>
<section class="card" id="sd-card">
  <h2>Send to dealer</h2>
  <p class="hint" style="margin:0 0 12px">One photo per dealer with the products, order number and shipping details. Press Send on WhatsApp, then choose the dealer's chat or group.</p>
  <?php if ($noDealer): ?><p class="hint" style="margin:0 0 12px;color:var(--warn,#b45309)">No dealer set for: <?= e(implode(', ', $noDealer)) ?>. These are not in any photo.</p><?php endif; ?>
  <div id="sd-list" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px"></div>
  <div id="sd-toast" class="hint" style="margin:12px 0 0;font-weight:700" aria-live="polite"></div>
</section>
<script type="application/json" id="sd-data"><?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="assets/send_dealer.js?v=1"></script>
    <?php
    return (string)ob_get_clean();
}
