<?php
/**
 * DESTRUCTIVE integration tests, only for an explicitly named disposable DB.
 *
 * Example (PHP 7.4+ with PDO MySQL; create an empty database first):
 *   YASH_TEST_DB=yash_test_income YASH_TEST_ALLOW_RESET=1 php tests/income_rules_test.php tree
 * Repeat with: historical, pending, shortfall, migration, integrity, custom_plan,
 * rank, payouts, moves, offset, payout_timing, schema_guard and registration.
 * These tests drop/reseed every application table in that database.
 */
define('YASH_CLI', true);
if (!defined('DB_NAME')) { define('DB_NAME', (string)getenv('YASH_TEST_DB')); }
$allowReset = defined('YASH_TEST_ALLOW_RESET') ? YASH_TEST_ALLOW_RESET : getenv('YASH_TEST_ALLOW_RESET');
if (!preg_match('/^yash_test_[a-z0-9_]+$/', DB_NAME) || (string)$allowReset !== '1') {
    fwrite(STDERR, "Refusing to run: select a disposable yash_test_* DB and set YASH_TEST_ALLOW_RESET=1.\n");
    exit(1);
}
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/income_recalculation.php';
require __DIR__ . '/../install/seed.php';
$scenario = defined('INCOME_TEST_SCENARIO') ? INCOME_TEST_SCENARIO : ($argv[1] ?? '');
if (!in_array($scenario, ['tree', 'historical', 'pending', 'shortfall', 'migration', 'integrity', 'custom_plan', 'rank', 'payouts',
    'moves', 'offset', 'payout_timing', 'schema_guard', 'registration'], true)) {
    fwrite(STDERR, "Choose tree, historical, pending, shortfall, migration, integrity, custom_plan, rank, payouts, moves, offset, payout_timing, schema_guard or registration.\n"); exit(1);
}
seed_database(true);
$checks = 0;
function test_check($condition, $message)
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++; echo 'PASS: ' . $message . "\n";
}
function test_eq($actual, $expected, $message)
{
    test_check(is_numeric($actual) && is_numeric($expected) ? abs((float)$actual - (float)$expected) < 0.001 : $actual === $expected,
        $message . ' (got ' . json_encode($actual) . ', expected ' . json_encode($expected) . ')');
}
function test_member($name, $sponsor, $parent, $leg)
{
    [$ok, $id, $code] = register_distributor(['sponsor' => (string)$sponsor, 'placement' => $parent, 'placement_leg' => $leg, 'leg' => $leg,
        'full_name' => 'Test ' . $name, 'email' => '', 'mobile' => '9876543210', 'password' => 'TestPassword123',
        'dob' => '', 'marital_status' => '', 'nationality' => 'Indian', 'address' => '', 'city' => '', 'state' => '',
        'pincode' => '', 'nominee_name' => '', 'nominee_relation' => '', 'bank_holder' => '', 'bank_account_no' => '',
        'bank_ifsc' => '', 'bank_name' => '', 'bank_branch' => '', 'aadhaar_no' => '', 'pan_no' => '']);
    if (!$ok) { throw new RuntimeException('Test member registration failed: ' . $code); }
    return $id;
}
function test_order($id, $bv)
{
    static $n = 0; $n++;
    q("INSERT INTO orders (order_no, user_id, total_bv, payment_mode, payment_status, status, created_at)
       VALUES (?, ?, ?, 'bank_transfer', 'paid', 'pending', ?)", ['TEST-' . $n, $id, $bv, now()]);
    $oid = (int)db()->lastInsertId();
    [$ok, $message] = approve_order($oid, 1);
    if (!$ok) { throw new RuntimeException('Test order failed: ' . $message); }
    return $oid;
}
function test_tree()
{
    $m = ['X' => 1];
    $m['A'] = test_member('A', 1, 1, 'L');
    $defs = [
        ['B','X','A','L'], ['C','A','A','R'], ['D','X','B','L'], ['E','X','B','R'],
        ['F','X','C','L'], ['G','A','C','R'], ['H','X','D','L'], ['I','X','D','R'],
        ['J','X','E','L'], ['K','X','E','R'], ['L','X','F','L'], ['M','X','F','R'],
        ['N','X','G','L'], ['O','A','G','R'],
    ];
    foreach ($defs as [$name, $sponsor, $parent, $leg]) { $m[$name] = test_member($name, $m[$sponsor], $m[$parent], $leg); }
    return $m;
}
function test_digest($sql)
{
    return hash('sha256', json_encode(q_all($sql), JSON_UNESCAPED_UNICODE));
}
function test_legacy_fixture()
{
    $m = test_tree();
    // Saved BV is authoritative; order totals are deliberately different for O.
    foreach ($m as $name => $id) {
        if ($name === 'X') { continue; }
        q("UPDATE users SET self_bv=3000, is_active=1, activated_at='2025-01-01 00:00:00' WHERE id=?", [$id]);
        q("INSERT INTO orders (order_no,user_id,total_bv,payment_mode,payment_status,status,approved_at,created_at)
           VALUES (?, ?, ?, 'bank_transfer', 'paid', 'approved', '2025-01-01 00:00:00', '2025-01-01 00:00:00')",
            ['OLD-' . $name, $id, $name === 'O' ? 1500 : 3000]);
    }
    // Simulate the old one-per-leg / first-pair ledger, including a payout.
    add_commission($m['A'], null, 'sponsor', 600, 3000, 1, 'Legacy sponsor');
    add_commission($m['A'], null, 'binary', 450, 3000, 1, 'Legacy first pair');
    add_commission($m['A'], null, 'sponsor_matching', 225, 0, 1, 'Legacy matching');
    q("INSERT INTO payouts (user_id,request_no,amount,tds_amount,admin_charge,net_amount,status,paid_at,created_at)
       VALUES (?, 'OLD-PAYOUT', 500, 25, 25, 450, 'paid', '2025-01-03 00:00:00', '2025-01-02 00:00:00')", [$m['A']]);
    debit_wallet($m['A'], 500, 'payout', (int)db()->lastInsertId(), 'Legacy payout');
    q("UPDATE users SET total_withdrawn=500 WHERE id=?", [$m['A']]);
    q("DELETE FROM income_matching_state"); q("DELETE FROM income_sponsor_events"); q("DELETE FROM income_rank_events");
    q("DELETE FROM settings WHERE skey='income_rules_1850'");
    return $m;
}

if ($scenario === 'tree') {
    $m = test_tree(); $oids = [];
    foreach ($m as $name => $id) { if ($name !== 'X') { $oids[$name] = test_order($id, 3000); } }
    $a = q_row('SELECT * FROM users WHERE id=?', [$m['A']]); $aEarn = user_earnings_breakdown($m['A']);
    test_eq($a['left_bv'], 21000, 'A has seven 3000-BV members on the left');
    test_eq($a['right_bv'], 21000, 'A has seven 3000-BV members on the right');
    test_eq($aEarn['sponsor'], 900, 'A receives 300 each for personally sponsored C, G and O');
    test_eq($aEarn['binary'], 3150, 'A receives 450 for each of seven matched pairs');
    test_eq($aEarn['sponsor_matching'], 450, 'A receives one 225 first-match bonus from each of B and C');
    test_eq($aEarn['total'], 4500, 'A total is 4500, excluding unrelated rewards');
    test_eq(user_earnings_breakdown($m['B'])['sponsor'], 0, 'B earns no sponsor income');
    test_check(user_earnings_breakdown($m['B'])['binary'] > 0, 'B still earns matching without sponsor income');
    foreach (['B','D'] as $name) {
        $event = q_row('SELECT * FROM income_sponsor_events WHERE member_id=?', [$m[$name]]);
        test_eq((int)$event['sponsor_id'], $m['X'], 'X is the sponsor paid for ' . $name);
        test_eq($event['amount'], 300, 'X bonus for ' . $name . ' is 300');
    }
    $b = q_row('SELECT * FROM income_matching_state WHERE user_id=?', [$m['B']]);
    test_eq((int)$b['first_parent_id'], $m['A'], 'B first-match bonus follows placement to A, not sponsor X');
    test_eq($b['first_bonus_status'], 'paid', 'B first-match bonus paid once');
    test_check(!approve_order($oids['B'], 1)[0], 'an already approved order cannot run twice');
    test_order($m['H'], 3000); test_order($m['L'], 3000);
    $aEarn = user_earnings_breakdown($m['A']);
    test_eq($aEarn['binary'], 3600, 'an eighth pair pays a further 450');
    test_eq($aEarn['sponsor_matching'], 450, 'later child matches do not repeat first-match bonuses');
    test_eq($aEarn['sponsor'], 900, 'repeat purchases do not repeat sponsor income');
}

if ($scenario === 'historical') {
    $m = test_legacy_fixture();
    $profiles = test_digest('SELECT id,username,password,full_name,email,mobile,sponsor_id,placement_id,leg,path,depth,status,is_active,activated_at,self_bv,kyc_status FROM users ORDER BY id');
    $orders = test_digest('SELECT * FROM orders ORDER BY id');
    $payouts = test_digest('SELECT * FROM payouts ORDER BY id');
    $originalCommissions = test_digest('SELECT * FROM commissions WHERE id<=3 ORDER BY id');
    $originalWallet = test_digest('SELECT * FROM wallet_transactions WHERE id<=4 ORDER BY id');
    $p = income_preview_reconciliation(); $a = $p['rows'][$m['A']];
    test_eq(count($p['errors']), 0, 'legacy fixture preview has no blocking errors');
    test_eq($p['summary']['self_bv_discrepancies'], 1, 'preview flags saved Self BV differing from approved orders');
    test_eq($p['rows'][$m['O']]['self_bv_difference'], 1500, 'difference for O is visible');
    test_eq($a['after']['earnings']['sponsor'], 900, 'historical sponsor projection uses all three referrals');
    test_eq($a['after']['earnings']['binary'], 3150, 'historical projection pays all seven pairs');
    test_eq($a['after']['earnings']['sponsor_matching'], 450, 'historical parent bonus projection uses placement');
    test_eq($a['net_adjustment'], 3225, 'legacy A income is adjusted by the difference');
    $result = income_apply_reconciliation($p['hash'], 1);
    test_eq(test_digest('SELECT id,username,password,full_name,email,mobile,sponsor_id,placement_id,leg,path,depth,status,is_active,activated_at,self_bv,kyc_status FROM users ORDER BY id'), $profiles, 'profiles, placements, sponsors, activation and Self BV remain unchanged');
    test_eq(test_digest('SELECT * FROM orders ORDER BY id'), $orders, 'all order rows remain unchanged');
    test_eq(test_digest('SELECT * FROM payouts ORDER BY id'), $payouts, 'payout history remains unchanged');
    test_eq(test_digest('SELECT * FROM commissions WHERE id<=3 ORDER BY id'), $originalCommissions, 'original commission rows remain unchanged');
    test_eq(test_digest('SELECT * FROM wallet_transactions WHERE id<=4 ORDER BY id'), $originalWallet, 'original wallet history remains unchanged');
    test_eq(user_earnings_breakdown($m['A'])['total'], 4500, 'A historical earnings total reconciles to 4500');
    test_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$m['A']]), 4000, 'A wallet receives only the 3225 adjustment after old payout');
    test_eq(q_val('SELECT COUNT(*) FROM income_recalc_entries WHERE run_id=?', [$result['run_id']]), count($m), 'audit records a before and after entry for every member');
    $commissionCount = (int)q_val('SELECT COUNT(*) FROM commissions'); $walletCount = (int)q_val('SELECT COUNT(*) FROM wallet_transactions');
    $again = income_preview_reconciliation(); income_apply_reconciliation($again['hash'], 1);
    test_eq(q_val('SELECT COUNT(*) FROM commissions'), $commissionCount, 'repeating recalculation does not duplicate commissions');
    test_eq(q_val('SELECT COUNT(*) FROM wallet_transactions'), $walletCount, 'repeating recalculation does not duplicate wallet entries');
    test_order($m['B'], 3000); test_order($m['C'], 3000);
    $earn = user_earnings_breakdown($m['A']);
    test_eq($earn['binary'], 3600, 'future orders earn only the next new pair after reconciliation');
    test_eq($earn['sponsor'], 900, 'future purchases do not repay historical sponsor events');
    test_eq($earn['sponsor_matching'], 450, 'future matching does not repeat historical first-child bonuses');
    $stale = income_preview_reconciliation();
    db_tx(function () use ($m) { credit_wallet($m['A'], 10, 'admin', null, 'Test concurrent change'); });
    $runs = (int)q_val('SELECT COUNT(*) FROM income_recalc_runs');
    try { income_apply_reconciliation($stale['hash'], 1); test_check(false, 'stale preview must fail'); }
    catch (RuntimeException $ex) { test_check(strpos($ex->getMessage(), 'changed since') !== false, 'stale preview is rejected'); }
    test_eq(q_val('SELECT COUNT(*) FROM income_recalc_runs'), $runs, 'stale preview creates no audit run');
    $fresh = income_preview_reconciliation();
    q("UPDATE admins SET status='blocked' WHERE id=1");
    try { income_apply_reconciliation($fresh['hash'], 1); test_check(false, 'blocked superadmin must not apply'); }
    catch (RuntimeException $ex) { test_check(strpos($ex->getMessage(), 'active superadmin') !== false, 'blocked superadmin cannot apply even with an earlier preview'); }
    test_eq(q_val('SELECT COUNT(*) FROM income_recalc_runs'), $runs, 'blocked superadmin creates no audit run');
}

