<?php
/**
 * Binary genealogy tree renderer.
 * Renders $levels generations below $rootUser (user panel: 5, super admin: 10).
 * $linkBase — URL for re-rooting (e.g. 'tree.php') with ?root=<id>
 * $addBase  — URL of the PANEL registration page (e.g. 'add-member.php')
 *             used by the "add member" links.
 *
 * Design: classic centered binary genealogy chart — the tree uses the full
 * page width, the root sits at the top center, each parent is centered above
 * its two children, and every branch is drawn with rounded elbow connectors.
 * Member cards carry an avatar bubble (initials, ring = status), the member
 * ID (click to re-root), the name and an "➕ Add" tag. Empty positions below
 * a member render as dashed "Add Member" slots. A zoom toolbar (fit / in /
 * out, auto-fit on load + resize) keeps deep trees readable and makes the
 * chart mobile friendly together with touch scrolling.
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

    $node = function ($user, $isRoot, $parentUser = null, $leg = null)
        use ($linkBase, $addBase, $byParent) {
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

        // Member node — avatar bubble (initials, ring = status), ID, name, add tag
        $on = (int)$user['is_active'] === 1;
        $blocked = $user['status'] === 'blocked' ? ' <span class="t-block" title="blocked">⛔</span>' : '';
        $parts = preg_split('/\s+/', trim($user['full_name']));
        $initials = strtoupper(substr($parts[0] ?? 'U', 0, 1) . substr($parts[1] ?? '', 0, 1));
        $kids = isset($byParent[$user['id']]) ? $byParent[$user['id']] : [];
        // first free leg under this member (for the add tag); spillover if full
        $tagLeg = !isset($kids['L']) ? 'L' : (!isset($kids['R']) ? 'R' : 'L');
        $addHref = $addBase . '?ref=' . urlencode($user['username']) . '&leg=' . $tagLeg;
        return '<div class="t-node' . ($isRoot ? ' root' : '') . '">'
            . '<span class="t-avatar' . ($on ? '' : ' off') . '" title="' . ($on ? 'Active' : 'Inactive') . '">' . e($initials) . '</span>'
            . '<div class="t-id"><a href="' . e($linkBase) . '?root=' . (int)$user['id'] . '" title="Re-root the tree at this member">' . e($user['username']) . '</a>' . $blocked . '</div>'
            . '<div class="t-name">' . e($user['full_name']) . '</div>'
            . '<a class="t-add-tag" href="' . e($addHref) . '" title="Add a new member under ' . e($user['username']) . '">➕ Add</a>'
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
                <span class="lg"><span class="dot" style="background:#2e7d32"></span> Active</span>
                <span class="lg"><span class="dot" style="background:#c62828"></span> Inactive</span>
                <span class="lg">Click a member ID to re-root the tree</span>
                <span class="lg">➕ Add on a member adds under them; empty slots add exactly there</span>
            </div>
            <div class="tree-toolbar">
                <div class="tt-zoom">
                    <button type="button" data-tree-zoom="out" title="Zoom out">➖</button>
                    <button type="button" data-tree-zoom="fit" title="Fit to window">⛶ Fit</button>
                    <button type="button" data-tree-zoom="in" title="Zoom in">➕</button>
                </div>
            </div>
            <div class="tree-wrap"><div class="tree" data-tree-root="1"><ul>' .
            preg_replace('~<li>~', '<li style="padding-top:0">', $renderLevel($root, 0, true), 1) .
            '</ul></div></div>';
}
