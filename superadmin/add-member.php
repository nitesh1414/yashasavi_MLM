<?php
/**
 * Add Distributor — registration page INSIDE the super admin panel.
 * Opened from the network tree "add member" links (ref + leg prefilled),
 * or directly from the sidebar. The super admin may place the new member
 * under any active sponsor.
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/member_register.php';
$a = require_superadmin();

$opts = [
    'area'         => 'superadmin',
    'actor'        => $a,
    'success_url'  => 'tree.php',
    'back_url'     => 'tree.php',
    'lock_sponsor' => false,
];
[$errors, $f, $prefill] = member_register_handle($opts);

$activeKey = 'add-member';
$pageTitle = 'Add Distributor';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card">
    <div class="card-title">
        ➕ Register New Distributor
        <span class="right">Pick any active sponsor and leg — or leave the sponsor empty to place the member under the company root.</span>
    </div>
    <?php member_register_render_form($f, $errors, $prefill, $opts); ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
