<?php
/** Payout → My Balance Payout: payout ledger (weekly closings + payment
 *  releases), balance summary, payout request form and history. */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/payout_lib.php';
$u = require_user();
$plan = get_plan();

if (is_post() && post_str('action') === 'request') {
    verify_csrf();
    $amount = (float)post_str('amount');
    try {
        [$ok, $res] = request_payout($u['id'], $amount);
        if ($ok) {
            flash('success', 'Payout request submitted. You will be notified once processed.');
        } else {
            flash('error', $res);
        }
    } catch (Throwable $ex) {
        error_log('Payout request failed: ' . $ex->getMessage());
        flash('error', $ex instanceof PDOException ? 'Payout request failed; no funds were held.' : $ex->getMessage());
    }
    redirect('payout-balance.php');
}

$eligible = payout_eligible_amount($u['id']);   /* three plan incomes + rank rewards */
$bankComplete = trim((string)$u['bank_name']) !== '' && trim((string)$u['bank_account_no']) !== '' && trim((string)$u['bank_ifsc']) !== '';
$kycComplete = $u['kyc_status'] === 'verified';
$myStatements = member_payout_statements($u['id']);
$rows = q_all("SELECT * FROM payouts WHERE user_id = ? ORDER BY id DESC", [$u['id']]);

/* A gross entitlement view, distinct from the actual wallet ledger. */
$entitlementLedger = payout_entitlement_ledger($u['id']);
$ledger = $entitlementLedger['rows'];
$bal = $entitlementLedger['balance'];

$activeKey = 'payout-balance';
$pageTitle = 'My Balance Payout';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';

$tds = (float)$plan['tds_percent'];
$adminFee = (float)$plan['admin_charge_percent'];
?>

<div class="stat-grid">
    <div class="stat-card"><div class="st-ico">👛</div><div><b><?= money($u['wallet_balance']) ?></b><span>Wallet Balance<br>(gross income credited)</span></div></div>
    <div class="stat-card gold"><div class="st-ico">✅</div><div><b><?= money($eligible) ?></b><span>Eligible for Payout<br>(before TDS + charges)</span></div></div>
    <div class="stat-card teal"><div class="st-ico">⚖️</div><div><b><?= money($bal) ?></b><span>Gross entitlement balance<br>(before deductions)</span></div></div>
    <div class="stat-card red"><div class="st-ico">🏦</div><div><b><?= money($u['total_withdrawn']) ?></b><span>Total Withdrawn</span></div></div>
</div>

<?php if (!$kycComplete || !$bankComplete): ?>
<div class="alert alert-warning">
    ⚠️ <b>Your payout will not be done</b> until your details are completed:
    <?= !$kycComplete ? '<b>KYC not completed</b> (status: ' . e(ucfirst($u['kyc_status'])) . ')' : '' ?>
    <?= !$kycComplete && !$bankComplete ? ' · ' : '' ?>
    <?= !$bankComplete ? '<b>Bank details not completed</b>' : '' ?>
    — update them in your <a href="profile.php">profile</a>. Your income stays safe in your wallet and is paid once completed.
</div>
<?php endif; ?>

<div class="card">
    <div class="card-title">🧾 Gross Payout Entitlement</div>
    <p class="form-hint" style="margin:4px 0 10px">
        Eligible gross commissions and corrections, including the current period, less the gross amount of
        pending or paid requests. Rejected requests are excluded. This is a live eligibility view, not the
        wallet ledger; purchases and non-payout income can make the wallet different. A negative balance
        indicates prior payouts exceed current eligible income; the requestable amount is never below zero
        and is also limited by your wallet. Actual deductions are shown in payout history and receipts.
    </p>
    <?php if (!$ledger): ?>
        <div class="empty-state"><span class="es-ico">🧾</span>No eligible income or payout requests yet.</div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table table-dt">
            <thead>
                <tr><th>Tran Date</th><th>Description</th><th>CR</th><th>DR</th><th>Balance</th></tr>
            </thead>
            <tbody>
<?php foreach ($ledger as $l): ?>
            <tr>
                <td><?= e(dmy($l['date'])) ?></td>
                <td><?= e($l['desc']) ?></td>
                <td style="color:#176d2d"><?= $l['cr'] > 0 ? money($l['cr']) : '—' ?></td>
                <td style="color:#9c1f1f"><?= $l['dr'] > 0 ? money($l['dr']) : '—' ?></td>
                <td><b><?= money($l['bal']) ?></b></td>
            </tr>
<?php endforeach; ?>
            </tbody>
            </table>
    </div>
    <?php endif; ?>
</div>

