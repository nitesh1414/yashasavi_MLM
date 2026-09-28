<?php
/** Super admin account — profile + password */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

if (is_post()) {
    verify_csrf();
    $tab = post_str('tab');
    if ($tab === 'profile') {
        $name = post_str('name');
        $email = post_str('email');
        if (strlen($name) < 2) {
            flash('error', 'Name is required.');
        } elseif ($email !== '' && !is_email($email)) {
            flash('error', 'Invalid email.');
        } else {
            q("UPDATE admins SET name=?, email=? WHERE id=?", [$name, $email ?: null, $a['id']]);
            flash('success', 'Profile updated.');
        }
    } elseif ($tab === 'password') {
        $current = $_POST['current'] ?? '';
        $new = $_POST['new'] ?? '';
        $new2 = $_POST['new2'] ?? '';
        $errors = [];
        if (!password_verify($current, $a['password'])) { $errors[] = 'Current password is incorrect.'; }
        if ($e = strong_password_error($new)) { $errors[] = $e; }
        if ($new !== $new2) { $errors[] = 'New passwords do not match.'; }
        if ($errors) {
            foreach ($errors as $er) { flash('error', $er); }
        } else {
            q("UPDATE admins SET password=? WHERE id=?",
              [password_hash($new, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), $a['id']]);
            flash('success', 'Password changed successfully.');
        }
    }
    redirect('profile.php');
}

$activeKey = 'profile';
$pageTitle = 'My Account';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
$a = current_admin('superadmin');
?>

<div class="two-col">
    <div class="card">
        <div class="card-title">👑 My Profile</div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="tab" value="profile">
            <div class="form-group">
                <label>Name</label>
                <input class="form-control" name="name" value="<?= e($a['name']) ?>" required>
            </div>
            <div class="form-group">
                <label>Username</label>
                <input class="form-control" value="<?= e($a['username']) ?>" disabled>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input class="form-control" type="email" name="email" value="<?= e($a['email']) ?>">
            </div>
            <div class="form-group">
                <label>Last Login</label>
                <input class="form-control" value="<?= dmy($a['last_login'], true) ?>" disabled>
            </div>
            <button class="btn btn-primary" type="submit">Save Profile</button>
        </form>
    </div>

    <div class="card">
        <div class="card-title">🔒 Change Password</div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="tab" value="password">
            <div class="form-group">
                <label>Current Password</label>
                <input class="form-control" type="password" name="current" required>
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input class="form-control" type="password" name="new" required>
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input class="form-control" type="password" name="new2" required>
            </div>
            <button class="btn btn-primary" type="submit">Update Password</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
