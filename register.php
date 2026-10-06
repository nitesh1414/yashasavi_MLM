<?php
/** Distributor registration (binary placement under a sponsor) */
require_once __DIR__ . '/includes/init.php';

// Logged-in users are normally sent to their dashboard, but they may open the
// form from a tree "empty position" link (which carries ?ref=) to sign up a
// new downline member.
if (current_user() && get_str('ref') === '') {
    redirect('user/index.php');
}

$prefill = ['sponsor' => strtoupper(get_str('ref')), 'leg' => get_str('leg', 'L')];
$errors = [];

$STATES = ['Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Delhi','Goa','Gujarat','Haryana',
    'Himachal Pradesh','Jammu & Kashmir','Jharkhand','Karnataka','Kerala','Madhya Pradesh','Maharashtra','Manipur',
    'Meghalaya','Mizoram','Nagaland','Orissa','Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura',
    'Uttar Pradesh','Uttaranchal','West Bengal'];

$f = [
    'sponsor' => '', 'leg' => 'L', 'full_name' => '', 'email' => '', 'mobile' => '', 'dob' => '',
    'marital_status' => '', 'nationality' => 'Indian', 'state' => 'Maharashtra', 'city' => '', 'pincode' => '',
    'address' => '', 'nominee_name' => '', 'nominee_relation' => '', 'bank_holder' => '', 'bank_account_no' => '',
    'bank_ifsc' => '', 'bank_name' => '', 'bank_branch' => '', 'aadhaar_no' => '', 'pan_no' => '',
];

