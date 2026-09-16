<?php
/** Payout requests — request withdrawal + history */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();
$plan = get_plan();

if (is_post() && post_str('action') === 'request') {
    verify_csrf();
    $amount = (float)post_str('amount');
    [$ok, $res] = request_payout($u['id'], $amount);
    if ($ok) {
        flash('success', 'Payout request submitted. You will be notified once processed.');
    } else {
        flash('error', $res);
    }
    redirect('payout.php');
}

$total = (int)q_val("SELECT COUNT(*) FROM payouts WHERE user_id = ?", [$u['id']]);
[$per, $offset] = paginate($total, 15, $links);
$rows = q_all("SELECT * FROM payouts WHERE user_id = ? ORDER BY id DESC LIMIT $per OFFSET $offset", [$u['id']]);

$activeKey = 'payout';
$pageTitle = 'Payout Requests';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';

$tds = (float)$plan['tds_percent'];
$adminFee = (float)$plan['admin_charge_percent'];
?>

<div class="two-col">
    <div class="card">
        <div class="card-title">🏦 Request a Payout</div>
        <?php if ((float)$u['wallet_balance'] < (float)$plan['payout_min']): ?>
            <div class="alert alert-warning">
                You need at least <b><?= money($plan['payout_min']) ?></b> in your wallet to request a payout.
                Current balance: <b><?= money($u['wallet_balance']) ?></b>.
            </div>
        <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request">
            <div class="form-group">
                <label>Amount (available: <?= money($u['wallet_balance']) ?>)</label>
                <input class="form-control" type="number" step="0.01" min="<?= e($plan['payout_min']) ?>"
                       max="<?= e($u['wallet_balance']) ?>" name="amount" required
                       placeholder="Min <?= e($plan['payout_min']) ?>">
            </div>
            <table class="kv-table" style="width:100%;margin:12px 0">
                <tr><td>TDS (<?= e($tds) ?>%)</td><td id="tds-row">—</td></tr>
                <tr><td>Admin charge (<?= e($adminFee) ?>%)</td><td id="admin-row">—</td></tr>
                <tr><td><b>Net payable to bank</b></td><td id="net-row"><b>—</b></td></tr>
            </table>
            <p class="form-hint" style="margin-bottom:12px">
                Payout will be transferred to your registered bank account:
                <b><?= e($u['bank_name'] ?: '— not set —') ?></b>
                (<?= e(mask_acct($u['bank_account_no'])) ?>). Update it in your
                <a href="profile.php">profile</a> if needed.
            </p>
            <button class="btn btn-primary" type="submit">💸 Submit Payout Request</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title">ℹ️ Payout Rules</div>
        <table class="kv-table" style="width:100%">
            <tr><td>Minimum request</td><td><?= money($plan['payout_min']) ?></td></tr>
            <tr><td>TDS deduction</td><td><?= e($tds) ?>%</td></tr>
            <tr><td>Admin / processing charge</td><td><?= e($adminFee) ?>%</td></tr>
            <tr><td>Processing time</td><td>Within 3–5 working days</td></tr>
        </table>
        <p class="form-hint" style="margin-top:10px">
            Requested amount is immediately deducted (held) from your wallet. If a request is
            rejected, the amount is refunded to your wallet automatically.
        </p>
    </div>
</div>

<div class="card">
    <div class="card-title">📜 Payout History</div>
    <?php if (!$rows): ?>
        <div class="empty-state"><span class="es-ico">🏦</span>No payout requests yet.</div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table">
            <tr><th>Request No.</th><th>Date</th><th>Amount</th><th>TDS</th><th>Charge</th><th>Net</th><th>Status</th><th>Paid On</th></tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><b><?= e($r['request_no']) ?></b></td>
                <td><?= dmy($r['created_at']) ?></td>
                <td><?= money($r['amount']) ?></td>
                <td><?= money($r['tds_amount']) ?></td>
                <td><?= money($r['admin_charge']) ?></td>
                <td><b><?= money($r['net_amount']) ?></b></td>
                <td><?= status_badge($r['status']) ?></td>
                <td><?= $r['paid_at'] ? dmy($r['paid_at']) : ($r['reject_reason'] ? '<small style="color:#000">' . e($r['reject_reason']) . '</small>' : '—') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?= $links ?>
    <?php endif; ?>
</div>

<?php
$pageScripts = <<<JS
var amt = document.querySelector('input[name=amount]');
if (amt) {
    amt.addEventListener('input', function () {
        var v = parseFloat(this.value) || 0;
        var tds = Math.round(v * $tds / 100 * 100) / 100;
        var adm = Math.round(v * $adminFee / 100 * 100) / 100;
        document.getElementById('tds-row').textContent = '- ₹' + tds.toFixed(2);
        document.getElementById('admin-row').textContent = '- ₹' + adm.toFixed(2);
        document.getElementById('net-row').innerHTML = '<b>₹' + (v - tds - adm).toFixed(2) + '</b>';
    });
}
JS;
require __DIR__ . '/../includes/dash_footer.php';
