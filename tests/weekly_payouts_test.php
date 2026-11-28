<?php
/**
 * Destructive integration tests. ONLY run against a disposable yash_test_* DB.
 *
 * YASH_TEST_DB=yash_test_weekly YASH_TEST_ALLOW_RESET=1 php tests/weekly_payouts_test.php lifecycle
 * Repeat for scheduling, auto_visit, legacy, upgrade, reconciliation, rollback,
 * multiweek, eligibility, kyc and storage.
 * Each scenario drops and reseeds all application tables in its test database.
 */
define('YASH_CLI', true);
if (!defined('DB_NAME')) { define('DB_NAME', (string)getenv('YASH_TEST_DB')); }
$allowReset = defined('YASH_TEST_ALLOW_RESET') ? YASH_TEST_ALLOW_RESET : getenv('YASH_TEST_ALLOW_RESET');
if (!preg_match('/^yash_test_[a-z0-9_]+$/', DB_NAME) || (string)$allowReset !== '1') {
    fwrite(STDERR, "Refusing to run: select a disposable yash_test_* DB and set YASH_TEST_ALLOW_RESET=1.\n");
    exit(1);
}
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/payout_lib.php';
require __DIR__ . '/../includes/income_recalculation.php';
require __DIR__ . '/../includes/kyc.php';
require __DIR__ . '/../install/seed.php';
$scenario = defined('WEEKLY_TEST_SCENARIO') ? WEEKLY_TEST_SCENARIO : ($argv[1] ?? '');
if (!in_array($scenario, ['lifecycle', 'scheduling', 'auto_visit', 'legacy', 'upgrade', 'reconciliation', 'rollback', 'multiweek', 'eligibility', 'kyc', 'storage'], true)) {
    fwrite(STDERR, "Choose lifecycle, scheduling, auto_visit, legacy, upgrade, reconciliation, rollback, multiweek, eligibility, kyc or storage.\n"); exit(1);
}
seed_database(true);
$checks = 0;
function w_check($condition, $message)
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++; echo 'PASS: ' . $message . "\n";
}
function w_eq($actual, $expected, $message)
{
    w_check(is_numeric($actual) && is_numeric($expected) ? abs((float)$actual - (float)$expected) < 0.001 : $actual === $expected,
        $message . ' (got ' . json_encode($actual) . ', expected ' . json_encode($expected) . ')');
}
function w_member($name)
{
    [$ok, $id, $message] = register_distributor(['sponsor' => '1', 'leg' => 'L', 'full_name' => 'Test ' . $name,
        'email' => '', 'mobile' => '9876543210', 'password' => 'TestPassword123', 'dob' => '', 'marital_status' => '',
        'nationality' => 'Indian', 'address' => '', 'city' => '', 'state' => '', 'pincode' => '', 'nominee_name' => '',
        'nominee_relation' => '', 'bank_holder' => '', 'bank_account_no' => '', 'bank_ifsc' => '', 'bank_name' => '',
        'bank_branch' => '', 'aadhaar_no' => '', 'pan_no' => '']);
    if (!$ok) { throw new RuntimeException('Test member registration failed: ' . $message); }
    return $id;
}
function w_bank($id)
{
    q("UPDATE users SET kyc_status='verified',bank_name='Test Bank',bank_account_no=?,bank_ifsc='TEST0001',bank_holder='Test Holder' WHERE id=?",
        ['123456' . $id, $id]);
}
function w_earning($id, $type, $amount, $date)
{
    $GLOBALS['__commission_clock'] = $date;
    try {
        db_tx(function () use ($id, $type, $amount) {
            income_rules_ready(true);
            add_commission($id, null, $type, $amount, 0, null, 'Weekly test income');
        });
    } finally { unset($GLOBALS['__commission_clock']); }
}
function w_dates()
{
    $sat = weekly_latest_completed_saturday();
    $d = new DateTimeImmutable($sat, new DateTimeZone(APP_TIMEZONE));
    return [$sat, $d->modify('-14 days')->format('Y-m-d') . ' 12:00:00',
        $d->modify('-2 days')->format('Y-m-d') . ' 12:00:00',
        $d->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
        $d->modify('+7 days')->format('Y-m-d'), $d->modify('+8 days')->setTime(0, 1)];
}
function w_digest($sql)
{
    return hash('sha256', json_encode(q_all($sql), JSON_UNESCAPED_UNICODE));
}
function w_row($statementId, $userId)
{
    foreach (payout_statement_rows($statementId) as $row) { if ((int)$row['user_id'] === (int)$userId) { return $row; } }
    return null;
}

