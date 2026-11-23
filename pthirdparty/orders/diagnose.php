<?php
// Diagnose (manager only): why do orders have no Shiprocket info / why do parcels not update?
// Everything is read-only. The report has NO passwords, tokens, names or phone numbers, so it can be copied and sent as it is.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/orders_lib.php';
require __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/sr_connect_lib.php';
require_once __DIR__ . '/includes/order_tags_lib.php';
$user = require_admin();
$pdo = db();

$F = [];   // findings: [level ok|warn|bad, title, detail]
$add = static function (string $lv, string $t, string $d = '') use (&$F) { $F[] = [$lv, $t, $d]; };
$ago = static fn(int $ts) => $ts > 0 ? date('d M H:i', $ts) . ' (' . max(0, (int)round((time() - $ts) / 60)) . ' min ago)' : 'never';
$live = [];   // live test lines

try {
    sr_conn_ensure($pdo);
    srs_ensure($pdo);
} catch (Throwable $e) {
    $add('bad', 'Panel tables could not be prepared', $e->getMessage());
}
$hasSr = orders_has_sr_id($pdo);

/* ---- live test (button) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    @set_time_limit(80);
    if (!sr_enabled()) {
        $live[] = ['bad', 'No Shiprocket login is saved. Open the Shiprocket page and save the API user.'];
    } else {
        try {
            [$code, $d] = sr_http('POST', sr_base() . '/auth/login', ['email' => trim((string)sr_cfg('shiprocket_email', '')), 'password' => (string)sr_cfg('shiprocket_password', '')], null);
            if ($code < 400 && !empty($d['token'])) {
                $live[] = ['ok', 'Login: Shiprocket accepted the login (HTTP ' . $code . ').'];
                $tok = (string)$d['token'];
                $st = $pdo->query("SELECT o.id, o.tracking_id FROM orders o WHERE o.status IN ('created','dispatched') AND o.tracking_id IS NOT NULL AND o.tracking_id <> '' ORDER BY o.id DESC LIMIT 30");
                $n = 0;
                foreach ($st->fetchAll() as $o) {
                    $awbs = sr_awbs_of((string)$o['tracking_id']);
                    if (!$awbs) {
                        continue;
                    }
                    [$c2, $d2] = sr_http('GET', sr_base() . '/courier/track/awb/' . rawurlencode($awbs[0]), null, $tok);
                    $td = $d2['tracking_data'] ?? [];
                    $cs = (string)($td['shipment_track'][0]['current_status'] ?? $td['track_status'] ?? '');
                    $msg = $c2 >= 400 || !$d2 ? sr_error_text($d2, $c2) : ($cs !== '' ? 'status "' . $cs . '"' : 'answered, but no status inside (Shiprocket has no tracking for this AWB yet: ' . mb_substr((string)($td['error'] ?? 'no tracking_data'), 0, 120) . ')');
                    $live[] = [$c2 < 400 && $cs !== '' ? 'ok' : 'warn', 'Order #' . (int)$o['id'] . ', AWB ' . $awbs[0] . ': HTTP ' . $c2 . ', ' . $msg];
                    if (++$n >= 5) {
                        break;
                    }
                }
                if ($n === 0) {
                    $live[] = ['warn', 'No open order with a usable tracking ID was found to test.'];
                }
                [$c3, $d3] = sr_http('GET', sr_base() . '/orders?per_page=1&page=1', null, $tok);
                $live[] = [$c3 < 400 ? 'ok' : 'warn', 'Orders list (HTTP ' . $c3 . '): ' . ($c3 < 400 ? 'readable, ' . (int)($d3['meta']['pagination']['total'] ?? 0) . ' orders in Shiprocket' : sr_error_text($d3, $c3))];
            } else {
                $live[] = ['bad', 'Login: Shiprocket refused (HTTP ' . $code . '): ' . sr_error_text($d, $code)];
            }
        } catch (Throwable $e) {
            $live[] = ['bad', 'Could not talk to Shiprocket: ' . $e->getMessage() . ' (the web server may block outgoing calls)'];
        }
    }
}

/* ---- 1. connection and sync settings ---- */
$src = sr_login_source();
if ($src === '') {
    $add('bad', 'No Shiprocket login saved', 'The panel cannot ask Shiprocket anything. Open the Shiprocket page and save the API user.');
} else {
    $okAt = (int)(sr_conn_get($pdo, 'login_ok_at') ?? 0);
    $add($okAt > 0 ? 'ok' : 'warn', 'Login saved (' . $src . ')', 'Last successful connection: ' . $ago($okAt) . ($okAt > 0 ? '' : '. Press "Test the connection" on the Shiprocket page or the live test below.'));
}
if (!function_exists('curl_init')) {
    $add('bad', 'The server has no cURL', 'Without cURL the panel cannot call Shiprocket at all.');
}
$autoOn = sr_conn_get($pdo, 'auto_on') === '1';
$last = (int)(sr_conn_get($pdo, 'last_run_at') ?? 0);
$add($autoOn ? 'ok' : 'bad', $autoOn ? 'Automatic sync is ON every ' . sr_auto_interval($pdo) . ' min' : 'Automatic sync is OFF', $autoOn ? 'Last run: ' . $ago($last) . '. It runs after a panel page is opened, so it only runs while someone uses the panel (add the cron link to run it even when nobody does).' : 'Parcels will NOT update by themselves. Turn it on in the Shiprocket page.');
$runs = json_decode((string)(sr_conn_get($pdo, 'runs_log') ?? '[]'), true) ?: [];
foreach (array_slice($runs, 0, 5) as $r) {
    $bad = (int)($r['errors'] ?? 0) > 0 || ($r['error'] ?? '') !== '';
    $add($bad ? 'warn' : 'ok', 'Sync run ' . ($r['at'] ?? '') . ' (' . ($r['how'] ?? '') . '): checked ' . (int)($r['checked'] ?? 0) . ', delivered ' . (int)($r['delivered'] ?? 0) . ', errors ' . (int)($r['errors'] ?? 0), (string)($r['first_error'] ?? ''));
}
if (!$runs) {
    $add('warn', 'The sync has never run', 'Press "Sync now" on the Shiprocket page once.');
}
$hookLast = (int)(sr_conn_get($pdo, 'webhook_last_at') ?? 0);
$add($hookLast > 0 ? 'ok' : 'warn', 'Webhook: last message ' . $ago($hookLast), $hookLast > 0 ? '' : 'Shiprocket has never sent anything. The webhook is optional; the automatic sync does the same job.');

