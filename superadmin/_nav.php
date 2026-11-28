<?php
/** Sidebar navigation for the super admin area. Set $activeKey before include. */
$pendingOrders = (int)q_val("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
$pendingPayouts = (int)q_val("SELECT COUNT(*) FROM payouts WHERE status = 'pending'");
$newEnq = (int)q_val("SELECT COUNT(*) FROM enquiries WHERE status = 'new'");
$nav = [
    ['icon' => '<i class="fas fa-chart-pie" aria-hidden="true"></i>', 'label' => 'Dashboard', 'href' => 'index.php', 'active' => $activeKey === 'dashboard'],
    ['label2' => 'Network'],
    ['icon' => '<i class="fas fa-users" aria-hidden="true"></i>', 'label' => 'Distributors', 'href' => 'users.php', 'active' => $activeKey === 'users'],
    ['icon' => '<i class="fas fa-sitemap" aria-hidden="true"></i>', 'label' => 'Network Tree', 'href' => 'tree.php', 'active' => $activeKey === 'tree'],
    ['icon' => '<i class="fas fa-user-plus" aria-hidden="true"></i>', 'label' => 'Add Distributor', 'href' => 'add-member.php', 'active' => $activeKey === 'add-member'],
    ['icon' => '<i class="fas fa-layer-group" aria-hidden="true"></i>', 'label' => 'Bulk Members', 'href' => 'bulk_members.php', 'active' => $activeKey === 'bulk-members'],
    ['icon' => '<i class="fas fa-award" aria-hidden="true"></i>', 'label' => 'Ranks', 'href' => 'ranks.php', 'active' => $activeKey === 'ranks'],
    ['label2' => 'Sales'],
    ['icon' => '<i class="fas fa-box" aria-hidden="true"></i>', 'label' => 'Orders', 'href' => 'orders.php', 'active' => $activeKey === 'orders', 'count' => $pendingOrders],
    ['icon' => '<i class="fas fa-file-invoice" aria-hidden="true"></i>', 'label' => 'Manual Order', 'href' => 'order_add.php', 'active' => $activeKey === 'manual-order'],
    ['icon' => '<i class="fas fa-coins" aria-hidden="true"></i>', 'label' => 'Commissions', 'href' => 'commissions.php', 'active' => $activeKey === 'commissions'],
    ['label2' => 'Finance'],
    ['icon' => '<i class="fas fa-university" aria-hidden="true"></i>', 'label' => 'Payouts', 'href' => 'payouts.php', 'active' => $activeKey === 'payouts', 'count' => $pendingPayouts],
    ['icon' => '<i class="fas fa-wallet" aria-hidden="true"></i>', 'label' => 'E-Wallets', 'href' => 'wallets.php', 'active' => $activeKey === 'wallets'],
    ['label2' => 'Plan & Content'],
    ['icon' => '<i class="fas fa-sliders-h" aria-hidden="true"></i>', 'label' => 'MLM Plan Settings', 'href' => 'plan.php', 'active' => $activeKey === 'plan'],
    ['icon' => '<i class="fas fa-flag-checkered" aria-hidden="true"></i>', 'label' => 'Dashboard Milestones', 'href' => 'milestones.php', 'active' => $activeKey === 'milestones'],
    ['icon' => '<i class="fas fa-trophy" aria-hidden="true"></i>', 'label' => 'Award & Rewards', 'href' => 'rewards.php', 'active' => $activeKey === 'rewards'],
    ['icon' => '<i class="fas fa-bullhorn" aria-hidden="true"></i>', 'label' => 'Announcements', 'href' => 'announcements.php', 'active' => $activeKey === 'announcements'],
    ['label2' => 'Website'],
    ['icon' => '<i class="fas fa-shopping-bag" aria-hidden="true"></i>', 'label' => 'Products', 'href' => 'products.php', 'active' => $activeKey === 'products'],
    ['icon' => '<i class="fas fa-balance-scale" aria-hidden="true"></i>', 'label' => 'Legal Documents', 'href' => 'documents.php', 'active' => $activeKey === 'documents'],
    ['icon' => '<i class="fas fa-chart-bar" aria-hidden="true"></i>', 'label' => 'Reports', 'href' => 'reports.php', 'active' => $activeKey === 'reports'],
    ['label2' => 'Administration'],
    ['icon' => '<i class="fas fa-user-shield" aria-hidden="true"></i>', 'label' => 'Staff Accounts', 'href' => 'admins.php', 'active' => $activeKey === 'admins'],
    ['icon' => '<i class="fas fa-user-cog" aria-hidden="true"></i>', 'label' => 'My Account', 'href' => 'profile.php', 'active' => $activeKey === 'profile'],
];
