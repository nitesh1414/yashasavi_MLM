<?php
/** Categories list + create/edit */
require_once __DIR__ . '/../includes/init.php';
$a = require_admin();

$editId = get_int('edit');

if (is_post()) {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'save') {
        $name = post_str('name');
        $slug = post_str('slug') ?: slugify($name);
        $desc = post_str('description');
        $sort = (int)post_str('sort_order');
        $status = post_str('status') === 'inactive' ? 'inactive' : 'active';
        $errors = [];
        if (strlen($name) < 2) { $errors[] = 'Category name required.'; }
        if (q_val("SELECT COUNT(*) FROM categories WHERE slug = ? AND id != ?", [$slug, $editId])) { $errors[] = 'Slug already in use.'; }
        $image = handle_upload('image', 'categories');
        if ($image === '') { $errors[] = 'Image upload failed.'; }
        if ($errors) {
            foreach ($errors as $er) { flash('error', $er); }
        } else {
            if ($editId) {
                $old = q_row("SELECT image FROM categories WHERE id = ?", [$editId]);
                if ($image && $old['image']) { delete_upload($old['image']); }
                q("UPDATE categories SET name=?, slug=?, description=?, image=?, sort_order=?, status=? WHERE id=?",
                  [$name, $slug, $desc, $image ?: $old['image'], $sort, $status, $editId]);
                flash('success', 'Category updated.');
            } else {
                q("INSERT INTO categories (name, slug, description, image, sort_order, status, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?)", [$name, $slug, $desc, $image, $sort, $status, now()]);
                flash('success', 'Category created.');
            }
            redirect('categories.php');
        }
    }

    if ($action === 'delete') {
        $id = (int)post_str('id');
        $count = (int)q_val("SELECT COUNT(*) FROM products WHERE category_id = ?", [$id]);
        if ($count) {
            flash('error', 'Category has products. Move or delete them first.');
        } else {
            $c = q_row("SELECT image FROM categories WHERE id = ?", [$id]);
            delete_upload($c['image'] ?? null);
            q("DELETE FROM categories WHERE id = ?", [$id]);
            flash('success', 'Category deleted.');
        }
        redirect('categories.php');
    }
}

$editCat = $editId ? q_row("SELECT * FROM categories WHERE id = ?", [$editId]) : null;
$categories = q_all("SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
                     FROM categories c ORDER BY c.sort_order, c.name");

$activeKey = 'categories';
$pageTitle = 'Categories';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="two-col">
    <div class="card">
        <div class="card-title">🗂️ Categories (<?= count($categories) ?>)</div>
        <div class="table-wrap" style="box-shadow:none">
            <table class="table">
                <tr><th></th><th>Name</th><th>Products</th><th>Status</th><th>Actions</th></tr>
                <?php foreach ($categories as $c): ?>
                <tr>
                    <td><img class="table-img" src="<?= e(upload_url($c['image']) ?: placeholder('C')) ?>" alt=""></td>
                    <td><b><?= e($c['name']) ?></b><br><small style="color:#000"><?= e($c['slug']) ?></small></td>
                    <td><?= (int)$c['product_count'] ?></td>
                    <td><?= badge($c['status'], $c['status'] === 'active' ? 'success' : 'secondary') ?></td>
                    <td>
                        <div class="table-actions">
                            <a class="btn btn-outline btn-sm" href="categories.php?edit=<?= (int)$c['id'] ?>">Edit</a>
                            <form method="post" class="inline-form" data-confirm="Delete this category?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit">Del</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-title"><?= $editCat ? '✏️ Edit Category' : '➕ Add New Category' ?></div>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <div class="form-group">
                <label>Name <span class="req">*</span></label>
                <input class="form-control" name="name" required value="<?= e($editCat['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Slug</label>
                <input class="form-control" name="slug" value="<?= e($editCat['slug'] ?? '') ?>" placeholder="auto">
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea class="form-control" name="description" style="min-height:80px"><?= e($editCat['description'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Image <?= $editCat ? '(leave empty to keep current)' : '' ?></label>
                <input class="form-control" type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
                <?php if (!empty($editCat['image'])): ?>
                    <img class="upload-preview" src="<?= e(upload_url($editCat['image'])) ?>" alt="">
                <?php endif; ?>
            </div>
            <div class="form-grid2">
                <div class="form-group">
                    <label>Sort Order</label>
                    <input class="form-control" type="number" name="sort_order" value="<?= (int)($editCat['sort_order'] ?? 10) ?>">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select class="form-control" name="status">
                        <option value="active" <?= ($editCat['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($editCat['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <button class="btn btn-primary" type="submit"><?= $editCat ? 'Update Category' : 'Add Category' ?></button>
            <?php if ($editCat): ?><a class="btn btn-light" href="categories.php">Cancel</a><?php endif; ?>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
