<?php
/** Superadmin-only, two-step historical income reconciliation. */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/income_recalculation.php';
$a = require_superadmin();
set_time_limit(0);

if (is_post()) {
    verify_csrf();
    $saved = $_SESSION['income_recalc_preview'] ?? null;
    $postedHash = post_str('snapshot');
    if (!$saved || (int)$saved['admin_id'] !== (int)$a['id'] || time() - (int)$saved['created'] > 1800
        || !hash_equals((string)$saved['hash'], $postedHash) || post_str('confirm_word') !== 'RECALCULATE'
        || !isset($_POST['confirm_reviewed'])) {
        flash('error', 'Review the current preview, tick the confirmation and type RECALCULATE. Previews expire after 30 minutes.');
        redirect('income_recalculate.php');
    }
    unset($_SESSION['income_recalc_preview']);
    try {
        $result = income_apply_reconciliation($postedHash, (int)$a['id']);
        flash('success', 'Reconciliation #' . $result['run_id'] . ' completed. Original financial history was retained.');
        redirect('income_recalculation_run.php?id=' . $result['run_id']);
    } catch (Throwable $ex) {
        error_log('Income reconciliation failed: ' . $ex->getMessage());
        $msg = $ex instanceof PDOException ? 'The database could not complete the reconciliation; the transaction was rolled back. Review the server log and preview again.' : $ex->getMessage();
        flash('error', $msg);
        redirect('income_recalculate.php');
    }
}

$preview = null; $error = null; $runs = [];
try {
    $preview = income_preview_reconciliation();
    $_SESSION['income_recalc_preview'] = ['admin_id' => (int)$a['id'], 'hash' => $preview['hash'], 'created' => time()];
    $runs = q_all("SELECT id, admin_id, created_at, summary_json FROM income_recalc_runs ORDER BY id DESC LIMIT 20");
} catch (Throwable $ex) {
    unset($_SESSION['income_recalc_preview']);
    error_log('Income preview failed: ' . $ex->getMessage());
    $error = $ex instanceof PDOException ? 'The preview could not be loaded. Check DATABASE_CHANGES.sql and the server log.' : $ex->getMessage();
}
if ($preview && get_str('export') === '1') {
    $csv = [];
    foreach ($preview['rows'] as $row) {
        $b = $row['before']; $n = $row['after'];
        // Avoid spreadsheet formula injection in legacy member identifiers.
        $name = preg_match('/^[=+\-@]/', $row['username']) ? "'" . $row['username'] : $row['username'];
        $csv[] = [$row['id'], $name, $row['status'], $row['is_active'], $row['sponsor_id'], $row['placement_id'],
            $row['self_bv'], $row['approved_bv'], $row['self_bv_difference'], $b['left_bv'], $n['left_bv'],
            $b['right_bv'], $n['right_bv'], $b['pairs'], $n['pairs'], $b['points'], $n['points'],
            $b['earnings']['sponsor'], $n['earnings']['sponsor'], $row['adjustments']['sponsor'],
            $b['earnings']['binary'], $n['earnings']['binary'], $row['adjustments']['binary'],
            $b['earnings']['sponsor_matching'], $n['earnings']['sponsor_matching'], $row['adjustments']['sponsor_matching'],
            $b['earnings']['rank'], $n['earnings']['rank'], $row['adjustments']['rank'], $b['wallet'], $n['wallet'],
            $row['net_adjustment'], $row['ledger_difference'], $b['payout_taken'], $row['overpaid']];
    }
    output_csv('income-preview.csv', ['Member ID', 'Username', 'Status', 'Earning active', 'Sponsor ID', 'Placement parent ID',
        'Saved Self BV', 'Approved order BV', 'Self BV difference', 'Old left BV', 'New left BV', 'Old right BV', 'New right BV',
        'Old pairs', 'New pairs', 'Old points', 'New points', 'Old sponsor', 'New sponsor', 'Sponsor adjustment',
        'Old binary', 'New binary', 'Binary adjustment', 'Old child bonus', 'New child bonus', 'Child bonus adjustment',
        'Old rank', 'New rank', 'Rank adjustment', 'Old wallet', 'New wallet', 'Net adjustment', 'Wallet minus ledger',
        'Payouts taken', 'Payouts above new income'], $csv);
}
$activeKey = 'plan'; $pageTitle = 'Review Historical Income & BV';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card">
    <div class="card-title">🔍 Historical income &amp; BV preview <span class="right"><a class="btn btn-light btn-sm" href="plan.php">← Plan settings</a></span></div>
    <p>Review the projected changes below. <b>Opening this page does not change any data.</b> Applying it is a separate, superadmin-only action.</p>
    <ul style="font-size:13px;line-height:1.7;padding-left:20px">
        <li>Calculates with each member's <b>saved Self BV</b>, their <b>current</b> placement and sponsor, current plan and current rank settings. Self BV is never overwritten.</li>
        <li>One sponsor income per qualifying referral, every eligible matching pair, and one first-match bonus per immediate placement child; points and rank rewards are recalculated too.</li>
        <li>Manual activation remains an eligibility override; blocked members do not earn under the current snapshot. Deferred income waits until the recipient becomes eligible.</li>
        <li>Original orders, profiles, placements, sponsors, commissions, wallet entries and payout records are retained. Adjustments are dated when applied, not backdated. Other income types are unchanged.</li>
        <li>This is a current-state projection, not a chronological replay: it cannot reconstruct prior placements or prior plan rates from saved Self BV. Legacy daily/monthly caps are not enforced by this engine.</li>
        <li>Wallet and total-earned amounts cannot be made negative. Shortfalls, invalid trees or other blocking issues stop the <b>entire</b> operation. No partial recalculation is applied.</li>
    </ul>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
