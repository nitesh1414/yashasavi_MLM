<?php
/**
 * Add Member — registration page INSIDE the user dashboard panel.
 * Opened from the genealogy tree "add member" links (ref + leg prefilled),
 * or directly from the sidebar. The new member is always placed inside the
 * logged-in distributor's own network.
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/member_register.php';
$u = require_user();

$opts = [
    'area'         => 'user',
    'actor'        => $u,
    'success_url'  => 'tree.php',
    'back_url'     => 'tree.php',
    'lock_sponsor' => true,
];
[$errors, $f, $prefill] = member_register_handle($opts);

$activeKey = 'add-member';
$pageTitle = 'Add Member';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card">
    <div class="card-title">
        ➕ Add New Member
        <span class="right">New members are placed inside your own network — opened from a tree position, sponsor &amp; leg come preselected.</span>
    </div>
    <?php member_register_render_form($f, $errors, $prefill, $opts); ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
