<?php
/** Payout → Reward Payout Report (15-day halves: 1st–15th, 16th–month end). */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/payout_lib.php';
$u = require_user();
$plan = get_plan();

$rows = payout_report_periods($u['id'], 'reward');
$map = payout_column_map('reward');

$tot = ['gross' => 0.0, 'tds' => 0.0, 'admin' => 0.0, 'net' => 0.0];
foreach ($map as [$pk]) { $tot[$pk] = 0.0; }
foreach ($rows as $r) {
    foreach ($map as [$pk]) { $tot[$pk] += $r['parts'][$pk]; }
    $tot['gross'] += $r['gross'];
    $tot['tds'] += $r['tds'];
    $tot['admin'] += $r['admin'];
    $tot['net'] += $r['net'];
}

/* current (running) 15-day half */
[$curFrom, $curTo] = payout_reward_bounds(date('Y-m-d'));

$activeKey = 'payout-rewards';
$pageTitle = 'Reward Payout Report';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';

$tdsPct = rtrim(rtrim(number_format((float)$plan['tds_percent'], 2, '.', ''), '0'), '.');
$admPct = rtrim(rtrim(number_format((float)$plan['admin_charge_percent'], 2, '.', ''), '0'), '.');
?>

<div class="card">
    <div class="card-title">
        🏆 Reward Payout Report (15 Days)
        <span class="right" style="font-size:11px;color:var(--ink-soft)">
            Periods: 1st–15th &amp; 16th–month end · TDS <?= e($tdsPct) ?>% + Admin Charges <?= e($admPct) ?>%
        </span>
    </div>
    <p class="form-hint" style="margin:4px 0 10px">
        Your reward income (cash rewards for rank achievements, car fund and royalty) for every completed
        15-day period. The running period (<b><?= e(dmy($curFrom)) ?> – <?= e(dmy($curTo)) ?></b>) closes on
        <?= e(dmy($curTo)) ?> and appears here afterwards. Click <b>Statement</b> for the payout statement of a period.
    </p>

    <?php if (!$rows): ?>
        <div class="empty-state">
            <span class="es-ico">🏆</span>
            <p>No reward payout yet. Rank rewards, car fund and royalty appear here as they are credited.</p>
            <a class="btn btn-primary mt-2" href="plan.php">See the Marketing Plan</a>
        </div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table table-dt">
            <thead>
                <tr>
                    <th>Sr.No.</th><th>From Date</th><th>To Date</th>
                    <?php foreach ($map as [, $label]): ?><th><?= e($label) ?></th><?php endforeach; ?>
                    <th>Total Amount</th>
                    <th>TDS (<?= e($tdsPct) ?>%)</th>
                    <th>Admin Charges (<?= e($admPct) ?>%)</th>
                    <th>Net Amount</th>
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
                            data-statement="<?= e($r['from'] . '|' . $r['to']) ?>" data-kind="reward"
                            data-label="Period <?= e(dmy($r['from']) . ' – ' . dmy($r['to'])) ?>">Statement</button>
                </td>
            </tr>
<?php endforeach; ?>
            </tbody>
            </table>
    </div>

    <div class="payout-tot">
        <span class="pt-lbl">Total (<?= count($rows) ?> period<?= count($rows) > 1 ? 's' : '' ?>)</span>
        <?php foreach ($map as [$pk, $label]): ?>
            <span><?= e($label) ?>: <b><?= money_txt($tot[$pk]) ?></b></span>
        <?php endforeach; ?>
        <span>Gross: <b><?= money_txt($tot['gross']) ?></b></span>
        <span>TDS: <b><?= money_txt($tot['tds']) ?></b></span>
        <span>Admin Charges: <b><?= money_txt($tot['admin']) ?></b></span>
        <span class="pt-net">Net Amount: <b><?= money_txt($tot['net']) ?></b></span>
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
