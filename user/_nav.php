<?php
/** Builds the sidebar navigation for the user area. Call with $activeKey before include. */
$pendingOrdersCount = (int)q_val("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status='pending'", [(int)$u['id']]);
$nav = [
    ['icon' => '🏠', 'label' => 'Dashboard', 'href' => 'index.php', 'active' => $activeKey === 'dashboard'],
    ['label2' => 'My Business'],
    ['icon' => '🌳', 'label' => 'Genealogy Tree', 'href' => 'tree.php', 'active' => $activeKey === 'tree'],
    ['icon' => '👥', 'label' => 'My Team', 'href' => 'team.php', 'active' => $activeKey === 'team'],
    ['icon' => '💠', 'label' => 'Marketing Plan', 'href' => 'plan.php', 'active' => $activeKey === 'plan'],
    ['icon' => '💰', 'label' => 'Earnings', 'href' => 'earnings.php', 'active' => $activeKey === 'earnings'],
    ['label2' => 'Shopping'],
    ['icon' => '🛒', 'label' => 'Shop (DP)', 'href' => 'shop.php', 'active' => $activeKey === 'shop'],
    ['icon' => '📦', 'label' => 'My Orders', 'href' => 'orders.php', 'active' => $activeKey === 'orders', 'count' => $pendingOrdersCount],
    ['label2' => 'Wallet & Payouts'],
    ['icon' => '👛', 'label' => 'My Wallet', 'href' => 'wallet.php', 'active' => $activeKey === 'wallet'],
    ['icon' => '🏦', 'label' => 'Payout Requests', 'href' => 'payout.php', 'active' => $activeKey === 'payout'],
    ['label2' => 'Account'],
    ['icon' => '👤', 'label' => 'My Profile', 'href' => 'profile.php', 'active' => $activeKey === 'profile'],
    ['icon' => '🔒', 'label' => 'Change Password', 'href' => 'password.php', 'active' => $activeKey === 'password'],
];
