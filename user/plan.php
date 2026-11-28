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

$activeKey = 'plan';
$pageTitle = 'Marketing Plan';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';

$unit = max(1, (float)$plan['pair_unit_bv']);
/* payout rate for one matched pair of the plan unit (₹450 on the current plan) */
$pairPay = income_pair_rate($plan);
$sponsorPay = round((float)$plan['activation_bv'] * (float)$plan['sponsor_percent'] / 100, 2);
?>

<div class="two-col">
    <div>
        <div class="card">
            <div class="card-title">1. 🤝 Direct Sponsor Income</div>
            <div class="pt-chips"><span class="pt-chip green">Each qualifying personally sponsored member: <b><?= money($sponsorPay) ?></b></span></div>
            <div class="plan-box">
                When a member you personally sponsor completes <b><?= e($plan['activation_bv']) ?> Self BV</b>,
                you receive <b><?= money($sponsorPay) ?></b> once, subject to your own eligibility.
                Their placement can be anywhere; this income goes to their sponsor, not automatically to their placement parent.
                There is no one-per-leg limit.
                <table class="kv-table" style="width:100%;margin-top:10px">
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
                            <span class="pt-sub">Left BV <?= e($plan['pair_unit_bv']) ?></span>
                        </div>
                    </div>
                    <div class="pt-kid">
                        <div class="pt-node">
                            <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                            <span class="pt-name">RIGHT (R)</span>
                            <span class="pt-sub">Right BV <?= e($plan['pair_unit_bv']) ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <table class="table" style="width:100%">
                <tr><th>Left BV</th><th>Right BV</th><th>Matched BV</th><th>Payout</th></tr>
                <tr>
                    <td><?= e($plan['pair_unit_bv']) ?></td>
                    <td><?= e($plan['pair_unit_bv']) ?></td>
                    <td><b><?= e($plan['pair_unit_bv']) ?></b> = 1 pair</td>
                    <td><b><?= money($pairPay) ?></b></td>
                </tr>
            </table>
            <div class="plan-box">
                Each <b><?= e($plan['pair_unit_bv']) ?> BV left + <?= e($plan['pair_unit_bv']) ?> BV right</b>
                produces one pair, paid at <b><?= money($pairPay) ?></b> for <b>every eligible pair</b>, including the first.
                Earning sponsor income is not a prerequisite. Unmatched BV carries forward.
                <?= (int)$plan['matching_requires_active'] ? 'Your own joining eligibility is required.' : 'The current plan does not require joining for matching income.' ?>
                <table class="kv-table" style="width:100%;margin-top:10px">
                    <tr><td>Your left leg BV</td><td><b><?= bv($u['left_bv']) ?></b></td></tr>
                    <tr><td>Your right leg BV</td><td><b><?= bv($u['right_bv']) ?></b></td></tr>
                    <tr><td>Matched BV (weaker side)</td><td><b><?= bv(min((float)$u['left_bv'], (float)$u['right_bv'])) ?></b></td></tr>
                    <tr><td>Pairs matched so far</td><td><b><?= (int)$u['matched_pairs'] ?></b></td></tr>
                    <tr><td>Matching bonus income earned</td><td><b><?= money($earn['binary']) ?></b></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-title">3. 💫 Placement Parent First-Match Bonus</div>
            <div class="plan-tree">
                <div class="pt-node you">
                    <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                    <span class="pt-name">YOU</span>
                    <span class="pt-sub">Placement parent</span>
                </div>
                <div class="pt-stem"></div>
                <div class="pt-node">
                    <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                    <span class="pt-name">Immediate Child</span>
                    <span class="pt-sub">placed directly under you</span>
                </div>
                <div class="pt-branch"></div>
                <div class="pt-kids">
                    <div class="pt-kid">
                        <div class="pt-node">
                            <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                            <span class="pt-name">LEFT (L)</span>
                            <span class="pt-sub"><?= e($plan['pair_unit_bv']) ?> BV</span>
                        </div>
                    </div>
                    <div class="pt-kid">
                        <div class="pt-node">
                            <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                            <span class="pt-name">RIGHT (R)</span>
                            <span class="pt-sub"><?= e($plan['pair_unit_bv']) ?> BV</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="pt-chips">
                <span class="pt-chip">Member's Matching Bonus: <b><?= money($pairPay) ?></b></span>
                <span class="pt-chip gold">Your <?= e($plan['sponsor_matching_percent']) ?>% first-match bonus: <b><?= money($pairPay * $plan['sponsor_matching_percent'] / 100) ?></b></span>
            </div>
            <div class="plan-box">
                When an <b>immediate placement child</b> earns their first matching pair,
                their eligible placement parent receives <b><?= e($plan['sponsor_matching_percent']) ?>%</b> of that first pair,
                currently <b><?= money($pairPay * (float)$plan['sponsor_matching_percent'] / 100) ?></b>.
                The bonus is paid <b>once per child</b>, regardless of who sponsored that child; later pairs do not trigger this bonus.
                <table class="kv-table" style="width:100%;margin-top:10px">
                    <tr><td>Placement first-match bonus earned</td><td><b><?= money($earn['sponsor_matching']) ?></b></td></tr>
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
                Every matched pair adds <b><?= (int)$plan['points_per_unit'] ?> point(s)</b> when you are eligible.
                Points need your own <b>Self <?= e($plan['activation_bv']) ?> BV</b> or manual earning activation and drive the <b>rank achievements</b> (each rank
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
                Your points: <b><?= number_format($points) ?> P</b> (<?= (int)$plan['points_per_unit'] ?> point(s) per matched pair)
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
                Lifetime points milestones — <b><?= (int)$plan['points_per_unit'] ?> point(s) per matched pair</b>. Recognition only — no award commission is credited.
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
                <tr><td>Pair unit</td><td><?= e($plan['pair_unit_bv']) ?> BV on each side</td></tr>
                <tr><td>Points per matched pair</td><td><?= (int)$plan['points_per_unit'] ?> P</td></tr>
                <tr><td>Payout commission</td><td>Sponsor income + all eligible matching pairs + placement-child first-match bonuses + rank rewards</td></tr>
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
