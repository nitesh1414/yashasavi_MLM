# User Panel — Reference Portal Analysis & Changes

**Reference:** https://www.zaadiayurveda.com/Login/Login (User panel)
**Our project:** Yashasavi Veda Herbals — Distributor Panel
**Document date:** v1.8.43 · round r66

---

## 1. How the reference was analyzed

The reference portal is an ASP.NET MVC (.NET 4.8) application. The following could be
verified directly from this environment:

| Area | Status |
| --- | --- |
| Public website (Home, About Us, Products, Opportunity, Legals, Promotion, Contact) | Fully analyzed (absorbed into our project in earlier rounds) |
| Login page + Forgot Password page (`/Login/Login`, `/Login/Reset`) | Fully analyzed |
| Registration form (`/Login/Register`) — every field | Fully analyzed (absorbed in earlier rounds) |
| Member panel routes (server structure) | Mapped by probing — see below |
| **Logged-in pages themselves** | **Not reachable from this environment** — the analysis tooling can only fetch public URLs (it cannot submit the login form or hold a login session), and the sandbox has no direct network access to the portal. Conclusions about logged-in pages below come from the route map, the server's own responses and the standard dataflow of this portal family. |

The account credentials shared for the analysis (ZA…12) belong to the reference site's
member area, which requires a browser session. If you share screenshots of the logged-in
pages, the comparison can be extended page by page.

### Route map of the reference portal (verified)

| URL | Finding |
| --- | --- |
| `/Login/Login` | Member login (member ID + password, POST-only). "Forgot Password" link present. |
| `/Login/Reset` | "Recover Your Password" → **Get Password** flow. |
| `/Login/Register` | Registration: Sponsor ID + auto Sponsor Name, Placement (LEFT/RIGHT), Full Name, Email, Mobile, DOB, Marital Status, Nationality, State, City, Pin Code, Nominee + relation, bank details (holder, account no. + **retype**, IFSC, bank, branch), Aadhaar, PAN, Address, terms checkbox. |
| `/Home/Index` | Public site with nav + **three login types**: User Login, Franchise Login (franchise.…), Depo Login (depo.…). |
| `/User/Index` | Member area entry (auth-protected — redirects to login). |
| `/Report` | Member dashboard (confirmed: it calls stored procedure `SP_DASHBOARD_DETAILS` with mode `GET_BALANCE_WITH_WALLET` — i.e. the dashboard leads with **wallet balance**). Note: their dashboard page crashes publicly when not logged in; we deliberately do not replicate that behaviour. |
| `/Product` | Product controller (public `Products` catalogue action). |
| Guessed routes (`/Dashboard`, `/Wallet`, `/Payout`, `/Genealogy`, `/Order`, `/News`, …) | 404 — the remaining member actions use names that cannot be discovered without a logged-in session. |

---

## 2. Feature parity — reference vs. our project

| Reference (User panel) | Our project | Status |
| --- | --- | --- |
| Member login with Member ID | `login.php` — Member ID **or** email, with brute-force throttling | ✔ ours is stronger |
| **Forgot password ("Get Password")** | — | ✖ **was missing → added this round** |
| Registration: sponsor lookup, placement L/R, full KYC/bank/nominee fields | `register.php` — all fields incl. sponsor AJAX lookup | ✔ |
| Dashboard with wallet balance | `user/index.php` — wallet, income breakdown, points/rank, team stats | ✔ |
| Genealogy / tree (binary L/R) | `user/tree.php` — interactive tree, leg summary, member details | ✔ |
| Team / downline listing | `user/team.php` — direct + team with filters | ✔ |
| Joining a new member | `user/add-member.php` (plus admin bulk tools) | ✔ |
| Marketing plan / income rules | `user/plan.php` — live plan with the member's own numbers | ✔ |
| Earnings / income statement | `user/earnings.php` — every commission with notes + filters | ✔ |
| Wallet ledger | `user/wallet.php` — every credit/debit | ✔ |
| Payout request + statement | `user/payout.php`, `user/receipt.php` — request with 5% TDS + 5% admin, weekly statements | ✔ |
| Product purchase at DP | `user/shop.php` + `user/cart.php` — DP pricing, BV totals | ✔ |
| Order history | `user/orders.php`, `user/order_view.php` | ✔ |
| KYC upload | `user/kyc.php` | ✔ |
| Profile / bank update | `user/profile.php` | ✔ |
| Change password | `user/password.php` | ✔ |
| Franchise / Depo logins | — | Not in scope (separate portals on the reference) |

