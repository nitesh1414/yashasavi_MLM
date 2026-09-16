<?php
/** All commissions with filters */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

$type = get_str('type');
$from = get_str('from');
$to = get_str('to');
$q = get_str('q');

$where = "1=1";
$params = [];
if ($type !== '' && in_array($type, ['sponsor', 'binary', 'level', 'rank'], true)) {
    $where .= " AND c.type = ?";
    $params[] = $type;
}
if ($from !== '') { $where .= " AND DATE(c.created_at) >= ?"; $params[] = $from; }
if ($to !== '') { $where .= " AND DATE(c.created_at) <= ?"; $params[] = $to; }
if ($q !== '') {
    $where .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR o.order_no LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%");
}
$total = (int)q_val("SELECT COUNT(*) FROM commissions c JOIN users u ON u.id = c.user_id
                     LEFT JOIN orders o ON o.id = c.order_id WHERE $where", $params);
$sum = (float)q_val("SELECT COALESCE(SUM(c.amount),0) FROM commissions c JOIN users u ON u.id = c.user_id
                     LEFT JOIN orders o ON o.id = c.order_id WHERE $where", $params);
[$per, $offset] = paginate($total, 25, $links);
$rows = q_all("SELECT c.*, u.username, u.full_name, o.order_no FROM commissions c
               JOIN users u ON u.id = c.user_id
               LEFT JOIN orders o ON o.id = c.order_id
               WHERE $where ORDER BY c.id DESC LIMIT $per OFFSET $offset", $params);

$activeKey = 'commissions';
$pageTitle = 'Commissions';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="stat-grid">
    <div class="stat-card"><div class="st-ico">🧾</div><div><b><?= $total ?></b><span>Commissions (filtered)</span></div></div>
    <div class="stat-card gold"><div class="st-ico">💰</div><div><b><?= money($sum) ?></b><span>Total Amount (filtered)</span></div></div>
</div>

<div class="card">
    <div class="card-title">
        💠 Commission Ledger
        <span class="right">
            <?php
            $qs = $_GET; unset($qs['type']);
            $base = '?' . http_build_query($qs);
            ?>
            <a class="btn <?= $type === '' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="<?= e($base) ?>">All</a>
            <?php foreach (['sponsor', 'binary', 'level', 'rank'] as $t): ?>
                <a class="btn <?= $type === $t ? 'btn-primary' : 'btn-light' ?> btn-sm" href="<?= e($base) ?>&type=<?= $t ?>"><?= ucfirst($t) ?></a>
            <?php endforeach; ?>
        </span>
    </div>

    <form method="get" class="filter-form">
        <?php if ($type): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
        <div class="form-group"><label>User / Order</label>
            <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="User ID, name or order no"></div>
        <div class="form-group"><label>From</label>
            <input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
        <div class="form-group"><label>To</label>
            <input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn btn-light" href="commissions.php">Clear</a>
        <a class="btn btn-outline" href="commissions.php?export=1&<?= e(http_build_query($_GET)) ?>">⬇ Export CSV</a>
    </form>

    <?php if (!$rows): ?>
        <div class="empty-state"><span class="es-ico">💠</span>No commissions found.</div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table">
            <tr><th>ID</th><th>Date</th><th>User</th><th>Type</th><th>Order</th><th>BV</th><th>Amount</th><th>Note</th></tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td>#<?= (int)$r['id'] ?></td>
                <td><?= dmy($r['created_at'], true) ?></td>
                <td><a href="user_view.php?id=<?= (int)$r['user_id'] ?>"><b><?= e($r['username']) ?></b></a><br>
                    <small style="color:#8d9c8d"><?= e($r['full_name']) ?></small></td>
                <td><?= badge(ucfirst($r['type']), $r['type'] === 'binary' ? 'info' : 'primary') ?><?= $r['level'] ? ' L' . (int)$r['level'] : '' ?></td>
                <td><?= e($r['order_no'] ?: '—') ?></td>
                <td><?= e($r['bv']) ?></td>
                <td><b><?= money($r['amount']) ?></b></td>
                <td><small style="color:#8d9c8d"><?= e($r['note']) ?></small></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?= $links ?>
    <?php endif; ?>
</div>

<?php
// CSV export
if (get_str('export') === '1') {
    $all = q_all("SELECT c.id, c.created_at, u.username, u.full_name, c.type, o.order_no, c.level, c.bv, c.amount, c.status
                  FROM commissions c JOIN users u ON u.id = c.user_id LEFT JOIN orders o ON o.id = c.order_id
                  WHERE $where ORDER BY c.id DESC", $params);
    $out = [];
    foreach ($all as $r) {
        $out[] = [$r['id'], $r['created_at'], $r['username'], $r['full_name'], $r['type'],
                  $r['order_no'], $r['level'], $r['bv'], $r['amount'], $r['status']];
    }
    output_csv('commissions.csv', ['ID', 'Date', 'User ID', 'Name', 'Type', 'Order', 'Level', 'BV', 'Amount', 'Status'], $out);
}
require __DIR__ . '/../includes/dash_footer.php';
