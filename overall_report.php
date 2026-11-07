<?php
// Overall Report: the whole business on one page (manager only). READ ONLY: this page never writes anything.
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/report_lib.php';
$user = require_admin();
$pdo = db();

$today = biz_today();
$P = rep_period($today, (string)($_GET['f'] ?? 'month'), (string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? ''));

// ---- numbers (each figure is worked out once and shared by every card) ----
$S  = rep_sales_safe($pdo, $P['from'], $P['to']);
$S0 = rep_sales_safe($pdo, $P['prevFrom'], $P['prevTo']);
$R  = rep_ratios($S);
$R0 = rep_ratios($S0);
$C  = rep_try(static fn() => rep_cash($pdo, $P['from'], $P['to']), ['prepaid_in' => 0.0, 'cod_in' => 0.0, 'refund_out' => 0.0, 'dealer_paid' => 0.0, 'bought' => 0.0, 'bills' => 0, 'cash_in' => 0.0, 'net_cash' => 0.0]);
$C0 = rep_try(static fn() => rep_cash($pdo, $P['prevFrom'], $P['prevTo']), $C);
$snap = rep_try(static fn() => rep_snapshot($pdo, $today), ['cod_pending' => 0.0, 'cod_orders' => 0, 'aging' => [], 'owed' => 0.0, 'owed_gross' => 0.0, 'advance' => 0.0, 'accounts' => 0.0]);
$dealers = rep_try(static fn() => rep_dealers($pdo, $P['from'], $P['to']), []);
$daily = rep_try(static fn() => rep_daily($pdo, $P['from'], $P['to']), []);
$months = rep_try(static fn() => rep_months($pdo, $today, 6), []);
$mix = rep_try(static fn() => rep_status_mix($pdo, $P['from'], $P['to']), []);
$top = rep_try(static fn() => rep_top_products($pdo, $P['from'], $P['to'], 5), []);
$rep = rep_try(static fn() => rep_repeat_customers($pdo, $P['from'], $P['to']), ['repeat' => 0]);
$ret = rep_try(static fn() => rep_returns($pdo, $P['from'], $P['to']), ['orders' => 0, 'amount' => 0.0, 'closed' => 0]);

// today vs this month (always current, whatever the filter says)
$mFrom = date('Y-m-01', strtotime($today));
$daysIn = (int)date('j', strtotime($today));
$T  = rep_sales_safe($pdo, $today, $today);
$M  = rep_sales_safe($pdo, $mFrom, $today);
$TC = rep_try(static fn() => rep_cash($pdo, $today, $today), ['cash_in' => 0.0, 'net_cash' => 0.0, 'bought' => 0.0]);
$MC = rep_try(static fn() => rep_cash($pdo, $mFrom, $today), ['cash_in' => 0.0, 'net_cash' => 0.0, 'bought' => 0.0]);
$MR = rep_ratios($M);

// ---- presentation helpers ----
$money = static fn($v): string => e(inr($v));
$pct = static fn(float $v): string => number_format($v, 1) . '%';
/** Change chip. $goodUp: true = up is good, false = up is bad, null = neutral (just a fact). */
$chip = static function (float $cur, float $prev, ?bool $goodUp = true, bool $isPoints = false): string {
    if ($isPoints) { // margins are compared in percentage points
        $diff = $cur - $prev;
        if (abs($diff) < 0.05) {
            return '<span class="rp-chip flat">no change</span>';
        }
        $up = $diff > 0;
        $cls = $goodUp === null ? 'flat' : (($up === $goodUp) ? 'good' : 'bad');
        return '<span class="rp-chip ' . $cls . '">' . ($up ? '▲' : '▼') . ' ' . number_format(abs($diff), 1) . ' pts</span>';
    }
    $d = rep_delta($cur, $prev);
    if ($d['dir'] === 'flat') {
        return '<span class="rp-chip flat">no change</span>';
    }
    if ($d['dir'] === 'new') {
        return '<span class="rp-chip flat">new</span>';
    }
    $up = $d['dir'] === 'up';
    $cls = $goodUp === null ? 'flat' : (($up === $goodUp) ? 'good' : 'bad');
    return '<span class="rp-chip ' . $cls . '">' . ($up ? '▲' : '▼') . ' ' . number_format(abs($d['pct']), 1) . '%</span>';
};
$link = static function (array $over) use ($P): string {
    $q = array_merge(['f' => $P['key'], 'from' => $P['key'] === 'custom' ? $P['from'] : '', 'to' => $P['key'] === 'custom' ? $P['to'] : ''], $over);
    return 'overall_report.php?' . http_build_query(array_filter($q, static fn($v) => $v !== ''));
};

/** One summary card. */
$card = static function (string $k, string $v, string $sub = '', string $chipHtml = '', string $tone = '') {
    echo '<div class="rp-card' . ($tone !== '' ? ' ' . $tone : '') . '"><div class="rp-k">' . $k . '</div><div class="rp-v">' . $v . '</div>';
    if ($chipHtml !== '' || $sub !== '') {
        echo '<div class="rp-sub">' . $chipHtml . ($sub !== '' ? '<span>' . $sub . '</span>' : '') . '</div>';
    }
    echo '</div>';
};

