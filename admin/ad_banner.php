<?php
/** Ad banner popup management — popup on the public website and the member dashboard. */
require_once __DIR__ . '/../includes/init.php';
$a = require_admin();

if (is_post()) {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'upload') {
        $image = handle_upload('image', 'banners');
        if ($image === null) {
            flash('error', 'Please choose a banner image file.');
        } elseif ($image === '') {
            flash('error', 'Please choose a valid banner image (max ' . MAX_UPLOAD_MB . ' MB, JPG / PNG / WEBP / GIF).');
        } else {
            $old = setting('ad_banner_image');
            if ($old && $old !== $image) {
                delete_upload($old);
            }
            save_setting('ad_banner_image', $image);
            save_setting('ad_banner_active', '1');
            flash('success', 'Ad banner saved and set LIVE — it now pops up on the website and the member dashboard.');
        }
        redirect('ad_banner.php');
    }

    if ($action === 'frequency') {
        $freq = post_str('frequency') === 'always' ? 'always' : 'first';
        save_setting('ad_banner_frequency', $freq);
        flash('success', $freq === 'always'
            ? 'Popup frequency: EVERY page refresh shows the popup.'
            : 'Popup frequency: only the FIRST page view of a visit shows the popup.');
        redirect('ad_banner.php');
    }

    if ($action === 'toggle') {
        $newState = setting('ad_banner_active') === '1' ? '0' : '1';
        save_setting('ad_banner_active', $newState);
        flash('success', $newState === '1' ? 'Ad banner popup is now LIVE.' : 'Ad banner popup is hidden.');
        redirect('ad_banner.php');
    }

    if ($action === 'remove') {
        delete_upload(setting('ad_banner_image'));
        save_setting('ad_banner_image', '');
        save_setting('ad_banner_active', '0');
        flash('success', 'Ad banner removed — no popup will be shown anywhere.');
        redirect('ad_banner.php');
    }
}

$banner = setting('ad_banner_image');
$live = $banner && setting('ad_banner_active') === '1';

$activeKey = 'ad_banner';
$pageTitle = 'Ad Banner Popup';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="two-col">
    <div class="card">
        <div class="card-title">📢 Current Ad Banner
            <span class="right"><?= $live ? badge('LIVE — popup showing', 'success') : ($banner ? badge('Hidden', 'warning') : badge('No banner yet', 'secondary')) ?></span>
        </div>
        <?php if ($banner): ?>
            <div style="text-align:center">
                <img src="<?= e(upload_url($banner)) ?>" alt="Ad banner preview"
                     style="max-width:100%;max-height:420px;border-radius:12px;border:1px solid var(--line);box-shadow:0 8px 28px rgba(0,0,0,.14)">
            </div>
            <p class="form-hint" style="margin-top:10px">This image pops up over the website and over the member
                dashboard after login. Clicking the banner (or the ✕) closes the popup and shows the normal page.</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <button class="btn <?= $live ? 'btn-outline' : 'btn-primary' ?>" type="submit">
                        <?= $live ? '🚫 Hide Popup' : '✅ Show Popup' ?>
                    </button>
                </form>
                <form method="post" class="inline-form" data-confirm="Remove the ad banner completely?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="remove">
                    <button class="btn btn-danger" type="submit">🗑 Remove Banner</button>
                </form>
            </div>
        <?php else: ?>
            <div class="empty-state"><span class="es-ico">📢</span>No ad banner uploaded yet. Upload one below — it will pop
                up on the website and on the member dashboard after login.</div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title">🔁 Popup Frequency</div>
        <?php $freq = setting('ad_banner_frequency') === 'always' ? 'always' : 'first'; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="frequency">
            <div class="form-group">
                <label>When should the popup appear?</label>
                <select class="form-control" name="frequency">
                    <option value="first" <?= $freq === 'first' ? 'selected' : '' ?>>Only the first page view of a visit (once per visit)</option>
                    <option value="always" <?= $freq === 'always' ? 'selected' : '' ?>>Every time the page is refreshed (every page load)</option>
                </select>
                <div class="form-hint"><b>First page view:</b> the popup shows once when the visitor opens the site / logs in —
                    not again while they browse. <b>Every refresh:</b> the popup shows again on every page load.</div>
            </div>
            <button class="btn btn-primary" type="submit">Save Frequency</button>
            <?php if ($freq === 'always'): ?>
                <?= badge('Current: EVERY refresh', 'warning') ?>
            <?php else: ?>
                <?= badge('Current: first view only', 'info') ?>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <div class="card-title"><?= $banner ? '⬆️ Replace Banner Image' : '⬆️ Upload Ad Banner' ?></div>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <div class="form-group">
                <label>Banner Image <span class="req">*</span></label>
                <input class="form-control" type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.gif" required>
                <div class="form-hint">JPG, PNG, WEBP or GIF · max <?= MAX_UPLOAD_MB ?> MB. Recommended: a portrait or
                    square promo flyer (about 800–1200 px wide) — it is displayed as a centered popup.</div>
            </div>
            <button class="btn btn-primary" type="submit"><?= $banner ? 'Upload &amp; Replace' : 'Upload &amp; Go Live' ?></button>
        </form>
        <p class="form-hint" style="margin-top:12px">Uploading a new image automatically makes the popup LIVE and deletes
            the old image.</p>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
