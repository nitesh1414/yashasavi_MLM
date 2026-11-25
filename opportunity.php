<?php
/** Business Opportunity — the complete Yashasavi marketing plan, rendered
 *  live from the plan settings, ranks, award rewards and documents tables
 *  so anything the super admin changes is reflected here immediately. */
require_once __DIR__ . '/includes/init.php';

$plan = get_plan();
$pairPay = income_pair_rate($plan);
$ranks = q_all("SELECT * FROM ranks ORDER BY min_points ASC, id ASC");
$awards = q_all("SELECT * FROM award_rewards WHERE status='active' ORDER BY points ASC");

$pageTitle = 'Business Opportunity — Marketing Plan';
$pageDesc = 'The Yashasavi income plan: direct sponsor income, binary matching, placement first-match bonuses, points and rank rewards.';

require __DIR__ . '/includes/site_header.php';
?>

<section class="page-hero plan-hero">
    <div class="container">
        <span class="hero-kicker"><?= e(setting('site_tagline')) ?></span>
        <h1>Build Multiple Sources of Income</h1>
        <p>Explore the current income plan, matching rules, points and company-managed recognition milestones.</p>
    </div>
</section>

<!-- income types -->
<section class="section">
    <div class="container">
        <div class="sec-head">
            <span class="sec-kicker">Earnings &amp; Recognition</span>
            <h2>Explore the Plan</h2>
            <p>Eligible sponsor, matching, placement first-match and rank income is credited to your wallet. Other benefits depend on company policy and are not automatic wallet income.</p>
        </div>
        <div class="income-grid">
            <div class="income-card"><div class="i-ico">🛍️</div><h3>01 · Retail</h3><p>Distributor price and retail price vary by product. Any retail margin depends on the actual selling price and costs.</p></div>
            <div class="income-card"><div class="i-ico">🤝</div><h3>02 · Direct Sponsor Income</h3><p>Earn <b><?= money((float)$plan['activation_bv'] * (float)$plan['sponsor_percent'] / 100) ?></b> once per personally sponsored member who completes <?= e($plan['activation_bv']) ?> Self BV, regardless of placement and subject to eligibility.</p></div>
            <div class="income-card"><div class="i-ico">⚖️</div><h3>03 · Placement First-Match Bonus</h3><p>When an immediate placement child earns their first pair, their eligible placement parent receives <b><?= e($plan['sponsor_matching_percent']) ?>%</b> of that pair, once per child, regardless of sponsorship.</p></div>
            <div class="income-card"><div class="i-ico">🔁</div><h3>04 · Binary Matching Bonus</h3><p><b><?= e($plan['pair_unit_bv']) ?> BV on each side</b> = 1 pair → <b><?= money($pairPay) ?></b> per eligible pair, including the first.</p></div>
            <div class="income-card"><div class="i-ico">⭐</div><h3>05 · Points</h3><p><b><?= (int)$plan['points_per_unit'] ?> point(s) per matched pair</b>. Points collect from every eligible matched pair — the more your team grows, the more points you earn.</p></div>
            <div class="income-card"><div class="i-ico">🏅</div><h3>06 · Rank Cash Rewards</h3><p>Eligible rank milestones and their one-time cash rewards are shown in the current rank table below.</p></div>
            <div class="income-card"><div class="i-ico">🏆</div><h3>07 · Award &amp; Rewards</h3><p>Lifetime points milestones — dinner set, mixer grinder, mobile, laptop, scooter, cash funds, car funds, house fund, plot fund and bungalow fund.</p></div>
            <div class="income-card"><div class="i-ico">👑</div><h3>08 · Royalty</h3><p>Any royalty programme is company-managed; it is not an automatic commission in this plan.</p></div>
            <div class="income-card"><div class="i-ico">🏠</div><h3>09 · House Fund</h3><p>Any house-fund benefit is subject to the company's separate policies and approval.</p></div>
            <div class="income-card"><div class="i-ico">✈️</div><h3>10 · Tour &amp; Vacation</h3><p>Any tours or vacation benefits are subject to company policies and availability.</p></div>
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
                <h3>🛡️ Eligibility &amp; Rules</h3>
                <ul class="plan-list">
                    <li><b>Carry forward:</b> unmatched BV and ineligible matched pairs are carried forward.</li>
                    <li><b>No level limit</b> — unlimited depth income</li>
                    <li><b>No need to manage rank</b> — points are calculated automatically</li>
                    <li>TDS <?= e(rtrim(rtrim(number_format((float)$plan['tds_percent'], 2, '.', ''), '0'), '.')) ?>% + admin charge <?= e(rtrim(rtrim(number_format((float)$plan['admin_charge_percent'], 2, '.', ''), '0'), '.')) ?>% applicable on payouts</li>
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
            <p>Each eligible matched pair earns <?= (int)$plan['points_per_unit'] ?> point(s) under the current plan.</p>
        </div>
        <div class="table-wrap plan-table-wrap">
            <table class="plan-rank-table">
                <thead>
                    <tr><th>#</th><th>Rank</th><th>Points Required</th><th>Matched Business (L + R)</th><th>Cash Reward</th></tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($ranks as $r): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><b>🏅 <?= e($r['name']) ?></b></td>
                        <td><?= number_format((int)$r['min_points']) ?> P</td>
                        <td><?= (int)$plan['points_per_unit'] > 0 ? e(bv(ceil((float)$r['min_points'] / (int)$plan['points_per_unit']) * (float)$plan['pair_unit_bv'] * 2)) : '—' ?></td>
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
            <p>These milestones recognise lifetime points. Award fulfilment is managed by the company and is not an automatic wallet payment.</p>
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
