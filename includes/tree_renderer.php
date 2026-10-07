<?php
/**
 * Binary genealogy tree renderer.
 * Renders the COMPLETE downline of $rootUser (every member, unlimited
 * depth) inside a scrollable chart — the viewer scrolls horizontally and
 * vertically to see the whole network. $levels limits the depth when given
 * (0 = all levels, the default).
 * $linkBase — URL for re-rooting (e.g. 'tree.php') with ?root=<id>
 * $addBase  — URL of the PANEL registration page (e.g. 'add-member.php')
 *
 * Design: binary chart on the full page. Each member node shows ONLY the
 * member ID as a pill at a FIXED readable size (never shrunk to fit);
 * hovering (desktop) or tapping (mobile) the pill reveals the remaining
 * information (name, status, leg, upline, BVs, rank) plus the "add under
 * this member" and "view subtree" actions in a tooltip. Branches are drawn
 * as straight lines between parent and child pills by an SVG overlay (see
 * dash.js drawTreeLines). Empty positions below a member render as dashed
 * "Add Member" pills that open the panel registration form with sponsor +
 * leg prefilled. A zoom toolbar (100% / fit / in / out) allows an optional
 * overview; the tree always STARTS at full size with scroll bars.
 */
function render_binary_tree($rootUser, $levels = 0, $linkBase = 'tree.php', $addBase = 'add-member.php', $viewUserBase = null)
{
    /* whole downline in ONE query via the materialized ancestry path.
     * Safety cap for extreme networks: beyond it the top levels are shown
     * and the viewer drills down with "view subtree". */
    $cap = 4000;
    $rows = q_all("SELECT id, username, full_name, leg, placement_id, left_bv, right_bv,
                   is_active, status, rank_id, self_bv, created_at, sponsor_id, path
                   FROM users WHERE path LIKE ? AND id != ?
                   ORDER BY depth, id LIMIT " . ($cap + 1),
                   [$rootUser['path'] . '%', (int)$rootUser['id']]);
    $truncated = count($rows) > $cap;
    if ($truncated) {
        $rows = array_slice($rows, 0, $cap);
    }

    /* parents always have a smaller depth than their children, so one pass
     * in this order builds the placement map without recursion */
    $byParent = [];
    $all = [(int)$rootUser['id'] => $rootUser];
    foreach ($rows as $r) {
        if (!isset($all[(int)$r['placement_id']])) { continue; } /* orphan guard */
        $byParent[(int)$r['placement_id']][$r['leg']] = $r;
        $all[(int)$r['id']] = $r;
    }

    /* subtree size of every member, computed bottom-up in one pass —
     * replaces two COUNT(*) queries per rendered member */
    $size = [];
    foreach ($rows as $r) { $size[(int)$r['id']] = 1; }
    for ($i = count($rows) - 1; $i >= 0; $i--) {
        $pid = (int)$rows[$i]['placement_id'];
        if (isset($size[$pid])) {
            $size[$pid] += $size[(int)$rows[$i]['id']];
        }
    }

    $rankNames = [];
    foreach (q_all("SELECT id, name FROM ranks") as $r) {
        $rankNames[$r['id']] = $r['name'];
    }

    /* sponsors of the rendered members (for the tooltip) */
    $sponsorNames = [null => 'Company Root'];
    $spIds = [];
    foreach ($all as $m) { if (!empty($m['sponsor_id'])) { $spIds[(int)$m['sponsor_id']] = 1; } }
    if ($spIds) {
        $in = implode(',', array_map('intval', array_keys($spIds)));
        foreach (q_all("SELECT id, username, full_name FROM users WHERE id IN ($in)") as $sp) {
            $sponsorNames[(int)$sp['id']] = $sp['username'] . ' — ' . $sp['full_name'];
        }
    }

    /* members below each rendered member, per leg (from the computed sizes) */
    $legCounts = function ($user) use ($byParent, $size) {
        $out = ['L' => 0, 'R' => 0];
        $kids = isset($byParent[$user['id']]) ? $byParent[$user['id']] : [];
        foreach (['L', 'R'] as $side) {
            if (!empty($kids[$side])) {
                $out[$side] = isset($size[(int)$kids[$side]['id']]) ? (int)$size[(int)$kids[$side]['id']] : 0;
            }
        }
        return $out;
    };

    $node = function ($user, $isRoot, $parentUser = null, $leg = null)
        use ($linkBase, $addBase, $viewUserBase, $byParent, $rankNames, $sponsorNames, $legCounts) {
        if (!$user) {
            // Empty position directly below a REAL member — clickable add slot
            if ($parentUser && $leg) {
                $href = $addBase . '?ref=' . urlencode($parentUser['username']) . '&leg=' . $leg;
                $legName = $leg === 'L' ? 'LEFT' : 'RIGHT';
                return '<div class="t-node empty add">
                            <a href="' . e($href) . '" title="Add a new member in this position">
                                <span class="t-add-plus">➕</span>
                                <span class="t-add-text">Add Member</span>
                                <span class="t-add-leg">' . $legName . '</span>
                            </a>
                        </div>';
            }
            return '';
        }

        // Member node — visible: ID pill only; everything else in the hover tooltip
        $on = (int)$user['is_active'] === 1;
        $blocked = $user['status'] === 'blocked';
        $kids = isset($byParent[$user['id']]) ? $byParent[$user['id']] : [];
        // first free leg under this member (for the add action); spillover if full
        $tagLeg = !isset($kids['L']) ? 'L' : (!isset($kids['R']) ? 'R' : 'L');
        $addHref = $addBase . '?ref=' . urlencode($user['username']) . '&leg=' . $tagLeg;
        $viewHref = $linkBase . '?root=' . (int)$user['id'];
        $statusTxt = $blocked ? 'Blocked' : ($on ? 'Active' : 'Inactive');
        $upline = $parentUser ? $parentUser['username'] : 'Company Root';
        $rank = isset($rankNames[$user['rank_id']]) ? $rankNames[$user['rank_id']] : '—';

        $joined = $user['created_at'] ? date('d M Y', strtotime($user['created_at'])) : '—';
        $sponsorTxt = $sponsorNames[$user['sponsor_id'] ?? null] ?? '—';
        $cnt = $legCounts($user);

        $tip = '<div class="t-tip" role="tooltip">'
            . '<div class="t-tip-head">' . e($user['full_name']) . ($blocked ? ' ⛔' : '') . '</div>'
            . '<dl>'
            . '<dt>User ID</dt><dd><b>' . e($user['username']) . '</b></dd>'
            . '<dt>Status</dt><dd>' . e($statusTxt) . '</dd>'
            . '<dt>Joined</dt><dd>' . e($joined) . '</dd>'
            . '<dt>Sponsor</dt><dd>' . e($sponsorTxt) . '</dd>'
            . '<dt>Leg</dt><dd>' . ($isRoot ? '—' : ($user['leg'] === 'R' ? 'RIGHT' : 'LEFT')) . '</dd>'
            . '<dt>Upline</dt><dd>' . e($upline) . '</dd>'
            . '<dt>Left members</dt><dd>' . number_format($cnt['L']) . '</dd>'
            . '<dt>Right members</dt><dd>' . number_format($cnt['R']) . '</dd>'
            . '<dt>Left BV</dt><dd>' . e(number_format((float)$user['left_bv'], 0)) . '</dd>'
            . '<dt>Right BV</dt><dd>' . e(number_format((float)$user['right_bv'], 0)) . '</dd>'
            . '<dt>Self BV</dt><dd>' . e(number_format((float)$user['self_bv'], 0)) . '</dd>'
            . '<dt>Rank</dt><dd>' . e($rank) . '</dd>'
            . '</dl>'
            . '<div class="t-tip-actions">'
            . ($viewUserBase
                ? '<a href="' . e($viewUserBase . '?id=' . (int)$user['id']) . '">👤 View distributor</a>'
                : '')
            . '<a href="' . e($addHref) . '" title="Add a new member under ' . e($user['username']) . '">➕ Add under</a>'
            . '<a href="' . e($viewHref) . '">🔍 View subtree</a>'
            . '</div>'
            . '</div>';

        /* In the super admin tree the ID pill itself links to the distributor view. */
        $pillInner = e($user['username']);
        $pill = $viewUserBase
            ? '<a class="t-pill' . ($on ? ' on' : ' off') . '" href="' . e($viewUserBase . '?id=' . (int)$user['id']) . '" title="Open distributor view">' . $pillInner . '</a>'
            : '<span class="t-pill' . ($on ? ' on' : ' off') . '">' . $pillInner . '</span>';
        return '<div class="t-node' . ($isRoot ? ' root' : '') . '" tabindex="0">'
            . $pill
            . $tip
            . '</div>';
    };

    $renderLevel = function ($user, $depth, $isRoot, $parentUser = null, $leg = null)
        use (&$renderLevel, $byParent, $node, $levels) {
        $html = '<li>' . $node($user, $isRoot, $parentUser, $leg);
        /* Only real members have children rows: each child position is either
         * a member node or an add-member slot; nothing below empty slots.
         * $levels < 1 renders the whole downline (scroll to explore). */
        if ($user && ($levels < 1 || $depth < $levels)) {
            $html .= '<ul>';
            $kids = isset($byParent[$user['id']]) ? $byParent[$user['id']] : [];
            $html .= '<li>' . $renderLevel(isset($kids['L']) ? $kids['L'] : null, $depth + 1, false, $user, 'L') . '</li>';
            $html .= '<li>' . $renderLevel(isset($kids['R']) ? $kids['R'] : null, $depth + 1, false, $user, 'R') . '</li>';
            $html .= '</ul>';
        }
        $html .= '</li>';
        return $html;
    };

    $root = $all[$rootUser['id']];

    /* summary table: registrations + confirmed BV per leg of the viewed root */
    $sum = ['L' => ['n' => 0, 'bv' => (float)$root['left_bv']], 'R' => ['n' => 0, 'bv' => (float)$root['right_bv']]];
    $rootCnt = $legCounts($root);
    $sum['L']['n'] = $rootCnt['L'];
    $sum['R']['n'] = $rootCnt['R'];
    $summary = '<div class="card" style="margin-bottom:14px">'
        . '<div class="card-title">📊 Network Summary — ' . e($root['username']) . ' (' . e($root['full_name']) . ')</div>'
        . '<div class="table-wrap"><table class="table">'
        . '<thead><tr><th>Leg</th><th>Registrations</th><th>Confirmed BV</th><th>Matching Pairs</th></tr></thead>'
        . '<tbody>'
        . '<tr><td><b>LEFT</b></td><td>' . number_format($sum['L']['n']) . '</td><td>' . number_format($sum['L']['bv']) . '</td><td rowspan="2" style="text-align:center;font-size:16px"><b>' . number_format((int)$root['matched_pairs']) . '</b></td></tr>'
        . '<tr><td><b>RIGHT</b></td><td>' . number_format($sum['R']['n']) . '</td><td>' . number_format($sum['R']['bv']) . '</td></tr>'
        . '</tbody></table></div></div>';

    return $summary
        . ($truncated
            ? '<div class="alert alert-warning">This network has more than ' . number_format($cap)
                . ' members — showing the top ' . number_format($cap) . '. Use 🔍 <b>View subtree</b> '
                . 'on any member to continue deeper into that branch.</div>'
            : '')
        . '<div class="tree-legends">
                <span class="lg"><span class="dot" style="background:#43a047"></span> Active member</span>
                <span class="lg"><span class="dot" style="background:#e53935"></span> Inactive member</span>
                <span class="lg"><span class="dot" style="background:#fff;box-shadow:0 0 0 2px #d6a83c inset"></span> Root</span>
                <span class="lg">📜 Scroll in any direction to see the <b>whole</b> network — members stay full size</span>
                <span class="lg">Hover / tap a member ID for details &amp; actions</span>
                <span class="lg">Empty slots add a member at that exact position</span>
            </div>
            <div class="tree-toolbar">
                <div class="tt-zoom">
                    <button type="button" data-tree-zoom="out" title="Zoom out">➖</button>
                    <button type="button" data-tree-zoom="fit" title="Fit to window (overview)">⛶ Fit</button>
                    <button type="button" data-tree-zoom="full" title="Back to full size (100%)">🔍 100%</button>
                    <button type="button" data-tree-zoom="in" title="Zoom in">➕</button>
                </div>
            </div>
            <div class="tree-wrap"><div class="tree" data-tree-root="1" data-tree-lines="1">'
            . '<svg class="tree-lines" aria-hidden="true"></svg><ul>' .
            preg_replace('~<li>~', '<li style="padding-top:0">', $renderLevel($root, 0, true), 1) .
            '</ul></div></div>';
}
