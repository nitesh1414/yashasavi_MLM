<?php
/** Shop — products at DP for distributors */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

if (is_post() && post_str('action') === 'add') {
    verify_csrf();
    $pid = (int)post_str('product_id');
    $qty = max(1, min(99, (int)post_str('qty', 1)));
    $p = q_row("SELECT * FROM products WHERE id = ? AND status='active'", [$pid]);
    if ($p && $p['stock'] >= $qty) {
        $cart = $_SESSION['cart'] ?? [];
        $newQty = min(99, ($cart[$pid] ?? 0) + $qty);
        if ($p['stock'] >= $newQty) {
            $cart[$pid] = $newQty;
            $_SESSION['cart'] = $cart;
            flash('success', e($p['name']) . ' added to cart.');
        } else {
            flash('error', 'Only ' . (int)$p['stock'] . ' unit(s) in stock.');
        }
    } else {
        flash('error', 'Product not available.');
    }
    redirect('shop.php');
}

$catSlug = get_str('cat');
$cat = null;
if ($catSlug) {
    $cat = q_row("SELECT * FROM categories WHERE slug = ? AND status='active'", [$catSlug]);
}
$categories = q_all("SELECT * FROM categories WHERE status='active' ORDER BY sort_order");
$where = "p.status='active'";
$params = [];
if ($cat) { $where .= " AND p.category_id = ?"; $params[] = $cat['id']; }
$total = (int)q_val("SELECT COUNT(*) FROM products p WHERE $where", $params);
[$per, $offset] = paginate($total, 12, $links);
$products = q_all("SELECT p.*, c.name AS cat_name FROM products p
                   LEFT JOIN categories c ON c.id = p.category_id
                   WHERE $where ORDER BY p.sort_order, p.id LIMIT $per OFFSET $offset", $params);
$cartCount = array_sum($_SESSION['cart'] ?? []);

$activeKey = 'shop';
$pageTitle = 'Shop — Distributor Prices';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card">
    <div class="card-title">
        🛒 Product Shop — Distributor Price (DP)
        <span class="right"><a class="btn btn-primary btn-sm" href="cart.php">🛍️ Cart (<?= (int)$cartCount ?>)</a></span>
    </div>
    <p style="font-size:13px;color:var(--ink-soft);margin-bottom:16px">
        As a distributor you buy at <b>DP</b> and every approved order adds <b>BV</b> to your network.
        Sell at MRP to earn retail profit of <b>MRP − DP</b> per unit.
    </p>

    <div class="filter-form" style="gap:8px;flex-wrap:wrap">
        <a class="btn <?= !$cat ? 'btn-primary' : 'btn-light' ?> btn-sm" href="shop.php">All</a>
        <?php foreach ($categories as $c): ?>
            <a class="btn <?= ($cat && $cat['id'] == $c['id']) ? 'btn-primary' : 'btn-light' ?> btn-sm" href="shop.php?cat=<?= e($c['slug']) ?>"><?= e($c['name']) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$products): ?>
        <div class="empty-state"><span class="es-ico">📦</span>No products in this category.</div>
    <?php else: ?>
    <div class="three-col" style="grid-template-columns:repeat(4,1fr);gap:16px">
        <?php foreach ($products as $p): ?>
        <div class="shop-card">
            <div class="sc-img"><img src="<?= e(upload_url($p['image']) ?: placeholder('Product')) ?>" alt=""></div>
            <div class="sc-body">
                <h4><?= e($p['name']) ?></h4>
                <div class="sc-meta"><?= e($p['size']) ?> · MRP <?= money($p['mrp']) ?> · Stock: <?= (int)$p['stock'] ?></div>
                <div class="sc-row">
                    <span class="sc-price"><?= money($p['dp']) ?> <small>DP</small><br>
                        <small style="color:#000;font-weight:600"><?= bv($p['bv']) ?></small></span>
                    <form method="post" style="display:flex;gap:6px;align-items:center">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                        <input class="form-control qty-input" type="number" name="qty" value="1" min="1" max="99">
                        <button class="btn btn-primary btn-sm" type="submit" <?= $p['stock'] < 1 ? 'disabled' : '' ?>>+ Add</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?= $links ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
