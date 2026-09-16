<?php
/** Distributors management */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $id = (int)post_str('id');
    $user = q_row("SELECT * FROM users WHERE id = ?", [$id]);
    if ($user) {
        if ($action === 'block') {
            q("UPDATE users SET status='blocked' WHERE id = ?", [$id]);
            flash('success', 'User ' . $user['username'] . ' blocked.');
        } elseif ($action === 'unblock') {
            q("UPDATE users SET status='active' WHERE id = ?", [$id]);
            flash('success', 'User ' . $user['username'] . ' unblocked.');
        } elseif ($action === 'activate') {
            q("UPDATE users SET is_active = 1, activated_at = COALESCE(activated_at, ?) WHERE id = ?", [now(), $id]);
            flash('success', 'User ' . $user['username'] . ' manually activated (can now earn commissions).');
        }
    }
    redirect('users.php' . (get_str('q') ? '?q=' . urlencode(get_str('q')) : ''));
}

$q = get_str('q');
$status = get_str('status');
$where = "1=1";
$params = [];
if ($q !== '') {
    $where .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR u.mobile LIKE ? OR u.email LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($status !== '') {
    $where .= " AND u.status = ?";
    $params[] = $status;
}
$total = (int)q_val("SELECT COUNT(*) FROM users u WHERE $where", $params);
[$per, $offset] = paginate($total, 20, $links);
$rows = q_all("SELECT u.*, s.username AS sponsor_name, r.name AS rank_name FROM users u
               LEFT JOIN users s ON s.id = u.sponsor_id
               LEFT JOIN ranks r ON r.id = u.rank_id
               WHERE $where ORDER BY u.id DESC LIMIT $per OFFSET $offset", $params);

$activeKey = 'users';
$pageTitle = 'Distributors';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card">
    <div class="card-title">
        👥 Distributors (<?= $total ?>)
        <span class="right">
            <a class="btn <?= $status === '' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="users.php">All</a>
            <a class="btn <?= $status === 'active' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="users.php?status=active">Active</a>
            <a class="btn <?= $status === 'blocked' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="users.php?status=blocked">Blocked</a>
        </span>
    </div>

    <form method="get" class="filter-form">
        <div class="form-group">
            <label>Search</label>
            <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="User ID, name, mobile, email">
        </div>
        <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <button class="btn btn-primary" type="submit">Search</button>
        <?php if ($q): ?><a class="btn btn-light" href="users.php<?= $status ? '?status=' . e($status) : '' ?>">Clear</a><?php endif; ?>
    </form>

    <?php if (!$rows): ?>
        <div class="empty-state"><span class="es-ico">👥</span>No distributors found.</div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table">
            <tr>
                <th>User</th><th>Sponsor</th><th>Team BV (L / R)</th><th>Wallet</th>
                <th>Rank</th><th>Status</th><th>Actions</th>
            </tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td>
                    <b><?= e($r['username']) ?></b>
                    <?= (int)$r['is_active'] ? badge('Act', 'success') : badge('Inact', 'warning') ?><br>
                    <small style="color:#8d9c8d"><?= e($r['full_name']) ?> · <?= e($r['mobile']) ?></small>
                </td>
                <td><?= e($r['sponsor_name'] ?: '—') ?></td>
                <td><?= e(number_format((float)$r['left_bv'], 0)) ?> / <?= e(number_format((float)$r['right_bv'], 0)) ?></td>
                <td><?= money($r['wallet_balance']) ?></td>
                <td><?= e($r['rank_name'] ?: '—') ?></td>
                <td><?= status_badge($r['status']) ?></td>
                <td>
                    <div class="table-actions">
                        <a class="btn btn-outline btn-sm" href="user_view.php?id=<?= (int)$r['id'] ?>">View</a>
                        <?php if ($r['status'] === 'active'): ?>
                        <form method="post" class="inline-form" data-confirm="Block this user? They will not be able to login.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="block">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-danger btn-sm" type="submit">Block</button>
                        </form>
                        <?php else: ?>
                        <form method="post" class="inline-form"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="unblock">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-outline btn-sm" type="submit">Unblock</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?= $links ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
