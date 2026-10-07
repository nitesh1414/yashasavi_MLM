<?php
/** Bulk member management — wipe the network (keep root) and/or generate
 *  many members on one leg with shared contact details (family/team import). */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();
set_time_limit(0);

$root = q_row("SELECT * FROM users WHERE sponsor_id IS NULL OR sponsor_id = 0 ORDER BY id ASC LIMIT 1");
$rootUser = q_val("SELECT username FROM users WHERE sponsor_id IS NULL OR sponsor_id = 0 ORDER BY id ASC LIMIT 1");

/** users.path must be TEXT — deep straight-line seeds (250+ levels) create
 *  ancestry paths that no longer fit into the old VARCHAR(255) column. */
function ensure_users_path_text()
{
    $type = q_val("SELECT DATA_TYPE FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'path'");
    if (!in_array($type, ['text', 'mediumtext', 'longtext'], true)) {
        db()->exec("ALTER TABLE users MODIFY `path` TEXT NOT NULL");
    }
}

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
                /* the one-time straight-line seed record belongs to the wiped network */
                q("DELETE FROM settings WHERE skey = 'line_seed'");
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

    if ($action === 'lines') {
        /* ONE-TIME setup: two straight lines (left + right) below a sponsor.
         * The first member takes the sponsor's LEFT / RIGHT position, every
         * next member goes DIRECTLY below the previous one, and the member
         * above becomes their sponsor. Normal MLM placement + commissions
         * keep working below the lines afterwards. */
        $sponsorCode = strtoupper(post_str('sponsor')) ?: ($rootUser ?: '');
        $leftCount = max(0, min(2000, (int)post_str('left_count')));
        $rightCount = max(0, min(2000, (int)post_str('right_count')));
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
        if ($leftCount < 1 && $rightCount < 1) {
            flash('error', 'Enter how many members to create in the left and/or right straight line.');
            redirect('bulk_members.php');
        }
        if (!is_mobile($mobile)) { flash('error', 'Invalid mobile number.'); redirect('bulk_members.php'); }

        /* deep chains need users.path to be TEXT (see install/upgrade.php) */
        try {
            ensure_users_path_text();
        } catch (Throwable $e) {
            flash('error', 'The users.path column could not be widened for deep lines. '
                . 'Open install/upgrade.php in the browser and run the upgrade first. ('
                . e($e->getMessage()) . ')');
            redirect('bulk_members.php');
        }

        $madeByLeg = ['L' => 0, 'R' => 0];
        $firstByLeg = ['L' => '', 'R' => ''];
        $lastByLeg = ['L' => '', 'R' => ''];
        $stopped = '';
        foreach ([['L', $leftCount], ['R', $rightCount]] as [$leg, $n]) {
            if ($n < 1 || $stopped !== '') { continue; }
            /* anchor = the deepest member of the sponsor's leg — on a fresh
             * network that is the sponsor itself; on a re-run the new line
             * continues below the members created last time */
            $anchor = $sponsor;
            while (true) {
                $ch = user_children($anchor['id']);
                if ($ch[$leg] === null) { break; }
                $anchor = $ch[$leg];
            }
            for ($i = 0; $i < $n; $i++) {
                [$ok, $uid, $code] = register_distributor([
                    'sponsor' => $anchor['username'],      /* the member above is the sponsor */
                    'leg' => $leg,
                    'placement' => (int)$anchor['id'],     /* directly below the member above */
                    'placement_leg' => $leg,
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
                    $stopped = ($leg === 'L' ? 'Left' : 'Right') . ' line stopped after '
                        . $madeByLeg[$leg] . ' member' . ($madeByLeg[$leg] === 1 ? '' : 's') . ': ' . $code;
                    break;
                }
                $madeByLeg[$leg]++;
                if ($firstByLeg[$leg] === '') { $firstByLeg[$leg] = $code; }
                $lastByLeg[$leg] = $code;
                if ($activate) {
                    q("UPDATE users SET is_active = 1, kyc_status = 'verified', activated_at = COALESCE(activated_at, NOW()) WHERE id = ?", [$uid]);
                }
                $anchor = q_row("SELECT * FROM users WHERE id = ?", [$uid]);
            }
        }
        if ($stopped !== '') {
            flash('error', e($stopped));
        }
        if ($madeByLeg['L'] || $madeByLeg['R']) {
            /* record the RESULTING line sizes (a re-run continues the lines,
             * so the plain per-run numbers would be misleading) */
            $heads = user_children($sponsor['id']);
            $lineSize = ['L' => 0, 'R' => 0];
            foreach (['L', 'R'] as $lg) {
                if ($heads[$lg]) {
                    $prefix = str_replace(['%', '_'], ['\\%', '\\_'], $heads[$lg]['path']);
                    $lineSize[$lg] = (int)q_val(
                        "SELECT COUNT(*) FROM users WHERE (path LIKE ? OR id = ?)",
                        [$prefix . '%', $heads[$lg]['id']]
                    );
                }
            }
            save_setting('line_seed', json_encode([
                'at' => now(),
                'sponsor' => $sponsor['username'],
                'left' => $lineSize['L'],
                'right' => $lineSize['R'],
            ]));
            $parts = [];
            foreach (['L', 'R'] as $leg) {
                if ($madeByLeg[$leg]) {
                    $parts[] = $madeByLeg[$leg] . ' on the ' . ($leg === 'L' ? 'LEFT' : 'RIGHT')
                        . ' (' . e($firstByLeg[$leg]) . ' … ' . e($lastByLeg[$leg]) . ')';
                }
            }
            flash('success', 'Straight lines created — ' . implode(', ', $parts)
                . '. Every member is sponsored by the member directly above them; new registrations '
                . 'below the lines follow the normal MLM placement logic.');
        }
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
        <b>Straight Lines</b> is the one-time setup tool: it places members one below another on the
        left and right of a sponsor (the member above is the sponsor).
        <b>Wipe</b> deletes <u>every member except the company root</u> together with their orders,
        commissions, wallets and payouts. All actions are irreversible — use with care.
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

    <div class="card-title">➖ Straight Lines <span class="right" style="font-weight:400">one-time setup</span></div>

    <div class="alert alert-warning">
        Creates <b>two straight lines</b> below the sponsor: the first member takes the sponsor's LEFT
        (or RIGHT) position, and every next member is placed <b>directly below the previous one</b> —
        the member above automatically becomes their sponsor. Example: 250 on the left + 250 on the right.
        This is a <b>one-time setup activity</b> — after it, every new registration below the lines
        follows the normal MLM placement and commission logic.
    </div>
    <?php
    $seed = json_decode(setting('line_seed', ''), true);
    if ($seed && !empty($seed['at'])): ?>
        <div class="alert alert-warning" style="border-color:#c07800">
            ⚠️ Straight lines were already created on
            <b><?= e(date('d M Y, h:i A', strtotime($seed['at']))) ?></b> for
            <b><?= e($seed['sponsor'] ?? '') ?></b>
            (<?= (int)($seed['left'] ?? 0) ?> left / <?= (int)($seed['right'] ?? 0) ?> right).
            Running this again would <u>continue the lines deeper</u> — usually not wanted.
        </div>
    <?php endif; ?>

    <form method="post" class="mt-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="lines">
        <div class="filter-form" style="grid-template-columns:repeat(2,1fr)">
            <div class="form-group">
                <label>Sponsor (line starts below this member)</label>
                <input class="form-control" name="sponsor" value="<?= e($rootUser ?: '') ?>" placeholder="e.g. <?= e($rootUser ?: 'YSH100001') ?>">
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <span style="display:flex;align-items:center;height:38px;font-size:13px;color:var(--ink-soft)">
                    1st member → sponsor's LEFT / RIGHT position, then one below another
                </span>
            </div>
            <div class="form-group">
                <label>Members in the LEFT line</label>
                <input class="form-control" type="number" name="left_count" value="250" min="0" max="2000">
            </div>
            <div class="form-group">
                <label>Members in the RIGHT line</label>
                <input class="form-control" type="number" name="right_count" value="250" min="0" max="2000">
            </div>
            <div class="form-group">
                <label>Full name (shared)</label>
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
                    <span style="font-size:12px;color:var(--ink-soft)">(needed for matching income)</span>
                </label>
            </div>
        </div>
        <button class="btn btn-primary" type="submit" data-confirm="Create the LEFT and RIGHT straight lines now? One-time setup — every member is placed directly below the previous one, with the member above as sponsor.">➖ Create Straight Lines</button>
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
