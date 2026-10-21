<?php
/** Bulk sponsor change — select any number of members and change their sponsor. */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'change') {
        $ids = array_map('intval', (array)($_POST['user_ids'] ?? []));
        $ids = array_values(array_unique(array_filter($ids)));
        $sponsorName = trim(post_str('sponsor'));
        if (!$ids) {
            flash('error', 'Select at least one member whose sponsor should be changed.');
            redirect('user_sponsor.php');
        }
        if ($sponsorName === '') {
            flash('error', 'Enter the new sponsor (member ID, e.g. YSH100002).');
            redirect('user_sponsor.php');
        }
        $sponsor = find_user($sponsorName);
        if (!$sponsor) {
            flash('error', 'New sponsor "' . $sponsorName . '" was not found.');
            redirect('user_sponsor.php');
        }
        if ($sponsor['status'] !== 'active') {
            flash('error', 'New sponsor ' . $sponsor['username'] . ' is blocked — choose an active member as sponsor.');
            redirect('user_sponsor.php');
        }

        $changed = [];
        $skipped = [];
        foreach ($ids as $id) {
            $u = q_row("SELECT * FROM users WHERE id = ?", [$id]);
            if (!$u) {
                $skipped[] = [$id, 'member not found'];
                continue;
            }
            if ((int)$u['id'] === (int)$sponsor['id']) {
                $skipped[] = [$u['username'], 'the member is the new sponsor'];
                continue;
            }
            if ((int)$u['sponsor_id'] === (int)$sponsor['id']) {
                $skipped[] = [$u['username'], 'already has this sponsor'];
                continue;
            }
            /* the new sponsor must not be in the member's downline */
            if (strpos($sponsor['path'], $u['path']) === 0) {
                $skipped[] = [$u['username'], 'the new sponsor is in this member\'s downline'];
                continue;
            }
            /* the member must not appear in the new sponsor's sponsor chain (sponsor cycle) */
            $cycle = false;
            $sid = (int)$sponsor['id'];
            for ($i = 0; $i < 100; $i++) {
                $row = $sid ? q_row("SELECT id, sponsor_id FROM users WHERE id = ?", [$sid]) : null;
                if (!$row) { break; }
                if ((int)$row['id'] === (int)$u['id']) { $cycle = true; break; }
                $sid = (int)$row['sponsor_id'];
            }
            if ($cycle) {
                $skipped[] = [$u['username'], 'this member is in the new sponsor\'s sponsor chain (cycle)'];
                continue;
            }
            q("UPDATE users SET sponsor_id = ? WHERE id = ?", [(int)$sponsor['id'], (int)$u['id']]);
            $changed[] = $u['username'];
        }

        if ($changed) {
            /* rebuild every member's income from the order history so the
             * commission goes to the NEW sponsor from the beginning */
            [$ok, $st] = recalculate_all_incomes();
            $msg = 'Sponsor changed to <b>' . e($sponsor['username']) . '</b> for ' . count($changed) . ' member(s): '
                 . e(implode(', ', $changed)) . '. '
                 . 'All incomes were recalculated from the order history (' . number_format($st['orders']) . ' orders replayed, '
                 . number_format($st['commissions']) . ' payout income entries rebuilt — ₹' . number_format($st['earned'], 2) . ').';
            if ($skipped) {
                $msg .= ' Skipped: ';
                $parts = [];
                foreach ($skipped as $sk) { $parts[] = e($sk[0]) . ' (' . e($sk[1]) . ')'; }
                $msg .= implode(', ', $parts) . '.';
            }
            flash(count($skipped) ? 'warning' : 'success', $msg);
        } else {
            $msg = 'No member was changed. ';
            $parts = [];
            foreach ($skipped as $sk) { $parts[] = e($sk[0]) . ' (' . e($sk[1]) . ')'; }
            flash('error', $msg . ($parts ? 'Skipped: ' . implode(', ', $parts) . '.' : ''));
        }
        redirect('user_sponsor.php');
    }
}

