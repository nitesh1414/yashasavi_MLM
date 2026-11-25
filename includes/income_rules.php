<?php
/**
 * Incremental income rules. All calls that change business data must run
 * inside a transaction and acquire the income_rules_1850 setting first.
 * The setting is deliberately absent on upgraded sites until a superadmin
 * has reviewed and applied the initial historical reconciliation.
 */

/** Prevent MySQL's non-strict mode from silently clipping financial values.
 * This changes only the current connection, and makes a failed transaction
 * roll back rather than writing a truncated amount. */
function income_strict_sql_mode()
{
    static $enabled = false;
    if (!$enabled) {
        db()->exec("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_TRANS_TABLES')");
        $enabled = true;
    }
}

/** BV and payout arithmetic converts fixed-point amounts to integer
 * hundredths. A 32-bit PHP build cannot represent the installed DECIMAL
 * ranges, so fail closed rather than silently overflowing. */
function income_require_64bit()
{
    if (PHP_INT_SIZE < 8) {
        throw new RuntimeException('A 64-bit PHP runtime is required for income and payout calculations.');
    }
}

function income_rules_ready($lock = false)
{
    if ($lock) {
        income_require_64bit();
        income_strict_sql_mode();
        // Unlike the readiness marker, this permanent row also exists before
        // the first reconciliation, so concurrent writers have a real mutex.
        $mutex = q_val("SELECT svalue FROM settings WHERE skey = 'income_lock_1850' FOR UPDATE");
        if ($mutex !== '1') {
            throw new RuntimeException('Income coordination is not installed. Apply DATABASE_CHANGES.sql during maintenance.');
        }
    }
    $sql = "SELECT svalue FROM settings WHERE skey = 'income_rules_1850'";
    if ($lock) { $sql .= ' FOR UPDATE'; }
    return q_val($sql) === 'ready';
}

function income_maintenance_message()
{
    return 'Income processing is paused until the database upgrade and the superadmin historical income preview have been reviewed and applied.';
}

/** Manual earning activation is an existing override; completed saved Self
 * BV also qualifies. Blocking an account always suspends its earnings. */
function income_can_earn(array $user, array $plan)
{
    return $user['status'] === 'active'
        && ((int)$user['is_active'] === 1 || (float)$user['self_bv'] >= (float)$plan['activation_bv']);
}

function income_can_match(array $user, array $plan)
{
    return $user['status'] === 'active'
        && (!(int)$plan['matching_requires_active'] || income_can_earn($user, $plan));
}

function income_pair_rate(array $plan)
{
    return round($plan['binary_type'] === 'fixed'
        ? (float)$plan['binary_value']
        : (float)$plan['pair_unit_bv'] * (float)$plan['binary_value'] / 100, 2);
}

/** Whole matching units using integer hundredths of BV, not floating-point
 * division. Both legs consume the same amount; unconsumed BV carries over. */
function income_whole_pairs($availableBv, $processedBv, $unitBv)
{
    income_require_64bit();
    $unit = (int)round((float)$unitBv * 100);
    if ($unit <= 0) { throw new RuntimeException('The matching BV unit must be positive.'); }
    $available = (int)round((float)$availableBv * 100);
    $processed = (int)round((float)$processedBv * 100);
    return intdiv(max(0, $available - $processed), $unit);
}

/** Rank event rows prevent a recalculation or a later promotion from paying
 * the same milestone twice. Rank rewards are also reconciled in the preview. */
function check_rank($userId, $orderId = null)
{
    $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$userId]);
    if (!$u || !income_can_earn($u, get_plan())) { return null; }
    $best = null;
    foreach (q_all("SELECT * FROM ranks ORDER BY min_points ASC, id ASC") as $rank) {
        if ((int)$rank['min_points'] <= 0 || (int)$u['reward_points'] < (int)$rank['min_points']) { continue; }
        $best = $rank;
        $rankId = (int)$rank['id'];
        $exists = q_row("SELECT user_id FROM income_rank_events WHERE user_id = ? AND rank_id = ? FOR UPDATE", [$userId, $rankId]);
        if ($exists) { continue; }
        $amount = max(0, round((float)$rank['reward_amount'], 2));
        q("INSERT INTO income_rank_events (user_id, rank_id, amount) VALUES (?, ?, ?)", [$userId, $rankId, $amount]);
        if ($amount > 0) {
            add_commission($userId, $orderId, 'rank', $amount, (int)$u['reward_points'],
                $rankId <= 255 ? $rankId : null,
                'Rank achievement: ' . $rank['name'] . ' (' . (int)$rank['min_points'] . ' points)');
        }
    }
    if ($best && (int)$u['rank_id'] !== (int)$best['id']) {
        q("UPDATE users SET rank_id = ? WHERE id = ?", [$best['id'], $userId]);
    }
    return $best;
}

