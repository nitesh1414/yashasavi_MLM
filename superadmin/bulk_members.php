<?php
/** Bulk member management — wipe the network (keep root) and/or generate
 *  many members on one leg with shared contact details (family/team import). */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();
set_time_limit(0);

/* joining BV (3,000 on the current plan) — used by the "Self BV" option
 * on both generator forms; guarded like the tree colour rule */
$joinBv = (float)get_plan()['activation_bv'];
if ($joinBv <= 0) { $joinBv = 3000.0; }

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

/** Each generated member, their initial password, activation and optional
 * Self BV grant commit together. Earlier successful members in a batch are
 * retained if a later member fails, and the UI reports the exact count. */
function create_bulk_member_atomic(array $data, $activate, $selfbv)
{
    return db_tx(function () use ($data, $activate, $selfbv) {
        $ready = income_rules_ready(true);
        if (($activate || $selfbv) && !$ready) { throw new RuntimeException(income_maintenance_message()); }
        if ($ready) { get_plan(true); }
        [$ok, $uid, $code] = register_distributor($data);
        if (!$ok) { throw new RuntimeException($code); }
        q("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?",
          [password_hash($code, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), $uid]);
        if ($activate) {
            q("UPDATE users SET is_active = 1, kyc_status = 'verified', activated_at = COALESCE(activated_at, NOW()) WHERE id = ?", [$uid]);
            income_settle_member($uid);
        }
        if ($selfbv) {
            $joining = (float)get_plan()['activation_bv'];
            if ($joining <= 0) { throw new RuntimeException('The joining BV must be positive.'); }
            income_apply_self_bv($uid, $joining, null, 'Bulk member Self BV grant');
        }
        return [$uid, $code];
    });
}

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    if (in_array($action, ['generate', 'lines'], true)
        && (post_str('selfbv') === '1' || post_str('activate') === '1') && !income_rules_ready()) {
        flash('error', income_maintenance_message());
        redirect('bulk_members.php');
    }

    if ($action === 'wipe') {
        if (!income_rules_ready()) { flash('error', income_maintenance_message()); redirect('bulk_members.php'); }
        /* Never erase financial or reconciliation history. Wiping is limited
         * to an entirely unused network; a live network must be preserved. */
        if (!$root) { flash('error', 'Company root member not found.'); redirect('bulk_members.php'); }
        $keep = (int)$root['id'];
        try {
            db_tx(function () use ($keep) {
                if (!income_rules_ready(true)) { throw new RuntimeException(income_maintenance_message()); }
                foreach (['orders', 'order_items', 'commissions', 'wallet_transactions', 'payouts', 'payout_statements',
                          'payout_statement_rows', 'bv_logs', 'points_logs', 'income_sponsor_events', 'income_rank_events',
                          'income_recalc_runs', 'income_recalc_entries', 'weekly_payout_runs', 'weekly_payout_reservations'] as $table) {
                    if ((int)q_val("SELECT COUNT(*) FROM $table") > 0) {
                        throw new RuntimeException('Wipe is blocked because business or reconciliation history exists. No data was deleted.');
                    }
                }
                $hasValues = (int)q_val("SELECT COUNT(*) FROM users WHERE self_bv <> 0 OR left_bv <> 0 OR right_bv <> 0
                    OR matched_pairs <> 0 OR reward_points <> 0 OR COALESCE(rank_id, 0) <> 0 OR wallet_balance <> 0
                    OR total_earned <> 0 OR total_withdrawn <> 0");
                $hasState = (int)q_val("SELECT COUNT(*) FROM income_matching_state WHERE processed_bv <> 0 OR points_processed_bv <> 0
                    OR pending_pairs <> 0 OR pending_bv <> 0 OR pending_amount <> 0 OR pending_points <> 0 OR first_match_seen <> 0
                    OR first_parent_id IS NOT NULL OR first_bonus_amount <> 0 OR first_bonus_order_id IS NOT NULL
                    OR first_bonus_commission_id IS NOT NULL OR first_bonus_status <> 'none'");
                if ($hasValues || $hasState) { throw new RuntimeException('Wipe is blocked because BV or income state exists. No data was deleted.'); }
                q("DELETE FROM income_matching_state WHERE user_id <> ?", [$keep]);
                q("DELETE FROM users WHERE id <> ?", [$keep]);
                q("DELETE FROM settings WHERE skey = 'line_seed'");
            });
            /* restart member numbering so the next member becomes id 2 /
             * YSH100002 again instead of continuing the old counter. MySQL
             * lifts the value to (highest remaining id + 1) on the next
             * insert — the company root keeps its id. Run OUTSIDE the
             * transaction: DDL statements commit implicitly. */
            $freshIds = true;
            try {
                db()->exec("ALTER TABLE users AUTO_INCREMENT = 1");
            } catch (Throwable $e) {
                $freshIds = false;   /* numbering continues — cosmetic only */
            }
            flash('success', 'Unused network cleared — all members except ' . e($root['username'])
                . ' were deleted. No business or financial history existed.'
                . ($freshIds
                    ? ' Member IDs start from the beginning again (next member: YSH'
                        . (100000 + (int)$root['id'] + 1) . ').'
                    : ''));
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
        $activate = post_str('activate') === '1';
        $selfbv = post_str('selfbv') === '1';

        $sponsor = find_user($sponsorCode);
        if (!$sponsor) {
            flash('error', 'Sponsor / anchor member not found: ' . e($sponsorCode));
            redirect('bulk_members.php');
        }
        if (!is_mobile($mobile)) { flash('error', 'Invalid mobile number.'); redirect('bulk_members.php'); }

        /* SAME-LEG placement: the first member takes the sponsor's chosen leg
         * (or continues below the deepest member of that leg) and every next
         * member goes directly below the previous one — the leg grows as one
         * straight line on the extreme LEFT / RIGHT side of the tree, never
         * in the opposite position of any level. The entered sponsor stays
         * the introducer of every created member. */
        $made = 0; $firstCode = ''; $lastCode = '';
        $anchor = $sponsor;
        while (true) {
            $ch = user_children($anchor['id']);
            if ($ch[$leg] === null) { break; }
            $anchor = $ch[$leg];
        }
        for ($i = 0; $i < $count; $i++) {
            /* the first-time password is the member's own User ID — exactly
             * like normal registration (register.php); the random placeholder
             * below is replaced immediately after the account is created */
            try {
                [$uid, $code] = create_bulk_member_atomic([
                    'sponsor' => $sponsor['username'],
                    'leg' => $leg,
                    'placement' => (int)$anchor['id'],
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
                    'password' => 'Temp-' . bin2hex(random_bytes(8)),
                ], $activate, $selfbv);
            } catch (Throwable $ex) {
                error_log('Bulk member creation failed: ' . $ex->getMessage());
                $reason = $ex instanceof PDOException ? 'Database error; the failed member was not created.' : $ex->getMessage();
                flash('error', 'Creation stopped after ' . $made . ' member(s). The failed member was not created: ' . e($reason));
                break;
            }
            $made++;
            if ($firstCode === '') { $firstCode = $code; }
            $lastCode = $code;
            /* the next member goes directly below this one */
            $anchor = q_row("SELECT * FROM users WHERE id = ?", [$uid]);
        }
        if ($made) {
            flash('success', $made . ' member' . ($made === 1 ? '' : 's') . ' created'
                . ($firstCode ? ' (' . e($firstCode) . ' … ' . e($lastCode) . ')' : '')
                . ($activate ? ' — all ACTIVE and KYC-verified' : '')
                . ($selfbv ? ' — each received Self BV at the current joining threshold' : '')
                . '. First-time password: each member\'s own User ID.');
        }
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
        $activate = post_str('activate') === '1';
        $selfbv = post_str('selfbv') === '1';

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
                try {
                    [$uid, $code] = create_bulk_member_atomic([
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
                        /* first-time password = the User ID, like normal registration;
                         * replaced immediately after the account is created */
                        'password' => 'Temp-' . bin2hex(random_bytes(8)),
                    ], $activate, $selfbv);
                } catch (Throwable $ex) {
                    error_log('Bulk line creation failed: ' . $ex->getMessage());
                    $reason = $ex instanceof PDOException ? 'Database error; the failed member was not created.' : $ex->getMessage();
                    $stopped = ($leg === 'L' ? 'Left' : 'Right') . ' line stopped after '
                        . $madeByLeg[$leg] . ' member(s). The failed member was not created: ' . $reason;
                    break;
                }
                $madeByLeg[$leg]++;
                if ($firstByLeg[$leg] === '') { $firstByLeg[$leg] = $code; }
                $lastByLeg[$leg] = $code;
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
                . '. Every member is sponsored by the member directly above them and their first-time '
                . 'password is their own User ID; new registrations below the lines follow the normal '
                . 'MLM placement logic.');
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
        on the chosen leg of an anchor member — one below another on the extreme side of that leg,
        exactly like normal registrations (the entered sponsor stays the introducer of every member).
        <b>Straight Lines</b> is the one-time setup tool: it places members one below another on the
        left and right of a sponsor (the member above is the sponsor).
        <b>Wipe</b> deletes <u>every member except the company root</u> only when the entire network has no
        business or reconciliation history. It is blocked once orders, BV, earnings, payouts or an audit exist. All actions are irreversible — use with care.
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
                    <option value="R" selected>RIGHT (extreme right)</option>
                    <option value="L">LEFT (extreme left)</option>
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
                <label>Password</label>
                <div class="form-hint" style="margin-top:8px">
                    🔐 Each member's initial password is their own <b>User ID</b> (e.g. <b>YSH100002</b>),
                    exactly like a normal registration.
                </div>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <label style="font-weight:400;display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="activate" value="1" checked> Active + KYC verified
                </label>
                <label style="font-weight:400;display:flex;align-items:center;gap:8px;margin-top:6px">
                    <input type="checkbox" name="selfbv" value="1"> Self BV <?= number_format($joinBv, 0) ?> (activates eligibility and may immediately credit income)
                </label>
                <div class="form-hint" style="margin-top:6px">
                    A Self BV grant activates eligibility, propagates team BV and can immediately credit income. No order is created; a historical preview will flag the difference from order totals.
                </div>
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
                <label>Password</label>
                <div class="form-hint" style="margin-top:8px">
                    🔐 Each member's initial password is their own <b>User ID</b> (e.g. <b>YSH100002</b>),
                    exactly like a normal registration.
                </div>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <label style="font-weight:400;display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="activate" value="1" checked> Active + KYC verified
                    <span style="font-size:12px;color:var(--ink-soft)">(manual earning activation and KYC)</span>
                </label>
                <label style="font-weight:400;display:flex;align-items:center;gap:8px;margin-top:6px">
                    <input type="checkbox" name="selfbv" value="1"> Self BV <?= number_format($joinBv, 0) ?> (activates eligibility and may immediately credit income)
                </label>
                <div class="form-hint" style="margin-top:6px">
                    Checked: the Self BV grant activates eligibility, propagates team BV and can immediately credit sponsor, matching and rank income. No order is created; it will be flagged in a historical preview.
                </div>
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
            Deletes every member except the company root (<?= e($rootUser ?: '—') ?>) only on an unused network.
            It is blocked if any order, BV, commission, wallet, payout or reconciliation history exists.
            Member IDs then start from the beginning again (next member: <?= e('YSH' . (100000 + (int)($root['id'] ?? 0) + 1)) ?>).
        </p>
        <button class="btn btn-danger" type="submit" data-confirm="DELETE EVERY member except the company root on this unused network? This cannot be undone.">🗑️ Wipe Unused Network (keep root)</button>
    </form>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
