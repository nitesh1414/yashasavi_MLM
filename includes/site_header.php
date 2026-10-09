<?php
/** Public website header — expects optional: $pageTitle, $pageDesc, $bodyClass */
$siteName = setting('site_name', 'Yashasavi Veda Herbals Private Limited');
$siteTagline = setting('site_tagline', 'Health • Wealth • Wellness');
$logo = upload_url(setting('site_logo')) ?: url('assets/img/logo.png');
$siteSlogan = setting('site_slogan', 'Your Dream Your Better');
$navPages = q_all("SELECT * FROM pages WHERE status='published' AND show_in_menu=1 ORDER BY menu_order ASC, title ASC");
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$currentSlug = get_str('slug');
$menu = [
    ['label' => 'Home', 'href' => url('index.php'), 'active' => $currentScript === 'index.php', 'ico' => '🏠'],
    ['label' => 'About Us', 'href' => url('page.php?slug=about-us'), 'active' => $currentScript === 'page.php' && $currentSlug === 'about-us', 'ico' => 'ℹ️'],
    ['label' => 'Products', 'href' => url('products.php'), 'active' => in_array($currentScript, ['products.php', 'product.php']), 'ico' => '🛍️'],
];
$pageIcons = ['opportunity' => '💼', 'legals' => '⚖️', 'promotion' => '📣', 'terms-and-conditions' => '📜',
              'disclaimer' => '🛡️', 'privacy-policy' => '🛡️', 'refund-policy' => '💸', 'contact-us' => '📞'];
foreach ($navPages as $p) {
    if (in_array($p['slug'], ['about-us'])) { continue; }
    /* the Opportunity menu entry opens the live plan page (ranks & rewards from DB) */
    $menu[] = [
        'label' => $p['title'],
        'href' => $p['slug'] === 'opportunity' ? url('opportunity.php') : url('page.php?slug=' . $p['slug']),
        'active' => ($currentScript === 'page.php' && $currentSlug === $p['slug'])
            || ($p['slug'] === 'opportunity' && $currentScript === 'opportunity.php'),
        'ico' => $pageIcons[$p['slug']] ?? '📄',
    ];
}
$menu[] = ['label' => 'Contact Us', 'href' => url('contact.php'), 'active' => $currentScript === 'contact.php', 'ico' => '📞'];
$metaTitle = isset($pageTitle) ? $pageTitle . ' — ' . $siteName : setting('seo_meta_title', $siteName);
$metaDesc = isset($pageDesc) ? $pageDesc : setting('seo_meta_desc', '');
$u = current_user();
require_once __DIR__ . '/social_icons.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($metaTitle) ?></title>
<meta name="description" content="<?= e($metaDesc) ?>">
<link rel="icon" type="image/png" href="<?= e(upload_url(setting('site_favicon')) ?: url('assets/img/favicon.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(url('assets/img/apple-touch-icon.png')) ?>">
<link rel="manifest" href="<?= e(url('manifest.php')) ?>">
<meta name="theme-color" content="#103a14">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Yashasavi">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>?v=<?= APP_VERSION ?>">
</head>
<body<?= isset($bodyClass) ? ' class="' . e($bodyClass) . '"' : '' ?>>

<div class="topbar">
    <div class="container">
        <div>
            <span>📧 <a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a></span>
            <span class="topbar-sep">|</span>
            <span>📞 <?= e(setting('contact_phone')) ?></span>
        </div>
        <div class="topbar-right">
            <span class="topbar-soc">
                <?php foreach (['whatsapp', 'facebook', 'instagram', 'youtube', 'twitter', 'telegram'] as $sk): ?>
                    <?php if (setting('social_' . $sk)): ?>
                        <a class="soc-<?= $sk ?>" href="<?= e(setting('social_' . $sk)) ?>" target="_blank" rel="noopener"
                           aria-label="<?= e(ucfirst($sk)) ?>" title="<?= e(ucfirst($sk)) ?>"><?= social_icon_svg($sk) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </span>
            <span class="topbar-sep">|</span>
            <a href="<?= url('login.php') ?>">Distributor Login</a>
            <a class="topbar-join" href="<?= url('register.php') ?>">Join Now</a>
        </div>
    </div>
</div>

<nav class="navbar">
    <div class="container">
        <a class="brand" href="<?= url('index.php') ?>" aria-label="<?= e($siteName) ?> — home">
            <img src="<?= e($logo) ?>" alt="<?= e($siteName) ?> logo" width="46" height="46">
            <span class="brand-text">
                <span class="brand-name"><?= e($siteName) ?></span>
                <span class="brand-tag"><?= e($siteTagline) ?></span>
            </span>
        </a>
        <ul class="nav-menu" id="navMenu">
            <li class="nav-menu-head">
                <span class="nav-menu-brand">
                    <img src="<?= e($logo) ?>" alt="">
                    <span><?= e($siteName) ?></span>
                </span>
                <button class="nav-close" aria-label="Close menu">✕</button>
            </li>
            <?php foreach ($menu as $m): ?>
                <li><a href="<?= e($m['href']) ?>" class="<?= $m['active'] ? 'active' : '' ?>">
                    <span class="m-ico"><?= $m['ico'] ?? '📄' ?></span><?= e($m['label']) ?>
                </a></li>
            <?php endforeach; ?>
            <li class="nav-mobile-auth">
                <?php if ($u): ?>
                    <a class="btn btn-primary" href="<?= url('user/index.php') ?>">My Dashboard</a>
                <?php else: ?>
                    <a class="btn btn-outline" href="<?= url('login.php') ?>">Login</a>
                    <a class="btn btn-primary" href="<?= url('register.php') ?>">Register</a>
                <?php endif; ?>
            </li>
        </ul>
        <div class="nav-actions">
            <?php if ($u): ?>
                <a class="btn btn-primary btn-sm" href="<?= url('user/index.php') ?>">My Dashboard</a>
            <?php else: ?>
                <a class="btn btn-outline btn-sm" href="<?= url('login.php') ?>">Login</a>
                <a class="btn btn-primary btn-sm" href="<?= url('register.php') ?>">Register</a>
            <?php endif; ?>
            <button class="nav-toggle" aria-label="Open menu" aria-expanded="false">☰</button>
        </div>
    </div>
</nav>
<div class="nav-overlay" id="navOverlay"></div>

<?php if (setting('announcement_bar')): ?>
<div style="background:var(--gold-light);color:#000;text-align:center;font-size:13.5px;padding:8px 16px;">
    📢 <?= e(setting('announcement_bar')) ?>
</div>
<?php endif; ?>

<main>
<?php if ($flashes = render_flashes()): ?>
<div class="container mt-2"><?= $flashes ?></div>
<?php endif; ?>
