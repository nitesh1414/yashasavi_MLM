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
  manual activation, full profile + genealogy
- Orders: approve / reject — **approval instantly runs the commission engine**
- Commission ledger with filters + CSV export
- Payouts: approve / reject (reject refunds the wallet), CSV export for the bank run
- E-wallets: full transaction ledger + manual credit/debit adjustments
- MLM plan settings (activation BV, sponsor %, binary % / fixed per pair, pair unit,
  daily cap, carry-forward, level depth, TDS, admin charge, payout minimum) and
  level percentages (1–10)
- Rank ladder (team BV + active directs → one-time rewards), announcements,
  staff accounts, business reports (top earners/sponsors/products, legs, 6-month trend)

## The MLM engine

| Income stream | Default |
|---|---|
| Activation | 100 BV lifetime self-purchase |
| Direct sponsor bonus | 5% of order BV |
| Level income (5 levels) | 5% / 3% / 2% / 1% / 1% of order BV |
| Binary matching | 10% of matched BV, 1:1 units of 100 BV |
| Daily cap / carry forward | ₹5,000 / unmatched BV carries forward |
| Rank rewards | Silver → Crown Star (₹0 – ₹25,000 one-time) |
| Payouts | min ₹500, TDS 5%, admin charge 5% |

Commissions are credited to e-wallets atomically (MySQL transactions) when an order is
approved; binary legs, matched pairs, team BV, ranks and wallets are updated in the same
transaction. All parameters above are editable from the Super Admin panel.

## Requirements

- PHP **7.4+** (8.x ready) with `pdo_mysql`, `mbstring` (uses only standard extensions)
- MySQL **5.7+** / MariaDB 10.3+
- Apache (or any web server) with `mod_rewrite` optional — pretty URLs work both ways

## Installation

1. Upload the repository to your web root.
2. Create an empty MySQL database (or let the installer create it).
3. Browse to **`/install/`** and fill in the database details — tables, seed data
   (10 products, 6 categories, 6 pages, sliders, testimonials, documents, MLM plan,
   ranks) and demo media are installed automatically.
4. Delete the `install/` directory (or leave `install/install.lock` in place) when done.

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
