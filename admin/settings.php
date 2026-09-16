<?php
/** Site settings — identity, contact, social, homepage texts, SEO */
require_once __DIR__ . '/../includes/init.php';
$a = require_admin();

$tabs = ['general' => 'General', 'contact' => 'Contact & Social', 'home' => 'Homepage Texts', 'seo' => 'SEO'];

if (is_post()) {
    verify_csrf();
    $tab = post_str('tab');
    if (!isset($tabs[$tab])) { $tab = 'general'; }

    if ($tab === 'general') {
        $keys = ['site_name', 'site_tagline', 'announcement_bar'];
        foreach ($keys as $k) { save_setting($k, post_str($k)); }
        $logo = handle_upload('site_logo', 'site');
        if ($logo) {
            delete_upload(setting('site_logo'));
            save_setting('site_logo', $logo);
        }
        $favicon = handle_upload('site_favicon', 'site');
        if ($favicon) {
            delete_upload(setting('site_favicon'));
            save_setting('site_favicon', $favicon);
        }
        flash('success', 'General settings saved.');
    } elseif ($tab === 'contact') {
        foreach (['contact_email', 'contact_phone', 'contact_address', 'footer_about',
                  'social_facebook', 'social_instagram', 'social_youtube', 'social_twitter'] as $k) {
            save_setting($k, post_str($k));
        }
        flash('success', 'Contact & social settings saved.');
    } elseif ($tab === 'home') {
        foreach (['how_works_1_title', 'how_works_1_text', 'how_works_2_title', 'how_works_2_text',
                  'how_works_3_title', 'how_works_3_text', 'stats_customers', 'stats_products',
                  'stats_distributors', 'stats_states'] as $k) {
            save_setting($k, post_str($k));
        }
        flash('success', 'Homepage texts saved.');
    } elseif ($tab === 'seo') {
        save_setting('seo_meta_title', post_str('seo_meta_title'));
        save_setting('seo_meta_desc', post_str('seo_meta_desc'));
        flash('success', 'SEO settings saved.');
    }
    redirect('settings.php?tab=' . $tab);
}

$cur = get_str('tab', 'general');
if (!isset($tabs[$cur])) { $cur = 'general'; }

$activeKey = 'settings';
$pageTitle = 'Site Settings';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card" style="max-width:840px">
    <div class="card-title">
        ⚙️ Site Settings
        <span class="right">
            <?php foreach ($tabs as $key => $label): ?>
                <a class="btn <?= $cur === $key ? 'btn-primary' : 'btn-light' ?> btn-sm" href="settings.php?tab=<?= $key ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </span>
    </div>

    <?php if ($cur === 'general'): ?>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="tab" value="general">
        <div class="form-group">
            <label>Site Name</label>
            <input class="form-control" name="site_name" value="<?= e(setting('site_name')) ?>">
        </div>
        <div class="form-group">
            <label>Tagline (shown under logo)</label>
            <input class="form-control" name="site_tagline" value="<?= e(setting('site_tagline')) ?>">
        </div>
        <div class="form-group">
            <label>Announcement Bar (top of site — empty = hidden)</label>
            <input class="form-control" name="announcement_bar" value="<?= e(setting('announcement_bar')) ?>">
        </div>
        <div class="form-grid2">
            <div class="form-group">
                <label>Logo (PNG with transparent background)</label>
                <input class="form-control" type="file" name="site_logo" accept=".jpg,.jpeg,.png,.webp">
                <img class="upload-preview" src="<?= e(upload_url(setting('site_logo')) ?: placeholder('Logo')) ?>" alt="" style="height:70px;width:auto">
            </div>
            <div class="form-group">
                <label>Favicon</label>
                <input class="form-control" type="file" name="site_favicon" accept=".png,.jpg,.ico">
                <img class="upload-preview" src="<?= e(upload_url(setting('site_favicon')) ?: placeholder('F')) ?>" alt="" style="height:48px;width:auto">
            </div>
        </div>
        <button class="btn btn-primary" type="submit">Save Settings</button>
    </form>

    <?php elseif ($cur === 'contact'): ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="tab" value="contact">
        <div class="form-grid2">
            <div class="form-group">
                <label>Contact Email</label>
                <input class="form-control" name="contact_email" value="<?= e(setting('contact_email')) ?>">
            </div>
            <div class="form-group">
                <label>Contact Phone</label>
                <input class="form-control" name="contact_phone" value="<?= e(setting('contact_phone')) ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Contact Address</label>
            <textarea class="form-control" name="contact_address"><?= e(setting('contact_address')) ?></textarea>
        </div>
        <div class="form-group">
            <label>Footer About Text</label>
            <textarea class="form-control" name="footer_about"><?= e(setting('footer_about')) ?></textarea>
        </div>
        <div class="form-grid2">
            <?php foreach (['facebook', 'instagram', 'youtube', 'twitter'] as $s): ?>
                <div class="form-group">
                    <label><?= ucfirst($s) ?> URL</label>
                    <input class="form-control" name="social_<?= $s ?>" value="<?= e(setting('social_' . $s)) ?>" placeholder="https://...">
                </div>
            <?php endforeach; ?>
        </div>
        <button class="btn btn-primary" type="submit">Save Settings</button>
    </form>

    <?php elseif ($cur === 'home'): ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="tab" value="home">
        <h4 style="font-size:14px;margin-bottom:12px">"How It Works" steps (homepage)</h4>
        <?php for ($i = 1; $i <= 3; $i++): ?>
        <div class="form-grid2">
            <div class="form-group">
                <label>Step <?= $i ?> Title</label>
                <input class="form-control" name="how_works_<?= $i ?>_title" value="<?= e(setting("how_works_{$i}_title")) ?>">
            </div>
            <div class="form-group">
                <label>Step <?= $i ?> Text</label>
                <input class="form-control" name="how_works_<?= $i ?>_text" value="<?= e(setting("how_works_{$i}_text")) ?>">
            </div>
        </div>
        <?php endfor; ?>
        <h4 style="font-size:14px;margin:14px 0 12px">Stats band numbers</h4>
        <div class="form-grid2">
            <div class="form-group">
                <label>Happy Customers</label>
                <input class="form-control" name="stats_customers" value="<?= e(setting('stats_customers')) ?>">
            </div>
            <div class="form-group">
                <label>Products</label>
                <input class="form-control" name="stats_products" value="<?= e(setting('stats_products')) ?>">
            </div>
            <div class="form-group">
                <label>Distributors</label>
                <input class="form-control" name="stats_distributors" value="<?= e(setting('stats_distributors')) ?>">
            </div>
            <div class="form-group">
                <label>States Covered</label>
                <input class="form-control" name="stats_states" value="<?= e(setting('stats_states')) ?>">
            </div>
        </div>
        <button class="btn btn-primary" type="submit">Save Settings</button>
    </form>

    <?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="tab" value="seo">
        <div class="form-group">
            <label>Default Meta Title</label>
            <input class="form-control" name="seo_meta_title" value="<?= e(setting('seo_meta_title')) ?>">
        </div>
        <div class="form-group">
            <label>Default Meta Description</label>
            <textarea class="form-control" name="seo_meta_desc"><?= e(setting('seo_meta_desc')) ?></textarea>
        </div>
        <button class="btn btn-primary" type="submit">Save Settings</button>
    </form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