$q = get_str('q');
$where = "u.status != 'deleted'";
$params = [];
if ($q !== '') {
    $where .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR u.mobile LIKE ? OR s.username LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
$users = q_all(
    "SELECT u.id, u.username, u.full_name, u.mobile, u.status, u.is_active, u.self_bv,
            u.sponsor_id, u.placement_id, u.leg, s.username AS sponsor_name, s.full_name AS sponsor_full
     FROM users u
     LEFT JOIN users s ON s.id = u.sponsor_id
     WHERE $where
     ORDER BY u.id ASC",
    $params
);

$activeKey = 'users';
$pageTitle = 'Change Sponsor';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<form method="post" id="sponsorForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change">

    <div class="card">
        <div class="card-title">
            🔗 Change Sponsor of Members
            <span class="right"><a class="btn btn-light btn-sm" href="users.php">← All Distributors</a></span>
        </div>
        <p style="font-size:13px;color:var(--ink-soft)">
            Select <b>any number of members</b> below and enter the <b>new sponsor</b> — all selected members will be
            moved to that sponsor. After the change, <b>all incomes are recalculated</b> from the full order history,
            so the direct sponsor leg income (₹300 L/R) and the direct sponsor matching bonus (₹225) go to the
            <b>new</b> sponsor from the beginning. The <b>position in the tree does not change</b> — only the sponsor.
            Use the search box and the column filters to find members quickly.
        </p>
        <div class="filter-form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px">
            <div class="form-group" style="margin:0">
                <label>Search member (ID / name / mobile / sponsor)</label>
                <input class="form-control" type="text" name="q" value="<?= e($q) ?>" placeholder="e.g. YSH100002" style="min-width:220px">
            </div>
            <button class="btn btn-light" type="submit" name="search" value="1" onclick="this.form.method='get'">🔍 Search</button>
            <div class="form-group" style="margin:0">
                <label>New sponsor (member ID)</label>
                <input class="form-control" type="text" name="sponsor" value="<?= e(post_str('sponsor')) ?>" placeholder="e.g. YSH100005" required style="min-width:180px">
            </div>
            <button class="btn btn-primary" type="submit"
                    data-confirm="Change the sponsor of ALL selected members and recalculate every member's income from the order history?">
                🔗 Change Sponsor of Selected Members
            </button>
        </div>

        <div class="table-wrap">
            <table class="table table-dt">
                <thead>
                    <tr>
                        <th style="width:30px"><input type="checkbox" id="checkAll" title="Select all rows on this page"></th>
                        <th>Member</th><th>Name</th><th>Mobile</th><th>Current Sponsor</th><th>Position</th><th>Status</th><th>Self BV</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><input type="checkbox" class="row-check" name="user_ids[]" value="<?= (int)$u['id'] ?>"></td>
                        <td><b><?= e($u['username']) ?></b></td>
                        <td><?= e($u['full_name']) ?></td>
                        <td><?= e($u['mobile']) ?></td>
                        <td><?= $u['sponsor_name'] ? '<b>' . e($u['sponsor_name']) . '</b> · ' . e($u['sponsor_full']) : '—' ?></td>
                        <td><?= $u['placement_id'] ? 'under ' . e(q_val("SELECT username FROM users WHERE id = ?", [$u['placement_id']])) . ' (' . ($u['leg'] === 'R' ? 'Right' : 'Left') . ')' : 'root' ?></td>
                        <td><?= $u['status'] === 'active' ? badge('Active', 'success') : badge('Blocked', 'danger') ?>
                            <?= (int)$u['is_active'] ? badge('Joined', 'success') : '' ?></td>
                        <td><?= bv((float)$u['self_bv']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="form-hint" style="margin-top:10px">
            Selected members: <b id="selCount">0</b>. The header checkbox selects all rows on the current page.
        </p>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var checkAll = document.getElementById('checkAll');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.row-check').forEach(function (c) { c.checked = checkAll.checked; });
            updateCount();
        });
    }
    function updateCount() {
        var n = document.querySelectorAll('.row-check:checked').length;
        var el = document.getElementById('selCount');
        if (el) { el.textContent = n; }
    }
    document.querySelectorAll('.row-check').forEach(function (c) { c.addEventListener('change', updateCount); });

    var form = document.getElementById('sponsorForm');
    form.addEventListener('submit', function (ev) {
        if (ev.submitter && ev.submitter.name === 'search') { return; } /* search stays a GET */
        if (!form.method || form.method.toLowerCase() === 'get') { return; }
        if (!document.querySelectorAll('.row-check:checked').length) {
            ev.preventDefault();
            alert('Select at least one member first.');
        }
    });
});
</script>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
