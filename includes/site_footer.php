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

<?php if (setting('social_whatsapp')): ?>
<a class="wa-float" href="<?= e(setting('social_whatsapp')) ?>" target="_blank" rel="noopener" aria-label="Chat on WhatsApp">
    <?= social_icon_svg('whatsapp') ?>
    <span class="wa-tip">Chat on WhatsApp</span>
</a>
<?php endif; ?>

<script src="<?= url('assets/js/app.js') ?>?v=<?= APP_VERSION ?>"></script>
</body>
</html>
