<?php
/** Weekly payout statement receipt — printable / downloadable by the member. */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

$statementId = (int)get_str('statement');
$st = payout_statement($statementId);
if (!$st) {
    flash('error', 'Payout statement not found.');
    redirect('payout.php');
}
/* own statement row only */
$mine = q_row(
    "SELECT * FROM payout_statement_rows WHERE statement_id = ? AND user_id = ?",
    [$st['id'], $u['id']]
);
if (!$mine) {
    flash('error', 'This payout statement does not belong to your account.');
    redirect('payout.php');
}
$plan = get_plan();

$activeKey = 'payout';
$pageTitle = 'Payout Receipt #' . (int)$st['id'];
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<style>
@media print{
    body *{visibility:hidden}
    #receipt, #receipt *{visibility:visible}
    #receipt{position:absolute;left:0;top:0;width:100%;border:none!important;box-shadow:none!important;margin:0!important;padding:0!important}
    #receipt .no-print{display:none!important}
}
</style>

<div class="card" id="receipt" style="max-width:860px;margin:0 auto">
    <div class="no-print" style="text-align:right;margin-bottom:10px">
        <a class="btn btn-outline btn-sm" href="payout.php">← Back to Payouts</a>
        <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">⬇ Download Receipt (Print / PDF)</button>
    </div>

    <div style="text-align:center;border-bottom:2px solid var(--line);padding-bottom:12px;margin-bottom:16px">
        <img src="<?= e(upload_url(setting('site_logo')) ?: url('assets/img/logo.png')) ?>" alt="Logo"
             style="height:72px;object-fit:contain;margin-bottom:8px">
        <h2 style="margin:0;font-size:21px">Yashasavi Veda Herbals Private Limited</h2>
        <div style="font-size:15px;font-weight:700;margin-top:6px;letter-spacing:.5px">WEEKLY PAYOUT STATEMENT</div>
        <div style="font-size:12.5px;color:var(--ink-soft);margin-top:4px">
            Week: <b><?= payout_statement_week_label($st) ?></b>
            &nbsp;·&nbsp; Generated: <b><?= dmy($st['run_ts'], true) ?></b>
            &nbsp;·&nbsp; Statement #<?= (int)$st['id'] ?>
            &nbsp;·&nbsp; Receipt of: <b><?= e($u['username']) ?></b>
        </div>
    </div>

    <table class="kv-table" style="width:100%;margin-bottom:16px">
        <tr><td style="width:30%">Member ID</td><td><b><?= e($u['username']) ?></b></td></tr>
        <tr><td>Member Name</td><td><b><?= e($u['full_name']) ?></b></td></tr>
        <tr><td>Mobile</td><td><?= e($u['mobile']) ?></td></tr>
        <tr><td>Bank Account</td><td><?= e($u['bank_name'] ?: '—') ?> · <?= e($u['bank_account_no'] ?: '—') ?> · <?= e($u['bank_ifsc'] ?: '—') ?></td></tr>
        <tr><td>KYC Status</td><td><?= $mine['kyc_status'] === 'verified' ? badge('Completed', 'success') : badge('Not completed', 'danger') ?></td></tr>
        <tr><td>Bank Details</td><td><?= (int)$mine['bank_ok'] ? badge('Completed', 'success') : badge('Not completed', 'danger') ?></td></tr>
    </table>

    <table class="table" style="width:100%">
        <tr><th>Description</th><th style="text-align:right">Amount</th></tr>
        <tr><td>Payout amount (gross eligible income for the week)</td><td style="text-align:right"><b><?= money($mine['gross']) ?></b></td></tr>
        <tr><td>TDS (<?= e($plan['tds_percent']) ?>%)</td><td style="text-align:right">− <?= money($mine['tds']) ?></td></tr>
        <tr><td>Admin / processing charge (<?= e($plan['admin_charge_percent']) ?>%)</td><td style="text-align:right">− <?= money($mine['admin_charge']) ?></td></tr>
        <tr style="background:var(--soft,#f6f8f4)"><th>NET PAYABLE</th><th style="text-align:right"><?= money($mine['net']) ?></th></tr>
    </table>

    <div style="margin-top:16px">
        <?php if ((int)$mine['payout_done']): ?>
            <?= badge('✔ Payout will be done — transferred to your registered bank account', 'success') ?>
        <?php else: ?>
            <?= badge('✖ Payout will not be done', 'danger') ?>
            <div style="margin-top:6px;font-size:13px"><?= e($mine['reason']) ?>.
                The amount stays in your wallet and is included again once your details are completed.</div>
        <?php endif; ?>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:46px">
        <div style="font-size:12px;color:var(--ink-soft)">Computer generated receipt — no signature required.</div>
        <div style="text-align:center">
            <div style="border-top:1px solid #000;padding-top:6px;min-width:200px;font-size:13px;font-weight:600">For Yashasavi Veda Herbals Private Limited</div>
            <div style="font-size:11.5px;color:var(--ink-soft)">Authorised Signatory</div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
