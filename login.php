<?php
/** Distributor login */
require_once __DIR__ . '/includes/init.php';

if (current_user()) {
    redirect('user/index.php');
}

$error = '';
if (is_post()) {
    verify_csrf();
    $login = post_str('login');
    $pass = $_POST['password'] ?? '';
    login_throttle('user');
    $r = attempt_user_login($login, $pass);
    if ($r === true) {
        login_throttle_reset('user');
        flash('success', 'Welcome back!');
        redirect('user/index.php');
    }
    if ($r === 'blocked') {
        $error = 'Your account has been blocked. Please contact support.';
    } else {
        $error = 'Invalid login credentials. Please try again.';
    }
}

$pageTitle = 'Distributor Login';
require __DIR__ . '/includes/site_header.php';
?>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-logo">
            <img src="<?= e(upload_url(setting('site_logo')) ?: url('assets/img/logo.svg')) ?>" alt="logo">
            <h2 style="font-size:22px">Distributor Login</h2>
        </div>
        <div class="auth-tabs">
            <a class="active" href="<?= url('login.php') ?>">Login</a>
            <a href="<?= url('register.php') ?>">Register</a>
        </div>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>User ID / Email</label>
                <input class="form-control" type="text" name="login" required autofocus value="<?= e(post_str('login')) ?>" placeholder="e.g. YSH100001">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input class="form-control" type="password" name="password" required placeholder="••••••••">
            </div>
            <button class="btn btn-primary btn-block" type="submit">Login →</button>
        </form>
        <div class="auth-foot">
            New distributor? <a href="<?= url('register.php') ?>"><b>Register here</b></a>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/site_footer.php'; ?>