if ($scenario === 'pending') {
    $a = test_member('A', 1, 1, 'L');
    $b = test_member('B', $a, $a, 'L');
    $c = test_member('C', $a, $a, 'R');
    $d = test_member('D', 1, $b, 'L');
    $e = test_member('E', 1, $b, 'R');
    test_order($b, 3000); test_order($c, 3000); test_order($d, 3000); test_order($e, 3000);
    test_eq(user_earnings_breakdown($a)['total'], 0, 'ineligible A has no immediate earnings');
    test_eq(q_val("SELECT COUNT(*) FROM income_sponsor_events WHERE sponsor_id=? AND status='pending'", [$a]), 2, 'both referral bonuses wait for A');
    test_eq(q_val("SELECT first_bonus_status FROM income_matching_state WHERE user_id=?", [$b]), 'pending_parent', 'B first-match bonus waits for placement parent A');
    test_order($a, 3000);
    $earn = user_earnings_breakdown($a);
    test_eq($earn['sponsor'], 600, 'both pending referral bonuses settle once on activation');
    test_eq($earn['binary'], 450, 'pending matching pair settles on activation');
    test_eq($earn['sponsor_matching'], 225, 'pending child first-match bonus settles on activation');
    test_eq(q_val('SELECT reward_points FROM users WHERE id=?', [$a]), 1, 'pending matching points settle on activation');
    test_order($a, 1);
    test_eq(user_earnings_breakdown($a)['total'], 1275, 'subsequent activity does not duplicate pending events');
}

