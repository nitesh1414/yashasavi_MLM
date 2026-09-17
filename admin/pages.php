<?php
/** CMS pages list */
require_once __DIR__ . '/../includes/init.php';
$a = require_admin();

if (is_post() && post_str('action') === 'delete') {
    verify_csrf();
    $id = (int)post_str('id');
    // do not allow deleting essential pages
    $page = q_row("SELECT * FROM pages WHERE id = ?", [$id]);
    if ($page && in_array($page['slug'], ['about-us', 'legals', 'promotion', 'opportunity'], true)) {
        flash('error', 'This page is part of the site structure and cannot be deleted (you can edit it).');
    } elseif ($page) {
        q("DELETE FROM pages WHERE id = ?", [$id]);
        flash('success', 'Page deleted.');
    }
    redirect('pages.php');
}

$total = (int)q_val("SELECT COUNT(*) FROM pages");
[$per, $offset] = paginate($total, 20, $links);
$pages = q_all("SELECT p.*, parent.title AS parent_title FROM pages p
                LEFT JOIN pages parent ON parent.id = p.parent_id
                ORDER BY p.menu_order, p.title LIMIT $per OFFSET $offset");

$activeKey = 'pages';
$pageTitle = 'Pages';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card">
    <div class="card-title">
        📄 Website Pages (<?= $total ?>)
        <a class="btn btn-primary btn-sm right" href="page_edit.php">+ Add New Page</a>
    </div>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table">
            <tr><th>Title</th><th>Slug</th><th>In Menu</th><th>Order</th><th>Status</th><th>Actions</th></tr>
            <?php foreach ($pages as $p): ?>
            <tr>
                <td>
                    <b><?= e($p['title']) ?></b>
                    <?php if ($p['parent_title']): ?><br><small style="color:#000">↳ under: <?= e($p['parent_title']) ?></small><?php endif; ?>
                </td>
                <td><code>page.php?slug=<?= e($p['slug']) ?></code></td>
                <td><?= (int)$p['show_in_menu'] ? badge('Yes', 'success') : badge('No', 'secondary') ?></td>
                <td><?= (int)$p['menu_order'] ?></td>
                <td><?= badge(ucfirst($p['status']), $p['status'] === 'published' ? 'success' : 'secondary') ?></td>
                <td>
                    <div class="table-actions">
                        <a class="btn btn-outline btn-sm" href="page_edit.php?id=<?= (int)$p['id'] ?>">Edit</a>
                        <a class="btn btn-light btn-sm" href="<?= url('page.php?slug=' . $p['slug']) ?>" target="_blank">View</a>
                        <form method="post" class="inline-form" data-confirm="Delete this page permanently?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button class="btn btn-danger btn-sm" type="submit">Delete</button>
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
