-- ===========================================================================
-- DATABASE CHANGES & DEPLOYMENT NOTES: 1.8.51 WEEKLY PAYOUTS + 1.8.50 INCOME RULES
-- Single developer handoff for all database changes in this release.
-- ===========================================================================
-- WARNING: this is financial software with live data. Do not run a fresh
-- installer, seed_database(), DROP/TRUNCATE, the Bulk Members wipe, or any
-- historical recalculation against production as part of testing.
--
-- 1. Rehearse this entire procedure on a restored, access-restricted staging
--    copy first. Verify a transaction-consistent backup AND its restore.
--    A 64-bit PHP runtime is required for the fixed-point BV/payout arithmetic;
--    income and payout writes fail closed on 32-bit builds. Review the
--    projected adjustments, tax/report implications and edge cases with the
--    business/accounting owner before authorizing production use.
-- 2. Schedule a maintenance window. Stop all application workers, payment/
--    payout jobs, order approvals, background jobs, integrations and external
--    writers. The baseline apply locks entire input ranges and can take a long
--    time on a large network; allow for database timeouts and capacity.
-- 3. Select the intended database explicitly, check table engines, and execute
--    the seven CREATE statements and the coordination-row INSERT below. They are
--    additive and idempotent; MySQL DDL autocommits. Existing tables with these names must be checked against
--    the definitions below: IF NOT EXISTS will not repair an incompatible one.
--    All of these plus settings, admins, plan_settings, ranks, users, orders,
--    commissions, wallet_transactions, payouts, payout_statements,
--    payout_statement_rows, bv_logs and points_logs must use InnoDB; relevant
--    operations fail closed if required tables are missing or non-transactional. The income preview also
--    checks the installed decimal widths and signed columns required for
--    corrections, including narrower schemas from older deployments.
-- 4. Deploy all application files together, clear PHP/opcode and worker caches,
--    and keep writers paused. Do NOT run the generic installer/fresh seed.
--    The generic /install/upgrade.php can create missing tables but also offers
--    content/catalog changes; this SQL avoids those optional changes.
-- 5. On a pre-1.8.50 installation the setting income_rules_1850 must be ABSENT
--    until the first reconciliation is reviewed and confirmed. A site already
--    reconciled on 1.8.50 retains its ready marker; upgrading to 1.8.51 does NOT
--    require another reconciliation. Do not insert or manually set the marker
--    to ready. While it is absent, new order approvals, manual
--    Self BV grants, new payout requests and weekly payout generation are
--    paused. Existing pending payouts, wallet adjustments, pending-order
--    checkout/refunds and registrations can still run while coordinated by
--    the mutex, but may change a preview or cause a shortfall. Keep ALL such
--    writers stopped throughout the maintenance and review window. The separate
--    income_lock_1850 row is a coordination mutex, NOT the readiness marker;
--    it must exist even before the first run. Fresh installs initialize both.
--    Financial transactions enable STRICT_TRANS_TABLES for their connection
--    so an out-of-range value fails and rolls back rather than being clipped.
-- 6. For a pre-1.8.50 site requiring its initial baseline, as superadmin open
--    Plan Settings -> Review recalculation preview. This performs no writes.
--    An already-ready 1.8.50 site should not repeat the baseline just for this
--    upgrade. Review EVERY member, especially the Self BV vs
--    approved-order BV differences, negative adjustments, wallet/ledger
--    differences, already-paid excesses, rank changes and all blockers.
--    Resolve blockers under accounting supervision and refresh the preview;
--    an insufficient wallet/total earned blocks the ENTIRE run (no debt is
--    silently created). Obtain the necessary approval before confirming.
-- 7. Confirm only the reviewed, current preview. The app uses a serializable
--    transaction, rechecks its input fingerprint, and either applies all
--    changes and records the approving admin/run or rolls everything back.
--    Keep the run ID and audit export with the backup. Verify sample member
--    balances, income, points, payout eligibility and both ledgers before
--    resuming writers. A stale preview or a DB error applies nothing.
--
-- WEEKLY PAYOUT DEPLOYMENT (1.8.51)
-- - Stop cron and ALL old application/integration writers before deployment.
--   Old code does not know about reservations and must not run concurrently
--   with this release. Create the two new InnoDB tables below (including on an
--   already-reconciled 1.8.50 database); do not modify old payout or statement
--   rows. Check table definitions and transactional engines on staging first.
-- - Configure the CLI scheduler exactly as described in README.md and monitor
--   its nonzero exit status, logs and report freshness. The app itself cannot
--   install or guarantee a hosting provider's cron. It uses APP_TIMEZONE and
--   creates the latest completed Sunday–Saturday report on Sunday or catch-up.
--   The first run starts at the latest completed Saturday; earlier unpaid
--   income is included without creating artificial historical reports. Later
--   missed weeks are generated oldest first, at most 52 per invocation. Legacy
--   statements for the same week suppress a duplicate without being changed.
-- - A new weekly report starts Unpaid and creates a pending payout PLUS an
--   explicit reservation, without changing the wallet. Purchases, manual
--   debits/withdrawals and reconciliation cannot spend reserved funds. A Paid
--   batch rechecks current eligibility, minimum, cutoff entitlement, verified
--   KYC and unchanged bank details; each eligible gross is debited once, and
--   members who no longer qualify are released without a debit/refund and stay
--   unpaid. Members who become eligible after generation join a later report,
--   not the existing batch. Re-uploaded KYC documents become Pending atomically;
--   administrators must refresh stale KYC reviews. TDS/admin amounts are fixed
--   at generation. Blocked/released income carries forward. Report totals are
--   generation snapshots, not paid totals.
--   Paid records the administrator's confirmation; it does NOT transfer funds.
--   Review current eligibility/bank details when arranging payment separately;
--   the app cannot verify an external bank transfer or reverse one.
-- - Legacy report/manual pending requests were ALREADY debited and must only
--   be processed individually; never create reservations for or refund them
--   twice. A superadmin can explicitly release a new unpaid report's remaining
--   reservations (for example before a negative reconciliation), with actor,
--   reason and timestamp recorded. This makes the funds available without a
--   refund or payout, and affected income remains unpaid for a later report.
-- - NEVER roll back application code while new weekly reservations are active:
--   older code would spend reserved funds or refund money never debited.
--   Stop jobs and writers, explicitly settle/release every reservation under
--   accounting supervision, and verify all records before any rollback. A
--   rollback to pre-1.8.50 code has additional signed-ledger constraints below.
--
-- CALCULATION / ACCOUNTING MODEL
-- - Saved users.self_bv is the authoritative input; it is NEVER overwritten
--   during recalculation. Approved orders are used only for the discrepancy
--   comparison, not replayed or edited. Pending/rejected orders do not count
--   toward the approved-order comparison. Every current placement subtree's
--   saved Self BV contributes to the appropriate left/right team total.
-- - Each member whose saved Self BV meets activation_bv creates one sponsor
--   event for their CURRENT sponsor, independent of placement. Each whole
--   pair of CURRENT pair_unit_bv on both sides pays the CURRENT pair rate;
--   sponsor income is not a prerequisite. Each matched member's CURRENT
--   immediate placement parent gets ONE first-match percentage bonus for that
--   child. Points and current rank entitlements are also recalculated.
-- - Current blocked/active status is applied to the entire projection. An
--   active member is earning-eligible if manually activated OR saved Self BV
--   meets activation_bv. matching_requires_active=0 permits matching without
--   joining; points, sponsor income and parent bonus still need eligibility.
--   Ineligible events are recorded as pending. Recalculation can therefore
--   reverse historical income for a currently blocked/ineligible member: the
--   preview exposes every such adjustment before confirmation.
-- - This is NOT a time-ordered order replay. It cannot reconstruct historic
--   placements, sponsors, joining dates, past plan rates or daily/monthly
--   caps from saved Self BV. It applies current settings to the full current
--   snapshot. The pre-existing 1.8.48 engine did NOT enforce daily_cap or
--   monthly_cap; this release likewise does not enforce those legacy fields.
--   If caps are required, do not deploy/apply until the business approves a
--   separately specified cap policy and historical reconstruction method.
-- - Running activity preserves the rate/recipient of pending sponsor and
--   first-match events at qualification; a reviewed historical recalculation
--   intentionally replaces that operational state using CURRENT relationships
--   and rates. Sponsor changes and tree moves do not automatically rewrite
--   historic income. A placement move rebalances saved team BV, records the
--   BV deltas, and processes newly formed eligible pairs on the new ancestor
--   chain; previous matching is not paid twice or automatically reversed.
--   Bulk Self BV grants now propagate BV and may credit earnings immediately;
--   they are not orders and will be flagged in preview. Each bulk-created
--   member and their optional grant commit atomically. The legacy network
--   wipe is blocked whenever financial, BV or reconciliation history exists.
-- - The reconciliation changes only derived users.left_bv, right_bv,
--   matched_pairs, reward_points, rank_id, wallet_balance and total_earned.
--   It adds signed, credited commission adjustment rows (types sponsor,
--   binary, sponsor_matching, rank), with corresponding credit/debit wallet
--   transactions referencing each commission. Corrections are dated on the
--   run date, not backdated; payout calculations include signed corrections.
--   Negative commission amounts are intentional: audit all integrations,
--   exports, accounting and tax workflows that assume commissions are >= 0.
--   Other commission types remain untouched. Existing commission, wallet,
--   BV/points log, order, payout and payout-statement rows are never deleted
--   or rewritten by the reconciliation. New BV/points log deltas are appended.
--   Existing wallet-vs-ledger discrepancies are shown, not silently rebuilt.
--   Payouts already made/held remain intact and offset future eligibility.
--   Weekly payout generation reserves each post-cutoff net-negative audit
--   run against the closed week's entitlement; newer positive income is not
--   advanced to compensate. Later net-positive runs wait for their own week.
--   Each run's audit, adjustments and wallet entries share one run timestamp.
-- - The member entitlement view uses gross eligible income less gross held or
--   paid requests. It does not estimate historical taxes or represent wallet
--   purchases. Period-report deductions are explicitly illustrative at current
--   rates; payout and weekly statement rows retain amounts calculated when they
--   were created. Blocked statement rows are not charged. Statement totals
--   reflect requests created, not proof of payment; current request status may
--   later be paid or rejected. On payouts, TDS rounds first and the admin
--   charge is limited to the remaining gross amount, avoiding a negative net
--   due to paise rounding. New payout/order refunds restore the wallet without
--   counting as new total_earned; existing totals are not rewritten.
-- - The five new tables hold operational matching, sponsor and rank events,
--   plus immutable run/entry audit snapshots. Operational event rows may be
--   replaced on a later reviewed reconciliation; their before/after state is
--   recorded in income_recalc_entries. The run also stores the plan, ranks,
--   input SHA-256 fingerprint, summary, actor and timestamp. Each member entry
--   stores source BV/relationships, before/after values and adjustment IDs.
--   On the first successful historical run, the transaction inserts/sets
--   settings.income_rules_1850='ready'. No such row is inserted by this SQL.
-- - A rollback to older application code after applying signed adjustments is
--   NOT a database rollback. Coordinate an accounting-aware restoration from
--   the verified backup, including every transaction since that backup. Do
--   not delete adjustment rows or manually reset the ready flag as a shortcut.
--
-- TESTING (ONLY a disposable database whose name begins yash_test_):
--   YASH_TEST_DB=yash_test_income YASH_TEST_ALLOW_RESET=1 php tests/income_rules_test.php tree
-- Repeat for historical, pending, shortfall, migration, integrity, custom_plan,
-- rank, payouts, moves, offset, payout_timing, schema_guard and registration.
--   YASH_TEST_DB=yash_test_weekly YASH_TEST_ALLOW_RESET=1 php tests/weekly_payouts_test.php lifecycle
-- Repeat for scheduling, legacy, upgrade, reconciliation, rollback, multiweek,
-- eligibility, kyc and storage. With Node.js and PHP CLI + PDO MySQL installed:
--   YASH_TEST_DB=yash_test_weekly_race YASH_TEST_ALLOW_RESET=1 node tests/weekly_payouts_concurrency.mjs
-- The test scripts DROP/RESEED application tables in the selected database.
-- Use separate disposable DBs for simultaneous suites; NEVER point these tests
-- at production or a database whose data must be kept.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS income_matching_state (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    processed_bv DECIMAL(14,2) NOT NULL DEFAULT 0,
    points_processed_bv DECIMAL(14,2) NOT NULL DEFAULT 0,
    pending_pairs INT UNSIGNED NOT NULL DEFAULT 0,
    pending_bv DECIMAL(14,2) NOT NULL DEFAULT 0,
    pending_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    pending_points INT UNSIGNED NOT NULL DEFAULT 0,
    first_match_seen TINYINT(1) NOT NULL DEFAULT 0,
    first_parent_id INT UNSIGNED NULL,
    first_bonus_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    first_bonus_order_id INT UNSIGNED NULL,
    first_bonus_commission_id INT UNSIGNED NULL,
    first_bonus_status ENUM('none','pending_match','pending_parent','paid') NOT NULL DEFAULT 'none',
    INDEX idx_parent_bonus (first_parent_id, first_bonus_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS income_sponsor_events (
    member_id INT UNSIGNED NOT NULL PRIMARY KEY,
    sponsor_id INT UNSIGNED NULL,
    order_id INT UNSIGNED NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    joining_bv DECIMAL(14,2) NOT NULL DEFAULT 0,
    status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
    commission_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    INDEX idx_sponsor_pending (sponsor_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS income_rank_events (
    user_id INT UNSIGNED NOT NULL,
    rank_id INT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, rank_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS income_recalc_runs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NOT NULL,
    snapshot_hash CHAR(64) NOT NULL,
    plan_json LONGTEXT NOT NULL,
    summary_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS income_recalc_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    before_json LONGTEXT NOT NULL,
    after_json LONGTEXT NOT NULL,
    adjustment_json LONGTEXT NOT NULL,
    INDEX idx_run (run_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New 1.8.51 tables. Their separate identity preserves legacy held payouts.
CREATE TABLE IF NOT EXISTS weekly_payout_runs (
    period_end DATE NOT NULL PRIMARY KEY,
    statement_id INT UNSIGNED NULL,
    status ENUM('unpaid','in_process','paid','legacy') NOT NULL DEFAULT 'unpaid',
    created_at DATETIME NOT NULL,
    in_process_at DATETIME NULL,
    in_process_by INT UNSIGNED NULL,
    paid_at DATETIME NULL,
    paid_by INT UNSIGNED NULL,
    UNIQUE KEY uq_weekly_statement (statement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weekly_payout_reservations (
    payout_id INT UNSIGNED NOT NULL PRIMARY KEY,
    statement_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    status ENUM('reserved','paid','released') NOT NULL DEFAULT 'reserved',
    created_at DATETIME NOT NULL,
    settled_at DATETIME NULL,
    UNIQUE KEY uq_weekly_member (statement_id, user_id),
    INDEX idx_weekly_user_status (user_id, status),
    INDEX idx_weekly_statement (statement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permanent mutex; this does NOT enable income rules or recalculate anything.
-- The existing settings table is part of the v1.8.48 schema.
INSERT IGNORE INTO settings (skey, svalue) VALUES ('income_lock_1850', '1');
