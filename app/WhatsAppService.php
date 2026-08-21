<?php
/**
 * app/WhatsAppService.php
 * Sends the voucher via Meta WhatsApp Cloud API using an approved template.
 * Never exposes the access token to the frontend — this file only ever runs
 * server-side.
 */

declare(strict_types=1);

final class WhatsAppService
{
    /**
     * Sends the voucher template message to the customer and logs the attempt.
     * Does not throw on API failure — failure is recorded as a FAILED log row
     * so the registration itself is never lost.
     */
    public static function sendVoucher(array $customer, array $voucher): array
    {
        global $CONFIG;
        $wa = $CONFIG['whatsapp'];
        $pdo = Database::connection();

        $productLabel = Products::label($customer['selected_product']) ?? $customer['selected_product'];
        $productLabel = self::waProductLabel($productLabel);
        $sessionLabel = VoucherService::formatVoucherTimeLabel($voucher);
        $eventDateFormatted = (new DateTimeImmutable($voucher['event_date']))->format('j F Y');

        $storeName = Settings::get('store_name') . ', ' . Settings::get('branch_name');

        $templateName = $wa['template_name'];

        // Body variables for `your_1_special_offer` (no IMAGE header):
        // {{1}} name · {{2}} voucher · {{3}} product · {{4}} date · {{5}} time · {{6}} store
        $components = [
            [
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => self::waText($customer['full_name'], 50)],
                    ['type' => 'text', 'text' => self::waText($voucher['voucher_code'], 20)],
                    ['type' => 'text', 'text' => self::waText($productLabel, 80)],
                    ['type' => 'text', 'text' => self::waText($eventDateFormatted, 30)],
                    ['type' => 'text', 'text' => self::waText($sessionLabel, 30)],
                    ['type' => 'text', 'text' => self::waText($storeName, 80)],
                ],
            ],
        ];

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($customer['mobile_number'], '+'),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $wa['template_lang'] ?: 'en'],
                'components' => $components,
            ],
        ];

        // Insert a PENDING log row first so we always have a record even if the HTTP call itself throws.
        $insert = $pdo->prepare("
            INSERT INTO whatsapp_logs (customer_id, voucher_id, template_name, status, created_at)
            VALUES (:customer_id, :voucher_id, :template_name, 'PENDING', datetime('now'))
        ");
        $insert->execute([
            'customer_id'   => $customer['id'],
            'voucher_id'    => $voucher['id'],
            'template_name' => $templateName,
        ]);
        $logId = (int) $pdo->lastInsertId();

        if (empty($wa['access_token']) || empty($wa['phone_number_id'])) {
            self::markLog($logId, 'FAILED', ['error' => 'WhatsApp API credentials not configured'], null);
            return ['ok' => false, 'reason' => 'not_configured'];
        }

        $url = "https://graph.facebook.com/{$wa['api_version']}/{$wa['phone_number_id']}/messages";

        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $wa['access_token'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 20,
        ];
        // Windows PHP often lacks a CA bundle — use the project-bundled cacert.pem.
        $caBundle = APP_ROOT . '/storage/certs/cacert.pem';
        if (is_file($caBundle)) {
            $curlOpts[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($ch, $curlOpts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            self::markLog($logId, 'FAILED', ['curl_error' => $curlError], null);
            return ['ok' => false, 'reason' => 'network_error'];
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['messages'][0]['id'])) {
            $messageId = $decoded['messages'][0]['id'];
            self::markLog($logId, 'SENT', $decoded, $messageId);
            return ['ok' => true, 'message_id' => $messageId];
        }

        self::markLog($logId, 'FAILED', $decoded ?? ['raw' => $response], null);
        return ['ok' => false, 'reason' => 'api_error', 'detail' => $decoded];
    }

    /**
     * Send OTP via WhatsApp using the `otptemp` authentication template.
     * Template format: {{1}} is your verification code.
     * @return array{ok:bool, error?:string}
     */
    public static function sendOtp(string $mobileE164, string $otp): array
    {
        global $CONFIG;
        $wa = $CONFIG['whatsapp'];

        if (empty($wa['access_token']) || empty($wa['phone_number_id'])) {
            return ['ok' => false, 'error' => 'WhatsApp not configured.'];
        }

        $otpTemplate = (string) ($wa['otp_template_name'] ?? 'otptemp');
        $otpLang     = (string) ($wa['otp_template_lang'] ?? 'en');

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'   => ltrim($mobileE164, '+'),
            'type' => 'template',
            'template' => [
                'name'     => $otpTemplate,
                'language' => ['code' => $otpLang],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $otp],
                        ],
                    ],
                    // button component for "Copy code" (index 0 = COPY_CODE button)
                    [
                        'type'     => 'button',
                        'sub_type' => 'url',
                        'index'    => '0',
                        'parameters' => [
                            ['type' => 'text', 'text' => $otp],
                        ],
                    ],
                ],
            ],
        ];

        $url = "https://graph.facebook.com/{$wa['api_version']}/{$wa['phone_number_id']}/messages";
        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $wa['access_token'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 20,
        ];
        $caBundle = APP_ROOT . '/storage/certs/cacert.pem';
        if (is_file($caBundle)) {
            $curlOpts[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($ch, $curlOpts);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'error' => 'Network error: ' . $curlErr];
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['messages'][0]['id'])) {
            return ['ok' => true];
        }

        $err = (string) ($decoded['error']['message'] ?? $response);
        return ['ok' => false, 'error' => mb_substr($err, 0, 200)];
    }

    /** WhatsApp body params: no newlines/tabs; normalize fancy punctuation. */
    private static function waText(?string $value, int $maxLen = 200): string
    {
        $value = trim((string) $value);
        $value = str_replace(["\r", "\n", "\t"], ' ', $value);
        $value = str_replace(['–', '—', '₹'], ['-', '-', 'Rs.'], $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return mb_substr($value, 0, $maxLen);
    }

    /** Keep marketing labels short for WhatsApp template limits. */
    private static function waProductLabel(?string $label): string
    {
        $label = trim((string) $label);
        if ($label === '') {
            return '';
        }
        if (stripos($label, 'Semi Kanjeevaram Sarees') !== false) {
            return 'Semi Kanjeevaram Sarees';
        }
        return $label;
    }

    /**
     * @return array{id?:string,link?:string}|null
     */
    private static function resolveHeaderImage(array $wa): ?array
    {
        $link = trim((string) ($wa['header_image_url'] ?? ''));
        if ($link !== '') {
            return ['link' => $link];
        }

        // Admin-uploaded header takes priority; only sync Meta sample if none exists.
        $adminPath = self::headerImagePath();
        if ($adminPath === null) {
            self::syncTemplateHeaderImage($wa);
            $adminPath = self::headerImagePath();
        }

        if ($adminPath === null) {
            return null;
        }

        $mediaId = self::uploadMedia($wa, $adminPath);
        return $mediaId !== null ? ['id' => $mediaId] : null;
    }

    /** Absolute path to the current WhatsApp header image, if present. */
    public static function headerImagePath(): ?string
    {
        $relative = Settings::get('whatsapp_header_image', 'public/assets/whatsapp-header.jpg');
        $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
        $full = APP_ROOT . '/' . $relative;
        return is_file($full) ? $full : null;
    }

    /** Public URL path for preview in admin (relative to site root). */
    public static function headerImagePublicUrl(): ?string
    {
        $relative = Settings::get('whatsapp_header_image', 'public/assets/whatsapp-header.jpg');
        $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
        if (!is_file(APP_ROOT . '/' . $relative)) {
            return null;
        }
        return '/' . $relative . '?v=' . filemtime(APP_ROOT . '/' . $relative);
    }

    /**
     * Save an uploaded header image for WhatsApp template sends.
     * @return array{ok:bool, error?:string}
     */
    public static function saveUploadedHeaderImage(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'No file uploaded.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload failed. Please try again.'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Invalid upload.'];
        }

        $info = @getimagesize($tmp);
        if ($info === false) {
            return ['ok' => false, 'error' => 'File must be a JPG or PNG image.'];
        }
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return ['ok' => false, 'error' => 'Only JPG, PNG or WebP images are allowed.'];
        }
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'Image must be under 5 MB.'];
        }

        $dir = APP_ROOT . '/public/assets';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $destRel = 'public/assets/whatsapp-header.jpg';
        $dest = APP_ROOT . '/' . $destRel;

        $src = @imagecreatefromstring((string) file_get_contents($tmp));
        if ($src === false) {
            return ['ok' => false, 'error' => 'Could not read the image.'];
        }
        // Flatten onto white background for JPEG
        $w = imagesx($src);
        $h = imagesy($src);
        $out = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefilledrectangle($out, 0, 0, $w, $h, $white);
        imagecopy($out, $src, 0, 0, 0, 0, $w, $h);
        $ok = imagejpeg($out, $dest, 90);
        imagedestroy($src);
        imagedestroy($out);
        if (!$ok) {
            return ['ok' => false, 'error' => 'Could not save the image.'];
        }

        Settings::set('whatsapp_header_image', $destRel);
        Settings::set('wa_header_media_id', '');
        Settings::set('wa_header_media_path', '');
        Settings::set('wa_header_synced_from_meta', '0'); // don't overwrite admin upload
        Settings::set('wa_header_admin_uploaded', '1');

        return ['ok' => true];
    }

    /** Download the approved template HEADER image once and store locally (only if admin has not uploaded). */
    private static function syncTemplateHeaderImage(array $wa): void
    {
        if (Settings::get('wa_header_admin_uploaded') === '1') {
            return;
        }
        $dest = APP_ROOT . '/public/assets/whatsapp-header.jpg';
        $flag = Settings::get('wa_header_synced_from_meta');
        if ($flag === '1' && is_file($dest) && filesize($dest) > 1000) {
            return;
        }
        if (empty($wa['access_token']) || empty($wa['business_account_id']) || empty($wa['template_name'])) {
            return;
        }

        $caBundle = APP_ROOT . '/storage/certs/cacert.pem';
        $url = "https://graph.facebook.com/{$wa['api_version']}/{$wa['business_account_id']}/message_templates"
            . '?name=' . rawurlencode((string) $wa['template_name'])
            . '&fields=name,components';

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $wa['access_token']],
            CURLOPT_TIMEOUT => 25,
        ];
        if (is_file($caBundle)) {
            $opts[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response === false) {
            return;
        }

        $decoded = json_decode($response, true);
        $handle = null;
        foreach (($decoded['data'][0]['components'] ?? []) as $comp) {
            if (($comp['type'] ?? '') === 'HEADER' && !empty($comp['example']['header_handle'][0])) {
                $handle = $comp['example']['header_handle'][0];
                break;
            }
        }
        if (!$handle) {
            return;
        }

        $ch = curl_init($handle);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
        ];
        if (is_file($caBundle)) {
            $opts[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($ch, $opts);
        $img = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($img === false || $http >= 400 || strlen($img) < 500) {
            return;
        }

        // Ensure JPEG for SimplePdf/media upload compatibility
        if (function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring($img);
            if ($src !== false) {
                ob_start();
                imagejpeg($src, null, 90);
                imagedestroy($src);
                $img = (string) ob_get_clean();
            }
        }

        $dir = dirname($dest);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dest, $img);
        Settings::set('wa_header_media_id', '');
        Settings::set('wa_header_media_path', '');
        Settings::set('wa_header_synced_from_meta', '1');
    }

    private static function uploadMedia(array $wa, string $filePath): ?string
    {
        $cacheKey = 'wa_header_media_id';
        $cacheMetaKey = 'wa_header_media_path';
        $cachedId = Settings::get($cacheKey);
        $cachedPath = Settings::get($cacheMetaKey);
        if (!empty($cachedId) && $cachedPath === $filePath) {
            return $cachedId;
        }

        $mime = match (strtolower(pathinfo($filePath, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        $url = "https://graph.facebook.com/{$wa['api_version']}/{$wa['phone_number_id']}/media";
        $caBundle = APP_ROOT . '/storage/certs/cacert.pem';

        $cfile = new CURLFile($filePath, $mime, basename($filePath));
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $wa['access_token'],
            ],
            CURLOPT_POSTFIELDS => [
                'messaging_product' => 'whatsapp',
                'type' => $mime,
                'file' => $cfile,
            ],
            CURLOPT_TIMEOUT => 30,
        ];
        if (is_file($caBundle)) {
            $opts[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }
        $decoded = json_decode($response, true);
        $mediaId = $decoded['id'] ?? null;
        if (!is_string($mediaId) || $mediaId === '') {
            return null;
        }

        Settings::set($cacheKey, $mediaId);
        Settings::set($cacheMetaKey, $filePath);
        return $mediaId;
    }

    private static function markLog(int $logId, string $status, ?array $apiResponse, ?string $messageId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            UPDATE whatsapp_logs
            SET status = :status,
                api_response = :api_response,
                message_id = COALESCE(:message_id, message_id),
                sent_at = CASE WHEN :status2 = 'SENT' THEN datetime('now') ELSE sent_at END,
                failed_at = CASE WHEN :status3 = 'FAILED' THEN datetime('now') ELSE failed_at END
            WHERE id = :id
        ");
        $stmt->execute([
            'status' => $status,
            'api_response' => $apiResponse ? json_encode($apiResponse, JSON_UNESCAPED_UNICODE) : null,
            'message_id' => $messageId,
            'status2' => $status,
            'status3' => $status,
            'id' => $logId,
        ]);
    }

    /** Latest WhatsApp log row for a customer, or null. */
    public static function latestLog(int $customerId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM whatsapp_logs WHERE customer_id = :cid ORDER BY id DESC LIMIT 1");
        $stmt->execute(['cid' => $customerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** How many resend attempts have happened today for this customer. */
    public static function resendsToday(int $customerId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM whatsapp_logs
            WHERE customer_id = :cid AND date(created_at) = date('now')
        ");
        $stmt->execute(['cid' => $customerId]);
        return (int) $stmt->fetchColumn();
    }

    /** Applies webhook delivery-status updates (delivered/read/failed) by message_id. */
    public static function applyStatusUpdate(string $messageId, string $status): void
    {
        $pdo = Database::connection();
        $column = match ($status) {
            'delivered' => 'delivered_at',
            'read' => 'read_at',
            'failed' => 'failed_at',
            default => null,
        };
        if ($column === null) {
            return;
        }
        $statusUpper = strtoupper($status);
        $stmt = $pdo->prepare("
            UPDATE whatsapp_logs
            SET status = :status, {$column} = datetime('now')
            WHERE message_id = :mid
        ");
        $stmt->execute(['status' => $statusUpper, 'mid' => $messageId]);
    }
}
