<?php
/**
 * Member payout-report library — powers the member panel Payout section:
 *
 *   Incentive Payout Report (weekly, Sunday → Saturday)   user/payout.php
 *   Reward Payout Report (15-day halves)                  user/payout-rewards.php
 *   Payout statement popup + PDF download                 user/payout-statement.php
 *   My Balance Payout ledger                              user/payout-balance.php
 *
 * Everything is computed LIVE from the member's own credited
 * commissions, so the reports always mirror the actual performance:
 *   - incentive income  = sponsor (one per qualifying referral),
 *                         binary (every eligible matching pair),
 *                         sponsor_matching (immediate child's first match)
 *   - reward income     = rank + award (Cash Reward), car_fund (Car Fund),
 *                         level (Royalty)
 * Period deductions are illustrations using the CURRENT plan percentages,
 * not historical payout deductions. Actual amounts are stored on each payout
 * or generated weekly statement. They cannot be allocated to periods reliably.
 *
 * Period rules: a period appears only once it has fully completed —
 * weeks close after their Saturday, 15-day halves after the 15th / the
 * last day of the month (the running week / half is never shown).
 */

/** Column definitions for a report kind.
 * Returns [ [partKey, column label, [commission types]], ... ] */
function payout_column_map($kind)
{
    if ($kind === 'reward') {
        return [
            ['cash', 'Cash Reward', ['rank', 'award']],
            ['car', 'Car Fund', ['car_fund']],
            ['royal', 'Royalty', ['level']],
        ];
    }
    return [
        ['sponsor', 'Direct Sponsor Income', ['sponsor']],
        ['match', 'Matching Bonus', ['binary']],
        ['direct', 'Placement First-Match', ['sponsor_matching']],
    ];
}

/** Sunday → Saturday week bounds of a date. Returns [from, to] (Y-m-d). */
function payout_week_bounds($dateStr)
{
    $ts = strtotime(substr((string)$dateStr, 0, 10));
    $dow = (int)date('w', $ts); /* 0 = Sunday */
    return [date('Y-m-d', $ts - $dow * 86400), date('Y-m-d', $ts + (6 - $dow) * 86400)];
}

/** 15-day half-month bounds of a date (1st–15th, 16th–month end). */
function payout_reward_bounds($dateStr)
{
    $ts = strtotime(substr((string)$dateStr, 0, 10));
    $y = date('Y', $ts);
    $m = date('m', $ts);
    if ((int)date('d', $ts) <= 15) {
        return ["$y-$m-01", "$y-$m-15"];
    }
    return ["$y-$m-16", date('Y-m-t', $ts)];
}

function payout_period_bounds($kind, $dateStr)
{
    return $kind === 'reward' ? payout_reward_bounds($dateStr) : payout_week_bounds($dateStr);
}

/** Live gross payout-entitlement activity, not the wallet transaction ledger.
 * Credited eligible income (including signed adjustments) is grouped by day;
 * pending and paid payout requests reduce entitlement by their GROSS amount
 * from the day the wallet was held. Rejected requests are excluded. This uses
 * exactly the same income types and current payout statuses as payout eligibility,
 * includes the current period, and never applies estimated tax a second time. */
