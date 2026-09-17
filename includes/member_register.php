<?php
/**
 * Shared "Add Member" registration used INSIDE the panels (user dashboard
 * and super admin). This is intentionally separate from the public
 * website registration page (register.php): it renders inside the panel
 * chrome, has no terms checkbox, and enforces panel-specific placement
 * rules (a distributor may only place members inside their own downline).
 *
 * Usage in a panel page:
 *   $opts = ['area' => 'user', 'actor' => $u, 'success_url' => 'tree.php', ...];
 *   [$errors, $f] = member_register_handle($opts);
 *   ... render panel chrome ...
 *   member_register_render_form($f, $errors, $opts);
 */

function member_register_field_defaults()
{
    return [
        'sponsor' => '', 'leg' => 'L', 'full_name' => '', 'email' => '', 'mobile' => '', 'dob' => '',
        'marital_status' => '', 'nationality' => 'Indian', 'state' => 'Maharashtra', 'city' => '', 'pincode' => '',
        'address' => '', 'nominee_name' => '', 'nominee_relation' => '', 'bank_holder' => '', 'bank_account_no' => '',
        'bank_ifsc' => '', 'bank_name' => '', 'bank_branch' => '', 'aadhaar_no' => '', 'pan_no' => '',
    ];
}

function member_register_states()
{
    return ['Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Delhi','Goa','Gujarat','Haryana',
        'Himachal Pradesh','Jammu & Kashmir','Jharkhand','Karnataka','Kerala','Madhya Pradesh','Maharashtra','Manipur',
        'Meghalaya','Mizoram','Nagaland','Orissa','Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura',
        'Uttar Pradesh','Uttaranchal','West Bengal'];
}

/**
 * Validate that $sponsorRow is a legal placement parent for the actor.
 * User area: the sponsor must be the actor themselves or inside the
 * actor's downline. Super admin: any active sponsor.
 */
function member_register_sponsor_allowed($area, $actor, $sponsorRow)
{
    if (!$sponsorRow) {
        return false;
    }
    if ($area === 'superadmin') {
        return true;
    }
    return (int)$sponsorRow['id'] === (int)$actor['id']
        || strpos($sponsorRow['path'], $actor['path']) === 0;
}

/**
 * Process GET prefill + POST registration. Redirects on success.
 * Returns [$errors, $f, $prefill].
 */
