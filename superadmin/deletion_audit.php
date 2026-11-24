<?php
/** Financially safe member-removal audit, visible only to superadmins. */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();
$events = q_all("SELECT d.*, u.username, u.full_name, a.username AS actor
                 FROM member_deletion_audit d LEFT JOIN users u ON u.id = d.user_id
                 LEFT JOIN admins a ON a.id = d.admin_id ORDER BY d.id DESC LIMIT 500");
$activeKey = 'users'; $pageTitle = 'Deleted Member Audit';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>
<div class="card">
    <div class="card-title"><b>Deleted member audit</b><span class="right"><a class="btn btn-light btn-sm" href="users.php">← Distributors</a></span></div>
    <p class="form-hint">Archived accounts are removed from the live tree but their financial records remain intact.</p>
    <?php if (!$events): ?><div class="empty-state">No members have been deleted.</div><?php else: ?>
    <?php foreach ($events as $event): $data = json_decode($event['changes_json'], true) ?: []; ?>
    <details style="border:1px solid #e2e9e1;border-radius:10px;padding:12px;margin-top:10px">
        <summary style="cursor:pointer"><b><?= e($event['username'] ?: ('#' . $event['user_id'])) ?></b> — <?= e($event['full_name']) ?> · <?= dmy($event['created_at'], true) ?> · <?= e($event['actor'] ?: 'superadmin') ?></summary>
        <p class="form-hint" style="margin-top:8px">Promoted member ID: <?= (int)($data['promoted_id'] ?? 0) ?: 'none' ?> · grafted branch ID: <?= (int)($data['grafted_id'] ?? 0) ?: 'none' ?>
            · <?= count($data['moves'] ?? []) ?> placement path(s) · <?= count($data['sponsor_changes'] ?? []) ?> sponsor change(s) · <?= count($data['bv_changes'] ?? []) ?> BV change(s).</p>
        <p class="form-hint">Orders retained: <?= (int)($data['orders_preserved'] ?? 0) ?> · Payouts retained: <?= (int)($data['payouts_preserved'] ?? 0) ?>
            · Wallet retained: <?= money($data['wallet_balance_preserved'] ?? 0) ?> · Cancelled pending sponsor bonuses: <?= count($data['cancelled_pending_bonuses'] ?? []) ?>
            · Held first-pair sponsor bonuses no longer payable: <?= count($data['unpayable_pending_first_bonuses'] ?? []) ?>. <a href="user_view.php?id=<?= (int)$event['user_id'] ?>">View archived member</a></p>
        <?php if (!empty($data['moves'])): ?><div class="table-wrap" style="box-shadow:none;margin-top:8px"><table class="table"><thead><tr><th>Member ID</th><th>Old ancestry</th><th>New ancestry</th></tr></thead><tbody>
        <?php foreach ($data['moves'] as $m): ?><tr><td><?= (int)$m['user_id'] ?></td><td><?= e($m['old_path']) ?></td><td><?= e($m['new_path']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
        <?php if (!empty($data['sponsor_changes'])): ?><div class="table-wrap" style="box-shadow:none;margin-top:8px"><table class="table"><thead><tr><th>Referral ID</th><th>New sponsor ID</th></tr></thead><tbody>
        <?php foreach ($data['sponsor_changes'] as $c): ?><tr><td><?= (int)$c['user_id'] ?></td><td><?= (int)$c['new_sponsor_id'] ?: 'None' ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </details>
    <?php endforeach; endif; ?>
</div>
<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
