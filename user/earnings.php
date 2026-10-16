<?php
/** Earnings statement — all commissions */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

$type = get_str('type');
$where = "c.user_id = ?";
$params = [$u['id']];
if ($type !== '' && in_array($type, ['sponsor', 'binary', 'level', 'rank', 'sponsor_matching', 'car_fund', 'award', 'retail', 'other'], true)) {
    $where .= " AND c.type = ?";
    $params[] = $type;
}
$total = (int)q_val("SELECT COUNT(*) FROM commissions c WHERE $where", $params);
[$per, $offset] = paginate($total, 20, $links);
$rows = q_all("SELECT c.*, o.order_no FROM commissions c
               LEFT JOIN orders o ON o.id = c.order_id
               WHERE $where ORDER BY c.id DESC LIMIT $per OFFSET $offset", $params);

$earn = user_earnings_breakdown($u['id']);

$activeKey = 'earnings';
$pageTitle = 'Earnings Statement';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="stat-grid">
    <div class="stat-card"><div class="st-ico">🤝</div><div><b><?= money($earn['sponsor']) ?></b><span>Leg Completion (₹300 + ₹300)</span></div></div>
    <div class="stat-card teal"><div class="st-ico">💠</div><div><b><?= money($earn['binary']) ?></b><span>First Matching Pair (₹450)</span></div></div>
    <div class="stat-card blue"><div class="st-ico">⚖️</div><div><b><?= money($earn['sponsor_matching']) ?></b><span>50% Direct Sponsor Bonus (₹225)</span></div></div>
    <div class="stat-card gold"><div class="st-ico">🏅</div><div><b><?= money($earn['rank'] + $earn['award']) ?></b><span>Rank + Award Rewards</span></div></div>
</div>

<div class="card">
    <div class="card-title">
        💰 Commission Statement (gross income)
        <span class="right">
            <a class="btn <?= $type === '' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="earnings.php">All</a>
            <?php foreach (['sponsor', 'binary', 'level', 'rank', 'sponsor_matching', 'car_fund', 'award', 'retail', 'other'] as $t): ?>
                <a class="btn <?= $type === $t ? 'btn-primary' : 'btn-light' ?> btn-sm" href="earnings.php?type=<?= $t ?>"><?= ucfirst($t) ?></a>
            <?php endforeach; ?>
        </span>
    </div>

    <p class="form-hint" style="margin:6px 0 10px">Amounts below are <b>gross</b> plan income. TDS <?= e(get_plan()['tds_percent']) ?>% + Admin <?= e(get_plan()['admin_charge_percent']) ?>% are deducted when each income is credited to your wallet — the <a href="wallet.php">Wallet page</a> shows the net credits.</p>
    <?php if (!$rows): ?>
        <div class="empty-state"><span class="es-ico">💰</span>No commissions yet. Commissions appear here when orders in your team are approved.</div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table">
            <tr><th>Date</th><th>Type</th><th>Order</th><th>Level</th><th>BV</th><th>Amount</th><th>Note</th></tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= dmy($r['created_at'], true) ?></td>
                <td><?php $tLabels = ['sponsor' => 'Leg Bonus (L/R)', 'binary' => 'Matching', 'level' => 'SP Match', 'rank' => 'Cash Reward', 'sponsor_matching' => 'Sponsor Matching', 'car_fund' => 'Car Fund', 'award' => 'Award Reward', 'retail' => 'Retail', 'other' => 'Other']; ?>
                    <?= badge($tLabels[$r['type']] ?? ucfirst($r['type']), $r['type'] === 'binary' ? 'info' : ($r['type'] === 'rank' || $r['type'] === 'car_fund' || $r['type'] === 'award' ? 'warning' : 'primary')) ?></td>
                <td><?= e($r['order_no'] ?: '—') ?></td>
                <td><?php
                    if ($r['type'] === 'sponsor' && (int)$r['level'] === 1) { echo 'Left leg'; }
                    elseif ($r['type'] === 'sponsor' && (int)$r['level'] === 2) { echo 'Right leg'; }
                    elseif ($r['level']) { echo 'L' . (int)$r['level']; }
                    else { echo '—'; }
                ?></td>
                <td><?= e($r['bv']) ?></td>
                <td><b style="color:#000">+<?= money($r['amount']) ?></b></td>
                <td><small style="color:#000"><?= e($r['note']) ?></small></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?= $links ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
