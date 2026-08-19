<?php
/**
 * public/verify_otp.php  ->  /verify-otp
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Invalid request.'], 405);
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    json_response(['ok' => false, 'error' => 'Session expired. Refresh the page.'], 419);
}

$mobile = Validation::normaliseMobile((string) ($_POST['mobile'] ?? ''));
if ($mobile === null) {
    json_response(['ok' => false, 'error' => 'Enter a valid 10-digit mobile number.']);
}

$code = (string) ($_POST['otp'] ?? '');
$result = OtpService::verify($mobile, $code);
if (!($result['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => $result['error'] ?? 'OTP verification failed.']);
}

json_response(['ok' => true, 'message' => 'Mobile number verified.']);
