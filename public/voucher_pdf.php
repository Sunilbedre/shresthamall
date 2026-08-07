<?php
/**
 * public/voucher_pdf.php  ->  route: /voucher-pdf
 * Streams a real PDF download (attachment) with voucher details + QR.
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$code = strtoupper(trim((string) ($_GET['code'] ?? '')));

// Prefer session (success page) when code omitted
if ($code === '') {
    $customerId = $_SESSION['last_registration_customer_id'] ?? null;
    $customer = $customerId ? CustomerService::findById((int) $customerId) : null;
    $voucher = $customer ? VoucherModel::findByCustomerId((int) $customer['id']) : null;
} else {
    $voucher = VoucherModel::findByCode($code);
    $customer = $voucher ? CustomerService::findById((int) $voucher['customer_id']) : null;
}

if (!$customer || !$voucher) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Voucher not found.';
    exit;
}

VoucherPdf::download($customer, $voucher);
