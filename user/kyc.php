<?php
/** Upload KYC documents (PAN + Aadhaar images) — distributor self-service */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

if (is_post()) {
    verify_csrf();
    $panImg = handle_upload('pan_card', 'kyc');
    $aadImg = handle_upload('aadhaar_card', 'kyc');
    $hasPan = is_string($panImg) || !empty($u['pan_image']);
    $hasAad = is_string($aadImg) || !empty($u['aadhaar_image']);

    if ($panImg === false || $aadImg === false) {
        if (is_string($panImg)) { delete_upload($panImg); }
        if (is_string($aadImg)) { delete_upload($aadImg); }
        flash('error', 'An image could not be uploaded — use JPG, PNG, WEBP or GIF under ' . MAX_UPLOAD_MB . ' MB.');
    } elseif (!is_string($panImg) && !is_string($aadImg)) {
        flash('error', 'Please choose at least one document image to upload.');
    } else {
        if (is_string($panImg)) {
            delete_upload($u['pan_image']);
            q("UPDATE users SET pan_image = ? WHERE id = ?", [$panImg, $u['id']]);
        }
        if (is_string($aadImg)) {
            delete_upload($u['aadhaar_image']);
            q("UPDATE users SET aadhaar_image = ? WHERE id = ?", [$aadImg, $u['id']]);
        }
        /* re-submitting puts the KYC back into the verification queue */
        q("UPDATE users SET kyc_status = 'pending', kyc_remark = NULL WHERE id = ?", [$u['id']]);
        flash('success', 'KYC documents uploaded — the company will verify them shortly.');
    }
    redirect('kyc.php');
}

$u = current_user(); /* refreshed row */
$activeKey = 'kyc';
$pageTitle = 'Upload KYC Documents';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card" style="max-width:720px">
    <div class="card-title">📄 Upload KYC Documents</div>

    <div class="kv-table" style="margin-bottom:16px">
        <table>
            <tr><td>KYC status</td>
                <td><?php if ($u['kyc_status'] === 'verified'): ?><b style="color:#2e7d32">✔ Verified</b>
                    <?php elseif ($u['kyc_status'] === 'rejected'): ?><b style="color:#c62828">✖ Rejected</b>
                    <?php else: ?><b style="color:#b28704">⏳ Pending verification</b><?php endif; ?></td></tr>
            <?php if ($u['kyc_remark']): ?><tr><td>Remark</td><td><?= e($u['kyc_remark']) ?></td></tr><?php endif; ?>
        </table>
    </div>

    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-grid2">
            <div class="form-group">
                <label>PAN Card Image <?= $u['pan_image'] ? '' : '<span class="req">*</span>' ?></label>
                <input class="form-control" type="file" name="pan_card" accept=".jpg,.jpeg,.png,.webp,.gif" data-preview="pan-preview">
                <div class="form-hint">Clear photo/scan of your PAN card (JPG, PNG or WEBP, max <?= MAX_UPLOAD_MB ?> MB).</div>
                <?php if ($u['pan_image']): ?>
                    <img id="pan-preview" src="<?= e(upload_url($u['pan_image'])) ?>" alt="PAN card" style="max-height:130px;border-radius:10px;margin-top:8px;border:1px solid var(--line)">
                <?php else: ?>
                    <img id="pan-preview" src="" alt="" style="display:none;max-height:130px;border-radius:10px;margin-top:8px;border:1px solid var(--line)">
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>Aadhaar Card Image <?= $u['aadhaar_image'] ? '' : '<span class="req">*</span>' ?></label>
                <input class="form-control" type="file" name="aadhaar_card" accept=".jpg,.jpeg,.png,.webp,.gif" data-preview="aad-preview">
                <div class="form-hint">Clear photo/scan of your Aadhaar card (JPG, PNG or WEBP, max <?= MAX_UPLOAD_MB ?> MB).</div>
                <?php if ($u['aadhaar_image']): ?>
                    <img id="aad-preview" src="<?= e(upload_url($u['aadhaar_image'])) ?>" alt="Aadhaar card" style="max-height:130px;border-radius:10px;margin-top:8px;border:1px solid var(--line)">
                <?php else: ?>
                    <img id="aad-preview" src="" alt="" style="display:none;max-height:130px;border-radius:10px;margin-top:8px;border:1px solid var(--line)">
                <?php endif; ?>
            </div>
        </div>
        <button class="btn btn-primary" type="submit">📤 Upload Documents</button>
        <div class="form-hint" style="margin-top:8px">Uploading new documents sends your KYC for verification again.</div>
    </form>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
