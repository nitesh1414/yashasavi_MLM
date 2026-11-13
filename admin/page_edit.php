<?php
/** CMS page editor (create / update) */
require_once __DIR__ . '/../includes/init.php';
$a = require_admin();

$id = get_int('id');
$page = $id ? q_row("SELECT * FROM pages WHERE id = ?", [$id]) : null;
if ($id && !$page) {
    flash('error', 'Page not found.');
    redirect('pages.php');
}
$parents = q_all("SELECT id, title FROM pages WHERE id != ? ORDER BY title", [$id ?: 0]);

if (is_post()) {
    verify_csrf();
    $title = post_str('title');
    $slug = post_str('slug') ?: slugify($title);
    $content = $_POST['content'] ?? '';
    $showInMenu = isset($_POST['show_in_menu']) ? 1 : 0;
    $menuOrder = (int)post_str('menu_order');
    $parentId = (int)post_str('parent_id') ?: null;
    $status = post_str('status') === 'draft' ? 'draft' : 'published';
    $metaTitle = post_str('meta_title');
    $metaDesc = post_str('meta_description');

    $errors = [];
    if (strlen($title) < 2) { $errors[] = 'Title is required.'; }
    // slug uniqueness
    $dup = q_val("SELECT COUNT(*) FROM pages WHERE slug = ? AND id != ?", [$slug, $id ?: 0]);
    if ($dup) { $errors[] = 'Slug already in use — pick a different one.'; }

    if ($errors) {
        foreach ($errors as $er) { flash('error', $er); }
    } else {
        if ($page) {
            q("UPDATE pages SET title=?, slug=?, content=?, meta_title=?, meta_description=?,
               show_in_menu=?, menu_order=?, parent_id=?, status=?, updated_at=? WHERE id=?",
              [$title, $slug, $content, $metaTitle, $metaDesc, $showInMenu, $menuOrder, $parentId, $status, now(), $id]);
            flash('success', 'Page updated successfully.');
        } else {
            q("INSERT INTO pages (title, slug, content, meta_title, meta_description, show_in_menu,
               menu_order, parent_id, status, created_at, updated_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
              [$title, $slug, $content, $metaTitle, $metaDesc, $showInMenu, $menuOrder, $parentId, $status, now(), now()]);
            flash('success', 'Page created successfully.');
        }
        redirect('pages.php');
    }
    // refill form on error
    $page = array_merge($page ?: [], [
        'title' => $title, 'slug' => $slug, 'content' => $content, 'meta_title' => $metaTitle,
        'meta_description' => $metaDesc, 'show_in_menu' => $showInMenu, 'menu_order' => $menuOrder,
        'parent_id' => $parentId, 'status' => $status,
    ]);
}

$activeKey = 'pages';
$pageTitle = ($page ? 'Edit Page: ' . $page['title'] : 'Add New Page');
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card">
    <form method="post">
        <?= csrf_field() ?>
        <div class="form-grid2">
            <div class="form-group">
                <label>Page Title <span class="req">*</span></label>
                <input class="form-control" name="title" required value="<?= e($page['title'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Slug (URL) — leave empty to auto-generate</label>
                <input class="form-control" name="slug" value="<?= e($page['slug'] ?? '') ?>" placeholder="about-us">
            </div>
        </div>
        <div class="form-group">
            <label>Content</label>
            <textarea class="form-control" name="content" id="editor" style="min-height:380px"><?= e($page['content'] ?? '') ?></textarea>
            <div class="form-hint">Use the rich editor. If it does not load, you can paste HTML directly.</div>
        </div>
        <div class="form-grid2">
            <div class="form-group">
                <label>Meta Title (SEO)</label>
                <input class="form-control" name="meta_title" value="<?= e($page['meta_title'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Meta Description (SEO)</label>
                <input class="form-control" name="meta_description" value="<?= e($page['meta_description'] ?? '') ?>">
            </div>
        </div>
        <div class="form-grid3">
            <div class="form-group">
                <label>Status</label>
                <select class="form-control" name="status">
                    <option value="published" <?= ($page['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
                    <option value="draft" <?= ($page['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
                </select>
            </div>
            <div class="form-group">
                <label>Show in Main Menu</label>
                <select class="form-control" name="show_in_menu">
                    <option value="1" <?= (int)($page['show_in_menu'] ?? 1) === 1 ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= (int)($page['show_in_menu'] ?? 1) === 0 ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="form-group">
                <label>Menu Order</label>
                <input class="form-control" type="number" name="menu_order" value="<?= (int)($page['menu_order'] ?? 10) ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Parent Page</label>
            <select class="form-control" name="parent_id">
                <option value="0">— None (top level) —</option>
                <?php foreach ($parents as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (int)($page['parent_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary" type="submit">💾 Save Page</button>
        <a class="btn btn-light" href="pages.php">Cancel</a>
    </form>
</div>

<script src="https://cdn.ckeditor.com/4.22.1/standard/ckeditor.js"></script>
<?php
$pageScripts = <<<JS
if (window.CKEDITOR) {
    CKEDITOR.replace('editor', { height: 360 });
}
JS;
require __DIR__ . '/../includes/dash_footer.php';
?>
