<?php
/**
 * =====================================================================
 *  MLM ENGINE
 * ---------------------------------------------------------------------
 *  Binary tree (left / right placement) with:
 *   - Leg completion bonus             : Rs 300 once when the LEFT leg's
 *                                        total team business completes
 *                                        3,000 BV, Rs 300 once for the
 *                                        RIGHT leg (max Rs 600 / member)
 *   - Binary pair matching income      : first matching pair only
 *                                        (3,000 + 3,000 BV = Rs 450),
 *                                        further pairs add POINTS
 *   - 50% direct sponsor bonus         : Rs 225 when a direct member
 *                                        completes their first pair
 *   - Points / ranks / awards          : 1 point per matched pair unit
 *   - E-wallet, commissions & payouts
 *
 *  Every amount/rate above is configurable from the Super Admin panel
 *  (plan settings, rank table, award rewards).
 * =====================================================================
 */

/* ------------------------------------------------------------------ */
/*  Plan settings (cached)                                             */
/* ------------------------------------------------------------------ */

function get_plan()
{
    static $plan = null;
    if ($plan === null) {
        $plan = q_row("SELECT * FROM plan_settings WHERE id = 1");
        if (!$plan) {
            $plan = [
                'id' => 1, 'activation_bv' => 500, 'sponsor_percent' => 10,
                'pair_unit_bv' => 3000, 'binary_type' => 'fixed', 'binary_value' => 450,
                'level_depth' => 0, 'daily_cap' => 320000, 'monthly_cap' => 800000,
                'carry_forward' => 1,
                'matching_requires_active' => 1, 'level_requires_active' => 1,
                'sponsor_matching_percent' => 50, 'points_per_unit' => 1, 'point_bv' => 400,
                'car_fund_points' => 500, 'car_fund_amount' => 150000,
                'tds_percent' => 5, 'admin_charge_percent' => 7, 'payout_min' => 500,
            ];
        }
    }
    return $plan;
}

function get_plan_levels()
{
    static $levels = null;
    if ($levels === null) {
        $levels = [];
        foreach (q_all("SELECT * FROM plan_levels ORDER BY level_no") as $l) {
            $levels[(int)$l['level_no']] = (float)$l['percent'];
        }
    }
    return $levels;
}

function get_ranks()
{
    static $ranks = null;
    if ($ranks === null) {
        $ranks = q_all("SELECT * FROM ranks ORDER BY min_team_bv ASC");
    }
    return $ranks;
}

/* ------------------------------------------------------------------ */
/*  Transaction helper                                                 */
/* ------------------------------------------------------------------ */

function db_tx($fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn($pdo); // nested: join the outer transaction
    }
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ------------------------------------------------------------------ */
/*  Tree helpers                                                       */
/* ------------------------------------------------------------------ */

/** Load a user row (or null) by id / username / member id. */
function find_user($idOrName)
{
    if ($idOrName === '' || $idOrName === null) {
        return null;
    }
    if (ctype_digit((string)$idOrName)) {
        return q_row("SELECT * FROM users WHERE id = ? OR username = ? LIMIT 1", [$idOrName, strtoupper($idOrName)]);
    }
    return q_row("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1", [$idOrName, $idOrName]);
}

/** Children (direct placements) of a user: ['L' => row|null, 'R' => row|null]. */
function user_children($userId)
{
    $rows = q_all("SELECT * FROM users WHERE placement_id = ?", [$userId]);
    $out = ['L' => null, 'R' => null];
    foreach ($rows as $r) {
        $out[$r['leg']] = $r;
    }
    return $out;
}

/**
 * Find the first free position in the given leg below $sponsorId.
 * If the sponsor's leg slot is free the sponsor is returned directly,
 * otherwise the search walks down that leg (spillover) level by level.
 * Returns [placementUserId, leg] or null.
 */
/**
 * Find the position for a new member in the given leg below $sponsorId.
 * SAME-LEG spillover: if the sponsor's leg slot is free the sponsor is
 * returned directly; otherwise the search walks straight down that leg —
 * a member added to the LEFT leg always lands in the left-most free LEFT
 * slot (never in a right position of some level) and vice versa, so each
 * leg grows as one straight line on its extreme side of the tree.
 * Returns [placementUserId, leg] or null.
 */
function find_position($sponsorId, $leg)
{
    $leg = ($leg === 'R') ? 'R' : 'L';
    $children = user_children($sponsorId);
    if ($children[$leg] === null) {
        return [$sponsorId, $leg];
    }
    // walk straight down the same leg to the deepest free same-leg slot
    $node = $children[$leg];
    $seen = 0;
    while ($seen < 100000) {
        $seen++;
        $ch = user_children($node['id']);
        if ($ch[$leg] === null) {
            return [$node['id'], $leg];
        }
        $node = $ch[$leg];
    }
    return null;
}

/** All ancestors of a user (nearest first), with their leg relative to their own parent. */
function ancestor_chain($user)
{
    $ids = array_filter(array_map('intval', explode('/', $user['path'])));
    $ids = array_diff($ids, [(int)$user['id']]); // exclude self
    if (!$ids) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = q_all("SELECT * FROM users WHERE id IN ($ph) AND status != 'deleted' ORDER BY depth DESC", array_values($ids));
    return $rows; // deepest (nearest) first
}

