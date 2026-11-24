<?php
/** Prospective green-ID sponsor and team-BV matching policy (v1.8.49).
 * Existing paid commissions are never rebuilt by this policy. All callers
 * which mutate these records run within the same transaction as the order. */

function income_policy_ready()
{
    return q_val("SELECT svalue FROM settings WHERE skey = 'income_rules_v1_8_49'") === 'ready';
}

/** Serialize all network/financial mutations in a consistent order. This
 * row lock is held only for the current database transaction. It prevents
 * two simultaneous activations/approvals or a deletion and a placement
 * change from observing and paying against different versions of a tree. */
function lock_network_mutation()
{
    if (!db()->inTransaction()) { throw new LogicException('Network lock requires a transaction.'); }
    $row = q_row("SELECT svalue FROM settings WHERE skey = 'income_rules_v1_8_49' FOR UPDATE");
    if (!$row || $row['svalue'] !== 'ready') {
        throw new RuntimeException('Run the database upgrade before changing the network or financial records.');
    }
    // The request may have displayed plan data before waiting for this lock.
    // Refresh inside the serialized transaction so a concurrent plan edit
    // cannot make an order or payout use stale rates.
    get_plan(true);
}

/** One-time upgrade: preserve the existing displayed team BV and mark
 * all previously completed pairs as processed, without paying past matches
 * or changing any commissions, payouts, points, or wallet balances. */
function initialize_income_policy_baseline()
{
    return db_tx(function () {
        // Inserting the marker first also serializes simultaneous upgrades.
        q("INSERT IGNORE INTO settings (skey, svalue) VALUES ('income_rules_v1_8_49', 'initializing')");
        $marker = q_row("SELECT svalue FROM settings WHERE skey = 'income_rules_v1_8_49' FOR UPDATE");
        if ($marker['svalue'] === 'ready') { return false; }
        $plan = get_plan();
        $unit = max(1, (float)$plan['pair_unit_bv']);
        $ppu = max(0, (int)$plan['points_per_unit']);
        $rows = q_all("SELECT id, left_bv, right_bv, reward_points
                       FROM users WHERE status <> 'deleted' ORDER BY id FOR UPDATE");
        $paid = [];
        foreach (q_all("SELECT user_id FROM commissions WHERE type = 'binary' AND status = 'credited' GROUP BY user_id") as $r) {
            $paid[(int)$r['user_id']] = true;
        }
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $left = (float)$r['left_bv']; $right = (float)$r['right_bv'];
            $existing = floor(min($left, $right) / $unit) * $unit;
            $processed = max($existing, isset($paid[$id]) ? $unit : 0);
            $oldPointBv = $ppu > 0 ? floor((int)$r['reward_points'] / $ppu) * $unit : 0;
            $pointBaseline = max($existing, $oldPointBv);
            q("INSERT INTO matching_state (user_id, left_eligible_bv, right_eligible_bv, processed_bv, points_processed_bv)
               VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE
               left_eligible_bv=VALUES(left_eligible_bv), right_eligible_bv=VALUES(right_eligible_bv),
               processed_bv=VALUES(processed_bv), points_processed_bv=VALUES(points_processed_bv)",
              [$id, $left, $right, $processed, $pointBaseline]);
        }
        save_setting('income_rules_v1_8_49', 'ready');
        return true;
    });
}

/** This row and the associated user row are locked for the transaction. */
function lock_matching_state($userId)
{
    q("INSERT IGNORE INTO matching_state (user_id) VALUES (?)", [(int)$userId]);
    return q_row("SELECT * FROM matching_state WHERE user_id = ? FOR UPDATE", [(int)$userId]);
}

/** Each new pair of team BV pays, irrespective of the contributing
 * members' sponsors or green status. Existing recipient-eligibility rules
 * are retained; pairs waiting for eligibility keep the rate at which they
 * were earned. Watermarks prevent duplicate payment after placement moves. */
