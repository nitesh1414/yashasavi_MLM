<?php
/** My orders list */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

$status = get_str('status');
$where = "user_id = ?";
$params = [$u['id']];
if ($status !== '') {
    $where .= " AND status = ?";
    $params[] = $status;
}
$total = (int)q_val("SELECT COUNT(*) FROM orders WHERE $where", $params);
$orders = q_all("SELECT * FROM orders WHERE $where ORDER BY id DESC", $params);

/* Invoice popup — the document itself is fetched from user/invoice.php */
$invUrl = url('user/invoice.php');
$pageScripts = <<<JS
(function () {
    var modal = document.getElementById('invModal');
    if (!modal) { return; }
    var bodyEl = document.getElementById('invModalBody');
    var subEl = document.getElementById('invModalSub');
    var base = '{$invUrl}';
    var cur = null; /* { id: 12, html: '...' } */

    function open(id, orderNo) {
        cur = { id: id, html: '' };
        subEl.textContent = orderNo ? ('Order ' + orderNo) : '';
        bodyEl.innerHTML = '<div class="invmodal-load">Loading invoice…</div>';
        modal.hidden = false;
        document.documentElement.style.overflow = 'hidden';
        fetch(base + '?id=' + encodeURIComponent(id) + '&ajax=1', { credentials: 'same-origin' })
            .then(function (r) {
                if (r.redirected || !r.ok) { throw new Error('failed'); }
                return r.text();
            })
            .then(function (html) {
                if (!cur || cur.id !== id) { return; }
                cur.html = html;
                bodyEl.innerHTML = html;
            })
            .catch(function () {
                if (!cur || cur.id !== id) { return; }
                bodyEl.innerHTML = '<div class="invmodal-err">Could not load the invoice. Please try again.</div>';
            });
    }

    function close() {
        cur = null;
        modal.hidden = true;
        bodyEl.innerHTML = '';
        document.documentElement.style.overflow = '';
    }

    /* Print exactly the invoice document: it is rendered inside a hidden
       iframe so the panel chrome (sidebar, topbar, tabbar) never prints. */
    function printInvoice() {
        if (!cur || !cur.html) { return; }
        var f = document.createElement('iframe');
        f.setAttribute('aria-hidden', 'true');
        f.style.cssText = 'position:fixed;left:-9999px;top:0;width:840px;height:640px;border:0';
        var printed = false;
        function go() {
            if (printed) { return; }
            printed = true;
            try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) {}
            setTimeout(function () { if (f.parentNode) { f.parentNode.removeChild(f); } }, 60000);
        }
        f.onload = function () { setTimeout(go, 350); };
        document.body.appendChild(f);
        var doc = f.contentDocument || f.contentWindow.document;
        doc.open();
        doc.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Invoice</title>'
            + '<style>@page{size:A4;margin:12mm}@media print{body{margin:0;-webkit-print-color-adjust:exact;print-color-adjust:exact}}</style>'
            + '</head><body style="margin:0">' + cur.html + '</body></html>');
        doc.close();
        setTimeout(go, 3000); /* safety net if onload never fires */
    }

    function downloadPdf() {
        if (!cur) { return; }
        window.location.href = base + '?id=' + encodeURIComponent(cur.id) + '&pdf=1';
    }

    document.addEventListener('click', function (ev) {
        var t = ev.target && ev.target.closest ? ev.target.closest('[data-invoice]') : null;
        if (t) {
            ev.preventDefault();
            open(parseInt(t.getAttribute('data-invoice'), 10) || 0, t.getAttribute('data-order') || '');
            return;
        }
        if (!modal || modal.hidden) { return; }
        if (ev.target.closest('[data-invprint]')) { ev.preventDefault(); printInvoice(); return; }
        if (ev.target.closest('[data-invdl]')) { ev.preventDefault(); downloadPdf(); return; }
        if (ev.target.closest('[data-invclose]')) { ev.preventDefault(); close(); }
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && modal && !modal.hidden) { close(); }
    });
})();
JS;

$activeKey = 'orders';
$pageTitle = 'My Orders';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card">
    <div class="card-title">
        📦 My Orders
        <span class="right">
            <a class="btn <?= $status === '' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="orders.php">All</a>
            <?php foreach (['pending', 'approved', 'rejected'] as $st): ?>
                <a class="btn <?= $status === $st ? 'btn-primary' : 'btn-light' ?> btn-sm" href="orders.php?status=<?= $st ?>"><?= ucfirst($st) ?></a>
            <?php endforeach; ?>
        </span>
    </div>

    <?php if (!$orders): ?>
        <div class="empty-state">
            <span class="es-ico">📦</span>
            <p>No orders found.</p>
            <a class="btn btn-primary mt-2" href="shop.php">Shop Now</a>
        </div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table table-dt">
            <thead>
                <tr><th>Order No.</th><th>Date</th><th>Items</th><th>DP Total</th><th>BV</th><th>Payment</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
