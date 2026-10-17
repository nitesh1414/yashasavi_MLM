<?php
/** Payout requests — approve / reject / mark paid */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $id = (int)post_str('id');
    if ($action === 'paid' || $action === 'reject') {
        [$ok, $msg] = process_payout($id, $action, $a['id'], post_str('reason'));
        $ok ? flash('success', $msg) : flash('error', $msg);
    }
    if ($action === 'generate') {
        /* weekly payout report + statement — income counted up to the last
         * Saturday 11:59 PM; generated only here at the Superadmin end */
        [$ok, $runTs, $st] = generate_payout_report();
        if (!$ok || (!$st['created'] && !$st['blocked'] && !$st['skipped'])) {
            flash('warning', 'Weekly payout report: no eligible members found (eligible income = Leg Completion + Matching Bonus + 50% Direct Sponsor Bonus, minimum ' . money_txt(get_plan()['payout_min']) . ').');
            redirect('payouts.php');
        }
        flash('success',
            'Weekly payout statement generated: ' . number_format($st['created']) . ' payout(s) '
            . money_txt($st['total']) . ' gross → ' . money_txt($st['net']) . ' net'
            . ($st['blocked'] ? ' · ' . number_format($st['blocked']) . ' member(s) listed as PAYOUT WILL NOT BE DONE (KYC / bank details incomplete)' : '')
            . ($st['skipped'] ? ' · ' . number_format($st['skipped']) . ' skipped (wallet below minimum)' : '')
            . '. Members now see the statement in their dashboard and can download the receipt.');
        redirect('payouts.php?statement=' . (int)$st['statement_id']);
    }
    redirect('payouts.php' . (get_str('status') ? '?status=' . urlencode(get_str('status')) : ''));
}

/* report run view / bank-report CSV download */
$runTs = get_str('run');
$runRows = [];
if ($runTs !== '') {
    $runRows = payout_report_rows($runTs);
}

/* payout statement view */
$statementId = (int)get_str('statement');
$statement = $statementId ? payout_statement($statementId) : null;
$statementRows = $statement ? payout_statement_rows($statement['id']) : [];
if ($statement && get_str('download') !== '') {
    $out = [];
    foreach ($statementRows as $r) {
        $out[] = [$r['username'], $r['full_name'], $r['mobile'],
                  $r['bank_name'], $r['bank_account_no'], $r['bank_ifsc'],
                  $r['kyc_status'], $r['bank_ok'] ? 'Completed' : 'Not completed',
                  $r['gross'], $r['tds'], $r['admin_charge'], $r['net'],
                  $r['payout_done'] ? 'Payout will be done' : ($r['reason'] ?: 'Payout will not be done')];
    }
    output_csv('payout-statement-' . date('Ymd', strtotime($statement['run_ts'])) . '.csv',
        ['User ID', 'Name', 'Mobile', 'Bank Name', 'Account No', 'IFSC', 'KYC', 'Bank Details',
         'Gross Amount', 'TDS', 'Admin Charge', 'Net Payable', 'Payout Status'], $out);
}
$recentStatements = q_all("SELECT * FROM payout_statements ORDER BY id DESC LIMIT 8");
if (get_str('download') !== '' && $runTs !== '') {
    $out = [];
    foreach ($runRows as $r) {
        $out[] = [$r['request_no'], $r['username'], $r['full_name'], $r['mobile'],
                  $r['bank_name'], $r['bank_account_no'], $r['bank_ifsc'], $r['bank_holder'],
                  $r['amount'], $r['tds_amount'], $r['admin_charge'], $r['net_amount'],
                  $r['status'], $r['created_at'], $r['paid_at']];
    }
    output_csv('payout-report-' . date('Ymd', strtotime($runTs)) . '.csv',
        ['Request No', 'User ID', 'Name', 'Mobile', 'Bank Name', 'Account No', 'IFSC', 'Account Holder',
         'Gross Amount', 'TDS', 'Admin Charge', 'Net Payable', 'Status', 'Generated On', 'Paid On'], $out);
}