</div>

<?php if ($preview): $s = $preview['summary']; ?>
<?php if (!$preview['already_initialized']): ?>
<div class="alert alert-warning"><b>Initial migration:</b> income processing is paused on this existing database until a superadmin has reviewed and applied this preview. See DATABASE_CHANGES.sql for the deployment procedure.</div>
<?php endif; ?>
<div class="stat-grid">
    <div class="stat-card"><div class="st-ico">👥</div><div><b><?= number_format($s['members']) ?></b><span>Members reviewed</span></div></div>
    <div class="stat-card gold"><div class="st-ico">🔎</div><div><b><?= number_format($s['self_bv_discrepancies']) ?></b><span>Self BV / approved order differences</span></div></div>
    <div class="stat-card teal"><div class="st-ico">➕</div><div><b><?= money($s['credits']) ?></b><span>Gross adjustment credits</span></div></div>
    <div class="stat-card red"><div class="st-ico">➖</div><div><b><?= money($s['debits']) ?></b><span>Gross adjustment debits</span></div></div>
</div>
<div class="card">
    <p><b>Net wallet change:</b> <?= money($s['net']) ?> · <b>Members with derived changes:</b> <?= number_format($s['changed_members']) ?> ·
       <b>Wallet/ledger differences:</b> <?= number_format($s['ledger_discrepancies']) ?> ·
       <b>Already-paid amounts above new earnings:</b> <?= number_format($s['overpaid_members']) ?> member(s).</p>
    <p class="form-hint">A wallet/ledger difference is shown for review; the existing wallet balance is the starting balance and is not rebuilt from the ledger.
       Existing payouts are never altered. If paid/pending payouts exceed projected earnings, future eligible income first offsets that excess.</p>
    <?php if ($preview['errors']): ?>
    <div class="alert alert-danger"><b>Cannot apply: <?= count($preview['errors']) ?> blocking issue(s).</b>
        <ul style="margin:8px 0 0 18px"><?php foreach ($preview['errors'] as $problem): ?><li><?= e($problem) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
