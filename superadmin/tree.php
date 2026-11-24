<?php
/** Network tree viewer (super admin — any user) */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/tree_renderer.php';
$a = require_superadmin();

$companyRoot = q_row("SELECT * FROM users WHERE (placement_id IS NULL OR placement_id = 0) AND status <> 'deleted' ORDER BY id LIMIT 1");
$rootId = get_int('root', $companyRoot ? (int)$companyRoot['id'] : 0);
$rootUser = $rootId ? q_row("SELECT * FROM users WHERE id = ? AND status <> 'deleted'", [$rootId]) : null;
if (!$rootUser && $companyRoot) {
    flash('error', 'User not found in the active tree.');
    redirect('tree.php');
}

$activeKey = 'tree';
$pageTitle = 'Network Tree';
$wideContent = true; // tree chart uses the complete page width
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<?php if (!$rootUser): ?>
<div class="card"><div class="empty-state"><p>The network is empty.</p><a class="btn btn-primary" href="add-member.php">Create the first root member</a></div></div>
<?php else: ?>
<div class="card">
    <div class="card-title">
        🌳 Network Tree — viewing: <?= e($rootUser['username']) ?> (<?= e($rootUser['full_name']) ?>)
        <span class="right">
            <?php if ($companyRoot && (int)$rootUser['id'] !== (int)$companyRoot['id']): ?>
                <a class="btn btn-light btn-sm" href="tree.php?root=<?= (int)$companyRoot['id'] ?>">⬅ Company Root</a>
            <?php endif; ?>
            <a class="btn btn-primary btn-sm" href="add-member.php">➕ Add Distributor</a>
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
    <?= render_binary_tree($rootUser, 0, 'tree.php', 'add-member.php', 'user_view.php') ?>
</div>

<?php endif; ?>
<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