/** Count of members in the left / right subtree of a user. */
function team_counts($user)
{
    $out = ['L' => 0, 'R' => 0];
    $ch = user_children($user['id']);
    foreach (['L', 'R'] as $side) {
        if ($ch[$side]) {
            $prefix = str_replace(['%', '_'], ['\%', '\_'], $ch[$side]['path']);
            $out[$side] = (int)q_val(
                "SELECT COUNT(*) FROM users WHERE (path LIKE ? OR id = ?)",
                [$prefix . '%', $ch[$side]['id']]
            );
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/*  Registration                                                       */
/* ------------------------------------------------------------------ */

/**
 * Create a new distributor.
 * $data keys: sponsor (id/username), leg (L/R), full_name, email, mobile,
 *             password, dob, marital_status, nationality, state, city,
 *             pincode, address, nominee_name, nominee_relation,
 *             bank_holder, bank_account_no, bank_ifsc, bank_name,
 *             bank_branch, aadhaar_no, pan_no
 *
 * Returns [ok(bool), userId|int, error|string].
 */
function register_distributor($data)
{
    return db_tx(function () use ($data) {
        $sponsor = find_user($data['sponsor']);
        if (!$sponsor) {
            return [false, 0, 'Sponsor ID not found.'];
        }
        if ($sponsor['status'] !== 'active') {
            return [false, 0, 'Sponsor account is not active.'];
        }

        /* NOTE: email and mobile are no longer unique — one family may share
         * contact details across several distributor IDs. */

        /* explicit placement (bulk tools) or the standard spillover search */
        $placementId = null; $leg = null;
        if (!empty($data['placement'])) {
            $puser = ctype_digit((string)$data['placement'])
                ? q_row("SELECT * FROM users WHERE id = ?", [(int)$data['placement']])
                : find_user((string)$data['placement']);
            $pleg = (($data['placement_leg'] ?? $data['leg']) === 'R') ? 'R' : 'L';
            if ($puser) {
                $ch = user_children($puser['id']);
                if ($ch[$pleg] === null) {
                    $placementId = (int)$puser['id'];
                    $leg = $pleg;
                }
            }
        }
        if (!$placementId) {
            $pos = find_position($sponsor['id'], $data['leg']);
            if (!$pos) {
                return [false, 0, 'No free position available under this sponsor.'];
            }
            [$placementId, $leg] = $pos;
        }

        $parent = q_row("SELECT * FROM users WHERE id = ?", [$placementId]);

        // insert
        q("INSERT INTO users
           (username, password, full_name, email, mobile, dob, marital_status, nationality,
            address, city, state, pincode, nominee_name, nominee_relation,
            bank_holder, bank_account_no, bank_ifsc, bank_name, bank_branch,
            aadhaar_no, pan_no, pan_image, aadhaar_image, sponsor_id, placement_id, leg, status, kyc_status,
            left_bv, right_bv, self_bv, matched_pairs, wallet_balance, path, created_at)
           VALUES ('', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 'pending',
                   0, 0, 0, 0, 0, '/', ?)",
           [
               password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]),
               $data['full_name'], $data['email'], $data['mobile'],
               $data['dob'] ?: null, $data['marital_status'], $data['nationality'],
               $data['address'], $data['city'], $data['state'], $data['pincode'],
               $data['nominee_name'], $data['nominee_relation'],
               $data['bank_holder'], $data['bank_account_no'], $data['bank_ifsc'],
               $data['bank_name'], $data['bank_branch'],
               $data['aadhaar_no'], $data['pan_no'],
               $data['pan_image'] ?? null, $data['aadhaar_image'] ?? null,
               $sponsor['id'], $placementId, $leg, now(),
           ]);

        $id = (int)db()->lastInsertId();
        $username = 'YSH' . (100000 + $id);
        $path = $parent['path'] . $id . '/';
        q("UPDATE users SET username = ?, path = ?, depth = ? WHERE id = ?",
          [$username, $path, (int)$parent['depth'] + 1, $id]);

        return [true, $id, $username];
    });
}

/* ------------------------------------------------------------------ */
/*  Wallet                                                             */
/* ------------------------------------------------------------------ */

/**
 * Credit a user's wallet inside the current transaction.
 * Returns the new balance.
 */
/** Timestamp used for new commission / wallet rows and for daily-cap
 *  windows. During a full recalculation (recalculate_all_incomes) it is
 *  set to the replayed order's approval time, so daily / monthly caps
 *  apply to each order's ORIGINAL day instead of "today". */
function commission_now()
{
    return (!empty($GLOBALS['__commission_clock'])) ? $GLOBALS['__commission_clock'] : now();
}

function credit_wallet($userId, $amount, $refType, $refId, $note)
{
    $amount = round((float)$amount, 2);
    if ($amount <= 0) {
        return null;
    }
    $u = q_row("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE", [$userId]);
    $newBal = round((float)$u['wallet_balance'] + $amount, 2);
    q("UPDATE users SET wallet_balance = ?, total_earned = total_earned + ? WHERE id = ?",
      [$newBal, $amount, $userId]);
    q("INSERT INTO wallet_transactions (user_id, type, amount, balance_after, ref_type, ref_id, note, created_at)
       VALUES (?, 'credit', ?, ?, ?, ?, ?, ?)",
      [$userId, $amount, $newBal, $refType, $refId, $note, commission_now()]);
    return $newBal;
}

/** Debit a user's wallet. Returns new balance or null when insufficient. */
function debit_wallet($userId, $amount, $refType, $refId, $note)
{
    $amount = round((float)$amount, 2);
    if ($amount <= 0) {
        return null;
    }
    $u = q_row("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE", [$userId]);
    if ((float)$u['wallet_balance'] < $amount) {
        return null;
    }
    $newBal = round((float)$u['wallet_balance'] - $amount, 2);
    q("UPDATE users SET wallet_balance = ? WHERE id = ?", [$newBal, $userId]);
    q("INSERT INTO wallet_transactions (user_id, type, amount, balance_after, ref_type, ref_id, note, created_at)
       VALUES (?, 'debit', ?, ?, ?, ?, ?, ?)",
      [$userId, $amount, $newBal, $refType, $refId, $note, now()]);
    return $newBal;
}

/** Record a commission row and credit the wallet. */
function add_commission($userId, $orderId, $type, $amount, $bvAmount, $level = null, $note = '')
{
    q("INSERT INTO commissions (user_id, order_id, type, amount, bv, level, note, status, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, 'credited', ?)",
      [$userId, $orderId, $type, round((float)$amount, 2), round((float)$bvAmount, 2), $level, $note, commission_now()]);
    $cid = (int)db()->lastInsertId();
    credit_wallet($userId, $amount, 'commission', $cid, ucfirst($type) . ' income' . ($note ? ' — ' . $note : ''));
    return $cid;
}

/* ------------------------------------------------------------------ */
/*  Ranks                                                              */
/* ------------------------------------------------------------------ */

/**
 * Award & rewards: every award_rewards row whose LIFETIME points
 * threshold is reached is awarded once (tracked via commissions.level =
 * reward id). Points = matched pair points (3,000 BV left + 3,000 BV
 * right per point) — self BV is never counted. Cash awards are wallet
 * income only, NOT part of the member's payout commission.
 */
function check_award_rewards($userId, $orderId = null)
{
    $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$userId]);
    if (!$u || $u['status'] !== 'active') {
        return;
    }
    foreach (q_all("SELECT * FROM award_rewards WHERE status = 'active' ORDER BY points ASC") as $a) {
        if ((int)$u['reward_points'] < (int)$a['points']) {
            continue;
        }
        $already = (int)q_val(
            "SELECT COUNT(*) FROM commissions WHERE user_id = ? AND type = 'award' AND level = ? AND status = 'credited'",
            [$userId, (int)$a['id']]
        );
        if ($already) {
            continue;
        }
        $amt = $a['reward_type'] === 'cash' ? (float)$a['amount'] : 0.0;
        add_commission($userId, $orderId, 'award', $amt, (int)$u['reward_points'], (int)$a['id'],
            'Award reward: ' . $a['reward_title'] . ' (' . (int)$a['points'] . ' lifetime points)');
    }
}

