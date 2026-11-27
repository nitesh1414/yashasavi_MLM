<?php
/**
 * Database-free tests of the automatic payout visit guard. Each case must run
 * in its own process because current_admin() and the guard cache per request.
 *
 *   for c in get post head cli install unauth blocked failure limit; do
 *       php tests/superadmin_auto_payout_guard_test.php "$c" || exit
 *   done
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$scenario = defined('SUPERADMIN_GUARD_SCENARIO') ? SUPERADMIN_GUARD_SCENARIO : ($argv[1] ?? '');
if (!in_array($scenario, ['get', 'post', 'head', 'cli', 'install', 'unauth', 'blocked', 'failure', 'limit'], true)) {
    fwrite(STDERR, "Choose get, post, head, cli, install, unauth, blocked, failure or limit.\n");
    exit(1);
}
if ($scenario === 'cli') { define('YASH_CLI', true); }
$_SERVER['REQUEST_METHOD'] = $scenario === 'post' ? 'POST' : ($scenario === 'head' ? 'HEAD' : 'GET');
$_SERVER['SCRIPT_NAME'] = $scenario === 'install' ? '/site/install/upgrade.php' : '/site/superadmin/index.php';
$_SESSION = $scenario === 'unauth' ? ['admin_id' => 5] : ['superadmin_id' => 5];
$fixture = ['id' => 5, 'role' => 'superadmin', 'status' => $scenario === 'blocked' ? 'blocked' : 'active'];
$flashes = []; $runCalls = 0;

function q_row($sql, $params = [])
{
    global $fixture;
    if (strpos($sql, 'FROM admins WHERE id') === false || ($params[0] ?? null) !== 5 || ($params[1] ?? null) !== 'superadmin') {
        throw new RuntimeException('Unexpected admin lookup');
    }
    return $fixture;
}
function flash($type, $message)
{
    global $flashes;
    $flashes[] = [$type, $message];
}
class AutoGuardRedirect extends RuntimeException {}
function redirect($url) { throw new AutoGuardRedirect($url); }
function weekly_payout_auto_run_due()
{
    global $scenario, $runCalls;
    $runCalls++;
    if ($scenario === 'failure') { throw new RuntimeException('simulated scheduling failure'); }
    if ($scenario === 'limit') { return array_fill(0, 52, ['outcome' => 'legacy']); }
    return [['outcome' => 'created'], ['outcome' => 'existing'], ['outcome' => 'created']];
}
function auto_guard_check($ok, $message)
{
    if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
    echo 'PASS: ' . $message . "\n";
}
require __DIR__ . '/../includes/auth.php';

$admin = null; $target = null;
try { $admin = require_superadmin(); }
catch (AutoGuardRedirect $e) { $target = $e->getMessage(); }

if ($scenario === 'unauth' || $scenario === 'blocked') {
    auto_guard_check($target === '/superadmin/login.php' && $admin === null, 'unauthorised superadmin is redirected');
    auto_guard_check(!isset($_SESSION['superadmin_id']) && $runCalls === 0, 'unauthorised access never runs the scheduler');
} else {
    auto_guard_check($target === null && $admin === $fixture, 'active superadmin reaches the requested page');
    if (in_array($scenario, ['post', 'head', 'cli', 'install'], true)) {
        auto_guard_check($runCalls === 0 && !$flashes, 'non-panel, non-GET or CLI requests do not automatically generate reports');
    } else {
        require_superadmin();
        auto_guard_check($runCalls === 1, 'the scheduler is attempted only once in one request');
        if ($scenario === 'failure') {
            auto_guard_check(count($flashes) === 1 && $flashes[0][0] === 'warning'
                && strpos($flashes[0][1], 'next visit will retry') !== false, 'failure is visible and a later visit can retry');
        } elseif ($scenario === 'limit') {
            auto_guard_check(count($flashes) === 1 && strpos($flashes[0][1], 'Further missed weeks') !== false,
                'a 52-period legacy catch-up warns that another visit may be needed');
        } else {
            auto_guard_check(count($flashes) === 1 && strpos($flashes[0][1], '2 weekly payout report(s)') !== false
                && strpos($flashes[0][1], 'Unpaid') !== false, 'created reports are announced without claiming payment');
        }
    }
}
echo 'OK: superadmin automatic payout guard ' . $scenario . "\n";
