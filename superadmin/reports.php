<?php
/** Business reports — monthly summary, top earners, top sponsors, leg analysis */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

$month = get_str('month', date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) { $month = date('Y-m'); }
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

// Month summary
$mOrders = (int)q_val("SELECT COUNT(*) FROM orders WHERE status='approved' AND approved_at BETWEEN ? AND ?", [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);
$mSales = (float)q_val("SELECT COALESCE(SUM(total_dp),0) FROM orders WHERE status='approved' AND approved_at BETWEEN ? AND ?", [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);
$mBv = (float)q_val("SELECT COALESCE(SUM(total_bv),0) FROM orders WHERE status='approved' AND approved_at BETWEEN ? AND ?", [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);
$mComm = (float)q_val("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE created_at BETWEEN ? AND ?", [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);
$mUsers = (int)q_val("SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ?", [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);
$mPayout = (float)q_val("SELECT COALESCE(SUM(net_amount),0) FROM payouts WHERE status='paid' AND paid_at BETWEEN ? AND ?", [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);

// Top earners (all time)
$topEarners = q_all("SELECT u.id, u.username, u.full_name, u.wallet_balance, u.total_earned, r.name AS rank_name
                     FROM users u LEFT JOIN ranks r ON r.id = u.rank_id
                     ORDER BY u.total_earned DESC LIMIT 10");
// Top sponsors (by direct team BV)
$topSponsors = q_all("SELECT s.id, s.username, s.full_name,
                        (SELECT COUNT(*) FROM users d WHERE d.sponsor_id = s.id) AS directs,
                        (SELECT COALESCE(SUM(d.self_bv),0) FROM users d WHERE d.sponsor_id = s.id) AS team_self_bv
                      FROM users s ORDER BY team_self_bv DESC LIMIT 10");
// Top products
$topProducts = q_all("SELECT oi.product_name, SUM(oi.qty) AS qty_sold, SUM(oi.total) AS revenue
                      FROM order_items oi JOIN orders o ON o.id = oi.order_id
                      WHERE o.status = 'approved'
                      GROUP BY oi.product_name ORDER BY revenue DESC LIMIT 10");
// Leg analysis (direct children of company root)
$legs = q_all("SELECT u.id, u.username, u.left_bv, u.right_bv FROM users u WHERE u.placement_id IS NULL ORDER BY u.id");
// Last 6 months trend
$trend = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = date('Y-m-01', strtotime("$monthStart -$i months"));
    $te = date('Y-m-t', strtotime($ts));
    $trend[date('M y', strtotime($ts))] = [
        'bv' => (float)q_val("SELECT COALESCE(SUM(total_bv),0) FROM orders WHERE status='approved' AND approved_at BETWEEN ? AND ?", [$ts . ' 00:00:00', $te . ' 23:59:59']),
        'inc' => (float)q_val("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE created_at BETWEEN ? AND ?", [$ts . ' 00:00:00', $te . ' 23:59:59']),
    ];
}
$maxTrend = max(1, max(array_merge(array_column($trend, 'bv'), array_column($trend, 'inc'))));

$activeKey = 'reports';
$pageTitle = 'Reports';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<form method="get" class="filter-form" style="max-width:500px;margin-bottom:18px">
    <div class="form-group"><label>Report Month</label>
        <input class="form-control" type="month" name="month" value="<?= e($month) ?>"></div>
    <button class="btn btn-primary" type="submit">Show</button>
</form>

<div class="stat-grid">
    <div class="stat-card"><div class="st-ico">👥</div><div><b><?= $mUsers ?></b><span>New Distributors (<?= e(date('M Y', strtotime($monthStart))) ?>)</span></div></div>
    <div class="stat-card teal"><div class="st-ico">📦</div><div><b><?= $mOrders ?></b><span>Approved Orders</span></div></div>
    <div class="stat-card gold"><div class="st-ico">💠</div><div><b><?= bv($mBv) ?></b><span>Business Volume</span></div></div>
    <div class="stat-card blue"><div class="st-ico">💰</div><div><b><?= money($mComm) ?></b><span>Commissions</span></div></div>
</div>

<div class="two-col">
    <div>
        <div class="card">
            <div class="card-title">📊 Sales vs Income — last 6 months</div>
            <div class="chart-bars">
                <?php foreach ($trend as $label => $t): ?>
                <div class="cb" style="height:<?= max(2, round($t['bv'] / $maxTrend * 100)) ?>%">
                    <span class="cb-v"><?= $t['bv'] ? round($t['bv']) : '' ?></span>
                    <span class="cb-l"><?= e($label) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="height:24px"></div>
            <table class="kv-table" style="width:100%;margin-top:8px">
                <tr><td>Month sales (DP)</td><td><b><?= money($mSales) ?></b></td></tr>
                <tr><td>Payouts paid in month</td><td><b><?= money($mPayout) ?></b></td></tr>
                <tr><td>Payout ratio</td><td><b><?= $mSales > 0 ? round($mComm / $mSales * 100, 1) : 0 ?>%</b> of DP value</td></tr>
            </table>
        </div>

        <div class="card">
            <div class="card-title">🏆 Top Earners (all time)</div>
            <table class="table" style="width:100%">
                <tr><th>#</th><th>User</th><th>Rank</th><th>Earned</th><th>Wallet</th></tr>
                <?php foreach ($topEarners as $i => $t): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><a href="user_view.php?id=<?= (int)$t['id'] ?>"><b><?= e($t['username']) ?></b></a><br>
                        <small style="color:#000"><?= e($t['full_name']) ?></small></td>
                    <td><?= e($t['rank_name'] ?: '—') ?></td>
                    <td><b><?= money($t['total_earned']) ?></b></td>
                    <td><?= money($t['wallet_balance']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-title">🤝 Top Sponsors (by team self-BV)</div>
            <table class="table" style="width:100%">
                <tr><th>#</th><th>User</th><th>Directs</th><th>Team Self BV</th></tr>
                <?php foreach ($topSponsors as $i => $t): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><a href="user_view.php?id=<?= (int)$t['id'] ?>"><b><?= e($t['username']) ?></b></a></td>
                    <td><?= (int)$t['directs'] ?></td>
                    <td><b><?= bv($t['team_self_bv']) ?></b></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="card">
            <div class="card-title">🛒 Best Selling Products</div>
            <table class="table" style="width:100%">
                <tr><th>#</th><th>Product</th><th>Qty</th><th>Revenue (DP)</th></tr>
                <?php foreach ($topProducts as $i => $t): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= e($t['product_name']) ?></td>
                    <td><?= (int)$t['qty_sold'] ?></td>
                    <td><b><?= money($t['revenue']) ?></b></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="card">
            <div class="card-title">⚖️ Company Legs</div>
            <table class="table" style="width:100%">
                <tr><th>Leg (root child)</th><th>Left BV</th><th>Right BV</th><th>Balance</th></tr>
                <?php foreach ($legs as $l): ?>
                <tr>
                    <td><b><?= e($l['username']) ?></b></td>
                    <td><?= e(number_format((float)$l['left_bv'], 0)) ?></td>
                    <td><?= e(number_format((float)$l['right_bv'], 0)) ?></td>
                    <td><?= badge(number_format(abs((float)$l['left_bv'] - (float)$l['right_bv']), 0) . ' BV', 'info') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
