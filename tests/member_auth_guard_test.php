<?php
/**
 * Fast, database-free tests for the member access guard.
 *
 * current_user() caches a user for one request, so run each case separately:
 *   for c in new legacy blocked missing deleted; do php tests/member_auth_guard_test.php "$c" || exit; done
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$scenario = defined('AUTH_TEST_SCENARIO') ? AUTH_TEST_SCENARIO : ($argv[1] ?? '');
if (!in_array($scenario, ['new', 'legacy', 'blocked', 'missing', 'deleted'], true)) {
    fwrite(STDERR, "Choose new, legacy, blocked, missing or deleted.\n");
    exit(1);
}

$fixture = in_array($scenario, ['new', 'legacy', 'blocked'], true)
    ? ['id' => 42, 'status' => $scenario === 'blocked' ? 'blocked' : 'active',
       'must_change_password' => $scenario === 'new' ? 0 : 1]
    : null;
$flashes = [];
$_SESSION = $scenario === 'missing' ? [] : ['user_id' => 42];
$_SERVER['SCRIPT_NAME'] = '/user/index.php';

// Substitute the database and redirect at the boundary; test the real guard.
function q_row($sql, $params = [])
{
    global $fixture;
    if (strpos($sql, 'FROM users WHERE id') === false || (int)($params[0] ?? 0) !== 42) {
        throw new RuntimeException('Unexpected user lookup in guard test');
    }
    return $fixture;
}
function flash($type, $message)
{
    global $flashes;
    $flashes[] = [$type, $message];
}
class GuardTestRedirect extends RuntimeException {}
function redirect($url)
{
    throw new GuardTestRedirect($url);
}
function guard_test_check($condition, $message)
{
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    echo 'PASS: ' . $message . "\n";
}
require __DIR__ . '/../includes/auth.php';

$user = null; $target = null;
try {
    $user = require_user();
} catch (GuardTestRedirect $e) {
    $target = $e->getMessage();
}
if ($scenario === 'new' || $scenario === 'legacy') {
    guard_test_check($target === null && $user === $fixture, $scenario . ' member reaches the dashboard');
    guard_test_check(($_SESSION['user_id'] ?? null) === 42 && !$flashes, 'session remains signed in without a password-change prompt');
} else {
    guard_test_check($target === '/login.php' && $user === null, $scenario . ' member cannot access the dashboard');
    guard_test_check(!isset($_SESSION['user_id']), 'the unauthorised session is cleared');
    $expectedMessage = $scenario === 'blocked' ? 'Your account is not active.' : 'Please login to continue.';
    guard_test_check(count($flashes) === 1 && $flashes[0][0] === 'warning'
        && strpos($flashes[0][1], $expectedMessage) !== false, 'an appropriate sign-in warning is shown');
}
echo 'OK: member guard ' . $scenario . "\n";
