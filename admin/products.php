<?php
/** Products list (CMS) */
require_once __DIR__ . '/../includes/init.php';
$a = require_admin();

if (is_post() && post_str('action') === 'delete') {
    verify_csrf();
    $id = (int)post_str('id');
    $used = (int)q_val("SELECT COUNT(*) FROM order_items WHERE product_id = ?", [$id]);
    if ($used) {
        q("UPDATE products SET status='inactive' WHERE id = ?", [$id]);
        flash('warning', 'Product has order history — it was marked inactive instead of deleted.');
    } else {
        $p = q_row("SELECT image FROM products WHERE id = ?", [$id]);
        delete_upload($p['image'] ?? null);
        q("DELETE FROM products WHERE id = ?", [$id]);
        flash('success', 'Product deleted.');
    }
    redirect('products.php');
}

$q = get_str('q');
$where = "1=1";
$params = [];
if ($q !== '') {
    $where .= " AND (p.name LIKE ? OR p.slug LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
}
$total = (int)q_val("SELECT COUNT(*) FROM products p WHERE $where", $params);
[$per, $offset] = paginate($total, 15, $links);
$products = q_all("SELECT p.*, c.name AS cat_name FROM products p
                   LEFT JOIN categories c ON c.id = p.category_id
                   WHERE $where ORDER BY p.sort_order, p.id LIMIT $per OFFSET $offset", $params);

$activeKey = 'products';
$pageTitle = 'Products';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card">
    <div class="card-title">
        🧴 Products (<?= $total ?>)
        <a class="btn btn-primary btn-sm right" href="product_edit.php">+ Add New Product</a>
    </div>

    <form method="get" class="filter-form">
        <div class="form-group">
            <label>Search</label>
            <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Product name">
        </div>
        <button class="btn btn-primary" type="submit">Search</button>
        <?php if ($q): ?><a class="btn btn-light" href="products.php">Clear</a><?php endif; ?>
    </form>

    <div class="table-wrap" style="box-shadow:none">
        <table class="table">
            <tr><th></th><th>Product</th><th>Category</th><th>MRP</th><th>DP</th><th>BV</th><th>Stock</th><th>Status</th><th>Actions</th></tr>
            <?php foreach ($products as $p): ?>
            <tr>
                <td><img class="table-img" src="<?= e(upload_url($p['image']) ?: placeholder('P')) ?>" alt=""></td>
                <td><b><?= e($p['name']) ?></b><br><small style="color:#8d9c8d"><?= e($p['size']) ?><?= (int)$p['is_featured'] ? ' · ⭐ featured' : '' ?></small></td>
                <td><?= e($p['cat_name'] ?: '—') ?></td>
                <td><?= money($p['mrp']) ?></td>
                <td><?= money($p['dp']) ?></td>
                <td><?= e($p['bv']) ?></td>
                <td><?= (int)$p['stock'] ?></td>
                <td><?= badge($p['status'], $p['status'] === 'active' ? 'success' : 'secondary') ?></td>
                <td>
                    <div class="table-actions">
                        <a class="btn btn-outline btn-sm" href="product_edit.php?id=<?= (int)$p['id'] ?>">Edit</a>
                        <form method="post" class="inline-form" data-confirm="Delete this product?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button class="btn btn-danger btn-sm" type="submit">Del</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?= $links ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
