<?php
/**
 * app/OtpService.php — generate / verify mobile OTP.
 * Delivery order: WhatsApp (otptemp) → SMS Alert (fallback when DLT active).
 */

declare(strict_types=1);

final class OtpService
{
    private const SESSION_KEY = 'mobile_otp';
    private const TTL_SECONDS = 600; // 10 minutes
    private const MAX_SENDS_PER_MOBILE = 5;
    private const MAX_VERIFY_ATTEMPTS = 5;

    /** Default SMS body — matches approved template style (bedreportalotp). */
    public const DEFAULT_MESSAGE = 'Your OTP for login to {portal} web portal is {otp}. Valid for 10 mins. Do not share this OTP. -BEDRES';

    /**
     * @return array{ok:bool, error?:string, cooldown?:int, channel?:string}
     */
    public static function send(string $mobileE164, string $ip): array
    {
        global $CONFIG;

        $existing = CustomerService::findByMobile($mobileE164);
        if ($existing !== null) {
            return ['ok' => false, 'error' => 'This mobile number already used a voucher earlier.'];
        }

        if (AuthService::rateLimited('otp_ip_' . $ip, 12, 600)) {
            return ['ok' => false, 'error' => 'Too many OTP requests. Please wait a few minutes.'];
        }

        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_array($state)
            && ($state['mobile'] ?? '') === $mobileE164
            && (int) ($state['sent_at'] ?? 0) > time() - 45
        ) {
            $wait = 45 - (time() - (int) $state['sent_at']);
            return ['ok' => false, 'error' => 'Please wait before requesting another OTP.', 'cooldown' => max(1, $wait)];
        }

        $sends = (int) ($state['send_count'] ?? 0);
        if (is_array($state) && ($state['mobile'] ?? '') === $mobileE164 && $sends >= self::MAX_SENDS_PER_MOBILE) {
            return ['ok' => false, 'error' => 'OTP limit reached for this number. Try again later.'];
        }

        $otp = (string) random_int(100000, 999999);

        // Send on BOTH channels where possible.
        $waOk = false;
        $smsOk = false;
        $waErr = '';
        $smsErr = '';

        $waResult = WhatsAppService::sendOtp($mobileE164, $otp);
        if ($waResult['ok'] ?? false) {
            $waOk = true;
        } else {
            $waErr = (string) ($waResult['error'] ?? 'WhatsApp OTP failed');
        }

        if (SmsAlertService::isEnabled()) {
            $template = (string) ($CONFIG['smsalert']['otp_message'] ?? self::DEFAULT_MESSAGE);
            $portal = (string) ($CONFIG['smsalert']['otp_portal_name'] ?? 'Shreeshta Family Store');
            $text = str_replace(['{otp}', '{portal}'], [$otp, $portal], $template);
            $smsResult = SmsAlertService::sendSms($mobileE164, $text);
            if ($smsResult['ok'] ?? false) {
                $smsOk = true;
            } else {
                $smsErr = (string) ($smsResult['error'] ?? 'SMS OTP failed');
            }
        }

        if (!$waOk && !$smsOk) {
            $joined = trim(($waErr !== '' ? 'WhatsApp: ' . $waErr : '') . ($smsErr !== '' ? ' | SMS: ' . $smsErr : ''));
            return ['ok' => false, 'error' => ($joined !== '' ? $joined : 'Could not send OTP. Please try again.')];
        }

        $_SESSION[self::SESSION_KEY] = [
            'mobile'          => $mobileE164,
            'hash'            => password_hash($otp, PASSWORD_DEFAULT),
            'expires'         => time() + self::TTL_SECONDS,
            'sent_at'         => time(),
            'send_count'      => (is_array($state) && ($state['mobile'] ?? '') === $mobileE164) ? $sends + 1 : 1,
            'verify_attempts' => 0,
            'verified'        => false,
            'channel'         => $waOk && $smsOk ? 'both' : ($waOk ? 'whatsapp' : 'sms'),
        ];

        $msg = $waOk && $smsOk
            ? 'OTP sent to both WhatsApp and SMS. Valid for 10 minutes.'
            : ($waOk
                ? 'OTP sent to your WhatsApp. Valid for 10 minutes.'
                : 'OTP sent via SMS. Valid for 10 minutes.');

