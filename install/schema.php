<?php
/**
 * Database schema — array of CREATE TABLE statements (MySQL / MariaDB).
 * Kept compatible with MySQL 5.7+ and MariaDB 10.2+.
 */

return [
"CREATE TABLE IF NOT EXISTS settings (
    skey   VARCHAR(64) NOT NULL PRIMARY KEY,
    svalue TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    username VARCHAR(60) NOT NULL UNIQUE,
    email VARCHAR(150) NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','superadmin') NOT NULL DEFAULT 'admin',
    status ENUM('active','blocked') NOT NULL DEFAULT 'active',
    last_login DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(20) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NULL UNIQUE,
    mobile VARCHAR(15) NOT NULL UNIQUE,
    dob DATE NULL,
    marital_status VARCHAR(20) NULL,
    nationality VARCHAR(60) NULL DEFAULT 'Indian',
    address VARCHAR(255) NULL,
    city VARCHAR(80) NULL,
    state VARCHAR(80) NULL,
    pincode VARCHAR(10) NULL,
    nominee_name VARCHAR(120) NULL,
    nominee_relation VARCHAR(60) NULL,
    bank_holder VARCHAR(120) NULL,
    bank_account_no VARCHAR(40) NULL,
    bank_ifsc VARCHAR(20) NULL,
    bank_name VARCHAR(120) NULL,
    bank_branch VARCHAR(120) NULL,
    aadhaar_no VARCHAR(20) NULL,
    pan_no VARCHAR(20) NULL,
    sponsor_id INT UNSIGNED NULL,
    placement_id INT UNSIGNED NULL,
    leg ENUM('L','R') NOT NULL DEFAULT 'L',
    path VARCHAR(255) NOT NULL DEFAULT '/',
    depth INT UNSIGNED NOT NULL DEFAULT 0,
    left_bv DECIMAL(14,2) NOT NULL DEFAULT 0,
    right_bv DECIMAL(14,2) NOT NULL DEFAULT 0,
    self_bv DECIMAL(14,2) NOT NULL DEFAULT 0,
    matched_pairs INT UNSIGNED NOT NULL DEFAULT 0,
    rank_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    activated_at DATETIME NULL,
    wallet_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_earned DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_withdrawn DECIMAL(14,2) NOT NULL DEFAULT 0,
    status ENUM('active','blocked') NOT NULL DEFAULT 'active',
    kyc_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    kyc_remark VARCHAR(255) NULL,
    last_login DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_sponsor (sponsor_id),
    INDEX idx_placement (placement_id),
    INDEX idx_path (path(191)),
    INDEX idx_active (is_active),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    description TEXT NULL,
    image VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NULL,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(200) NOT NULL UNIQUE,
    size VARCHAR(60) NULL,
    mrp DECIMAL(10,2) NOT NULL DEFAULT 0,
    dp DECIMAL(10,2) NOT NULL DEFAULT 0,
    bv DECIMAL(10,2) NOT NULL DEFAULT 0,
    short_desc VARCHAR(500) NULL,
    description LONGTEXT NULL,
    benefits TEXT NULL,
    ingredients TEXT NULL,
    how_to_use TEXT NULL,
    image VARCHAR(255) NULL,
    stock INT NOT NULL DEFAULT 0,
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX idx_cat (category_id),
    INDEX idx_status (status),
    INDEX idx_featured (is_featured)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS pages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(200) NOT NULL UNIQUE,
    content LONGTEXT NULL,
    meta_title VARCHAR(200) NULL,
    meta_description VARCHAR(300) NULL,
    parent_id INT UNSIGNED NULL,
    show_in_menu TINYINT(1) NOT NULL DEFAULT 1,
    menu_order INT NOT NULL DEFAULT 0,
    status ENUM('published','draft') NOT NULL DEFAULT 'published',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX idx_menu (show_in_menu, menu_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS sliders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NULL,
    subtitle VARCHAR(255) NULL,
    description VARCHAR(500) NULL,
    image VARCHAR(255) NOT NULL,
    btn_text VARCHAR(60) NULL,
    btn_link VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS testimonials (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    designation VARCHAR(120) NULL,
    photo VARCHAR(255) NULL,
    content TEXT NOT NULL,
    rating TINYINT NOT NULL DEFAULT 5,
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS documents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    type ENUM('legal','download') NOT NULL DEFAULT 'legal',
    image VARCHAR(255) NULL,
    file VARCHAR(255) NULL,
    external_url VARCHAR(255) NULL,
    description VARCHAR(500) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS enquiries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NULL,
    mobile VARCHAR(15) NULL,
    subject VARCHAR(200) NULL,
    message TEXT NOT NULL,
    status ENUM('new','read','replied') NOT NULL DEFAULT 'new',
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS announcements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    content TEXT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(30) NOT NULL UNIQUE,
    user_id INT UNSIGNED NOT NULL,
    total_mrp DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_dp DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_bv DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_mode ENUM('wallet','bank_transfer','online') NOT NULL DEFAULT 'bank_transfer',
    payment_status ENUM('pending','paid','refunded') NOT NULL DEFAULT 'pending',
    txn_ref VARCHAR(120) NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    remark VARCHAR(255) NULL,
    ship_name VARCHAR(120) NULL,
    ship_mobile VARCHAR(15) NULL,
    ship_address VARCHAR(255) NULL,
    ship_city VARCHAR(80) NULL,
    ship_state VARCHAR(80) NULL,
    ship_pincode VARCHAR(10) NULL,
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS order_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NULL,
    product_name VARCHAR(180) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    mrp DECIMAL(10,2) NOT NULL DEFAULT 0,
    bv DECIMAL(10,2) NOT NULL DEFAULT 0,
    qty INT NOT NULL DEFAULT 1,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_bv DECIMAL(12,2) NOT NULL DEFAULT 0,
    INDEX idx_order (order_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS commissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NULL,
    type ENUM('sponsor','binary','level','rank','other') NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    bv DECIMAL(12,2) NOT NULL DEFAULT 0,
    level TINYINT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    status ENUM('credited','reversed') NOT NULL DEFAULT 'credited',
    created_at DATETIME NOT NULL,
    INDEX idx_user (user_id),
    INDEX idx_order (order_id),
    INDEX idx_type (type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS wallet_transactions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type ENUM('credit','debit') NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance_after DECIMAL(14,2) NOT NULL DEFAULT 0,
    ref_type ENUM('commission','payout','purchase','admin','refund') NOT NULL,
    ref_id INT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_user (user_id),
    INDEX idx_ref (ref_type, ref_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS payouts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    request_no VARCHAR(30) NOT NULL UNIQUE,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    tds_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    admin_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('pending','paid','rejected') NOT NULL DEFAULT 'pending',
    bank_name VARCHAR(120) NULL,
    bank_account_no VARCHAR(40) NULL,
    bank_ifsc VARCHAR(20) NULL,
    bank_holder VARCHAR(120) NULL,
    reject_reason VARCHAR(255) NULL,
    paid_at DATETIME NULL,
    processed_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_user (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS plan_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    activation_bv DECIMAL(12,2) NOT NULL DEFAULT 100,
    sponsor_percent DECIMAL(6,2) NOT NULL DEFAULT 5,
    pair_unit_bv DECIMAL(12,2) NOT NULL DEFAULT 100,
    binary_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    binary_value DECIMAL(10,2) NOT NULL DEFAULT 10,
    level_depth TINYINT UNSIGNED NOT NULL DEFAULT 5,
    daily_cap DECIMAL(12,2) NOT NULL DEFAULT 5000,
    carry_forward TINYINT(1) NOT NULL DEFAULT 1,
    matching_requires_active TINYINT(1) NOT NULL DEFAULT 1,
    level_requires_active TINYINT(1) NOT NULL DEFAULT 1,
    tds_percent DECIMAL(6,2) NOT NULL DEFAULT 5,
    admin_charge_percent DECIMAL(6,2) NOT NULL DEFAULT 5,
    payout_min DECIMAL(12,2) NOT NULL DEFAULT 500,
    updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS plan_levels (
    level_no TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    percent DECIMAL(6,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS ranks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    min_team_bv DECIMAL(14,2) NOT NULL DEFAULT 0,
    min_directs TINYINT UNSIGNED NOT NULL DEFAULT 0,
    reward_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    badge VARCHAR(60) NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
