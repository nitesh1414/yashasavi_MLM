<?php
/** Order detail (super admin) — approve / reject with commission preview */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

$id = get_int('id');
$o = q_row("SELECT o.*, u.username, u.full_name, u.mobile FROM orders o
            JOIN users u ON u.id = o.user_id WHERE o.id = ?", [$id]);
if (!$o) {
    flash('error', 'Order not found.');
    redirect('orders.php');
}
$items = q_all("SELECT * FROM order_items WHERE order_id = ?", [$id]);

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'approve') {
        [$ok, $msg] = approve_order($id, $a['id']);
        $ok ? flash('success', $msg) : flash('error', $msg);
        redirect('order_view.php?id=' . $id);
    } elseif ($action === 'reject') {
        [$ok, $msg] = reject_order($id, $a['id'], post_str('reason'));
        $ok ? flash('success', $msg) : flash('error', $msg);
        redirect('order_view.php?id=' . $id);
    }
}
$commissions = q_all("SELECT c.*, u.username FROM commissions c JOIN users u ON u.id = c.user_id WHERE c.order_id = ? ORDER BY c.type", [$id]);

$activeKey = 'orders';
$pageTitle = 'Order ' . $o['order_no'];
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="two-col">
    <div class="card">
        <div class="card-title">
            📦 Order <?= e($o['order_no']) ?> <?= status_badge($o['status']) ?>
            <a class="btn btn-light btn-sm right" href="orders.php">⬅ All Orders</a>
        </div>
        <div class="table-wrap" style="box-shadow:none">
            <table class="table">
                <tr><th>Product</th><th>DP</th><th>BV</th><th>Qty</th><th>Total</th></tr>
                <?php foreach ($items as $it): ?>
                <tr>
                    <td><?= e($it['product_name']) ?></td>
                    <td><?= money($it['price']) ?></td>
                    <td><?= e($it['bv']) ?></td>
                    <td><?= (int)$it['qty'] ?></td>
                    <td><b><?= money($it['total']) ?></b></td>
                </tr>
                <?php endforeach; ?>
                <tr><td colspan="4" style="text-align:right"><b>Total BV</b></td><td><b style="color:#000"><?= e($o['total_bv']) ?></b></td></tr>
                <tr><td colspan="4" style="text-align:right"><b>Payable</b></td><td><b><?= money($o['total_dp']) ?></b></td></tr>
            </table>
        </div>

        <?php if ($o['status'] === 'pending'): ?>
        <div class="alert alert-warning" style="margin-top:16px">
            Approving this order will immediately credit <b><?= e($o['total_bv']) ?> BV</b> to the network and pay
            sponsor / level / binary matching commissions as per the MLM plan.
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <form method="post" class="inline-form" data-confirm="Approve order and run commissions?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="approve">
                <button class="btn btn-primary" type="submit">✔ Approve Order</button>
            </form>
            <form method="post" class="inline-form" style="display:flex;gap:8px;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <input class="form-control" name="reason" placeholder="Rejection reason" style="max-width:240px">
                <button class="btn btn-danger" type="submit" data-confirm="Reject this order?">✖ Reject</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="card">
            <div class="card-title">ℹ️ Order Details</div>
            <table class="kv-table" style="width:100%">
                <tr><td>Distributor</td>
                    <td><a href="user_view.php?id=<?= (int)$o['user_id'] ?>"><b><?= e($o['username']) ?></b></a><br>
                        <small><?= e($o['full_name']) ?> · <?= e($o['mobile']) ?></small></td></tr>
                <tr><td>Placed On</td><td><?= dmy($o['created_at'], true) ?></td></tr>
                <tr><td>Payment Mode</td><td><?= e(ucfirst(str_replace('_', ' ', $o['payment_mode']))) ?></td></tr>
                <tr><td>Payment Status</td><td><?= status_badge($o['payment_status']) ?></td></tr>
                <tr><td>Txn Reference</td><td><?= e($o['txn_ref'] ?: '—') ?></td></tr>
                <?php if ($o['approved_at']): ?>
                <tr><td>Approved</td><td><?= dmy($o['approved_at'], true) ?></td></tr>
                <?php endif; ?>
                <?php if ($o['remark']): ?>
                <tr><td>Remark</td><td><?= e($o['remark']) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
        <div class="card">
            <div class="card-title">🚚 Shipping</div>
            <p style="font-size:13.5px;line-height:1.8">
                <b><?= e($o['ship_name']) ?></b><br>
                <?= e($o['ship_address']) ?><br>
                <?= e($o['ship_city']) ?>, <?= e($o['ship_state']) ?> - <?= e($o['ship_pincode']) ?><br>
                📞 <?= e($o['ship_mobile']) ?>
            </p>
        </div>
        <?php if ($commissions): ?>
        <div class="card">
            <div class="card-title">💠 Commissions from this order</div>
            <table class="table" style="width:100%">
                <tr><th>User</th><th>Type</th><th>Amount</th></tr>
                <?php foreach ($commissions as $c): ?>
                <tr>
                    <td><a href="user_view.php?id=<?= (int)$c['user_id'] ?>"><?= e($c['username']) ?></a></td>
                    <td><?= badge(ucfirst($c['type']), 'primary') ?><?= $c['level'] ? ' L' . (int)$c['level'] : '' ?></td>
                    <td><b><?= money($c['amount']) ?></b></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