if ($scenario === 'shortfall') {
    $m = test_legacy_fixture();
    add_commission($m['A'], null, 'sponsor', 10000, 0, null, 'Legacy overpayment');
    db_tx(function () use ($m) { debit_wallet($m['A'], (float)q_val('SELECT wallet_balance FROM users WHERE id=?', [$m['A']]), 'admin', null, 'Test spent balance'); });
    $before = test_digest('SELECT * FROM users ORDER BY id'); $comm = test_digest('SELECT * FROM commissions ORDER BY id');
    $p = income_preview_reconciliation();
    test_check(count($p['errors']) > 0 && strpos(implode(' ', $p['errors']), 'negative wallet') !== false, 'preview blocks a wallet shortfall');
    try { income_apply_reconciliation($p['hash'], 1); test_check(false, 'shortfall must not be applied'); }
    catch (RuntimeException $ex) { test_check(strpos($ex->getMessage(), 'blocking issues') !== false, 'shortfall apply is rejected'); }
    test_eq(test_digest('SELECT * FROM users ORDER BY id'), $before, 'blocked recalculation leaves all user rows untouched');
    test_eq(test_digest('SELECT * FROM commissions ORDER BY id'), $comm, 'blocked recalculation leaves commissions untouched');
    test_eq(q_val('SELECT COUNT(*) FROM income_recalc_runs'), 0, 'blocked recalculation creates no audit run');
}

