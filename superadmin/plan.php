<?php
/** MLM plan settings + level percentages */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

if (is_post()) {
    verify_csrf();
    $tab = post_str('tab', 'main');

    if ($tab === 'main') {
        $fields = [
            'activation_bv' => 'float',
            'sponsor_percent' => 'float',
            'pair_unit_bv' => 'float_pos',
            'binary_type' => 'enum:percent,fixed',
            'binary_value' => 'float_pos',
            'level_depth' => 'int:1:10',
            'daily_cap' => 'float',
            'carry_forward' => 'bool',
            'matching_requires_active' => 'bool',
            'level_requires_active' => 'bool',
            'tds_percent' => 'float',
            'admin_charge_percent' => 'float',
            'payout_min' => 'float_pos',
        ];
        $updates = [];
        $errors = [];
        foreach ($fields as $key => $rule) {
            $val = $_POST[$key] ?? null;
            if ($val === null) { continue; }
            if ($rule === 'bool') {
                $updates[$key] = isset($_POST[$key]) ? 1 : 0;
                continue;
            }
            if ($rule === 'float' || $rule === 'float_pos') {
                $val = (float)$val;
                if ($rule === 'float_pos' && $val <= 0) { $errors[] = ucfirst(str_replace('_', ' ', $key)) . ' must be greater than 0.'; continue; }
                $updates[$key] = $val;
            }
            if (strpos($rule, 'enum:') === 0) {
                $allowed = explode(',', substr($rule, 5));
                $updates[$key] = in_array($val, $allowed, true) ? $val : $allowed[0];
            }
            if (strpos($rule, 'int:') === 0) {
                [$_, $min, $max] = explode(':', $rule);
                $val = max((int)$min, min((int)$max, (int)$val));
                $updates[$key] = $val;
            }
        }
        if ($errors) {
            foreach ($errors as $er) { flash('error', $er); }
        } else {
            $sets = [];
            $params = [];
            foreach ($updates as $k => $v) {
                $sets[] = "$k = ?";
                $params[] = $v;
            }
            $sets[] = 'updated_at = ?';
            $params[] = now();
            $params[] = 1;
            q("UPDATE plan_settings SET " . implode(', ', $sets) . " WHERE id = 1", $params);
            flash('success', 'MLM plan settings saved.');
        }
        redirect('plan.php');
    }

    if ($tab === 'levels') {
        foreach (range(1, 10) as $ln) {
            $pct = max(0, min(100, (float)post_str('level_' . $ln, 0)));
            q("INSERT INTO plan_levels (level_no, percent) VALUES (?, ?)
               ON DUPLICATE KEY UPDATE percent = VALUES(percent)", [$ln, $pct]);
        }
        flash('success', 'Level income percentages saved.');
        redirect('plan.php?tab=levels');
    }
}

$cur = get_str('tab', 'main');
$plan = get_plan();
$levels = get_plan_levels();

$activeKey = 'plan';
$pageTitle = 'MLM Plan Settings';
require __DIR__ . '/_nav.php';
require __DIR__ . '/../includes/dash_header.php';
?>

<div class="card" style="max-width:900px">
    <div class="card-title">
        ⚙️ MLM Plan Settings
        <span class="right">
            <a class="btn <?= $cur === 'main' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="plan.php">Main Settings</a>
            <a class="btn <?= $cur === 'levels' ? 'btn-primary' : 'btn-light' ?> btn-sm" href="plan.php?tab=levels">Level Percentages</a>
        </span>
    </div>
    <p class="form-hint" style="margin-bottom:16px">
        Changes apply to <b>future</b> orders only. Last updated: <?= dmy($plan['updated_at'], true) ?>.
    </p>

    <?php if ($cur === 'main'): ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="tab" value="main">

        <h4 style="font-size:14px;margin:6px 0 12px">Activation</h4>
        <div class="form-grid2">
            <div class="form-group">
                <label>Activation BV (lifetime self-purchase to start earning)</label>
                <input class="form-control" type="number" step="0.01" name="activation_bv" value="<?= e($plan['activation_bv']) ?>">
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">Direct Sponsor Bonus</h4>
        <div class="form-grid2">
            <div class="form-group">
                <label>Sponsor Bonus (% of order BV)</label>
                <input class="form-control" type="number" step="0.01" name="sponsor_percent" value="<?= e($plan['sponsor_percent']) ?>">
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">Binary Matching Income</h4>
        <div class="form-grid3">
            <div class="form-group">
                <label>BV per pair unit (1:1 matching unit)</label>
                <input class="form-control" type="number" step="0.01" name="pair_unit_bv" value="<?= e($plan['pair_unit_bv']) ?>">
            </div>
            <div class="form-group">
                <label>Income Type</label>
                <select class="form-control" name="binary_type">
                    <option value="percent" <?= $plan['binary_type'] === 'percent' ? 'selected' : '' ?>>% of matched BV</option>
                    <option value="fixed" <?= $plan['binary_type'] === 'fixed' ? 'selected' : '' ?>>Fixed ₹ per pair</option>
                </select>
            </div>
            <div class="form-group">
                <label>Value (% or ₹)</label>
                <input class="form-control" type="number" step="0.01" name="binary_value" value="<?= e($plan['binary_value']) ?>">
            </div>
        </div>
        <div class="form-grid3">
            <div class="form-group">
                <label>Daily Cap (₹, 0 = no cap)</label>
                <input class="form-control" type="number" step="0.01" name="daily_cap" value="<?= e($plan['daily_cap']) ?>">
            </div>
            <div class="form-group">
                <label>Carry forward unmatched BV / pairs</label>
                <select class="form-control" name="carry_forward">
                    <option value="1" <?= (int)$plan['carry_forward'] === 1 ? 'selected' : '' ?>>Yes (recommended)</option>
                    <option value="0" <?= (int)$plan['carry_forward'] === 0 ? 'selected' : '' ?>>No (flush unmatched)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Matching requires active member</label>
                <select class="form-control" name="matching_requires_active">
                    <option value="1" <?= (int)$plan['matching_requires_active'] === 1 ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= (int)$plan['matching_requires_active'] === 0 ? 'selected' : '' ?>>No</option>
                </select>
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">Level Income</h4>
        <div class="form-grid3">
            <div class="form-group">
                <label>Paid levels (1–10)</label>
                <input class="form-control" type="number" min="1" max="10" name="level_depth" value="<?= e($plan['level_depth']) ?>">
            </div>
            <div class="form-group">
                <label>Level income requires active member</label>
                <select class="form-control" name="level_requires_active">
                    <option value="1" <?= (int)$plan['level_requires_active'] === 1 ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= (int)$plan['level_requires_active'] === 0 ? 'selected' : '' ?>>No</option>
                </select>
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">Payouts</h4>
        <div class="form-grid3">
            <div class="form-group">
                <label>Minimum payout (₹)</label>
                <input class="form-control" type="number" step="0.01" name="payout_min" value="<?= e($plan['payout_min']) ?>">
            </div>
            <div class="form-group">
                <label>TDS %</label>
                <input class="form-control" type="number" step="0.01" name="tds_percent" value="<?= e($plan['tds_percent']) ?>">
            </div>
            <div class="form-group">
                <label>Admin / processing charge %</label>
                <input class="form-control" type="number" step="0.01" name="admin_charge_percent" value="<?= e($plan['admin_charge_percent']) ?>">
            </div>
        </div>

        <button class="btn btn-primary" type="submit">💾 Save Plan Settings</button>
    </form>

    <?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="tab" value="levels">
        <p class="form-hint" style="margin-bottom:14px">
            Percentages apply to the <b>BV</b> of each approved order. Set 0 to disable a level.
            Levels are counted along the sponsor chain (level 1 = direct sponsor).
        </p>
        <div class="form-grid2">
            <?php foreach (range(1, 10) as $ln): ?>
            <div class="form-group">
                <label>Level <?= $ln ?> (%)</label>
                <input class="form-control" type="number" step="0.01" min="0" max="100" name="level_<?= $ln ?>"
                       value="<?= e($levels[$ln] ?? 0) ?>" <?= $ln > (int)$plan['level_depth'] ? 'disabled' : '' ?>>
            </div>
            <?php endforeach; ?>
        </div>
        <button class="btn btn-primary" type="submit">💾 Save Level Percentages</button>
    </form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
