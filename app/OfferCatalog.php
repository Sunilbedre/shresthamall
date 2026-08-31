<?php
/**
 * app/OfferCatalog.php
 * Dynamic products + date/time slots with per-slot capacity.
 * Admin can change catalogue anytime; form only shows open slots with remaining stock.
 */

declare(strict_types=1);

final class OfferCatalog
{
    public static function ensureSchema(): void
    {
        $pdo = Database::connection();

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS offer_products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_key TEXT NOT NULL UNIQUE,
                label TEXT NOT NULL,
                active INTEGER NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS offer_slots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_key TEXT NOT NULL,
                event_date TEXT NOT NULL,
                session TEXT NOT NULL,
                slot_start TEXT NOT NULL,
                slot_end TEXT NOT NULL,
                capacity INTEGER NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(product_key, event_date, session),
                FOREIGN KEY (product_key) REFERENCES offer_products(product_key) ON DELETE CASCADE
            );
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_offer_slots_product ON offer_slots(product_key);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_offer_slots_date ON offer_slots(event_date);");

        // Extend customers for multi-date offers
        $cols = $pdo->query("PRAGMA table_info(customers)")->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        if (!in_array('event_date', $names, true)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN event_date TEXT NULL");
        }
        if (!in_array('offer_slot_id', $names, true)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN offer_slot_id INTEGER NULL");
        }

