<?php
/** Staff admin accounts management (superadmin area) */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

$editId = get_int('edit');

if (is_post()) {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'save') {
        $name = post_str('name');
        $username = post_str('username');
        $email = post_str('email');
        $role = post_str('role') === 'superadmin' ? 'superadmin' : 'admin';
        $pass = $_POST['password'] ?? '';
        $errors = [];
        if (strlen($name) < 2) { $errors[] = 'Name required.'; }
        if (!preg_match('/^[a-z0-9_.]{3,30}$/i', $username)) { $errors[] = 'Username must be 3–30 chars (letters, numbers, _ or .).'; }
        if (q_val("SELECT COUNT(*) FROM admins WHERE username = ? AND id != ?", [$username, $editId])) { $errors[] = 'Username already taken.'; }
        if ($email !== '' && !is_email($email)) { $errors[] = 'Invalid email.'; }
        if (!$editId && $e = strong_password_error($pass)) { $errors[] = $e; }
        if ($editId && $pass !== '' && $e = strong_password_error($pass)) { $errors[] = $e; }
        if ($errors) {
            foreach ($errors as $er) { flash('error', $er); }
        } else {
            if ($editId) {
                q("UPDATE admins SET name=?, username=?, email=?, role=? WHERE id=?", [$name, $username, $email ?: null, $role, $editId]);
                if ($pass !== '') {
                    q("UPDATE admins SET password=? WHERE id=?", [password_hash($pass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), $editId]);
                }
                flash('success', 'Staff account updated.');
            } else {
                q("INSERT INTO admins (name, username, email, password, role, status, created_at)
                   VALUES (?, ?, ?, ?, ?, 'active', ?)",
                  [$name, $username, $email ?: null, password_hash($pass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), $role, now()]);
                flash('success', 'Staff account created.');
            }
            redirect('admins.php');
        }
    } elseif ($action === 'toggle') {
        $id = (int)post_str('id');
        $target = q_row("SELECT * FROM admins WHERE id = ?", [$id]);
        if (!$target) {
            flash('error', 'Account not found.');
        } elseif ($target['role'] === 'superadmin' && $target['status'] === 'active'
                  && (int)q_val("SELECT COUNT(*) FROM admins WHERE role='superadmin' AND status='active'") <= 1) {
            flash('error', 'Cannot block the last active super admin.');
        } else {
            $newStatus = $target['status'] === 'active' ? 'blocked' : 'active';
            q("UPDATE admins SET status = ? WHERE id = ?", [$newStatus, $id]);
            flash('success', 'Account ' . ($newStatus === 'active' ? 'enabled' : 'blocked') . '.');
        }
        redirect('admins.php');
    } elseif ($action === 'delete') {
        $id = (int)post_str('id');
        $target = q_row("SELECT * FROM admins WHERE id = ?", [$id]);
        if (!$target) {
            flash('error', 'Account not found.');
        } elseif ($target['role'] === 'superadmin') {
            flash('error', 'Super admin accounts cannot be deleted from here — edit instead.');
        } else {
            q("DELETE FROM admins WHERE id = ?", [$id]);
            flash('success', 'Staff account deleted.');
        }
        redirect('admins.php');
    }
}

$editAdm = $editId ? q_row("SELECT * FROM admins WHERE id = ?", [$editId]) : null;
$staff = q_all("SELECT * FROM admins ORDER BY role, id");

$activeKey = 'admins';
$pageTitle = 'Staff Accounts';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="two-col">
    <div class="card">
        <div class="card-title">🛠️ Staff Accounts (<?= count($staff) ?>)</div>
        <div class="table-wrap" style="box-shadow:none">
            <table class="table">
                <tr><th>Name</th><th>Username</th><th>Role</th><th>Last Login</th><th>Status</th><th>Actions</th></tr>
                <?php foreach ($staff as $s): ?>
                <tr>
                    <td><b><?= e($s['name']) ?></b><br><small style="color:#8d9c8d"><?= e($s['email'] ?: '') ?></small></td>
                    <td><?= e($s['username']) ?></td>
                    <td><?= $s['role'] === 'superadmin' ? badge('👑 Super Admin', 'primary') : badge('CMS Admin', 'info') ?></td>
                    <td><?= dmy($s['last_login'], true) ?></td>
                    <td><?= $s['status'] === 'active' ? badge('Active', 'success') : badge('Blocked', 'secondary') ?></td>
                    <td>
                        <div class="table-actions">
                            <a class="btn btn-outline btn-sm" href="admins.php?edit=<?= (int)$s['id'] ?>">Edit</a>
                            <form method="post" class="inline-form" data-confirm="Toggle this account status?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button class="btn btn-light btn-sm" type="submit"><?= $s['status'] === 'active' ? 'Block' : 'Enable' ?></button>
                            </form>
                            <?php if ($s['role'] !== 'superadmin'): ?>
                            <form method="post" class="inline-form" data-confirm="Delete this staff account?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit">Del</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <p class="form-hint">CMS Admins manage the website (pages, products, sliders). Only Super Admins control the MLM system.</p>
    </div>

    <div class="card">
        <div class="card-title"><?= $editAdm ? '✏️ Edit Account' : '➕ Add Staff Account' ?></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <div class="form-group">
                <label>Full Name <span class="req">*</span></label>
                <input class="form-control" name="name" required value="<?= e($editAdm['name'] ?? '') ?>">
            </div>
            <div class="form-grid2">
                <div class="form-group">
                    <label>Username <span class="req">*</span></label>
                    <input class="form-control" name="username" required value="<?= e($editAdm['username'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input class="form-control" type="email" name="email" value="<?= e($editAdm['email'] ?? '') ?>">
                </div>
            </div>
            <div class="form-grid2">
                <div class="form-group">
                    <label>Role</label>
                    <select class="form-control" name="role">
                        <option value="admin" <?= ($editAdm['role'] ?? '') === 'admin' ? 'selected' : '' ?>>CMS Admin (website)</option>
                        <option value="superadmin" <?= ($editAdm['role'] ?? '') === 'superadmin' ? 'selected' : '' ?>>Super Admin (full)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Password <?= $editAdm ? '(empty = keep current)' : '*' ?></label>
                    <input class="form-control" type="password" name="password" <?= $editAdm ? '' : 'required' ?>>
                </div>
            </div>
            <button class="btn btn-primary" type="submit"><?= $editAdm ? 'Update Account' : 'Create Account' ?></button>
            <?php if ($editAdm): ?><a class="btn btn-light" href="admins.php">Cancel</a><?php endif; ?>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
