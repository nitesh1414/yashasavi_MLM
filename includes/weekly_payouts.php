<?php
/**
 * Automatic weekly payout reports. All mutations share the income mutex.
 * New reports reserve funds without debiting the wallet. Only a confirmed
 * superadmin Paid action settles the eligible reservations. Historical
 * reports and manually requested payouts keep their original accounting.
 */

/** Fail closed if the additive weekly schema or transactional storage is missing. */
function weekly_payout_require_storage()
{
    static $checked = false;
    if ($checked) { return; }
    $tables = ['settings', 'admins', 'plan_settings', 'users', 'commissions', 'wallet_transactions', 'payouts',
        'payout_statements', 'payout_statement_rows', 'income_recalc_runs', 'income_recalc_entries',
        'weekly_payout_runs', 'weekly_payout_reservations'];
    $marks = implode(',', array_fill(0, count($tables), '?'));
    $engines = [];
    foreach (q_all("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($marks)", $tables) as $row) {
        $engines[$row['TABLE_NAME']] = strtoupper((string)$row['ENGINE']);
    }
    foreach ($tables as $table) {
        if (($engines[$table] ?? '') !== 'INNODB') {
            throw new RuntimeException('Weekly payout storage is missing or is not InnoDB (' . $table . '). Apply DATABASE_CHANGES.sql during maintenance.');
        }
    }
    $checked = true;
}

/** Reservations remain in wallet_balance but cannot be spent twice. */
function weekly_reserved_amount($userId, $lock = false)
{
    weekly_payout_require_storage();
    if ($lock && !db()->inTransaction()) { throw new RuntimeException('Reservation locking requires a transaction.'); }
    $sql = "SELECT payout_id, amount FROM weekly_payout_reservations WHERE user_id=? AND status='reserved' ORDER BY payout_id";
    if ($lock) { $sql .= ' FOR UPDATE'; }
    $reserved = 0.0;
    foreach (q_all($sql, [(int)$userId]) as $row) { $reserved = round($reserved + (float)$row['amount'], 2); }
    return $reserved;
}

function weekly_available_wallet(array $user, $lock = false)
{
    return max(0.0, round((float)$user['wallet_balance'] - weekly_reserved_amount($user['id'], $lock), 2));
}

/** Display the wallet and its reservations from one consistent DB snapshot. */
function weekly_wallet_snapshot($userId)
{
    weekly_payout_require_storage();
    $row = q_row("SELECT u.wallet_balance, u.total_earned, u.total_withdrawn,
                         (SELECT COALESCE(SUM(r.amount), 0) FROM weekly_payout_reservations r
                          WHERE r.user_id=u.id AND r.status='reserved') AS reserved
                  FROM users u WHERE u.id=?", [(int)$userId]);
    if (!$row) { throw new RuntimeException('Wallet owner not found.'); }
    $row['reserved'] = round((float)$row['reserved'], 2);
    $row['available'] = max(0.0, round((float)$row['wallet_balance'] - $row['reserved'], 2));
    return $row;
}

/** The latest COMPLETED Saturday, in the configured application timezone. */
function weekly_latest_completed_saturday($asOf = null)
{
    $zone = new DateTimeZone(APP_TIMEZONE);
    $local = $asOf instanceof DateTimeInterface
        ? (new DateTimeImmutable('@' . $asOf->getTimestamp()))->setTimezone($zone)
        : new DateTimeImmutable('now', $zone);
    return $local->setTime(0, 0)->modify('last saturday')->format('Y-m-d');
}

function weekly_valid_period($periodEnd, $asOf = null)
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$periodEnd, new DateTimeZone(APP_TIMEZONE));
    return $date && $date->format('Y-m-d') === (string)$periodEnd && $date->format('w') === '6'
        && $periodEnd <= weekly_latest_completed_saturday($asOf);
}

/** Create at most one new-style report for a completed week. Older reports
 * are not changed; a legacy report for the same week suppresses a duplicate. */
