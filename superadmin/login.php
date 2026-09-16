<?php
/** Super admin login */
require_once __DIR__ . '/../includes/init.php';

if (current_admin('superadmin')) {
    redirect('index.php');
}

$error = '';
if (is_post()) {
    verify_csrf();
    login_throttle('superadmin');
    $r = attempt_admin_login(post_str('username'), $_POST['password'] ?? '', 'superadmin');
    if ($r === true) {
        login_throttle_reset('superadmin');
        flash('success', 'Welcome back!');
        redirect('index.php');
    }
    $error = ($r === 'blocked') ? 'Account blocked.' : 'Invalid username or password.';
}

$siteName = setting('site_name', 'Yashasavi Ayurveda');
$logo = upload_url(setting('site_logo')) ?: placeholder('Logo');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Super Admin Login — <?= e($siteName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/dash.css') ?>?v=<?= APP_VERSION ?>">
</head>
<body class="dash-login-body">
<div class="dash-login">
    <div class="dl-logo">
        <img src="<?= e($logo) ?>" alt="logo">
        <h1>👑 Super Admin</h1>
        <div class="dl-sub"><?= e($siteName) ?> — MLM Management</div>
    </div>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <div class="form-group">
            <label>Username</label>
            <input class="form-control" name="username" required autofocus value="<?= e(post_str('username')) ?>">
        </div>
        <div class="form-group">
            <label>Password</label>
            <input class="form-control" type="password" name="password" required>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Login →</button>
    </form>
    <p style="text-align:center;margin-top:16px;font-size:12.5px">
        <a href="<?= url('index.php') ?>" style="color:#66735f">← Back to website</a>
    </p>
</div>
</body>
</html>
