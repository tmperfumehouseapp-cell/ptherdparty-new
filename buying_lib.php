<?php
// Orders page: the "Buying" tab (manager only) and the tab row shared with the normal orders list.

/** The All / Prepaid / COD / COD money pending / Buying buttons. */
function orders_tabs(string $mode, bool $isAdmin): string
{
    $link = static function (string $m): string {
        if ($m === 'buying') {
            return 'orders.php?mode=buying';
        }
        $qs = $_GET;
        unset($qs['page']);
        if ($m === '') {
            unset($qs['mode']);
        } else {
            $qs['mode'] = $m;
        }
        foreach (['dealer'] as $drop) {
            unset($qs[$drop]);
        }
        return 'orders.php' . ($qs ? '?' . http_build_query($qs) : '');
    };
    $tabs = ['' => 'All', 'prepaid' => 'Prepaid', 'cod' => 'COD', 'cod_due' => 'COD money pending'];
    if ($isAdmin) {
        $tabs['buying'] = 'Buying';
    }
    $h = '<div style="display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px">';
    foreach ($tabs as $mk => $ml) {
        $h .= '<a class="btn ' . ($mode === $mk ? 'btn-accent' : 'btn-ghost') . '" style="display:inline-flex;align-items:center;text-decoration:none;height:38px" href="' . e($link($mk)) . '">' . e($ml) . '</a>';
    }
    return $h . '</div>';
}

function buying_date_label(string $d): string
{
    return date('d M Y', strtotime($d));
}

/** Everything the Buying tab shows, for one date range and (optionally) one dealer. */
function buying_data(PDO $pdo, string $from, string $to, int $dealer): array
{
    // sales side: by order date, refunded orders left out
    $st = $pdo->prepare(
        "SELECT o.order_date AS d, COUNT(*) AS n,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'prepaid' THEN o.total ELSE 0 END), 0) AS prepaid,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'cod' THEN o.total ELSE 0 END), 0) AS cod,
                COALESCE(SUM(CASE WHEN o.payment_mode = 'cod' THEN GREATEST(o.total - o.advance_amount - COALESCE(cr.recv, 0), 0) ELSE 0 END), 0) AS due
         FROM orders o
         LEFT JOIN (SELECT order_id, SUM(amount) AS recv FROM cod_receipts GROUP BY order_id) cr ON cr.order_id = o.id
         WHERE o.status <> 'refunded' AND o.order_date BETWEEN ? AND ?
         GROUP BY o.order_date"
    );
    $st->execute([$from, $to]);
    $days = [];
    foreach ($st->fetchAll() as $r) {
        $days[$r['d']] = ['orders' => (int)$r['n'], 'prepaid' => (float)$r['prepaid'], 'cod' => (float)$r['cod'], 'due' => (float)$r['due'], 'buy' => 0.0, 'bills' => 0, 'paid' => 0.0];
    }

    // buying side: approved bills (they sit in the party ledger as 'bill'), by bill date
    $sql = "SELECT entry_date AS d, COUNT(*) AS n, SUM(amount) AS amt FROM party_ledger
            WHERE entry_type = 'bill' AND entry_date BETWEEN ? AND ?" . ($dealer ? ' AND party_id = ?' : '') . ' GROUP BY entry_date';
    $st = $pdo->prepare($sql);
    $st->execute($dealer ? [$from, $to, $dealer] : [$from, $to]);
    foreach ($st->fetchAll() as $r) {
        $days[$r['d']] = ($days[$r['d']] ?? ['orders' => 0, 'prepaid' => 0.0, 'cod' => 0.0, 'due' => 0.0, 'buy' => 0.0, 'bills' => 0, 'paid' => 0.0]);
        $days[$r['d']]['buy'] = (float)$r['amt'];
        $days[$r['d']]['bills'] = (int)$r['n'];
    }
    $sql = "SELECT entry_date AS d, SUM(amount) AS amt FROM party_ledger
            WHERE entry_type = 'payment' AND entry_date BETWEEN ? AND ?" . ($dealer ? ' AND party_id = ?' : '') . ' GROUP BY entry_date';
    $st = $pdo->prepare($sql);
    $st->execute($dealer ? [$from, $to, $dealer] : [$from, $to]);
    foreach ($st->fetchAll() as $r) {
        $days[$r['d']] = ($days[$r['d']] ?? ['orders' => 0, 'prepaid' => 0.0, 'cod' => 0.0, 'due' => 0.0, 'buy' => 0.0, 'bills' => 0, 'paid' => 0.0]);
        $days[$r['d']]['paid'] = (float)$r['amt'];
    }
    krsort($days);

    $tot = ['orders' => 0, 'prepaid' => 0.0, 'cod' => 0.0, 'due' => 0.0, 'buy' => 0.0, 'bills' => 0, 'paid' => 0.0, 'buyDays' => 0];
    foreach ($days as $d) {
        foreach (['orders', 'prepaid', 'cod', 'due', 'buy', 'bills', 'paid'] as $k) {
            $tot[$k] += $d[$k];
        }
        if ($d['bills'] > 0) {
            $tot['buyDays']++;
        }
    }

    // every dealer: purchases and payments in the range, and what is still owed today
    $dealers = $pdo->prepare(
        "SELECT p.id, p.name, p.is_active,
                COALESCE(SUM(CASE WHEN l.entry_type = 'bill' AND l.entry_date BETWEEN ? AND ? THEN l.amount END), 0) AS buy,
                COALESCE(SUM(CASE WHEN l.entry_type = 'bill' AND l.entry_date BETWEEN ? AND ? THEN 1 END), 0) AS bills,
                COALESCE(SUM(CASE WHEN l.entry_type = 'payment' AND l.entry_date BETWEEN ? AND ? THEN l.amount END), 0) AS paid,
                COALESCE(SUM(CASE WHEN l.entry_type IN ('payment','collected') THEN -l.amount ELSE l.amount END), 0) AS balance
         FROM parties p LEFT JOIN party_ledger l ON l.party_id = p.id
         GROUP BY p.id, p.name, p.is_active ORDER BY p.is_active DESC, p.name"
    );
    $dealers->execute([$from, $to, $from, $to, $from, $to]);
    $dealerRows = $dealers->fetchAll();

    $bills = [];
    if ($dealer) {
        $st = $pdo->prepare(
            "SELECT l.entry_date, l.amount, l.bill_id, b.bill_no,
                    (SELECT COALESCE(SUM(bi.qty), 0) FROM bill_items bi WHERE bi.bill_id = l.bill_id) AS units
             FROM party_ledger l LEFT JOIN bills b ON b.id = l.bill_id
             WHERE l.entry_type = 'bill' AND l.party_id = ? AND l.entry_date BETWEEN ? AND ?
             ORDER BY l.entry_date DESC, l.id DESC"
        );
        $st->execute([$dealer, $from, $to]);
        $bills = $st->fetchAll();
    }
    return ['days' => $days, 'tot' => $tot, 'dealers' => $dealerRows, 'bills' => $bills];
}

