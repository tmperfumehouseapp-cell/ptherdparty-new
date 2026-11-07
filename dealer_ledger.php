<?php
// Dealer ledger (manager only). One page, four tabs:
//   Today          daily numbers: orders, sales, COD, buying, profit, paid, owed, dispatched / pending
//   Dealer ledger  one dealer day by day: opening, buying, prepaid on their QR, payments, closing
//   COD & profit   COD orders: value, collected, buying cost, profit, what you owe the dealer for them
//   Dispatch       which ordered units the dealer has sent and which are still pending (partial dispatch included)
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/dealer_lib.php';
$user = require_admin();
$pdo = db();

$tabs = ['today' => 'Today', 'ledger' => 'By dealer', 'cod' => 'COD & profit', 'dispatch' => 'Dispatch'];
$tab = array_key_exists((string)($_GET['tab'] ?? ''), $tabs) ? (string)$_GET['tab'] : 'today';

$today = biz_today();
$dealers = dealer_list($pdo);
$dealer = (int)($_GET['dealer'] ?? 0);
$dealerName = '';
foreach ($dealers as $d) {
    if ((int)$d['id'] === $dealer) {
        $dealerName = $d['name'];
    }
}
if ($dealerName === '') {
    $dealer = 0;
}

// ----- date range (defaults differ per tab) -----
$from = valid_date((string)($_GET['from'] ?? '')) ?? '';
$to = valid_date((string)($_GET['to'] ?? '')) ?? '';
if ($from === '' && $to === '') {
    if ($tab === 'today') {
        $from = $to = $today;
    } elseif ($tab === 'ledger' || $tab === 'cod') {
        $from = date('Y-m-01', strtotime($today));
        $to = $today;
    }
} else {
    if ($to === '' && $tab !== 'dispatch') {
        $to = $today;
    }
    if ($from === '' && $tab !== 'dispatch') {
        $from = date('Y-m-d', strtotime($to . ' -29 days'));
    }
}
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}
$type = in_array(($_GET['type'] ?? ''), ['prepaid', 'cod'], true) ? (string)$_GET['type'] : '';
$pay = in_array(($_GET['pay'] ?? ''), ['paid', 'due'], true) ? (string)$_GET['pay'] : '';
$status = in_array(($_GET['status'] ?? ''), ['pending', 'partial', 'done', 'all'], true) ? (string)$_GET['status'] : 'open';
$view = (($_GET['view'] ?? '') === 'products') ? 'products' : 'orders';

$firstDay = $pdo->query("SELECT MIN(d) FROM (SELECT MIN(order_date) AS d FROM orders UNION ALL SELECT MIN(entry_date) FROM party_ledger) x")->fetchColumn() ?: $today;

/** Link to this page with some parameters changed. */
$url = static function (array $over) use ($tab, $dealer, $from, $to, $type, $pay, $status, $view): string {
    $q = array_merge(['tab' => $tab, 'dealer' => $dealer ?: '', 'from' => $from, 'to' => $to, 'type' => $type, 'pay' => $pay,
                      'status' => $status === 'open' ? '' : $status, 'view' => $view === 'orders' ? '' : $view], $over);
    $q = array_filter($q, static fn($v) => $v !== '' && $v !== null);
    return 'dealer_ledger.php?' . http_build_query($q);
};

$dateInput = 'style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"';
$hasKind = dealer_has_paykind($pdo);

/** "₹1,200 owed" / "₹300 advance" / "Settled" */
$bal = static function (float $b): string {
    if ($b > 0.004) {
        return inr($b) . ' owed';
    }
    return $b < -0.004 ? inr(-$b) . ' advance' : 'Settled';
};
$tile = static function (string $k, string $v, string $sub = '', string $style = ''): string {
    return '<div class="stat"><div class="k">' . e($k) . '</div><div class="v"' . ($style ? ' style="' . e($style) . '"' : '') . '>' . $v . '</div>'
        . ($sub !== '' ? '<div class="mini">' . $sub . '</div>' : '') . '</div>';
};
$chips = static function (bool $openAll = false) use ($url, $today, $firstDay, $from, $to): string {
    $yest = date('Y-m-d', strtotime($today . ' -1 day'));
    $list = [
        'Today' => [$today, $today],
        'Yesterday' => [$yest, $yest],
        'Last 7 days' => [date('Y-m-d', strtotime($today . ' -6 days')), $today],
        'This month' => [date('Y-m-01', strtotime($today)), $today],
        'All time' => $openAll ? ['', ''] : [$firstDay, $today],
    ];
    $h = '<div class="dd-chips">';
    foreach ($list as $label => [$f, $t]) {
        $on = ($f === $from && $t === $to);
        $h .= '<a class="btn ' . ($on ? 'btn-accent' : 'btn-ghost') . ' btn-sm linkbtn" href="' . e($url(['from' => $f, 'to' => $t])) . '">' . e($label) . '</a>';
    }
    return $h . '</div>';
};
$dealerSelect = static function () use ($dealers, $dealer): string {
    $h = '<div><label for="dealer">Dealer</label><select id="dealer" name="dealer"><option value="0">All dealers</option>';
    foreach ($dealers as $d) {
        $h .= '<option value="' . (int)$d['id'] . '"' . ($dealer === (int)$d['id'] ? ' selected' : '') . '>' . e($d['name'] . ($d['is_active'] ? '' : ' (off)')) . '</option>';
    }
    return $h . '</select></div>';
};

