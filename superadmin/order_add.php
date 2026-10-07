<?php
/** Manual order entry — simplified: pick the distributor and the product from
 *  dropdowns, see the pricing instantly, submit — and the order is DELIVERED
 *  directly: the cash payment is marked as received and the approval step
 *  (BV, commissions, stock, ranks) runs immediately.
 */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

$preUid = get_int('uid');   /* preselect when opened from the member view */
$users = q_all("SELECT id, username, full_name, mobile, city
                FROM users WHERE status = 'active' ORDER BY username");
$products = q_all("SELECT * FROM products WHERE status = 'active' ORDER BY sort_order, id");

if (is_post()) {
    verify_csrf();
    $uid = (int)post_str('user_id');
    $pid = (int)post_str('product_id');
    $qty = max(1, (int)post_str('qty'));

    $buyer = q_row("SELECT * FROM users WHERE id = ? AND status = 'active'", [$uid]);
    if (!$buyer) {
        flash('error', 'Please select a distributor.');
        redirect('order_add.php');
    }
    $p = q_row("SELECT * FROM products WHERE id = ? AND status = 'active'", [$pid]);
    if (!$p) {
        flash('error', 'Please select a product.');
        redirect('order_add.php');
    }
    if ((int)$p['stock'] < $qty) {
        flash('error', 'Insufficient stock for ' . $p['name'] . ' (available: ' . (int)$p['stock'] . ').');
        redirect('order_add.php');
    }

    $totalMrp = (float)$p['mrp'] * $qty;
    $totalDp  = (float)$p['dp'] * $qty;
    $totalBv  = (float)$p['bv'] * $qty;

    /* create the order (cash, already received) and deliver it straight away */
    $orderNo = '';
    $oid = db_tx(function () use (&$orderNo, $buyer, $p, $qty, $totalMrp, $totalDp, $totalBv) {
        $orderNo = order_no();
        q("INSERT INTO orders (order_no, user_id, total_mrp, total_dp, total_bv, payment_mode, payment_status,
           txn_ref, status, ship_name, ship_mobile, ship_address, ship_city, ship_state, ship_pincode, created_at)
           VALUES (?, ?, ?, ?, ?, 'cash', 'paid', 'cash received by company', 'pending', ?, ?, ?, ?, ?, ?, NOW())",
          [$orderNo, $buyer['id'], $totalMrp, $totalDp, $totalBv,
           $buyer['full_name'], $buyer['mobile'], $buyer['address'], $buyer['city'], $buyer['state'], $buyer['pincode']]);
        $oid = (int)db()->lastInsertId();
        q("INSERT INTO order_items (order_id, product_id, product_name, price, mrp, bv, qty, total, total_bv)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
          [$oid, $p['id'], $p['name'], $p['dp'], $p['mrp'], $p['bv'],
           $qty, (float)$p['dp'] * $qty, (float)$p['bv'] * $qty]);
        return $oid;
    });

    /* deliver directly: runs the full approval — self BV, activation,
     * sponsor + level income, binary matching, ranks, stock decrement */
    [$ok, $msg] = approve_order($oid, $a['id']);
    if ($ok) {
        flash('success', 'Order ' . $orderNo . ' for ' . $buyer['username'] . ' (' . $buyer['full_name'] .
            ') delivered — cash ₹' . number_format($totalDp) . ' received, ' . number_format($totalBv) .
            ' BV and commissions credited.');
        redirect('order_view.php?id=' . $oid);
    }
    flash('error', 'Order ' . $orderNo . ' was created but could not be delivered: ' . $msg);
    redirect('order_view.php?id=' . $oid);
}

