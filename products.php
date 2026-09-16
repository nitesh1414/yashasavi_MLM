<?php
/** Product listing with category filter + search */
require_once __DIR__ . '/includes/init.php';

$catSlug = get_str('cat');
$search = get_str('q');
$cat = null;
if ($catSlug) {
    $cat = q_row("SELECT * FROM categories WHERE slug = ? AND status='active'", [$catSlug]);
}
$categories = q_all("SELECT * FROM categories WHERE status='active' ORDER BY sort_order");

$where = "p.status='active'";
$params = [];
$join = "LEFT JOIN categories c ON c.id = p.category_id";
if ($cat) {
    $where .= " AND p.category_id = ?";
    $params[] = $cat['id'];
}
if ($search !== '') {
    $where .= " AND (p.name LIKE ? OR p.short_desc LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$total = (int)q_val("SELECT COUNT(*) FROM products p $join WHERE $where", $params);
[$per, $offset] = paginate($total, ITEMS_PER_PAGE, $links);
$products = q_all("SELECT p.*, c.name AS cat_name, c.slug AS cat_slug FROM products p $join
                   WHERE $where ORDER BY p.sort_order, p.id LIMIT $per OFFSET $offset", $params);

$pageTitle = 'Products' . ($cat ? ' — ' . $cat['name'] : '');
require __DIR__ . '/includes/site_header.php';
?>
<section class="page-hero">
    <h1><?= $cat ? e($cat['name']) : 'Our Products' ?></h1>
    <div class="crumbs"><a href="<?= url('index.php') ?>">Home</a> / Products<?= $cat ? ' / ' . e($cat['name']) : '' ?></div>
</section>

<section class="section">
    <div class="container">
        <div class="filter-bar">
            <a href="<?= url('products.php') ?>" class="<?= !$cat ? 'active' : '' ?>">All Products</a>
            <?php foreach ($categories as $c): ?>
                <a href="<?= url('products.php?cat=' . $c['slug']) ?>" class="<?= ($cat && $cat['id'] == $c['id']) ? 'active' : '' ?>"><?= e($c['name']) ?></a>
            <?php endforeach; ?>
            <form class="search-form" method="get" action="<?= url('products.php') ?>">
                <?php if ($cat): ?><input type="hidden" name="cat" value="<?= e($cat['slug']) ?>"><?php endif; ?>
                <input type="text" name="q" placeholder="Search products..." value="<?= e($search) ?>">
                <button type="submit">🔍</button>
            </form>
        </div>

        <?php if ($cat && $cat['description']): ?>
            <p style="color:var(--ink-soft);margin-bottom:26px;"><?= e($cat['description']) ?></p>
        <?php endif; ?>

        <?php if (!$products): ?>
            <div class="empty-state" style="padding:60px;text-align:center;color:var(--ink-soft)">
                <span style="font-size:44px">📦</span>
                <p>No products found<?= $search ? ' for "' . e($search) . '"' : '' ?>.</p>
            </div>
        <?php else: ?>
        <div class="product-grid">
            <?php foreach ($products as $p): ?>
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
        <?= $links ?>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/site_footer.php'; ?>
