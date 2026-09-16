<?php
/** Genealogy tree (user's own network) */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/tree_renderer.php';
$u = require_user();

$rootId = get_int('root', (int)$u['id']);
$rootUser = $u;
if ($rootId !== (int)$u['id']) {
    $candidate = q_row("SELECT * FROM users WHERE id = ?", [$rootId]);
    // only allow viewing own subtree
    if (!$candidate || strpos($candidate['path'], $u['path']) !== 0) {
        $candidate = null;
    }
    if ($candidate) {
        $rootUser = $candidate;
    }
}

$activeKey = 'tree';
$pageTitle = 'Genealogy Tree';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card">
    <div class="card-title">
        🌳 Genealogy Tree — viewing: <?= e($rootUser['username']) ?> (<?= e($rootUser['full_name']) ?>)
        <span class="right">
            <?php if ((int)$rootUser['id'] !== (int)$u['id']): ?>
                <a class="btn btn-light btn-sm" href="tree.php">⬅ Back to my tree</a>
            <?php endif; ?>
            <a class="btn btn-primary btn-sm" href="add-member.php">➕ Add Member</a>
            <a class="btn btn-light btn-sm" href="tree.php?root=<?= (int)$rootUser['id'] ?>">🔄 Refresh</a>
        </span>
    </div>
    <?= render_binary_tree($rootUser, 5, 'tree.php', 'add-member.php') ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
