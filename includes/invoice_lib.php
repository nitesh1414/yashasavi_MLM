<?php
/**
 * Invoice library — shared by the member "My Orders" invoice popup
 * (user/invoice.php) and its PDF download.
 *
 * invoice_data()  loads and validates an order (ownership enforced),
 * invoice_html()  renders the printable invoice document (HTML),
 * invoice_words() formats an amount in words (Indian numbering).
 */

/** Load an order with items + buyer + company details for the invoice.
 * Pass the member id to enforce ownership (member panel); pass null for
 * the admin side. Returns null when the order does not exist / is not
 * visible for that member. */
function invoice_data($orderId, $userId = null)
{
    $orderId = (int)$orderId;
    $where = $userId !== null ? " AND o.user_id = " . (int)$userId : '';
    $o = q_row("SELECT o.*, u.full_name, u.username, u.mobile, u.email, u.address, u.city, u.state, u.pincode
                FROM orders o JOIN users u ON u.id = o.user_id
                WHERE o.id = ?" . $where, [$orderId]);
    if (!$o) {
        return null;
    }
    $items = q_all("SELECT * FROM order_items WHERE order_id = ? ORDER BY id", [$orderId]);
    if (!$items) {
        return null;
    }
    $pmLabel = ['wallet' => 'Wallet', 'bank_transfer' => 'Bank / UPI Transfer', 'online' => 'Online Payment', 'cash' => 'Cash'][(string)$o['payment_mode']] ?? (string)$o['payment_mode'];
    $stLabel = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'][(string)$o['status']] ?? (string)$o['status'];
    $payLabel = ['pending' => 'Pending', 'paid' => 'Paid', 'refunded' => 'Refunded'][(string)$o['payment_status']] ?? (string)$o['payment_status'];

    $totMrp = 0.0; $totDp = 0.0; $totBv = 0.0; $totQty = 0;
    foreach ($items as $it) {
        $totMrp += (float)$it['mrp'] * (int)$it['qty'];
        $totDp += (float)$it['price'] * (int)$it['qty'];
        $totBv += (float)$it['bv'] * (int)$it['qty'];
        $totQty += (int)$it['qty'];
    }
    return [
        'order' => $o, 'items' => $items,
        'totals' => ['mrp' => $totMrp, 'dp' => $totDp, 'bv' => $totBv, 'qty' => $totQty, 'savings' => $totMrp - $totDp],
        'labels' => ['pm' => $pmLabel, 'status' => $stLabel, 'pay' => $payLabel],
        'company' => [
            'name' => setting('company_name') ?: setting('site_name'),
            'address' => setting('contact_address'),
            'phone' => setting('contact_phone'),
            'email' => setting('contact_email'),
            'logo' => setting('site_logo'),
        ],
        'no' => 'INV-' . preg_replace('/[^A-Za-z0-9]/', '', (string)$o['order_no']),
    ];
}

/** Amount in words, Indian numbering (crore / lakh / thousand / hundred). */
function invoice_words($amount)
{
    $amount = round((float)$amount, 2);
    $rupees = (int)floor($amount);
    $paise = (int)round(($amount - $rupees) * 100);
    $ones = ['Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $below1000 = function ($n) use (&$below1000, $ones, $tens) {
        if ($n < 20) { return $ones[$n]; }
        if ($n < 100) { return trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]); }
        return trim($ones[intdiv($n, 100)] . ' Hundred' . ($n % 100 ? ' ' . $below1000($n % 100) : ''));
    };
    $parts = [];
    $units = [[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']];
    $rest = $rupees;
    foreach ($units as [$div, $name]) {
        if ($rest >= $div) {
            $parts[] = $below1000(intdiv($rest, $div)) . ' ' . $name;
            $rest %= $div;
        }
    }
    if ($rest > 0 || !$parts) {
        $parts[] = $below1000($rest);
    }
    $words = implode(' ', $parts) . ' Rupees';
    if ($paise > 0) {
        $words .= ' and ' . $below1000($paise) . ' Paise';
    }
    return $words . ' Only';
}

/** The invoice document (HTML fragment with its own scoped styles).
 * Used inside the orders popup and for printing. */
function invoice_html(array $inv)
{
    $o = $inv['order'];
    $t = $inv['totals'];
    $c = $inv['company'];
    $logo = $c['logo'] ? upload_url($c['logo']) : url('assets/img/logo.png');
    $date = $o['approved_at'] ?: $o['created_at'];
    $ship = null;
    if ((string)$o['ship_name'] !== '' || (string)$o['ship_address'] !== '') {
        $ship = [
            'name' => $o['ship_name'] ?: $o['full_name'],
            'addr' => trim(($o['ship_address'] ?: $o['address']) . ', ' . ($o['ship_city'] ?: $o['city']) . ', ' . ($o['ship_state'] ?: $o['state']) . ' - ' . ($o['ship_pincode'] ?: $o['pincode']), ', -'),
            'mobile' => $o['ship_mobile'] ?: $o['mobile'],
        ];
    }
    ob_start();
    ?>
<style>
.invoice-doc{background:#fff;color:#111;font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:1.45;max-width:820px;margin:0 auto;padding:34px 38px;box-sizing:border-box}
.invoice-doc *{box-sizing:border-box}
.inv-top{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;border-bottom:3px solid #17433c;padding-bottom:14px}
.inv-co img{max-height:54px;max-width:100%;display:block;margin-bottom:8px}
.inv-co .nm{font-size:17px;font-weight:700;color:#17433c;letter-spacing:.2px}
.inv-co .ad{font-size:10.5px;color:#444;max-width:380px}
.inv-co .ct{font-size:10.5px;color:#444}
.inv-title{text-align:right;flex:0 0 auto}
.inv-title h2{margin:0;font-size:21px;letter-spacing:2.5px;color:#17433c;font-weight:700}
.inv-title .no{margin-top:7px;font-size:12px}
.inv-title .no b{font-size:13px}
.inv-meta{display:flex;gap:14px;margin:16px 0 0}
.inv-meta .box{flex:1;border:1px solid #d9dee2;border-radius:4px;padding:10px 12px;background:#f8faf9}
.inv-meta .box h4{margin:0 0 6px;font-size:10px;letter-spacing:1.2px;color:#666;text-transform:uppercase;border-bottom:1px solid #e3e8ea;padding-bottom:5px}
.inv-meta .box p{margin:2px 0;font-size:11.5px}
.inv-items{width:100%;border-collapse:collapse;margin-top:16px;font-size:11.5px}
.inv-items th{background:#17433c;color:#fff;text-align:left;padding:8px 9px;font-size:10px;letter-spacing:.8px;text-transform:uppercase}
.inv-items td{padding:8px 9px;border-bottom:1px solid #e3e8ea;vertical-align:top}
.inv-items .r{text-align:right}
.inv-items tr.tot td{background:#f3f7f5;font-weight:700}
.inv-sum{display:flex;justify-content:space-between;gap:20px;margin-top:14px;align-items:flex-start}
.inv-words{flex:1;border:1px dashed #b9c4c0;border-radius:4px;padding:10px 12px;background:#fbfdfc;font-size:11px}
.inv-words b{display:block;font-size:9.5px;letter-spacing:1.2px;color:#666;text-transform:uppercase;margin-bottom:4px}
.inv-tots{flex:0 0 300px}
.inv-tots table{width:100%;border-collapse:collapse;font-size:11.5px}
.inv-tots td{padding:6px 9px;border-bottom:1px solid #e3e8ea}
.inv-tots .lbl{color:#555}
.inv-tots td.r{text-align:right;white-space:nowrap}
.inv-tots tr.grand td{background:#17433c;color:#fff;font-weight:700;font-size:13px;border:none}
.inv-note{margin-top:16px;font-size:10px;color:#555;line-height:1.55;border-top:1px solid #e3e8ea;padding-top:10px}
.inv-status{display:inline-block;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:700;letter-spacing:.6px}
.inv-status.paid,.inv-status.approved{background:#e3f4e6;color:#176d2d}
.inv-status.pending{background:#fdf3dc;color:#8a6100}
.inv-status.rejected,.inv-status.cancelled,.inv-status.refunded{background:#fde5e5;color:#9c1f1f}
</style>
<div class="invoice-doc" id="invoiceDoc">
    <div class="inv-top">
        <div class="inv-co">
            <img src="<?= e($logo) ?>" alt="logo">
            <div class="nm"><?= e($c['name']) ?></div>
            <div class="ad"><?= e($c['address']) ?></div>
            <div class="ct">Phone: <?= e($c['phone']) ?> &nbsp;·&nbsp; Email: <?= e($c['email']) ?></div>
        </div>
        <div class="inv-title">
            <h2>TAX INVOICE</h2>
            <div class="no">Invoice No: <b><?= e($inv['no']) ?></b><br>
                Invoice Date: <b><?= e(dmy($date)) ?></b><br>
                Order No: <b><?= e($o['order_no']) ?></b></div>
        </div>
    </div>

    <div class="inv-meta">
        <div class="box">
            <h4>Billed To</h4>
            <p><b><?= e($o['full_name']) ?></b> (<?= e($o['username']) ?>)</p>
            <?php if ($o['address'] || $o['city']): ?><p><?= e(trim($o['address'] . ', ' . $o['city'] . ', ' . $o['state'] . ' - ' . $o['pincode'], ', -')) ?></p><?php endif; ?>
            <p>Mobile: <?= e($o['mobile']) ?><?php if ($o['email']): ?> · <?= e($o['email']) ?><?php endif; ?></p>
        </div>
        <?php if ($ship): ?>
        <div class="box">
            <h4>Shipped To</h4>
            <p><b><?= e($ship['name']) ?></b></p>
            <p><?= e($ship['addr']) ?></p>
            <p>Mobile: <?= e($ship['mobile']) ?></p>
        </div>
        <?php endif; ?>
        <div class="box">
            <h4>Payment &amp; Status</h4>
            <p>Payment Mode: <b><?= e($inv['labels']['pm']) ?></b></p>
            <p>Payment: <span class="inv-status <?= e($o['payment_status']) ?>"><?= e($inv['labels']['pay']) ?></span>
               &nbsp; Order: <span class="inv-status <?= e($o['status']) ?>"><?= e($inv['labels']['status']) ?></span></p>
            <p>Order Date: <?= e(dmy($o['created_at'], true)) ?></p>
        </div>
    </div>

    <table class="inv-items">
        <thead>
            <tr><th style="width:26px">#</th><th>Product</th><th class="r">MRP</th><th class="r">DP</th><th class="r">BV</th><th class="r">Qty</th><th class="r">DP Total</th></tr>
        </thead>
        <tbody>
        <?php $i = 0; foreach ($inv['items'] as $it): $i++; ?>
            <tr>
                <td><?= $i ?></td>
                <td><b><?= e($it['product_name']) ?></b></td>
                <td class="r"><?= money($it['mrp']) ?></td>
                <td class="r"><?= money($it['price']) ?></td>
                <td class="r"><?= number_format((float)$it['bv'], 0) ?></td>
                <td class="r"><?= (int)$it['qty'] ?></td>
                <td class="r"><?= money((float)$it['price'] * (int)$it['qty']) ?></td>
            </tr>
        <?php endforeach; ?>
            <tr class="tot">
                <td colspan="2">Total (<?= $i ?> product<?= $i > 1 ? 's' : '' ?>)</td>
                <td class="r"><?= money($t['mrp']) ?></td>
                <td class="r">—</td>
                <td class="r"><?= number_format($t['bv'], 0) ?></td>
                <td class="r"><?= (int)$t['qty'] ?></td>
                <td class="r"><?= money($t['dp']) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="inv-sum">
        <div class="inv-words">
            <b>Amount in words (Total DP Payable)</b>
            <?= e(invoice_words($t['dp'])) ?>
        </div>
        <div class="inv-tots">
            <table>
                <tr><td class="lbl">Total MRP</td><td class="r"><?= money($t['mrp']) ?></td></tr>
                <tr><td class="lbl">Distributor Savings (MRP &minus; DP)</td><td class="r">&minus; <?= money($t['savings']) ?></td></tr>
                <tr><td class="lbl">Total BV Earned</td><td class="r"><?= number_format($t['bv'], 0) ?> BV</td></tr>
                <tr class="grand"><td>Total Payable (DP)</td><td class="r"><?= money($t['dp']) ?></td></tr>
            </table>
        </div>
    </div>

    <div class="inv-note">
        <b>Notes:</b> Prices are inclusive of applicable taxes. Distributor Price (DP) is the net payable amount;
        Business Volume (BV) is the plan value credited on approval of this order. Goods once sold are subject to the
        company's return policy. This is a computer-generated invoice and does not require a physical signature.
    </div>
</div>
    <?php
    return (string)ob_get_clean();
}
