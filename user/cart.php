<?php
/** Cart + checkout */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

$cart = $_SESSION['cart'] ?? [];

if (is_post()) {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'update') {
        foreach (($_POST['qty'] ?? []) as $pid => $qty) {
            $pid = (int)$pid;
            $qty = (int)$qty;
            if ($qty <= 0) {
                unset($cart[$pid]);
            } else {
                $stock = (int)q_val("SELECT stock FROM products WHERE id = ? AND status='active'", [$pid]);
                $cart[$pid] = max(1, min(99, min($qty, $stock)));
            }
        }
        $_SESSION['cart'] = $cart;
        flash('success', 'Cart updated.');
        redirect('cart.php');
    }

    if ($action === 'remove') {
        $pid = (int)post_str('remove_pid');
        unset($cart[$pid]);
        $_SESSION['cart'] = $cart;
        flash('success', 'Item removed from cart.');
        redirect('cart.php');
    }

    if ($action === 'checkout') {
        if (!$cart) {
            flash('error', 'Your cart is empty.');
            redirect('shop.php');
        }
        $paymentMode = post_str('payment_mode');
        if (!in_array($paymentMode, ['wallet', 'bank_transfer'], true)) {
            $paymentMode = 'bank_transfer';
        }
        // shipping details
        $shipName = post_str('ship_name') ?: $u['full_name'];
        $shipMobile = post_str('ship_mobile') ?: $u['mobile'];
        $shipAddress = post_str('ship_address') ?: $u['address'];
        $shipCity = post_str('ship_city') ?: $u['city'];
        $shipState = post_str('ship_state') ?: $u['state'];
        $shipPin = post_str('ship_pincode') ?: $u['pincode'];
        $txnRef = post_str('txn_ref');

        $errors = [];
        if (strlen((string)$shipName) < 3) { $errors[] = 'Shipping name required.'; }
        if (strlen((string)$shipAddress) < 5) { $errors[] = 'Shipping address required.'; }
        if ($paymentMode === 'bank_transfer' && $txnRef === '') { $errors[] = 'Please enter your payment / UPI reference number.'; }

        // validate cart against stock and compute totals
        $items = [];
        $totalDp = 0.0; $totalMrp = 0.0; $totalBv = 0.0;
        foreach ($cart as $pid => $qty) {
            $p = q_row("SELECT * FROM products WHERE id = ? AND status='active'", [$pid]);
            if (!$p) { $errors[] = 'A product in your cart is no longer available.'; continue; }
            if ($p['stock'] < $qty) { $errors[] = e($p['name']) . ' has only ' . (int)$p['stock'] . ' unit(s) in stock.'; continue; }
            $items[] = ['p' => $p, 'qty' => $qty];
            $totalDp += (float)$p['dp'] * $qty;
            $totalMrp += (float)$p['mrp'] * $qty;
            $totalBv += (float)$p['bv'] * $qty;
        }
        if (!$items) { $errors[] = 'Your cart is empty.'; }

        if ($paymentMode === 'wallet' && (float)$u['wallet_balance'] < $totalDp) {
            $errors[] = 'Insufficient wallet balance (' . money($u['wallet_balance']) . '). Choose bank transfer or add funds.';
        }

        if ($errors) {
            foreach ($errors as $er) { flash('error', $er); }
            redirect('cart.php');
        }

        // create order
        db_tx(function () use (&$orderNo, $items, $totalDp, $totalMrp, $totalBv, $paymentMode, $txnRef, $u, $shipName, $shipMobile, $shipAddress, $shipCity, $shipState, $shipPin) {
            $orderNo = order_no();
            q("INSERT INTO orders (order_no, user_id, total_mrp, total_dp, total_bv, payment_mode, payment_status,
               txn_ref, status, ship_name, ship_mobile, ship_address, ship_city, ship_state, ship_pincode, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)",
              [$orderNo, $u['id'], $totalMrp, $totalDp, $totalBv, $paymentMode,
               $paymentMode === 'wallet' ? 'paid' : 'pending', $txnRef ?: null,
               $shipName, $shipMobile, $shipAddress, $shipCity, $shipState, $shipPin, now()]);
            $oid = (int)db()->lastInsertId();
            foreach ($items as $it) {
                q("INSERT INTO order_items (order_id, product_id, product_name, price, mrp, bv, qty, total, total_bv)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                  [$oid, $it['p']['id'], $it['p']['name'], $it['p']['dp'], $it['p']['mrp'], $it['p']['bv'],
                   $it['qty'], (float)$it['p']['dp'] * $it['qty'], (float)$it['p']['bv'] * $it['qty']]);
            }
            if ($paymentMode === 'wallet') {
                debit_wallet($u['id'], $totalDp, 'purchase', $oid, 'Purchase payment for order ' . $orderNo);
            }
            return $oid;
        });

        $_SESSION['cart'] = [];
        flash('success', 'Order ' . $orderNo . ' placed successfully! It will be processed after verification by the company.');
        redirect('orders.php');
    }
}

