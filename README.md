# Yashasavi Ayurveda — MLM & E-Commerce Platform

A complete multi-level marketing (MLM) and e-commerce web application for an Ayurvedic
direct-selling business — public website, distributor back-office, CMS admin panel and a
super-admin MLM management console, in one self-contained PHP + MySQL codebase with
**no framework dependencies**.

## Highlights

**Public website**
- Homepage with hero slider, featured products, categories, stats, testimonials & CMS pages
- Product catalogue with category filters, product details, enquiry/contact forms
- Distributor registration with sponsor lookup (AJAX), binary leg (LEFT/RIGHT) placement,
  full KYC/bank/nominee profile
- Legals (certificates) and Promotion (brochure/business-plan downloads) pages

**Distributor panel** (`user/`)
- Dashboard with team stats, e-wallet balance, announcements, recent activity
- Binary genealogy tree viewer (re-root into your own downline)
- Team lists (direct / full downline with search)
- Shop with cart, wallet or bank-transfer checkout, order history & invoice view
- Earnings breakdown (sponsor / matching / placement first-match / rank), wallet statement
- Payout requests with recorded TDS and admin deductions; current-rate estimates are labelled separately
- Profile (personal / bank / KYC) and password management

**CMS admin** (`admin/`)
- Pages (CKEditor WYSIWYG, SEO fields, menu ordering), Products (images, MRP/DP/BV,
  benefits/ingredients/usage, stock, featured), Categories, Sliders, Testimonials
- Legal documents & downloads (PDF upload or external URL)
- Enquiry inbox (new → read → replied), site settings (identity, contact, social,
  homepage texts, SEO), staff profile

**Super admin** (`superadmin/`) — the MLM control room
- Live dashboard (registrations chart, BV, commissions, wallet liability)
- Distributor management: search, block/unblock, edit, KYC verify/reject,
  manual activation, full profile + genealogy
- Orders: approve / reject — **approval instantly runs the commission engine**
- Commission ledger with filters + CSV export
- Weekly payout reports scheduled for Sunday, report-level Unpaid / In Process / Paid,
  reservation-safe wallets and CSV export; manual/legacy requests are handled individually
- E-wallets: full transaction ledger + manual credit/debit adjustments
- MLM plan settings (joining BV, sponsor rate, pair unit and rate, placement first-match
  percentage, points, TDS, admin charge and payout minimum)
- Points-based rank ladder and one-time rank rewards, announcements, staff accounts and
  business reports
- Reviewed historical income/BV reconciliation with an audit trail and CSV exports

## The MLM engine

| Income stream | Fresh-install default |
|---|---|
| Joining eligibility | 3,000 saved Self BV, or manual earning activation |
| Direct sponsor bonus | ₹300 once per personally sponsored member completing joining BV |
| Binary matching | ₹450 for each eligible 3,000 left + 3,000 right BV pair |
| Placement first-match bonus | ₹225 once to the matched member's immediate placement parent |
| Points and ranks | 1 point per eligible pair; one-time rewards at configured rank thresholds |
| Payouts | ₹500 minimum, 5% TDS and 5% admin charge |

Sponsor and placement relationships are independent. The sponsor receives the direct
bonus; the immediate placement parent receives the first-match bonus. Matching does not
require prior sponsor income. Unmatched BV and ineligible income can carry forward.
The actual thresholds and rates are configurable, and existing installations retain
their own settings. There is no automatic level income. The legacy `daily_cap` and
`monthly_cap` fields are **not enforced** by this engine.

Order approval updates BV, commissions and wallets in a transaction. A historical
reconciliation instead projects current settings and relationships using saved Self BV,
flags differences from approved-order BV and appends auditable corrections; it does not
rewrite the original orders, payout records or financial ledgers. Review the safety and
deployment procedure in [DATABASE_CHANGES.sql](DATABASE_CHANGES.sql) before use.

## Requirements

- 64-bit PHP **7.4+** (8.x ready) with `pdo_mysql`, `mbstring` (uses only standard extensions)
- MySQL **5.7+** / MariaDB 10.3+
- Apache (or any web server) with `mod_rewrite` optional — pretty URLs work both ways

## Installation

1. Upload the repository to your web root.
2. Create an empty MySQL database (or let the installer create it).
3. Browse to **`/install/`** and fill in the database details — tables, reference
   content, MLM plan, ranks and demo media are installed automatically.