$activeKey = 'orders';
$pageTitle = 'Add Order (Manual)';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card" style="max-width:660px">
    <div class="card-title">
        ➕ Add Order (Manual)
        <span class="right">Pick a distributor and a product — the order is delivered directly and commissions run immediately.</span>
    </div>

    <form method="post" id="manual-order-form">
        <?= csrf_field() ?>

        <div class="form-grid2">
            <div class="form-group">
                <label>Distributor <span class="req">*</span></label>
                <select class="form-control" name="user_id" id="mo-user" required>
                    <option value="">— select distributor —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" data-name="<?= e($u['full_name']) ?>"<?= $preUid === (int)$u['id'] ? ' selected' : '' ?>>
                            <?= e($u['username'] . ' — ' . $u['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-hint">User ID with the member's name.</div>
            </div>

            <div class="form-group">
                <label>Product <span class="req">*</span></label>
                <select class="form-control" name="product_id" id="mo-product" required>
                    <option value="">— select product —</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"
                                data-name="<?= e($p['name']) ?>"
                                data-mrp="<?= (float)$p['mrp'] ?>"
                                data-dp="<?= (float)$p['dp'] ?>"
                                data-bv="<?= (float)$p['bv'] ?>"
                                data-stock="<?= (int)$p['stock'] ?>">
                            <?= e($p['name']) ?> — ₹<?= number_format((float)$p['dp']) ?> / <?= number_format((float)$p['bv']) ?> BV
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-hint">Product with distributor price and BV.</div>
            </div>

            <div class="form-group">
                <label>Quantity <span class="req">*</span></label>
                <input class="form-control" type="number" name="qty" id="mo-qty" value="1" min="1" max="99" required>
                <div class="form-hint">Number of units of the selected product.</div>
            </div>
        </div>

        <!-- live pricing panel: fills as soon as both dropdowns have a choice -->
        <div id="mo-pricing" class="mo-pricing" style="display:none">
            <div class="mo-pricing-head">🧾 Order summary</div>
            <div class="mo-row"><span>Order for</span><b id="mo-for">—</b></div>
            <div class="mo-row"><span>Product</span><b id="mo-pname">—</b></div>
            <div class="mo-row"><span>MRP (per unit)</span><b id="mo-umrp">—</b></div>
            <div class="mo-row"><span>Distributor price (per unit)</span><b id="mo-udp">—</b></div>
            <div class="mo-row"><span>BV (per unit)</span><b id="mo-ubv">—</b></div>
            <div class="mo-row"><span>Quantity</span><b id="mo-q">—</b></div>
            <div class="mo-row mo-total"><span>Total payable (cash)</span><b id="mo-total">—</b></div>
            <div class="mo-row"><span>Total MRP</span><b id="mo-tmrp">—</b></div>
            <div class="mo-row"><span>Total BV</span><b id="mo-tbv">—</b></div>
        </div>

        <button class="btn btn-primary" type="submit" id="mo-submit" disabled>📦 Place Order &amp; Deliver Directly</button>
        <div class="form-hint" style="margin-top:8px">
            The cash payment is recorded as received; BV, commissions and stock update immediately — no approval step needed.
        </div>
    </form>
</div>

<style>
.mo-pricing{background:#f4f8f3;border:1px solid #dfe9dc;border-radius:12px;padding:4px 14px;margin:16px 0 6px}
.mo-pricing-head{font-size:12px;text-transform:uppercase;letter-spacing:.7px;color:#68786b;padding:10px 0 4px}
.mo-row{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px dashed #dfe8dc;font-size:13.5px}
.mo-row:last-child{border-bottom:0}
.mo-row>span{color:#68786b}
.mo-row>b{color:#000;font-weight:600;text-align:right;overflow-wrap:anywhere}
.mo-row.mo-total{background:#eaf4e8;border-radius:10px;padding:10px 12px;margin-top:6px;border-bottom:0}
.mo-row.mo-total b{font-size:16px;color:#2e7d32}
</style>

<script>
(function () {
    var uSel = document.getElementById('mo-user');
    var pSel = document.getElementById('mo-product');
    var qty = document.getElementById('mo-qty');
    var box = document.getElementById('mo-pricing');
    var submit = document.getElementById('mo-submit');

    function money(n) { return '₹' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 }); }

    function upd() {
        var uOpt = uSel.options[uSel.selectedIndex];
        var pOpt = pSel.options[pSel.selectedIndex];
        var ready = uOpt && uOpt.value && pOpt && pOpt.value;
        submit.disabled = !ready;
        if (!ready) { box.style.display = 'none'; return; }

        var q = Math.max(1, parseInt(qty.value, 10) || 1);
        var dp = parseFloat(pOpt.getAttribute('data-dp')) || 0;
        var mrp = parseFloat(pOpt.getAttribute('data-mrp')) || 0;
        var bv = parseFloat(pOpt.getAttribute('data-bv')) || 0;

        document.getElementById('mo-for').textContent = uOpt.textContent.trim();
        document.getElementById('mo-pname').textContent = pOpt.getAttribute('data-name');
        document.getElementById('mo-umrp').textContent = money(mrp);
        document.getElementById('mo-udp').textContent = money(dp);
        document.getElementById('mo-ubv').textContent = Number(bv).toLocaleString('en-IN') + ' BV';
        document.getElementById('mo-q').textContent = q;
        document.getElementById('mo-total').textContent = money(dp * q);
        document.getElementById('mo-tmrp').textContent = money(mrp * q);
        document.getElementById('mo-tbv').textContent = Number(bv * q).toLocaleString('en-IN') + ' BV';

        /* warn about stock */
        var stock = parseInt(pOpt.getAttribute('data-stock'), 10) || 0;
        qty.max = Math.max(1, stock);
        document.getElementById('mo-q').textContent = q + (q > stock ? '  ⚠ only ' + stock + ' in stock' : '');

        box.style.display = '';
    }

    uSel.addEventListener('change', upd);
    pSel.addEventListener('change', upd);
    qty.addEventListener('input', upd);
    upd();
})();
</script>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