/** A qualified referral creates one event, regardless of their placement. */
function income_record_sponsor_event(array $member, $orderId = null)
{
    $plan = get_plan();
    $threshold = (float)$plan['activation_bv'];
    if ((float)$member['self_bv'] < $threshold) { return; }
    $existing = q_row("SELECT member_id FROM income_sponsor_events WHERE member_id = ? FOR UPDATE", [(int)$member['id']]);
    if ($existing) { return; }
    $sponsorId = (int)$member['sponsor_id'];
    $amount = round($threshold * (float)$plan['sponsor_percent'] / 100, 2);
    if ($sponsorId === (int)$member['id']) { throw new RuntimeException('A member cannot sponsor themselves.'); }
    $sponsor = $sponsorId ? q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$sponsorId]) : null;
    if ($sponsorId && !$sponsor) { throw new RuntimeException('The member\'s sponsor no longer exists.'); }
    $status = !$sponsor ? 'cancelled' : (income_can_earn($sponsor, $plan) ? 'paid' : 'pending');
    q("INSERT INTO income_sponsor_events (member_id, sponsor_id, order_id, amount, joining_bv, status, created_at, paid_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
       [$member['id'], $sponsorId ?: null, $orderId, $amount, $threshold, $status, commission_now(),
        $status === 'paid' ? commission_now() : null]);
    if ($status === 'paid' && $amount > 0) {
        $cid = add_commission($sponsorId, $orderId, 'sponsor', $amount, $threshold, null,
            'Personally sponsored member ' . $member['username'] . ' completed joining BV');
        q("UPDATE income_sponsor_events SET commission_id = ? WHERE member_id = ?", [$cid, $member['id']]);
    }
}

function income_pay_pending_sponsors($userId)
{
    $sponsor = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$userId]);
    if (!$sponsor || !income_can_earn($sponsor, get_plan())) { return; }
    $events = q_all("SELECT e.*, u.username FROM income_sponsor_events e
                     JOIN users u ON u.id = e.member_id
                     WHERE e.sponsor_id = ? AND e.status = 'pending' ORDER BY e.member_id FOR UPDATE", [(int)$userId]);
    foreach ($events as $event) {
        $cid = null;
        if ((float)$event['amount'] > 0) {
            $cid = add_commission($userId, $event['order_id'], 'sponsor', $event['amount'], $event['joining_bv'], null,
                'Pending personally sponsored member ' . $event['username'] . ' completed joining BV');
        }
        q("UPDATE income_sponsor_events SET status = 'paid', commission_id = ?, paid_at = ? WHERE member_id = ?",
            [$cid, commission_now(), $event['member_id']]);
    }
}

/** Pay at most once for this child's first match, to the frozen placement
 * parent at the time of that match (not the child's sponsor). */
function income_pay_first_match_bonus($memberId)
{
    $event = q_row("SELECT * FROM income_matching_state WHERE user_id = ? FOR UPDATE", [(int)$memberId]);
    if (!$event || $event['first_bonus_status'] !== 'pending_parent') { return; }
    $parent = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$event['first_parent_id']]);
    if (!$parent || !income_can_earn($parent, get_plan())) { return; }
    $member = q_row("SELECT username FROM users WHERE id = ?", [(int)$memberId]);
    if (!$member) { throw new RuntimeException('First-match member no longer exists.'); }
    $cid = add_commission($parent['id'], $event['first_bonus_order_id'], 'sponsor_matching',
        $event['first_bonus_amount'], 0, null,
        'Immediate placement child ' . $member['username'] . ' completed their first matching pair');
    q("UPDATE income_matching_state SET first_bonus_status = 'paid', first_bonus_commission_id = ? WHERE user_id = ?", [$cid, $memberId]);
}