/** Works out everything the Buying tab shows. Returns the values buying_body() prints. */
function buying_prepare(PDO $pdo): array
{
    $today = biz_today();
    $from = valid_date((string)($_GET['from'] ?? '')) ?? '';
    $to = valid_date((string)($_GET['to'] ?? '')) ?? '';
    $dealer = (int)($_GET['dealer'] ?? 0);
    if ($from === '' && $to === '') {
        $from = date('Y-m-d', strtotime($today . ' -29 days'));
        $to = $today;
    } elseif ($to === '') {
        $to = $today;
    } elseif ($from === '') {
        $from = date('Y-m-d', strtotime($to . ' -29 days'));
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $partyList = $pdo->query('SELECT id, name, is_active FROM parties ORDER BY is_active DESC, name')->fetchAll();
    $dealerName = '';
    foreach ($partyList as $p) {
        if ((int)$p['id'] === $dealer) {
            $dealerName = $p['name'];
        }
    }
    if ($dealerName === '') {
        $dealer = 0;
    }

    $data = buying_data($pdo, $from, $to, $dealer);
    $days = $data['days'];
    $t = $data['tot'];

    $first = $pdo->query("SELECT MIN(d) FROM (SELECT MIN(order_date) AS d FROM orders UNION ALL SELECT MIN(entry_date) FROM party_ledger WHERE entry_type = 'bill') x")->fetchColumn();
    $chip = static function (string $label, string $f, string $t2) use ($dealer, $from, $to): string {
        $qs = ['mode' => 'buying', 'from' => $f, 'to' => $t2] + ($dealer ? ['dealer' => $dealer] : []);
        $on = ($f === $from && $t2 === $to);
        return '<a class="btn ' . ($on ? 'btn-accent' : 'btn-ghost') . ' btn-sm linkbtn" href="orders.php?' . e(http_build_query($qs)) . '">' . e($label) . '</a>';
    };
    $yest = date('Y-m-d', strtotime($today . ' -1 day'));
    $dateInput = 'style="width:100%;height:48px;border-radius:12px;border:1px solid var(--field-line);background:var(--field);padding:0 12px"';
    $waiting = (int)$pdo->query("SELECT COUNT(*) FROM bills WHERE status IN ('made','sent')" . ($dealer ? ' AND party_id = ' . (int)$dealer : ''))->fetchColumn();

    return compact('today', 'from', 'to', 'dealer', 'dealerName', 'partyList', 'data', 'days', 't', 'first', 'chip', 'yest', 'dateInput', 'waiting');
}

/** Prints the Buying tab (below the header and the tab row). */
function buying_body(array $v): void
{
    extract($v);
    ?>
<section class="card">
  <form method="get" class="filters">
    <input type="hidden" name="mode" value="buying">
    <div><label for="dealer">Dealer</label>
      <select id="dealer" name="dealer"><option value="0">All dealers</option>
        <?php foreach ($partyList as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $dealer === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['name'] . ($p['is_active'] ? '' : ' (off)')) ?></option><?php endforeach; ?>
      </select></div>
    <div><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($from) ?>" <?= $dateInput ?>></div>
    <div><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($to) ?>" <?= $dateInput ?>></div>
    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-ghost linkbtn" href="orders.php?mode=buying">Clear</a>
  </form>
  <div style="display:flex;flex-wrap:wrap;gap:8px">
    <?= $chip('Today', $today, $today) ?>
    <?= $chip('Yesterday', $yest, $yest) ?>
    <?= $chip('Last 7 days', date('Y-m-d', strtotime($today . ' -6 days')), $today) ?>
    <?= $chip('This month', date('Y-m-01', strtotime($today)), $today) ?>
    <?= $chip('All time', $first ?: $today, $today) ?>
  </div>
</section>

<style>.bgrid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin:16px 0}.bgrid .stat{min-width:0}.bgrid .stat .v{font-size:24px}</style>
<div class="bgrid">
  <div class="stat"><div class="k">Prepaid</div><div class="v"><?= e(inr($t['prepaid'])) ?></div></div>
  <div class="stat"><div class="k">COD</div><div class="v"><?= e(inr($t['cod'])) ?></div></div>
  <div class="stat"><div class="k">COD money pending</div><div class="v" style="color:#B45309"><?= e(inr($t['due'])) ?></div></div>
  <div class="stat"><div class="k">Total buying<?= $dealerName !== '' ? ' · ' . e($dealerName) : '' ?></div><div class="v"><?= e(inr($t['buy'])) ?></div></div>
</div>

<section class="card">
  <h2>Day by day</h2>
  <?php if (!$days): ?>
    <div class="empty">Nothing in this date range.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="tight" style="min-width:640px">
      <thead><tr><th>Date</th><th class="num">Orders</th><th class="num">Prepaid</th><th class="num">COD</th><th class="num">COD pending</th><th class="num">Buying</th></tr></thead>
      <tbody>
      <?php foreach ($days as $d => $r): ?>
        <tr>
          <td><?= e(buying_date_label($d)) ?></td>
          <td class="num"><?= (int)$r['orders'] ?></td>
          <td class="num"><?= $r['prepaid'] > 0 ? e(inr($r['prepaid'])) : '-' ?></td>
          <td class="num"><?= $r['cod'] > 0 ? e(inr($r['cod'])) : '-' ?></td>
          <td class="num"><?= $r['due'] > 0.004 ? '<span class="tag tag-warn">' . e(inr($r['due'])) . '</span>' : '-' ?></td>
          <td class="num"><?= $r['buy'] > 0 ? '<b>' . e(inr($r['buy'])) . '</b>' . ($r['bills'] > 1 ? '<div class="mini">' . (int)$r['bills'] . ' bills</div>' : '') : '-' ?></td>
        </tr>
      <?php endforeach; ?>
        <tr style="border-top:2px solid var(--line);font-weight:800">
          <td>Total</td><td class="num"><?= (int)$t['orders'] ?></td><td class="num"><?= e(inr($t['prepaid'])) ?></td><td class="num"><?= e(inr($t['cod'])) ?></td><td class="num"><?= e(inr($t['due'])) ?></td><td class="num"><?= e(inr($t['buy'])) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
  <p class="hint" style="margin:10px 0 0">Sales columns use the order date and leave out refunded orders. Buying is the bills you approved, by bill date<?= $waiting ? ' (' . $waiting . ' bill' . ($waiting > 1 ? 's' : '') . ' still waiting for approval are not counted)' : '' ?>.</p>
  <?php endif; ?>
</section>

    <?php if ($dealer): ?>
      <?php
        $dr = null;
        foreach ($data['dealers'] as $x) {
            if ((int)$x['id'] === $dealer) {
                $dr = $x;
            }
        }
        $bal = $dr ? (float)$dr['balance'] : 0.0;
        $avg = $t['buyDays'] > 0 ? $t['buy'] / $t['buyDays'] : 0.0;
      ?>
<section class="card" style="margin-top:16px">
  <h2><?= e($dealerName) ?> · <?= e(buying_date_label($from)) ?> to <?= e(buying_date_label($to)) ?></h2>
  <div class="bgrid" style="margin-top:4px;grid-template-columns:repeat(auto-fit,minmax(128px,1fr))">
    <div class="stat"><div class="k">Total buying</div><div class="v"><?= e(inr($t['buy'])) ?></div></div>
    <div class="stat"><div class="k">Daily buying (average)</div><div class="v"><?= e(inr($avg)) ?></div><div class="mini">over <?= (int)$t['buyDays'] ?> buying day<?= $t['buyDays'] === 1 ? '' : 's' ?></div></div>
    <div class="stat"><div class="k">Purchases</div><div class="v"><?= (int)$t['bills'] ?></div></div>
    <div class="stat"><div class="k">Paid in this range</div><div class="v"><?= e(inr($t['paid'])) ?></div></div>
    <div class="stat"><div class="k">Pending payment now</div><div class="v"<?= $bal > 0.004 ? ' style="color:#B45309"' : '' ?>><?= $bal > 0.004 ? e(inr($bal)) : ($bal < -0.004 ? '₹0 (advance ' . e(inr(-$bal)) . ')' : '₹0') ?></div><div class="mini">all time, not only this range</div></div>
  </div>
  <h2 style="margin-top:18px">Date-wise purchase history</h2>
      <?php if (!$data['bills']): ?>
  <div class="empty">No approved purchases from <?= e($dealerName) ?> in this range.</div>
      <?php else: ?>
  <div class="table-wrap">
    <table class="tight" style="min-width:480px">
      <thead><tr><th>Date</th><th>Bill</th><th class="num">Units</th><th class="num">Amount</th></tr></thead>
      <tbody>
        <?php foreach ($data['bills'] as $b): ?>
        <tr>
          <td><?= e(buying_date_label($b['entry_date'])) ?></td>
          <td><?= e($b['bill_no'] ?? '-') ?></td>
          <td class="num"><?= (int)$b['units'] ?: '-' ?></td>
          <td class="num"><?= e(inr($b['amount'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
      <?php endif; ?>
  <p style="margin:12px 0 0"><a class="btn btn-ghost btn-sm linkbtn" href="party_ledger.php?id=<?= (int)$dealer ?>">Open <?= e($dealerName) ?> ledger</a></p>
</section>
    <?php else: ?>
<section class="card" style="margin-top:16px">
  <h2>Dealer-wise buying</h2>
  <div class="table-wrap">
    <table class="tight" style="min-width:560px">
      <thead><tr><th>Dealer</th><th class="num">Purchases</th><th class="num">Buying</th><th class="num">Paid</th><th class="num">Pending now</th></tr></thead>
      <tbody>
        <?php foreach ($data['dealers'] as $x): ?>
          <?php if ((float)$x['buy'] == 0.0 && (float)$x['paid'] == 0.0 && abs((float)$x['balance']) < 0.005) { continue; } ?>
          <?php $bal = (float)$x['balance']; ?>
        <tr>
          <td><a class="rowlink" href="orders.php?<?= e(http_build_query(['mode' => 'buying', 'dealer' => $x['id'], 'from' => $from, 'to' => $to])) ?>"><?= e($x['name']) ?></a></td>
          <td class="num"><?= (int)$x['bills'] ?></td>
          <td class="num"><?= e(inr($x['buy'])) ?></td>
          <td class="num"><?= e(inr($x['paid'])) ?></td>
          <td class="num"><?= $bal > 0.004 ? '<span class="tag tag-warn">' . e(inr($bal)) . '</span>' : ($bal < -0.004 ? '<span class="tag tag-ok">Advance ' . e(inr(-$bal)) . '</span>' : '-') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint" style="margin:10px 0 0">Tap a dealer to see their purchases day by day. "Pending now" is what you still owe, all time.</p>
</section>
    <?php endif; ?>
    <?php
}
