<?php
/** Marketing plan explanation + my live numbers */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

$plan = get_plan();
$earn = user_earnings_breakdown($u['id']);
$ranks = q_all("SELECT * FROM ranks ORDER BY min_points ASC, id ASC");
$awards = q_all("SELECT * FROM award_rewards WHERE status = 'active' ORDER BY points ASC");
$points = (int)$u['reward_points'];
$directs = (int)q_val("SELECT COUNT(*) FROM users WHERE sponsor_id = ? AND is_active = 1", [$u['id']]);
$qual = income_policy_ready() ? q_row("SELECT left_eligible_bv, right_eligible_bv FROM matching_state WHERE user_id = ?", [$u['id']]) : null;

$activeKey = 'plan';
$pageTitle = 'Marketing Plan';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';

$unit = max(1, (float)$plan['pair_unit_bv']);
/* payout rate for one matched pair of the plan unit (₹450 on the current plan) */
$pairPay = $plan['binary_type'] === 'percent'
    ? $unit * (float)$plan['binary_value'] / 100
    : (float)$plan['binary_value'];
?>

<div class="two-col">
    <div>
        <div class="card">
            <div class="card-title">1. 🤝 Direct Sponsor Income</div>
            <div class="plan-tree">
                <div class="pt-node you">
                    <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                    <span class="pt-name">YOU</span>
                </div>
                <div class="pt-branch"></div>
                <div class="pt-kids">
                    <div class="pt-kid">
                        <div class="pt-node">
                            <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                            <span class="pt-name">LEFT LEG</span>
                            <span class="pt-sub">all downline members</span>
                        </div>
                    </div>
                    <div class="pt-kid">
                        <div class="pt-node">
                            <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                            <span class="pt-name">RIGHT LEG</span>
                            <span class="pt-sub">all downline members</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="pt-chips">
                <span class="pt-chip">Joining threshold: <b><?= bv($plan['activation_bv']) ?></b></span>
                <span class="pt-chip green">Each green direct: <b><?= money((float)$plan['activation_bv'] * (float)$plan['sponsor_percent'] / 100) ?></b></span>
            </div>
            <div class="plan-box">
                Each time one of your <b>directly sponsored members becomes green</b>, you receive
                <b><?= money((float)$plan['activation_bv'] * (float)$plan['sponsor_percent'] / 100) ?></b> once for that member.
                The payment goes to their actual sponsor even when they are placed under someone else.
                There is no one-per-leg limit; your own account must be eligible to receive the payment.
                <table class="kv-table" style="width:100%;margin-top:10px">
                    <tr><td>Your left leg BV</td><td><b><?= bv($u['left_bv']) ?></b></td></tr>
                    <tr><td>Your right leg BV</td><td><b><?= bv($u['right_bv']) ?></b></td></tr>
                    <tr><td>Direct sponsor income earned</td><td><b><?= money($earn['sponsor']) ?></b></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-title">2. 💠 Matching Pair Income</div>
            <div class="plan-tree">
                <div class="pt-node you">
                    <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                    <span class="pt-name">YOU</span>
                </div>
                <div class="pt-branch"></div>
                <div class="pt-kids">
                    <div class="pt-kid">
                        <div class="pt-node">
                            <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                            <span class="pt-name">LEFT (L)</span>
                            <span class="pt-sub">Left <?= bv($unit) ?></span>
                        </div>
                    </div>
                    <div class="pt-kid">
                        <div class="pt-node">
                            <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                            <span class="pt-name">RIGHT (R)</span>
                            <span class="pt-sub">Right <?= bv($unit) ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <table class="table" style="width:100%">
                <tr><th>Left BV</th><th>Right BV</th><th>Matched BV</th><th>Payout</th></tr>
                <tr>
                    <td><?= bv($unit) ?></td>
                    <td><?= bv($unit) ?></td>
                    <td><b><?= bv($unit) ?></b> = 1 pair</td>
                    <td><b><?= money($pairPay) ?></b></td>
                </tr>
            </table>
            <div class="plan-box">
                Every <b><?= bv($unit) ?> of approved team BV on the left</b> matched with
                <b><?= bv($unit) ?> on the right</b> earns <b><?= money($pairPay) ?></b>.
                Each pair pays regardless of who sponsored the contributing members or whether those members are green.
                Unmatched team BV carries forward.
                <?php if ((int)$plan['matching_requires_active']): ?> Your own ID must be green to receive matching income.<?php endif; ?>
                <table class="kv-table" style="width:100%;margin-top:10px">
                    <tr><td>Your left leg BV</td><td><b><?= bv($u['left_bv']) ?></b></td></tr>
                    <tr><td>Your right leg BV</td><td><b><?= bv($u['right_bv']) ?></b></td></tr>
                    <tr><td>Matching left / right BV</td><td><b><?= bv($qual['left_eligible_bv'] ?? 0) ?> / <?= bv($qual['right_eligible_bv'] ?? 0) ?></b></td></tr>
                    <tr><td>Pairs matched so far</td><td><b><?= (int)$u['matched_pairs'] ?></b> (each new qualifying pair pays)</td></tr>
                    <tr><td>Matching bonus income earned</td><td><b><?= money($earn['binary']) ?></b></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-title">3. 💫 Direct Sponsor Matching Bonus Income</div>
            <div class="plan-tree">
                <div class="pt-node you">
                    <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                    <span class="pt-name">YOU</span>
                    <span class="pt-sub">Sponsor</span>
                </div>
                <div class="pt-stem"></div>
                <div class="pt-node">
                    <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                    <span class="pt-name">Direct Member</span>
                    <span class="pt-sub">directly sponsored by you</span>
                </div>
                <div class="pt-branch"></div>
                <div class="pt-kids">
                    <div class="pt-kid">
                        <div class="pt-node">
                            <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                            <span class="pt-name">LEFT (L)</span>
                            <span class="pt-sub"><?= bv($unit) ?></span>
                        </div>
                    </div>
                    <div class="pt-kid">
                        <div class="pt-node">
                            <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                            <span class="pt-name">RIGHT (R)</span>
                            <span class="pt-sub"><?= bv($unit) ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="pt-chips">
                <span class="pt-chip">Member's Matching Bonus: <b><?= money($pairPay) ?></b></span>
                <span class="pt-chip gold">Your <?= e($plan['sponsor_matching_percent']) ?>% Direct Sponsor Bonus: <b><?= money($pairPay * $plan['sponsor_matching_percent'] / 100) ?></b></span>
            </div>
            <div class="plan-box">
                When a <b>directly sponsored downline member in your LEFT leg</b> or in your
                <b>RIGHT leg</b> completes their own first Matching Pair Income (<?= money($pairPay) ?>), you receive
                <b><?= e($plan['sponsor_matching_percent']) ?>%</b> of that matching income —
                <b><?= money($pairPay * $plan['sponsor_matching_percent'] / 100) ?></b> — as your Direct Sponsor Matching Bonus.
                It is paid <b>once per direct member</b>, based on the sponsor and placement when the pair was earned. The original sponsor must be active and green when it settles; otherwise this first-pair bonus is not paid.
                <table class="kv-table" style="width:100%;margin-top:10px">
                    <tr><td>Sponsor matching earned</td><td><b><?= money($earn['sponsor_matching']) ?></b></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-title">🛒 Retail Income</div>
            <div class="plan-box">
                Buy at <b>Distributor Price (DP)</b> and sell at <b>MRP</b> — an instant
                <b>30–45% retail margin</b> on every product. Retail profit is yours directly;
                any retail bonus credited by the company appears in your earnings.
                <table class="kv-table" style="width:100%;margin-top:10px">
                    <tr><td>Retail income credited</td><td><b><?= money($earn['retail']) ?></b></td></tr>
                </table>
            </div>
        </div>

    </div>

    <div>
        <div class="card" style="border:1.5px solid var(--accent)">
            <div class="card-title">⭐ Points</div>
            <div class="plan-box">
                Each <?= bv($unit) ?> left + <?= bv($unit) ?> right pair earns <b><?= (int)$plan['points_per_unit'] ?> point(s)</b>.
                Points are added for <b>every matched pair</b>. Matching income is paid for every qualifying pair of team BV. Points need your own ID to be green (through approved joining BV or manual activation) and drive the <b>rank achievements</b> (each rank
                pays its reward into your wallet) and the <b>award milestones</b> below
                (recognition only).
                <table class="kv-table" style="width:100%;margin-top:10px">
                    <tr><td>Your points</td><td><b><?= number_format($points) ?> P</b></td></tr>
                    <tr><td>Pairs matched</td><td><b><?= (int)$u['matched_pairs'] ?></b></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-title">🏅 Rank Achievements</div>
            <p style="font-size:13px;color:var(--ink-soft);margin-bottom:14px">
                Your points: <b><?= number_format($points) ?> P</b> (<?= (int)$plan['points_per_unit'] ?> P per <?= bv($unit) ?> matched pair)
            </p>
            <?php $currentRankId = (int)$u['rank_id']; ?>
            <?php foreach ($ranks as $r): ?>
                <?php $achieved = $points >= (int)$r['min_points']; ?>
                <div class="plan-box" style="<?= $achieved ? 'background:#edf7ed;border-color:#a5d6a7' : '' ?>">
                    <b><?= e($r['name']) ?></b>
                    <?= ($currentRankId >= (int)$r['id']) ? badge('Achieved', 'success') : ($achieved ? badge('Pending review', 'warning') : '') ?>
                    <br>Points ≥ <b><?= number_format((int)$r['min_points']) ?> P</b>
                    <?php if ((float)$r['reward_amount'] > 0): ?> · Reward to wallet: <b><?= money($r['reward_amount']) ?></b><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <p style="font-size:12.5px;color:var(--ink-soft)">Ranks are checked automatically whenever your points grow.</p>
        </div>

        <div class="card">
            <div class="card-title">🏆 Award Rewards</div>
            <p style="font-size:13px;color:var(--ink-soft);margin-bottom:14px">
                Lifetime points milestones — <b><?= (int)$plan['points_per_unit'] ?> point(s) per <?= bv($unit) ?> matched pair</b>. Recognition only — no award commission is credited.
            </p>
            <?php foreach ($awards as $a): ?>
                <?php $got = array_key_exists((int)$a['id'], $earn['award_by_id']); ?>
                <div class="plan-box" style="<?= $points >= (int)$a['points'] ? 'background:#edf7ed;border-color:#a5d6a7' : '' ?>">
                    <b><?= e($a['reward_title']) ?></b> <?= $got ? badge('Received', 'success') : ($points >= (int)$a['points'] ? badge('Eligible', 'warning') : '') ?>
                    <br><?= number_format((int)$a['points']) ?> lifetime points
                    <?php if ($a['reward_type'] === 'cash' && (float)$a['amount'] > 0): ?> · Cash: <b><?= money($a['amount']) ?></b>
                    <?php else: ?> · Gift item<?php endif; ?>
                </div>
            <?php endforeach; ?>
            <table class="kv-table" style="width:100%;margin-top:10px">
                <tr><td>Award rewards received</td><td><b><?= number_format($points) ?> P — recognition only</b></td></tr>
            </table>
        </div>

        <div class="card">
            <div class="card-title">⚙️ Other Rules</div>
            <table class="kv-table" style="width:100%">
                <tr><td>Joining / eligibility BV</td><td><?= e($plan['activation_bv']) ?> BV self purchase</td></tr>
                <tr><td>Matching unit</td><td><?= bv($unit) ?> left + <?= bv($unit) ?> right</td></tr>
                <tr><td>Points per matched pair</td><td><?= (int)$plan['points_per_unit'] ?> P</td></tr>
                <tr><td>Payout commission</td><td><?= money((float)$plan['activation_bv'] * (float)$plan['sponsor_percent'] / 100) ?> per green direct member + <?= money($pairPay) ?> per eligible pair + <?= money($pairPay * (float)$plan['sponsor_matching_percent'] / 100) ?> first-pair sponsor matching bonus + rank rewards</td></tr>
                <tr><td>Rank rewards</td><td>credited to your wallet when the rank is achieved — included in payouts</td></tr>
                <tr><td>Payout minimum</td><td><?= money($plan['payout_min']) ?></td></tr>
                <tr><td>TDS on payout</td><td><?= e($plan['tds_percent']) ?>%</td></tr>
                <tr><td>Admin charge on payout</td><td><?= e($plan['admin_charge_percent']) ?>%</td></tr>
                <tr><td>Matching requires joining completed</td><td><?= (int)$plan['matching_requires_active'] ? 'Yes' : 'No' ?></td></tr>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