function income_pay_pending_child_bonuses($parentId)
{
    $parent = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$parentId]);
    if (!$parent || !income_can_earn($parent, get_plan())) { return; }
    foreach (q_all("SELECT user_id FROM income_matching_state WHERE first_parent_id = ? AND first_bonus_status = 'pending_parent' ORDER BY user_id FOR UPDATE", [(int)$parentId]) as $event) {
        income_pay_first_match_bonus((int)$event['user_id']);
    }
}

/** Count and settle every new pair. Ineligible matches and points retain
 * their original rates in pending state and are settled once eligible. */
function run_binary_matching($user, $orderId = null)
{
    $userId = (int)(is_array($user) ? $user['id'] : $user);
    $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$userId]);
    $state = q_row("SELECT * FROM income_matching_state WHERE user_id = ? FOR UPDATE", [$userId]);
    if (!$u || !$state) { throw new RuntimeException('Income baseline is missing for member ' . $userId . '. Run the superadmin preview.'); }
    $plan = get_plan();
    $unit = (float)$plan['pair_unit_bv'];
    $rate = income_pair_rate($plan);
    $newPairs = income_whole_pairs(min((float)$u['left_bv'], (float)$u['right_bv']), $state['processed_bv'], $unit);
    $pendingPairs = (int)$state['pending_pairs'] + $newPairs;
    $pendingBv = round((float)$state['pending_bv'] + $newPairs * $unit, 2);
    $pendingAmount = round((float)$state['pending_amount'] + $newPairs * $rate, 2);
    $pendingPoints = (int)$state['pending_points'] + $newPairs * max(0, (int)$plan['points_per_unit']);
    $processed = round((float)$state['processed_bv'] + $newPairs * $unit, 2);
    $firstSeen = (int)$state['first_match_seen'];
    $parentId = $state['first_parent_id'];
    $bonus = (float)$state['first_bonus_amount'];
    $bonusOrder = $state['first_bonus_order_id'];
    $bonusStatus = $state['first_bonus_status'];
    if ($newPairs > 0 && !$firstSeen) {
        $firstSeen = 1;
        $parentId = (int)$u['placement_id'] ?: null;
        $bonus = round($rate * (float)$plan['sponsor_matching_percent'] / 100, 2);
        $bonusOrder = $orderId;
        $bonusStatus = ($parentId && $bonus > 0) ? 'pending_match' : 'none';
    }
    if ($newPairs > 0) {
        q("UPDATE users SET matched_pairs = matched_pairs + ? WHERE id = ?", [$newPairs, $userId]);
    }
    $payPairs = income_can_match($u, $plan) && $pendingPairs > 0;
    $payPoints = income_can_earn($u, $plan) && $pendingPoints > 0;
    if ($payPairs && $bonusStatus === 'pending_match') { $bonusStatus = 'pending_parent'; }
    q("UPDATE income_matching_state SET processed_bv = ?, points_processed_bv = ?, pending_pairs = ?,
       pending_bv = ?, pending_amount = ?, pending_points = ?, first_match_seen = ?, first_parent_id = ?,
       first_bonus_amount = ?, first_bonus_order_id = ?, first_bonus_status = ? WHERE user_id = ?",
       [$processed, $processed, $payPairs ? 0 : $pendingPairs, $payPairs ? 0 : $pendingBv,
        $payPairs ? 0 : $pendingAmount, $payPoints ? 0 : $pendingPoints, $firstSeen, $parentId,
        $bonus, $bonusOrder, $bonusStatus, $userId]);
    if ($payPairs && $pendingAmount > 0) {
        add_commission($userId, $orderId, 'binary', $pendingAmount, $pendingBv, null,
            'Matching income for ' . $pendingPairs . ' pair(s), including any carried pending pairs');
    }
    if ($payPoints) {
        $total = (int)$u['reward_points'] + $pendingPoints;
        q("UPDATE users SET reward_points = ? WHERE id = ?", [$total, $userId]);
        q("INSERT INTO points_logs (user_id, order_id, points, total_points, left_bv, right_bv, created_at)
           VALUES (?, ?, ?, ?, ?, ?, ?)", [$userId, $orderId, $pendingPoints, $total, $u['left_bv'], $u['right_bv'], commission_now()]);
        check_rank($userId, $orderId);
    }
    if ($bonusStatus === 'pending_parent') { income_pay_first_match_bonus($userId); }
    return $payPairs ? $pendingAmount : 0.0;
}

