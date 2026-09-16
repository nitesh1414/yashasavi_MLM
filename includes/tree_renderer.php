<?php
/**
 * Binary genealogy tree renderer.
 * Renders $levels generations below $rootUser (default 5).
 * $linkBase — URL for re-rooting (e.g. 'tree.php') with ?root=<id>
 * $addBase  — URL of the PANEL registration page (e.g. 'add-member.php')
 *             used by the empty-slot "add member" links.
 *
 * Empty positions render as "add member" links that open the panel
 * registration form with the sponsor ID (the parent of the empty slot)
 * and the leg pre-selected, so a new member can be placed exactly there.
 * A zoom toolbar (fit / in / out) lets the full 5-level tree fit in a
 * single window.
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
        use ($linkBase, $addBase, $rankNames) {
        if (!$user) {
            // Slot directly below a REAL member — clickable "add member" link
            // (sponsor = that member, leg = this side; spillover fills exactly here).
            if ($parentUser && $leg) {
                $href = $addBase . '?ref=' . urlencode($parentUser['username']) . '&leg=' . $leg;
                $legName = $leg === 'L' ? 'LEFT' : 'RIGHT';
                return '<div class="t-node empty add">
                            <a href="' . e($href) . '" title="Register a new member in this position">
                                <span class="t-add-plus">➕</span>
                                <span class="t-add-text">Add Member</span>
                                <span class="t-add-leg">' . $legName . '</span>
                            </a>
                        </div>';
            }
            // Deeper position (its parent is still empty) — future spot placeholder
            return '<div class="t-node vacant" title="Future position — opens once the slot above is filled"><span class="t-v-dot">·</span></div>';
        }
        $cls = 't-node' . ($isRoot ? ' root' : '');
        $status = (int)$user['is_active'] === 1 ? '🟢' : '🔴';
        $blocked = $user['status'] === 'blocked' ? ' ⛔' : '';
        $rank = isset($rankNames[$user['rank_id']]) ? ' · ' . $rankNames[$user['rank_id']] : '';
        $link = '<a href="' . e($linkBase) . '?root=' . (int)$user['id'] . '">view ▾</a>';
        return '<div class="t-node ' . str_replace('t-node ', '', $cls) . '">
            <div class="t-id">' . e($user['username']) . '</div>
            <div class="t-name">' . e($user['full_name']) . '</div>
            <div class="t-meta">' . $status . $blocked . ' L:' . e(number_format((float)$user['left_bv'], 0)) .
            ' R:' . e(number_format((float)$user['right_bv'], 0)) . $rank . '</div>
            <div class="t-actions">' . $link . '</div>
        </div>';
    };

    $renderLevel = function ($user, $depth, $isRoot, $parentUser = null, $leg = null)
        use (&$renderLevel, $byParent, $node, $levels) {
        $html = '<li>' . $node($user, $isRoot, $parentUser, $leg);
        /* Recurse through EMPTY slots too, so the full $levels-deep structure
         * (and every addable position) is always visible. */
        if ($depth < $levels) {
            $html .= '<ul>';
            $kids = ($user && isset($byParent[$user['id']])) ? $byParent[$user['id']] : [];
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
                <span class="lg">L:/R: = leg BV</span>
                <span class="lg">Click "view" on a node to re-root the tree</span>
                <span class="lg">Click an empty position to add a new member there</span>
                <span class="lg">Dotted · = future position (opens once the slot above is filled)</span>
            </div>
            <div class="tree-toolbar">
                <span class="tt-label">Showing ' . (int)$levels . ' levels below the root — use ➕/➖ to zoom, "Fit" to fit the whole tree in the window.</span>
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
