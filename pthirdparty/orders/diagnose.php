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
$act = [];    // results of the send / retry buttons
$from = (string)($_GET['from'] ?? $_POST['from'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !strtotime($from)) {
    $from = date('Y-m-d', strtotime('-7 days'));
}
$action = (string)($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'live') {
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

/* ---- 4. daily shipments: did each recent order really go to Shiprocket? ---- */
require_once __DIR__ . '/includes/shiprocket_lib.php';
$shipReady = sr_ready($pdo);
$eligibleSql = "SELECT o.id FROM orders o WHERE o.order_date >= ? AND o.status IN ('created','dispatched')
    AND COALESCE(o.tracking_id,'') = ''" . ($hasSr ? " AND COALESCE(o.shiprocket_order_id,'') = ''" : '') . "
    AND EXISTS (SELECT 1 FROM order_items oi JOIN parties pa ON pa.id = oi.party_id AND pa.auto_ship = 1 WHERE oi.order_id = o.id)
    AND NOT EXISTS (SELECT 1 FROM shipments sh WHERE sh.order_id = o.id)";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['send', 'retry'], true) && $shipReady) {
    csrf_verify();
    @set_time_limit(90);
    $only = (int)($_POST['order'] ?? 0);
    $ids = [];
    try {
        if ($action === 'send') {
            $q = $pdo->prepare($eligibleSql . ($only ? ' AND o.id = ' . $only : '') . ' ORDER BY o.id LIMIT 8');
            $q->execute([$from]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $oid) {
                $ids[] = (int)$oid;
                sr_queue_order($pdo, (int)$oid);
            }
        } else {
            $q = $pdo->prepare("SELECT sh.id, sh.order_id FROM shipments sh JOIN orders o ON o.id = sh.order_id WHERE sh.status = 'failed' AND o.order_date >= ?" . ($only ? ' AND o.id = ' . $only : '') . ' ORDER BY sh.id LIMIT 8');
            $q->execute([$from]);
            foreach ($q->fetchAll() as $r) {
                sr_requeue($pdo, (int)$r['id']);
                $ids[] = (int)$r['order_id'];
            }
        }
        $ids = array_values(array_unique($ids));
        foreach ($ids as $oid) {
            sr_process_order($pdo, $oid);
        }
        $done = 0;
        foreach ($ids as $oid) {
            $c = $pdo->prepare('SELECT status, awb, error FROM shipments WHERE order_id = ? ORDER BY id');
            $c->execute([$oid]);
            $line = [];
            foreach ($c->fetchAll() as $sh) {
                $line[] = $sh['status'] . ($sh['awb'] ? ' AWB ' . $sh['awb'] : '') . ($sh['error'] ? ' - ' . $sh['error'] : '');
                $done += $sh['status'] === 'assigned' ? 1 : 0;
            }
            $act[] = [$line && strpos(implode(' ', $line), 'failed') === false ? 'ok' : 'bad', 'Order #' . $oid . ': ' . ($line ? implode(' | ', $line) : 'nothing was queued')];
        }
        if (!$ids) {
            $act[] = ['warn', $action === 'send' ? 'No order needed sending (only orders with an auto-ship dealer, no shipment yet, and no Shiprocket Order ID / AWB typed are sent).' : 'No failed shipment found in this date range.'];
        }
    } catch (Throwable $e) {
        $act[] = ['bad', 'Stopped: ' . $e->getMessage()];
    }
}
$shipRows = [];
$shipCount = [];
$autoParties = [];
$cronKeySet = trim((string)sr_cfg('cron_key', '')) !== '';
if ($shipReady) {
    foreach ($pdo->query('SELECT status, COUNT(*) c FROM shipments GROUP BY status')->fetchAll() as $r) {
        $shipCount[$r['status']] = (int)$r['c'];
    }
    foreach ($pdo->query('SELECT name, auto_ship FROM parties WHERE is_active = 1 ORDER BY name')->fetchAll() as $r) {
        $autoParties[] = $r['name'] . ($r['auto_ship'] ? ' (auto-ship ON)' : ' (auto-ship off)');
    }
    $add($autoParties ? 'ok' : 'warn', 'Dealers: ' . count($autoParties) . ' active', implode(', ', $autoParties));
    $add($shipCount ? 'ok' : 'warn', 'Panel shipments in Shiprocket: ' . ($shipCount ? implode(', ', array_map(static fn($k, $v) => "$v $k", array_keys($shipCount), $shipCount)) : 'none ever created by the panel'), $shipCount ? '' : 'The panel has never created a shipment itself. Orders are created in Shiprocket by hand / by Shopify, and only their ID is typed here.');
    $add($cronKeySet ? 'ok' : 'warn', $cronKeySet ? 'Send-queue cron key is set' : 'No cron key for ship_run.php', $cronKeySet ? 'Hostinger cron job must open ship_run.php?key=... every few minutes to resend anything that failed or waited.' : 'Without it, a shipment that does not go out right after the order is saved is only retried when you open the order.');

    $oq = $pdo->prepare("SELECT o.id, o.order_date, o.status, o.tracking_id, " . ($hasSr ? 'o.shiprocket_order_id' : "'' AS shiprocket_order_id") . " FROM orders o WHERE o.order_date >= ? ORDER BY o.id DESC LIMIT 200");
    $oq->execute([$from]);
    $orders = $oq->fetchAll();
    $dealerOf = $shipOf = [];
    if ($orders) {
        $in = implode(',', array_map(static fn($r) => (int)$r['id'], $orders));
        foreach ($pdo->query("SELECT DISTINCT oi.order_id, pa.name, pa.auto_ship FROM order_items oi JOIN parties pa ON pa.id = oi.party_id WHERE oi.order_id IN ($in)")->fetchAll() as $r) {
            $dealerOf[(int)$r['order_id']][] = [$r['name'], (int)$r['auto_ship']];
        }
        foreach ($pdo->query("SELECT order_id, status, awb, error, attempts, next_try FROM shipments WHERE order_id IN ($in) ORDER BY id")->fetchAll() as $r) {
            $shipOf[(int)$r['order_id']][] = $r;
        }
    }
    foreach ($orders as $o) {
        $id = (int)$o['id'];
        $dl = $dealerOf[$id] ?? [];
        $auto = array_filter($dl, static fn($d) => $d[1] === 1);
        $dn = implode(', ', array_map(static fn($d) => $d[0] . ($d[1] ? ' [auto]' : ' [off]'), $dl)) ?: 'no dealer';
        $typed = trim((string)$o['shiprocket_order_id']) !== '' || trim((string)$o['tracking_id']) !== '';
        $sh = $shipOf[$id] ?? [];
        $lv = 'ok';
        $can = '';
        if ($sh) {
            $parts = [];
            foreach ($sh as $x) {
                if ($x['status'] === 'assigned') {
                    $parts[] = 'Sent to Shiprocket, AWB ' . $x['awb'];
                } elseif ($x['status'] === 'failed') {
                    $lv = 'bad';
                    $can = 'retry';
                    $parts[] = 'FAILED after ' . (int)$x['attempts'] . ' tries: ' . $x['error'];
                } elseif ($x['status'] === 'cancelled') {
                    $lv = $lv === 'bad' ? 'bad' : 'warn';
                    $parts[] = 'Cancelled in Shiprocket';
                } else {
                    $lv = $lv === 'bad' ? 'bad' : 'warn';
                    $parts[] = 'Waiting in queue (' . $x['status'] . ', ' . (int)$x['attempts'] . ' tries' . ($x['error'] ? ', last problem: ' . $x['error'] : '') . ')';
                }
            }
            $why = implode(' | ', $parts);
        } elseif (in_array($o['status'], ['delivered', 'refunded'], true)) {
            $why = 'Order is ' . $o['status'] . '; nothing to send';
        } elseif ($typed) {
            $why = 'Done by hand: ' . (trim((string)$o['shiprocket_order_id']) !== '' ? 'Shiprocket Order ID typed' : 'tracking ID typed') . ', the panel will not create a second one';
        } elseif ($auto) {
            $lv = 'bad';
            $can = 'send';
            $why = 'NEVER SENT: an auto-ship dealer is on it, but no shipment was ever queued (order made before auto-ship worked, or the queue step failed silently)';
        } else {
            $lv = 'warn';
            $why = 'Not sent: no dealer on this order has auto-ship ON (' . $dn . '), and no Shiprocket Order ID is typed';
        }
        $shipRows[] = [$id, (string)$o['order_date'], (string)$o['status'], $dn, $why, $lv, $can];
    }
}

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
if ($shipRows) {
    $rep[] = '--- orders since ' . $from . ' (order | date | status | dealers | what happened) ---';
    foreach ($shipRows as $x) {
        $rep[] = '#' . $x[0] . ' | ' . $x[1] . ' | ' . $x[2] . ' | ' . $x[3] . ' | ' . $x[4];
    }
}
foreach ($act as [$lv, $t]) {
    $rep[] = '[ACTION ' . strtoupper($lv) . '] ' . $t;
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
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="live"><button class="btn btn-accent" type="submit">Run live test</button></form>
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

    <?php if ($shipReady): ?>
    <section class="card">
      <h2>Did each order really go to Shiprocket?</h2>
      <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px">
        <div class="field" style="margin:0"><label for="from">Orders from date</label><input id="from" type="date" name="from" value="<?= e($from) ?>"></div>
        <button class="btn btn-ghost btn-sm" type="submit">Show</button>
      </form>
      <?php foreach ($act as [$lv, $t]): ?><div style="margin:6px 0"><span class="tag <?= e($cls[$lv]) ?>"><?= e(strtoupper($lv)) ?></span> <?= e($t) ?></div><?php endforeach; ?>
      <?php $nSend = count(array_filter($shipRows, static fn($x) => $x[6] === 'send')); $nRetry = count(array_filter($shipRows, static fn($x) => $x[6] === 'retry')); ?>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin:8px 0">
        <?php if ($nSend): ?><form method="post" onsubmit="return confirm('This creates REAL shipments in Shiprocket for up to 8 orders, one click at a time. Continue?');"><?= csrf_field() ?><input type="hidden" name="action" value="send"><input type="hidden" name="from" value="<?= e($from) ?>"><button class="btn btn-accent btn-sm" type="submit">Send <?= (int)$nSend ?> unsent orders to Shiprocket</button></form><?php endif; ?>
        <?php if ($nRetry): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="from" value="<?= e($from) ?>"><button class="btn btn-accent btn-sm" type="submit">Retry <?= (int)$nRetry ?> failed</button></form><?php endif; ?>
      </div>
      <div style="overflow-x:auto"><table class="tbl"><thead><tr><th>Order</th><th>Date</th><th>Status</th><th>Dealers</th><th>What happened</th><th></th></tr></thead><tbody>
        <?php foreach ($shipRows as $x): ?><tr><td><a href="order_view.php?id=<?= (int)$x[0] ?>">#<?= (int)$x[0] ?></a></td><td><?= e($x[1]) ?></td><td><?= e($x[2]) ?></td><td><?= e($x[3]) ?></td>
          <td><span class="tag <?= e($cls[$x[5]]) ?>"><?= e(strtoupper($x[5])) ?></span> <?= e($x[4]) ?></td>
          <td><?php if ($x[6] !== ''): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= e($x[6]) ?>"><input type="hidden" name="order" value="<?= (int)$x[0] ?>"><input type="hidden" name="from" value="<?= e($from) ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= $x[6] === 'send' ? 'Send' : 'Retry' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="hint">"Send" never touches an order that already has a Shiprocket Order ID or AWB typed, so nothing is created twice.</div>
    </section>
    <?php endif; ?>

    <section class="card">
      <h2>Copy this and send it to me</h2>
      <p style="margin:0 0 8px">No passwords, names or phone numbers are inside.</p>
      <textarea id="rep" readonly rows="12" style="width:100%;font-family:ui-monospace,Consolas,monospace;font-size:12px"><?= e($report) ?></textarea>
      <button class="btn btn-ghost btn-sm" type="button" onclick="var t=document.getElementById('rep');t.select();document.execCommand('copy');this.textContent='Copied'">Copy report</button>
    </section>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
