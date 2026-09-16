<?php
/** Distributor detail view (super admin) */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/tree_renderer.php';
$a = require_superadmin();

$id = get_int('id');
$u = q_row("SELECT u.*, s.username AS sponsor_name, s.full_name AS sponsor_full, r.name AS rank_name
            FROM users u
            LEFT JOIN users s ON s.id = u.sponsor_id
            LEFT JOIN ranks r ON r.id = u.rank_id
            WHERE u.id = ?", [$id]);
if (!$u) {
    flash('error', 'User not found.');
    redirect('users.php');
}

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'kyc_verify') {
        q("UPDATE users SET kyc_status='verified', kyc_remark=NULL WHERE id=?", [$id]);
        flash('success', 'KYC marked verified.');
    } elseif ($action === 'kyc_reject') {
        q("UPDATE users SET kyc_status='rejected', kyc_remark=? WHERE id=?", [post_str('remark') ?: 'Rejected by admin', $id]);
        flash('success', 'KYC marked rejected.');
    } elseif ($action === 'activate') {
        q("UPDATE users SET is_active=1, activated_at=COALESCE(activated_at, ?) WHERE id=?", [now(), $id]);
        flash('success', 'User activated.');
    }
    redirect('user_view.php?id=' . $id);
}

$teamCounts = team_counts($u);
$earn = user_earnings_breakdown($u['id']);
$directs = q_all("SELECT id, username, full_name, is_active, self_bv, created_at FROM users WHERE sponsor_id = ? ORDER BY id DESC LIMIT 10", [$id]);
$orders = q_all("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 10", [$id]);
$payouts = q_all("SELECT * FROM payouts WHERE user_id = ? ORDER BY id DESC LIMIT 10", [$id]);
$commissions = q_all("SELECT c.*, o.order_no FROM commissions c LEFT JOIN orders o ON o.id=c.order_id WHERE c.user_id = ? ORDER BY c.id DESC LIMIT 10", [$id]);

$activeKey = 'users';
$pageTitle = 'User: ' . $u['username'];
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="stat-grid">
    <div class="stat-card"><div class="st-ico">👛</div><div><b><?= money($u['wallet_balance']) ?></b><span>Wallet Balance</span></div></div>
    <div class="stat-card gold"><div class="st-ico">💰</div><div><b><?= money($earn['total']) ?></b><span>Total Income</span></div></div>
    <div class="stat-card teal"><div class="st-ico">💠</div><div><b><?= (int)$u['matched_pairs'] ?></b><span>Matched Pairs</span></div></div>
    <div class="stat-card blue"><div class="st-ico">🏅</div><div><b><?= e($u['rank_name'] ?: '—') ?></b><span>Rank</span></div></div>
</div>

