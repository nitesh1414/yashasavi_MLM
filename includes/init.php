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