function check_rank($userId, $orderId = null, $withReward = true)
{
    $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$userId]);
    if (!$u) {
        return null;
    }
    /* rank = highest rank whose LIFETIME points threshold is reached
     * (1 point = 3,000 BV matched left + right; self BV never counts) */
    $best = null;
    foreach (q_all("SELECT * FROM ranks ORDER BY min_points ASC, id ASC") as $r) {
        if ((int)$r['min_points'] > 0 && (int)$u['reward_points'] >= (int)$r['min_points']) {
            $best = $r;
        }
    }
    if (!$best) {
        return null;
    }
    if ((int)$u['rank_id'] >= (int)$best['id']) {
        return null; // already at or above this rank
    }
    q("UPDATE users SET rank_id = ? WHERE id = ?", [$best['id'], $userId]);
    if ($withReward && (float)$best['reward_amount'] > 0) {
        add_commission($userId, $orderId, 'rank', $best['reward_amount'], (int)$u['reward_points'], null,
            'Rank achievement: ' . $best['name'] . ' (' . (int)$best['min_points'] . ' points)');
    }
    return $best;
}

/* ------------------------------------------------------------------ */
/*  Binary matching                                                    */
/* ------------------------------------------------------------------ */

/**
 * Run pair matching for a user after their leg BV changed.
 * Pays income for newly matched pair units (weaker leg), respects the
 * daily cap and carries the remaining units forward.
 */
function run_binary_matching($user, $orderId)
{
    $plan = get_plan();
    $unit = max(1, (float)$plan['pair_unit_bv']);
    $left = (float)$user['left_bv'];
    $right = (float)$user['right_bv'];
    $unitsAvailable = (int)floor(min($left, $right) / $unit);
    $newUnits = $unitsAvailable - (int)$user['matched_pairs'];
    if ($newUnits <= 0) {
        return 0.0;
    }
    /* every matched unit is COUNTED, but the matching commission is paid
     * only ONCE — for the member's FIRST matching pair (₹450). Further
     * pairs add POINTS only (see update_reward_points). */
    q("UPDATE users SET matched_pairs = matched_pairs + ? WHERE id = ?", [$newUnits, $user['id']]);

    if ((int)$plan['matching_requires_active'] === 1 && (int)$user['is_active'] !== 1) {
        return 0.0; // inactive members match pairs but are not paid
    }
    if ((int)$user['matched_pairs'] > 0) {
        return 0.0; // first pair already paid — points only from here
    }
    if ($plan['binary_type'] === 'fixed') {
        $income = round((float)$plan['binary_value'], 2);
    } else {
        $income = round($unit * ((float)$plan['binary_value'] / 100), 2);
    }
    if ($income <= 0) {
        return 0.0;
    }
    add_commission($user['id'], $orderId, 'binary', $income, $unit, 1,
        'First matching pair: ' . bv($left) . ' left + ' . bv($right) . ' right');

    /* 50% Direct Sponsor Bonus: the DIRECT sponsor (L1) of the member who
       just completed their first matching pair receives 50% (₹225). Paid
       once per direct member (their first pair is the only paid pair). */
    $smPct = isset($plan['sponsor_matching_percent']) ? (float)$plan['sponsor_matching_percent'] : 0.0;
    if ($smPct > 0 && $user['sponsor_id']) {
        $sponsor = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$user['sponsor_id']]);
        $smAmt = round($income * $smPct / 100, 2);
        if ($sponsor && $smAmt > 0 && $sponsor['status'] === 'active' && (int)$sponsor['is_active'] === 1) {
            add_commission($sponsor['id'], $orderId, 'sponsor_matching', $smAmt, 0, 1,
                $smPct . '% bonus — direct ' . $user['username'] . ' completed the first matching pair');
        }
    }
    return $income;
}

/**
 * Points: 1 point per matched pair unit (3,000 BV left + 3,000 BV right).
 * Points are added ONLY to members who completed their own Self 3,000 BV
 * (rule: self BV is never counted in any calculation — it only makes the
 * member eligible for commission and points). Every points change is
 * logged (points_logs) and drives the rank cash rewards and the award
 * milestones from the MLM points PDF.
 *
 * $withRewards = false (payout recalculation) updates points and rank
 * TITLES only — rank/award reward money is not part of the payout rebuild.
 */
function update_reward_points($userId, $orderId = null, $withRewards = true)
{
    $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$userId]);
    if (!$u || (int)$u['is_active'] !== 1 || $u['status'] !== 'active') {
        return 0;   // needs Self 3,000 BV completed
    }
    $plan = get_plan();
    $unit = max(1, (float)$plan['pair_unit_bv']);
    $ppu = max(0, (int)(isset($plan['points_per_unit']) ? $plan['points_per_unit'] : 1));
    if ($ppu <= 0) {
        return 0;
    }
    $target = (int)floor(min((float)$u['left_bv'], (float)$u['right_bv']) / $unit) * $ppu;
    $cur = (int)$u['reward_points'];
    if ($target <= $cur) {
        return 0;
    }
    $add = $target - $cur;
    q("UPDATE users SET reward_points = ? WHERE id = ?", [$target, $userId]);
    q("INSERT INTO points_logs (user_id, order_id, points, total_points, left_bv, right_bv, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?)",
      [$userId, $orderId, $add, $target, (float)$u['left_bv'], (float)$u['right_bv'], commission_now()]);
    check_rank($userId, $orderId, $withRewards);
    if ($withRewards) {
        check_award_rewards($userId, $orderId);
    }
    return $add;
}

