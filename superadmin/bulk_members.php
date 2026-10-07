<?php
/** Bulk member management — wipe the network (keep root) and/or generate
 *  many members on one leg with shared contact details (family/team import). */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();
set_time_limit(0);

$root = q_row("SELECT * FROM users WHERE sponsor_id IS NULL OR sponsor_id = 0 ORDER BY id ASC LIMIT 1");
$rootUser = q_val("SELECT username FROM users WHERE sponsor_id IS NULL OR sponsor_id = 0 ORDER BY id ASC LIMIT 1");

if (is_post()) {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'wipe') {
        /* Delete EVERY member except the company root (and their business data) */
        if (!$root) { flash('error', 'Company root member not found.'); redirect('bulk_members.php'); }
        $keep = (int)$root['id'];
        $del = q_all("SELECT id FROM users WHERE id != ?", [$keep]);
        $ids = array_map(fn ($r) => (int)$r['id'], $del);
        try {
            db_tx(function () use ($ids, $keep) {
                if ($ids) {
                    $in = implode(',', $ids);
                    q("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE user_id IN ($in))");
                    /* commissions: owned by the deleted members OR earned from
                     * their orders — must run BEFORE the orders themselves are
                     * deleted (the subquery needs them); note: commissions has
                     * no from_user_id column, the upline context lives in `note` */
                    q("DELETE FROM commissions WHERE user_id IN ($in)
                       OR order_id IN (SELECT id FROM orders WHERE user_id IN ($in))");
                    q("DELETE FROM orders WHERE user_id IN ($in)");
                }
                /* sweep: commissions whose order no longer exists
                 * (orphans from older runs / legacy data) */
                q("DELETE FROM commissions WHERE order_id IS NOT NULL
                   AND order_id NOT IN (SELECT id FROM orders)");
                /* wallet history and payouts all belong to the wiped network */
                q("DELETE FROM wallet_transactions");
                q("DELETE FROM payouts");
                if ($ids) {
                    $in = implode(',', $ids);
                    q("DELETE FROM users WHERE id IN ($in)");
                }
                /* reset the root to a clean company account — the whole
                 * network (and every commission that fed the wallet) is gone */
                q("UPDATE users SET left_bv = 0, right_bv = 0, self_bv = 0, matched_pairs = 0,
                   wallet_balance = 0, total_earned = 0, total_withdrawn = 0 WHERE id = ?", [$keep]);
            });
            flash('success', 'Network wiped — all members except ' . e($root['username'])
                . ' were deleted. Orders, commissions, wallets and network counters were reset.');
        } catch (Throwable $e) {
            flash('error', 'The wipe failed and NOTHING was deleted: ' . e($e->getMessage()));
        }
        redirect('bulk_members.php');
    }

    if ($action === 'generate') {
        $count = max(1, min(2000, (int)post_str('count')));
        $leg = post_str('leg') === 'L' ? 'L' : 'R';
        $sponsorCode = strtoupper(post_str('sponsor')) ?: ($rootUser ?: '');
        $fullName = post_str('full_name') ?: 'Yashasavi Veda Herbal';
        $mobile = post_str('mobile') ?: '9529512562';
        $email = post_str('email');
        $password = post_str('password') ?: 'User@1234';
        $activate = post_str('activate') === '1';

        $sponsor = find_user($sponsorCode);
        if (!$sponsor) {
            flash('error', 'Sponsor / anchor member not found: ' . e($sponsorCode));
            redirect('bulk_members.php');
        }
        if (!is_mobile($mobile)) { flash('error', 'Invalid mobile number.'); redirect('bulk_members.php'); }

        /* Breadth-first placement INSIDE the sponsor's chosen leg subtree —
         * a queue of free [row, leg] slots; every new member opens two more.
         * Fast even for hundreds of members. */
        $slots = [[$sponsor, $leg]];
        $made = 0; $firstCode = ''; $lastCode = '';
        for ($i = 0; $i < $count; $i++) {
            /* next free slot (expand occupied ones as we pass them) */
            while (true) {
                if (!$slots) { break 2; }   /* no free slot left — impossible here */
                $slot = array_shift($slots);
                $ch = user_children($slot[0]['id']);
                if ($ch[$slot[1]] === null) { break; }
                /* occupied: its two child slots join the end of the queue */
                $slots[] = [$ch[$slot[1]], 'L'];
                $slots[] = [$ch[$slot[1]], 'R'];
            }
            [$placeRow, $placeLeg] = $slot;
            [$ok, $uid, $code] = register_distributor([
                'sponsor' => $sponsor['username'],
                'leg' => $placeLeg,
                'placement' => (int)$placeRow['id'],
                'placement_leg' => $placeLeg,
                'full_name' => $fullName,
                'email' => $email,
                'mobile' => $mobile,
                'dob' => '1990-01-01',
                'marital_status' => 'Single',
                'nationality' => 'Indian',
                'address' => 'Chandrapur, Maharashtra',
                'city' => 'Chandrapur',
                'state' => 'Maharashtra',
                'pincode' => '442001',
                'nominee_name' => $fullName,
                'nominee_relation' => 'Self',
                'bank_holder' => $fullName,
                'bank_account_no' => '',
                'bank_ifsc' => '',
                'bank_name' => '',
                'bank_branch' => '',
                'aadhaar_no' => '',
                'pan_no' => '',
                'password' => $password,
            ]);
            if (!$ok) {
                flash('error', 'Creation stopped after ' . $made . ' members: ' . e($code));
                break;
            }
            $made++;
            if ($firstCode === '') { $firstCode = $code; }
            $lastCode = $code;
            if ($activate) {
                q("UPDATE users SET is_active = 1, kyc_status = 'verified', activated_at = COALESCE(activated_at, NOW()) WHERE id = ?", [$uid]);
            }
            /* the new member opens two child slots in the fill order */
            $newRow = q_row("SELECT id, username, path, depth FROM users WHERE id = ?", [$uid]);
            $slots[] = [$newRow, 'L'];
            $slots[] = [$newRow, 'R'];
        }
        flash('success', $made . ' member' . ($made === 1 ? '' : 's') . ' created'
            . ($firstCode ? ' (' . e($firstCode) . ' … ' . e($lastCode) . ')' : '')
            . ($activate ? ' — all ACTIVE and KYC-verified.' : '.'));
        redirect('bulk_members.php');
    }
}

$activeKey = 'bulk-members';
$pageTitle = 'Bulk Members';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
$total = (int)q_val("SELECT COUNT(*) FROM users");
?>

<div class="card" style="max-width:860px">
    <div class="card-title">👥 Bulk Members <span class="right"><?= $total ?> members in the network</span></div>

    <div class="alert alert-warning">
        <b>Generate</b> creates many members at once with shared contact details (e.g. one team/company list)
        on the chosen leg of an anchor member — breadth-first, exactly like normal registrations.
        <b>Wipe</b> deletes <u>every member except the company root</u> together with their orders,
        commissions, wallets and payouts. Both actions are irreversible — use with care.
    </div>

    <form method="post" class="mt-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generate">
        <div class="filter-form" style="grid-template-columns:repeat(2,1fr)">
            <div class="form-group">
                <label>Anchor member (sponsor)</label>
                <input class="form-control" name="sponsor" value="<?= e($rootUser ?: '') ?>" placeholder="e.g. <?= e($rootUser ?: 'YSH100001') ?>">
            </div>
            <div class="form-group">
                <label>Leg to fill</label>
                <select class="form-control" name="leg">
                    <option value="R" selected>RIGHT</option>
                    <option value="L">LEFT</option>
                </select>
            </div>
            <div class="form-group">
                <label>How many members?</label>
                <input class="form-control" type="number" name="count" value="500" min="1" max="2000" required>
            </div>
            <div class="form-group">
                <label>Full name</label>
                <input class="form-control" name="full_name" value="Yashasavi Veda Herbal" required>
            </div>
            <div class="form-group">
                <label>Mobile (shared)</label>
                <input class="form-control" name="mobile" value="9529512562" required>
            </div>
            <div class="form-group">
                <label>Email (shared, optional)</label>
                <input class="form-control" type="email" name="email" value="yashasaviveda26@gmail.com">
            </div>
            <div class="form-group">
                <label>Password for every member</label>
                <input class="form-control" name="password" value="User@1234" required>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <label style="font-weight:400;display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="activate" value="1" checked> Active + KYC verified
                </label>
            </div>
        </div>
        <button class="btn btn-primary" type="submit" data-confirm="Create these members now?">➕ Generate Members</button>
    </form>

    <hr style="margin:24px 0;border:0;border-top:1px solid var(--line)">

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="wipe">
        <b>Danger zone</b>
        <p style="font-size:13px;color:var(--ink-soft);margin:6px 0 12px">
            Deletes every member except the company root (<?= e($rootUser ?: '—') ?>) —
            including their orders, commissions, wallet transactions and payouts — and resets the
            root's network counters. The root's own orders and wallet are kept.
        </p>
        <button class="btn btn-danger" type="submit" data-confirm="DELETE EVERY member except the company root? This cannot be undone.">🗑️ Wipe Network (keep root)</button>
    </form>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