<?php foreach ($orders as $o):
                $items = q_val("SELECT COUNT(*) FROM order_items WHERE order_id = ?", [$o['id']]);
                $pmLabel = ['wallet' => 'Wallet', 'bank_transfer' => 'Bank/UPI', 'online' => 'Online', 'cash' => 'Cash'][$o['payment_mode']] ?? $o['payment_mode'];
            ?>
            <tr>
                <td><b><?= e($o['order_no']) ?></b></td>
                <td><?= dmy($o['created_at'], true) ?></td>
                <td><?= (int)$items ?></td>
                <td><?= money($o['total_dp']) ?></td>
                <td><?= bv($o['total_bv']) ?></td>
                <td><?= e($pmLabel) ?><br><small style="color:#000"><?= status_badge($o['payment_status']) ?></small>
                    <?php if ($o['payment_mode'] === 'cash' && $o['payment_status'] === 'pending' && $o['status'] === 'pending'): ?>
                    <br><small style="color:#a67c00">awaiting company authorization</small>
                    <?php endif; ?></td>
                <td><?= status_badge($o['status']) ?></td>
                <td style="white-space:nowrap">
                    <a class="btn btn-outline btn-sm" href="order_view.php?id=<?= (int)$o['id'] ?>">View</a>
                    <button type="button" class="btn btn-primary btn-sm" data-invoice="<?= (int)$o['id'] ?>" data-order="<?= e($o['order_no']) ?>">Invoice</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
    </div>
    <?php endif; ?>
</div>

<?php /* ---------- Invoice popup (opened by the Invoice buttons above) ---------- */ ?>
<div class="invmodal" id="invModal" hidden>
    <div class="invmodal-backdrop" data-invclose></div>
    <div class="invmodal-box" role="dialog" aria-modal="true" aria-label="Order invoice">
        <div class="invmodal-head">
            <div class="invmodal-ht">
                <div class="invmodal-title">🧾 Invoice</div>
                <div class="invmodal-sub" id="invModalSub"></div>
            </div>
            <div class="invmodal-actions">
                <button type="button" class="btn btn-outline btn-sm" data-invprint>🖨️ Print</button>
                <button type="button" class="btn btn-primary btn-sm" data-invdl>⬇️ Download PDF</button>
                <button type="button" class="invmodal-x" data-invclose aria-label="Close invoice">&times;</button>
            </div>
        </div>
        <div class="invmodal-body" id="invModalBody"></div>
    </div>
</div>

<style>
/* Invoice popup — scoped styles (orders page only) */
.invmodal{position:fixed;inset:0;z-index:9995;display:flex;align-items:center;justify-content:center;padding:18px}
.invmodal[hidden]{display:none}
.invmodal-backdrop{position:absolute;inset:0;background:rgba(8,20,12,.72);backdrop-filter:blur(2px)}
.invmodal-box{position:relative;display:flex;flex-direction:column;width:min(94vw,880px);max-height:92vh;background:#fff;border-radius:14px;box-shadow:0 24px 70px rgba(0,0,0,.4);overflow:hidden;animation:invmodalIn .28s ease}
.invmodal-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;background:var(--card,#fff);border-bottom:1px solid var(--line,#e2e8de);flex:0 0 auto}
.invmodal-title{font-family:var(--font-head,inherit);font-weight:600;font-size:15px;color:#000}
.invmodal-sub{font-size:11.5px;color:var(--ink-soft,#000);margin-top:1px}
.invmodal-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.invmodal-x{width:34px;height:34px;border-radius:50%;border:1px solid var(--line,#e2e8de);background:#fff;color:#000;font-size:20px;line-height:1;cursor:pointer}
.invmodal-x:hover{background:#fde5e5;color:#9c1f1f;border-color:#f3bcbc}
.invmodal-body{overflow:auto;padding:0;background:#eef1ee;-webkit-overflow-scrolling:touch}
.invmodal-body .invoice-doc{margin:14px auto;box-shadow:0 2px 14px rgba(0,0,0,.12)}
.invmodal-load,.invmodal-err{padding:60px 20px;text-align:center;color:var(--ink-soft,#000);font-size:13px}
.invmodal-err{color:#9c1f1f}
@keyframes invmodalIn{from{opacity:0;transform:scale(.94) translateY(12px)}to{opacity:1;transform:none}}
@media (max-width:640px){
    .invmodal{padding:0}
    .invmodal-box{width:100%;max-height:100%;height:100%;border-radius:0}
    .invmodal-head{flex-wrap:wrap;padding:10px 12px}
    .invmodal-body .invoice-doc{margin:10px auto}
    .invmodal-body{overflow-y:auto;overflow-x:hidden}
}
</style>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
