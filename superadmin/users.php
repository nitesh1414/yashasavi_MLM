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
/* all matching rows at once — the DataTable on this page provides the
 * sorting (click a column name), instant search and pagination client-side */
$rows = q_all("SELECT u.*, s.username AS sponsor_name, r.name AS rank_name FROM users u
               LEFT JOIN users s ON s.id = u.sponsor_id
               LEFT JOIN ranks r ON r.id = u.rank_id
               WHERE $where ORDER BY u.id DESC", $params);

$pageScriptsFiles = [
    url('assets/datatables/jquery.min.js'),
    url('assets/datatables/jquery.dataTables.min.js'),
    url('assets/js/dtables.js'),
];

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
        <table class="table table-dt" style="width:100%">
            <thead>
            <tr>
                <th>User</th><th>Sponsor</th><th>Left BV</th><th>Right BV</th><th>Wallet</th>
                <th>Rank</th><th>Status</th><th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td>
                    <b><?= e($r['username']) ?></b>
                    <?= (int)$r['is_active'] ? badge('Act', 'success') : badge('Inact', 'warning') ?><br>
                    <small style="color:#000"><?= e($r['full_name']) ?> · <?= e($r['mobile']) ?></small>
                </td>
                <td><?= e($r['sponsor_name'] ?: '—') ?></td>
                <td data-order="<?= (float)$r['left_bv'] ?>"><?= e(number_format((float)$r['left_bv'], 0)) ?></td>
                <td data-order="<?= (float)$r['right_bv'] ?>"><?= e(number_format((float)$r['right_bv'], 0)) ?></td>
                <td data-order="<?= (float)$r['wallet_balance'] ?>"><?= money($r['wallet_balance']) ?></td>
                <td><?= e($r['rank_name'] ?: '—') ?></td>
                <td><?= status_badge($r['status']) ?></td>
                <td>
                    <div class="table-actions">
                        <a class="btn btn-outline btn-sm" href="user_view.php?id=<?= (int)$r['id'] ?>">View</a>
                        <a class="btn btn-light btn-sm" href="user_edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
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
                        <a class="btn btn-danger btn-sm" href="user_delete.php?id=<?= (int)$r['id'] ?>"
                           title="Delete this distributor (asks for confirmation first)">🗑 Delete</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