function run_binary_matching($user, $orderId)
{
    $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$user['id']]);
    if (!$u || $u['status'] === 'deleted') { return 0.0; }
    $s = lock_matching_state($u['id']);
    $plan = get_plan();
    $unit = max(1, (float)$plan['pair_unit_bv']);
    $each = round($plan['binary_type'] === 'fixed' ? (float)$plan['binary_value'] : $unit * (float)$plan['binary_value'] / 100, 2);
    $matchable = min((float)$s['left_eligible_bv'], (float)$s['right_eligible_bv']);
    $newUnits = max(0, (int)floor(($matchable - (float)$s['processed_bv'] + 0.000001) / $unit));
    if ($newUnits) {
        $volume = $newUnits * $unit;
        $newAmount = round($newUnits * $each, 2);
        $firstBonus = (float)$s['pending_first_bonus'];
        $firstSponsorId = $s['pending_first_sponsor_id'];
        $firstSponsorLeg = $s['pending_first_sponsor_leg'];
        if ((int)$s['pending_pairs'] === 0) {
            $hadFirstPair = (int)q_val("SELECT COUNT(*) FROM commissions WHERE user_id = ? AND type = 'binary' AND status = 'credited'", [$u['id']]) > 0;
            $firstBonus = $hadFirstPair ? 0 : round($each * (float)($plan['sponsor_matching_percent'] ?? 0) / 100, 2);
            $firstSponsorId = null; $firstSponsorLeg = null;
            // A held first pair belongs to the sponsor in place when it was
            // earned, not a replacement sponsor assigned before settlement.
            $side = $u['leg'];
            foreach ($firstBonus > 0 ? ancestor_chain($u) : [] as $anc) {
                if ((int)$anc['id'] === (int)$u['sponsor_id']) {
                    $firstSponsorId = (int)$anc['id'];
                    $firstSponsorLeg = $side === 'R' ? 2 : 1;
                    break;
                }
                $side = $anc['leg'];
            }
        }
        q("UPDATE matching_state SET processed_bv = processed_bv + ?, pending_pairs = pending_pairs + ?,
           pending_bv = pending_bv + ?, pending_amount = pending_amount + ?, pending_first_bonus = ?,
           pending_first_sponsor_id = ?, pending_first_sponsor_leg = ? WHERE user_id = ?",
          [$volume, $newUnits, $volume, $newAmount, $firstBonus, $firstSponsorId, $firstSponsorLeg, $u['id']]);
        q("UPDATE users SET matched_pairs = matched_pairs + ? WHERE id = ?", [$newUnits, $u['id']]);
        $s['pending_pairs'] = (int)$s['pending_pairs'] + $newUnits;
        $s['pending_bv'] = (float)$s['pending_bv'] + $volume;
        $s['pending_amount'] = (float)$s['pending_amount'] + $newAmount;
        $s['pending_first_bonus'] = $firstBonus;
        $s['pending_first_sponsor_id'] = $firstSponsorId;
        $s['pending_first_sponsor_leg'] = $firstSponsorLeg;
    }
    if ($u['status'] !== 'active' || ((int)$plan['matching_requires_active'] && !(int)$u['is_active'])) { return 0.0; }
    $units = (int)$s['pending_pairs'];
    $income = round((float)$s['pending_amount'], 2);
    if (!$units || $income <= 0) { return 0.0; }
    $hadFirst = (int)q_val("SELECT COUNT(*) FROM commissions WHERE user_id = ? AND type = 'binary' AND status = 'credited'", [$u['id']]) > 0;
    add_commission($u['id'], $orderId, 'binary', $income, (float)$s['pending_bv'], 1,
        $units . ' matched pair(s) of team BV');
    q("UPDATE matching_state SET pending_pairs = 0, pending_bv = 0, pending_amount = 0, pending_first_bonus = 0,
       pending_first_sponsor_id = NULL, pending_first_sponsor_leg = NULL WHERE user_id = ?", [$u['id']]);

    // First-pair sponsor income is bound to the sponsor/leg at accrual.
    // A later sponsor/placement change cannot award it retroactively to a
    // replacement; a deleted or ineligible original sponsor cannot receive it.
    $bonus = round((float)$s['pending_first_bonus'], 2);
    $sponsorId = (int)$s['pending_first_sponsor_id'];
    if (!$hadFirst && $bonus > 0 && $sponsorId) {
        $sponsor = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$sponsorId]);
        if ($sponsor && $sponsor['status'] === 'active' && (int)$sponsor['is_active']) {
            add_commission($sponsor['id'], $orderId, 'sponsor_matching', $bonus, 0, 1,
                'First-pair bonus for direct member ' . $u['username'] . ' (' . ((int)$s['pending_first_sponsor_leg'] === 2 ? 'right' : 'left') . ')');
        }
    }
    return $income;
}

/** Points remain lifetime rewards, with an independent watermark so a
 * reparenting operation never creates retroactive points on a later order. */
