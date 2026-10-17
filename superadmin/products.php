<?php
/** Product catalogue management (MRP / DP / BV, images, stock, status). */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

$editId = get_int('edit');
$editing = null;
if ($editId) {
    $editing = q_row("SELECT * FROM products WHERE id = ?", [$editId]);
    if (!$editing) { flash('error', 'Product not found.'); redirect('products.php'); }
}

if (is_post()) {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'save') {
        $name = trim(post_str('name'));
        $slug = trim(post_str('slug'));
        $catId = (int)post_str('category_id');
        $size = trim(post_str('size'));
        $mrp = (float)post_str('mrp');
        $dp = (float)post_str('dp');
        $bv = (float)post_str('bv');
        $short = trim(post_str('short_desc'));
        $desc = trim($_POST['description'] ?? '');
        $benefits = trim($_POST['benefits'] ?? '');
        $ingredients = trim($_POST['ingredients'] ?? '');
        $howTo = trim($_POST['how_to_use'] ?? '');
        $stock = (int)post_str('stock');
        $featured = isset($_POST['is_featured']) ? 1 : 0;
        $status = post_str('status') === 'inactive' ? 'inactive' : 'active';
        $sort = (int)post_str('sort_order');

        $errors = [];
        if (strlen($name) < 2) { $errors[] = 'Product name required.'; }
        if ($slug === '') { $slug = slugify($name); }
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) { $errors[] = 'Slug may contain only lowercase letters, numbers and hyphens.'; }
        if (q_val("SELECT COUNT(*) FROM products WHERE slug = ? AND id != ?", [$slug, $editId])) { $errors[] = 'Slug already in use.'; }
        if ($mrp < 0 || $dp < 0 || $bv < 0) { $errors[] = 'MRP / DP / BV cannot be negative.'; }
        if ($dp > $mrp && $mrp > 0) { $errors[] = 'Distributor price (DP) should not exceed MRP.'; }

        $image = $editing ? $editing['image'] : null;
        $up = handle_upload('image', 'products');
        if ($up === '') { $errors[] = 'Product image could not be saved.'; }
        elseif ($up !== null) {
            if ($editing && $editing['image']) { delete_upload($editing['image']); }
            $image = $up;
        }

        if ($errors) {
            foreach ($errors as $er) { flash('error', $er); }
        } else {
            if ($editing) {
                q("UPDATE products SET category_id=?, name=?, slug=?, size=?, mrp=?, dp=?, bv=?,
                   short_desc=?, description=?, benefits=?, ingredients=?, how_to_use=?, image=?,
                   stock=?, is_featured=?, status=?, sort_order=? WHERE id=?",
                  [$catId, $name, $slug, $size, $mrp, $dp, $bv, $short, $desc, $benefits,
                   $ingredients, $howTo, $image, $stock, $featured, $status, $sort, $editId]);
                flash('success', 'Product updated.');
            } else {
                q("INSERT INTO products (category_id, name, slug, size, mrp, dp, bv, short_desc,
                   description, benefits, ingredients, how_to_use, image, stock, is_featured,
                   status, sort_order, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                  [$catId, $name, $slug, $size, $mrp, $dp, $bv, $short, $desc, $benefits,
                   $ingredients, $howTo, $image, $stock, $featured, $status, $sort, now()]);
                flash('success', 'Product created.');
            }
            redirect('products.php');
        }
    } elseif ($action === 'delete') {
        $id = (int)post_str('id');
        $inOrders = (int)q_val("SELECT COUNT(*) FROM order_items WHERE product_id = ?", [$id]);
        $p = q_row("SELECT image FROM products WHERE id = ?", [$id]);
        if ($inOrders) {
            flash('error', 'Product is used in ' . $inOrders . ' order line(s) — mark it inactive instead of deleting.');
        } else {
            if ($p && $p['image']) { delete_upload($p['image']); }
            q("DELETE FROM products WHERE id = ?", [$id]);
            flash('success', 'Product deleted.');
        }
        redirect('products.php');
    }
}

