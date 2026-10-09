<?php
/**
 * Upgrade an EXISTING installation to the current schema — without data loss.
 *
 * Safe to run any number of times: everything it does is either
 * "create if missing", "add column if missing" or "insert row if missing".
 * Distributors, teams, orders, commissions and wallets are never touched.
 *
 * Open it in the browser:  /install/upgrade.php
 */

/* ------------------------------------------------------------------ */
/*  Bootstrap                                                          */
/* ------------------------------------------------------------------ */
$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    die('<!DOCTYPE html><meta charset="utf-8"><body style="font-family:system-ui;padding:40px">'
        . '<h2>config.php not found</h2><p>Please run <a href="install.php">the installer</a> first.</p></body>');
}
require_once dirname(__DIR__) . '/includes/init.php';
require_once __DIR__ . '/seed.php';   /* defines seed_database() + install_schema() — no side effects */

$D = seed_data();
$pdo = db();

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

/** does a table exist? */
function ug_table_exists($name)
{
    return (bool)q_val(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$name]
    );
}

/** add a column if it is missing; returns true when the column was added now */
function ug_ensure_column($table, $column, $ddl)
{
    $exists = (bool)q_val(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $column]
    );
    if ($exists) {
        return false;
    }
    db()->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
    return true;
}

/** copy seed assets into uploads/ without overwriting anything */
function ug_copy_seed_assets()
{
    $src = __DIR__ . '/seed-assets';
    $dst = dirname(__DIR__) . '/uploads';
    $copied = 0;
    if (!is_dir($src)) {
        return 0;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $target = $dst . '/' . $it->getSubPathName();
        if ($item->isDir()) {
            @mkdir($target, 0755, true);
        } elseif (!is_file($target)) {
            @mkdir(dirname($target), 0755, true);
            if (@copy($item->getPathname(), $target)) {
                $copied++;
            }
        }
    }
    return $copied;
}

/* columns the current code expects on legacy installs (idempotent) */
function ug_column_fixes()
{
    /* [table, column, DDL] */
    return [
        ['plan_settings', 'monthly_cap',            "`monthly_cap` DECIMAL(12,2) NOT NULL DEFAULT 0"],
        ['plan_settings', 'sponsor_matching_percent', "`sponsor_matching_percent` DECIMAL(6,2) NOT NULL DEFAULT 0"],
        ['plan_settings', 'point_bv',               "`point_bv` DECIMAL(10,2) NOT NULL DEFAULT 400"],
        ['plan_settings', 'car_fund_points',        "`car_fund_points` INT UNSIGNED NOT NULL DEFAULT 500"],
        ['plan_settings', 'car_fund_amount',        "`car_fund_amount` DECIMAL(12,2) NOT NULL DEFAULT 150000"],
        ['commissions',   'bv',                     "`bv` DECIMAL(12,2) NOT NULL DEFAULT 0"],
        ['commissions',   'level',                  "`level` TINYINT UNSIGNED NULL"],
        ['users',         'self_bv',                "`self_bv` DECIMAL(12,2) NOT NULL DEFAULT 0"],
        ['users',         'must_change_password',   "`must_change_password` TINYINT(1) NOT NULL DEFAULT 0"],
        ['users',         'left_bv',                "`left_bv` DECIMAL(12,2) NOT NULL DEFAULT 0"],
        ['users',         'right_bv',               "`right_bv` DECIMAL(12,2) NOT NULL DEFAULT 0"],
        ['users',         'matched_pairs',          "`matched_pairs` INT UNSIGNED NOT NULL DEFAULT 0"],
        ['users',         'rank_id',                "`rank_id` INT UNSIGNED NULL"],
        ['users',         'wallet_balance',         "`wallet_balance` DECIMAL(12,2) NOT NULL DEFAULT 0"],
        ['products',      'mrp',                    "`mrp` DECIMAL(10,2) NOT NULL DEFAULT 0"],
        ['products',      'dp',                     "`dp` DECIMAL(10,2) NOT NULL DEFAULT 0"],
        ['products',      'bv',                     "`bv` DECIMAL(10,2) NOT NULL DEFAULT 0"],
        ['orders',        'total_mrp',              "`total_mrp` DECIMAL(12,2) NOT NULL DEFAULT 0"],
        ['orders',        'total_dp',               "`total_dp` DECIMAL(12,2) NOT NULL DEFAULT 0"],
        ['orders',        'total_bv',               "`total_bv` DECIMAL(12,2) NOT NULL DEFAULT 0"],
    ];
}

