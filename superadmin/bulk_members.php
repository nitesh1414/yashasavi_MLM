<?php
/** Bulk generation on one or both legs with shared contact details.
 * Destructive bulk wipe is disabled in favor of per-member audited removal. */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/bulk_seeding.php';
$a = require_superadmin();
set_time_limit(0);

// A long request refreshes this again under the network lock per member.
$joinBv = (float)get_plan()['activation_bv'];
$rootUser = q_val("SELECT username FROM users WHERE (placement_id IS NULL OR placement_id = 0) AND status <> 'deleted' ORDER BY id ASC LIMIT 1");

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'wipe') {
        flash('error', 'Bulk wipe is disabled. Use the member deletion preview so tree, sponsor and financial effects are reviewed and retained.');
        redirect('bulk_members.php');
    }
    if (in_array($action, ['generate', 'lines'], true)) {
        $activate = post_str('activate') === '1';
        $selfbv = post_str('selfbv') === '1';
        if (($activate || $selfbv) && post_str('ack_income') !== '1') {
            flash('error', 'Confirm that activation or synthetic BV can create real income before generating members.');
            redirect('bulk_members.php');
        }
        $sponsorCode = strtoupper(post_str('sponsor')) ?: ($rootUser ?: '');
        $sponsor = find_user($sponsorCode);
        if (!$sponsor || $sponsor['status'] !== 'active') {
            flash('error', 'The sponsor / anchor member was not found or is not active.');
            redirect('bulk_members.php');
        }
        $mobile = post_str('mobile') ?: '9529512562';
        if (!is_mobile($mobile)) { flash('error', 'Invalid mobile number.'); redirect('bulk_members.php'); }
        $pathType = q_val("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'path'");
        if (!in_array($pathType, ['text', 'mediumtext', 'longtext'], true)) {
            flash('error', 'Run the database upgrade before creating deep member lines.');
            redirect('bulk_members.php');
        }
        $fullName = post_str('full_name') ?: 'Yashasavi Veda Herbal';
        $details = [
            'full_name' => $fullName, 'email' => post_str('email'), 'mobile' => $mobile,
            'dob' => '1990-01-01', 'marital_status' => 'Single', 'nationality' => 'Indian',
            'address' => 'Chandrapur, Maharashtra', 'city' => 'Chandrapur',
            'state' => 'Maharashtra', 'pincode' => '442001',
            'nominee_name' => $fullName, 'nominee_relation' => 'Self', 'bank_holder' => $fullName,
            'bank_account_no' => '', 'bank_ifsc' => '', 'bank_name' => '', 'bank_branch' => '',
            'aadhaar_no' => '', 'pan_no' => '',
        ];
        if ($action === 'generate') {
            $leg = post_str('leg') === 'L' ? 'L' : 'R';
            $counts = [$leg => max(1, min(2000, (int)post_str('count')))];
        } else {
            $counts = ['L' => max(0, min(2000, (int)post_str('left_count'))),
                       'R' => max(0, min(2000, (int)post_str('right_count')))];
            if (!$counts['L'] && !$counts['R']) {
                flash('error', 'Enter how many members to create in the left and/or right straight line.');
                redirect('bulk_members.php');
            }
        }

        $made = ['L' => 0, 'R' => 0]; $first = ['L' => '', 'R' => '']; $last = $first; $stopped = '';
        foreach ($counts as $leg => $count) {
            if ($stopped !== '') { break; }
            $cursorId = (int)$sponsor['id'];
            for ($i = 0; $i < $count; $i++) {
                // Each member, their initial password, optional synthetic BV,
                // activation and pending KYC are one serialized transaction.
                [$ok, $uid, $code] = bulk_create_seeded_member((int)$sponsor['id'], $cursorId, $leg,
                    $action === 'lines', $details, $selfbv, $activate);
                if (!$ok) { $stopped = ($leg === 'L' ? 'Left' : 'Right') . ' line stopped: ' . $code; break; }
                $made[$leg]++; $cursorId = $uid;
                if ($first[$leg] === '') { $first[$leg] = $code; }
                $last[$leg] = $code;
            }
        }
        if ($stopped !== '') { flash('error', $stopped . ' Earlier completed members were retained.'); }
        if ($made['L'] || $made['R']) {
            if ($action === 'lines') {
                $currentSponsor = q_row("SELECT * FROM users WHERE id = ? AND status <> 'deleted'", [$sponsor['id']]);
                $size = $currentSponsor ? team_counts($currentSponsor) : ['L' => 0, 'R' => 0];
                save_setting('line_seed', json_encode([
                    'at' => now(), 'sponsor' => $sponsor['username'], 'left' => $size['L'], 'right' => $size['R'],
                ]));
            }
            $parts = [];
            foreach (['L', 'R'] as $leg) {
                if ($made[$leg]) { $parts[] = $made[$leg] . ' on ' . ($leg === 'L' ? 'LEFT' : 'RIGHT') . ' (' . $first[$leg] . ' … ' . $last[$leg] . ')'; }
            }
            flash('success', 'Created ' . implode(', ', $parts) . '. KYC remains pending. '
                . ($selfbv ? 'Synthetic joining BV used the current plan for each member. ' : '')
                . 'Each first-time password is the member’s own User ID and must be changed at login.');
        }
        redirect('bulk_members.php');
    }
}
$activeKey = 'bulk-members';
$pageTitle = 'Bulk Members';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
$total = (int)q_val("SELECT COUNT(*) FROM users WHERE status <> 'deleted'");
?>

