<?php
/** MLM plan settings + level percentages */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

if (is_post()) {
    verify_csrf();
    $tab = post_str('tab', 'main');

    if ($tab === 'main') {
        $fields = [
            'activation_bv' => 'float_pos',
            'sponsor_percent' => 'percent',
            'pair_unit_bv' => 'float_pos',
            'binary_type' => 'enum:percent,fixed',
            'binary_value' => 'float_pos',
            'matching_requires_active' => 'bool',
            'sponsor_matching_percent' => 'percent',
            'points_per_unit' => 'int:0:1000',
            'tds_percent' => 'percent',
            'admin_charge_percent' => 'percent',
            'payout_min' => 'float_pos',
        ];
        $updates = [];
        $errors = [];
        foreach ($fields as $key => $rule) {
            $val = $_POST[$key] ?? null;
            if ($val === null) { continue; }
            if ($rule === 'bool') {
                $updates[$key] = (string)$val === '1' ? 1 : 0;
                continue;
            }
            if ($rule === 'float_pos' || $rule === 'percent') {
                if (!is_numeric($val) || !is_finite((float)$val)) { $errors[] = ucfirst(str_replace('_', ' ', $key)) . ' must be a valid number.'; continue; }
                $val = (float)$val;
                if ($rule === 'float_pos' && $val <= 0) { $errors[] = ucfirst(str_replace('_', ' ', $key)) . ' must be greater than 0.'; continue; }
                if ($key === 'pair_unit_bv' && $val < 1) { $errors[] = 'BV per pair must be at least 1.'; continue; }
                if ($rule === 'percent' && ($val < 0 || $val > 100)) { $errors[] = ucfirst(str_replace('_', ' ', $key)) . ' must be between 0 and 100.'; continue; }
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
            $saveError = db_tx(function () use ($sets, $params, $updates) {
                lock_network_mutation();
                $old = q_row("SELECT * FROM plan_settings WHERE id = 1 FOR UPDATE");
                $next = array_merge($old, $updates);
                if ((float)$next['tds_percent'] + (float)$next['admin_charge_percent'] > 100) {
                    return 'TDS and admin charges together cannot exceed 100%.';
                }
                q("UPDATE plan_settings SET " . implode(', ', $sets) . " WHERE id = ?", $params);
                if (isset($updates['pair_unit_bv']) && (float)$old['pair_unit_bv'] !== (float)$updates['pair_unit_bv']) {
                    $unit = max(1, (float)$updates['pair_unit_bv']);
                    // A rate/unit edit is prospective: don't mint pairs or
                    // points from team BV already present before the edit.
                    foreach (q_all("SELECT * FROM matching_state ORDER BY user_id FOR UPDATE") as $state) {
                        $baseline = floor(min((float)$state['left_eligible_bv'], (float)$state['right_eligible_bv']) / $unit) * $unit;
                        q("UPDATE matching_state SET processed_bv = ?, points_processed_bv = ? WHERE user_id = ?",
                          [max((float)$state['processed_bv'], $baseline), max((float)$state['points_processed_bv'], $baseline), $state['user_id']]);
                    }
                }
                return '';
            });
            flash($saveError ? 'error' : 'success', $saveError ?: 'MLM plan settings saved. Existing earnings and pending pair amounts are unchanged.');
        }
        redirect('plan.php');
    }


    if ($tab === 'recalc') {
        flash('error', 'Historical recalculation is disabled. Existing commissions, payouts and balances are preserved.');
        redirect('plan.php');
    }

}

$cur = get_str('tab', 'main');
$plan = get_plan();

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
            <a class="btn btn-light btn-sm" href="ranks.php">🏅 Ranks (Points)</a>
            <a class="btn btn-light btn-sm" href="rewards.php">🎁 Awards (Points)</a>
        </span>
    </div>
    <p class="form-hint" style="margin-bottom:16px">
        Changes apply to <b>future activity only</b>. Existing commissions, payout records and wallet balances are preserved. Last updated: <?= dmy($plan['updated_at'], true) ?>.
    </p>

    <?php if ($cur === 'main'): ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="tab" value="main">

        <h4 style="font-size:14px;margin:6px 0 12px">Joining &amp; Eligibility</h4>
        <div class="form-grid2">
            <div class="form-group">
                <label>Joining BV (Self BV a member must complete to earn commission &amp; points)</label>
                <input class="form-control" type="number" step="0.01" name="activation_bv" value="<?= e($plan['activation_bv']) ?>">
                <div class="form-hint" style="margin-top:6px">Your own self BV makes you eligible; approved purchases also contribute to team BV for placement ancestors, even before your ID is green.</div>
            </div>
            <div class="form-group">
                <label>Matching requires eligible member (completed joining BV)</label>
                <select class="form-control" name="matching_requires_active">
                    <option value="1" <?= (int)$plan['matching_requires_active'] === 1 ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= (int)$plan['matching_requires_active'] === 0 ? 'selected' : '' ?>>No</option>
                </select>
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">1 · Direct Sponsor Income (once per newly green direct member)</h4>
        <div class="form-grid2">
            <div class="form-group">
                <label>Direct sponsor income — % of joining BV</label>
                <input class="form-control" type="number" step="0.01" name="sponsor_percent" value="<?= e($plan['sponsor_percent']) ?>">
                <div class="form-hint" style="margin-top:6px">At the default settings, 10% × 3,000 BV = <b>₹300 per green direct member</b>. It is paid to the actual sponsor, even if another member is the placement parent. Each direct member earns this bonus once; there is no one-per-leg limit.</div>
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">2 · Matching Income (every eligible pair)</h4>
        <div class="form-grid3">
            <div class="form-group">
                <label>BV per pair unit (1:1 matching unit)</label>
                <input class="form-control" type="number" step="0.01" min="1" name="pair_unit_bv" value="<?= e($plan['pair_unit_bv']) ?>">
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
                <div class="form-hint" style="margin-top:6px">At the default settings, <b>each 3,000 BV left + 3,000 BV right pays ₹450</b>. The placement upline receives matching income regardless of the contributing members’ sponsors or green status. Unmatched team BV carries forward.</div>
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">3 · Direct Sponsor Matching Bonus (one time per direct)</h4>
        <div class="form-grid2">
            <div class="form-group">
                <label>Bonus — % of the direct's first matching pair income</label>
                <input class="form-control" type="number" step="0.01" name="sponsor_matching_percent" value="<?= e($plan['sponsor_matching_percent']) ?>">
                <div class="form-hint" style="margin-top:6px">At the defaults, 50% × ₹450 = <b>₹225</b> — paid <b>once</b> when a <b>directly sponsored downline member in the member's left or right leg</b> receives their own first matching payment. The original sponsor must be active and green when the first matching payment settles; if ineligible at settlement, that bonus is not held.</div>
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">4 · Points, Ranks &amp; Awards (rank rewards are paid to the wallet and included in payouts)</h4>
        <div class="form-grid2">
            <div class="form-group">
                <label>Points per matched pair unit</label>
                <input class="form-control" type="number" min="0" name="points_per_unit" value="<?= e($plan['points_per_unit']) ?>">
                <div class="form-hint" style="margin-top:6px">At the default settings, 1 point = 3,000 BV left + 3,000 BV right (both sides). Points require a green ID, through joining BV or manual activation. Every <a href="ranks.php">rank</a> achievement credits that rank's reward amount to the member's <b>wallet balance</b> (wallet income — included in payouts). <a href="rewards.php">Award milestones</a> are recognition only; no car fund exists.</div>
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

    <div class="alert alert-warning" style="margin-top:18px">Historical income recalculation is disabled to protect existing commissions, payouts and wallet balances. New rates and rules apply prospectively.</div>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
