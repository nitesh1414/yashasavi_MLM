<?php
/** MLM engine: prospective direct-sponsor and team-BV matching income,
 * points/ranks, wallet and payout processing. Past financial entries are
 * preserved. See income_policy.php for activation and matching rules. */

/* ------------------------------------------------------------------ */
/*  Plan settings (cached)                                             */
/* ------------------------------------------------------------------ */

function get_plan($refresh = false)
{
    static $plan = null;
    if ($plan === null || $refresh) {
        $plan = q_row("SELECT * FROM plan_settings WHERE id = 1");
        if (!$plan) {
            $plan = [
                'id' => 1, 'activation_bv' => 3000, 'sponsor_percent' => 10,
                'pair_unit_bv' => 3000, 'binary_type' => 'fixed', 'binary_value' => 450,
                'level_depth' => 0, 'daily_cap' => 320000, 'monthly_cap' => 800000,
                'carry_forward' => 1,
                'matching_requires_active' => 1, 'level_requires_active' => 1,
                'sponsor_matching_percent' => 50, 'points_per_unit' => 1, 'point_bv' => 400,
                'car_fund_points' => 500, 'car_fund_amount' => 150000,
                'tds_percent' => 5, 'admin_charge_percent' => 5, 'payout_min' => 500,
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
        return q_row("SELECT * FROM users WHERE (id = ? OR username = ?) AND status <> 'deleted' LIMIT 1", [$idOrName, strtoupper($idOrName)]);
    }
    return q_row("SELECT * FROM users WHERE (username = ? OR email = ?) AND status <> 'deleted' LIMIT 1", [$idOrName, $idOrName]);
}

/** Children (direct placements) of a user: ['L' => row|null, 'R' => row|null]. */
function user_children($userId)
{
    $rows = q_all("SELECT * FROM users WHERE placement_id = ? AND status <> 'deleted'", [$userId]);
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
    if (!income_policy_ready()) { return [false, 0, 'Run the database upgrade before registering members.']; }
    return db_tx(function () use ($data) {
        // Only a signed-in superadmin may establish a new first root after
        // the previous last member was archived. Serialize competing roots.
        lock_network_mutation();
        $firstRoot = !empty($data['_first_root']) && current_admin('superadmin')
            && (int)q_val("SELECT COUNT(*) FROM users WHERE status <> 'deleted'") === 0;
        $sponsor = $firstRoot ? null : find_user($data['sponsor']);
        if (!$firstRoot && !$sponsor) { return [false, 0, 'Sponsor ID not found.']; }
        if ($sponsor) {
            $sponsor = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$sponsor['id']]);
            if (!$sponsor || $sponsor['status'] !== 'active') { return [false, 0, 'Sponsor account is not active.']; }
        }
        // Member-panel placement authority may have changed while the
        // registration form was open (or while waiting for this lock).
        if (!empty($data['_member_actor_id'])) {
            $actor = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$data['_member_actor_id']]);
            if (!$actor || $actor['status'] !== 'active' || !$sponsor
                || ((int)$sponsor['id'] !== (int)$actor['id'] && strpos($sponsor['path'], $actor['path']) !== 0)) {
                return [false, 0, 'Your account or sponsor position changed. Please review the registration.'];
            }
        }
        $placementId = null; $leg = 'L'; $parent = null;
        if (!$firstRoot) {
            if (!empty($data['placement'])) {
                $puser = ctype_digit((string)$data['placement'])
                    ? q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$data['placement']])
                    : find_user((string)$data['placement']);
                $pleg = (($data['placement_leg'] ?? $data['leg']) === 'R') ? 'R' : 'L';
                if ($puser && $puser['status'] === 'active') {
                    $ch = user_children($puser['id']);
                    if ($ch[$pleg] === null) { $placementId = (int)$puser['id']; $leg = $pleg; }
                }
            }
            if (!$placementId) {
                $pos = find_position($sponsor['id'], $data['leg']);
                if (!$pos) { return [false, 0, 'No free position available under this sponsor.']; }
                [$placementId, $leg] = $pos;
            }
            $parent = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$placementId]);
            if (!$parent || $parent['status'] === 'deleted') { return [false, 0, 'Placement member is no longer available.']; }
        }

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
               $sponsor ? $sponsor['id'] : null, $placementId, $leg, now(),
           ]);

        $id = (int)db()->lastInsertId();
        $username = 'YSH' . (100000 + $id);
        $path = ($parent ? $parent['path'] : '/') . $id . '/';
        q("UPDATE users SET username = ?, path = ?, depth = ? WHERE id = ?",
          [$username, $path, $parent ? (int)$parent['depth'] + 1 : 0, $id]);
        q("INSERT IGNORE INTO matching_state (user_id) VALUES (?)", [$id]);

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
/** Timestamp used for new commission and wallet rows. */
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
    // A returned purchase/payout debit restores the balance but is not new
    // income. Preserve the income counter when issuing a refund.
    $earnedIncrement = $refType === 'refund' ? 0 : $amount;
    q("UPDATE users SET wallet_balance = ?, total_earned = total_earned + ? WHERE id = ?",
      [$newBal, $earnedIncrement, $userId]);
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
 * Rank check: the member's rank is the highest rank whose LIFETIME points
 * threshold (1 point = 3,000 BV matched left + right) is reached. EVERY
 * rank whose threshold is reached pays its reward amount to the member's
 * WALLET balance as a 'rank' commission — a jump over several ranks pays
 * EACH of them, no level income is skipped. Each rank is paid exactly
 * once: the commission's level column stores the rank id. Rank rewards
 * are wallet income and count towards the payout-eligible income
 * (withdrawable via payout requests, rules a-e aside).
 */