function update_reward_points($userId, $orderId = null)
{
    $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$userId]);
    if (!$u || $u['status'] === 'deleted') { return 0; }
    $plan = get_plan();
    $unit = max(1, (float)$plan['pair_unit_bv']);
    $ppu = max(0, (int)$plan['points_per_unit']);
    $s = lock_matching_state($userId);
    $available = min((float)$u['left_bv'], (float)$u['right_bv']);
    $units = max(0, (int)floor(($available - (float)$s['points_processed_bv'] + 0.000001) / $unit));
    if ($units) {
        // Snapshot points at the rate earned, even for a recipient who is
        // not green or is blocked. Zero-point periods are watermarked too.
        $earned = $units * $ppu;
        q("UPDATE matching_state SET points_processed_bv = points_processed_bv + ?, pending_points = pending_points + ? WHERE user_id = ?",
          [$units * $unit, $earned, $userId]);
        $s['pending_points'] = (int)$s['pending_points'] + $earned;
    }
    if (!(int)$u['is_active'] || $u['status'] !== 'active') { return 0; }
    $add = (int)$s['pending_points'];
    if (!$add) { return 0; }
    $total = (int)$u['reward_points'] + $add;
    q("UPDATE matching_state SET pending_points = 0 WHERE user_id = ?", [$userId]);
    q("UPDATE users SET reward_points = ? WHERE id = ?", [$total, $userId]);
    q("INSERT INTO points_logs (user_id, order_id, points, total_points, left_bv, right_bv, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?)", [$userId, $orderId, $add, $total, $u['left_bv'], $u['right_bv'], commission_now()]);
    check_rank($userId, $orderId);
    return $add;
}

/** Create exactly one direct-sponsor event per newly green member, even
 * when that sponsor is not their placement parent or tree ancestor. */
function pay_direct_sponsor_leg_bonus(array $buyer, $orderId)
{
    $sponsorId = (int)$buyer['sponsor_id'];
    if (!$sponsorId || $sponsorId === (int)$buyer['id']) { return 0; }
    $plan = get_plan();
    $threshold = (float)$plan['activation_bv'];
    $amount = round($threshold * (float)$plan['sponsor_percent'] / 100, 2);
    if ($amount <= 0) { return 0; }
    $side = $buyer['leg']; $level = null;
    foreach (ancestor_chain($buyer) as $anc) {
        if ((int)$anc['id'] === $sponsorId) { $level = $side === 'R' ? 2 : 1; break; }
        $side = $anc['leg'];
    }
    q("INSERT IGNORE INTO sponsor_activation_bonuses (member_id, sponsor_id, order_id, amount, bv, leg, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?)", [$buyer['id'], $sponsorId, $orderId, $amount, $threshold, $level, commission_now()]);
    return settle_sponsor_activation_bonus((int)$buyer['id']);
}

function settle_sponsor_activation_bonus($memberId)
{
    $event = q_row("SELECT * FROM sponsor_activation_bonuses WHERE member_id = ? FOR UPDATE", [(int)$memberId]);
    if (!$event || $event['status'] !== 'pending') { return 0; }
    $sponsor = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$event['sponsor_id']]);
    if (!$sponsor || $sponsor['status'] !== 'active' || !(int)$sponsor['is_active']) { return 0; }
    $buyer = q_row("SELECT username FROM users WHERE id = ?", [(int)$memberId]);
    $cid = add_commission($sponsor['id'], $event['order_id'], 'sponsor', $event['amount'], $event['bv'], $event['leg'],
        'Direct member ' . ($buyer['username'] ?? ('#' . $memberId)) . ' became green');
    q("UPDATE sponsor_activation_bonuses SET status = 'paid', commission_id = ?, paid_at = ? WHERE member_id = ? AND status = 'pending'",
      [$cid, commission_now(), $memberId]);
    return 1;
}

function pay_pending_sponsor_legs($userId, $orderId = null)
{
    $events = q_all("SELECT member_id FROM sponsor_activation_bonuses WHERE sponsor_id = ? AND status = 'pending' ORDER BY member_id FOR UPDATE", [(int)$userId]);
    $paid = 0;
    foreach ($events as $event) { $paid += settle_sponsor_activation_bonus($event['member_id']); }
    return $paid;
}

/** Record approved team BV on an ancestor's leg before evaluating matching. */
function add_qualified_team_bv($userId, $leg, $amount)
{
    if ($amount <= 0) { return; }
    lock_matching_state($userId);
    $col = $leg === 'R' ? 'right_eligible_bv' : 'left_eligible_bv';
    q("UPDATE matching_state SET $col = $col + ? WHERE user_id = ?", [(float)$amount, (int)$userId]);
}

