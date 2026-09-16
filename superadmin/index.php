<?php
/** Super admin dashboard */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

$stats = [
    'users'         => (int)q_val("SELECT COUNT(*) FROM users"),
    'active_users'  => (int)q_val("SELECT COUNT(*) FROM users WHERE is_active = 1"),
    'new_today'     => (int)q_val("SELECT COUNT(*) FROM users WHERE created_at >= ?", [date('Y-m-d 00:00:00')]),
    'orders'        => (int)q_val("SELECT COUNT(*) FROM orders"),
    'pending_orders'=> (int)q_val("SELECT COUNT(*) FROM orders WHERE status='pending'"),
    'sales_dp'      => (float)q_val("SELECT COALESCE(SUM(total_dp),0) FROM orders WHERE status='approved'"),
    'sales_bv'      => (float)q_val("SELECT COALESCE(SUM(total_bv),0) FROM orders WHERE status='approved'"),
    'commission'    => (float)q_val("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status='credited'"),
    'payout_pending'=> (int)q_val("SELECT COUNT(*) FROM payouts WHERE status='pending'"),
    'payout_paid'   => (float)q_val("SELECT COALESCE(SUM(amount),0) FROM payouts WHERE status='paid'"),
    'wallet_total'  => (float)q_val("SELECT COALESCE(SUM(wallet_balance),0) FROM users"),
    'enquiries'     => (int)q_val("SELECT COUNT(*) FROM enquiries WHERE status='new'"),
];

// registrations last 14 days (chart)
$chart = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chart[$d] = (int)q_val("SELECT COUNT(*) FROM users WHERE DATE(created_at) = ?", [$d]);
}
$maxChart = max(1, max($chart));

$recentUsers = q_all("SELECT u.*, s.username AS sponsor_name FROM users u
                      LEFT JOIN users s ON s.id = u.sponsor_id ORDER BY u.id DESC LIMIT 6");
$recentOrders = q_all("SELECT o.*, u.username FROM orders o JOIN users u ON u.id = o.user_id ORDER BY o.id DESC LIMIT 6");

$activeKey = 'dashboard';
$pageTitle = 'Super Admin Dashboard';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="stat-grid">
    <div class="stat-card"><div class="st-ico">👥</div><div><b><?= $stats['users'] ?></b><span>Distributors (<?= $stats['active_users'] ?> active)</span></div></div>
    <div class="stat-card teal"><div class="st-ico">📦</div><div><b><?= $stats['orders'] ?></b><span>Orders (<?= $stats['pending_orders'] ?> pending)</span></div></div>
    <div class="stat-card gold"><div class="st-ico">💠</div><div><b><?= bv($stats['sales_bv']) ?></b><span>Business Volume (approved)</span></div></div>
    <div class="stat-card blue"><div class="st-ico">💰</div><div><b><?= money($stats['commission']) ?></b><span>Commissions Paid Out</span></div></div>
</div>

<div class="two-col">
    <div>
        <div class="card">
            <div class="card-title">📈 New Registrations — last 14 days</div>
            <div class="chart-bars">
                <?php foreach ($chart as $d => $n): ?>
                <div class="cb" style="height:<?= max(2, round($n / $maxChart * 100)) ?>%">
                    <span class="cb-v"><?= $n ?: '' ?></span>
                    <span class="cb-l"><?= date('d/m', strtotime($d)) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="height:24px"></div>
            <table class="kv-table" style="width:100%;margin-top:8px">
                <tr><td>Registered today</td><td><b><?= $stats['new_today'] ?></b></td></tr>
                <tr><td>Approved sales (DP value)</td><td><b><?= money($stats['sales_dp']) ?></b></td></tr>
                <tr><td>Wallet liability (all balances)</td><td><b><?= money($stats['wallet_total']) ?></b></td></tr>
                <tr><td>Payouts paid till date</td><td><b><?= money($stats['payout_paid']) ?></b></td></tr>
            </table>
        </div>

        <div class="card">
            <div class="card-title">📦 Recent Orders <a class="right" href="orders.php">View all →</a></div>
            <?php if (!$recentOrders): ?>
                <div class="empty-state"><span class="es-ico">📦</span>No orders yet.</div>
            <?php else: ?>
            <div class="table-wrap" style="box-shadow:none">
                <table class="table">
                    <tr><th>Order</th><th>User</th><th>DP</th><th>Status</th><th></th></tr>
                    <?php foreach ($recentOrders as $o): ?>
                    <tr>
                        <td><?= e($o['order_no']) ?></td>
                        <td><?= e($o['username']) ?></td>
                        <td><?= money($o['total_dp']) ?></td>
                        <td><?= status_badge($o['status']) ?></td>
                        <td><a class="btn btn-outline btn-sm" href="order_view.php?id=<?= (int)$o['id'] ?>">Open</a></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-title">⚡ Pending Actions</div>
            <p style="line-height:2">
                <a href="orders.php?status=pending" class="btn btn-outline btn-sm">📦 <?= $stats['pending_orders'] ?> order(s) awaiting approval</a><br>
                <a href="payouts.php?status=pending" class="btn btn-outline btn-sm">🏦 <?= $stats['payout_pending'] ?> payout request(s)</a><br>
                <a href="<?= url('admin/enquiries.php') ?>" class="btn btn-outline btn-sm">✉️ <?= $stats['enquiries'] ?> new website enquir<?= $stats['enquiries'] == 1 ? 'y' : 'ies' ?> (CMS panel)</a>
            </p>
        </div>

        <div class="card">
            <div class="card-title">👥 Newest Distributors <a class="right" href="users.php">View all →</a></div>
            <div class="table-wrap" style="box-shadow:none">
                <table class="table">
                    <tr><th>User</th><th>Sponsor</th><th>Status</th></tr>
                    <?php foreach ($recentUsers as $r): ?>
                    <tr>
                        <td><b><?= e($r['username']) ?></b><br><small style="color:#8d9c8d"><?= e($r['full_name']) ?></small></td>
                        <td><?= e($r['sponsor_name'] ?: '—') ?></td>
                        <td><?= (int)$r['is_active'] ? badge('Active', 'success') : badge('Inactive', 'warning') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
