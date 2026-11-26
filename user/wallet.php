<?php
/** E-wallet — balance + all transactions */
require_once __DIR__ . '/../includes/init.php';
$u = require_user();
$wallet = weekly_wallet_snapshot($u['id']);
$u['wallet_balance'] = $wallet['wallet_balance'];
$u['total_earned'] = $wallet['total_earned'];
$u['total_withdrawn'] = $wallet['total_withdrawn'];
$reserved = $wallet['reserved'];
$spendable = $wallet['available'];

$type = get_str('type', '');
$where = "user_id = ?";
$params = [$u['id']];
if ($type === 'credit' || $type === 'debit') {
    $where .= " AND type = ?";
    $params[] = $type;
}
$total = (int)q_val("SELECT COUNT(*) FROM wallet_transactions WHERE $where", $params);
$rows = q_all("SELECT * FROM wallet_transactions WHERE $where ORDER BY id DESC", $params);

$activeKey = 'wallet';
$pageTitle = 'My Wallet';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="stat-grid">
    <div class="stat-card"><div class="st-ico">👛</div><div><b><?= money($u['wallet_balance']) ?></b><span>Wallet Balance<br>(includes reserved)</span></div></div>
    <div class="stat-card gold"><div class="st-ico">📅</div><div><b><?= money($reserved) ?></b><span>Reserved for weekly payouts</span></div></div>
    <div class="stat-card teal"><div class="st-ico">💳</div><div><b><?= money($spendable) ?></b><span>Available to spend</span></div></div>
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
        <table class="table table-dt">
            <thead>
                <tr><th>Date</th><th>Type</th><th>Amount</th><th>Balance After</th><th>Reference</th><th>Note</th></tr>
            </thead>
            <tbody>
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
            </tbody>
            </table>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
