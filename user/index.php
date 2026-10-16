<?php
/** Distributor dashboard */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

$earn = user_earnings_breakdown($u['id']);
$teamCounts = team_counts($u);
$rank = $u['rank_id'] ? q_row("SELECT * FROM ranks WHERE id = ?", [$u['rank_id']]) : null;
$directs = (int)q_val("SELECT COUNT(*) FROM users WHERE sponsor_id = ?", [$u['id']]);
$directActive = (int)q_val("SELECT COUNT(*) FROM users WHERE sponsor_id = ? AND is_active = 1", [$u['id']]);
$plan = get_plan();
$pairUnits = floor(min((float)$u['left_bv'], (float)$u['right_bv']) / max(1, (float)$plan['pair_unit_bv']));
$carry = max((float)$u['left_bv'], (float)$u['right_bv']) - min((float)$u['left_bv'], (float)$u['right_bv']);
$announcements = q_all("SELECT * FROM announcements WHERE status='active' ORDER BY created_at DESC LIMIT 3");
$recentTx = q_all("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 6", [$u['id']]);
$recentOrders = q_all("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 5", [$u['id']]);

$activeKey = 'dashboard';
$pageTitle = 'Dashboard';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<?php if (!$u['is_active']): ?>
<div class="alert alert-warning">
    ⚠️ Your account is <b>not activated</b> yet. Purchase products worth at least
    <b><?= e($plan['activation_bv']) ?> BV</b> (lifetime) from the <a href="shop.php"><b>Shop</b></a> to activate your
    account and start earning commissions. Current self BV: <b><?= bv($u['self_bv']) ?></b>.
</div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="st-ico">👛</div>
        <div><b><?= money($u['wallet_balance']) ?></b><span>Wallet Balance</span></div>
    </div>
    <div class="stat-card gold">
        <div class="st-ico">💰</div>
        <div><b><?= money($earn['total']) ?></b><span>Total Income</span></div>
    </div>
    <div class="stat-card teal">
        <div class="st-ico">⭐</div>
        <div><b><?= number_format((int)$u['reward_points']) ?> P</b><span>Points (<?= (int)$u['matched_pairs'] ?> pairs matched)</span></div>
    </div>
    <div class="stat-card blue">
        <div class="st-ico">🏅</div>
        <div><b><?= e($rank ? $rank['name'] : '—') ?></b><span>Current Rank</span></div>
    </div>
</div>