if ($scenario === 'lifecycle') {
    [$sat, $old, $within, $newWeek, $nextSat, $nextSunday] = w_dates();
    $a = 1; $b = w_member('Below minimum'); $c = w_member('Missing KYC'); $d = w_member('Missing bank');
    $e = w_member('Blocked'); $f = w_member('KYC changes'); $g = w_member('Second eligible');
    foreach ([$a, $b, $c, $d, $e, $f, $g] as $id) { w_bank($id); }
    q("UPDATE users SET kyc_status='pending' WHERE id=?", [$c]);
    q("UPDATE users SET bank_account_no=NULL WHERE id=?", [$d]);
    q("UPDATE users SET status='blocked' WHERE id=?", [$e]);
    w_earning($a, 'sponsor', 300, $old); w_earning($a, 'binary', 200, $within);
    w_earning($a, 'sponsor_matching', 100, $within); w_earning($a, 'rank', 100, $within);
    w_earning($a, 'binary', 300, $newWeek);
    w_earning($b, 'sponsor', 499.99, $old); w_earning($c, 'binary', 600, $old);
    w_earning($d, 'rank', 650, $old); w_earning($e, 'sponsor', 700, $old);
    w_earning($f, 'binary', 800, $old); w_earning($g, 'sponsor', 500, $within);
    [$ok, $ts, $s] = generate_payout_report(); $statement = $s['statement_id'];
    w_check($ok && $s['outcome'] === 'created', 'the completed week generates a report');
    w_eq($s['period_end'], $sat, 'report ends on the latest completed Saturday');
    w_eq($s['created'], 3, 'only three eligible members receive reservations');
    w_eq($s['blocked'], 4, 'below-minimum, KYC, bank and blocked members remain unpaid');
    w_eq($s['total'], 2000, 'gross includes all eligible income types and earlier carry-forward');
    w_eq($s['net'], 1800, 'TDS and admin deductions use generation-time rates');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$a]), 1000, 'generation does not debit the wallet');
    w_eq(weekly_reserved_amount($a), 700, 'completed-week income is reserved, current-week income is not');
    $walletSnapshot = weekly_wallet_snapshot($a);
    w_check((float)$walletSnapshot['wallet_balance'] === 1000.0 && $walletSnapshot['reserved'] === 700.0
        && $walletSnapshot['available'] === 300.0, 'wallet display reads balance, reservation and availability together');
    w_eq(payout_eligible_amount($a), 300, 'reservation offsets entitlement exactly once');
    $aRow = w_row($statement, $a); $fRow = w_row($statement, $f);
    w_eq(payout_statement_status($aRow)[0], 'Unpaid', 'a newly generated report member is Unpaid');
    w_eq(w_row($statement, $b)['gross'], 499.99, 'below-minimum income is visible and carried forward');
    w_check(strpos(w_row($statement, $c)['reason'], 'KYC') !== false, 'missing KYC is reported');
    w_check(strpos(w_row($statement, $d)['reason'], 'bank') !== false, 'missing bank is reported');
    w_check(strpos(w_row($statement, $e)['reason'], 'active') !== false, 'blocked member is reported');
    $snapshot = w_digest('SELECT * FROM payout_statement_rows WHERE statement_id=' . (int)$statement . ' ORDER BY id');
    $txCount = (int)q_val('SELECT COUNT(*) FROM wallet_transactions WHERE user_id=?', [$a]);
    w_check(debit_wallet($a, 301, 'purchase', null, 'Reservation test') === null, 'purchase cannot spend a reservation');
    w_check(debit_wallet($a, 300, 'purchase', null, 'Unreserved funds') !== null, 'unreserved funds may still be spent');
    w_eq(q_val('SELECT COUNT(*) FROM wallet_transactions WHERE user_id=?', [$a]), $txCount + 1, 'failed debit has no wallet ledger entry');
    w_earning($a, 'binary', 600, $newWeek);
    [$manualOk, $manualResult] = request_payout($a, 700);
    w_check(!$manualOk && strpos($manualResult, 'available wallet') !== false, 'manual withdrawal cannot use reserved funds even with sufficient entitlement');
    [$manualOk, $manual] = request_payout($a, 600);
    w_check($manualOk, 'manual withdrawal can use additional unreserved funds');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$a]), 700, 'manual request keeps its immediate-debit behavior');
    w_check(!process_payout($aRow['payout_id'], 'paid', 1)[0] && !process_payout($aRow['payout_id'], 'reject', 1)[0], 'weekly requests cannot be paid or refunded individually');
    [$ok2, $ts2, $again] = generate_payout_report();
    w_check($ok2 && $again['outcome'] === 'existing' && $again['statement_id'] === $statement, 'repeated generation returns the same report');
    w_eq(q_val('SELECT COUNT(*) FROM payout_statements WHERE period_date=?', [$sat]), 1, 'duplicate generation never creates another weekly statement');
    w_check(process_weekly_payout_report($statement, 'in_process', 1)[0], 'superadmin marks the report In Process');
    w_eq(payout_statement_status(w_row($statement, $a))[0], 'In Process', 'member status follows In Process');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$a]), 700, 'In Process does not debit a reservation');
    q("UPDATE users SET kyc_status='pending' WHERE id=?", [$f]);
    w_check(process_weekly_payout_report($statement, 'paid', 1)[0], 'the batch marks all still-eligible reservations Paid');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$a]), 0, 'first eligible wallet is debited exactly once');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$g]), 0, 'second eligible wallet is debited in the same batch');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$f]), 800, 'member whose KYC changed is not debited or refunded');
    w_eq(q_val('SELECT total_withdrawn FROM users WHERE id=?', [$a]), 700, 'total withdrawn increases by paid gross only');
    $walletSnapshot = weekly_wallet_snapshot($a);
    w_check((float)$walletSnapshot['wallet_balance'] === 0.0 && $walletSnapshot['reserved'] === 0.0
        && (float)$walletSnapshot['total_withdrawn'] === 700.0, 'wallet display reflects paid settlement consistently');
    w_eq(q_val('SELECT total_earned FROM users WHERE id=?', [$a]), 1600, 'paid payout does not change lifetime earned');
    w_eq(payout_statement_status(w_row($statement, $a))[0], 'Paid', 'member sees Paid after settlement');
    w_eq(payout_statement_status(w_row($statement, $f))[0], 'Unpaid · released', 'changed KYC remains unpaid and is released');
    w_eq(payout_statement_status(w_row($statement, $b))[0], 'Unpaid', 'a blocked row remains unpaid even when the batch is Paid');
    w_eq(w_digest('SELECT * FROM payout_statement_rows WHERE statement_id=' . (int)$statement . ' ORDER BY id'), $snapshot, 'report snapshot is unchanged by later status transitions');
    w_eq(q_val("SELECT COUNT(*) FROM wallet_transactions WHERE ref_type='payout' AND ref_id=? AND type='debit'", [$aRow['payout_id']]), 1, 'exactly one wallet debit records the paid reservation');
    w_check(!process_weekly_payout_report($statement, 'paid', 1)[0], 'a repeated Paid action is refused');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$a]), 0, 'repeated Paid does not change the wallet');
    w_check(process_payout($manual, 'reject', 1)[0], 'separate held manual payout still refunds on rejection');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$a]), 600, 'legacy-style manual refund restores only the held amount');
    w_eq(q_val('SELECT total_earned FROM users WHERE id=?', [$a]), 1600, 'manual refund does not inflate lifetime earned');
    w_bank($c); w_bank($d); w_bank($f); q("UPDATE users SET status='active' WHERE id=?", [$e]);
    w_earning($b, 'binary', 0.01, $newWeek);
    [$ok3, $ts3, $next] = generate_payout_report($nextSat, $nextSunday);
    w_check($ok3 && $next['created'] === 6, 'next week reserves newly eligible carry-forward members');
    w_eq(w_row($next['statement_id'], $b)['gross'], 500, 'below-minimum income carries forward into next week');
    w_eq(w_row($next['statement_id'], $c)['gross'], 600, 'KYC-blocked income carries forward');
    w_eq(w_row($next['statement_id'], $f)['gross'], 800, 'released income is eligible in a later report');
    w_eq(weekly_reserved_amount($a), 600, 'new report reserves only the available wallet cap');
}