if ($scenario === 'migration') {
    q("DELETE FROM settings WHERE skey='income_rules_1850'");
    $id = test_member('Waiting', 1, 1, 'L');
    q("INSERT INTO orders (order_no,user_id,total_bv,payment_mode,payment_status,status,created_at)
       VALUES ('PENDING-MIGRATION', ?, 3000, 'bank_transfer', 'pending', 'pending', ?)", [$id, now()]);
    $oid = (int)db()->lastInsertId();
    [$ok, $msg] = authorize_and_approve_order($oid, 1);
    test_check(!$ok && strpos($msg, 'paused') !== false, 'existing DB cannot approve income before baseline');
    $order = q_row('SELECT * FROM orders WHERE id=?', [$oid]);
    test_eq($order['payment_status'], 'pending', 'failed authorization does not mark payment paid');
    test_eq($order['status'], 'pending', 'failed authorization leaves order pending');
    $p = income_preview_reconciliation(); income_apply_reconciliation($p['hash'], 1);
    test_check(authorize_and_approve_order($oid, 1)[0], 'order approval resumes after reviewed baseline');
    test_eq(q_val('SELECT self_bv FROM users WHERE id=?', [$id]), 3000, 'approved order credits Self BV once');
}

if ($scenario === 'registration') {
    $data = ['sponsor' => 'YSH100001', 'placement' => 1, 'placement_leg' => 'L', 'leg' => 'L',
        'full_name' => 'Registration Test', 'email' => '', 'mobile' => '9876543210', 'password' => 'Temp-password',
        'dob' => '', 'marital_status' => '', 'nationality' => 'Indian', 'address' => '', 'city' => '', 'state' => '',
        'pincode' => '', 'nominee_name' => '', 'nominee_relation' => '', 'bank_holder' => '', 'bank_account_no' => '',
        'bank_ifsc' => '', 'bank_name' => '', 'bank_branch' => '', 'aadhaar_no' => '', 'pan_no' => '',
        'use_username_password' => true];
    [$ok, $id, $username] = register_distributor($data);
    $user = q_row('SELECT username, password, path, must_change_password FROM users WHERE id=?', [$id]);
    test_check($ok && $user['username'] === $username && password_verify($username, $user['password']), 'registration atomically assigns the promised member-ID password');
    test_eq($user['must_change_password'], 0, 'new member is not required to change their password at login');
    test_eq(q_val('SELECT COUNT(*) FROM income_matching_state WHERE user_id=?', [$id]), 1, 'new account and empty income state commit together');
    // Existing accounts can still carry the old flag; they must also be able
    // to reach the dashboard without altering their password or their record.
    q('UPDATE users SET must_change_password=1 WHERE id=?', [$id]);
    $_SESSION['user_id'] = $id;
    $_SERVER['SCRIPT_NAME'] = '/user/index.php';
    // A redirect calls exit(0), so make an unexpected redirect fail the test
    // instead of silently terminating the scenario with a successful status.
    $guardReturned = false;
    register_shutdown_function(static function () use (&$guardReturned) {
        if (!$guardReturned) {
            fwrite(STDERR, "FAIL: member guard exited instead of allowing the flagged account.\n");
            exit(1);
        }
    });
    $guarded = require_user();
    $guardReturned = true;
    test_check((int)$guarded['id'] === (int)$id, 'previously flagged members can access the dashboard without changing their password');
    test_eq(q_val('SELECT must_change_password FROM users WHERE id=?', [$id]), 1, 'access does not require a migration or rewrite of old flags');
    unset($_SESSION['user_id']);
    db()->exec('ALTER TABLE users MODIFY path VARCHAR(5) NOT NULL');
    $data['placement'] = $id;
    $count = (int)q_val('SELECT COUNT(*) FROM users');
    try { register_distributor($data); test_check(false, 'a too-short path must fail'); }
    catch (PDOException $ex) { test_check(true, 'path overflow aborts registration'); }
    test_eq(q_val('SELECT COUNT(*) FROM users'), $count, 'failed registration leaves no partial member or temporary password');
    test_eq(q_val('SELECT COUNT(*) FROM income_matching_state'), $count, 'failed registration leaves no partial income state');
    q("DELETE FROM settings WHERE skey='income_lock_1850'");
    try { register_distributor($data); test_check(false, 'missing coordination must block registration'); }
    catch (RuntimeException $ex) { test_check(strpos($ex->getMessage(), 'coordination') !== false, 'missing migration mutex gives an actionable registration error'); }
    test_eq(q_val('SELECT COUNT(*) FROM users'), $count, 'missing coordination leaves membership unchanged');
}

if ($scenario === 'integrity') {
    $a = test_member('A', 1, 1, 'L'); $b = test_member('B', 1, 1, 'R');
    q("UPDATE users SET placement_id=?, leg='L' WHERE id=?", [1, $b]);
    $p = income_preview_reconciliation();
    test_check(count($p['errors']) > 0, 'duplicate placement or inconsistent paths block preview');
    try { income_apply_reconciliation($p['hash'], 1); test_check(false, 'invalid tree must not apply'); }
    catch (RuntimeException $ex) { test_check(strpos($ex->getMessage(), 'blocking issues') !== false, 'invalid tree cannot be applied'); }
    test_eq(q_val('SELECT COUNT(*) FROM income_recalc_runs'), 0, 'invalid tree creates no audit run');
}
if ($scenario === 'custom_plan') {
    q("UPDATE plan_settings SET pair_unit_bv=2000, binary_type='percent', binary_value=15, matching_requires_active=0 WHERE id=1");
    $a = test_member('A', 1, 1, 'L');
    $b = test_member('B', $a, $a, 'L');
    $c = test_member('C', 1, $a, 'R');
    test_order($b, 1000); test_order($b, 1000); test_order($c, 2000);
    test_eq(user_earnings_breakdown($a)['binary'], 300, 'percent matching respects configured pair BV and rate without joining requirement');
    test_eq(q_val('SELECT reward_points FROM users WHERE id=?', [$a]), 0, 'points still wait for joining eligibility');
    test_eq(q_val('SELECT pending_points FROM income_matching_state WHERE user_id=?', [$a]), 1, 'ineligible points are retained');
    test_eq(user_earnings_breakdown(1)['sponsor_matching'], 150, 'first-match bonus uses configured pair income and placement parent');
    test_order($b, 1000);
    test_eq(q_val("SELECT COUNT(*) FROM income_sponsor_events WHERE member_id=? AND status='pending'", [$b]), 1, 'multiple orders produce one pending referral event at threshold');
    test_order($a, 3000);
    test_eq(user_earnings_breakdown($a)['sponsor'], 300, 'pending referral event is paid after sponsor activates');
    test_eq(q_val('SELECT reward_points FROM users WHERE id=?', [$a]), 1, 'previously pending points settle at activation');
    test_eq(user_earnings_breakdown($a)['binary'], 300, 'activation does not repay an already settled pair');
    test_order($b, 3000);
    test_eq(user_earnings_breakdown($a)['sponsor'], 300, 'later referral purchases do not repeat sponsor income');
}

if ($scenario === 'rank') {
    q("UPDATE ranks SET min_points=1, reward_amount=100 WHERE id=1");
    $a = test_member('A', 1, 1, 'L'); $b = test_member('B', 1, 1, 'R');
    test_order($a, 3000); test_order($b, 3000);
    test_eq(user_earnings_breakdown(1)['rank'], 100, 'rank reward is credited once when points cross its threshold');
    test_eq(q_val('SELECT COUNT(*) FROM income_rank_events WHERE user_id=1'), 1, 'rank milestone is tracked');
    // Simulate an imported Self BV correction; historical calculations must use it.
    q("UPDATE users SET self_bv=0 WHERE id=?", [$b]);
    $p = income_preview_reconciliation();
    test_eq($p['rows'][1]['adjustments']['rank'], -100, 'preview shows a negative rank correction when the rank is no longer reached');
    income_apply_reconciliation($p['hash'], 1);
    test_eq(user_earnings_breakdown(1)['rank'], 0, 'rank correction adjusts income without deleting original reward');
    test_eq(q_val("SELECT COUNT(*) FROM commissions WHERE user_id=1 AND type='rank'"), 2, 'rank reward and signed correction both remain in the ledger');
    test_order($b, 3000);
    test_eq(user_earnings_breakdown(1)['rank'], 100, 'rank reward can be earned again after a reviewed reversal');
    test_eq(q_val('SELECT COUNT(*) FROM income_rank_events WHERE user_id=1'), 1, 're-earned rank remains idempotently tracked');
}

if ($scenario === 'payouts') {
    require __DIR__ . '/../includes/payout_lib.php';
    $a = test_member('A', 1, 1, 'L');
    q("UPDATE users SET kyc_status='verified',bank_name='Test Bank',bank_account_no='123',bank_ifsc='TEST0001' WHERE id=?", [$a]);
    add_commission($a, null, 'binary', 1000, 0, null, 'Earlier income');
    q("UPDATE commissions SET created_at=DATE_SUB(NOW(), INTERVAL 21 DAY) WHERE user_id=?", [$a]);
    [$ok, $firstPayout] = request_payout($a, 700);
    test_check($ok, 'eligible payout request is debited atomically');
    test_check(!request_payout($a, 700)[0], 'a second request cannot reuse already held funds');
    test_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$a]), 300, 'wallet reflects exactly one payout hold');
    test_eq(q_val('SELECT COUNT(*) FROM payouts WHERE user_id=?', [$a]), 1, 'only one payout was recorded');
    test_eq(payout_entitlement_ledger($a)['balance'], 300, 'gross entitlement does not subtract estimated tax a second time');
    q('UPDATE plan_settings SET tds_percent=20, admin_charge_percent=10 WHERE id=1'); get_plan(true);
    test_eq(payout_entitlement_ledger($a)['balance'], 300, 'changing current tax rates does not rewrite gross entitlement');
    test_eq(q_val('SELECT tds_amount FROM payouts WHERE id=?', [$firstPayout]), 35, 'payout keeps its original recorded TDS amount');
    test_check(process_payout($firstPayout, 'paid', 1)[0], 'pending payout can be marked paid');
    test_eq(payout_entitlement_ledger($a)['balance'], 300, 'marking a held payout paid does not debit the gross again');
    add_commission($a, null, 'binary', 400, 0, null, 'Current period income');
    add_commission($a, null, 'award', 100, 0, null, 'Legacy non-payout income');
    test_eq(payout_entitlement_ledger($a)['balance'], 700, 'current income is included but non-payout legacy types are excluded');
    [$ok, $secondPayout] = request_payout($a, 500);
    test_check($ok, 'a second eligible request can be held');
    test_eq(payout_entitlement_ledger($a)['balance'], 200, 'pending request reduces gross entitlement once');
    $earnedBeforeRefund = q_val('SELECT total_earned FROM users WHERE id=?', [$a]);
    test_check(process_payout($secondPayout, 'reject', 1)[0], 'the second request can be rejected and refunded');
    test_eq(q_val('SELECT total_earned FROM users WHERE id=?', [$a]), $earnedBeforeRefund, 'a payout refund does not count as new income');
    test_eq(payout_entitlement_ledger($a)['balance'], 700, 'rejected requests are excluded from the live entitlement view');
    db_tx(function () use ($a) {
        credit_wallet($a, 500, 'admin', null, 'Manual funds do not increase payout eligibility');
        income_recalc_adjustment($a, 'binary', -1000, 998);
    });
    test_eq(payout_entitlement_ledger($a)['balance'], -300, 'signed overpayment remains visible without taxing the correction');
    test_eq(payout_eligible_amount($a), 0, 'an overpayment clamps request eligibility to zero');
    // Another member has an old earning corrected down today, plus manual wallet funds.
    $b = test_member('B', 1, 1, 'R');
    q("UPDATE users SET kyc_status='verified',bank_name='Test Bank',bank_account_no='456',bank_ifsc='TEST0001' WHERE id=?", [$b]);
    add_commission($b, null, 'binary', 1000, 0, null, 'Earlier income');
    q("UPDATE commissions SET created_at=DATE_SUB(NOW(), INTERVAL 21 DAY) WHERE user_id=?", [$b]);
    db_tx(function () use ($b) {
        income_recalc_adjustment($b, 'binary', -1000, 999);
        credit_wallet($b, 1000, 'admin', null, 'Unrelated manual funds');
    });
    test_eq(payout_eligible_amount($b), 0, 'negative correction reduces current payout eligibility');
    $c = test_member('C', 1, $a, 'L');
    q("UPDATE users SET kyc_status='verified',bank_name='Snapshot Bank',bank_account_no='789',bank_ifsc='TEST0001' WHERE id=?", [$c]);
    add_commission($c, null, 'binary', 600, 0, null, 'Completed-week income');
    q("UPDATE commissions SET created_at=DATE_SUB(NOW(), INTERVAL 21 DAY) WHERE user_id=?", [$c]);
    $priorSat = date('Y-m-d', strtotime('last saturday -7 days'));
    [$ok, $ts, $stats] = generate_payout_report($priorSat);
    test_check($ok, 'weekly payout report runs with a correction present');
    test_eq(q_val("SELECT COUNT(*) FROM payouts WHERE user_id=?", [$b]), 0, 'post-cutoff negative correction prevents payout of reversed old income');
    $statementRows = payout_statement_rows($stats['statement_id']);
    $cRows = array_values(array_filter($statementRows, function ($row) use ($c) { return (int)$row['user_id'] === $c; }));
    test_eq(count($cRows), 1, 'weekly statement stores an eligible member snapshot');
    test_eq(payout_statement_status($cRows[0])[0], 'Unpaid', 'a weekly reservation starts unpaid');
    test_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$c]), 600, 'weekly reservation does not debit the wallet');
    test_eq($cRows[0]['tds'], 120, 'weekly statement stores the rate actually used at creation');
    q('UPDATE plan_settings SET tds_percent=5, admin_charge_percent=5 WHERE id=1'); get_plan(true);
    $memberStatements = member_payout_statements($c);
    test_eq($memberStatements[0]['tds'], 120, 'later rate changes do not rewrite the weekly payout snapshot');
    test_check(!process_payout((int)$cRows[0]['payout_id'], 'paid', 1)[0], 'a weekly reservation cannot be paid individually');
    test_check(process_weekly_payout_report($stats['statement_id'], 'in_process', 1)[0], 'weekly report can be marked in process');
    test_eq(payout_statement_status(member_payout_statements($c)[0])[0], 'In Process', 'member sees the current report status');
    test_check(process_weekly_payout_report($stats['statement_id'], 'paid', 1)[0], 'statement-linked reservation is paid by its report');
    test_eq(payout_statement_status(member_payout_statements($c)[0])[0], 'Paid', 'statement reflects the linked payout current status');
    test_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$c]), 0, 'the weekly wallet debit happens on Paid');
    test_eq(payout_entitlement_ledger($c)['balance'], 0, 'paid statement request offsets gross income exactly once');
    test_eq(payout_statement_status(['payout_done' => 1, 'payout_status' => 'rejected'])[0], 'Rejected / refunded', 'rejected request is not displayed as paid');
    test_eq(payout_statement_status(['payout_done' => 1, 'payout_status' => null])[0], 'Request status unavailable', 'missing legacy request is not assumed paid');
    $refundOrder = db_tx(function () {
        income_rules_ready(true);
        credit_wallet(1, 300, 'admin', null, 'Funds for test purchase');
        q("INSERT INTO orders (order_no,user_id,total_dp,payment_mode,payment_status,status,created_at) VALUES ('WALLET-REFUND',1,200,'wallet','paid','pending',?)", [now()]);
        $id = (int)db()->lastInsertId();
        debit_wallet(1, 200, 'purchase', $id, 'Test purchase');
        return $id;
    });
    $rootEarned = q_val('SELECT total_earned FROM users WHERE id=1');
    test_check(reject_order($refundOrder, 1, 'test')[0], 'wallet-funded pending order can be rejected');
    test_eq(q_val('SELECT wallet_balance FROM users WHERE id=1'), 300, 'rejected wallet purchase is refunded exactly once');
    test_eq(q_val('SELECT total_earned FROM users WHERE id=1'), $rootEarned, 'purchase refund does not count as new income');
    q('UPDATE plan_settings SET payout_min=0.01, tds_percent=50, admin_charge_percent=50 WHERE id=1'); get_plan(true);
    add_commission($c, null, 'binary', 0.05, 0, null, 'Small amount rounding');
    [$ok, $smallPayout] = request_payout($c, 0.05);
    $split = q_row('SELECT tds_amount,admin_charge,net_amount FROM payouts WHERE id=?', [$smallPayout]);
    test_check($ok && (float)$split['tds_amount'] === 0.03 && (float)$split['admin_charge'] === 0.02 && (float)$split['net_amount'] === 0.0, 'deduction rounding cannot make a small payout negative');
    $smallEstimate = payout_finalize_period(['parts' => ['x' => 0.05]], get_plan());
    test_check($smallEstimate['tds'] == 0.03 && $smallEstimate['admin'] == 0.02 && $smallEstimate['net'] == 0,
        'small illustrative period uses the same safe rounding');
    $d = test_member('D', 1, $a, 'R');
    q("UPDATE users SET kyc_status='verified',bank_name='Test Bank',bank_account_no='012',bank_ifsc='TEST0001' WHERE id=?", [$d]);
    add_commission($d, null, 'binary', 0.05, 0, null, 'Small completed-week income');
    q("UPDATE commissions SET created_at=DATE_SUB(NOW(), INTERVAL 21 DAY) WHERE user_id=?", [$d]);
    [$ok, $ts, $stats] = generate_payout_report();
    $smallRow = q_row('SELECT gross,tds,admin_charge,net,payout_done,payout_id FROM payout_statement_rows WHERE statement_id=? AND user_id=?', [$stats['statement_id'], $d]);
    $smallWeeklyPayout = $smallRow ? q_row('SELECT amount,tds_amount,admin_charge,net_amount FROM payouts WHERE id=?', [$smallRow['payout_id']]) : null;
    test_check($ok && $smallRow && (int)$smallRow['payout_done'] === 1 && (float)$smallRow['gross'] === 0.05
        && (float)$smallRow['tds'] === 0.03 && (float)$smallRow['admin_charge'] === 0.02 && (float)$smallRow['net'] === 0.0,
        'weekly statement safely rounds deductions on a five-paise payout');
    test_check($smallWeeklyPayout && (float)$smallWeeklyPayout['amount'] === 0.05
        && (float)$smallWeeklyPayout['tds_amount'] === 0.03 && (float)$smallWeeklyPayout['admin_charge'] === 0.02
        && (float)$smallWeeklyPayout['net_amount'] === 0.0, 'weekly payout stores the same nonnegative split as its statement');
    $period = payout_finalize_period(['parts' => ['x' => -100]], get_plan());
    test_eq($period['tds'], 0, 'negative period corrections do not create negative TDS');
    test_eq($period['admin'], 0, 'negative period corrections do not create negative admin charges');
}

