<?php
/** Homepage slider management */
require_once __DIR__ . '/../includes/init.php';
$a = require_admin();

$editId = get_int('edit');

if (is_post()) {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'save') {
        $title = post_str('title');
        $subtitle = post_str('subtitle');
        $description = post_str('description');
        $btnText = post_str('btn_text');
        $btnLink = post_str('btn_link');
        $sort = (int)post_str('sort_order');
        $status = post_str('status') === 'inactive' ? 'inactive' : 'active';
        $image = handle_upload('image', 'sliders');
        if ($image === '') {
            flash('error', 'Please choose a valid slide image (max ' . MAX_UPLOAD_MB . ' MB).');
        } elseif (strlen($title) < 2) {
            flash('error', 'Slide title is required.');
        } else {
            if ($editId) {
                $old = q_row("SELECT image FROM sliders WHERE id = ?", [$editId]);
                if ($image && $old['image']) { delete_upload($old['image']); }
                q("UPDATE sliders SET title=?, subtitle=?, description=?, image=?, btn_text=?, btn_link=?, sort_order=?, status=? WHERE id=?",
                  [$title, $subtitle, $description, $image ?: $old['image'], $btnText, $btnLink, $sort, $status, $editId]);
                flash('success', 'Slide updated.');
            } else {
                q("INSERT INTO sliders (title, subtitle, description, image, btn_text, btn_link, sort_order, status)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                  [$title, $subtitle, $description, $image, $btnText, $btnLink, $sort, $status]);
                flash('success', 'Slide added.');
            }
            redirect('sliders.php');
        }
    }

    if ($action === 'delete') {
        $id = (int)post_str('id');
        $s = q_row("SELECT image FROM sliders WHERE id = ?", [$id]);
        delete_upload($s['image'] ?? null);
        q("DELETE FROM sliders WHERE id = ?", [$id]);
        flash('success', 'Slide deleted.');
        redirect('sliders.php');
    }
}

$editSlide = $editId ? q_row("SELECT * FROM sliders WHERE id = ?", [$editId]) : null;
$sliders = q_all("SELECT * FROM sliders ORDER BY sort_order, id");

$activeKey = 'sliders';
$pageTitle = 'Home Slider';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="two-col">
    <div class="card">
        <div class="card-title">🖼️ Homepage Slides (<?= count($sliders) ?>)</div>
        <?php if (!$sliders): ?>
            <div class="empty-state"><span class="es-ico">🖼️</span>No slides yet — add your first slide.</div>
        <?php endif; ?>
        <?php foreach ($sliders as $s): ?>
        <div style="display:flex;gap:14px;align-items:center;border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:12px;flex-wrap:wrap">
            <img src="<?= e(upload_url($s['image']) ?: placeholder('S')) ?>" alt="" style="width:170px;height:80px;object-fit:cover;border-radius:8px">
            <div style="flex:1;min-width:180px">
                <b><?= e($s['title']) ?></b><br>
                <small style="color:var(--ink-soft)"><?= e($s['subtitle']) ?> — order #<?= (int)$s['sort_order'] ?></small><br>
                <?= badge($s['status'], $s['status'] === 'active' ? 'success' : 'secondary') ?>
            </div>
            <div class="table-actions">
                <a class="btn btn-outline btn-sm" href="sliders.php?edit=<?= (int)$s['id'] ?>">Edit</a>
                <form method="post" class="inline-form" data-confirm="Delete this slide?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit">Del</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="card-title"><?= $editSlide ? '✏️ Edit Slide' : '➕ Add New Slide' ?></div>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <div class="form-group">
                <label>Headline (big title) <span class="req">*</span></label>
                <input class="form-control" name="title" required value="<?= e($editSlide['title'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Kicker (small label above title)</label>
                <input class="form-control" name="subtitle" value="<?= e($editSlide['subtitle'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea class="form-control" name="description" style="min-height:70px"><?= e($editSlide['description'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Slide Image <?= $editSlide ? '(leave empty to keep current)' : '' ?> <span class="req">*</span></label>
                <input class="form-control" type="file" name="image" accept=".jpg,.jpeg,.png,.webp" required <?= $editSlide ? '' : 'required' ?>>
                <div class="form-hint">Recommended: 1600×700 px</div>
            </div>
            <div class="form-grid2">
                <div class="form-group">
                    <label>Button Text</label>
                    <input class="form-control" name="btn_text" value="<?= e($editSlide['btn_text'] ?? '') ?>" placeholder="Explore Products">
                </div>
                <div class="form-group">
                    <label>Button Link</label>
                    <input class="form-control" name="btn_link" value="<?= e($editSlide['btn_link'] ?? '') ?>" placeholder="products.php">
                </div>
            </div>
            <div class="form-grid2">
                <div class="form-group">
                    <label>Sort Order</label>
                    <input class="form-control" type="number" name="sort_order" value="<?= (int)($editSlide['sort_order'] ?? 10) ?>">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select class="form-control" name="status">
                        <option value="active" <?= ($editSlide['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($editSlide['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <button class="btn btn-primary" type="submit"><?= $editSlide ? 'Update Slide' : 'Add Slide' ?></button>
            <?php if ($editSlide): ?><a class="btn btn-light" href="sliders.php">Cancel</a><?php endif; ?>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