<div class="two-col">
    <div>
        <div class="card">
            <div class="card-title">
                👤 <?= e($u['username']) ?> — <?= e($u['full_name']) ?>
                <span class="right">
                    <a class="btn btn-outline btn-sm" href="user_edit.php?id=<?= (int)$u['id'] ?>">✏️ Edit</a>
                    <a class="btn btn-light btn-sm" href="tree.php?root=<?= (int)$u['id'] ?>">🌳 Tree</a>
                </span>
            </div>
            <table class="kv-table" style="width:100%">
                <tr><td>User ID / Login</td><td><b><?= e($u['username']) ?></b></td></tr>
                <tr><td>Status</td>
                    <td><?= status_badge($u['status']) ?> <?= (int)$u['is_active'] ? badge('Earning Active', 'success') : badge('Not Earning', 'warning') ?></td></tr>
                <tr><td>Email / Mobile</td><td><?= e($u['email'] ?: '—') ?> / <?= e($u['mobile']) ?></td></tr>
                <tr><td>Address</td><td><?= e(trim($u['address'] . ', ' . $u['city'] . ', ' . $u['state'] . ' - ' . $u['pincode'], ', -')) ?></td></tr>
                <tr><td>Nominee</td><td><?= e(trim($u['nominee_name'] . ' (' . $u['nominee_relation'] . ')', ' ()')) ?: '—' ?></td></tr>
                <tr><td>Sponsor</td><td><?= e($u['sponsor_name'] ? $u['sponsor_name'] . ' — ' . $u['sponsor_full'] : '— (root)') ?></td></tr>
                <tr><td>Placement / Leg</td>
                    <td><?= e($u['placement_id'] ? q_val("SELECT username FROM users WHERE id = ?", [$u['placement_id']]) : 'root') ?> · <?= $u['leg'] === 'L' ? 'LEFT' : 'RIGHT' ?></td></tr>
                <tr><td>Bank</td>
                    <td><?= e($u['bank_name'] ?: '—') ?> · <?= e(mask_acct($u['bank_account_no'])) ?> · IFSC <?= e($u['bank_ifsc'] ?: '—') ?></td></tr>
                <tr><td>PAN / Aadhaar</td><td><?= e($u['pan_no'] ?: '—') ?> / <?= e($u['aadhaar_no'] ? 'XXXX-XXXX-' . substr($u['aadhaar_no'], -4) : '—') ?></td></tr>
                <tr><td>Joined / Activated</td><td><?= dmy($u['created_at']) ?> / <?= dmy($u['activated_at']) ?></td></tr>
            </table>
        </div>

        <div class="card">
            <div class="card-title">🌳 Genealogy (below this user)</div>
            <?= render_binary_tree($u, 2, 'tree.php') ?>
        </div>

        <div class="card">
            <div class="card-title">📦 Recent Orders</div>
            <?php if (!$orders): ?><p style="color:var(--ink-soft)">No orders.</p>
            <?php else: ?>
            <div class="table-wrap" style="box-shadow:none">
                <table class="table">
                    <tr><th>Order</th><th>Date</th><th>DP</th><th>BV</th><th>Status</th><th></th></tr>
                    <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><?= e($o['order_no']) ?></td>
                        <td><?= dmy($o['created_at']) ?></td>
                        <td><?= money($o['total_dp']) ?></td>
                        <td><?= e($o['total_bv']) ?></td>
                        <td><?= status_badge($o['status']) ?></td>
                        <td><a class="btn btn-light btn-sm" href="order_view.php?id=<?= (int)$o['id'] ?>">Open</a></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-title">📊 Business Summary</div>
            <table class="kv-table" style="width:100%">
                <tr><td>Left team BV (<?= $teamCounts['L'] ?> members)</td><td><b><?= bv($u['left_bv']) ?></b></td></tr>
                <tr><td>Right team BV (<?= $teamCounts['R'] ?> members)</td><td><b><?= bv($u['right_bv']) ?></b></td></tr>
                <tr><td>Self purchase BV</td><td><b><?= bv($u['self_bv']) ?></b></td></tr>
                <tr><td>Direct referral income</td><td><?= money($earn['sponsor']) ?></td></tr>
                <tr><td>Binary matching income</td><td><?= money($earn['binary']) ?></td></tr>
                <tr><td>Level income</td><td><?= money($earn['level']) ?></td></tr>
                <tr><td>Rank rewards</td><td><?= money($earn['rank']) ?></td></tr>
                <tr><td>Total withdrawn</td><td><?= money($u['total_withdrawn']) ?></td></tr>
            </table>
        </div>

        <div class="card">
            <div class="card-title">🪪 KYC <?= status_badge($u['kyc_status']) ?></div>
            <p style="font-size:13px;color:var(--ink-soft)">
                PAN: <b><?= e($u['pan_no'] ?: '—') ?></b> · Aadhaar: <b><?= e($u['aadhaar_no'] ? 'XXXX-XXXX-' . substr($u['aadhaar_no'], -4) : '—') ?></b>
                <?php if ($u['kyc_remark']): ?><br>Remark: <?= e($u['kyc_remark']) ?><?php endif; ?>
            </p>
            <?php if ($u['kyc_status'] !== 'verified'): ?>
            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="kyc_verify">
                <button class="btn btn-primary btn-sm" type="submit">✔ Mark Verified</button>
            </form>
            <?php endif; ?>
            <?php if ($u['kyc_status'] !== 'rejected'): ?>
            <form method="post" style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="kyc_reject">
                <input class="form-control" name="remark" placeholder="Reason (optional)" style="max-width:200px">
                <button class="btn btn-danger btn-sm" type="submit">✖ Reject</button>
            </form>
            <?php endif; ?>
            <?php if (!(int)$u['is_active']): ?>
            <form method="post" style="margin-top:8px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="activate">
                <button class="btn btn-gold btn-sm" type="submit">⚡ Manually Activate (allow earning)</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-title">👥 Direct Referrals (latest 10)</div>
            <?php if (!$directs): ?><p style="color:var(--ink-soft)">No direct referrals.</p>
            <?php else: ?>
            <table class="table" style="width:100%">
                <tr><th>User</th><th>Self BV</th><th>Status</th><th>Joined</th></tr>
                <?php foreach ($directs as $d): ?>
                <tr>
                    <td><a href="user_view.php?id=<?= (int)$d['id'] ?>"><b><?= e($d['username']) ?></b></a><br>
                        <small style="color:#8d9c8d"><?= e($d['full_name']) ?></small></td>
                    <td><?= e($d['self_bv']) ?></td>
                    <td><?= (int)$d['is_active'] ? badge('Active', 'success') : badge('Inactive', 'warning') ?></td>
                    <td><?= dmy($d['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-title">🏦 Recent Payouts</div>
            <?php if (!$payouts): ?><p style="color:var(--ink-soft)">No payout requests.</p>
            <?php else: ?>
            <table class="table" style="width:100%">
                <tr><th>Request</th><th>Amount</th><th>Status</th></tr>
                <?php foreach ($payouts as $p): ?>
                <tr>
                    <td><?= e($p['request_no']) ?></td>
                    <td><?= money($p['amount']) ?></td>
                    <td><?= status_badge($p['status']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