$accounts = $pdo->query('SELECT id, name FROM accounts WHERE is_active = 1 ORDER BY name')->fetchAll();
$balances = dealer_balances($pdo);

/** The "Pay a dealer" box. It posts to party_ledger.php, which saves the payment and returns here. */
$payForm = static function () use ($accounts, $dealers, $dealer, $url, $dateInput, $hasKind, $balances): string {
    ob_start(); ?>
<details class="card dd-pay" id="pay" style="margin-top:16px">
  <summary>Pay a dealer</summary>
  <form method="post" action="party_ledger.php" enctype="multipart/form-data" autocomplete="off" style="margin-top:14px" id="pf_form" data-owed="<?= e(json_encode($balances)) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="payment"><input type="hidden" name="back" value="<?= e($url(['dealer' => $dealer ?: ''])) ?>">
    <div class="field"><label for="pf_dealer">Dealer</label>
      <select id="pf_dealer" name="id" required>
        <?php if (!$dealer): ?><option value="">Choose dealer</option><?php endif; ?>
        <?php foreach ($dealers as $d): ?><?php if ($dealer && (int)$d['id'] !== $dealer) { continue; } ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
      </select></div>
    <?php if ($hasKind): ?>
    <div class="field"><label for="pf_kind">This payment is for</label>
      <select id="pf_kind" name="pay_kind"><option value="cod">COD buying cost</option><option value="extra">Extra / old balance</option></select></div>
    <?php endif; ?>
    <div class="field"><label for="pf_acc">Paid from account</label>
      <select id="pf_acc" name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="pf_amount">Amount (₹)</label><input id="pf_amount" name="amount" type="text" inputmode="decimal" required></div>
    <div class="field"><label for="pf_method">How paid</label>
      <select id="pf_method" name="pay_method"><option value="online">Online</option><option value="cash">Cash</option></select></div>
    <div class="field"><label for="pf_date">Date</label><input id="pf_date" name="entry_date" type="date" value="<?= e(biz_today()) ?>" <?= $dateInput ?>></div>
    <div class="field"><label for="pf_note">Note</label><input id="pf_note" name="note" type="text" placeholder="UTR / reference"></div>
    <div class="field" id="pf_proof_box"><label for="pf_proof">Payment screenshot (optional)</label><input id="pf_proof" name="pay_proof" type="file" accept="image/*,application/pdf"></div>
    <button class="btn btn-accent btn-block" type="submit">Save payment</button>
    <div class="hint">The money goes out of the account and is taken off what you owe the dealer. Saved once, in the dealer's ledger.</div>
  </form>
  <script>
  (function () {
    var s = document.getElementById('pf_method'), b = document.getElementById('pf_proof_box');
    function sync() { b.style.display = s.value === 'cash' ? 'none' : ''; }
    s.addEventListener('change', sync); sync();
    // one click saves once, and a payment bigger than what you owe asks first (catches an extra zero)
    var f = document.getElementById('pf_form'), owedMap = {};
    try { owedMap = JSON.parse(f.getAttribute('data-owed')) || {}; } catch (x) {}
    f.addEventListener('submit', function (e) {
      var amt = parseFloat(String(document.getElementById('pf_amount').value).replace(/[^0-9.]/g, '')) || 0;
      var owed = Math.max(0, parseFloat(owedMap[document.getElementById('pf_dealer').value]) || 0);
      if (amt > owed + 0.5 && !confirm('You owe ' + owed.toLocaleString('en-IN') + ' but are paying ' + amt.toLocaleString('en-IN') + '. The dealer will then owe you the difference. Save this payment?')) {
        e.preventDefault();
        return;
      }
      var b = f.querySelector('button[type=submit]');
      if (b) { b.disabled = true; }
    });
  })();
  </script>
</details>
    <?php
    return (string)ob_get_clean();
};

// ----- data for the chosen tab -----
$allItems = dealer_items($pdo);

