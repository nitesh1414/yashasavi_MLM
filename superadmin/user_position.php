<?php
/** Change a member's position in the binary tree (super admin).
 *  Moves the member WITH their entire downline to the first free slot under
 *  the chosen target member + leg — the same spillover search used at
 *  registration. Sponsor, orders, wallets and paid commissions stay put;
 *  leg BV counters of the affected uplines are rebalanced automatically.
 */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

$id = get_int('id');
$u = q_row("SELECT * FROM users WHERE id = ?", [$id]);
if (!$u) {
    flash('error', 'Member not found.');
    redirect('users.php');
}

if (is_post()) {
    verify_csrf();
    $targetId = (int)post_str('target_id');
    $leg = post_str('leg') === 'R' ? 'R' : 'L';
    [$ok, $msg] = move_user_position((int)$u['id'], $targetId, $leg, (int)$a['id']);
    $ok ? flash('success', $msg) : flash('error', $msg);
    redirect('user_view.php?id=' . (int)$u['id']);
}

/* current position chain (root first, with the leg each step hangs on) */
$chain = [];
$cur = $u;
while ($cur && (int)$cur['placement_id'] > 0) {
    $p = q_row("SELECT id, username, full_name, placement_id FROM users WHERE id = ?", [(int)$cur['placement_id']]);
    if (!$p) { break; }
    $chain[] = ['p' => $p, 'leg' => $cur['leg']];
    $cur = $p;
}
$chain = array_reverse($chain);

/* the team that moves along */
$team = (int)q_val("SELECT COUNT(*) FROM users WHERE path LIKE ?", [$u['path'] . '%']);
$teamBv = (float)q_val("SELECT COALESCE(SUM(self_bv), 0) FROM users WHERE path LIKE ?", [$u['path'] . '%']);

/* allowed targets: active members, not the member, not inside their downline */
$targets = q_all("SELECT id, username, full_name, mobile, city FROM users
                  WHERE status = 'active' AND id <> ? AND path NOT LIKE ? ORDER BY username",
                  [(int)$u['id'], $u['path'] . '%']);

$activeKey = 'users';
$pageTitle = 'Change Position — ' . $u['username'];
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card" style="max-width:680px">
    <div class="card-title">
        🔁 Change Position — <?= e($u['username']) ?> (<?= e($u['full_name']) ?>)
        <span class="right">Move the member — with their whole team — under another member.</span>
    </div>

    <div class="kv-table" style="margin-bottom:14px">
        <table>
            <tr><td>Current position</td>
                <td>
                    <?php if (!$chain): ?>
                        <b>Company root</b> — cannot be moved
                    <?php else: ?>
                        <?php foreach ($chain as $i => $step): ?>
                            <?= $i > 0 ? ' → ' : '' ?><?= e($step['p']['username']) ?>
                            <b style="color:#2e7d32"><?= $step['leg'] === 'R' ? '· R' : '· L' ?></b>
                        <?php endforeach; ?>
                        → <b><?= e($u['username']) ?></b>
                    <?php endif; ?>
                </td></tr>
            <tr><td>Moves along</td>
                <td><b><?= $team ?></b> member<?= $team === 1 ? '' : 's' ?> (the whole downline)
                    <?= $teamBv > 0 ? ' carrying <b>' . number_format($teamBv, 0) . '</b> confirmed BV' : '' ?></td></tr>
            <tr><td>Sponsor (introducer)</td><td>Stays unchanged — only the position moves.</td></tr>
        </table>
    </div>

    <?php if ($chain): ?>
    <form method="post" id="move-form">
        <?= csrf_field() ?>

        <div class="form-grid2">
            <div class="form-group">
                <label>Place under member <span class="req">*</span></label>
                <div class="combo">
                    <input class="form-control combo-input" type="text" id="mp-target" placeholder="Search member — ID or name" autocomplete="off">
                    <input type="hidden" name="target_id" id="mp-target-id" value="">
                    <button type="button" class="combo-toggle" tabindex="-1" aria-label="Show all">▾</button>
                    <div class="combo-list" id="mp-target-list"></div>
                </div>
                <div class="form-hint">The member (and their team) takes the first free slot under this member + leg — same as registration.</div>
            </div>
            <div class="form-group">
                <label>Leg <span class="req">*</span></label>
                <select class="form-control" name="leg" id="mp-leg">
                    <option value="L">LEFT</option>
                    <option value="R">RIGHT</option>
                </select>
                <div class="form-hint">The spillover search walks down this leg to the first free position.</div>
            </div>
        </div>

        <button class="btn btn-primary" type="submit" id="mp-submit" disabled>🔁 Move Member</button>
        <div class="form-hint" style="margin-top:8px">
            Orders, wallet and commissions already paid are never changed — only the tree position and leg BV counters are rebalanced.
        </div>
    </form>
    <?php else: ?>
    <div class="alert alert-warning">The company root anchors the network and cannot be repositioned.</div>
    <?php endif; ?>