function payout_entitlement_ledger($userId)
{
    $ledger = [];
    foreach (q_all("SELECT DATE(created_at) AS day, SUM(amount) AS amount FROM commissions
                    WHERE user_id = ? AND status = 'credited'
                      AND type IN ('sponsor','binary','sponsor_matching','rank')
                    GROUP BY DATE(created_at)", [(int)$userId]) as $income) {
        $amount = round((float)$income['amount'], 2);
        if ($amount == 0.0) { continue; }
        $ledger[] = ['date' => $income['day'], 'ord' => 1, 'id' => 0,
            'desc' => 'ELIGIBLE INCOME / ADJUSTMENTS', 'cr' => max(0, $amount), 'dr' => max(0, -$amount)];
    }
    foreach (q_all("SELECT id, request_no, amount, status, created_at FROM payouts
                    WHERE user_id = ? AND status IN ('paid','pending')", [(int)$userId]) as $payout) {
        $ledger[] = ['date' => substr($payout['created_at'], 0, 10), 'ord' => 2, 'id' => (int)$payout['id'],
            'desc' => 'PAYOUT REQUEST ' . $payout['request_no'] . ($payout['status'] === 'paid' ? ' (PAID)' : ' (HELD)'),
            'cr' => 0.0, 'dr' => (float)$payout['amount']];
    }
    usort($ledger, function ($a, $b) { return [$a['date'], $a['ord'], $a['id']] <=> [$b['date'], $b['ord'], $b['id']]; });
    $balance = 0.0;
    foreach ($ledger as &$row) {
        $balance = round($balance + $row['cr'] - $row['dr'], 2);
        $row['bal'] = $balance;
    }
    unset($row);
    return ['rows' => $ledger, 'balance' => $balance];
}

/** All COMPLETED periods (newest first) in which this member earned
 * income of the given kind. Each row:
 *   ['from','to','parts'=>[key=>amount],'gross','tds','admin','net'] */
function payout_report_periods($userId, $kind)
{
    $plan = get_plan();
    $map = payout_column_map($kind);
    $types = [];
    foreach ($map as [, , $ts]) { $types = array_merge($types, $ts); }
    $types = array_values(array_unique($types));
    $in = "'" . implode("','", $types) . "'";
    $today = date('Y-m-d');

    $periods = [];
    foreach (q_all("SELECT type, amount, created_at FROM commissions
                    WHERE user_id = ? AND status = 'credited' AND type IN ($in)
                      AND created_at <= ?", [(int)$userId, $today . ' 23:59:59']) as $r) {
        [$f, $t] = payout_period_bounds($kind, $r['created_at']);
        if ($t >= $today) { continue; } /* running week / half — not closed yet */
        if (!isset($periods[$f])) {
            $periods[$f] = ['from' => $f, 'to' => $t, 'parts' => []];
            foreach ($map as [$pk]) { $periods[$f]['parts'][$pk] = 0.0; }
        }
        foreach ($map as [$pk, , $ts]) {
            if (in_array($r['type'], $ts, true)) {
                $periods[$f]['parts'][$pk] += (float)$r['amount'];
            }
        }
    }
    krsort($periods);
    foreach ($periods as &$p) {
        $p = payout_finalize_period($p, $plan);
    }
    unset($p);
    return array_values($periods);
}

/** Apply totals + deductions to a period row. */
function payout_finalize_period(array $p, array $plan)
{
    $p['gross'] = round(array_sum($p['parts']), 2);
    // A negative correction is not a negative taxable payout. The same
    // paise rounding rule as actual requests keeps small estimates nonnegative.
    [$p['tds'], $p['admin']] = payout_deductions(max(0, $p['gross']), $plan);
    $p['net'] = round($p['gross'] - $p['tds'] - $p['admin'], 2);
    foreach ($p['parts'] as $k => $v) { $p['parts'][$k] = round((float)$v, 2); }
    return $p;
}

/** Breakdown of ONE period, validating its boundaries. Returns null when
 * $from/$to are not exactly a completed period of that kind. */
function payout_period_breakdown($userId, $kind, $from, $to)
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$to)) {
        return null;
    }
    [$expFrom, $expTo] = payout_period_bounds($kind, $from);
    if ($from !== $expFrom || $to !== $expTo) { return null; }
    if ($expTo >= date('Y-m-d')) { return null; } /* not completed yet */

    $plan = get_plan();
    $map = payout_column_map($kind);
    $types = [];
    foreach ($map as [, , $ts]) { $types = array_merge($types, $ts); }
    $types = array_values(array_unique($types));
    $in = "'" . implode("','", $types) . "'";

    $p = ['from' => $from, 'to' => $to, 'parts' => []];
    foreach ($map as [$pk]) { $p['parts'][$pk] = 0.0; }
    foreach (q_all("SELECT type, amount FROM commissions
                    WHERE user_id = ? AND status = 'credited' AND type IN ($in)
                      AND created_at >= ? AND created_at <= ?",
                    [(int)$userId, $from . ' 00:00:00', $to . ' 23:59:59']) as $r) {
        foreach ($map as [$pk, , $ts]) {
            if (in_array($r['type'], $ts, true)) { $p['parts'][$pk] += (float)$r['amount']; }
        }
    }
    return payout_finalize_period($p, $plan);
}

