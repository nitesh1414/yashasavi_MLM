<?php
/** Rank definitions + current achievers */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/rank_icons.php';
$a = require_superadmin();

$editId = max(0, get_int('edit'));
$iconReady = rank_icon_storage_available();
$form = null;

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'save') {
        $field = function ($key, $default = '') { return isset($_POST[$key]) && is_scalar($_POST[$key]) ? trim((string)$_POST[$key]) : $default; };
        $idText = $field('id');
        $validId = ctype_digit($idText) && strlen($idText) <= 10 && (float)$idText <= 4294967295;
        $editId = $validId ? (int)$idText : 0;
        $form = ['name'=>$field('name'), 'min_points'=>$field('min_points'),
                 'reward_amount'=>$field('reward_amount'), 'sort_order'=>$field('sort_order')];
        $name = $form['name'];
        $pointsText = $form['min_points'];
        $rewardText = $form['reward_amount'];
        $sortText = $form['sort_order'];
        $removeIcon = $field('remove_icon') === '1';
        $fileError = $_FILES['icon_image']['error'] ?? UPLOAD_ERR_NO_FILE;
        $hasFile = !is_scalar($fileError) || (int)$fileError !== UPLOAD_ERR_NO_FILE;
        $errors = [];
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if (!$validId) { $errors[] = 'Invalid rank identifier.'; }
        if ($nameLength < 2 || $nameLength > 80 || preg_match('/[\x00-\x1f\x7f]/', $name)) { $errors[] = 'Rank name must be 2 to 80 characters.'; }
        if (!ctype_digit($pointsText) || strlen($pointsText) > 10 || (float)$pointsText > 4294967295) { $errors[] = 'Points must be a whole number from 0 to 4294967295.'; }
        if ($rewardText === '' || !is_numeric($rewardText) || !is_finite((float)$rewardText) || (float)$rewardText < 0 || (float)$rewardText > 9999999999.99) { $errors[] = 'Rank reward is outside the allowed range.'; }
        if (!preg_match('/^-?\d+$/D', $sortText) || strlen($sortText) > 11 || (float)$sortText < -2147483648 || (float)$sortText > 2147483647) { $errors[] = 'Sort order is outside the allowed range.'; }
        if (!$iconReady && ($hasFile || $removeIcon)) { $errors[] = 'Apply DATABASE_CHANGES_1.8.54.sql before using rank images.'; }
        if ($removeIcon && $hasFile) { $errors[] = 'Choose a replacement image or remove the existing image, not both.'; }
        if (!$errors) {
            $newIcon = null;
            try {
                $newIcon = rank_icon_upload('icon_image');
                $oldIcon = db_tx(function () use ($name, $pointsText, $rewardText, $sortText, $editId, $iconReady, $newIcon, $removeIcon) {
                    income_rules_ready(true); // serialize with rank awards and reconciliation
                    $old = $editId ? q_row('SELECT * FROM ranks WHERE id=? FOR UPDATE', [$editId]) : null;
                    if ($editId && !$old) { throw new RuntimeException('That rank no longer exists.'); }
                    if (q_val('SELECT COUNT(*) FROM ranks WHERE name=? AND id<>?', [$name, $editId])) {
                        throw new RuntimeException('Rank name already exists.');
                    }
                    $badgeSlug = strtolower(str_replace(' ', '-', $name));
                    $badgeSlug = function_exists('mb_substr') ? mb_substr($badgeSlug, 0, 60, 'UTF-8') : substr($badgeSlug, 0, 60);
                    $oldIcon = $old['icon_image'] ?? null;
                    $icon = $newIcon ?: ($removeIcon ? null : $oldIcon);
                    if ($editId && $iconReady) {
                        q('UPDATE ranks SET name=?,min_points=?,reward_amount=?,badge=?,sort_order=?,icon_image=? WHERE id=?',
                            [$name, (int)$pointsText, (float)$rewardText, $badgeSlug, (int)$sortText, $icon, $editId]);
                    } elseif ($editId) {
                        q('UPDATE ranks SET name=?,min_points=?,reward_amount=?,badge=?,sort_order=? WHERE id=?',
                            [$name, (int)$pointsText, (float)$rewardText, $badgeSlug, (int)$sortText, $editId]);
                    } elseif ($iconReady) {
                        q('INSERT INTO ranks (name,min_points,reward_amount,badge,sort_order,icon_image) VALUES (?,?,?,?,?,?)',
                            [$name, (int)$pointsText, (float)$rewardText, $badgeSlug, (int)$sortText, $icon]);
                    } else {
                        q('INSERT INTO ranks (name,min_points,reward_amount,badge,sort_order) VALUES (?,?,?,?,?)',
                            [$name, (int)$pointsText, (float)$rewardText, $badgeSlug, (int)$sortText]);
                    }
                    return ($newIcon || $removeIcon) ? $oldIcon : null;
                });
                try { rank_icon_delete_unreferenced($oldIcon); } catch (Throwable $cleanup) { error_log('Rank icon cleanup failed: ' . $cleanup->getMessage()); }
                flash('success', $editId ? 'Rank updated.' : 'Rank created.');
                redirect('ranks.php');
            } catch (Throwable $ex) {
                if ($newIcon) {
                    try { rank_icon_delete_unreferenced($newIcon); } catch (Throwable $cleanup) { error_log('Rank icon cleanup failed: ' . $cleanup->getMessage()); }
                }
                error_log('Rank update failed: ' . $ex->getMessage());
                $errors[] = $ex instanceof PDOException ? 'Rank update failed; no changes were saved.' : $ex->getMessage();
            }
        }
        foreach ($errors as $er) { flash('error', $er); }
    } elseif ($action === 'delete') {
        $id = (int)post_str('id');
        try {
            $oldIcon = db_tx(function () use ($id) {
                income_rules_ready(true);
                $row = q_row('SELECT * FROM ranks WHERE id=? FOR UPDATE', [$id]);
                if (!$row) { throw new RuntimeException('That rank no longer exists.'); }
                $inUse = (int)q_val('SELECT COUNT(*) FROM users WHERE rank_id=?', [$id]);
                if ($inUse) { throw new RuntimeException('Rank is held by ' . $inUse . ' user(s) — cannot delete.'); }
                q('DELETE FROM ranks WHERE id=?', [$id]);
                return $row['icon_image'] ?? null;
            });
            try { rank_icon_delete_unreferenced($oldIcon); } catch (Throwable $cleanup) { error_log('Rank icon cleanup failed: ' . $cleanup->getMessage()); }
            flash('success', 'Rank deleted.');
        } catch (Throwable $ex) {
            flash('error', $ex instanceof PDOException ? 'Rank could not be deleted.' : $ex->getMessage());
        }
        redirect('ranks.php');
    }
}

