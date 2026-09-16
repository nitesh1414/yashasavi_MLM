<?php
/** CMS admin dashboard */
require_once __DIR__ . '/../includes/init.php';
$a = require_admin();

$stats = [
    'pages'        => (int)q_val("SELECT COUNT(*) FROM pages"),
    'products'     => (int)q_val("SELECT COUNT(*) FROM products"),
    'categories'   => (int)q_val("SELECT COUNT(*) FROM categories"),
    'enquiries'    => (int)q_val("SELECT COUNT(*) FROM enquiries WHERE status='new'"),
    'testimonials' => (int)q_val("SELECT COUNT(*) FROM testimonials"),
    'sliders'      => (int)q_val("SELECT COUNT(*) FROM sliders WHERE status='active'"),
];
$recentEnq = q_all("SELECT * FROM enquiries ORDER BY id DESC LIMIT 6");

$activeKey = 'dashboard';
$pageTitle = 'Admin Dashboard';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="stat-grid">
    <div class="stat-card"><div class="st-ico">📄</div><div><b><?= $stats['pages'] ?></b><span>Pages</span></div></div>
    <div class="stat-card teal"><div class="st-ico">🧴</div><div><b><?= $stats['products'] ?></b><span>Products</span></div></div>
    <div class="stat-card blue"><div class="st-ico">🗂️</div><div><b><?= $stats['categories'] ?></b><span>Categories</span></div></div>
    <div class="stat-card gold"><div class="st-ico">✉️</div><div><b><?= $stats['enquiries'] ?></b><span>New Enquiries</span></div></div>
</div>

<div class="two-col">
    <div class="card">
        <div class="card-title">✉️ Latest Enquiries <a class="right" href="enquiries.php">View all →</a></div>
        <?php if (!$recentEnq): ?>
            <div class="empty-state"><span class="es-ico">✉️</span>No enquiries yet.</div>
        <?php else: ?>
        <div class="table-wrap" style="box-shadow:none">
            <table class="table">
                <tr><th>Name</th><th>Subject</th><th>Date</th><th>Status</th></tr>
                <?php foreach ($recentEnq as $en): ?>
                <tr>
                    <td><b><?= e($en['name']) ?></b><br><small style="color:#000"><?= e($en['email'] ?: $en['mobile']) ?></small></td>
                    <td><?= e($en['subject']) ?></td>
                    <td><?= dmy($en['created_at']) ?></td>
                    <td><?= status_badge($en['status']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="card">
            <div class="card-title">⚡ Quick Actions</div>
            <p>
                <a class="btn btn-primary btn-sm" href="page_edit.php">+ New Page</a>
                <a class="btn btn-primary btn-sm" href="product_edit.php">+ New Product</a>
                <a class="btn btn-outline btn-sm" href="sliders.php">Manage Slider</a>
                <a class="btn btn-outline btn-sm" href="settings.php">Site Settings</a>
            </p>
            <p style="margin-top:12px;font-size:13px;color:var(--ink-soft)">
                This panel manages the <b>public website content</b>. MLM operations (distributors,
                orders, commissions, payouts, plan settings) are handled in the
                <a href="<?= url('superadmin/login.php') ?>"><b>Super Admin panel</b></a>.
            </p>
        </div>
        <div class="card">
            <div class="card-title">🌐 Website Links</div>
            <p style="line-height:2.2">
                <a href="<?= url('index.php') ?>" target="_blank">🏠 View Website</a><br>
                <a href="<?= url('products.php') ?>" target="_blank">🧴 Products Page</a><br>
                <a href="<?= url('contact.php') ?>" target="_blank">✉️ Contact Page</a>
            </p>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
