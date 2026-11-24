<?php
/** Superadmin-only member removal: preview and apply the exact same plan.
 * The account is archived, not erased, to retain orders and financial audit. */

/** Validate a live tree and calculate each subtree's member count and BV.
 * The traversal is iterative so deep straight-line networks remain safe. */
function deletion_tree_metrics(array $members)
{
    if (!$members) { return ['children' => [], 'counts' => [], 'bv' => [], 'totals' => []]; }
    $children = []; $roots = [];
    foreach ($members as $id => $m) {
        if ((float)$m['self_bv'] < 0) { return ['error' => 'A member has negative self BV. Repair the network before deleting.']; }
        $parent = (int)$m['placement_id'];
        if (!$parent) { $roots[] = (int)$id; continue; }
        if (!isset($members[$parent])) { return ['error' => 'A placement parent is missing. Repair the network before deleting.']; }
        $side = $m['leg'] === 'R' ? 'R' : 'L';
        if (isset($children[$parent][$side])) { return ['error' => 'The tree has duplicate positions. Repair the network before deleting.']; }
        $children[$parent][$side] = (int)$id;
    }
    if (count($roots) !== 1) { return ['error' => 'The live placement tree must have exactly one root. Repair the network before deleting.']; }
    $queue = [[$roots[0], '/', 0]]; $order = []; $seen = [];
    for ($i = 0; $i < count($queue); $i++) {
        [$id, $prefix, $depth] = $queue[$i];
        if (isset($seen[$id])) { return ['error' => 'A placement cycle was found. Repair the network before deleting.']; }
        $seen[$id] = true;
        $path = $prefix . $id . '/';
        if (strlen($path) > 65535) { return ['error' => 'A proposed ancestry path exceeds the database limit.']; }
        if ($members[$id]['path'] !== $path || (int)$members[$id]['depth'] !== $depth) {
            return ['error' => 'Placement ancestry or depth is inconsistent. Repair the network before deleting.'];
        }
        $order[] = $id;
        foreach (['L', 'R'] as $side) {
            if (isset($children[$id][$side])) { $queue[] = [$children[$id][$side], $path, $depth + 1]; }
        }
    }
    if (count($seen) !== count($members)) { return ['error' => 'The placement tree contains a detached branch or cycle. Repair it before deleting.']; }
    $counts = []; $bv = []; $totals = [];
    foreach (array_reverse($order) as $id) {
        $counts[$id] = ['L' => 0, 'R' => 0]; $bv[$id] = ['L' => 0.0, 'R' => 0.0];
        foreach (['L', 'R'] as $side) {
            $child = $children[$id][$side] ?? 0;
            if ($child) {
                $counts[$id][$side] = 1 + $counts[$child]['L'] + $counts[$child]['R'];
                $bv[$id][$side] = $totals[$child];
            }
        }
        $totals[$id] = round((float)$members[$id]['self_bv'] + $bv[$id]['L'] + $bv[$id]['R'], 2);
    }
    return compact('children', 'counts', 'bv', 'totals');
}

/** Preview is a pure calculation. On POST the same data is read FOR UPDATE
 * and hashed again; stale previews cannot be confirmed. */
