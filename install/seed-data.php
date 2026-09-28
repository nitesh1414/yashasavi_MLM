<?php
/**
 * Seed data — the single source of truth for reference content.
 *
 * Used by:
 *   - install/seed.php     (fresh installs — inserts everything)
 *   - install/upgrade.php  (upgrades existing installs — inserts only what is missing)
 *
 * Plan figures follow the official Yashasavi Veda Herbal Private Limited
 * marketing plan document.
 */

function seed_data()
{
    return [

    /* MLM plan settings (row id = 1) */
    'plan' => [
        'activation_bv' => 500, 'sponsor_percent' => 10, 'pair_unit_bv' => 3000,
        'binary_type' => 'fixed', 'binary_value' => 450, 'level_depth' => 0,
        'daily_cap' => 320000, 'monthly_cap' => 800000, 'carry_forward' => 1,
        'matching_requires_active' => 1, 'level_requires_active' => 1,
        'sponsor_matching_percent' => 50, 'point_bv' => 400,
        'car_fund_points' => 500, 'car_fund_amount' => 150000,
        'tds_percent' => 5, 'admin_charge_percent' => 7, 'payout_min' => 500,
    ],

    /* Cash reward ranks — 1 Point = 400 BV team business (min_team_bv = points x 400) */
    'ranks' => [
        // name, points, directs, cash reward, sort
        ['Silver Star',          20, 0,       3000.00,  1],
        ['Gold',                 50, 0,       9000.00,  2],
        ['Director',            250, 0,      20000.00,  3],
        ['Platinum',            900, 0,      60000.00,  4],
        ['Emerald',            2100, 0,     140000.00,  5],
        ['Ruby',               3600, 0,     220000.00,  6],
        ['Sapphire',           6000, 0,     400000.00,  7],
        ['Diamond',           13000, 0,    1000000.00,  8],
        ['Crown Diamond',     25000, 0,    2000000.00,  9],
        ['Black Diamond',     60000, 0,    7500000.00, 10],
        ['Ambassador',       150000, 0,   15000000.00, 11],
        ['Brand Ambassador', 300000, 0,   30000000.00, 12],
        ['President',       1000000, 0,  120000000.00, 13],
        ['President Director', 1500000, 0, 150000000.00, 14],
        ['Chairman',        3000000, 0,  250000000.00, 15],
    ],

    /* Next-to-next matching award rewards (points based) */
    'awards' => [
        // points, title, type, amount, description
        [20,   'Dinner Set',   'item', 0,        'Achieve 20 points to win a premium dinner set.'],
        [40,   'Mixer Grinder', 'item', 0,       'Achieve 40 points to win a mixer grinder.'],
        [800,  'Cash Fund ₹1,00,000', 'cash', 100000.00, 'Achieve 800 points to win a ₹1,00,000 cash fund.'],
    ],

    /* Site settings (key => value) */
    'settings' => [
        'site_name'        => 'Yashasavi Ayurveda',
        'site_tagline'     => 'Health • Wealth • Wellness',
        'site_slogan'      => 'Your Dream Your Better',
        'company_name'     => 'Yashasavi Veda Herbal Private Limited',
        'site_logo'        => '', /* empty = bundled assets/img/logo.png */
        'site_favicon'     => '',
        'contact_email'    => 'support@yashasaviveda.in',
        'contact_phone'    => '+91 95295 12562',
        'contact_address'  => 'Plot No. 6, T. M. I. D. C. Road, Tukum, Chandrapur, Maharashtra - 442401',
        'footer_about'     => 'Yashasavi Veda Herbal Private Limited is a legally registered direct selling company offering 100% Ayurvedic products for health, personal care, home care and agriculture — with a proven business opportunity that lets everyone build multiple sources of income.',
        'social_facebook'  => 'https://facebook.com/',
        'social_instagram' => 'https://instagram.com/',
        'social_youtube'   => 'https://youtube.com/',
        'social_twitter'   => 'https://twitter.com/',
        'seo_meta_title'   => 'Yashasavi Ayurveda — Health • Wealth • Wellness',
        'seo_meta_desc'    => 'Yashasavi Veda Herbal Private Limited — 100% Ayurvedic products and a genuine direct selling business opportunity. Your Dream, Your Better.',
        'how_works_1_title' => 'Register',
        'how_works_1_text'  => 'Register yourself as a distributor with a sponsor ID — registration is free.',
        'how_works_2_title' => 'Buy Product',
        'how_works_2_text'  => 'Buy 100% Ayurvedic products at the special distributor price (DP) and sell at MRP.',
        'how_works_3_title' => 'Earn',
        'how_works_3_text'  => 'Earn retail income, direct sponsor income, binary matching income, rewards and more — no level limit.',
        'stats_customers'  => '5000+',
        'stats_products'   => '16+',
        'stats_distributors' => '1000+',
        'stats_states'     => '12+',
        'announcement_bar' => 'Welcome to Yashasavi Ayurveda — Your Dream, Your Better! Become a distributor today and build multiple sources of income.',
        'currency_symbol'  => '₹',
    ],

    /* Product categories */
    'cats' => [
        // name, slug, description, image, sort
        ['Health Care',  'health-care',  'Ayurvedic health care products — juices, capsules and oils for immunity, joints and vitality.', 'health-care.jpg', 1],
        ['Personal Care','personal-care','Soaps, toothpaste, hair oils, sunscreen and skin care made from natural ingredients.', 'personal-care.jpg', 2],
        ['Home Care',    'home-care',    'Powerful natural home care essentials for a clean and healthy home.', 'home-care.jpg', 3],
        ['Agro Care',    'agro-care',    'Natural agricultural growth boosters and soil conditioners for better yields.', 'agro-care.jpg', 4],
    ],

    /* Products — Yashasavi Veda Herbals catalogue */
    'products' => [
        // name, slug, size, cat_slug, mrp, dp, bv, featured, short, benefits
        ['Multi Herbs Premium Juice', 'multi-herbs-premium-juice', '650 ML', 'health-care', 1499, 999, 750, 1,
         'A super anti-oxidant booster juice made with a rich blend of Ayurvedic herbs in liquid concentrate form — for energy, immunity and everyday wellness.',
         "Powerful natural anti-oxidant blend<br>Boosts energy and immunity<br>Supports daily wellness<br>100% natural formulation"],
        ['Ortho Care Capsules', 'ortho-care-capsules', '60 Capsules (500 mg)', 'health-care', 999, 649, 500, 1,
         'Joint health capsules with Cissus quadrangularis, Boswellia, Guggul, Ashwagandha and Moringa — helps reduce joint pain, swelling and inflammation.',
         "Helps reduce joint pain<br>Decreases inflammation<br>Improves joint mobility<br>Helps manage rheumatoid conditions"],
        ['Sea Buckthorn Juice', 'sea-buckthorn-juice', '500 ML', 'health-care', 1299, 849, 650, 1,
         'Pure Sea Buckthorn juice — rich in Omega 3, 6, 7 and 9, vitamins and minerals. 100% natural with no preservatives.',
         "Rich in Omega fatty acids<br>Enriched with vitamins & minerals<br>Supports heart and skin health<br>No preservatives"],
        ['Ortho Care Juice', 'ortho-care-juice', '750 ML', 'health-care', 999, 649, 500, 0,
         'Ayurvedic ortho care juice that promotes cartilage regeneration and supports strong, flexible joints.',
         "Promotes cartilage regeneration<br>Supports joint strength<br>Natural Ayurvedic herbs<br>Safe for long term use"],
        ['Haldi Chandan Premium Soap', 'haldi-chandan-soap', '75 GM', 'personal-care', 99, 65, 50, 0,
         'Grade 1 premium soap with pure turmeric (Haldi) and sandalwood (Chandan) — cleanses, nourishes and rejuvenates the skin naturally.',
         "Grade 1 pure soap<br>Cleanses & nourishes skin<br>Natural turmeric + sandalwood<br>Suitable for all skin types"],
        ['Activated Charcoal Soap', 'activated-charcoal-soap', '75 GM', 'personal-care', 129, 85, 65, 0,
         'Deep cleansing activated charcoal soap that draws out impurities, oil and pollution — for fresh, clear skin. Suitable for all skin types.',
         "Deep pore cleansing<br>Removes excess oil & impurities<br>Natural activated charcoal<br>For all skin types"],
        ['Herbal Toothpaste (9 Herbs)', 'herbal-toothpaste', '100 GM', 'personal-care', 149, 99, 75, 0,
         'Natural toothpaste with 9 potent herbs — helps prevent cavities, strengthen gums and freshen breath without harsh chemicals.',
         "9 herbal extracts<br>Prevents cavities<br>Strengthens gums & enamel<br>Freshens breath naturally"],
        ['Ayurvedic Hair Oil', 'ayurvedic-hair-oil', '100 ML', 'personal-care', 349, 229, 175, 0,
         'Classical Ayurvedic hair oil for strong, shiny and healthy hair — nourishes the scalp and reduces hair fall.',
         "Strong, shiny, healthy hair<br>Nourishes the scalp<br>Reduces hair fall<br>100% Ayurvedic oils"],
        ['Sunscreen Lotion SPF 50 PA++++', 'sunscreen-lotion', '50 ML', 'personal-care', 449, 299, 225, 1,
         'Broad spectrum fluid sunscreen lotion with Niacinamide and Vitamin derivatives — UVA + UVB protection, SPF 50 PA++++.',
         "Broad spectrum UVA + UVB<br>SPF 50 PA++++ protection<br>With Niacinamide<br>Non-sticky, fluid texture"],
        ['21 Herbs Hair Oil', '21-herbs-hair-oil', '100 ML', 'personal-care', 599, 399, 300, 0,
         'Unique hair oil blended with 21 Ayurvedic and Homeopathy herbs — soothes the scalp and improves hair texture and shine.',
         "21 Ayurveda + Homeopathy herbs<br>Soothes the scalp<br>Improves hair texture<br>Reduces dandruff"],
        ['3-in-1 Face Wash (Cleanse + Exfoliate + Rejuvenate)', 'face-wash-3in1', '100 GM', 'personal-care', 349, 229, 175, 0,
         'Coffee powder and walnut shell 3-in-1 face wash — cleanses, exfoliates and rejuvenates for fresh, glowing skin. Paraben free.',
         "Cleanse + exfoliate + rejuvenate<br>Coffee powder + walnut shell<br>Paraben free<br>Gentle daily use"],
        ['Herbal Ortho Oil', 'herbal-ortho-oil', '60 ML', 'health-care', 349, 229, 175, 1,
         'Ayurvedic pain relief oil for joint pain, sciatica and arthritis — natural care for stronger joints and better movement.',
         "Relieves joint pain<br>Helps in sciatica<br>Arthritis pain support<br>Ayurvedic external use only"],
        ['Toilet Cleaner (10x Power)', 'toilet-cleaner', '500 ML', 'home-care', 199, 129, 100, 0,
         'Powerful 10x toilet cleaner that removes tough stains and bad odour — for a fresh, hygienic toilet and a healthy home.',
         "10x powerful cleaning<br>Removes tough stains<br>Removes bad odour<br>Fresh & hygienic finish"],
        ['Fairness Cream', 'fairness-cream', '50 GM', 'personal-care', 299, 199, 150, 0,
         'Natural fairness cream with Aloe Vera and Vitamin E — brightens skin tone, moisturises, reduces dark spots and repairs skin.',
         "Brightens skin tone<br>Aloe Vera + Vitamin E<br>Non-sticky moisturisation<br>Reduces dark spots"],
        ['Soil Double PowerGuard (Humic Acid)', 'soil-powerguard', '500 GM', 'agro-care', 599, 399, 300, 0,
         'Double power soil conditioner with Humic Acid and natural extracts — improves soil health, structure and nutrient availability.',
         "Humic acid + natural extracts<br>Improves soil health<br>Better nutrient uptake<br>Suitable for all crops"],
        ['Agri Grow Double Super Growth Booster', 'agri-grow', '500 ML', 'agro-care', 499, 329, 250, 0,
         'Double super growth booster for all crops — promotes healthy growth, flowering and better yields naturally.',
         "Double super growth booster<br>Promotes flowering & growth<br>Improves crop yield<br>Natural plant nutrition"],
    ],

    /* Home page sliders */
    'sliders' => [
        // title, subtitle, description, image, btn_text, btn_link
        ['100% Natural • No Preservatives', 'Health • Wealth • Wellness', 'Discover the authentic power of Ayurveda with the premium Yashasavi Veda Herbals range — 16+ products for health, personal, home and agro care.', 'sliders/slide-1.jpg', 'Explore Products', 'products.php'],
        ['Your Dream, Your Better', 'Build Multiple Sources of Income', 'Retail income, direct sponsor income, binary matching income, cash rewards, car fund and much more — a proven plan from ₹3,000 up to ₹25 crore.', 'sliders/slide-2.jpg', 'View the Plan', 'opportunity.php'],
        ['No Level Limit • Carry Forward', 'Unlimited Income Opportunity', 'Left-right carry forward business, no need to manage rank, daily capping ₹3,20,000 — simple, powerful and transparent.', 'sliders/slide-3.jpg', 'Join Now', 'register.php'],
    ],

    /* Testimonials */
    'testimonials' => [
        // name, designation, content, rating, sort
        ['Rajesh Kumar', 'Distributor — Chandrapur', 'Yashasavi products are genuinely effective and my customers keep coming back. The plan is transparent, the matching income is credited on time, and the rewards are real.', 5, 1],
        ['Sunita Deshmukh', 'Distributor — Nagpur', 'I started part-time and now lead a team of 40+ distributors. Direct sponsor income plus binary matching gives me multiple sources of earnings every week.', 5, 2],
        ['Dr. Amit Verma', 'Wellness Advisor', 'As a practitioner I recommend these formulations confidently — 100% Ayurvedic, natural and effective. Patients see visible results.', 5, 3],
    ],

    /* Legal documents & downloads */
    'legalDocs' => [
        // title, image, description, sort
        ['PAN Card', 'documents/pan-card.jpg', 'Permanent Account Number card of Yashasavi Veda Herbal Private Limited (PAN: AACCZ2307Q).', 1],
        ['Udyam Registration Certificate', 'documents/udyam-registration.jpg', 'MSME Udyam registration certificate issued by the Government of India.', 2],
        ['Bank Account Details', 'documents/bank-details.jpg', 'Company bank account details — HDFC Bank, Chandrapur Branch.', 3],
        ['Certificate of Incorporation', 'documents/certificate-of-incorporation.jpg', 'Certificate of Incorporation under the Companies Act, 2013 — registered June 2023.', 4],
        ['FSSAI License Certificate', 'documents/fssai-license.jpg', 'Food Safety and Standards Authority of India license for food category products.', 5],
        ['GST Registration Certificate', 'documents/gst-registration.jpg', 'GST registration certificate issued by the Department of Revenue, Government of India.', 6],
    ],

    /* CMS pages */
    'pages' => [
        [
            'title' => 'About Us', 'slug' => 'about-us', 'menu_order' => 1, 'show_in_menu' => 1,
            'content' => '<h2>Yashasavi Veda Herbal Private Limited</h2>
<p><strong>Ayurveda for a Healthy Life, Wellness for a Better Tomorrow.</strong></p>
<p>Yashasavi Veda Herbal Private Limited is a legally registered and compliant direct selling company from Chandrapur, Maharashtra. We offer a growing range of 100% Ayurvedic and natural products for health care, personal care, home care and agriculture — formulated with pure herbs, no harmful chemicals and no unnecessary preservatives.</p>
<p>All our operations are conducted with transparency, integrity and in full compliance with applicable laws and regulations. Our statutory documents — PAN card, Udyam registration, bank details, Certificate of Incorporation, FSSAI license and GST registration — are available on our <a href="page.php?slug=legals">Legals</a> page.</p>
<h3>Our Goal</h3>
<p>To make people aware of complete unity and support for each other, and to provide them good health — the backbone of society and the nation.</p>
<h3>Our Vision</h3>
<p>To be an ethical and exemplary enterprise of the people, by the people, for the people — <em>Your Dream, Your Better</em>.</p>
<h3>Our Mission</h3>
<p>To help people get healthy and stay healthy without having to spend a fortune, and to give every family a genuine opportunity to build multiple sources of income.</p>
<h3>Why Choose Us</h3>
<ul>
<li>100% Ayurvedic formula products — natural, pure and effective</li>
<li>Legally registered company with complete statutory compliance</li>
<li>Proven, simple and powerful income plan — no level limit</li>
<li>Left-right carry forward business — your unmatched BV is never lost</li>
<li>Rewards from ₹3,000 up to ₹25 crore</li>
<li>Nominee facility for every distributor</li>
</ul>',
        ],
        [
            'title' => 'Opportunity', 'slug' => 'opportunity', 'menu_order' => 2, 'show_in_menu' => 1,
            'content' => '<h2>Better Earnings. Better Lifestyle.</h2>
<p>Time to take action! Becoming a distributor of Yashasavi Ayurveda is simple — and with our proven plan you can build <strong>multiple sources of income</strong>. See the complete plan with live rank and reward tables on our <a href="opportunity.php">Business Opportunity</a> page.</p>
<div class="steps-grid">
<div class="step-card"><h4>1. Register</h4><p>Register yourself as a distributor using a sponsor ID. Registration is free.</p></div>
<div class="step-card"><h4>2. Buy &amp; Sell</h4><p>Purchase products at the special distributor price (DP) and sell at MRP — keep 30% to 45% retail income.</p></div>
<div class="step-card"><h4>3. Build Your Team</h4><p>Grow your left and right teams — earn matching income on every 3000:3000 BV pair with carry forward.</p></div>
</div>',
        ],
        [
            'title' => 'Legals', 'slug' => 'legals', 'menu_order' => 3, 'show_in_menu' => 1,
            'content' => '<h2>Legals</h2>
<p>Yashasavi Veda Herbal Private Limited is a legally registered and compliant organization. All our operations are conducted with transparency, integrity and in full compliance with applicable laws and regulations. These documents reflect our commitment to quality, safety and ethical business practices.</p>',
        ],
        [
            'title' => 'Promotion', 'slug' => 'promotion', 'menu_order' => 4, 'show_in_menu' => 1,
            'content' => '<h2>Promotional Material</h2>
<p>Download our official brochure and business plan presentation to share with your prospects. Log in to your dashboard for the latest product catalogue, price list and promotional images.</p>',
        ],
        [
            'title' => 'Terms & Conditions', 'slug' => 'terms-and-conditions', 'menu_order' => 90, 'show_in_menu' => 0,
            'content' => '<h2>Terms &amp; Conditions</h2>
<p>By using this website and participating in the Yashasavi Ayurveda business opportunity, you agree to the following terms:</p>
<ol>
<li>Distributorship is open to individuals of 18 years of age and above.</li>
<li>The company may modify the marketing plan, product prices and BV values at any time.</li>
<li>Commissions are calculated only on approved purchases at the declared BV of each product. TDS (5%) and admin charges (7%) are deducted as applicable; without KYC confirmation, rewards and payouts are not released.</li>
<li>Cash rewards are awarded within 15 days of achieving the required points. The car fund amount is paid directly at the car showroom within 60 days of achieving car-fund status.</li>
<li>Distributors must not make any income claims or medical claims about the products.</li>
<li>Indulging in unethical practices may lead to termination of the distributorship.</li>
<li>All disputes are subject to the jurisdiction of Chandrapur, Maharashtra.</li>
</ol>',
        ],
        [
            'title' => 'Privacy Policy', 'slug' => 'privacy-policy', 'menu_order' => 91, 'show_in_menu' => 0,
            'content' => '<h2>Privacy Policy</h2>
<p>We respect your privacy. Personal information collected during registration and purchases (name, contact details, bank details, KYC documents) is used solely for operating your distributor account, paying commissions and statutory compliance. We never sell your data to third parties. You may request correction or deletion of your data by contacting support.</p>',
        ],
        [
            'title' => 'Refund & Return Policy', 'slug' => 'refund-policy', 'menu_order' => 92, 'show_in_menu' => 0,
            'content' => '<h2>Refund &amp; Return Policy</h2>
<p>Products may be returned within 7 days of delivery only if they are unused, undamaged and in original packaging. In case of a damaged or wrong product received, contact support within 48 hours of delivery with photographs for a free replacement. BV and commissions already credited on returned orders will be reversed. Purchases made towards rank achievement are not refundable once the reward cycle has been processed.</p>',
        ],
        [
            'title' => 'Disclaimer', 'slug' => 'disclaimer', 'menu_order' => 93, 'show_in_menu' => 0,
            'content' => '<h2>Disclaimer</h2>
<p>Yashasavi Ayurveda products are Ayurvedic / wellness formulations and are not intended to diagnose, treat, cure or prevent any disease. Results may vary from person to person. Consult your healthcare professional before use if you are pregnant, nursing, taking medication or have a medical condition. Income examples shown in the marketing plan are potential earnings only and depend on individual effort, team building and product sales — the company does not guarantee any income.</p>',
        ],
    ],
    ];
}
