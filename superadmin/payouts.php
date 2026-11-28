<?php
/** Payout requests — approve / reject / mark paid */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

/** Protect spreadsheet exports from member-supplied formula cells. */
function payout_csv_rows(array $rows)
{
    foreach ($rows as &$row) {
        foreach ($row as &$cell) {
            if (is_string($cell) && preg_match('/^(?:[\t\r\n]|[\x00-\x20]*[=+\-@])/', $cell)) { $cell = "'" . $cell; }
        }
        unset($cell);
    }
    unset($row);
    return $rows;
}

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $id = (int)post_str('id');
    try {
        if ($action === 'paid' || $action === 'reject') {
            [$ok, $msg] = process_payout($id, $action, $a['id'], post_str('reason'));
            $ok ? flash('success', $msg) : flash('error', $msg);
        }
        if (in_array($action, ['report_in_process', 'report_paid', 'report_release'], true)) {
            $reportAction = substr($action, 7);
            [$ok, $msg] = process_weekly_payout_report($id, $reportAction, $a['id']);
            $ok ? flash('success', $msg) : flash('warning', $msg);
            redirect('payouts.php?statement=' . $id);
        }
        if ($action === 'generate') {
            // Manual catch-up can include the newly completed week on Sunday.
            $results = weekly_payout_run_due();
            $latest = weekly_latest_completed_saturday();
            $run = q_row("SELECT * FROM weekly_payout_runs WHERE period_end=?", [$latest]);
            if (!$results && $run) {
                flash('info', $run['status'] === 'legacy'
                    ? 'A legacy statement already exists for this week; it was left unchanged.'
                    : 'This weekly report has already been generated; no duplicate was created.');
            } elseif ($results) {
                $created = array_sum(array_map(function ($r) { return $r['outcome'] === 'created' ? 1 : 0; }, $results));
                flash('success', $created . ' weekly report(s) generated; existing legacy reports were left unchanged. No bank transfers were initiated.'
                    . (count($results) === 52 && !$run ? ' More weeks remain; run catch-up again.' : ''));
            } else {
                flash('warning', 'No weekly report is due. Check the scheduler history if a completed week is missing.');
            }
            $viewId = (int)($run['statement_id'] ?? 0);
            if (!$viewId && $results) { $viewId = (int)end($results)['statement_id']; }
            redirect('payouts.php' . ($viewId ? '?statement=' . $viewId : ''));
        }
    } catch (Throwable $ex) {
        error_log('Payout operation failed: ' . $ex->getMessage());
        flash('error', $ex instanceof PDOException ? 'Payout operation failed; no changes were saved.' : $ex->getMessage());
    }
    if (strpos($action, 'report_') === 0 && $id > 0) { redirect('payouts.php?statement=' . $id); }
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
                  $r['gross'], $r['tds'], $r['admin_charge'], $r['net'], payout_statement_status($r)[0],
                  $r['payout_reason'] ?: $r['reason']];
    }
    output_csv('payout-statement-' . date('Ymd', strtotime($statement['run_ts'])) . '.csv',
        ['User ID', 'Name', 'Mobile', 'Bank Name', 'Account No', 'IFSC', 'KYC', 'Bank Details',
         'Gross Amount', 'TDS', 'Admin Charge', 'Net at generation', 'Current payout status', 'Reason'], payout_csv_rows($out));
}
$recentStatements = q_all("SELECT s.*, w.status AS report_status FROM payout_statements s
                           LEFT JOIN weekly_payout_runs w ON w.statement_id=s.id ORDER BY s.period_date DESC, s.id DESC");
if (get_str('download') !== '' && $runTs !== '') {
    $out = [];
    foreach ($runRows as $r) {
        $out[] = [$r['request_no'], $r['username'], $r['full_name'], $r['mobile'],
                  $r['bank_name'], $r['bank_account_no'], $r['bank_ifsc'], $r['bank_holder'],
                  $r['amount'], $r['tds_amount'], $r['admin_charge'], $r['net_amount'],
                  payout_record_status($r)[0], $r['created_at'], $r['paid_at']];
    }
    output_csv('payout-report-' . date('Ymd', strtotime($runTs)) . '.csv',
        ['Request No', 'User ID', 'Name', 'Mobile', 'Bank Name', 'Account No', 'IFSC', 'Account Holder',
         'Gross Amount', 'TDS', 'Admin Charge', 'Net Payable', 'Status', 'Generated On', 'Paid On'], payout_csv_rows($out));
}

$status = get_str('status');
if (!in_array($status, ['', 'pending', 'paid', 'rejected'], true)) { $status = ''; }
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
$rows = q_all("SELECT p.*, u.username, u.full_name, wr.statement_id AS weekly_statement_id,
                      wr.status AS reservation_status, w.status AS report_status
               FROM payouts p JOIN users u ON u.id = p.user_id
               LEFT JOIN weekly_payout_reservations wr ON wr.payout_id=p.id
               LEFT JOIN weekly_payout_runs w ON w.statement_id=wr.statement_id
               WHERE $where ORDER BY FIELD(p.status,'pending','paid','rejected'), p.id DESC", $params);

if (get_str('export') === '1') {
    $expStatus = $status ?: 'pending';
    $all = q_all("SELECT p.*, u.username, u.full_name, wr.status AS reservation_status, w.status AS report_status
                  FROM payouts p JOIN users u ON u.id=p.user_id
                  LEFT JOIN weekly_payout_reservations wr ON wr.payout_id=p.id
                  LEFT JOIN weekly_payout_runs w ON w.statement_id=wr.statement_id
                  WHERE p.status=? ORDER BY p.id DESC", [$expStatus]);
    $out = [];
    foreach ($all as $r) {
        $out[] = [$r['request_no'], $r['username'], $r['full_name'], $r['bank_name'], $r['bank_account_no'],
                  $r['bank_ifsc'], $r['amount'], $r['tds_amount'], $r['admin_charge'], $r['net_amount'],
                  payout_record_status($r)[0], $r['created_at'], $r['paid_at']];
    }
    output_csv('payouts-' . $expStatus . '.csv',
        ['Request No', 'User ID', 'Name', 'Bank', 'Account No', 'IFSC', 'Amount', 'TDS', 'Charge', 'Net', 'Status', 'Requested', 'Paid'], payout_csv_rows($out));
}

$statementCounts = ['paid' => 0, 'reserved' => 0, 'unpaid' => 0, 'paid_gross' => 0.0, 'paid_net' => 0.0];
foreach ($statementRows as $r) {
    if ($r['payout_status'] === 'paid') {
        $statementCounts['paid']++;
        $statementCounts['paid_gross'] += (float)$r['gross'];
        $statementCounts['paid_net'] += (float)$r['net'];
    } elseif ($r['reservation_status'] === 'reserved' && $r['payout_status'] === 'pending') {
        $statementCounts['reserved']++;
    } else { $statementCounts['unpaid']++; }
}

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
    <div class="card-title">📅 Automatic Weekly Payout Reports</div>
    <p style="font-size:13px;color:var(--ink-soft)">
        The <b>Sunday → Saturday</b> week is generated automatically from Monday when an active superadmin logs in
        or refreshes a page (<?= e(APP_TIMEZONE) ?>). If no one visits on Monday, missed reports catch up on the next visit;
        no server cron is required. The button can also run a completed week manually from Sunday.
        Reports include older unpaid eligible income through Saturday. Eligible members with verified KYC,
        bank details and at least <?= money(get_plan()['payout_min']) ?> have funds <b>reserved, not deducted</b>.
        Unavailable amounts carry forward. Mark a report In process or Paid from its statement. Paid deducts the gross once
        and records the payment; <b>it does not send a bank transfer</b>. Sponsor, matching, placement first-match
        and rank income qualify. Recorded TDS and admin charges use the rates at generation.
    </p>
    <form method="post" class="filter-form" data-confirm="Generate any due weekly reports? Eligible amounts will be reserved without debiting wallets.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generate">
        <button class="btn btn-primary" type="submit">📅 Run Due Reports</button>
    </form>
</div>

<?php if ($statement): ?>
<div class="card no-print" style="border:1px solid var(--line)">
    <div class="card-title">Report #<?= (int)$statement['id'] ?> status
        <?php [$reportLabel, $reportStyle] = weekly_report_status($statement['report_status']); ?>
        <?= badge($reportLabel, $reportStyle) ?>
    </div>
    <?php if ($statement['report_status'] !== null): ?>
    <p class="form-hint">Currently <?= (int)$statementCounts['paid'] ?> paid · <?= (int)$statementCounts['reserved'] ?> reserved ·
        <?= (int)$statementCounts['unpaid'] ?> unpaid/released. Recorded paid gross <?= money($statementCounts['paid_gross']) ?>
        and net <?= money($statementCounts['paid_net']) ?>. Members without a payout remain unpaid even when a batch is marked Paid.</p>
    <?php if ($statement['report_status'] !== 'paid'): ?>
    <?php if ($statementCounts['reserved'] === 0): ?><p class="form-hint">There are no reservations to pay. Marking this report Paid finalizes the batch with 0 paid members; unpaid income carries forward.</p><?php endif; ?>
    <div class="table-actions">
        <?php if ($statement['report_status'] === 'unpaid'): ?>
        <form method="post" class="inline-form" data-confirm="Mark the report In process? Reserved funds remain in member wallets.">
            <?= csrf_field() ?><input type="hidden" name="action" value="report_in_process"><input type="hidden" name="id" value="<?= (int)$statement['id'] ?>">
            <button class="btn btn-outline btn-sm" type="submit">Mark In Process</button>
        </form>
        <?php endif; ?>
        <form method="post" class="inline-form" data-confirm="<?= $statementCounts['reserved'] ? 'Confirm payment has been arranged separately? This records Paid and debits all still-eligible reservations once. No bank transfer is sent.' : 'Finalize this report as Paid with no reserved payouts? Ineligible members remain unpaid; no bank transfer is sent.' ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="report_paid"><input type="hidden" name="id" value="<?= (int)$statement['id'] ?>">
            <button class="btn btn-primary btn-sm" type="submit">Mark Report Paid</button>
        </form>
        <?php if ($statementCounts['reserved'] > 0): ?>
        <form method="post" class="inline-form" data-confirm="Release ALL remaining reservations on this report without paying? Members remain unpaid, and their funds become available. Use this only when correcting the report or income.">
            <?= csrf_field() ?><input type="hidden" name="action" value="report_release"><input type="hidden" name="id" value="<?= (int)$statement['id'] ?>">
            <button class="btn btn-danger btn-sm" type="submit">Release Reservations (Unpaid)</button>
        </form>
        <?php endif; ?>
    </div>
    <?php else: ?>
        <p class="form-hint">This batch is final and cannot debit the same reservation again. Marking paid did not initiate a bank transfer.</p>
    <?php endif; ?>
    <?php else: ?>
        <p class="form-hint">Legacy report: requests were already debited when created and must be handled individually. Historical records are unchanged.</p>
    <?php endif; ?>
</div>
<style>
@media screen{
    /* Keep member IDs, bank data and status badges readable; scroll the
       statement rather than breaking a word into single letters. */
    #payoutStatement table{min-width:1100px}
    #payoutStatement td:nth-child(2){min-width:130px}
    #payoutStatement td:nth-child(3){min-width:115px}
    #payoutStatement td:last-child{min-width:170px}
    #payoutStatement .badge{max-width:none;white-space:nowrap;overflow-wrap:normal}
}
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
    <p class="form-hint no-print" style="margin-bottom:8px">Scroll horizontally to see all statement columns.</p>
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
                    <?php [$statusLabel, $statusStyle] = payout_statement_status($r); ?>
                    <?= badge($statusLabel, $statusStyle) ?>
                    <?php if (!empty($r['payout_reason']) || (!(int)$r['payout_done'] && $r['reason'])): ?>
                        <br><small style="color:#000"><?= e($r['payout_reason'] ?: $r['reason']) ?></small>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:var(--soft,#f6f8f6)">
                <th colspan="5">TOTAL (requests created at generation)</th>
                <th><?= money($statement['total_gross']) ?></th>
                <th><?= money($statement['total_tds']) ?></th>
                <th><?= money($statement['total_admin']) ?></th>
                <th><?= money($statement['total_net']) ?></th>
                <th><?= (int)$statement['members_done'] ?> created · <?= (int)$statement['members_blocked'] ?> not created</th>
            </tr>
        </table>
    </div>
    <p class="form-hint" style="margin-top:10px">
        Totals are the original requests/reservations at generation, not current paid totals. Individual statuses above are current.
        New reservations are not wallet debits until marked Paid; blocked or released amounts remain unpaid.
        Deductions on unpaid or released rows are illustrative/planned and have not been applied as payments. No bank transfer is initiated by this page.
    </p>
</div>
<?php endif; ?>

<?php if ($recentStatements && !$statement): ?>
<div class="card">
    <div class="card-title">📄 Weekly Payout Statements</div>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table table-dt">
            <thead>
                <tr><th>Statement</th><th>Week</th><th>Generated</th><th>Report status</th><th>Members</th><th>Gross at generation</th><th>Net at generation</th><th>Not created</th><th></th></tr>
            </thead>
            <tbody>
<?php foreach ($recentStatements as $st): ?>
            <tr>
                <td><b>#<?= (int)$st['id'] ?></b></td>
                <td><?= payout_statement_week_label($st) ?></td>
                <td><?= dmy($st['run_ts'], true) ?></td>
                <td><?php [$label, $style] = weekly_report_status($st['report_status']); ?><?= badge($label, $style) ?></td>
                <td><?= (int)$st['members_done'] + (int)$st['members_blocked'] ?></td>
                <td><?= money($st['total_gross']) ?></td>
                <td><b><?= money($st['total_net']) ?></b></td>
                <td><?= (int)$st['members_blocked'] ? badge((int)$st['members_blocked'] . ' not created', 'warning') : '—' ?></td>
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
                <td><?php [$label, $style] = payout_record_status($r); ?><?= badge($label, $style) ?></td>
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

    <p class="form-hint">The Pending filter includes weekly Unpaid and In process reservations. Weekly payouts are processed only as a report; legacy and manual requests remain individually managed.</p>
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
                    <?php [$label, $style] = payout_record_status($r); ?><?= badge($label, $style) ?>
                    <?php if ($r['paid_at']): ?><br><small style="color:#000"><?= dmy($r['paid_at']) ?></small><?php endif; ?>
                    <?php if ($r['reject_reason']): ?><br><small style="color:#000"><?= e($r['reject_reason']) ?></small><?php endif; ?>
                </td>
                <td>
                    <?php if ($r['reservation_status'] !== null): ?>
                        <a class="btn btn-outline btn-sm" href="payouts.php?statement=<?= (int)$r['weekly_statement_id'] ?>">View report</a>
                    <?php elseif ($r['status'] === 'pending'): ?>
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

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
