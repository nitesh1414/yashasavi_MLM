<?php
/** Forgot password — recover access to the distributor panel.
 *  Dataflow (adapted from the reference portal's Login/Reset):
 *  1. Member enters the Member ID.
 *  2. Identity check: the full registered mobile number (+ date of birth
 *     when one is on file) must match the member's record.
 *  3. The member sets a NEW password (never displays the old one).
 *  The reset ticket lives only in the session and expires in 15 minutes. */
require_once __DIR__ . '/includes/init.php';

if (current_user()) {
    redirect('user/index.php');
}

$step = 1;          /* 1 = member id, 2 = verify identity, 3 = new password */
$error = '';
$memberId = null;
$maskedMobile = '';

/* the pending reset ticket: ['id' => user id, 'token' => secret, 'exp' => ts] */
$ticket = $_SESSION['pwreset'] ?? null;
if ($ticket && ($ticket['exp'] < time())) {
    unset($_SESSION['pwreset']);
    $ticket = null;
}

if (is_post()) {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'lookup') {
        login_throttle('forgot');
        $loginName = trim(post_str('login'));
        $u = find_user($loginName);
        if (!$u) {
            $error = 'Member ID not found. Please check and try again.';
        } else {
            $memberId = (int)$u['id'];
            $mob = trim((string)$u['mobile']);
            $maskedMobile = strlen($mob) > 4
                ? substr($mob, 0, 2) . str_repeat('*', max(0, strlen($mob) - 4)) . substr($mob, -2)
                : '****';
            $_SESSION['pwreset'] = [
                'id' => $memberId,
                'token' => bin2hex(random_bytes(16)),
                'exp' => time() + 900,
            ];
            $step = 2;
        }
    } elseif ($action === 'verify') {
        login_throttle('forgot');
        if (!$ticket) {
            $error = 'Your reset session expired. Please start again with your Member ID.';
            $step = 1;
        } else {
            $u = q_row("SELECT * FROM users WHERE id = ?", [(int)$ticket['id']]);
            $mobile = trim(post_str('mobile'));
            $dob = trim(post_str('dob'));
            if (!$u || strcasecmp($mobile, trim((string)$u['mobile'])) !== 0) {
                $memberId = (int)$ticket['id'];
                $mob = trim((string)$u['mobile']);
                $maskedMobile = strlen($mob) > 4
                    ? substr($mob, 0, 2) . str_repeat('*', max(0, strlen($mob) - 4)) . substr($mob, -2)
                    : '****';
                $error = 'The mobile number does not match this member\'s record.';
                $step = 2;
            } elseif ($u['dob'] && $dob !== '' && $dob !== date('Y-m-d', strtotime((string)$u['dob']))) {
                $memberId = (int)$ticket['id'];
                $maskedMobile = '******';
                $error = 'The date of birth does not match this member\'s record.';
                $step = 2;
            } else {
                $_SESSION['pwreset']['verified'] = true;
                $memberId = (int)$ticket['id'];
                $step = 3;
            }
        }
    } elseif ($action === 'set') {
        if (!$ticket || empty($ticket['verified'])) {
            $error = 'Your reset session expired. Please start again with your Member ID.';
            $step = 1;
        } else {
            $newPass = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            if ($e = strong_password_error($newPass)) {
                $error = $e;
                $memberId = (int)$ticket['id'];
                $step = 3;
            } elseif ($newPass !== $confirm) {
                $error = 'The two passwords do not match.';
                $memberId = (int)$ticket['id'];
                $step = 3;
            } else {
                q("UPDATE users SET password = ? WHERE id = ?",
                  [password_hash($newPass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), (int)$ticket['id']]);
                unset($_SESSION['pwreset']);
                login_throttle_reset('forgot');
                flash('success', 'Your password has been reset. Please login with your new password.');
                redirect('login.php');
            }
        }
    }
} elseif ($ticket && !empty($ticket['verified'])) {
    /* page reload in the middle of step 3 */
    $memberId = (int)$ticket['id'];
    $step = 3;
}

$pageTitle = 'Forgot Password';
require __DIR__ . '/includes/site_header.php';
?>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-logo">
            <img src="<?= e(upload_url(setting('site_logo')) ?: url('assets/img/logo.svg')) ?>" alt="logo">
            <h2 style="font-size:22px">Recover Your Password</h2>
        </div>
        <div class="auth-tabs">
            <a href="<?= url('login.php') ?>">Login</a>
            <a href="<?= url('register.php') ?>">Register</a>
        </div>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

        <?php if ($step === 1): ?>
        <p class="form-hint" style="margin-bottom:14px">
            Enter your Member ID (e.g. YSH100001) — we will verify your identity with the
            mobile number registered on the account, then you can set a new password.
        </p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="lookup">
            <div class="form-group">
                <label>Member ID</label>
                <input class="form-control" type="text" name="login" required autofocus
                       value="<?= e(post_str('login')) ?>" placeholder="e.g. YSH100001">
            </div>
            <button class="btn btn-primary btn-block" type="submit">Continue →</button>
        </form>

        <?php elseif ($step === 2): ?>
        <p class="form-hint" style="margin-bottom:14px">
            Member found. Registered mobile on file: <b><?= e($maskedMobile) ?></b>.<br>
            Enter the <b>full registered mobile number</b><?php
            $hasDob = (bool)q_val("SELECT dob FROM users WHERE id = ?", [$memberId]);
            echo $hasDob ? ' and the <b>date of birth</b> on file' : ''; ?> to confirm it is really you.
        </p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="verify">
            <div class="form-group">
                <label>Registered mobile number</label>
                <input class="form-control" type="text" name="mobile" required autofocus
                       inputmode="numeric" placeholder="10-digit mobile on file">
            </div>
            <?php if ($hasDob): ?>
            <div class="form-group">
                <label>Date of birth</label>
                <input class="form-control" type="date" name="dob" required>
            </div>
            <?php endif; ?>
            <button class="btn btn-primary btn-block" type="submit">Verify →</button>
        </form>
        <div style="text-align:center;margin-top:10px">
            <a href="<?= url('forgot.php') ?>">← start over with a different Member ID</a>
        </div>

        <?php else: ?>
        <p class="form-hint" style="margin-bottom:14px">
            Identity confirmed — choose a new password for your account.
            It must be at least 8 characters and contain both letters and numbers.
        </p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set">
            <div class="form-group">
                <label>New password</label>
                <input class="form-control" type="password" name="new_password" required autofocus placeholder="••••••••">
            </div>
            <div class="form-group">
                <label>Confirm new password</label>
                <input class="form-control" type="password" name="confirm_password" required placeholder="••••••••">
            </div>
            <button class="btn btn-primary btn-block" type="submit">Set New Password</button>
        </form>
        <?php endif; ?>

        <div class="auth-foot">
            Remembered it? <a href="<?= url('login.php') ?>"><b>Login here</b></a>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/site_footer.php'; ?>
