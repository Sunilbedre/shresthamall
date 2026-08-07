<?php
/**
 * app/VoucherService.php
 * Voucher code generation and time/status verification logic.
 */

declare(strict_types=1);

final class VoucherService
{
    // Excludes confusing characters: O, 0, I, 1
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function generateCode(): string
    {
        $pdo = Database::connection();
        do {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            $code = 'SFS1-' . $suffix;

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM vouchers WHERE voucher_code = :code");
            $stmt->execute(['code' => $code]);
            $exists = (int) $stmt->fetchColumn() > 0;
        } while ($exists);

        return $code;
    }

    /** Returns ['start' => DateTimeImmutable, 'end' => DateTimeImmutable] for a session on the event date. */
    public static function sessionWindow(string $eventDate, string $session): array
    {
        $startKey = $session === 'morning' ? 'morning_start' : 'evening_start';
        $endKey   = $session === 'morning' ? 'morning_end' : 'evening_end';

        $start = new DateTimeImmutable($eventDate . ' ' . Settings::get($startKey, '00:00'), new DateTimeZone('Asia/Kolkata'));
        $end   = new DateTimeImmutable($eventDate . ' ' . Settings::get($endKey, '23:59'), new DateTimeZone('Asia/Kolkata'));

        return ['start' => $start, 'end' => $end];
    }

    public static function formatSessionLabel(string $session): string
    {
        $window = self::sessionWindow(date('Y-m-d'), $session); // date irrelevant, only formatting times
        return $window['start']->format('g:i A') . ' – ' . $window['end']->format('g:i A');
    }

    /**
     * Determines the live status of a voucher for display at the verification counter.
     * Returns one of: VALID, NOT_ACTIVE_YET, TIME_EXPIRED, ALREADY_REDEEMED, BLOCKED, CANCELLED
     */
    public static function checkStatus(array $voucher): string
    {
        if ($voucher['status'] === 'REDEEMED') {
            return 'ALREADY_REDEEMED';
        }
        if ($voucher['status'] === 'BLOCKED') {
            return 'BLOCKED';
        }
        if ($voucher['status'] === 'CANCELLED') {
            return 'CANCELLED';
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
        $start = new DateTimeImmutable($voucher['session_start'], new DateTimeZone('Asia/Kolkata'));
        $end   = new DateTimeImmutable($voucher['session_end'], new DateTimeZone('Asia/Kolkata'));

        if ($now < $start) {
            return 'NOT_ACTIVE_YET';
        }
        if ($now > $end) {
            return 'TIME_EXPIRED';
        }
        return 'VALID';
    }
}