function income_settle_member($userId, $orderId = null)
{
    income_pay_pending_sponsors($userId);
    run_binary_matching($userId, $orderId);
    income_pay_pending_child_bonuses($userId);
}

/** Add approved-order or explicitly granted Self BV and propagate it using
 * the placement links themselves. This function must be called in a tx. */
function income_apply_self_bv($userId, $amount, $orderId = null, $sourceNote = 'Manual Self BV')
{
    // The caller holds the coordination row. Refresh even if a page read the
    // plan before acquiring it, so this whole event uses the committed plan.
    get_plan(true);
    $amount = round((float)$amount, 2);
    if ($amount < 0) { throw new RuntimeException('Negative Self BV cannot be applied as a purchase.'); }
    $buyer = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$userId]);
    if (!$buyer) { return false; }
    $newSelf = round((float)$buyer['self_bv'] + $amount, 2);
    q("UPDATE users SET self_bv = ? WHERE id = ?", [$newSelf, $userId]);
    q("INSERT INTO bv_logs (user_id, order_id, kind, bv, balance_after, note, created_at)
       VALUES (?, ?, 'self', ?, ?, ?, ?)", [$userId, $orderId, $amount, $newSelf, $sourceNote, commission_now()]);
    $buyer['self_bv'] = $newSelf;
    $threshold = (float)get_plan()['activation_bv'];
    if ($newSelf >= $threshold) {
        income_record_sponsor_event($buyer, $orderId);
        if ((int)$buyer['is_active'] !== 1) {
            q("UPDATE users SET is_active = 1, activated_at = COALESCE(activated_at, ?) WHERE id = ?", [now(), $userId]);
        }
    }
    income_settle_member($userId, $orderId);

    $seen = [(int)$userId => true];
    $leg = $buyer['leg'];
    $parentId = (int)$buyer['placement_id'];
    while ($parentId > 0) {
        if (isset($seen[$parentId])) { throw new RuntimeException('Placement cycle detected.'); }
        $seen[$parentId] = true;
        $ancestor = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$parentId]);
        if (!$ancestor || !in_array($leg, ['L', 'R'], true)) { throw new RuntimeException('Invalid placement tree.'); }
        $col = $leg === 'R' ? 'right_bv' : 'left_bv';
        $newBv = round((float)$ancestor[$col] + $amount, 2);
        q("UPDATE users SET $col = ? WHERE id = ?", [$newBv, $parentId]);
        q("INSERT INTO bv_logs (user_id, order_id, kind, bv, balance_after, note, created_at)
           VALUES (?, ?, ?, ?, ?, ?, ?)", [$parentId, $orderId, $leg === 'R' ? 'right' : 'left', $amount,
            $newBv, 'Team BV from ' . $buyer['username'], commission_now()]);
        run_binary_matching($parentId, $orderId);
        $leg = $ancestor['leg'];
        $parentId = (int)$ancestor['placement_id'];
    }
    return true;
}

/** Existing admin activation and unblock controls also settle deferred
 * earnings; a block never destroys previously earned income. */
function income_change_member_status($userId, $action)
{
    return db_tx(function () use ($userId, $action) {
        $ready = income_rules_ready(true);
        if (!$ready && $action !== 'block') { return [false, income_maintenance_message()]; }
        if ($ready) { get_plan(true); }
        $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$userId]);
        if (!$u) { return [false, 'Member not found.']; }
        if ($action === 'block') {
            q("UPDATE users SET status = 'blocked' WHERE id = ?", [$userId]);
        } elseif ($action === 'unblock') {
            q("UPDATE users SET status = 'active' WHERE id = ?", [$userId]);
            income_settle_member($userId);
        } elseif ($action === 'activate') {
            q("UPDATE users SET is_active = 1, activated_at = COALESCE(activated_at, ?) WHERE id = ?", [now(), $userId]);
            income_settle_member($userId);
        } else {
            return [false, 'Unknown action.'];
        }
        return [true, 'Member status updated. Pending eligible income has been processed.'];
    });
}
