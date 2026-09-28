<?php
/**
 * Application bootstrap — include this at the top of every entry point.
 * Loads configuration, database, helpers and starts the session.
 */

/* Optional local override (git-ignored) — must be loaded first */
if (is_file(__DIR__ . '/../config.local.php')) {
    require_once __DIR__ . '/../config.local.php';
}
require_once __DIR__ . '/../config.php';

/* Turn the $CFG array (if used) into constants */
$CFG = isset($CFG) && is_array($CFG) ? $CFG : [];
foreach ($CFG as $k => $v) {
    if (!defined($k)) {
        define($k, $v);
    }
}

if (!defined('APP_TIMEZONE')) {
    die('Configuration missing. Please check config.php');
}

/* ------------------------------------------------------------------ */
/*  Error reporting                                                    */
/* ------------------------------------------------------------------ */
if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('log_errors', '1');
}

date_default_timezone_set(APP_TIMEZONE);

/* ------------------------------------------------------------------ */
/*  Session (skipped for pure-CLI runs; the dev-server bridge keeps it) */
/* ------------------------------------------------------------------ */
if (!defined('YASH_CLI') && session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ------------------------------------------------------------------ */
/*  Friendly catch-all — a database/schema problem must never          */
/*  white-screen the site.                                             */
/* ------------------------------------------------------------------ */
set_exception_handler(function (Throwable $e) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Fatal: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code(500);
    $msg = $e->getMessage();
    $isPdo = $e instanceof PDOException || stripos($msg, 'SQLSTATE') !== false;
    $schemaOutdated = $isPdo && (
        stripos($msg, 'Base table or view') !== false
        || stripos($msg, "doesn't exist") !== false
        || stripos($msg, 'Unknown column') !== false
        || stripos($msg, 'Bad column') !== false
    );
    $safeMsg = htmlspecialchars(substr($msg, 0, 300));
    /* app base path so the link works from any subfolder (root, /user, /superadmin, /admin) */
    $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $leaf = basename($base);
    if ($leaf === 'user' || $leaf === 'superadmin' || $leaf === 'admin' || $leaf === 'install') {
        $base = dirname($base);
    }
    $base = rtrim($base, '/\\');
    $upgradeUrl = $base . '/install/upgrade.php';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Site maintenance — Yashasavi Ayurveda</title><style>'
        . 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f7f4;'
        . 'color:#1b3a1f;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}'
        . '.card{background:#fff;border-radius:14px;box-shadow:0 8px 30px rgba(27,58,31,.08);'
        . 'max-width:560px;padding:36px;line-height:1.6}h1{font-size:22px;margin:0 0 10px;color:#1b3a1f}'
        . 'a{color:#2e7d32;font-weight:600}.muted{color:#5a6b5c;font-size:13px;margin-top:14px}'
        . '</style></head><body><div class="card">';
    if ($schemaOutdated) {
        echo '<h1>⚙️ The website database needs to be updated</h1>'
            . '<p>New features were added to the software and the database has not been upgraded yet.</p>'
            . '<p style="margin-top:16px"><a class="btn" href="' . $upgradeUrl . '">→ Run the upgrade now</a></p>'
            . '<p style="margin-top:16px">Open <code>install/upgrade.php</code> in your browser and click '
            . '<b>Run Upgrade</b>. Existing accounts, teams and orders are preserved.</p>';
    } else {
        echo '<h1>⚠️ Something went wrong</h1>'
            . '<p>The page could not be loaded. Please try again in a moment.</p>'
            . '<p style="margin-top:12px"><a href="index.php">← Back to the home page</a></p>';
    }
    if (APP_ENV === 'development') {
        echo '<p class="muted">Debug: ' . $safeMsg . '</p>';
    }
    echo '</div></body></html>';
    exit(1);
});


/* ------------------------------------------------------------------ */
/*  Small polyfills for older PHP (< 8.0) on shared hosting            */
/* ------------------------------------------------------------------ */
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

/* ------------------------------------------------------------------ */
/*  Core includes                                                      */
/* ------------------------------------------------------------------ */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mlm.php';