<div class="card" style="max-width:860px">
    <div class="card-title">👥 Bulk Members <span class="right"><?= $total ?> members in the network</span></div>

    <div class="alert alert-warning">
        <b>Generate</b> creates many members at once with shared contact details (e.g. one team/company list)
        on the chosen leg of an anchor member — one below another on the extreme side of that leg,
        exactly like normal registrations (the entered sponsor stays the introducer of every member).
        <b>Straight Lines</b> is the one-time setup tool: it places members one below another on the
        left and right of a sponsor (the member above is the sponsor).
        Generating green IDs or synthetic joining BV can credit real sponsor, matching and rank income.
        Bulk wipe is disabled; remove members individually using the audited deletion preview.
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
                <input class="form-control" type="number" name="count" value="1" min="1" max="2000" required>
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
                    🔐 Each member's first-time password is their own <b>User ID</b> (e.g. <b>YSH100002</b>)
                    — exactly like a normal registration. They must change it after first login.
                </div>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <label style="font-weight:400;display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="activate" value="1"> Manually activate earning IDs (green)
                </label>
                <label style="font-weight:400;display:flex;align-items:center;gap:8px;margin-top:6px">
                    <input type="checkbox" name="selfbv" value="1"> Add synthetic joining BV (currently <?= number_format($joinBv, 0) ?> BV per member)
                </label>
                <div class="form-hint" style="margin-top:6px">Both options are off by default. Synthetic joining BV also activates the ID; it is not an order. KYC remains pending and must be reviewed separately. The current plan is checked again for each member.</div>
            </div>
        </div>
        <label style="font-weight:600;display:flex;align-items:flex-start;gap:8px;margin:12px 0">
            <input type="checkbox" name="ack_income" value="1">
            <span>I understand that activation or synthetic BV can immediately create real sponsor, matching and rank income, and will not be automatically reversed.</span>
        </label>
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
                <input class="form-control" type="number" name="left_count" value="1" min="0" max="2000">
            </div>
            <div class="form-group">
                <label>Members in the RIGHT line</label>
                <input class="form-control" type="number" name="right_count" value="0" min="0" max="2000">
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
                    🔐 Each member's first-time password is their own <b>User ID</b> (e.g. <b>YSH100002</b>)
                    — exactly like a normal registration. They must change it after first login.
                </div>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <label style="font-weight:400;display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="activate" value="1"> Manually activate earning IDs (green)
                </label>
                <label style="font-weight:400;display:flex;align-items:center;gap:8px;margin-top:6px">
                    <input type="checkbox" name="selfbv" value="1"> Add synthetic joining BV (currently <?= number_format($joinBv, 0) ?> BV per member)
                </label>
                <div class="form-hint" style="margin-top:6px">Both options are off by default. Synthetic joining BV also activates the ID; it is not an order. KYC remains pending and must be reviewed separately. The current plan is checked again for each member.</div>
            </div>
        </div>
        <label style="font-weight:600;display:flex;align-items:flex-start;gap:8px;margin:12px 0">
            <input type="checkbox" name="ack_income" value="1">
            <span>I understand that activation or synthetic BV can immediately create real sponsor, matching and rank income, and will not be automatically reversed.</span>
        </label>
        <button class="btn btn-primary" type="submit" data-confirm="Create the LEFT and RIGHT straight lines now? One-time setup — every member is placed directly below the previous one, with the member above as sponsor.">➖ Create Straight Lines</button>
    </form>

    <hr style="margin:24px 0;border:0;border-top:1px solid var(--line)">
    <p class="form-hint"><b>Removing members:</b> bulk wipe is disabled. Open
        <a href="users.php">Distributors</a> and use Delete to preview the impact on each member's tree, sponsors and records.</p>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
