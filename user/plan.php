<?php
/** Marketing plan explanation + my live numbers */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

$plan = get_plan();
$levels = get_plan_levels();
$earn = user_earnings_breakdown($u['id']);
$ranks = get_ranks();
$awards = q_all("SELECT * FROM award_rewards WHERE status = 'active' ORDER BY points ASC");
$teamBv = (float)$u['left_bv'] + (float)$u['right_bv'] + (float)$u['self_bv'];
$pointBv = max(1, (float)$plan['point_bv']);
$points = (int)floor($teamBv / $pointBv);
$directs = (int)q_val("SELECT COUNT(*) FROM users WHERE sponsor_id = ? AND is_active = 1", [$u['id']]);

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
                    <span class="pt-sub">Sponsor</span>
                </div>
                <div class="pt-stem"></div>
                <div class="pt-node">
                    <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                    <span class="pt-name">Direct Member</span>
                    <span class="pt-sub">directly sponsored by you</span>
                </div>
            </div>
            <div class="pt-chips">
                <span class="pt-chip">Joining amount: <b>₹6,000</b></span>
                <span class="pt-chip">Business Volume: <b>3,000 BV</b></span>
                <span class="pt-chip green">Your Direct Sponsor commission: <b><?= money(3000 * $plan['sponsor_percent'] / 100) ?></b></span>
            </div>
            <div class="plan-box">
                Every member you <b>directly sponsor</b> who joins with the ₹6,000 plan (3,000 BV) pays you a
                <b>direct sponsor commission of <?= money(3000 * $plan['sponsor_percent'] / 100) ?></b>
                (<?= e($plan['sponsor_percent']) ?>% of the joining BV) — on every eligible direct sponsorship.
                <table class="kv-table" style="width:100%;margin-top:10px">
                    <tr><td>Direct sponsor income earned</td><td><b><?= money($earn['sponsor']) ?></b></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-title">2. 💠 Matching Bonus Income</div>
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
                            <span class="pt-sub">Left BV 3,000</span>
                        </div>
                    </div>
                    <div class="pt-kid">
                        <div class="pt-node">
                            <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                            <span class="pt-name">RIGHT (R)</span>
                            <span class="pt-sub">Right BV 3,000</span>
                        </div>
                    </div>
                </div>
            </div>
            <table class="table" style="width:100%">
                <tr><th>Left BV</th><th>Right BV</th><th>Matched BV</th><th>Payout</th></tr>
                <tr>
                    <td>3,000</td>
                    <td>3,000</td>
                    <td><b>3,000</b> = 1 pair</td>
                    <td><b><?= money($pairPay) ?></b></td>
                </tr>
            </table>
            <div class="plan-box">
                Matching bonus is calculated on <b>eligible matching business volume</b>:
                3,000 BV on your <b>Left</b> leg + 3,000 BV on your <b>Right</b> leg = 1 matched pair,
                paid at the payout rate of <b><?= money($pairPay) ?></b> per <?= e($plan['pair_unit_bv']) ?> BV matched pair.
                <br><b>Eligibility rules:</b>
                your account must be an <b>active member</b><?= (int)$plan['matching_requires_active'] ? '' : ' (not required by current settings)' ?>;
                only <b>confirmed / approved order BV</b> counts;
                unmatched BV is <b><?= (int)$plan['carry_forward'] === 1 ? 'carried forward to the next matching' : 'flushed' ?></b>;
                <?php if ((float)$plan['daily_cap'] > 0): ?>daily cap: <b><?= money($plan['daily_cap']) ?></b>;<?php endif; ?>
                pair matching is 1:<?= e($plan['pair_unit_bv']) ?> on both legs.
                <table class="kv-table" style="width:100%;margin-top:10px">
                    <tr><td>Your left leg BV</td><td><b><?= bv($u['left_bv']) ?></b></td></tr>
                    <tr><td>Your right leg BV</td><td><b><?= bv($u['right_bv']) ?></b></td></tr>
                    <tr><td>Pairs matched so far</td><td><b><?= (int)$u['matched_pairs'] ?></b></td></tr>
                    <tr><td>Matching bonus income earned</td><td><b><?= money($earn['binary']) ?></b></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-title">3. 💫 50% Direct Sponsor Bonus Income</div>
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
                            <span class="pt-sub">3,000 BV</span>
                        </div>
                    </div>
                    <div class="pt-kid">
                        <div class="pt-node">
                            <i class="pt-ico fas fa-user" aria-hidden="true"></i>
                            <span class="pt-name">RIGHT (R)</span>
                            <span class="pt-sub">3,000 BV</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="pt-chips">
                <span class="pt-chip">Member's Matching Bonus: <b><?= money($pairPay) ?></b></span>
                <span class="pt-chip gold">Your 50% Direct Sponsor Bonus: <b><?= money($pairPay * $plan['sponsor_matching_percent'] / 100) ?></b></span>
            </div>
            <div class="plan-box">
                When <b>one of the legs</b> of your directly sponsored member completes a Matching Bonus Income
                (<?= money($pairPay) ?> in the example), you receive
                <b><?= e($plan['sponsor_matching_percent']) ?>%</b> of that matching income —
                <b><?= money($pairPay * $plan['sponsor_matching_percent'] / 100) ?></b> — as your Direct Sponsor Bonus.
                It applies to <b>every matching payout</b> of every directly sponsored member, with <b>no level limit</b>.
                <table class="kv-table" style="width:100%;margin-top:10px">
                    <tr><td>Sponsor matching earned</td><td><b><?= money($earn['sponsor_matching']) ?></b></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-title">🚗 Car Fund</div>
            <div class="plan-box">
                Accumulate <b><?= (int)$plan['car_fund_points'] ?> points</b>
                (1 point = <b><?= bv($pointBv) ?></b> of group BV — your own purchases + both legs) and receive
                <b><?= money($plan['car_fund_amount']) ?></b> towards a brand-new car,
                delivered directly from the showroom within <b>60 days</b>. One-time reward.
                <table class="kv-table" style="width:100%;margin-top:10px">
                    <tr><td>Your points</td><td><b><?= $points ?> P</b> of <?= (int)$plan['car_fund_points'] ?> P</td></tr>
                    <tr><td>Car fund received</td><td><b><?= money($earn['car_fund']) ?></b></td></tr>
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

        <div class="card">
            <div class="card-title">📈 Level Income</div>
            <div class="plan-box">
                You earn level income on purchases made by your sponsor upline chain,
                up to <b><?= (int)$plan['level_depth'] ?> levels</b> deep:
            </div>
            <table class="table" style="width:100%">
                <tr><th>Level</th><th>Income %</th><th>On a <?= e($plan['pair_unit_bv']) ?> BV purchase</th></tr>
                <?php for ($i = 1; $i <= (int)$plan['level_depth']; $i++): ?>
                <tr>
                    <td>Level <?= $i ?></td>
                    <td><b><?= e($levels[$i] ?? 0) ?>%</b></td>
                    <td><?= money($unit * ($levels[$i] ?? 0) / 100) ?></td>
                </tr>
                <?php endfor; ?>
            </table>
            <p style="margin-top:10px;color:var(--ink-soft)">Level income earned: <b><?= money($earn['level']) ?></b></p>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-title">🏅 Rank Achievements</div>
            <p style="font-size:13px;color:var(--ink-soft);margin-bottom:14px">
                Current group BV: <b><?= bv($teamBv) ?></b> (<b><?= $points ?> points</b>) · Active directs: <b><?= $directs ?></b>
            </p>
            <?php $currentRankId = (int)$u['rank_id']; ?>
            <?php foreach ($ranks as $r): ?>
                <?php $rankPoints = (int)floor((float)$r['min_team_bv'] / $pointBv); ?>
                <?php $achieved = $teamBv >= (float)$r['min_team_bv'] && $directs >= (int)$r['min_directs']; ?>
                <div class="plan-box" style="<?= $achieved ? 'background:#edf7ed;border-color:#a5d6a7' : '' ?>">
                    <b><?= e($r['name']) ?></b>
                    <?= ($currentRankId >= (int)$r['id']) ? badge('Achieved', 'success') : ($achieved ? badge('Pending review', 'warning') : '') ?>
                    <br>Group BV ≥ <b><?= bv($r['min_team_bv']) ?></b> (<?= $rankPoints ?> P)
                    <?php if ((int)$r['min_directs'] > 0): ?> · Active directs ≥ <b><?= (int)$r['min_directs'] ?></b><?php endif; ?>
                    <?php if ((float)$r['reward_amount'] > 0): ?> · Reward: <b><?= money($r['reward_amount']) ?></b><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <p style="font-size:12.5px;color:var(--ink-soft)">Ranks are checked automatically on every approved order in your team.</p>
        </div>

        <div class="card">
            <div class="card-title">🏆 Award Rewards</div>
            <p style="font-size:13px;color:var(--ink-soft);margin-bottom:14px">
                Milestone gifts for group volume — <b>1 point = <?= bv($pointBv) ?></b>. Cash rewards are paid within <b>15 days</b> of achievement.
            </p>
            <?php foreach ($awards as $a): ?>
                <?php $got = array_key_exists((int)$a['id'], $earn['award_by_id']); ?>
                <div class="plan-box" style="<?= $points >= (int)$a['points'] ? 'background:#edf7ed;border-color:#a5d6a7' : '' ?>">
                    <b><?= e($a['reward_title']) ?></b> <?= $got ? badge('Received', 'success') : ($points >= (int)$a['points'] ? badge('Eligible', 'warning') : '') ?>
                    <br><?= (int)$a['points'] ?> points (<?= bv((int)$a['points'] * $pointBv) ?> group BV)
                    <?php if ($a['reward_type'] === 'cash' && (float)$a['amount'] > 0): ?> · Cash: <b><?= money($a['amount']) ?></b>
                    <?php else: ?> · Gift item<?php endif; ?>
                </div>
            <?php endforeach; ?>
            <table class="kv-table" style="width:100%;margin-top:10px">
                <tr><td>Award rewards received</td><td><b><?= money($earn['award']) ?></b></td></tr>
            </table>
        </div>

        <div class="card">
            <div class="card-title">⚙️ Other Rules</div>
            <table class="kv-table" style="width:100%">
                <?php if ((float)$plan['daily_cap'] > 0): ?><tr><td>Daily cap (matching income)</td><td><?= money($plan['daily_cap']) ?></td></tr><?php endif; ?>
                <?php if ((float)$plan['monthly_cap'] > 0): ?><tr><td>Monthly cap (matching income)</td><td><?= money($plan['monthly_cap']) ?></td></tr><?php endif; ?>
                <tr><td>Point value (1 point)</td><td><?= bv($pointBv) ?> group BV</td></tr>
                <tr><td>Activation requirement</td><td><?= e($plan['activation_bv']) ?> BV lifetime purchase</td></tr>
                <tr><td>Payout minimum</td><td><?= money($plan['payout_min']) ?></td></tr>
                <tr><td>TDS on payout</td><td><?= e($plan['tds_percent']) ?>%</td></tr>
                <tr><td>Admin charge on payout</td><td><?= e($plan['admin_charge_percent']) ?>%</td></tr>
                <tr><td>Matching requires active</td><td><?= (int)$plan['matching_requires_active'] ? 'Yes' : 'No' ?></td></tr>
                <tr><td>Level income requires active</td><td><?= (int)$plan['level_requires_active'] ? 'Yes' : 'No' ?></td></tr>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