/* ------------------------------------------------------------------ */
/*  Payout statement (HTML popup document + PDF)                       */
/* ------------------------------------------------------------------ */

/** Statement number, e.g. PS-20261004-W (weekly) / PS-20261001-R (reward). */
function payout_statement_no($kind, $from)
{
    return 'PS-' . date('Ymd', strtotime($from)) . ($kind === 'reward' ? '-R' : '-W');
}

/** The payout statement document — self-styled HTML fragment used in the
 * popup and for printing (mirrors the invoice look: green #17433c). */
function payout_statement_html(array $st)
{
    $u = $st['user'];
    $c = $st['company'];
    $map = payout_column_map($st['kind']);
    ob_start();
    ?>
<style>
.pst-doc{background:#fff;color:#111;font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:1.45;max-width:720px;margin:0 auto;padding:34px 38px;box-sizing:border-box}
.pst-doc *{box-sizing:border-box}
.pst-top{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;border-bottom:3px solid #17433c;padding-bottom:14px}
.pst-co .nm{font-size:17px;font-weight:700;color:#17433c;letter-spacing:.2px}
.pst-co .ad{font-size:10.5px;color:#444;max-width:380px}
.pst-co .ct{font-size:10.5px;color:#444}
.pst-title{text-align:right;flex:0 0 auto}
.pst-title h2{margin:0;font-size:19px;letter-spacing:2.5px;color:#17433c;font-weight:700}
.pst-title .no{margin-top:7px;font-size:12px}
.pst-title .no b{font-size:13px}
.pst-det{display:grid;grid-template-columns:1fr 1fr;gap:0 22px;margin:16px 0 0;border:1px solid #d9dee2;border-radius:4px;background:#f8faf9;padding:10px 14px}
.pst-det h4{grid-column:1 / -1;margin:0 0 6px;font-size:10px;letter-spacing:1.2px;color:#666;text-transform:uppercase;border-bottom:1px solid #e3e8ea;padding-bottom:5px}
.pst-det div{font-size:11.5px;padding:2.5px 0}
.pst-det span{display:inline-block;min-width:118px;color:#555}
.pst-det b{font-weight:700}
.pst-amt{width:100%;border-collapse:collapse;margin-top:16px;font-size:11.5px}
.pst-amt th{background:#17433c;color:#fff;text-align:left;padding:8px 10px;font-size:10px;letter-spacing:.8px;text-transform:uppercase}
.pst-amt th.r,.pst-amt td.r{text-align:right}
.pst-amt td{padding:7px 10px;border-bottom:1px solid #e3e8ea}
.pst-amt td.lbl{color:#333}
.pst-amt tr.tot td{background:#f3f7f5;font-weight:700}
.pst-net{display:flex;justify-content:space-between;align-items:center;background:#17433c;color:#fff;border-radius:4px;padding:11px 14px;margin-top:14px;font-weight:700}
.pst-net .lb{font-size:12.5px;letter-spacing:.6px}
.pst-net .vl{font-size:16px}
.pst-note{margin-top:16px;font-size:10px;color:#555;line-height:1.55;border-top:1px solid #e3e8ea;padding-top:10px;text-align:center}
</style>
<div class="pst-doc" id="payoutStatementDoc">
    <div class="pst-top">
        <div class="pst-co">
            <div class="nm"><?= e($c['name']) ?></div>
            <div class="ad"><?= e($c['address']) ?></div>
            <div class="ct">Contact No.: <?= e($c['phone']) ?></div>
            <div class="ct">Email ID: <?= e($c['email']) ?><?php if ($c['website']): ?> &nbsp;·&nbsp; Website: <?= e($c['website']) ?><?php endif; ?></div>
        </div>
        <div class="pst-title">
            <h2>INCOME ESTIMATE</h2>
            <div class="no">Estimate No: <b><?= e($st['no']) ?></b><br>
                Generated On: <b><?= e(dmy($st['generated'])) ?></b></div>
        </div>
    </div>

    <div class="pst-det">
        <h4>Details</h4>
        <div><span>Name</span> <b><?= e($u['full_name']) ?></b></div>
        <div><span>Member ID</span> <b><?= e($u['username']) ?></b></div>
        <div><span>Period</span> <b><?= e(dmy($st['from']) . ' To ' . dmy($st['to'])) ?></b></div>
        <div><span>Mobile No.</span> <?= e($u['mobile']) ?></div>
        <div><span>Address</span> <?= e(trim($u['address'] . ', ' . $u['city'], ', ')) ?></div>
        <div><span>State</span> <?= e($u['state']) ?></div>
    </div>

    <table class="pst-amt">
        <thead>
            <tr><th>Earnings</th><th class="r">Amount</th><th>Estimated deduction(s)</th><th class="r">Amount</th></tr>
        </thead>
        <tbody>
        <?php
        $earn = [];
        foreach ($map as [$pk, $label]) {
            $earn[] = [$label, $st['parts'][$pk]];
        }
        $ded = [
            ['TDS (' . rtrim(rtrim(number_format($st['tdsPct'], 2, '.', ''), '0'), '.') . '%)', $st['tds']],
            ['Admin / Processing Charges (' . rtrim(rtrim(number_format($st['adminPct'], 2, '.', ''), '0'), '.') . '%)', $st['admin']],
        ];
        $rows = max(count($earn), count($ded));
        for ($i = 0; $i < $rows; $i++):
            [$el, $ea] = $earn[$i] ?? ['', 0.0];
            [$dl, $da] = $ded[$i] ?? ['', null];
        ?>
            <tr>
                <td class="lbl"><?= e($el) ?></td>
                <td class="r"><?= $el !== '' ? money($ea) : '' ?></td>
                <td class="lbl"><?= e($dl) ?></td>
                <td class="r"><?= $da !== null ? money($da) : '' ?></td>
            </tr>
        <?php endfor; ?>
            <tr class="tot">
                <td>Total</td>
                <td class="r"><?= money($st['gross']) ?></td>
                <td>Total</td>
                <td class="r"><?= money($st['tds'] + $st['admin']) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="pst-net">
        <span class="lb">ESTIMATED NET AMOUNT</span>
        <span class="vl"><?= money($st['net']) ?></span>
    </div>

    <div class="pst-note">
        Illustrative only, based on current plan rates. This is not a payout receipt or proof of payment.
        See payout history and weekly receipts for recorded amounts and status.
    </div>
</div>
    <?php
    return (string)ob_get_clean();
}

/** The same statement as a PDF (reuses the dependency-free MiniPDF writer). */
function payout_statement_pdf(array $st)
{
    require_once __DIR__ . '/invoice_pdf.php';
    $u = $st['user'];
    $c = $st['company'];
    $map = payout_column_map($st['kind']);
    $green = [0.09, 0.263, 0.235];
    $gray = [0.42, 0.44, 0.46];

    $p = new MiniPDF(40, 46);
    $p->newPage();
    $M = 56;
    $W = MiniPDF::PW - $M * 2;
    $R = MiniPDF::PW - $M;

    /* header */
    $p->text($M, 52, 15.5, true, $c['name'], 'L', $green);
    $y = 70;
    foreach (inv_pdf_wrap(trim((string)$c['address']), 8.2, false, 330) as $line) {
        $p->text($M, $y, 8.2, false, $line, 'L', $gray);
        $y += 11;
    }
    $p->text($M, $y, 8.2, false, 'Contact No.: ' . $c['phone'], 'L', $gray);
    $y += 11;
    $p->text($M, $y, 8.2, false, 'Email ID.: ' . $c['email'] . ($c['website'] ? '   Website: ' . $c['website'] : ''), 'L', $gray);

    $p->text($R, 52, 16, true, 'INCOME ESTIMATE', 'R', $green);
    $p->text($R, 70, 9, false, 'Estimate No: ' . $st['no'], 'R');
    $p->text($R, 81, 9, false, 'Generated On: ' . dmy($st['generated']), 'R');
    $p->hline($M, $R, 100, 2.2, 0.09);
    $y = 116;

    /* details */
    $p->text($M, $y, 8, true, 'DETAILS', 'L', $gray);
    $y += 14;
    $det = [
        ['Name', $u['full_name']],
        ['Member ID', $u['username']],
        ['Period', dmy($st['from']) . ' To ' . dmy($st['to'])],
        ['Mobile No.', $u['mobile']],
        ['Address', trim($u['address'] . ', ' . $u['city'], ', ')],
        ['State', $u['state']],
    ];
    $half = 3;
    for ($i = 0; $i < $half; $i++) {
        $p->text($M, $y, 9.5, false, $det[$i][0], 'L', $gray);
        $p->text($M + 88, $y, 9.5, false, $det[$i][1], 'L', [0.15, 0.15, 0.15]);
        [$l2, $v2] = $det[$i + $half];
        $p->text($M + 250, $y, 9.5, false, $l2, 'L', $gray);
        $p->text($M + 338, $y, 9.5, false, $v2, 'L', [0.15, 0.15, 0.15]);
        $y += 12.5;
    }
    $y += 8;

    /* amounts */
    $p->rect($M, $y, $W, 17, 0.09);
    $p->text($M + 8, $y + 12, 7.6, true, 'EARNINGS', 'L', [1, 1, 1]);
    $p->text($M + 248, $y + 12, 7.6, true, 'AMOUNT', 'R', [1, 1, 1]);
    $p->text($M + 258, $y + 12, 7.6, true, 'ESTIMATED DEDUCTIONS', 'L', [1, 1, 1]);
    $p->text($R - 8, $y + 12, 7.6, true, 'AMOUNT', 'R', [1, 1, 1]);
    $y += 17;

    $earn = [];
    foreach ($map as [$pk, $label]) { $earn[] = [$label, $st['parts'][$pk]]; }
    $ded = [
        ['TDS (' . $st['tdsPct'] . '%)', $st['tds']],
        ['Admin / Processing Charges (' . $st['adminPct'] . '%)', $st['admin']],
    ];
    $rows = max(count($earn), count($ded));
    for ($i = 0; $i < $rows; $i++) {
        [$el, $ea] = $earn[$i] ?? ['', 0.0];
        [$dl, $da] = $ded[$i] ?? ['', null];
        $p->hline($M, $R, $y + 14, 0.4, 0.88);
        if ($el !== '') { $p->text($M + 8, $y + 11, 9.3, false, $el); }
        if ($el !== '') { $p->text($M + 248, $y + 11, 9.3, false, 'Rs. ' . number_format((float)$ea, 2), 'R'); }
        if ($dl !== '') { $p->text($M + 258, $y + 11, 9.3, false, $dl); }
        if ($da !== null) { $p->text($R - 8, $y + 11, 9.3, false, 'Rs. ' . number_format((float)$da, 2), 'R'); }
        $y += 15.5;
    }
    $p->rect($M, $y, $W, 17, 0.93);
    $p->text($M + 8, $y + 12, 9.3, true, 'Total');
    $p->text($M + 248, $y + 12, 9.3, true, 'Rs. ' . number_format($st['gross'], 2), 'R');
    $p->text($M + 258, $y + 12, 9.3, true, 'Total');
    $p->text($R - 8, $y + 12, 9.3, true, 'Rs. ' . number_format($st['tds'] + $st['admin'], 2), 'R');
    $y += 30;

    /* net payable */
    $p->rect($M, $y, $W, 24, 0.09);
    $p->text($M + 8, $y + 16, 11, true, 'ESTIMATED NET AMOUNT', 'L', [1, 1, 1]);
    $p->text($R - 8, $y + 16, 13, true, 'Rs. ' . number_format($st['net'], 2), 'R', [1, 1, 1]);

    /* footer */
    $fy = MiniPDF::PH - 34;
    $p->hline($M, $R, $fy - 12, 0.5, 0.85);
    $p->text($M, $fy - 3, 7.6, false, 'Illustration using current rates; not a payout receipt or proof of payment.', 'L', $gray);
    $p->text($M, $fy + 8, 7.6, false, 'See payout history and weekly receipts for recorded amounts and status.', 'L', $gray);

    return $p->output();
}
