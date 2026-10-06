<?php
/** Manual order entry — the super admin places a cash order on behalf of any
 *  distributor, exactly like the distributor ordering from their own panel.
 *  The cash payment is marked as ALREADY RECEIVED (payment_status = paid), so
 *  the order goes straight to the normal approval step and generates
 *  commissions when approved. */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

/* distributor picker (search) */
$q = get_str('q');
$distributors = [];
$picked = null;
if ($q !== '') {
    $like = '%' . $q . '%';
    $distributors = q_all("SELECT id, username, full_name, mobile, city, state, address, pincode
                           FROM users WHERE status = 'active'
                             AND (username LIKE ? OR full_name LIKE ? OR mobile LIKE ? OR email LIKE ?)
                           ORDER BY id LIMIT 25", [$like, $like, $like, $like]);
} elseif (get_int('uid') > 0) {
    $picked = q_row("SELECT * FROM users WHERE id = ?", [get_int('uid')]);
}

$products = q_all("SELECT * FROM products WHERE status = 'active' ORDER BY sort_order, id");

if (is_post()) {
    verify_csrf();
    $uid = (int)post_str('user_id');
    $buyer = q_row("SELECT * FROM users WHERE id = ? AND status = 'active'", [$uid]);
    if (!$buyer) {
        flash('error', 'Please pick a valid distributor.');
        redirect('order_add.php');
    }

    /* collect quantities */
    $items = []; $totalDp = 0.0; $totalMrp = 0.0; $totalBv = 0.0;
    foreach (($_POST['qty'] ?? []) as $pid => $qty) {
        $pid = (int)$pid; $qty = (int)$qty;
        if ($qty <= 0) { continue; }
        $p = q_row("SELECT * FROM products WHERE id = ? AND status = 'active'", [$pid]);
        if (!$p) { continue; }
        if ((int)$p['stock'] < $qty) {
            flash('error', e($p['name']) . ' has only ' . (int)$p['stock'] . ' unit(s) in stock.');
            redirect('order_add.php?uid=' . $uid);
        }
        $items[] = ['p' => $p, 'qty' => $qty];
        $totalDp += (float)$p['dp'] * $qty;
        $totalMrp += (float)$p['mrp'] * $qty;
        $totalBv += (float)$p['bv'] * $qty;
    }
    if (!$items) {
        flash('error', 'Add at least one product to the order.');
        redirect('order_add.php?uid=' . $uid);
    }

    $shipName = post_str('ship_name') ?: $buyer['full_name'];
    $shipMobile = post_str('ship_mobile') ?: $buyer['mobile'];
    $shipAddress = post_str('ship_address') ?: $buyer['address'];
    $shipCity = post_str('ship_city') ?: $buyer['city'];
    $shipState = post_str('ship_state') ?: $buyer['state'];
    $shipPin = post_str('ship_pincode') ?: $buyer['pincode'];

    /* same order creation as the distributor checkout — cash, already received */
    $orderNo = '';
    db_tx(function () use (&$orderNo, $buyer, $items, $totalDp, $totalMrp, $totalBv, $shipName, $shipMobile, $shipAddress, $shipCity, $shipState, $shipPin) {
        $orderNo = order_no();
        q("INSERT INTO orders (order_no, user_id, total_mrp, total_dp, total_bv, payment_mode, payment_status,
           txn_ref, status, ship_name, ship_mobile, ship_address, ship_city, ship_state, ship_pincode, created_at)
           VALUES (?, ?, ?, ?, ?, 'cash', 'paid', 'cash received by company', 'pending', ?, ?, ?, ?, ?, ?, NOW())",
          [$orderNo, $buyer['id'], $totalMrp, $totalDp, $totalBv,
           $shipName, $shipMobile, $shipAddress, $shipCity, $shipState, $shipPin]);
        $oid = (int)db()->lastInsertId();
        foreach ($items as $it) {
            q("INSERT INTO order_items (order_id, product_id, product_name, price, mrp, bv, qty, total, total_bv)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
              [$oid, $it['p']['id'], $it['p']['name'], $it['p']['dp'], $it['p']['mrp'], $it['p']['bv'],
               $it['qty'], (float)$it['p']['dp'] * $it['qty'], (float)$it['p']['bv'] * $it['qty']]);
        }
        return $oid;
    });

    $oid = (int)q_val("SELECT id FROM orders WHERE order_no = ?", [$orderNo]);
    flash('success', 'Order ' . $orderNo . ' created for ' . e($buyer['username']) . ' — cash payment marked as received. Approve it to credit the BV and commissions.');
    redirect('order_view.php?id=' . $oid);
}