/* the full commission type list the engine uses */
function ug_commission_types_ddl()
{
    return "MODIFY `type` ENUM('sponsor','binary','level','rank','sponsor_matching','car_fund','award','retail','other') NOT NULL";
}

function ug_needs_upgrade($pdo)
{
    if (!ug_table_exists('users')) {
        return null; /* nothing installed at all */
    }
    foreach (ug_column_fixes() as $c) {
        if (!ug_table_exists($c[0])) {
            return true; /* table missing → CREATE IF NOT EXISTS will add it */
        }
        $exists = (bool)q_val(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$c[0], $c[1]]
        );
        if (!$exists) {
            return true;
        }
    }
    if (!ug_table_exists('award_rewards')) {
        return true;
    }
    /* enum outdated? */
    $type = q_val("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commissions' AND COLUMN_NAME = 'type'");
    if ($type && stripos($type, 'sponsor_matching') === false) {
        return true;
    }
    /* users.path must be TEXT — straight-line seeds create 250+ level
     * paths that no longer fit into the old VARCHAR(255) */
    $pathType = q_val("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'path'");
    if ($pathType && !in_array($pathType, ['text', 'mediumtext', 'longtext'], true)) {
        return true;
    }
    return false;
}

/* ------------------------------------------------------------------ */
/*  RUN UPGRADE                                                        */
/* ------------------------------------------------------------------ */
$log = [];
$done = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $refreshContent = isset($_POST['refresh_content']);
    $replaceCatalog = isset($_POST['replace_catalog']);
    try {
        /* 1 — create any missing table (award_rewards, documents, …) */
        $createdTables = 0;
        foreach (install_schema() as $stmt) {
            $m = [];
            if (preg_match('/CREATE TABLE IF NOT EXISTS `?([a-z_]+)`?/i', $stmt, $m)) {
                if (!ug_table_exists($m[1])) {
                    $pdo->exec($stmt);
                    $createdTables++;
                    $log[] = 'Created missing table: <code>' . $m[1] . '</code>';
                }
            } else {
                $pdo->exec($stmt); /* CREATE TABLE IF NOT EXISTS is idempotent anyway */
            }
        }
        if (!$createdTables) {
            $log[] = 'All tables already present.';
        }

        /* 2 — add missing columns */
        $addedCols = [];
        foreach (ug_column_fixes() as $c) {
            if (ug_table_exists($c[0]) && ug_ensure_column($c[0], $c[1], $c[2])) {
                $addedCols[] = $c[0] . '.' . $c[1];
            }
        }
        $log[] = $addedCols
            ? 'Added columns: <code>' . implode('</code>, <code>', $addedCols) . '</code>'
            : 'All required columns already present.';

        /* 3 — widen the commission type enum */
        $pdo->exec('ALTER TABLE commissions ' . ug_commission_types_ddl());
        $log[] = 'Commission income types extended (sponsor matching, car fund, award, retail).';

        /* 3b — allow the cash payment mode on orders */
        if (ug_table_exists('orders')) {
            $pm = q_val("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_mode'");
            if ($pm && stripos($pm, 'cash') === false) {
                $pdo->exec("ALTER TABLE orders MODIFY `payment_mode` ENUM('wallet','bank_transfer','online','cash') NOT NULL DEFAULT 'bank_transfer'");
                $log[] = 'Orders: cash payment mode enabled.';
            }
        }

        /* 3c — email/mobile may be shared (family members) — unique -> normal index */
        if (ug_table_exists('users')) {
            $idx = q_all("SHOW INDEX FROM users");
            $byName = [];
            foreach ($idx as $ix) {
                $byName[$ix['Key_name']] = $ix;
            }
            foreach (['email', 'mobile'] as $col) {
                if (isset($byName[$col]) && (int)$byName[$col]['Non_unique'] === 0) {
                    $pdo->exec("ALTER TABLE users DROP INDEX `$col`");
                    $pdo->exec("ALTER TABLE users ADD INDEX `idx_$col` (`$col`)");
                    $log[] = "Users: $col is no longer unique (family members may share contact details).";
                }
            }
        }

        /* 3d — users.path must be TEXT: straight-line seeds (bulk members)
         * create 250+ level ancestry paths longer than the old VARCHAR(255);
         * a too-short column would silently truncate and corrupt the tree */
        if (ug_table_exists('users')) {
            $pathType = q_val("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'path'");
            if ($pathType && !in_array($pathType, ['text', 'mediumtext', 'longtext'], true)) {
                $pdo->exec("ALTER TABLE users MODIFY `path` TEXT NOT NULL");
                $log[] = 'Users: ancestry <code>path</code> widened to TEXT (supports deep straight-line seeds).';
            }
        }

        /* 4 — MLM plan settings */
        $plan = $D['plan'];
        if (!(int)q_val("SELECT COUNT(*) FROM plan_settings WHERE id = 1")) {
            q("INSERT INTO plan_settings (id, activation_bv, sponsor_percent, pair_unit_bv, binary_type,
               binary_value, level_depth, daily_cap, monthly_cap, carry_forward, matching_requires_active,
               level_requires_active, sponsor_matching_percent, point_bv, car_fund_points, car_fund_amount,
               tds_percent, admin_charge_percent, payout_min, updated_at)
               VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
              [$plan['activation_bv'], $plan['sponsor_percent'], $plan['pair_unit_bv'], $plan['binary_type'],
               $plan['binary_value'], $plan['level_depth'], $plan['daily_cap'], $plan['monthly_cap'],
               $plan['carry_forward'], $plan['matching_requires_active'], $plan['level_requires_active'],
               $plan['sponsor_matching_percent'], $plan['point_bv'], $plan['car_fund_points'],
               $plan['car_fund_amount'], $plan['tds_percent'], $plan['admin_charge_percent'], $plan['payout_min']]);
            $log[] = 'Created MLM plan settings with the official plan values.';
        } else {
            /* bring newly added / still-default columns to the official values */
            $pdfCols = [
                'monthly_cap' => [$plan['monthly_cap'], '0'],
                'sponsor_matching_percent' => [$plan['sponsor_matching_percent'], '0'],
                'point_bv' => [$plan['point_bv'], '400'],
                'car_fund_points' => [$plan['car_fund_points'], '500'],
                'car_fund_amount' => [$plan['car_fund_amount'], '150000'],
                /* the pre-upgrade schema default — sync it to the official ₹3,20,000 */
                'daily_cap' => [$plan['daily_cap'], '5000'],
            ];
            $updated = [];
            foreach ($pdfCols as $col => [$want, $default]) {
                $cur = q_val("SELECT `$col` FROM plan_settings WHERE id = 1");
                if ($cur !== null && (float)$cur === (float)$default && (float)$want !== (float)$default) {
                    q("UPDATE plan_settings SET `$col` = ? WHERE id = 1", [$want]);
                    $updated[] = $col . ' = ' . $want;
                }
            }
            $log[] = $updated
                ? 'Plan settings updated: <code>' . implode('</code>, <code>', $updated) . '</code>'
                : 'Plan settings already configured.';
        }

        /* plan levels */
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10] as $ln) {
            $pdo->exec("INSERT IGNORE INTO plan_levels (level_no, percent) VALUES ($ln, 0)");
        }

        /* 5 — ranks (insert missing, sync official thresholds by name) */
        $rankInserts = 0; $rankUpdates = 0;
        foreach ($D['ranks'] as $r) {
            $ex = q_row("SELECT * FROM ranks WHERE name = ?", [$r[0]]);
            if (!$ex) {
                q("INSERT INTO ranks (name, min_team_bv, min_directs, reward_amount, badge, sort_order)
                   VALUES (?, ?, ?, ?, ?, ?)",
                  [$r[0], $r[1] * 400, $r[2], $r[3], strtolower(str_replace(' ', '-', $r[0])), $r[4]]);
                $rankInserts++;
            } elseif ((float)$ex['min_team_bv'] !== (float)($r[1] * 400) || (float)$ex['reward_amount'] !== (float)$r[3]) {
                q("UPDATE ranks SET min_team_bv = ?, min_directs = ?, reward_amount = ?, badge = ?, sort_order = ? WHERE id = ?",
                  [$r[1] * 400, $r[2], $r[3], strtolower(str_replace(' ', '-', $r[0])), $r[4], $ex['id']]);
                $rankUpdates++;
            }
        }
        $log[] = "Ranks: $rankInserts added, $rankUpdates synced to the official plan (1P = 400 BV).";

        /* 6 — award rewards (insert by title if missing) */
        $awardInserts = 0;
        $s = 1;
        foreach ($D['awards'] as $a) {
            if (!(int)q_val("SELECT COUNT(*) FROM award_rewards WHERE reward_title = ?", [$a[1]])) {
                q("INSERT INTO award_rewards (points, reward_title, reward_type, amount, description, status, sort_order, created_at)
                   VALUES (?, ?, ?, ?, ?, 'active', ?, NOW())", [$a[0], $a[1], $a[2], $a[3], $a[4], $s]);
                $awardInserts++;
            }
            $s++;
        }
        $log[] = $awardInserts ? "Award rewards: $awardInserts added (Dinner Set, Mixer Grinder, cash fund…)." : 'Award rewards already present.';

        /* 7 — site settings */
        $setInserts = 0; $setUpdates = 0;
        foreach ($D['settings'] as $k => $v) {
            $cur = q_val("SELECT svalue FROM settings WHERE skey = ?", [$k]);
            if ($cur === null || $cur === false) {
                save_setting($k, $v);
                $setInserts++;
            } elseif ($refreshContent && $cur !== $v) {
                save_setting($k, $v);
                $setUpdates++;
            }
        }
        $log[] = "Site settings: $setInserts added" . ($refreshContent ? ", $setUpdates refreshed with current branding" : '') . '.';

        /* 8 — categories */
        $catIds = [];
        foreach (q_all("SELECT id, slug FROM categories") as $c) {
            $catIds[$c['slug']] = $c['id'];
        }
        $catInserts = 0;
        foreach ($D['cats'] as $c) {
            if (!isset($catIds[$c[1]])) {
                q("INSERT INTO categories (name, slug, description, image, sort_order, status, created_at)
                   VALUES (?, ?, ?, ?, ?, 'active', NOW())", [$c[0], $c[1], $c[2], 'categories/' . $c[3], $c[4]]);
                $catIds[$c[1]] = (int)$pdo->lastInsertId();
                $catInserts++;
            }
        }
        if ($catInserts) {
            $log[] = "Categories: $catInserts added.";
        }

        /* 9 — products */
        if ($replaceCatalog) {
            $pdfSlugs = array_map(fn($p) => $p[1], $D['products']);
            $in = "'" . implode("','", $pdfSlugs) . "'";
            $deactivated = (int)q_val("SELECT COUNT(*) FROM products WHERE status = 'active' AND slug NOT IN ($in)");
            if ($deactivated) {
                q("UPDATE products SET status = 'inactive' WHERE status = 'active' AND slug NOT IN ($in)");
                $log[] = "Product catalogue: $deactivated old product(s) deactivated (rows and past orders are kept).";
            }
        }
        $prodInserts = 0;
        $sortBase = (int)q_val("SELECT COALESCE(MAX(sort_order), 0) FROM products");
        foreach ($D['products'] as $p) {
            if (!(int)q_val("SELECT COUNT(*) FROM products WHERE slug = ?", [$p[1]])) {
                $sortBase++;
                q("INSERT INTO products (category_id, name, slug, size, mrp, dp, bv, short_desc, description,
                   benefits, ingredients, how_to_use, image, stock, is_featured, status, sort_order, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 500, ?, 'active', ?, NOW())",
                  [$catIds[$p[3]], $p[0], $p[1], $p[2], $p[4], $p[5], $p[6], $p[8],
                   '<p>' . $p[8] . '</p><p>A 100% natural, quality-tested Yashasavi Veda Herbals formulation — pure Ayurveda for a healthy life and wellness for a better tomorrow.</p>',
                   $p[9],
                   'Natural Ayurvedic herbs and extracts.',
                   'Use as directed on the product label or as advised by your physician.',
                   'products/' . $p[1] . '.jpg', $p[7], $sortBase]);
                $prodInserts++;
            }
        }
        $log[] = $prodInserts
            ? "Products: $prodInserts added from the official 16-product catalogue (MRP / DP / BV)."
            : 'Products: official catalogue already present.';

        /* 10 — legal documents */
        $docInserts = 0;
        foreach ($D['legalDocs'] as $d) {
            if (!(int)q_val("SELECT COUNT(*) FROM documents WHERE title = ?", [$d[0]])) {
                q("INSERT INTO documents (title, type, image, description, sort_order, status) VALUES
                   (?, 'legal', ?, ?, ?, 'active')", [$d[0], $d[1], $d[2], $d[3]]);
                $docInserts++;
            }
        }
        if ($docInserts) {
            $log[] = "Legal documents: $docInserts added.";
        }

        /* 11 — CMS pages */
        $pageInserts = 0; $pageUpdates = 0;
        foreach ($D['pages'] as $p) {
            $ex = q_val("SELECT content FROM pages WHERE slug = ?", [$p['slug']]);
            if ($ex === null || $ex === false) {
                q("INSERT INTO pages (title, slug, content, meta_title, meta_description, show_in_menu, menu_order, status, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, 'published', NOW(), NOW())",
                  [$p['title'], $p['slug'], $p['content'], $p['title'] . ' — Yashasavi Veda Herbals Private Limited',
                   substr(strip_tags($p['content']), 0, 200), $p['show_in_menu'], $p['menu_order']]);
                $pageInserts++;
            } elseif ($refreshContent && $ex !== $p['content']) {
                q("UPDATE pages SET title = ?, content = ?, meta_title = ?, meta_description = ?, show_in_menu = ?, menu_order = ?, updated_at = NOW() WHERE slug = ?",
                  [$p['title'], $p['content'], $p['title'] . ' — Yashasavi Veda Herbals Private Limited',
                   substr(strip_tags($p['content']), 0, 200), $p['show_in_menu'], $p['menu_order'], $p['slug']]);
                $pageUpdates++;
            }
        }
        $log[] = "CMS pages: $pageInserts added" . ($refreshContent ? ", $pageUpdates refreshed with current content" : '') . '.';

        /* 12 — sliders / testimonials / announcement (only when empty) */
        if (!(int)q_val("SELECT COUNT(*) FROM sliders")) {
            $s = 1;
            foreach ($D['sliders'] as $sl) {
                q("INSERT INTO sliders (title, subtitle, description, image, btn_text, btn_link, sort_order, status)
                   VALUES (?, ?, ?, ?, ?, ?, ?, 'active')", [$sl[0], $sl[1], $sl[2], $sl[3], $sl[4], $sl[5], $s++]);
            }
            $log[] = 'Home page sliders added.';
        }
        if (!(int)q_val("SELECT COUNT(*) FROM testimonials")) {
            foreach ($D['testimonials'] as $t) {
                q("INSERT INTO testimonials (name, designation, photo, content, rating, sort_order, status)
                   VALUES (?, ?, NULL, ?, ?, ?, 'active')", [$t[0], $t[1], $t[2], $t[3], $t[4]]);
            }
            $log[] = 'Testimonials added.';
        }
        if (!(int)q_val("SELECT COUNT(*) FROM announcements")) {
            q("INSERT INTO announcements (title, content, status, created_by, created_at) VALUES
               (?, ?, 'active', 1, NOW())",
              ['Welcome to Yashasavi Veda Herbals Private Limited — Your Dream, Your Better!',
               'Dear Distributors, welcome to the new Yashasavi Veda Herbals Private Limited portal. Complete your profile and KYC details to receive fast payouts and become eligible for rewards. For any help, contact customer care: 9529512562.']);
            $log[] = 'Welcome announcement added.';
        }

        /* 13 — bundled images (only files that are missing) */
        $copied = ug_copy_seed_assets();
        $log[] = $copied ? "Media: $copied bundled image(s) copied into uploads/." : 'Media: uploads/ already complete.';

        $done = true;
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

/* ------------------------------------------------------------------ */
/*  Status check for the overview                                      */
/* ------------------------------------------------------------------ */
$installed = ug_table_exists('users');
$needsUpgrade = $installed ? ug_needs_upgrade($pdo) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Upgrade — Yashasavi MLM</title>
<style>
:root{--green:#2e7d32;--dark:#1b3a1f;--gold:#c99a2e;--bg:#f5f7f4;--danger:#c62828}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:#000;padding:40px 16px;line-height:1.6}
.wrap{max-width:720px;margin:0 auto}
.card{background:#fff;border-radius:14px;box-shadow:0 8px 30px rgba(27,58,31,.08);padding:32px;margin-bottom:20px}
h1{font-size:26px;margin-bottom:6px}
h2{font-size:18px;margin:18px 0 10px}
p.lead{margin-bottom:18px}
button{margin-top:20px;background:var(--green);color:#fff;border:0;padding:12px 26px;border-radius:8px;font-size:15px;cursor:pointer}
button:hover{background:var(--dark)}
ul.log{list-style:none;margin-top:8px}
ul.log li{padding:8px 10px;border-bottom:1px solid #edf1ed;font-size:14px}
.ok{color:#2e7d32;font-weight:600}
.warn{color:#a67c00;font-weight:600}
.err{background:#fdecec;border:1px solid #f5c6c6;padding:12px 16px;border-radius:8px;margin-bottom:16px}
label.chk{display:flex;gap:10px;align-items:flex-start;margin:12px 0;padding:12px 14px;background:#f0f6ef;border:1px solid #cfe0cf;border-radius:10px;font-size:14px;cursor:pointer}
.note{font-size:13px;margin-top:10px}
code{background:#f0f6ef;border:1px solid #dbe5db;padding:1px 6px;border-radius:6px;font-size:12.5px}
.creds{background:#f0f6ef;border:1px solid #cfe0cf;border-radius:10px;padding:16px 20px;margin-top:14px}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>⬆️ Yashasavi MLM — Upgrade</h1>
        <p class="lead">Brings an <strong>existing installation</strong> up to the current database schema and
        reference content. Your distributors, teams, orders, commissions and wallets are <strong>never touched</strong>.
        It is safe to run more than once.</p>

        <?php if (!$installed): ?>
            <div class="creds" style="background:#fff8e6;border-color:#efd9a0">
                <strong>No installation found.</strong> Please run <a href="install.php">the installer</a> first.
            </div>
        <?php elseif ($done && !$error): ?>
            <h2 class="ok">✅ Upgrade complete!</h2>
            <ul class="log">
                <?php foreach ($log as $l): ?><li>✔️ <?= $l ?></li><?php endforeach; ?>
            </ul>
            <div class="creds">
                <p>🌐 <strong>Website</strong> — <a href="../index.php">open site</a></p>
                <p>👤 <strong>Distributor panel</strong> — <a href="../user/plan.php">the page that was broken</a></p>
                <p>🛡️ <strong>Super admin</strong> — <a href="../superadmin/login.php">log in</a></p>
            </div>
            <p class="note">You can delete <code>install/upgrade.php</code> from the server when done.</p>
        <?php elseif ($error): ?>
            <div class="err"><strong>Upgrade failed:</strong> <?= htmlspecialchars($error) ?></div>
            <ul class="log"><?php foreach ($log as $l): ?><li>✔️ <?= $l ?></li><?php endforeach; ?></ul>
            <p><a href="upgrade.php">&larr; Try again</a></p>
        <?php else: ?>
            <?php if (!$needsUpgrade): ?>
                <div class="creds" style="background:#eef7ef;border-color:#cfe0cf">
                    <strong class="ok">✓ Database is already up to date.</strong>
                    <p class="note">You can still run the upgrade to re-sync missing reference content.</p>
                </div>
            <?php else: ?>
                <div class="creds" style="background:#fff8e6;border-color:#efd9a0">
                    <strong class="warn">⚠ This installation uses an older database schema.</strong>
                    <p class="note">New income types (sponsor matching, car fund, award rewards, retail),
                    points system, caps and the official product catalogue need the current schema —
                    that is why some pages currently show an error.</p>
                </div>
            <?php endif; ?>

            <form method="post">
                <label class="chk">
                    <input type="checkbox" name="refresh_content" checked>
                    <span><strong>Refresh website content</strong> — update CMS pages and branding (company name,
                    tagline, contact details) to the current Yashasavi Veda Herbals Private Limited content. Uncheck to keep your edited pages.</span>
                </label>
                <label class="chk">
                    <input type="checkbox" name="replace_catalog" checked>
                    <span><strong>Use the official 16-product catalogue</strong> — old products are deactivated
                    (kept in the database, past orders unaffected) and the official MRP / DP / BV products are added.</span>
                </label>
                <button type="submit">Run Upgrade</button>
            </form>
            <p class="note">Backups: <code>mysqldump <?= htmlspecialchars(DB_NAME) ?></code> before upgrading is always a good idea.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
