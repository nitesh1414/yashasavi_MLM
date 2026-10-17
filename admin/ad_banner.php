<?php
/** Ad banner popup management — the popup shown when the website is first opened. */
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
            flash('success', 'Ad banner saved and set LIVE — it now pops up when the website is first opened.');
        }
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
        flash('success', 'Ad banner removed — no popup will be shown.');
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
            <p class="form-hint" style="margin-top:10px">This image pops up over the website when a visitor opens it for the
                first time. Clicking the banner (or the ✕) closes the popup and shows the normal website.</p>
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
                up when the website is first opened.</div>
        <?php endif; ?>
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
