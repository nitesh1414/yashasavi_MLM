<?php
/** My profile — personal, bank & KYC details */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

if (is_post()) {
    verify_csrf();
    $tab = post_str('tab');

    if ($tab === 'personal') {
        $full_name = post_str('full_name');
        $email = post_str('email');
        $mobile = post_str('mobile');
        $errors = [];
        if (strlen($full_name) < 3) { $errors[] = 'Please enter your full name.'; }
        if ($email !== '' && !is_email($email)) { $errors[] = 'Invalid email address.'; }
        if (!is_mobile($mobile)) { $errors[] = 'Invalid mobile number.'; }
        // uniqueness (excluding self)
        if ($email && q_val("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?", [$email, $u['id']])) { $errors[] = 'Email already in use.'; }
        if (q_val("SELECT COUNT(*) FROM users WHERE mobile = ? AND id != ?", [$mobile, $u['id']])) { $errors[] = 'Mobile already in use.'; }
        if ($errors) {
            foreach ($errors as $er) { flash('error', $er); }
        } else {
            q("UPDATE users SET full_name=?, email=?, mobile=?, dob=?, marital_status=?, nationality=?,
               address=?, city=?, state=?, pincode=?, nominee_name=?, nominee_relation=? WHERE id=?",
              [$full_name, $email ?: null, $mobile, post_str('dob') ?: null, post_str('marital_status'),
               post_str('nationality'), post_str('address'), post_str('city'), post_str('state'),
               post_str('pincode'), post_str('nominee_name'), post_str('nominee_relation'), $u['id']]);
            flash('success', 'Profile updated successfully.');
        }
        redirect('profile.php');
    }

    if ($tab === 'bank') {
        $acct = post_str('bank_account_no');
        $ifsc = strtoupper(post_str('bank_ifsc'));
        $pan = strtoupper(post_str('pan_no'));
        $aadhaar = post_str('aadhaar_no');
        $errors = [];
        if ($acct !== '' && !preg_match('/^[0-9]{6,20}$/', $acct)) { $errors[] = 'Account number looks invalid.'; }
        if ($acct !== '' && $acct !== post_str('bank_account_no2')) { $errors[] = 'Account numbers do not match.'; }
        if ($ifsc !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) { $errors[] = 'IFSC looks invalid.'; }
        if ($pan !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) { $errors[] = 'PAN looks invalid.'; }
        if ($aadhaar !== '' && !preg_match('/^[0-9]{12}$/', $aadhaar)) { $errors[] = 'Aadhaar must be 12 digits.'; }
        if ($errors) {
            foreach ($errors as $er) { flash('error', $er); }
        } else {
            q("UPDATE users SET bank_holder=?, bank_account_no=?, bank_ifsc=?, bank_name=?, bank_branch=?,
               aadhaar_no=?, pan_no=? WHERE id=?",
              [post_str('bank_holder'), $acct ?: null, $ifsc ?: null, post_str('bank_name'),
               post_str('bank_branch'), $aadhaar ?: null, $pan ?: null, $u['id']]);
            flash('success', 'Bank & KYC details updated.');
        }
        redirect('profile.php');
    }
}

$activeKey = 'profile';
$pageTitle = 'My Profile';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
$u = current_user(); // refreshed
?>