if ($scenario === 'moves') {
    $p = test_member('P', 1, 1, 'L'); $q = test_member('Q', 1, 1, 'R');
    $left = test_member('Left', 1, $p, 'L'); $moving = test_member('Moving', 1, $q, 'L');
    foreach ([$p, $q, $left, $moving] as $id) { test_order($id, 3000); }
    $orders = test_digest('SELECT * FROM orders ORDER BY id');
    test_eq(user_earnings_breakdown($p)['binary'], 0, 'P has no match before the placement move');
    test_eq(user_earnings_breakdown(1)['binary'], 900, 'root has two already-paid pairs before the move');
    test_check(move_user_position($moving, $p, 'R', 1)[0], 'moving a whole subtree to the other leg succeeds');
    test_eq(q_val('SELECT sponsor_id FROM users WHERE id=?', [$moving]), 1, 'placement move does not change the sponsor');
    test_eq(q_val('SELECT left_bv FROM users WHERE id=?', [$q]), 0, 'old parent loses the moved saved BV');
    test_eq(q_val('SELECT right_bv FROM users WHERE id=?', [$p]), 3000, 'new parent receives the moved saved BV');
    test_eq(user_earnings_breakdown($p)['binary'], 450, 'the new parent earns its newly formed first pair immediately');
    test_eq(user_earnings_breakdown(1)['sponsor_matching'], 225, 'the immediate placement parent earns P first-match bonus');
    test_eq(user_earnings_breakdown(1)['binary'], 900, 'already-paid root pairs are not reversed or duplicated by the move');
    test_eq(test_digest('SELECT * FROM orders ORDER BY id'), $orders, 'placement moves do not alter orders');
    test_eq(q_val("SELECT COUNT(*) FROM bv_logs WHERE note LIKE 'Placement move of %'"), 4, 'net BV moves are recorded on both chains');
    test_check(move_user_position($moving, $q, 'L', 1)[0], 'moving the subtree back succeeds');
    test_check(move_user_position($moving, $p, 'R', 1)[0], 'moving it to the same destination again succeeds');
    test_eq(user_earnings_breakdown($p)['binary'], 450, 'returning the same BV does not repay the prior pair');
    test_eq(user_earnings_breakdown(1)['sponsor_matching'], 225, 'returning BV does not repeat the first-child bonus');
    test_check(!move_user_position($p, $left, 'L', 1)[0], 'moving under a descendant is rejected');
    q('UPDATE users SET right_bv=0 WHERE id=?', [$p]); // simulate a corrupt imported counter
    $before = test_digest('SELECT * FROM users ORDER BY id'); $logs = test_digest('SELECT * FROM bv_logs ORDER BY id');
    try { move_user_position($moving, $q, 'L', 1); test_check(false, 'inconsistent BV must reject the move'); }
    catch (RuntimeException $ex) { test_check(strpos($ex->getMessage(), 'inconsistent') !== false, 'inconsistent counters reject the move'); }
    test_eq(test_digest('SELECT * FROM users ORDER BY id'), $before, 'failed move rolls back all placement and counter changes');
    test_eq(test_digest('SELECT * FROM bv_logs ORDER BY id'), $logs, 'failed move rolls back BV log entries');
}

