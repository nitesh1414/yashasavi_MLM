<?php
/**
 * =====================================================================
 *  YASHASAVI MLM  —  Application Configuration
 * ---------------------------------------------------------------------
 *  Edit the values below to match your server environment.
 *  The DATABASE and the APPLICATION URL are managed from this file.
 * =====================================================================
 */

$CFG = [

    'APP_ENV'   => 'production',
    'APP_URL'   => '',

    'DB_HOST'    => '127.0.0.1',
    'DB_PORT'    => '3306',
    'DB_NAME'    => 'yashasavi_mlm',
    'DB_USER'    => 'root',
    'DB_PASS'    => '',
    'DB_CHARSET' => 'utf8mb4',

    'SESSION_NAME' => 'YASHMLMSESS',
    'BCRYPT_COST'  => 10,

    'MAX_UPLOAD_MB'     => 5,
    'ALLOWED_IMG_EXT'   => 'jpg,jpeg,png,webp,gif',
    'ALLOWED_DOC_EXT'   => 'pdf',

    'APP_VERSION'    => '1.1.0',
    'APP_TIMEZONE'   => 'Asia/Kolkata',
    'ITEMS_PER_PAGE' => 12,
];
