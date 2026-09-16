<?php
/** Earnings statement — all commissions */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

$type = get_str('type');
$where = "c.user_id = ?";
$params = [$u['id']];
if ($type !== '' && in_array($type, ['sponsor', 'binary', 'level', 'rank'], true)) {
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
    <div class="stat-card"><div class="st-ico">🤝</div><div><b><?= money($earn['sponsor']) ?></b><span>Sponsor Bonus</span></div></div>
    <div class="stat-card teal"><div class="st-ico">💠</div><div><b><?= money($earn['binary']) ?></b><span>Binary Matching</span></div></div>
    <div class="stat-card blue"><div class="st-ico">📈</div><div><b><?= money($earn['level']) ?></b><span>Level Income</span></div></div>
    <div class="stat-card gold"><div class="st-ico">🏅</div><div><b><?= money($earn['rank']) ?></b><span>Rank Rewards</span></div></div>
</div>

<div class="card">
    <div class="card-title">
        💰 Commission Statement
        <span class="right">
            <a class="btn <?= $type === '' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="earnings.php">All</a>
            <?php foreach (['sponsor', 'binary', 'level', 'rank'] as $t): ?>
                <a class="btn <?= $type === $t ? 'btn-primary' : 'btn-light' ?> btn-sm" href="earnings.php?type=<?= $t ?>"><?= ucfirst($t) ?></a>
            <?php endforeach; ?>
        </span>
    </div>

    <?php if (!$rows): ?>
        <div class="empty-state"><span class="es-ico">💰</span>No commissions yet. Commissions appear here when orders in your team are approved.</div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table">
            <tr><th>Date</th><th>Type</th><th>Order</th><th>Level</th><th>BV</th><th>Amount</th><th>Note</th></tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= dmy($r['created_at'], true) ?></td>
                <td><?= badge(ucfirst($r['type']), $r['type'] === 'binary' ? 'info' : ($r['type'] === 'rank' ? 'warning' : 'primary')) ?></td>
                <td><?= e($r['order_no'] ?: '—') ?></td>
                <td><?= $r['level'] ? 'L' . (int)$r['level'] : '—' ?></td>
                <td><?= e($r['bv']) ?></td>
                <td><b style="color:#1b5e20">+<?= money($r['amount']) ?></b></td>
                <td><small style="color:#8d9c8d"><?= e($r['note']) ?></small></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?= $links ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
