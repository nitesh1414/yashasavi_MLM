<?php
/** Sidebar navigation for the CMS admin area. Set $activeKey before include. */
$newEnq = (int)q_val("SELECT COUNT(*) FROM enquiries WHERE status = 'new'");
$nav = [
    ['icon' => '🏠', 'label' => 'Dashboard', 'href' => 'index.php', 'active' => $activeKey === 'dashboard'],
    ['label2' => 'Website Content'],
    ['icon' => '📄', 'label' => 'Pages', 'href' => 'pages.php', 'active' => $activeKey === 'pages'],
    ['icon' => '🖼️', 'label' => 'Home Slider', 'href' => 'sliders.php', 'active' => $activeKey === 'sliders'],
    ['icon' => '⭐', 'label' => 'Testimonials', 'href' => 'testimonials.php', 'active' => $activeKey === 'testimonials'],
    ['icon' => '📑', 'label' => 'Legal Docs & Downloads', 'href' => 'documents.php', 'active' => $activeKey === 'documents'],
    ['label2' => 'Catalogue'],
    ['icon' => '🧴', 'label' => 'Products', 'href' => 'products.php', 'active' => $activeKey === 'products'],
    ['icon' => '🗂️', 'label' => 'Categories', 'href' => 'categories.php', 'active' => $activeKey === 'categories'],
    ['label2' => 'Communication'],
    ['icon' => '✉️', 'label' => 'Enquiries', 'href' => 'enquiries.php', 'active' => $activeKey === 'enquiries', 'count' => $newEnq],
    ['label2' => 'Configuration'],
    ['icon' => '⚙️', 'label' => 'Site Settings', 'href' => 'settings.php', 'active' => $activeKey === 'settings'],
    ['icon' => '👤', 'label' => 'My Account', 'href' => 'profile.php', 'active' => $activeKey === 'profile'],
];
