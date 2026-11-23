<?php
// Shiprocket in the panel (manager only): save the API login, connect, automatic sync, webhook, and read-only tests.
// Nothing here changes anything in Shiprocket: the panel only reads (and marks panel orders delivered).
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/orders_lib.php';
require __DIR__ . '/includes/ops_lib.php';
require_once __DIR__ . '/includes/sr_connect_lib.php';
$user = require_admin();
$pdo = db();
sr_conn_ensure($pdo);

$probe = null;     // answer of a test call
$probeTitle = '';
$probeErr = '';
$probePath = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_login') {
            sr_save_login($pdo, (string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
            $t = sr_test_login($pdo);
            flash($t['ok'] ? 'ok' : 'error', $t['ok'] ? 'Saved. ' . $t['msg'] : 'Saved, but ' . $t['msg']);
        } elseif ($action === 'connect') {
            $t = sr_test_login($pdo);
            flash($t['ok'] ? 'ok' : 'error', $t['msg']);
        } elseif ($action === 'auto') {
            sr_conn_set($pdo, 'auto_on', !empty($_POST['auto_on']) ? '1' : '0');
            $m = (int)($_POST['interval'] ?? 30);
            sr_conn_set($pdo, 'interval_min', (string)(in_array($m, [10, 15, 30, 60, 120], true) ? $m : 30));
            flash('ok', !empty($_POST['auto_on']) ? 'Automatic sync is ON, every ' . sr_auto_interval($pdo) . ' minutes.' : 'Automatic sync is OFF.');
        } elseif ($action === 'sync_now') {
            if (!sr_enabled()) {
                throw new RuntimeException('Save the Shiprocket login first.');
            }
            @set_time_limit(80);
            $r = sr_auto_run($pdo, true);
            flash($r['error'] === '' && $r['errors'] === 0 ? 'ok' : 'error',
                'Read the Shiprocket order list: ' . (int)($r['pulled'] ?? 0) . ' panel orders updated. Checked ' . $r['checked'] . ' order' . ($r['checked'] === 1 ? '' : 's') . ' in ' . round($r['ms'] / 1000, 1) . ' s: ' . $r['delivered'] . ' marked delivered'
                . ($r['errors'] ? '. ' . $r['errors'] . ' problem(s): ' . $r['first_error'] : '.'));
        } elseif ($action === 'token_new') {
            sr_conn_set($pdo, 'webhook_token', bin2hex(random_bytes(20)));
            flash('ok', 'A new webhook token was made. Put the new address in Shiprocket.');
        } elseif ($action === 'cron_new') {
            sr_conn_set($pdo, 'cron_key', bin2hex(random_bytes(20)));
            flash('ok', 'A new cron link was made.');
        } elseif ($action === 'probe' || $action === 'track') {
            if (!sr_enabled()) {
                throw new RuntimeException('Save the Shiprocket login first.');
            }
            $probePath = $action === 'track'
                ? '/courier/track/awb/' . rawurlencode(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['awb'] ?? '')))
                : trim((string)($_POST['path'] ?? ''));
            $probeTitle = $probePath;
            try {
                $probe = sr_probe_get($pdo, $probePath);
            } catch (SrError $e) {
                $probeErr = $e->getMessage();
            }
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    if ($probe === null && $probeErr === '') {
        redirect('shiprocket_settings.php');
    }
}

$source = sr_login_source();
$email = $source === 'config' ? '(set in config.php)' : (string)(sr_conn_get($pdo, 'email') ?? '');
$autoOn = sr_conn_get($pdo, 'auto_on') === '1';
$interval = sr_auto_interval($pdo);
$lastRun = (int)(sr_conn_get($pdo, 'last_run_at') ?? 0);
$loginOkAt = (int)(sr_conn_get($pdo, 'login_ok_at') ?? 0);
$runs = json_decode((string)(sr_conn_get($pdo, 'runs_log') ?? '[]'), true) ?: [];
$token = (string)(sr_conn_get($pdo, 'webhook_token') ?? '');
if ($token === '') {
    $token = bin2hex(random_bytes(20));
    sr_conn_set($pdo, 'webhook_token', $token);
}
$cronKey = (string)(sr_conn_get($pdo, 'cron_key') ?? '');
if ($cronKey === '') {
    $cronKey = bin2hex(random_bytes(20));
    sr_conn_set($pdo, 'cron_key', $cronKey);
}
$base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'your-site.com') . rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$hookUrl = $base . '/shiprocket_webhook.php?token=' . $token;
$cronUrl = $base . '/sr_sync.php?key=' . $cronKey;
$hookLast = (int)(sr_conn_get($pdo, 'webhook_last_at') ?? 0);
$hookLog = [];
try {
    $hookLog = $pdo->query('SELECT received_at, awb, status, result FROM sr_webhook_log ORDER BY id DESC LIMIT 10')->fetchAll();
} catch (Throwable $e) {
    $hookLog = [];
}
$openOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('created','dispatched') AND tracking_id IS NOT NULL AND tracking_id <> ''")->fetchColumn();
$noAwb = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('created','dispatched') AND (tracking_id IS NULL OR tracking_id = '')")->fetchColumn();
$ago = static fn(int $t): string => $t <= 0 ? 'never' : (time() - $t < 90 ? 'just now' : (time() - $t < 5400 ? round((time() - $t) / 60) . ' min ago' : date('d M H:i', $t)));

