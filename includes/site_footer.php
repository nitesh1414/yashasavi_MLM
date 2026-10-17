<?php require_once __DIR__ . '/social_icons.php'; ?>
</main>

<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <div>
                <img src="<?= e(upload_url(setting('site_logo')) ?: url('assets/img/logo.png')) ?>" alt="logo">
                <p><?= e(setting('footer_about')) ?></p>
                <div class="social-links">
                    <?php foreach (['whatsapp', 'facebook', 'instagram', 'youtube', 'twitter', 'telegram'] as $sk): ?>
                        <?php if (setting('social_' . $sk)): ?>
                            <a class="soc-<?= $sk ?>" href="<?= e(setting('social_' . $sk)) ?>" target="_blank" rel="noopener"
                               aria-label="<?= e(ucfirst($sk)) ?>" title="<?= e(ucfirst($sk)) ?>"><?= social_icon_svg($sk) ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="<?= url('index.php') ?>">Home</a></li>
                    <li><a href="<?= url('products.php') ?>">Products</a></li>
                    <li><a href="<?= url('opportunity.php') ?>">Business Opportunity</a></li>
                    <li><a href="<?= url('register.php') ?>">Become a Distributor</a></li>
                    <li><a href="<?= url('login.php') ?>">Distributor Login</a></li>
                </ul>
            </div>
            <div>
                <h4>Company</h4>
                <ul>
                    <?php foreach (q_all("SELECT title, slug FROM pages WHERE status='published' ORDER BY menu_order LIMIT 6") as $p): ?>
                        <li><a href="<?= url('page.php?slug=' . $p['slug']) ?>"><?= e($p['title']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <h4>Contact</h4>
                <ul>
                    <li>📍 <?= e(setting('contact_address')) ?></li>
                    <li>📞 <?= e(setting('contact_phone')) ?></li>
                    <li>📧 <a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bar">
            <span>© <?= date('Y') ?> <?= e(setting('company_name', setting('site_name'))) ?>. All rights reserved.</span>
            <span>
                <a href="<?= url('admin/login.php') ?>">Admin</a> &nbsp;|&nbsp;
                <a href="<?= url('superadmin/login.php') ?>">Super Admin</a>
            </span>
        </div>
    </div>
</footer>

<?php $tbCur = basename($_SERVER['SCRIPT_NAME'] ?? ''); ?>
<nav class="app-tabbar" aria-label="Mobile quick navigation">
    <a href="<?= url('index.php') ?>" class="<?= $tbCur === 'index.php' ? 'active' : '' ?>"><span class="t-ico">🏠</span>Home</a>
    <a href="<?= url('products.php') ?>" class="<?= in_array($tbCur, ['products.php', 'product.php']) ? 'active' : '' ?>"><span class="t-ico">🛍️</span>Products</a>
    <a href="<?= url('opportunity.php') ?>" class="<?= $tbCur === 'opportunity.php' ? 'active' : '' ?>"><span class="t-ico">💼</span>Business</a>
    <a href="<?= url('contact.php') ?>" class="<?= $tbCur === 'contact.php' ? 'active' : '' ?>"><span class="t-ico">📞</span>Contact</a>
    <a href="<?= url('login.php') ?>"><span class="t-ico">🔐</span>Login</a>
</nav>

<?php if (setting('social_whatsapp')): ?>
<a class="wa-float" href="<?= e(setting('social_whatsapp')) ?>" target="_blank" rel="noopener" aria-label="Chat on WhatsApp">
    <?= social_icon_svg('whatsapp') ?>
    <span class="wa-tip">Chat on WhatsApp</span>
</a>
<?php endif; ?>

<?php /* Ad banner popup — shows when the website is first opened; clicking the banner closes it. */ ?>
<?php $adBannerImg = setting('ad_banner_active') === '1' ? setting('ad_banner_image') : ''; ?>
<?php if ($adBannerImg): ?>
<div class="adpop" id="adPopup" hidden>
    <div class="adpop-backdrop" data-adclose></div>
    <div class="adpop-box">
        <button type="button" class="adpop-close" data-adclose aria-label="Close advertisement">&times;</button>
        <img src="<?= e(upload_url($adBannerImg)) ?>" alt="Advertisement" class="adpop-img" data-adclose>
        <div class="adpop-hint">Tap the banner to continue to the website</div>
    </div>
</div>
<style>
.adpop{position:fixed;inset:0;z-index:9990;display:flex;align-items:center;justify-content:center}
.adpop[hidden]{display:none}
.adpop-backdrop{position:absolute;inset:0;background:rgba(8,14,26,.78);backdrop-filter:blur(2px)}
.adpop-box{position:relative;max-width:min(92vw,560px);max-height:88vh;display:flex;flex-direction:column;align-items:center;animation:adpopIn .32s ease}
.adpop-img{max-width:100%;max-height:80vh;object-fit:contain;border-radius:14px;box-shadow:0 18px 60px rgba(0,0,0,.45);cursor:pointer;background:#fff}
.adpop-close{position:absolute;top:-16px;right:-16px;width:38px;height:38px;border-radius:50%;border:none;background:#fff;color:#1d2733;font-size:24px;line-height:1;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center}
.adpop-close:hover{background:var(--brand,#2a7a2a);color:#fff}
.adpop-hint{margin-top:12px;color:rgba(255,255,255,.85);font-size:13px;letter-spacing:.3px;text-shadow:0 1px 4px rgba(0,0,0,.5)}
@keyframes adpopIn{from{opacity:0;transform:scale(.86) translateY(14px)}to{opacity:1;transform:none}}
@media (max-width:600px){.adpop-close{top:-12px;right:-8px;width:32px;height:32px;font-size:20px}}
</style>
<script>
(function () {
    var pop = document.getElementById('adPopup');
    if (!pop) { return; }
    var seen = false;
    try { seen = sessionStorage.getItem('adBannerClosed') === '1'; } catch (e) { seen = false; }
    if (!seen) {
        document.documentElement.style.overflow = 'hidden';
        pop.hidden = false;
    }
    pop.addEventListener('click', function (ev) {
        if (!ev.target.closest('[data-adclose]')) { return; }
        pop.hidden = true;
        document.documentElement.style.overflow = '';
        try { sessionStorage.setItem('adBannerClosed', '1'); } catch (e) {}
    });
})();
</script>
<?php endif; ?>

<script src="<?= url('assets/js/app.js') ?>?v=<?= APP_VERSION ?>"></script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('<?= e(url('sw.js')) ?>').catch(function () {});
    });
}
</script>
</body>
</html>