if ($scenario === 'offset') {
    $m = test_legacy_fixture(); $a = $m['A'];
    // Legacy total equals the new total, but two categories need opposite adjustments.
    add_commission($a, null, 'sponsor', 500, 0, null, 'Legacy category allocation');
    add_commission($a, null, 'binary', 2500, 0, null, 'Legacy category allocation');
    add_commission($a, null, 'sponsor_matching', 225, 0, null, 'Legacy category allocation');
    q("UPDATE commissions SET created_at=DATE_SUB(NOW(), INTERVAL 21 DAY) WHERE user_id=?", [$a]);
    db_tx(function () use ($a) { debit_wallet($a, 4000, 'admin', null, 'Test spent balance'); });
    $payouts = test_digest('SELECT * FROM payouts ORDER BY id');
    $p = income_preview_reconciliation();
    test_eq($p['rows'][$a]['adjustments']['sponsor'], -200, 'preview displays the negative sponsor category correction');
    test_eq($p['rows'][$a]['adjustments']['binary'], 200, 'preview displays the offsetting positive matching correction');
    test_eq($p['rows'][$a]['net_adjustment'], 0, 'offsetting category corrections have zero net effect');
    test_eq(count($p['errors']), 0, 'offsetting corrections are allowed with a zero wallet');
    $run = income_apply_reconciliation($p['hash'], 1); $runId = $run['run_id'];
    test_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$a]), 0, 'credits before debits keep the zero wallet nonnegative');
    $transactions = q_all("SELECT type, amount, balance_after, created_at FROM wallet_transactions WHERE user_id=? AND note LIKE ? ORDER BY id", [$a, 'Historical income reconciliation #' . $runId . '%']);
    test_eq(count($transactions), 2, 'both offsetting wallet ledger entries are retained');
    test_eq($transactions[0]['type'], 'credit', 'offsetting credit is recorded before the debit');
    test_eq($transactions[0]['balance_after'], 200, 'credit temporarily covers the following debit');
    test_eq($transactions[1]['type'], 'debit', 'negative correction has an auditable debit entry');
    test_eq($transactions[1]['balance_after'], 0, 'wallet returns to its original balance');
    test_eq(test_digest('SELECT * FROM payouts ORDER BY id'), $payouts, 'offsetting corrections preserve existing payouts');
    $runTime = q_val('SELECT created_at FROM income_recalc_runs WHERE id=?', [$runId]);
    test_eq($transactions[0]['created_at'], $runTime, 'adjustment and audit timestamps are identical');
    test_eq(q_val("SELECT COUNT(*) FROM commissions WHERE user_id=? AND note LIKE ? AND created_at<>?", [$a, 'Historical income reconciliation #' . $runId . '%', $runTime]), 0, 'all category corrections share the audit run timestamp');
    $cut = date('Y-m-d', strtotime('last saturday')) . ' 23:59:59';
    test_eq(payout_eligible_amount($a, $cut), 4000, 'a net-zero post-cutoff correction does not withhold closed-week entitlement');
}

