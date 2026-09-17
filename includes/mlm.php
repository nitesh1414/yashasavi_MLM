<?php
/**
 * =====================================================================
 *  MLM ENGINE
 * ---------------------------------------------------------------------
 *  Binary tree (left / right placement) with:
 *   - Sponsor (direct referral) bonus  : % of order BV to the sponsor
 *   - Binary pair matching income      : 1:1 BV matching on weaker leg,
 *                                        unmatched BV carries forward
 *   - Level income                     : % of order BV up to N sponsor
 *                                        levels
 *   - Rank achievements                : team BV + direct members
 *   - E-wallet, commissions & payouts
 *
 *  Every amount/rate above is configurable from the Super Admin panel
 *  (plan settings, level table, rank table).
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
                'id' => 1, 'activation_bv' => 100, 'sponsor_percent' => 5,
                'pair_unit_bv' => 100, 'binary_type' => 'percent', 'binary_value' => 10,
                'level_depth' => 5, 'daily_cap' => 5000, 'carry_forward' => 1,
                'matching_requires_active' => 1, 'level_requires_active' => 1,
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
function find_position($sponsorId, $leg)
{
    $leg = ($leg === 'R') ? 'R' : 'L';
    $children = user_children($sponsorId);
    if ($children[$leg] === null) {
        return [$sponsorId, $leg];
    }
    // BFS down the chosen leg for the first node with a free slot
    $queue = [$children[$leg]['id']];
    $seen = 0;
    while ($queue && $seen < 100000) {
        $seen++;
        $nodeId = array_shift($queue);
        $ch = user_children($nodeId);
        if ($ch['L'] === null) {
            return [$nodeId, 'L'];
        }
        if ($ch['R'] === null) {
            return [$nodeId, 'R'];
        }
        $queue[] = $ch['L']['id'];
        $queue[] = $ch['R']['id'];
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

        // unique checks
        if (q_val("SELECT COUNT(*) FROM users WHERE email = ?", [$data['email']]) > 0) {
            return [false, 0, 'This email address is already registered.'];
        }
        if (q_val("SELECT COUNT(*) FROM users WHERE mobile = ?", [$data['mobile']]) > 0) {
            return [false, 0, 'This mobile number is already registered.'];
        }

        $pos = find_position($sponsor['id'], $data['leg']);
        if (!$pos) {
            return [false, 0, 'No free position available under this sponsor.'];
        }
        [$placementId, $leg] = $pos;

        $parent = q_row("SELECT * FROM users WHERE id = ?", [$placementId]);

        // insert
        q("INSERT INTO users
           (username, password, full_name, email, mobile, dob, marital_status, nationality,
            address, city, state, pincode, nominee_name, nominee_relation,
            bank_holder, bank_account_no, bank_ifsc, bank_name, bank_branch,
            aadhaar_no, pan_no, pan_image, aadhaar_image, sponsor_id, placement_id, leg, status, kyc_status,
            left_bv, right_bv, self_bv, matched_pairs, wallet_balance, created_at)
           VALUES ('', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 'pending',
                   0, 0, 0, 0, 0, ?)",
           [
               password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]),
               $data['full_name'], $data['email'], $data['mobile'],
               $data['dob'] ?: null, $data['marital_status'], $data['nationality'],
               $data['address'], $data['city'], $data['state'], $data['pincode'],
               $data['nominee_name'], $data['nominee_relation'],
               $data['bank_holder'], $data['bank_account_no'], $data['bank_ifsc'],
               $data['bank_name'], $data['bank_branch'],
               $data['aadhaar_no'], $data['pan_no'],
               $data['pan_image'] ?: null, $data['aadhaar_image'] ?: null,
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
      [$userId, $amount, $newBal, $refType, $refId, $note, now()]);
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
      [$userId, $orderId, $type, round((float)$amount, 2), round((float)$bvAmount, 2), $level, $note, now()]);
    $cid = (int)db()->lastInsertId();
    credit_wallet($userId, $amount, 'commission', $cid, ucfirst($type) . ' income' . ($note ? ' — ' . $note : ''));
    return $cid;
}

/* ------------------------------------------------------------------ */
/*  Ranks                                                              */
/* ------------------------------------------------------------------ */

/**
 * Recompute the rank of a user. Awards the reward when a new rank is
 * reached. Returns the (possibly new) rank row or null.
 */
