<?php
/** Homepage — slider, features, about, featured products, categories, steps, testimonials, CTA */
require_once __DIR__ . '/includes/init.php';

$sliders = q_all("SELECT * FROM sliders WHERE status='active' ORDER BY sort_order, id");
$featured = q_all("SELECT p.*, c.name AS cat_name FROM products p
                   LEFT JOIN categories c ON c.id = p.category_id
                   WHERE p.status='active' AND p.is_featured=1 ORDER BY p.sort_order LIMIT 8");
if (!$featured) {
    $featured = q_all("SELECT p.*, c.name AS cat_name FROM products p
                       LEFT JOIN categories c ON c.id = p.category_id
                       WHERE p.status='active' ORDER BY p.sort_order LIMIT 8");
}
$categories = q_all("SELECT * FROM categories WHERE status='active' ORDER BY sort_order LIMIT 6");
$testimonials = q_all("SELECT * FROM testimonials WHERE status='active' ORDER BY sort_order LIMIT 3");

require __DIR__ . '/includes/site_header.php';
?>

<!-- hero slider -->
<section class="hero">
    <?php if ($sliders): $i = 0; foreach ($sliders as $s): ?>
    <div class="hero-slide <?= $i === 0 ? 'active' : '' ?>">
        <img src="<?= e(upload_url($s['image']) ?: placeholder('Yashasavi')) ?>" alt="<?= e($s['title']) ?>">
        <div class="container" style="position:relative;z-index:3;height:100%;display:flex;align-items:center;">
            <div class="hero-text">
                <?php if ($s['subtitle']): ?><span class="hero-kicker"><?= e($s['subtitle']) ?></span><?php endif; ?>
                <h1><?= e($s['title']) ?></h1>
                <?php if ($s['description']): ?><p><?= e($s['description']) ?></p><?php endif; ?>
                <?php if ($s['btn_text']): ?>
                    <a class="btn btn-gold" href="<?= e($s['btn_link'] ?: 'products.php') ?>"><?= e($s['btn_text']) ?> →</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php $i++; endforeach; else: ?>
    <div class="hero-slide active">
        <div class="container" style="position:relative;z-index:3;height:100%;display:flex;align-items:center;">
            <div class="hero-text">
                <span class="hero-kicker"><?= e(setting('site_tagline')) ?></span>
                <h1>Be Healthy With <?= e(setting('site_name')) ?> Products</h1>
                <p>Pure Ayurvedic products and a genuine direct-selling business opportunity.</p>
                <a class="btn btn-gold" href="<?= url('register.php') ?>">Join Now →</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (count($sliders) > 1): ?><div class="hero-dots"></div><?php endif; ?>
</section>

<!-- feature strip -->
<section class="section">
    <div class="container">
        <div class="features">
            <div class="feature"><div class="f-ico">🌿</div><h3>100% Natural</h3><p>Pure Ayurvedic formulations made from natural ingredients.</p></div>
            <div class="feature"><div class="f-ico">🛡️</div><h3>Quality Assured</h3><p>Manufactured under strict quality standards and testing.</p></div>
            <div class="feature"><div class="f-ico">💪</div><h3>Boost Immunity</h3><p>Products that support your health and wellness naturally.</p></div>
            <div class="feature"><div class="f-ico">🤝</div><h3>Trusted Network</h3><p>Thousands of happy customers and growing distributor family.</p></div>
        </div>
    </div>
</section>

<!-- about -->
<section class="section section-soft">
    <div class="container">
        <div class="about-grid">
            <div class="about-img">
                <img src="<?= e(upload_url(q_val("SELECT image FROM sliders WHERE status='active' ORDER BY sort_order LIMIT 1")) ?: placeholder('About Us')) ?>" alt="About us">
                <div class="about-exp"><b><?= e(setting('stats_customers', '5000+')) ?></b><span>Happy Customers</span></div>
            </div>
            <div>
                <div class="sec-head" style="text-align:left;margin:0 0 18px">
                    <span class="sec-kicker">Get to know us</span>
                    <h2>We Are <?= e(setting('site_name')) ?></h2>
                </div>
                <p style="color:var(--ink-soft)"><?= e(setting('footer_about')) ?></p>
                <ul class="about-list">
                    <li>Genuine direct selling business opportunity</li>
                    <li>Premium quality natural health products</li>
                    <li>Transparent income plan with timely payouts</li>
                    <li>Full support &amp; training for distributors</li>
                </ul>
                <a class="btn btn-primary" href="<?= url('page.php?slug=about-us') ?>">Know More About Us</a>
            </div>
        </div>
    </div>
</section>

<!-- featured products -->
<section class="section">
    <div class="container">
        <div class="sec-head">
            <span class="sec-kicker">Checkout New Products</span>
            <h2>Introducing Our Products</h2>
            <p>Premium Ayurvedic products for health, nutrition and personal care.</p>
        </div>
        <div class="product-grid">
            <?php foreach ($featured as $p): ?>
            <div class="p-card">
                <a class="p-img" href="<?= url('product.php?id=' . $p['id']) ?>">
                    <img src="<?= e(upload_url($p['image']) ?: placeholder('Product')) ?>" alt="<?= e($p['name']) ?>">
                    <?php if ($p['cat_name']): ?><span class="p-badge"><?= e($p['cat_name']) ?></span><?php endif; ?>
                </a>
                <div class="p-body">
                    <h3><a href="<?= url('product.php?id=' . $p['id']) ?>" style="color:inherit"><?= e($p['name']) ?></a></h3>
                    <span class="p-size"><?= e($p['size']) ?></span>
                    <div class="p-price">
                        <span class="mrp"><small>MRP </small><?= money($p['mrp']) ?></span>
                        <a class="btn btn-outline btn-sm" href="<?= url('product.php?id=' . $p['id']) ?>">Read More</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="text-center mt-3">
            <a class="btn btn-primary" href="<?= url('products.php') ?>">View All Products</a>
        </p>
    </div>
</section>

<!-- categories -->
<?php if ($categories): ?>
<section class="section section-cream">
    <div class="container">
        <div class="sec-head">
            <span class="sec-kicker">Category Wise</span>
            <h2>Products Under Every Category</h2>
        </div>
        <div class="cat-grid">
            <?php foreach ($categories as $c): ?>
            <a class="cat-card" href="<?= url('products.php?cat=' . $c['slug']) ?>">
                <img src="<?= e(upload_url($c['image']) ?: placeholder('Category')) ?>" alt="<?= e($c['name']) ?>">
                <h4><?= e($c['name']) ?></h4>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- stats -->
<section class="stats-band">
    <div class="container">
        <div class="stats-grid">
            <div><b><?= e(setting('stats_customers', '5000+')) ?></b><span>Happy Customers</span></div>
            <div><b><?= e(setting('stats_products', '25+')) ?></b><span>Quality Products</span></div>
            <div><b><?= e(setting('stats_distributors', '1000+')) ?></b><span>Distributors</span></div>
            <div><b><?= e(setting('stats_states', '12+')) ?></b><span>States Covered</span></div>
        </div>
    </div>
</section>

<!-- how it works -->
<section class="section">
    <div class="container">
        <div class="sec-head">
            <span class="sec-kicker">Better Earnings. Better Lifestyle.</span>
            <h2>How It Works</h2>
            <p>Time to take action! Becoming a distributor is simple and easy.</p>
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
        <p class="text-center mt-3">
            <a class="btn btn-primary" href="<?= url('register.php') ?>">Register as Distributor</a>
        </p>
    </div>
</section>

<!-- testimonials -->
<?php if ($testimonials): ?>
<section class="section section-soft">
    <div class="container">
        <div class="sec-head">
            <span class="sec-kicker">Testimonials</span>
            <h2>What Our Distributors Say</h2>
        </div>
        <div class="testi-grid">
            <?php foreach ($testimonials as $t): ?>
            <div class="testi-card">
                <div class="t-stars"><?= str_repeat('★', (int)$t['rating']) . str_repeat('☆', max(0, 5 - (int)$t['rating'])) ?></div>
                <p>“<?= e($t['content']) ?>”</p>
                <div class="testi-who">
                    <?php if ($t['photo']): ?>
                        <img src="<?= e(upload_url($t['photo'])) ?>" alt="">
                    <?php else: ?>
                        <span class="avatar" style="width:46px;height:46px;border-radius:50%;background:var(--green-light);color:var(--green-dark);display:flex;align-items:center;justify-content:center;font-weight:700"><?= e(strtoupper(substr($t['name'], 0, 1))) ?></span>
                    <?php endif; ?>
                    <div><b><?= e($t['name']) ?></b><span><?= e($t['designation']) ?></span></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- CTA -->
<section class="section">
    <div class="container">
        <div class="cta-band">
            <div>
                <h2>Ready to start your journey?</h2>
                <p>Join <?= e(setting('site_name')) ?> today and build your own business.</p>
            </div>
            <a class="btn btn-gold" href="<?= url('register.php') ?>">Become a Distributor →</a>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/site_footer.php'; ?>
