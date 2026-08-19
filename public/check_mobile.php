<?php
/**
 * public/check_mobile.php  ->  route: /check-mobile
 * Lightweight JSON check: has this mobile already registered?
 * Returns no personal data — only registered true/false + message.
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'registered' => false, 'message' => 'Invalid request.'], 405);
}

if (AuthService::rateLimited('check_mobile_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 30, 300)) {
    json_response(['ok' => false, 'registered' => false, 'message' => 'Too many checks. Please wait a moment.'], 429);
}

$raw = (string) ($_GET['mobile'] ?? '');
$mobile = Validation::normaliseMobile($raw);

if ($mobile === null) {
    json_response([
        'ok' => true,
        'registered' => false,
        'valid' => false,
        'message' => '',
    ]);
}

$existing = CustomerService::findByMobile($mobile);
if ($existing !== null) {
    json_response([
        'ok' => true,
        'registered' => true,
        'valid' => true,
        'message' => 'This mobile number already used a voucher earlier. Only one coupon per number is allowed.',
    ]);
}

json_response([
    'ok' => true,
    'registered' => false,
    'valid' => true,
    'message' => '',
]);