function check_rank($userId, $orderId = null)
{
    $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$userId]);
    if (!$u) {
        return null;
    }
    $teamBv   = (float)$u['left_bv'] + (float)$u['right_bv'];
    $directs  = (int)q_val("SELECT COUNT(*) FROM users WHERE sponsor_id = ? AND is_active = 1", [$userId]);
    $best = null;
    foreach (get_ranks() as $r) {
        if ($teamBv >= (float)$r['min_team_bv'] && $directs >= (int)$r['min_directs']) {
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
    if ((float)$best['reward_amount'] > 0) {
        add_commission($userId, $orderId, 'rank', $best['reward_amount'], 0, null,
            'Rank achievement: ' . $best['name']);
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

    if ((int)$plan['matching_requires_active'] === 1 && (int)$user['is_active'] !== 1) {
        return 0.0; // inactive members accumulate BV but are not paid
    }

    // income per unit
    if ($plan['binary_type'] === 'fixed') {
        $perUnit = (float)$plan['binary_value'];
    } else {
        $perUnit = $unit * ((float)$plan['binary_value'] / 100);
    }

    // daily cap
    $cap = (float)$plan['daily_cap'];
    $payableUnits = $newUnits;
    if ($cap > 0) {
        $todayStart = date('Y-m-d 00:00:00');
        $paidToday = (float)q_val(
            "SELECT COALESCE(SUM(amount),0) FROM commissions
             WHERE user_id = ? AND type = 'binary' AND status = 'credited' AND created_at >= ?",
            [$user['id'], $todayStart]
        );
        $capLeft = $cap - $paidToday;
        if ($perUnit > 0) {
            $payableUnits = min($newUnits, (int)floor($capLeft / $perUnit));
        } else {
            $payableUnits = 0;
        }
    }

    $paidUnits = max(0, $payableUnits);
    if ($paidUnits > 0 && $perUnit > 0) {
        $income = round($paidUnits * $perUnit, 2);
        add_commission($user['id'], $orderId, 'binary', $income, $paidUnits * $unit, null,
            $paidUnits . ' pair(s) matched @ ' . bv($unit));
    }

    // update matched pairs: paid units always count; if carry forward is
    // disabled the remaining units are flushed (matched without pay)
    $countedUnits = $paidUnits;
    if ((int)$plan['carry_forward'] === 0) {
        $countedUnits = $newUnits;
    }
    if ($countedUnits > 0) {
        q("UPDATE users SET matched_pairs = matched_pairs + ? WHERE id = ?", [$countedUnits, $user['id']]);
    }
    return $paidUnits > 0 ? round($paidUnits * $perUnit, 2) : 0.0;
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

        $totalBv = (float)$order['total_bv'];
        $buyer = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$order['user_id']]);
        if (!$buyer) {
            return [false, 'Buyer account missing.'];
        }

        $plan = get_plan();

        /* 1) self BV + activation */
        q("UPDATE users SET self_bv = self_bv + ? WHERE id = ?", [$totalBv, $buyer['id']]);
        $buyer['self_bv'] = (float)$buyer['self_bv'] + $totalBv;
        if ((int)$buyer['is_active'] !== 1 && $buyer['self_bv'] >= (float)$plan['activation_bv']) {
            q("UPDATE users SET is_active = 1, activated_at = ? WHERE id = ?", [now(), $buyer['id']]);
            $buyer['is_active'] = 1;
        }

        /* 2) sponsor bonus */
        if ((float)$plan['sponsor_percent'] > 0 && $buyer['sponsor_id']) {
            $sponsor = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$buyer['sponsor_id']]);
            if ($sponsor && $sponsor['status'] === 'active' && (int)$sponsor['is_active'] === 1) {
                $amt = round($totalBv * (float)$plan['sponsor_percent'] / 100, 2);
                if ($amt > 0) {
                    add_commission($sponsor['id'], $order['id'], 'sponsor', $amt, $totalBv, null,
                        'Direct referral purchase of ' . $buyer['username']);
                }
            }
        }

        /* 3) level income along the sponsor chain */
        $levelPercents = get_plan_levels();
        $depth = (int)$plan['level_depth'];
        if ($depth > 0) {
            $uplineId = $buyer['sponsor_id'];
            $level = 1;
            while ($uplineId && $level <= $depth) {
                $up = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$uplineId]);
                if (!$up) {
                    break;
                }
                $pct = isset($levelPercents[$level]) ? (float)$levelPercents[$level] : 0.0;
                if ($pct > 0
                    && $up['status'] === 'active'
                    && !((int)$plan['level_requires_active'] === 1 && (int)$up['is_active'] !== 1)) {
                    $amt = round($totalBv * $pct / 100, 2);
                    if ($amt > 0) {
                        add_commission($up['id'], $order['id'], 'level', $amt, $totalBv, $level,
                            'Level ' . $level . ' income');
                    }
                }
                $uplineId = $up['sponsor_id'] ?: null;
                $level++;
            }
        }

        /* 4) propagate BV up the placement tree + binary matching */
        $chain = ancestor_chain($buyer); // nearest first
        $leg = $buyer['leg'];            // leg of buyer relative to its parent
        foreach ($chain as $anc) {
            $col = ($leg === 'R') ? 'right_bv' : 'left_bv';
            q("UPDATE users SET $col = $col + ? WHERE id = ?", [$totalBv, $anc['id']]);
            $anc[$col] = (float)$anc[$col] + $totalBv;
            run_binary_matching($anc, $order['id']);
            $leg = $anc['leg']; // leg of this ancestor relative to its own parent
        }

        /* 5) rank check for buyer and every ancestor */
        check_rank($buyer['id'], $order['id']);
        foreach ($chain as $anc) {
            check_rank($anc['id'], $order['id']);
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
    $out = ['sponsor' => 0.0, 'binary' => 0.0, 'level' => 0.0, 'rank' => 0.0, 'total' => 0.0];
    foreach (q_all("SELECT type, SUM(amount) amt FROM commissions WHERE user_id = ? AND status='credited' GROUP BY type", [$userId]) as $r) {
        $out[$r['type']] = (float)$r['amt'];
    }
    $out['total'] = $out['sponsor'] + $out['binary'] + $out['level'] + $out['rank'];
    return $out;
}
