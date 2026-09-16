<?php
/**
 * Shared dashboard chrome for user / admin / superadmin areas.
 * Before including, set:
 *   $area      — 'user' | 'admin' | 'superadmin'
 *   $nav       — [ [icon, label, href, active(bool)|null, count?] ]
 *   $pageTitle — string
 */
$dashUser = $area === 'user' ? current_user() : current_admin($area);
$siteName = setting('site_name', 'Yashasavi Ayurveda');
$logo = upload_url(setting('site_logo')) ?: placeholder('Logo');
$initials = '';
$dashName = '';
$dashRole = '';
if ($dashUser) {
    if ($area === 'user') {
        $parts = preg_split('/\s+/', trim($dashUser['full_name']));
        $initials = strtoupper(substr($parts[0] ?? 'U', 0, 1) . substr($parts[1] ?? '', 0, 1));
        $dashName = $dashUser['full_name'];
        $dashRole = $dashUser['username'] . ($dashUser['is_active'] ? '' : ' • INACTIVE');
    } else {
        $initials = strtoupper(substr($dashUser['name'], 0, 1));
        $dashName = $dashUser['name'];
        $dashRole = ucfirst($dashUser['role']);
    }
}
$areaTitles = ['user' => 'Distributor Panel', 'admin' => 'Website Admin', 'superadmin' => 'Super Admin'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Dashboard') ?> — <?= e($siteName) ?></title>
<link rel="icon" href="<?= e(upload_url(setting('site_favicon')) ?: placeholder('i')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/dash.css') ?>?v=<?= APP_VERSION ?>">
</head>
<body class="dash-body area-<?= e($area) ?>">

<div class="dash">
    <aside class="sidebar" id="sidebar">
        <div class="side-brand">
            <img src="<?= e($logo) ?>" alt="">
            <div>
                <div class="sb-name"><?= e($siteName) ?></div>
                <div class="sb-sub"><?= e($areaTitles[$area] ?? '') ?></div>
            </div>
        </div>
        <nav class="side-nav">
            <?php
            $lastLabel = null;
            foreach ($nav as $item):
                if (isset($item['label2'])) { echo '<div class="side-label">' . e($item['label2']) . '</div>'; continue; }
                $active = !empty($item['active']);
            ?>
                <a href="<?= e($item['href']) ?>" class="<?= $active ? 'active' : '' ?>">
                    <span class="s-ico"><?= $item['icon'] ?></span>
                    <span><?= e($item['label']) ?></span>
                    <?php if (!empty($item['count'])): ?><span class="side-count"><?= (int)$item['count'] ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="side-foot"><?= e($siteName) ?> • v<?= APP_VERSION ?></div>
    </aside>

    <div class="main">
        <div class="topbar">
            <button class="menu-toggle" aria-label="Menu">☰</button>
            <div class="page-title"><?= e($pageTitle ?? '') ?></div>
            <div class="tb-right">
                <div class="tb-user">
                    <div class="avatar"><?= e($initials) ?></div>
                    <div>
                        <div class="tb-name"><?= e($dashName) ?></div>
                        <div class="tb-role"><?= e($dashRole) ?></div>
                    </div>
                </div>
                <a class="btn-logout" href="<?= e($area === 'user' ? 'logout.php' : 'logout.php') ?>">Logout</a>
            </div>
        </div>
        <div class="content">
            <div class="content-inner">
                <?= render_flashes() ?>