<div class="two-col">
    <div>
        <div class="card">
            <div class="card-title">📊 Business Summary</div>
            <table class="kv-table" style="width:100%">
                <tr><td>Left Team BV</td><td><b><?= bv($u['left_bv']) ?></b> &nbsp;<?= badge($teamCounts['L'] . ' members', 'info') ?></td></tr>
                <tr><td>Right Team BV</td><td><b><?= bv($u['right_bv']) ?></b> &nbsp;<?= badge($teamCounts['R'] . ' members', 'info') ?></td></tr>
                <tr><td>Self Purchase BV</td><td><b><?= bv($u['self_bv']) ?></b></td></tr>
                <tr><td>Carry Forward (unmatched BV)</td><td><b><?= bv($carry) ?></b></td></tr>
                <tr><td>Direct Referrals</td><td><b><?= $directs ?></b> (<?= $directActive ?> active)</td></tr>
                <tr><td>Account Status</td><td><?= (int)$u['is_active'] ? badge('Active', 'success') : badge('Inactive', 'warning') ?></td></tr>
                <tr><td>KYC Status</td><td><?= status_badge($u['kyc_status']) ?></td></tr>
                <tr><td>Total Withdrawn</td><td><b><?= money($u['total_withdrawn']) ?></b></td></tr>
            </table>
        </div>

        <div class="card">
            <div class="card-title">🧾 Recent Orders <a class="right" href="orders.php">View all →</a></div>
            <?php if (!$recentOrders): ?>
                <div class="empty-state"><span class="es-ico">📦</span>No orders yet. <a href="shop.php"><b>Visit the shop</b></a></div>
            <?php else: ?>
            <div class="table-wrap" style="box-shadow:none">
                <table class="table">
                    <tr><th>Order</th><th>Date</th><th>DP Total</th><th>BV</th><th>Status</th></tr>
                    <?php foreach ($recentOrders as $o): ?>
                    <tr>
                        <td><a href="order_view.php?id=<?= (int)$o['id'] ?>"><?= e($o['order_no']) ?></a></td>
                        <td><?= dmy($o['created_at']) ?></td>
                        <td><?= money($o['total_dp']) ?></td>
                        <td><?= bv($o['total_bv']) ?></td>
                        <td><?= status_badge($o['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-title">📣 Announcements</div>
            <?php if (!$announcements): ?>
                <p style="color:var(--ink-soft)">No announcements.</p>
            <?php else: foreach ($announcements as $a): ?>
                <div class="ann-item">
                    <b><?= e($a['title']) ?></b>
                    <p><?= e($a['content']) ?></p>
                    <span class="ann-date"><?= dmy($a['created_at'], true) ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="card">
            <div class="card-title">💹 Income Breakdown</div>
            <table class="kv-table" style="width:100%">
                <tr><td>Leg Completion Income (₹300 L + ₹300 R)</td><td><b><?= money($earn['sponsor']) ?></b></td></tr>
                <tr><td>Matching Bonus (first pair ₹450)</td><td><b><?= money($earn['binary']) ?></b></td></tr>
                <tr><td>50% Direct Sponsor Bonus (₹225)</td><td><b><?= money($earn['sponsor_matching']) ?></b></td></tr>
                <tr><td>Rank Cash Rewards (not in payout)</td><td><b><?= money($earn['rank']) ?></b></td></tr>
                <tr><td>Award Rewards (not in payout)</td><td><b><?= money($earn['award']) ?></b></td></tr>
            </table>
            <a class="btn btn-outline btn-sm mt-2" href="earnings.php">Full Statement →</a>
        </div>

        <div class="card">
            <div class="card-title">📊 BV &amp; Points Log</div>
            <?php
            $bvLogs = q_all("SELECT * FROM bv_logs WHERE user_id = ? ORDER BY id DESC LIMIT 6", [$u['id']]);
            $ptLogs = q_all("SELECT * FROM points_logs WHERE user_id = ? ORDER BY id DESC LIMIT 3", [$u['id']]);
            $logs = [];
            foreach ($ptLogs as $p) { $logs[] = ['d' => $p['created_at'], 't' => 'point', 'txt' => '+' . (int)$p['points'] . ' points (total ' . number_format((int)$p['total_points']) . ' P) — L ' . bv($p['left_bv']) . ' / R ' . bv($p['right_bv'])]; }
            foreach ($bvLogs as $b) { $logs[] = ['d' => $b['created_at'], 't' => $b['kind'], 'txt' => '+' . bv($b['bv']) . ' ' . $b['kind'] . ' BV (total ' . bv($b['balance_after']) . ')']; }
            usort($logs, function ($x, $y) { return strcmp($y['d'], $x['d']); });
            ?>
            <?php if (!$logs): ?>
                <div class="empty-state"><span class="es-ico">📊</span>No BV or points yet. Your self purchases
                    and your team's purchases will appear here.</div>
            <?php else: ?>
            <table class="kv-table" style="width:100%">
                <?php foreach (array_slice($logs, 0, 8) as $l): ?>
                <tr>
                    <td>
                        <?php if ($l['t'] === 'point'): ?><?= badge('POINTS', 'info') ?>
                        <?php elseif ($l['t'] === 'self'): ?><?= badge('SELF BV', 'warning') ?>
                        <?php elseif ($l['t'] === 'left'): ?><?= badge('LEFT BV', 'success') ?>
                        <?php else: ?><?= badge('RIGHT BV', 'success') ?><?php endif; ?>
                        <?= e($l['txt']) ?>
                    </td>
                    <td style="text-align:right;color:var(--ink-soft)"><?= dmy($l['d'], true) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <p class="form-hint" style="margin-top:8px">Full history: every BV entry (self / left / right) and every points award is logged. Rank &amp; award milestones are on the <a href="plan.php">Marketing Plan</a> page.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-title">💳 Payout Status <a class="right" href="payout.php">View all →</a></div>
            <?php $payouts = q_all("SELECT * FROM payouts WHERE user_id = ? ORDER BY id DESC LIMIT 4", [$u['id']]); ?>
            <?php if (!$payouts): ?>
                <div class="empty-state"><span class="es-ico">🏦</span>No payouts yet. Eligible payout income:
                    <b><?= money(payout_eligible_amount($u['id'])) ?></b></div>
            <?php else: ?>
            <table class="kv-table" style="width:100%">
                <?php foreach ($payouts as $p): ?>
                <tr>
                    <td>
                        <b><?= e($p['request_no']) ?></b> · <?= dmy($p['created_at']) ?><br>
                        <small style="color:#000">Gross <?= money($p['amount']) ?> · TDS <?= money($p['tds_amount']) ?>
                            · Charge <?= money($p['admin_charge']) ?></small>
                    </td>
                    <td style="text-align:right">
                        <b><?= money($p['net_amount']) ?></b><br>
                        <?php if ($p['status'] === 'pending'): ?>
                            <?= badge('⏳ Payout in process', 'warning') ?>
                        <?php elseif ($p['status'] === 'paid'): ?>
                            <?= badge('✅ Payment is done ' . ($p['paid_at'] ? dmy($p['paid_at']) : ''), 'success') ?>
                        <?php else: ?>
                            <?= status_badge($p['status']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-title">👛 Recent Wallet Activity <a class="right" href="wallet.php">View all →</a></div>
            <?php if (!$recentTx): ?>
                <p style="color:var(--ink-soft)">No transactions yet.</p>
            <?php else: ?>
            <table class="kv-table" style="width:100%">
                <?php foreach ($recentTx as $t): ?>
                <tr>
                    <td><?= dmy($t['created_at']) ?><br><small style="color:#000"><?= e($t['note']) ?></small></td>
                    <td style="text-align:right;color:#000;font-weight:400">
                        <?= $t['type'] === 'credit' ? '+' : '−' ?><?= money($t['amount']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