function check_rank($userId, $orderId = null)
{
    $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$userId]);
    if (!$u) {
        return null;
    }
    /* ranks already paid (the commission level column stores the rank id) */
    $paid = [];
    foreach (q_all("SELECT level FROM commissions WHERE user_id = ? AND type = 'rank' AND level IS NOT NULL", [$userId]) as $p) {
        $paid[(int)$p['level']] = true;
    }
    /* legacy rank rows (level NULL, paid before per-rank tracking) count
     * for every rank at or below the member's current rank_id — prevents
     * double payment when an old database is upgraded */
    if ((int)$u['rank_id'] > 0) {
        foreach (q_all("SELECT id FROM ranks") as $r0) {
            if ((int)$r0['id'] <= (int)$u['rank_id']) {
                $paid[(int)$r0['id']] = true;
            }
        }
    }
    /* rank = highest rank whose LIFETIME points threshold is reached
     * (1 point = 3,000 BV matched left + right; self BV never counts);
     * every newly reached rank on the way is paid, in ascending order */
    $best = null;
    $newly = [];
    foreach (q_all("SELECT * FROM ranks ORDER BY min_points ASC, id ASC") as $r) {
        if ((int)$r['min_points'] > 0 && (int)$u['reward_points'] >= (int)$r['min_points']) {
            $best = $r;
            if (empty($paid[(int)$r['id']])) {
                $newly[] = $r;
            }
        }
    }
    if (!$best) {
        return null;
    }
    if ((int)$u['rank_id'] !== (int)$best['id']) {
        q("UPDATE users SET rank_id = ? WHERE id = ?", [$best['id'], $userId]);
    }
    /* rank achievement: every newly reached rank's reward amount is added
     * to the member's wallet balance (wallet income — included in payouts) */
    foreach ($newly as $r) {
        if ((float)$r['reward_amount'] > 0) {
            add_commission($userId, $orderId, 'rank', $r['reward_amount'], (int)$u['reward_points'], (int)$r['id'],
                'Rank achievement: ' . $r['name'] . ' (' . (int)$r['min_points'] . ' points)');
        }
    }
    return $best;
}

/* ------------------------------------------------------------------ */
/*  Binary matching                                                    */
/* ------------------------------------------------------------------ */

