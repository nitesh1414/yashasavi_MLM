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
- Reference-inspired responsive dashboard with referral links, income, team and BV summaries,
  announcements, and expandable recent activity
- Binary genealogy tree viewer (re-root into your own downline)
- Team lists (direct / full downline with search)
- Shop with cart, wallet or bank-transfer checkout, order history & invoice view
- Earnings breakdown (sponsor / binary / level / rank), wallet statement
- Payout requests with live TDS + admin-charge + net preview
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
  manual activation, full profile + genealogy, and audited member removal with an impact preview
- Orders: approve / reject — **approval instantly runs the commission engine**
- Commission ledger with filters + CSV export
- Payouts: approve / reject (reject refunds the wallet), CSV export for the bank run
- E-wallets: full transaction ledger + manual credit/debit adjustments
- MLM plan settings (activation BV, direct sponsor %, matching rate and unit,
  points, TDS, admin charge and payout minimum)
- Rank ladder (lifetime matched-pair points → one-time rewards), announcements,
  staff accounts, business reports (top earners/sponsors/products, legs, 6-month trend)

## The MLM engine

The amounts below are the **fresh-install defaults**. Existing installations retain their
configured plan, rank and award settings during upgrade.

| Rule | Default |
|---|---|
| Green-ID activation | 3,000 BV in approved self purchases (or superadmin manual activation) |
| Direct sponsor | 10% of activation BV = ₹300 once for each newly green directly sponsored member |
| Binary matching | ₹450 for every 3,000 BV left + 3,000 BV right pair |
| First-pair sponsor matching | 50% of the direct member's first pair = ₹225, subject to eligibility and placement |
| Points / ranks | 1 point per matched pair; each reached rank reward is credited once |
| Payout deductions | ₹500 minimum, 5% TDS and 5% admin charge |

Direct sponsor income goes to the **actual sponsor**, even if placement is elsewhere,
and is not limited to one member per leg. All approved team BV contributes to matching,
including BV from members who are not yet green. The placement upline receives matching;
if the recipient must be green or is blocked, matching and activation bonuses wait until
eligible. Pending matching amounts and points retain their earning-time rates. The
first-pair sponsor benefit belongs to the sponsor and placement in effect when the pair
was earned; it is not transferred to a replacement sponsor. That original sponsor must
be active and green when the first matching payment settles; unlike direct-activation
bonuses, this first-pair benefit is not held if the sponsor is ineligible at settlement.

Matching and points maintain processed-volume watermarks. An upgrade or tree change
never replays previously present/processed volume: new eligible volume must cross the
watermark before it can create another payment. Existing commissions, wallet entries and
payouts are not recalculated. Bulk-generated members default to unactivated earning IDs
and pending KYC. Choosing green IDs or synthetic joining BV requires acknowledging that
it **can create real income**, even without an order; each member and their financial
effects commit together under the current plan. A stopped batch keeps earlier completed
members. Historical recalculation and the former bulk-wipe action are disabled.
Member payout-report estimates apply the current deduction percentages even to prior
periods; actual payout requests retain their original recorded deductions and amounts.

## Removing a member

Only a superadmin can use **Distributors → Delete**. The preview lists the proposed tree,
sponsor, BV, pending-income and retained-record changes. If both branches exist, choose
which branch takes the member's position; the other is grafted intact into the first
free position on its own side beneath the promoted branch. Choose an active replacement
sponsor for direct referrals. If that replacement is itself a direct referral, it takes
the deleted member's old sponsor (or becomes a sponsor root) to avoid self-sponsorship.
Cycles are rejected. Changes apply prospectively and the preview must be reconfirmed if
network or financial data changes, or after 15 minutes.

Removal is a one-way archive, not erasure: the member cannot log in, while historical
orders, commissions, wallet and payout records remain available to superadmins from
**Deleted member audit** and the archived read-only profile. Pending activation bonuses
owed to the removed sponsor are cancelled; a held first-pair sponsor bonus tied to that
sponsor is no longer payable, not transferred. The removed account's held matching
and points cannot be settled while archived. Existing pending orders cannot be approved,
but staff can review/reject them; existing payout requests can still be resolved. A
rejected paid wallet order or payout is refunded to the **archived, inaccessible** wallet,
so staff must arrange a separate financial resolution. Wallet adjustments are retained
in the ledger; refunds no longer count as new earnings.

The preview also reports any legacy leg-BV inconsistencies and shows the recalculated
team BV from retained self BV. Carefully review those changes before confirmation; there
is no automatic undo. Removing the last root leaves an empty network; a superadmin can
then create a new first root.

## Requirements

- PHP **7.4+** (8.x ready) with `pdo_mysql`, `mbstring` (uses only standard extensions)
- MySQL **5.7+** / MariaDB 10.3+
- Apache (or any web server) with `mod_rewrite` optional — pretty URLs work both ways

## Installation

1. Upload the repository to your web root.
2. Create an empty MySQL database (or let the installer create it).
3. Browse to **`/install/`** and fill in the database details — tables, seed data
   (product catalogue, categories, pages, sliders, testimonials, documents, MLM plan,
   ranks) and demo media are installed automatically.
4. Leave `install/install.lock` in place to prevent reinstallation, and restrict
   access to installer files after setup.

## Upgrading an existing installation

1. Put the site in maintenance mode and back up the **database and uploads**. Test the
   upgrade and a representative removal preview on a copy first. MySQL schema changes
   may not be fully transactional; keep the backup until reconciliation is complete.
2. Deploy the new files without overwriting production configuration or uploads. In the
   existing `config.php`, set `APP_VERSION` to `1.8.49` so browsers refresh versioned
   assets; retain the site's database credentials and other settings. **Do not run the
   fresh installer** on an existing database: it drops and reseeds tables.
3. Sign in as superadmin, open **`/install/upgrade.php`**, and run the upgrade. It is
   CSRF-protected and may be rerun. Optional content/catalog refreshes are off by default;
   select them only deliberately. Existing MLM plan, rank and award definitions remain
   unchanged. Newly required schema and a prospective matching baseline are added.
4. Verify member counts, tree placement, balances, orders and payouts against the backup,
   then resume writes. Old green IDs and already present matched BV do not receive
   retroactive commissions; only future eligible events follow the new rules.

The lock that serializes network and financial mutations is established by this upgrade;
registration, approval and removal require the upgrade to have completed first.

## Integration tests

The following suite repeatedly **drops and recreates the application's tables** in its
target database. Create an empty disposable database named with the `yash_test_` prefix,
use credentials with permission to modify it, and never point it at live data. Connection
host/user/password come from the usual configuration.

```sh
# Example: create an isolated database first using your MySQL administration tool.
YASH_TEST_DB=yash_test_sandbox php tests/income_tree_integration.php
```

## Default credentials (change after first login!)

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