if (is_post()) {
    verify_csrf();
    foreach ($f as $k => $v) {
        $f[$k] = post_str($k, $v);
    }
    /* no password at registration: the first-time password is the User ID itself */
    $terms = isset($_POST['terms']);

    if ($f['sponsor'] === '') { $errors[] = 'Sponsor ID is required.'; }
    if (strlen($f['full_name']) < 3) { $errors[] = 'Please enter your full name.'; }
    if ($f['email'] !== '' && !is_email($f['email'])) { $errors[] = 'Please enter a valid email address.'; }
    if (!is_mobile($f['mobile'])) { $errors[] = 'Please enter a valid 10-digit mobile number.'; }
    if (!in_array($f['state'], $STATES, true)) { $f['state'] = $STATES[15]; }
    if ($f['city'] === '') { $errors[] = 'City is required.'; }
    if ($f['pincode'] !== '' && !preg_match('/^[0-9]{6}$/', $f['pincode'])) { $errors[] = 'Pin code must be 6 digits.'; }
    if ($f['bank_account_no'] !== '' && !preg_match('/^[0-9]{6,20}$/', $f['bank_account_no'])) { $errors[] = 'Bank account number looks invalid.'; }
    if ($f['bank_ifsc'] !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', strtoupper($f['bank_ifsc']))) { $errors[] = 'IFSC code looks invalid (e.g. SBIN0001234).'; }
    if ($f['aadhaar_no'] !== '' && !preg_match('/^[0-9]{12}$/', $f['aadhaar_no'])) { $errors[] = 'Aadhaar number must be 12 digits.'; }
    if ($f['pan_no'] !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', strtoupper($f['pan_no']))) { $errors[] = 'PAN number looks invalid.'; }
    if (!$terms) { $errors[] = 'You must accept the terms and conditions.'; }

    if (!$errors) {
        $f['leg'] = ($f['leg'] === 'R') ? 'R' : 'L';
        $f['bank_ifsc'] = strtoupper($f['bank_ifsc']);
        $f['pan_no'] = strtoupper($f['pan_no']);
        $f['password'] = 'Temp-' . bin2hex(random_bytes(8)); /* replaced by the User ID below */

        // KYC document uploads (PAN card + Aadhaar card images) — OPTIONAL now;
        // members can upload them later from their panel (Upload KYC page)
        $panImg = handle_upload('pan_card', 'kyc');
        $aadImg = handle_upload('aadhaar_card', 'kyc');
        $f['pan_image'] = is_string($panImg) ? $panImg : null;
        $f['aadhaar_image'] = is_string($aadImg) ? $aadImg : null;
        if ($panImg === false || $aadImg === false) {
            if (is_string($panImg)) { delete_upload($panImg); }
            if (is_string($aadImg)) { delete_upload($aadImg); }
            $errors[] = 'An image could not be uploaded — use JPG, PNG, WEBP or GIF under ' . MAX_UPLOAD_MB . ' MB.';
        }
    }

    if (!$errors) {
        [$ok, $uid, $msg] = register_distributor($f);
        if ($ok) {
            $u = q_row("SELECT username FROM users WHERE id = ?", [$uid]);
            /* first-time password = the User ID; must be changed after first login */
            q("UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?",
              [password_hash($u['username'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), $uid]);
            flash('success', 'Registration successful! Your User ID is ' . $u['username'] .
                '. Your first-time password is your User ID (' . $u['username'] . ') — you must change it after logging in.');
            redirect('login.php');
        }
        $errors[] = $msg;
    }
}

$pageTitle = 'Register as Distributor';
require __DIR__ . '/includes/site_header.php';
?>
<div class="auth-wrap">
    <div class="auth-card wide">
        <div class="auth-logo">
            <img src="<?= e(upload_url(setting('site_logo')) ?: url('assets/img/logo.svg')) ?>" alt="logo">
            <h2 style="font-size:22px">Register Your Account</h2>
            <p style="font-size:13px;color:var(--ink-soft)">Join <?= e(setting('site_name')) ?> as a distributor</p>
        </div>
        <div class="auth-tabs">
            <a href="<?= url('login.php') ?>">Login</a>
            <a class="active" href="<?= url('register.php') ?>">Register</a>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $er): ?>• <?= e($er) ?><br><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <h3 style="font-size:15px;margin-bottom:12px;color:#000">1️⃣ Position in Network</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label>Sponsor ID <span class="req">*</span></label>
                    <input class="form-control" type="text" name="sponsor" id="sponsor" required
                           value="<?= e($f['sponsor'] ?: $prefill['sponsor']) ?>" placeholder="e.g. YSH100001">
                    <div class="form-hint" id="sponsor_name" style="font-weight:400"></div>
                </div>
                <div class="form-group">
                    <label>Placement <span class="req">*</span></label>
                    <select class="form-control" name="leg">
                        <option value="L" <?= $f['leg'] === 'L' ? 'selected' : '' ?>>LEFT</option>
                        <option value="R" <?= $f['leg'] === 'R' ? 'selected' : '' ?>>RIGHT</option>
                    </select>
                    <div class="form-hint">Your position will be placed in the first free slot of this leg under the sponsor (spillover).</div>
                </div>
            </div>

            <h3 style="font-size:15px;margin:18px 0 12px;color:#000">2️⃣ Personal Details</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label>Full Name <span class="req">*</span></label>
                    <input class="form-control" type="text" name="full_name" required value="<?= e($f['full_name']) ?>">
                </div>
                <div class="form-group">
                    <label>Mobile No. <span class="req">*</span></label>
                    <input class="form-control" type="text" name="mobile" required value="<?= e($f['mobile']) ?>" placeholder="10-digit mobile">
                </div>
                <div class="form-group">
                    <label>Email Id</label>
                    <input class="form-control" type="email" name="email" value="<?= e($f['email']) ?>">
                </div>
                <div class="form-group">
                    <label>Date of Birth</label>
                    <input class="form-control" type="date" name="dob" value="<?= e($f['dob']) ?>">
                </div>
                <div class="form-group">
                    <label>Marital Status</label>
                    <select class="form-control" name="marital_status">
                        <option value="">Select</option>
                        <option value="Married" <?= $f['marital_status'] === 'Married' ? 'selected' : '' ?>>Married</option>
                        <option value="Unmarried" <?= $f['marital_status'] === 'Unmarried' ? 'selected' : '' ?>>Unmarried</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nationality</label>
                    <input class="form-control" type="text" name="nationality" value="<?= e($f['nationality']) ?>">
                </div>
                <div class="form-group">
                    <label>State <span class="req">*</span></label>
                    <select class="form-control" name="state">
                        <?php foreach ($STATES as $s): ?>
                            <option value="<?= e($s) ?>" <?= $f['state'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>City <span class="req">*</span></label>
                    <input class="form-control" type="text" name="city" required value="<?= e($f['city']) ?>">
                </div>
                <div class="form-group">
                    <label>Pin Code</label>
                    <input class="form-control" type="text" name="pincode" value="<?= e($f['pincode']) ?>">
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <input class="form-control" type="text" name="address" value="<?= e($f['address']) ?>">
                </div>
            </div>

            <h3 style="font-size:15px;margin:18px 0 12px;color:#000">3️⃣ Nominee Details</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label>Nominee Name</label>
                    <input class="form-control" type="text" name="nominee_name" value="<?= e($f['nominee_name']) ?>">
                </div>
                <div class="form-group">
                    <label>Relation with Applicant</label>
                    <input class="form-control" type="text" name="nominee_relation" value="<?= e($f['nominee_relation']) ?>">
                </div>
            </div>

            <h3 style="font-size:15px;margin:18px 0 12px;color:#000">4️⃣ Bank Details (for payouts)</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label>Account Holder Name</label>
                    <input class="form-control" type="text" name="bank_holder" value="<?= e($f['bank_holder']) ?>">
                </div>
                <div class="form-group">
                    <label>Account Number</label>
                    <input class="form-control" type="text" name="bank_account_no" value="<?= e($f['bank_account_no']) ?>">
                </div>
                <div class="form-group">
                    <label>Bank IFSC Code</label>
                    <input class="form-control" type="text" name="bank_ifsc" value="<?= e($f['bank_ifsc']) ?>" placeholder="SBIN0001234">
                </div>
                <div class="form-group">
                    <label>Bank Name</label>
                    <input class="form-control" type="text" name="bank_name" value="<?= e($f['bank_name']) ?>">
                </div>
                <div class="form-group">
                    <label>Branch</label>
                    <input class="form-control" type="text" name="bank_branch" value="<?= e($f['bank_branch']) ?>">
                </div>
                <div class="form-group">
                    <label>Aadhaar No.</label>
                    <input class="form-control" type="text" name="aadhaar_no" value="<?= e($f['aadhaar_no']) ?>">
                </div>
                <div class="form-group">
                    <label>PAN No.</label>
                    <input class="form-control" type="text" name="pan_no" value="<?= e($f['pan_no']) ?>">
                </div>
            </div>

            <h3 style="font-size:15px;margin:18px 0 12px;color:#000">5️⃣ KYC Documents <small style="color:#666;font-weight:400">(optional — you can upload them later from your dashboard)</small></h3>
            <div class="form-grid">
                <div class="form-group">
                    <label>PAN Card Image</label>
                    <input class="form-control" type="file" name="pan_card" accept=".jpg,.jpeg,.png,.webp,.gif">
                    <div class="form-hint">Clear photo/scan of your PAN card (JPG, PNG or WEBP, max <?= MAX_UPLOAD_MB ?> MB).</div>
                </div>
                <div class="form-group">
                    <label>Aadhaar Card Image</label>
                    <input class="form-control" type="file" name="aadhaar_card" accept=".jpg,.jpeg,.png,.webp,.gif">
                    <div class="form-hint">Clear photo/scan of your Aadhaar card (JPG, PNG or WEBP, max <?= MAX_UPLOAD_MB ?> MB).</div>
                </div>
            </div>

            <div class="alert alert-warning" style="margin-top:18px">
                <b>First login:</b> your password will be your User ID (shown after registration).
                You must change it when you log in for the first time.
            </div>

            <label class="form-check" style="color:var(--ink)">
                <input type="checkbox" name="terms" required>
                <span>By clicking on register, you agree to the
                    <a href="<?= url('page.php?slug=terms-and-conditions') ?>" target="_blank"><b>terms and conditions</b></a>
                    and the <a href="<?= url('page.php?slug=privacy-policy') ?>" target="_blank"><b>privacy policy</b></a>.</span>
            </label>

            <button class="btn btn-primary btn-block" type="submit" style="margin-top:10px">🚀 Submit Registration</button>
        </form>
        <div class="auth-foot">Already have an account? <a href="<?= url('login.php') ?>"><b>Login here</b></a></div>
    </div>
</div>
<?php require __DIR__ . '/includes/site_footer.php'; ?>
