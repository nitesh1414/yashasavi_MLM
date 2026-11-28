<?php
/**
 * DESTRUCTIVE migration rehearsal: only an explicitly named disposable DB.
 * YASH_TEST_DB=yash_test_migration YASH_TEST_ALLOW_RESET=1 php tests/dashboard_migration_test.php
 */
define('YASH_CLI', true);
if (!defined('DB_NAME')) { define('DB_NAME', (string)getenv('YASH_TEST_DB')); }
$allow = defined('YASH_TEST_ALLOW_RESET') ? YASH_TEST_ALLOW_RESET : getenv('YASH_TEST_ALLOW_RESET');
if (!preg_match('/^yash_test_[a-z0-9_]+$/', DB_NAME) || (string)$allow !== '1') {
    fwrite(STDERR, "Refusing to run: select a disposable yash_test_* DB and set YASH_TEST_ALLOW_RESET=1.\n"); exit(1);
}
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/dashboard_milestones.php';
require __DIR__ . '/../includes/rank_icons.php';
require __DIR__ . '/../install/seed.php';
seed_database(true);
$checks = 0;
function migration_check($condition, $message)
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++; echo 'PASS: ' . $message . "\n";
}
function migration_snapshot()
{
    return [
        q_all('SELECT * FROM users ORDER BY id'), q_all('SELECT * FROM commissions ORDER BY id'),
        q_all('SELECT * FROM wallet_transactions ORDER BY id'), q_all('SELECT * FROM payouts ORDER BY id'),
        q_all('SELECT * FROM payout_statements ORDER BY id'), q_all('SELECT * FROM payout_statement_rows ORDER BY id'),
        q_all('SELECT * FROM weekly_payout_runs ORDER BY statement_id'),
        q_all('SELECT * FROM weekly_payout_reservations ORDER BY payout_id'),
        q_all('SELECT id,name,min_points,min_team_bv,min_directs,reward_amount,badge,sort_order FROM ranks ORDER BY id'),
        q_all('SELECT * FROM plan_settings ORDER BY id'), q_all('SELECT * FROM settings ORDER BY skey'),
    ];
}
function run_dashboard_migration()
{
    $sql = file_get_contents(__DIR__ . '/../DATABASE_CHANGES_1.8.54.sql');
    if ($sql === false) { throw new RuntimeException('Migration SQL is missing.'); }
    $sql = preg_replace('/^\s*--[^\n]*$/m', '', $sql);
    foreach (explode(';', $sql) as $statement) { if (trim($statement) !== '') { q($statement); } }
}
q('UPDATE plan_settings SET car_fund_points=321,car_fund_amount=76543.21 WHERE id=1');
save_setting('dashboard_tour_points', '77'); save_setting('dashboard_tour_amount', '12345.67');
q("UPDATE users SET kyc_status='verified',bank_name='Test Bank',bank_account_no='123456',bank_ifsc='TEST0001' WHERE id=1");
$GLOBALS['__commission_clock'] = date('Y-m-d H:i:s', strtotime('-21 days'));
db_tx(function () { income_rules_ready(true); add_commission(1, null, 'binary', 1250, 0, null, 'Migration rehearsal'); });
unset($GLOBALS['__commission_clock']);
$report = generate_payout_report();
migration_check($report[0] && $report[2]['outcome'] === 'created'
    && (int)q_val("SELECT COUNT(*) FROM weekly_payout_reservations WHERE status='reserved'") === 1,
    'pre-migration fixture contains a real pending reservation');
q('DROP TABLE dashboard_milestones');
q('ALTER TABLE ranks DROP COLUMN icon_image');
migration_check(!dashboard_milestones_available() && !rank_icon_storage_available(), 'fixture represents the previous schema');
$before = migration_snapshot();
run_dashboard_migration();
migration_check(dashboard_milestones_available() && rank_icon_storage_available(), 'SQL adds the named funds table and nullable rank image column');
migration_check(migration_snapshot() === $before, 'migration preserves all existing wallet, payout, income, rank and legacy settings data');
migration_check((int)q_val('SELECT COUNT(*) FROM dashboard_milestones') === 0
    && (int)q_val('SELECT COUNT(*) FROM ranks WHERE icon_image IS NOT NULL') === 0, 'migration neither invents funds nor assigns images to existing ranks');
migration_check(q_val("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dashboard_milestones'") === 'InnoDB', 'new table uses transactional InnoDB storage');
migration_check((int)q_val("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dashboard_milestones' AND INDEX_NAME='uq_dashboard_milestone_name' AND NON_UNIQUE=0") === 1,
    'fund names have the documented unique index');
[$ok, , $id] = dashboard_milestone_save(0, ['name'=>'Rehearsal Fund', 'target_points'=>'5', 'amount'=>'500', 'sort_order'=>'0', 'status'=>'active']);
migration_check($ok, 'new configuration is writable after migration');
$icon = 'rank-icons/' . str_repeat('a', 32) . '.png';
q('UPDATE ranks SET icon_image=? WHERE id=1', [$icon]);
run_dashboard_migration();
migration_check(dashboard_milestones_available() && rank_icon_storage_available(), 'SQL is safely rerunnable');
migration_check((int)q_val('SELECT COUNT(*) FROM dashboard_milestones WHERE id=? AND name=?', [$id, 'Rehearsal Fund']) === 1
    && q_val('SELECT icon_image FROM ranks WHERE id=1') === $icon, 'rerun preserves newly configured funds and icon references');
migration_check(migration_snapshot() === $before, 'rerun leaves old settings, financial records, ranks and reservations untouched');
echo 'OK: dashboard migration (' . $checks . " checks)\n";