/**
 * Left / Right Leg Completion Bonus: Rs 300 is paid ONCE per leg when
 * that leg's TOTAL team business — every downline member in the leg, at
 * any depth — first completes the joining threshold (3,000 BV). Maximum
 * Rs 600 per member: one payment for the left leg, one for the right.
 * Called from the placement-tree loop with the leg's BV before and after
 * this order, so the payment fires exactly at the crossing order.
 */
function pay_leg_completion_bonus(array $user, $legCol, $preBv, $orderId, array $plan)
{
    $threshold = (float)$plan['activation_bv'];
    $amt = round($threshold * (float)$plan['sponsor_percent'] / 100, 2);
    if ($amt <= 0 || $preBv >= $threshold || (float)$user[$legCol] < $threshold) {
        return false; // leg already crossed earlier, or still below the threshold
    }
    $lvl = ($legCol === 'right_bv') ? 2 : 1; // commissions.level: 1 = left leg, 2 = right leg
    $already = (int)q_val(
        "SELECT COUNT(*) FROM commissions WHERE type = 'sponsor' AND user_id = ? AND level = ?",
        [$user['id'], $lvl]
    );
    if ($already || $user['status'] !== 'active' || (int)$user['is_active'] !== 1) {
        return false; // already paid for this leg, or member not yet eligible
    }
    add_commission($user['id'], $orderId, 'sponsor', $amt, $threshold, $lvl,
        ($lvl === 2 ? 'Right' : 'Left') . ' leg completed ' . bv($threshold) . ' — one-time leg bonus');
    return true;
}

/**
 * Leg Completion Bonus catch-up: if a leg completed 3,000 BV while the
 * member was not yet eligible (own 3,000 BV joining not complete), the
 * pending Rs 300 per completed leg is paid as soon as the member
 * activates. Maximum one left + one right payment, ever.
 */
function pay_pending_leg_commissions($userId, $orderId = null)
{
    $plan = get_plan();
    $threshold = (float)$plan['activation_bv'];
    $amt = round($threshold * (float)$plan['sponsor_percent'] / 100, 2);
    if ($amt <= 0) {
        return 0;
    }
    $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$userId]);
    if (!$u) {
        return 0;
    }
    $paid = 0;
    foreach ([1 => 'left_bv', 2 => 'right_bv'] as $lvl => $col) {
        if ((float)$u[$col] < $threshold) {
            continue;
        }
        $already = (int)q_val(
            "SELECT COUNT(*) FROM commissions WHERE type = 'sponsor' AND user_id = ? AND level = ?",
            [$userId, $lvl]
        );
        if ($already) {
            continue;
        }
        add_commission($userId, $orderId, 'sponsor', $amt, $threshold, $lvl,
            ($lvl === 2 ? 'Right' : 'Left') . ' leg completed ' . bv($threshold) . ' — one-time leg bonus');
        $paid++;
    }
    return $paid;
}

/* ------------------------------------------------------------------ */
/*  Order approval — the commission pipeline                           */
/* ------------------------------------------------------------------ */

/**
 * Approve a pending order: credit BV, activate the buyer, propagate BV
 * up the binary tree, pay sponsor / level / matching income and check
 * ranks. Everything runs in a single transaction.
 *
 * Returns [ok(bool), message].
 */
/**
 * Move a member (with their entire downline) to a new position in the
 * binary tree. The new slot is found with the same spillover search as
 * registration: the first free position under the chosen target + leg.
 *
 * Updates placement_id / leg and rewrites path + depth for the member and
 * every descendant. Leg BV counters of the affected upline chains are
 * rebalanced (the team's confirmed BV moves with it). Sponsor (introducer),
 * orders, wallet and already-paid commissions are untouched.
 */
