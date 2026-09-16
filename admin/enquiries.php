<?php
/** Contact enquiries inbox */
require_once __DIR__ . '/../includes/init.php';
$a = require_admin();

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $id = (int)post_str('id');
    if ($action === 'read') {
        q("UPDATE enquiries SET status='read' WHERE id = ?", [$id]);
    } elseif ($action === 'replied') {
        q("UPDATE enquiries SET status='replied' WHERE id = ?", [$id]);
    } elseif ($action === 'delete') {
        q("DELETE FROM enquiries WHERE id = ?", [$id]);
        flash('success', 'Enquiry deleted.');
    }
    redirect('enquiries.php' . (get_str('status') ? '?status=' . get_str('status') : ''));
}

$status = get_str('status');
$where = '1=1';
if ($status !== '') { $where .= " AND status = ?"; }
$params = $status !== '' ? [$status] : [];
$total = (int)q_val("SELECT COUNT(*) FROM enquiries WHERE $where", $params);
[$per, $offset] = paginate($total, 20, $links);
$rows = q_all("SELECT * FROM enquiries WHERE $where ORDER BY id DESC LIMIT $per OFFSET $offset", $params);

$activeKey = 'enquiries';
$pageTitle = 'Enquiries';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card">
    <div class="card-title">
        ✉️ Website Enquiries (<?= $total ?>)
        <span class="right">
            <a class="btn <?= $status === '' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="enquiries.php">All</a>
            <?php foreach (['new', 'read', 'replied'] as $st): ?>
                <a class="btn <?= $status === $st ? 'btn-primary' : 'btn-light' ?> btn-sm" href="enquiries.php?status=<?= $st ?>"><?= ucfirst($st) ?></a>
            <?php endforeach; ?>
        </span>
    </div>

    <?php if (!$rows): ?>
        <div class="empty-state"><span class="es-ico">✉️</span>No enquiries found.</div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table">
            <tr><th>From</th><th>Subject</th><th>Message</th><th>Date</th><th>Status</th><th>Actions</th></tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td>
                    <b><?= e($r['name']) ?></b><br>
                    <small style="color:#8d9c8d"><?= e($r['email'] ?: '') ?><?= $r['email'] && $r['mobile'] ? ' · ' : '' ?><?= e($r['mobile'] ?: '') ?></small>
                </td>
                <td><?= e($r['subject']) ?></td>
                <td style="max-width:340px"><small><?= e($r['message']) ?></small></td>
                <td><?= dmy($r['created_at'], true) ?></td>
                <td><?= status_badge($r['status']) ?></td>
                <td>
                    <div class="table-actions">
                        <?php if ($r['status'] === 'new'): ?>
                        <form method="post" class="inline-form"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-light btn-sm" type="submit">Mark Read</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($r['status'] !== 'replied'): ?>
                        <form method="post" class="inline-form"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="replied"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-outline btn-sm" type="submit">Mark Replied</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($r['email']): ?>
                            <a class="btn btn-light btn-sm" href="mailto:<?= e($r['email']) ?>?subject=Re: <?= e($r['subject']) ?>">Reply</a>
                        <?php endif; ?>
                        <form method="post" class="inline-form" data-confirm="Delete this enquiry?"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-danger btn-sm" type="submit">Del</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?= $links ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
