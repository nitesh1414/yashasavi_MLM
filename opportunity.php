<?php
/** Business Opportunity — the complete Yashasavi marketing plan, rendered
 *  live from the plan settings, ranks, award rewards and documents tables
 *  so anything the super admin changes is reflected here immediately. */
require_once __DIR__ . '/includes/init.php';

$plan = get_plan();
$ranks = q_all("SELECT * FROM ranks ORDER BY min_points ASC, id ASC");
$awards = q_all("SELECT * FROM award_rewards WHERE status='active' ORDER BY points ASC");

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
            <div class="income-card"><div class="i-ico">🤝</div><h3>02 · Direct Sponsor Income</h3><p>Every directly sponsored member — left or right — who completes <b>3,000 BV joining</b> pays you <b>₹300, one time per direct</b>.</p></div>
            <div class="income-card"><div class="i-ico">⚖️</div><h3>03 · 50% Direct Sponsor Bonus</h3><p>When your direct member completes their first matching pair, you receive <b>₹225 (50%)</b> — once per direct, left or right leg.</p></div>
            <div class="income-card"><div class="i-ico">🔁</div><h3>04 · Binary Matching Bonus</h3><p><b>3,000 BV left + 3,000 BV right</b> = 1 pair → <b><?= money($plan['binary_value']) ?></b> for your <b>first matching pair</b>. Every further pair adds points.</p></div>
            <div class="income-card"><div class="i-ico">⭐</div><h3>05 · Points</h3><p><b>1 point = 3,000 BV left + 3,000 BV right</b>. Points collect from every matched pair — the more your team grows, the more points you earn.</p></div>
            <div class="income-card"><div class="i-ico">🏅</div><h3>06 · Rank Cash Rewards</h3><p>Rank points from 20 P (Silver Star ₹3,000) up to 30,00,000 P (Chairman ₹25 crore) — automatic cash rewards.</p></div>
            <div class="income-card"><div class="i-ico">🏆</div><h3>07 · Award &amp; Rewards</h3><p>Lifetime points milestones — dinner set, mixer grinder, mobile, laptop, scooter, cash funds, car funds, house fund, plot fund and bungalow fund.</p></div>
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
            <p>1 Point (P) = 3,000 BV on the left + 3,000 BV on the right. Rewards are awarded within 15 days of achieving.</p>
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
                        <td><?= e(bv((float)$r['min_points'] * 6000)) ?></td>
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
