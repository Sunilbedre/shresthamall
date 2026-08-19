<?php
/**
 * public/send_otp.php  ->  /send-otp
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

$result = OtpService::send($mobile, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!($result['ok'] ?? false)) {
    json_response([
        'ok'       => false,
        'error'    => $result['error'] ?? 'Could not send OTP.',
        'cooldown' => $result['cooldown'] ?? null,
    ]);
}

json_response([
    'ok'      => true,
    'channel' => $result['channel'] ?? 'whatsapp',
    'message' => $result['message'] ?? 'OTP sent. Valid for 10 minutes.',
]);