$pageTitle = 'Shiprocket';
$active = 'sr_settings';
$extraCss = 'assets/pages.css';
require __DIR__ . '/includes/header.php';
$mono = 'style="font-family:ui-monospace,Consolas,monospace;font-size:13px;word-break:break-all;background:var(--field,#F1F5F9);padding:8px 10px;border-radius:10px;display:block"';
?>
<div class="grid">
  <div class="wide stack">

    <section class="card">
      <h2>1. Connect Shiprocket</h2>
      <p style="margin:0 0 10px">
        <?php if ($source === ''): ?><span class="tag tag-warn">Not connected</span> Enter the login of a Shiprocket <b>API user</b> (Shiprocket &rarr; Settings &rarr; API &rarr; create an API user). Use this separate user, not your main login.
        <?php else: ?><span class="tag tag-ok">Login saved</span> <?= $source === 'config' ? 'from config.php' : 'in the panel (the password is stored encrypted)' ?> · last successful connection: <b><?= e($ago($loginOkAt)) ?></b>
        <?php endif; ?>
      </p>
      <form method="post" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_login">
        <div class="field" style="margin:0;flex:1 1 240px"><label for="email">API user email</label><input id="email" name="email" type="email" autocomplete="off" value="<?= e($source === 'config' ? '' : $email) ?>" placeholder="api-user@example.com"></div>
        <div class="field" style="margin:0;flex:1 1 220px"><label for="pw">API user password</label><input id="pw" name="password" type="password" autocomplete="new-password" placeholder="<?= $source === 'panel' ? 'leave empty to keep the saved one' : 'password' ?>"></div>
        <button class="btn btn-accent" type="submit">Save and connect</button>
      </form>
      <?php if ($source !== ''): ?>
      <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="connect"><button class="btn btn-ghost btn-sm" type="submit">Test the connection again</button></form>
      <?php endif; ?>
      <div class="hint">The password is never shown again. Shiprocket gives the panel a pass that lasts about 10 days and the panel renews it by itself.</div>
    </section>

    <section class="card">
      <h2>2. Automatic sync</h2>
      <p style="margin:0 0 10px">The panel asks Shiprocket where each open parcel is and marks the order <b>delivered</b> when every parcel on it is delivered.
        It checks the <b><?= (int)$openOrders ?></b> open order<?= $openOrders === 1 ? '' : 's' ?> that have a tracking ID.
        <?php if ($noAwb > 0): ?><br><span class="mini"><?= (int)$noAwb ?> open order<?= $noAwb === 1 ? ' has' : 's have' ?> no tracking ID saved, so they cannot be checked.</span><?php endif; ?></p>
      <form method="post" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
        <?= csrf_field() ?><input type="hidden" name="action" value="auto">
        <label style="display:flex;gap:8px;align-items:center;font-weight:700;margin:0"><input type="checkbox" name="auto_on" value="1"<?= $autoOn ? ' checked' : '' ?>> Automatic sync ON</label>
        <div class="field" style="margin:0"><label for="iv">Every</label>
          <select id="iv" name="interval"><?php foreach ([10 => '10 minutes', 15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour', 120 => '2 hours'] as $m => $l): ?><option value="<?= $m ?>"<?= $interval === $m ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <button class="btn" type="submit">Save</button>
      </form>
      <form method="post" style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="action" value="sync_now">
        <button class="btn btn-accent" type="submit">Sync now</button> <span class="mini">Last sync: <b><?= e($ago($lastRun)) ?></b><?= $autoOn ? ' · next one is due ' . ($lastRun > 0 ? (time() - $lastRun >= $interval * 60 ? 'at the next page you open' : 'in about ' . max(1, (int)ceil(($interval * 60 - (time() - $lastRun)) / 60)) . ' min') : 'at the next page you open') : '' ?></span></form>
      <p class="hint" style="margin-top:10px"><b>How often it really runs:</b> every <?= (int)$interval ?> minutes <i>while somebody uses the panel</i> (it runs after the page is sent, so nobody waits). To make it run every <?= (int)$interval ?> minutes even when nobody has the panel open, add a cron job in Hostinger (Advanced &rarr; Cron Jobs) that opens this link:</p>
      <code <?= $mono ?>><?= e($cronUrl) ?></code>
      <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="cron_new"><button class="btn btn-ghost btn-sm" type="submit" onclick="return confirm('Make a new cron link? The old one stops working.');">Make a new cron link</button></form>
      <?php if ($runs): ?>
      <h3 style="margin:16px 0 6px">Last syncs</h3>
      <div class="table-wrap"><table class="tight" style="min-width:480px"><thead><tr><th>When</th><th>How</th><th class="num">Checked</th><th class="num">Marked delivered</th><th class="num">Problems</th><th class="num">Took</th></tr></thead><tbody>
      <?php foreach ($runs as $r): ?><tr><td><?= e($r['at']) ?></td><td><?= e($r['how']) ?></td><td class="num"><?= (int)$r['checked'] ?></td><td class="num"><?= (int)$r['delivered'] ?></td>
        <td class="num"><?= $r['errors'] ? '<span class="tag tag-bad" title="' . e($r['first_error']) . '">' . (int)$r['errors'] . '</span>' : '0' ?></td><td class="num"><?= e(round($r['ms'] / 1000, 1)) ?> s</td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>3. Webhook (instant)</h2>
      <p style="margin:0 0 10px">With a webhook Shiprocket tells the panel by itself when a parcel changes status, so a delivered parcel is marked within seconds. In Shiprocket open its API / webhook settings (the menu names can differ) and paste this address as the webhook URL:</p>
      <code <?= $mono ?>><?= e($hookUrl) ?></code>
      <p class="hint" style="margin:8px 0">The secret token is already inside the address. If Shiprocket has a separate "token" / "x-api-key" box, paste this token there too:</p>
      <code <?= $mono ?>><?= e($token) ?></code>
      <p style="margin:10px 0 0">Last message received: <b><?= e($ago($hookLast)) ?></b></p>
      <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="token_new"><button class="btn btn-ghost btn-sm" type="submit" onclick="return confirm('Make a new token? The address in Shiprocket must be changed too.');">Make a new token</button></form>
      <?php if ($hookLog): ?>
      <h3 style="margin:16px 0 6px">Last messages from Shiprocket</h3>
      <div class="table-wrap"><table class="tight" style="min-width:480px"><thead><tr><th>When</th><th>AWB</th><th>Status</th><th>What the panel did</th></tr></thead><tbody>
      <?php foreach ($hookLog as $h): ?><tr><td><?= e($h['received_at']) ?></td><td><?= e($h['awb']) ?></td><td><?= e($h['status']) ?></td><td class="mini"><?= e($h['result']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>
  </div>

  <aside class="narrow stack">
    <section class="card">
      <h2>Test: what does Shiprocket say?</h2>
      <p class="hint" style="margin:-6px 0 10px">Read-only. It shows what your Shiprocket account returns, so we can see what can be automated (for example whether COD remittance data is available).</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="track">
        <div class="field"><label for="awb">Track one AWB</label><input id="awb" name="awb" type="text" placeholder="e.g. 78021108222"></div>
        <button class="btn btn-ghost btn-block" type="submit">Test tracking</button></form>
      <form method="post" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="probe">
        <div class="field"><label for="path">Any read-only address (GET)</label><input id="path" name="path" type="text" value="<?= e($probePath !== '' && strpos($probePath, '/courier/track') !== 0 ? $probePath : '/orders?per_page=3&page=1') ?>" placeholder="/orders?per_page=3"></div>
        <button class="btn btn-ghost btn-block" type="submit">Test this address</button>
        <div class="hint">Take the address from Shiprocket's API documentation. Starts with /. If Shiprocket answers 404 the address does not exist for your account.</div></form>
    </section>
    <?php if ($probe !== null || $probeErr !== ''): ?>
    <section class="card">
      <h2>Answer</h2>
      <div class="mini" style="margin-bottom:6px">GET <?= e($probeTitle) ?></div>
      <?php if ($probeErr !== ''): ?><div class="form-err"><?= e($probeErr) ?></div>
      <?php else: ?>
        <p style="margin:0 0 8px"><span class="tag <?= $probe['code'] < 300 ? 'tag-ok' : 'tag-bad' ?>">HTTP <?= (int)$probe['code'] ?></span> <?= e($probe['summary']) ?></p>
        <details><summary class="btn btn-ghost btn-sm" style="display:inline-flex;cursor:pointer">Show the first part of the answer</summary>
          <pre style="white-space:pre-wrap;word-break:break-word;font-size:12px;max-height:380px;overflow:auto;margin-top:8px"><?= e($probe['json']) ?></pre>
          <div class="hint">This can contain customer details. Only you (manager) see it.</div></details>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
