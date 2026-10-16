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
                    <span class="pt-name">L1 · Direct Member</span>
                    <span class="pt-sub">directly sponsored by you — earns you the bonus</span>
                </div>
            </div>
            <div class="pt-chips">
                <span class="pt-chip">Joining amount: <b>₹6,000</b></span>
                <span class="pt-chip">Business Volume: <b>3,000 BV</b></span>
                <span class="pt-chip green">Your Direct Sponsor commission: <b><?= money(3000 * $plan['sponsor_percent'] / 100) ?></b></span>
            </div>
            <div class="plan-box">
                Every member you <b>directly sponsor</b> — in your <b>left</b> or <b>right</b> leg — who completes
                <b>3,000 BV in total purchases</b> (the ₹6,000 joining) pays you a one-time
                <b>direct sponsor commission of <?= money(3000 * $plan['sponsor_percent'] / 100) ?></b>
                (<?= e($plan['sponsor_percent']) ?>% of the joining BV). It is paid <b>once per direct member</b> —
                ₹300 for a left direct, ₹300 for a right direct.
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
                3,000 BV on your <b>Left</b> leg + 3,000 BV on your <b>Right</b> leg = 1 matched pair.
                The payout rate is <b><?= money($pairPay) ?></b> — paid <b>ONCE, for your FIRST matching pair
                only</b>. Every further pair adds <b>1 point</b> to your tally (see Points &amp; Rewards) — no
                further matching commission.
                <br><b>Eligibility rules:</b>
                you must have completed your own <b>Self 3,000 BV</b> joining
                <?= (int)$plan['matching_requires_active'] ? '' : ' (not required by current settings)' ?>;
                only <b>confirmed / approved order BV</b> counts;
                pair matching is 1:<?= e($plan['pair_unit_bv']) ?> on both legs;
                unmatched BV keeps accumulating.
                <table class="kv-table" style="width:100%;margin-top:10px">
                    <tr><td>Your left leg BV</td><td><b><?= bv($u['left_bv']) ?></b></td></tr>
                    <tr><td>Your right leg BV</td><td><b><?= bv($u['right_bv']) ?></b></td></tr>
                    <tr><td>Matched BV (weaker side)</td><td><b><?= bv(min((float)$u['left_bv'], (float)$u['right_bv'])) ?></b></td></tr>
                    <tr><td>Pairs matched so far</td><td><b><?= (int)$u['matched_pairs'] ?></b> (only the first pair pays)</td></tr>
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
                When a <b>directly sponsored member in your LEFT leg</b> or a <b>directly sponsored member in your
                RIGHT leg</b> completes their first Matching Bonus Income (<?= money($pairPay) ?>), you receive
                <b><?= e($plan['sponsor_matching_percent']) ?>%</b> of that matching income —
                <b><?= money($pairPay * $plan['sponsor_matching_percent'] / 100) ?></b> — as your Direct Sponsor Bonus.
                It is paid <b>once per direct member</b> (their first pair is the only paid pair).
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
                <b>1 point = 3,000 BV on your left + 3,000 BV on your right</b> (both sides together).
                Points are added for <b>every matched pair</b> — the first pair pays the ₹450 Matching Bonus,
                and every further pair adds points to your tally. Points need your own
                <b>Self 3,000 BV</b> completed and drive the <b>rank cash rewards</b> and the
                <b>award milestones</b> below.
                <table class="kv-table" style="width:100%;margin-top:10px">
                    <tr><td>Your points</td><td><b><?= number_format($points) ?> P</b></td></tr>
                    <tr><td>Pairs matched</td><td><b><?= (int)$u['matched_pairs'] ?></b></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-title">🏅 Rank Achievements</div>
            <p style="font-size:13px;color:var(--ink-soft);margin-bottom:14px">
                Your points: <b><?= number_format($points) ?> P</b> (1 P = 3,000 BV left + 3,000 BV right)
            </p>
            <?php $currentRankId = (int)$u['rank_id']; ?>
            <?php foreach ($ranks as $r): ?>
                <?php $achieved = $points >= (int)$r['min_points']; ?>
                <div class="plan-box" style="<?= $achieved ? 'background:#edf7ed;border-color:#a5d6a7' : '' ?>">
                    <b><?= e($r['name']) ?></b>
                    <?= ($currentRankId >= (int)$r['id']) ? badge('Achieved', 'success') : ($achieved ? badge('Pending review', 'warning') : '') ?>
                    <br>Points ≥ <b><?= number_format((int)$r['min_points']) ?> P</b>
                    <?php if ((float)$r['reward_amount'] > 0): ?> · Cash reward: <b><?= money($r['reward_amount']) ?></b><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <p style="font-size:12.5px;color:var(--ink-soft)">Ranks are checked automatically whenever your points grow.</p>
        </div>

        <div class="card">
            <div class="card-title">🏆 Award Rewards</div>
            <p style="font-size:13px;color:var(--ink-soft);margin-bottom:14px">
                Lifetime points milestones — <b>1 point = 3,000 BV left + 3,000 BV right</b>. Cash rewards are paid within <b>15 days</b> of achievement.
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
                <tr><td>Award rewards received</td><td><b><?= money($earn['award']) ?></b></td></tr>
            </table>
        </div>

        <div class="card">
            <div class="card-title">⚙️ Other Rules</div>
            <table class="kv-table" style="width:100%">
                <tr><td>Joining / eligibility BV</td><td><?= e($plan['activation_bv']) ?> BV self purchase</td></tr>
                <tr><td>Point value (1 point)</td><td>3,000 BV left + 3,000 BV right</td></tr>
                <tr><td>Points per matched pair</td><td><?= (int)$plan['points_per_unit'] ?> P</td></tr>
                <tr><td>Payout commission</td><td>₹300 sponsor + ₹450 first pair + ₹225 bonus only</td></tr>
                <tr><td>Payout minimum</td><td><?= money($plan['payout_min']) ?></td></tr>
                <tr><td>TDS on payout</td><td><?= e($plan['tds_percent']) ?>%</td></tr>
                <tr><td>Admin charge on payout</td><td><?= e($plan['admin_charge_percent']) ?>%</td></tr>
                <tr><td>Matching requires joining completed</td><td><?= (int)$plan['matching_requires_active'] ? 'Yes' : 'No' ?></td></tr>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
