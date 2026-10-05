<?php
/** PWA web app manifest — name/colors driven by site settings */
require_once __DIR__ . '/includes/init.php';
header('Content-Type: application/manifest+json; charset=utf-8');

$manifest = [
    'name'             => setting('site_name', 'Yashasavi Veda Herbals Private Limited'),
    'short_name'       => 'Yashasavi',
    'description'      => setting('seo_meta_desc', '100% Ayurvedic products and a genuine direct selling business opportunity.'),
    'start_url'        => url('index.php'),
    'scope'            => base_url() . '/',
    'display'          => 'standalone',
    'orientation'      => 'portrait-primary',
    'background_color' => '#ffffff',
    'theme_color'      => '#103a14',
    'icons'            => [
        ['src' => url('assets/img/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => url('assets/img/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => url('assets/img/icon-maskable-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => url('assets/img/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
];
echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