function move_user_position($userId, $targetId, $leg, $adminId)
{
    return db_tx(function () use ($userId, $targetId, $leg, $adminId) {
        $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$userId]);
        if (!$u) {
            return [false, 'Member not found.'];
        }
        if ((int)$u['placement_id'] === 0 || $u['path'] === '/') {
            return [false, 'The company root cannot be moved.'];
        }
        $target = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$targetId]);
        if (!$target) {
            return [false, 'Target member not found.'];
        }
        if ($target['status'] !== 'active') {
            return [false, 'Target account is not active.'];
        }
        if ((int)$target['id'] === (int)$u['id']) {
            return [false, 'A member cannot be placed under themselves.'];
        }
        if (strpos($target['path'], $u['path']) === 0) {
            return [false, 'Target is inside the member\'s own downline — that would create a loop.'];
        }
        $leg = ($leg === 'R') ? 'R' : 'L';
        if ((int)$target['id'] === (int)$u['placement_id'] && $leg === $u['leg']) {
            return [false, 'The member is already directly at this position.'];
        }

        $pos = find_position((int)$target['id'], $leg);
        if (!$pos) {
            return [false, 'No free position available under this target.'];
        }
        [$newParentId, $newLeg] = $pos;
        $newParent = q_row("SELECT * FROM users WHERE id = ?", [$newParentId]);
        if (!$newParent) {
            return [false, 'Position error.'];
        }
        if ((int)$newParent['id'] === (int)$u['id'] || strpos($newParent['path'], $u['path']) === 0) {
            return [false, 'The free position under this target falls inside the member\'s own downline — choose another target or leg.'];
        }

        $oldPath = $u['path'];
        $newPath = $newParent['path'] . $u['id'] . '/';

        /* path is VARCHAR(255) — make sure the deepest descendant still fits */
        $maxLen = (float)q_val("SELECT COALESCE(MAX(LENGTH(path)), 0) FROM users WHERE path LIKE ?", [$oldPath . '%']);
        if (strlen($newPath) + ((float)$maxLen - strlen($oldPath)) > 255) {
            return [false, 'The member\'s downline is too deep to move to this position.'];
        }

        /* total confirmed BV that relocates with the subtree */
        $bvDelta = (float)q_val("SELECT COALESCE(SUM(self_bv), 0) FROM users WHERE path LIKE ?", [$oldPath . '%']);

        /* old upline chain: [ancestorId, leg that leads down to the member] */
        $oldChain = [];
        $cur = $u;
        while ($cur && (int)$cur['placement_id'] > 0) {
            $oldChain[] = [(int)$cur['placement_id'], $cur['leg']];
            $cur = q_row("SELECT id, placement_id, leg FROM users WHERE id = ?", [(int)$cur['placement_id']]);
        }

        /* 1) rewrite paths of the member and their whole downline */
        q("UPDATE users SET path = CONCAT(?, SUBSTRING(path, ?)) WHERE path LIKE ?",
          [$newPath, strlen($oldPath) + 1, $oldPath . '%']);
        /* 2) shift depths by the level difference */
        $depthDelta = ((int)$newParent['depth'] + 1) - (int)$u['depth'];
        if ($depthDelta !== 0) {
            q("UPDATE users SET depth = depth + ? WHERE path LIKE ?", [$depthDelta, $newPath . '%']);
        }
        /* 3) new placement + leg */
        q("UPDATE users SET placement_id = ?, leg = ? WHERE id = ?", [(int)$newParent['id'], $newLeg, (int)$u['id']]);

        /* 4) rebalance leg BV: take it off the old chain, add it to the new chain */
        foreach ($oldChain as $step) {
            $col = $step[1] === 'R' ? 'right_bv' : 'left_bv';
            q("UPDATE users SET $col = GREATEST(0, $col - ?) WHERE id = ?", [$bvDelta, $step[0]]);
        }
        $parent = q_row("SELECT id, placement_id, leg FROM users WHERE id = ?", [(int)$newParent['id']]);
        $legFrom = $newLeg;
        while ($parent && (int)$parent['id'] > 0) {
            $col = $legFrom === 'R' ? 'right_bv' : 'left_bv';
            q("UPDATE users SET $col = $col + ? WHERE id = ?", [$bvDelta, (int)$parent['id']]);
            $legFrom = $parent['leg'];
            $parent = (int)$parent['placement_id'] > 0
                ? q_row("SELECT id, placement_id, leg FROM users WHERE id = ?", [(int)$parent['placement_id']])
                : null;
        }

        return [true, $u['username'] . ' moved under ' . $newParent['username'] . ' (' . ($newLeg === 'R' ? 'RIGHT' : 'LEFT') . ' leg)'
            . ($bvDelta > 0 ? ' — ' . number_format($bvDelta, 0) . ' BV relocated with the team.' : '.')];
    });
}

/**
 * Single-action approval: authorizes the payment (marks it received/paid)
 * and then approves + delivers the order in one go.
 */
function authorize_and_approve_order($orderId, $adminId)
{
    $order = q_row("SELECT * FROM orders WHERE id = ?", [$orderId]);
    if (!$order) {
        return [false, 'Order not found.'];
    }
    if ($order['status'] !== 'pending') {
        return [false, 'Order is not pending (current status: ' . $order['status'] . ').'];
    }
    $authorized = false;
    if ($order['payment_status'] !== 'paid') {
        q("UPDATE orders SET payment_status = 'paid' WHERE id = ?", [$orderId]);
        $authorized = true;
    }
    $res = approve_order($orderId, $adminId);
    if ($res[0] && $authorized) {
        $res[1] = 'Payment authorized. ' . $res[1];
    }
    return $res;
}

