<?php
/** Legal documents & downloadables shown on the public website. */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

$editId = get_int('edit');
$editing = null;
if ($editId) {
    $editing = q_row("SELECT * FROM documents WHERE id = ?", [$editId]);
    if (!$editing) { flash('error', 'Document not found.'); redirect('documents.php'); }
}

if (is_post()) {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'save') {
        $title = trim(post_str('title'));
        $type = post_str('type') === 'download' ? 'download' : 'legal';
        $desc = trim(post_str('description'));
        $extUrl = trim(post_str('external_url'));
        $sort = (int)post_str('sort_order');
        $status = post_str('status') === 'inactive' ? 'inactive' : 'active';

        $errors = [];
        if (strlen($title) < 2) { $errors[] = 'Title required.'; }

        $image = $editing ? $editing['image'] : null;
        $up = handle_upload('image', 'documents');
        if ($up === '') { $errors[] = 'Image could not be saved.'; }
        elseif ($up !== null) {
            if ($editing && $editing['image']) { delete_upload($editing['image']); }
            $image = $up;
        }
        $file = $editing ? $editing['file'] : null;
        $upf = handle_upload('file', 'downloads', 'pdf,jpg,jpeg,png');
        if ($upf === '') { $errors[] = 'File could not be saved.'; }
        elseif ($upf !== null) {
            if ($editing && $editing['file']) { delete_upload($editing['file']); }
            $file = $upf;
        }

        if ($errors) {
            foreach ($errors as $er) { flash('error', $er); }
        } else {
            if ($editing) {
                q("UPDATE documents SET title=?, type=?, image=?, file=?, external_url=?, description=?, status=?, sort_order=? WHERE id=?",
                  [$title, $type, $image, $file, $extUrl, $desc, $status, $sort, $editId]);
                flash('success', 'Document updated.');
            } else {
                q("INSERT INTO documents (title, type, image, file, external_url, description, status, sort_order)
                   VALUES (?,?,?,?,?,?,?,?)",
                  [$title, $type, $image, $file, $extUrl, $desc, $status, $sort]);
                flash('success', 'Document created.');
            }
            redirect('documents.php');
        }
    } elseif ($action === 'delete') {
        $id = (int)post_str('id');
        $d = q_row("SELECT image, file FROM documents WHERE id = ?", [$id]);
        if ($d) {
            if ($d['image']) { delete_upload($d['image']); }
            if ($d['file']) { delete_upload($d['file']); }
        }
        q("DELETE FROM documents WHERE id = ?", [$id]);
        flash('success', 'Document deleted.');
        redirect('documents.php');
    }
}

$docs = q_all("SELECT * FROM documents ORDER BY type, sort_order, id");

$activeKey = 'documents';
$pageTitle = 'Legal Documents & Downloads';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<?php if ($editing || get_str('action') === 'add'): ?>
<div class="card" style="max-width:720px">
    <div class="card-title">📄 <?= $editing ? 'Edit Document' : 'Add Document' ?>
        <span class="right"><a class="btn btn-light btn-sm" href="documents.php">← Back to list</a></span>
    </div>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div class="form-grid2">
            <div class="form-group">
                <label>Title *</label>
                <input class="form-control" name="title" required value="<?= e($editing['title'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Type</label>
                <select class="form-control" name="type">
                    <option value="legal" <?= ($editing['type'] ?? 'legal') === 'legal' ? 'selected' : '' ?>>Legal document (Legals page)</option>
                    <option value="download" <?= ($editing['type'] ?? '') === 'download' ? 'selected' : '' ?>>Download (Promotion page)</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Description</label>
            <input class="form-control" name="description" maxlength="500" value="<?= e($editing['description'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Document image (scan / certificate photo, jpg / png)</label>
            <?php if (!empty($editing['image'])): ?>
                <div style="margin-bottom:6px"><img src="<?= e(upload_url($editing['image'])) ?>" alt="" style="width:90px;border-radius:8px;border:1px solid var(--line)"></div>
            <?php endif; ?>
            <input class="form-control" type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
        </div>
        <div class="form-grid2">
            <div class="form-group">
                <label>Downloadable file (pdf / jpg / png)</label>
                <?php if (!empty($editing['file'])): ?>
                    <div class="form-hint">Current: <a href="<?= e(upload_url($editing['file'])) ?>" target="_blank"><?= e($editing['file']) ?></a></div>
                <?php endif; ?>
                <input class="form-control" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png">
            </div>
            <div class="form-group">
                <label>…or external URL</label>
                <input class="form-control" name="external_url" value="<?= e($editing['external_url'] ?? '') ?>">
            </div>
        </div>
        <div class="form-grid2">
            <div class="form-group">
                <label>Sort order</label>
                <input class="form-control" type="number" name="sort_order" value="<?= (int)($editing['sort_order'] ?? 0) ?>">
            </div>
            <div class="form-group">
                <label>Status</label>
                <select class="form-control" name="status">
                    <option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($editing['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>
        <button class="btn btn-primary" type="submit">💾 <?= $editing ? 'Update Document' : 'Create Document' ?></button>
    </form>
</div>

<?php else: ?>
<div class="card">
    <div class="card-title">📄 Legal Documents &amp; Downloads (<?= count($docs) ?>)
        <span class="right"><a class="btn btn-primary btn-sm" href="documents.php?action=add">➕ Add Document</a></span>
    </div>
    <p class="form-hint" style="margin-bottom:14px">Legal documents appear on the public <b>Legals</b> page; downloads appear on the <b>Promotion</b> page.</p>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>#</th><th>Image</th><th>Title</th><th>Type</th><th>File / URL</th><th>Sort</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td><?= (int)$d['id'] ?></td>
                        <td>
                            <?php if ($d['image'] && is_file(dirname(__DIR__) . '/uploads/' . $d['image'])): ?>
                                <img src="<?= e(upload_url($d['image'])) ?>" alt="" style="width:52px;border-radius:6px;border:1px solid var(--line)">
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><b><?= e($d['title']) ?></b><div class="muted" style="font-size:11px"><?= e(substr(strip_tags($d["description"] ?? ""), 0, 60)) ?></div></td>
                        <td><?= $d['type'] === 'legal' ? '⚖️ Legal' : '⬇️ Download' ?></td>
                        <td>
                            <?php if ($d['file']): ?><a href="<?= e(upload_url($d['file'])) ?>" target="_blank">file</a>
                            <?php elseif ($d['external_url']): ?><a href="<?= e($d['external_url']) ?>" target="_blank" rel="noopener">link</a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= (int)$d['sort_order'] ?></td>
                        <td><?= $d['status'] === 'active' ? '<span class="text-ok">Active</span>' : '<span class="text-danger">Inactive</span>' ?></td>
                        <td class="ta-r">
                            <a class="btn btn-light btn-sm" href="documents.php?edit=<?= (int)$d['id'] ?>">✏️ Edit</a>
                            <form method="post" style="display:inline" onsubmit="return confirm('Delete this document?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
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
