<?php
/**
 * Payout statement for ONE of MY report periods.
 *
 *   payout-statement.php?kind=incentive&from=YYYY-MM-DD&to=YYYY-MM-DD&ajax=1
 *       → HTML fragment (loaded into the statement popup)
 *   …&pdf=1
 *       → the same statement as a downloadable PDF (pure PHP, no libs).
 *
 * kind = incentive (weekly Sunday–Saturday) or reward (15-day halves).
 * The period boundaries are validated against the member's own data, so
 * only real completed periods can be opened.
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/payout_lib.php';
$u = require_user();

$kind = get_str('kind') === 'reward' ? 'reward' : 'incentive';
$from = get_str('from');
$to = get_str('to');
$isAjax = isset($_GET['ajax']);
$back = $kind === 'reward' ? 'payout-rewards.php' : 'payout.php';

$period = payout_period_breakdown($u['id'], $kind, $from, $to);
if (!$period) {
    if ($isAjax || isset($_GET['pdf'])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Statement not found for that period.');
    }
    flash('warning', 'Statement not found for that period.');
    redirect($back);
}

$plan = get_plan();
$st = [
    'kind' => $kind,
    'from' => $period['from'],
    'to' => $period['to'],
    'parts' => $period['parts'],
    'gross' => $period['gross'],
    'tds' => $period['tds'],
    'admin' => $period['admin'],
    'net' => $period['net'],
    'tdsPct' => (float)$plan['tds_percent'],
    'adminPct' => (float)$plan['admin_charge_percent'],
    'no' => payout_statement_no($kind, $period['from']),
    'generated' => now(),
    'user' => $u,
    'company' => [
        'name' => setting('company_name') ?: setting('site_name'),
        'address' => setting('contact_address'),
        'phone' => setting('contact_phone'),
        'email' => setting('contact_email'),
        'website' => setting('contact_website') ?: setting('site_url'),
    ],
];

/* ---------- PDF download ---------- */
if (isset($_GET['pdf'])) {
    $pdf = payout_statement_pdf($st);
    $name = preg_replace('/[^A-Za-z0-9_\-]/', '', $st['no']) ?: 'payout-statement';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $name . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf;
    exit;
}

/* ---------- HTML fragment for the popup ---------- */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, max-age=0, must-revalidate');
echo payout_statement_html($st);
