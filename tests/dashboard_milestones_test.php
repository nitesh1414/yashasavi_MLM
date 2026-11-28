<?php
/**
 * DESTRUCTIVE: use only an explicitly named disposable yash_test_* database.
 * YASH_TEST_DB=yash_test_milestones YASH_TEST_ALLOW_RESET=1 php tests/dashboard_milestones_test.php
 */
define('YASH_CLI', true);
if (!defined('DB_NAME')) { define('DB_NAME', (string)getenv('YASH_TEST_DB')); }
$allow = defined('YASH_TEST_ALLOW_RESET') ? YASH_TEST_ALLOW_RESET : getenv('YASH_TEST_ALLOW_RESET');
if (!preg_match('/^yash_test_[a-z0-9_]+$/', DB_NAME) || (string)$allow !== '1') {
    fwrite(STDERR, "Refusing to run: select a disposable yash_test_* DB and set YASH_TEST_ALLOW_RESET=1.\n"); exit(1);
}
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/member_dashboard.php';
require __DIR__ . '/../includes/dashboard_milestones.php';
require __DIR__ . '/../includes/rank_icons.php';
require __DIR__ . '/../install/seed.php';
seed_database(true);
$checks = 0;
function milestone_check($condition, $message)
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++; echo 'PASS: ' . $message . "\n";
}
function milestone_snapshot()
{
    return [
        q_all('SELECT id,self_bv,left_bv,right_bv,matched_pairs,reward_points,rank_id,wallet_balance,total_earned,total_withdrawn FROM users ORDER BY id'),
        q_all('SELECT * FROM commissions ORDER BY id'), q_all('SELECT * FROM payouts ORDER BY id'),
        q_all('SELECT * FROM wallet_transactions ORDER BY id'), q_all('SELECT * FROM weekly_payout_reservations ORDER BY payout_id'),
        q_row('SELECT car_fund_points,car_fund_amount FROM plan_settings WHERE id=1'),
        q_all("SELECT skey,svalue FROM settings WHERE skey IN ('dashboard_tour_points','dashboard_tour_amount') ORDER BY skey"),
    ];
}
$before = milestone_snapshot();
milestone_check(dashboard_milestones_available() && rank_icon_storage_available(), 'fresh install includes both additive storage changes');
milestone_check(dashboard_milestones_list() === [], 'fresh install does not invent any named funds');
[$ok, $message, $bike] = dashboard_milestone_save(0, ['name'=>'Bike Fund','target_points'=>'10','amount'=>'25000.50','sort_order'=>'2','status'=>'active']);
milestone_check($ok && $bike > 0, 'add a named fund with its own target and amount');
[$ok, , $home] = dashboard_milestone_save(0, ['name'=>'Home Fund','target_points'=>'100','amount'=>'800000','sort_order'=>'1','status'=>'active']);
milestone_check($ok && $home !== $bike, 'add a second, independently configured fund');
[$ok, , $hidden] = dashboard_milestone_save(0, ['name'=>'Future Fund','target_points'=>'500','amount'=>'0','sort_order'=>'0','status'=>'inactive']);
milestone_check($ok, 'an inactive fund can be saved without displaying it');
$all = dashboard_milestones_list(); $active = dashboard_milestones_list(true);
milestone_check(array_column($all, 'name') === ['Future Fund','Home Fund','Bike Fund'], 'all funds follow explicit sort order');
milestone_check(array_column($active, 'name') === ['Home Fund','Bike Fund'], 'only active funds are selected for member cards');
$bikeRow = $active[1];
[$value, $status] = member_milestone_text(member_display_milestone(9, $bikeRow['target_points'], $bikeRow['amount']));
milestone_check($value === '9 pts' && strpos($status, '₹25,000.50') !== false && strpos($status, 'target 10 pts') !== false
    && strpos($status, 'not paid') === false, 'fund progress uses saved points, target and amount');
[$value, $status] = member_milestone_text(member_display_milestone(10, $bikeRow['target_points'], $bikeRow['amount']));
milestone_check($value === '10 pts' && strpos($status, 'target 10 pts') !== false && strpos($status, 'not paid') !== false,
    'reached fund still shows its target and does not claim payment');