if ($scenario === 'scheduling') {
    $zone = new DateTimeZone(APP_TIMEZONE);
    $justBefore = new DateTimeImmutable('2026-08-08 23:59:59', $zone);
    $justAfter = new DateTimeImmutable('2026-08-09 00:00:00', $zone);
    w_eq(weekly_latest_completed_saturday($justBefore), '2026-08-01', 'Saturday is not complete before local midnight');
    w_eq(weekly_latest_completed_saturday($justAfter), '2026-08-08', 'the completed week changes at Sunday midnight');
    w_eq(weekly_latest_completed_saturday(new DateTimeImmutable('2026-08-08 18:29:59', new DateTimeZone('UTC'))), '2026-08-01', 'UTC input is converted to application timezone before cutoff');
    w_eq(weekly_latest_completed_saturday(new DateTimeImmutable('2026-08-08 18:30:00', new DateTimeZone('UTC'))), '2026-08-08', 'the UTC instant at local Sunday midnight closes the week');
    w_check(!weekly_valid_period('2026-08-08', $justBefore), 'an unfinished Saturday cannot be generated');
    w_check(!weekly_valid_period('2026-08-07', $justAfter) && !weekly_valid_period('2026-02-30', $justAfter), 'invalid dates and non-Saturdays are refused');
    [$ok, $ts, $bad] = generate_payout_report('2026-08-08', $justBefore);
    w_check(!$ok && !empty($bad['error']), 'invalid report generation returns an actionable error');
    w_eq(q_val('SELECT COUNT(*) FROM payout_statements'), 0, 'invalid generation creates no statement');
    $first = weekly_payout_run_due($justBefore);
    w_check(count($first) === 1 && $first[0]['period_end'] === '2026-08-01', 'first scheduler run starts with latest completed week, not artificial history');
    w_check(process_weekly_payout_report($first[0]['statement_id'], 'in_process', 1)[0], 'even an empty report can be marked In Process');
    [$emptyPaid, $emptyMessage] = process_weekly_payout_report($first[0]['statement_id'], 'paid', 1);
    w_check($emptyPaid && strpos($emptyMessage, '0 eligible member') !== false, 'empty report can be finalized without claiming a member payment');
    w_eq(count(weekly_payout_run_due($justBefore)), 0, 'a repeated invocation is a no-op');
    $next = weekly_payout_run_due($justAfter);
    w_check(count($next) === 1 && $next[0]['period_end'] === '2026-08-08', 'first Sunday invocation creates the newly completed week');
    $later = weekly_payout_run_due(new DateTimeImmutable('2026-08-30 00:01:00', $zone));
    w_check(count($later) === 3 && $later[0]['period_end'] === '2026-08-15' && $later[2]['period_end'] === '2026-08-29', 'missed weeks are generated oldest first');
    w_eq(q_val('SELECT COUNT(*) FROM weekly_payout_runs'), 5, 'each completed week is represented once');
    seed_database(true);
    $oldEnd = (new DateTimeImmutable(weekly_latest_completed_saturday(), $zone))->modify('-60 weeks')->format('Y-m-d');
    w_check(generate_payout_report($oldEnd)[0], 'older completed report can seed catch-up');
    $part = weekly_payout_run_due();
    w_eq(count($part), 52, 'one invocation limits catch-up to 52 weeks');
    w_eq(count(weekly_payout_run_due()), 8, 'a later invocation resumes the remaining weeks');
    w_eq(count(weekly_payout_run_due()), 0, 'catch-up completes without duplicates');
}

