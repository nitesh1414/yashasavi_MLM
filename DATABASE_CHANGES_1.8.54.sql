-- v1.8.54 only: named dashboard milestones and optional rank icon paths.
-- Rehearse on a restored staging copy; verify a restorable backup first.
-- Select the intended database before executing. Existing installations must
-- already have the earlier income/weekly schema and reviewed readiness state.
-- MySQL DDL autocommits. IF NOT EXISTS does not repair an incompatible table;
-- compare an existing table/column with DATABASE_CHANGES_1.8.54.md first.
-- No commissions, wallets, payouts, ranks or existing Car/Tour settings are
-- recalculated, credited, reset or seeded by this script.

CREATE TABLE IF NOT EXISTS dashboard_milestones (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    target_points INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_dashboard_milestone_name (name),
    INDEX idx_dashboard_milestone_display (status, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MySQL 5.7/MariaDB-compatible conditional ADD COLUMN (safe to rerun).
-- The existing ranks table is a prerequisite; this does not alter rank values.
SET @rank_icon_column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ranks' AND COLUMN_NAME = 'icon_image'
);
SET @rank_icon_ddl := IF(@rank_icon_column_exists = 0,
    'ALTER TABLE ranks ADD COLUMN icon_image VARCHAR(255) NULL DEFAULT NULL',
    'SELECT 1');
PREPARE rank_icon_stmt FROM @rank_icon_ddl;
EXECUTE rank_icon_stmt;
DEALLOCATE PREPARE rank_icon_stmt;