[$ok] = dashboard_milestone_save($bike, ['name'=>'Bike Fund','target_points'=>'12','amount'=>'30000','sort_order'=>'3','status'=>'inactive']);
milestone_check($ok && count(dashboard_milestones_list(true)) === 1, 'editing can change the fund and hide it without deleting it');
[$ok] = dashboard_milestone_save(0, ['name'=>'home fund','target_points'=>'2','amount'=>'1','sort_order'=>'0','status'=>'active']);
milestone_check(!$ok && count(dashboard_milestones_list()) === 3, 'duplicate names are rejected using database collation');
foreach ([
    ['name'=>'Car Fund','target_points'=>'1','amount'=>'1','status'=>'active'],
    ['name'=>'Tour/Vacation','target_points'=>'1','amount'=>'1','status'=>'active'],
    ['name'=>'X','target_points'=>'1','amount'=>'1','status'=>'active'],
    ['name'=>'Bad Points','target_points'=>'0','amount'=>'1','status'=>'active'],
    ['name'=>'Fractional','target_points'=>'1.5','amount'=>'1','status'=>'active'],
    ['name'=>'Too Large','target_points'=>'4294967296','amount'=>'1','status'=>'active'],
    ['name'=>'Negative Amount','target_points'=>'10','amount'=>'-1','status'=>'active'],
    ['name'=>'Invalid Amount','target_points'=>'10','amount'=>'NaN','status'=>'active'],
    ['name'=>'Huge Amount','target_points'=>'10','amount'=>'10000000000','status'=>'active'],
    ['name'=>'Bad Status','target_points'=>'10','amount'=>'1','status'=>'deleted'],
    ['name'=>'Bad Order','target_points'=>'10','amount'=>'1','sort_order'=>'-1','status'=>'active'],
] as $invalid) {
    [$ok] = dashboard_milestone_save(0, $invalid);
    milestone_check(!$ok, 'reject invalid fund ' . $invalid['name']);
}
[$ok] = dashboard_milestone_save(987654, ['name'=>'Missing','target_points'=>'1','amount'=>'0','status'=>'active']);
milestone_check(!$ok, 'editing a missing fund does not create one');
[$ok] = dashboard_milestone_delete($hidden);
milestone_check($ok && count(dashboard_milestones_list()) === 2, 'delete removes only the requested fund');
[$ok] = dashboard_milestone_delete($hidden);
milestone_check(!$ok, 'deleting a missing fund reports failure');
milestone_check(milestone_snapshot() === $before, 'adding, editing, hiding, displaying and deleting funds leaves financial, points and legacy settings unchanged');
milestone_check(rank_icon_safe_path('rank-icons/' . str_repeat('a', 32) . '.png'), 'generated rank icon paths are recognized');
foreach (['../config.php','rank-icons/../../config.php','rank-icons/x.php','https://example.test/a.png','rank-icons/' . str_repeat('a', 32) . '.png.php'] as $unsafe) {
    milestone_check(!rank_icon_safe_path($unsafe) && rank_icon_url($unsafe) === null, 'unsafe rank image path is not rendered');
}
milestone_check(rank_icon_url('rank-icons/' . str_repeat('f', 32) . '.png') === null, 'a missing image falls back instead of producing a broken image URL');
milestone_check(rank_icon_upload('no_such_upload') === null, 'omitting an image preserves the optional upload behavior');
$_FILES['rank_test'] = ['name'=>'fake.png', 'tmp_name'=>__DIR__ . '/../assets/img/favicon.png', 'error'=>UPLOAD_ERR_OK];
try { rank_icon_upload('rank_test'); $blocked = false; }
catch (RuntimeException $e) { $blocked = strpos($e->getMessage(), 'HTTP upload') !== false; }
unset($_FILES['rank_test']);
milestone_check($blocked, 'a server-side file path cannot masquerade as an HTTP upload');
q('DROP TABLE dashboard_milestones');
milestone_check(!dashboard_milestones_available() && dashboard_milestones_list(true) === [], 'before migration, the dashboard degrades safely without a new table');
try { dashboard_milestone_save(0, ['name'=>'Test','target_points'=>'1','amount'=>'1','status'=>'active']); $blocked = false; }
catch (RuntimeException $e) { $blocked = strpos($e->getMessage(), 'DATABASE_CHANGES_1.8.54.sql') !== false; }
milestone_check($blocked, 'configuration changes are blocked with an actionable migration message when storage is absent');
echo 'OK: dashboard milestones (' . $checks . " checks)\n";
