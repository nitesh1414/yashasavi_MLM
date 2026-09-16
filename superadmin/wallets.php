<?php
/** E-wallet overview + manual adjust */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

if (is_post() && post_str('action') === 'adjust') {
    verify_csrf();
    $userId = (int)post_str('user_id');
    $amount = round((float)post_str('amount'), 2);
    $direction = post_str('direction');
    $note = post_str('note') ?: 'Manual adjustment by admin';
    $target = q_row("SELECT * FROM users WHERE id = ?", [$userId]);
    if (!$target) {
        flash('error', 'User not found.');
    } elseif ($amount <= 0) {
        flash('error', 'Amount must be positive.');
    } else {
        try {
            db_tx(function () use ($target, $amount, $direction, $note, $userId) {
                if ($direction === 'debit') {
                    $newBal = debit_wallet($userId, $amount, 'admin', null, $note);
                    if ($newBal === null) {
                        throw new RuntimeException('User has insufficient wallet balance.');
                    }
                } else {
                    credit_wallet($userId, $amount, 'admin', null, $note);
                }
            });
            flash('success', 'Wallet ' . ($direction === 'debit' ? 'debited' : 'credited') . ' for ' . $target['username'] . '.');
        } catch (Throwable $tx) {
            flash('error', $tx->getMessage() ?: 'Adjustment failed.');
        }
    }
    redirect('wallets.php');
}

$q = get_str('q');
$type = get_str('type');
$where = "1=1";
$params = [];
if ($q !== '') {
    $where .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR t.note LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%");
}
if ($type === 'credit' || $type === 'debit') {
    $where .= " AND t.type = ?";
    $params[] = $type;
}
$total = (int)q_val("SELECT COUNT(*) FROM wallet_transactions t JOIN users u ON u.id = t.user_id WHERE $where", $params);
[$per, $offset] = paginate($total, 25, $links);
$rows = q_all("SELECT t.*, u.username, u.full_name FROM wallet_transactions t
               JOIN users u ON u.id = t.user_id
               WHERE $where ORDER BY t.id DESC LIMIT $per OFFSET $offset", $params);
$totalBalance = (float)q_val("SELECT COALESCE(SUM(wallet_balance),0) FROM users");

$activeKey = 'wallets';
$pageTitle = 'E-Wallets';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="stat-grid">
    <div class="stat-card gold"><div class="st-ico">👛</div><div><b><?= money($totalBalance) ?></b><span>Total Wallet Liability (all users)</span></div></div>
</div>

<div class="two-col">
    <div class="card">
        <div class="card-title">
            📜 Wallet Transactions
            <span class="right">
                <a class="btn <?= $type === '' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="wallets.php">All</a>
                <a class="btn <?= $type === 'credit' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="wallets.php?type=credit">Credits</a>
                <a class="btn <?= $type === 'debit' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="wallets.php?type=debit">Debits</a>
            </span>
        </div>
        <form method="get" class="filter-form">
            <?php if ($type): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
            <div class="form-group"><label>Search</label>
                <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="User or note"></div>
            <button class="btn btn-primary btn-sm" type="submit">Search</button>
        </form>
        <?php if (!$rows): ?>
            <div class="empty-state"><span class="es-ico">👛</span>No transactions found.</div>
        <?php else: ?>
        <div class="table-wrap" style="box-shadow:none">
            <table class="table">
                <tr><th>ID</th><th>Date</th><th>User</th><th>Type</th><th>Amount</th><th>After</th><th>Note</th></tr>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td>#<?= (int)$r['id'] ?></td>
                    <td><?= dmy($r['created_at'], true) ?></td>
                    <td><a href="user_view.php?id=<?= (int)$r['user_id'] ?>"><b><?= e($r['username']) ?></b></a></td>
                    <td><?= badge(ucfirst($r['type']), $r['type'] === 'credit' ? 'success' : 'danger') ?></td>
                    <td style="font-weight:600"><?= $r['type'] === 'credit' ? '+' : '−' ?><?= money($r['amount']) ?></td>
                    <td><?= money($r['balance_after']) ?></td>
                    <td><small style="color:#000"><?= e($r['note']) ?></small></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?= $links ?>
        <?php endif; ?>
    </div>

    <div>
        <div class="card">
            <div class="card-title">🛠️ Manual Wallet Adjustment</div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="adjust">
                <div class="form-group">
                    <label>User ID (numeric id or YSH code)</label>
                    <?php $userOptions = q_all("SELECT id, username, full_name FROM users ORDER BY id LIMIT 500"); ?>
                    <select class="form-control" name="user_id" required>
                        <option value="">— select user —</option>
                        <?php foreach ($userOptions as $uo): ?>
                            <option value="<?= (int)$uo['id'] ?>"><?= e($uo['username'] . ' — ' . $uo['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-grid2">
                    <div class="form-group">
                        <label>Direction</label>
                        <select class="form-control" name="direction">
                            <option value="credit">Credit (add money)</option>
                            <option value="debit">Debit (remove money)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (₹)</label>
                        <input class="form-control" type="number" step="0.01" min="0.01" name="amount" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Note (visible to user)</label>
                    <input class="form-control" name="note" placeholder="e.g. Promotional bonus">
                </div>
                <button class="btn btn-primary" type="submit" data-confirm="Apply this wallet adjustment?">Apply Adjustment</button>
            </form>
        </div>

        <div class="card">
            <div class="card-title">🏆 Top Wallet Balances</div>
            <table class="table" style="width:100%">
                <tr><th>User</th><th>Balance</th></tr>
                <?php foreach (q_all("SELECT id, username, full_name, wallet_balance FROM users ORDER BY wallet_balance DESC LIMIT 8") as $t): ?>
                <tr>
                    <td><a href="user_view.php?id=<?= (int)$t['id'] ?>"><b><?= e($t['username']) ?></b></a><br>
                        <small style="color:#000"><?= e($t['full_name']) ?></small></td>
                    <td><b><?= money($t['wallet_balance']) ?></b></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
