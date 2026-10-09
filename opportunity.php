<?php
/** Business Opportunity — the complete Yashasavi marketing plan, rendered
 *  live from the plan settings, ranks, award rewards and documents tables
 *  so anything the super admin changes is reflected here immediately. */
require_once __DIR__ . '/includes/init.php';

$plan = get_plan();
$ranks = q_all("SELECT * FROM ranks ORDER BY sort_order, min_team_bv ASC");
$awards = q_all("SELECT * FROM award_rewards WHERE status='active' ORDER BY points ASC");
$pointBv = max(1, (float)$plan['point_bv']);

$pageTitle = 'Business Opportunity — Marketing Plan';
$pageDesc = 'The complete Yashasavi income plan: retail income, direct sponsor income, binary matching income, sponsor matching income, cash rewards, car fund and award rewards — from ₹3,000 up to ₹25 crore.';

require __DIR__ . '/includes/site_header.php';
?>

<section class="page-hero plan-hero">
    <div class="container">
        <span class="hero-kicker"><?= e(setting('site_tagline')) ?></span>
        <h1>Build Multiple Sources of Income</h1>
        <p>Achieve Freedom • Live Your Dream — a simple, powerful and proven plan with <b>no level limit</b>,
            left-right carry forward business and rewards from ₹3,000 up to ₹25 crore.</p>
    </div>
</section>

<!-- income types -->
<section class="section">
    <div class="container">
        <div class="sec-head">
            <span class="sec-kicker">11 Types of Income</span>
            <h2>Every Way You Can Earn</h2>
            <p>All incomes are calculated automatically and credited to your E-wallet.</p>
        </div>
        <div class="income-grid">
            <div class="income-card"><div class="i-ico">🛍️</div><h3>01 · Retail Income</h3><p>Buy at the special distributor price (DP) and sell at MRP — keep <b>30% to 45%</b> retail margin on every product.</p></div>
            <div class="income-card"><div class="i-ico">🤝</div><h3>02 · Direct Sponsor Income</h3><p>Earn <b><?= e(rtrim(rtrim((string)(float)$plan['sponsor_percent'], '0'), '.')) ?>% of BV</b> on every purchase by members you directly sponsored. No level limit.</p></div>
            <div class="income-card"><div class="i-ico">⚖️</div><h3>03 · Direct Sponsor Matching Income</h3><p>When your direct members earn binary matching income, you receive <b><?= e(rtrim(rtrim((string)(float)$plan['sponsor_matching_percent'], '0'), '.')) ?>%</b> of it — unlimited depth.</p></div>
            <div class="income-card"><div class="i-ico">🔁</div><h3>04 · Binary Matching Bonus</h3><p>Every <b><?= e(bv($plan['pair_unit_bv'])) ?> : <?= e(bv($plan['pair_unit_bv'])) ?> BV</b> pair (left : right) pays <b><?= money($plan['binary_value']) ?></b> with carry forward.</p></div>
            <div class="income-card"><div class="i-ico">🏅</div><h3>05 · Cash Rewards</h3><p>Achieve rank points (1 point = <?= e(bv($pointBv)) ?>) and win cash rewards from ₹3,000 up to ₹25 crore.</p></div>
            <div class="income-card"><div class="i-ico">🏆</div><h3>06 · Award &amp; Rewards</h3><p>Next-to-next matching rewards — dinner set, mixer grinder, cash funds and more as your team grows.</p></div>
            <div class="income-card"><div class="i-ico">🚗</div><h3>07 · Car Fund</h3><p>Reach <b><?= (int)$plan['car_fund_points'] ?> points</b> and receive a <b><?= money($plan['car_fund_amount']) ?></b> car fund — paid directly at the showroom within 60 days.</p></div>
            <div class="income-card"><div class="i-ico">👑</div><h3>08 · Royalty Income</h3><p>Top leaders share in the company's growth through the royalty pool.</p></div>
            <div class="income-card"><div class="i-ico">🏠</div><h3>09 · House Fund</h3><p>Consistent achievers become eligible for the house fund support program.</p></div>
            <div class="income-card"><div class="i-ico">✈️</div><h3>10 · Tour &amp; Vacation</h3><p>Qualify for domestic and international company tours with your family.</p></div>
            <div class="income-card"><div class="i-ico">🎓</div><h3>11 · Education System</h3><p>Free business training, product education and leadership development for every distributor.</p></div>
        </div>
    </div>
</section>