        return ['ok' => true, 'channel' => ($waOk && $smsOk ? 'both' : ($waOk ? 'whatsapp' : 'sms')), 'message' => $msg];
    }

    /**
     * @return array{ok:bool, error?:string}
     */
    public static function verify(string $mobileE164, string $code): array
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return ['ok' => false, 'error' => 'Enter the 6-digit OTP.'];
        }

        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($state) || ($state['mobile'] ?? '') !== $mobileE164) {
            return ['ok' => false, 'error' => 'Please request an OTP for this number first.'];
        }
        if ((int) ($state['expires'] ?? 0) < time()) {
            return ['ok' => false, 'error' => 'OTP expired. Please request a new one.'];
        }
        if ((int) ($state['verify_attempts'] ?? 0) >= self::MAX_VERIFY_ATTEMPTS) {
            return ['ok' => false, 'error' => 'Too many wrong attempts. Request a new OTP.'];
        }

        if (!password_verify($code, (string) $state['hash'])) {
            $_SESSION[self::SESSION_KEY]['verify_attempts'] = (int) ($state['verify_attempts'] ?? 0) + 1;
            return ['ok' => false, 'error' => 'Incorrect OTP. Please try again.'];
        }

        $_SESSION[self::SESSION_KEY]['verified'] = true;
        $_SESSION[self::SESSION_KEY]['verified_at'] = time();
        return ['ok' => true];
    }

    public static function isVerified(string $mobileE164): bool
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($state)) {
            return false;
        }
        if (($state['mobile'] ?? '') !== $mobileE164) {
            return false;
        }
        if (empty($state['verified'])) {
            return false;
        }
        // Verified OTP valid for registration window (30 min)
        return (int) ($state['verified_at'] ?? 0) > time() - 1800;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Staff counter: send OTP to customer for redemption verification.
     * Does NOT block on already-registered numbers.
     * Uses a separate session key so it doesn't conflict with registration OTPs.
     * @return array{ok:bool, error?:string, channel?:string, cooldown?:int}
     */
    public static function sendForRedemption(string $mobileE164, string $ip): array
    {
        global $CONFIG;
        $key = 'redeem_otp';

        if (AuthService::rateLimited('redeem_otp_ip_' . $ip, 20, 600)) {
            return ['ok' => false, 'error' => 'Too many OTP requests from this device.'];
        }

        $state = $_SESSION[$key] ?? null;
        if (is_array($state)
            && ($state['mobile'] ?? '') === $mobileE164
            && (int) ($state['sent_at'] ?? 0) > time() - 45
        ) {
            $wait = 45 - (time() - (int) $state['sent_at']);
            return ['ok' => false, 'error' => 'Please wait before resending OTP.', 'cooldown' => max(1, $wait)];
        }

        $otp = (string) random_int(100000, 999999);

        $waOk = false;
        $smsOk = false;
        $waErr = '';
        $smsErr = '';

        $waResult = WhatsAppService::sendOtp($mobileE164, $otp);
        if ($waResult['ok'] ?? false) {
            $waOk = true;
        } else {
            $waErr = (string) ($waResult['error'] ?? 'WhatsApp OTP failed');
        }

        if (SmsAlertService::isEnabled()) {
            $template = (string) ($CONFIG['smsalert']['otp_message'] ?? self::DEFAULT_MESSAGE);
            $portal = (string) ($CONFIG['smsalert']['otp_portal_name'] ?? 'Shreeshta Family Store');
            $text = str_replace(['{otp}', '{portal}'], [$otp, $portal], $template);
            $smsResult = SmsAlertService::sendSms($mobileE164, $text);
            if ($smsResult['ok'] ?? false) {
                $smsOk = true;
            } else {
                $smsErr = (string) ($smsResult['error'] ?? 'SMS OTP failed');
            }
        }

        if (!$waOk && !$smsOk) {
            $joined = trim(($waErr !== '' ? 'WhatsApp: ' . $waErr : '') . ($smsErr !== '' ? ' | SMS: ' . $smsErr : ''));
            return ['ok' => false, 'error' => ($joined !== '' ? $joined : 'Could not send OTP.')];
        }

        $sends = (is_array($state) && ($state['mobile'] ?? '') === $mobileE164)
            ? (int) ($state['send_count'] ?? 0) + 1 : 1;

        $_SESSION[$key] = [
            'mobile'          => $mobileE164,
            'hash'            => password_hash($otp, PASSWORD_DEFAULT),
            'expires'         => time() + self::TTL_SECONDS,
            'sent_at'         => time(),
            'send_count'      => $sends,
            'verify_attempts' => 0,
            'verified'        => false,
            'channel'         => $waOk && $smsOk ? 'both' : ($waOk ? 'whatsapp' : 'sms'),
        ];

        return ['ok' => true, 'channel' => ($waOk && $smsOk ? 'both' : ($waOk ? 'whatsapp' : 'sms'))];
    }

    /**
     * Verify redemption OTP entered by staff on behalf of customer.
     * @return array{ok:bool, error?:string}
     */
    public static function verifyForRedemption(string $mobileE164, string $code): array
    {
        $key  = 'redeem_otp';
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return ['ok' => false, 'error' => 'Enter the 6-digit OTP.'];
        }
        $state = $_SESSION[$key] ?? null;
        if (!is_array($state) || ($state['mobile'] ?? '') !== $mobileE164) {
            return ['ok' => false, 'error' => 'Please send OTP first.'];
        }
        if ((int) ($state['expires'] ?? 0) < time()) {
            return ['ok' => false, 'error' => 'OTP expired. Send a new one.'];
        }
        if ((int) ($state['verify_attempts'] ?? 0) >= self::MAX_VERIFY_ATTEMPTS) {
            return ['ok' => false, 'error' => 'Too many wrong attempts. Send a new OTP.'];
        }
        if (!password_verify($code, (string) $state['hash'])) {
            $_SESSION[$key]['verify_attempts'] = (int) ($state['verify_attempts'] ?? 0) + 1;
            return ['ok' => false, 'error' => 'Incorrect OTP. Please try again.'];
        }
        $_SESSION[$key]['verified']    = true;
        $_SESSION[$key]['verified_at'] = time();
        return ['ok' => true];
    }

    public static function clearRedemption(): void
    {
        unset($_SESSION['redeem_otp']);
    }
}
