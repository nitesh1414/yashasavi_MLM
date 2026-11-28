<?php
/**
 * DESTRUCTIVE: only an explicitly named disposable yash_test_* database.
 * YASH_TEST_DB=yash_test_calculations YASH_TEST_ALLOW_RESET=1 php tests/dashboard_calculations_test.php
 */
define('YASH_CLI', true);
if (!defined('DB_NAME')) { define('DB_NAME', (string)getenv('YASH_TEST_DB')); }
$allow = defined('YASH_TEST_ALLOW_RESET') ? YASH_TEST_ALLOW_RESET : getenv('YASH_TEST_ALLOW_RESET');
if (!preg_match('/^yash_test_[a-z0-9_]+$/', DB_NAME) || (string)$allow !== '1') {
    fwrite(STDERR, "Refusing to run: select a disposable yash_test_* DB and set YASH_TEST_ALLOW_RESET=1.\n"); exit(1);
}
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/member_dashboard.php';
require __DIR__ . '/../install/seed.php';
seed_database(true);
$checks = 0;
function calc_check($condition, $message)
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++; echo 'PASS: ' . $message . "\n";
}
function calc_eq($a, $b, $message)
{
    calc_check(is_numeric($a) && is_numeric($b) ? abs((float)$a - (float)$b) < 0.001 : $a === $b,
        $message . ' (' . json_encode($a) . ' vs ' . json_encode($b) . ')');
}
function calc_member($name, $sponsor, $leg)
{
    [$ok, $id, $code] = register_distributor(['sponsor'=>$sponsor, 'leg'=>$leg, 'full_name'=>$name,
        'email'=>'', 'mobile'=>'9876543210', 'password'=>'TestPassword123', 'dob'=>'', 'marital_status'=>'',
        'nationality'=>'Indian', 'address'=>'', 'city'=>'', 'state'=>'', 'pincode'=>'', 'nominee_name'=>'',
        'nominee_relation'=>'', 'bank_holder'=>'', 'bank_account_no'=>'', 'bank_ifsc'=>'', 'bank_name'=>'',
        'bank_branch'=>'', 'aadhaar_no'=>'', 'pan_no'=>'']);
    if (!$ok) { throw new RuntimeException('Cannot create calculation fixture: ' . $code); }
    return [$id, $code];
}
function calc_order($id, $dp, $bv)
{
    static $n = 0;
    q("INSERT INTO orders (order_no,user_id,total_dp,total_bv,payment_mode,payment_status,status,created_at)
       VALUES (?,?,?,?, 'bank_transfer','pending','pending',?)", ['CALC-' . ++$n, $id, $dp, $bv, now()]);
    $order = (int)db()->lastInsertId(); [$ok, $message] = approve_order($order, 1);
    if (!$ok) { throw new RuntimeException('Cannot approve calculation order: ' . $message); }
}
function calc_snapshot()
{
    return [q_all('SELECT * FROM users ORDER BY id'), q_all('SELECT * FROM commissions ORDER BY id'),
        q_all('SELECT * FROM wallet_transactions ORDER BY id'), q_all('SELECT * FROM payouts ORDER BY id'),
        q_all('SELECT * FROM points_logs ORDER BY id'), q_all('SELECT * FROM income_rank_events ORDER BY user_id,rank_id'),
        q_all('SELECT * FROM weekly_payout_reservations ORDER BY payout_id')];
}
[$a, $aCode] = calc_member('Dashboard Member', 'YSH100001', 'L');
[$b, $bCode] = calc_member('Left Child', $aCode, 'L');
[$c, $cCode] = calc_member('Right Child', $aCode, 'R');
[$d, $dCode] = calc_member('Left Grandchild', $bCode, 'L');
[$e, $eCode] = calc_member('Right Grandchild', $bCode, 'R');
q('UPDATE ranks SET min_points=1 WHERE id=1');
q('UPDATE award_rewards SET points=1 WHERE id=1');
$GLOBALS['__commission_clock'] = date('Y-m-d H:i:s', strtotime('-21 days'));
calc_order($a, 2000, 1500); calc_order($a, 2200, 1500); calc_order($a, 500, 400);
foreach ([$b, $c, $d, $e] as $id) { calc_order($id, 4000, 3000); }
db_tx(function () use ($a) { income_rules_ready(true); add_commission($a, null, 'level', 1250, 0, null, 'Test legacy royalty'); });
unset($GLOBALS['__commission_clock']);
q("UPDATE users SET kyc_status='verified',bank_name='Test Bank',bank_account_no='123456',bank_ifsc='TEST0001',bank_holder=full_name WHERE id=?", [$a]);
$before = calc_snapshot();
$u = q_row('SELECT * FROM users WHERE id=?', [$a]); $plan = get_plan();
$package = member_joining_package($u, $plan);
calc_check($package['status'] === 'qualified' && (float)$package['amount'] === 4200.0, 'package includes the two paid orders through qualification, not later purchases');
calc_eq($u['self_bv'], 3400, 'saved self BV includes all approved purchases');
$counts = team_counts($u);
calc_check($counts === ['L'=>3,'R'=>1], 'team counts follow actual placement paths, including grandchildren');
calc_eq($u['left_bv'], 9000, 'left team BV follows approved purchases in the left placement subtree');
calc_eq($u['right_bv'], 3000, 'right team BV follows approved purchases in the right placement subtree');
calc_eq($u['matched_pairs'], 1, 'matched pair count follows both leg BV');
calc_eq($u['reward_points'], 1, 'reward points follow the configured matched-pair rate');
$rank = q_row('SELECT * FROM ranks WHERE id=?', [$u['rank_id']]);
calc_check($rank && $rank['name'] === 'Silver Star' && (int)$rank['min_points'] === 1, 'current rank comes from the member’s persisted rank ID');
calc_eq(q_val("SELECT COUNT(*) FROM award_rewards WHERE status='active' AND points>0 AND points<=?", [$u['reward_points']]), 1,
    'award card counts configured recognition thresholds reached, not payments');
$earn = user_earnings_breakdown($a);
foreach (['sponsor'=>600,'binary'=>450,'sponsor_matching'=>225,'rank'=>3000,'level'=>1250,'total'=>5525] as $type=>$amount) {
    calc_eq($earn[$type], $amount, $type . ' income matches the underlying credited commission events');
}
calc_eq(q_val("SELECT SUM(amount) FROM commissions WHERE user_id=? AND status='credited'", [$a]), $earn['total'], 'total income equals the entire credited ledger');
$wallet = weekly_wallet_snapshot($a);
calc_eq($wallet['wallet_balance'], 5525, 'wallet balance reflects actual credits');
calc_eq($wallet['reserved'], 0, 'no weekly funds are reserved initially');
calc_eq($wallet['available'], 5525, 'available wallet excludes existing reservations');
calc_eq(payout_eligible_amount($a), 4275, 'payout excludes credited legacy royalty and includes sponsor, pair, first-match and rank');
calc_eq(min($wallet['available'], payout_eligible_amount($a)), 4275, 'dashboard payout card is eligible gross limited by available wallet');
[$tds, $admin, $net] = payout_deductions(4275, $plan);
calc_check($tds === 213.75 && $admin === 213.75 && $net === 3847.5, 'estimated deductions and net use the same payout helper and configured rates');
calc_check(calc_snapshot() === $before, 'all package, earnings, team, rank, points and payout reads are financially read-only');
[$ok, $pid] = request_payout($a, 1000);
calc_check($ok, 'fixture can create one actual pending manual payout');
$wallet = weekly_wallet_snapshot($a);
calc_eq($wallet['wallet_balance'], 4525, 'manual request debits wallet once');
calc_eq(payout_eligible_amount($a), 3275, 'pending payout reduces remaining entitlement once');
calc_eq(min($wallet['available'], payout_eligible_amount($a)), 3275, 'dashboard payout card uses the remaining eligible gross');
[$ok] = process_payout($pid, 'reject', 1, 'Test rejection');
calc_check($ok, 'fixture can reject the pending request');
calc_eq(weekly_wallet_snapshot($a)['wallet_balance'], 5525, 'rejection restores the held manual payout once');
calc_eq(payout_eligible_amount($a), 4275, 'rejected payouts do not reduce eligible entitlement');
$report = generate_payout_report();
calc_check($report[0] && $report[2]['outcome'] === 'created', 'fixture can generate a due weekly report');
$wallet = weekly_wallet_snapshot($a);
calc_eq($wallet['wallet_balance'], 5525, 'weekly report reserves without immediately debiting the wallet');
calc_eq($wallet['reserved'], 4275, 'weekly reserved amount equals the actual eligible gross');
calc_eq($wallet['available'], 1250, 'reserved money is excluded from spendable wallet');
calc_eq(payout_eligible_amount($a), 0, 'reserved pending payout cannot be claimed again');
calc_eq(min($wallet['available'], payout_eligible_amount($a)), 0, 'additional eligible payout balance is zero after reservation');
$smallPlan = $plan; $smallPlan['tds_percent'] = 50; $smallPlan['admin_charge_percent'] = 50;
[$tds, $admin, $net] = payout_deductions(0.05, $smallPlan);
calc_check(abs($tds - 0.03) < 0.0001 && abs($admin - 0.02) < 0.0001 && (float)$net === 0.0, 'small-gross deduction rounding uses the nonnegative helper net');
$smallPlan['tds_percent'] = 17; $smallPlan['admin_charge_percent'] = 83;
[$tds, $admin, $net] = payout_deductions(0.03, $smallPlan);
calc_check(money_txt(0.03 - $tds - $admin) === '-₹0.00' && money_txt($net) === '₹0.00',
    'using the helper net avoids a misleading negative-zero amount from floating-point subtraction');
echo 'OK: dashboard calculations (' . $checks . " checks)\n";
