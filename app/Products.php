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
        $label = OfferCatalog::productLabel($key);
        if ($label !== null) {
            return $label;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT label FROM campaign_products WHERE product_key = :k ORDER BY id DESC LIMIT 1');
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        return $row ? (string) $row['label'] : null;
    }

    public static function isValidSession(string $session): bool
    {
        return in_array($session, self::SESSIONS, true);
    }
}
