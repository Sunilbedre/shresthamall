<?php
/**
 * public/check_mobile.php  ->  route: /check-mobile
 * Lightweight JSON check: has this mobile already registered?
 * Optional ?campaign=slug scopes the check to that special campaign bucket.
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
$campaignSlug = CampaignService::normaliseSlug((string) ($_GET['campaign'] ?? ''));

if ($mobile === null) {
    json_response([
        'ok' => true,
        'registered' => false,
        'valid' => false,
        'message' => '',
    ]);
}

$existing = CustomerService::findByMobileForCampaign($mobile, $campaignSlug);
if ($existing !== null) {
    $message = $campaignSlug !== ''
        ? 'This mobile number already registered for this special offer. Only one voucher per number for this event.'
        : 'This mobile number already used a voucher earlier. Only one coupon per number is allowed.';
    json_response([
        'ok' => true,
        'registered' => true,
        'valid' => true,
        'message' => $message,
    ]);
}

json_response([
    'ok' => true,
    'registered' => false,
    'valid' => true,
    'message' => '',
]);
