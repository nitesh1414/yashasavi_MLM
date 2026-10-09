<?php
/**
 * Delete a distributor — SUPER ADMIN ONLY.
 * GET  ?id=N  → full information about the member + confirmation form.
 * POST        → performs the deletion after re-checking every safety rule.
 *
 * Safety rules (the network and the accounts must stay consistent):
 *   - the company root cannot be deleted
 *   - a member with a downline cannot be deleted (the tree would break)
 *   - a member with directly sponsored members cannot be deleted
 *   - a member with orders cannot be deleted (BV / commission history)
 * In those cases the page explains why and suggests blocking instead.
 */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

$id = (int)(get_str('id') ?: post_str('id'));
$u = q_row("SELECT u.*, s.username AS sponsor_name, p.username AS upline_name, r.name AS rank_name
            FROM users u
            LEFT JOIN users s ON s.id = u.sponsor_id
            LEFT JOIN users p ON p.id = u.placement_id
            LEFT JOIN ranks r ON r.id = u.rank_id
            WHERE u.id = ?", [$id]);
if (!$u) {
    flash('error', 'Distributor not found.');
    redirect('users.php');
}

/* ---- safety analysis ---- */
$isRoot = $u['placement_id'] === null;
$downline = (int)q_val("SELECT COUNT(*) FROM users WHERE path LIKE ?", [$u['path'] . '%']) - 1;
$directs = (int)q_val("SELECT COUNT(*) FROM users WHERE sponsor_id = ?", [$id]);
$orders = (int)q_val("SELECT COUNT(*) FROM orders WHERE user_id = ?", [$id]);
$orderSum = q_row("SELECT COUNT(*) n, COALESCE(SUM(total_dp),0) dp, COALESCE(SUM(total_bv),0) bv
                   FROM orders WHERE user_id = ?", [$id]);
$earn = user_earnings_breakdown($id);
$payouts = (int)q_val("SELECT COUNT(*) FROM payouts WHERE user_id = ?", [$id]);

/* left / right team sizes */
$teamL = 0; $teamR = 0;
$ch = user_children($id);
if ($ch['L']) { $teamL = (int)q_val("SELECT COUNT(*) FROM users WHERE path LIKE ?", [$ch['L']['path'] . '%']); }
if ($ch['R']) { $teamR = (int)q_val("SELECT COUNT(*) FROM users WHERE path LIKE ?", [$ch['R']['path'] . '%']); }

$reasons = [];
if ($isRoot) { $reasons[] = 'This is the company ROOT position — it can never be deleted.'; }
if ($downline > 0) { $reasons[] = 'This member has <b>' . number_format($downline) . ' downline members</b> below them — deleting would break the network tree. Move / remove the downline first.'; }
if ($directs > 0) { $reasons[] = 'This member is the <b>direct sponsor of ' . number_format($directs) . ' member(s)</b> — those members would lose their sponsor.'; }
if ($orders > 0) { $reasons[] = 'This member has <b>' . number_format($orders) . ' order(s)</b> (' . money($orderSum['dp']) . ' DP / ' . number_format((float)$orderSum['bv']) . ' BV) — the BV and commission history of the whole team would become inconsistent.'; }
$canDelete = !$reasons;

if (is_post()) {
    verify_csrf();
    if (post_str('action') !== 'delete') { redirect('users.php'); }
    if (!$canDelete) {
        flash('error', $u['username'] . ' cannot be deleted (see the reasons on their delete page). You can block them instead.');
        redirect('user_delete.php?id=' . $id);
    }
    /* re-check under the just-deleted state cannot change here; delete the
     * member's own money records (commissions, wallet, payout requests) and
     * the account itself — nothing else references a downline-free,
     * sponsor-free, order-free member */
    db_tx(function () use ($id) {
        q("DELETE FROM commissions WHERE user_id = ?", [$id]);
        q("DELETE FROM wallet_transactions WHERE user_id = ?", [$id]);
        q("DELETE FROM payouts WHERE user_id = ?", [$id]);
        q("DELETE FROM users WHERE id = ?", [$id]);
    });
    flash('success', 'Distributor ' . $u['username'] . ' (' . $u['full_name'] . ') has been deleted permanently.');
    redirect('users.php');
}

$activeKey = 'users';
$pageTitle = 'Delete Distributor';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="two-col">
    <div>
        <div class="card" style="border:1.5px solid #e53935">
            <div class="card-title">🗑 Delete Distributor — <?= e($u['username']) ?></div>

            <?php if ($canDelete): ?>
            <div class="alert alert-warning" style="margin-bottom:14px">
                <b>You are about to permanently delete this member.</b><br>
                This cannot be undone. Please check <b>all</b> the information below carefully before confirming.
            </div>
            <?php else: ?>
            <div class="alert alert-danger" style="margin-bottom:14px">
                <b>This member CANNOT be deleted:</b>
                <ul style="margin:8px 0 0 18px">
                    <?php foreach ($reasons as $r): ?><li><?= $r ?></li><?php endforeach; ?>
                </ul>
                You can <a href="users.php"><b>block</b></a> them instead — a blocked member cannot log in but the network stays intact.
            </div>
            <?php endif; ?>

            <table class="kv-table" style="width:100%">
                <tr><td colspan="2" style="background:#f2f6f1"><b>👤 Identity</b></td></tr>
                <tr><td>User ID</td><td><b><?= e($u['username']) ?></b></td></tr>
                <tr><td>Full name</td><td><b><?= e($u['full_name']) ?></b></td></tr>
                <tr><td>Email</td><td><?= e($u['email'] ?: '—') ?></td></tr>
                <tr><td>Mobile</td><td><?= e($u['mobile']) ?></td></tr>
                <tr><td>Date of birth</td><td><?= $u['dob'] ? dmy($u['dob']) : '—' ?></td></tr>
                <tr><td>Marital status</td><td><?= e($u['marital_status'] ?: '—') ?></td></tr>
                <tr><td>Nationality</td><td><?= e($u['nationality'] ?: '—') ?></td></tr>

                <tr><td colspan="2" style="background:#f2f6f1"><b>📍 Address</b></td></tr>
                <tr><td>Address</td><td><?= e($u['address'] ?: '—') ?></td></tr>
                <tr><td>City / State / PIN</td><td><?= e($u['city'] ?: '—') ?> · <?= e($u['state'] ?: '—') ?> · <?= e($u['pincode'] ?: '—') ?></td></tr>

                <tr><td colspan="2" style="background:#f2f6f1"><b>👥 Network position</b></td></tr>
                <tr><td>Sponsor (introducer)</td><td><?= e($u['sponsor_name'] ?: '— (company root)') ?></td></tr>
                <tr><td>Upline (placed under)</td><td><?= e($u['upline_name'] ?: '— (company root)') ?></td></tr>
                <tr><td>Leg</td><td><?= $isRoot ? '— (root)' : ($u['leg'] === 'R' ? 'RIGHT' : 'LEFT') ?></td></tr>
                <tr><td>Level (from company root)</td><td><?= (int)$u['depth'] + 1 ?></td></tr>
                <tr><td>Left team members</td><td><?= number_format($teamL) ?></td></tr>
                <tr><td>Right team members</td><td><?= number_format($teamR) ?></td></tr>
                <tr><td>Downline members</td><td><b><?= number_format($downline) ?></b></td></tr>
                <tr><td>Directly sponsored members</td><td><b><?= number_format($directs) ?></b></td></tr>

                <tr><td colspan="2" style="background:#f2f6f1"><b>📊 Business &amp; money</b></td></tr>
                <tr><td>Left BV</td><td><?= bv($u['left_bv']) ?></td></tr>
                <tr><td>Right BV</td><td><?= bv($u['right_bv']) ?></td></tr>
                <tr><td>Self purchase BV</td><td><?= bv($u['self_bv']) ?></td></tr>
                <tr><td>Matched pairs</td><td><?= number_format((int)$u['matched_pairs']) ?></td></tr>
                <tr><td>Wallet balance</td><td><b><?= money($u['wallet_balance']) ?></b></td></tr>
                <tr><td>Total earned</td><td><?= money($u['total_earned']) ?></td></tr>
                <tr><td>Total withdrawn</td><td><?= money($u['total_withdrawn']) ?></td></tr>
                <tr><td>Direct sponsor income</td><td><?= money($earn['sponsor']) ?></td></tr>
                <tr><td>Matching income</td><td><?= money($earn['binary']) ?></td></tr>
                <tr><td>50% Direct SP Match Income</td><td><?= money($earn['sponsor_matching']) ?></td></tr>
                <tr><td>Rank rewards</td><td><?= money($earn['rank']) ?></td></tr>
                <tr><td>Orders</td><td><?= number_format((int)$orderSum['n']) ?> (<?= money($orderSum['dp']) ?> DP / <?= number_format((float)$orderSum['bv']) ?> BV)</td></tr>
                <tr><td>Payout requests</td><td><?= number_format($payouts) ?></td></tr>

                <tr><td colspan="2" style="background:#f2f6f1"><b>🏦 Bank &amp; KYC</b></td></tr>
                <tr><td>Bank account</td><td><?= e($u['bank_account_no'] ? $u['bank_name'] . ' · ' . $u['bank_account_no'] . ' · ' . $u['bank_ifsc'] : '—') ?></td></tr>
                <tr><td>Account holder</td><td><?= e($u['bank_holder'] ?: '—') ?></td></tr>
                <tr><td>PAN / Aadhaar</td><td><?= e($u['pan_no'] ?: '—') ?> · <?= e($u['aadhaar_no'] ? 'XXXX-XXXX-' . substr($u['aadhaar_no'], -4) : '—') ?></td></tr>
                <tr><td>Nominee</td><td><?= e($u['nominee_name'] ?: '—') ?> (<?= e($u['nominee_relation'] ?: '—') ?>)</td></tr>

                <tr><td colspan="2" style="background:#f2f6f1"><b>⚙️ Status</b></td></tr>
                <tr><td>Member status</td><td><?= (int)$u['is_active'] ? badge('Active', 'success') : badge('Inactive', 'warning') ?> <?= status_badge($u['status']) ?></td></tr>
                <tr><td>KYC</td><td><?= status_badge($u['kyc_status']) ?></td></tr>
                <tr><td>Rank</td><td><?= e($u['rank_name'] ?: '—') ?></td></tr>
                <tr><td>Joined</td><td><?= dmy($u['created_at'], true) ?></td></tr>
                <tr><td>Activated at</td><td><?= $u['activated_at'] ? dmy($u['activated_at'], true) : '—' ?></td></tr>
                <tr><td>Last login</td><td><?= $u['last_login'] ? dmy($u['last_login'], true) : '—' ?></td></tr>
            </table>

            <?php if ($canDelete): ?>
            <form method="post" class="inline-form" data-confirm="FINAL WARNING: permanently delete <?= e($u['username']) ?> (<?= e($u['full_name']) ?>)? This cannot be undone.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
                    <button class="btn btn-danger" type="submit">🗑 Yes, delete <?= e($u['username']) ?> permanently</button>
                    <a class="btn btn-outline" href="user_view.php?id=<?= (int)$u['id'] ?>">Cancel — view member</a>
                    <a class="btn btn-light" href="users.php">Cancel — back to list</a>
                </div>
            </form>
            <?php else: ?>
            <div style="margin-top:16px">
                <a class="btn btn-outline" href="user_view.php?id=<?= (int)$u['id'] ?>">View member</a>
                <a class="btn btn-light" href="users.php">Back to distributors</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
