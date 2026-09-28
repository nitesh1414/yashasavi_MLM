<?php
/**
 * Binary genealogy tree renderer.
 * Renders $levels generations below $rootUser (user panel: 5, super admin: 10).
 * $linkBase — URL for re-rooting (e.g. 'tree.php') with ?root=<id>
 * $addBase  — URL of the PANEL registration page (e.g. 'add-member.php')
 *
 * Design: minimal centered binary chart on the full page. Each member node
 * shows ONLY the member ID as a pill; hovering (desktop) or tapping (mobile)
 * the pill reveals the remaining information (name, status, leg, upline,
 * BVs, rank) plus the "add under this member" and "view subtree" actions in
 * a tooltip. Branches are drawn as straight lines between parent and child
 * pills by an SVG overlay (see dash.js drawTreeLines). Empty positions below
 * a member render as dashed "Add Member" pills that open the panel
 * registration form with sponsor + leg prefilled. A zoom toolbar (fit / in /
 * out, auto-fit on load + resize) keeps deep trees readable on any screen.
 */
function render_binary_tree($rootUser, $levels = 5, $linkBase = 'tree.php', $addBase = 'add-member.php')
{
    // preload descendants up to $levels via BFS
    $byParent = [];
    $current = [$rootUser['id']];
    $all = [$rootUser['id'] => $rootUser];
    for ($i = 0; $i < $levels; $i++) {
        if (!$current) { break; }
        $ph = implode(',', array_map('intval', $current));
        $rows = q_all("SELECT id, username, full_name, leg, placement_id, left_bv, right_bv,
                       is_active, status, rank_id, self_bv
                       FROM users WHERE placement_id IN ($ph)");
        $next = [];
        foreach ($rows as $r) {
            $byParent[$r['placement_id']][$r['leg']] = $r;
            $all[$r['id']] = $r;
            $next[] = $r['id'];
        }
        $current = $next;
    }

    $rankNames = [];
    foreach (q_all("SELECT id, name FROM ranks") as $r) {
        $rankNames[$r['id']] = $r['name'];
    }

    $node = function ($user, $isRoot, $parentUser = null, $leg = null)
        use ($linkBase, $addBase, $byParent, $rankNames) {
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

        $tip = '<div class="t-tip" role="tooltip">'
            . '<div class="t-tip-head">' . e($user['full_name']) . ($blocked ? ' ⛔' : '') . '</div>'
            . '<dl>'
            . '<dt>Status</dt><dd>' . e($statusTxt) . '</dd>'
            . '<dt>Leg</dt><dd>' . ($isRoot ? '—' : ($user['leg'] === 'R' ? 'RIGHT' : 'LEFT')) . '</dd>'
            . '<dt>Upline</dt><dd>' . e($upline) . '</dd>'
            . '<dt>Left BV</dt><dd>' . e(number_format((float)$user['left_bv'], 0)) . '</dd>'
            . '<dt>Right BV</dt><dd>' . e(number_format((float)$user['right_bv'], 0)) . '</dd>'
            . '<dt>Self BV</dt><dd>' . e(number_format((float)$user['self_bv'], 0)) . '</dd>'
            . '<dt>Rank</dt><dd>' . e($rank) . '</dd>'
            . '</dl>'
            . '<div class="t-tip-actions">'
            . '<a href="' . e($addHref) . '" title="Add a new member under ' . e($user['username']) . '">➕ Add under</a>'
            . '<a href="' . e($viewHref) . '">🔍 View subtree</a>'
            . '</div>'
            . '</div>';

        return '<div class="t-node' . ($isRoot ? ' root' : '') . '" tabindex="0">'
            . '<span class="t-pill' . ($on ? ' on' : ' off') . '">' . e($user['username']) . '</span>'
            . $tip
            . '</div>';
    };

    $renderLevel = function ($user, $depth, $isRoot, $parentUser = null, $leg = null)
        use (&$renderLevel, $byParent, $node, $levels) {
        $html = '<li>' . $node($user, $isRoot, $parentUser, $leg);
        /* Only real members have children rows: each child position is either
         * a member node or an add-member slot; nothing below empty slots. */
        if ($user && $depth < $levels) {
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
    return '<div class="tree-legends">
                <span class="lg"><span class="dot" style="background:#43a047"></span> Active member</span>
                <span class="lg"><span class="dot" style="background:#e53935"></span> Inactive member</span>
                <span class="lg"><span class="dot" style="background:#fff;box-shadow:0 0 0 2px #d6a83c inset"></span> Root</span>
                <span class="lg">Hover / tap a member ID for details &amp; actions</span>
                <span class="lg">Empty slots add a member at that exact position</span>
            </div>
            <div class="tree-toolbar">
                <div class="tt-zoom">
                    <button type="button" data-tree-zoom="out" title="Zoom out">➖</button>
                    <button type="button" data-tree-zoom="fit" title="Fit to window">⛶ Fit</button>
                    <button type="button" data-tree-zoom="in" title="Zoom in">➕</button>
                </div>
            </div>
            <div class="tree-wrap"><div class="tree" data-tree-root="1" data-tree-lines="1">'
            . '<svg class="tree-lines" aria-hidden="true"></svg><ul>' .
            preg_replace('~<li>~', '<li style="padding-top:0">', $renderLevel($root, 0, true), 1) .
            '</ul></div></div>';
}
