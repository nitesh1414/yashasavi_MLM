<?php
/** MLM plan settings + level percentages */
require_once __DIR__ . '/../includes/init.php';
$a = require_superadmin();

if (is_post()) {
    verify_csrf();
    $tab = post_str('tab', 'main');

    if ($tab === 'main') {
        // Validate against the actual v1.8.48 column ranges; zero is a valid
        // commission rate, but the joining/pair units and payout minimum must
        // remain positive. The matching select explicitly posts "0" or "1".
        $fields = [
            'activation_bv' => ['positive', 9999999999.99],
            'sponsor_percent' => ['nonnegative', 9999.99],
            'pair_unit_bv' => ['positive', 9999999999.99],
            'binary_value' => ['nonnegative', 99999999.99],
            'sponsor_matching_percent' => ['nonnegative', 9999.99],
            'car_fund_amount' => ['nonnegative', 9999999999.99],
            'tds_percent' => ['nonnegative', 100],
            'admin_charge_percent' => ['nonnegative', 100],
            'payout_min' => ['positive', 9999999999.99],
        ];
        $updates = []; $errors = [];
        foreach ($fields as $key => [$rule, $max]) {
            if (!array_key_exists($key, $_POST)) { continue; }
            $raw = $_POST[$key];
            $val = is_scalar($raw) && is_numeric($raw) ? (float)$raw : NAN;
            $val = round($val, 2);
            if (!is_finite($val) || $val > $max || $val < 0 || ($rule === 'positive' && $val <= 0)) {
                $errors[] = ucfirst(str_replace('_', ' ', $key)) . ' is outside the allowed range.';
            } else { $updates[$key] = $val; }
        }
        if (isset($_POST['binary_type'])) {
            if (!in_array($_POST['binary_type'], ['percent', 'fixed'], true)) { $errors[] = 'Invalid binary income type.'; }
            else { $updates['binary_type'] = $_POST['binary_type']; }
        }
        if (isset($_POST['matching_requires_active'])) {
            $choice = (string)$_POST['matching_requires_active'];
            if (!in_array($choice, ['0', '1'], true)) { $errors[] = 'Invalid matching eligibility option.'; }
            else { $updates['matching_requires_active'] = (int)$choice; }
        }
        if (isset($_POST['points_per_unit'])) {
            $raw = (string)$_POST['points_per_unit'];
            if (!ctype_digit($raw) || (int)$raw > 1000) { $errors[] = 'Points per pair must be between 0 and 1000.'; }
            else { $updates['points_per_unit'] = (int)$raw; }
        }
        if (array_key_exists('car_fund_points', $_POST)) {
            $raw = is_scalar($_POST['car_fund_points']) ? trim((string)$_POST['car_fund_points']) : '';
            if (!ctype_digit($raw) || strlen($raw) > 10 || (float)$raw > 4294967295) {
                $errors[] = 'Car Fund points must be a whole number from 0 to 4294967295.';
            } else { $updates['car_fund_points'] = (int)$raw; }
        }
        $milestoneUpdates = [];
        if (array_key_exists('dashboard_tour_points', $_POST) || array_key_exists('dashboard_tour_amount', $_POST)) {
            $points = isset($_POST['dashboard_tour_points']) && is_scalar($_POST['dashboard_tour_points'])
                ? trim((string)$_POST['dashboard_tour_points']) : '';
            $amount = isset($_POST['dashboard_tour_amount']) && is_scalar($_POST['dashboard_tour_amount'])
                ? trim((string)$_POST['dashboard_tour_amount']) : '';
            if ($points === '' && $amount === '') {
                $milestoneUpdates = ['dashboard_tour_points' => '', 'dashboard_tour_amount' => ''];
            } elseif (!ctype_digit($points) || strlen($points) > 10 || (float)$points < 1 || (float)$points > 4294967295
                || $amount === '' || !is_numeric($amount) || !is_finite((float)$amount)
                || (float)$amount < 0 || (float)$amount > 9999999999.99) {
                $errors[] = 'Enter valid Tour/Vacation points and amount, or leave both blank.';
            } else {
                $milestoneUpdates = ['dashboard_tour_points' => (string)(int)$points,
                    'dashboard_tour_amount' => number_format((float)$amount, 2, '.', '')];
            }
        }
        if (!$errors) {
            try {
                db_tx(function () use ($updates, $milestoneUpdates) {
                    income_rules_ready(true); // same lock order as orders and reconciliation
                    $current = q_row("SELECT * FROM plan_settings WHERE id = 1 FOR UPDATE");
                    if (!$current) { throw new RuntimeException('MLM plan settings are missing.'); }
                    $final = array_merge($current, $updates);
                    if ((float)$final['tds_percent'] + (float)$final['admin_charge_percent'] > 100) {
                        throw new RuntimeException('TDS and admin charges together cannot exceed 100%.');
                    }
                    $sets = []; $params = [];
                    foreach ($updates as $k => $v) { $sets[] = "$k = ?"; $params[] = $v; }
                    $sets[] = 'updated_at = ?'; $params[] = now(); $params[] = 1;
                    q("UPDATE plan_settings SET " . implode(', ', $sets) . " WHERE id = ?", $params);
                    foreach ($milestoneUpdates as $key => $value) { save_setting($key, $value); }
                });
                flash('success', 'MLM plan settings saved.');
            } catch (Throwable $ex) {
                error_log('Plan update failed: ' . $ex->getMessage());
                $errors[] = $ex instanceof PDOException ? 'The plan could not be saved; no settings were changed.' : $ex->getMessage();
            }
        }
        foreach ($errors as $er) { flash('error', $er); }
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
        Changes apply to <b>future</b> activity. To apply current settings historically, review the
        separate income and BV preview below. Last updated: <?= dmy($plan['updated_at'], true) ?>.
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
                <div class="form-hint" style="margin-top:6px">A member's Self BV establishes joining eligibility and contributes to their placement ancestors' team BV. Manual earning activation remains an override.</div>
            </div>
            <div class="form-group">
                <label>Matching requires eligible member (completed joining BV)</label>
                <select class="form-control" name="matching_requires_active">
                    <option value="1" <?= (int)$plan['matching_requires_active'] === 1 ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= (int)$plan['matching_requires_active'] === 0 ? 'selected' : '' ?>>No</option>
                </select>
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">1 · Direct Sponsor Income (once per qualifying personally sponsored member)</h4>
        <div class="form-grid2">
            <div class="form-group">
                <label>Direct sponsor income — % of joining BV</label>
                <input class="form-control" type="number" step="0.01" name="sponsor_percent" value="<?= e($plan['sponsor_percent']) ?>">
                <div class="form-hint" style="margin-top:6px">At 10% and 3,000 joining BV, the actual sponsor receives <b>₹300 once per qualifying personally sponsored member</b>, regardless of that member's placement. There is no per-leg limit.</div>
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">2 · Matching Pair Income (every eligible pair)</h4>
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
                <div class="form-hint" style="margin-top:6px">At the default settings, every 3,000 BV left + 3,000 BV right pair earns <b>₹450</b>. Sponsor income is not required. The joining eligibility setting above still applies.</div>
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">3 · Placement Parent First-Match Bonus (once per immediate child)</h4>
        <div class="form-grid2">
            <div class="form-group">
                <label>Bonus — % of an immediate placement child's first matching pair income</label>
                <input class="form-control" type="number" step="0.01" name="sponsor_matching_percent" value="<?= e($plan['sponsor_matching_percent']) ?>">
                <div class="form-hint" style="margin-top:6px">At the defaults, 50% × ₹450 = <b>₹225 once</b> to the matched member's immediate placement parent when that child earns their first pair. The child's sponsor may be someone else.</div>
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">4 · Points, Ranks &amp; Awards (rank rewards are paid to the wallet and included in payouts)</h4>
        <div class="form-grid2">
            <div class="form-group">
                <label>Points per matched pair unit</label>
                <input class="form-control" type="number" min="0" name="points_per_unit" value="<?= e($plan['points_per_unit']) ?>">
                <div class="form-hint" style="margin-top:6px">Points are awarded per matched pair using the configured unit and point rate. Points require joining eligibility or manual earning activation. Every <a href="ranks.php">rank</a> achievement credits that rank's reward amount to the member's <b>wallet balance</b> (wallet income — included in payouts). <a href="rewards.php">Award milestones</a> are recognition only.</div>
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">Dashboard milestones</h4>
        <p class="form-hint" style="margin-bottom:12px">These milestones are informational only. Reaching a target does not credit a wallet, create a payout, or confirm a reward has been paid. Set Car Fund points to 0 to disable it; leave both Tour/Vacation fields blank to leave it unconfigured.</p>
        <div class="form-grid2">
            <div class="form-group">
                <label>Car Fund target points</label>
                <input class="form-control" type="number" min="0" max="4294967295" step="1" name="car_fund_points" value="<?= e($plan['car_fund_points']) ?>">
            </div>
            <div class="form-group">
                <label>Car Fund milestone amount (₹)</label>
                <input class="form-control" type="number" min="0" max="9999999999.99" step="0.01" name="car_fund_amount" value="<?= e($plan['car_fund_amount']) ?>">
            </div>
            <div class="form-group">
                <label>Tour / Vacation target points</label>
                <input class="form-control" type="number" min="1" max="4294967295" step="1" name="dashboard_tour_points" value="<?= e(setting('dashboard_tour_points', '')) ?>" placeholder="Not configured">
            </div>
            <div class="form-group">
                <label>Tour / Vacation milestone amount (₹)</label>
                <input class="form-control" type="number" min="0" max="9999999999.99" step="0.01" name="dashboard_tour_amount" value="<?= e(setting('dashboard_tour_amount', '')) ?>" placeholder="Not configured">
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

    <div class="card" style="margin-top:18px;border:1.5px dashed var(--accent)">
        <div class="card-title">🔄 Historical income &amp; BV reconciliation</div>
        <p style="font-size:13px;color:var(--ink-soft)">
            Preview the effect on every member before applying the current plan to their current tree and sponsors.
            Saved Self BV is preserved; differences from approved-order totals are flagged. Original commissions,
            wallet transactions, orders, profiles and payout history are retained. Any income differences are
            recorded as new, auditable wallet and commission adjustments.
        </p>
        <a class="btn btn-primary" href="income_recalculate.php">Review recalculation preview</a>
    </div>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