        self::seedIfEmpty();
    }

    private static function seedIfEmpty(): void
    {
        $pdo = Database::connection();
        $count = (int) $pdo->query("SELECT COUNT(*) FROM offer_products")->fetchColumn();
        if ($count > 0) {
            return;
        }

        // Default catalogue from current client brief (editable in Admin → Offers)
        $products = [
            ['rupee1_saree', '1 Rupee Saree', 1],
            ['semi_kanjeevaram_599', 'Semi Kanjeevaram Sarees — Get 2 Sarees for Just ₹599/-', 2],
        ];
        $insP = $pdo->prepare("
            INSERT INTO offer_products (product_key, label, active, sort_order)
            VALUES (:k, :l, 1, :s)
        ");
        foreach ($products as [$key, $label, $sort]) {
            $insP->execute(['k' => $key, 'l' => $label, 's' => $sort]);
        }

        $slots = [
            // 1 Rupee Saree — 22 Sat + 23 Sun, both slots, 250 each
            ['rupee1_saree', '2026-08-22', 'morning', '11:30', '14:30', 250],
            ['rupee1_saree', '2026-08-22', 'evening', '17:00', '20:00', 250],
            ['rupee1_saree', '2026-08-23', 'morning', '11:30', '14:30', 250],
            ['rupee1_saree', '2026-08-23', 'evening', '17:00', '20:00', 250],
            // Semi Kanjeevaram — 22 + 23 morning only, 200 each
            ['semi_kanjeevaram_599', '2026-08-22', 'morning', '11:30', '14:30', 200],
            ['semi_kanjeevaram_599', '2026-08-23', 'morning', '11:30', '14:30', 200],
        ];
        $insS = $pdo->prepare("
            INSERT INTO offer_slots (product_key, event_date, session, slot_start, slot_end, capacity, active)
            VALUES (:p, :d, :s, :st, :en, :c, 1)
        ");
        foreach ($slots as [$p, $d, $s, $st, $en, $c]) {
            $insS->execute(['p' => $p, 'd' => $d, 's' => $s, 'st' => $st, 'en' => $en, 'c' => $c]);
        }
    }

    /** @return list<array{product_key:string,label:string,active:int,sort_order:int,id:int}> */
    public static function products(bool $activeOnly = true): array
    {
        $pdo = Database::connection();
        $sql = "SELECT * FROM offer_products";
        if ($activeOnly) {
            $sql .= " WHERE active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";
        return $pdo->query($sql)->fetchAll();
    }

    public static function productLabel(string $key): ?string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT label FROM offer_products WHERE product_key = :k LIMIT 1");
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        return $row ? (string) $row['label'] : null;
    }

    public static function productExists(string $key): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT 1 FROM offer_products WHERE product_key = :k LIMIT 1");
        $stmt->execute(['k' => $key]);
        return (bool) $stmt->fetchColumn();
    }

    public static function findSlot(int $slotId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM offer_slots WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $slotId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Booked count for a slot (product + date + session). */
    public static function bookedCount(array $slot): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM customers
            WHERE selected_product = :p AND session = :s AND event_date = :d
        ");
        $stmt->execute([
            'p' => $slot['product_key'],
            's' => $slot['session'],
            'd' => $slot['event_date'],
        ]);
        return (int) $stmt->fetchColumn();
    }

    public static function remaining(array $slot): int
    {
        return max(0, (int) $slot['capacity'] - self::bookedCount($slot));
    }

    /**
     * Open slots for public form (active product + active slot + remaining > 0).
     * @return list<array>
     */
    public static function availableSlots(?string $productKey = null): array
    {
        $pdo = Database::connection();
        $sql = "
            SELECT s.*, p.label AS product_label
            FROM offer_slots s
            INNER JOIN offer_products p ON p.product_key = s.product_key
            WHERE s.active = 1 AND p.active = 1
        ";
        $params = [];
        if ($productKey !== null && $productKey !== '') {
            $sql .= " AND s.product_key = :p";
            $params['p'] = $productKey;
        }
        $sql .= " ORDER BY s.event_date ASC, s.slot_start ASC, p.sort_order ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $left = self::remaining($row);
            if ($left <= 0) {
                continue;
            }
            $row['booked'] = (int) $row['capacity'] - $left;
            $row['remaining'] = $left;
            $row['label'] = self::formatSlotLabel($row);
            $out[] = $row;
        }
        return $out;
    }

    public static function formatSlotLabel(array $slot): string
    {
        $date = new DateTimeImmutable($slot['event_date']);
        $day = $date->format('D, d M Y'); // Sat, 22 Aug 2026
        $start = self::formatTime($slot['slot_start']);
        $end = self::formatTime($slot['slot_end']);
        return "{$day} · {$start} – {$end}";
    }

    public static function formatTime(string $hhmm): string
    {
        try {
            return (new DateTimeImmutable('2000-01-01 ' . $hhmm))->format('g:i A');
        } catch (Throwable $e) {
            return $hhmm;
        }
    }

    public static function formatSessionRange(array $slot): string
    {
        return self::formatTime($slot['slot_start']) . ' – ' . self::formatTime($slot['slot_end']);
    }

    public static function upsertProduct(string $key, string $label, int $sortOrder = 0, bool $active = true): array
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_]+/', '_', $key) ?? $key;
        $key = trim($key, '_');
        if ($key === '' || strlen($key) < 2) {
            return ['ok' => false, 'error' => 'Invalid product key.'];
        }
        $label = trim($label);
        if ($label === '') {
            return ['ok' => false, 'error' => 'Product name is required.'];
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            INSERT INTO offer_products (product_key, label, active, sort_order)
            VALUES (:k, :l, :a, :s)
            ON CONFLICT(product_key) DO UPDATE SET
                label = excluded.label,
                active = excluded.active,
                sort_order = excluded.sort_order
        ");
        $stmt->execute([
            'k' => $key,
            'l' => $label,
            'a' => $active ? 1 : 0,
            's' => $sortOrder,
        ]);
        return ['ok' => true, 'product_key' => $key];
    }

    public static function addSlot(
        string $productKey,
        string $eventDate,
        string $session,
        string $slotStart,
        string $slotEnd,
        int $capacity
    ): array {
        if (!self::productExists($productKey)) {
            return ['ok' => false, 'error' => 'Product not found.'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            return ['ok' => false, 'error' => 'Invalid date.'];
        }
        if (!in_array($session, ['morning', 'evening'], true)) {
            return ['ok' => false, 'error' => 'Session must be morning or evening.'];
        }
        if ($capacity < 1) {
            return ['ok' => false, 'error' => 'Capacity must be at least 1.'];
        }

        $pdo = Database::connection();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO offer_slots (product_key, event_date, session, slot_start, slot_end, capacity, active)
                VALUES (:p, :d, :s, :st, :en, :c, 1)
            ");
            $stmt->execute([
                'p' => $productKey,
                'd' => $eventDate,
                's' => $session,
                'st' => $slotStart,
                'en' => $slotEnd,
                'c' => $capacity,
            ]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return ['ok' => false, 'error' => 'This date + time slot already exists for the product.'];
            }
            throw $e;
        }
        return ['ok' => true];
    }

    public static function updateSlot(
        int $id,
        int $capacity,
        bool $active,
        string $slotStart,
        string $slotEnd,
        ?string $eventDate = null,
        ?string $session = null
    ): array
    {
        if ($capacity < 0) {
            return ['ok' => false, 'error' => 'Invalid capacity.'];
        }
        if ($eventDate !== null && $eventDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            return ['ok' => false, 'error' => 'Invalid event date.'];
        }
        if ($session !== null && $session !== '' && !in_array($session, ['morning', 'evening'], true)) {
            return ['ok' => false, 'error' => 'Invalid session.'];
        }

        $pdo = Database::connection();
        $sets = ['capacity = :c', 'active = :a', 'slot_start = :st', 'slot_end = :en'];
        $params = [
            'c' => $capacity,
            'a' => $active ? 1 : 0,
            'st' => $slotStart,
            'en' => $slotEnd,
            'id' => $id,
        ];
        if ($eventDate !== null && $eventDate !== '') {
            $sets[] = 'event_date = :ed';
            $params['ed'] = $eventDate;
        }
        if ($session !== null && $session !== '') {
            $sets[] = 'session = :sess';
            $params['sess'] = $session;
        }
        $sql = 'UPDATE offer_slots SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return ['ok' => true];
    }

    public static function setProductActive(string $key, bool $active): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("UPDATE offer_products SET active = :a WHERE product_key = :k");
        $stmt->execute(['a' => $active ? 1 : 0, 'k' => $key]);
    }

    /** Turn off every active slot (end this week / prepare next week). Past mobiles stay. */
    public static function deactivateAllActiveSlots(): int
    {
        $pdo = Database::connection();
        return $pdo->exec("UPDATE offer_slots SET active = 0 WHERE active = 1");
    }

    /** Soft-end slots whose event_date is before today (Asia/Kolkata). */
    public static function deactivatePastSlots(): int
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        $pdo = Database::connection();
        $stmt = $pdo->prepare("UPDATE offer_slots SET active = 0 WHERE active = 1 AND event_date < :t");
        $stmt->execute(['t' => $today]);
        return $stmt->rowCount();
    }

    /**
     * Copy active slots onto new dates (same product/session/times/capacity).
     * Useful when next weekend uses the same structure.
     * @param array<string,string> $dateMap oldYmd => newYmd
     */
    public static function cloneActiveSlots(array $dateMap): array
    {
        if ($dateMap === []) {
            return ['ok' => false, 'error' => 'No date mapping provided.', 'created' => 0];
        }
        $pdo = Database::connection();
        $slots = $pdo->query("SELECT * FROM offer_slots WHERE active = 1")->fetchAll();
        $created = 0;
        $skipped = 0;
        foreach ($slots as $slot) {
            $old = (string) $slot['event_date'];
            if (!isset($dateMap[$old])) {
                continue;
            }
            $newDate = $dateMap[$old];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
                continue;
            }
            $res = self::addSlot(
                (string) $slot['product_key'],
                $newDate,
                (string) $slot['session'],
                (string) $slot['slot_start'],
                (string) $slot['slot_end'],
                (int) $slot['capacity']
            );
            if ($res['ok'] ?? false) {
                $created++;
            } else {
                $skipped++;
            }
        }
        return ['ok' => true, 'created' => $created, 'skipped' => $skipped];
    }

    /** @return list<array> all slots with booked/remaining for admin */
    public static function allSlotsDetailed(): array
    {
        $pdo = Database::connection();
        $rows = $pdo->query("
            SELECT s.*, p.label AS product_label
            FROM offer_slots s
            INNER JOIN offer_products p ON p.product_key = s.product_key
            ORDER BY s.event_date ASC, s.slot_start ASC, p.sort_order ASC
        ")->fetchAll();
        foreach ($rows as &$row) {
            $row['booked'] = self::bookedCount($row);
            $row['remaining'] = max(0, (int) $row['capacity'] - (int) $row['booked']);
            $row['label'] = self::formatSlotLabel($row);
        }
        unset($row);
        return $rows;
    }

    /**
     * Distinct event dates from the catalogue.
     * Active-only = current live event dates (used for default export / registrations filter).
     * @return list<string> Y-m-d
     */
    public static function eventDates(bool $activeOnly = true): array
    {
        $pdo = Database::connection();
        $sql = "
            SELECT DISTINCT s.event_date
            FROM offer_slots s
            INNER JOIN offer_products p ON p.product_key = s.product_key
        ";
        if ($activeOnly) {
            $sql .= " WHERE s.active = 1 AND p.active = 1";
        }
        $sql .= " ORDER BY s.event_date ASC";
        return array_map('strval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    /** All distinct event dates that appear on customer registrations (history). */
    public static function registeredEventDates(): array
    {
        $pdo = Database::connection();
        $rows = $pdo->query("
            SELECT DISTINCT d FROM (
                SELECT event_date AS d FROM customers
                WHERE event_date IS NOT NULL AND event_date != ''
                UNION
                SELECT event_date AS d FROM vouchers
                WHERE event_date IS NOT NULL AND event_date != ''
            )
            ORDER BY d DESC
        ")->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    /** Count customers for a date filter (current active dates, one date, or all). */
    public static function registrationCountForFilter(string $param): int
    {
        $pdo = Database::connection();
        if ($param === 'all') {
            return (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        }
        $dates = $param === 'current' ? self::eventDates(true) : (
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $param) ? [$param] : []
        );
        if ($dates === []) {
            return 0;
        }
        $ph = [];
        $params = [];
        foreach ($dates as $i => $d) {
            $ph[] = ':d' . $i;
            $params['d' . $i] = $d;
        }
        $in = implode(',', $ph);
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM customers c
            LEFT JOIN vouchers v ON v.customer_id = c.id
            WHERE COALESCE(NULLIF(c.event_date,''), v.event_date) IN ($in)
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