4. Delete the `install/` directory (or leave `install/install.lock` in place) when done.

These instructions are for a **new, empty database only**. For an existing installation,
never run the fresh installer or seed on production: rehearse with a restored staging
copy and follow the maintenance, backup, SQL and review steps in
[DATABASE_CHANGES.sql](DATABASE_CHANGES.sql).

## Weekly payout scheduler

The application **does not install a scheduler by itself**. After deploying and
migrating the database, configure the server's cron (or hosting control panel)
to invoke the CLI script, for example every 15 minutes:

```cron
*/15 * * * * /usr/bin/php /absolute/path/to/yashasavi_MLM/cron/weekly_payouts.php >> /var/log/yashasavi-weekly-payouts.log 2>&1
```

Use the actual PHP executable and absolute application path, a restricted service
account with database access, and a writable, private, rotated log. Monitor job
failures and missing reports, and keep the server clock accurate and synchronized.
Cron may use the server's timezone: the script uses `APP_TIMEZONE` (default
`Asia/Kolkata`) to determine the latest completed Saturday.
It creates the new report on the first invocation after Sunday 00:00 local time.
Repeated or overlapping invocations are safe. On the first run it starts at the
latest completed week; thereafter it catches up missing weeks in order (up to 52
per invocation). An existing legacy report for the same week is left untouched
and prevents a duplicate. The superadmin's **Run Due Reports** button is a manual
fallback, not a replacement for cron. The script is CLI-only and returns nonzero
on failure; no HTTP endpoint or secret URL is needed.

Reports include eligible sponsor, matching, placement first-match and rank income
through Saturday, including earlier unpaid income. The minimum, wallet availability,
active status, verified KYC and bank details apply. Eligible gross amounts are
reserved at generation: the displayed wallet balance is unchanged, but those funds
cannot be spent or requested again. Ineligible income carries forward. The report
starts **Unpaid**; a superadmin may mark it **In Process** or **Paid**. Paid rechecks
eligibility and bank details, debits each still-eligible gross reservation exactly
once in one transaction, and leaves ineligible rows unpaid. Members who become
eligible after generation are included in a later report, not added to the existing
reservation batch. Uploading new KYC documents sends verification back to Pending;
a superadmin must review the current documents before verifying them. An empty
report may be finalized as Paid with zero members paid; individual ineligible rows
still show Unpaid. Deductions are recorded at generation and lifetime earned is not
reduced by the payout. This action records payment only: **it does not initiate a
bank transfer**. Arrange and verify the actual bank payment separately. A superadmin
can explicitly release all remaining
reservations on an unpaid report, for example before correcting income; this leaves
members unpaid and makes funds available without a debit or refund. Old pending
payouts and separate manual requests were already debited and retain their original
individual processing/refund behavior.

## Member first login

New members can access their dashboard immediately after login; changing the
password is optional. Existing accounts with the old `must_change_password` flag
can also log in without a forced change, and no password or database migration is
performed. The Change Password page remains available in the member panel.

**Security warning:** Public, panel and bulk registration currently use the member
ID as the initial password. Member IDs may be visible or predictable, so accounts
that keep this password are vulnerable to unauthorized access. Before production
use, arrange unique, private credentials for members through a secure process.

## Default installer credentials (replace before production)

| Role | URL | Username | Password |
|---|---|---|---|
| Super Admin (MLM) | `/superadmin/login.php` | `superadmin` | `Super@123` |
| CMS Admin | `/admin/login.php` | `admin` | `Admin@123` |
| Root distributor | `/login.php` | `YSH100001` | `User@123` |

## Project structure

```
├── index.php, products.php, register.php …   # public site
├── user/            # distributor back-office
├── admin/           # CMS (content) panel
├── superadmin/      # MLM management console
├── includes/        # init, db, auth, functions, mlm engine, layouts
├── assets/          # css/js for site + dashboards
├── uploads/         # media (git-ignored, seeded by installer)
└── install/         # web installer, schema, seed data & demo media
```

## Security notes

- CSRF tokens on every form, prepared statements everywhere, bcrypt passwords,
  session-based auth with separate namespaces for the three login areas
- Login throttling, wallet operations guarded by `SELECT … FOR UPDATE` transactions
- Uploads restricted by extension/size and served from `/uploads` with an `.htaccess`
  that disables PHP execution
