<?php
/**
 * Optional CLI fallback. Superadmin visits automatically catch up reports;
 * this can also be run repeatedly without creating duplicate reports.
 */
if (PHP_SAPI !== 'cli' || !empty($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}
define('YASH_CLI', true);
require __DIR__ . '/../includes/init.php';

try {
    $results = weekly_payout_auto_run_due();
    if (!$results) {
        echo '[' . now() . '] No weekly payout reports due.' . PHP_EOL;
    }
    foreach ($results as $result) {
        echo '[' . now() . '] Week ending ' . $result['period_end'] . ': ' . $result['outcome'];
        if ($result['statement_id']) { echo ' statement #' . $result['statement_id']; }
        if ($result['outcome'] === 'created') {
            echo ', ' . $result['created'] . ' reservation(s), ' . $result['blocked'] . ' unpaid/ineligible';
        }
        echo '.' . PHP_EOL;
    }
} catch (Throwable $e) {
    error_log('Weekly payout scheduler failed: ' . $e->getMessage());
    fwrite(STDERR, '[' . now() . '] Weekly payout scheduler failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