/** Bars: [label, full, value A (sales), value B (profit)]. Pure SVG, no libraries. */
$barChart = static function (array $rows, string $aName, string $bName) use ($money): string {
    $n = count($rows);
    if ($n === 0) {
        return '<div class="rp-empty">No data in this period.</div>';
    }
    $max = 0.0;
    foreach ($rows as $r) {
        $max = max($max, $r['a'], $r['b']);
    }
    $max = $max > 0 ? $max : 1.0;
    $W = 720;
    $H = 220;
    $padL = 6;
    $padB = 26;
    $padT = 8;
    $plotH = $H - $padB - $padT;
    $slot = ($W - $padL) / $n;
    $bw = max(2.0, min(34.0, $slot * 0.62));
    $svg = '<svg class="rp-svg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($aName . ' and ' . $bName) . '">';
    for ($g = 0; $g <= 3; $g++) {
        $y = $padT + $plotH - $plotH * $g / 3;
        $svg .= '<line x1="0" x2="' . $W . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '" class="rp-grid"/>';
    }
    $every = $n > 24 ? (int)ceil($n / 12) : 1;
    foreach ($rows as $i => $r) {
        $x = $padL + $slot * $i + ($slot - $bw) / 2;
        $ha = $plotH * $r['a'] / $max;
        $hb = $plotH * max(0.0, $r['b']) / $max;
        $svg .= '<g><title>' . e($r['full'] . ': ' . $aName . ' ' . inr($r['a']) . ', ' . $bName . ' ' . inr($r['b'])) . '</title>';
        $svg .= '<rect x="' . round($x, 1) . '" y="' . round($padT + $plotH - $ha, 1) . '" width="' . round($bw, 1) . '" height="' . round(max($ha, $r['a'] > 0 ? 1 : 0), 1) . '" rx="3" class="rp-bar-a"/>';
        $svg .= '<rect x="' . round($x + $bw * 0.2, 1) . '" y="' . round($padT + $plotH - $hb, 1) . '" width="' . round($bw * 0.6, 1) . '" height="' . round(max($hb, $r['b'] > 0 ? 1 : 0), 1) . '" rx="2" class="rp-bar-b"/></g>';
        if ($i % $every === 0) {
            $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($H - 8) . '" text-anchor="middle" class="rp-axis">' . e($r['label']) . '</text>';
        }
    }
    $svg .= '</svg>';
    return '<div class="rp-legend"><span><i class="a"></i>' . e($aName) . '</span><span><i class="b"></i>' . e($bName) . '</span><span class="rp-peak">Tallest bar ' . $money($max) . '</span></div>' . $svg;
};

/** Horizontal share bars: [[label, value, extra html]]. */
$shareBars = static function (array $rows, float $total) use ($money): string {
    $h = '';
    foreach ($rows as [$label, $val, $extra]) {
        $w = $total > 0 ? max(0.0, min(100.0, $val / $total * 100)) : 0;
        $h .= '<div class="rp-share"><div class="rp-share-top"><span class="rp-share-n">' . e($label) . '</span><span><b>' . $money($val) . '</b> <small>' . number_format($w, 0) . '%</small></span></div>'
            . '<div class="rp-track"><div class="rp-fill" style="width:' . round($w, 1) . '%"></div></div>' . ($extra !== '' ? '<div class="rp-share-x">' . $extra . '</div>' : '') . '</div>';
    }
    return $h;
};

// ---- the "at a glance" sentence ----
$hasPrev = ($S0['orders'] > 0);
$sd = rep_delta($S['sales'], $S0['sales']);
$pd = rep_delta($S['est_profit'], $S0['est_profit']);
$mgDiff = $R['margin'] - $R0['margin'];
$aovDiff = rep_delta($R['aov'], $R0['aov']);
if ($S['orders'] === 0 && !$hasPrev) {
    $verdict = ['flat', 'No orders in this period yet.', 'Pick another date range, or create orders to see numbers here.'];
} elseif (!$hasPrev) {
    $verdict = ['flat', 'Nothing earlier to compare with.', 'There are no orders in ' . $P['prevLabel'] . ', so growth cannot be measured yet.'];
} else {
    $salesUp = $sd['dir'] === 'up' || $sd['dir'] === 'new';
    $salesDown = $sd['dir'] === 'down';
    $profUp = $pd['dir'] === 'up' || $pd['dir'] === 'new';
    $profDown = $pd['dir'] === 'down';
    if ($salesUp && $profUp) {
        $verdict = ['good', 'Business is growing.', 'Sales and profit are both up compared with ' . $P['prevLabel'] . '.'];
    } elseif ($salesDown && $profDown) {
        $verdict = ['bad', 'Business is slowing down.', 'Sales and profit are both lower than ' . $P['prevLabel'] . '.'];
    } elseif ($salesUp && $profDown) {
        $verdict = ['warn', 'Sales are up but profit is down.', 'You are selling more, but keeping less. Check discounts and buying prices.'];
    } elseif ($salesDown && $profUp) {
        $verdict = ['warn', 'Profit is up on fewer sales.', 'You are earning more from less volume compared with ' . $P['prevLabel'] . '.'];
    } else {
        $verdict = ['flat', 'Business is steady.', 'Sales and profit are about the same as ' . $P['prevLabel'] . '.'];
    }
}
$unrealized = max(0.0, $S['est_profit'] - $S['real_profit']);

