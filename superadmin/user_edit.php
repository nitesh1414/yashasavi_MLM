<?php
/** Edit distributor (super admin) */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

$id = get_int('id');
$u = q_row("SELECT * FROM users WHERE id = ?", [$id]);
if (!$u) {
    flash('error', 'User not found.');
    redirect('users.php');
}

if (is_post()) {
    verify_csrf();
    $full_name = post_str('full_name');
    $email = post_str('email');
    $mobile = post_str('mobile');
    $errors = [];
    if (strlen($full_name) < 3) { $errors[] = 'Full name required.'; }
    if ($email !== '' && !is_email($email)) { $errors[] = 'Invalid email.'; }
    if (!is_mobile($mobile)) { $errors[] = 'Invalid mobile.'; }
    if ($email && q_val("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?", [$email, $id])) { $errors[] = 'Email already in use.'; }
    if (q_val("SELECT COUNT(*) FROM users WHERE mobile = ? AND id != ?", [$mobile, $id])) { $errors[] = 'Mobile already in use.'; }
    $newPass = $_POST['new_password'] ?? '';
    if ($newPass !== '') {
        if ($e = strong_password_error($newPass)) { $errors[] = $e; }
    }
    if ($errors) {
        foreach ($errors as $er) { flash('error', $er); }
        redirect('user_edit.php?id=' . $id);
    }
    q("UPDATE users SET full_name=?, email=?, mobile=?, dob=?, marital_status=?, nationality=?,
       address=?, city=?, state=?, pincode=?, nominee_name=?, nominee_relation=?,
       bank_holder=?, bank_account_no=?, bank_ifsc=?, bank_name=?, bank_branch=?,
       aadhaar_no=?, pan_no=? WHERE id=?",
      [$full_name, $email ?: null, $mobile, post_str('dob') ?: null, post_str('marital_status'),
       post_str('nationality'), post_str('address'), post_str('city'), post_str('state'), post_str('pincode'),
       post_str('nominee_name'), post_str('nominee_relation'), post_str('bank_holder'),
       post_str('bank_account_no') ?: null, strtoupper(post_str('bank_ifsc')) ?: null,
       post_str('bank_name'), post_str('bank_branch'),
       post_str('aadhaar_no') ?: null, strtoupper(post_str('pan_no')) ?: null, $id]);
    if ($newPass !== '') {
        q("UPDATE users SET password=? WHERE id=?", [password_hash($newPass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), $id]);
    }
    flash('success', 'Distributor updated.');
    redirect('user_view.php?id=' . $id);
}

$activeKey = 'users';
$pageTitle = 'Edit User ' . $u['username'];
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card" style="max-width:900px">
    <div class="card-title">
        ✏️ Edit Distributor — <?= e($u['username']) ?>
        <a class="btn btn-light btn-sm right" href="user_view.php?id=<?= (int)$u['id'] ?>">⬅ Back to view</a>
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <div class="form-grid2">
            <div class="form-group"><label>Full Name</label>
                <input class="form-control" name="full_name" value="<?= e($u['full_name']) ?>" required></div>
            <div class="form-group"><label>New Password (leave empty to keep current)</label>
                <input class="form-control" type="password" name="new_password" placeholder="unchanged"></div>
            <div class="form-group"><label>Email</label>
                <input class="form-control" type="email" name="email" value="<?= e($u['email']) ?>"></div>
            <div class="form-group"><label>Mobile</label>
                <input class="form-control" name="mobile" value="<?= e($u['mobile']) ?>" required></div>
            <div class="form-group"><label>Date of Birth</label>
                <input class="form-control" type="date" name="dob" value="<?= e($u['dob']) ?>"></div>
            <div class="form-group"><label>Marital Status</label>
                <input class="form-control" name="marital_status" value="<?= e($u['marital_status']) ?>"></div>
            <div class="form-group"><label>Nationality</label>
                <input class="form-control" name="nationality" value="<?= e($u['nationality']) ?>"></div>
            <div class="form-group"><label>Address</label>
                <input class="form-control" name="address" value="<?= e($u['address']) ?>"></div>
            <div class="form-group"><label>City</label>
                <input class="form-control" name="city" value="<?= e($u['city']) ?>"></div>
            <div class="form-group"><label>State</label>
                <input class="form-control" name="state" value="<?= e($u['state']) ?>"></div>
            <div class="form-group"><label>Pincode</label>
                <input class="form-control" name="pincode" value="<?= e($u['pincode']) ?>"></div>
            <div class="form-group"><label>Nominee Name</label>
                <input class="form-control" name="nominee_name" value="<?= e($u['nominee_name']) ?>"></div>
            <div class="form-group"><label>Nominee Relation</label>
                <input class="form-control" name="nominee_relation" value="<?= e($u['nominee_relation']) ?>"></div>
            <div class="form-group"><label>Account Holder</label>
                <input class="form-control" name="bank_holder" value="<?= e($u['bank_holder']) ?>"></div>
            <div class="form-group"><label>Account Number</label>
                <input class="form-control" name="bank_account_no" value="<?= e($u['bank_account_no']) ?>"></div>
            <div class="form-group"><label>IFSC</label>
                <input class="form-control" name="bank_ifsc" value="<?= e($u['bank_ifsc']) ?>"></div>
            <div class="form-group"><label>Bank Name</label>
                <input class="form-control" name="bank_name" value="<?= e($u['bank_name']) ?>"></div>
            <div class="form-group"><label>Branch</label>
                <input class="form-control" name="bank_branch" value="<?= e($u['bank_branch']) ?>"></div>
            <div class="form-group"><label>Aadhaar No.</label>
                <input class="form-control" name="aadhaar_no" value="<?= e($u['aadhaar_no']) ?>"></div>
            <div class="form-group"><label>PAN No.</label>
                <input class="form-control" name="pan_no" value="<?= e($u['pan_no']) ?>"></div>
        </div>
        <button class="btn btn-primary" type="submit">💾 Save Changes</button>
        <a class="btn btn-light" href="user_view.php?id=<?= (int)$u['id'] ?>">Cancel</a>
    </form>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