function generate_payout_report($periodEnd = null, $asOf = null)
{
    $periodEnd = $periodEnd ?: weekly_latest_completed_saturday($asOf);
    $stats = ['created' => 0, 'blocked' => 0, 'skipped' => 0, 'total' => 0.0,
        'net' => 0.0, 'statement_id' => 0, 'outcome' => '', 'period_end' => $periodEnd];
    if (!weekly_valid_period($periodEnd, $asOf)) {
        $stats['error'] = 'A payout report can only be generated for a completed Saturday.';
        return [false, null, $stats];
    }
    $cut = $periodEnd . ' 23:59:59';
    $runTs = null;
    db_tx(function () use ($periodEnd, $cut, &$stats, &$runTs) {
        if (!income_rules_ready(true)) { $stats['error'] = income_maintenance_message(); return; }
        weekly_payout_require_storage();
        $existing = q_row("SELECT * FROM weekly_payout_runs WHERE period_end=? FOR UPDATE", [$periodEnd]);
        if ($existing) {
            $stats['outcome'] = $existing['status'] === 'legacy' ? 'legacy' : 'existing';
            $stats['statement_id'] = (int)$existing['statement_id'];
            return;
        }
        if ((int)q_val("SELECT COUNT(*) FROM payout_statements WHERE period_date=?", [$periodEnd]) > 0) {
            // Do not attach a new status or reservation to an old report: its
            // pending requests were already debited under the old workflow.
            q("INSERT INTO weekly_payout_runs (period_end, statement_id, status, created_at) VALUES (?, NULL, 'legacy', ?)", [$periodEnd, now()]);
            $stats['outcome'] = 'legacy';
            return;
        }
        $plan = get_plan(true);
        if (!payout_settings_valid($plan)) { $stats['error'] = 'Payout settings are invalid; check TDS, admin charges and the minimum.'; return; }
        $min = (float)$plan['payout_min'];
        $runTs = now();
        q("INSERT INTO payout_statements (run_ts, period_date, total_gross, total_tds, total_admin, total_net, members_done, members_blocked, created_at)
           VALUES (?, ?, 0, 0, 0, 0, 0, 0, ?)", [$runTs, $periodEnd, $runTs]);
        $statementId = (int)db()->lastInsertId();
        $stats['statement_id'] = $statementId;
        q("INSERT INTO weekly_payout_runs (period_end, statement_id, status, created_at) VALUES (?, ?, 'unpaid', ?)", [$periodEnd, $statementId, $runTs]);
        $totGross = $totTds = $totAdmin = $totNet = 0.0;
        foreach (q_all("SELECT id FROM users ORDER BY id") as $candidate) {
            $u = q_row("SELECT * FROM users WHERE id=? FOR UPDATE", [(int)$candidate['id']]);
            if (!$u) { continue; }
            // Unpaid earnings carry forward, but previously paid/held/manual
            // requests and existing report reservations cannot be reused.
            $eligible = min(payout_eligible_amount($u['id'], $cut, true), payout_eligible_amount($u['id'], null, true));
            if ($eligible <= 0) { continue; }
            $available = weekly_available_wallet($u, true);
            $amount = round(min($eligible, $available), 2);
            $kycOk = $u['kyc_status'] === 'verified';
            $bankOk = trim((string)$u['bank_name']) !== '' && trim((string)$u['bank_account_no']) !== ''
                && trim((string)$u['bank_ifsc']) !== '';
            [$tds, $admin, $net] = payout_deductions($amount, $plan);
            $reason = null;
            if ($u['status'] !== 'active') { $reason = 'Account is not active; income remains unpaid.'; }
            elseif ($eligible < $min) { $reason = 'Eligible income is below the payout minimum; it carries forward.'; }
            elseif ($available < $min) { $reason = 'Available wallet balance is below the payout minimum; income remains unpaid.'; }
            elseif (!$kycOk || !$bankOk) { $reason = 'KYC or bank details are incomplete; income remains unpaid.'; }
            if ($reason !== null) {
                // Show the unpaid entitlement, rather than zero when the
                // wallet is short. Deductions on blocked rows are illustrative.
                [$blockedTds, $blockedAdmin, $blockedNet] = payout_deductions($eligible, $plan);
                $stats['blocked']++;
                q("INSERT INTO payout_statement_rows
                   (statement_id,user_id,username,full_name,mobile,bank_name,bank_account_no,bank_ifsc,bank_holder,
                    kyc_status,bank_ok,gross,tds,admin_charge,net,payout_done,payout_id,reason)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NULL,?)",
                  [$statementId,$u['id'],$u['username'],$u['full_name'],$u['mobile'],$u['bank_name'],$u['bank_account_no'],
                   $u['bank_ifsc'],$u['bank_holder'],$u['kyc_status'],(int)$bankOk,$eligible,$blockedTds,$blockedAdmin,$blockedNet,$reason]);
                continue;
            }
            $no = 'RPT' . date('ymd', strtotime($periodEnd)) . strtoupper(bin2hex(random_bytes(6)));
            q("INSERT INTO payouts (user_id,request_no,amount,tds_amount,admin_charge,net_amount,status,
               bank_name,bank_account_no,bank_ifsc,bank_holder,created_at) VALUES (?,?,?,?,?,?,'pending',?,?,?,?,?)",
              [$u['id'],$no,$amount,$tds,$admin,$net,$u['bank_name'],$u['bank_account_no'],$u['bank_ifsc'],$u['bank_holder'],$runTs]);
            $pid = (int)db()->lastInsertId();
            q("INSERT INTO weekly_payout_reservations (payout_id,statement_id,user_id,amount,status,created_at)
               VALUES (?,?,?,?,'reserved',?)", [$pid,$statementId,$u['id'],$amount,$runTs]);
            q("INSERT INTO payout_statement_rows
               (statement_id,user_id,username,full_name,mobile,bank_name,bank_account_no,bank_ifsc,bank_holder,
                kyc_status,bank_ok,gross,tds,admin_charge,net,payout_done,payout_id,reason)
               VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?,?,?,1,?,NULL)",
              [$statementId,$u['id'],$u['username'],$u['full_name'],$u['mobile'],$u['bank_name'],$u['bank_account_no'],
               $u['bank_ifsc'],$u['bank_holder'],$u['kyc_status'],$amount,$tds,$admin,$net,$pid]);
            $stats['created']++;
            $stats['total'] = round($stats['total'] + $amount, 2);
            $stats['net'] = round($stats['net'] + $net, 2);
            $totGross = round($totGross + $amount, 2); $totTds = round($totTds + $tds, 2);
            $totAdmin = round($totAdmin + $admin, 2); $totNet = round($totNet + $net, 2);
        }
        q("UPDATE payout_statements SET total_gross=?,total_tds=?,total_admin=?,total_net=?,members_done=?,members_blocked=? WHERE id=?",
          [$totGross,$totTds,$totAdmin,$totNet,$stats['created'],$stats['blocked'],$statementId]);
        $stats['outcome'] = 'created';
    });
    return [$stats['outcome'] !== '', $runTs, $stats];
}

