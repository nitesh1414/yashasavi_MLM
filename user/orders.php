<?php
/** My orders list */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

$status = get_str('status');
$where = "user_id = ?";
$params = [$u['id']];
if ($status !== '') {
    $where .= " AND status = ?";
    $params[] = $status;
}
$total = (int)q_val("SELECT COUNT(*) FROM orders WHERE $where", $params);
[$per, $offset] = paginate($total, 15, $links);
$orders = q_all("SELECT * FROM orders WHERE $where ORDER BY id DESC LIMIT $per OFFSET $offset", $params);

$activeKey = 'orders';
$pageTitle = 'My Orders';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card">
    <div class="card-title">
        📦 My Orders
        <span class="right">
            <a class="btn <?= $status === '' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="orders.php">All</a>
            <?php foreach (['pending', 'approved', 'rejected'] as $st): ?>
                <a class="btn <?= $status === $st ? 'btn-primary' : 'btn-light' ?> btn-sm" href="orders.php?status=<?= $st ?>"><?= ucfirst($st) ?></a>
            <?php endforeach; ?>
        </span>
    </div>

    <?php if (!$orders): ?>
        <div class="empty-state">
            <span class="es-ico">📦</span>
            <p>No orders found.</p>
            <a class="btn btn-primary mt-2" href="shop.php">Shop Now</a>
        </div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table">
            <tr><th>Order No.</th><th>Date</th><th>Items</th><th>DP Total</th><th>BV</th><th>Payment</th><th>Status</th><th></th></tr>
            <?php foreach ($orders as $o):
                $items = q_val("SELECT COUNT(*) FROM order_items WHERE order_id = ?", [$o['id']]);
                $pmLabel = ['wallet' => 'Wallet', 'bank_transfer' => 'Bank/UPI', 'online' => 'Online'][$o['payment_mode']] ?? $o['payment_mode'];
            ?>
            <tr>
                <td><b><?= e($o['order_no']) ?></b></td>
                <td><?= dmy($o['created_at'], true) ?></td>
                <td><?= (int)$items ?></td>
                <td><?= money($o['total_dp']) ?></td>
                <td><?= bv($o['total_bv']) ?></td>
                <td><?= e($pmLabel) ?><br><small style="color:#8d9c8d"><?= status_badge($o['payment_status']) ?></small></td>
                <td><?= status_badge($o['status']) ?></td>
                <td><a class="btn btn-outline btn-sm" href="order_view.php?id=<?= (int)$o['id'] ?>">View</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?= $links ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