if ($scenario === 'auto_visit') {
    $zone = new DateTimeZone(APP_TIMEZONE);
    $sunday = new DateTimeImmutable('2026-08-09 23:59:59', $zone);
    $monday = new DateTimeImmutable('2026-08-10 00:00:00', $zone);
    w_eq(weekly_latest_auto_saturday($sunday), '2026-08-01', 'Sunday auto-run excludes the just-closed Saturday');
    w_eq(weekly_latest_auto_saturday($monday), '2026-08-08', 'automatic eligibility changes at Monday midnight');
    w_eq(weekly_latest_auto_saturday(new DateTimeImmutable('2026-08-09 18:29:59', new DateTimeZone('UTC'))), '2026-08-01', 'automatic cutoff uses the application timezone before Monday');
    w_eq(weekly_latest_auto_saturday(new DateTimeImmutable('2026-08-09 18:30:00', new DateTimeZone('UTC'))), '2026-08-08', 'UTC instant at local Monday midnight is eligible');
    w_bank(1); w_earning(1, 'binary', 1000, '2026-07-29 12:00:00');
    $old = weekly_payout_auto_run_due($sunday);
    w_check(count($old) === 1 && $old[0]['period_end'] === '2026-08-01', 'a Sunday visit catches up an older overdue week');
    w_eq(q_val('SELECT COUNT(*) FROM weekly_payout_runs WHERE period_end=?', ['2026-08-08']), 0, 'Sunday visit does not automatically run the newly closed week');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 1000, 'automatic generation does not debit the wallet');
    w_eq(q_val("SELECT status FROM weekly_payout_runs WHERE period_end='2026-08-01'"), 'unpaid', 'automatic report remains Unpaid');
    w_eq(q_val("SELECT COALESCE(SUM(amount),0) FROM weekly_payout_reservations WHERE status='reserved'"), 1000, 'eligible funds are reserved once');
    w_eq(count(weekly_payout_auto_run_due($sunday)), 0, 'repeated Sunday visits do not duplicate reports');
    $new = weekly_payout_auto_run_due($monday);
    w_check(count($new) === 1 && $new[0]['period_end'] === '2026-08-08', 'first Monday visit generates the due week');
    w_eq(count(weekly_payout_auto_run_due($monday)), 0, 'refreshing on Monday is idempotent');
    $caughtUp = weekly_payout_auto_run_due(new DateTimeImmutable('2026-09-01 11:00:00', $zone));
    w_check(count($caughtUp) === 3 && $caughtUp[0]['period_end'] === '2026-08-15'
        && $caughtUp[2]['period_end'] === '2026-08-29', 'a later Tuesday visit catches up missed Mondays in order');
    w_eq(q_val('SELECT COUNT(*) FROM weekly_payout_runs'), 5, 'one report exists per due week');
    w_eq(q_val("SELECT COUNT(*) FROM weekly_payout_runs WHERE status='paid'"), 0, 'automatic visits never mark reports Paid');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 1000, 'catch-up does not make a payment or debit');
}

