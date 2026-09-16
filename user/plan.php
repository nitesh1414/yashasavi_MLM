<?php
/** Marketing plan explanation + my live numbers */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

$plan = get_plan();
$levels = get_plan_levels();
$earn = user_earnings_breakdown($u['id']);
$ranks = get_ranks();
$teamBv = (float)$u['left_bv'] + (float)$u['right_bv'];
$directs = (int)q_val("SELECT COUNT(*) FROM users WHERE sponsor_id = ? AND is_active = 1", [$u['id']]);

$activeKey = 'plan';
$pageTitle = 'Marketing Plan';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';

$unit = max(1, (float)$plan['pair_unit_bv']);
?>

<div class="two-col">
    <div>
        <div class="card">
            <div class="card-title">💠 Binary Pair Matching Income</div>
            <div class="plan-box">
                Every purchase in your team adds <b>BV (Business Volume)</b> to your <b>Left</b> or <b>Right</b> leg.
                Income is paid on matched pairs: <b>1:<?= e($plan['pair_unit_bv'] . ' BV') ?></b> on both legs = 1 pair.
                <?php if ($plan['binary_type'] === 'percent'): ?>
                    Pair income = <b><?= e($plan['binary_value']) ?>%</b> of matched BV (<?= money($unit * $plan['binary_value'] / 100) ?> per pair).
                <?php else: ?>
                    Pair income = <b><?= money($plan['binary_value']) ?></b> per matched pair.
                <?php endif; ?>
                Unmatched BV is <b><?= (int)$plan['carry_forward'] === 1 ? 'carried forward' : 'flushed' ?></b>.
                <?php if ((float)$plan['daily_cap'] > 0): ?>Daily cap: <b><?= money($plan['daily_cap']) ?></b>.<?php endif; ?>
            </div>
            <table class="kv-table" style="width:100%">
                <tr><td>Left leg BV</td><td><b><?= bv($u['left_bv']) ?></b></td></tr>
                <tr><td>Right leg BV</td><td><b><?= bv($u['right_bv']) ?></b></td></tr>
                <tr><td>Pairs matched so far</td><td><b><?= (int)$u['matched_pairs'] ?></b></td></tr>
                <tr><td>Binary income earned</td><td><b><?= money($earn['binary']) ?></b></td></tr>
            </table>
        </div>

        <div class="card">
            <div class="card-title">🤝 Direct Sponsor Bonus</div>
            <div class="plan-box">
                You earn <b><?= e($plan['sponsor_percent']) ?>%</b> of the BV of every purchase made by your
                <b>directly sponsored</b> members. Earned so far: <b><?= money($earn['sponsor']) ?></b>.
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
                Current team BV: <b><?= bv($teamBv) ?></b> · Active directs: <b><?= $directs ?></b>
            </p>
            <?php $currentRankId = (int)$u['rank_id']; ?>
            <?php foreach ($ranks as $r): ?>
                <?php $achieved = (float)$teamBv >= (float)$r['min_team_bv'] && $directs >= (int)$r['min_directs']; ?>
                <div class="plan-box" style="<?= $achieved ? 'background:#edf7ed;border-color:#a5d6a7' : '' ?>">
                    <b><?= e($r['name']) ?></b>
                    <?= ($currentRankId >= (int)$r['id']) ? badge('Achieved', 'success') : ($achieved ? badge('Pending review', 'warning') : '') ?>
                    <br>Team BV ≥ <b><?= bv($r['min_team_bv']) ?></b>
                    <?php if ((int)$r['min_directs'] > 0): ?> · Active directs ≥ <b><?= (int)$r['min_directs'] ?></b><?php endif; ?>
                    <?php if ((float)$r['reward_amount'] > 0): ?> · Reward: <b><?= money($r['reward_amount']) ?></b><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <p style="font-size:12.5px;color:var(--ink-soft)">Ranks are checked automatically on every approved order in your team.</p>
        </div>

        <div class="card">
            <div class="card-title">⚙️ Other Rules</div>
            <table class="kv-table" style="width:100%">
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
