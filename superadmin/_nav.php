<?php
/** Sidebar navigation for the super admin area. Set $activeKey before include. */
$pendingOrders = (int)q_val("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
$pendingPayouts = (int)q_val("SELECT COUNT(*) FROM payouts WHERE status = 'pending'");
$newEnq = (int)q_val("SELECT COUNT(*) FROM enquiries WHERE status = 'new'");
$nav = [
    ['icon' => '📊', 'label' => 'Dashboard', 'href' => 'index.php', 'active' => $activeKey === 'dashboard'],
    ['label2' => 'Network'],
    ['icon' => '👥', 'label' => 'Distributors', 'href' => 'users.php', 'active' => $activeKey === 'users'],
    ['icon' => '🌳', 'label' => 'Network Tree', 'href' => 'tree.php', 'active' => $activeKey === 'tree'],
    ['icon' => '➕', 'label' => 'Add Distributor', 'href' => 'add-member.php', 'active' => $activeKey === 'add-member'],
    ['icon' => '🏅', 'label' => 'Ranks', 'href' => 'ranks.php', 'active' => $activeKey === 'ranks'],
    ['label2' => 'Sales'],
    ['icon' => '📦', 'label' => 'Orders', 'href' => 'orders.php', 'active' => $activeKey === 'orders', 'count' => $pendingOrders],
    ['icon' => '💠', 'label' => 'Commissions', 'href' => 'commissions.php', 'active' => $activeKey === 'commissions'],
    ['label2' => 'Finance'],
    ['icon' => '🏦', 'label' => 'Payouts', 'href' => 'payouts.php', 'active' => $activeKey === 'payouts', 'count' => $pendingPayouts],
    ['icon' => '👛', 'label' => 'E-Wallets', 'href' => 'wallets.php', 'active' => $activeKey === 'wallets'],
    ['label2' => 'Plan & Content'],
    ['icon' => '⚙️', 'label' => 'MLM Plan Settings', 'href' => 'plan.php', 'active' => $activeKey === 'plan'],
    ['icon' => '📣', 'label' => 'Announcements', 'href' => 'announcements.php', 'active' => $activeKey === 'announcements'],
    ['icon' => '📄', 'label' => 'Reports', 'href' => 'reports.php', 'active' => $activeKey === 'reports'],
    ['label2' => 'Administration'],
    ['icon' => '🛠️', 'label' => 'Staff Accounts', 'href' => 'admins.php', 'active' => $activeKey === 'admins'],
    ['icon' => '👤', 'label' => 'My Account', 'href' => 'profile.php', 'active' => $activeKey === 'profile'],
];
