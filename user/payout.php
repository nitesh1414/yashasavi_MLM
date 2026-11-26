<?php
/** Payout → Incentive Payout Report (weekly, Sunday → Saturday). */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/payout_lib.php';
$u = require_user();
$plan = get_plan();

$rows = payout_report_periods($u['id'], 'incentive');
$recentStatements = array_slice(member_payout_statements($u['id']), 0, 5);
$map = payout_column_map('incentive');

/* column totals across all weeks */
$tot = ['gross' => 0.0, 'tds' => 0.0, 'admin' => 0.0, 'net' => 0.0];
foreach ($map as [$pk]) { $tot[$pk] = 0.0; }
foreach ($rows as $r) {
    foreach ($map as [$pk]) { $tot[$pk] += $r['parts'][$pk]; }
    $tot['gross'] += $r['gross'];
    $tot['tds'] += $r['tds'];
    $tot['admin'] += $r['admin'];
    $tot['net'] += $r['net'];
}

/* current (running) week — shown as a hint, not as a row */
[$curFrom, $curTo] = payout_week_bounds(date('Y-m-d'));

$activeKey = 'payout';
$pageTitle = 'Incentive Payout Report';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';

$tdsPct = rtrim(rtrim(number_format((float)$plan['tds_percent'], 2, '.', ''), '0'), '.');
$admPct = rtrim(rtrim(number_format((float)$plan['admin_charge_percent'], 2, '.', ''), '0'), '.');
?>

<div class="card">
    <div class="card-title">📄 Your Weekly Payouts <a class="right" href="payout-balance.php#weekly-statements">View all →</a></div>
    <p class="form-hint">Actual report amounts may include eligible income carried forward from earlier weeks. Status is current; the income-by-week table below is an estimate, not a payment record.</p>
    <?php if (!$recentStatements): ?>
        <div class="empty-state">No weekly payout statements yet.</div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none"><table class="table">
        <thead><tr><th>Week</th><th>Gross at generation</th><th>Net at generation</th><th>Your payout status</th><th></th></tr></thead><tbody>
        <?php foreach ($recentStatements as $st): ?>
        <tr>
            <td><?= payout_statement_week_label($st) ?></td><td><?= money($st['gross']) ?></td><td><?= money($st['net']) ?></td>
            <td><?php [$label, $style] = payout_statement_status($st); ?><?= badge($label, $style) ?>
                <?php if (!empty($st['payout_reason']) || (!(int)$st['payout_done'] && $st['reason'])): ?><br><small><?= e($st['payout_reason'] ?: $st['reason']) ?></small><?php endif; ?></td>
            <td><a class="btn btn-outline btn-sm" href="receipt.php?statement=<?= (int)$st['id'] ?>">View</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-title">
        📅 Incentive Payout Report (Weekly)
        <span class="right" style="font-size:11px;color:var(--ink-soft)">
            Weeks run Sunday → Saturday · illustrative rates: TDS <?= e($tdsPct) ?>% + admin <?= e($admPct) ?>%
        </span>
    </div>
    <p class="form-hint" style="margin:4px 0 10px">
        Your incentive income for every completed week (Sunday to Saturday). The running week
        (<b><?= e(dmy($curFrom)) ?> – <?= e(dmy($curTo)) ?></b>) closes on Saturday and appears here afterwards.
        Deductions and net amounts below are <b>estimates using today's rates</b>, not records of what was paid;
        rates and payout timing can differ. See <a href="payout-balance.php">payout history and receipts</a> for
        recorded payout amounts. Click <b>Estimate</b> for a period breakdown.
    </p>

    <?php if (!$rows): ?>
        <div class="empty-state">
            <span class="es-ico">📅</span>
            <p>No incentive payout yet. Your weekly income appears here once your first completed week ends.</p>
            <a class="btn btn-primary mt-2" href="shop.php">Start Earning</a>
        </div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table table-dt">
            <thead>
                <tr>
                    <th>Sr.No.</th><th>From Date</th><th>To Date</th>
                    <?php foreach ($map as [, $label]): ?><th><?= e($label) ?></th><?php endforeach; ?>
                    <th>Total Amount</th>
                    <th>Est. TDS (<?= e($tdsPct) ?>%)</th>
                    <th>Est. Admin (<?= e($admPct) ?>%)</th>
                    <th>Est. Net</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
<?php $sr = 0; foreach ($rows as $r): $sr++; ?>
            <tr>
                <td><?= $sr ?></td>
                <td><?= e(dmy($r['from'])) ?></td>
                <td><?= e(dmy($r['to'])) ?></td>
                <?php foreach ($map as [$pk]): ?><td><?= money($r['parts'][$pk]) ?></td><?php endforeach; ?>
                <td><b><?= money($r['gross']) ?></b></td>
                <td><?= money($r['tds']) ?></td>
                <td><?= money($r['admin']) ?></td>
                <td><b><?= money($r['net']) ?></b></td>
                <td style="white-space:nowrap">
                    <button type="button" class="btn btn-primary btn-sm"
                            data-statement="<?= e($r['from'] . '|' . $r['to']) ?>" data-kind="incentive"
                            data-label="Week <?= e(dmy($r['from']) . ' – ' . dmy($r['to'])) ?>">Estimate</button>
                </td>
            </tr>
<?php endforeach; ?>
            </tbody>
            </table>
    </div>

    <div class="payout-tot">
        <span class="pt-lbl">Total (<?= count($rows) ?> week<?= count($rows) > 1 ? 's' : '' ?>)</span>
        <?php foreach ($map as [$pk, $label]): ?>
            <span><?= e($label) ?>: <b><?= money_txt($tot[$pk]) ?></b></span>
        <?php endforeach; ?>
        <span>Gross: <b><?= money_txt($tot['gross']) ?></b></span>
        <span>Est. TDS: <b><?= money_txt($tot['tds']) ?></b></span>
        <span>Est. Admin: <b><?= money_txt($tot['admin']) ?></b></span>
        <span class="pt-net">Est. Net: <b><?= money_txt($tot['net']) ?></b></span>
    </div>
    <?php endif; ?>
</div>

<style>
.payout-tot{display:flex;flex-wrap:wrap;gap:8px 20px;align-items:center;background:#f3f7f5;border:1px solid #d9e4dd;border-radius:10px;padding:10px 14px;margin-top:12px;font-size:12px;color:#243}
.payout-tot .pt-lbl{font-weight:700;color:#17433c;letter-spacing:.4px}
.payout-tot .pt-net{margin-left:auto;font-size:13px;color:#17433c}
@media (max-width:640px){.payout-tot .pt-net{margin-left:0}}
</style>

<?php require __DIR__ . '/../includes/statement_modal.php'; ?>
<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
