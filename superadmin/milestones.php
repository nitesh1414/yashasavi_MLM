<?php
/** Named, display-only dashboard milestone funds. */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/dashboard_milestones.php';
$a = require_superadmin();
$storageReady = dashboard_milestones_available();
$editId = max(0, get_int('edit'));
$form = null;

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $rawId = isset($_POST['id']) && is_scalar($_POST['id']) ? trim((string)$_POST['id']) : '';
    $validId = ctype_digit($rawId) && strlen($rawId) <= 10 && (float)$rawId <= 4294967295;
    $id = $validId ? (int)$rawId : -1;
    if (!$storageReady) {
        flash('error', 'Milestone storage is unavailable. Apply DATABASE_CHANGES_1.8.54.sql before saving.');
        redirect('milestones.php');
    }
    if ($action === 'save') {
        $editId = max(0, $id);
        $form = [
            'name' => is_scalar($_POST['name'] ?? null) ? trim((string)$_POST['name']) : '',
            'target_points' => is_scalar($_POST['target_points'] ?? null) ? trim((string)$_POST['target_points']) : '',
            'amount' => is_scalar($_POST['amount'] ?? null) ? trim((string)$_POST['amount']) : '',
            'sort_order' => is_scalar($_POST['sort_order'] ?? null) ? trim((string)$_POST['sort_order']) : '',
            'status' => is_scalar($_POST['status'] ?? null) ? trim((string)$_POST['status']) : '',
        ];
        try {
            if (!$validId) { throw new RuntimeException('Invalid milestone identifier.'); }
            [$ok, $message] = dashboard_milestone_save($id, $form);
            if ($ok) { flash('success', $message); redirect('milestones.php'); }
            flash('error', $message);
        } catch (Throwable $ex) {
            error_log('Milestone update failed: ' . $ex->getMessage());
            flash('error', $ex instanceof PDOException ? 'Could not save the milestone. Check for duplicate names and try again.' : $ex->getMessage());
        }
    } elseif ($action === 'delete') {
        try {
            if (!$validId || $id < 1) { throw new RuntimeException('Invalid milestone identifier.'); }
            [$ok, $message] = dashboard_milestone_delete($id);
            flash($ok ? 'success' : 'error', $message);
        } catch (Throwable $ex) {
            error_log('Milestone deletion failed: ' . $ex->getMessage());
            flash('error', $ex instanceof PDOException ? 'Could not delete the milestone.' : $ex->getMessage());
        }
        redirect('milestones.php');
    }
}

$milestones = $storageReady ? dashboard_milestones_list() : [];
$edit = null;
foreach ($milestones as $row) { if ((int)$row['id'] === $editId) { $edit = $row; break; } }
if ($editId && !$edit && $form === null) { flash('warning', 'That milestone was not found.'); $editId = 0; }
$form = $form ?? ($edit ?: ['name'=>'', 'target_points'=>'', 'amount'=>'', 'status'=>'active', 'sort_order'=>0]);

$activeKey = 'milestones';
$pageTitle = 'Dashboard Milestones';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<?php if (!$storageReady): ?>
<div class="alert alert-warning">Named milestones require the v1.8.54 database changes. Ask your database administrator to review and apply <b>DATABASE_CHANGES_1.8.54.sql</b>.</div>
<?php else: ?>
<div class="two-col">
    <div class="card">
        <div class="card-title"><i class="fas fa-flag-checkered" aria-hidden="true"></i> Named Milestones</div>
        <p class="form-hint">Each active fund has one point target and amount and appears on its own member dashboard card. These are informational only: they never credit a wallet or create a payout.</p>
        <div class="table-wrap" style="box-shadow:none;margin-top:14px">
            <table class="table">
                <thead><tr><th>Name</th><th>Target</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($milestones as $item): ?>
                <tr>
                    <td><b><?= e($item['name']) ?></b></td>
                    <td><?= number_format((int)$item['target_points']) ?> pts</td>
                    <td><?= money($item['amount']) ?></td>
                    <td><?= status_badge($item['status']) ?></td>
                    <td><div class="table-actions">
                        <a class="btn btn-outline btn-sm" href="milestones.php?edit=<?= (int)$item['id'] ?>">Edit</a>
                        <form method="post" class="inline-form" data-confirm="Delete this informational milestone? Wallets and payouts will not change.">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Del</button>
                        </form>
                    </div></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$milestones): ?><tr><td colspan="5">No additional milestones yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="form-hint" style="margin-top:12px">Car Fund and Tour/Vacation are configured separately in <a href="plan.php">MLM Plan Settings</a>.</p>
    </div>
    <div class="card">
        <div class="card-title"><i class="fas fa-plus-circle" aria-hidden="true"></i> <?= $editId ? 'Edit Milestone' : 'Add Milestone' ?></div>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$editId ?>">
            <div class="form-group"><label for="milestone-name">Fund name <span class="req">*</span></label>
                <input id="milestone-name" class="form-control" name="name" maxlength="80" required value="<?= e($form['name']) ?>" placeholder="e.g. Bike Fund"></div>
            <div class="form-grid2">
                <div class="form-group"><label for="milestone-points">Target points <span class="req">*</span></label>
                    <input id="milestone-points" class="form-control" type="number" min="1" max="4294967295" step="1" name="target_points" required value="<?= e($form['target_points']) ?>"></div>
                <div class="form-group"><label for="milestone-amount">Milestone amount (₹) <span class="req">*</span></label>
                    <input id="milestone-amount" class="form-control" type="number" min="0" max="9999999999.99" step="0.01" name="amount" required value="<?= e($form['amount']) ?>"></div>
                <div class="form-group"><label for="milestone-order">Sort order</label>
                    <input id="milestone-order" class="form-control" type="number" min="0" max="2147483647" step="1" name="sort_order" value="<?= e($form['sort_order']) ?>"></div>
                <div class="form-group"><label for="milestone-status">Status</label>
                    <select id="milestone-status" class="form-control" name="status">
                        <option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $form['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select></div>
            </div>
            <button class="btn btn-primary" type="submit"><?= $editId ? 'Update Milestone' : 'Add Milestone' ?></button>
            <?php if ($editId): ?><a class="btn btn-light" href="milestones.php">Cancel</a><?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