function approve_order($orderId, $adminId)
{
    return db_tx(function () use ($orderId, $adminId) {
        $order = q_row("SELECT * FROM orders WHERE id = ? FOR UPDATE", [$orderId]);
        if (!$order) {
            return [false, 'Order not found.'];
        }
        if ($order['status'] !== 'pending') {
            return [false, 'Order is not pending (current status: ' . $order['status'] . ').'];
        }
        if ($order['payment_mode'] === 'cash' && $order['payment_status'] !== 'paid') {
            return [false, 'Cash payment has not been authorized yet — use the “Authorize Payment” button first.'];
        }

        /* 1-5) business effects: BV, activation, sponsor + level income,
         *      binary matching, ranks & special rewards (shared with the
         *      full plan recalculation) */
        if (!apply_order_business_effects($order)) {
            return [false, 'Buyer account missing.'];
        }

        /* 6) finalize order + stock */
        q("UPDATE orders SET status = 'approved', payment_status = 'paid',
           approved_by = ?, approved_at = ?, remark = CONCAT(IFNULL(remark,''), ?) WHERE id = ?",
          [$adminId, now(), '', $order['id']]);
        q("UPDATE products p
           JOIN order_items oi ON oi.product_id = p.id AND oi.order_id = ?
           SET p.stock = GREATEST(0, p.stock - oi.qty)", [$order['id']]);

        return [true, 'Order approved. Commissions have been processed.'];
    });
}

/**
 * Core business effects of an approved order: self BV + activation,
 * direct sponsor bonus, level income, BV propagation up the placement
 * tree with binary matching, and rank / special-reward checks.
 * Shared by approve_order() and the full plan recalculation
 * (recalculate_all_incomes()); payment status, order status and stock
 * are the caller's business. Returns false when the buyer is missing.
 */
/**
 * The commission pipeline of an approved order.
 *
 * The plan (all amounts one-time, per the official rules):
 *  1. Self BV of the buyer grows (dashboard display + eligibility only —
 *     self BV is never counted in any commission or points calculation).
 *     Completing Self 3,000 BV makes the member eligible for commission
 *     and points.
 *  2. Leg Completion Bonus ₹300 — paid ONCE per leg (left and right),
 *     when that leg's TOTAL team business — all downline members in the
 *     leg, at any depth — first completes 3,000 BV. Max ₹600 / member.
 *  3. BV propagates up the placement tree (logged in bv_logs).
 *  4. Matching Bonus ₹450 — paid ONCE, for the member's FIRST matching
 *     pair (3,000 BV left + 3,000 BV right). Further pairs add POINTS.
 *  5. 50% Direct Sponsor Bonus ₹225 — paid ONCE to the DIRECT sponsor
 *     when their direct member completes the first matching pair.
 *  6. Points: +1 per matched 3,000 BV pair (left + right), logged and
 *     driving the rank cash rewards and award milestones.
 *
 * There is NO level income, NO car fund and NO other commission.
 *
 * $payoutOnly = true replays an order for the payout recalculation:
 * rank/award reward money is not created (wallet income only — never part
 * of a member's payout commission); points, logs and rank titles are.
 */
function apply_order_business_effects(array $order, $payoutOnly = false)
{
    $totalBv = (float)$order['total_bv'];
    $buyer = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$order['user_id']]);
    if (!$buyer) {
        return false;
    }
    $plan = get_plan();

    /* 1) self BV (display + eligibility) + activation at 3,000 BV */
    q("UPDATE users SET self_bv = self_bv + ? WHERE id = ?", [$totalBv, $buyer['id']]);
    $buyer['self_bv'] = (float)$buyer['self_bv'] + $totalBv;
    q("INSERT INTO bv_logs (user_id, order_id, kind, bv, balance_after, note, created_at)
       VALUES (?, ?, 'self', ?, ?, ?, ?)",
      [$buyer['id'], $order['id'], $totalBv, $buyer['self_bv'],
       'Purchase ' . ($order['order_no'] ?? '') . ' — self BV', commission_now()]);
    if ((int)$buyer['is_active'] !== 1 && $buyer['self_bv'] >= (float)$plan['activation_bv']) {
        q("UPDATE users SET is_active = 1, activated_at = ? WHERE id = ?", [now(), $buyer['id']]);
        $buyer['is_active'] = 1;
        /* the buyer is now eligible: pay any ₹300 leg bonuses pending from
         * legs that completed 3,000 BV earlier, and grant points for
         * already-matched leg BV (retroactive eligibility) */
        pay_pending_leg_commissions($buyer['id'], $order['id']);
        update_reward_points($buyer['id'], $order['id'], !$payoutOnly);
    }

    /* 2) propagate BV up the placement tree (logged) + leg bonus +
     *    matching + points — the ₹300 leg bonus fires when an ancestor's
     *    left or right leg first completes 3,000 BV total team business */
    $chain = ancestor_chain($buyer); // nearest first
    $leg = $buyer['leg'];            // leg of buyer relative to its parent
    foreach ($chain as $anc) {
        $col = ($leg === 'R') ? 'right_bv' : 'left_bv';
        $preLegBv = (float)$anc[$col];
        q("UPDATE users SET $col = $col + ? WHERE id = ?", [$totalBv, $anc['id']]);
        $anc[$col] = $preLegBv + $totalBv;
        q("INSERT INTO bv_logs (user_id, order_id, kind, bv, balance_after, note, created_at)
           VALUES (?, ?, ?, ?, ?, ?, ?)",
          [$anc['id'], $order['id'], $leg === 'R' ? 'right' : 'left', $totalBv, $anc[$col],
           'Team BV from ' . $buyer['username'] . ' (' . ($leg === 'R' ? 'right' : 'left') . ' leg)', commission_now()]);
        pay_leg_completion_bonus($anc, $col, $preLegBv, $order['id'], $plan);
        run_binary_matching($anc, $order['id']);
        update_reward_points($anc['id'], $order['id'], !$payoutOnly);
        $leg = $anc['leg']; // leg of this ancestor relative to its own parent
    }

    return true;
}

/**
 * Recalculate the Total Income and BV of ALL members from the approved
 * order history using the CURRENT plan settings. Run from the MLM plan
 * settings page after the admin changes any plan value.
 *
 * PAYOUT COMMISSIONS ONLY: the rebuild generates exactly the three
 * payout incomes — Direct Sponsor, binary Matching Bonus and the 50%
 * Direct Sponsor Bonus. Level income, rank rewards, car fund and award
 * rewards are NOT part of a member's payout commission: any old entries
 * are cleared and NOT rebuilt. Rank TITLES are still recomputed (no
 * reward money), and left / right / self BV, matched pairs, activation
 * and wallet ledger rows for the three payout incomes are rebuilt.
 * Manual commissions ('other', 'retail') and the payout history are
 * kept. Wallet balances are rebuilt from the complete ledger afterwards.
 * Members are only ever ACTIVATED by the replay, never de-activated —
 * manual activations survive.
 *
 * Returns [ok(bool), stats(array)].
 */