function member_register_handle($opts)
{
    $errors = [];
    $f = member_register_field_defaults();
    $prefill = [
        'sponsor' => strtoupper(get_str('ref')),
        'leg' => (get_str('leg') === 'R') ? 'R' : 'L',
        'from_tree' => get_str('ref') !== '',
    ];

    if ($prefill['sponsor'] !== '') {
        $sp = find_user($prefill['sponsor']);
        if (!$sp || !member_register_sponsor_allowed($opts['area'], $opts['actor'], $sp)) {
            flash('error', 'Invalid sponsor for this position.');
            redirect($opts['success_url']);
        }
        $prefill['sponsor_name'] = $sp['full_name'];
    } else {
        $prefill['sponsor_name'] = '';
    }

    if (is_post()) {
        verify_csrf();
        foreach ($f as $k => $v) {
            $f[$k] = post_str($k, $v);
        }
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';

        $STATES = member_register_states();

        if ($f['sponsor'] === '' && $opts['area'] !== 'superadmin') { $errors[] = 'Sponsor ID is required.'; }
        if (strlen($f['full_name']) < 3) { $errors[] = 'Please enter the full name.'; }
        if ($f['email'] !== '' && !is_email($f['email'])) { $errors[] = 'Please enter a valid email address.'; }
        if (!is_mobile($f['mobile'])) { $errors[] = 'Please enter a valid 10-digit mobile number.'; }
        if (!in_array($f['state'], $STATES, true)) { $f['state'] = $STATES[15]; }
        if ($f['city'] === '') { $errors[] = 'City is required.'; }
        if ($f['pincode'] !== '' && !preg_match('/^[0-9]{6}$/', $f['pincode'])) { $errors[] = 'Pin code must be 6 digits.'; }
        if ($f['bank_account_no'] !== '' && !preg_match('/^[0-9]{6,20}$/', $f['bank_account_no'])) { $errors[] = 'Bank account number looks invalid.'; }
        if ($f['bank_ifsc'] !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', strtoupper($f['bank_ifsc']))) { $errors[] = 'IFSC code looks invalid (e.g. SBIN0001234).'; }
        if ($f['aadhaar_no'] !== '' && !preg_match('/^[0-9]{12}$/', $f['aadhaar_no'])) { $errors[] = 'Aadhaar number must be 12 digits.'; }
        if ($f['pan_no'] !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', strtoupper($f['pan_no']))) { $errors[] = 'PAN number looks invalid.'; }
        if ($e = strong_password_error($password)) { $errors[] = $e; }
        if ($password !== $password2) { $errors[] = 'Passwords do not match.'; }

        /* Super admin may add a member WITHOUT a sponsor: the member is then
         * placed under the company root (first free position of the leg). */
        if (!$errors && $opts['area'] === 'superadmin' && $f['sponsor'] === '') {
            $root = q_val("SELECT username FROM users WHERE sponsor_id IS NULL OR sponsor_id = 0 ORDER BY id ASC LIMIT 1");
            if (!$root) {
                $errors[] = 'No company root member found — please specify a sponsor.';
            } else {
                $f['sponsor'] = $root;
            }
        }

        /* placement guard (panel-specific) */
        if (!$errors) {
            $sp = find_user($f['sponsor']);
            if (!$sp) {
                $errors[] = 'Sponsor ID not found.';
            } elseif ($sp['status'] !== 'active') {
                $errors[] = 'Sponsor account is not active.';
            } elseif (!member_register_sponsor_allowed($opts['area'], $opts['actor'], $sp)) {
                $errors[] = 'You can only add members under your own network positions.';
            }
        }

        if (!$errors) {
            $f['leg'] = ($f['leg'] === 'R') ? 'R' : 'L';
            $f['bank_ifsc'] = strtoupper($f['bank_ifsc']);
            $f['pan_no'] = strtoupper($f['pan_no']);
            $f['password'] = $password;

            /* KYC document uploads (PAN card + Aadhaar card images) — required */
            $panImg = handle_upload('pan_card', 'kyc');
            if ($panImg === null) {
                $errors[] = 'Please upload the PAN card image (required for KYC).';
            } elseif ($panImg === false) {
                $errors[] = 'PAN card image could not be uploaded — use JPG, PNG, WEBP or GIF under ' . MAX_UPLOAD_MB . ' MB.';
            }
            $aadImg = handle_upload('aadhaar_card', 'kyc');
            if ($aadImg === null) {
                $errors[] = 'Please upload the Aadhaar card image (required for KYC).';
            } elseif ($aadImg === false) {
                $errors[] = 'Aadhaar card image could not be uploaded — use JPG, PNG, WEBP or GIF under ' . MAX_UPLOAD_MB . ' MB.';
            }
            if (!$errors) {
                $f['pan_image'] = $panImg;
                $f['aadhaar_image'] = $aadImg;
            } else {
                if (is_string($panImg)) { delete_upload($panImg); }
                if (is_string($aadImg)) { delete_upload($aadImg); }
            }
        }

        if (!$errors) {
            [$ok, $uid, $msg] = register_distributor($f);
            if ($ok) {
                $nu = q_row("SELECT username, full_name FROM users WHERE id = ?", [$uid]);
                flash('success', 'Member registered successfully — User ID ' . $nu['username'] . ' (' . $nu['full_name'] . ').');
                redirect($opts['success_url']);
            }
            $errors[] = $msg;
        }
    } else {
        /* Opened from the sidebar (no tree link): a distributor adds under
         * themselves and picks the leg; the super admin picks everything. */
        if ($opts['area'] === 'user' && $prefill['sponsor'] === '') {
            $prefill['sponsor'] = $opts['actor']['username'];
            $prefill['sponsor_name'] = $opts['actor']['full_name'];
        }
        $f['sponsor'] = $prefill['sponsor'];
        $f['leg'] = $prefill['leg'];
    }

    return [$errors, $f, $prefill];
}

/**
 * Render the embedded registration form (panel chrome is provided by the page).
 */
function member_register_render_form($f, $errors, $prefill, $opts)
{
    $STATES = member_register_states();
    $lockSponsor = !empty($opts['lock_sponsor']);
    $sponsorRow = $f['sponsor'] !== '' ? find_user($f['sponsor']) : null;
    ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $er): ?>• <?= e($er) ?><br><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" autocomplete="off" enctype="multipart/form-data" class="member-form">
        <?= csrf_field() ?>

        <h3 class="mf-section">1️⃣ Position in Network</h3>
        <div class="form-grid2">
            <div class="form-group">
                <label>Sponsor / Parent ID <?php if ($opts['area'] !== 'superadmin'): ?><span class="req">*</span><?php endif; ?></label>
                <?php if ($lockSponsor): ?>
                    <input class="form-control" type="text" value="<?= e($f['sponsor']) ?>" readonly tabindex="-1">
                    <input type="hidden" name="sponsor" value="<?= e($f['sponsor']) ?>">
                    <div class="form-hint" id="sponsor_name"><?= $sponsorRow ? '✓ ' . e($sponsorRow['full_name']) : '' ?></div>
                <?php else: ?>
                    <input class="form-control" type="text" name="sponsor" id="sponsor" <?= $opts['area'] === 'superadmin' ? '' : 'required' ?>
                           value="<?= e($f['sponsor']) ?>" placeholder="<?= $opts['area'] === 'superadmin' ? 'Optional — empty places under company root' : 'e.g. YSH100001' ?>" data-sponsor-api="<?= e(url('api.php')) ?>">
                    <div class="form-hint" id="sponsor_name"><?= $sponsorRow ? '✓ ' . e($sponsorRow['full_name']) : ($opts['area'] === 'superadmin' ? 'Leave empty to place the new member under the company root.' : '') ?></div>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>Placement Leg <span class="req">*</span></label>
                <?php if ($lockSponsor && !empty($prefill['from_tree'])): ?>
                    <input class="form-control" type="text" value="<?= $f['leg'] === 'R' ? 'RIGHT' : 'LEFT' ?>" readonly tabindex="-1">
                    <input type="hidden" name="leg" value="<?= e($f['leg']) ?>">
                    <div class="form-hint">Position comes from the tree link (first free slot of this leg under the sponsor).</div>
                <?php else: ?>
                    <select class="form-control" name="leg">
                        <option value="L" <?= $f['leg'] === 'L' ? 'selected' : '' ?>>LEFT</option>
                        <option value="R" <?= $f['leg'] === 'R' ? 'selected' : '' ?>>RIGHT</option>
                    </select>
                    <div class="form-hint">Placed in the first free slot of this leg under the sponsor (spillover).</div>
                <?php endif; ?>
            </div>
        </div>

        <h3 class="mf-section">2️⃣ Personal Details</h3>
        <div class="form-grid2">
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

        <h3 class="mf-section">3️⃣ Nominee Details</h3>
        <div class="form-grid2">
            <div class="form-group">
                <label>Nominee Name</label>
                <input class="form-control" type="text" name="nominee_name" value="<?= e($f['nominee_name']) ?>">
            </div>
            <div class="form-group">
                <label>Relation with Applicant</label>
                <input class="form-control" type="text" name="nominee_relation" value="<?= e($f['nominee_relation']) ?>">
            </div>
        </div>

        <h3 class="mf-section">4️⃣ Bank Details (for payouts)</h3>
        <div class="form-grid2">
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

        <h3 class="mf-section">5️⃣ KYC Documents</h3>
        <div class="form-grid2">
            <div class="form-group">
                <label>PAN Card Image <span class="req">*</span></label>
                <input class="form-control" type="file" name="pan_card" accept=".jpg,.jpeg,.png,.webp,.gif" required>
                <div class="form-hint">Clear photo/scan of the PAN card (JPG, PNG or WEBP, max <?= MAX_UPLOAD_MB ?> MB).</div>
            </div>
            <div class="form-group">
                <label>Aadhaar Card Image <span class="req">*</span></label>
                <input class="form-control" type="file" name="aadhaar_card" accept=".jpg,.jpeg,.png,.webp,.gif" required>
                <div class="form-hint">Clear photo/scan of the Aadhaar card (JPG, PNG or WEBP, max <?= MAX_UPLOAD_MB ?> MB).</div>
            </div>
        </div>

        <h3 class="mf-section">6️⃣ Login Details</h3>
        <div class="form-grid2">
            <div class="form-group">
                <label>Password <span class="req">*</span></label>
                <input class="form-control" type="password" name="password" required>
                <div class="form-hint">Minimum 8 characters with letters and numbers.</div>
            </div>
            <div class="form-group">
                <label>Confirm Password <span class="req">*</span></label>
                <input class="form-control" type="password" name="password2" required>
            </div>
        </div>

        <button class="btn btn-primary" type="submit">➕ Register Member</button>
        <a class="btn btn-light" href="<?= e($opts['back_url']) ?>">Cancel</a>
    </form>
    <?php
}
