<?php
/** Orders management — approve/reject (triggers MLM engine) */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $id = (int)post_str('id');
    if ($action === 'approve') {
        [$ok, $msg] = approve_order($id, $a['id']);
        $ok ? flash('success', $msg) : flash('error', $msg);
    } elseif ($action === 'reject') {
        [$ok, $msg] = reject_order($id, $a['id'], post_str('reason'));
        $ok ? flash('success', $msg) : flash('error', $msg);
    }
    redirect('orders.php' . (get_str('status') ? '?status=' . urlencode(get_str('status')) : ''));
}

$status = get_str('status');
$q = get_str('q');
$where = "1=1";
$params = [];
if ($status !== '') { $where .= " AND o.status = ?"; $params[] = $status; }
if ($q !== '') {
    $where .= " AND (o.order_no LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%");
}
$total = (int)q_val("SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.user_id WHERE $where", $params);
[$per, $offset] = paginate($total, 20, $links);
$rows = q_all("SELECT o.*, u.username, u.full_name, u.mobile FROM orders o
               JOIN users u ON u.id = o.user_id
               WHERE $where ORDER BY FIELD(o.status,'pending','approved','rejected','cancelled'), o.id DESC
               LIMIT $per OFFSET $offset", $params);

$activeKey = 'orders';
$pageTitle = 'Orders';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card">
    <div class="card-title">
        📦 Orders (<?= $total ?>)
        <span class="right">
            <a class="btn <?= $status === '' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="orders.php">All</a>
            <?php foreach (['pending', 'approved', 'rejected'] as $st): ?>
                <a class="btn <?= $status === $st ? 'btn-primary' : 'btn-light' ?> btn-sm" href="orders.php?status=<?= $st ?>"><?= ucfirst($st) ?></a>
            <?php endforeach; ?>
        </span>
    </div>

    <form method="get" class="filter-form">
        <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <div class="form-group">
            <label>Search</label>
            <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Order no, user ID or name">
        </div>
        <button class="btn btn-primary" type="submit">Search</button>
        <?php if ($q): ?><a class="btn btn-light" href="orders.php<?= $status ? '?status=' . e($status) : '' ?>">Clear</a><?php endif; ?>
    </form>

    <?php if (!$rows): ?>
        <div class="empty-state"><span class="es-ico">📦</span>No orders found.</div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table">
            <tr><th>Order</th><th>User</th><th>Payment</th><th>DP</th><th>BV</th><th>Date</th><th>Status</th><th>Actions</th></tr>
            <?php foreach ($rows as $o): ?>
            <tr>
                <td><b><?= e($o['order_no']) ?></b></td>
                <td>
                    <a href="user_view.php?id=<?= (int)$o['user_id'] ?>"><b><?= e($o['username']) ?></b></a><br>
                    <small style="color:#000"><?= e($o['full_name']) ?></small>
                </td>
                <td>
                    <?= e(ucfirst(str_replace('_', ' ', $o['payment_mode']))) ?><br>
                    <?= status_badge($o['payment_status']) ?>
                    <?php if ($o['txn_ref']): ?><br><small style="color:#000">Ref: <?= e($o['txn_ref']) ?></small><?php endif; ?>
                </td>
                <td><?= money($o['total_dp']) ?></td>
                <td><b><?= e($o['total_bv']) ?></b></td>
                <td><?= dmy($o['created_at'], true) ?></td>
                <td><?= status_badge($o['status']) ?></td>
                <td>
                    <div class="table-actions">
                        <a class="btn btn-outline btn-sm" href="order_view.php?id=<?= (int)$o['id'] ?>">Open</a>
                        <?php if ($o['status'] === 'pending'): ?>
                        <form method="post" class="inline-form" data-confirm="Approve this order? Commissions will be credited to the upline immediately.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                            <button class="btn btn-primary btn-sm" type="submit">✔ Approve</button>
                        </form>
                        <form method="post" class="inline-form" data-confirm="Reject this order?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                            <input type="hidden" name="reason" value="Payment could not be verified">
                            <button class="btn btn-danger btn-sm" type="submit">✖ Reject</button>
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
