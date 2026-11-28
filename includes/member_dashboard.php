<?php
/** Read-only calculations for the member dashboard. */

/**
 * Purchase amount through the first joining-BV qualification, using the
 * recorded event when available and a continuous Self BV log. Never change
 * the saved Self BV. When logs are incomplete or contain a manual BV grant,
 * the qualifying purchase amount cannot be established safely.
 *
 * Returns amount (null if unknowable), status, and the recorded target BV.
 */
function member_joining_package(array $user, array $plan)
{
    income_require_64bit();
    $userId = (int)$user['id'];
    $target = (float)$plan['activation_bv'];
    $event = q_row('SELECT order_id, joining_bv FROM income_sponsor_events WHERE member_id=?', [$userId]);
    if ($event && (float)$event['joining_bv'] > 0) { $target = (float)$event['joining_bv']; }
    $result = ['amount' => null, 'status' => 'unavailable', 'target_bv' => $target];

    if ($target <= 0) { return $result; }
    if ((float)$user['self_bv'] < $target) {
        // Until qualification, show paid approved purchases so far without
        // changing the authoritative saved Self BV.
        if (!$event) {
            $result['amount'] = (float)q_val("SELECT COALESCE(SUM(total_dp),0) FROM orders
                WHERE user_id=? AND status='approved' AND payment_status='paid'", [$userId]);
            $result['status'] = 'in_progress';
        }
        return $result;
    }
    // Without a recorded target, the current plan cannot establish which
    // historical purchase first qualified the member: the plan may have
    // changed. Reconciliations can leave order_id null, but their stored target
    // can still be checked against a continuous BV log.
    if (!$event || (float)$event['joining_bv'] <= 0) { return $result; }
    $qualifying = !empty($event['order_id'])
        ? q_row("SELECT id FROM bv_logs WHERE user_id=? AND kind='self' AND order_id=? ORDER BY id LIMIT 1",
            [$userId, (int)$event['order_id']])
        : q_row("SELECT id FROM bv_logs WHERE user_id=? AND kind='self' AND balance_after>=? ORDER BY id LIMIT 1",
            [$userId, $target]);
    if (!$qualifying) { return $result; }

    $rows = q_all("SELECT b.id,b.order_id,b.bv,b.balance_after,o.id AS order_found,o.user_id AS buyer,
                          o.total_bv,o.total_dp,o.status,o.payment_status
                   FROM bv_logs b LEFT JOIN orders o ON o.id=b.order_id
                   WHERE b.user_id=? AND b.kind='self' AND b.id<=? ORDER BY b.id",
        [$userId, (int)$qualifying['id']]);
    $previous = 0; $amount = 0; $seen = [];
    $targetUnits = (int)round($target * 100);
    foreach ($rows as $row) {
        $bv = (int)round((float)$row['bv'] * 100);
        $balance = (int)round((float)$row['balance_after'] * 100);
        $orderId = (int)$row['order_id'];
        if ($bv < 0 || $balance !== $previous + $bv || !$orderId || isset($seen[$orderId])
            || !$row['order_found'] || (int)$row['buyer'] !== $userId
            || $row['status'] !== 'approved' || $row['payment_status'] !== 'paid'
            || (float)$row['total_dp'] < 0
            || (int)round((float)$row['total_bv'] * 100) !== $bv
            || $previous >= $targetUnits) {
            return $result;
        }
        $seen[$orderId] = true;
        $amount += (int)round((float)$row['total_dp'] * 100);
        $previous = $balance;
    }
    if (!$rows || $previous < $targetUnits || (int)end($rows)['id'] !== (int)$qualifying['id']) { return $result; }
    $result['amount'] = $amount / 100;
    $result['status'] = 'qualified';
    return $result;
}

/** Informational milestone only: no commission, wallet credit or payout. */
function member_display_milestone($points, $target, $amount)
{
    $points = max(0, (int)$points);
    $targetText = is_scalar($target) ? trim((string)$target) : '';
    $configured = ctype_digit($targetText) && strlen($targetText) <= 10
        && (float)$targetText > 0 && (float)$targetText <= 4294967295
        && is_numeric($amount) && is_finite((float)$amount) && (float)$amount >= 0;
    return ['points' => $points, 'configured' => $configured,
        'target' => $configured ? (int)$target : null,
        'reached' => $configured && $points >= (int)$target,
        'amount' => $configured ? round((float)$amount, 2) : null];
}

/** Compact display text: points and configured amount remain visible at every stage. */
function member_milestone_text(array $milestone)
{
    $value = number_format($milestone['points']) . ' pts';
    if (!$milestone['configured']) { return [$value, 'Not configured']; }
    $status = money_txt($milestone['amount']) . ' · target ' . number_format($milestone['target']) . ' pts';
    if ($milestone['reached']) { $status .= ' · reached, not paid'; }
    return [$value, $status];
}