/* ---- 2. data coverage ---- */
$one = static fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
$total = $one('SELECT COUNT(*) FROM orders');
$open = $one("SELECT COUNT(*) FROM orders WHERE status IN ('created','dispatched')");
$withSr = $one('SELECT COUNT(*) FROM sr_status');
$srStatusRows = $one("SELECT COUNT(*) FROM sr_status WHERE COALESCE(status,'') <> ''");
$openTrack = $one("SELECT COUNT(*) FROM orders WHERE status IN ('created','dispatched') AND tracking_id IS NOT NULL AND tracking_id <> ''");
$openSrId = $hasSr ? $one("SELECT COUNT(*) FROM orders WHERE status IN ('created','dispatched') AND shiprocket_order_id IS NOT NULL AND shiprocket_order_id <> ''") : 0;
$openNone = $one("SELECT COUNT(*) FROM orders WHERE status IN ('created','dispatched') AND COALESCE(tracking_id,'') = ''" . ($hasSr ? " AND COALESCE(shiprocket_order_id,'') = ''" : ''));
$polled = $one("SELECT COUNT(*) FROM sr_poll p JOIN orders o ON o.id = p.order_id WHERE o.status IN ('created','dispatched')");
$sci = $one("SELECT COUNT(*) FROM orders WHERE tracking_id REGEXP '[0-9][.,]?[0-9]*[Ee][+]?[0-9]+'");
$add('ok', "Orders: $total in the panel, $open open (created / dispatched)", "$withSr have some Shiprocket information saved ($srStatusRows with a parcel status).");
$add($openTrack === $open ? 'ok' : ($openTrack ? 'warn' : 'bad'), "Open orders with a tracking ID (AWB): $openTrack of $open", 'The automatic sync can only follow orders that have an AWB in the tracking field.');
if ($hasSr) {
    $add($openSrId >= $open ? 'ok' : 'warn', "Open orders with a Shiprocket Order ID typed: $openSrId of $open", 'Reports (CSV) are matched by this ID.');
} else {
    $add('bad', 'Orders have no Shiprocket Order ID column', 'Report matching by Order ID is not available.');
}
if ($openNone > 0) {
    $add('bad', "$openNone open orders have NEITHER a tracking ID NOR a Shiprocket Order ID", 'Nothing can find them: not the sync, not a report. Type the Shiprocket Order ID on each order (list below).');
}
if ($sci > 0) {
    $add('bad', "$sci orders have a damaged tracking ID (like 1.9042E+13)", 'Spreadsheet programs ruined the number. Use the real AWB from Shiprocket.');
}
if ($open > 0) {
    $add($polled >= $openTrack ? 'ok' : 'warn', "Open orders already checked by the sync: $polled of $openTrack with AWB", $polled < $openTrack ? 'The rest have not been reached yet. Each run checks 15 (button: 25), so a big backlog needs several runs.' : '');
}

