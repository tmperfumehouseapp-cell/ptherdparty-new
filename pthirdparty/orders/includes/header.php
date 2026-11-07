<?php
// Expects: $user (from require_login), $pageTitle, $active (nav key)
$isAdmin = $user['role'] === 'admin';

// ready=false items are shown as "Soon" and get switched on in later steps
$nav = [
    ['key' => 'dashboard',  'label' => 'Home',        'href' => 'index.php',      'ready' => true,  'admin' => true],
    ['key' => 'new_order',  'label' => 'New order',   'href' => 'new_order.php',  'ready' => true, 'admin' => false],
    ['key' => 'orders',     'label' => 'Orders',      'href' => 'orders.php',     'ready' => true,  'admin' => false],
    ['key' => 'tracking',   'label' => 'Tracking',    'href' => 'tracking.php',   'ready' => true,  'admin' => false],
    ['key' => 'commission', 'label' => 'Commission',  'href' => 'commission.php', 'ready' => true,  'admin' => false],
    ['key' => 'dealers',    'label' => 'Dealers',     'href' => 'dealers.php',    'ready' => true,  'admin' => true],
    ['key' => 'billing',    'label' => 'Billing',     'href' => 'billing.php',    'ready' => true,  'admin' => true],
    ['key' => 'cod_amount', 'label' => 'COD Amount',  'href' => 'cod_amount.php', 'ready' => true,  'admin' => true],
    ['key' => 'products',   'label' => 'Products',    'href' => 'products.php',   'ready' => true,  'admin' => true],
    ['key' => 'accounts',   'label' => 'Accounts',    'href' => 'accounts.php',   'ready' => true,  'admin' => true],
    ['key' => 'numbers',    'label' => 'Numbers',     'href' => 'numbers.php',    'ready' => true,  'admin' => true],
    ['key' => 'wa_check',   'label' => 'WhatsApp',    'href' => 'wa_admin.php',   'ready' => true,  'admin' => true],
    ['key' => 'groups',     'label' => 'Groups',      'href' => 'groups.php',     'ready' => true,  'admin' => true],
    ['key' => 'users',      'label' => 'Team',        'href' => 'users.php',      'ready' => true,  'admin' => true],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · Order Desk</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php
// the address carries the file's change time: a phone that kept an old or missing copy downloads a fresh one
$cssV = static fn(string $f): int => (int)@filemtime(dirname(__DIR__) . '/' . $f);
// a built-in design is loaded FIRST while an original design file is missing; originals that exist still win over it
$cssGone = [];
foreach (array_filter(['assets/style.css', !empty($extraCss) ? (string)$extraCss : null]) as $cf) {
    if (!is_file(dirname(__DIR__) . '/' . $cf)) {
        $cssGone[] = $cf;
    }
}
?>
<?php if ($cssGone): ?><link rel="stylesheet" href="assets/fallback.css?v=<?= $cssV('assets/fallback.css') ?>">
<?php endif; ?>
<?php if (!in_array('assets/style.css', $cssGone, true)): ?><link rel="stylesheet" href="assets/style.css?v=<?= $cssV('assets/style.css') ?>">
<?php endif; ?>
<?php if (!empty($extraCss) && !in_array((string)$extraCss, $cssGone, true)): ?><link rel="stylesheet" href="<?= e($extraCss) ?>?v=<?= $cssV((string)$extraCss) ?>">
<?php endif; ?>
</head>
<body>
<div class="shell">
  <nav class="side" aria-label="Main">
    <div class="brand"><span class="logo">OD</span><span class="brand-name">Order Desk</span></div>
    <?php foreach ($nav as $n): ?>
      <?php if ($n['admin'] && !$isAdmin) continue; ?>
      <?php if ($n['ready']): ?>
        <a class="nav-item<?= $active === $n['key'] ? ' is-active' : '' ?>" href="<?= e($n['href']) ?>"><?= e($n['label']) ?></a>
      <?php else: ?>
        <span class="nav-item is-soon"><?= e($n['label']) ?><small>Soon</small></span>
      <?php endif; ?>
    <?php endforeach; ?>
    <div class="side-foot">
      <div class="who"><span class="avatar"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
        <span><?= e($user['name']) ?><small><?= e($user['role']) ?></small></span></div>
      <a class="nav-item" href="logout.php">Log out</a>
    </div>
  </nav>
  <main class="main">
    <header class="page-head">
      <h1><?= e($pageTitle) ?></h1>
    </header>
    <?php foreach (flash_pull() as $f): ?>
      <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
    <?php
      // weekly reminder: group leaves must be entered each week (admin only, hidden on the Groups page itself)
      if ($isAdmin && $active !== 'groups') {
          require_once __DIR__ . '/ops_lib.php';
          if (groups_report_due(db())) {
              echo '<a href="groups.php" style="display:block;text-decoration:none;background:#FEF3C7;border:1px solid #FCD34D;color:#92400E;border-radius:14px;padding:12px 16px;margin-bottom:16px;font-weight:700">'
                 . 'Weekly group report is due. Add this week\'s members who left. Tap to open Groups.</a>';
          }
      }
    ?>