function recalculate_all_incomes()
{
    $t0 = microtime(true);
    $stats = ['orders' => 0, 'commissions' => 0, 'earned' => 0.0, 'members' => 0, 'seconds' => 0.0];

    db_tx(function () use (&$stats, $t0) {
        $plan = get_plan();

        /* 1) wipe the derived data of every member (including points) */
        q("UPDATE users SET left_bv = 0, right_bv = 0, self_bv = 0, matched_pairs = 0, reward_points = 0, total_earned = 0, rank_id = NULL");

        /* 2) remove the automatic commissions and their wallet ledger
         *    rows — manual ('other' / 'retail') entries and payouts stay */
        q("DELETE wt FROM wallet_transactions wt
           JOIN commissions c ON c.id = wt.ref_id AND wt.ref_type = 'commission'
           WHERE c.type IN ('sponsor','binary','level','sponsor_matching','rank','car_fund','award')");
        q("DELETE FROM commissions WHERE type IN ('sponsor','binary','level','sponsor_matching','rank','car_fund','award')");
        /* BV and points logs are rebuilt from the replay as well */
        q("DELETE FROM bv_logs");
        q("DELETE FROM points_logs");
        $kept = (int)q_val("SELECT COUNT(*) FROM commissions");

        /* 3) replay every approved order chronologically under the NEW
         *    settings; the commission clock runs at each order's approval
         *    time so daily / monthly caps apply to the original days */
        $orders = q_all("SELECT * FROM orders WHERE status = 'approved'
                         ORDER BY COALESCE(approved_at, created_at) ASC, id ASC");
        foreach ($orders as $order) {
            $GLOBALS['__commission_clock'] = $order['approved_at'] ?: $order['created_at'];
            apply_order_business_effects($order, true);   /* payout incomes only */
            $stats['orders']++;
        }
        $GLOBALS['__commission_clock'] = null;

        /* 4) activation only ever upgrades (manual activations survive) */
        q("UPDATE users SET is_active = 1, activated_at = COALESCE(activated_at, ?)
           WHERE is_active = 0 AND self_bv >= ?", [now(), (float)$plan['activation_bv']]);

        /* 5) wallet balances = the complete ledger */
        q("UPDATE users u SET wallet_balance =
             (SELECT COALESCE(SUM(CASE WHEN t.type = 'credit' THEN t.amount ELSE -t.amount END), 0)
              FROM wallet_transactions t WHERE t.user_id = u.id)");

        $stats['commissions'] = (int)q_val("SELECT COUNT(*) FROM commissions") - $kept;
        $stats['earned'] = (float)q_val(
            "SELECT COALESCE(SUM(amount),0) FROM commissions
             WHERE type IN ('sponsor','binary','level','sponsor_matching','rank','car_fund','award')");
        $stats['members'] = (int)q_val("SELECT COUNT(*) FROM users WHERE total_earned > 0");
        $stats['points'] = (int)q_val("SELECT COALESCE(SUM(reward_points), 0) FROM users");
        $stats['seconds'] = round(microtime(true) - $t0, 1);
    });

    return [true, $stats];
}

/** Reject a pending order. */
function reject_order($orderId, $adminId, $reason)
{
    $order = q_row("SELECT * FROM orders WHERE id = ? FOR UPDATE", [$orderId]);
    if (!$order) {
        return [false, 'Order not found.'];
    }
    if ($order['status'] !== 'pending') {
        return [false, 'Only pending orders can be rejected.'];
    }
    // refund wallet payment if it was paid from wallet
    if ($order['payment_mode'] === 'wallet' && $order['payment_status'] === 'paid') {
        credit_wallet($order['user_id'], (float)$order['total_dp'], 'refund', $order['id'],
            'Refund for rejected order ' . $order['order_no']);
    }
    q("UPDATE orders SET status = 'rejected', remark = ?, approved_by = ?, approved_at = ? WHERE id = ?",
      [$reason, $adminId, now(), $orderId]);
    return [true, 'Order rejected.'];
}

/* ------------------------------------------------------------------ */
/*  Payouts                                                            */
/* ------------------------------------------------------------------ */

/**
 * User requests a withdrawal. Wallet is debited immediately (held);
 * rejected requests are refunded.
 */
function request_payout($userId, $amount)
{
    $plan = get_plan();
    $amount = round((float)$amount, 2);
    if ($amount < (float)$plan['payout_min']) {
        return [false, 'Minimum payout amount is ' . money($plan['payout_min']) . '.'];
    }
    $u = q_row("SELECT * FROM users WHERE id = ?", [$userId]);
    /* only the three plan incomes are payable — rank rewards, car fund,
     * award rewards, level income etc. are NOT added to a member's payout */
    $eligible = payout_eligible_amount($userId);
    if ($amount > $eligible) {
        return [false, 'Amount exceeds your eligible payout income (' . money($eligible) . '). Only Leg Completion, Matching Bonus and 50% Direct Sponsor Bonus incomes are payable.'];
    }
    if ($amount > (float)$u['wallet_balance']) {
        return [false, 'Amount exceeds your wallet balance.'];
    }
    return db_tx(function () use ($u, $amount, $plan) {
        $tds   = round($amount * (float)$plan['tds_percent'] / 100, 2);
        $admin = round($amount * (float)$plan['admin_charge_percent'] / 100, 2);
        $net   = round($amount - $tds - $admin, 2);
        $no    = payout_no();
        q("INSERT INTO payouts (user_id, request_no, amount, tds_amount, admin_charge, net_amount,
           status, bank_name, bank_account_no, bank_ifsc, bank_holder, created_at)
           VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)",
          [$u['id'], $no, $amount, $tds, $admin, $net,
           $u['bank_name'], $u['bank_account_no'], $u['bank_ifsc'], $u['bank_holder'], now()]);
        $pid = (int)db()->lastInsertId();
        debit_wallet($u['id'], $amount, 'payout', $pid, 'Payout request ' . $no);
        return [true, $pid];
    });
}

/**
 * Amount currently eligible for payout: ONLY the three plan incomes —
 * Leg Completion Income (once per leg), Matching Bonus Income (first
 * pair) and 50% Direct Sponsor Bonus Income — minus payouts already
 * generated / paid. All other
 * amounts (rank rewards, car fund, award rewards, level income, manual
 * entries) are NOT added to a member's payout.
 * $upto (optional 'YYYY-MM-DD HH:MM:SS') limits the income side to that
 * moment — used by date-wise payout reports.
 */
function payout_eligible_amount($userId, $upto = null)
{
    $sql = "SELECT COALESCE(SUM(amount), 0) FROM commissions
            WHERE user_id = ? AND status = 'credited'
              AND type IN ('sponsor','binary','sponsor_matching')";
    $params = [(int)$userId];
    if ($upto) {
        $sql .= " AND created_at <= ?";
        $params[] = $upto;
    }
    $gross = (float)q_val($sql, $params);
    $taken = (float)q_val(
        "SELECT COALESCE(SUM(amount), 0) FROM payouts
         WHERE user_id = ? AND status IN ('pending','paid')", [(int)$userId]);
    return max(0.0, round($gross - $taken, 2));
}

/**
 * Generate the date-wise payout report (super admin): creates a pending
 * payout for every member whose eligible income — the three plan incomes
 * only — has reached the payout minimum as of the END of $date, debits
 * their wallet, and returns the run timestamp that identifies the rows
 * (payout_report_rows). TDS and admin charge are the plan percentages
 * (5% / 5% on the current plan) and are deducted from the payout amount.
 * Members immediately see the payout as "in process"; marking it paid
 * shows "Payment is done".
 *
 * Returns [ok(bool), runTs(string|null), stats(array)].
 */
function generate_payout_report($date)
{
    $plan = get_plan();
    $cut = $date . ' 23:59:59';
    $min = (float)$plan['payout_min'];
    $tdsPct = (float)$plan['tds_percent'];
    $admPct = (float)$plan['admin_charge_percent'];

    $stats = ['created' => 0, 'skipped' => 0, 'total' => 0.0, 'net' => 0.0];
    $runTs = null;

    db_tx(function () use ($cut, $min, $tdsPct, $admPct, &$stats, &$runTs) {
        $cands = q_all(
            "SELECT u.id, u.wallet_balance,
                    COALESCE(c.gross, 0) AS gross, COALESCE(p.taken, 0) AS taken
             FROM users u
             LEFT JOIN (SELECT user_id, SUM(amount) AS gross FROM commissions
                        WHERE status = 'credited' AND type IN ('sponsor','binary','sponsor_matching')
                          AND created_at <= ? GROUP BY user_id) c ON c.user_id = u.id
             LEFT JOIN (SELECT user_id, SUM(amount) AS taken FROM payouts
                        WHERE status IN ('pending','paid') GROUP BY user_id) p ON p.user_id = u.id
             WHERE u.status = 'active' AND u.bank_account_no IS NOT NULL AND u.bank_account_no <> ''",
            [$cut]);
        $runTs = now();
        foreach ($cands as $x) {
            $amount = round((float)$x['gross'] - (float)$x['taken'], 2);
            if ($amount < $min) { continue; }
            /* the wallet may hold less if the member spent it on purchases */
            if ($amount > (float)$x['wallet_balance']) {
                $amount = round((float)$x['wallet_balance'], 2);
                if ($amount < $min) { $stats['skipped']++; continue; }
            }
            $tds = round($amount * $tdsPct / 100, 2);
            $adm = round($amount * $admPct / 100, 2);
            $net = round($amount - $tds - $adm, 2);
            $u = q_row("SELECT username, bank_name, bank_account_no, bank_ifsc, bank_holder FROM users WHERE id = ?", [$x['id']]);
            $no = 'RPT' . date('ymd', strtotime($cut)) . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            q("INSERT INTO payouts (user_id, request_no, amount, tds_amount, admin_charge, net_amount,
               status, bank_name, bank_account_no, bank_ifsc, bank_holder, created_at)
               VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)",
              [$x['id'], $no, $amount, $tds, $adm, $net,
               $u['bank_name'], $u['bank_account_no'], $u['bank_ifsc'], $u['bank_holder'], $runTs]);
            $pid = (int)db()->lastInsertId();
            debit_wallet($x['id'], $amount, 'payout', $pid,
                'Payout report ' . $no . ' (' . date('d M Y', strtotime($cut)) . ')');
            $stats['created']++;
            $stats['total'] = round($stats['total'] + $amount, 2);
            $stats['net'] = round($stats['net'] + $net, 2);
        }
    });

    return [!empty($runTs), $runTs, $stats];
}

/** All payout rows of one report run (same creation timestamp). */
function payout_report_rows($runTs)
{
    return q_all("SELECT p.*, u.username, u.full_name, u.mobile
                  FROM payouts p JOIN users u ON u.id = p.user_id
                  WHERE p.created_at = ? ORDER BY u.username ASC", [$runTs]);
}

/** Super admin marks a payout as paid or rejects it (refund). */
function process_payout($payoutId, $action, $adminId, $reason = '')
{
    return db_tx(function () use ($payoutId, $action, $adminId, $reason) {
        $p = q_row("SELECT * FROM payouts WHERE id = ? FOR UPDATE", [$payoutId]);
        if (!$p) {
            return [false, 'Payout request not found.'];
        }
        if ($p['status'] !== 'pending') {
            return [false, 'Payout request is already processed.'];
        }
        if ($action === 'paid') {
            q("UPDATE payouts SET status = 'paid', paid_at = ?, processed_by = ? WHERE id = ?",
              [now(), $adminId, $payoutId]);
            q("UPDATE users SET total_withdrawn = total_withdrawn + ? WHERE id = ?",
              [$p['amount'], $p['user_id']]);
            return [true, 'Payout marked as paid.'];
        }
        if ($action === 'reject') {
            credit_wallet($p['user_id'], (float)$p['amount'], 'refund', $p['id'],
                'Payout request ' . $p['request_no'] . ' rejected — amount refunded to wallet');
            q("UPDATE payouts SET status = 'rejected', reject_reason = ?, processed_by = ? WHERE id = ?",
              [$reason ?: 'Rejected by admin', $adminId, $payoutId]);
            return [true, 'Payout rejected and wallet refunded.'];
        }
        return [false, 'Unknown action.'];
    });
}

/* ------------------------------------------------------------------ */
/*  Stats (dashboards)                                                 */
/* ------------------------------------------------------------------ */

function user_earnings_breakdown($userId)
{
    $out = [
        'sponsor' => 0.0, 'binary' => 0.0, 'level' => 0.0, 'rank' => 0.0,
        'sponsor_matching' => 0.0, 'car_fund' => 0.0, 'award' => 0.0, 'retail' => 0.0,
        'other' => 0.0, 'total' => 0.0, 'award_by_id' => [],
    ];
    foreach (q_all("SELECT type, SUM(amount) amt FROM commissions WHERE user_id = ? AND status='credited' GROUP BY type", [$userId]) as $r) {
        $out[$r['type']] = (float)$r['amt'];
    }
    /* which award_rewards rows were already received (commissions.level holds the award id) */
    foreach (q_all("SELECT level, SUM(amount) amt FROM commissions WHERE user_id = ? AND type = 'award' AND status='credited' AND level IS NOT NULL GROUP BY level", [$userId]) as $r) {
        $out['award_by_id'][(int)$r['level']] = (float)$r['amt'];
    }
    $out['total'] = 0.0;
    foreach ($out as $k => $v) {
        if ($k !== 'total' && is_float($v)) { $out['total'] += $v; }
    }
    return $out;
}