</div>

<style>
.combo{position:relative}
.combo-input{font-size:16px;padding-right:36px}
.combo-toggle{position:absolute;right:4px;top:50%;transform:translateY(-50%);background:none;border:0;font-size:15px;color:#68786b;cursor:pointer;padding:8px;line-height:1}
.combo-toggle:hover{color:#1b3a1f}
.combo-list{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1px solid #cfd8cf;border-radius:10px;box-shadow:0 12px 30px rgba(22,53,26,.16);max-height:262px;overflow-y:auto;z-index:60;-webkit-overflow-scrolling:touch}
.combo-list.open{display:block}
.combo-opt{padding:9px 12px;font-size:13px;cursor:pointer;line-height:1.4}
.combo-opt b{color:#000;font-weight:600;overflow-wrap:anywhere}
.combo-opt small{display:block;color:#68786b;font-size:11.5px}
.combo-opt:hover,.combo-opt.active{background:#eaf4e8}
.combo-empty{padding:10px 12px;color:#68786b;font-size:12.5px}
</style>

<script>
(function () {
    var TARGETS = <?= json_encode(array_map(function ($t) use ($u) {
        $cur = q_val("SELECT username FROM users WHERE id = ?", [(int)$u['placement_id']]);
        return [
            'id'     => (int)$t['id'],
            'label'  => $t['username'] . ' — ' . $t['full_name'],
            'sub'    => trim(($t['city'] ?? '') . ' • ' . ($t['mobile'] ?? ''), ' •'),
            'search' => $t['username'] . ' ' . $t['full_name'] . ' ' . ($t['mobile'] ?? '') . ' ' . ($t['city'] ?? ''),
            'cur'    => ((int)$t['id'] === (int)$u['placement_id']),
        ];
    }, $targets)) ?>;

    var input = document.getElementById('mp-target');
    var hidden = document.getElementById('mp-target-id');
    var list = document.getElementById('mp-target-list');
    var toggle = document.querySelector('.combo-toggle');
    var submit = document.getElementById('mp-submit');
    var open = false, activeIndex = -1, filtered = TARGETS.slice();

    function render() {
        list.innerHTML = '';
        if (!filtered.length) {
            list.innerHTML = '<div class="combo-empty">No matches found</div>';
            return;
        }
        filtered.forEach(function (it, i) {
            var d = document.createElement('div');
            d.className = 'combo-opt' + (i === activeIndex ? ' active' : '');
            var b = document.createElement('b');
            b.textContent = it.label + (it.cur ? '  (current position)' : '');
            d.appendChild(b);
            if (it.sub) {
                var sm = document.createElement('small');
                sm.textContent = it.sub;
                d.appendChild(sm);
            }
            d.addEventListener('mousedown', function (e) { e.preventDefault(); pick(it); });
            list.appendChild(d);
        });
    }
    function filter() {
        var q = input.value.trim().toLowerCase();
        filtered = !q ? TARGETS.slice() : TARGETS.filter(function (it) {
            return (it.label + ' ' + (it.search || '')).toLowerCase().indexOf(q) !== -1;
        });
        activeIndex = filtered.length ? 0 : -1;
        render();
    }
    function openList() { filter(); list.classList.add('open'); open = true; }
    function closeList() { list.classList.remove('open'); open = false; }
    function pick(it) {
        input.value = it.label;
        hidden.value = it.id;
        closeList();
        check();
    }
    function check() {
        var picked = TARGETS.filter(function (t) { return String(t.id) === String(hidden.value); })[0] || null;
        submit.disabled = !picked;
    }
    input.addEventListener('focus', openList);
    input.addEventListener('click', openList);
    input.addEventListener('input', function () { hidden.value = ''; openList(); check(); });
    input.addEventListener('keydown', function (e) {
        if (!open) { if (e.key === 'ArrowDown') { openList(); e.preventDefault(); } return; }
        if (e.key === 'ArrowDown') { activeIndex = Math.min(activeIndex + 1, filtered.length - 1); render(); e.preventDefault(); }
        else if (e.key === 'ArrowUp') { activeIndex = Math.max(activeIndex - 1, 0); render(); e.preventDefault(); }
        else if (e.key === 'Enter') { if (filtered[activeIndex]) { pick(filtered[activeIndex]); } e.preventDefault(); }
        else if (e.key === 'Escape') { closeList(); }
    });
    toggle.addEventListener('click', function () { if (open) { closeList(); } else { input.focus(); openList(); } });
    document.addEventListener('click', function (e) { if (!input.parentNode.contains(e.target)) { closeList(); } });
})();
</script>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
