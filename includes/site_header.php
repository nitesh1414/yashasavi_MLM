<?php
/** Public website header — expects optional: $pageTitle, $pageDesc, $bodyClass */
$siteName = setting('site_name', 'Yashasavi Ayurveda');
$siteTagline = setting('site_tagline', 'Health • Wealth • Wellness');
$logo = upload_url(setting('site_logo')) ?: placeholder('Logo');
$navPages = q_all("SELECT * FROM pages WHERE status='published' AND show_in_menu=1 ORDER BY menu_order ASC, title ASC");
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$currentSlug = get_str('slug');
$menu = [
    ['label' => 'Home', 'href' => url('index.php'), 'active' => $currentScript === 'index.php'],
    ['label' => 'About Us', 'href' => url('page.php?slug=about-us'), 'active' => $currentScript === 'page.php' && $currentSlug === 'about-us'],
    ['label' => 'Products', 'href' => url('products.php'), 'active' => in_array($currentScript, ['products.php', 'product.php'])],
];
foreach ($navPages as $p) {
    if (in_array($p['slug'], ['about-us'])) { continue; }
    $menu[] = [
        'label' => $p['title'],
        'href' => url('page.php?slug=' . $p['slug']),
        'active' => $currentScript === 'page.php' && $currentSlug === $p['slug'],
    ];
}
$menu[] = ['label' => 'Contact Us', 'href' => url('contact.php'), 'active' => $currentScript === 'contact.php'];
$metaTitle = isset($pageTitle) ? $pageTitle . ' — ' . $siteName : setting('seo_meta_title', $siteName);
$metaDesc = isset($pageDesc) ? $pageDesc : setting('seo_meta_desc', '');
$u = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($metaTitle) ?></title>
<meta name="description" content="<?= e($metaDesc) ?>">
<link rel="icon" href="<?= e(upload_url(setting('site_favicon')) ?: placeholder('icon')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>?v=<?= APP_VERSION ?>">
</head>
<body<?= isset($bodyClass) ? ' class="' . e($bodyClass) . '"' : '' ?>>

<div class="topbar">
    <div class="container">
        <div>
            <span>📧 <a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a></span>
        </div>
        <div class="topbar-right">
            <?php if (setting('social_facebook')): ?><a href="<?= e(setting('social_facebook')) ?>" target="_blank" rel="noopener">Facebook</a><?php endif; ?>
            <?php if (setting('social_instagram')): ?><a href="<?= e(setting('social_instagram')) ?>" target="_blank" rel="noopener">Instagram</a><?php endif; ?>
            <?php if (setting('social_youtube')): ?><a href="<?= e(setting('social_youtube')) ?>" target="_blank" rel="noopener">YouTube</a><?php endif; ?>
        </div>
    </div>
</div>

<nav class="navbar">
    <div class="container">
        <a class="brand" href="<?= url('index.php') ?>">
            <img src="<?= e($logo) ?>" alt="logo">
            <span>
                <span class="brand-name"><?= e($siteName) ?></span><br>
                <span class="brand-tag"><?= e($siteTagline) ?></span>
            </span>
        </a>
        <ul class="nav-menu" id="navMenu">
            <?php foreach ($menu as $m): ?>
                <li><a href="<?= e($m['href']) ?>" class="<?= $m['active'] ? 'active' : '' ?>"><?= e($m['label']) ?></a></li>
            <?php endforeach; ?>
        </ul>
        <div class="nav-actions">
            <?php if ($u): ?>
                <a class="btn btn-primary btn-sm" href="<?= url('user/index.php') ?>">My Dashboard</a>
            <?php else: ?>
                <a class="btn btn-outline btn-sm" href="<?= url('login.php') ?>">Login</a>
                <a class="btn btn-primary btn-sm" href="<?= url('register.php') ?>">Register</a>
            <?php endif; ?>
            <button class="nav-toggle" aria-label="Menu">☰</button>
        </div>
    </div>
</nav>

<?php if (setting('announcement_bar')): ?>
<div style="background:var(--gold-light);color:#000;text-align:center;font-size:13.5px;padding:8px 16px;">
    📢 <?= e(setting('announcement_bar')) ?>
</div>
<?php endif; ?>

<main>
<?php if ($flashes = render_flashes()): ?>
<div class="container mt-2"><?= $flashes ?></div>
<?php endif; ?>
