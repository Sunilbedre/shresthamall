<?php
/**
 * app/Settings.php
 * Cached read/write access to the `settings` key-value table.
 */

declare(strict_types=1);

final class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $pdo = Database::connection();
            $rows = $pdo->query("SELECT key_name, value FROM settings")->fetchAll();
            self::$cache = [];
            foreach ($rows as $row) {
                self::$cache[$row['key_name']] = $row['value'];
            }
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            INSERT INTO settings (key_name, value) VALUES (:k, :v)
            ON CONFLICT(key_name) DO UPDATE SET value = :v2
        ");
        $stmt->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
        self::$cache = null; // invalidate cache
    }

    public static function setMany(array $pairs): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO settings (key_name, value) VALUES (:k, :v)
                ON CONFLICT(key_name) DO UPDATE SET value = :v2
            ");
            foreach ($pairs as $k => $v) {
                $stmt->execute(['k' => $k, 'v' => $v, 'v2' => $v]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::$cache = null;
    }
}
