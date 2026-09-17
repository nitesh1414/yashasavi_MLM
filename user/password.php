<?php
/** Change password (user) */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

if (is_post()) {
    verify_csrf();
    $current = $_POST['current'] ?? '';
    $new = $_POST['new'] ?? '';
    $new2 = $_POST['new2'] ?? '';
    $errors = [];
    if (!password_verify($current, $u['password'])) { $errors[] = 'Current password is incorrect.'; }
    if ($e = strong_password_error($new)) { $errors[] = $e; }
    if ($new !== $new2) { $errors[] = 'New passwords do not match.'; }
    if ($errors) {
        foreach ($errors as $er) { flash('error', $er); }
    } else {
        q("UPDATE users SET password = ? WHERE id = ?",
          [password_hash($new, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), $u['id']]);
        flash('success', 'Password changed successfully.');
    }
    redirect('password.php');
}

$activeKey = 'password';
$pageTitle = 'Change Password';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card" style="max-width:520px">
    <div class="card-title">🔒 Change Password</div>
    <form method="post">
        <?= csrf_field() ?>
        <div class="form-group"><label>Current Password</label>
            <input class="form-control" type="password" name="current" required></div>
        <div class="form-group"><label>New Password</label>
            <input class="form-control" type="password" name="new" required>
            <div class="form-hint">Minimum 8 characters with letters and numbers.</div></div>
        <div class="form-group"><label>Confirm New Password</label>
            <input class="form-control" type="password" name="new2" required></div>
        <button class="btn btn-primary" type="submit">Update Password</button>
    </form>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
