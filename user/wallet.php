<?php
/** E-wallet — balance + all transactions */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();

$type = get_str('type', '');
$where = "user_id = ?";
$params = [$u['id']];
if ($type === 'credit' || $type === 'debit') {
    $where .= " AND type = ?";
    $params[] = $type;
}
$total = (int)q_val("SELECT COUNT(*) FROM wallet_transactions WHERE $where", $params);
[$per, $offset] = paginate($total, 20, $links);
$rows = q_all("SELECT * FROM wallet_transactions WHERE $where ORDER BY id DESC LIMIT $per OFFSET $offset", $params);

$activeKey = 'wallet';
$pageTitle = 'My Wallet';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="stat-grid">
    <div class="stat-card"><div class="st-ico">👛</div><div><b><?= money($u['wallet_balance']) ?></b><span>Current Balance</span></div></div>
    <div class="stat-card gold"><div class="st-ico">💰</div><div><b><?= money($u['total_earned']) ?></b><span>Total Earned (all time)</span></div></div>
    <div class="stat-card red"><div class="st-ico">🏦</div><div><b><?= money($u['total_withdrawn']) ?></b><span>Total Withdrawn</span></div></div>
</div>

<div class="card">
    <div class="card-title">
        📜 Wallet Transactions
        <span class="right">
            <a class="btn <?= $type === '' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="wallet.php">All</a>
            <a class="btn <?= $type === 'credit' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="wallet.php?type=credit">Credits</a>
            <a class="btn <?= $type === 'debit' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="wallet.php?type=debit">Debits</a>
        </span>
    </div>

    <?php if (!$rows): ?>
        <div class="empty-state"><span class="es-ico">👛</span>No wallet transactions yet.</div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table">
            <tr><th>Date</th><th>Type</th><th>Amount</th><th>Balance After</th><th>Reference</th><th>Note</th></tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= dmy($r['created_at'], true) ?></td>
                <td><?= badge(ucfirst($r['type']), $r['type'] === 'credit' ? 'success' : 'danger') ?></td>
                <td style="font-weight:400;color:#000">
                    <?= $r['type'] === 'credit' ? '+' : '−' ?><?= money($r['amount']) ?></td>
                <td><?= money($r['balance_after']) ?></td>
                <td><?= badge(ucfirst($r['ref_type']), 'secondary') ?></td>
                <td><small style="color:#000"><?= e($r['note']) ?></small></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?= $links ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
