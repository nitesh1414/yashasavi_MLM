            </div><!-- /content-inner -->
        </div><!-- /content -->
    </div><!-- /main -->
</div><!-- /dash -->

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
