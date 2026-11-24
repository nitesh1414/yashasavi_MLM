<?php
/** Remove one member from the live network, after a full, stale-safe preview. */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/member_deletion.php';
$a = require_superadmin();
$id = (int)(get_str('id') ?: post_str('id'));
$u = q_row("SELECT * FROM users WHERE id = ? AND status <> 'deleted'", [$id]);
if (!$u) { flash('error', 'Member not found or already deleted.'); redirect('users.php'); }
$ready = income_policy_ready();

if (is_post()) {
    verify_csrf();
    $promote = post_str('promote');
    $sponsorId = (int)post_str('sponsor_id');
    $sponsorText = post_str('sponsor');
    if (post_str('confirm_username') !== $u['username']) {
        flash('error', 'Enter the exact member ID to confirm.');
        redirect('user_delete.php?id=' . $id . '&preview=1&promote=' . urlencode($promote) . '&sponsor=' . urlencode($sponsorText));
    }
    // A confirmation is valid only for the exact plan the server issued to
    // this superadmin session, not merely for client-supplied hidden fields.
    $issued = $_SESSION['member_deletion_preview'] ?? null;
    unset($_SESSION['member_deletion_preview']);
    if (!$issued || (int)$issued['id'] !== $id || $issued['promote'] !== $promote
        || (int)$issued['sponsor_id'] !== $sponsorId || time() - (int)$issued['issued_at'] > 900
        || !hash_equals($issued['nonce'], post_str('preview_token'))) {
        flash('error', 'The preview expired or its choices were changed. Review a fresh preview before confirming.');
        redirect('user_delete.php?id=' . $id . '&preview=1&promote=' . urlencode($promote) . '&sponsor=' . urlencode($sponsorText));
    }
    [$ok, $msg] = delete_member_with_plan($id, $promote, $sponsorId, $issued['hash'], (int)$a['id']);
    flash($ok ? 'success' : 'error', $msg);
    if ($ok) { redirect('users.php'); }
    redirect('user_delete.php?id=' . $id . '&preview=1&promote=' . urlencode($promote) . '&sponsor=' . urlencode($sponsorText));
}

$kids = user_children($id);
$directCount = (int)q_val("SELECT COUNT(*) FROM users WHERE sponsor_id = ? AND status <> 'deleted'", [$id]);
$promote = get_str('promote');
$sponsorText = get_str('sponsor');
$sponsor = $sponsorText !== '' ? find_user($sponsorText) : null;
$preview = null;
unset($_SESSION['member_deletion_preview']);
if ($ready && get_str('preview') === '1') {
    $preview = build_member_deletion_plan($id, $promote, $sponsor ? (int)$sponsor['id'] : 0);
    if (empty($preview['error'])) {
        $promote = $preview['promoteLeg'];
        $_SESSION['member_deletion_preview'] = [
            'id' => $id, 'promote' => $promote, 'sponsor_id' => (int)$preview['newSponsorId'],
            'hash' => $preview['hash'], 'nonce' => bin2hex(random_bytes(32)), 'issued_at' => time(),
        ];
    }
}
$label = function ($mid) use ($preview) {
    if (!$mid) { return 'Company root / none'; }
    $m = $preview['members'][(int)$mid] ?? null;
    return $m ? $m['username'] . ' — ' . $m['full_name'] : '#' . (int)$mid;
};
$position = function ($parent, $leg) use ($label) {
    return $parent ? $label($parent) . ' · ' . ($leg === 'R' ? 'Right' : 'Left') : 'Company root';
};
$activeKey = 'users';
$pageTitle = 'Delete Member — Preview';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>
<style>
.delete-preview table.table { min-width: 680px; }
.delete-preview table.table th:first-child { min-width: 160px; }
.delete-preview table.table td { overflow-wrap: break-word; }
.delete-preview table.table.delete-impact-table { min-width: 760px; table-layout: fixed; }
.delete-impact-table th { white-space: normal; }
.delete-impact-table th:first-child { width: 25%; }
</style>
<div class="card" style="max-width:1100px;border:1px solid #e7b9ad">
    <div class="card-title"><b>Remove <?= e($u['username']) ?> from the network</b>
        <span class="right"><a class="btn btn-light btn-sm" href="users.php">← Distributors</a></span>
    </div>
    <div class="alert alert-warning"><b>Review before confirming.</b> Only this member is removed from the live tree; both teams are kept.
        Login is disabled, while their orders, commissions, wallet, payouts and audit identity are retained.</div>
    <?php if (!$ready): ?>
        <div class="alert alert-danger">The database must be upgraded first. <a href="../install/upgrade.php">Run the upgrade</a> before removing members.</div>
    <?php else: ?>
    <form method="get" class="delete-options">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="preview" value="1">
        <div class="form-grid2">
            <div class="form-group">
                <label>Branch to take this member's position</label>
                <?php if ($kids['L'] && $kids['R']): ?>
                <select class="form-control" name="promote" required>
                    <option value="">Choose left or right</option>
                    <option value="L" <?= $promote === 'L' ? 'selected' : '' ?>>Left — <?= e($kids['L']['username']) ?></option>
                    <option value="R" <?= $promote === 'R' ? 'selected' : '' ?>>Right — <?= e($kids['R']['username']) ?></option>
                </select>
                <div class="form-hint">The other whole branch is attached at the first free slot on its own side under the promoted branch.</div>
                <?php elseif ($kids['L'] || $kids['R']): $one = $kids['L'] ?: $kids['R']; ?>
                <input type="hidden" name="promote" value="<?= $kids['L'] ? 'L' : 'R' ?>">
                <div class="form-hint"><b><?= e($one['username']) ?></b> is the only child and will take this position.</div>
                <?php else: ?>
                <div class="form-hint">No child branches; this position will become empty.</div>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>Replacement sponsor <?= $directCount ? '(required)' : '(not needed)' ?></label>
                <?php if ($directCount): ?>
                <input class="form-control" name="sponsor" value="<?= e($sponsorText) ?>" required placeholder="Member ID, e.g. YSH100001">
                <div class="form-hint"><?= number_format($directCount) ?> direct referral(s) will transfer. The chosen sponsor cannot be the deleted member.
                    If a direct referral is chosen, that member takes the old sponsor (or becomes a sponsor root).</div>
                <?php else: ?>
                <div class="form-hint">There are no directly sponsored members to transfer.</div>
                <?php endif; ?>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Preview exact changes</button>
    </form>
    <?php endif; ?>
