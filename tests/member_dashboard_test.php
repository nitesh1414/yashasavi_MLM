<?php
/**
 * DESTRUCTIVE member dashboard integration test, only for a disposable DB.
 *   YASH_TEST_DB=yash_test_dashboard YASH_TEST_ALLOW_RESET=1 php tests/member_dashboard_test.php
 * This resets every application table in the named database.
 */
define('YASH_CLI', true);
if (!defined('DB_NAME')) { define('DB_NAME', (string)getenv('YASH_TEST_DB')); }
$allow = defined('YASH_TEST_ALLOW_RESET') ? YASH_TEST_ALLOW_RESET : getenv('YASH_TEST_ALLOW_RESET');
if (!preg_match('/^yash_test_[a-z0-9_]+$/', DB_NAME) || (string)$allow !== '1') {
    fwrite(STDERR, "Refusing to run: select a disposable yash_test_* DB and set YASH_TEST_ALLOW_RESET=1.\n");
    exit(1);
}
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/member_dashboard.php';
require __DIR__ . '/../install/seed.php';
seed_database(true);
$checks = 0;
function md_check($condition, $message)
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++; echo 'PASS: ' . $message . "\n";
}
function md_member($name)
{
    [$ok, $id, $result] = register_distributor(['sponsor'=>'YSH100001', 'leg'=>'L',
        'full_name'=>$name, 'email'=>'', 'mobile'=>'9876543210', 'password'=>'TestPassword123',
        'dob'=>'', 'marital_status'=>'', 'nationality'=>'Indian', 'address'=>'', 'city'=>'', 'state'=>'',
        'pincode'=>'', 'nominee_name'=>'', 'nominee_relation'=>'', 'bank_holder'=>'', 'bank_account_no'=>'',
        'bank_ifsc'=>'', 'bank_name'=>'', 'bank_branch'=>'', 'aadhaar_no'=>'', 'pan_no'=>'']);
    if (!$ok) { throw new RuntimeException('Cannot create dashboard test member: ' . $result); }
    return $id;
}
function md_order($userId, $dp, $bv)
{
    static $n = 0;
    q("INSERT INTO orders (order_no,user_id,total_dp,total_bv,payment_mode,payment_status,status,created_at)
       VALUES (?,?,?,?, 'bank_transfer','pending','pending',?)", ['MD-TEST-' . ++$n, $userId, $dp, $bv, now()]);
    $id = (int)db()->lastInsertId();
    [$ok, $message] = approve_order($id, 1);
    if (!$ok) { throw new RuntimeException('Cannot approve dashboard test order: ' . $message); }
    return $id;
}
function md_user($id) { return q_row('SELECT * FROM users WHERE id=?', [$id]); }

$id = md_member('Package Test');
$plan = get_plan();
$p = member_joining_package(md_user($id), $plan);
md_check($p['status'] === 'in_progress' && (float)$p['amount'] === 0.0, 'no purchases show zero and joining progress');
md_order($id, 2000, 1500);
$p = member_joining_package(md_user($id), $plan);
md_check($p['status'] === 'in_progress' && (float)$p['amount'] === 2000.0, 'partial approved purchases are shown while joining is in progress');
md_order($id, 2200, 1500);
$p = member_joining_package(md_user($id), $plan);
md_check($p['status'] === 'qualified' && (float)$p['amount'] === 4200.0, 'package sums both purchases through the first 3000 BV qualification');
md_order($id, 700, 300);
$p = member_joining_package(md_user($id), $plan);
md_check($p['status'] === 'qualified' && (float)$p['amount'] === 4200.0, 'later approved purchases do not increase the joining package');
q("INSERT INTO orders (order_no,user_id,total_dp,total_bv,payment_mode,payment_status,status,created_at)
   VALUES ('MD-PENDING',?,10000,3000,'bank_transfer','pending','pending',?)", [$id, now()]);
md_check((float)member_joining_package(md_user($id), $plan)['amount'] === 4200.0, 'pending purchases are excluded');
$changedPlan = $plan; $changedPlan['activation_bv'] = 6000;
$p = member_joining_package(md_user($id), $changedPlan);
md_check($p['status'] === 'qualified' && (float)$p['amount'] === 4200.0,
    'a later plan change does not replace the recorded joining target');
q('UPDATE income_sponsor_events SET order_id=NULL WHERE member_id=?', [$id]);
$p = member_joining_package(md_user($id), $plan);
md_check($p['status'] === 'qualified' && (float)$p['amount'] === 4200.0, 'complete BV logs recover the package after a historical reconciliation clears the order reference');
q('DELETE FROM income_sponsor_events WHERE member_id=?', [$id]);
$p = member_joining_package(md_user($id), $plan);
md_check($p['status'] === 'unavailable' && $p['amount'] === null, 'without a recorded target, historical qualification is not guessed from the current plan');

$zeroBv = md_member('Zero BV Purchase Test');
md_order($zeroBv, 120, 0); md_order($zeroBv, 1000, 1500); md_order($zeroBv, 2000, 1500);
md_order($zeroBv, 9000, 1000);
$p = member_joining_package(md_user($zeroBv), $plan);
md_check($p['status'] === 'qualified' && (float)$p['amount'] === 3120.0,
    'approved zero-BV purchases before qualification count, while later purchases do not');

$manual = md_member('Manual BV Test');
db_tx(function () use ($manual) { income_rules_ready(true); income_apply_self_bv($manual, 500, null, 'Manual grant'); });
md_order($manual, 3000, 2500);
$p = member_joining_package(md_user($manual), $plan);
md_check($p['status'] === 'unavailable' && $p['amount'] === null, 'manual BV prevents inventing a qualifying purchase total');
$legacy = md_member('Legacy BV Test');
q('UPDATE users SET self_bv=3000 WHERE id=?', [$legacy]);
$p = member_joining_package(md_user($legacy), $plan);
md_check($p['status'] === 'unavailable' && $p['amount'] === null, 'legacy saved BV without a recorded qualification is not reconstructed');
q("DELETE FROM bv_logs WHERE user_id=? AND kind='self' ORDER BY id LIMIT 1", [$id]);
$p = member_joining_package(md_user($id), $plan);
md_check($p['status'] === 'unavailable' && $p['amount'] === null, 'missing BV history makes the package unavailable');
md_check((float)q_val('SELECT self_bv FROM users WHERE id=?', [$id]) === 3300.0, 'dashboard calculations do not rewrite saved Self BV');

$before = q_row('SELECT wallet_balance,total_earned FROM users WHERE id=?', [$id]);
$unconfigured = member_display_milestone(9, '', '');
$notYet = member_display_milestone(9, 10, 5000);
$reached = member_display_milestone(10, 10, 5000);
md_check(!$unconfigured['configured'] && !$unconfigured['reached'] && $unconfigured['amount'] === null, 'missing tour settings are not treated as an earned reward');
md_check($notYet['configured'] && !$notYet['reached'] && $notYet['points'] === 9, 'milestone displays progress before its threshold');
md_check($reached['reached'] && (float)$reached['amount'] === 5000.0, 'milestone records the configured amount at the threshold');
[$progressValue, $progressStatus] = member_milestone_text($notYet);
[$reachedValue, $reachedStatus] = member_milestone_text($reached);
md_check($progressValue === '9 pts' && strpos($progressStatus, '₹5,000.00') !== false
    && strpos($progressStatus, 'target 10 pts') !== false, 'before the target, the card shows current points and configured amount');
md_check($reachedValue === '10 pts' && strpos($reachedStatus, '₹5,000.00') !== false
    && strpos($reachedStatus, 'target 10 pts') !== false && strpos($reachedStatus, 'not paid') !== false,
    'at the target, the card still shows its target and amount without implying a payment');
md_check(member_milestone_text($unconfigured) === ['9 pts', 'Not configured'], 'unconfigured milestones still show current points');
md_check(!member_display_milestone(10, 0, 5000)['configured'], 'zero target disables a milestone');
md_check(!member_display_milestone(10, '10.5', 5000)['configured'], 'fractional milestone targets are not silently rounded');
md_check(!member_display_milestone(10, 10, -1)['configured'], 'negative milestone amount is rejected');
$after = q_row('SELECT wallet_balance,total_earned FROM users WHERE id=?', [$id]);
md_check($before === $after, 'displaying milestones never credits or debits a wallet');
echo 'OK: member dashboard (' . $checks . " checks)\n";
