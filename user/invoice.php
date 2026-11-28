<?php
/**
 * Invoice for one of MY orders.
 *
 *   invoice.php?id=ORDER_ID        → HTML fragment (loaded into the
 *                                     invoice popup on the orders page)
 *   invoice.php?id=ORDER_ID&pdf=1  → the same invoice as a downloadable
 *                                     PDF (generated in pure PHP — no
 *                                     external libraries).
 *
 * Ownership is enforced inside invoice_data(), so a member can never
 * open or download another member's invoice.
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/invoice_lib.php';
$u = require_user();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isAjax = isset($_GET['ajax']);
$inv = $id ? invoice_data($id, $u['id']) : null;

if (!$inv) {
    if ($isAjax || isset($_GET['pdf'])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Invoice not found for that order.');
    }
    flash('warning', 'Invoice not found for that order.');
    redirect('orders.php');
}

/* ---------- PDF download ---------- */
if (isset($_GET['pdf'])) {
    require_once __DIR__ . '/../includes/invoice_pdf.php';
    $pdf = invoice_pdf($inv);
    $name = preg_replace('/[^A-Za-z0-9_\-]/', '', $inv['no']) ?: 'invoice';
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
echo invoice_html($inv);