/** Apply the sponsor and recipient-eligibility effects of turning green. */
function mark_member_green(array $buyer, $orderId = null)
{
    q("UPDATE users SET is_active = 1, activated_at = COALESCE(activated_at, ?) WHERE id = ?", [commission_now(), $buyer['id']]);
    $buyer['is_active'] = 1;
    pay_direct_sponsor_leg_bonus($buyer, $orderId);
    pay_pending_sponsor_legs($buyer['id'], $orderId);
    run_binary_matching($buyer, $orderId);
    update_reward_points($buyer['id'], $orderId);
    return $buyer;
}

/** The superadmin's manual green-ID action must follow the same pipeline. */
function manually_activate_member($userId)
{
    if (!income_policy_ready()) { return [false, 'Run the database upgrade before activating members.']; }
    return db_tx(function () use ($userId) {
        lock_network_mutation();
        $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$userId]);
        if (!$u || $u['status'] !== 'active') { return [false, 'Only an active account can be activated.']; }
        if ((int)$u['is_active']) { return [false, 'This member is already green.']; }
        mark_member_green($u, null);
        return [true, 'Member activated; pending matching and direct sponsor income processed.'];
    });
}

/** Administrative bulk seeding explicitly supplies synthetic BV. It follows
 * the same activation/matching pipeline and may therefore create income; it
 * is not an approved customer order. */
function seed_member_business($userId, $selfBv, $activate)
{
    return db_tx(function () use ($userId, $selfBv, $activate) {
        lock_network_mutation();
        $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$userId]);
        if (!$u || $u['status'] !== 'active' || (float)$u['self_bv'] !== 0.0 || (int)$u['is_active']) {
            return [false, 'Only a new, inactive member can be seeded.'];
        }
        $amount = max(0, (float)$selfBv);
        $chain = ancestor_chain($u);
        if ($amount > 0) {
            q("UPDATE users SET self_bv = ? WHERE id = ?", [$amount, $userId]);
            q("INSERT INTO bv_logs (user_id, order_id, kind, bv, balance_after, note, created_at) VALUES (?, NULL, 'self', ?, ?, 'Superadmin seed BV (no order)', ?)", [$userId, $amount, $amount, now()]);
            $leg = $u['leg'];
            foreach ($chain as $anc) {
                $col = $leg === 'R' ? 'right_bv' : 'left_bv';
                q("UPDATE users SET $col = $col + ? WHERE id = ?", [$amount, $anc['id']]);
                $total = q_val("SELECT $col FROM users WHERE id = ?", [$anc['id']]);
                q("INSERT INTO bv_logs (user_id, order_id, kind, bv, balance_after, note, created_at) VALUES (?, NULL, ?, ?, ?, 'Superadmin seed BV (no order)', ?)",
                  [$anc['id'], $leg === 'R' ? 'right' : 'left', $amount, $total, now()]);
                add_qualified_team_bv($anc['id'], $leg, $amount);
                run_binary_matching($anc, null);
                update_reward_points($anc['id'], null);
                $leg = $anc['leg'];
            }
        }
        if ($activate || $amount >= (float)get_plan()['activation_bv']) {
            [$ok, $msg] = manually_activate_member($userId);
            if (!$ok) { throw new RuntimeException($msg); }
        }
        return [true, 'Seeded.'];
    });
}

/** Block/unblock atomically with deletion and settle eligible, previously
 * earned events once an already-green account becomes usable again. */
function set_member_account_status($userId, $status)
{
    if (!in_array($status, ['active', 'blocked'], true)) { return [false, 'Invalid account status.']; }
    if (!income_policy_ready()) { return [false, 'Run the database upgrade before changing member status.']; }
    return db_tx(function () use ($userId, $status) {
        lock_network_mutation();
        $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$userId]);
        if (!$u || $u['status'] === 'deleted') { return [false, 'Member not found or already deleted.']; }
        q("UPDATE users SET status = ? WHERE id = ?", [$status, $u['id']]);
        if ($status === 'active' && (int)$u['is_active']) {
            $u['status'] = 'active';
            pay_pending_sponsor_legs($u['id']);
            run_binary_matching($u, null);
            update_reward_points($u['id']);
        }
        return [true, 'User ' . $u['username'] . ($status === 'active' ? ' unblocked.' : ' blocked.')];
    });
}