$pageTitle = 'Dealer ledger';
$active = 'dealer_ledger';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
?>
<style>
.dd-tabs{display:flex;gap:8px;overflow-x:auto;margin:0 0 14px;padding-bottom:2px;-webkit-overflow-scrolling:touch}
.dd-tabs a{flex:0 0 auto;display:inline-flex;align-items:center;text-decoration:none;height:40px;padding:0 16px;border-radius:12px;font-weight:800;font-size:14px;white-space:nowrap}
.dd-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
.dd-filter summary{cursor:pointer;font-weight:800;font-size:14px}
.dd-tabs{gap:6px}
.dd-tabs a{flex:1 1 auto;justify-content:center;padding:0 8px;font-size:13px}
.dd-tiles{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(148px,1fr));margin:16px 0}
.dd-tiles .stat{min-width:0;padding:16px 18px;border-radius:18px}
.dd-tiles .stat .v{font-size:22px;word-break:break-word}
.dd-days{display:flex;flex-direction:column;gap:12px}
.dd-day{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:14px 16px}
.dd-dayhead{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;font-weight:800}
.dd-line{display:flex;justify-content:space-between;gap:10px;padding:5px 0;font-weight:600;font-size:14px}
.dd-line span{color:var(--muted)}
.dd-line b{font-variant-numeric:tabular-nums;text-align:right}
.dd-close{border-top:1px solid var(--line);margin-top:4px;padding-top:9px}
.dd-close span{color:var(--ink);font-weight:800}
.dd-day details summary,.dd-pay summary{cursor:pointer;font-size:12px;color:var(--muted);font-weight:700;margin-top:6px}
.dd-pay summary{font-size:16px;font-weight:800;color:var(--ink);margin:0}
.dd-ent{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-top:1px dashed var(--line);font-size:13px;font-weight:600}
.dd-ent .mini{display:block}
.dd-order{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:14px 16px}
.dd-it{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-top:1px solid var(--line);font-size:14px;font-weight:600}
.dd-it .r{text-align:right;flex:0 0 auto}
.dd-sec{margin:22px 0 10px;font-size:17px;font-weight:800}
.dd-neg{color:#B45309}
@media (max-width:520px){.dd-tiles{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.dd-tiles .stat .v{font-size:19px}.card{padding:18px 16px}}
</style>

<div class="dd-tabs">
  <?php foreach ($tabs as $k => $label): ?>
    <a class="btn <?= $tab === $k ? 'btn-accent' : 'btn-ghost' ?>" href="<?= e('dealer_ledger.php?tab=' . $k . ($dealer ? '&dealer=' . $dealer : '')) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$hasKind): ?>
<div class="flash flash-error">One small step is missing: run <b>migrate_dealer_ledger.sql</b> in phpMyAdmin. Until then payments cannot be tagged as COD or Extra.</div>
<?php endif; ?>

<?php /* ============================== TODAY ============================== */ ?>
<?php if ($tab === 'today'): ?>
  <?php
    $items = dealer_filter($allItems, $dealer, $from, $to, $type, $pay);
    $t = dealer_totals($items);
    $money = dealer_money($pdo, $from, $to, $dealer);
    $owedNow = $dealer ? ($balances[$dealer] ?? 0.0) : array_sum($balances);
    $perDealer = [];
    foreach ($dealers as $d) {
        $sub = dealer_totals(dealer_filter($items, (int)$d['id'], '', ''));
        if ($sub['units'] > 0 || abs($balances[(int)$d['id']] ?? 0) > 0.004) {
            $perDealer[] = ['d' => $d, 't' => $sub, 'bal' => $balances[(int)$d['id']] ?? 0.0];
        }
    }
    $rangeText = $from === $to ? dealer_dlabel($from) : dealer_dlabel($from) . ' to ' . dealer_dlabel($to);
  ?>
<section class="card dd-filter">
  <?= $chips() ?>
  <details open><summary>Filters<?= $dealerName !== '' ? ' · ' . e($dealerName) : '' ?></summary>
  <form method="get" class="filters" style="margin:12px 0 0">
    <input type="hidden" name="tab" value="today">
    <?= $dealerSelect() ?>
    <div><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($from) ?>" <?= $dateInput ?>></div>
    <div><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($to) ?>" <?= $dateInput ?>></div>
    <div><label for="type">Order type</label><select id="type" name="type"><option value="">All</option><option value="prepaid"<?= $type === 'prepaid' ? ' selected' : '' ?>>Prepaid</option><option value="cod"<?= $type === 'cod' ? ' selected' : '' ?>>COD</option></select></div>
    <div><label for="pay">Payment</label><select id="pay" name="pay"><option value="">All</option><option value="paid"<?= $pay === 'paid' ? ' selected' : '' ?>>Fully paid</option><option value="due"<?= $pay === 'due' ? ' selected' : '' ?>>COD money pending</option></select></div>
    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-ghost linkbtn" href="dealer_ledger.php?tab=today">Clear</a>
  </form>
  </details>
</section>

<div class="dd-sec"><?= e($rangeText) ?><?= $dealerName !== '' ? ' · ' . e($dealerName) : '' ?></div>
<div class="dd-tiles" style="margin-top:0">
  <?= $tile('Orders', (string)$t['orders']) ?>
  <?= $tile('Products ordered', (string)$t['units'], 'units') ?>
  <?= $tile('Prepaid sales', e(inr($t['prepaid']))) ?>
  <?= $tile('COD sales', e(inr($t['cod'])), $t['adv'] > 0.004 ? 'advance ' . e(inr($t['adv'])) . ' taken' : '') ?>
  <?= $tile('COD received', e(inr($t['recv'])), 'still to come ' . e(inr($t['due']))) ?>
  <?= $tile('Buying cost of these orders', e(inr($t['cost'])), 'at your buying prices') ?>
  <?= $tile('Profit', e(inr($t['profit'])), '', 'color:var(--good)') ?>
  <?= $tile('Paid to dealers', e(inr($money['paid'])), $money['prepaid_dealer'] > 0.004 ? '+ ' . e(inr($money['prepaid_dealer'])) . ' prepaid straight to dealer QR' : '') ?>
  <?= $tile('Total outstanding', e($bal($owedNow)), 'right now, all time', $owedNow > 0.004 ? 'color:#B45309' : '') ?>
  <?= $tile('Dispatched', (string)$t['disp'], 'units sent by dealers') ?>
  <?= $tile('Pending dispatch', (string)$t['pend'], $t['pend'] > 0 ? 'buying value ' . e(inr($t['pend_value'])) : 'nothing pending', $t['pend'] > 0 ? 'color:#B45309' : '') ?>
</div>
<p class="hint">Sales, buying cost and profit are for orders placed in these dates (refunded orders left out). "Paid to dealers" is by payment date. Outstanding is the live ledger balance.</p>

<section class="card" style="margin-top:16px">
  <h2>By dealer</h2>
  <?php if (!$perDealer): ?>
    <div class="empty">No orders for this selection.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="tight" style="min-width:560px">
    <thead><tr><th>Dealer</th><th class="num">Units</th><th class="num">Buying cost</th><th class="num">Dispatched</th><th class="num">Pending</th><th class="num">You owe now</th></tr></thead>
    <tbody>
    <?php foreach ($perDealer as $r): ?>
      <tr>
        <td><a class="rowlink" href="<?= e($url(['tab' => 'ledger', 'dealer' => (int)$r['d']['id'], 'from' => date('Y-m-01', strtotime($today)), 'to' => $today])) ?>"><?= e($r['d']['name']) ?></a></td>
        <td class="num"><?= (int)$r['t']['units'] ?></td>
        <td class="num"><?= e(inr($r['t']['cost'])) ?></td>
        <td class="num"><?= (int)$r['t']['disp'] ?></td>
        <td class="num"><?= $r['t']['pend'] > 0 ? '<span class="tag tag-warn">' . (int)$r['t']['pend'] . '</span>' : '-' ?></td>
        <td class="num"><?= $r['bal'] > 0.004 ? '<b>' . e(inr($r['bal'])) . '</b>' : ($r['bal'] < -0.004 ? '<span class="tag tag-ok">Advance ' . e(inr(-$r['bal'])) . '</span>' : '-') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<?php /* ============================== LEDGER ============================== */ ?>
<?php elseif ($tab === 'ledger'): ?>
<section class="card dd-filter">
  <?= $chips() ?>
  <details open><summary>Filters<?= $dealerName !== '' ? ' · ' . e($dealerName) : '' ?></summary>
  <form method="get" class="filters" style="margin:12px 0 0">
    <input type="hidden" name="tab" value="ledger">
    <?= $dealerSelect() ?>
    <div><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($from) ?>" <?= $dateInput ?>></div>
    <div><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($to) ?>" <?= $dateInput ?>></div>
    <button class="btn" type="submit">Show</button>
    <a class="btn btn-ghost linkbtn" href="dealer_ledger.php?tab=ledger">Clear</a>
  </form>
  </details>
</section>

  <?php if (!$dealer): ?>
    <?php
      $cards = [];
      foreach ($dealers as $d) {
          $L = dealer_ledger($pdo, (int)$d['id'], $from, $to);
          if (!$d['is_active'] && !$L['days'] && abs($L['closing']) < 0.005) {
              continue;
          }
          $cards[] = [$d, $L, dealer_totals(dealer_filter($allItems, (int)$d['id'], '', ''))];
      }
    ?>
<div class="dd-tiles">
  <?= $tile('Total outstanding', e($bal(array_sum($balances))), 'all dealers, right now', array_sum($balances) > 0.004 ? 'color:#B45309' : '') ?>
</div>
<div class="dd-days">
  <?php foreach ($cards as [$d, $L, $pt]): ?>
  <div class="dd-day">
    <div class="dd-dayhead"><span><?= e($d['name']) ?></span><span class="tag <?= $L['closing'] > 0.004 ? 'tag-warn' : 'tag-ok' ?>"><?= e($bal($L['closing'])) ?></span></div>
    <div class="dd-line"><span>Opening</span><b><?= e($bal($L['opening'])) ?></b></div>
    <div class="dd-line"><span>+ Buying (bills)</span><b><?= e(inr($L['tot']['buy'])) ?></b></div>
    <div class="dd-line"><span>− Prepaid on dealer QR</span><b><?= e(inr($L['tot']['prepaid'])) ?></b></div>
    <div class="dd-line"><span>− Paid for COD</span><b><?= e(inr($L['tot']['cod'])) ?></b></div>
    <div class="dd-line"><span>− Extra / old balance paid</span><b><?= e(inr($L['tot']['extra'])) ?></b></div>
    <div class="dd-line dd-close"><span>Closing</span><b><?= e($bal($L['closing'])) ?></b></div>
    <?php if ($pt['pend'] > 0): ?><div class="mini" style="margin-top:6px"><?= (int)$pt['pend'] ?> unit<?= $pt['pend'] > 1 ? 's' : '' ?> not billed yet · buying value <?= e(inr($pt['pend_value'])) ?></div><?php endif; ?>
    <p style="margin:10px 0 0"><a class="btn btn-ghost btn-sm linkbtn" href="<?= e($url(['dealer' => (int)$d['id']])) ?>">Open day-by-day ledger</a></p>
  </div>
  <?php endforeach; ?>
</div>
  <?php else: ?>
    <?php
      $L = dealer_ledger($pdo, $dealer, $from, $to);
      $pt = dealer_totals(dealer_filter($allItems, $dealer, '', ''));
      $nowBal = $balances[$dealer] ?? 0.0;
      $dayList = array_reverse($L['days'], true);
    ?>
<div class="dd-tiles">
  <?= $tile('Opening balance', e($bal($L['opening'])), 'on ' . e(dealer_dlabel($from))) ?>
  <?= $tile('Total debit', e(inr($L['debit'])), 'buying' . ($L['tot']['other'] > 0.004 ? ' + other ' . e(inr($L['tot']['other'])) : '')) ?>
  <?= $tile('Total credit', e(inr($L['credit'])), 'prepaid + payments') ?>
  <?= $tile('Closing balance', e($bal($L['closing'])), 'on ' . e(dealer_dlabel($to)), $L['closing'] > 0.004 ? 'color:#B45309' : '') ?>
</div>
<section class="card">
  <h2><?= e($dealerName) ?> · how the balance moved</h2>
  <div class="dd-line"><span>+ Buying (approved bills)</span><b><?= e(inr($L['tot']['buy'])) ?></b></div>
  <?php if ($L['tot']['other'] > 0.004): ?><div class="dd-line"><span>+ Opening / refund entries</span><b><?= e(inr($L['tot']['other'])) ?></b></div><?php endif; ?>
  <div class="dd-line"><span>− Prepaid orders paid straight to dealer QR</span><b><?= e(inr($L['tot']['prepaid'])) ?></b></div>
  <div class="dd-line"><span>− Paid for COD buying cost</span><b><?= e(inr($L['tot']['cod'])) ?></b></div>
  <div class="dd-line"><span>− Extra / old balance paid</span><b><?= e(inr($L['tot']['extra'])) ?></b></div>
  <div class="dd-line dd-close"><span>Balance change</span><b><?= e(inr($L['debit'] - $L['credit'])) ?></b></div>
  <?php if ($pt['pend'] > 0): ?>
  <div class="sheet-note" style="margin-top:12px">
    <b><?= (int)$pt['pend'] ?> unit<?= $pt['pend'] > 1 ? 's' : '' ?> ordered but not billed yet</b>, buying value <?= e(inr($pt['pend_value'])) ?>.
    Balance today is <?= e($bal($nowBal)) ?>; once those are billed it becomes about <?= e($bal($nowBal + $pt['pend_value'])) ?>.
    <a class="rowlink" href="<?= e($url(['tab' => 'dispatch', 'from' => '', 'to' => ''])) ?>">See pending products</a>
  </div>
  <?php endif; ?>
</section>

<div class="dd-sec">Day by day</div>
      <?php if (!$dayList): ?>
<section class="card"><div class="empty">No entries for <?= e($dealerName) ?> in these dates.</div></section>
      <?php else: ?>
<div class="dd-days">
        <?php foreach ($dayList as $date => $D): ?>
  <div class="dd-day">
    <div class="dd-dayhead"><span><?= e(dealer_dlabel($date)) ?></span><span class="tag <?= $D['close'] > 0.004 ? 'tag-warn' : 'tag-ok' ?>">Closing <?= e($bal($D['close'])) ?></span></div>
    <div class="dd-line"><span>Opening</span><b><?= e($bal($D['open'])) ?></b></div>
          <?php if ($D['buy'] > 0): ?><div class="dd-line"><span>+ Buying</span><b><?= e(inr($D['buy'])) ?></b></div><?php endif; ?>
          <?php if ($D['other'] > 0): ?><div class="dd-line"><span>+ Opening / refund entry</span><b><?= e(inr($D['other'])) ?></b></div><?php endif; ?>
          <?php if ($D['prepaid'] > 0): ?><div class="dd-line"><span>− Prepaid on dealer QR</span><b><?= e(inr($D['prepaid'])) ?></b></div><?php endif; ?>
          <?php if ($D['cod'] > 0): ?><div class="dd-line"><span>− Paid for COD</span><b><?= e(inr($D['cod'])) ?></b></div><?php endif; ?>
          <?php if ($D['extra'] > 0): ?><div class="dd-line"><span>− Extra / old balance paid</span><b><?= e(inr($D['extra'])) ?></b></div><?php endif; ?>
    <div class="dd-line dd-close"><span>Closing</span><b><?= e($bal($D['close'])) ?></b></div>
    <details><summary>Entries (<?= count($D['rows']) ?>)</summary>
          <?php foreach ($D['rows'] as $r): ?>
            <?php
              $minus = in_array($r['entry_type'], ['payment', 'collected'], true);
              $label = ['opening' => 'Opening', 'bill' => 'Bill ' . ($r['bill_no'] ?? ''), 'collected' => 'Prepaid on dealer QR', 'returned' => 'Refund from dealer QR'][$r['entry_type']]
                  ?? ('Payment · ' . ($r['pay_kind'] === 'cod' ? 'COD' : 'Extra / old'));
            ?>
      <div class="dd-ent">
        <div><?= e(trim($label)) ?><?php if ($r['account']): ?><span class="mini"><?= $r['entry_type'] === 'payment' ? 'from ' : 'on ' ?><?= e($r['account']) ?></span><?php endif; ?><?php if ($r['note'] && trim((string)$r['note']) !== trim($label)): ?><span class="mini"><?= e($r['note']) ?></span><?php endif; ?><?php if ($r['entry_type'] === 'payment'): ?><span class="acts" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px"><a class="btn btn-ghost btn-sm linkbtn" href="ledger_entry.php?id=<?= (int)$r['id'] ?>&amp;back=<?= e(rawurlencode($url(['dealer' => $dealer ?: '']))) ?>">Edit Payment</a><form method="post" action="ledger_entry.php" onsubmit="return confirm('Delete this payment of <?= e(inr($r['amount'])) ?>? The amount is added back to what you owe the dealer.');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= e($url(['dealer' => $dealer ?: ''])) ?>"><button class="btn btn-ghost btn-sm" type="submit">Delete Payment</button></form></span><?php endif; ?></div>
        <b class="<?= $minus ? 'pos' : '' ?>"><?= $minus ? '−' : '+' ?><?= e(inr($r['amount'])) ?></b>
      </div>
          <?php endforeach; ?>
    </details>
  </div>
        <?php endforeach; ?>
</div>
      <?php endif; ?>
  <?php endif; ?>
<?= $payForm() ?>

<?php /* ============================== COD & PROFIT ============================== */ ?>
<?php elseif ($tab === 'cod'): ?>
  <?php
    $codItems = dealer_filter($allItems, $dealer, $from, $to, 'cod', '');
    $ct = dealer_totals($codItems);
    $codDays = dealer_cod_days($codItems);
    $money = dealer_money($pdo, $from, $to, $dealer);
    $onQr = dealer_cod_on_qr($pdo, $from, $to, $dealer);
    $owedCod = max(0.0, $ct['cost'] - $onQr);
    $remain = $owedCod - $money['cod_paid'];
  ?>
<section class="card dd-filter">
  <?= $chips() ?>
  <details open><summary>Filters<?= $dealerName !== '' ? ' · ' . e($dealerName) : '' ?></summary>
  <form method="get" class="filters" style="margin:12px 0 0">
    <input type="hidden" name="tab" value="cod">
    <?= $dealerSelect() ?>
    <div><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($from) ?>" <?= $dateInput ?>></div>
    <div><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($to) ?>" <?= $dateInput ?>></div>
    <button class="btn" type="submit">Show</button>
    <a class="btn btn-ghost linkbtn" href="dealer_ledger.php?tab=cod">Clear</a>
  </form>
  </details>
</section>

<div class="dd-sec">COD orders · <?= e($from === $to ? dealer_dlabel($from) : dealer_dlabel($from) . ' to ' . dealer_dlabel($to)) ?><?= $dealerName !== '' ? ' · ' . e($dealerName) : '' ?></div>
<div class="dd-tiles" style="margin-top:0">
  <?= $tile('COD order value', e(inr($ct['cod'])), $ct['orders'] . ' order' . ($ct['orders'] === 1 ? '' : 's') . ' · advance ' . e(inr($ct['adv']))) ?>
  <?= $tile('COD received', e(inr($ct['recv'])), 'in your bank so far') ?>
  <?= $tile('Still to collect', e(inr($ct['due'])), '', $ct['due'] > 0.004 ? 'color:#B45309' : '') ?>
  <?= $tile('Buying cost', e(inr($ct['cost'])), 'of these COD products') ?>
  <?= $tile('Profit', e(inr($ct['profit'])), 'on these COD orders', 'color:var(--good)') ?>
  <?= $tile('Payable to dealer', e(inr($owedCod)), $onQr > 0.004 ? 'after ' . e(inr($onQr)) . ' already on their QR' : 'buying cost of COD orders') ?>
  <?= $tile('Already paid', e(inr($money['cod_paid'])), 'payments tagged "COD buying cost"') ?>
  <?= $tile('Remaining', e(inr(max(0.0, $remain))), $remain < -0.004 ? 'paid ' . e(inr(-$remain)) . ' extra' : 'to pay for COD orders', $remain > 0.004 ? 'color:#B45309' : '') ?>
</div>
<p class="hint">Payable = buying cost of the COD orders placed in these dates, less customer money that already landed on the dealer's own QR. "Already paid" counts payments you saved as <i>COD buying cost</i> in these dates. Extra payments for old balance are separate and only change the dealer ledger.</p>

<div class="dd-sec">Day by day</div>
  <?php if (!$codDays): ?>
<section class="card"><div class="empty">No COD orders in these dates.</div></section>
  <?php else: ?>
<div class="dd-days">
    <?php foreach ($codDays as $date => $D): ?>
  <div class="dd-day">
    <div class="dd-dayhead"><span><?= e(dealer_dlabel($date)) ?></span><span class="tag"><?= (int)$D['orders'] ?> COD order<?= $D['orders'] === 1 ? '' : 's' ?></span></div>
    <div class="dd-line"><span>COD value</span><b><?= e(inr($D['cod'])) ?></b></div>
    <div class="dd-line"><span>Received so far</span><b><?= e(inr($D['recv'])) ?></b></div>
    <div class="dd-line"><span>Still to collect</span><b class="<?= $D['due'] > 0.004 ? 'dd-neg' : '' ?>"><?= e(inr($D['due'])) ?></b></div>
    <div class="dd-line"><span>Buying cost</span><b><?= e(inr($D['cost'])) ?></b></div>
    <div class="dd-line dd-close"><span>Profit</span><b class="pos"><?= e(inr($D['profit'])) ?></b></div>
  </div>
    <?php endforeach; ?>
</div>
  <?php endif; ?>
<?= $payForm() ?>

<?php /* ============================== DISPATCH ============================== */ ?>
<?php else: ?>
  <?php
    $scope = dealer_filter($allItems, $dealer, $from, $to);
    $dt = dealer_totals($scope);
    if ($view === 'products') {
        $prodRows = dealer_products($scope);
        if ($status === 'open') {
            $prodRows = array_filter($prodRows, static fn($r) => $r['pend'] > 0);
        } elseif ($status === 'pending') {
            $prodRows = array_filter($prodRows, static fn($r) => $r['disp'] === 0);
        } elseif ($status === 'partial') {
            $prodRows = array_filter($prodRows, static fn($r) => $r['disp'] > 0 && $r['pend'] > 0);
        } elseif ($status === 'done') {
            $prodRows = array_filter($prodRows, static fn($r) => $r['pend'] <= 0);
        }
        $orderRows = [];
    } else {
        $orderRows = array_filter(dealer_orders($scope), static function ($o) use ($status) {
            return match ($status) {
                'open' => $o['state'] !== 'done',
                'pending' => $o['state'] === 'pending',
                'partial' => $o['state'] === 'partial',
                'done' => $o['state'] === 'done',
                default => true,
            };
        });
        $prodRows = [];
    }
    $shown = count($orderRows);
    $orderRows = array_slice($orderRows, 0, 150, true);
  ?>
<section class="card dd-filter">
  <?= $chips(true) ?>
  <details open><summary>Filters<?= $dealerName !== '' ? ' · ' . e($dealerName) : '' ?></summary>
  <form method="get" class="filters" style="margin:12px 0 0">
    <input type="hidden" name="tab" value="dispatch">
    <?= $dealerSelect() ?>
    <div><label for="status">Show</label><select id="status" name="status">
      <?php foreach (['open' => 'Not fully dispatched', 'pending' => 'Nothing dispatched', 'partial' => 'Partly dispatched', 'done' => 'Fully dispatched', 'all' => 'Everything'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select></div>
    <div><label for="view">View</label><select id="view" name="view"><option value="orders"<?= $view === 'orders' ? ' selected' : '' ?>>Order by order</option><option value="products"<?= $view === 'products' ? ' selected' : '' ?>>Product by product</option></select></div>
    <div><label for="from">Orders from</label><input id="from" name="from" type="date" value="<?= e($from) ?>" <?= $dateInput ?>></div>
    <div><label for="to">Orders to</label><input id="to" name="to" type="date" value="<?= e($to) ?>" <?= $dateInput ?>></div>
    <button class="btn" type="submit">Show</button>
    <a class="btn btn-ghost linkbtn" href="dealer_ledger.php?tab=dispatch">Clear</a>
  </form>
  </details>
</section>

<div class="dd-tiles">
  <?= $tile('Ordered', (string)$dt['units'], 'units from ' . ($dealerName !== '' ? e($dealerName) : 'dealers')) ?>
  <?= $tile('Dispatched', (string)$dt['disp'], 'sent by the dealer') ?>
  <?= $tile('Pending', (string)$dt['pend'], 'not sent yet', $dt['pend'] > 0 ? 'color:#B45309' : '') ?>
  <?= $tile('Pending buying value', e(inr($dt['pend_value'])), 'what those units cost you') ?>
</div>

  <?php if ($view === 'products'): ?>
<section class="card">
  <h2>Product by product</h2>
    <?php if (!$prodRows): ?>
  <div class="empty">Nothing to show for this selection.</div>
    <?php else: ?>
  <div class="table-wrap"><table class="tight" style="min-width:540px">
    <thead><tr><th>Product</th><th>Dealer</th><th class="num">Ordered</th><th class="num">Sent</th><th class="num">Pending</th><th class="num">Pending value</th></tr></thead>
    <tbody>
      <?php foreach ($prodRows as $r): ?>
      <tr>
        <td><?= e($r['name']) ?></td><td><?= e($r['dealer']) ?></td>
        <td class="num"><?= (int)$r['ordered'] ?></td><td class="num"><?= (int)$r['disp'] ?></td>
        <td class="num"><?= $r['pend'] > 0 ? '<span class="tag tag-warn">' . (int)$r['pend'] . '</span>' : '<span class="tag tag-ok">Done</span>' ?></td>
        <td class="num"><?= $r['pend'] > 0 ? e(inr($r['pend_value'])) : '-' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
    <?php endif; ?>
</section>
  <?php else: ?>
    <?php if (!$orderRows): ?>
<section class="card"><div class="empty">No orders match. Everything shown here is already fully dispatched, or nothing was ordered in these dates.</div></section>
    <?php else: ?>
<div class="dd-days">
      <?php foreach ($orderRows as $o): ?>
  <div class="dd-order">
    <div class="dd-dayhead">
      <span><a class="rowlink" href="order_view.php?id=<?= (int)$o['id'] ?>">Order <?= e(order_no($o['id'])) ?></a> <span class="mini"><?= e($o['customer']) ?> · <?= e(date('d M', strtotime($o['date']))) ?> · <?= $o['mode'] === 'cod' ? 'COD' : 'Prepaid' ?></span></span>
      <span class="tag <?= ['done' => 'tag-ok', 'partial' => 'tag-info', 'pending' => 'tag-warn'][$o['state']] ?>"><?= ['done' => 'Dispatched', 'partial' => 'Partly', 'pending' => 'Pending'][$o['state']] ?></span>
    </div>
    <div class="mini" style="margin-bottom:4px"><?= (int)$o['disp'] ?> of <?= (int)$o['units'] ?> unit<?= $o['units'] === 1 ? '' : 's' ?> dispatched<?= $o['last'] ? ' · last on ' . e(date('d M', strtotime($o['last']))) : '' ?><?= $o['pend_value'] > 0.004 ? ' · pending value ' . e(inr($o['pend_value'])) : '' ?></div>
        <?php foreach ($o['items'] as $it): ?>
          <?php $p = $it['qty'] - $it['disp']; ?>
    <div class="dd-it">
      <div><?= e($it['pname']) ?> × <?= (int)$it['qty'] ?><span class="mini" style="display:block"><?= e($it['dname']) ?></span></div>
      <div class="r">
          <?php if ($p <= 0): ?><span class="tag tag-ok">Sent <?= $it['disp_date'] ? e(date('d M', strtotime($it['disp_date']))) : '' ?></span>
          <?php elseif ($it['disp'] > 0): ?><span class="tag tag-info"><?= (int)$it['disp'] ?> sent</span> <span class="tag tag-warn"><?= $p ?> pending</span>
          <?php else: ?><span class="tag tag-warn"><?= $p ?> pending</span><?php endif; ?>
          <?php if ($p > 0): ?><span class="mini" style="display:block"><?= e(inr($p * $it['base'])) ?></span><?php endif; ?>
      </div>
    </div>
        <?php endforeach; ?>
  </div>
      <?php endforeach; ?>
</div>
      <?php if ($shown > 150): ?><p class="hint">Showing the newest 150 of <?= (int)$shown ?> orders. Use the dates or dealer to narrow it down.</p><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
<p class="hint">A unit counts as dispatched once it is on a bill you made for that dealer. When a product has several orders, the oldest orders are marked dispatched first.</p>
<?php endif; ?>
<script>
if (window.matchMedia && matchMedia('(max-width:600px)').matches) { document.querySelectorAll('.dd-filter details').forEach(function (d) { d.open = false; }); }
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>