/* ------------------------------------------------------------------ */
/*  Order approval — the commission pipeline                           */
/* ------------------------------------------------------------------ */

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
        lock_network_mutation();
        $u = q_row("SELECT * FROM users WHERE id = ? AND status <> 'deleted' FOR UPDATE", [(int)$userId]);
        if (!$u) {
            return [false, 'Member not found.'];
        }
        if (!income_policy_ready()) { return [false, 'Run the database upgrade before moving members.']; }
        $beforeBv = [];
        foreach (q_all("SELECT id, left_bv, right_bv FROM users WHERE status <> 'deleted'") as $row) {
            $beforeBv[(int)$row['id']] = min((float)$row['left_bv'], (float)$row['right_bv']);
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

        /* users.path is TEXT: reject moves that would exceed its byte limit. */
        $maxLen = (float)q_val("SELECT COALESCE(MAX(LENGTH(path)), 0) FROM users WHERE path LIKE ?", [$oldPath . '%']);
        if (strlen($newPath) + ((float)$maxLen - strlen($oldPath)) > 65535) {
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

        // A move reassigns team BV. Watermark newly matched historical
        // volume without creating commissions or points for the move.
        $live = [];
        foreach (q_all("SELECT id, path, leg, self_bv, is_active, left_bv, right_bv FROM users WHERE status <> 'deleted' ORDER BY id FOR UPDATE") as $row) {
            $live[(int)$row['id']] = $row;
        }
        $unit = max(1, (float)get_plan()['pair_unit_bv']);
        foreach ($live as $mid => $member) {
            $state = lock_matching_state($mid);
            $newL = (float)$member['left_bv']; $newR = (float)$member['right_bv'];
            $processed = (float)$state['processed_bv'];
            if (min($newL, $newR) > min((float)$state['left_eligible_bv'], (float)$state['right_eligible_bv']) + .001) {
                $processed = max($processed, floor(min($newL, $newR) / $unit) * $unit);
            }
            $pointProcessed = (float)$state['points_processed_bv'];
            $newMin = min((float)$member['left_bv'], (float)$member['right_bv']);
            if ($newMin > ($beforeBv[$mid] ?? 0) + .001) {
                $pointProcessed = max($pointProcessed, floor($newMin / $unit) * $unit);
            }
            q("UPDATE matching_state SET left_eligible_bv=?, right_eligible_bv=?, processed_bv=?, points_processed_bv=? WHERE user_id=?",
              [$newL, $newR, $processed, $pointProcessed, $mid]);
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
    if (!income_policy_ready()) { return [false, 'Run install/upgrade.php before approving orders.']; }
    return db_tx(function () use ($orderId, $adminId) {
        lock_network_mutation();
        $order = q_row("SELECT * FROM orders WHERE id = ? FOR UPDATE", [$orderId]);
        if (!$order) { return [false, 'Order not found.']; }
        if ($order['status'] !== 'pending') { return [false, 'Order is not pending (current status: ' . $order['status'] . ').']; }
        $buyer = q_row("SELECT status FROM users WHERE id = ? FOR UPDATE", [(int)$order['user_id']]);
        if (!$buyer || $buyer['status'] === 'deleted') { return [false, 'Buyer account is missing or deleted; reject or resolve the order instead.']; }
        $authorized = $order['payment_status'] !== 'paid';
        if ($authorized) { q("UPDATE orders SET payment_status = 'paid' WHERE id = ?", [$orderId]); }
        $res = approve_order($orderId, $adminId);
        if (!$res[0] && $authorized) { q("UPDATE orders SET payment_status = ? WHERE id = ?", [$order['payment_status'], $orderId]); }
        if ($res[0] && $authorized) { $res[1] = 'Payment authorized. ' . $res[1]; }
        return $res;
    });
}

function approve_order($orderId, $adminId)
{
    if (!income_policy_ready()) { return [false, 'Run install/upgrade.php before approving orders.']; }
    return db_tx(function () use ($orderId, $adminId) {
        lock_network_mutation();
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

        /* 1-5) business effects: BV, joining completion, direct sponsor
         *      leg income, binary matching, points (shared with the full
         *      plan recalculation) */
        if (!apply_order_business_effects($order)) {
            return [false, 'Buyer account is missing or deleted; reject or resolve the order instead.'];
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

/** Approved orders update self and team BV. All approved team BV can
 * match; direct sponsors receive one bonus per green direct, independent
 * of placement. */
function apply_order_business_effects(array $order, $payoutOnly = false)
{
    if (!income_policy_ready()) { throw new RuntimeException('Run install/upgrade.php before applying the new income policy.'); }
    $totalBv = (float)$order['total_bv'];
    $buyer = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$order['user_id']]);
    if (!$buyer || $buyer['status'] === 'deleted') { return false; }
    $plan = get_plan();
    $preActive = (int)$buyer['is_active'] === 1;
    q("UPDATE users SET self_bv = self_bv + ? WHERE id = ?", [$totalBv, $buyer['id']]);
    $buyer['self_bv'] = (float)$buyer['self_bv'] + $totalBv;
    q("INSERT INTO bv_logs (user_id, order_id, kind, bv, balance_after, note, created_at)
       VALUES (?, ?, 'self', ?, ?, ?, ?)",
      [$buyer['id'], $order['id'], $totalBv, $buyer['self_bv'],
       'Purchase ' . ($order['order_no'] ?? '') . ' — self BV', commission_now()]);

    $justGreen = !$preActive && $buyer['self_bv'] >= (float)$plan['activation_bv'];
    if ($justGreen) { $buyer = mark_member_green($buyer, $order['id']); }
    $leg = $buyer['leg'];
    foreach (ancestor_chain($buyer) as $anc) {
        $col = $leg === 'R' ? 'right_bv' : 'left_bv';
        q("UPDATE users SET $col = $col + ? WHERE id = ?", [$totalBv, $anc['id']]);
        $anc = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$anc['id']]);
        q("INSERT INTO bv_logs (user_id, order_id, kind, bv, balance_after, note, created_at)
           VALUES (?, ?, ?, ?, ?, ?, ?)",
          [$anc['id'], $order['id'], $leg === 'R' ? 'right' : 'left', $totalBv, $anc[$col],
           'Team BV from ' . $buyer['username'] . ' (' . ($leg === 'R' ? 'right' : 'left') . ' leg)', commission_now()]);
        add_qualified_team_bv($anc['id'], $leg, $totalBv);
        run_binary_matching($anc, $order['id']);
        update_reward_points($anc['id'], $order['id']);
        $leg = $anc['leg'];
    }
    return true;
}

/** Historical replay is deliberately disabled by the prospective policy. */
function recalculate_all_incomes()
{
    throw new LogicException('Historical income recalculation is disabled: existing commissions and payouts must remain unchanged.');
}

/** Reject a pending order. */
function reject_order($orderId, $adminId, $reason)
{
    return db_tx(function () use ($orderId, $adminId, $reason) {
        lock_network_mutation();
        $order = q_row("SELECT * FROM orders WHERE id = ? FOR UPDATE", [$orderId]);
        if (!$order) { return [false, 'Order not found.']; }
        if ($order['status'] !== 'pending') { return [false, 'Only pending orders can be rejected.']; }
        if ($order['payment_mode'] === 'wallet' && $order['payment_status'] === 'paid') {
            credit_wallet($order['user_id'], (float)$order['total_dp'], 'refund', $order['id'],
                'Refund for rejected order ' . $order['order_no']);
        }
        q("UPDATE orders SET status = 'rejected', remark = ?, approved_by = ?, approved_at = ? WHERE id = ?",
          [$reason, $adminId, now(), $orderId]);
        return [true, 'Order rejected.'];
    });
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
        return [false, 'Minimum payout amount is ' . money_txt($plan['payout_min']) . '.'];
    }
    $u = q_row("SELECT * FROM users WHERE id = ?", [$userId]);
    if (!$u || $u['status'] !== 'active') { return [false, 'This member account is not available for payout.']; }
    /* payout is only done when KYC and bank details are completed */
    if ($u['kyc_status'] !== 'verified') {
        return [false, 'Your KYC is not completed yet — the payout cannot be done. Please complete your KYC.'];
    }
    if (trim((string)$u['bank_name']) === '' || trim((string)$u['bank_account_no']) === '' || trim((string)$u['bank_ifsc']) === '') {
        return [false, 'Your bank details are not completed — the payout cannot be done. Please add your bank details in your profile.'];
    }
    /* the payable incomes are the three plan incomes plus rank rewards —
     * car fund, award rewards, level income etc. are NOT added to a
     * member's payout */
    $eligible = payout_eligible_amount($userId);
    if ($amount > $eligible) {
        return [false, 'Amount exceeds your eligible payout income (' . money_txt($eligible) . '). Only Direct Sponsor Income, Matching Income, Direct Sponsor Matching Bonus and Rank Rewards are payable.'];
    }
    if ($amount > (float)$u['wallet_balance']) {
        return [false, 'Amount exceeds your wallet balance.'];
    }
    return db_tx(function () use ($u, $amount, $plan) {
        lock_network_mutation();
        $plan = get_plan(true);
        $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$u['id']]);
        if (!$u || $u['status'] !== 'active') { return [false, 'This member account is not available for payout.']; }
        if ($u['kyc_status'] !== 'verified' || trim((string)$u['bank_name']) === '' || trim((string)$u['bank_account_no']) === '' || trim((string)$u['bank_ifsc']) === '') {
            return [false, 'Your KYC or bank details changed. Please review them before requesting payout.'];
        }
        if ($amount < (float)$plan['payout_min']) { return [false, 'The minimum payout amount changed.']; }
        if ($amount > (float)$u['wallet_balance'] || $amount > payout_eligible_amount($u['id'])) {
            return [false, 'Your available payout balance changed; please review it and try again.'];
        }
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
 * Amount currently eligible for payout: the three plan incomes —
 * Direct Sponsor Income, Matching
 * Income (₹450 for each qualifying pair) and the Direct Sponsor Matching Bonus —
 * PLUS the Rank Rewards credited for rank achievements, minus payouts
 * already generated / paid. Car fund, award rewards and level income do
 * not exist. Manual entries are not added to a member's payout either.
 * $upto (optional 'YYYY-MM-DD HH:MM:SS') limits the income side to that
 * moment — used by date-wise payout reports.
 */
function payout_eligible_amount($userId, $upto = null)
{
    $sql = "SELECT COALESCE(SUM(amount), 0) FROM commissions
            WHERE user_id = ? AND status = 'credited'
              AND type IN ('sponsor','binary','sponsor_matching','rank')";
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
 * Generate the WEEKLY payout report + payout statement (super admin only,
 * run every Monday): income is counted up to the LAST SATURDAY 11:59 PM,
 * so the statement covers the completed Sunday-to-Saturday week. Every
 * member whose eligible income — the three plan incomes plus rank
 * rewards — has
 * reached the payout minimum is listed on the statement. Members with completed KYC and bank
 * details get a pending payout (wallet debited, TDS + admin charge
 * deducted from the payout amount); members with incomplete KYC or bank
 * details are listed with "payout will not be done" and keep their
 * balance for the next week. Returns the run timestamp identifying the
 * rows (payout_report_rows) plus the created statement id.
 *
 * Returns [ok(bool), runTs(string|null), stats(array)] with
 * stats = [created, blocked, skipped, total, net, statement_id].
 */
function generate_payout_report()
{
    /* income is counted up to the last Saturday 11:59 PM (the completed
     * Sunday → Saturday week); the report is generated every Monday */
    $sat = date('Y-m-d', strtotime('last saturday'));
    $cut = $sat . ' 23:59:59';

    $stats = ['created' => 0, 'blocked' => 0, 'skipped' => 0, 'total' => 0.0, 'net' => 0.0, 'statement_id' => 0];
    $runTs = null;

    db_tx(function () use ($cut, &$stats, &$runTs) {
        lock_network_mutation();
        $plan = get_plan();
        $min = (float)$plan['payout_min'];
        $tdsPct = (float)$plan['tds_percent'];
        $admPct = (float)$plan['admin_charge_percent'];
        $cands = q_all(
            "SELECT u.id, u.username, u.full_name, u.mobile, u.wallet_balance, u.kyc_status,
                    u.bank_name, u.bank_account_no, u.bank_ifsc, u.bank_holder,
                    COALESCE(c.gross, 0) AS gross, COALESCE(p.taken, 0) AS taken
             FROM users u
             LEFT JOIN (SELECT user_id, SUM(amount) AS gross FROM commissions
                        WHERE status = 'credited' AND type IN ('sponsor','binary','sponsor_matching','rank')
                          AND created_at <= ? GROUP BY user_id) c ON c.user_id = u.id
             LEFT JOIN (SELECT user_id, SUM(amount) AS taken FROM payouts
                        WHERE status IN ('pending','paid') GROUP BY user_id) p ON p.user_id = u.id
             WHERE u.status = 'active' FOR UPDATE",
            [$cut]);
        $runTs = now();

        /* statement header — one per report run */
        q("INSERT INTO payout_statements (run_ts, period_date, total_gross, total_tds, total_admin, total_net, members_done, members_blocked, created_at)
           VALUES (?, ?, 0, 0, 0, 0, 0, 0, ?)", [$runTs, substr($cut, 0, 10), $runTs]);
        $statementId = (int)db()->lastInsertId();
        $stats['statement_id'] = $statementId;
        $totGross = $totTds = $totAdm = $totNet = 0.0;

        foreach ($cands as $u) {
            $amount = round((float)$u['gross'] - (float)$u['taken'], 2);
            if ($amount < $min) { continue; } /* not payout-eligible this week */

            /* the wallet may hold less if the member spent it on purchases */
            if ($amount > (float)$u['wallet_balance']) {
                $amount = round((float)$u['wallet_balance'], 2);
            }
            $tds = round($amount * $tdsPct / 100, 2);
            $adm = round($amount * $admPct / 100, 2);
            $net = round($amount - $tds - $adm, 2);

            $kycOk = $u['kyc_status'] === 'verified';
            $bankOk = trim((string)$u['bank_name']) !== '' && trim((string)$u['bank_account_no']) !== ''
                   && trim((string)$u['bank_ifsc']) !== '';

            if ($amount < $min) {
                /* wallet below minimum after the cap — listed, but no payout */
                $stats['skipped']++;
                q("INSERT INTO payout_statement_rows
                   (statement_id, user_id, username, full_name, mobile, bank_name, bank_account_no, bank_ifsc, bank_holder,
                    kyc_status, bank_ok, gross, tds, admin_charge, net, payout_done, payout_id, reason)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, NULL, ?)",
                  [$statementId, $u['id'], $u['username'], $u['full_name'], $u['mobile'],
                   $u['bank_name'], $u['bank_account_no'], $u['bank_ifsc'], $u['bank_holder'],
                   $u['kyc_status'], (int)$bankOk, $amount, 'Wallet balance below minimum — payout will not be done']);
                continue;
            }

            $reason = null;
            if (!$kycOk && !$bankOk) { $reason = 'KYC and bank details not completed — payout will not be done'; }
            elseif (!$kycOk) { $reason = 'KYC not completed — payout will not be done'; }
            elseif (!$bankOk) { $reason = 'Bank details not completed — payout will not be done'; }

            if ($reason !== null) {
                /* incomplete KYC / bank — on the statement, balance stays in the wallet */
                $stats['blocked']++;
                q("INSERT INTO payout_statement_rows
                   (statement_id, user_id, username, full_name, mobile, bank_name, bank_account_no, bank_ifsc, bank_holder,
                    kyc_status, bank_ok, gross, tds, admin_charge, net, payout_done, payout_id, reason)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, ?)",
                  [$statementId, $u['id'], $u['username'], $u['full_name'], $u['mobile'],
                   $u['bank_name'], $u['bank_account_no'], $u['bank_ifsc'], $u['bank_holder'],
                   $u['kyc_status'], (int)$bankOk, $amount, $tds, $adm, $net, $reason]);
                continue;
            }

            /* KYC + bank completed — the payout will be done */
            $no = 'RPT' . date('ymd', strtotime($cut)) . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            q("INSERT INTO payouts (user_id, request_no, amount, tds_amount, admin_charge, net_amount,
               status, bank_name, bank_account_no, bank_ifsc, bank_holder, created_at)
               VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)",
              [$u['id'], $no, $amount, $tds, $adm, $net,
               $u['bank_name'], $u['bank_account_no'], $u['bank_ifsc'], $u['bank_holder'], $runTs]);
            $pid = (int)db()->lastInsertId();
            if (debit_wallet($u['id'], $amount, 'payout', $pid,
                'Payout report ' . $no . ' (' . date('d M Y', strtotime($cut)) . ')') === null) {
                throw new RuntimeException('Payout wallet balance changed; the report was not generated.');
            }

            q("INSERT INTO payout_statement_rows
               (statement_id, user_id, username, full_name, mobile, bank_name, bank_account_no, bank_ifsc, bank_holder,
                kyc_status, bank_ok, gross, tds, admin_charge, net, payout_done, payout_id, reason)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, 1, ?, NULL)",
              [$statementId, $u['id'], $u['username'], $u['full_name'], $u['mobile'],
               $u['bank_name'], $u['bank_account_no'], $u['bank_ifsc'], $u['bank_holder'],
               $u['kyc_status'], $amount, $tds, $adm, $net, $pid]);

            $stats['created']++;
            $stats['total'] = round($stats['total'] + $amount, 2);
            $stats['net'] = round($stats['net'] + $net, 2);
            $totGross = round($totGross + $amount, 2);
            $totTds = round($totTds + $tds, 2);
            $totAdm = round($totAdm + $adm, 2);
            $totNet = round($totNet + $net, 2);
        }

        q("UPDATE payout_statements SET total_gross = ?, total_tds = ?, total_admin = ?, total_net = ?,
           members_done = ?, members_blocked = ? WHERE id = ?",
          [$totGross, $totTds, $totAdm, $totNet, $stats['created'], $stats['blocked'] + $stats['skipped'], $statementId]);
    });

    return [!empty($runTs), $runTs, $stats];
}

/** One payout statement (header row) by id. */
function payout_statement($id)
{
    return q_row("SELECT * FROM payout_statements WHERE id = ?", [(int)$id]);
}

/** Week label of a statement: "from Sunday <d> - to Saturday <d>". */
function payout_statement_week_label(array $statement)
{
    $sat = $statement['period_date'];
    $sun = date('Y-m-d', strtotime($sat . ' -6 days'));
    return 'from Sunday ' . dmy($sun) . ' - to Saturday ' . dmy($sat);
}

/** All statement rows of one payout statement, payout-done members first. */
function payout_statement_rows($statementId)
{
    return q_all("SELECT * FROM payout_statement_rows WHERE statement_id = ? ORDER BY payout_done DESC, username ASC", [(int)$statementId]);
}

/** All payout statements a member appears on (newest first). */
function member_payout_statements($userId)
{
    return q_all("SELECT s.*, r.payout_done, r.gross, r.tds, r.admin_charge, r.net, r.reason, r.kyc_status, r.bank_ok
                  FROM payout_statement_rows r
                  JOIN payout_statements s ON s.id = r.statement_id
                  WHERE r.user_id = ?
                  ORDER BY s.id DESC", [(int)$userId]);
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
        lock_network_mutation();
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
