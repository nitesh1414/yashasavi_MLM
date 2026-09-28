<?php
/** My team — direct referrals & searchable downline */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

$type = get_str('type', 'direct'); // direct | downline
$q = get_str('q');

if ($type === 'downline') {
    $where = "u.id != " . (int)$u['id'] . " AND u.path LIKE ?";
    $params = [$u['path'] . '%'];
    $title = 'My Downline (entire network)';
} else {
    $where = "u.sponsor_id = ?";
    $params = [(int)$u['id']];
    $title = 'My Direct Referrals';
}
if ($q !== '') {
    $where .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR u.mobile LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
}
$total = (int)q_val("SELECT COUNT(*) FROM users u WHERE $where", $params);
[$per, $offset] = paginate($total, 20, $links);
$rows = q_all("SELECT u.*, s.username AS sponsor_name FROM users u
               LEFT JOIN users s ON s.id = u.sponsor_id
               WHERE $where ORDER BY u.id DESC LIMIT $per OFFSET $offset", $params);

$activeKey = 'team';
$pageTitle = 'My Team';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card">
    <div class="card-title">
        👥 <?= e($title) ?>
        <span class="right">
            <a class="btn <?= $type === 'direct' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="team.php?type=direct">Direct (<?= (int)q_val("SELECT COUNT(*) FROM users WHERE sponsor_id = ?", [$u['id']]) ?>)</a>
            <a class="btn <?= $type === 'downline' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="team.php?type=downline">Downline</a>
        </span>
    </div>

    <form method="get" class="filter-form">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <div class="form-group">
            <label>Search</label>
            <input class="form-control" type="text" name="q" value="<?= e($q) ?>" placeholder="User ID, name or mobile">
        </div>
        <button class="btn btn-primary" type="submit">Search</button>
        <?php if ($q): ?><a class="btn btn-light" href="team.php?type=<?= e($type) ?>">Clear</a><?php endif; ?>
    </form>

    <?php if (!$rows): ?>
        <div class="empty-state"><span class="es-ico">👥</span>No members found.</div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table">
            <tr>
                <th>User</th><th>Name</th><th>Leg</th><th>Sponsor</th>
                <th>Self BV</th><th>Status</th><th>Joined</th>
            </tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><b><?= e($r['username']) ?></b></td>
                <td><?= e($r['full_name']) ?><br><small style="color:#000"><?= e($r['mobile']) ?></small></td>
                <td><?= $r['leg'] === 'L' ? badge('Left', 'info') : badge('Right', 'warning') ?></td>
                <td><?= e($r['sponsor_name'] ?? '—') ?></td>
                <td><?= bv($r['self_bv']) ?></td>
                <td>
                    <?= (int)$r['is_active'] ? badge('Active', 'success') : badge('Inactive', 'warning') ?>
                    <?= $r['status'] === 'blocked' ? badge('Blocked', 'danger') : '' ?>
                </td>
                <td><?= dmy($r['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?= $links ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
