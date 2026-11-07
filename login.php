<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = strtolower(trim($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');

    $st = db()->prepare('SELECT id, password_hash FROM users WHERE username = ? AND is_active = 1');
    $st->execute([$username]);
    $row = $st->fetch();

    if ($row && password_verify($pass, $row['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$row['id'];
        redirect('index.php');
    }
    // no limit on tries: a wrong password just shows the message and the user can try again at once
    $error = 'Wrong username or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in · Order Desk</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php if (!is_file(__DIR__ . '/assets/style.css')): ?><link rel="stylesheet" href="assets/fallback.css?v=<?= (int)@filemtime(__DIR__ . '/assets/fallback.css') ?>">
<?php endif; ?>
<?php if (is_file(__DIR__ . '/assets/style.css')): ?><link rel="stylesheet" href="assets/style.css?v=<?= (int)@filemtime(__DIR__ . '/assets/style.css') ?>"><?php endif; ?>
</head>
<body class="auth">
<form class="auth-card" method="post">
  <div class="brand"><span class="logo">OD</span><span class="brand-name">Order Desk</span></div>
  <h1>Welcome back</h1>
  <p>Log in to create orders and track your earnings.</p>
  <?php foreach (flash_pull() as $f): ?><div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>
  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
  <?= csrf_field() ?>
  <div class="field"><label for="username">Username</label><input id="username" name="username" type="text" autocomplete="username" required autofocus></div>
  <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
  <button class="btn btn-accent btn-block" type="submit">Log in</button>
</form>
</body>
</html>