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
 * clicking / tapping the pill reveals the remaining
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
                   is_active, status, rank_id, self_bv, created_at, sponsor_id, path, depth
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

    $node = function ($user, $isRoot, $parentUser = null, $leg = null, $level = 1)
        use ($linkBase, $addBase, $viewUserBase, $byParent, $rankNames, $sponsorNames, $legCounts) {
        if (!$user) {
            // Empty position directly below a REAL member — clickable add slot
            if ($parentUser && $leg) {
                $href = $addBase . '?ref=' . urlencode($parentUser['username']) . '&leg=' . $leg;
                $legName = $leg === 'L' ? 'LEFT' : 'RIGHT';
                return '<div class="t-node empty add">
                            <a href="' . e($href) . '" title="Add a new member in this position (' . $legName . ' leg)">
                                <span class="t-add-plus">➕</span>
                                <span class="t-add-text">' . $legName . '</span>
                            </a>
                        </div>';
            }
            return '';
        }

        // Member node — visible: ID pill only; everything else in the click tooltip
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
            . '<dt>Level</dt><dd>' . (int)$level . '</dd>'
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

        /* Compact member node: a small person icon (green = active,
         * red = inactive) with the User ID below it. The pill is NOT a
         * link: a click/tap opens the info popup (the "View distributor"
         * action lives inside the popup), so the info never appears on
         * hover or focus without an explicit click. */
        /* Font Awesome user icon — big enough to cover the complete User ID
         * placed below it; colored green (active) / red (inactive) via CSS */
        $ico = '<i class="t-ico fas fa-user" aria-hidden="true"></i>';
        $pill = '<span class="t-pill ' . ($on ? 'on' : 'off') . '" title="Click for details">'
            . $ico . '<span class="t-id">' . e($user['username']) . '</span></span>';
        return '<div class="t-node' . ($isRoot ? ' root' : '') . '">'
            . $pill
            . $tip
            . '</div>';
    };

    /* nesting guard: browsers cap HTML nesting depth (~512 levels). Chains
     * are rendered FLAT so they never add depth; only consecutive two-child
     * splits nest — beyond this guard the split member shows a drill-down
     * note instead (realistically never reached). */
    $nestGuard = 240;
    $deepNote = '<div class="deep-note">More levels below — use 🔍 View subtree on this member to continue.</div>';

    $renderLevel = function ($user, $depth, $isRoot, $parentUser = null, $leg = null, $ndepth = 1)
        use (&$renderLevel, $byParent, $node, $levels, $nestGuard, $deepNote) {
        $level = $depth + 1;   /* root = level 1 */
        $html = '<li data-level="' . $level . '" data-id="' . (int)$user['id'] . '"'
            . ($parentUser ? ' data-parent="' . (int)$parentUser['id'] . '"' : '')
            . ($isRoot ? ' style="padding-top:0"' : '') . '>'
            . $node($user, $isRoot, $parentUser, $leg, $level);
        /* Only real members have children rows: each child position is either
         * a member node or an add-member slot; nothing below empty slots.
         * $levels < 1 renders the whole downline (scroll to explore). */
        if ($user && ($levels < 1 || $depth < $levels)) {
            $kids = isset($byParent[$user['id']]) ? $byParent[$user['id']] : [];
            $lKid = $kids['L'] ?? null;
            $rKid = $kids['R'] ?? null;
            if ($lKid && $rKid) {
                /* both legs real — classic two-child row (one nesting level) */
                if ($ndepth >= $nestGuard) {
                    $html .= $deepNote;
                } else {
                    $html .= '<ul>'
                        . $renderLevel($lKid, $depth + 1, false, $user, 'L', $ndepth + 1)
                        . $renderLevel($rKid, $depth + 1, false, $user, 'R', $ndepth + 1)
                        . '</ul>';
                }
            } elseif ($lKid || $rKid) {
                /* ONE real child — straight-line leg. Rendered as a FLAT list
                 * of sibling <li>s: every member of the leg is a direct child
                 * of one <ul class="chain">, so the DOM nesting depth stays
                 * constant no matter how many levels deep the leg goes.
                 * (A nested list 250 levels deep exceeded the browser's
                 * ~512-level HTML nesting cap: the parser flattened the
                 * structure and the hidden member info leaked onto the page.)
                 * Parent links for the connector lines are carried in
                 * data-id / data-parent attributes instead of DOM nesting. */
                $emptyLeg = $lKid ? 'R' : 'L';
                /* this member's free position floats beside the line */
                $html .= '<div class="side-slot ' . $emptyLeg . '" data-parent="' . (int)$user['id'] . '">'
                    . $node(null, false, $user, $emptyLeg, $depth + 2) . '</div>';
                $html .= '<ul class="chain">';
                $p = $user;
                $cur = $lKid ?: $rKid;
                $lvl = $depth + 2;
                while (true) {
                    $ck = isset($byParent[$cur['id']]) ? $byParent[$cur['id']] : [];
                    $cl = $ck['L'] ?? null;
                    $cr = $ck['R'] ?? null;
                    $html .= '<li data-level="' . $lvl . '" data-id="' . (int)$cur['id'] . '"'
                        . ' data-parent="' . (int)$p['id'] . '">'
                        . $node($cur, false, $p, $cur['leg'], $lvl);
                    if ($cl && $cr) {
                        /* the leg splits into two real children — normal
                         * nested two-child row from here (chain ends) */
                        if ($ndepth >= $nestGuard) {
                            $html .= $deepNote;
                        } else {
                            $html .= '<ul>'
                                . $renderLevel($cl, $lvl, false, $cur, 'L', $ndepth + 1)
                                . $renderLevel($cr, $lvl, false, $cur, 'R', $ndepth + 1)
                                . '</ul>';
                        }
                        $html .= '</li>';
                        break;
                    }
                    if (!$cl && !$cr) {
                        /* leg tail — its two free positions below it */
                        $el = $lvl + 1;
                        $html .= '<ul>'
                            . '<li data-level="' . $el . '" data-parent="' . (int)$cur['id'] . '">' . $node(null, false, $cur, 'L', $el) . '</li>'
                            . '<li data-level="' . $el . '" data-parent="' . (int)$cur['id'] . '">' . $node(null, false, $cur, 'R', $el) . '</li>'
                            . '</ul></li>';
                        break;
                    }
                    /* single child continues the leg as the NEXT SIBLING li;
                     * this member's free position floats beside the line */
                    $cel = $cl ? 'R' : 'L';
                    $html .= '<div class="side-slot ' . $cel . '" data-parent="' . (int)$cur['id'] . '">'
                        . $node(null, false, $cur, $cel, $lvl + 1) . '</div>';
                    $html .= '</li>';
                    $p = $cur;
                    $cur = $cl ?: $cr;
                    $lvl++;
                }
                $html .= '</ul>';
            } else {
                /* leaf — both positions free (frontier row) */
                $el = $depth + 2;
                $html .= '<ul>'
                    . '<li data-level="' . $el . '" data-parent="' . (int)$user['id'] . '">' . $node(null, false, $user, 'L', $el) . '</li>'
                    . '<li data-level="' . $el . '" data-parent="' . (int)$user['id'] . '">' . $node(null, false, $user, 'R', $el) . '</li>'
                    . '</ul>';
            }
        }
        $html .= '</li>';
        return $html;
    };

    $root = $all[$rootUser['id']];

    /* deepest level in the fetched downline (viewed member = level 1) */
    $maxLevel = 1;
    foreach ($rows as $r) {
        $rel = (int)$r['depth'] - (int)$rootUser['depth'] + 1;
        if ($rel > $maxLevel) { $maxLevel = $rel; }
    }

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
                <span class="lg"><span class="dot" style="background:#43a047"></span> Active member (green icon)</span>
                <span class="lg"><span class="dot" style="background:#e53935"></span> Inactive member (red icon)</span>
                <span class="lg"><span class="dot" style="background:#fff;box-shadow:0 0 0 2px #d6a83c inset"></span> Root</span>
                <span class="lg">📜 First frame shows up to <b>level 7</b> — scroll for the rest; members stay full size</span>
                <span class="lg">➡️ Single-child legs render as straight vertical lines — the ➕ chip beside the line is that free position</span>
                <span class="lg">🔎 Enter a level number and press <b>Go</b> to jump to that level</span>
                <span class="lg">Click / tap a member ID for details &amp; actions — click again (or anywhere) to close</span>
            </div>
            <div class="tree-toolbar">
                <div class="tt-level">
                    <span class="tt-label">Level:</span>
                    <input type="number" min="1" max="' . (int)$maxLevel . '" value="1" data-tree-level-input
                           title="Jump to a level (1 = the viewed member)" aria-label="Tree level">
                    <button type="button" data-tree-level-go title="Scroll to this level and highlight its members">Go</button>
                    <span class="lvl-result" data-tree-level-result></span>
                </div>
                <div class="tt-zoom">
                    <button type="button" data-tree-zoom="out" title="Zoom out">➖</button>
                    <button type="button" data-tree-zoom="fit" title="Fit to window (overview)">⛶ Fit</button>
                    <button type="button" data-tree-zoom="full" title="Back to full size (100%)">🔍 100%</button>
                    <button type="button" data-tree-zoom="in" title="Zoom in">➕</button>
                </div>
            </div>
            <div class="tree-wrap"><div class="tree" data-tree-root="1" data-tree-lines="1" data-tree-max-level="' . (int)$maxLevel . '">'
            . '<svg class="tree-lines" aria-hidden="true"></svg><ul>'
            . $renderLevel($root, 0, true)
            . '</ul></div></div>';
}
