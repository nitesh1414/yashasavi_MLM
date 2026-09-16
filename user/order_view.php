<?php
/** Order details + invoice view */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

$id = get_int('id');
$order = q_row("SELECT * FROM orders WHERE id = ? AND user_id = ?", [$id, $u['id']]);
if (!$order) {
    flash('error', 'Order not found.');
    redirect('orders.php');
}
$items = q_all("SELECT * FROM order_items WHERE order_id = ?", [$order['id']]);

$activeKey = 'orders';
$pageTitle = 'Order ' . $order['order_no'];
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="two-col">
    <div class="card">
        <div class="card-title">
            🧾 Order <?= e($order['order_no']) ?> <?= status_badge($order['status']) ?>
            <span class="right"><a class="btn btn-light btn-sm" href="orders.php">⬅ All Orders</a></span>
        </div>
        <div class="table-wrap" style="box-shadow:none">
            <table class="table">
                <tr><th>Product</th><th>Price (DP)</th><th>MRP</th><th>BV</th><th>Qty</th><th>Total</th></tr>
                <?php foreach ($items as $it): ?>
                <tr>
                    <td><?= e($it['product_name']) ?></td>
                    <td><?= money($it['price']) ?></td>
                    <td><?= money($it['mrp']) ?></td>
                    <td><?= e($it['total_bv']) ?></td>
                    <td><?= (int)$it['qty'] ?></td>
                    <td><b><?= money($it['total']) ?></b></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="5" style="text-align:right"><b>Total BV</b></td>
                    <td><b style="color:var(--dash-gold)"><?= bv($order['total_bv']) ?></b></td>
                </tr>
                <tr>
                    <td colspan="5" style="text-align:right"><b>Payable (DP Total)</b></td>
                    <td><b><?= money($order['total_dp']) ?></b></td>
                </tr>
            </table>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-title">ℹ️ Order Info</div>
            <table class="kv-table" style="width:100%">
                <tr><td>Order No.</td><td><b><?= e($order['order_no']) ?></b></td></tr>
                <tr><td>Placed On</td><td><?= dmy($order['created_at'], true) ?></td></tr>
                <tr><td>Payment Mode</td><td><?= e(ucfirst(str_replace('_', ' ', $order['payment_mode']))) ?></td></tr>
                <tr><td>Payment Status</td><td><?= status_badge($order['payment_status']) ?></td></tr>
                <tr><td>Txn Reference</td><td><?= e($order['txn_ref'] ?: '—') ?></td></tr>
                <tr><td>Status</td><td><?= status_badge($order['status']) ?></td></tr>
                <?php if ($order['approved_at']): ?>
                <tr><td>Approved On</td><td><?= dmy($order['approved_at'], true) ?></td></tr>
                <?php endif; ?>
                <?php if ($order['remark']): ?>
                <tr><td>Remark</td><td><?= e($order['remark']) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
        <div class="card">
            <div class="card-title">🚚 Shipping To</div>
            <p style="font-size:13.5px;line-height:1.8">
                <b><?= e($order['ship_name']) ?></b><br>
                <?= e($order['ship_address']) ?><br>
                <?= e($order['ship_city']) ?>, <?= e($order['ship_state']) ?> - <?= e($order['ship_pincode']) ?><br>
                📞 <?= e($order['ship_mobile']) ?>
            </p>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
