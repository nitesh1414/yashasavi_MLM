<?php
/**
 * =====================================================================
 *  YASHASAVI MLM  —  Application Configuration
 * ---------------------------------------------------------------------
 *  Edit the values below to match your server environment.
 *  Everything (database + application URL) is managed from this file.
 * =====================================================================
 */

/* ------------------------------------------------------------------ */
/*  Environment                                                        */
/* ------------------------------------------------------------------ */
/* 'development' shows errors on screen, 'production' hides them.      */
define('APP_ENV', 'development');

/* ------------------------------------------------------------------ */
/*  Application URL                                                    */
/* ------------------------------------------------------------------ */
/* Base URL of the application WITHOUT a trailing slash.
 * Example:  https://www.yashasaviayurveda.com
 * Example:  https://example.com/yashasavi  (if in a sub-folder)
 * Leave EMPTY ('') to auto-detect from the current request.          */
define('APP_URL', '');

/* ------------------------------------------------------------------ */
/*  Database (MySQL / MariaDB)                                         */
/* ------------------------------------------------------------------ */
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'yashasavi_mlm');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

/* ------------------------------------------------------------------ */
/*  Security                                                           */
/* ------------------------------------------------------------------ */
define('SESSION_NAME', 'YASHMLMSESS');   // session cookie name
define('BCRYPT_COST', 10);              // password hash cost

/* ------------------------------------------------------------------ */
/*  Uploads                                                            */
/* ------------------------------------------------------------------ */
define('MAX_UPLOAD_MB', 5);             // max uploaded file size (MB)
define('ALLOWED_IMG_EXT', 'jpg,jpeg,png,webp,gif');
define('ALLOWED_DOC_EXT', 'pdf');

/* ------------------------------------------------------------------ */
/*  Application                                                        */
/* ------------------------------------------------------------------ */
define('APP_VERSION', '1.0.0');
define('APP_TIMEZONE', 'Asia/Kolkata');
define('ITEMS_PER_PAGE', 12);