$status = get_str('status');
$q = get_str('q');
$where = "1=1";
$params = [];
if ($status !== '') { $where .= " AND p.status = ?"; $params[] = $status; }
if ($q !== '') {
    $where .= " AND (p.request_no LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%");
}
$total = (int)q_val("SELECT COUNT(*) FROM payouts p JOIN users u ON u.id = p.user_id WHERE $where", $params);
$sumPending = (float)q_val("SELECT COALESCE(SUM(amount),0) FROM payouts p JOIN users u ON u.id=p.user_id WHERE p.status='pending'" . ($status === 'pending' ? " AND $where" : ''), $status === 'pending' ? $params : []);
$sumPaid = (float)q_val("SELECT COALESCE(SUM(amount),0) FROM payouts WHERE status='paid'");
$rows = q_all("SELECT p.*, u.username, u.full_name FROM payouts p
               JOIN users u ON u.id = p.user_id
               WHERE $where ORDER BY FIELD(p.status,'pending','paid','rejected'), p.id DESC
              ", $params);

$activeKey = 'payouts';
$pageTitle = 'Payouts';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="stat-grid">
    <div class="stat-card gold"><div class="st-ico">⏳</div><div><b><?= money($sumPending) ?></b><span>Pending Payout Amount</span></div></div>
    <div class="stat-card"><div class="st-ico">✅</div><div><b><?= money($sumPaid) ?></b><span>Total Paid Till Date</span></div></div>
</div>

<div class="card" style="border:1.5px dashed var(--accent)">
    <div class="card-title">📅 Generate Weekly Payout Report &amp; Statement</div>
    <p style="font-size:13px;color:var(--ink-soft)">
        Run this <b>every Monday</b>. Income is counted <b>up to the last Saturday 11:59 PM</b> — the completed
        <b>Sunday → Saturday</b> week. It creates the <b>weekly payout statement</b> listing every eligible
        member with amounts, KYC status and bank details. Members with <b>completed KYC + bank details</b>
        get a pending payout (wallet debited) — <b>payout will be done</b>. Members with incomplete KYC or
        bank details are listed as <b>payout will not be done</b> and keep their balance for the next week.
        Eligible income = <b>Leg Completion + Matching Bonus + 50% Direct Sponsor Bonus</b> only, minimum
        <?= money(get_plan()['payout_min']) ?>. <?= e(get_plan()['tds_percent']) ?>% TDS and
        <?= e(get_plan()['admin_charge_percent']) ?>% admin charge are deducted from the payout amount.
    </p>
    <form method="post" class="filter-form" data-confirm="Generate the weekly payout report? Pending payouts will be created for all eligible members with completed KYC + bank details.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generate">
        <button class="btn btn-primary" type="submit">📅 Generate Report &amp; Statement</button>
    </form>
</div>

<?php if ($statement): ?>
<style>
@media print{
    /* print ONLY the statement table area */
    body *{visibility:hidden}
    #payoutStatement, #payoutStatement *{visibility:visible}
    #payoutStatement{position:absolute;left:0;top:0;width:100%;border:none!important;box-shadow:none!important;padding:0!important;margin:0!important}
    #payoutStatement .no-print{display:none!important}
    #payoutStatement table{font-size:11px}
}
</style>
<div class="card print-statement" id="payoutStatement" style="border:1.5px solid var(--accent)">
    <div class="card-title no-print">
        📄 Payout Statement #<?= (int)$statement['id'] ?>
        <span class="right">
            <a class="btn btn-outline btn-sm" href="payouts.php?statement=<?= (int)$statement['id'] ?>&download=1">⬇ Download (CSV)</a>
            <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">🖨 Print Statement</button>
        </span>
    </div>
    <div style="text-align:center;border-bottom:2px solid var(--line);padding-bottom:12px;margin-bottom:14px">
        <img src="<?= e(upload_url(setting('site_logo')) ?: url('assets/img/logo.png')) ?>" alt="Logo"
             style="height:64px;object-fit:contain;margin-bottom:8px">
        <h2 style="margin:0;font-size:20px">Yashasavi Veda Herbals Private Limited</h2>
        <div style="font-size:15px;font-weight:700;margin-top:6px">WEEKLY PAYOUT STATEMENT</div>
        <div style="font-size:12.5px;color:var(--ink-soft);margin-top:4px">
            Week: <b><?= payout_statement_week_label($statement) ?></b>
            &nbsp;·&nbsp; Generated: <b><?= dmy($statement['run_ts'], true) ?></b>
            &nbsp;·&nbsp; Statement #<?= (int)$statement['id'] ?>
        </div>
    </div>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table">
            <tr>
                <th>#</th><th>Member</th><th>Bank Details</th><th>KYC</th><th>Bank</th>
                <th>Gross</th><th>TDS</th><th>Admin</th><th>Net Payable</th><th>Payout Status</th>
            </tr>
            <?php $i = 1; foreach ($statementRows as $r): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><b><?= e($r['username']) ?></b><br><small style="color:#000"><?= e($r['full_name']) ?><br><?= e($r['mobile']) ?></small></td>
                <td><small><?= e($r['bank_name'] ?: '—') ?><br><?= e($r['bank_account_no'] ?: '—') ?><br><?= e($r['bank_ifsc'] ?: '—') ?></small></td>
                <td><?= $r['kyc_status'] === 'verified' ? badge('Completed', 'success') : badge('Not completed', 'danger') ?></td>
                <td><?= (int)$r['bank_ok'] ? badge('Completed', 'success') : badge('Not completed', 'danger') ?></td>
                <td><?= money($r['gross']) ?></td>
                <td><?= money($r['tds']) ?></td>
                <td><?= money($r['admin_charge']) ?></td>
                <td><b><?= money($r['net']) ?></b></td>
                <td>
                    <?php if ((int)$r['payout_done']): ?>
                        <?= badge('✔ Payout will be done', 'success') ?>
                    <?php else: ?>
                        <?= badge('✖ Payout will not be done', 'danger') ?>
                        <br><small style="color:#000"><?= e($r['reason']) ?></small>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:var(--soft,#f6f8f6)">
                <th colspan="5">TOTAL (payouts done)</th>
                <th><?= money($statement['total_gross']) ?></th>
                <th><?= money($statement['total_tds']) ?></th>
                <th><?= money($statement['total_admin']) ?></th>
                <th><?= money($statement['total_net']) ?></th>
                <th><?= (int)$statement['members_done'] ?> done · <?= (int)$statement['members_blocked'] ?> not done</th>
            </tr>
        </table>
    </div>
    <p class="form-hint" style="margin-top:10px">
        <?= (int)$statement['members_done'] ?> member(s) with completed KYC &amp; bank details — payout will be done.
        <?= (int)$statement['members_blocked'] ? (int)$statement['members_blocked'] . ' member(s) — payout will not be done (complete KYC / bank details; the amount stays in the wallet for the next week).' : '' ?>
    </p>
</div>
<?php endif; ?>

<?php if ($recentStatements && !$statement): ?>
<div class="card">
    <div class="card-title">📄 Weekly Payout Statements</div>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table table-dt">
            <thead>
                <tr><th>Statement</th><th>Week</th><th>Generated</th><th>Members</th><th>Gross</th><th>Net Paid</th><th>Not done</th><th></th></tr>
            </thead>
            <tbody>
<?php foreach ($recentStatements as $st): ?>
            <tr>
                <td><b>#<?= (int)$st['id'] ?></b></td>
                <td><?= payout_statement_week_label($st) ?></td>
                <td><?= dmy($st['run_ts'], true) ?></td>
                <td><?= (int)$st['members_done'] + (int)$st['members_blocked'] ?></td>
                <td><?= money($st['total_gross']) ?></td>
                <td><b><?= money($st['total_net']) ?></b></td>
                <td><?= (int)$st['members_blocked'] ? badge((int)$st['members_blocked'] . ' will not be done', 'warning') : '—' ?></td>
                <td><a class="btn btn-outline btn-sm" href="payouts.php?statement=<?= (int)$st['id'] ?>">View Statement →</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
    </div>
</div>
<?php endif; ?>

<?php if ($runRows): ?>
<div class="card" style="border:1.5px solid var(--accent)">
    <div class="card-title">
        📄 Payout Report — <?= dmy($runTs, true) ?>
        <span class="right"><a class="btn btn-outline btn-sm" href="payouts.php?download=1&run=<?= e(urlencode($runTs)) ?>">⬇ Download Bank Report (CSV)</a></span>
    </div>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table table-dt">
            <thead>
                <tr><th>Request</th><th>User</th><th>Bank</th><th>Gross</th><th>TDS</th><th>Admin</th><th>Net Payable</th><th>Status</th></tr>
            </thead>
            <tbody>
<?php foreach ($runRows as $r): ?>
            <tr>
                <td><b><?= e($r['request_no']) ?></b></td>
                <td><b><?= e($r['username']) ?></b><br><small style="color:#000"><?= e($r['full_name']) ?> · <?= e($r['mobile']) ?></small></td>
                <td><small><?= e($r['bank_name'] ?: '—') ?><br><?= e($r['bank_account_no']) ?><br><?= e($r['bank_ifsc'] ?: '') ?></small></td>
                <td><?= money($r['amount']) ?></td>
                <td><?= money($r['tds_amount']) ?></td>
                <td><?= money($r['admin_charge']) ?></td>
                <td><b><?= money($r['net_amount']) ?></b></td>
                <td><?= status_badge($r['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-title">
        🏦 Payout Requests
        <span class="right">
            <a class="btn <?= $status === '' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="payouts.php">All</a>
            <?php foreach (['pending', 'paid', 'rejected'] as $st): ?>
                <a class="btn <?= $status === $st ? 'btn-primary' : 'btn-light' ?> btn-sm" href="payouts.php?status=<?= $st ?>"><?= ucfirst($st) ?></a>
            <?php endforeach; ?>
            <a class="btn btn-outline btn-sm" href="payouts.php?export=1&<?= e(http_build_query(array_merge($_GET, ['status' => $status ?: 'pending']))) ?>">⬇ Export</a>
        </span>
    </div>

    <form method="get" class="filter-form">
        <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <div class="form-group"><label>Search</label>
            <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Request no, user"></div>
        <button class="btn btn-primary" type="submit">Search</button>
    </form>

    <?php if (!$rows): ?>
        <div class="empty-state"><span class="es-ico">🏦</span>No payout requests found.</div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table table-dt">
            <thead>
                <tr><th>Request</th><th>User</th><th>Bank</th><th>Amount</th><th>Deductions</th><th>Net</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
<?php foreach ($rows as $r): ?>
            <tr>
                <td><b><?= e($r['request_no']) ?></b><br><small style="color:#000"><?= dmy($r['created_at']) ?></small></td>
                <td><a href="user_view.php?id=<?= (int)$r['user_id'] ?>"><b><?= e($r['username']) ?></b></a><br>
                    <small style="color:#000"><?= e($r['full_name']) ?></small></td>
                <td><small><?= e($r['bank_name'] ?: '—') ?><br><?= e(mask_acct($r['bank_account_no'])) ?><br><?= e($r['bank_ifsc'] ?: '') ?></small></td>
                <td><?= money($r['amount']) ?></td>
                <td><small>TDS <?= money($r['tds_amount']) ?><br>Chg <?= money($r['admin_charge']) ?></small></td>
                <td><b><?= money($r['net_amount']) ?></b></td>
                <td>
                    <?= status_badge($r['status']) ?>
                    <?php if ($r['paid_at']): ?><br><small style="color:#000"><?= dmy($r['paid_at']) ?></small><?php endif; ?>
                    <?php if ($r['reject_reason']): ?><br><small style="color:#000"><?= e($r['reject_reason']) ?></small><?php endif; ?>
                </td>
                <td>
                    <?php if ($r['status'] === 'pending'): ?>
                    <div class="table-actions">
                        <form method="post" class="inline-form" data-confirm="Mark this payout as PAID?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="paid">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-primary btn-sm" type="submit">✔ Mark Paid</button>
                        </form>
                        <form method="post" class="inline-form" data-confirm="Reject and refund to wallet?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="reason" value="Rejected by admin">
                            <button class="btn btn-danger btn-sm" type="submit">Reject</button>
                        </form>
                    </div>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
    </div>
    <?php endif; ?>
</div>

<?php
if (get_str('export') === '1') {
    $expStatus = $status ?: 'pending';
    $all = q_all("SELECT p.request_no, u.username, u.full_name, p.amount, p.tds_amount, p.admin_charge,
                  p.net_amount, p.status, p.created_at, p.paid_at, p.bank_name, p.bank_account_no, p.bank_ifsc
                  FROM payouts p JOIN users u ON u.id = p.user_id WHERE p.status = ? ORDER BY p.id DESC", [$expStatus]);
    $out = [];
    foreach ($all as $r) {
        $out[] = [$r['request_no'], $r['username'], $r['full_name'], $r['bank_name'], $r['bank_account_no'],
                  $r['bank_ifsc'], $r['amount'], $r['tds_amount'], $r['admin_charge'], $r['net_amount'],
                  $r['status'], $r['created_at'], $r['paid_at']];
    }
    output_csv('payouts-' . $expStatus . '.csv',
        ['Request No', 'User ID', 'Name', 'Bank', 'Account No', 'IFSC', 'Amount', 'TDS', 'Charge', 'Net', 'Status', 'Requested', 'Paid'], $out);
}
require __DIR__ . '/../includes/dash_footer.php';
