<?php
/** Rank definitions + current achievers */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

$editId = get_int('edit');

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'save') {
        $name = post_str('name');
        $minPoints = (int)post_str('min_points');
        $reward = (float)post_str('reward_amount');
        $sort = (int)post_str('sort_order');
        $errors = [];
        if (strlen($name) < 2) { $errors[] = 'Rank name required.'; }
        if ($minPoints < 0) { $errors[] = 'Points cannot be negative.'; }
        if (!is_finite($reward) || $reward < 0 || $reward > 9999999999.99) { $errors[] = 'Rank reward is outside the allowed range.'; }
        if (!$errors) {
            try {
                db_tx(function () use ($name, $minPoints, $reward, $sort, $editId) {
                    income_rules_ready(true); // serialize with rank awards and reconciliation
                    if (q_val("SELECT COUNT(*) FROM ranks WHERE name = ? AND id != ?", [$name, $editId])) {
                        throw new RuntimeException('Rank name already exists.');
                    }
                    $badgeSlug = strtolower(str_replace(' ', '-', $name));
                    if ($editId) {
                        q("UPDATE ranks SET name=?, min_points=?, reward_amount=?, badge=?, sort_order=? WHERE id=?",
                          [$name, $minPoints, $reward, $badgeSlug, $sort, $editId]);
                    } else {
                        q("INSERT INTO ranks (name, min_points, reward_amount, badge, sort_order)
                           VALUES (?, ?, ?, ?, ?)", [$name, $minPoints, $reward, $badgeSlug, $sort]);
                    }
                });
                flash('success', $editId ? 'Rank updated.' : 'Rank created.');
                redirect('ranks.php');
            } catch (Throwable $ex) {
                error_log('Rank update failed: ' . $ex->getMessage());
                $errors[] = $ex instanceof PDOException ? 'Rank update failed; no changes were saved.' : $ex->getMessage();
            }
        }
        foreach ($errors as $er) { flash('error', $er); }
    } elseif ($action === 'delete') {
        $id = (int)post_str('id');
        try {
            db_tx(function () use ($id) {
                income_rules_ready(true);
                $inUse = (int)q_val("SELECT COUNT(*) FROM users WHERE rank_id = ?", [$id]);
                if ($inUse) { throw new RuntimeException('Rank is held by ' . $inUse . ' user(s) — cannot delete.'); }
                q("DELETE FROM ranks WHERE id = ?", [$id]);
            });
            flash('success', 'Rank deleted.');
        } catch (Throwable $ex) {
            flash('error', $ex instanceof PDOException ? 'Rank could not be deleted.' : $ex->getMessage());
        }
        redirect('ranks.php');
    }
}

$editRank = $editId ? q_row("SELECT * FROM ranks WHERE id = ?", [$editId]) : null;
$ranks = q_all("SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.rank_id = r.id) AS holders
                FROM ranks r ORDER BY r.min_points ASC, r.id ASC");

$activeKey = 'ranks';
$pageTitle = 'Ranks';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="two-col">
    <div class="card">
        <div class="card-title">🏅 Rank Ladder</div>
        <div class="table-wrap" style="box-shadow:none">
            <table class="table table-dt">
                <thead>
                    <tr><th>Rank</th><th>Points Required</th><th>Cash Reward</th><th>Holders</th><th>Actions</th></tr>
                </thead>
                <tbody>
<?php foreach ($ranks as $r): ?>
                <tr>
                    <td><b><?= e($r['name']) ?></b></td>
                    <td><b><?= number_format((int)$r['min_points']) ?> pts</b></td>
                    <td><?= money($r['reward_amount']) ?></td>
                    <td><?= (int)$r['holders'] ?></td>
                    <td>
                        <div class="table-actions">
                            <a class="btn btn-outline btn-sm" href="ranks.php?edit=<?= (int)$r['id'] ?>">Edit</a>
                            <form method="post" class="inline-form" data-confirm="Delete this rank?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit">Del</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                </table>
        </div>
        <p class="form-hint">Points are awarded per eligible matched pair at the configured rate. Ranks are re-checked whenever points grow; the one-time cash reward is credited to the wallet and is eligible for payout.</p>
    </div>

    <div class="card">
        <div class="card-title"><?= $editRank ? '✏️ Edit Rank' : '➕ Add Rank' ?></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <div class="form-group">
                <label>Rank Name <span class="req">*</span></label>
                <input class="form-control" name="name" required value="<?= e($editRank['name'] ?? '') ?>">
            </div>
            <div class="form-grid3">
                <div class="form-group">
                    <label>Points Required</label>
                    <input class="form-control" type="number" min="0" name="min_points" value="<?= (int)($editRank['min_points'] ?? 0) ?>">
                </div>
                <div class="form-group">
                    <label>Cash Reward (₹, one time)</label>
                    <input class="form-control" type="number" step="0.01" min="0" name="reward_amount" value="<?= e($editRank['reward_amount'] ?? 0) ?>">
                </div>
                <div class="form-group">
                    <label>Sort Order</label>
                    <input class="form-control" type="number" name="sort_order" value="<?= (int)($editRank['sort_order'] ?? 10) ?>">
                </div>
            </div>
            <button class="btn btn-primary" type="submit"><?= $editRank ? 'Update Rank' : 'Add Rank' ?></button>
            <?php if ($editRank): ?><a class="btn btn-light" href="ranks.php">Cancel</a><?php endif; ?>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
