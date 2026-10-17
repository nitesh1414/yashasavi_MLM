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
            'sponsor_percent' => 'float_pos',
            'pair_unit_bv' => 'float_pos',
            'binary_type' => 'enum:percent,fixed',
            'binary_value' => 'float_pos',
            'matching_requires_active' => 'bool',
            'sponsor_matching_percent' => 'float_pos',
            'points_per_unit' => 'int:0:1000',
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
            q("UPDATE plan_settings SET " . implode(', ', $sets) . " WHERE id = ?", $params);
            flash('success', 'MLM plan settings saved.');
        }
        redirect('plan.php');
    }


    if ($tab === 'recalc') {
        /* recompute Total Income + BV of ALL members from the approved
         * order history under the CURRENT plan settings */
        [$ok, $st] = recalculate_all_incomes();
        flash('success',
            'Recalculation complete: ' . number_format($st['orders']) . ' approved order(s) replayed from zero under the current settings — '
            . number_format($st['commissions']) . ' payout income entries rebuilt (₹' . number_format($st['earned'], 2) . ' total) for '
            . number_format($st['members']) . ' member(s), ' . number_format($st['points']) . ' points awarded, in ' . $st['seconds'] . 's. '
            . 'Payout commission = ₹300 per completed Leg (L+R, once each) + ₹450 first Matching Pair + ₹225 Direct Sponsor Bonus only — rank and award rewards are NOT added. '
            . 'Wallet balances were rebuilt from the ledger; manual entries and payouts were kept.');
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
        Changes apply to <b>future</b> orders only — run the recalculation below to also apply them to
        <b>all existing orders and members</b>. Last updated: <?= dmy($plan['updated_at'], true) ?>.
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
                <div class="form-hint" style="margin-top:6px">Self BV is shown on the member dashboard but is never counted in any commission or points calculation — it only makes the member eligible.</div>
            </div>
            <div class="form-group">
                <label>Matching requires eligible member (completed joining BV)</label>
                <select class="form-control" name="matching_requires_active">
                    <option value="1" <?= (int)$plan['matching_requires_active'] === 1 ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= (int)$plan['matching_requires_active'] === 0 ? 'selected' : '' ?>>No</option>
                </select>
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">1 · Leg Completion Income (one time per leg, ₹300 + ₹300)</h4>
        <div class="form-grid2">
            <div class="form-group">
                <label>Sponsor income — % of joining BV</label>
                <input class="form-control" type="number" step="0.01" name="sponsor_percent" value="<?= e($plan['sponsor_percent']) ?>">
                <div class="form-hint" style="margin-top:6px">10% × 3,000 BV = <b>₹300 per leg</b> — paid <b>once</b> when the member's LEFT leg total (all downline, any depth) completes 3,000 BV, and once when the RIGHT leg completes 3,000 BV. Maximum ₹600 per member.</div>
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">2 · Matching Bonus Income (first pair only)</h4>
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
                <div class="form-hint" style="margin-top:6px">3,000 BV left + 3,000 BV right = <b>₹450, paid ONCE for the member's FIRST matching pair</b>. Every further pair adds points only.</div>
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">3 · 50% Direct Sponsor Bonus (one time per direct)</h4>
        <div class="form-grid2">
            <div class="form-group">
                <label>Bonus — % of the direct's first matching income</label>
                <input class="form-control" type="number" step="0.01" name="sponsor_matching_percent" value="<?= e($plan['sponsor_matching_percent']) ?>">
                <div class="form-hint" style="margin-top:6px">50% × ₹450 = <b>₹225</b> — paid <b>once</b> to the direct sponsor when a direct member in the <b>left</b> or <b>right</b> leg completes their first matching pair.</div>
            </div>
        </div>

        <h4 style="font-size:14px;margin:16px 0 12px">4 · Points, Ranks &amp; Awards</h4>
        <div class="form-grid2">
            <div class="form-group">
                <label>Points per matched pair unit</label>
                <input class="form-control" type="number" min="0" name="points_per_unit" value="<?= e($plan['points_per_unit']) ?>">
                <div class="form-hint" style="margin-top:6px">1 point = 3,000 BV left + 3,000 BV right (both sides). Points need completed joining BV. Manage the <a href="ranks.php">rank cash rewards</a> and <a href="rewards.php">award milestones</a> on their pages.</div>
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
        <div class="card-title">🔄 Recalculate Total Income &amp; BV of all members</div>
        <p style="font-size:13px;color:var(--ink-soft)">
            After changing any setting above, run this to recompute <b>every member's payout commission,
            Total Income, BV and points</b> from zero, using the full <b>approved order history</b> under
            the NEW settings: Left / Right / Self BV, matched pairs, points, rank titles, BV / points logs
            and the three one-time payout incomes —
            <b>₹300 per completed Leg (left + right, once each), ₹450 first Matching Pair and
            ₹225 Direct Sponsor Bonus</b>.
        </p>
        <p style="font-size:12.5px;color:var(--ink-soft);margin:6px 0 0">
            <b>Rest all amounts are NOT added to a member's payout commission:</b> rank cash rewards and
            award rewards are wallet income only (cleared by the rebuild, not re-paid). There is no level
            income, car fund or any other commission in this plan.
        </p>
        <ul style="font-size:12.5px;color:var(--ink-soft);margin:8px 0 14px 18px">
            <li>All automatic income entries, points and BV / points logs are wiped and rebuilt chronologically.</li>
            <li>Wallet balances are rebuilt from the complete ledger — <b>manual income entries and payout history are kept</b>.</li>
            <li>Members are only ever <b>activated</b> by the recalculation, never de-activated (manual activations survive).</li>
            <li>Pending / rejected orders are ignored; product stock and order statuses are not touched.</li>
            <li>Large networks may take a while — everything runs in one database transaction.</li>
        </ul>
        <form method="post" data-confirm="Recalculate the Total Income and BV of ALL members from the order history using the CURRENT plan settings? All automatic income entries will be replaced.">
            <?= csrf_field() ?>
            <input type="hidden" name="tab" value="recalc">
            <button class="btn btn-primary" type="submit">🔄 Recalculate Total Income &amp; BV now</button>
        </form>
    </div>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/dash_footer.php'; ?>
