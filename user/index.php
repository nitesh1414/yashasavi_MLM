<?php
/** Distributor dashboard */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/member_dashboard.php';
$u = require_user();
$wallet = weekly_wallet_snapshot($u['id']);
$u['wallet_balance'] = $wallet['wallet_balance'];
$u['total_withdrawn'] = $wallet['total_withdrawn'];
$reserved = $wallet['reserved'];
$spendable = $wallet['available'];

$earn = user_earnings_breakdown($u['id']);
$teamCounts = team_counts($u);
$rank = $u['rank_id'] ? q_row("SELECT * FROM ranks WHERE id = ?", [$u['rank_id']]) : null;
$directs = (int)q_val("SELECT COUNT(*) FROM users WHERE sponsor_id = ?", [$u['id']]);
$plan = get_plan();
$directActive = (int)q_val("SELECT COUNT(*) FROM users WHERE sponsor_id = ? AND status = 'active'
                            AND (is_active = 1 OR self_bv >= ?)", [$u['id'], $plan['activation_bv']]);
$rulesReady = income_rules_ready();
$matchingState = $rulesReady ? q_row("SELECT processed_bv FROM income_matching_state WHERE user_id = ?", [$u['id']]) : null;
$unmatchedLeft = $matchingState ? max(0, round((float)$u['left_bv'] - (float)$matchingState['processed_bv'], 2)) : null;
$unmatchedRight = $matchingState ? max(0, round((float)$u['right_bv'] - (float)$matchingState['processed_bv'], 2)) : null;
$announcements = q_all("SELECT * FROM announcements WHERE status='active' ORDER BY created_at DESC LIMIT 3");
$latestStatement = q_row("SELECT s.*, r.payout_done, r.gross, r.tds, r.admin_charge, r.net, r.reason,
                                 p.status AS payout_status, p.reject_reason AS payout_reason,
                                 w.status AS report_status, wr.status AS reservation_status
                          FROM payout_statement_rows r
                          JOIN payout_statements s ON s.id = r.statement_id
                          LEFT JOIN payouts p ON p.id = r.payout_id AND p.user_id = r.user_id
                          LEFT JOIN weekly_payout_runs w ON w.statement_id=s.id
                          LEFT JOIN weekly_payout_reservations wr ON wr.payout_id=r.payout_id AND wr.user_id=r.user_id AND wr.statement_id=r.statement_id
                          WHERE r.user_id = ? ORDER BY s.period_date DESC, s.id DESC LIMIT 1", [$u['id']]);
$recentTx = q_all("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 6", [$u['id']]);
$recentOrders = q_all("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 5", [$u['id']]);
$package = member_joining_package($u, $plan);
$carMilestone = member_display_milestone($u['reward_points'], $plan['car_fund_points'], $plan['car_fund_amount']);
$tourMilestone = member_display_milestone($u['reward_points'], setting('dashboard_tour_points', ''), setting('dashboard_tour_amount', ''));
$awardsReached = (int)q_val("SELECT COUNT(*) FROM award_rewards WHERE status='active' AND points > 0 AND points <= ?", [(int)$u['reward_points']]);
$eligibleGross = min($spendable, payout_eligible_amount($u['id']));
$referralUrl = url('register.php?ref=' . rawurlencode($u['username']));
$whatsappUrl = 'https://wa.me/?text=' . rawurlencode('Join my team: ' . $referralUrl);
$firstName = preg_split('/\\s+/', trim((string)$u['full_name']))[0] ?: $u['full_name'];
$matchingPercent = rtrim(rtrim(number_format((float)$plan['sponsor_matching_percent'], 2, '.', ''), '0'), '.');
[$carValue, $carStatus] = member_milestone_text($carMilestone);
[$tourValue, $tourStatus] = member_milestone_text($tourMilestone);
$packageValue = $package['amount'] === null ? '—' : money_txt($package['amount']);
$packageStatus = $package['status'] === 'qualified' ? 'Qualified'
    : ($package['status'] === 'in_progress' ? bv($u['self_bv']) . ' / ' . bv($package['target_bv']) : 'Not available');
$metrics = [
    ['package', 'My Package', 'fa-box-open', 'green', $packageValue, $packageStatus],
    ['sponsor', 'Direct Sponsor Income', 'fa-user-plus', 'blue', money_txt($earn['sponsor']), ''],
    ['matching', 'Direct Matching Income', 'fa-code-branch', 'violet', money_txt($earn['binary']), ''],
    ['first-match', $matchingPercent . '% Matching Income', 'fa-project-diagram', 'teal', money_txt($earn['sponsor_matching']), ''],
    ['cash-reward', 'Cash Reward', 'fa-medal', 'amber', money_txt($earn['rank']), ''],
    ['royalty', 'Royalty Income', 'fa-crown', 'rose', money_txt($earn['level']), ''],
    ['payout', 'Incentive Payout Balance', 'fa-wallet', 'green', money_txt($eligibleGross), 'Eligible gross'],
    ['car', 'Car Fund', 'fa-car', 'blue', $carValue, $carStatus],
    ['tour', 'Tour Vacation', 'fa-plane', 'violet', $tourValue, $tourStatus],
    ['awards', 'Awards & Rewards', 'fa-gift', 'amber', number_format($awardsReached), 'Milestones reached'],
    ['total', 'Total Income', 'fa-chart-line', 'teal', money_txt($earn['total']), ''],
];

$activeKey = 'dashboard';
$pageTitle = 'Dashboard';
$wideContent = true;
$bodyClass = 'member-dashboard-page';
$pageStylesFiles = [url('assets/css/member-dashboard.css') . '?v=' . APP_VERSION];
$pageScriptsFiles = [url('assets/vendor/qrcode-generator/qrcode.js') . '?v=' . APP_VERSION,
    url('assets/js/member-dashboard.js') . '?v=' . APP_VERSION];
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="member-dashboard">
    <section class="md-welcome" aria-label="Dashboard overview">
        <div>
            <div class="md-eyebrow">MEMBER OVERVIEW</div>
            <h1>Welcome back, <?= e($firstName) ?></h1>
            <p>Your business, at a glance.</p>
        </div>
        <div class="md-id-pill"><i class="fas fa-id-card" aria-hidden="true"></i><span>Member ID <b><?= e($u['username']) ?></b></span></div>
    </section>

    <section class="md-overview-grid" aria-label="Member information and referral link">
        <article class="md-panel md-profile">
            <div class="md-panel-header">
                <div><span class="md-eyebrow">YOUR ACCOUNT</span><h2>Member information</h2></div>
                <a class="md-text-link" href="profile.php">Edit profile <span aria-hidden="true">↗</span></a>
            </div>
            <dl class="md-profile-grid">
                <div><dt>Name</dt><dd><?= e($u['full_name']) ?></dd></div>
                <div><dt>Email</dt><dd><?= e($u['email'] ?: '—') ?></dd></div>
                <div><dt>Mobile number</dt><dd><?= e($u['mobile'] ?: '—') ?></dd></div>
                <div><dt>Joining date</dt><dd><?= e(dmy($u['created_at'])) ?></dd></div>
                <div><dt>Last login</dt><dd><?= e(dmy($u['last_login'], true)) ?></dd></div>
                <div><dt>Rank</dt><dd><span class="md-rank"><i class="fas fa-award" aria-hidden="true"></i> <?= e($rank ? $rank['name'] : 'Not ranked') ?></span></dd></div>
            </dl>
        </article>

        <article class="md-panel md-referral">
            <div class="md-panel-header">
                <div><span class="md-eyebrow">GROW YOUR NETWORK</span><h2>Share your referral link</h2></div>
                <span class="md-share-icon"><i class="fas fa-share-alt" aria-hidden="true"></i></span>
            </div>
            <div class="md-referral-body">
                <div class="md-referral-actions">
                    <label for="referral-link">Your referral link</label>
                    <div class="md-copy-row">
                        <input id="referral-link" value="<?= e($referralUrl) ?>" readonly spellcheck="false">
                        <button type="button" id="referral-copy"><i class="far fa-copy" aria-hidden="true"></i> Copy</button>
                    </div>
                    <a class="md-whatsapp" href="<?= e($whatsappUrl) ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-whatsapp" aria-hidden="true"></i> Share on WhatsApp</a>
                    <span class="md-copy-status" id="referral-copy-status" role="status" aria-live="polite"></span>
                </div>
                <div class="md-qr-wrap">
                    <button type="button" class="md-qr-open" id="referral-qr-open" aria-haspopup="dialog" aria-controls="referral-qr-dialog" aria-label="Enlarge referral QR code">
                        <span id="referral-qr" class="md-qr" aria-hidden="true">QR unavailable</span>
                    </button>
                    <span>Tap to enlarge</span>
                </div>
            </div>
            <dialog id="referral-qr-dialog" class="md-qr-dialog" role="dialog" aria-labelledby="referral-qr-title">
                <div class="md-qr-dialog-head"><h2 id="referral-qr-title">Scan to join</h2><button type="button" id="referral-qr-close" aria-label="Close QR code">×</button></div>
                <div id="referral-qr-large" class="md-qr-large" role="img" aria-label="Referral registration QR code">QR unavailable</div>
                <p>Scan this code to open your registration link.</p>
            </dialog>
        </article>
    </section>

    <section class="md-section" aria-labelledby="income-overview-title">
        <div class="md-section-header"><div><span class="md-eyebrow">YOUR EARNINGS</span><h2 id="income-overview-title">Income overview</h2></div><a class="md-text-link" href="earnings.php">View statement <span aria-hidden="true">→</span></a></div>
        <div class="md-metric-grid">
            <?php foreach ($metrics as [$key, $label, $icon, $tint, $value, $status]): ?>
            <article class="md-metric" data-metric="<?= e($key) ?>">
                <span class="md-metric-icon <?= e($tint) ?>"><i class="fas <?= e($icon) ?>" aria-hidden="true"></i></span>
                <span class="md-metric-label"><?= e($label) ?></span>
                <strong class="md-metric-value"><?= e($value) ?></strong>
                <?php if ($status !== ''): ?><span class="md-metric-status"><?= e($status) ?></span><?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="md-section" aria-labelledby="team-overview-title">
        <div class="md-section-header"><div><span class="md-eyebrow">YOUR NETWORK</span><h2 id="team-overview-title">Team overview</h2></div><a class="md-text-link" href="tree.php">View genealogy <span aria-hidden="true">→</span></a></div>
        <div class="md-team-grid">
            <div class="md-team-card"><span class="md-team-icon left"><i class="fas fa-users" aria-hidden="true"></i></span><div><span>Left team members</span><strong><?= number_format($teamCounts['L']) ?></strong></div></div>
            <div class="md-team-card"><span class="md-team-icon right"><i class="fas fa-users" aria-hidden="true"></i></span><div><span>Right team members</span><strong><?= number_format($teamCounts['R']) ?></strong></div></div>
            <div class="md-team-card"><span class="md-team-icon left"><i class="fas fa-chart-bar" aria-hidden="true"></i></span><div><span>Left BV</span><strong><?= e(bv($u['left_bv'])) ?></strong></div></div>
            <div class="md-team-card"><span class="md-team-icon right"><i class="fas fa-chart-bar" aria-hidden="true"></i></span><div><span>Right BV</span><strong><?= e(bv($u['right_bv'])) ?></strong></div></div>
        </div>
    </section>

    <?php if (!income_can_earn($u, $plan)): ?>
    <div class="alert alert-warning">
        ⚠️ Your account is <b>not activated</b> yet. Purchase products worth at least
        <b><?= e($plan['activation_bv']) ?> BV</b> (lifetime) from the <a href="shop.php"><b>Shop</b></a> to activate your
        account and start earning commissions. Current self BV: <b><?= bv($u['self_bv']) ?></b>.
    </div>
    <?php endif; ?>

    <section class="md-section" aria-labelledby="wallet-overview-title">
        <div class="md-section-header"><div><span class="md-eyebrow">BALANCES</span><h2 id="wallet-overview-title">Wallet overview</h2></div><a class="md-text-link" href="wallet.php">View wallet <span aria-hidden="true">→</span></a></div>
        <div class="stat-grid md-wallet-grid">
            <div class="stat-card"><div class="st-ico"><i class="fas fa-wallet" aria-hidden="true"></i></div><div><b><?= money($u['wallet_balance']) ?></b><span>Wallet Balance<br>(includes reservations)</span></div></div>
            <div class="stat-card gold"><div class="st-ico"><i class="fas fa-calendar-alt" aria-hidden="true"></i></div><div><b><?= money($reserved) ?></b><span>Reserved for weekly payouts</span></div></div>
            <div class="stat-card teal"><div class="st-ico"><i class="fas fa-credit-card" aria-hidden="true"></i></div><div><b><?= money($spendable) ?></b><span>Available to spend</span></div></div>
            <div class="stat-card teal"><div class="st-ico"><i class="fas fa-star" aria-hidden="true"></i></div><div><b><?= number_format((int)$u['reward_points']) ?> P</b><span>Points (<?= (int)$u['matched_pairs'] ?> pairs matched)</span></div></div>
            <div class="stat-card blue"><div class="st-ico"><i class="fas fa-award" aria-hidden="true"></i></div><div><b><?= e($rank ? $rank['name'] : '—') ?></b><span>Current Rank</span></div></div>
        </div>
    </section>

<div class="two-col">
    <div>
        <div class="card">
            <div class="card-title"><i class="fas fa-chart-line" aria-hidden="true"></i> Business Summary</div>
            <table class="kv-table" style="width:100%">
                <tr><td>Left Team BV</td><td><b><?= bv($u['left_bv']) ?></b> &nbsp;<?= badge($teamCounts['L'] . ' members', 'info') ?></td></tr>
                <tr><td>Right Team BV</td><td><b><?= bv($u['right_bv']) ?></b> &nbsp;<?= badge($teamCounts['R'] . ' members', 'info') ?></td></tr>
                <tr><td>Self Purchase BV</td><td><b><?= bv($u['self_bv']) ?></b></td></tr>
                <tr><td>Unmatched team BV (left / right)</td><td><?php if ($matchingState): ?>
                    <b><?= number_format($unmatchedLeft, 2) ?> / <?= number_format($unmatchedRight, 2) ?> BV</b>
                    <?php else: ?><?= $rulesReady ? 'Unavailable — contact support' : 'Available after income review' ?><?php endif; ?></td></tr>
                <tr><td>Direct Referrals</td><td><b><?= $directs ?></b> (<?= $directActive ?> earning eligible)</td></tr>
                <tr><td>Earning Status</td><td><?= income_can_earn($u, $plan) ? badge('Eligible', 'success') : badge('Not yet eligible', 'warning') ?></td></tr>
                <tr><td>KYC Status</td><td><?= status_badge($u['kyc_status']) ?></td></tr>
                <tr><td>Total Withdrawn</td><td><b><?= money($u['total_withdrawn']) ?></b></td></tr>
            </table>
        </div>

        <div class="card">
            <div class="card-title"><i class="fas fa-box" aria-hidden="true"></i> Recent Orders <a class="right" href="orders.php">View all →</a></div>
            <?php if (!$recentOrders): ?>
                <div class="empty-state"><span class="es-ico"><i class="fas fa-box" aria-hidden="true"></i></span>No orders yet. <a href="shop.php"><b>Visit the shop</b></a></div>
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
            <div class="card-title"><i class="fas fa-bullhorn" aria-hidden="true"></i> Announcements</div>
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
            <div class="card-title"><i class="fas fa-chart-pie" aria-hidden="true"></i> Income Breakdown</div>
            <table class="kv-table" style="width:100%">
                <tr><td>Direct Sponsor Income</td><td><b><?= money($earn['sponsor']) ?></b></td></tr>
                <tr><td>Matching Pair Income</td><td><b><?= money($earn['binary']) ?></b></td></tr>
                <tr><td>Placement Child First-Match Bonus</td><td><b><?= money($earn['sponsor_matching']) ?></b></td></tr>
                <tr><td>Rank Rewards</td><td><b><?= money($earn['rank']) ?></b></td></tr>
                <tr><td>Points — rank ladder</td><td><b><?= number_format((float)$u['reward_points']) ?> P</b></td></tr>
                <?php
                $bal = (float)$u['wallet_balance'];
                $available = min($spendable, payout_eligible_amount($u['id']));
                [$tdsAmt, $admAmt] = payout_deductions($available, $plan);
                ?>
                <tr><td><b>Wallet Balance (includes reserved)</b></td><td><b><?= money($bal) ?></b></td></tr>
                <tr><td>Weekly payout reservations</td><td><b><?= money($reserved) ?></b></td></tr>
                <tr><td>Available wallet balance</td><td><b><?= money($spendable) ?></b></td></tr>
                <tr><td>Additional eligible gross, limited by available wallet</td><td><b><?= money($available) ?></b></td></tr>
                <tr><td>Estimated TDS (<?= e($plan['tds_percent']) ?>%)</td><td style="color:var(--danger,crimson)"><b>− <?= money($tdsAmt) ?></b></td></tr>
                <tr><td>Estimated admin charge (<?= e($plan['admin_charge_percent']) ?>%)</td><td style="color:var(--danger,crimson)"><b>− <?= money($admAmt) ?></b></td></tr>
                <tr><td><b>Estimated net on that gross</b></td><td><b><?= money($available - $tdsAmt - $admAmt) ?></b></td></tr>
            </table>
            <p class="form-hint" style="margin-top:8px">This is an illustration using current rates, not a payout or a guarantee. Requests also require the minimum amount, active status, KYC and bank details. Actual deductions are recorded when a payout is created.</p>
            <a class="btn btn-outline btn-sm mt-2" href="earnings.php">Full Statement →</a>
        </div>

        <div class="card">
            <div class="card-title"><i class="fas fa-chart-bar" aria-hidden="true"></i> BV &amp; Points Log</div>
            <?php
            $bvLogs = q_all("SELECT * FROM bv_logs WHERE user_id = ? ORDER BY id DESC LIMIT 6", [$u['id']]);
            $ptLogs = q_all("SELECT * FROM points_logs WHERE user_id = ? ORDER BY id DESC LIMIT 3", [$u['id']]);
            $logs = [];
            foreach ($ptLogs as $p) { $logs[] = ['d' => $p['created_at'], 't' => 'point', 'txt' => ((int)$p['points'] >= 0 ? '+' : '') . (int)$p['points'] . ' points (total ' . number_format((int)$p['total_points']) . ' P) — L ' . bv($p['left_bv']) . ' / R ' . bv($p['right_bv'])]; }
            foreach ($bvLogs as $b) { $logs[] = ['d' => $b['created_at'], 't' => $b['kind'], 'txt' => ((float)$b['bv'] >= 0 ? '+' : '') . bv($b['bv']) . ' ' . $b['kind'] . ' (total ' . bv($b['balance_after']) . ')']; }
            usort($logs, function ($x, $y) { return strcmp($y['d'], $x['d']); });
            ?>
            <?php if (!$logs): ?>
                <div class="empty-state"><span class="es-ico"><i class="fas fa-chart-bar" aria-hidden="true"></i></span>No BV or points yet. Your self purchases
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

        <?php if ($latestStatement): ?>
        <div class="card">
            <div class="card-title"><i class="fas fa-file-alt" aria-hidden="true"></i> Latest Weekly Payout Statement
                <span class="right"><a class="btn btn-primary btn-sm" href="receipt.php?statement=<?= (int)$latestStatement['id'] ?>">⬇ Download Receipt</a></span>
            </div>
            <table class="kv-table" style="width:100%">
                <tr><td>Week</td><td><b><?= payout_statement_week_label($latestStatement) ?></b></td></tr>
                <tr><td>Generated</td><td><?= dmy($latestStatement['run_ts'], true) ?></td></tr>
                <tr><td>Payout amount (gross)</td><td><b><?= money($latestStatement['gross']) ?></b></td></tr>
                <tr><td>TDS + Admin at generation<?= (int)$latestStatement['payout_done'] ? '' : ' (illustrative)' ?></td><td>− <?= money((float)$latestStatement['tds'] + (float)$latestStatement['admin_charge']) ?></td></tr>
                <tr><td><b>Net at generation</b></td><td><b><?= money($latestStatement['net']) ?></b></td></tr>
                <tr><td>Payout status</td><td>
                    <?php [$statusLabel, $statusStyle] = payout_statement_status($latestStatement); ?>
                    <?= badge($statusLabel, $statusStyle) ?>
                    <?php if (!empty($latestStatement['payout_reason']) || (!(int)$latestStatement['payout_done'] && $latestStatement['reason'])): ?><br><small><?= e($latestStatement['payout_reason'] ?: $latestStatement['reason']) ?></small><?php endif; ?>
                </td></tr>
            </table>
            <?php if (!(int)$latestStatement['payout_done']): ?><p class="form-hint">No request was created; any displayed deductions were not applied.</p><?php endif; ?>
            <a class="btn btn-outline btn-sm mt-2" href="payout-balance.php#weekly-statements">All statements →</a>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-title"><i class="fas fa-university" aria-hidden="true"></i> Payout Status <a class="right" href="payout-balance.php">View all →</a></div>
            <?php $payouts = q_all("SELECT p.*, wr.status AS reservation_status, w.status AS report_status FROM payouts p
                                   LEFT JOIN weekly_payout_reservations wr ON wr.payout_id=p.id
                                   LEFT JOIN weekly_payout_runs w ON w.statement_id=wr.statement_id
                                   WHERE p.user_id = ? ORDER BY p.id DESC LIMIT 4", [$u['id']]); ?>
            <?php if (!$payouts): ?>
                <div class="empty-state"><span class="es-ico"><i class="fas fa-university" aria-hidden="true"></i></span>No payouts yet. Eligible payout income:
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
                        <?php [$label, $style] = payout_record_status($p); ?>
                        <?= badge($label, $style) ?>
                        <?php if ($p['paid_at']): ?><br><small><?= e(dmy($p['paid_at'])) ?></small><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-title"><i class="fas fa-wallet" aria-hidden="true"></i> Recent Wallet Activity <a class="right" href="wallet.php">View all →</a></div>
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
</div><!-- /member-dashboard -->

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
