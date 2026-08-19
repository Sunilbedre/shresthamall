<?php
/**
 * app/Products.php
 * Compatibility layer over OfferCatalog (DB-backed dynamic products).
 */

declare(strict_types=1);

final class Products
{
    public const SESSIONS = ['morning', 'evening'];

    public static function all(): array
    {
        $out = [];
        foreach (OfferCatalog::products(false) as $p) {
            $out[$p['product_key']] = [
                'label' => $p['label'],
                'active' => (int) $p['active'] === 1,
            ];
        }
        return $out;
    }

    public static function exists(string $key): bool
    {
        return OfferCatalog::productExists($key);
    }

    public static function label(string $key): ?string
    {
        return OfferCatalog::productLabel($key);
    }

    public static function isValidSession(string $session): bool
    {
        return in_array($session, self::SESSIONS, true);
    }
}
