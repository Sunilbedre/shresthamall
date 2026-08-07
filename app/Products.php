<?php
/**
 * app/Products.php
 * Offer catalogue + per-product-per-slot registration limits.
 * Session/time slot is chosen separately on the form (not tied to product).
 */

declare(strict_types=1);

final class Products
{
    /**
     * key => [ label, limit_setting_key, default_limit_per_slot ]
     * Saree: 500 per slot · all others: 100 per slot
     */
    private const CATALOGUE = [
        'saree' => [
            'label' => '₹1 Saree',
            'limit_key' => 'limit_saree',
            'default_limit' => 500,
        ],
        'kids_tshirt_boys' => [
            'label' => '₹1 Kids T-Shirt – Boys',
            'limit_key' => 'limit_kids_boys',
            'default_limit' => 100,
        ],
        'kids_tshirt_girls' => [
            'label' => '₹1 Kids T-Shirt – Girls',
            'limit_key' => 'limit_kids_girls',
            'default_limit' => 100,
        ],
        'womens_leggings' => [
            'label' => "₹1 Women's Leggings",
            'limit_key' => 'limit_leggings',
            'default_limit' => 100,
        ],
        'womens_kurti' => [
            'label' => "₹1 Women's Kurti",
            'limit_key' => 'limit_kurti',
            'default_limit' => 100,
        ],
        'mens_shirt' => [
            'label' => "₹1 Men's Shirt",
            'limit_key' => 'limit_mens_shirt',
            'default_limit' => 100,
        ],
    ];

    public const SESSIONS = ['morning', 'evening'];

    public static function all(): array
    {
        return self::CATALOGUE;
    }

    public static function exists(string $key): bool
    {
        return isset(self::CATALOGUE[$key]);
    }

    public static function label(string $key): ?string
    {
        return self::CATALOGUE[$key]['label'] ?? null;
    }

    public static function limitKeyFor(string $key): ?string
    {
        return self::CATALOGUE[$key]['limit_key'] ?? null;
    }

    public static function defaultLimit(string $key): int
    {
        return (int) (self::CATALOGUE[$key]['default_limit'] ?? 100);
    }

    public static function isValidSession(string $session): bool
    {
        return in_array($session, self::SESSIONS, true);
    }
}