$activeKey = 'orders';
$pageTitle = 'Add Order (Manual)';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card" style="max-width:1080px">
    <div class="card-title">🧾 Manual Order — Cash (already received) <span class="right"><a class="btn btn-light btn-sm" href="orders.php">← All Orders</a></span></div>

    <?php if (!$picked): ?>
    <p style="font-size:13.5px;color:var(--ink-soft)">
        Search a distributor by ID, name, mobile or email, then build their order — exactly like they
        would from their own panel. The cash payment is pre-authorized, so the order only needs your approval.
    </p>
    <form method="get" class="filter-form">
        <div class="form-group" style="flex:1;min-width:240px">
            <label>Distributor search</label>
            <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="YSH100005, name, mobile or email" autofocus>
        </div>
        <button class="btn btn-primary" type="submit">🔍 Search</button>
    </form>

    <?php if ($distributors): ?>
    <div class="table-wrap" style="box-shadow:none;margin-top:10px">
        <table class="table">
            <thead><tr><th>ID</th><th>Name</th><th>Mobile</th><th>City</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($distributors as $d): ?>
                <tr>
                    <td><b><?= e($d['username']) ?></b></td>
                    <td><?= e($d['full_name']) ?></td>
                    <td><?= e($d['mobile']) ?></td>
                    <td><?= e($d['city']) ?></td>
                    <td><a class="btn btn-primary btn-sm" href="order_add.php?uid=<?= (int)$d['id'] ?>">Select →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php elseif ($q !== ''): ?>
        <div class="empty-state"><span class="es-ico">🔍</span>No distributor matches "<?= e($q) ?>".</div>
    <?php endif; ?>

    <?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="user_id" value="<?= (int)$picked['id'] ?>">

        <div class="kv-table" style="margin-bottom:16px">
            <table>
                <tr><td>Distributor</td><td><b><?= e($picked['username']) ?></b> — <?= e($picked['full_name']) ?></td></tr>
                <tr><td>Mobile</td><td><?= e($picked['mobile']) ?></td></tr>
                <tr><td>Payment</td><td><b>CASH — already received by the company</b> (order will be ready to approve)</td></tr>
            </table>
        </div>

        <div class="card-title" style="font-size:14px">🛍️ Products</div>
        <div class="table-wrap" style="box-shadow:none">
            <table class="table">
                <thead><tr><th>Product</th><th>DP</th><th>MRP</th><th>BV</th><th>Stock</th><th style="width:110px">Qty</th></tr></thead>
                <tbody>
                <?php foreach ($products as $p): ?>
                    <tr>
                        <td><?= e($p['name']) ?> <small style="color:var(--ink-soft)">(<?= e($p['size']) ?>)</small></td>
                        <td><?= money($p['dp']) ?></td>
                        <td><?= money($p['mrp']) ?></td>
                        <td><?= e(number_format((float)$p['bv'], 0)) ?></td>
                        <td><?= (int)$p['stock'] ?></td>
                        <td><input class="form-control qty-input" type="number" name="qty[<?= (int)$p['id'] ?>]" value="0" min="0" max="99" data-dp="<?= (float)$p['dp'] ?>" data-bv="<?= (float)$p['bv'] ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="filter-form" style="margin-top:16px;grid-template-columns:repeat(2,1fr)">
            <div class="form-group"><label>Ship to — name</label><input class="form-control" name="ship_name" value="<?= e($picked['full_name']) ?>"></div>
            <div class="form-group"><label>Ship to — mobile</label><input class="form-control" name="ship_mobile" value="<?= e($picked['mobile']) ?>"></div>
            <div class="form-group" style="grid-column:1/-1"><label>Address</label><input class="form-control" name="ship_address" value="<?= e($picked['address']) ?>"></div>
            <div class="form-group"><label>City</label><input class="form-control" name="ship_city" value="<?= e($picked['city']) ?>"></div>
            <div class="form-group"><label>State</label><input class="form-control" name="ship_state" value="<?= e($picked['state']) ?>"></div>
            <div class="form-group"><label>Pin code</label><input class="form-control" name="ship_pincode" value="<?= e($picked['pincode']) ?>"></div>
        </div>

        <button class="btn btn-primary" type="submit" data-confirm="Create this cash order for <?= e($picked['username']) ?>?">🧾 Create Order</button>
        <a class="btn btn-light" href="order_add.php">← Change distributor</a>
    </form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