$editRank = $editId ? q_row('SELECT * FROM ranks WHERE id=?', [$editId]) : null;
$ranks = q_all('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.rank_id=r.id) AS holders
                FROM ranks r ORDER BY r.min_points ASC, r.id ASC');
$form = $form ?? ($editRank ?: ['name'=>'', 'min_points'=>0, 'reward_amount'=>0, 'sort_order'=>10]);

$activeKey = 'ranks';
$pageTitle = 'Ranks';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<?php if (!$iconReady): ?>
<div class="alert alert-warning">Rank image uploads require the v1.8.54 database changes. Review <b>DATABASE_CHANGES_1.8.54.sql</b>.</div>
<?php endif; ?>
<div class="two-col">
    <div class="card">
        <div class="card-title">🏅 Rank Ladder</div>
        <div class="table-wrap" style="box-shadow:none">
            <table class="table table-dt">
                <thead><tr><th>Rank</th><th>Points Required</th><th>Cash Reward</th><th>Holders</th><th>Actions</th></tr></thead>
                <tbody>
<?php foreach ($ranks as $r): $image = rank_icon_url($r['icon_image'] ?? null); ?>
                <tr>
                    <td><?php if ($image): ?><img src="<?= e($image) ?>" alt="" width="36" height="36" style="object-fit:contain;vertical-align:middle;margin-right:7px"><?php endif; ?><b><?= e($r['name']) ?></b></td>
                    <td><b><?= number_format((int)$r['min_points']) ?> pts</b></td>
                    <td><?= money($r['reward_amount']) ?></td>
                    <td><?= (int)$r['holders'] ?></td>
                    <td><div class="table-actions">
                        <a class="btn btn-outline btn-sm" href="ranks.php?edit=<?= (int)$r['id'] ?>">Edit</a>
                        <form method="post" class="inline-form" data-confirm="Delete this rank?">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-danger btn-sm" type="submit">Del</button>
                        </form>
                    </div></td>
                </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="form-hint">Points are awarded per eligible matched pair at the configured rate. Ranks are re-checked whenever points grow; the one-time cash reward is credited to the wallet and is eligible for payout.</p>
    </div>

    <div class="card">
        <div class="card-title"><?= $editId ? '✏️ Edit Rank' : '➕ Add Rank' ?></div>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$editId ?>">
            <div class="form-group">
                <label for="rank-name">Rank Name <span class="req">*</span></label>
                <input id="rank-name" class="form-control" name="name" maxlength="80" required value="<?= e($form['name']) ?>">
            </div>
            <div class="form-grid3">
                <div class="form-group"><label for="rank-points">Points Required</label>
                    <input id="rank-points" class="form-control" type="number" min="0" max="4294967295" step="1" name="min_points" required value="<?= e($form['min_points']) ?>"></div>
                <div class="form-group"><label for="rank-reward">Cash Reward (₹, one time)</label>
                    <input id="rank-reward" class="form-control" type="number" step="0.01" min="0" max="9999999999.99" name="reward_amount" required value="<?= e($form['reward_amount']) ?>"></div>
                <div class="form-group"><label for="rank-order">Sort Order</label>
                    <input id="rank-order" class="form-control" type="number" min="-2147483648" max="2147483647" step="1" name="sort_order" required value="<?= e($form['sort_order']) ?>"></div>
            </div>
            <?php if ($iconReady): $currentImage = rank_icon_url($editRank['icon_image'] ?? null); ?>
            <div class="form-group">
                <label for="rank-icon">Rank image (optional)</label>
                <?php if ($currentImage): ?><div style="margin:6px 0"><img src="<?= e($currentImage) ?>" alt="Current rank image" width="64" height="64" style="object-fit:contain"></div><?php endif; ?>
                <input id="rank-icon" class="form-control" type="file" name="icon_image" accept="image/jpeg,image/png,image/gif,image/webp">
                <p class="form-hint">JPG, PNG, GIF or WebP; up to <?= e(min((int)MAX_UPLOAD_MB, 5)) ?> MB and 2048 × 2048 pixels. Images are saved as PNG. Upload a file to replace the current image.</p>
                <?php if ($editRank && !empty($editRank['icon_image'])): ?><label><input type="checkbox" name="remove_icon" value="1"> Remove current image</label><?php endif; ?>
            </div>
            <?php endif; ?>
            <button class="btn btn-primary" type="submit"><?= $editId ? 'Update Rank' : 'Add Rank' ?></button>
            <?php if ($editId): ?><a class="btn btn-light" href="ranks.php">Cancel</a><?php endif; ?>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
