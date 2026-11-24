<?php
/** Business Opportunity — the complete Yashasavi marketing plan, rendered
 *  live from the plan settings, ranks, award rewards and documents tables
 *  so anything the super admin changes is reflected here immediately. */
require_once __DIR__ . '/includes/init.php';

$plan = get_plan();
$unit = max(1, (float)$plan['pair_unit_bv']);
$pairPay = round($plan['binary_type'] === 'fixed' ? (float)$plan['binary_value'] : $unit * (float)$plan['binary_value'] / 100, 2);
$directPay = round((float)$plan['activation_bv'] * (float)$plan['sponsor_percent'] / 100, 2);
$firstBonus = round($pairPay * (float)$plan['sponsor_matching_percent'] / 100, 2);
$pointsPerPair = max(0, (int)$plan['points_per_unit']);
$ranks = q_all("SELECT * FROM ranks ORDER BY min_points ASC, id ASC");
$awards = q_all("SELECT * FROM award_rewards WHERE status='active' ORDER BY points ASC");

$pageTitle = 'Business Opportunity — Marketing Plan';
$pageDesc = 'Explore the configured Yashasavi income plan, matching rules, ranks and award milestones.';

require __DIR__ . '/includes/site_header.php';
?>

<section class="page-hero plan-hero">
    <div class="container">
        <span class="hero-kicker"><?= e(setting('site_tagline')) ?></span>
        <h1>Build Multiple Sources of Income</h1>
        <p>Achieve Freedom • Live Your Dream — a simple, powerful and proven plan with <b>no level limit</b>,
            left-right carry forward business and configured rank rewards.</p>
    </div>
</section>

<!-- income types -->
<section class="section">
    <div class="container">
        <div class="sec-head">
            <span class="sec-kicker">The Income Plan</span>
            <h2>Every Way You Can Earn</h2>
            <p>Plan income is subject to eligibility. Other company programs and awards are not automatic wallet credits.</p>
        </div>
        <div class="income-grid">
            <div class="income-card"><div class="i-ico">🛍️</div><h3>01 · Retail Income</h3><p>Buy at the special distributor price (DP) and sell at MRP — keep <b>30% to 45%</b> retail margin on every product.</p></div>
            <div class="income-card"><div class="i-ico">🤝</div><h3>02 · Direct Sponsor Income</h3><p>At the current plan rate, earn <b><?= money($directPay) ?> once per newly green direct member</b>. The actual sponsor receives it even when someone else is the placement parent, subject to eligibility.</p></div>
            <div class="income-card"><div class="i-ico">⚖️</div><h3>03 · First-Pair Sponsor Bonus</h3><p>The current rate is <b><?= e($plan['sponsor_matching_percent']) ?>% (<?= money($firstBonus) ?>)</b> of a direct member's first pair, once per direct and subject to sponsor and placement eligibility.</p></div>
            <div class="income-card"><div class="i-ico">🔁</div><h3>04 · Binary Matching Bonus</h3><p><b><?= bv($unit) ?> left + <?= bv($unit) ?> right</b> of approved team BV = 1 pair → <b><?= money($pairPay) ?></b> per pair. Team BV counts even when contributors are not yet green.</p></div>
            <div class="income-card"><div class="i-ico">⭐</div><h3>05 · Points</h3><p>At the current rate, each <?= bv($unit) ?> left + right pair earns <b><?= $pointsPerPair ?> point(s)</b>, subject to recipient eligibility.</p></div>
            <div class="income-card"><div class="i-ico">🏅</div><h3>06 · Rank Cash Rewards</h3><p>Configured rank rewards are credited when an eligible member reaches a new rank.</p></div>
            <div class="income-card"><div class="i-ico">🏆</div><h3>07 · Award &amp; Rewards</h3><p>Lifetime points milestones are listed below for company recognition; they are not automatic wallet credits.</p></div>
            <div class="income-card"><div class="i-ico">👑</div><h3>08 · Royalty Income</h3><p>Not configured as an automatic income; confirm program availability with the company.</p></div>
            <div class="income-card"><div class="i-ico">🏠</div><h3>09 · House Fund</h3><p>Confirm program availability and conditions with the company.</p></div>
            <div class="income-card"><div class="i-ico">✈️</div><h3>10 · Tour &amp; Vacation</h3><p>Tour programs are not configured as automatic income; confirm availability with the company.</p></div>
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
                <p>When <b>both your left and right leg</b> generate <?= bv($unit) ?> each,
                   one pair is matched and you earn <b><?= money($pairPay) ?></b>.</p>
                <table class="plan-table">
                    <tr><th>Left Leg</th><th></th><th>Right Leg</th><th>You Earn</th></tr>
                    <tr><td><?= e(bv($plan['pair_unit_bv'])) ?></td><td>+</td><td><?= e(bv($plan['pair_unit_bv'])) ?></td><td><b><?= money($pairPay) ?></b></td></tr>
                    <tr><td><?= e(bv($plan['pair_unit_bv'] * 2)) ?></td><td>+</td><td><?= e(bv($plan['pair_unit_bv'] * 2)) ?></td><td><b><?= money($pairPay * 2) ?></b></td></tr>
                    <tr><td><?= e(bv($plan['pair_unit_bv'] * 5)) ?></td><td>+</td><td><?= e(bv($plan['pair_unit_bv'] * 5)) ?></td><td><b><?= money($pairPay * 5) ?></b></td></tr>
                </table>
                <p class="muted">Unmatched BV is carried forward — your business is never lost.</p>
            </div>
            <div class="plan-box">
                <h3>🛡️ Capping &amp; Rules</h3>
                <ul class="plan-list">
                    <li><b>Carry forward:</b> unmatched team BV carries forward.</li>
                    <li><b>Eligibility:</b> <?= (int)$plan['matching_requires_active'] ? 'your ID must be green to receive matching.' : 'matching does not require a green ID.' ?></li>
                    <li>Legacy daily and monthly cap settings are not applied by the prospective income policy.</li>
                    <li><b>No need to manage rank</b> — points are calculated automatically</li>
                    <li>TDS <?= e((float)$plan['tds_percent']) ?>% + admin charge <?= e((float)$plan['admin_charge_percent']) ?>% applicable on payouts</li>
                    <li>Verified KYC and bank details are required for payouts</li>
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
            <p>The current rate is <?= $pointsPerPair ?> point(s) per <?= bv($unit) ?> matched on each side. Historical points retain the rate in effect when earned.</p>
        </div>
        <div class="table-wrap plan-table-wrap">
            <table class="plan-rank-table">
                <thead>
                    <tr><th>#</th><th>Rank</th><th>Points Required</th><th>Illustrative BV at current rate (L + R)</th><th>Cash Reward</th></tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($ranks as $r): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><b>🏅 <?= e($r['name']) ?></b></td>
                        <td><?= number_format((int)$r['min_points']) ?> P</td>
                        <td><?= $pointsPerPair ? bv(ceil((int)$r['min_points'] / $pointsPerPair) * $unit * 2) : '—' ?></td>
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
            <p>Points mark eligibility for company recognition; awards are not automatic wallet credits.</p>
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