/** Run from cron. The first invocation starts with the latest completed week;
 * subsequent invocations catch up missing weeks in order, at most 52 at once. */
function weekly_payout_run_due($asOf = null)
{
    weekly_payout_require_storage();
    $latest = weekly_latest_completed_saturday($asOf);
    $last = q_val("SELECT MAX(period_end) FROM weekly_payout_runs");
    $next = $last ? (new DateTimeImmutable($last, new DateTimeZone(APP_TIMEZONE)))->modify('+7 days')->format('Y-m-d') : $latest;
    $results = [];
    while ($next <= $latest && count($results) < 52) {
        [$ok, $ts, $stats] = generate_payout_report($next, $asOf);
        if (!$ok) { throw new RuntimeException($stats['error'] ?? 'Weekly payout generation failed.'); }
        $results[] = $stats;
        $next = (new DateTimeImmutable($next, new DateTimeZone(APP_TIMEZONE)))->modify('+7 days')->format('Y-m-d');
    }
    return $results;
}

/** Status label for a new weekly report header. */
function weekly_report_status($status)
{
    switch ($status) {
        case 'unpaid': return ['Unpaid', 'warning'];
        case 'in_process': return ['In Process', 'info'];
        case 'paid': return ['Paid', 'success'];
        default: return ['Legacy report', 'secondary'];
    }
}

