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
                <div class="combo" id="mo-user-combo">
                    <input class="form-control combo-input" type="text" id="mo-user" placeholder="Search distributor — ID or name" autocomplete="off">
                    <input type="hidden" name="user_id" id="mo-user-id" value="">
                    <button type="button" class="combo-toggle" tabindex="-1" aria-label="Show all">▾</button>
                    <div class="combo-list" id="mo-user-list"></div>
                </div>
                <div class="form-hint">Type to search by User ID, name or mobile — or click ▾ to browse.</div>
            </div>

            <div class="form-group">
                <label>Product <span class="req">*</span></label>
                <div class="combo" id="mo-product-combo">
                    <input class="form-control combo-input" type="text" id="mo-product" placeholder="Search product" autocomplete="off">
                    <input type="hidden" name="product_id" id="mo-product-id" value="">
                    <button type="button" class="combo-toggle" tabindex="-1" aria-label="Show all">▾</button>
                    <div class="combo-list" id="mo-product-list"></div>
                </div>
                <div class="form-hint">Type to search — shows distributor price, BV and stock.</div>
            </div>

            <div class="form-group">
                <label>Quantity <span class="req">*</span></label>
                <input class="form-control" type="number" name="qty" id="mo-qty" value="1" min="1" max="99" required>
                <div class="form-hint">Number of units of the selected product.</div>
            </div>
        </div>

        <!-- live pricing panel: fills as soon as both fields have a choice -->
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

