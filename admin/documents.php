<?php
/** Legal documents & downloadable files (Legals + Promotion pages) */
require_once __DIR__ . '/../includes/init.php';
$a = require_admin();

$editId = get_int('edit');

if (is_post()) {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'save') {
        $title = post_str('title');
        $type = post_str('type') === 'download' ? 'download' : 'legal';
        $description = post_str('description');
        $externalUrl = post_str('external_url');
        $sort = (int)post_str('sort_order');
        $status = post_str('status') === 'inactive' ? 'inactive' : 'active';
        $image = handle_upload('image', 'documents');
        $file = handle_upload('file', 'documents', ALLOWED_DOC_EXT);

        if (strlen($title) < 2) {
            flash('error', 'Title is required.');
        } elseif ($type === 'legal' && !$image && !$editId) {
            flash('error', 'Please upload the certificate image for a legal document.');
        } elseif ($type === 'download' && !$file && !$externalUrl && !$editId) {
            flash('error', 'Upload a PDF file or provide an external URL for a download.');
        } else {
            if ($editId) {
                $old = q_row("SELECT * FROM documents WHERE id = ?", [$editId]);
                if ($image && $old['image']) { delete_upload($old['image']); }
                if ($file && $old['file']) { delete_upload($old['file']); }
                q("UPDATE documents SET title=?, type=?, image=?, file=?, external_url=?, description=?, sort_order=?, status=? WHERE id=?",
                  [$title, $type, $image ?: $old['image'], $file ?: $old['file'], $externalUrl,
                   $description, $sort, $status, $editId]);
                flash('success', 'Document updated.');
            } else {
                q("INSERT INTO documents (title, type, image, file, external_url, description, sort_order, status)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                  [$title, $type, $image, $file, $externalUrl, $description, $sort, $status]);
                flash('success', 'Document added.');
            }
            redirect('documents.php');
        }
    }

    if ($action === 'delete') {
        $id = (int)post_str('id');
        $d = q_row("SELECT * FROM documents WHERE id = ?", [$id]);
        delete_upload($d['image'] ?? null);
        delete_upload($d['file'] ?? null);
        q("DELETE FROM documents WHERE id = ?", [$id]);
        flash('success', 'Document deleted.');
        redirect('documents.php');
    }
}

$editDoc = $editId ? q_row("SELECT * FROM documents WHERE id = ?", [$editId]) : null;
$docs = q_all("SELECT * FROM documents ORDER BY type, sort_order, id");

$activeKey = 'documents';
$pageTitle = 'Legal Docs & Downloads';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="two-col">
    <div class="card">
        <div class="card-title">📑 Documents</div>
        <p class="form-hint" style="margin-bottom:14px">
            <b>Legal</b> documents appear on the <a href="<?= url('page.php?slug=legals') ?>" target="_blank">Legals page</a> (certificate images).
            <b>Download</b> items appear on the <a href="<?= url('page.php?slug=promotion') ?>" target="_blank">Promotion page</a> (brochure / plan PDFs).
        </p>
        <?php if (!$docs): ?>
            <div class="empty-state"><span class="es-ico">📑</span>No documents yet.</div>
        <?php endif; ?>
        <?php foreach ($docs as $d): ?>
        <div style="border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:10px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <?php if ($d['image']): ?>
                <img src="<?= e(upload_url($d['image'])) ?>" alt="" style="width:64px;height:48px;object-fit:cover;border-radius:6px">
            <?php elseif ($d['file']): ?>
                <span style="font-size:26px">📄</span>
            <?php endif; ?>
            <div style="flex:1;min-width:160px">
                <b><?= e($d['title']) ?></b> <?= badge($d['type'], $d['type'] === 'legal' ? 'info' : 'warning') ?><br>
                <small style="color:var(--ink-soft)"><?= e($d['description']) ?></small>
            </div>
            <div class="table-actions">
                <a class="btn btn-outline btn-sm" href="documents.php?edit=<?= (int)$d['id'] ?>">Edit</a>
                <form method="post" class="inline-form" data-confirm="Delete this document?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit">Del</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="card-title"><?= $editDoc ? '✏️ Edit Document' : '➕ Add Document' ?></div>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <div class="form-group">
                <label>Title <span class="req">*</span></label>
                <input class="form-control" name="title" required value="<?= e($editDoc['title'] ?? '') ?>">
            </div>
            <div class="form-grid2">
                <div class="form-group">
                    <label>Type</label>
                    <select class="form-control" name="type" id="docType">
                        <option value="legal" <?= ($editDoc['type'] ?? '') === 'legal' ? 'selected' : '' ?>>Legal (certificate image)</option>
                        <option value="download" <?= ($editDoc['type'] ?? '') === 'download' ? 'selected' : '' ?>>Download (PDF)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sort Order</label>
                    <input class="form-control" type="number" name="sort_order" value="<?= (int)($editDoc['sort_order'] ?? 10) ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <input class="form-control" name="description" value="<?= e($editDoc['description'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Certificate Image (for legal docs) <?= $editDoc ? '— keep empty to retain current' : '' ?></label>
                <input class="form-control" type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
            </div>
            <div class="form-group">
                <label>PDF File (for downloads) <?= $editDoc ? '— keep empty to retain current' : '' ?></label>
                <input class="form-control" type="file" name="file" accept=".pdf">
            </div>
            <div class="form-group">
                <label>or External URL (optional)</label>
                <input class="form-control" name="external_url" value="<?= e($editDoc['external_url'] ?? '') ?>" placeholder="https://...">
            </div>
            <div class="form-group">
                <label>Status</label>
                <select class="form-control" name="status">
                    <option value="active" <?= ($editDoc['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($editDoc['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <button class="btn btn-primary" type="submit"><?= $editDoc ? 'Update Document' : 'Add Document' ?></button>
            <?php if ($editDoc): ?><a class="btn btn-light" href="documents.php">Cancel</a><?php endif; ?>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
