<?php
/** Read-only record of one applied income reconciliation. */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();
$id = get_int('id');
$run = q_row("SELECT r.*, a.username AS admin_name FROM income_recalc_runs r LEFT JOIN admins a ON a.id = r.admin_id WHERE r.id = ?", [$id]);
if (!$run) { flash('error', 'Reconciliation run not found.'); redirect('income_recalculate.php'); }
$entries = q_all("SELECT e.*, u.username FROM income_recalc_entries e LEFT JOIN users u ON u.id = e.user_id WHERE e.run_id = ? ORDER BY e.user_id", [$id]);
$stats = json_decode($run['summary_json'], true) ?: [];
$recordedPlan = json_decode($run['plan_json'], true) ?: [];
if (get_str('export') === '1') {
    $csv = [];
    foreach ($entries as $entry) {
        $record = json_decode($entry['before_json'], true) ?: [];
        $before = $record['values'] ?? []; $after = json_decode($entry['after_json'], true) ?: [];
        $adjust = json_decode($entry['adjustment_json'], true) ?: []; $amounts = $adjust['amounts'] ?? [];
        $name = $record['username'] ?? ($entry['username'] ?: ('Member #' . $entry['user_id']));
        if (preg_match('/^[=+\-@]/', $name)) { $name = "'" . $name; }
        $ids = $adjust['commission_ids'] ?? [];
        $csv[] = [$entry['user_id'], $name, $record['self_bv'] ?? 0, $record['approved_bv'] ?? 0,
            $record['sponsor_id'] ?? '', $record['placement_id'] ?? '',
            $before['left_bv'] ?? 0, $after['left_bv'] ?? 0, $before['right_bv'] ?? 0, $after['right_bv'] ?? 0,
            $before['pairs'] ?? 0, $after['pairs'] ?? 0, $before['points'] ?? 0, $after['points'] ?? 0,
            $amounts['sponsor'] ?? 0, $amounts['binary'] ?? 0, $amounts['sponsor_matching'] ?? 0, $amounts['rank'] ?? 0,
            $before['wallet'] ?? 0, $after['wallet'] ?? 0, $adjust['net'] ?? 0, implode(' ', $ids)];
    }
    output_csv('income-reconciliation-' . $id . '.csv', ['Member ID', 'Username', 'Saved Self BV', 'Approved order BV',
        'Sponsor ID', 'Placement parent ID', 'Old left BV', 'New left BV', 'Old right BV', 'New right BV',
        'Old pairs', 'New pairs', 'Old points', 'New points', 'Sponsor adjustment', 'Binary adjustment', 'Child bonus adjustment',
        'Rank adjustment', 'Old wallet', 'New wallet', 'Net adjustment', 'Adjustment commission IDs'], $csv);
}
$activeKey = 'plan'; $pageTitle = 'Income Reconciliation Audit #' . $id;
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>
<div class="card">
    <div class="card-title">📋 Reconciliation audit #<?= (int)$id ?><span class="right"><a class="btn btn-light btn-sm" href="income_recalculate.php">← Preview &amp; runs</a></span></div>
    <p>Applied <?= e(dmy($run['created_at'], true)) ?> by <?= e($run['admin_name'] ?: ('admin #' . $run['admin_id'])) ?>.
       Original commissions, transactions and payouts remain in their ledgers. Adjustments reference this run in their notes.</p>
    <p><b>Members:</b> <?= (int)($stats['members'] ?? 0) ?> · <b>Credits:</b> <?= money($stats['credits'] ?? 0) ?> ·
       <b>Debits:</b> <?= money($stats['debits'] ?? 0) ?> · <b>Net:</b> <?= money($stats['net'] ?? 0) ?></p>
    <p class="form-hint">Snapshot SHA-256: <code><?= e($run['snapshot_hash']) ?></code> · Algorithm: <?= e($recordedPlan['algorithm'] ?? 'unknown') ?>.
       The complete saved plan, ranks and per-member before/after state are retained in the reconciliation audit tables.</p>
</div>
<div class="card">
    <div class="card-title">Member adjustments <span class="right"><a class="btn btn-light btn-sm" href="income_recalculation_run.php?id=<?= (int)$id ?>&amp;export=1">⬇ Download audit CSV</a></span></div>
    <div class="table-wrap"><table class="table table-dt"><thead><tr>
        <th>Member</th><th>Saved / approved Self BV</th><th>Team BV L / R</th><th>Pairs / points</th>
        <th>Sponsor Δ</th><th>Matching Δ</th><th>Child first-match Δ</th><th>Rank Δ</th><th>Wallet</th><th>Commission IDs</th>
    </tr></thead><tbody>
    <?php foreach ($entries as $entry):
        $beforeRecord = json_decode($entry['before_json'], true) ?: [];
        $before = $beforeRecord['values'] ?? [];
        $after = json_decode($entry['after_json'], true) ?: [];
        $adjust = json_decode($entry['adjustment_json'], true) ?: [];
        $d = $adjust['amounts'] ?? []; $ids = $adjust['commission_ids'] ?? [];
    ?>
    <tr>
        <td><b><?= e($beforeRecord['username'] ?? ($entry['username'] ?: ('Member #' . $entry['user_id']))) ?></b></td>
        <td><?= number_format((float)($beforeRecord['self_bv'] ?? 0), 2) ?> / <?= number_format((float)($beforeRecord['approved_bv'] ?? 0), 2) ?></td>
        <td><?= number_format((float)($before['left_bv'] ?? 0), 2) ?> / <?= number_format((float)($before['right_bv'] ?? 0), 2) ?><br>→ <?= number_format((float)($after['left_bv'] ?? 0), 2) ?> / <?= number_format((float)($after['right_bv'] ?? 0), 2) ?></td>
        <td><?= (int)($before['pairs'] ?? 0) ?> / <?= (int)($before['points'] ?? 0) ?><br>→ <?= (int)($after['pairs'] ?? 0) ?> / <?= (int)($after['points'] ?? 0) ?></td>
        <?php foreach (['sponsor', 'binary', 'sponsor_matching', 'rank'] as $type): ?><td><?= money($d[$type] ?? 0) ?></td><?php endforeach; ?>
        <td><?= money($before['wallet'] ?? 0) ?> → <?= money($after['wallet'] ?? 0) ?><br><b>Δ <?= money($adjust['net'] ?? 0) ?></b></td>
        <td><?= $ids ? e(implode(', ', array_map(function ($v) { return '#' . (int)$v; }, $ids))) : '—' ?></td>
    </tr>
    <?php endforeach; ?></tbody></table></div>
</div>
<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
