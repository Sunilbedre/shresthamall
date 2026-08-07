<?php
/**
 * app/Validation.php
 * Server-side validation. This is the source of truth — client-side JS
 * validation is a UX nicety only, never trusted.
 */

declare(strict_types=1);

final class Validation
{
    /**
     * Normalises a raw Indian mobile number to +91XXXXXXXXXX form.
     * Returns null if the number is not a valid 10-digit Indian mobile number.
     */
    public static function normaliseMobile(string $raw): ?string
    {
        // Strip everything except digits
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        // Strip a leading country code of 91 if the user typed +91XXXXXXXXXX / 91XXXXXXXXXX
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        // Strip a leading 0 (some people type 0XXXXXXXXXX)
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return null;
        }
        if (!preg_match('/^[6-9]\d{9}$/', $digits)) {
            return null;
        }

        return '+91' . $digits;
    }

    public static function maskMobile(string $normalised): string
    {
        // +91XXXXXXXXXX -> +91XXXXX•••••  (show first 5, mask last 5)
        if (strlen($normalised) !== 13) {
            return $normalised;
        }
        return substr($normalised, 0, 8) . str_repeat('•', 5);
    }

    public static function validateName(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $len = mb_strlen($name);
        if ($len < 2 || $len > 80) {
            return null;
        }
        // Letters, spaces, dots, apostrophes, hyphens only
        if (!preg_match('/^[\p{L}\p{M}\.\'\-\s]+$/u', $name)) {
            return null;
        }
        return $name;
    }

    public static function validateProductKey(string $key): bool
    {
        return Products::exists($key);
    }

    public static function validateArea(string $area): ?string
    {
        $area = trim($area);
        if ($area === '' || !Areas::isValid($area)) {
            return null;
        }
        return $area;
    }
}