**Conclusion:** apart from the forgot-password recovery flow, our User panel already covers
the reference portal's working and dataflow — in most areas with more detail (interactive
tree, weekly payout statements with receipts, wallet ledger, plan page with live numbers).

---

## 3. Changes made this round (v1.8.43)

### 3.1 Forgot Password / password recovery (new) — `forgot.php`

**Why:** the reference portal has a "Forgot Password" recovery flow on its login page
(`/Login/Reset` → "Get Password"). Our project had no self-service recovery — a member who
forgot the password had to contact the super admin.

**Working / dataflow (adapted, not copied):**

```
login.php  ──"Forgot password?"──►  forgot.php
   Step 1: Member ID  (e.g. YSH100002)
   Step 2: Identity check — the FULL registered mobile number
           (masked preview like 98******10 is shown) + date of birth
           from the member's record (DOB is asked only when one is on file)
   Step 3: Set a NEW password (min 8 chars, letters + numbers, typed twice)
           ──► redirect to login.php with a success message
```

Differences from the reference (intentional, security):

* We **never display or email the old password** — the member sets a new one.
* Verification uses the data already on file (mobile + DOB) because the project runs
  without an SMS/e-mail gateway. (The reference's "Get Password" simply hands the
  password back after a lookup.)
* The reset ticket lives only in the server session and **expires after 15 minutes**;
  the "set new password" step cannot be reached without passing verification first.
* The flow is throttled like the login (5 free attempts, then a delay per extra attempt).
* The new password must pass the same strength policy used everywhere else
  (`strong_password_error`: ≥ 8 characters, letters + numbers), and it is stored
  bcrypt-hashed like every other password in the project.

### 3.2 Login page (updated) — `login.php`

A "Forgot password?" link was added directly under the password field, matching the
reference's placement.

### 3.3 No other changes required

Registration, dashboard, tree, team, plan, earnings, wallet, payouts, shop/orders, KYC,
profile and password pages already match or exceed the reference dataflow (see §2).

---

## 4. Usage

### 4.1 Member — recovering a forgotten password

1. Open **Login** and click **Forgot password?** (under the password field).
2. Enter your **Member ID** (e.g. `YSH100002`) → **Continue**.
3. The page shows the masked mobile on file (e.g. `98******10`). Type the **complete
   registered mobile number** and your **date of birth** (DOB is requested only if it is
   on file) → **Verify**.
   - Wrong mobile / DOB → the page says which one did not match; try again.
4. Choose a **new password** (at least 8 characters, must contain letters and numbers),
   type it twice → **Set New Password**.
5. You are redirected to **Login** with *"Your password has been reset"* — log in with
   the member ID + the new password.

Notes:
- The whole recovery must be finished within **15 minutes** of entering the member ID,
  in the same browser.
- If the member has no mobile number on file (or cannot pass verification), the super
  admin can still set a new password manually — see below.

### 4.2 Super admin — manual password reset (already existed)

`Super Admin → Distributors → (member) Edit` → **New password** field → Save.
Use this when a member cannot complete the self-service flow (e.g. changed mobile).

### 4.3 Quick test walk-through (for QA)

1. Fresh install → login as a member (e.g. `YSH100001 / User@123`).
2. Log out → **Forgot password?** → enter the member ID.
3. Enter a wrong mobile → expect *"mobile number does not match"*.
4. Enter the correct mobile + DOB → set `NewPass123` (twice).
5. Log in with the old password → must fail; with `NewPass123` → must succeed.

---

## 5. Security notes (things we deliberately did NOT copy)

| Reference behaviour | Our behaviour |
| --- | --- |
| `/Report` (dashboard) crashes publicly and leaks view source + stored-procedure names when visited without login | Every panel page redirects cleanly to the login page; errors are never displayed to visitors |
| "Get Password" hands the current password back | A new password is set after identity verification; old one is never revealed |
| No visible brute-force protection | Login **and** recovery are throttled; passwords are bcrypt-hashed; all forms are CSRF-protected |

---

*Generated for the Yashasavi MLM project — see `git log` for the matching commit (v1.8.43).*
