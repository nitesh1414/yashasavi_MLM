<?php
/** Award & Rewards management (next-to-next matching rewards, points based). */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

$editId = get_int('edit');
$editing = null;
if ($editId) {
    $editing = q_row("SELECT * FROM award_rewards WHERE id = ?", [$editId]);
    if (!$editing) { flash('error', 'Reward not found.'); redirect('rewards.php'); }
}

if (is_post()) {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'save') {
        $points = (int)post_str('points');
        $title = trim(post_str('reward_title'));
        $type = post_str('reward_type') === 'cash' ? 'cash' : 'item';
        $amount = (float)post_str('amount');
        $desc = trim(post_str('description'));
        $sort = (int)post_str('sort_order');
        $status = post_str('status') === 'inactive' ? 'inactive' : 'active';

        $errors = [];
        if ($points <= 0) { $errors[] = 'Points must be greater than 0.'; }
        if (strlen($title) < 2) { $errors[] = 'Reward title required.'; }
        if ($type === 'cash' && $amount <= 0) { $errors[] = 'Cash rewards need an amount.'; }

        if ($errors) {
            foreach ($errors as $er) { flash('error', $er); }
        } else {
            if ($editing) {
                q("UPDATE award_rewards SET points=?, reward_title=?, reward_type=?, amount=?,
                   description=?, status=?, sort_order=? WHERE id=?",
                  [$points, $title, $type, $amount, $desc, $status, $sort, $editId]);
                flash('success', 'Award reward updated.');
            } else {
                q("INSERT INTO award_rewards (points, reward_title, reward_type, amount, description, status, sort_order, created_at)
                   VALUES (?,?,?,?,?,?,?,?)",
                  [$points, $title, $type, $amount, $desc, $status, $sort, now()]);
                flash('success', 'Award reward created.');
            }
            redirect('rewards.php');
        }
    } elseif ($action === 'delete') {
        $id = (int)post_str('id');
        $awarded = (int)q_val("SELECT COUNT(*) FROM commissions WHERE type = 'award' AND level = ? AND status = 'credited'", [$id]);
        if ($awarded) {
            flash('error', 'This reward was already awarded to ' . $awarded . ' member(s) — mark it inactive instead.');
        } else {
            q("DELETE FROM award_rewards WHERE id = ?", [$id]);
            flash('success', 'Award reward deleted.');
        }
        redirect('rewards.php');
    }
}

$rewards = q_all("SELECT r.*,
                  (SELECT COUNT(*) FROM commissions c WHERE c.type='award' AND c.level = r.id AND c.status='credited') AS awarded
                  FROM award_rewards r ORDER BY r.points ASC");
$pointBv = (float)get_plan()['point_bv'];

$activeKey = 'rewards';
$pageTitle = 'Award & Rewards';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<?php if ($editing || get_str('action') === 'add'): ?>
<div class="card" style="max-width:720px">
    <div class="card-title">🏆 <?= $editing ? 'Edit Award Reward' : 'Add Award Reward' ?>
        <span class="right"><a class="btn btn-light btn-sm" href="rewards.php">← Back to list</a></span>
    </div>
    <p class="form-hint">Awards are granted automatically when a member's team business reaches the required points (1 point = <?= e(bv($pointBv)) ?> of team BV). Each reward is given once per member.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div class="form-grid2">
            <div class="form-group">
                <label>Points required *</label>
                <input class="form-control" type="number" min="1" name="points" required value="<?= (int)($editing['points'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Reward type</label>
                <select class="form-control" name="reward_type">
                    <option value="item" <?= ($editing['reward_type'] ?? 'item') === 'item' ? 'selected' : '' ?>>Item / Gift</option>
                    <option value="cash" <?= ($editing['reward_type'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash fund</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Reward title *</label>
            <input class="form-control" name="reward_title" required value="<?= e($editing['reward_title'] ?? '') ?>" placeholder="e.g. Dinner Set, Mixer Grinder, Cash Fund ₹1,00,000">
        </div>
        <div class="form-grid2">
            <div class="form-group">
                <label>Cash amount (₹, for cash rewards)</label>
                <input class="form-control" type="number" step="0.01" name="amount" value="<?= e($editing['amount'] ?? '0') ?>">
            </div>
            <div class="form-group">
                <label>Sort order</label>
                <input class="form-control" type="number" name="sort_order" value="<?= (int)($editing['sort_order'] ?? 0) ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Description</label>
            <input class="form-control" name="description" maxlength="500" value="<?= e($editing['description'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Status</label>
            <select class="form-control" name="status">
                <option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= ($editing['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <button class="btn btn-primary" type="submit">💾 <?= $editing ? 'Update Reward' : 'Create Reward' ?></button>
    </form>
</div>

<?php else: ?>
<div class="card">
    <div class="card-title">🏆 Award &amp; Rewards (next-to-next matching)
        <span class="right"><a class="btn btn-primary btn-sm" href="rewards.php?action=add">➕ Add Reward</a></span>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>#</th><th>Points</th><th>Team BV needed</th><th>Reward</th><th>Type</th><th>Cash</th><th>Awarded to</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($rewards as $r): ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><b><?= (int)$r['points'] ?> P</b></td>
                        <td><?= e(bv((int)$r['points'] * $pointBv)) ?></td>
                        <td><?= e($r['reward_title']) ?></td>
                        <td><?= $r['reward_type'] === 'cash' ? '💰 Cash' : '🎁 Item' ?></td>
                        <td><?= $r['reward_type'] === 'cash' ? '₹' . number_format((float)$r['amount'], 0) : '—' ?></td>
                        <td><?= (int)$r['awarded'] ?> member(s)</td>
                        <td><?= $r['status'] === 'active' ? '<span class="text-ok">Active</span>' : '<span class="text-danger">Inactive</span>' ?></td>
                        <td class="ta-r">
                            <a class="btn btn-light btn-sm" href="rewards.php?edit=<?= (int)$r['id'] ?>">✏️ Edit</a>
                            <form method="post" style="display:inline" onsubmit="return confirm('Delete this reward?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-light btn-sm" type="submit">🗑</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rewards): ?>
                    <tr><td colspan="9" class="muted">No award rewards defined yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