if ($scenario === 'legacy') {
    [$sat, $old, $within, $newWeek, $nextSat, $nextSunday] = w_dates();
    w_bank(1); w_earning(1, 'binary', 2000, $old);
    [$ok, $p1] = request_payout(1, 700); [$ok2, $p2] = request_payout(1, 600);
    w_check($ok && $ok2, 'legacy-style manual requests are already held');
    foreach ([$p1, $p2] as $p) {
        q("INSERT INTO payout_statements (run_ts,period_date,total_gross,total_tds,total_admin,total_net,members_done,members_blocked,created_at)
           VALUES (?,?,0,0,0,0,1,0,?)", [now(),$sat,now()]);
        $sid = (int)db()->lastInsertId();
        q("INSERT INTO payout_statement_rows (statement_id,user_id,username,full_name,mobile,gross,payout_done,payout_id)
           VALUES (?,1,'YSH100001','Legacy member','9876543210',?,1,?)", [$sid, $p === $p1 ? 700 : 600, $p]);
    }
    $payouts = w_digest('SELECT * FROM payouts ORDER BY id');
    $statements = w_digest('SELECT * FROM payout_statements ORDER BY id');
    $rows = w_digest('SELECT * FROM payout_statement_rows ORDER BY id');
    [$ok3, $ts, $s] = generate_payout_report();
    w_check($ok3 && $s['outcome'] === 'legacy', 'historical duplicate reports suppress a new report for the week');
    w_eq(w_digest('SELECT * FROM payouts ORDER BY id'), $payouts, 'legacy payouts are not rewritten by generation');
    w_eq(w_digest('SELECT * FROM payout_statements ORDER BY id'), $statements, 'duplicate legacy headers are unchanged');
    w_eq(w_digest('SELECT * FROM payout_statement_rows ORDER BY id'), $rows, 'legacy snapshots remain unchanged');
    w_eq(q_val("SELECT status FROM weekly_payout_runs WHERE period_end=?", [$sat]), 'legacy', 'a legacy marker prevents later duplication');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 700, 'legacy report detection does not debit again');
    w_check(process_payout($p1, 'paid', 1)[0], 'old held request can still be individually marked paid');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 700, 'marking an old held request paid does not debit it again');
    w_check(process_payout($p2, 'reject', 1)[0] && !process_payout($p2, 'reject', 1)[0], 'old held request is refunded only once');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 1300, 'legacy refund returns exactly the held gross');
    w_eq(q_val('SELECT total_earned FROM users WHERE id=1'), 2000, 'legacy refund does not change earned income');
    $next = weekly_payout_run_due($nextSunday);
    w_check(count($next) === 1 && $next[0]['created'] === 1, 'later new report includes remaining eligible legacy income');
    w_eq(weekly_reserved_amount(1), 1300, 'new report reserves only unpaid income after legacy paid/rejected requests');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 1300, 'the new reservation remains in the wallet');
}

if ($scenario === 'upgrade') {
    [$sat, $old] = w_dates();
    w_bank(1); w_earning(1, 'binary', 1000, $old);
    [$ok, $payout] = request_payout(1, 600);
    w_check($ok, 'a legacy pending request is already held before the upgrade');
    q("INSERT INTO payout_statements (run_ts,period_date,total_gross,total_tds,total_admin,total_net,members_done,members_blocked,created_at)
       VALUES (?,?,600,30,30,540,1,0,?)", [now(),$sat,now()]);
    $sid = (int)db()->lastInsertId();
    q("INSERT INTO payout_statement_rows (statement_id,user_id,username,full_name,mobile,gross,payout_done,payout_id)
       VALUES (?,1,'YSH100001','Legacy','9876543210',600,1,?)", [$sid,$payout]);
    $payoutHash = w_digest('SELECT * FROM payouts ORDER BY id');
    $statementHash = w_digest('SELECT * FROM payout_statements ORDER BY id');
    $rowHash = w_digest('SELECT * FROM payout_statement_rows ORDER BY id');
    db()->exec('DROP TABLE weekly_payout_reservations'); db()->exec('DROP TABLE weekly_payout_runs');
    $failed = false;
    try { debit_wallet(1, 100, 'admin', null, 'Missing migration'); } catch (Throwable $ex) { $failed = true; }
    w_check($failed, 'missing reservation storage fails closed');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 400, 'missing storage cannot cause a partial debit');
    $sql = file_get_contents(__DIR__ . '/../DATABASE_CHANGES.sql');
    preg_match_all('/CREATE TABLE IF NOT EXISTS (?:weekly_payout_runs|weekly_payout_reservations)\s*\(.*?\) ENGINE=InnoDB[^;]*;/s', $sql, $matches);
    w_eq(count($matches[0]), 2, 'deployment SQL defines both weekly tables');
    foreach ([1, 2] as $attempt) { foreach ($matches[0] as $ddl) { db()->exec($ddl); } }
    w_eq(w_digest('SELECT * FROM payouts ORDER BY id'), $payoutHash, 'additive migration preserves legacy payout records');
    w_eq(w_digest('SELECT * FROM payout_statements ORDER BY id'), $statementHash, 'additive migration preserves legacy statement headers');
    w_eq(w_digest('SELECT * FROM payout_statement_rows ORDER BY id'), $rowHash, 'additive migration preserves legacy snapshots');
    w_eq(q_val("SELECT svalue FROM settings WHERE skey='income_rules_1850'"), 'ready', 'upgrade does not reset an already-reviewed income baseline');
    [$ok2, $ts, $s] = generate_payout_report();
    w_check($ok2 && $s['outcome'] === 'legacy', 'migrated legacy week is detected without re-holding or duplicating');
    w_check(process_payout($payout, 'paid', 1)[0], 'migrated held payout still uses individual processing');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 400, 'legacy payment after migration is not debited twice');
}