<div class="two-col">
    <div class="card">
        <div class="card-title">🏦 Request a Payout</div>
        <p style="font-size:12.5px;color:var(--ink-soft)">
            Payable income = <b>Direct Sponsor Income + Matching Pair Income + Placement Child First-Match Bonus + Rank Rewards</b>;
            rank achievements are credited to your wallet and are payable.
        </p>
        <?php if ($eligible < (float)$plan['payout_min']): ?>
            <div class="alert alert-warning">
                You need at least <b><?= money($plan['payout_min']) ?></b> of eligible payout income to request a payout.
                Current eligible income: <b><?= money($eligible) ?></b> (wallet balance: <?= money($u['wallet_balance']) ?>).
            </div>
        <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request">
            <div class="form-group">
                <label>Amount (eligible: <?= money($eligible) ?>)</label>
                <input class="form-control" type="number" step="0.01" min="<?= e($plan['payout_min']) ?>"
                       max="<?= e(min($eligible, (float)$u['wallet_balance'])) ?>" name="amount" required
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
            <tr><td>Weekly payout statement</td><td>Generated by the company every <b>Monday</b> (income counted up to the last <b>Saturday 11:59 PM</b>)</td></tr>
        </table>
        <p class="form-hint" style="margin-top:10px">
            Requested amount is immediately deducted (held) from your wallet. If a request is
            rejected, the amount is refunded to your wallet automatically.
        </p>
    </div>
</div>

<div class="card">
    <div class="card-title">📊 My Weekly Payout Statements</div>
    <p class="form-hint" style="margin:6px 0 10px">The company generates the payout statement <b>every Monday</b>.
        It lists eligible members and the amounts calculated at generation. A created request remains pending
        until processed; its current status is shown below. For rows without a request, any shown deductions are
        illustrative and were not applied.</p>
    <?php if (!$myStatements): ?>
        <div class="empty-state"><span class="es-ico">📊</span>No payout statements yet. Your weekly statement appears here
            once the company generates the weekly payout report and you are payout-eligible.</div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table table-dt">
            <thead>
                <tr><th>Statement</th><th>Week</th><th>Generated</th><th>Your KYC</th><th>Your Bank</th><th>Gross</th><th>TDS</th><th>Admin</th><th>Net Payable</th><th>Payout Status</th><th>Receipt</th></tr>
            </thead>
            <tbody>
<?php foreach ($myStatements as $st): ?>
            <tr>
                <td><b>#<?= (int)$st['id'] ?></b></td>
                <td><?= payout_statement_week_label($st) ?></td>
                <td><?= dmy($st['run_ts'], true) ?></td>
                <td><?= $st['kyc_status'] === 'verified' ? badge('Completed', 'success') : badge('Not completed', 'danger') ?></td>
                <td><?= (int)$st['bank_ok'] ? badge('Completed', 'success') : badge('Not completed', 'danger') ?></td>
                <td><?= money($st['gross']) ?></td>
                <td><?= money($st['tds']) ?></td>
                <td><?= money($st['admin_charge']) ?></td>
                <td><b><?= money($st['net']) ?></b></td>
                <td>
                    <?php [$statusLabel, $statusStyle] = payout_statement_status($st); ?>
                    <?= badge($statusLabel, $statusStyle) ?>
                    <?php if (!(int)$st['payout_done'] && $st['reason']): ?><br><small style="color:#000"><?= e($st['reason']) ?></small><?php endif; ?>
                </td>
                <td><a class="btn btn-outline btn-sm" href="receipt.php?statement=<?= (int)$st['id'] ?>">⬇ Receipt</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-title">📜 Payout History</div>
    <?php if (!$rows): ?>
        <div class="empty-state"><span class="es-ico">🏦</span>No payout requests yet.</div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table table-dt">
            <thead>
                <tr><th>Request No.</th><th>Date</th><th>Amount</th><th>TDS</th><th>Charge</th><th>Net</th><th>Status</th><th>Paid On</th></tr>
            </thead>
            <tbody>
<?php foreach ($rows as $r): ?>
            <tr>
                <td><b><?= e($r['request_no']) ?></b></td>
                <td><?= dmy($r['created_at']) ?></td>
                <td><?= money($r['amount']) ?></td>
                <td><?= money($r['tds_amount']) ?></td>
                <td><?= money($r['admin_charge']) ?></td>
                <td><b><?= money($r['net_amount']) ?></b></td>
                <td><?= status_badge($r['status'] === 'paid' ? 'approved' : ($r['status'] === 'pending' ? 'pending' : 'rejected')) ?></td>
                <td><?= $r['paid_at'] ? dmy($r['paid_at'], true) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