/* ---- 3. why does each open order have no Shiprocket info ---- */
$reasonCount = [];
$sample = [];
$cols = 'o.id, o.status, o.tracking_id, ' . ($hasSr ? 'o.shiprocket_order_id' : "'' AS shiprocket_order_id") . ', p.last_status AS poll_status, s.order_id AS has_sr';
$q = $pdo->query("SELECT $cols FROM orders o LEFT JOIN sr_poll p ON p.order_id = o.id LEFT JOIN sr_status s ON s.order_id = o.id WHERE o.status IN ('created','dispatched') AND (s.order_id IS NULL OR COALESCE(s.status,'') = '') ORDER BY o.id DESC");
foreach ($q->fetchAll() as $r) {
    $trk = trim((string)$r['tracking_id']);
    $sid = trim((string)$r['shiprocket_order_id']);
    if ($trk === '' && $sid === '') {
        $why = 'Nothing typed: no tracking ID and no Shiprocket Order ID';
    } elseif ($trk !== '' && preg_match('/^\d+([.,]\d+)?E\+?\d+$/i', preg_replace('/\s/', '', $trk))) {
        $why = 'Tracking ID is damaged by a spreadsheet (' . mb_substr($trk, 0, 20) . '): type the real AWB';
    } elseif ($trk !== '' && !sr_awbs_of($trk)) {
        $why = 'Tracking ID is not a valid AWB (' . mb_substr($trk, 0, 20) . ')';
    } elseif ($trk === '') {
        $why = 'Only a Shiprocket Order ID: press Sync now (it reads Shiprocket\'s order list); if it stays, that ID is not in Shiprocket or has a typo';
    } elseif ($r['poll_status'] === null) {
        $why = 'Has an AWB but the sync has not reached it yet';
    } elseif ($r['poll_status'] === '-' || $r['poll_status'] === '') {
        $why = 'Shiprocket answered but gave no status for this AWB';
    } else {
        $why = 'Checked, last answer: ' . mb_substr((string)$r['poll_status'], 0, 40);
    }
    $reasonCount[$why] = ($reasonCount[$why] ?? 0) + 1;
    if (count($sample) < 40) {
        $sample[] = [(int)$r['id'], (string)$r['status'], $trk, $sid, $why];
    }
}
arsort($reasonCount);

