<?php
/**
 * app/SmsAlertService.php — SMS Alert (smsalert.co.in) HTTP API.
 * Docs: push.json for custom OTP SMS; sender ID from account (BEDSOL).
 */

declare(strict_types=1);

final class SmsAlertService
{
    public static function isConfigured(): bool
    {
        global $CONFIG;
        $cfg = $CONFIG['smsalert'] ?? [];
        if (!empty($cfg['apikey'])) {
            return true;
        }
        return !empty($cfg['user']) && !empty($cfg['password']);
    }

    public static function isEnabled(): bool
    {
        global $CONFIG;
        return !empty($CONFIG['smsalert']['enabled']) && self::isConfigured();
    }

    /**
     * Send plain SMS via push.json.
     * @return array{ok:bool, error?:string, response?:mixed}
     */
    public static function sendSms(string $mobileE164, string $text): array
    {
        global $CONFIG;
        $cfg = $CONFIG['smsalert'] ?? [];

        if (!self::isConfigured()) {
            return ['ok' => false, 'error' => 'SMS Alert is not configured.'];
        }

        // API accepts with or without 91; we send 10-digit or 91XXXXXXXXXX
        $digits = preg_replace('/\D+/', '', $mobileE164) ?? '';
        if (str_starts_with($digits, '91') && strlen($digits) === 12) {
            $mobileno = $digits;
        } elseif (strlen($digits) === 10) {
            $mobileno = '91' . $digits;
        } else {
            return ['ok' => false, 'error' => 'Invalid mobile number for SMS.'];
        }

        $params = [
            'sender' => (string) ($cfg['sender'] ?? 'BEDSOL'),
            'mobileno' => $mobileno,
            'text' => $text,
        ];
        if (!empty($cfg['apikey'])) {
            $params['apikey'] = (string) $cfg['apikey'];
        } else {
            $params['user'] = (string) $cfg['user'];
            $params['pwd'] = (string) $cfg['password'];
        }
        if (!empty($cfg['route'])) {
            $params['route'] = (string) $cfg['route'];
        }

        $url = 'https://www.smsalert.co.in/api/push.json?' . http_build_query($params);
        $raw = self::httpGet($url);
        if ($raw === null) {
            return ['ok' => false, 'error' => 'Could not reach SMS Alert API.'];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'Unexpected SMS Alert response.', 'response' => $raw];
        }

        $status = strtolower((string) ($json['status'] ?? ''));
        if ($status === 'error' || $status === 'failure' || $status === 'fail') {
            $err = (string) ($json['description']['desc'] ?? $json['description'] ?? $json['message'] ?? 'SMS send failed');
            if (is_array($json['description'] ?? null)) {
                $err = (string) ($json['description']['desc'] ?? $err);
            }
            return ['ok' => false, 'error' => mb_substr($err, 0, 200), 'response' => $json];
        }
        if ($status === 'success' || $status === 'ok') {
            return ['ok' => true, 'response' => $json];
        }
        // Fallback: presence of message id / batch usually means accepted
        if (isset($json['description']['batch_id']) || isset($json['description']['msgid']) || isset($json['batch_id'])) {
            return ['ok' => true, 'response' => $json];
        }

        $err = is_string($json['description'] ?? null)
            ? (string) $json['description']
            : (string) ($json['description']['desc'] ?? $raw);
        return ['ok' => false, 'error' => mb_substr($err, 0, 200), 'response' => $json];
    }

    private static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $ca = APP_ROOT . '/storage/certs/cacert.pem';
            if (is_file($ca)) {
                curl_setopt($ch, CURLOPT_CAINFO, $ca);
            }
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code >= 500) {
                return null;
            }
            return (string) $body;
        }

        $ctx = stream_context_create(['http' => ['timeout' => 20], 'ssl' => ['verify_peer' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }
}
