<?php
/**
 * Seed data — creates default admin accounts, the root distributor,
 * website content (pages, sliders, products, categories, testimonials),
 * MLM plan settings, levels, ranks and award rewards.
 *
 * All reference content (plan figures, ranks, awards, settings, catalogue,
 * pages …) lives in install/seed-data.php so the upgrader shares it.
 */

require_once __DIR__ . '/seed-data.php';

function seed_database($fresh = false)
{
    $pdo = db();

    /* ---------------------------------------------------------------- */
    /*  Drop existing tables (fresh install)                            */
    /* ---------------------------------------------------------------- */
    if ($fresh) {
        $tables = ['wallet_transactions', 'commissions', 'payouts', 'order_items', 'orders',
                   'announcements', 'enquiries', 'documents', 'testimonials', 'sliders',
                   'pages', 'products', 'categories', 'users', 'award_rewards', 'plan_levels',
                   'ranks', 'plan_settings', 'admins', 'settings'];
        foreach ($tables as $t) {
            $pdo->exec("DROP TABLE IF EXISTS `$t`");
        }
        foreach (install_schema() as $stmt) {
            $pdo->exec($stmt);
        }
    }

    $now = now();
    $D = seed_data();

    /* ---------------------------------------------------------------- */
    /*  Staff accounts                                                  */
    /* ---------------------------------------------------------------- */
    q("INSERT INTO admins (name, username, email, password, role, status, created_at) VALUES
       (?, ?, ?, ?, 'superadmin', 'active', ?)",
      ['Super Admin', 'superadmin', 'superadmin@yashasavi.test',
       password_hash('Super@123', PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), $now]);
    q("INSERT INTO admins (name, username, email, password, role, status, created_at) VALUES
       (?, ?, ?, ?, 'admin', 'active', ?)",
      ['Website Admin', 'admin', 'admin@yashasavi.test',
       password_hash('Admin@123', PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), $now]);

    /* ---------------------------------------------------------------- */
    /*  Root distributor (company account)                              */
    /* ---------------------------------------------------------------- */
    q("INSERT INTO users (username, password, full_name, email, mobile, nationality, address,
       city, state, sponsor_id, placement_id, leg, path, depth, is_active, activated_at,
       status, kyc_status, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, 'L', '/', 0, 1, ?, 'active', 'verified', ?)",
      ['YSH100001', password_hash('User@123', PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]),
       'Yashasavi Veda Herbals Private Limited', 'support@yashasaviveda.in', '9529512562', 'Indian',
       'Plot No. 6, T. M. I. D. C. Road, Tukum, Chandrapur, Maharashtra - 442401', 'Chandrapur',
       'Maharashtra', $now, $now]);
    q("UPDATE users SET path = CONCAT('/', id, '/') WHERE id = LAST_INSERT_ID()");

    /* ---------------------------------------------------------------- */
    /*  MLM plan settings, levels, ranks and award rewards              */
    /* ---------------------------------------------------------------- */
    $plan = $D['plan'];
    q("INSERT INTO plan_settings (id, activation_bv, sponsor_percent, pair_unit_bv, binary_type,
       binary_value, level_depth, daily_cap, monthly_cap, carry_forward, matching_requires_active,
       level_requires_active, sponsor_matching_percent, point_bv, car_fund_points, car_fund_amount,
       tds_percent, admin_charge_percent, payout_min, updated_at)
       VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
      [$plan['activation_bv'], $plan['sponsor_percent'], $plan['pair_unit_bv'], $plan['binary_type'],
       $plan['binary_value'], $plan['level_depth'], $plan['daily_cap'], $plan['monthly_cap'],
       $plan['carry_forward'], $plan['matching_requires_active'], $plan['level_requires_active'],
       $plan['sponsor_matching_percent'], $plan['point_bv'], $plan['car_fund_points'],
       $plan['car_fund_amount'], $plan['tds_percent'], $plan['admin_charge_percent'],
       $plan['payout_min'], $now]);

    foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10] as $ln) {
        q("INSERT INTO plan_levels (level_no, percent) VALUES (?, 0)", [$ln]);
    }

    foreach ($D['ranks'] as $r) {
        q("INSERT INTO ranks (name, min_team_bv, min_directs, reward_amount, badge, sort_order)
           VALUES (?, ?, ?, ?, ?, ?)",
          [$r[0], $r[1] * 400, $r[2], $r[3], strtolower(str_replace(' ', '-', $r[0])), $r[4]]);
    }

    $s = 1;
    foreach ($D['awards'] as $a) {
        q("INSERT INTO award_rewards (points, reward_title, reward_type, amount, description, status, sort_order, created_at)
           VALUES (?, ?, ?, ?, ?, 'active', ?, ?)", [$a[0], $a[1], $a[2], $a[3], $a[4], $s++, $now]);
    }

    /* ---------------------------------------------------------------- */
    /*  Site settings                                                   */
    /* ---------------------------------------------------------------- */
    foreach ($D['settings'] as $k => $v) {
        save_setting($k, $v);
    }

    /* ---------------------------------------------------------------- */
    /*  Categories                                                      */
    /* ---------------------------------------------------------------- */
    foreach ($D['cats'] as $c) {
        q("INSERT INTO categories (name, slug, description, image, sort_order, status, created_at)
           VALUES (?, ?, ?, ?, ?, 'active', ?)", [$c[0], $c[1], $c[2], 'categories/' . $c[3], $c[4], $now]);
    }

    /* ---------------------------------------------------------------- */
    /*  Products — Yashasavi Veda Herbals catalogue                     */
    /* ---------------------------------------------------------------- */
    $catIds = [];
    foreach (q_all("SELECT id, slug FROM categories") as $c) {
        $catIds[$c['slug']] = $c['id'];
    }
    $sort = 1;
    foreach ($D['products'] as $p) {
        q("INSERT INTO products (category_id, name, slug, size, mrp, dp, bv, short_desc, description,
           benefits, ingredients, how_to_use, image, stock, is_featured, status, sort_order, created_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 500, ?, 'active', ?, ?)",
          [$catIds[$p[3]], $p[0], $p[1], $p[2], $p[4], $p[5], $p[6], $p[8],
           '<p>' . $p[8] . '</p><p>A 100% natural, quality-tested Yashasavi Veda Herbals formulation — pure Ayurveda for a healthy life and wellness for a better tomorrow.</p>',
           $p[9],
           'Natural Ayurvedic herbs and extracts.',
           'Use as directed on the product label or as advised by your physician.',
           'products/' . $p[1] . '.jpg', $p[7], $sort++, $now]);
    }

    /* ---------------------------------------------------------------- */
    /*  Sliders                                                         */
    /* ---------------------------------------------------------------- */
    $s = 1;
    foreach ($D['sliders'] as $sl) {
        q("INSERT INTO sliders (title, subtitle, description, image, btn_text, btn_link, sort_order, status)
           VALUES (?, ?, ?, ?, ?, ?, ?, 'active')", [$sl[0], $sl[1], $sl[2], $sl[3], $sl[4], $sl[5], $s++]);
    }

    /* ---------------------------------------------------------------- */
    /*  Testimonials                                                    */
    /* ---------------------------------------------------------------- */
    foreach ($D['testimonials'] as $t) {
        q("INSERT INTO testimonials (name, designation, photo, content, rating, sort_order, status)
           VALUES (?, ?, NULL, ?, ?, ?, 'active')", [$t[0], $t[1], $t[2], $t[3], $t[4]]);
    }

    /* ---------------------------------------------------------------- */
    /*  Legal documents & downloads                                     */
    /* ---------------------------------------------------------------- */
    foreach ($D['legalDocs'] as $d) {
        q("INSERT INTO documents (title, type, image, description, sort_order, status) VALUES
           (?, 'legal', ?, ?, ?, 'active')", [$d[0], $d[1], $d[2], $d[3]]);
    }
    q("INSERT INTO documents (title, type, file, description, sort_order, status) VALUES
       (?, 'download', NULL, 'The complete product catalogue and marketing plan presentation is available from your dashboard and support team.', 1, 'active')",
      ['Product Catalogue & Plan Presentation']);

    /* ---------------------------------------------------------------- */
    /*  CMS pages                                                       */
    /* ---------------------------------------------------------------- */
    foreach ($D['pages'] as $p) {
        q("INSERT INTO pages (title, slug, content, meta_title, meta_description, show_in_menu, menu_order, status, created_at, updated_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, 'published', ?, ?)",
          [$p['title'], $p['slug'], $p['content'], $p['title'] . ' — Yashasavi Veda Herbals Private Limited', substr(strip_tags($p['content']), 0, 200),
           $p['show_in_menu'], $p['menu_order'], $now, $now]);
    }

    /* ---------------------------------------------------------------- */
    /*  Announcement                                                    */
    /* ---------------------------------------------------------------- */
    q("INSERT INTO announcements (title, content, status, created_by, created_at) VALUES
       (?, ?, 'active', 1, ?)",
      ['Welcome to Yashasavi Veda Herbals Private Limited — Your Dream, Your Better!',
       'Dear Distributors, welcome to the new Yashasavi Veda Herbals Private Limited portal. Complete your profile and KYC details to receive fast payouts and become eligible for rewards. For any help, contact customer care: 9529512562.', $now]);
}

/** helper used by the installer */
function install_schema()
{
    return require __DIR__ . '/schema.php';
}
