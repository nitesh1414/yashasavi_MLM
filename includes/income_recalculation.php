<?php
/**
 * Reviewable, append-only historical reconciliation for income rules 1.8.50.
 * Never called by order approval or a sponsor/tree edit. A preview is based on
 * current placement, sponsor, saved Self BV and current plan/rank settings.
 */

function income_recalc_schema_ready()
{
    $tables = ['income_matching_state', 'income_sponsor_events', 'income_rank_events', 'income_recalc_runs', 'income_recalc_entries',
        'weekly_payout_runs', 'weekly_payout_reservations'];
    $marks = implode(',', array_fill(0, count($tables), '?'));
    return (int)q_val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($marks)", $tables) === count($tables);
}

function income_recalc_json($value)
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) { throw new RuntimeException('Unable to encode reconciliation audit data.'); }
    return $json;
}

/** Check actual installed numeric columns, including older installations
 * whose decimal widths may differ. Signed deltas must never be written into
 * unsigned columns, and cents must not be rounded away by the schema. */
function income_recalc_numeric_limits(array &$errors)
{
    $decimal = [
        'users.left_bv', 'users.right_bv', 'users.wallet_balance', 'users.total_earned',
        'commissions.amount', 'wallet_transactions.amount', 'wallet_transactions.balance_after',
        'bv_logs.bv', 'bv_logs.balance_after', 'points_logs.left_bv', 'points_logs.right_bv',
        'income_matching_state.processed_bv', 'income_matching_state.points_processed_bv',
        'income_matching_state.pending_bv', 'income_matching_state.pending_amount',
        'income_matching_state.first_bonus_amount', 'income_sponsor_events.amount', 'income_sponsor_events.joining_bv',
    ];
    $integer = ['users.matched_pairs', 'users.reward_points', 'points_logs.points', 'points_logs.total_points',
        'income_matching_state.pending_pairs', 'income_matching_state.pending_points'];
    $signed = ['commissions.amount', 'bv_logs.bv', 'points_logs.points'];
    $tables = array_values(array_unique(array_map(function ($key) { return explode('.', $key)[0]; }, array_merge($decimal, $integer))));
    $marks = implode(',', array_fill(0, count($tables), '?'));
    $columns = [];
    foreach (q_all("SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE
                    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($marks)", $tables) as $col) {
        $columns[$col['TABLE_NAME'] . '.' . $col['COLUMN_NAME']] = $col;
    }
    $limits = [];
    foreach (array_merge($decimal, $integer) as $key) {
        $col = $columns[$key] ?? null;
        if (!$col) { $errors[] = 'Required numeric column ' . $key . ' is missing.'; continue; }
        $unsigned = stripos($col['COLUMN_TYPE'], 'unsigned') !== false;
        if (in_array($key, $signed, true) && $unsigned) { $errors[] = $key . ' must support signed adjustments.'; }
        if (in_array($key, $decimal, true)) {
            if (!in_array(strtolower($col['DATA_TYPE']), ['decimal', 'numeric'], true) || (int)$col['NUMERIC_SCALE'] !== 2) {
                $errors[] = $key . ' must be a two-decimal fixed-point column.';
                continue;
            }
            $digits = (int)$col['NUMERIC_PRECISION'] - (int)$col['NUMERIC_SCALE'];
            $limits[$key] = $digits > 14 ? INF : pow(10, $digits) - 0.01;
        } else {
            $maxima = ['tinyint' => 127, 'smallint' => 32767, 'mediumint' => 8388607, 'int' => 2147483647, 'bigint' => PHP_INT_MAX];
            $type = strtolower($col['DATA_TYPE']);
            if (!isset($maxima[$type])) { $errors[] = $key . ' must be an integer column.'; continue; }
            $max = $maxima[$type];
            $limits[$key] = $unsigned && $type !== 'bigint' ? $max * 2 + 1 : $max;
        }
    }
    return $limits;
}

/** Read a consistent snapshot. $lock is used only by the confirmed apply:
 * lock complete ranges, including inserts, before calculating the hash. */
function income_recalc_lock_inputs()
{
    // All application business mutations acquire the permanent coordination
    // row first, including before the readiness marker exists. The scans also
    // protect against legacy/non-engine writers.
    income_rules_ready(true);
    foreach (['plan_settings', 'ranks', 'users', 'orders', 'commissions', 'wallet_transactions', 'payouts',
              'income_matching_state', 'income_sponsor_events', 'income_rank_events',
              'weekly_payout_runs', 'weekly_payout_reservations'] as $table) {
        $key = $table === 'plan_settings' ? 'id' : ($table === 'income_matching_state' ? 'user_id'
             : ($table === 'income_sponsor_events' ? 'member_id' : ($table === 'income_rank_events' ? 'user_id, rank_id'
             : ($table === 'weekly_payout_runs' ? 'period_end' : ($table === 'weekly_payout_reservations' ? 'payout_id' : 'id')))));
        $stmt = q("SELECT $key FROM $table ORDER BY $key FOR UPDATE");
        while ($stmt->fetch()) { /* drain the locking read without retaining all ids */ }
    }
}

/** Must be run inside a transaction (plain consistent snapshot for preview;
 * serializable and range-locked for apply). It never writes to the database. */
function income_calculate_reconciliation()
{
    if (!income_recalc_schema_ready()) {
        throw new RuntimeException('The income database tables are missing. Ask your developer to apply DATABASE_CHANGES.sql first.');
    }
    $plan = q_row("SELECT * FROM plan_settings WHERE id = 1");
    if (!$plan) { throw new RuntimeException('The MLM plan settings are missing.'); }
    $ranks = q_all("SELECT id, name, min_points, reward_amount FROM ranks ORDER BY id");
    $usersList = q_all("SELECT id, username, sponsor_id, placement_id, leg, path, depth, status, is_active, self_bv,
                        left_bv, right_bv, matched_pairs, reward_points, rank_id, wallet_balance, total_earned
                        FROM users ORDER BY id");
    $orders = q_all("SELECT user_id, COUNT(*) AS n, COALESCE(SUM(total_bv), 0) AS approved_bv
                     FROM orders WHERE status = 'approved' GROUP BY user_id ORDER BY user_id");
    $commissions = q_all("SELECT user_id, type, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount
                          FROM commissions WHERE status = 'credited' AND type IN ('sponsor','binary','sponsor_matching','rank')
                          GROUP BY user_id, type ORDER BY user_id, type");
    $payouts = q_all("SELECT user_id, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS taken FROM payouts
                      WHERE status IN ('pending','paid') GROUP BY user_id ORDER BY user_id");
    $reservations = q_all("SELECT payout_id, statement_id, user_id, amount FROM weekly_payout_reservations
                           WHERE status='reserved' ORDER BY payout_id");
    $ledger = q_all("SELECT user_id, COUNT(*) AS n, COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0) AS balance
                     FROM wallet_transactions GROUP BY user_id ORDER BY user_id");
    $matchingStates = q_all("SELECT * FROM income_matching_state ORDER BY user_id");
    $sponsorEvents = q_all("SELECT * FROM income_sponsor_events ORDER BY member_id");
    $rankEvents = q_all("SELECT * FROM income_rank_events ORDER BY user_id, rank_id");
    $marker = q_val("SELECT svalue FROM settings WHERE skey = 'income_rules_1850'", [], '');
    $mutex = q_val("SELECT svalue FROM settings WHERE skey = 'income_lock_1850'", [], '');
    $snapshot = ['algorithm' => 'income-rules-1.8.50-1', 'marker' => $marker, 'mutex' => $mutex, 'plan' => $plan, 'ranks' => $ranks,
        'users' => $usersList, 'orders' => $orders, 'commissions' => $commissions, 'payouts' => $payouts,
        'reservations' => $reservations, 'ledger' => $ledger, 'matching' => $matchingStates,
        'sponsors' => $sponsorEvents, 'rank_events' => $rankEvents];
    $hash = hash('sha256', income_recalc_json($snapshot));
    $errors = [];
    if ($mutex !== '1') {
        $errors[] = 'The income coordination row is missing or invalid. Ask your developer to check DATABASE_CHANGES.sql.';
    }
    // Atomic rollback and range locking cannot be promised on MyISAM or
    // other non-transactional legacy tables. Refuse to apply in that case.
    $requiredTables = ['settings', 'admins', 'plan_settings', 'ranks', 'users', 'orders', 'commissions', 'wallet_transactions',
        'payouts', 'bv_logs', 'points_logs', 'income_matching_state', 'income_sponsor_events', 'income_rank_events',
        'income_recalc_runs', 'income_recalc_entries', 'weekly_payout_runs', 'weekly_payout_reservations'];
    $marks = implode(',', array_fill(0, count($requiredTables), '?'));
    $engines = [];
    foreach (q_all("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($marks)", $requiredTables) as $table) {
        $engines[$table['TABLE_NAME']] = strtoupper((string)$table['ENGINE']);
    }
    foreach ($requiredTables as $table) {
        if (($engines[$table] ?? '') !== 'INNODB') { $errors[] = 'Table ' . $table . ' must exist and use InnoDB for an atomic reconciliation.'; }
    }
    $limits = income_recalc_numeric_limits($errors);
    $limit = function ($key) use ($limits) { return $limits[$key] ?? INF; };
    $unit = (float)$plan['pair_unit_bv'];
    $threshold = (float)$plan['activation_bv'];
    if ($unit <= 0 || $threshold <= 0 || (float)$plan['sponsor_percent'] < 0 || (float)$plan['binary_value'] < 0
        || (float)$plan['sponsor_matching_percent'] < 0 || !in_array($plan['binary_type'], ['fixed', 'percent'], true)) {
        $errors[] = 'The plan contains an invalid joining threshold, pair unit, rate or income type.';
        // Keep the preview renderable while ensuring the apply is blocked.
        $unit = max(1, $unit); $threshold = max(1, $threshold);
    }
    $rate = income_pair_rate($plan);
    $sponsorRate = round($threshold * (float)$plan['sponsor_percent'] / 100, 2);
    $firstBonus = round($rate * (float)$plan['sponsor_matching_percent'] / 100, 2);
    $ppu = max(0, (int)$plan['points_per_unit']);
    if ($firstBonus > $limit('income_matching_state.first_bonus_amount')
        || $sponsorRate > $limit('income_sponsor_events.amount') || $threshold > $limit('income_sponsor_events.joining_bv')) {
        $errors[] = 'The configured sponsor or first-match amount exceeds the installed income-state column limits.';
    }
    foreach ($ranks as $rank) {
        if ((float)$rank['reward_amount'] < 0) { $errors[] = 'Rank ' . $rank['name'] . ' has a negative reward.'; }
    }

    $users = []; $children = []; $remaining = []; $subtree = []; $teams = [];
    foreach ($usersList as $u) {
        $id = (int)$u['id'];
        $users[$id] = $u;
        $remaining[$id] = 0;
        $subtree[$id] = (float)$u['self_bv'];
        $teams[$id] = ['L' => 0.0, 'R' => 0.0];
        if ((float)$u['self_bv'] < 0) { $errors[] = $u['username'] . ' has negative saved Self BV.'; }
        if (!in_array($u['status'], ['active', 'blocked'], true)) { $errors[] = $u['username'] . ' has an unsupported status: ' . $u['status'] . '.'; }
    }
    foreach ($users as $id => $u) {
        $parent = (int)$u['placement_id'];
        $sponsor = (int)$u['sponsor_id'];
        if ($sponsor === $id || ($sponsor && !isset($users[$sponsor]))) {
            $errors[] = $u['username'] . ' has an invalid or missing sponsor.';
        }
        if ($parent) {
            if (!isset($users[$parent]) || $parent === $id || !in_array($u['leg'], ['L', 'R'], true)) {
                $errors[] = $u['username'] . ' has an invalid placement parent or side.';
                continue;
            }
            if (isset($children[$parent][$u['leg']])) {
                $errors[] = $users[$parent]['username'] . ' has more than one direct child on the ' . $u['leg'] . ' side.';
            }
            $children[$parent][$u['leg']] = $id;
            $remaining[$parent]++;
            $expectedPath = $users[$parent]['path'] . $id . '/';
            if ($u['path'] !== $expectedPath || (int)$u['depth'] !== (int)$users[$parent]['depth'] + 1) {
                $errors[] = $u['username'] . ' has a path/depth inconsistent with its placement parent.';
            }
        } elseif (!in_array($u['path'], ['/', '/' . $id . '/'], true) || (int)$u['depth'] !== 0) {
            $errors[] = $u['username'] . ' has an invalid root path/depth.';
        }
    }
    // Detect sponsor cycles without assuming the sponsor is a placement ancestor.
    $visited = [];
    foreach ($users as $id => $u) {
        if (isset($visited[$id])) { continue; }
        $chain = []; $at = $id;
        while ($at && isset($users[$at]) && !isset($visited[$at])) {
            if (isset($chain[$at])) { $errors[] = 'Sponsor cycle involving ' . $users[$at]['username'] . '.'; break; }
            $chain[$at] = true;
            $at = (int)$users[$at]['sponsor_id'];
        }
        foreach ($chain as $memberId => $_) { $visited[$memberId] = true; }
    }
    // Sum each subtree exactly once, from leaves to roots, without recursion.
    $queue = [];
    foreach ($remaining as $id => $n) { if ($n === 0) { $queue[] = $id; } }
    $processed = 0;
    for ($head = 0; $head < count($queue); $head++) {
        $id = $queue[$head]; $processed++;
        $u = $users[$id]; $parent = (int)$u['placement_id'];
        if ($parent && isset($users[$parent]) && $parent !== $id && in_array($u['leg'], ['L', 'R'], true)) {
            $teams[$parent][$u['leg']] = round($teams[$parent][$u['leg']] + $subtree[$id], 2);
            $subtree[$parent] = round($subtree[$parent] + $subtree[$id], 2);
            if (--$remaining[$parent] === 0) { $queue[] = $parent; }
        }
    }
    if ($processed !== count($users)) { $errors[] = 'The placement tree contains a cycle or an invalid parent link.'; }

    $orderByUser = []; $earned = []; $taken = []; $reserved = []; $ledgerByUser = [];
    $oldMatching = []; $oldSponsor = []; $oldRanks = [];
    foreach ($orders as $o) { $orderByUser[(int)$o['user_id']] = $o; }
    foreach ($commissions as $c) { $earned[(int)$c['user_id']][$c['type']] = (float)$c['amount']; }
    foreach ($payouts as $p) { $taken[(int)$p['user_id']] = (float)$p['taken']; }
    foreach ($reservations as $r) {
        $id = (int)$r['user_id'];
        $reserved[$id] = round(($reserved[$id] ?? 0) + (float)$r['amount'], 2);
    }
    foreach ($ledger as $l) { $ledgerByUser[(int)$l['user_id']] = (float)$l['balance']; }
    foreach ($matchingStates as $m) { $oldMatching[(int)$m['user_id']] = $m; }
    foreach ($sponsorEvents as $e) { $oldSponsor[(int)$e['member_id']] = $e; }
    foreach ($rankEvents as $e) { $oldRanks[(int)$e['user_id']][] = $e; }
    foreach ([$orderByUser, $earned, $taken, $reserved, $ledgerByUser, $oldMatching, $oldSponsor, $oldRanks] as $byUser) {
        foreach ($byUser as $id => $_) {
            if (!isset($users[$id])) { $errors[] = 'Business data references missing member ID ' . $id . '.'; }
        }
    }

    $orderedRanks = $ranks;
    usort($orderedRanks, function ($a, $b) { return [(int)$a['min_points'], (int)$a['id']] <=> [(int)$b['min_points'], (int)$b['id']]; });
    $rows = [];
    foreach ($users as $id => $u) {
        $pairCount = income_whole_pairs(min($teams[$id]['L'], $teams[$id]['R']), 0, $unit);
        $canEarn = income_can_earn($u, $plan);
        $canMatch = income_can_match($u, $plan);
        $points = $canEarn ? $pairCount * $ppu : 0;
        $processedBv = round($pairCount * $unit, 2);
        if ($pairCount > $limit('users.matched_pairs') || $points > min($limit('users.reward_points'), $limit('points_logs.total_points'))
            || $processedBv > min($limit('income_matching_state.processed_bv'), $limit('income_matching_state.points_processed_bv'))
            || (!$canEarn && $pairCount * $ppu > $limit('income_matching_state.pending_points'))
            || (!$canMatch && ($pairCount > $limit('income_matching_state.pending_pairs')
                || $processedBv > $limit('income_matching_state.pending_bv')
                || $pairCount * $rate > $limit('income_matching_state.pending_amount')))) {
            $errors[] = $u['username'] . ' exceeds the installed pair, points or pending-income column limits.';
        }
        $best = null; $rankAmount = 0.0; $newRankEvents = [];
        foreach ($orderedRanks as $rank) {
            if ((int)$rank['min_points'] > 0 && $points >= (int)$rank['min_points']) {
                $best = (int)$rank['id'];
                $rankAmount = round($rankAmount + (float)$rank['reward_amount'], 2);
                $newRankEvents[] = ['rank_id' => (int)$rank['id'], 'amount' => round((float)$rank['reward_amount'], 2)];
            }
        }
        $parent = (int)$u['placement_id'];
        $bonusStatus = 'none';
        if ($pairCount > 0 && $parent && $firstBonus > 0 && isset($users[$parent])) {
            $bonusStatus = !$canMatch ? 'pending_match' : (income_can_earn($users[$parent], $plan) ? 'paid' : 'pending_parent');
        }
        $matching = [
            'processed_bv' => round($pairCount * $unit, 2), 'points_processed_bv' => round($pairCount * $unit, 2),
            'pending_pairs' => $canMatch ? 0 : $pairCount, 'pending_bv' => $canMatch ? 0 : round($pairCount * $unit, 2),
            'pending_amount' => $canMatch ? 0 : round($pairCount * $rate, 2),
            'pending_points' => $canEarn ? 0 : $pairCount * $ppu,
            'first_match_seen' => $pairCount > 0 ? 1 : 0, 'first_parent_id' => $pairCount > 0 && $parent ? $parent : null,
            'first_bonus_amount' => $pairCount > 0 ? $firstBonus : 0, 'first_bonus_order_id' => null,
            'first_bonus_commission_id' => null, 'first_bonus_status' => $bonusStatus,
        ];
        $oldEarnings = [];
        foreach (['sponsor', 'binary', 'sponsor_matching', 'rank'] as $type) { $oldEarnings[$type] = round($earned[$id][$type] ?? 0, 2); }
        $rows[$id] = [
            'id' => $id, 'username' => $u['username'], 'status' => $u['status'], 'is_active' => (int)$u['is_active'], 'self_bv' => (float)$u['self_bv'],
            'approved_bv' => (float)($orderByUser[$id]['approved_bv'] ?? 0),
            'approved_orders' => (int)($orderByUser[$id]['n'] ?? 0),
            'sponsor_id' => (int)$u['sponsor_id'] ?: null, 'placement_id' => $parent ?: null,
            'before' => [
                'left_bv' => (float)$u['left_bv'], 'right_bv' => (float)$u['right_bv'], 'pairs' => (int)$u['matched_pairs'],
                'points' => (int)$u['reward_points'], 'rank_id' => $u['rank_id'] === null ? null : (int)$u['rank_id'],
                'wallet' => (float)$u['wallet_balance'], 'total_earned' => (float)$u['total_earned'],
                'earnings' => $oldEarnings, 'matching_state' => $oldMatching[$id] ?? null,
                'sponsor_event' => $oldSponsor[$id] ?? null, 'rank_events' => $oldRanks[$id] ?? [],
                'ledger_balance' => $ledgerByUser[$id] ?? 0, 'payout_taken' => $taken[$id] ?? 0,
                'payout_reserved' => $reserved[$id] ?? 0,
            ],
            'after' => [
                'left_bv' => $teams[$id]['L'], 'right_bv' => $teams[$id]['R'], 'pairs' => $pairCount,
                'points' => $points, 'rank_id' => $best, 'earnings' => ['sponsor' => 0.0,
                    'binary' => $canMatch ? round($pairCount * $rate, 2) : 0.0,
                    'sponsor_matching' => 0.0, 'rank' => $rankAmount],
                'matching_state' => $matching, 'sponsor_event' => null, 'rank_events' => $newRankEvents,
            ],
        ];
    }
    // Sponsorship is independent of placement, and each member qualifies once.
    foreach ($users as $id => $u) {
        if ((float)$u['self_bv'] < $threshold) { continue; }
        $sid = (int)$u['sponsor_id'];
        $hasSponsor = $sid && isset($users[$sid]) && $sid !== $id;
        $status = !$hasSponsor ? 'cancelled' : (income_can_earn($users[$sid], $plan) ? 'paid' : 'pending');
        $rows[$id]['after']['sponsor_event'] = ['sponsor_id' => $hasSponsor ? $sid : null, 'order_id' => null,
            'amount' => $sponsorRate, 'joining_bv' => $threshold, 'status' => $status, 'commission_id' => null];
        if ($status === 'paid') { $rows[$sid]['after']['earnings']['sponsor'] = round($rows[$sid]['after']['earnings']['sponsor'] + $sponsorRate, 2); }
    }
    // Exactly one bonus per immediate placement child, based on its first pair.
    foreach ($rows as $id => $row) {
        $m = $row['after']['matching_state'];
        if ($m['first_bonus_status'] === 'paid') {
            $parent = (int)$m['first_parent_id'];
            $rows[$parent]['after']['earnings']['sponsor_matching'] = round($rows[$parent]['after']['earnings']['sponsor_matching'] + $m['first_bonus_amount'], 2);
        }
    }
    $summary = ['members' => count($rows), 'approved_orders' => 0, 'self_bv_discrepancies' => 0,
        'ledger_discrepancies' => 0, 'overpaid_members' => 0, 'changed_members' => 0,
        'credits' => 0.0, 'debits' => 0.0, 'net' => 0.0, 'old_earnings' => 0.0, 'new_earnings' => 0.0];
    foreach ($rows as $id => &$row) {
        $summary['approved_orders'] += $row['approved_orders'];
        $row['self_bv_difference'] = round($row['self_bv'] - $row['approved_bv'], 2);
        $row['ledger_difference'] = round($row['before']['wallet'] - $row['before']['ledger_balance'], 2);
        if (abs($row['self_bv_difference']) >= 0.01) { $summary['self_bv_discrepancies']++; }
        if (abs($row['ledger_difference']) >= 0.01) { $summary['ledger_discrepancies']++; }
        $deltas = []; $net = 0.0; $credits = 0.0;
        foreach (['sponsor', 'binary', 'sponsor_matching', 'rank'] as $type) {
            $delta = round($row['after']['earnings'][$type] - $row['before']['earnings'][$type], 2);
            $deltas[$type] = $delta; $net = round($net + $delta, 2);
            $summary['old_earnings'] += $row['before']['earnings'][$type];
            $summary['new_earnings'] += $row['after']['earnings'][$type];
            if ($delta > 0) { $summary['credits'] += $delta; $credits += $delta; } else { $summary['debits'] += -$delta; }
            if (abs($delta) > min($limit('commissions.amount'), $limit('wallet_transactions.amount'))) {
                $errors[] = $row['username'] . ' has an adjustment too large for the installed commission or wallet ledger.';
            }
        }
        $row['adjustments'] = $deltas;
        $row['net_adjustment'] = $net;
        $row['after']['wallet'] = round($row['before']['wallet'] + $net, 2);
        $row['after']['total_earned'] = round($row['before']['total_earned'] + $net, 2);
        // Credits are applied before debits, so also test the temporary peak,
        // not just the final net balance, against the installed column widths.
        if ($row['before']['wallet'] + $credits > min($limit('users.wallet_balance'), $limit('wallet_transactions.balance_after'))
            || $row['before']['total_earned'] + $credits > $limit('users.total_earned')
            || $row['after']['left_bv'] > min($limit('users.left_bv'), $limit('bv_logs.balance_after'), $limit('points_logs.left_bv'))
            || $row['after']['right_bv'] > min($limit('users.right_bv'), $limit('bv_logs.balance_after'), $limit('points_logs.right_bv'))
            || abs($row['after']['left_bv'] - $row['before']['left_bv']) > $limit('bv_logs.bv')
            || abs($row['after']['right_bv'] - $row['before']['right_bv']) > $limit('bv_logs.bv')
            || abs($row['after']['points'] - $row['before']['points']) > $limit('points_logs.points')) {
            $errors[] = $row['username'] . ' exceeds an installed database ledger or counter limit.';
        }
        $row['after']['payout_eligible'] = max(0, round(array_sum($row['after']['earnings']) - $row['before']['payout_taken'], 2));
        $row['overpaid'] = max(0, round($row['before']['payout_taken'] - array_sum($row['after']['earnings']), 2));
        if ($row['overpaid'] > 0) { $summary['overpaid_members']++; }
        if ($row['after']['wallet'] < -0.001 || $row['after']['total_earned'] < -0.001) {
            $errors[] = $row['username'] . ' would have a negative wallet balance or total earned; resolve the shortfall before applying.';
        }
        if ($row['before']['payout_reserved'] > 0 && $row['after']['wallet'] + 0.001 < $row['before']['payout_reserved']) {
            $errors[] = $row['username'] . ' would spend funds reserved for a weekly payout. Process or explicitly release the unpaid report reservations, or resolve the shortfall before applying.';
        }
        if ($net != 0.0 || $row['before']['left_bv'] != $row['after']['left_bv'] || $row['before']['right_bv'] != $row['after']['right_bv']
            || $row['before']['pairs'] !== $row['after']['pairs'] || $row['before']['points'] !== $row['after']['points']
            || $row['before']['rank_id'] !== $row['after']['rank_id']) { $summary['changed_members']++; }
        $summary['net'] += $net;
    }
    unset($row);
    foreach (['credits', 'debits', 'net', 'old_earnings', 'new_earnings'] as $key) { $summary[$key] = round($summary[$key], 2); }
    return ['hash' => $hash, 'plan' => $plan, 'ranks' => $ranks, 'rows' => $rows, 'summary' => $summary,
        'errors' => array_values(array_unique($errors)), 'already_initialized' => $marker === 'ready'];
}

function income_preview_reconciliation()
{
    return db_tx(function () { return income_calculate_reconciliation(); });
}

/** Append a signed commission AND its corresponding wallet transaction. */
function income_recalc_adjustment($userId, $type, $amount, $runId, $createdAt = null)
{
    if (!db()->inTransaction()) { throw new RuntimeException('Income adjustments require a transaction.'); }
    $amount = round((float)$amount, 2);
    if ($amount == 0.0) { return null; }
    $createdAt = $createdAt ?: now();
    $note = 'Historical income reconciliation #' . $runId . ' (' . $type . ')';
    q("INSERT INTO commissions (user_id, order_id, type, amount, bv, level, note, status, created_at)
       VALUES (?, NULL, ?, ?, 0, NULL, ?, 'credited', ?)", [$userId, $type, $amount, $note, $createdAt]);
    $commissionId = (int)db()->lastInsertId();
    $u = q_row("SELECT wallet_balance, total_earned FROM users WHERE id = ? FOR UPDATE", [$userId]);
    $newBalance = round((float)$u['wallet_balance'] + $amount, 2);
    $newEarned = round((float)$u['total_earned'] + $amount, 2);
    if ($newBalance < -0.001 || $newEarned < -0.001) { throw new RuntimeException('A wallet changed or has insufficient funds; reconciliation was rolled back.'); }
    if ($newBalance + 0.001 < weekly_reserved_amount($userId, true)) {
        throw new RuntimeException('A reconciliation adjustment would spend reserved weekly payout funds; the run was rolled back.');
    }
    q("UPDATE users SET wallet_balance = ?, total_earned = ? WHERE id = ?", [$newBalance, $newEarned, $userId]);
    q("INSERT INTO wallet_transactions (user_id, type, amount, balance_after, ref_type, ref_id, note, created_at)
       VALUES (?, ?, ?, ?, 'commission', ?, ?, ?)", [$userId, $amount > 0 ? 'credit' : 'debit', abs($amount), $newBalance,
        $commissionId, $note, $createdAt]);
    return $commissionId;
}

/** Apply exactly the reviewed snapshot, atomically, or change nothing. */
function income_apply_reconciliation($expectedHash, $adminId)
{
    if (db()->inTransaction()) { throw new RuntimeException('Reconciliation requires its own transaction.'); }
    if (!preg_match('/^[a-f0-9]{64}$/', (string)$expectedHash) || (int)$adminId <= 0) {
        throw new RuntimeException('Invalid preview or administrator.');
    }
    // InnoDB serializable range locks prevent phantoms while the input tables
    // and wallet balances are checked and updated. A deadlock rolls back all.
    db()->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    return db_tx(function () use ($expectedHash, $adminId) {
        if (!income_recalc_schema_ready()) { throw new RuntimeException('Apply DATABASE_CHANGES.sql first.'); }
        income_recalc_lock_inputs();
        $admin = q_row("SELECT id FROM admins WHERE id = ? AND role = 'superadmin' AND status = 'active' FOR UPDATE", [(int)$adminId]);
        if (!$admin) { throw new RuntimeException('An active superadmin is required. Nothing was changed.'); }
        $preview = income_calculate_reconciliation();
        if (!hash_equals($expectedHash, $preview['hash'])) { throw new RuntimeException('The data changed since the preview. Nothing was changed; generate and review a new preview.'); }
        if ($preview['errors']) { throw new RuntimeException('The preview has blocking issues. Nothing was changed.'); }
        // All rows in one atomic run share a timestamp, even if a large run
        // crosses midnight or a payout cutoff while it is being applied.
        $runTs = now();
        q("INSERT INTO income_recalc_runs (admin_id, snapshot_hash, plan_json, summary_json, created_at) VALUES (?, ?, ?, ?, ?)",
            [(int)$adminId, $preview['hash'], income_recalc_json(['plan' => $preview['plan'], 'ranks' => $preview['ranks'], 'algorithm' => 'income-rules-1.8.50-1']),
             income_recalc_json($preview['summary']), $runTs]);
        $runId = (int)db()->lastInsertId();
        foreach ($preview['rows'] as $row) {
            $id = $row['id']; $before = $row['before']; $after = $row['after'];
            q("UPDATE users SET left_bv = ?, right_bv = ?, matched_pairs = ?, reward_points = ?, rank_id = ? WHERE id = ?",
                [$after['left_bv'], $after['right_bv'], $after['pairs'], $after['points'], $after['rank_id'], $id]);
            foreach (['left', 'right'] as $leg) {
                $key = $leg . '_bv';
                $delta = round($after[$key] - $before[$key], 2);
                if ($delta != 0.0) {
                    q("INSERT INTO bv_logs (user_id, order_id, kind, bv, balance_after, note, created_at)
                       VALUES (?, NULL, ?, ?, ?, ?, ?)", [$id, $leg, $delta, $after[$key], 'Historical reconciliation #' . $runId, $runTs]);
                }
            }
            $pointsDelta = $after['points'] - $before['points'];
            if ($pointsDelta !== 0) {
                q("INSERT INTO points_logs (user_id, order_id, points, total_points, left_bv, right_bv, created_at)
                   VALUES (?, NULL, ?, ?, ?, ?, ?)", [$id, $pointsDelta, $after['points'], $after['left_bv'], $after['right_bv'], $runTs]);
            }
            $m = $after['matching_state'];
            q("INSERT INTO income_matching_state (user_id, processed_bv, points_processed_bv, pending_pairs, pending_bv, pending_amount,
               pending_points, first_match_seen, first_parent_id, first_bonus_amount, first_bonus_order_id, first_bonus_commission_id, first_bonus_status)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE processed_bv=VALUES(processed_bv), points_processed_bv=VALUES(points_processed_bv),
               pending_pairs=VALUES(pending_pairs), pending_bv=VALUES(pending_bv), pending_amount=VALUES(pending_amount),
               pending_points=VALUES(pending_points), first_match_seen=VALUES(first_match_seen), first_parent_id=VALUES(first_parent_id),
               first_bonus_amount=VALUES(first_bonus_amount), first_bonus_order_id=VALUES(first_bonus_order_id),
               first_bonus_commission_id=VALUES(first_bonus_commission_id), first_bonus_status=VALUES(first_bonus_status)",
               [$id, $m['processed_bv'], $m['points_processed_bv'], $m['pending_pairs'], $m['pending_bv'], $m['pending_amount'],
                $m['pending_points'], $m['first_match_seen'], $m['first_parent_id'], $m['first_bonus_amount'],
                $m['first_bonus_order_id'], $m['first_bonus_commission_id'], $m['first_bonus_status']]);
            $event = $after['sponsor_event'];
            if (!$event) {
                q("DELETE FROM income_sponsor_events WHERE member_id = ?", [$id]);
            } else {
                q("INSERT INTO income_sponsor_events (member_id, sponsor_id, order_id, amount, joining_bv, status, commission_id, created_at, paid_at)
                   VALUES (?, ?, NULL, ?, ?, ?, NULL, ?, ?)
                   ON DUPLICATE KEY UPDATE sponsor_id=VALUES(sponsor_id), order_id=NULL, amount=VALUES(amount), joining_bv=VALUES(joining_bv),
                   status=VALUES(status), commission_id=NULL, created_at=VALUES(created_at), paid_at=VALUES(paid_at)",
                   [$id, $event['sponsor_id'], $event['amount'], $event['joining_bv'], $event['status'], $runTs, $event['status'] === 'paid' ? $runTs : null]);
            }
            q("DELETE FROM income_rank_events WHERE user_id = ?", [$id]);
            foreach ($after['rank_events'] as $event) {
                q("INSERT INTO income_rank_events (user_id, rank_id, amount) VALUES (?, ?, ?)", [$id, $event['rank_id'], $event['amount']]);
            }
            $commissionIds = [];
            // Credits first permit offsetting category corrections while still
            // refusing any net debit which would make the wallet negative.
            foreach ([1, -1] as $direction) {
                foreach ($row['adjustments'] as $type => $delta) {
                    if (($direction === 1 && $delta > 0) || ($direction === -1 && $delta < 0)) {
                        $commissionIds[$type] = income_recalc_adjustment($id, $type, $delta, $runId, $runTs);
                    }
                }
            }
            q("INSERT INTO income_recalc_entries (run_id, user_id, before_json, after_json, adjustment_json) VALUES (?, ?, ?, ?, ?)",
                [$runId, $id, income_recalc_json(['username' => $row['username'], 'status' => $row['status'], 'is_active' => $row['is_active'], 'self_bv' => $row['self_bv'], 'approved_bv' => $row['approved_bv'], 'approved_orders' => $row['approved_orders'],
                    'sponsor_id' => $row['sponsor_id'], 'placement_id' => $row['placement_id'], 'values' => $before]),
                 income_recalc_json($after), income_recalc_json(['amounts' => $row['adjustments'], 'net' => $row['net_adjustment'],
                    'commission_ids' => $commissionIds])]);
        }
        save_setting('income_rules_1850', 'ready');
        return ['run_id' => $runId, 'summary' => $preview['summary']];
    });
}
