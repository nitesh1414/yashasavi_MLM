# Database changes — v1.8.54

This document lists **only the additions for v1.8.54**. The earlier income and
weekly-payout prerequisites remain in [DATABASE_CHANGES.sql](DATABASE_CHANGES.sql).
No production database was changed as part of this work.

## Changes

1. **New `dashboard_milestones` InnoDB table:** `id`, unique `name` (up to 80
   characters), `target_points`, `amount` (`DECIMAL(12,2)`), `status`,
   `sort_order`, `created_at` and `updated_at`. It stores optional named,
   informational funds. Active entries appear as separate member cards; they
   do **not** create commissions, payout entitlements, reservations or wallet
   credits. It starts empty.
2. **New nullable `ranks.icon_image VARCHAR(255)` column:** a relative path
   under `uploads/rank-icons/` to an optional uploaded rank image. Existing
   ranks default to `NULL` and use the standard rank icon. Back up uploaded
   files as well as the database.

Existing Car Fund values remain in `plan_settings.car_fund_points` /
`car_fund_amount`; Tour/Vacation values remain in the existing `settings` keys
`dashboard_tour_points` and `dashboard_tour_amount`. They are not moved, copied,
recalculated or reset. Existing member points and ranks remain authoritative.
No existing financial rows or income rules are changed by this migration.

## Safe deployment

1. Confirm that the v1.8.50/1.8.51 income and weekly-payout schema is already
   installed and, where necessary, the historical income preview has been
   reviewed and applied. Take a transaction-consistent database backup and a
   copy of `uploads/`; verify their restoration on staging.
2. Rehearse with a restored staging copy. Select the correct database and run
   [DATABASE_CHANGES_1.8.54.sql](DATABASE_CHANGES_1.8.54.sql). The statements are
   additive and rerunnable, but MySQL DDL autocommits. `IF NOT EXISTS` cannot
   repair an incompatible pre-existing table or column: inspect their types,
   indexes, collation and InnoDB engine before continuing.
3. Deploy the matching application files together. Verify rank images and named
   funds using test accounts; compare representative member commissions,
   wallet balances, payouts, points and the existing Car/Tour settings before
   and after. The new table may remain empty until a superadmin adds funds.
   Ensure the web server cannot execute scripts in `uploads/`.
4. An authenticated superadmin **panel** visit can automatically generate due
   weekly reports and reserve eligible funds. If writers are frozen during
   maintenance, do not open those pages until that behavior is approved.
   `install/upgrade.php` itself does not trigger report generation.

The generic installer/upgrader also knows about the new table and column, but
has other optional content and catalog operations. Do **not** run the fresh
installer or seed on production. Do not drop the new table or column to roll
back without first preserving any configured funds and uploaded rank-image
references; follow the existing weekly-reservation rollback safeguards too.