<div class="two-col">
    <div class="card">
        <div class="profile-grid">
            <div>
                <div class="avatar-xl"><?= e(strtoupper(substr($u['full_name'], 0, 1))) ?></div>
                <p class="text-center mt-2"><b><?= e($u['username']) ?></b><br>
                    <?= status_badge((int)$u['is_active'] ? 'active' : 'inactive') ?>
                    <?= status_badge('kyc: ' . $u['kyc_status']) ?></p>
                <p class="text-center" style="font-size:12.5px;color:var(--ink-soft)">Joined <?= dmy($u['created_at']) ?></p>
            </div>
            <div>
                <div class="card-title" style="margin-bottom:8px">👤 Personal Details</div>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tab" value="personal">
                    <div class="form-group"><label>Full Name</label>
                        <input class="form-control" name="full_name" value="<?= e($u['full_name']) ?>" required></div>
                    <div class="form-grid2">
                        <div class="form-group"><label>Email</label>
                            <input class="form-control" type="email" name="email" value="<?= e($u['email']) ?>"></div>
                        <div class="form-group"><label>Mobile</label>
                            <input class="form-control" name="mobile" value="<?= e($u['mobile']) ?>" required></div>
                    </div>
                    <div class="form-grid2">
                        <div class="form-group"><label>Date of Birth</label>
                            <input class="form-control" type="date" name="dob" value="<?= e($u['dob']) ?>"></div>
                        <div class="form-group"><label>Marital Status</label>
                            <select class="form-control" name="marital_status">
                                <option value="">Select</option>
                                <?php foreach (['Married', 'Unmarried'] as $m): ?>
                                    <option value="<?= $m ?>" <?= $u['marital_status'] === $m ? 'selected' : '' ?>><?= $m ?></option>
                                <?php endforeach; ?>
                            </select></div>
                    </div>
                    <div class="form-group"><label>Nationality</label>
                        <input class="form-control" name="nationality" value="<?= e($u['nationality']) ?>"></div>
                    <div class="form-group"><label>Address</label>
                        <input class="form-control" name="address" value="<?= e($u['address']) ?>"></div>
                    <div class="form-grid3">
                        <div class="form-group"><label>City</label>
                            <input class="form-control" name="city" value="<?= e($u['city']) ?>"></div>
                        <div class="form-group"><label>State</label>
                            <input class="form-control" name="state" value="<?= e($u['state']) ?>"></div>
                        <div class="form-group"><label>Pincode</label>
                            <input class="form-control" name="pincode" value="<?= e($u['pincode']) ?>"></div>
                    </div>
                    <div class="form-grid2">
                        <div class="form-group"><label>Nominee Name</label>
                            <input class="form-control" name="nominee_name" value="<?= e($u['nominee_name']) ?>"></div>
                        <div class="form-group"><label>Nominee Relation</label>
                            <input class="form-control" name="nominee_relation" value="<?= e($u['nominee_relation']) ?>"></div>
                    </div>
                    <button class="btn btn-primary" type="submit">Save Personal Details</button>
                </form>
            </div>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-title">🏦 Bank &amp; KYC Details</div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="tab" value="bank">
                <div class="form-group"><label>Account Holder Name</label>
                    <input class="form-control" name="bank_holder" value="<?= e($u['bank_holder']) ?>"></div>
                <div class="form-grid2">
                    <div class="form-group"><label>Account Number</label>
                        <input class="form-control" name="bank_account_no" value="<?= e($u['bank_account_no']) ?>"></div>
                    <div class="form-group"><label>Retype Account Number</label>
                        <input class="form-control" name="bank_account_no2" placeholder="(re-enter)"></div>
                </div>
                <div class="form-grid2">
                    <div class="form-group"><label>IFSC Code</label>
                        <input class="form-control" name="bank_ifsc" value="<?= e($u['bank_ifsc']) ?>"></div>
                    <div class="form-group"><label>Bank Name</label>
                        <input class="form-control" name="bank_name" value="<?= e($u['bank_name']) ?>"></div>
                </div>
                <div class="form-group"><label>Branch</label>
                    <input class="form-control" name="bank_branch" value="<?= e($u['bank_branch']) ?>"></div>
                <div class="form-grid2">
                    <div class="form-group"><label>Aadhaar No.</label>
                        <input class="form-control" name="aadhaar_no" value="<?= e($u['aadhaar_no']) ?>"></div>
                    <div class="form-group"><label>PAN No.</label>
                        <input class="form-control" name="pan_no" value="<?= e($u['pan_no']) ?>"></div>
                </div>
                <button class="btn btn-primary" type="submit">Save Bank Details</button>
            </form>
        </div>

        <div class="card">
            <div class="card-title">🪪 KYC Documents <?= status_badge($u['kyc_status']) ?></div>
            <?php if ($u['pan_image'] || $u['aadhaar_image']): ?>
            <div style="display:flex;gap:14px;flex-wrap:wrap">
                <?php if ($u['pan_image']): ?>
                <a href="<?= e(upload_url($u['pan_image'])) ?>" target="_blank" title="View full PAN card image">
                    <figure style="text-align:center;margin:0">
                        <img src="<?= e(upload_url($u['pan_image'])) ?>" alt="PAN card"
                             style="width:150px;height:96px;object-fit:cover;border-radius:8px;border:1px solid var(--line)">
                        <figcaption style="font-size:11px;color:var(--ink-soft);margin-top:4px">PAN Card</figcaption>
                    </figure>
                </a>
                <?php endif; ?>
                <?php if ($u['aadhaar_image']): ?>
                <a href="<?= e(upload_url($u['aadhaar_image'])) ?>" target="_blank" title="View full Aadhaar card image">
                    <figure style="text-align:center;margin:0">
                        <img src="<?= e(upload_url($u['aadhaar_image'])) ?>" alt="Aadhaar card"
                             style="width:150px;height:96px;object-fit:cover;border-radius:8px;border:1px solid var(--line)">
                        <figcaption style="font-size:11px;color:var(--ink-soft);margin-top:4px">Aadhaar Card</figcaption>
                    </figure>
                </a>
                <?php endif; ?>
            </div>
            <p class="form-hint">Documents uploaded at registration. Contact support to re-upload if a document is rejected.</p>
            <?php else: ?>
            <p style="font-size:13px;color:var(--ink-soft)">No KYC documents on file. Please contact support to complete your KYC.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-title">🌳 Network Position</div>
            <table class="kv-table" style="width:100%">
                <tr><td>My User ID</td><td><b><?= e($u['username']) ?></b></td></tr>
                <tr><td>Sponsor</td>
                    <td><?php $sp = $u['sponsor_id'] ? q_row("SELECT username, full_name FROM users WHERE id = ?", [$u['sponsor_id']]) : null; ?>
                        <?= $sp ? e($sp['username'] . ' — ' . $sp['full_name']) : '— (company / root)' ?></td></tr>
                <tr><td>Placement Parent</td>
                    <td><?php $pl = $u['placement_id'] ? q_row("SELECT username, full_name FROM users WHERE id = ?", [$u['placement_id']]) : null; ?>
                        <?= $pl ? e($pl['username'] . ' — ' . $pl['full_name']) : '— (root)' ?></td></tr>
                <tr><td>My Leg</td><td><?= $u['leg'] === 'L' ? badge('LEFT', 'info') : badge('RIGHT', 'warning') ?></td></tr>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
