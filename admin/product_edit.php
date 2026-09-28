<?php
/** Product editor (create / update) */
require_once __DIR__ . '/../includes/init.php';
$a = require_admin();

$id = get_int('id');
$p = $id ? q_row("SELECT * FROM products WHERE id = ?", [$id]) : null;
if ($id && !$p) {
    flash('error', 'Product not found.');
    redirect('products.php');
}
$categories = q_all("SELECT * FROM categories WHERE status='active' ORDER BY name");

if (is_post()) {
    verify_csrf();
    $name = post_str('name');
    $slug = post_str('slug') ?: slugify($name);
    $catId = (int)post_str('category_id') ?: null;
    $size = post_str('size');
    $mrp = (float)post_str('mrp');
    $dp = (float)post_str('dp');
    $bv = (float)post_str('bv');
    $short = post_str('short_desc');
    $desc = $_POST['description'] ?? '';
    $benefits = $_POST['benefits'] ?? '';
    $ingredients = $_POST['ingredients'] ?? '';
    $howToUse = $_POST['how_to_use'] ?? '';
    $stock = (int)post_str('stock');
    $featured = isset($_POST['is_featured']) ? 1 : 0;
    $status = post_str('status') === 'inactive' ? 'inactive' : 'active';
    $sort = (int)post_str('sort_order');

    $errors = [];
    if (strlen($name) < 2) { $errors[] = 'Product name is required.'; }
    if ($mrp <= 0 || $dp <= 0) { $errors[] = 'MRP and DP must be positive numbers.'; }
    if ($dp > $mrp) { $errors[] = 'DP (distributor price) cannot be more than MRP.'; }
    if ($bv < 0) { $errors[] = 'BV cannot be negative.'; }
    if (q_val("SELECT COUNT(*) FROM products WHERE slug = ? AND id != ?", [$slug, $id ?: 0])) {
        $errors[] = 'Slug already in use.';
    }
    $image = handle_upload('image', 'products');
    if ($image === '') { $errors[] = 'Image upload failed.'; }

    if ($errors) {
        foreach ($errors as $er) { flash('error', $er); }
    } else {
        if ($p && $image && $p['image']) {
            delete_upload($p['image']);
        }
        $imgVal = $image ?: ($p['image'] ?? null);
        if ($p) {
            q("UPDATE products SET category_id=?, name=?, slug=?, size=?, mrp=?, dp=?, bv=?, short_desc=?,
               description=?, benefits=?, ingredients=?, how_to_use=?, image=?, stock=?, is_featured=?,
               status=?, sort_order=? WHERE id=?",
              [$catId, $name, $slug, $size, $mrp, $dp, $bv, $short, $desc, $benefits, $ingredients,
               $howToUse, $imgVal, $stock, $featured, $status, $sort, $id]);
            flash('success', 'Product updated.');
        } else {
            q("INSERT INTO products (category_id, name, slug, size, mrp, dp, bv, short_desc, description,
               benefits, ingredients, how_to_use, image, stock, is_featured, status, sort_order, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
              [$catId, $name, $slug, $size, $mrp, $dp, $bv, $short, $desc, $benefits, $ingredients,
               $howToUse, $imgVal, $stock, $featured, $status, $sort, now()]);
            flash('success', 'Product created.');
        }
        redirect('products.php');
    }
    $p = array_merge($p ?: [], ['name' => $name, 'slug' => $slug, 'category_id' => $catId, 'size' => $size,
        'mrp' => $mrp, 'dp' => $dp, 'bv' => $bv, 'short_desc' => $short, 'stock' => $stock,
        'is_featured' => $featured, 'status' => $status, 'sort_order' => $sort]);
}

$activeKey = 'products';
$pageTitle = $p ? 'Edit Product' : 'Add New Product';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card">
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-grid2">
            <div class="form-group">
                <label>Product Name <span class="req">*</span></label>
                <input class="form-control" name="name" required value="<?= e($p['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Slug (URL)</label>
                <input class="form-control" name="slug" value="<?= e($p['slug'] ?? '') ?>" placeholder="auto-generated">
            </div>
        </div>
        <div class="form-grid3">
            <div class="form-group">
                <label>Category</label>
                <select class="form-control" name="category_id">
                    <option value="">— None —</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)($p['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Size / Pack</label>
                <input class="form-control" name="size" value="<?= e($p['size'] ?? '') ?>" placeholder="e.g. 120 Capsules">
            </div>
            <div class="form-group">
                <label>Stock</label>
                <input class="form-control" type="number" name="stock" value="<?= (int)($p['stock'] ?? 100) ?>">
            </div>
        </div>
        <div class="form-grid3">
            <div class="form-group">
                <label>MRP (₹) <span class="req">*</span></label>
                <input class="form-control" type="number" step="0.01" name="mrp" required value="<?= e($p['mrp'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Distributor Price DP (₹) <span class="req">*</span></label>
                <input class="form-control" type="number" step="0.01" name="dp" required value="<?= e($p['dp'] ?? '') ?>">
                <div class="form-hint">Price at which distributors buy from their dashboard shop.</div>
            </div>
            <div class="form-group">
                <label>BV (Business Volume) <span class="req">*</span></label>
                <input class="form-control" type="number" step="0.01" name="bv" required value="<?= e($p['bv'] ?? '') ?>">
                <div class="form-hint">Commission points used by the MLM plan.</div>
            </div>
        </div>
        <div class="form-group">
            <label>Short Description</label>
            <input class="form-control" name="short_desc" value="<?= e($p['short_desc'] ?? '') ?>" maxlength="500">
        </div>
        <div class="form-group">
            <label>Full Description</label>
            <textarea class="form-control" name="description"><?= e($p['description'] ?? '') ?></textarea>
        </div>
        <div class="form-grid3">
            <div class="form-group">
                <label>Benefits (one per line)</label>
                <textarea class="form-control" name="benefits" style="min-height:90px"><?= e($p['benefits'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Ingredients</label>
                <textarea class="form-control" name="ingredients" style="min-height:90px"><?= e($p['ingredients'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>How to Use</label>
                <textarea class="form-control" name="how_to_use" style="min-height:90px"><?= e($p['how_to_use'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="form-grid2">
            <div class="form-group">
                <label>Product Image <?= $p ? '(leave empty to keep current)' : '' ?></label>
                <input class="form-control" type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.gif">
                <?php if (!empty($p['image'])): ?>
                    <img class="upload-preview" id="imgPreview" src="<?= e(upload_url($p['image'])) ?>" alt="">
                <?php else: ?>
                    <img class="upload-preview" id="imgPreview" src="<?= e(placeholder('Image')) ?>" alt="" style="display:none">
                <?php endif; ?>
            </div>
            <div>
                <div class="form-grid2">
                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-control" name="status">
                            <option value="active" <?= ($p['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($p['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sort Order</label>
                        <input class="form-control" type="number" name="sort_order" value="<?= (int)($p['sort_order'] ?? 10) ?>">
                    </div>
                </div>
                <label class="form-check">
                    <input type="checkbox" name="is_featured" <?= (int)($p['is_featured'] ?? 0) ? 'checked' : '' ?>>
                    <span>Show on homepage (featured product)</span>
                </label>
            </div>
        </div>
        <button class="btn btn-primary" type="submit">💾 Save Product</button>
        <a class="btn btn-light" href="products.php">Cancel</a>
    </form>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