/** Paid is a bookkeeping confirmation, not a bank transfer. All eligible
 * rows settle in one transaction; no row can be debited a second time. */
function process_weekly_payout_report($statementId, $action, $adminId)
{
    return db_tx(function () use ($statementId, $action, $adminId) {
        if (!income_rules_ready(true)) { return [false, income_maintenance_message()]; }
        weekly_payout_require_storage();
        $admin = q_row("SELECT id FROM admins WHERE id=? AND role='superadmin' AND status='active' FOR UPDATE", [(int)$adminId]);
        if (!$admin) { return [false, 'An active superadmin is required.']; }
        $run = q_row("SELECT * FROM weekly_payout_runs WHERE statement_id=? FOR UPDATE", [(int)$statementId]);
        if (!$run || $run['status'] === 'legacy') { return [false, 'Only new automatic weekly reports can be processed as a batch.']; }
        if ($run['status'] === 'paid') { return [false, 'This report is already marked paid; no balance was changed.']; }
        if ($action === 'in_process') {
            if ($run['status'] === 'in_process') { return [true, 'Report is already in process.']; }
            q("UPDATE weekly_payout_runs SET status='in_process',in_process_at=?,in_process_by=? WHERE statement_id=?", [now(),$adminId,$statementId]);
            return [true, 'Report marked in process. Member wallets remain reserved, not debited.'];
        }
        if ($action !== 'paid' && $action !== 'release') { return [false, 'Unknown weekly report action.']; }
        $plan = get_plan(true);
        if ($action === 'paid' && !payout_settings_valid($plan)) {
            return [false, 'Payout settings are invalid; no report payments were recorded.'];
        }
        $rows = q_all("SELECT r.payout_id,r.user_id,r.amount AS reserved_amount,r.status AS reservation_status,
                              p.user_id AS payout_user,p.amount,p.status AS payout_status,p.bank_name,p.bank_account_no,p.bank_ifsc,p.bank_holder
                       FROM weekly_payout_reservations r LEFT JOIN payouts p ON p.id=r.payout_id
                       WHERE r.statement_id=? ORDER BY r.user_id,r.payout_id FOR UPDATE", [(int)$statementId]);
        $createdRows = (int)q_val("SELECT COUNT(*) FROM payout_statement_rows WHERE statement_id=? AND payout_done=1", [(int)$statementId]);
        $linkedRows = (int)q_val("SELECT COUNT(*) FROM payout_statement_rows s JOIN weekly_payout_reservations r
                                 ON r.payout_id=s.payout_id AND r.statement_id=s.statement_id AND r.user_id=s.user_id AND r.amount=s.gross
                                 WHERE s.statement_id=? AND s.payout_done=1", [(int)$statementId]);
        if ($createdRows !== count($rows) || $linkedRows !== count($rows)) {
            throw new RuntimeException('Weekly statement and reservation records do not match. No report payments were recorded.');
        }
        $paid = 0; $released = 0; $at = now();
        foreach ($rows as $row) {
            if ((int)$row['payout_user'] !== (int)$row['user_id']
                || abs((float)$row['amount'] - (float)$row['reserved_amount']) >= 0.001) {
                throw new RuntimeException('A weekly reservation is inconsistent. No report payments were recorded.');
            }
            // A previous attempt may have released every ineligible row.
            if ($row['reservation_status'] === 'released' && $row['payout_status'] === 'rejected') { continue; }
            if ($row['reservation_status'] !== 'reserved' || $row['payout_status'] !== 'pending') {
                throw new RuntimeException('A weekly reservation is inconsistent. No report payments were recorded.');
            }
            if ($action === 'release') {
                // Explicit emergency escape hatch, e.g. before an income
                // correction. A reservation was never debited, so no refund.
                $reason = 'Unpaid: reservation released by superadmin; income can carry forward.';
                q("UPDATE payouts SET status='rejected',reject_reason=?,processed_by=? WHERE id=?", [$reason,$adminId,$row['payout_id']]);
                q("UPDATE weekly_payout_reservations SET status='released',settled_at=? WHERE payout_id=?", [$at,$row['payout_id']]);
                $released++;
                continue;
            }
            $u = q_row("SELECT * FROM users WHERE id=? FOR UPDATE", [(int)$row['user_id']]);
            if (!$u) { throw new RuntimeException('A member is missing. No report payments were recorded.'); }
            $bankOk = trim((string)$u['bank_name']) !== '' && trim((string)$u['bank_account_no']) !== '' && trim((string)$u['bank_ifsc']) !== '';
            $sameBank = trim((string)$u['bank_name']) === trim((string)$row['bank_name'])
                && trim((string)$u['bank_account_no']) === trim((string)$row['bank_account_no'])
                && strtoupper(trim((string)$u['bank_ifsc'])) === strtoupper(trim((string)$row['bank_ifsc']))
                && trim((string)$u['bank_holder']) === trim((string)$row['bank_holder']);
            $cut = $run['period_end'] . ' 23:59:59';
            $stillEligible = payout_entitlement_difference($u['id'], $cut, true, $row['payout_id']) >= 0
                && payout_entitlement_difference($u['id'], null, true) >= 0;
            if ($u['status'] !== 'active' || $u['kyc_status'] !== 'verified' || !$bankOk || !$sameBank
                || (float)$row['amount'] < (float)$plan['payout_min'] || !$stillEligible) {
                // No wallet debit was made for this new reservation. Release it
                // without a refund; unpaid income may be included next week.
                $reason = 'Not paid: eligibility, minimum, bank details or income changed; reservation released.';
                q("UPDATE payouts SET status='rejected',reject_reason=?,processed_by=? WHERE id=?", [$reason,$adminId,$row['payout_id']]);
                q("UPDATE weekly_payout_reservations SET status='released',settled_at=? WHERE payout_id=?", [$at,$row['payout_id']]);
                $released++;
                continue;
            }
            // Transition the reservation inside this transaction before the
            // normal debit. Other reservations remain protected. A failure
            // rolls this transition and all preceding payments back together.
            q("UPDATE weekly_payout_reservations SET status='paid',settled_at=? WHERE payout_id=?", [$at,$row['payout_id']]);
            if (debit_wallet($u['id'], (float)$row['amount'], 'payout', (int)$row['payout_id'],
                'Weekly payout report #' . (int)$statementId . ' marked paid') === null) {
                throw new RuntimeException('A reserved wallet balance is insufficient. No report payments were recorded.');
            }
            q("UPDATE payouts SET status='paid',paid_at=?,processed_by=? WHERE id=?", [$at,$adminId,$row['payout_id']]);
            q("UPDATE users SET total_withdrawn=total_withdrawn+? WHERE id=?", [$row['amount'],$u['id']]);
            $paid++;
        }
        if ($action === 'release') {
            if (!$released) { return [false, 'There are no remaining reservations to release.']; }
            q("UPDATE weekly_payout_runs SET status='unpaid' WHERE statement_id=?", [(int)$statementId]);
            return [true, $released . ' reservation(s) released; members remain unpaid and no wallet was debited or refunded.'];
        }
        // Paid is a report-level finalization, including an empty batch; it
        // never changes blocked/released member rows to paid.
        q("UPDATE weekly_payout_runs SET status='paid',paid_at=?,paid_by=? WHERE statement_id=?", [$at,$adminId,$statementId]);
        return [true, 'Report marked Paid: ' . $paid . ' eligible member payout(s) recorded.'
            . ($released ? ' ' . $released . ' ineligible reservation(s) released unpaid without a wallet debit.' : '')
            . ' Other ineligible members remain unpaid. No bank transfer was initiated.'];
    });
}