// build cart view
$items = [];
$totalDp = 0.0; $totalMrp = 0.0; $totalBv = 0.0;
foreach ($cart as $pid => $qty) {
    $p = q_row("SELECT * FROM products WHERE id = ? AND status='active'", [$pid]);
    if ($p) {
        $items[] = ['p' => $p, 'qty' => $qty];
        $totalDp += (float)$p['dp'] * $qty;
        $totalMrp += (float)$p['mrp'] * $qty;
        $totalBv += (float)$p['bv'] * $qty;
    }
}
$plan = get_plan();

$activeKey = 'shop';
$pageTitle = 'Cart & Checkout';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<?php if (!$items): ?>
<div class="card">
    <div class="empty-state">
        <span class="es-ico">🛒</span>
        <p>Your cart is empty.</p>
        <a class="btn btn-primary mt-2" href="shop.php">Continue Shopping</a>
    </div>
</div>
<?php else: ?>
<div class="cart-grid">
    <div class="card">
        <div class="card-title">🛍️ Your Cart (<?= count($items) ?> item<?= count($items) > 1 ? 's' : '' ?>)</div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <div class="table-wrap" style="box-shadow:none">
                <table class="table">
                    <tr><th>Product</th><th>DP</th><th>BV</th><th>Qty</th><th>Total</th><th></th></tr>
                    <?php foreach ($items as $it): ?>
                    <tr>
                        <td>
                            <b><?= e($it['p']['name']) ?></b><br>
                            <small style="color:#8d9c8d"><?= e($it['p']['size']) ?></small>
                        </td>
                        <td><?= money($it['p']['dp']) ?></td>
                        <td><?= e($it['p']['bv']) ?></td>
                        <td><input class="form-control qty-input" type="number" name="qty[<?= (int)$it['p']['id'] ?>]" value="<?= (int)$it['qty'] ?>" min="0" max="99"></td>
                        <td><b><?= money((float)$it['p']['dp'] * $it['qty']) ?></b></td>
                        <td>
                            <button class="btn btn-light btn-sm" type="submit" name="action" value="remove" formaction="cart.php"
                                    onclick="this.form.remove_pid.value=<?= (int)$it['p']['id'] ?>">✕</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <input type="hidden" name="remove_pid" value="">
            <div style="display:flex;gap:10px;margin-top:14px">
                <button class="btn btn-outline" type="submit" name="action" value="update">Update Quantities</button>
                <a class="btn btn-light" href="shop.php">+ Add More Products</a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-title">🧾 Order Summary</div>
        <table class="kv-table" style="width:100%">
            <tr><td>MRP Total</td><td><?= money($totalMrp) ?></td></tr>
            <tr><td>DP Total (payable)</td><td><b><?= money($totalDp) ?></b></td></tr>
            <tr><td>Total BV</td><td><b style="color:var(--dash-gold)"><?= bv($totalBv) ?></b></td></tr>
            <tr><td>Retail Profit (MRP−DP)</td><td><?= money($totalMrp - $totalDp) ?></td></tr>
        </table>

        <form method="post" style="margin-top:18px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="checkout">
            <h4 style="font-size:14px;margin-bottom:10px">Shipping Details</h4>
            <div class="form-group"><label>Receiver Name</label>
                <input class="form-control" name="ship_name" value="<?= e($u['full_name']) ?>"></div>
            <div class="form-group"><label>Mobile</label>
                <input class="form-control" name="ship_mobile" value="<?= e($u['mobile']) ?>"></div>
            <div class="form-group"><label>Address</label>
                <input class="form-control" name="ship_address" value="<?= e($u['address']) ?>"></div>
            <div class="form-grid2">
                <div class="form-group"><label>City</label>
                    <input class="form-control" name="ship_city" value="<?= e($u['city']) ?>"></div>
                <div class="form-group"><label>State</label>
                    <input class="form-control" name="ship_state" value="<?= e($u['state']) ?>"></div>
            </div>
            <div class="form-group"><label>Pincode</label>
                <input class="form-control" name="ship_pincode" value="<?= e($u['pincode']) ?>"></div>

            <h4 style="font-size:14px;margin:14px 0 10px">Payment Method</h4>
            <label class="form-check"><input type="radio" name="payment_mode" value="bank_transfer" checked>
                <span><b>Bank Transfer / UPI</b> — transfer <?= money($totalDp) ?> to the company account, then enter your transaction reference. Order is verified by the company.</span></label>
            <label class="form-check"><input type="radio" name="payment_mode" value="wallet">
                <span><b>E-Wallet</b> — pay instantly from your wallet balance (<?= money($u['wallet_balance']) ?>)</span></label>
            <div class="form-group" style="margin-top:10px">
                <label>Payment / UPI Transaction Reference <small style="color:var(--ink-soft)">(for bank transfer)</small></label>
                <input class="form-control" name="txn_ref" placeholder="e.g. UPI ref 4032XXXXXX12">
            </div>
            <button class="btn btn-primary btn-block" type="submit">✅ Place Order</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