</div>

<?php if ($preview && !empty($preview['error'])): ?>
    <div class="alert alert-danger" style="max-width:1100px"><?= e($preview['error']) ?></div>
<?php elseif ($preview): ?>
<div class="delete-preview" style="max-width:1100px">
    <p class="form-hint">On small screens, scroll tables horizontally to see every column.</p>
    <div class="card">
        <div class="card-title"><b>1 · Tree changes</b></div>
        <?php if ($preview['promotedId']): ?>
            <p><b><?= e($label($preview['promotedId'])) ?></b> will take <?= e($u['username']) ?>’s position
               (<?= e($position((int)$u['placement_id'], $u['leg'])) ?>).</p>
            <?php if ($preview['otherId']): ?>
            <p>The entire <?= $preview['otherLeg'] === 'R' ? 'right' : 'left' ?> branch rooted at <b><?= e($label($preview['otherId'])) ?></b>
               will move beneath <b><?= e($label($preview['destination'])) ?></b> on its <?= $preview['otherLeg'] === 'R' ? 'right' : 'left' ?> side.
               It carries <?= bv($preview['movedTeamBv']) ?>.</p>
            <?php endif; ?>
        <?php else: ?>
            <p>The position will become empty. No descendants are removed.</p>
            <?php if (!$u['placement_id']): ?><p><b>This is the last member of the tree.</b> The superadmin can create a new company root afterward.</p><?php endif; ?>
        <?php endif; ?>
        <?php if ($preview['moves']): ?>
        <div class="table-wrap" style="margin-top:12px;box-shadow:none"><table class="table">
            <thead><tr><th>Member</th><th>Current position</th><th>After removal</th><th>Depth</th><th>Old / new ancestry</th></tr></thead>
            <tbody><?php foreach ($preview['moves'] as $m): ?><tr>
                <td><?= e($label($m['user_id'])) ?></td>
                <td><?= e($position($m['old_parent'], $m['old_leg'])) ?></td>
                <td><?= e($position($m['new_parent'], $m['new_leg'])) ?></td>
                <td><?= $m['old_depth'] ?> → <?= $m['new_depth'] ?></td>
                <td><small><?= e($m['old_path']) ?><br>→ <?= e($m['new_path']) ?></small></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
        <p class="form-hint"><?= count($preview['moves']) ?> member(s) have an updated ancestry path; no descendant is deleted.</p>
        <?php endif; ?>
        <?php if ($preview['teamChanges']): ?>
        <div class="table-wrap" style="margin-top:12px;box-shadow:none"><table class="table">
            <thead><tr><th>Member</th><th>Left team: before → after</th><th>Right team: before → after</th></tr></thead><tbody>
            <?php foreach ($preview['teamChanges'] as $t): ?><tr><td><?= e($label($t['user_id'])) ?></td>
                <td><?= number_format($t['old_l']) ?> → <?= number_format($t['new_l']) ?></td>
                <td><?= number_format($t['old_r']) ?> → <?= number_format($t['new_r']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title"><b>2 · Sponsorship changes</b></div>
        <?php if (!$preview['sponsorChanges']): ?><p>No direct referrals will change sponsor.</p>
        <?php else: ?><div class="table-wrap" style="box-shadow:none"><table class="table">
            <thead><tr><th>Referral</th><th>Current sponsor</th><th>New sponsor</th></tr></thead><tbody>
            <?php foreach ($preview['sponsorChanges'] as $c): ?><tr>
                <td><?= e($label($c['user_id'])) ?></td><td><?= e($u['username']) ?></td><td><?= e($label($c['new_sponsor_id'])) ?></td>
            </tr><?php endforeach; ?></tbody></table></div>
        <p class="form-hint">The new sponsor applies prospectively. Past commissions and existing activation events are not transferred.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title"><b>3 · BV and income impact</b></div>
        <?php if (!$preview['bvChanges']): ?><p>No remaining member's displayed leg BV changes.</p>
        <?php else: ?><div class="table-wrap" style="box-shadow:none"><table class="table">
            <thead><tr><th>Member</th><th>Left BV: before → after</th><th>Right BV: before → after</th></tr></thead><tbody>
            <?php foreach ($preview['bvChanges'] as $b): ?><tr><td><?= e($label($b['user_id'])) ?></td>
                <td><?= bv($b['old_l']) ?> → <?= bv($b['new_l']) ?></td><td><?= bv($b['old_r']) ?> → <?= bv($b['new_r']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        <p class="form-hint">The deleted member's <?= bv($u['self_bv']) ?> of own BV leaves the live tree. Team BV is derived from the self BV of retained members.
            <?php if ($preview['legacyCounters']): ?><b><?= count($preview['legacyCounters']) ?> existing leg-counter inconsistency/ies will also be corrected;</b> the exact before/after values are listed above.<?php endif; ?></p>
        <p class="form-hint">Matching BV is updated for <?= count($preview['stateChanges']) ?> member(s).
            Already credited income, ranks and points are not reversed or re-paid; moving a branch does not create a new commission.</p>
        <?php if ($preview['stateChanges']): ?><div class="table-wrap" style="box-shadow:none"><table class="table delete-impact-table">
            <thead><tr><th>Member</th><th>Matching left BV: before → after</th><th>Matching right BV: before → after</th><th>Matching watermark: before → after</th><th>Points watermark: before → after</th></tr></thead><tbody>
            <?php foreach ($preview['stateChanges'] as $mid => $new): $old = $preview['states'][$mid]; ?><tr>
                <td><?= e($label($mid)) ?></td><td><?= bv($old['left_eligible_bv']) ?> → <?= bv($new['left_eligible_bv']) ?></td>
                <td><?= bv($old['right_eligible_bv']) ?> → <?= bv($new['right_eligible_bv']) ?></td>
                <td><?= bv($old['processed_bv']) ?> → <?= bv($new['processed_bv']) ?></td>
                <td><?= bv($old['points_processed_bv']) ?> → <?= bv($new['points_processed_bv']) ?></td>
            </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        <p><b><?= count($preview['bonuses']) ?></b> unpaid direct-activation bonus(es) owed to this deleted sponsor will be cancelled;
           they are not transferred to the replacement sponsor.</p>
        <?php if ($preview['bonuses']): ?><ul><?php foreach ($preview['bonuses'] as $b): ?><li><?= e($label($b['member_id'])) ?> · <?= money($b['amount']) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if ($preview['pendingFirstBonuses']): ?>
        <p><?= count($preview['pendingFirstBonuses']) ?> held first-pair sponsor bonus(es) tied to this sponsor will no longer be payable after removal; they will not transfer to the replacement sponsor.</p>
        <ul><?php foreach ($preview['pendingFirstBonuses'] as $bonus): ?><li><?= e($label($bonus['user_id'])) ?> · <?= money($bonus['amount']) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($preview['sourceBonus']): $sb = $preview['sourceBonus']; ?>
        <p class="form-hint">The <?= money($sb['amount']) ?> activation bonus associated with this member remains <?= e($sb['status']) ?> for its original sponsor <?= e($label($sb['sponsor_id'])) ?>; it is not transferred.</p>
        <?php endif; ?>
        <?php $pendingPairs = (int)($preview['states'][$id]['pending_pairs'] ?? 0); $pendingAmount = $preview['states'][$id]['pending_amount'] ?? 0; ?>
        <p class="form-hint"><?= $pendingPairs ?> unpaid matching pair(s) (<?= money($pendingAmount) ?>) and <?= (int)($preview['states'][$id]['pending_points'] ?? 0) ?> pending point(s) on the removed account cannot be paid while it is deleted.</p>
    </div>

    <div class="card">
        <div class="card-title"><b>4 · Records retained for audit</b></div>
        <p>Wallet balance: <b><?= money($u['wallet_balance']) ?></b> · Total earned: <b><?= money($u['total_earned']) ?></b>
           · Withdrawn: <b><?= money($u['total_withdrawn']) ?></b>. The member cannot log in or request new payouts after deletion.</p>
        <p><b><?= count($preview['orders']) ?></b> order(s), <b><?= count($preview['payouts']) ?></b> payout request(s), and <b><?= (int)$preview['walletHistory']['entries'] ?></b> wallet entries and all commissions are kept. Rank and points stay with the archived identity.</p>
        <?php if ($preview['orders']): ?><div class="table-wrap" style="box-shadow:none"><table class="table"><thead><tr><th>Order</th><th>Status</th><th>Payment mode / status</th><th>DP</th><th>BV</th></tr></thead><tbody>
            <?php foreach ($preview['orders'] as $o): ?><tr><td><?= e($o['order_no']) ?></td><td><?= e($o['status']) ?></td><td><?= e($o['payment_mode']) ?> / <?= e($o['payment_status']) ?></td><td><?= money($o['total_dp']) ?></td><td><?= bv($o['total_bv']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><p class="form-hint">Pending orders cannot be approved for a deleted account; they can be reviewed or rejected. Rejecting a paid wallet order refunds the retained, inaccessible archived wallet, so staff must resolve that balance separately.</p><?php endif; ?>
        <?php if ($preview['payouts']): ?><div class="table-wrap" style="box-shadow:none"><table class="table"><thead><tr><th>Payout</th><th>Status</th><th>Gross</th><th>Net</th></tr></thead><tbody>
            <?php foreach ($preview['payouts'] as $p): ?><tr><td><?= e($p['request_no']) ?></td><td><?= e($p['status']) ?></td><td><?= money($p['amount']) ?></td><td><?= money($p['net_amount']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><p class="form-hint">Existing pending payouts may still be paid or rejected by staff. A rejection refunds the archived wallet; the member cannot log in or request a new payout. New automatic payouts exclude deleted accounts.</p><?php endif; ?>
        <?php if ($preview['earnings']): ?><p class="form-hint">Commission history:</p><ul><?php foreach ($preview['earnings'] as $c): ?><li><?= e($c['type']) ?> · <?= e($c['status']) ?> · <?= (int)$c['entries'] ?> entries · <?= money($c['amount']) ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div>

    <div class="card" style="border:1px solid #d98e7f">
        <div class="card-title"><b>Confirm removal</b></div>
        <p class="form-hint">This action changes the live network immediately and cannot be undone automatically. If the tree or records change, or 15 minutes pass, this preview must be refreshed.</p>
        <form method="post" data-confirm="Remove this member from the live network and apply the previewed tree and sponsor changes?">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="promote" value="<?= e($preview['promoteLeg']) ?>">
            <input type="hidden" name="sponsor" value="<?= e($sponsorText) ?>">
            <input type="hidden" name="sponsor_id" value="<?= (int)$preview['newSponsorId'] ?>">
            <input type="hidden" name="preview_token" value="<?= e($_SESSION['member_deletion_preview']['nonce']) ?>">
            <div class="form-group"><label>Type <?= e($u['username']) ?> to confirm</label>
                <input class="form-control" name="confirm_username" required autocomplete="off" style="max-width:280px"></div>
            <button class="btn btn-danger" type="submit">Delete member and update tree</button>
            <a class="btn btn-light" href="users.php">Cancel</a>
        </form>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
