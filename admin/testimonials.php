<?php
/** Testimonials management */
require_once __DIR__ . '/../includes/init.php';
$a = require_admin();

$editId = get_int('edit');

if (is_post()) {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'save') {
        $name = post_str('name');
        $designation = post_str('designation');
        $content = post_str('content');
        $rating = max(1, min(5, (int)post_str('rating', 5)));
        $sort = (int)post_str('sort_order');
        $status = post_str('status') === 'inactive' ? 'inactive' : 'active';
        $photo = handle_upload('photo', 'testimonials');

        if (strlen($name) < 2 || strlen($content) < 10) {
            flash('error', 'Name and testimonial text are required.');
        } else {
            if ($editId) {
                $old = q_row("SELECT photo FROM testimonials WHERE id = ?", [$editId]);
                if ($photo && $old['photo']) { delete_upload($old['photo']); }
                q("UPDATE testimonials SET name=?, designation=?, photo=?, content=?, rating=?, sort_order=?, status=? WHERE id=?",
                  [$name, $designation, $photo ?: $old['photo'], $content, $rating, $sort, $status, $editId]);
                flash('success', 'Testimonial updated.');
            } else {
                q("INSERT INTO testimonials (name, designation, photo, content, rating, sort_order, status)
                   VALUES (?, ?, ?, ?, ?, ?, ?)", [$name, $designation, $photo, $content, $rating, $sort, $status]);
                flash('success', 'Testimonial added.');
            }
            redirect('testimonials.php');
        }
    }

    if ($action === 'delete') {
        $id = (int)post_str('id');
        $t = q_row("SELECT photo FROM testimonials WHERE id = ?", [$id]);
        delete_upload($t['photo'] ?? null);
        q("DELETE FROM testimonials WHERE id = ?", [$id]);
        flash('success', 'Testimonial deleted.');
        redirect('testimonials.php');
    }
}

$editT = $editId ? q_row("SELECT * FROM testimonials WHERE id = ?", [$editId]) : null;
$testimonials = q_all("SELECT * FROM testimonials ORDER BY sort_order, id");

$activeKey = 'testimonials';
$pageTitle = 'Testimonials';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="two-col">
    <div class="card">
        <div class="card-title">⭐ Testimonials (<?= count($testimonials) ?>)</div>
        <?php if (!$testimonials): ?>
            <div class="empty-state"><span class="es-ico">⭐</span>No testimonials yet.</div>
        <?php endif; ?>
        <?php foreach ($testimonials as $t): ?>
        <div style="border:1px solid var(--line);border-radius:10px;padding:14px;margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                <div>
                    <b><?= e($t['name']) ?></b> <small style="color:var(--ink-soft)"><?= e($t['designation']) ?></small>
                    <span style="color:var(--dash-gold)"><?= str_repeat('★', (int)$t['rating']) ?></span>
                    <?= badge($t['status'], $t['status'] === 'active' ? 'success' : 'secondary') ?>
                </div>
                <div class="table-actions">
                    <a class="btn btn-outline btn-sm" href="testimonials.php?edit=<?= (int)$t['id'] ?>">Edit</a>
                    <form method="post" class="inline-form" data-confirm="Delete this testimonial?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn btn-danger btn-sm" type="submit">Del</button>
                    </form>
                </div>
            </div>
            <p style="font-size:13px;color:var(--ink-soft);margin-top:6px">“<?= e($t['content']) ?>”</p>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="card-title"><?= $editT ? '✏️ Edit Testimonial' : '➕ Add Testimonial' ?></div>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <div class="form-grid2">
                <div class="form-group">
                    <label>Name <span class="req">*</span></label>
                    <input class="form-control" name="name" required value="<?= e($editT['name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Designation</label>
                    <input class="form-control" name="designation" value="<?= e($editT['designation'] ?? '') ?>" placeholder="Distributor — Nagpur">
                </div>
            </div>
            <div class="form-group">
                <label>Testimonial Text <span class="req">*</span></label>
                <textarea class="form-control" name="content" required><?= e($editT['content'] ?? '') ?></textarea>
            </div>
            <div class="form-grid3">
                <div class="form-group">
                    <label>Rating (1–5)</label>
                    <select class="form-control" name="rating">
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <option value="<?= $i ?>" <?= (int)($editT['rating'] ?? 5) === $i ? 'selected' : '' ?>><?= str_repeat('★', $i) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sort Order</label>
                    <input class="form-control" type="number" name="sort_order" value="<?= (int)($editT['sort_order'] ?? 10) ?>">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select class="form-control" name="status">
                        <option value="active" <?= ($editT['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($editT['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Photo (optional) <?= $editT ? '— leave empty to keep current' : '' ?></label>
                <input class="form-control" type="file" name="photo" accept=".jpg,.jpeg,.png,.webp">
            </div>
            <button class="btn btn-primary" type="submit"><?= $editT ? 'Update' : 'Add Testimonial' ?></button>
            <?php if ($editT): ?><a class="btn btn-light" href="testimonials.php">Cancel</a><?php endif; ?>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
