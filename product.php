<?php
/** Product detail page */
require_once __DIR__ . '/includes/init.php';

$id = get_int('id');
$product = q_row("SELECT p.*, c.name AS cat_name, c.slug AS cat_slug FROM products p
                  LEFT JOIN categories c ON c.id = p.category_id
                  WHERE p.id = ? AND p.status='active'", [$id]);
if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product Not Found';
    require __DIR__ . '/includes/site_header.php';
    echo '<section class="content-page"><div class="container content-wrap text-center">
          <h2>Product not found</h2>
          <p class="mt-3"><a class="btn btn-primary" href="' . url('products.php') . '">View All Products</a></p></div></section>';
    require __DIR__ . '/includes/site_footer.php';
    exit;
}

$pageTitle = $product['name'];
$pageDesc = $product['short_desc'];
$related = q_all("SELECT p.*, c.name AS cat_name FROM products p LEFT JOIN categories c ON c.id=p.category_id
                  WHERE p.status='active' AND p.id != ? " . ($product['category_id'] ? "AND p.category_id = " . (int)$product['category_id'] : '') . "
                  ORDER BY RAND() LIMIT 4", [$product['id']]);

require __DIR__ . '/includes/site_header.php';
?>
<section class="page-hero">
    <h1><?= e($product['name']) ?></h1>
    <div class="crumbs">
        <a href="<?= url('index.php') ?>">Home</a> /
        <a href="<?= url('products.php') ?>">Products</a>
        <?php if ($product['cat_slug']): ?> / <a href="<?= url('products.php?cat=' . $product['cat_slug']) ?>"><?= e($product['cat_name']) ?></a><?php endif; ?>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="pd-grid">
            <div>
                <div class="pd-img">
                    <img src="<?= e(upload_url($product['image']) ?: placeholder('Product')) ?>" alt="<?= e($product['name']) ?>">
                </div>
            </div>
            <div class="pd-info">
                <?php if ($product['cat_name']): ?><span class="p-badge" style="display:inline-block;background:var(--gold-light);color:#000;padding:4px 14px;border-radius:20px;font-size:12.5px;font-weight:400"><?= e($product['cat_name']) ?></span><?php endif; ?>
                <h1 style="margin-top:10px"><?= e($product['name']) ?></h1>
                <p style="color:var(--ink-soft)"><?= e($product['short_desc']) ?></p>
                <div class="pd-price-row">
                    <span class="pd-mrp"><small style="font-size:13px;color:var(--ink-soft)">MRP </small><?= money($product['mrp']) ?></span>
                    <span class="pd-dp">Size: <b><?= e($product['size']) ?></b></span>
                </div>
                <?php if ($product['stock'] > 0): ?>
                    <p><span class="badge badge-success">In Stock</span></p>
                <?php else: ?>
                    <p><span class="badge badge-secondary">Out of Stock</span></p>
                <?php endif; ?>
                <p style="margin-top:14px;color:var(--ink-soft);font-size:14px">
                    💡 Want to buy at the special <b>Distributor Price (DP)</b> and earn income?
                    <a href="<?= url('register.php') ?>"><b>Register as a distributor</b></a> or
                    <a href="<?= url('login.php') ?>"><b>login</b></a> to your dashboard shop.
                </p>
                <div class="mt-3">
                    <a class="btn btn-primary" href="<?= url(current_user() ? 'user/shop.php' : 'register.php') ?>">
                        <?= current_user() ? 'Buy at DP from Dashboard' : 'Register to Purchase' ?>
                    </a>
                </div>

                <div class="pd-tabs">
                    <div class="tab-head">
                        <button class="active" data-tab="desc">Description</button>
                        <button data-tab="benefits">Benefits</button>
                        <button data-tab="ingredients">Ingredients</button>
                        <button data-tab="usage">How to Use</button>
                    </div>
                    <div class="tab-body">
                        <div class="tab-pane active" data-tab="desc"><?= rich_text($product['description']) ?></div>
                        <div class="tab-pane" data-tab="benefits"><?= rich_text($product['benefits']) ?></div>
                        <div class="tab-pane" data-tab="ingredients"><?= rich_text($product['ingredients']) ?></div>
                        <div class="tab-pane" data-tab="usage"><?= rich_text($product['how_to_use']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($related): ?>
        <div class="mt-3">
            <div class="sec-head" style="margin-bottom:24px"><h2 style="font-size:24px">Related Products</h2></div>
            <div class="product-grid">
                <?php foreach ($related as $p): ?>
                <div class="p-card">
                    <a class="p-img" href="<?= url('product.php?id=' . $p['id']) ?>">
                        <img src="<?= e(upload_url($p['image']) ?: placeholder('Product')) ?>" alt="">
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
        </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/site_footer.php'; ?>
