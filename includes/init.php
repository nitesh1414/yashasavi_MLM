<?php
/**
 * Application bootstrap — include this at the top of every entry point.
 * Loads configuration, database, helpers and starts the session.
 */

if (!defined('APP_TIMEZONE')) {
    die('Configuration missing. Copy/rename config.php and try again.');
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
/*  Session                                                            */
/* ------------------------------------------------------------------ */
if (session_status() === PHP_SESSION_NONE) {
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
/*  Core includes                                                      */
/* ------------------------------------------------------------------ */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mlm.php';

/* Optional local override file (git-ignored) */
if (is_file(__DIR__ . '/../config.local.php')) {
    require_once __DIR__ . '/../config.local.php';
}
