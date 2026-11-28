<?php
/** KYC document and review changes share the member row lock used by payouts. */

/** Replace uploaded document paths and invalidate verification atomically.
 * Returns the previous paths so the caller can remove replaced files only
 * after the database commit succeeds. A null path means "keep the old one". */
function kyc_replace_documents($userId, $panImage, $aadhaarImage)
{
    if (($panImage !== null && (!is_string($panImage) || $panImage === ''))
        || ($aadhaarImage !== null && (!is_string($aadhaarImage) || $aadhaarImage === ''))
        || ($panImage === null && $aadhaarImage === null)) {
        throw new InvalidArgumentException('Choose at least one valid KYC document.');
    }
    return db_tx(function () use ($userId, $panImage, $aadhaarImage) {
        $current = q_row("SELECT pan_image, aadhaar_image FROM users WHERE id = ? FOR UPDATE", [(int)$userId]);
        if (!$current) { throw new RuntimeException('Member not found.'); }
        q("UPDATE users SET pan_image = ?, aadhaar_image = ?, kyc_status = 'pending', kyc_remark = NULL WHERE id = ?",
          [$panImage ?? $current['pan_image'], $aadhaarImage ?? $current['aadhaar_image'], (int)$userId]);
        return $current;
    });
}

/** Identify the KYC details an administrator actually viewed. */
function kyc_review_snapshot(array $user)
{
    return hash('sha256', serialize([(int)$user['id'], (string)$user['kyc_status'],
        (string)$user['pan_image'], (string)$user['aadhaar_image'], (string)$user['pan_no'], (string)$user['aadhaar_no']]));
}

/** The caller must authorize the superadmin before invoking a review. */
function kyc_review_decide($userId, $action, $reviewedSnapshot, $remark = '')
{
    if (!in_array($action, ['kyc_verify', 'kyc_reject'], true)) {
        throw new InvalidArgumentException('Unknown KYC review action.');
    }
    return db_tx(function () use ($userId, $action, $reviewedSnapshot, $remark) {
        // A concurrent upload must either follow this decision and make KYC
        // pending, or precede it and make the reviewed snapshot stale.
        $current = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$userId]);
        if (!$current || !hash_equals(kyc_review_snapshot($current), (string)$reviewedSnapshot)) {
            throw new RuntimeException('KYC details changed since you opened this page. Review the current documents and try again.');
        }
        if ($action === 'kyc_verify') {
            q("UPDATE users SET kyc_status='verified', kyc_remark=NULL WHERE id=?", [(int)$userId]);
        } else {
            q("UPDATE users SET kyc_status='rejected', kyc_remark=? WHERE id=?", [$remark !== '' ? $remark : 'Rejected by admin', (int)$userId]);
        }
    });
}