if ($scenario === 'reconciliation') {
    [$sat, $old, $within, $newWeek] = w_dates();
    w_bank(1); w_earning(1, 'binary', 1000, $old);
    [$ok, $ts, $s] = generate_payout_report(); $sid = $s['statement_id'];
    $preview = income_preview_reconciliation();
    w_eq($preview['rows'][1]['before']['payout_reserved'], 1000, 'reconciliation preview includes the current reservation');
    w_check(strpos(implode(' ', $preview['errors']), 'reserved for a weekly payout') !== false, 'negative correction cannot spend reserved funds');
    try { income_apply_reconciliation($preview['hash'], 1); w_check(false, 'conflicting reconciliation must not apply'); }
    catch (RuntimeException $ex) { w_check(strpos($ex->getMessage(), 'blocking issues') !== false, 'conflicting apply is refused'); }
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 1000, 'blocked reconciliation leaves wallet unchanged');
    $ledger = (int)q_val('SELECT COUNT(*) FROM wallet_transactions');
    w_check(process_weekly_payout_report($sid, 'release', 1)[0], 'superadmin can explicitly release an unpaid reservation');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 1000, 'release is neither a debit nor a refund');
    w_eq(q_val('SELECT COUNT(*) FROM wallet_transactions'), $ledger, 'release creates no artificial wallet transaction');
    w_eq(weekly_reserved_amount(1), 0, 'released reservation no longer blocks the wallet');
    w_eq(payout_statement_status(w_row($sid, 1))[0], 'Unpaid · released', 'released statement remains visibly unpaid');
    try { income_apply_reconciliation($preview['hash'], 1); w_check(false, 'old preview must not apply after release'); }
    catch (RuntimeException $ex) { w_check(strpos($ex->getMessage(), 'changed since') !== false, 'reservation release invalidates the old preview'); }
    $fresh = income_preview_reconciliation();
    w_eq(count($fresh['errors']), 0, 'fresh preview can proceed after release');
    income_apply_reconciliation($fresh['hash'], 1);
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 0, 'reviewed correction is appended after releasing the reservation');
    w_eq(q_val("SELECT COUNT(*) FROM commissions WHERE type='binary'"), 2, 'original income and signed correction are preserved');

    seed_database(true);
    w_bank(1); w_earning(1, 'binary', 1000, $old);
    db_tx(function () { income_rules_ready(true); credit_wallet(1, 1000, 'admin', null, 'Unreserved funds'); });
    [$ok, $ts, $s] = generate_payout_report(); $sid = $s['statement_id'];
    $p = income_preview_reconciliation();
    w_eq(count($p['errors']), 0, 'a correction funded by unreserved wallet funds is allowed');
    income_apply_reconciliation($p['hash'], 1);
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 1000, 'correction leaves the reservation fully backed');
    w_earning(1, 'binary', 1000, $newWeek);
    w_eq(payout_entitlement_difference(1), 0, 'new-week income could cover total current entitlement');
    $count = (int)q_val('SELECT COUNT(*) FROM wallet_transactions');
    [$paid, $msg] = process_weekly_payout_report($sid, 'paid', 1);
    w_check($paid && strpos($msg, '0 eligible member') !== false && strpos($msg, 'released unpaid') !== false,
        'reversed closed-week income is released instead of using future income');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 2000, 'ineligible settlement does not debit or refund');
    w_eq(q_val('SELECT COUNT(*) FROM wallet_transactions'), $count, 'ineligible settlement adds no wallet transaction');
    w_eq(payout_eligible_amount(1), 1000, 'new-week income remains available for a later report');
    w_eq(payout_statement_status(w_row($sid, 1))[0], 'Unpaid · released', 'a finalized batch does not claim the ineligible member was paid');
    w_check(!process_weekly_payout_report($sid, 'paid', 1)[0], 'retry after all rows were released is safe');
}

