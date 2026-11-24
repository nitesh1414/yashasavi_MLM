<?php
/** Member dashboard: live values, referral links and network summary. */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();
$earn = user_earnings_breakdown($u['id']);
$team = team_counts($u);
$rank = $u['rank_id'] ? q_row("SELECT name FROM ranks WHERE id = ?", [$u['rank_id']]) : null;
$plan = get_plan();
$announcements = q_all("SELECT title, content, created_at FROM announcements WHERE status='active' ORDER BY created_at DESC, id DESC LIMIT 3");
$recentOrders = q_all("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 5", [$u['id']]);
$recentTx = q_all("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 5", [$u['id']]);
$bvLogs = q_all("SELECT * FROM bv_logs WHERE user_id = ? ORDER BY id DESC LIMIT 5", [$u['id']]);
$pointLogs = q_all("SELECT * FROM points_logs WHERE user_id = ? ORDER BY id DESC LIMIT 3", [$u['id']]);
$eligible = payout_eligible_amount($u['id']);
$award = q_row("SELECT reward_title FROM award_rewards WHERE status = 'active' AND points <= ? ORDER BY points DESC LIMIT 1", [(int)$u['reward_points']]);
$referralPath = '../register.php?ref=' . rawurlencode($u['username']) . '&leg=';
$refLeft = url('register.php?ref=' . rawurlencode($u['username']) . '&leg=L');
$refRight = url('register.php?ref=' . rawurlencode($u['username']) . '&leg=R');
$initials = strtoupper(substr(trim($u['full_name']), 0, 1));
$cards = [
    ['My Package', null, 'fa-cubes', 'green', 'orders.php', 'Package tracking is not configured'],
    ['Direct Sponsor Income', $earn['sponsor'], 'fa-user-plus', 'orange', 'earnings.php?type=sponsor', 'Income from your green direct members'],
    ['Matching Income', $earn['binary'], 'fa-sitemap', 'blue', 'earnings.php?type=binary', 'Income from matched team BV'],
    ['Direct Income', $earn['sponsor_matching'], 'fa-user-friends', 'orange', 'earnings.php?type=sponsor_matching', 'Direct sponsor matching bonus'],
    ['Cash Reward', $earn['rank'] + $earn['award'], 'fa-money-bill-wave', 'gold', 'payout-rewards.php', 'Credited rank and award income'],
    ['Royalty Income', $earn['level'], 'fa-chart-pie', 'red', 'payout-rewards.php', 'Credited royalty income'],
    ['Incentive Payout Balance', $eligible, 'fa-wallet', 'red', 'payout-balance.php', 'Eligible income before payout deductions'],
];
$activeKey = 'dashboard'; $pageTitle = 'Dashboard'; $wideContent = true;
$bodyClass = 'member-dashboard-page';
$pageStylesFiles = [url('assets/css/member-dashboard.css') . '?v=' . APP_VERSION];
$pageScriptsFiles = [url('assets/js/member-dashboard.js') . '?v=' . APP_VERSION];
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>
<div class="member-dashboard">
    <div class="md-heading"><div><span class="md-overline">YOUR MEMBER SPACE</span><h1>Dashboard</h1></div><span class="md-heading-icon" aria-hidden="true"><i class="fas fa-chevron-right"></i></span></div>

    <?php if (!$u['is_active']): ?>
    <div class="alert alert-warning md-alert">Your account is not green yet. Complete <b><?= bv($plan['activation_bv']) ?></b> in approved self purchases to activate it.
        Current self BV: <b><?= bv($u['self_bv']) ?></b>. <a href="shop.php"><b>Visit the shop →</b></a></div>
    <?php endif; ?>

    <section class="md-overview" aria-label="Referral links and updates">
        <div class="md-panel md-referrals">
            <div class="md-panel-title"><div><span class="md-eyebrow">GROW YOUR TEAM</span><h2>Your Referral Link</h2></div><i class="fas fa-link" aria-hidden="true"></i></div>
            <div class="md-referral-row">
                <label class="sr-only" for="md-ref-left">Left referral link</label>
                <input id="md-ref-left" class="md-ref-input" type="text" readonly value="<?= e($refLeft) ?>" data-relative-url="<?= e($referralPath . 'L') ?>">
                <button type="button" class="md-copy" data-copy="md-ref-left"><i class="far fa-copy" aria-hidden="true"></i> Copy Left Referral</button>
            </div>
            <div class="md-referral-row">
                <label class="sr-only" for="md-ref-right">Right referral link</label>
                <input id="md-ref-right" class="md-ref-input" type="text" readonly value="<?= e($refRight) ?>" data-relative-url="<?= e($referralPath . 'R') ?>">
                <button type="button" class="md-copy" data-copy="md-ref-right"><i class="far fa-copy" aria-hidden="true"></i> Copy Right Referral</button>
            </div>
            <span class="md-copy-status" role="status" aria-live="polite"></span>
        </div>
        <div class="md-panel md-updates">
            <div class="md-panel-title"><div><span class="md-eyebrow">STAY IN THE LOOP</span><h2>Latest Updates</h2></div><span class="md-update-icon"><i class="fas fa-bullhorn" aria-hidden="true"></i></span></div>
            <?php if (!$announcements): ?><p class="md-update-empty">No updates at the moment. Check back soon.</p>
            <?php else: foreach ($announcements as $a): ?><div class="md-update-item"><span class="md-update-dot"></span><div><b><?= e($a['title']) ?></b><p><?= e($a['content']) ?></p><small><?= dmy($a['created_at']) ?></small></div></div><?php endforeach; endif; ?>
        </div>
        <a class="md-register" href="add-member.php"><span class="md-register-icon"><i class="fas fa-user-plus" aria-hidden="true"></i></span><span><b>Register Now</b><small>Build your team <i class="fas fa-arrow-right" aria-hidden="true"></i></small></span></a>
    </section>

    <section class="md-income" aria-label="Income summary">
        <?php foreach ($cards as [$title, $value, $icon, $tone, $link, $hint]): ?>
        <a class="md-income-card" href="<?= e($link) ?>" title="<?= e($hint) ?>">
            <span class="md-card-icon <?= e($tone) ?>"><i class="fas <?= e($icon) ?>" aria-hidden="true"></i></span>
            <span class="md-card-title"><?= e($title) ?></span>
            <span class="md-card-value"><?= $value === null ? '—' : money($value) ?></span>
            <span class="md-card-hint"><?= $value === null ? 'Not configured' : 'View details' ?> <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
        </a>
        <?php endforeach; ?>
    </section>

    <section class="md-member-row" aria-label="Rewards and member profile">
        <div class="md-reward-grid">
            <a class="md-mini-card" href="payout-rewards.php"><span class="md-mini-icon"><i class="fas fa-car" aria-hidden="true"></i></span><span><small>Car Fund</small><b><?= money($earn['car_fund']) ?></b></span><i class="fas fa-chevron-right md-mini-arrow" aria-hidden="true"></i></a>
            <a class="md-mini-card" href="plan.php"><span class="md-mini-icon"><i class="fas fa-plane" aria-hidden="true"></i></span><span><small>Tour Vacation</small><b>—</b><em>Not configured</em></span><i class="fas fa-chevron-right md-mini-arrow" aria-hidden="true"></i></a>
            <a class="md-mini-card" href="plan.php"><span class="md-mini-icon"><i class="fas fa-trophy" aria-hidden="true"></i></span><span><small>Award &amp; Rewards</small><b><?= e($award ? $award['reward_title'] : '—') ?></b><?php if ($award): ?><em>Eligible milestone</em><?php endif; ?></span><i class="fas fa-chevron-right md-mini-arrow" aria-hidden="true"></i></a>
            <a class="md-mini-card" href="earnings.php"><span class="md-mini-icon"><i class="fas fa-coins" aria-hidden="true"></i></span><span><small>Total Income</small><b><?= money($earn['total']) ?></b></span><i class="fas fa-chevron-right md-mini-arrow" aria-hidden="true"></i></a>
        </div>
        <div class="md-profile-card">
            <div class="md-profile-person"><span class="md-avatar"><?= e($initials) ?></span><div><span class="md-eyebrow">MEMBER PROFILE</span><b><?= e($u['full_name']) ?></b><span class="md-rank">RANK: <?= e($rank ? strtoupper($rank['name']) : '—') ?></span><span class="md-id"><?= e($u['username']) ?> · <?= (int)$u['is_active'] ? 'Green ID' : 'Not active' ?></span></div></div>
            <div class="md-profile-meta">
                <div><i class="fas fa-envelope" aria-hidden="true"></i><span><?= e($u['email'] ?: 'Email not provided') ?></span></div>
                <div><i class="far fa-calendar-alt" aria-hidden="true"></i><span>Joined <?= dmy($u['created_at'], true) ?></span></div>
                <div><i class="fas fa-phone" aria-hidden="true"></i><span><?= e($u['mobile'] ?: 'Phone not provided') ?></span></div>
                <div><i class="far fa-clock" aria-hidden="true"></i><span>Last login <?= $u['last_login'] ? dmy($u['last_login'], true) : '—' ?></span></div>
            </div>
        </div>
    </section>

    <section class="md-team-grid" aria-label="Network summary">
        <a class="md-team-card" href="team.php?type=downline&amp;leg=L"><span>Left Team</span><b><?= number_format($team['L']) ?></b></a>
        <a class="md-team-card" href="team.php?type=downline&amp;leg=R"><span>Right Team</span><b><?= number_format($team['R']) ?></b></a>
        <a class="md-team-card green" href="tree.php"><span>Left BV</span><b><?= bv($u['left_bv']) ?></b></a>
        <a class="md-team-card green" href="tree.php"><span>Right BV</span><b><?= bv($u['right_bv']) ?></b></a>
    </section>

    <div class="md-quick-links"><a href="wallet.php"><i class="fas fa-wallet" aria-hidden="true"></i> Wallet <b><?= money($u['wallet_balance']) ?></b></a><a href="plan.php"><i class="fas fa-star" aria-hidden="true"></i> Points <b><?= number_format((int)$u['reward_points']) ?> P</b></a><a href="orders.php"><i class="fas fa-box" aria-hidden="true"></i> Your orders <b>View →</b></a></div>

    <details class="md-activity">
        <summary>Business &amp; recent activity <span>Orders, BV, points and wallet history</span></summary>
        <div class="md-activity-grid">
            <div class="md-activity-section"><h3>Recent orders <a href="orders.php">View all →</a></h3>
                <?php if (!$recentOrders): ?><p>No orders yet. <a href="shop.php">Visit the shop</a>.</p><?php else: ?>
                <?php foreach ($recentOrders as $o): ?><div class="md-activity-row"><a href="order_view.php?id=<?= (int)$o['id'] ?>"><?= e($o['order_no']) ?></a><span><?= dmy($o['created_at']) ?> · <?= e($o['status']) ?></span><b><?= money($o['total_dp']) ?></b></div><?php endforeach; endif; ?>
            </div>
            <div class="md-activity-section"><h3>Wallet activity <a href="wallet.php">View all →</a></h3>
                <?php if (!$recentTx): ?><p>No wallet transactions yet.</p><?php else: ?>
                <?php foreach ($recentTx as $t): ?><div class="md-activity-row"><span><?= e($t['note']) ?><small><?= dmy($t['created_at']) ?></small></span><b><?= $t['type'] === 'credit' ? '+' : '−' ?><?= money($t['amount']) ?></b></div><?php endforeach; endif; ?>
            </div>
            <div class="md-activity-section"><h3>BV &amp; points</h3>
                <?php if (!$bvLogs && !$pointLogs): ?><p>No BV or points activity yet.</p><?php endif; ?>
                <?php foreach ($pointLogs as $p): ?><div class="md-activity-row"><span>Points earned <small><?= dmy($p['created_at']) ?></small></span><b>+<?= (int)$p['points'] ?> P</b></div><?php endforeach; ?>
                <?php foreach ($bvLogs as $b): ?><div class="md-activity-row"><span><?= e(ucfirst($b['kind'])) ?> BV <small><?= dmy($b['created_at']) ?></small></span><b>+<?= bv($b['bv']) ?></b></div><?php endforeach; ?>
            </div>
        </div>
    </details>
    <div class="md-footer"><span><b><?= e(setting('site_name')) ?></b> · Member dashboard</span><span>Last login: <?= $u['last_login'] ? dmy($u['last_login'], true) : '—' ?></span></div>
</div>
<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
