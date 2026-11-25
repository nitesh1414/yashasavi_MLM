<?php
/**
 * =====================================================================
 *  MLM ENGINE
 * ---------------------------------------------------------------------
 *  Binary tree (left / right placement) with:
 *   - One sponsor bonus per personally sponsored member completing joining BV
 *   - Matching income for EVERY eligible left/right BV pair
 *   - A first-match bonus for the matched member's immediate placement parent
 *   - Points, rank rewards, wallet and payouts
 *
 *  Every amount/rate above is configurable from the Super Admin panel
 *  (plan settings, rank table, award rewards).
 * =====================================================================
 */

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
        $rulesReady = income_rules_ready(true);
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
        if (!empty($data['use_username_password'])) {
            // Public/panel registration promises this initial password. Keep it
            // atomic with the account so an error cannot leave an unusable ID.
            q("UPDATE users SET username = ?, path = ?, depth = ?, password = ?, must_change_password = 1 WHERE id = ?",
              [$username, $path, (int)$parent['depth'] + 1, password_hash($username, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), $id]);
        } else {
            q("UPDATE users SET username = ?, path = ?, depth = ? WHERE id = ?",
              [$username, $path, (int)$parent['depth'] + 1, $id]);
        }
        if ($rulesReady) {
            q("INSERT INTO income_matching_state (user_id) VALUES (?)", [$id]);
        }

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
/** Timestamp used for new commission and wallet entries. */
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
    if (!$u) { throw new RuntimeException('Wallet owner not found.'); }
    $newBal = round((float)$u['wallet_balance'] + $amount, 2);
    if ($refType === 'refund') {
        // Returning a held payout or purchase is not new income.
        q("UPDATE users SET wallet_balance = ? WHERE id = ?", [$newBal, $userId]);
    } else {
        q("UPDATE users SET wallet_balance = ?, total_earned = total_earned + ? WHERE id = ?",
          [$newBal, $amount, $userId]);
    }
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
    if (!$u) { throw new RuntimeException('Wallet owner not found.'); }
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

/* The incremental rule implementation is separated from the historical
 * reconciliation so order approval never invokes a destructive rebuild. */
require_once __DIR__ . '/income_rules.php';

/* ------------------------------------------------------------------ */
/*  Order approval — the commission pipeline                           */
/* ------------------------------------------------------------------ */

