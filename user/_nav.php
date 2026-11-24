<?php
/** Builds the sidebar navigation for the user area. Call with $activeKey before include. */
$pendingOrdersCount = (int)q_val("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status='pending'", [(int)$u['id']]);
$nav = [
    ['icon' => '<i class="fas fa-home" aria-hidden="true"></i>', 'label' => 'Dashboard', 'href' => 'index.php', 'active' => $activeKey === 'dashboard'],
    ['label2' => 'My Business'],
    ['icon' => '<i class="fas fa-sitemap" aria-hidden="true"></i>', 'label' => 'Genealogy Tree', 'href' => 'tree.php', 'active' => $activeKey === 'tree'],
    ['icon' => '<i class="fas fa-user-plus" aria-hidden="true"></i>', 'label' => 'Add Member', 'href' => 'add-member.php', 'active' => $activeKey === 'add-member'],
    ['icon' => '<i class="fas fa-users" aria-hidden="true"></i>', 'label' => 'My Team', 'href' => 'team.php', 'active' => $activeKey === 'team'],
    ['icon' => '<i class="fas fa-chart-line" aria-hidden="true"></i>', 'label' => 'Marketing Plan', 'href' => 'plan.php', 'active' => $activeKey === 'plan'],
    ['icon' => '<i class="fas fa-coins" aria-hidden="true"></i>', 'label' => 'Earnings', 'href' => 'earnings.php', 'active' => $activeKey === 'earnings'],
    ['label2' => 'Shopping'],
    ['icon' => '<i class="fas fa-shopping-cart" aria-hidden="true"></i>', 'label' => 'Shop (DP)', 'href' => 'shop.php', 'active' => $activeKey === 'shop'],
    ['icon' => '<i class="fas fa-box" aria-hidden="true"></i>', 'label' => 'My Orders', 'href' => 'orders.php', 'active' => $activeKey === 'orders', 'count' => $pendingOrdersCount],
    ['label2' => 'Wallet & Payouts'],
    ['icon' => '<i class="fas fa-wallet" aria-hidden="true"></i>', 'label' => 'My Wallet', 'href' => 'wallet.php', 'active' => $activeKey === 'wallet'],
    ['icon' => '<i class="fas fa-file-invoice-dollar" aria-hidden="true"></i>', 'label' => 'Payout', 'href' => 'payout.php', 'active' => $activeKey === 'payout',
        'children' => [
            ['label' => 'Incentive Payout Report', 'href' => 'payout.php', 'active' => $activeKey === 'payout'],
            ['label' => 'Reward Payout Report', 'href' => 'payout-rewards.php', 'active' => $activeKey === 'payout-rewards'],
            ['label' => 'My Balance Payout', 'href' => 'payout-balance.php', 'active' => $activeKey === 'payout-balance'],
        ]],
    ['label2' => 'Account'],
    ['icon' => '<i class="fas fa-id-card" aria-hidden="true"></i>', 'label' => 'Upload KYC', 'href' => 'kyc.php', 'active' => $activeKey === 'kyc'],
    ['icon' => '<i class="fas fa-user" aria-hidden="true"></i>', 'label' => 'My Profile', 'href' => 'profile.php', 'active' => $activeKey === 'profile'],
    ['icon' => '<i class="fas fa-lock" aria-hidden="true"></i>', 'label' => 'Change Password', 'href' => 'password.php', 'active' => $activeKey === 'password'],
];
