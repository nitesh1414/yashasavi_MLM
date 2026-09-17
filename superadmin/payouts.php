<?php
/** Payout requests — approve / reject / mark paid */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $id = (int)post_str('id');
    if ($action === 'paid' || $action === 'reject') {
        [$ok, $msg] = process_payout($id, $action, $a['id'], post_str('reason'));
        $ok ? flash('success', $msg) : flash('error', $msg);
    }
    redirect('payouts.php' . (get_str('status') ? '?status=' . urlencode(get_str('status')) : ''));
}

$status = get_str('status');
$q = get_str('q');
$where = "1=1";
$params = [];
if ($status !== '') { $where .= " AND p.status = ?"; $params[] = $status; }
if ($q !== '') {
    $where .= " AND (p.request_no LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%");
}
$total = (int)q_val("SELECT COUNT(*) FROM payouts p JOIN users u ON u.id = p.user_id WHERE $where", $params);
$sumPending = (float)q_val("SELECT COALESCE(SUM(amount),0) FROM payouts p JOIN users u ON u.id=p.user_id WHERE p.status='pending'" . ($status === 'pending' ? " AND $where" : ''), $status === 'pending' ? $params : []);
$sumPaid = (float)q_val("SELECT COALESCE(SUM(amount),0) FROM payouts WHERE status='paid'");
[$per, $offset] = paginate($total, 20, $links);
$rows = q_all("SELECT p.*, u.username, u.full_name FROM payouts p
               JOIN users u ON u.id = p.user_id
               WHERE $where ORDER BY FIELD(p.status,'pending','paid','rejected'), p.id DESC
               LIMIT $per OFFSET $offset", $params);

$activeKey = 'payouts';
$pageTitle = 'Payouts';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="stat-grid">
    <div class="stat-card gold"><div class="st-ico">⏳</div><div><b><?= money($sumPending) ?></b><span>Pending Payout Amount</span></div></div>
    <div class="stat-card"><div class="st-ico">✅</div><div><b><?= money($sumPaid) ?></b><span>Total Paid Till Date</span></div></div>
</div>

<div class="card">
    <div class="card-title">
        🏦 Payout Requests
        <span class="right">
            <a class="btn <?= $status === '' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="payouts.php">All</a>
            <?php foreach (['pending', 'paid', 'rejected'] as $st): ?>
                <a class="btn <?= $status === $st ? 'btn-primary' : 'btn-light' ?> btn-sm" href="payouts.php?status=<?= $st ?>"><?= ucfirst($st) ?></a>
            <?php endforeach; ?>
            <a class="btn btn-outline btn-sm" href="payouts.php?export=1&<?= e(http_build_query(array_merge($_GET, ['status' => $status ?: 'pending']))) ?>">⬇ Export</a>
        </span>
    </div>

    <form method="get" class="filter-form">
        <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <div class="form-group"><label>Search</label>
            <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Request no, user"></div>
        <button class="btn btn-primary" type="submit">Search</button>
    </form>

    <?php if (!$rows): ?>
        <div class="empty-state"><span class="es-ico">🏦</span>No payout requests found.</div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none">
        <table class="table">
            <tr><th>Request</th><th>User</th><th>Bank</th><th>Amount</th><th>Deductions</th><th>Net</th><th>Status</th><th>Actions</th></tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><b><?= e($r['request_no']) ?></b><br><small style="color:#000"><?= dmy($r['created_at']) ?></small></td>
                <td><a href="user_view.php?id=<?= (int)$r['user_id'] ?>"><b><?= e($r['username']) ?></b></a><br>
                    <small style="color:#000"><?= e($r['full_name']) ?></small></td>
                <td><small><?= e($r['bank_name'] ?: '—') ?><br><?= e(mask_acct($r['bank_account_no'])) ?><br><?= e($r['bank_ifsc'] ?: '') ?></small></td>
                <td><?= money($r['amount']) ?></td>
                <td><small>TDS <?= money($r['tds_amount']) ?><br>Chg <?= money($r['admin_charge']) ?></small></td>
                <td><b><?= money($r['net_amount']) ?></b></td>
                <td>
                    <?= status_badge($r['status']) ?>
                    <?php if ($r['paid_at']): ?><br><small style="color:#000"><?= dmy($r['paid_at']) ?></small><?php endif; ?>
                    <?php if ($r['reject_reason']): ?><br><small style="color:#000"><?= e($r['reject_reason']) ?></small><?php endif; ?>
                </td>
                <td>
                    <?php if ($r['status'] === 'pending'): ?>
                    <div class="table-actions">
                        <form method="post" class="inline-form" data-confirm="Mark this payout as PAID?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="paid">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-primary btn-sm" type="submit">✔ Mark Paid</button>
                        </form>
                        <form method="post" class="inline-form" data-confirm="Reject and refund to wallet?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="reason" value="Rejected by admin">
                            <button class="btn btn-danger btn-sm" type="submit">Reject</button>
                        </form>
                    </div>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?= $links ?>
    <?php endif; ?>
</div>

<?php
if (get_str('export') === '1') {
    $expStatus = $status ?: 'pending';
    $all = q_all("SELECT p.request_no, u.username, u.full_name, p.amount, p.tds_amount, p.admin_charge,
                  p.net_amount, p.status, p.created_at, p.paid_at, p.bank_name, p.bank_account_no, p.bank_ifsc
                  FROM payouts p JOIN users u ON u.id = p.user_id WHERE p.status = ? ORDER BY p.id DESC", [$expStatus]);
    $out = [];
    foreach ($all as $r) {
        $out[] = [$r['request_no'], $r['username'], $r['full_name'], $r['bank_name'], $r['bank_account_no'],
                  $r['bank_ifsc'], $r['amount'], $r['tds_amount'], $r['admin_charge'], $r['net_amount'],
                  $r['status'], $r['created_at'], $r['paid_at']];
    }
    output_csv('payouts-' . $expStatus . '.csv',
        ['Request No', 'User ID', 'Name', 'Bank', 'Account No', 'IFSC', 'Amount', 'TDS', 'Charge', 'Net', 'Status', 'Requested', 'Paid'], $out);
}
require __DIR__ . '/../includes/dash_footer.php';