/**
 * Approve a pending order: credit BV, activate the buyer, propagate BV
 * up the binary tree and pay the sponsor, every matching pair, and
 * first-child-match incomes. Everything runs in a single transaction.
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
        $ready = income_rules_ready(true); // serialize tree changes with approvals and previews
        if ($ready) { get_plan(true); }
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

        /* Respect the actual path-column limit (TEXT on current installs). */
        $pathLimit = (int)q_val("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='path'");
        $maxLen = (int)q_val("SELECT COALESCE(MAX(LENGTH(path)), 0) FROM users WHERE path LIKE ?", [$oldPath . '%']);
        if (!$pathLimit || strlen($newPath) + $maxLen - strlen($oldPath) > $pathLimit) {
            return [false, 'The member\'s downline is too deep to move to this position.'];
        }

        /* Total saved Self BV relocates with the subtree. */
        $bvDelta = round((float)q_val("SELECT COALESCE(SUM(self_bv), 0) FROM users WHERE path LIKE ?", [$oldPath . '%']), 2);
        if ($bvDelta < 0) { throw new RuntimeException('The moving subtree has invalid Self BV. Review the historical preview.'); }

        /* Capture both ancestor chains before modifying any relationships. */
        $oldChain = []; $cur = $u; $seen = [];
        while ($cur && (int)$cur['placement_id'] > 0) {
            $parentId = (int)$cur['placement_id'];
            if (isset($seen[$parentId]) || !in_array($cur['leg'], ['L', 'R'], true)) { throw new RuntimeException('Invalid old placement chain.'); }
            $seen[$parentId] = true;
            $oldChain[] = [$parentId, $cur['leg']];
            $cur = q_row("SELECT id, placement_id, leg FROM users WHERE id = ?", [$parentId]);
            if (!$cur) { throw new RuntimeException('Old placement parent is missing.'); }
        }
        $newChain = []; $cur = $newParent; $legFrom = $newLeg; $seen = [];
        while ($cur) {
            $parentId = (int)$cur['id'];
            if (isset($seen[$parentId]) || !in_array($legFrom, ['L', 'R'], true)) { throw new RuntimeException('Invalid new placement chain.'); }
            $seen[$parentId] = true;
            $newChain[] = [$parentId, $legFrom];
            $legFrom = $cur['leg'];
            $next = (int)$cur['placement_id'];
            $cur = $next ? q_row("SELECT id, placement_id, leg FROM users WHERE id = ?", [$next]) : null;
            if ($next && !$cur) { throw new RuntimeException('New placement parent is missing.'); }
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

        /* 4) Rebalance the NET leg changes; common ancestors may cancel out.
         * Refuse inconsistent counters rather than silently clamping them. */
        $changes = [];
        foreach ($oldChain as [$id, $side]) { $changes[$id][$side] = ($changes[$id][$side] ?? 0) - $bvDelta; }
        foreach ($newChain as [$id, $side]) { $changes[$id][$side] = ($changes[$id][$side] ?? 0) + $bvDelta; }
        ksort($changes, SORT_NUMERIC);
        foreach ($changes as $id => $sides) {
            $ancestor = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [$id]);
            if (!$ancestor) { throw new RuntimeException('An affected placement parent is missing.'); }
            foreach ($sides as $side => $change) {
                $change = round($change, 2);
                if ($change == 0.0) { continue; }
                $col = $side === 'R' ? 'right_bv' : 'left_bv';
                $after = round((float)$ancestor[$col] + $change, 2);
                if ($after < 0) { throw new RuntimeException('Team BV is inconsistent. Review and apply the historical preview before moving this member.'); }
                q("UPDATE users SET $col = ? WHERE id = ?", [$after, $id]);
                q("INSERT INTO bv_logs (user_id, order_id, kind, bv, balance_after, note, created_at)
                   VALUES (?, NULL, ?, ?, ?, ?, ?)", [$id, $side === 'R' ? 'right' : 'left', $change, $after,
                    'Placement move of ' . $u['username'], now()]);
            }
        }
        // Newly formed pairs are normal prospective activity. Already-paid
        // pairs remain watermarked and are never repaid merely by moving BV.
        if ($ready && $bvDelta > 0) {
            foreach ($newChain as [$id, $side]) { run_binary_matching($id); }
        }

        return [true, $u['username'] . ' moved under ' . $newParent['username'] . ' (' . ($newLeg === 'R' ? 'RIGHT' : 'LEFT') . ' leg)'
            . ($bvDelta > 0 ? ' — ' . number_format($bvDelta, 0) . ' BV relocated.'
                . ($ready ? ' New eligible pairs were processed.' : ' Income will be initialized by the reviewed historical preview.') : '.')];
    });
}

/**
 * Single-action approval: authorizes the payment (marks it received/paid)
 * and then approves + delivers the order in one go.
 */
function authorize_and_approve_order($orderId, $adminId)
{
    return db_tx(function () use ($orderId, $adminId) {
        if (!income_rules_ready(true)) { return [false, income_maintenance_message()]; }
        $order = q_row("SELECT * FROM orders WHERE id = ? FOR UPDATE", [$orderId]);
        if (!$order) { return [false, 'Order not found.']; }
        if ($order['status'] !== 'pending') {
            return [false, 'Order is not pending (current status: ' . $order['status'] . ').'];
        }
        $authorized = $order['payment_status'] !== 'paid';
        if ($authorized) { q("UPDATE orders SET payment_status = 'paid' WHERE id = ?", [$orderId]); }
        $result = approve_order($orderId, $adminId);
        if (!$result[0]) {
            // A failure must not leave a newly authorized payment behind.
            if ($authorized) { q("UPDATE orders SET payment_status = ? WHERE id = ?", [$order['payment_status'], $orderId]); }
            return $result;
        }
        if ($authorized) { $result[1] = 'Payment authorized. ' . $result[1]; }
        return $result;
    });
}