if ($scenario === 'payout_timing') {
    $a = test_member('A', 1, 1, 'L');
    q("UPDATE users SET kyc_status='verified',bank_name='Test Bank',bank_account_no='123',bank_ifsc='TEST0001' WHERE id=?", [$a]);
    add_commission($a, null, 'binary', 1000, 0, null, 'Old matching income');
    q("UPDATE commissions SET created_at=DATE_SUB(NOW(), INTERVAL 21 DAY) WHERE user_id=?", [$a]);
    $p = income_preview_reconciliation();
    test_eq($p['rows'][$a]['net_adjustment'], -1000, 'preview reverses the unsupported old matching income');
    $run = income_apply_reconciliation($p['hash'], 1);
    add_commission($a, null, 'binary', 700, 0, null, 'New current-week income');
    $cut = date('Y-m-d', strtotime('last saturday')) . ' 23:59:59';
    test_eq(payout_eligible_amount($a), 700, 'new current income remains eligible for a manual request');
    test_eq(payout_eligible_amount($a, $cut), 0, 'post-cutoff reversal is reserved against the old completed week');
    [$ok, $ts, $stats] = generate_payout_report();
    test_check($ok, 'weekly payout report completes after a correction');
    test_eq(q_val('SELECT COUNT(*) FROM payouts WHERE user_id=?', [$a]), 0, 'new-week income is not advanced into the corrected old week');
    test_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$a]), 700, 'unclosed-week income remains in the wallet');
    test_eq(q_val("SELECT COUNT(*) FROM income_recalc_entries WHERE run_id=? AND user_id=?", [$run['run_id'], $a]), 1, 'payout cutoff is backed by the durable audit entry');
}