// chart data
$bk = rep_bucket($daily);
$dayRows = array_map(static fn($b) => ['label' => $b['label'], 'full' => $b['full'], 'a' => $b['sales'], 'b' => $b['profit']], $bk);
$monRows = array_map(static fn($m) => ['label' => $m['label'], 'full' => $m['label'] . ($m['partial'] ? ' (so far)' : ''), 'a' => $m['sales'], 'b' => $m['profit']], $months);
$dealerTotal = 0.0;
foreach ($dealers as $d) {
    $dealerTotal += $d['buy'];
}

$pageTitle = 'Overall Report';
$active = 'overall_report';
require __DIR__ . '/includes/header.php';
?>
<style>
.rp{--rp-good:#15803D;--rp-bad:#B42318;--rp-warn:#B45309;display:block}
.rp *{box-sizing:border-box}
.rp-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 6px}
.rp-filters .btn{min-height:38px;padding:0 14px;font-size:13px}
.rp-custom{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin:10px 0 0}
.rp-custom label{display:block;font-size:12px;font-weight:700;color:var(--muted);margin:0 0 4px}
.rp-custom input{height:40px;border-radius:10px;border:1px solid var(--field-line);background:#fff;padding:0 10px;font:inherit}
.rp-period{font-size:13px;color:var(--muted);margin:8px 0 16px}
.rp-period b{color:var(--ink)}
.rp-hero{display:flex;gap:14px;align-items:flex-start;border-radius:18px;padding:18px 20px;margin:0 0 16px;border:1px solid var(--line);background:var(--card)}
.rp-hero .ic{flex:none;width:44px;height:44px;border-radius:14px;display:grid;place-items:center;font-size:22px;font-weight:800}
.rp-hero h2{margin:0 0 2px;font-size:19px}.rp-hero p{margin:0;color:var(--muted);font-size:14px}
.rp-hero.good{background:#F0FDF4;border-color:#BBF7D0}.rp-hero.good .ic{background:#DCFCE7;color:#166534}
.rp-hero.bad{background:#FEF2F2;border-color:#FECACA}.rp-hero.bad .ic{background:#FEE2E2;color:#991B1B}
.rp-hero.warn{background:#FFFBEB;border-color:#FDE68A}.rp-hero.warn .ic{background:#FEF3C7;color:#92400E}
.rp-hero.flat .ic{background:#E2E8F0;color:#334155}
.rp-hero-chips{display:flex;flex-wrap:wrap;gap:8px 14px;margin-top:10px;font-size:13px;color:var(--ink)}
.rp-hero-chips span{display:inline-flex;gap:6px;align-items:center}
.rp-h{font-size:12px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);margin:22px 0 10px}
.rp-grid4{display:grid;gap:12px;grid-template-columns:repeat(4,minmax(0,1fr));margin:0 0 12px}
.rp-grid3{display:grid;gap:12px;grid-template-columns:repeat(3,minmax(0,1fr));margin:0 0 12px}
.rp-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:15px 16px;box-shadow:0 1px 2px rgba(16,24,40,.03);min-width:0}
.rp-k{font-size:12.5px;color:var(--muted);font-weight:700}
.rp-v{font-size:25px;font-weight:800;line-height:1.2;margin:4px 0 2px;word-break:break-word}
.rp-sub{display:flex;flex-wrap:wrap;gap:4px 8px;align-items:center;font-size:12px;color:var(--muted)}
.rp-card.est{border-top:3px solid #94A3B8}.rp-card.real{border-top:3px solid #19B98A}.rp-card.cash{border-top:3px solid #2563EB}
.rp-card.warn .rp-v{color:var(--rp-warn)}
.rp-chip{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11.5px;font-weight:800;white-space:nowrap}
.rp-chip.good{background:#DCFCE7;color:#166534}.rp-chip.bad{background:#FEE2E2;color:#991B1B}.rp-chip.flat{background:#E5E7EB;color:#475569}
.rp-two{display:grid;gap:14px;grid-template-columns:1.6fr 1fr;align-items:start;margin:0 0 14px}
.rp-panel{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:18px;min-width:0}
.rp-panel h3{margin:0 0 4px;font-size:16px}
.rp-panel .rp-lead{margin:0 0 12px;font-size:12.5px;color:var(--muted)}
.rp-svg{width:100%;height:auto;display:block}
.rp-grid{stroke:#EEF1F5;stroke-width:1}
.rp-axis{font-size:11px;fill:#64748B;font-family:inherit}
.rp-bar-a{fill:#C7D2E3}.rp-bar-b{fill:#19B98A}
.rp-legend{display:flex;flex-wrap:wrap;gap:6px 14px;font-size:12px;color:var(--muted);margin:0 0 6px}
.rp-legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px}
.rp-legend i.a{background:#C7D2E3}.rp-legend i.b{background:#19B98A}
.rp-peak{margin-left:auto}
.rp-empty{padding:26px 8px;text-align:center;color:var(--muted);font-size:14px}
.rp-share{padding:9px 0;border-bottom:1px solid #F1F3F5}.rp-share:last-child{border:0}
.rp-share-top{display:flex;justify-content:space-between;gap:10px;font-size:14px}
.rp-share-top small{color:var(--muted);font-weight:700}
.rp-share-n{font-weight:700;min-width:0;overflow:hidden;text-overflow:ellipsis}
.rp-track{height:8px;border-radius:99px;background:#EEF1F5;margin-top:6px;overflow:hidden}
.rp-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#19B98A,#0B6B4F)}
.rp-share-x{font-size:12px;color:var(--muted);margin-top:5px}
.rp-three{display:grid;gap:10px;grid-template-columns:repeat(3,1fr)}
.rp-flow{background:var(--field);border:1px solid var(--line);border-radius:14px;padding:12px 14px}
.rp-flow .rp-v{font-size:21px}
.rp-flow p{margin:4px 0 0;font-size:12px;color:var(--muted);line-height:1.45}
.rp-table{width:100%;border-collapse:collapse;font-size:14px;min-width:560px}
.rp-table th{font-size:12px;color:var(--muted);text-align:right;font-weight:800;padding:8px 10px;border-bottom:1px solid var(--line);white-space:nowrap}
.rp-table th:first-child,.rp-table td:first-child{text-align:left}
.rp-table td{padding:10px;text-align:right;border-bottom:1px solid #F1F3F5;white-space:nowrap}
.rp-table tr.me td{background:#F0FDF4;font-weight:700}
.rp-table small{color:var(--muted);font-weight:600}
.rp-note{font-size:12.5px;color:var(--muted);margin:10px 0 0;line-height:1.5}
.rp-aging{display:grid;gap:8px;grid-template-columns:repeat(4,1fr);margin-top:12px}
.rp-aging div{border:1px solid var(--line);border-radius:12px;padding:10px 12px;background:#fff}
.rp-aging b{display:block;font-size:16px}.rp-aging span{font-size:11.5px;color:var(--muted);font-weight:700}
.rp-aging .old{background:#FEF3C7;border-color:#FCD34D}
.rp-list{list-style:none;margin:0;padding:0}
.rp-list li{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid #F1F3F5;font-size:14px}
.rp-list li:last-child{border:0}.rp-list span{color:var(--muted)}.rp-list b{font-weight:800;text-align:right}
.rp-split{height:12px;border-radius:99px;background:#FDE68A;overflow:hidden;display:flex;margin:10px 0 8px}
.rp-split i{display:block;height:100%;background:#19B98A}
@media(max-width:980px){.rp-grid4{grid-template-columns:repeat(2,minmax(0,1fr))}.rp-two{grid-template-columns:1fr}}
@media(max-width:640px){.rp-grid3,.rp-three{grid-template-columns:1fr}.rp-grid4{gap:10px}.rp-v{font-size:21px}.rp-aging{grid-template-columns:repeat(2,1fr)}.rp-panel{padding:14px}.rp-hero{padding:14px}.rp-peak{margin-left:0}}
</style>

<div class="rp">

<form method="get" action="overall_report.php" class="rp-filters-wrap">
  <div class="rp-filters" role="group" aria-label="Date range">
    <?php foreach (rep_filters() as $k => $lab): ?>
      <?php if ($k === 'custom'): ?>
        <a class="btn <?= $P['key'] === 'custom' ? 'btn-accent' : 'btn-ghost' ?>" href="<?= e($link(['f' => 'custom', 'from' => $P['from'], 'to' => $P['to']])) ?>"><?= e($lab) ?></a>
      <?php else: ?>
        <a class="btn <?= $P['key'] === $k ? 'btn-accent' : 'btn-ghost' ?>" href="<?= e($link(['f' => $k, 'from' => '', 'to' => ''])) ?>"><?= e($lab) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php if ($P['key'] === 'custom'): ?>
  <input type="hidden" name="f" value="custom">
  <div class="rp-custom">
    <div><label for="rp-from">From</label><input id="rp-from" type="date" name="from" value="<?= e($P['from']) ?>" max="<?= e($today) ?>"></div>
    <div><label for="rp-to">To</label><input id="rp-to" type="date" name="to" value="<?= e($P['to']) ?>"></div>
    <button class="btn btn-accent" type="submit" style="min-height:40px">Show</button>
  </div>
  <?php endif; ?>
</form>
<div class="rp-period">Showing <b><?= e($P['label']) ?></b> (<?= (int)$P['days'] ?> day<?= $P['days'] === 1 ? '' : 's' ?>) · compared with <b><?= e($P['prevLabel']) ?></b> (<?= e(date('j M', strtotime($P['prevFrom']))) ?><?= $P['prevFrom'] === $P['prevTo'] ? '' : ' – ' . e(date('j M', strtotime($P['prevTo']))) ?>)</div>

<!-- ===== 1. at a glance ===== -->
<section class="rp-hero <?= e($verdict[0]) ?>">
  <div class="ic" aria-hidden="true"><?= $verdict[0] === 'good' ? '▲' : ($verdict[0] === 'bad' ? '▼' : ($verdict[0] === 'warn' ? '!' : '•')) ?></div>
  <div style="min-width:0">
    <h2><?= e($verdict[1]) ?></h2>
    <p><?= e($verdict[2]) ?></p>
    <?php if ($hasPrev): ?>
    <div class="rp-hero-chips">
      <span>Sales <?= $chip($S['sales'], $S0['sales']) ?></span>
      <span>Profit <?= $chip($S['est_profit'], $S0['est_profit']) ?></span>
      <span>Margin <?= $chip($R['margin'], $R0['margin'], true, true) ?></span>
      <span>Avg order value <?= $chip($R['aov'], $R0['aov']) ?></span>
      <span>Orders <?= $chip((float)$S['orders'], (float)$S0['orders']) ?></span>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ===== 2. profit vs cash: never mixed up ===== -->
<div class="rp-h">Sales, profit and cash: three different things</div>
<div class="rp-grid4">
  <?php $card('Sales (order value)', $money($S['sales']), $S['orders'] . ' order' . ($S['orders'] === 1 ? '' : 's') . ' · refunded orders left out', $chip($S['sales'], $S0['sales'])); ?>
  <?php $card('Estimated profit', $money($S['est_profit']), 'booked when orders were made', $chip($S['est_profit'], $S0['est_profit']), 'est'); ?>
  <?php $card('Realized profit', $money($S['real_profit']), $unrealized > 0.004 ? $money($unrealized) . ' still waiting on COD' : 'all of it received', $chip($S['real_profit'], $S0['real_profit']), 'real'); ?>
  <?php $card('Cash actually received', $money($C['net_cash']), 'in ' . $money($C['cash_in']) . ' · refunds out ' . $money($C['refund_out']), $chip($C['net_cash'], $C0['net_cash']), 'cash'); ?>
</div>
<p class="rp-note" style="margin-top:0"><b>Estimated profit</b> counts every order at the profit saved when it was made, even if the COD money has not arrived. <b>Realized profit</b> counts only the part of that profit whose money has really been received (and not refunded), the same rule Commission uses. <b>Cash received</b> is real money that came into your accounts in these dates, whichever day the order was made.</p>

<!-- ===== 3. financial overview ===== -->
<div class="rp-h">Financial overview · <?= e($P['label']) ?></div>
<div class="rp-grid4">
  <?php $card('Average order value', $money($R['aov']), 'per order', $chip($R['aov'], $R0['aov'])); ?>
  <?php $card('Average profit margin', $pct($R['margin']), 'realized ' . $pct($R['real_margin']), $chip($R['margin'], $R0['margin'], true, true)); ?>
  <?php $card('Total orders', (string)$S['orders'], $S['prepaid_orders'] . ' prepaid · ' . $S['cod_orders'] . ' COD', $chip((float)$S['orders'], (float)$S0['orders'])); ?>
  <?php $card('Average profit per order', $money($R['profit_per_order']), 'estimated', $chip($R['profit_per_order'], $R0['profit_per_order'])); ?>
  <?php $card('Total buying from dealers', $money($C['bought']), $C['bills'] . ' approved bill' . ($C['bills'] === 1 ? '' : 's'), $chip($C['bought'], $C0['bought'], null)); ?>
  <?php $card('Average customer spending', $money($R['per_customer']), $S['customers'] . ' customer' . ($S['customers'] === 1 ? '' : 's') . ($S['customers'] > 0 ? ' · ' . number_format($R['orders_per_customer'], 1) . ' orders each' : ''), $chip($R['per_customer'], $R0['per_customer'])); ?>
  <?php $card('COD received', $money($C['cod_in']), 'COD money that came in', $chip($C['cod_in'], $C0['cod_in'])); ?>
  <?php $card('COD still pending (now)', $money($snap['cod_pending']), $snap['cod_orders'] . ' order' . ($snap['cod_orders'] === 1 ? '' : 's') . ' · <a href="cod_amount.php">COD Amount</a>', '', $snap['cod_pending'] > 0.004 ? 'warn' : ''); ?>
</div>

<!-- ===== 4. trend chart + payment mix ===== -->
<div class="rp-two">
  <section class="rp-panel">
    <h3><?= count($daily) > 45 ? (count($daily) > 180 ? 'Month by month' : 'Week by week') : 'Day by day' ?></h3>
    <p class="rp-lead">Sales (grey) and estimated profit (green) for <?= e($P['label']) ?>. Tap or hover a bar for the exact figure.</p>
    <?= $barChart($dayRows, 'Sales', 'Profit') ?>
  </section>
  <section class="rp-panel">
    <h3>How customers pay</h3>
    <p class="rp-lead">Prepaid money is in hand. COD arrives after delivery.</p>
    <?php $prePct = $S['sales'] > 0 ? $S['prepaid_sales'] / $S['sales'] * 100 : 0; ?>
    <div class="rp-split" title="Prepaid <?= e(number_format($prePct, 0)) ?>%"><i style="width:<?= round($prePct, 1) ?>%"></i></div>
    <ul class="rp-list">
      <li><span>Prepaid sales</span><b><?= $money($S['prepaid_sales']) ?></b></li>
      <li><span>COD sales</span><b><?= $money($S['cod_sales']) ?></b></li>
      <li><span>COD share of sales</span><b><?= $pct($R['cod_share']) ?></b></li>
      <li><span>COD from these orders still to collect</span><b><?= $money($S['cod_due']) ?></b></li>
    </ul>
  </section>
</div>

<!-- ===== 5. today vs this month ===== -->
<div class="rp-h">Today vs this month</div>
<section class="rp-panel" style="margin:0 0 14px">
  <div class="table-wrap">
    <table class="rp-table" style="min-width:520px">
      <thead><tr><th></th><th>Today</th><th>This month so far</th><th>Daily average this month</th><th>Today vs average</th></tr></thead>
      <tbody>
      <?php
      $rowsTM = [
          ['Sales', $T['sales'], $M['sales'], true],
          ['Orders', (float)$T['orders'], (float)$M['orders'], false],
          ['Estimated profit', $T['est_profit'], $M['est_profit'], true],
          ['Cash received', $TC['net_cash'], $MC['net_cash'], true],
          ['Bought from dealers', $TC['bought'], $MC['bought'], true],
      ];
      foreach ($rowsTM as [$lab, $tv, $mv, $isMoney]):
          $avg = $mv / max(1, $daysIn);
          $fmt = static fn(float $x): string => $isMoney ? e(inr($x)) : e(number_format($x, $x == floor($x) ? 0 : 1));
      ?>
        <tr>
          <td><b><?= e($lab) ?></b></td>
          <td><?= $fmt($tv) ?></td>
          <td><?= $fmt($mv) ?></td>
          <td><?= $fmt($avg) ?></td>
          <td><?= $lab === 'Bought from dealers' ? $chip($tv, $avg, null) : $chip($tv, $avg) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="rp-grid4" style="margin:14px 0 0">
    <?php $card('Avg order value (month)', $money($MR['aov'])); ?>
    <?php $card('Avg profit margin (month)', $pct($MR['margin'])); ?>
    <?php $card('COD pending now', $money($snap['cod_pending']), '', '', $snap['cod_pending'] > 0.004 ? 'warn' : ''); ?>
    <?php $card('Owed to dealers now', $money($snap['owed']), $snap['advance'] > 0.004 ? 'after ' . $money($snap['advance']) . ' advance paid' : '<a href="dealers.php">Dealers</a>', '', $snap['owed'] > 0.004 ? 'warn' : ''); ?>
  </div>
</section>

<!-- ===== 6. dealers ===== -->
<div class="rp-h">Dealers · <?= e($P['label']) ?></div>
<div class="rp-two">
  <section class="rp-panel">
    <h3>Who you buy from</h3>
    <p class="rp-lead">Approved bills in this period. Bar = share of total buying (<?= $money($dealerTotal) ?>).</p>
    <?php
    $withBuy = array_values(array_filter($dealers, static fn($d) => $d['buy'] > 0));
    if (!$withBuy) {
        echo '<div class="rp-empty">No approved dealer bills in this period.</div>';
    } else {
        $rows = [];
        foreach (array_slice($withBuy, 0, 8) as $d) {
            $rows[] = [$d['name'] . ($d['is_active'] ? '' : ' (off)'), $d['buy'], $d['bills'] . ' bill' . ($d['bills'] === 1 ? '' : 's')];
        }
        echo $shareBars($rows, $dealerTotal);
        if (count($withBuy) > 8) {
            $rest = array_sum(array_column(array_slice($withBuy, 8), 'buy'));
            echo '<div class="rp-note">+ ' . (count($withBuy) - 8) . ' more dealers · ' . $money($rest) . ' (see the table)</div>';
        }
    }
    ?>
  </section>
  <section class="rp-panel">
    <h3>Dealer money</h3>
    <p class="rp-lead">What you owe is live, not limited to the dates.</p>
    <ul class="rp-list">
      <li><span>Bought (this period)</span><b><?= $money($C['bought']) ?></b></li>
      <li><span>Paid to dealers (this period)</span><b><?= $money($C['dealer_paid']) ?></b></li>
      <li><span>You owe dealers now</span><b style="color:var(--rp-warn)"><?= $money($snap['owed']) ?></b></li>
      <?php if ($snap['advance'] > 0.004): ?><li><span>of which you have paid in advance</span><b><?= $money($snap['advance']) ?></b></li><?php endif; ?>
      <li><span>Money across your accounts</span><b><?= $money($snap['accounts']) ?></b></li>
    </ul>
  </section>
</div>
<?php if ($dealers): ?>
<section class="rp-panel" style="margin:0 0 14px">
  <h3>Dealer-wise buying</h3>
  <div class="table-wrap">
    <table class="rp-table">
      <thead><tr><th>Dealer</th><th>Bills</th><th>Bought</th><th>Share</th><th>Paid</th><th>Outstanding now</th></tr></thead>
      <tbody>
      <?php foreach ($dealers as $d): $b = $d['balance']; ?>
        <tr>
          <td><a href="party_ledger.php?id=<?= (int)$d['id'] ?>"><?= e($d['name']) ?></a><?= $d['is_active'] ? '' : ' <small>(off)</small>' ?></td>
          <td><?= (int)$d['bills'] ?></td>
          <td><?= $money($d['buy']) ?></td>
          <td><?= $dealerTotal > 0 ? e(number_format($d['buy'] / $dealerTotal * 100, 0)) . '%' : '-' ?></td>
          <td><?= $d['paid'] > 0 ? $money($d['paid']) : '-' ?></td>
          <td><?= $b > 0.004 ? '<span class="tag tag-warn">' . $money($b) . '</span>' : ($b < -0.004 ? '<span class="tag tag-ok">Advance ' . $money(-$b) . '</span>' : '-') ?></td>
        </tr>
      <?php endforeach; ?>
        <tr class="me"><td>Total</td><td><?= (int)$C['bills'] ?></td><td><?= $money($dealerTotal) ?></td><td><?= $dealerTotal > 0 ? '100%' : '-' ?></td><td><?= $money(array_sum(array_column($dealers, 'paid'))) ?></td><td><?= $money($snap['owed']) ?></td></tr>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<!-- ===== 7. COD tied up ===== -->
<div class="rp-h">Money tied up in COD</div>
<section class="rp-panel" style="margin:0 0 14px">
  <div style="display:flex;flex-wrap:wrap;gap:10px 28px;align-items:baseline">
    <div><div class="rp-k">COD still to receive (all orders, now)</div><div class="rp-v" style="color:var(--rp-warn)"><?= $money($snap['cod_pending']) ?></div></div>
    <div class="rp-sub" style="font-size:13px"><span><?= (int)$snap['cod_orders'] ?> order<?= $snap['cod_orders'] === 1 ? '' : 's' ?> waiting · <a href="orders.php?mode=cod_due">open the list</a></span></div>
  </div>
  <div class="rp-aging">
    <?php $i = 0; foreach ($snap['aging'] as $lab => $v): $i++; ?>
      <div class="<?= $i >= 3 && $v > 0.004 ? 'old' : '' ?>"><span><?= e($lab) ?> old</span><b><?= $money($v) ?></b></div>
    <?php endforeach; ?>
  </div>
  <p class="rp-note">Age = days since the order was made. Money older than 15 days is highlighted: that is the COD most worth chasing. Lump COD remittances not matched to an order are taken off the oldest money first.</p>
</section>

<!-- ===== 8. months compared ===== -->
<div class="rp-h">Month by month</div>
<div class="rp-two">
  <section class="rp-panel">
    <h3>Last 6 months</h3>
    <p class="rp-lead">Sales (grey) and estimated profit (green). The latest bar is the month so far.</p>
    <?= $barChart($monRows, 'Sales', 'Profit') ?>
  </section>
  <section class="rp-panel">
    <h3>This month vs last month</h3>
    <?php
    $n = count($months);
    $cm = $n ? $months[$n - 1] : null;
    $pm = $n > 1 ? $months[$n - 2] : null;
    ?>
    <?php if ($cm && $pm): ?>
    <p class="rp-lead"><?= e($cm['label']) ?> so far (<?= (int)$daysIn ?> days) against the whole of <?= e($pm['label']) ?>.</p>
    <ul class="rp-list">
      <li><span>Sales</span><b><?= $money($cm['sales']) ?> <small style="color:var(--muted)">vs <?= $money($pm['sales']) ?></small></b></li>
      <li><span>Estimated profit</span><b><?= $money($cm['profit']) ?> <small style="color:var(--muted)">vs <?= $money($pm['profit']) ?></small></b></li>
      <li><span>Margin</span><b><?= $pct($cm['margin']) ?> <small style="color:var(--muted)">vs <?= $pct($pm['margin']) ?></small></b></li>
      <li><span>Avg order value</span><b><?= $money($cm['aov']) ?> <small style="color:var(--muted)">vs <?= $money($pm['aov']) ?></small></b></li>
      <li><span>Buying</span><b><?= $money($cm['buying']) ?> <small style="color:var(--muted)">vs <?= $money($pm['buying']) ?></small></b></li>
    </ul>
    <p class="rp-note">A month that is only part-way through will look smaller than a full one. For a fair growth check, use the <a href="<?= e($link(['f' => 'month', 'from' => '', 'to' => ''])) ?>">This month</a> filter: it compares the same number of days.</p>
    <?php else: ?>
    <div class="rp-empty">Not enough months yet.</div>
    <?php endif; ?>
  </section>
</div>
<section class="rp-panel" style="margin:0 0 14px">
  <div class="table-wrap">
    <table class="rp-table">
      <thead><tr><th>Month</th><th>Orders</th><th>Sales</th><th>Avg order</th><th>Est. profit</th><th>Margin</th><th>Buying</th><th>Sales vs prior</th></tr></thead>
      <tbody>
      <?php foreach (array_reverse($months, true) as $i => $m): $prev = $months[$i - 1] ?? null; ?>
        <tr class="<?= $m['partial'] ? 'me' : '' ?>">
          <td><?= e(date('F Y', strtotime($m['from']))) ?><?= $m['partial'] ? ' <small>(so far)</small>' : '' ?></td>
          <td><?= (int)$m['orders'] ?></td>
          <td><?= $money($m['sales']) ?></td>
          <td><?= $money($m['aov']) ?></td>
          <td><?= $money($m['profit']) ?></td>
          <td><?= $pct($m['margin']) ?></td>
          <td><?= $money($m['buying']) ?></td>
          <td><?= ($prev && !$m['partial']) ? $chip($m['sales'], $prev['sales']) : '<small>-</small>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- ===== 9. more useful numbers ===== -->
<div class="rp-h">More about your business · <?= e($P['label']) ?></div>
<div class="rp-three" style="margin:0 0 14px">
  <section class="rp-panel">
    <h3>Cash in and out</h3>
    <p class="rp-lead">Real money movement by the date it happened.</p>
    <ul class="rp-list">
      <li><span>Prepaid money in</span><b><?= $money($C['prepaid_in']) ?></b></li>
      <li><span>COD money in</span><b><?= $money($C['cod_in']) ?></b></li>
      <li><span>Refunds paid out</span><b><?= $money($C['refund_out']) ?></b></li>
      <li><span>Paid to dealers</span><b><?= $money($C['dealer_paid']) ?></b></li>
      <li><span><b>Left after refunds and dealer payments</b></span><b class="<?= ($C['net_cash'] - $C['dealer_paid']) < 0 ? 'neg' : 'pos' ?>"><?= $money($C['net_cash'] - $C['dealer_paid']) ?></b></li>
    </ul>
  </section>
  <section class="rp-panel">
    <h3>Customers and returns</h3>
    <p class="rp-lead">Who buys, and what comes back.</p>
    <ul class="rp-list">
      <li><span>Customers</span><b><?= (int)$S['customers'] ?></b></li>
      <li><span>Came back (ordered before)</span><b><?= (int)$rep['repeat'] ?><?= $S['customers'] > 0 ? ' · ' . e(number_format($rep['repeat'] / $S['customers'] * 100, 0)) . '%' : '' ?></b></li>
      <li><span>Refunds paid</span><b><?= $money($ret['amount']) ?> <small style="color:var(--muted)">(<?= (int)$ret['orders'] ?> order<?= $ret['orders'] === 1 ? '' : 's' ?>)</small></b></li>
      <li><span>Orders fully refunded</span><b><?= (int)$ret['closed'] ?></b></li>
      <li><span>Cost of goods sold</span><b><?= $money($S['goods_cost']) ?></b></li>
    </ul>
  </section>
  <section class="rp-panel">
    <h3>Order progress</h3>
    <p class="rp-lead">Where this period's orders are now.</p>
    <ul class="rp-list">
      <?php if (!$mix): ?><li><span>No orders</span><b>-</b></li><?php endif; ?>
      <?php foreach (['created', 'dispatched', 'delivered', 'return_received', 'refunded'] as $st): if (empty($mix[$st])) { continue; } ?>
        <li><span><span class="tag <?= e(status_tag_class($st)) ?>"><?= e(status_label($st)) ?></span></span><b><?= (int)$mix[$st]['n'] ?> <small style="color:var(--muted)">· <?= $money($mix[$st]['amt']) ?></small></b></li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>
<section class="rp-panel" style="margin:0 0 14px">
  <h3>Top products by profit</h3>
  <?php if (!$top): ?>
    <div class="rp-empty">No sales in this period.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="rp-table" style="min-width:420px">
      <thead><tr><th>Product</th><th>Units</th><th>Sales</th><th>Profit</th></tr></thead>
      <tbody>
      <?php foreach ($top as $t): ?>
        <tr><td style="white-space:normal"><?= e($t['name']) ?></td><td><?= (int)$t['units'] ?></td><td><?= $money($t['sales']) ?></td><td><b><?= $money($t['profit']) ?></b></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<p class="rp-note">Every number here is read from your existing orders, COD receipts, account ledger, refunds and dealer ledgers. This page only reads data; it cannot change an order, payment or ledger. Refunded orders are left out of sales and profit. Dates follow your business day setting.</p>

</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