function build_member_deletion_plan($userId, $promoteLeg, $newSponsorId, $lock = false)
{
    $lockSql = $lock ? ' FOR UPDATE' : '';
    $rows = q_all("SELECT id, username, full_name, sponsor_id, placement_id, leg, path, depth,
                          self_bv, left_bv, right_bv, matched_pairs, reward_points, is_active,
                          status, wallet_balance, total_earned, total_withdrawn
                   FROM users WHERE status <> 'deleted' ORDER BY id" . $lockSql);
    $members = [];
    foreach ($rows as $r) { $members[(int)$r['id']] = $r; }
    $id = (int)$userId;
    if (!isset($members[$id])) { return ['error' => 'Member not found or already deleted.']; }
    $target = $members[$id];
    $stateRows = q_all("SELECT * FROM matching_state ORDER BY user_id" . $lockSql);
    $states = []; $pendingFirstBonuses = [];
    foreach ($stateRows as $s) {
        $states[(int)$s['user_id']] = $s;
        if ((int)$s['pending_first_sponsor_id'] === $id && (int)$s['pending_pairs'] > 0
            && (float)$s['pending_first_bonus'] > 0 && isset($members[(int)$s['user_id']])) {
            $pendingFirstBonuses[] = ['user_id' => (int)$s['user_id'], 'amount' => (float)$s['pending_first_bonus']];
        }
    }
    $bonuses = q_all("SELECT member_id, sponsor_id, amount, status FROM sponsor_activation_bonuses
                      WHERE sponsor_id = ? AND status = 'pending' ORDER BY member_id" . $lockSql, [$id]);
    $sourceBonus = q_row("SELECT member_id, sponsor_id, amount, status FROM sponsor_activation_bonuses WHERE member_id = ?" . $lockSql, [$id]);
    $orders = q_all("SELECT id, order_no, status, payment_mode, payment_status, total_dp, total_bv FROM orders WHERE user_id = ? ORDER BY id" . $lockSql, [$id]);
    $payouts = q_all("SELECT id, request_no, status, amount, net_amount FROM payouts WHERE user_id = ? ORDER BY id" . $lockSql, [$id]);
    $earnings = q_all("SELECT type, status, COUNT(*) AS entries, COALESCE(SUM(amount),0) AS amount
                       FROM commissions WHERE user_id = ? GROUP BY type, status ORDER BY type, status", [$id]);
    $walletHistory = q_row("SELECT COUNT(*) AS entries, COALESCE(MAX(id), 0) AS last_id FROM wallet_transactions WHERE user_id = ?", [$id]);
    $planSettings = q_row("SELECT pair_unit_bv FROM plan_settings WHERE id = 1" . $lockSql);
    if (!$planSettings) { return ['error' => 'MLM plan settings are missing.']; }

    $beforeTree = deletion_tree_metrics($members);
    if (!empty($beforeTree['error'])) { return $beforeTree; }
    $children = $beforeTree['children'];
    $kids = $children[$id] ?? [];
    $directs = [];
    foreach ($members as $m) { if ((int)$m['sponsor_id'] === $id) { $directs[] = $m; } }
    $newSponsorId = (int)$newSponsorId;
    if ($directs && (!isset($members[$newSponsorId]) || $newSponsorId === $id || $members[$newSponsorId]['status'] !== 'active')) {
        return ['error' => 'Choose an active replacement sponsor for the affected direct referrals.'];
    }
    if (count($kids) === 2 && !in_array($promoteLeg, ['L', 'R'], true)) {
        return ['error' => 'Choose which branch should take the deleted member’s position.'];
    }
    if (count($kids) === 1) { $promoteLeg = (string)array_key_first($kids); }
    if (!$kids) { $promoteLeg = ''; }
    $hash = hash('sha256', json_encode([$id, $promoteLeg, $newSponsorId, $rows, $stateRows, $bonuses, $sourceBonus, $orders, $payouts, $earnings, $walletHistory,
        $planSettings['pair_unit_bv']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $promotedId = $promoteLeg !== '' ? ($kids[$promoteLeg] ?? 0) : 0;
    if ($kids && !$promotedId) { return ['error' => 'The selected branch no longer exists.']; }
    $otherLeg = $promoteLeg === 'L' ? 'R' : 'L';
    $otherId = $kids[$otherLeg] ?? 0;
    $destination = 0;
    if ($otherId) {
        $destination = $promotedId;
        $seen = [];
        while (!empty($children[$destination][$otherLeg])) {
            if (isset($seen[$destination])) { return ['error' => 'A placement cycle was found in the tree.']; }
            $seen[$destination] = true;
            $destination = $children[$destination][$otherLeg];
        }
    }

    // Reassign direct referrals. When the chosen sponsor is themselves a
    // direct referral, that one takes the deleted member's old sponsor (or
    // becomes a sponsor root); every other referral goes to the chosen sponsor.
    $after = $members;
    $sponsorChanges = [];
    foreach ($directs as $d) {
        $did = (int)$d['id'];
        $replacement = $newSponsorId;
        if ($did === $newSponsorId) {
            $oldSponsor = (int)$target['sponsor_id'];
            $replacement = isset($members[$oldSponsor]) && $oldSponsor !== $did && $oldSponsor !== $id ? $oldSponsor : 0;
        }
        $after[$did]['sponsor_id'] = $replacement ?: null;
        $sponsorChanges[] = ['user_id' => $did, 'new_sponsor_id' => $replacement];
    }
    // Check the proposed sponsor graph, not just the placement tree.
    foreach ($sponsorChanges as $change) {
        $visited = []; $cursor = $change['user_id'];
        while ($cursor && isset($after[$cursor]) && $cursor !== $id) {
            if (isset($visited[$cursor])) { return ['error' => 'That replacement sponsor would create a sponsor cycle. Choose a different member.']; }
            $visited[$cursor] = true;
            $cursor = (int)$after[$cursor]['sponsor_id'];
        }
    }

    if ($promotedId) {
        $after[$promotedId]['placement_id'] = $target['placement_id'];
        $after[$promotedId]['leg'] = $target['placement_id'] ? $target['leg'] : 'L';
        if ($otherId) {
            $after[$otherId]['placement_id'] = $destination;
            $after[$otherId]['leg'] = $otherLeg;
        }
        // Rebuild the moved branches' ancestry and depth without flattening them.
        $newChildren = [];
        foreach ($after as $mid => $m) {
            if ($mid === $id) { continue; }
            $parent = (int)$m['placement_id'];
            if ($parent) { $newChildren[$parent][] = $mid; }
        }
        $parentId = (int)$target['placement_id'];
        if ($parentId && !isset($after[$parentId])) { return ['error' => 'The current placement parent is missing.']; }
        $basePath = $parentId ? $after[$parentId]['path'] : '/';
        $baseDepth = $parentId ? (int)$after[$parentId]['depth'] + 1 : 0;
        $queue = [[$promotedId, $basePath, $baseDepth]]; $seen = [];
        for ($i = 0; $i < count($queue); $i++) {
            [$mid, $prefix, $depth] = $queue[$i];
            if (isset($seen[$mid])) { return ['error' => 'A placement cycle was found in the proposed tree.']; }
            $seen[$mid] = true;
            $path = $prefix . $mid . '/';
            if (strlen($path) > 65535) { return ['error' => 'A proposed ancestry path is too long.']; }
            $after[$mid]['path'] = $path;
            $after[$mid]['depth'] = $depth;
            foreach ($newChildren[$mid] ?? [] as $kid) { $queue[] = [$kid, $path, $depth + 1]; }
        }
    }

    // Derive live team BV from the retained tree, including non-green
    // members. This also safely repairs any legacy counters that were never
    // propagated by the old bulk seeder; all differences are previewed.
    $movedTeamBv = $otherId ? $beforeTree['totals'][$otherId] : 0.0;
    unset($after[$id]);
    $afterTree = deletion_tree_metrics($after);
    if (!empty($afterTree['error'])) { return $afterTree; }
    $legacyCounters = [];
    foreach ($after as $mid => &$m) {
        if (abs((float)$m['left_bv'] - $beforeTree['bv'][$mid]['L']) > .001
            || abs((float)$m['right_bv'] - $beforeTree['bv'][$mid]['R']) > .001) {
            $legacyCounters[] = $mid;
        }
        $m['left_bv'] = $afterTree['bv'][$mid]['L'];
        $m['right_bv'] = $afterTree['bv'][$mid]['R'];
    }
    unset($m);
    $unit = max(1, (float)$planSettings['pair_unit_bv']);
    $stateChanges = []; $bvChanges = []; $teamChanges = []; $moves = [];
    foreach ($after as $mid => $m) {
        $old = $members[$mid];
        if ($old['path'] !== $m['path'] || (int)$old['placement_id'] !== (int)$m['placement_id'] || $old['leg'] !== $m['leg']) {
            $moves[] = ['user_id' => $mid, 'old_parent' => (int)$old['placement_id'], 'old_leg' => $old['leg'], 'old_depth' => (int)$old['depth'],
                'new_parent' => (int)$m['placement_id'], 'new_leg' => $m['leg'], 'new_depth' => (int)$m['depth'],
                'old_path' => $old['path'], 'new_path' => $m['path']];
        }
        $s = $states[$mid] ?? null;
        if (!$s) { return ['error' => 'Income baseline is missing for a member. Run the database upgrade before deleting.']; }
        $newL = (float)$m['left_bv']; $newR = (float)$m['right_bv'];
        $newProcessed = (float)$s['processed_bv'];
        if (min($newL, $newR) > min((float)$s['left_eligible_bv'], (float)$s['right_eligible_bv']) + .001) {
            $newProcessed = max($newProcessed, floor(min($newL, $newR) / $unit) * $unit);
        }
        $newPointsProcessed = (float)$s['points_processed_bv'];
        if (min((float)$m['left_bv'], (float)$m['right_bv']) > min((float)$old['left_bv'], (float)$old['right_bv']) + .001) {
            $newPointsProcessed = max($newPointsProcessed, floor(min((float)$m['left_bv'], (float)$m['right_bv']) / $unit) * $unit);
        }
        if (abs((float)$s['left_eligible_bv'] - $newL) > .001 || abs((float)$s['right_eligible_bv'] - $newR) > .001
            || abs((float)$s['processed_bv'] - $newProcessed) > .001 || abs((float)$s['points_processed_bv'] - $newPointsProcessed) > .001) {
            $stateChanges[$mid] = ['left_eligible_bv' => $newL, 'right_eligible_bv' => $newR,
                'processed_bv' => $newProcessed, 'points_processed_bv' => $newPointsProcessed];
        }
        $oldCounts = $beforeTree['counts'][$mid]; $newCounts = $afterTree['counts'][$mid];
        if ($oldCounts !== $newCounts) {
            $teamChanges[] = ['user_id' => $mid, 'old_l' => $oldCounts['L'], 'new_l' => $newCounts['L'],
                'old_r' => $oldCounts['R'], 'new_r' => $newCounts['R']];
        }
        if (abs((float)$old['left_bv'] - (float)$m['left_bv']) > .001 || abs((float)$old['right_bv'] - (float)$m['right_bv']) > .001) {
            $bvChanges[] = ['user_id' => $mid, 'old_l' => (float)$old['left_bv'], 'new_l' => (float)$m['left_bv'],
                'old_r' => (float)$old['right_bv'], 'new_r' => (float)$m['right_bv']];
        }
    }
    return compact('hash', 'target', 'members', 'after', 'kids', 'promoteLeg', 'promotedId', 'otherId', 'otherLeg', 'destination',
        'directs', 'newSponsorId', 'sponsorChanges', 'moves', 'bvChanges', 'teamChanges', 'legacyCounters', 'stateChanges', 'states', 'bonuses', 'pendingFirstBonuses', 'sourceBonus', 'orders', 'payouts', 'earnings', 'walletHistory', 'movedTeamBv');
}

function delete_member_with_plan($userId, $promoteLeg, $newSponsorId, $expectedHash, $adminId)
{
    if (!income_policy_ready()) { return [false, 'Run the database upgrade before deleting members.']; }
    return db_tx(function () use ($userId, $promoteLeg, $newSponsorId, $expectedHash, $adminId) {
        lock_network_mutation();
        $p = build_member_deletion_plan($userId, $promoteLeg, $newSponsorId, true);
        if (!empty($p['error'])) { return [false, $p['error']]; }
        if (!$expectedHash || !hash_equals($p['hash'], $expectedHash)) {
            return [false, 'The network or financial records changed since the preview. Review a fresh preview before confirming.'];
        }
        foreach ($p['after'] as $id => $u) {
            $old = $p['members'][$id];
            if ($u['path'] !== $old['path'] || (int)$u['placement_id'] !== (int)$old['placement_id'] || $u['leg'] !== $old['leg']
                || (int)$u['sponsor_id'] !== (int)$old['sponsor_id'] || (float)$u['left_bv'] !== (float)$old['left_bv'] || (float)$u['right_bv'] !== (float)$old['right_bv']) {
                q("UPDATE users SET placement_id = ?, leg = ?, path = ?, depth = ?, sponsor_id = ?, left_bv = ?, right_bv = ? WHERE id = ?",
                  [$u['placement_id'], $u['leg'], $u['path'], $u['depth'], $u['sponsor_id'], $u['left_bv'], $u['right_bv'], $id]);
            }
        }
        foreach ($p['stateChanges'] as $id => $s) {
            q("UPDATE matching_state SET left_eligible_bv = ?, right_eligible_bv = ?, processed_bv = ?, points_processed_bv = ? WHERE user_id = ?",
              [$s['left_eligible_bv'], $s['right_eligible_bv'], $s['processed_bv'], $s['points_processed_bv'], $id]);
        }
        q("UPDATE sponsor_activation_bonuses SET status = 'cancelled' WHERE sponsor_id = ? AND status = 'pending'", [(int)$userId]);
        q("UPDATE users SET status = 'deleted', is_active = 0, sponsor_id = NULL, placement_id = NULL, path = ?, depth = 0 WHERE id = ?",
          ['/deleted/' . (int)$userId . '/', (int)$userId]);
        $audit = ['username' => $p['target']['username'], 'promoted_id' => $p['promotedId'], 'grafted_id' => $p['otherId'],
            'graft_parent' => $p['destination'], 'sponsor_changes' => $p['sponsorChanges'], 'moves' => $p['moves'],
            'bv_changes' => $p['bvChanges'], 'team_changes' => $p['teamChanges'], 'legacy_counter_repairs' => $p['legacyCounters'], 'matching_state_changes' => $p['stateChanges'], 'cancelled_pending_bonuses' => $p['bonuses'], 'unpayable_pending_first_bonuses' => $p['pendingFirstBonuses'], 'source_bonus_preserved' => $p['sourceBonus'],
            'wallet_balance_preserved' => $p['target']['wallet_balance'], 'orders_preserved' => count($p['orders']), 'payouts_preserved' => count($p['payouts'])];
        q("INSERT INTO member_deletion_audit (user_id, admin_id, changes_json, created_at) VALUES (?, ?, ?, ?)",
          [(int)$userId, (int)$adminId, json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now()]);
        return [true, $p['target']['username'] . ' was removed from the live tree; financial history was preserved.'];
    });
}