if ($scenario === 'schema_guard') {
    $m = test_legacy_fixture(); $a = $m['A'];
    db()->exec('ALTER TABLE commissions MODIFY amount DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0');
    $p = income_preview_reconciliation();
    test_check(strpos(implode(' ', $p['errors']), 'commissions.amount must support signed') !== false, 'unsigned legacy commission amounts block the preview');
    try { income_apply_reconciliation($p['hash'], 1); test_check(false, 'unsigned ledger must not be changed'); }
    catch (RuntimeException $ex) { test_check(strpos($ex->getMessage(), 'blocking issues') !== false, 'unsigned ledger blocks the whole apply'); }
    db()->exec('ALTER TABLE commissions MODIFY amount DECIMAL(12,2) NOT NULL DEFAULT 0');
    db()->exec('ALTER TABLE users MODIFY wallet_balance DECIMAL(5,2) NOT NULL DEFAULT 0');
    $p = income_preview_reconciliation();
    test_check(strpos(implode(' ', $p['errors']), 'installed database ledger or counter limit') !== false, 'preview uses the actual installed legacy wallet width');
    try { income_apply_reconciliation($p['hash'], 1); test_check(false, 'narrow wallet must not be changed'); }
    catch (RuntimeException $ex) { test_check(strpos($ex->getMessage(), 'blocking issues') !== false, 'narrow wallet blocks the entire apply'); }
    test_eq(q_val('SELECT COUNT(*) FROM income_recalc_runs'), 0, 'schema blockers create no audit run');
    q('UPDATE users SET wallet_balance=999.99 WHERE id=?', [$a]);
    $txCount = (int)q_val('SELECT COUNT(*) FROM wallet_transactions WHERE user_id=?', [$a]);
    try {
        db_tx(function () use ($a) { income_rules_ready(true); credit_wallet($a, 1, 'admin', null, 'Overflow test'); });
        test_check(false, 'overflow must fail instead of clipping');
    } catch (PDOException $ex) { test_check(true, 'strict financial connection rejects an out-of-range wallet'); }
    test_eq(q_val('SELECT wallet_balance FROM users WHERE id=?', [$a]), 999.99, 'overflow leaves the wallet unchanged');
    test_eq(q_val('SELECT COUNT(*) FROM wallet_transactions WHERE user_id=?', [$a]), $txCount, 'overflow leaves no partial wallet entry');
    q("DELETE FROM settings WHERE skey='income_lock_1850'");
    $p = income_preview_reconciliation();
    test_check(strpos(implode(' ', $p['errors']), 'coordination row') !== false, 'missing permanent mutex blocks the preview');
    try { income_apply_reconciliation($p['hash'], 1); test_check(false, 'missing mutex must not apply'); }
    catch (RuntimeException $ex) { test_check(strpos($ex->getMessage(), 'coordination') !== false, 'missing mutex rejects application before any writes'); }
}

echo "OK: $scenario ($checks checks)\n";