if ($scenario === 'rollback') {
    [$sat, $old] = w_dates();
    $b = w_member('Rollback'); w_bank(1); w_bank($b);
    w_earning(1, 'binary', 600, $old); w_earning($b, 'binary', 600, $old);
    [$ok, $ts, $s] = generate_payout_report(); $sid = $s['statement_id'];
    w_check(!process_weekly_payout_report($sid, 'paid', 2)[0], 'non-superadmin cannot settle a report');
    q("UPDATE admins SET status='blocked' WHERE id=1");
    w_check(!process_weekly_payout_report($sid, 'in_process', 1)[0], 'blocked superadmin cannot change report status');
    q("UPDATE admins SET status='active' WHERE id=1");
    // Simulate an out-of-band corrupt writer; the entire batch must roll back.
    q('UPDATE users SET wallet_balance=0 WHERE id=?', [$b]);
    $txCount = (int)q_val('SELECT COUNT(*) FROM wallet_transactions');
    try { process_weekly_payout_report($sid, 'paid', 1); w_check(false, 'a reservation shortfall must abort'); }
    catch (RuntimeException $ex) { w_check(strpos($ex->getMessage(), 'insufficient') !== false, 'a reservation shortfall aborts the batch'); }
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 600, 'a previously visited member debit rolled back');
    w_eq(q_val('SELECT total_withdrawn FROM users WHERE id=1'), 0, 'withdrawn counter rolled back');
    w_eq(q_val('SELECT COUNT(*) FROM wallet_transactions'), $txCount, 'failed batch leaves no partial ledger entries');
    w_eq(q_val("SELECT COUNT(*) FROM weekly_payout_reservations WHERE status='reserved'"), 2, 'all reservations remain reserved after rollback');
    w_eq(q_val("SELECT COUNT(*) FROM payouts WHERE status='pending'"), 2, 'all payouts remain pending after rollback');
    w_eq(q_val('SELECT status FROM weekly_payout_runs WHERE statement_id=?', [$sid]), 'unpaid', 'report status also rolls back');
    q('UPDATE users SET wallet_balance=600 WHERE id=?', [$b]);
    w_check(process_weekly_payout_report($sid, 'paid', 1)[0], 'corrected batch can be retried safely');
    w_eq(q_val("SELECT COUNT(*) FROM payouts WHERE status='paid'"), 2, 'both members are paid together after retry');
    w_eq(q_val("SELECT COUNT(*) FROM wallet_transactions WHERE ref_type='payout' AND type='debit'"), 2, 'successful retry creates one debit per member');
}

if ($scenario === 'multiweek') {
    [$sat, $old, $within, $newWeek, $nextSat, $nextSunday] = w_dates();
    w_bank(1); w_earning(1, 'binary', 1000, $old);
    [$ok, $ts, $first] = generate_payout_report();
    w_earning(1, 'binary', 1000, $newWeek);
    [$ok2, $ts2, $second] = generate_payout_report($nextSat, $nextSunday);
    w_check($ok && $ok2 && $second['created'] === 1, 'two different closed weeks can have separate reservations');
    w_eq(weekly_reserved_amount(1), 2000, 'both reservations are protected');
    w_check(process_weekly_payout_report($first['statement_id'], 'paid', 1)[0], 'later reservation does not invalidate earlier cutoff entitlement');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 1000, 'paying the earlier week leaves the later reservation in the wallet');
    w_eq(weekly_reserved_amount(1), 1000, 'later reservation remains protected after first settlement');
    w_check(debit_wallet(1, 1, 'admin', null, 'Must not spend') === null, 'remaining reservation cannot be spent');
    w_check(process_weekly_payout_report($second['statement_id'], 'paid', 1)[0], 'second report settles its own reservation');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 0, 'both payouts debit once in total');
    w_eq(q_val('SELECT total_earned FROM users WHERE id=1'), 2000, 'multiple payments do not alter lifetime earned');
}

if ($scenario === 'eligibility') {
    [$sat, $old] = w_dates();
    $b = w_member('Minimum changes'); $c = w_member('Remains eligible');
    foreach ([1, $b, $c] as $id) { w_bank($id); }
    w_earning(1, 'binary', 1000, $old); w_earning($b, 'sponsor', 800, $old); w_earning($c, 'rank', 1000, $old);
    [$ok, $ts, $s] = generate_payout_report(); $sid = $s['statement_id'];
    $rootRow = w_row($sid, 1); $bRow = w_row($sid, $b); $cRow = w_row($sid, $c);
    $snapshot = w_digest('SELECT * FROM payout_statement_rows WHERE statement_id=' . (int)$sid . ' ORDER BY id');
    q("UPDATE users SET bank_account_no='987654321' WHERE id=1");
    q('UPDATE plan_settings SET payout_min=900, tds_percent=80, admin_charge_percent=10 WHERE id=1');
    w_check(process_weekly_payout_report($sid, 'paid', 1)[0], 'batch settles members still eligible after settings and bank changes');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 1000, 'changed bank account releases a reservation without debit');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$b]), 800, 'reservation now below the minimum stays unpaid');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$c]), 0, 'still-eligible member is paid');
    w_eq(payout_statement_status(w_row($sid, 1))[0], 'Unpaid · released', 'changed-bank member status is unpaid');
    w_eq(payout_statement_status(w_row($sid, $b))[0], 'Unpaid · released', 'below-current-minimum member status is unpaid');
    w_eq(q_val('SELECT tds_amount FROM payouts WHERE id=?', [$cRow['payout_id']]), 50, 'paid payout retains its original TDS rather than current plan rate');
    w_eq(q_val('SELECT net_amount FROM payouts WHERE id=?', [$cRow['payout_id']]), 900, 'paid net retains the original generation-time split');
    w_eq(q_val("SELECT COUNT(*) FROM wallet_transactions WHERE ref_type='payout' AND ref_id IN (?,?)", [$rootRow['payout_id'],$bRow['payout_id']]), 0,
        'released reservations create neither debit nor refund entries');
    w_eq(w_digest('SELECT * FROM payout_statement_rows WHERE statement_id=' . (int)$sid . ' ORDER BY id'), $snapshot, 'bank and rate changes do not rewrite the snapshot');
}