<!-- how matching works -->
<section class="section section-soft">
    <div class="container">
        <div class="sec-head">
            <span class="sec-kicker">Matching Income</span>
            <h2>How the Binary Matching Works</h2>
        </div>
        <div class="plan-cols">
            <div class="plan-box">
                <h3>🧮 1 : 1 Pair Matching</h3>
                <p>When <b>both your left and right leg</b> generate <?= e(bv($plan['pair_unit_bv'])) ?> BV each,
                   one pair is matched and you earn <b><?= money($plan['binary_value']) ?></b>.</p>
                <table class="plan-table">
                    <tr><th>Left Leg</th><th></th><th>Right Leg</th><th>You Earn</th></tr>
                    <tr><td><?= e(bv($plan['pair_unit_bv'])) ?></td><td>+</td><td><?= e(bv($plan['pair_unit_bv'])) ?></td><td><b><?= money($plan['binary_value']) ?></b></td></tr>
                    <tr><td><?= e(bv($plan['pair_unit_bv'] * 2)) ?></td><td>+</td><td><?= e(bv($plan['pair_unit_bv'] * 2)) ?></td><td><b><?= money($plan['binary_value'] * 2) ?></b></td></tr>
                    <tr><td><?= e(bv($plan['pair_unit_bv'] * 5)) ?></td><td>+</td><td><?= e(bv($plan['pair_unit_bv'] * 5)) ?></td><td><b><?= money($plan['binary_value'] * 5) ?></b></td></tr>
                </table>
                <p class="muted">Unmatched BV is carried forward — your business is never lost.</p>
            </div>
            <div class="plan-box">
                <h3>🛡️ Capping &amp; Rules</h3>
                <ul class="plan-list">
                    <li><b>Daily capping:</b> <?= money($plan['daily_cap']) ?></li>
                    <li><b>Monthly capping:</b> <?= money($plan['monthly_cap']) ?></li>
                    <li><b>Carry forward:</b> unmatched BV / pairs carry forward (<?= (int)$plan['carry_forward'] === 1 ? 'Yes' : 'No' ?>)</li>
                    <li><b>No level limit</b> — unlimited depth income</li>
                    <li><b>No need to manage rank</b> — points are calculated automatically</li>
                    <li>TDS <?= e(rtrim(rtrim((string)(float)$plan['tds_percent'], '0'), '.')) ?>% + admin charge <?= e(rtrim(rtrim((string)(float)$plan['admin_charge_percent'], '0'), '.')) ?>% applicable on payouts</li>
                    <li>KYC confirmation is mandatory for rewards &amp; payouts</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- rank / cash reward table -->
<section class="section">
    <div class="container">
        <div class="sec-head">
            <span class="sec-kicker">Cash Reward Plan</span>
            <h2>Recognising Your Performance, Rewarding Your Success</h2>
            <p>1 Point (P) = <?= e(bv($pointBv)) ?> of team business BV. Rewards are awarded within 15 days of achieving.</p>
        </div>
        <div class="table-wrap plan-table-wrap">
            <table class="plan-rank-table">
                <thead>
                    <tr><th>#</th><th>Rank</th><th>Points</th><th>Team Business</th><th>Cash Reward</th></tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($ranks as $r): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><b>🏅 <?= e($r['name']) ?></b></td>
                        <td><?= number_format((float)$r['min_team_bv'] / $pointBv) ?> P</td>
                        <td><?= e(bv($r['min_team_bv'])) ?></td>
                        <td class="gold"><b><?= money($r['reward_amount']) ?></b></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- award rewards -->
<?php if ($awards): ?>
<section class="section section-cream">
    <div class="container">
        <div class="sec-head">
            <span class="sec-kicker">Award &amp; Rewards</span>
            <h2>Next-to-Next Matching Rewards</h2>
            <p>No time limit — every reward is given once you reach the required points.</p>
        </div>
        <div class="award-grid">
            <?php foreach ($awards as $a): ?>
            <div class="award-card">
                <span class="a-points"><?= number_format((int)$a['points']) ?> P</span>
                <div class="a-ico"><?= $a['reward_type'] === 'cash' ? '💰' : '🎁' ?></div>
                <h3><?= e($a['reward_title']) ?></h3>
                <?php if ($a['description']): ?><p><?= e($a['description']) ?></p><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- car fund -->
<section class="section">
    <div class="container">
        <div class="car-fund-band">
            <div class="car-emoji">🚗</div>
            <div>
                <span class="sec-kicker">Car Fund</span>
                <h2><?= money($plan['car_fund_amount']) ?> Car Fund at <?= (int)$plan['car_fund_points'] ?> Points</h2>
                <p>The car fund amount is given <b>directly at the car showroom</b> within 60 days of achieving.
                   Points keep adding to the cash bonus. No monthly product purchase condition.</p>
            </div>
        </div>
    </div>
</section>

<!-- steps CTA -->
<section class="section section-soft">
    <div class="container">
        <div class="sec-head">
            <span class="sec-kicker">Getting Started</span>
            <h2>Start Earning in 3 Simple Steps</h2>
        </div>
        <div class="steps-grid">
            <?php for ($i = 1; $i <= 3; $i++): ?>
            <div class="step-card">
                <div class="step-n"><?= $i ?></div>
                <h4><?= e(setting("how_works_{$i}_title")) ?></h4>
                <p><?= e(setting("how_works_{$i}_text")) ?></p>
            </div>
            <?php endfor; ?>
        </div>
        <div class="cta-band" style="margin-top:34px">
            <div>
                <h2>Your Dream, Your Better</h2>
                <p>Join thousands of distributors building health and wealth with <?= e(setting('site_name')) ?>.</p>
            </div>
            <a class="btn btn-gold" href="<?= url('register.php') ?>">Become a Distributor →</a>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/site_footer.php'; ?>
