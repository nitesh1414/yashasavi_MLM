<?php
/**
 * Seed data — creates default admin accounts, the root distributor,
 * website content (pages, sliders, products, categories, testimonials),
 * MLM plan settings, levels and ranks.
 */

function seed_database($fresh = false)
{
    $pdo = db();

    /* ---------------------------------------------------------------- */
    /*  Drop existing tables (fresh install)                            */
    /* ---------------------------------------------------------------- */
    if ($fresh) {
        $tables = ['wallet_transactions', 'commissions', 'payouts', 'order_items', 'orders',
                   'announcements', 'enquiries', 'documents', 'testimonials', 'sliders',
                   'pages', 'products', 'categories', 'users', 'plan_levels', 'ranks',
                   'plan_settings', 'admins', 'settings'];
        foreach ($tables as $t) {
            $pdo->exec("DROP TABLE IF EXISTS `$t`");
        }
        foreach (install_schema() as $stmt) {
            $pdo->exec($stmt);
        }
    }

    /* ---------------------------------------------------------------- */
    /*  Staff accounts                                                  */
    /* ---------------------------------------------------------------- */
    $now = now();
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
       'Yashasavi Ayurveda', 'support@yashasavi.test', '9876543210', 'Indian',
       'Yashasavi Ayurveda Pvt. Ltd., Nagpur, Maharashtra', 'Nagpur', 'Maharashtra',
       $now, $now]);
    q("UPDATE users SET path = CONCAT('/', id, '/') WHERE id = LAST_INSERT_ID()");

    /* ---------------------------------------------------------------- */
    /*  MLM plan settings, levels and ranks                             */
    /* ---------------------------------------------------------------- */
    q("INSERT INTO plan_settings (id, activation_bv, sponsor_percent, pair_unit_bv, binary_type,
       binary_value, level_depth, daily_cap, carry_forward, matching_requires_active,
       level_requires_active, tds_percent, admin_charge_percent, payout_min, updated_at)
       VALUES (1, 100, 5, 100, 'percent', 10, 5, 5000, 1, 1, 1, 5, 5, 500, ?)", [$now]);

    $levels = [1 => 5.00, 2 => 3.00, 3 => 2.00, 4 => 1.00, 5 => 1.00, 6 => 0, 7 => 0, 8 => 0, 9 => 0, 10 => 0];
    foreach ($levels as $ln => $pct) {
        q("INSERT INTO plan_levels (level_no, percent) VALUES (?, ?)", [$ln, $pct]);
    }

    $ranks = [
        ['Silver',        500,    0,   0,    1],
        ['Gold',          2000,   2,   250,  2],
        ['Diamond',       10000,  3,   1000, 3],
        ['Crown',         50000,  5,   5000, 4],
        ['Crown Star',    250000, 5,   25000, 5],
    ];
    foreach ($ranks as $r) {
        q("INSERT INTO ranks (name, min_team_bv, min_directs, reward_amount, badge, sort_order)
           VALUES (?, ?, ?, ?, ?, ?)", [$r[0], $r[1], $r[2], $r[3], strtolower(str_replace(' ', '-', $r[0])), $r[4]]);
    }

    /* ---------------------------------------------------------------- */
    /*  Site settings                                                   */
    /* ---------------------------------------------------------------- */
    $settings = [
        'site_name'        => 'Yashasavi Ayurveda',
        'site_tagline'     => 'Health • Wealth • Wellness',
        'site_logo'        => 'site/logo.png',
        'site_favicon'     => 'site/favicon.png',
        'contact_email'    => 'support@yashasaviayurveda.com',
        'contact_phone'    => '+91 98765 43210',
        'contact_address'  => 'Yashasavi Ayurveda Pvt. Ltd., Nagpur, Maharashtra, India - 440010',
        'footer_about'     => 'Yashasavi Ayurveda Pvt. Ltd. is a direct selling company dedicated to seeking out the best natural sources and technologies for health, personal care and general wellness.',
        'social_facebook'  => 'https://facebook.com/',
        'social_instagram' => 'https://instagram.com/',
        'social_youtube'   => 'https://youtube.com/',
        'social_twitter'   => 'https://twitter.com/',
        'seo_meta_title'   => 'Yashasavi Ayurveda — Natural Health & Wellness Direct Selling Company',
        'seo_meta_desc'    => 'Yashasavi Ayurveda offers pure Ayurvedic health, personal care and nutrition products with a genuine direct selling business opportunity.',
        'how_works_1_title' => 'Register',
        'how_works_1_text'  => 'Register yourself as a distributor with a sponsor ID.',
        'how_works_2_title' => 'Buy Product',
        'how_works_2_text'  => 'Buy products at special distributor price (DP).',
        'how_works_3_title' => 'Earn',
        'how_works_3_text'  => 'Sell and earn extra revenue with binary & level income.',
        'stats_customers'  => '5000+',
        'stats_products'   => '25+',
        'stats_distributors' => '1000+',
        'stats_states'     => '12+',
        'announcement_bar' => 'Welcome to Yashasavi Ayurveda — become a distributor today and build your own business!',
        'currency_symbol'  => '₹',
    ];
    foreach ($settings as $k => $v) {
        save_setting($k, $v);
    }

    /* ---------------------------------------------------------------- */
    /*  Categories                                                      */
    /* ---------------------------------------------------------------- */
    $cats = [
        ['Health Care',  'health-care',  'Ayurvedic health care products for immunity, digestion and vitality.', 'health-care.jpg', 1],
        ['Womens Care',  'womens-care',  'Specialised Ayurvedic wellness products for women.', 'womens-care.jpg', 2],
        ['Nutrition',    'nutrition',    'Nutritional supplements and health juices.', 'nutrition.jpg', 3],
        ['Personal Care','personal-care','Soaps, oils and natural personal care products.', 'personal-care.jpg', 4],
        ['Agro Care',    'agro-care',    'Natural agricultural and plant care solutions.', 'agro-care.jpg', 5],
        ['Home Care',    'home-care',    'Natural home care essentials.', 'home-care.jpg', 6],
    ];
    foreach ($cats as $c) {
        q("INSERT INTO categories (name, slug, description, image, sort_order, status, created_at)
           VALUES (?, ?, ?, ?, ?, 'active', ?)", [$c[0], $c[1], $c[2], 'categories/' . $c[3], $c[4], $now]);
    }

    /* ---------------------------------------------------------------- */
    /*  Products (based on the reference product catalogue)             */
    /* ---------------------------------------------------------------- */
    $products = [
        // name, slug, size, cat_slug, mrp, dp, bv, featured, short
        ['Miracle Magic 24 (Capsule)', 'miracle-magic-24', '120 Capsules', 'health-care', 2100, 1680, 1680, 1,
         'A premium blend of 24 Ayurvedic herbs for complete wellness and daily vitality.'],
        ['99 Berries Premium Juice', '99-berries-premium-juice', '750 ML', 'nutrition', 2350, 1880, 1880, 1,
         'Powerful antioxidant juice made from a rich mix of berries and Ayurvedic fruits.'],
        ['Haldi Chandan Premium Soap', 'haldi-chandan-premium-soap', '75 GM', 'personal-care', 65, 52, 52, 0,
         'Handmade soap with pure turmeric and sandalwood for naturally glowing skin.'],
        ['Ashwagandha Shilajit (Capsule)', 'ashwagandha-shilajit', '500 MG', 'health-care', 1250, 1000, 1000, 1,
         'Ashwagandha and Shilajit combination for strength, stamina and energy.'],
        ['Orthos XT (Tablet)', 'orthos-xt', '750 MG', 'health-care', 650, 520, 520, 0,
         'Advanced ortho-care formula for healthy bones and joints.'],
        ['Sciatina XT (Tablet)', 'sciatina-xt', '750 MG', 'health-care', 650, 520, 520, 0,
         'Ayurvedic support for sciatica and nerve health.'],
        ['Maxilas XT (Choorna)', 'maxilas-xt', '100 GM', 'nutrition', 250, 200, 200, 0,
         'Classical Ayurvedic choorna for digestion and metabolism.'],
        ['Arthos Joint Power Plus (Oil)', 'arthos-joint-power-plus', '100 ML', 'personal-care', 350, 280, 280, 0,
         'Warm herbal oil for joint pain relief and muscle relaxation.'],
        ['Coloq XT (Tablet)', 'coloq-xt', '750 MG', 'health-care', 600, 480, 480, 0,
         'Colon care formula for a clean and healthy digestive system.'],
        ['Amrut Red Juice', 'amrut-red-juice', '600 ML', 'nutrition', 1699, 1360, 1360, 1,
         'Red fruit Ayurvedic juice rich in antioxidants for daily immunity.'],
    ];
    $catIds = [];
    foreach (q_all("SELECT id, slug FROM categories") as $c) {
        $catIds[$c['slug']] = $c['id'];
    }
    $sort = 1;
    foreach ($products as $p) {
        q("INSERT INTO products (category_id, name, slug, size, mrp, dp, bv, short_desc, description,
           benefits, ingredients, how_to_use, image, stock, is_featured, status, sort_order, created_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 500, ?, 'active', ?, ?)",
          [$catIds[$p[3]], $p[0], $p[1], $p[2], $p[4], $p[5], $p[6], $p[8],
           '<p>' . $p[8] . ' Sourced from pure natural ingredients and manufactured under strict quality standards, this product brings the authentic power of Ayurveda to your daily life.</p>',
           'Supports overall wellness<br>Made from natural ingredients<br>No harmful chemicals<br>Quality tested formulation',
           'Natural Ayurvedic herbs and extracts.',
           'Use as directed on the product label or as advised by your physician.',
           'products/' . $p[1] . '.jpg', $p[7], $sort++, $now]);
    }

    /* ---------------------------------------------------------------- */
    /*  Sliders                                                         */
    /* ---------------------------------------------------------------- */
    $sliders = [
        ['Purest & Most Healthy Ingredients', 'Yashasavi Ayurveda Products', 'Discover the authentic power of Ayurveda with our premium range of natural products.', 'sliders/slide-1.jpg', 'Explore Products', 'products.php'],
        ['Highest Quality Products', 'Naturally Sourced, Quality Assured', 'Every product is made from pure natural ingredients under strict quality standards.', 'sliders/slide-2.jpg', 'Know More', 'page.php?slug=about-us'],
        ['Better Earnings. Better Lifestyle.', 'Become a Distributor Today', 'Register, buy at distributor price and earn binary & level income with your team.', 'sliders/slide-3.jpg', 'Join Now', 'register.php'],
    ];
    $s = 1;
    foreach ($sliders as $sl) {
        q("INSERT INTO sliders (title, subtitle, description, image, btn_text, btn_link, sort_order, status)
           VALUES (?, ?, ?, ?, ?, ?, ?, 'active')", [$sl[0], $sl[1], $sl[2], $sl[3], $sl[4], $sl[5], $s++]);
    }

    /* ---------------------------------------------------------------- */
    /*  Testimonials                                                    */
    /* ---------------------------------------------------------------- */
    $testimonials = [
        ['Rajesh Kumar', 'Distributor — Nagpur', 'Yashasavi products are genuinely effective and my customers keep coming back. The income plan is transparent and payouts are always on time.', 5, 1],
        ['Sunita Deshmukh', 'Distributor — Pune', 'I started as a part-time distributor and now I have a team of over 40 people. The training and support from the company is excellent.', 5, 2],
        ['Dr. Amit Verma', 'Wellness Advisor', 'As a practitioner I recommend these products confidently — pure formulations and visible results.', 5, 3],
    ];
    foreach ($testimonials as $t) {
        q("INSERT INTO testimonials (name, designation, photo, content, rating, sort_order, status)
           VALUES (?, ?, NULL, ?, ?, ?, 'active')", [$t[0], $t[1], $t[2], $t[3], $t[4]]);
    }

    /* ---------------------------------------------------------------- */
    /*  Legal documents & downloads                                     */
    /* ---------------------------------------------------------------- */
    q("INSERT INTO documents (title, type, image, description, sort_order, status) VALUES
       (?, 'legal', 'site/legal-sample.jpg', 'Certificate of Incorporation of Yashasavi Ayurveda Pvt. Ltd.', 1, 'active')",
      ['Certificate of Incorporation']);
    q("INSERT INTO documents (title, type, image, description, sort_order, status) VALUES
       (?, 'legal', 'site/legal-sample.jpg', 'Company PAN card.', 2, 'active')",
      ['PAN Card']);
    q("INSERT INTO documents (title, type, file, description, sort_order, status) VALUES
       (?, 'download', 'downloads/company-brochure.pdf', 'Company brochure with product catalogue.', 1, 'active')",
      ['Company Brochure']);
    q("INSERT INTO documents (title, type, file, description, sort_order, status) VALUES
       (?, 'download', 'downloads/business-plan.pdf', 'Complete MLM business plan presentation.', 2, 'active')",
      ['Business Plan Presentation']);

    /* ---------------------------------------------------------------- */
    /*  CMS pages                                                       */
    /* ---------------------------------------------------------------- */
    $pages = [
        [
            'title' => 'About Us', 'slug' => 'about-us', 'menu_order' => 1, 'show_in_menu' => 1,
            'content' => '<h2>Welcome to Yashasavi Ayurveda</h2>
<p>Yashasavi Ayurveda Pvt. Ltd. is a Direct Selling company dedicated to seeking out the best natural sources and technologies for health, personal care and general wellness. Throughout our history, our business has grown and changed enormously based on three enduring elements — <strong>purpose, values and principles</strong> — and will continue to do so for the coming generations of Yashasavi Ayurveda.</p>
<p>Our purpose unites us in a common cause and growth strategy to improve the lives of customers in a small but meaningful way each day. It inspires Yashasavi Ayurveda people to make a positive contribution every day to improve the lives of consumers. Our values reflect the behaviours and tone of our work with each other and with our direct sellers, and our principles articulate a unique approach towards day-to-day work.</p>
<h3>Our Goal</h3>
<p>The goal of the company is to make people aware of complete unity and support for each other and to provide them good health — the backbone of society and the nation.</p>
<h3>Our Vision</h3>
<p>To be an ethical and exemplary enterprise of the people, by the people, for the people.</p>
<h3>Our Mission</h3>
<p>To help people get healthy and stay healthy — without having to spend a fortune to do it.</p>
<h3>Our Values</h3>
<p>We are honest and straightforward, and operate within the spirit of the law by upholding the values and principles of Yashasavi Ayurveda in every action and decision. We always try to do the right thing. We are all leaders in our area of responsibility, with a deep commitment towards delivering leadership results. We act like owners, treating the company\'s assets as our own, and accept personal accountability for the company\'s long-term success by constantly improving our systems and their effectiveness.</p>',
        ],
        [
            'title' => 'Opportunity', 'slug' => 'opportunity', 'menu_order' => 2, 'show_in_menu' => 1,
            'content' => '<h2>Better Earnings. Better Lifestyle.</h2>
<p>Time to take action! Becoming a Distributor of Yashasavi Ayurveda is simple and easy.</p>
<div class="steps-grid">
<div class="step-card"><h4>1. Register</h4><p>Register yourself as a Distributor using a sponsor ID. Registration is free.</p></div>
<div class="step-card"><h4>2. Buy Product</h4><p>Purchase products at the special Distributor Price (DP) from your dashboard shop.</p></div>
<div class="step-card"><h4>3. Earn</h4><p>Sell at MRP and earn retail profit, plus binary matching and level income on your growing team.</p></div>
</div>
<h3>Five ways to earn</h3>
<ul>
<li><strong>Retail Profit</strong> — sell products at MRP and keep the margin over DP.</li>
<li><strong>Direct Sponsor Bonus</strong> — earn a percentage of BV on every purchase by your directly sponsored members.</li>
<li><strong>Binary Pair Matching Income</strong> — build a left and right team and earn on matched pairs; unmatched BV carries forward.</li>
<li><strong>Level Income</strong> — earn up to 5 levels deep on your team\'s purchases.</li>
<li><strong>Rank Rewards</strong> — achieve ranks and win reward amounts as your team grows.</li>
</ul>
<p><em>Login to your dashboard after registration to see the live marketing plan, your genealogy tree and income reports.</em></p>',
        ],
        [
            'title' => 'Legals', 'slug' => 'legals', 'menu_order' => 3, 'show_in_menu' => 1,
            'content' => '<h2>Legals</h2>
<p>Yashasavi Ayurveda Pvt. Ltd. is a legitimate business opportunity and follows Direct Selling Standards as per Govt. of India. Below are our statutory documents.</p>',
        ],
        [
            'title' => 'Promotion', 'slug' => 'promotion', 'menu_order' => 4, 'show_in_menu' => 1,
            'content' => '<h2>Promotional Material</h2>
<p>Download our official brochure and business plan presentation to share with your prospects.</p>',
        ],
        [
            'title' => 'Terms & Conditions', 'slug' => 'terms-and-conditions', 'menu_order' => 90, 'show_in_menu' => 0,
            'content' => '<h2>Terms &amp; Conditions</h2>
<p>By using this website and participating in the Yashasavi Ayurveda business opportunity, you agree to the following terms:</p>
<ol>
<li>Distributorship is open to individuals of 18 years of age and above.</li>
<li>The company may modify the marketing plan, product prices and BV values at any time.</li>
<li>Commissions are calculated only on approved purchases at the declared BV of each product.</li>
<li>Distributors must not make any income claims or medical claims about the products.</li>
<li>Indulging in unethical practices may lead to termination of the distributorship.</li>
<li>All disputes are subject to the jurisdiction of Nagpur, Maharashtra.</li>
</ol>',
        ],
        [
            'title' => 'Privacy Policy', 'slug' => 'privacy-policy', 'menu_order' => 91, 'show_in_menu' => 0,
            'content' => '<h2>Privacy Policy</h2>
<p>We respect your privacy. Personal information collected during registration and purchases (name, contact details, bank details, KYC documents) is used solely for operating your distributor account, paying commissions and statutory compliance. We never sell your data to third parties. You may request correction or deletion of your data by contacting support.</p>',
        ],
    ];
    foreach ($pages as $p) {
        q("INSERT INTO pages (title, slug, content, meta_title, meta_description, show_in_menu, menu_order, status, created_at, updated_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, 'published', ?, ?)",
          [$p['title'], $p['slug'], $p['content'], $p['title'] . ' — ' . 'Yashasavi Ayurveda', substr(strip_tags($p['content']), 0, 200),
           $p['show_in_menu'], $p['menu_order'], $now, $now]);
    }

    /* ---------------------------------------------------------------- */
    /*  Announcement                                                    */
    /* ---------------------------------------------------------------- */
    q("INSERT INTO announcements (title, content, status, created_by, created_at) VALUES
       (?, ?, 'active', 1, ?)",
      ['Welcome to Yashasavi Ayurveda!',
       'Dear Distributors, welcome to the new Yashasavi Ayurveda portal. Complete your profile and KYC details to receive fast payouts. For any help, contact support.', $now]);
}

/** helper used by the installer */
function install_schema()
{
    return require __DIR__ . '/schema.php';
}