function approve_order($orderId, $adminId)
{
    return db_tx(function () use ($orderId, $adminId) {
        if (!income_rules_ready(true)) { return [false, income_maintenance_message()]; }
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

        /* Business effects are atomic with finalizing the order. */
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

/** Process approved business without replaying or rewriting history. */
function apply_order_business_effects(array $order, $payoutOnly = false)
{
    if (!income_rules_ready(true)) { throw new RuntimeException(income_maintenance_message()); }
    return income_apply_self_bv((int)$order['user_id'], (float)$order['total_bv'], (int)$order['id'],
        'Purchase ' . ($order['order_no'] ?? '') . ' — self BV');
}

/** Reject a pending order. */
function reject_order($orderId, $adminId, $reason)
{
    return db_tx(function () use ($orderId, $adminId, $reason) {
        income_rules_ready(true); // preserve atomic refunds during reconciliation
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

/** Reject unsafe legacy payout settings instead of creating a negative payout. */
function payout_settings_valid(array $plan)
{
    $tds = (float)$plan['tds_percent'];
    $admin = (float)$plan['admin_charge_percent'];
    return is_finite($tds) && is_finite($admin) && $tds >= 0 && $admin >= 0
        && $tds + $admin <= 100 && (float)$plan['payout_min'] > 0;
}

/** Split a gross amount in paise. Independent rounding can otherwise make
 * deductions exceed a small gross payout (e.g. 50% + 50% of five paise).
 * TDS is rounded first and the admin charge is capped at the remainder. */
function payout_deductions($amount, array $plan)
{
    income_require_64bit();
    $grossPaise = max(0, (int)round((float)$amount * 100));
    $tdsPaise = min($grossPaise, max(0, (int)round($grossPaise * (float)$plan['tds_percent'] / 100)));
    $adminPaise = min($grossPaise - $tdsPaise, max(0, (int)round($grossPaise * (float)$plan['admin_charge_percent'] / 100)));
    return [$tdsPaise / 100, $adminPaise / 100, ($grossPaise - $tdsPaise - $adminPaise) / 100];
}

/**
 * User requests a withdrawal. Wallet is debited immediately (held);
 * rejected requests are refunded.
 */
function request_payout($userId, $amount)
{
    $amount = round((float)$amount, 2);
    return db_tx(function () use ($userId, $amount) {
        if (!income_rules_ready(true)) { return [false, income_maintenance_message()]; }
        $plan = get_plan(true);
        if (!payout_settings_valid($plan)) { return [false, 'Payout settings are invalid. Please contact the administrator.']; }
        if (!is_finite($amount) || $amount < (float)$plan['payout_min']) {
            return [false, 'Minimum payout amount is ' . money_txt($plan['payout_min']) . '.'];
        }
        $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$userId]);
        if (!$u || $u['status'] !== 'active') { return [false, 'Member account is not active.']; }
        if ($u['kyc_status'] !== 'verified') {
            return [false, 'Your KYC is not completed yet — the payout cannot be done. Please complete your KYC.'];
        }
        if (trim((string)$u['bank_name']) === '' || trim((string)$u['bank_account_no']) === '' || trim((string)$u['bank_ifsc']) === '') {
            return [false, 'Your bank details are not completed — the payout cannot be done. Please add your bank details in your profile.'];
        }
        $eligible = payout_eligible_amount($userId, null, true);
        if ($amount > $eligible) {
            return [false, 'Amount exceeds your eligible payout income (' . money_txt($eligible) . '). Sponsor, matching, placement first-match and rank income are payable.'];
        }
        if ($amount > (float)$u['wallet_balance']) { return [false, 'Amount exceeds your wallet balance.']; }
        [$tds, $admin, $net] = payout_deductions($amount, $plan);
        $no = payout_no();
        q("INSERT INTO payouts (user_id, request_no, amount, tds_amount, admin_charge, net_amount,
           status, bank_name, bank_account_no, bank_ifsc, bank_holder, created_at)
           VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)",
          [$u['id'], $no, $amount, $tds, $admin, $net, $u['bank_name'], $u['bank_account_no'], $u['bank_ifsc'], $u['bank_holder'], now()]);
        $pid = (int)db()->lastInsertId();
        if (debit_wallet($u['id'], $amount, 'payout', $pid, 'Payout request ' . $no) === null) {
            throw new RuntimeException('Wallet debit failed; no payout was created.');
        }
        return [true, $pid];
    });
}

/**
 * Amount currently eligible for payout: the three plan incomes —
 * Sponsor income, every eligible Matching Pair Income and the placement
 * parent's first-child-match bonus —
 * PLUS the Rank Rewards credited for rank achievements, minus payouts
 * already generated / paid. Car fund, award rewards and level income do
 * not exist. Manual entries are not added to a member's payout either.
 * $upto (optional 'YYYY-MM-DD HH:MM:SS') limits the income side to that
 * moment — used by date-wise payout reports.
 */
function payout_eligible_amount($userId, $upto = null, $lock = false)
{
    $sql = "SELECT COALESCE(SUM(amount), 0) FROM commissions
            WHERE user_id = ? AND status = 'credited'
              AND type IN ('sponsor','binary','sponsor_matching','rank')";
    $params = [(int)$userId];
    if ($upto) {
        $sql .= " AND created_at <= ?";
        $params[] = $upto;
    }
    if ($lock) { $sql .= ' FOR UPDATE'; }
    $gross = (float)q_val($sql, $params);
    $payoutSql = "SELECT COALESCE(SUM(amount), 0) FROM payouts WHERE user_id = ? AND status IN ('pending','paid')";
    if ($lock) { $payoutSql .= ' FOR UPDATE'; }
    $taken = (float)q_val($payoutSql, [(int)$userId]);
    $postCutoffDebits = 0.0;
    if ($upto) {
        // Do not let a new, not-yet-closed week's earnings replace older
        // income that a post-cutoff reconciliation already reversed. Use the
        // net of each atomic run, so opposite category corrections in the same
        // run offset one another. Positive runs are not advanced to this week.
        $sql = "SELECT e.adjustment_json FROM income_recalc_entries e
                JOIN income_recalc_runs r ON r.id = e.run_id
                WHERE e.user_id = ? AND r.created_at > ?";
        if ($lock) { $sql .= ' FOR UPDATE'; }
        foreach (q_all($sql, [(int)$userId, $upto]) as $entry) {
            $adjustment = json_decode($entry['adjustment_json'], true);
            if (!is_array($adjustment) || !isset($adjustment['net']) || !is_numeric($adjustment['net'])) {
                throw new RuntimeException('A reconciliation audit entry is invalid; the payout was not generated.');
            }
            $postCutoffDebits += min(0.0, (float)$adjustment['net']);
        }
    }
    return max(0.0, round($gross + $postCutoffDebits - $taken, 2));
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
        if (!income_rules_ready(true)) { $stats['error'] = income_maintenance_message(); return; }
        $plan = get_plan(true);
        if (!payout_settings_valid($plan)) { $stats['error'] = 'Payout settings are invalid; check TDS, admin charges and the minimum.'; return; }
        $min = (float)$plan['payout_min'];
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
             WHERE u.status = 'active'",
            [$cut]);
        $runTs = now();

        /* statement header — one per report run */
        q("INSERT INTO payout_statements (run_ts, period_date, total_gross, total_tds, total_admin, total_net, members_done, members_blocked, created_at)
           VALUES (?, ?, 0, 0, 0, 0, 0, 0, ?)", [$runTs, substr($cut, 0, 10), $runTs]);
        $statementId = (int)db()->lastInsertId();
        $stats['statement_id'] = $statementId;
        $totGross = $totTds = $totAdm = $totNet = 0.0;

        foreach ($cands as $candidate) {
            $u = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$candidate['id']]);
            if (!$u || $u['status'] !== 'active') { continue; }
            // Respect the completed-week cutoff AND current net entitlement;
            // the cutoff calculation also reserves later negative corrections.
            $amount = min(payout_eligible_amount($u['id'], $cut, true), payout_eligible_amount($u['id'], null, true));
            if ($amount < $min) { continue; } /* not payout-eligible this week */

            /* the wallet may hold less if the member spent it on purchases */
            if ($amount > (float)$u['wallet_balance']) {
                $amount = round((float)$u['wallet_balance'], 2);
            }
            [$tds, $adm, $net] = payout_deductions($amount, $plan);

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
                throw new RuntimeException('Wallet debit failed; payout report was rolled back.');
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

/** Statement snapshots say whether a request was created, not whether it was
 * paid. Show the linked request's current status without changing the snapshot. */
function payout_statement_status(array $row)
{
    if (empty($row['payout_done'])) { return ['No payout created', 'danger']; }
    switch ($row['payout_status'] ?? null) {
        case 'pending': return ['Pending processing', 'warning'];
        case 'paid': return ['Marked paid', 'success'];
        case 'rejected': return ['Rejected / refunded', 'danger'];
        default: return ['Request status unavailable', 'warning'];
    }
}

/** Statement snapshots with the linked request's current status. */
function payout_statement_rows($statementId)
{
    return q_all("SELECT r.*, p.status AS payout_status, p.paid_at AS payout_paid_at
                  FROM payout_statement_rows r
                  LEFT JOIN payouts p ON p.id = r.payout_id AND p.user_id = r.user_id
                  WHERE r.statement_id = ? ORDER BY r.payout_done DESC, r.username ASC", [(int)$statementId]);
}

/** All payout statements a member appears on (newest first). */
function member_payout_statements($userId)
{
    return q_all("SELECT s.*, r.payout_done, r.payout_id, r.gross, r.tds, r.admin_charge, r.net,
                         r.reason, r.kyc_status, r.bank_ok, p.status AS payout_status, p.paid_at AS payout_paid_at
                  FROM payout_statement_rows r
                  JOIN payout_statements s ON s.id = r.statement_id
                  LEFT JOIN payouts p ON p.id = r.payout_id AND p.user_id = r.user_id
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
        income_rules_ready(true); // existing pending payouts may still be processed during migration
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
