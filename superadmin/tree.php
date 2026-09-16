<?php
/** Network tree viewer (super admin — any user) */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/tree_renderer.php';
$a = require_superadmin();

$rootId = get_int('root', 1);
$rootUser = q_row("SELECT * FROM users WHERE id = ?", [$rootId]);
if (!$rootUser) {
    flash('error', 'User not found.');
    redirect('tree.php');
}

$activeKey = 'tree';
$pageTitle = 'Network Tree';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card">
    <div class="card-title">
        🌳 Network Tree — viewing: <?= e($rootUser['username']) ?> (<?= e($rootUser['full_name']) ?>)
        <span class="right">
            <?php if ((int)$rootUser['id'] !== 1): ?>
                <a class="btn btn-light btn-sm" href="tree.php?root=1">⬅ Company Root</a>
            <?php endif; ?>
            <a class="btn btn-light btn-sm" href="tree.php?root=<?= (int)$rootUser['id'] ?>">🔄 Refresh</a>
        </span>
    </div>
    <form method="get" class="filter-form" action="tree.php">
        <div class="form-group">
            <label>Jump to user</label>
            <input class="form-control" name="root" value="<?= (int)$rootUser['id'] ?>" placeholder="User ID number e.g. 5">
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Go</button>
    </form>
    <?= render_binary_tree($rootUser, 3, 'tree.php') ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