<style>
.mo-pricing{background:#f4f8f3;border:1px solid #dfe9dc;border-radius:12px;padding:4px 14px;margin:16px 0 6px}
.mo-pricing-head{font-size:12px;text-transform:uppercase;letter-spacing:.7px;color:#68786b;padding:10px 0 4px}
.mo-row{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px dashed #dfe9dc;font-size:13.5px}
.mo-row:last-child{border-bottom:0}
.mo-row>span{color:#68786b}
.mo-row>b{color:#000;font-weight:600;text-align:right;overflow-wrap:anywhere}
.mo-row.mo-total{background:#eaf4e8;border-radius:10px;padding:10px 12px;margin-top:6px;border-bottom:0}
.mo-row.mo-total b{font-size:16px;color:#2e7d32}
.combo{position:relative}
.combo-input{font-size:16px;padding-right:36px}
.combo-toggle{position:absolute;right:4px;top:50%;transform:translateY(-50%);background:none;border:0;font-size:15px;color:#68786b;cursor:pointer;padding:8px;line-height:1}
.combo-toggle:hover{color:#1b3a1f}
.combo-list{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1px solid #cfd8cf;border-radius:10px;box-shadow:0 12px 30px rgba(22,53,26,.16);max-height:262px;overflow-y:auto;z-index:60;-webkit-overflow-scrolling:touch}
.combo-list.open{display:block}
.combo-opt{padding:9px 12px;font-size:13px;cursor:pointer;line-height:1.4}
.combo-opt b{color:#000;font-weight:600;overflow-wrap:anywhere}
.combo-opt small{display:block;color:#68786b;font-size:11.5px}
.combo-opt:hover,.combo-opt.active{background:#eaf4e8}
.combo-empty{padding:10px 12px;color:#68786b;font-size:12.5px}
</style>

<script>
(function () {
    /* data from PHP: [{id, label, sub, search, ...}] */
    var USERS = <?= json_encode(array_map(function ($u) {
        return [
            'id'     => (int)$u['id'],
            'label'  => $u['username'] . ' — ' . $u['full_name'],
            'sub'    => trim(($u['city'] ?? '') . ' • ' . ($u['mobile'] ?? ''), ' •'),
            'search' => $u['username'] . ' ' . $u['full_name'] . ' ' . ($u['mobile'] ?? '') . ' ' . ($u['city'] ?? ''),
        ];
    }, $users)) ?>;
    var PRODUCTS = <?= json_encode(array_map(function ($p) {
        return [
            'id'     => (int)$p['id'],
            'label'  => $p['name'],
            'sub'    => '₹' . number_format((float)$p['dp']) . ' DP • ' . number_format((float)$p['bv']) . ' BV • ' . (int)$p['stock'] . ' in stock',
            'search' => $p['name'],
            'mrp'    => (float)$p['mrp'],
            'dp'     => (float)$p['dp'],
            'bv'     => (float)$p['bv'],
            'stock'  => (int)$p['stock'],
        ];
    }, $products)) ?>;

    /* ---- searchable dropdown (combobox) ---- */
    function makeCombo(inputId, hiddenId, listId, items, onPick) {
        var input = document.getElementById(inputId);
        var hidden = document.getElementById(hiddenId);
        var list = document.getElementById(listId);
        var toggle = input.parentNode.querySelector('.combo-toggle');
        var open = false, activeIndex = -1, filtered = items.slice();

        function render() {
            list.innerHTML = '';
            if (!filtered.length) {
                list.innerHTML = '<div class="combo-empty">No matches found</div>';
                return;
            }
            filtered.forEach(function (it, i) {
                var d = document.createElement('div');
                d.className = 'combo-opt' + (i === activeIndex ? ' active' : '');
                var b = document.createElement('b');
                b.textContent = it.label;
                d.appendChild(b);
                if (it.sub) {
                    var sm = document.createElement('small');
                    sm.textContent = it.sub;
                    d.appendChild(sm);
                }
                /* mousedown fires before blur and before click-through */
                d.addEventListener('mousedown', function (e) { e.preventDefault(); pick(it); });
                list.appendChild(d);
            });
        }
        function filter() {
            var q = input.value.trim().toLowerCase();
            filtered = !q ? items.slice() : items.filter(function (it) {
                return (it.label + ' ' + (it.search || '')).toLowerCase().indexOf(q) !== -1;
            });
            activeIndex = filtered.length ? 0 : -1;
            render();
        }
        function openList() { filter(); list.classList.add('open'); open = true; }
        function closeList() { list.classList.remove('open'); open = false; }
        function pick(it) {
            input.value = it.label;
            hidden.value = it.id;
            closeList();
            if (onPick) { onPick(it); }
        }
        input.addEventListener('focus', openList);
        input.addEventListener('click', openList);
        input.addEventListener('input', function () {
            hidden.value = '';
            openList();
            if (onPick) { onPick(null); }
        });
        input.addEventListener('keydown', function (e) {
            if (!open) {
                if (e.key === 'ArrowDown') { openList(); e.preventDefault(); }
                return;
            }
            if (e.key === 'ArrowDown') { activeIndex = Math.min(activeIndex + 1, filtered.length - 1); render(); scrollActive(); e.preventDefault(); }
            else if (e.key === 'ArrowUp') { activeIndex = Math.max(activeIndex - 1, 0); render(); scrollActive(); e.preventDefault(); }
            else if (e.key === 'Enter') { if (filtered[activeIndex]) { pick(filtered[activeIndex]); } e.preventDefault(); }
            else if (e.key === 'Escape') { closeList(); }
        });
        function scrollActive() {
            var el = list.querySelector('.combo-opt.active');
            if (el && el.scrollIntoView) { el.scrollIntoView({ block: 'nearest' }); }
        }
        toggle.addEventListener('click', function () {
            if (open) { closeList(); } else { input.focus(); openList(); }
        });
        document.addEventListener('click', function (e) {
            if (!input.parentNode.contains(e.target)) { closeList(); }
        });
        return {
            pick: pick,
            getSelected: function () {
                var v = hidden.value;
                for (var i = 0; i < items.length; i++) { if (String(items[i].id) === String(v)) { return items[i]; } }
                return null;
            }
        };
    }

    var qty = document.getElementById('mo-qty');
    var box = document.getElementById('mo-pricing');
    var submit = document.getElementById('mo-submit');

    function money(n) { return '₹' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 }); }
    function upd() {
        var u = userCombo.getSelected();
        var p = prodCombo.getSelected();
        submit.disabled = !(u && p);
        if (!(u && p)) { box.style.display = 'none'; return; }

        var q = Math.max(1, parseInt(qty.value, 10) || 1);
        document.getElementById('mo-for').textContent = u.label;
        document.getElementById('mo-pname').textContent = p.label;
        document.getElementById('mo-umrp').textContent = money(p.mrp);
        document.getElementById('mo-udp').textContent = money(p.dp);
        document.getElementById('mo-ubv').textContent = Number(p.bv).toLocaleString('en-IN') + ' BV';
        document.getElementById('mo-total').textContent = money(p.dp * q);
        document.getElementById('mo-tmrp').textContent = money(p.mrp * q);
        document.getElementById('mo-tbv').textContent = Number(p.bv * q).toLocaleString('en-IN') + ' BV';

        qty.max = Math.max(1, p.stock);
        document.getElementById('mo-q').textContent = q + (q > p.stock ? '  ⚠ only ' + p.stock + ' in stock' : '');
        box.style.display = '';
    }

    var userCombo = makeCombo('mo-user', 'mo-user-id', 'mo-user-list', USERS, upd);
    var prodCombo = makeCombo('mo-product', 'mo-product-id', 'mo-product-list', PRODUCTS, upd);
    qty.addEventListener('input', upd);

    /* preselected distributor (opened from the member view: ?uid=) */
    <?php if ($preUid > 0): ?>
    (function () {
        var pre = USERS.filter(function (u) { return u.id === <?= (int)$preUid ?>; })[0];
        if (pre) { userCombo.pick(pre); }
    })();
    <?php endif; ?>
    upd();
})();
</script>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