</div>
<div class="card">
    <div class="card-title">Member-by-member projection <span class="right"><a class="btn btn-light btn-sm" href="income_recalculate.php?export=1">⬇ Download fresh preview CSV</a></span></div>
    <p class="form-hint">Values show <b>current → projected</b>. Self BV and approved-order BV are displayed to two decimal places; differences are warnings, not replacements.</p>
    <div class="table-wrap"><table class="table table-dt">
        <thead><tr><th>Member / status</th><th>Saved Self / approved BV</th><th>Team BV L / R</th><th>Pairs / points</th><th>Sponsor income</th><th>Matching income</th><th>Child first-match bonus</th><th>Rank rewards</th><th>Wallet / net adjustment</th><th>Paid or pending payouts</th></tr></thead>
        <tbody>
        <?php foreach ($preview['rows'] as $r): $b = $r['before']; $n = $r['after']; ?>
        <tr>
            <td><a href="user_view.php?id=<?= (int)$r['id'] ?>"><b><?= e($r['username']) ?></b></a><br><small><?= e(ucfirst($r['status'])) ?> · <?= $r['is_active'] ? 'manual/earning active' : 'not manually active' ?></small></td>
            <td><?= number_format($r['self_bv'], 2) ?> / <?= number_format($r['approved_bv'], 2) ?>
                <?php if ($r['self_bv_difference'] != 0.0): ?><br><b style="color:#b45309">Difference <?= number_format($r['self_bv_difference'], 2) ?></b><?php endif; ?></td>
            <td><?= number_format($b['left_bv'], 2) ?> / <?= number_format($b['right_bv'], 2) ?><br>→ <?= number_format($n['left_bv'], 2) ?> / <?= number_format($n['right_bv'], 2) ?></td>
            <td><?= number_format($b['pairs']) ?> / <?= number_format($b['points']) ?><br>→ <?= number_format($n['pairs']) ?> / <?= number_format($n['points']) ?></td>
            <?php foreach (['sponsor', 'binary', 'sponsor_matching', 'rank'] as $t): ?>
            <td><?= money($b['earnings'][$t]) ?><br>→ <?= money($n['earnings'][$t]) ?><br><small>Δ <?= money($r['adjustments'][$t]) ?></small></td>
            <?php endforeach; ?>
            <td><?= money($b['wallet']) ?> → <?= money($n['wallet']) ?><br><b>Δ <?= money($r['net_adjustment']) ?></b>
                <?php if ($r['ledger_difference'] != 0.0): ?><br><small style="color:#b45309">Wallet / ledger Δ <?= money($r['ledger_difference']) ?></small><?php endif; ?></td>
            <td><?= money($b['payout_taken']) ?><?php if ($r['overpaid'] > 0): ?><br><small style="color:#b45309">Above new income: <?= money($r['overpaid']) ?></small><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php if (!$preview['errors']): ?>
<div class="card" style="border:1px solid #e4a640">
    <div class="card-title">Confirm reviewed reconciliation</div>
    <p>Applying this preview can credit or debit member wallets. A database transaction re-checks the complete input snapshot; if data changed, it cancels and asks you to review again.</p>
    <form method="post" data-confirm="Apply the reviewed historical income and BV adjustments to ALL members? This can change wallet balances.">
        <?= csrf_field() ?><input type="hidden" name="snapshot" value="<?= e($preview['hash']) ?>">
        <label><input type="checkbox" name="confirm_reviewed" value="1" required> I reviewed the member changes, discrepancies and wallet adjustments and have a verified backup.</label>
        <div class="form-group" style="margin-top:12px;max-width:320px"><label>Type RECALCULATE to confirm</label><input class="form-control" name="confirm_word" autocomplete="off" required></div>
        <button class="btn btn-primary" type="submit">Apply this reviewed preview</button>
    </form>
</div>
<?php endif; ?>
<div class="card"><div class="card-title">Recent reconciliation audit runs</div>
    <?php if (!$runs): ?><p class="form-hint">No reconciliations have been applied yet.</p><?php else: ?>
    <table class="table"><thead><tr><th>Run</th><th>Applied</th><th>Superadmin ID</th><th>Members</th><th>Net adjustment</th></tr></thead><tbody>
    <?php foreach ($runs as $run): $stats = json_decode($run['summary_json'], true) ?: []; ?>
        <tr><td><a href="income_recalculation_run.php?id=<?= (int)$run['id'] ?>">#<?= (int)$run['id'] ?></a></td><td><?= e(dmy($run['created_at'], true)) ?></td><td><?= (int)$run['admin_id'] ?></td><td><?= (int)($stats['members'] ?? 0) ?></td><td><?= money($stats['net'] ?? 0) ?></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
