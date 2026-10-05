            </div><!-- /content-inner -->
        </div><!-- /content -->
    </div><!-- /main -->
</div><!-- /dash -->

<?php if (($area ?? '') === 'user'): ?>
    <?php $tbCur = basename($_SERVER['SCRIPT_NAME'] ?? ''); ?>
    <nav class="app-tabbar" aria-label="App navigation">
        <a href="<?= url('user/index.php') ?>" class="<?= $tbCur === 'index.php' ? 'active' : '' ?>"><span class="t-ico">🏠</span>Home</a>
        <a href="<?= url('user/shop.php') ?>" class="<?= $tbCur === 'shop.php' ? 'active' : '' ?>"><span class="t-ico">🛍️</span>Shop</a>
        <a href="<?= url('user/cart.php') ?>" class="<?= $tbCur === 'cart.php' ? 'active' : '' ?>"><span class="t-ico">🛒</span>Cart</a>
        <a href="<?= url('user/tree.php') ?>" class="<?= $tbCur === 'tree.php' ? 'active' : '' ?>"><span class="t-ico">👥</span>Team</a>
        <a href="<?= url('user/profile.php') ?>" class="<?= $tbCur === 'profile.php' ? 'active' : '' ?>"><span class="t-ico">👤</span>Account</a>
    </nav>
<?php endif; ?>

<script src="<?= url('assets/js/dash.js') ?>?v=<?= APP_VERSION ?>"></script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('<?= e(url('sw.js')) ?>').catch(function () {});
    });
}
</script>
<?php if (!empty($pageScripts)): ?>
<script><?= $pageScripts ?></script>
<?php endif; ?>
</body>
</html>