if ($scenario === 'kyc') {
    [$sat, $old, $within, $newWeek, $nextSat, $nextSunday] = w_dates();
    w_bank(1);
    q("UPDATE users SET pan_image='kyc/old.png',aadhaar_image='kyc/aadhaar.png' WHERE id=1");
    w_earning(1, 'binary', 1000, $old);
    [$ok, $ts, $s] = generate_payout_report(); $sid = $s['statement_id'];
    w_check($ok && $s['created'] === 1, 'verified member initially has a reservation');
    $oldReview = kyc_review_snapshot(q_row('SELECT * FROM users WHERE id=1'));
    $oldImages = kyc_replace_documents(1, 'kyc/new.png', null);
    $current = q_row('SELECT * FROM users WHERE id=1');
    w_check($oldImages['pan_image'] === 'kyc/old.png' && $current['pan_image'] === 'kyc/new.png'
        && $current['aadhaar_image'] === 'kyc/aadhaar.png' && $current['kyc_status'] === 'pending',
        'replacing a document atomically makes KYC pending and keeps the untouched document');
    try { kyc_review_decide(1, 'kyc_reject', $oldReview, 'Outdated'); w_check(false, 'stale rejection must fail'); }
    catch (RuntimeException $ex) { w_check(strpos($ex->getMessage(), 'changed since') !== false, 'an old review cannot reject new KYC documents'); }
    $pendingReview = kyc_review_snapshot($current);
    kyc_replace_documents(1, 'kyc/newer.png', null);
    try { kyc_review_decide(1, 'kyc_verify', $pendingReview); w_check(false, 'stale verification must fail'); }
    catch (RuntimeException $ex) { w_check(strpos($ex->getMessage(), 'changed since') !== false, 'an old review cannot verify newly submitted KYC documents'); }
    try { kyc_replace_documents(1, '', null); w_check(false, 'invalid document path must fail'); }
    catch (InvalidArgumentException $ex) { w_check(q_val('SELECT pan_image FROM users WHERE id=1') === 'kyc/newer.png', 'invalid replacement leaves the current document intact'); }
    w_check(process_weekly_payout_report($sid, 'paid', 1)[0], 'batch can finalize after KYC changes');
    w_eq(payout_statement_status(w_row($sid, 1))[0], 'Unpaid · released', 'changed KYC is released and remains unpaid');
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 1000, 'unverified member is not debited');
    w_eq(q_val("SELECT COUNT(*) FROM wallet_transactions WHERE ref_type='payout'"), 0, 'KYC release creates no payout debit');
    $fresh = kyc_review_snapshot(q_row('SELECT * FROM users WHERE id=1'));
    kyc_review_decide(1, 'kyc_verify', $fresh);
    w_eq(q_val('SELECT kyc_status FROM users WHERE id=1'), 'verified', 'a fresh review can verify current documents');
    [$nextOk, $nextTs, $next] = generate_payout_report($nextSat, $nextSunday);
    w_check($nextOk && $next['created'] === 1, 'verified carry-forward can be reserved in a later report');
    w_eq(weekly_reserved_amount(1), 1000, 'later report reserves the original unpaid amount');
}

if ($scenario === 'storage') {
    // The storage guard must fail closed before any debit if a required table
    // lacks transactional semantics. Restore its engine for the next reset.
    db()->exec('ALTER TABLE weekly_payout_reservations ENGINE=MyISAM');
    try { debit_wallet(1, 1, 'admin', null, 'Test'); w_check(false, 'nontransactional reservation storage must fail'); }
    catch (RuntimeException $ex) { w_check(strpos($ex->getMessage(), 'InnoDB') !== false, 'nontransactional reservation storage is refused'); }
    w_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 0, 'storage failure does not change the wallet');
    db()->exec('ALTER TABLE weekly_payout_reservations ENGINE=InnoDB');
}

echo "OK: $scenario ($checks checks)\n";