$categories = q_all("SELECT * FROM categories ORDER BY sort_order");
$products = q_all("SELECT p.*, c.name AS cat_name FROM products p
                   LEFT JOIN categories c ON c.id = p.category_id
                   ORDER BY p.sort_order, p.id");
if ($editing && $editing['image'] && !is_file(dirname(__DIR__) . '/uploads/' . $editing['image'])) {
    $editing['image'] = null;
}

$activeKey = 'products';
$pageTitle = 'Products';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<?php if ($editing || get_str('action') === 'add'): ?>
<div class="card" style="max-width:1000px">
    <div class="card-title">🛒 <?= $editing ? 'Edit Product' : 'Add New Product' ?>
        <span class="right"><a class="btn btn-light btn-sm" href="products.php">← Back to list</a></span>
    </div>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div class="form-grid2">
            <div class="form-group">
                <label>Product Name *</label>
                <input class="form-control" name="name" required value="<?= e($editing['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Slug (URL key, blank = auto)</label>
                <input class="form-control" name="slug" value="<?= e($editing['slug'] ?? '') ?>">
            </div>
        </div>
        <div class="form-grid3">
            <div class="form-group">
                <label>Category</label>
                <select class="form-control" name="category_id">
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)($editing['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Size / Pack</label>
                <input class="form-control" name="size" value="<?= e($editing['size'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Stock</label>
                <input class="form-control" type="number" name="stock" value="<?= (int)($editing['stock'] ?? 500) ?>">
            </div>
        </div>
        <div class="form-grid3">
            <div class="form-group">
                <label>MRP (₹) *</label>
                <input class="form-control" type="number" step="0.01" name="mrp" required value="<?= e($editing['mrp'] ?? '') ?>">
                <div class="form-hint">Retail selling price.</div>
            </div>
            <div class="form-group">
                <label>Distributor Price DP (₹)</label>
                <input class="form-control" type="number" step="0.01" name="dp" value="<?= e($editing['dp'] ?? '') ?>">
                <div class="form-hint">Member purchase price — the MRP−DP gap is the retail income.</div>
            </div>
            <div class="form-group">
                <label>Business Volume (BV) *</label>
                <input class="form-control" type="number" step="0.01" name="bv" required value="<?= e($editing['bv'] ?? '') ?>">
                <div class="form-hint">Drives all commissions.</div>
            </div>
        </div>
        <div class="form-group">
            <label>Short Description</label>
            <input class="form-control" name="short_desc" maxlength="500" value="<?= e($editing['short_desc'] ?? '') ?>">
        </div>
        <div class="form-grid2">
            <div class="form-group">
                <label>Description</label>
                <textarea class="form-control" name="description" rows="4"><?= e($editing['description'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Benefits (one per line or &lt;br&gt;)</label>
                <textarea class="form-control" name="benefits" rows="4"><?= e($editing['benefits'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="form-grid2">
            <div class="form-group">
                <label>Ingredients</label>
                <input class="form-control" name="ingredients" value="<?= e($editing['ingredients'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>How to Use</label>
                <input class="form-control" name="how_to_use" value="<?= e($editing['how_to_use'] ?? '') ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Product Image (jpg / png)</label>
            <?php if (!empty($editing['image'])): ?>
                <div style="margin-bottom:6px"><img src="<?= e(upload_url($editing['image'])) ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--line)"></div>
            <?php endif; ?>
            <input class="form-control" type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
        </div>
        <div class="form-grid3">
            <div class="form-group">
                <label>Status</label>
                <select class="form-control" name="status">
                    <option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($editing['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="form-group">
                <label>Sort order</label>
                <input class="form-control" type="number" name="sort_order" value="<?= (int)($editing['sort_order'] ?? 0) ?>">
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <label style="display:flex;gap:8px;align-items:center;font-weight:400">
                    <input type="checkbox" name="is_featured" <?= !empty($editing['is_featured']) ? 'checked' : '' ?>>
                    Show as featured product
                </label>
            </div>
        </div>
        <button class="btn btn-primary" type="submit">💾 <?= $editing ? 'Update Product' : 'Create Product' ?></button>
    </form>
</div>

<?php else: ?>
<div class="card">
    <div class="card-title">🛒 Products (<?= count($products) ?>)
        <span class="right"><a class="btn btn-primary btn-sm" href="products.php?action=add">➕ Add Product</a></span>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th><th>Image</th><th>Product</th><th>Category</th>
                    <th>MRP</th><th>DP</th><th>BV</th><th>Retail %</th>
                    <th>Stock</th><th>Status</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p): ?>
                    <?php $retail = $p['mrp'] > 0 ? round((($p['mrp'] - $p['dp']) / $p['mrp']) * 100) : 0; ?>
                    <tr>
                        <td><?= (int)$p['id'] ?></td>
                        <td>
                            <?php if ($p['image'] && is_file(dirname(__DIR__) . '/uploads/' . $p['image'])): ?>
                                <img src="<?= e(upload_url($p['image'])) ?>" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:8px;border:1px solid var(--line)">
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <b><?= e($p['name']) ?></b>
                            <?php if ($p['is_featured']): ?><span class="badge" style="background:#f7ecd2;color:#7a5b12">★</span><?php endif; ?>
                            <div class="muted" style="font-size:11px"><?= e($p['size']) ?></div>
                        </td>
                        <td><?= e($p['cat_name'] ?: '—') ?></td>
                        <td>₹<?= number_format((float)$p['mrp'], 0) ?></td>
                        <td>₹<?= number_format((float)$p['dp'], 0) ?></td>
                        <td><b><?= number_format((float)$p['bv'], 0) ?></b></td>
                        <td><?= $retail ?>%</td>
                        <td><?= (int)$p['stock'] ?></td>
                        <td><?= $p['status'] === 'active' ? '<span class="text-ok">Active</span>' : '<span class="text-danger">Inactive</span>' ?></td>
                        <td class="ta-r">
                            <a class="btn btn-light btn-sm" href="products.php?edit=<?= (int)$p['id'] ?>">✏️ Edit</a>
                            <form method="post" style="display:inline" onsubmit="return confirm('Delete this product?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button class="btn btn-light btn-sm" type="submit">🗑</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
