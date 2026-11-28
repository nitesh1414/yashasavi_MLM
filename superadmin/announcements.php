<?php
/** Announcements for distributor panel */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

$editId = get_int('edit');

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'save') {
        $title = post_str('title');
        $body = post_str('content');
        $status = post_str('status') === 'inactive' ? 'inactive' : 'active';
        if (strlen($title) < 3 || strlen($body) < 5) {
            flash('error', 'Title and message are required.');
        } else {
            if ($editId) {
                q("UPDATE announcements SET title=?, content=?, status=? WHERE id=?", [$title, $body, $status, $editId]);
                flash('success', 'Announcement updated.');
            } else {
                q("INSERT INTO announcements (title, content, status, created_by, created_at) VALUES (?, ?, ?, ?, ?)",
                  [$title, $body, $status, $a['id'], now()]);
                flash('success', 'Announcement published.');
            }
            redirect('announcements.php');
        }
    } elseif ($action === 'delete') {
        q("DELETE FROM announcements WHERE id = ?", [(int)post_str('id')]);
        flash('success', 'Announcement deleted.');
        redirect('announcements.php');
    }
}

$editAnn = $editId ? q_row("SELECT * FROM announcements WHERE id = ?", [$editId]) : null;
$anns = q_all("SELECT an.*, ad.username AS author FROM announcements an
               LEFT JOIN admins ad ON ad.id = an.created_by ORDER BY an.id DESC");

$activeKey = 'announcements';
$pageTitle = 'Announcements';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="two-col">
    <div class="card">
        <div class="card-title">📣 Announcements (<?= count($anns) ?>)</div>
        <?php if (!$anns): ?>
            <div class="empty-state"><span class="es-ico">📣</span>No announcements yet.</div>
        <?php endif; ?>
        <?php foreach ($anns as $an): ?>
        <div style="border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;align-items:center">
                <div>
                    <b><?= e($an['title']) ?></b> <?= badge($an['status'], $an['status'] === 'active' ? 'success' : 'secondary') ?><br>
                    <small style="color:var(--ink-soft)">by <?= e($an['author'] ?: 'system') ?> · <?= dmy($an['created_at'], true) ?></small>
                </div>
                <div class="table-actions">
                    <a class="btn btn-outline btn-sm" href="announcements.php?edit=<?= (int)$an['id'] ?>">Edit</a>
                    <form method="post" class="inline-form" data-confirm="Delete this announcement?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$an['id'] ?>">
                        <button class="btn btn-danger btn-sm" type="submit">Del</button>
                    </form>
                </div>
            </div>
            <p style="font-size:13px;color:var(--ink-soft);margin-top:6px"><?= nl2br(e($an['content'])) ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="card-title"><?= $editAnn ? '✏️ Edit Announcement' : '➕ New Announcement' ?></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <div class="form-group">
                <label>Title <span class="req">*</span></label>
                <input class="form-control" name="title" required value="<?= e($editAnn['title'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Message <span class="req">*</span></label>
                <textarea class="form-control" name="content" style="min-height:120px" required><?= e($editAnn['content'] ?? '') ?></textarea>
                <div class="form-hint">Shown on every distributor's dashboard.</div>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select class="form-control" name="status">
                    <option value="active" <?= ($editAnn['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($editAnn['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <button class="btn btn-primary" type="submit"><?= $editAnn ? 'Update' : '📢 Publish' ?></button>
            <?php if ($editAnn): ?><a class="btn btn-light" href="announcements.php">Cancel</a><?php endif; ?>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
