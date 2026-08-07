<?php
/**
 * public/webhook.php  ->  route: /webhook
 * Meta WhatsApp Cloud API webhook.
 * Configure this exact URL (https://yourdomain.com/webhook.php) in the
 * Meta App Dashboard, along with WHATSAPP_WEBHOOK_VERIFY_TOKEN from .env.
 *
 * GET  = verification handshake (hub.challenge)
 * POST = delivery status callbacks (sent/delivered/read/failed)
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

global $CONFIG;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($mode === 'subscribe' && hash_equals($CONFIG['whatsapp']['webhook_verify_token'], (string) $token)) {
        http_response_code(200);
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'Verification failed';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    // Always log the raw payload for troubleshooting, then process quickly and return 200.
    @file_put_contents(
        APP_ROOT . '/storage/logs/webhook.log',
        date('c') . ' ' . $raw . PHP_EOL,
        FILE_APPEND
    );

    try {
        $entries = $payload['entry'] ?? [];
        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];
            foreach ($changes as $change) {
                $statuses = $change['value']['statuses'] ?? [];
                foreach ($statuses as $statusEvent) {
                    $messageId = $statusEvent['id'] ?? null;
                    $status = $statusEvent['status'] ?? null; // sent | delivered | read | failed
                    if ($messageId && $status) {
                        WhatsAppService::applyStatusUpdate($messageId, $status);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Webhook processing error: ' . $e->getMessage());
    }

    http_response_code(200);
    echo 'OK';
    exit;
}

http_response_code(405);
echo 'Method Not Allowed';