/* ---- plain-text report to copy ---- */
$rep = ["ORDER DESK DIAGNOSE " . date('Y-m-d H:i')];
foreach ($F as [$lv, $t, $d]) {
    $rep[] = '[' . strtoupper($lv) . '] ' . $t . ($d !== '' ? ' - ' . $d : '');
}
$rep[] = '--- why open orders have no Shiprocket info ---';
foreach ($reasonCount as $w => $n) {
    $rep[] = "$n x $w";
}
if ($sample) {
    $rep[] = '--- first ' . count($sample) . ' orders (order no | status | tracking | SR order id | reason) ---';
    foreach ($sample as $s) {
        $rep[] = '#' . implode(' | ', array_map(static fn($v) => $v === '' ? '-' : $v, $s));
    }
}
foreach ($live as [$lv, $t]) {
    $rep[] = '[LIVE ' . strtoupper($lv) . '] ' . $t;
}
$report = implode("\n", $rep);

$pageTitle = 'Diagnose';
$active = 'orders';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
$cls = ['ok' => 'tag-ok', 'warn' => 'tag-warn', 'bad' => 'tag-bad'];
?>
<div class="grid">
  <div class="wide stack">
    <section class="card">
      <h2>Live test with Shiprocket</h2>
      <p style="margin:0 0 10px">Logs in to Shiprocket and asks about up to 5 of your open orders, so we see the exact answer or error. Nothing is changed.</p>
      <form method="post"><?= csrf_field() ?><button class="btn btn-accent" type="submit">Run live test</button></form>
      <?php foreach ($live as [$lv, $t]): ?>
        <div style="margin-top:8px"><span class="tag <?= e($cls[$lv]) ?>"><?= e(strtoupper($lv)) ?></span> <?= e($t) ?></div>
      <?php endforeach; ?>
    </section>

    <section class="card">
      <h2>What the panel sees</h2>
      <?php foreach ($F as [$lv, $t, $d]): ?>
        <div style="margin:8px 0"><span class="tag <?= e($cls[$lv]) ?>"><?= e(strtoupper($lv)) ?></span> <b><?= e($t) ?></b><?php if ($d !== ''): ?><div class="mini" style="margin-left:2px"><?= e($d) ?></div><?php endif; ?></div>
      <?php endforeach; ?>
    </section>

    <section class="card">
      <h2>Why open orders show "No Shiprocket info"</h2>
      <?php if (!$reasonCount): ?><p>All open orders have a Shiprocket status.</p><?php endif; ?>
      <?php foreach ($reasonCount as $w => $n): ?><div style="margin:6px 0"><b><?= (int)$n ?></b> &times; <?= e($w) ?></div><?php endforeach; ?>
      <?php if ($sample): ?>
      <div style="overflow-x:auto;margin-top:10px"><table class="tbl"><thead><tr><th>Order</th><th>Status</th><th>Tracking</th><th>Shiprocket order ID</th><th>Reason</th></tr></thead><tbody>
        <?php foreach ($sample as $s): ?><tr><td><a href="order_view.php?id=<?= (int)$s[0] ?>">#<?= (int)$s[0] ?></a></td><td><?= e($s[1]) ?></td><td><?= e($s[2] ?: '-') ?></td><td><?= e($s[3] ?: '-') ?></td><td><?= e($s[4]) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Copy this and send it to me</h2>
      <p style="margin:0 0 8px">No passwords, names or phone numbers are inside.</p>
      <textarea id="rep" readonly rows="12" style="width:100%;font-family:ui-monospace,Consolas,monospace;font-size:12px"><?= e($report) ?></textarea>
      <button class="btn btn-ghost btn-sm" type="button" onclick="var t=document.getElementById('rep');t.select();document.execCommand('copy');this.textContent='Copied'">Copy report</button>
    </section>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
