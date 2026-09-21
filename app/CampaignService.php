<?php
/**
 * app/CampaignService.php
 * Special one-day / big-date events with separate links per product.
 */

declare(strict_types=1);

final class CampaignService
{
    public static function ensureSchema(): void
    {
        $pdo = Database::connection();

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                event_date TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'OPEN',
                morning_start TEXT NOT NULL DEFAULT '11:30',
                morning_end TEXT NOT NULL DEFAULT '14:30',
                evening_start TEXT NOT NULL DEFAULT '17:00',
                evening_end TEXT NOT NULL DEFAULT '20:00',
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS campaign_products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL,
                product_key TEXT NOT NULL,
                product_slug TEXT NOT NULL,
                label TEXT NOT NULL,
                daily_capacity INTEGER NOT NULL DEFAULT 0,
                sort_order INTEGER NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(campaign_id, product_key),
                UNIQUE(campaign_id, product_slug),
                FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
            );
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_campaign_products_campaign ON campaign_products(campaign_id);");

        $cols = $pdo->query("PRAGMA table_info(customers)")->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        if (!in_array('campaign_id', $names, true)) {
            $pdo->exec('ALTER TABLE customers ADD COLUMN campaign_id INTEGER NULL');
        }

        self::migrateMobileIndexes($pdo);
        self::seedOct2026IfNeeded();
    }

    /** Idempotent Oct 2, 2026 Gandhi Jayanti products (incl. min-₹99 saree link). */
    public static function seedOct2026Special(): void
    {
        $res = self::upsertCampaign(
            'oct-2026',
            'Gandhi Jayanti Special — ₹1 Offer',
            '2026-10-02',
            'OPEN'
        );
        $campaignId = (int) ($res['id'] ?? 0);
        if ($campaignId <= 0) {
            $c = self::findBySlug('oct-2026');
            $campaignId = (int) ($c['id'] ?? 0);
        }
        if ($campaignId <= 0) {
            return;
        }

        $products = [
            ['rupee1_saree_oct', 'saree', '1 Rupee Saree', 500, 1],
            ['rupee1_saree_min99_oct', 'saree-min99', '1 Rupee Saree — Min purchase ₹99/-', 500, 2],
            ['rupee1_kurti_oct', 'kurti', '1 Rupee Kurti / Leggings', 200, 3],
            ['rupee1_kids_tshirt_oct', 'kids', "1 Rupee Kid's T-shirt", 200, 4],
        ];

        foreach ($products as [$key, $slug, $label, $cap, $sort]) {
            self::upsertProduct($campaignId, $key, $slug, $label, $cap, $sort);
        }
    }

    private static function seedOct2026IfNeeded(): void
    {
        $campaign = self::findBySlug('oct-2026');
        if ($campaign === null) {
            return;
        }
        if (self::findProduct((int) $campaign['id'], 'saree-min99') !== null) {
            return;
        }
        self::seedOct2026Special();
    }

    private static function migrateMobileIndexes(PDO $pdo): void
    {
        $indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='customers'")
            ->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('idx_customers_mobile', $indexes, true)) {
            $pdo->exec('DROP INDEX idx_customers_mobile');
        }
        $pdo->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_mobile_weekend
            ON customers(mobile_number) WHERE campaign_id IS NULL
        ");
        $pdo->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_mobile_campaign
            ON customers(mobile_number, campaign_id) WHERE campaign_id IS NOT NULL
        ");
    }

    public static function findBySlug(string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE slug = :s LIMIT 1');
        $stmt->execute(['s' => $slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findProduct(int $campaignId, string $productSlug): ?array
    {
        $productSlug = strtolower(trim($productSlug));
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            SELECT cp.*, c.slug AS campaign_slug, c.title AS campaign_title, c.event_date,
                   c.status AS campaign_status, c.morning_start, c.morning_end,
                   c.evening_start, c.evening_end
            FROM campaign_products cp
            INNER JOIN campaigns c ON c.id = cp.campaign_id
            WHERE cp.campaign_id = :cid AND cp.product_slug = :ps AND cp.active = 1
            LIMIT 1
        ");
        $stmt->execute(['cid' => $campaignId, 'ps' => $productSlug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findProductByCampaignSlug(string $campaignSlug, string $productSlug): ?array
    {
        $campaign = self::findBySlug($campaignSlug);
        if ($campaign === null) {
            return null;
        }
        return self::findProduct((int) $campaign['id'], $productSlug);
    }

    /** @return list<array> */
    public static function products(int $campaignId, bool $activeOnly = true): array
    {
        $pdo = Database::connection();
        $sql = 'SELECT * FROM campaign_products WHERE campaign_id = :cid';
        if ($activeOnly) {
            $sql .= ' AND active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['cid' => $campaignId]);
        return $stmt->fetchAll();
    }

    /** @return list<array> */
    public static function listCampaigns(bool $newestFirst = true): array
    {
        $pdo = Database::connection();
        $order = $newestFirst ? 'DESC' : 'ASC';
        return $pdo->query("SELECT * FROM campaigns ORDER BY event_date {$order}, id DESC")->fetchAll();
    }

    public static function isOpen(array $campaign): bool
    {
        if (strtoupper((string) ($campaign['status'] ?? '')) !== 'OPEN') {
            return false;
        }
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        return ((string) ($campaign['event_date'] ?? '')) >= $today;
    }

    public static function bookedCount(int $campaignId, string $productKey, string $eventDate): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM customers
            WHERE campaign_id = :cid
              AND selected_product = :p
              AND event_date = :d
        ");
        $stmt->execute(['cid' => $campaignId, 'p' => $productKey, 'd' => $eventDate]);
        return (int) $stmt->fetchColumn();
    }

    public static function remainingDaily(int $campaignId, string $productKey, string $eventDate, int $dailyCapacity): int
    {
        if ($dailyCapacity >= 99999) {
            return 99999;
        }
        return max(0, $dailyCapacity - self::bookedCount($campaignId, $productKey, $eventDate));
    }

    public static function isProductFull(array $productRow): bool
    {
        return self::remainingDaily(
            (int) $productRow['campaign_id'],
            (string) $productRow['product_key'],
            (string) $productRow['event_date'],
            (int) $productRow['daily_capacity']
        ) <= 0;
    }

    public static function sessionWindow(array $campaign, string $session): array
    {
        $date = (string) $campaign['event_date'];
        $tz = new DateTimeZone('Asia/Kolkata');
        if ($session === 'morning') {
            $start = new DateTimeImmutable($date . ' ' . $campaign['morning_start'], $tz);
            $end = new DateTimeImmutable($date . ' ' . $campaign['morning_end'], $tz);
        } else {
            $start = new DateTimeImmutable($date . ' ' . $campaign['evening_start'], $tz);
            $end = new DateTimeImmutable($date . ' ' . $campaign['evening_end'], $tz);
        }
        return ['start' => $start, 'end' => $end];
    }

    public static function formatSessionLabel(array $campaign, string $session): string
    {
        $w = self::sessionWindow($campaign, $session);
        return $w['start']->format('g:i A') . ' – ' . $w['end']->format('g:i A');
    }

    public static function publicUrl(string $campaignSlug, string $productSlug): string
    {
        return app_url('s/' . rawurlencode($campaignSlug) . '/' . rawurlencode($productSlug));
    }

    public static function upsertCampaign(
        string $slug,
        string $title,
        string $eventDate,
        string $status = 'OPEN'
    ): array {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            INSERT INTO campaigns (slug, title, event_date, status)
            VALUES (:slug, :title, :date, :status)
            ON CONFLICT(slug) DO UPDATE SET
                title = excluded.title,
                event_date = excluded.event_date,
                status = excluded.status
        ");
        $stmt->execute([
            'slug' => strtolower(trim($slug)),
            'title' => trim($title),
            'date' => $eventDate,
            'status' => strtoupper($status),
        ]);
        $row = self::findBySlug($slug);
        return ['ok' => true, 'id' => (int) ($row['id'] ?? 0)];
    }

    public static function upsertProduct(
        int $campaignId,
        string $productKey,
        string $productSlug,
        string $label,
        int $dailyCapacity,
        int $sortOrder = 0
    ): array {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            INSERT INTO campaign_products (campaign_id, product_key, product_slug, label, daily_capacity, sort_order, active)
            VALUES (:cid, :pk, :ps, :label, :cap, :sort, 1)
            ON CONFLICT(campaign_id, product_key) DO UPDATE SET
                product_slug = excluded.product_slug,
                label = excluded.label,
                daily_capacity = excluded.daily_capacity,
                sort_order = excluded.sort_order,
                active = 1
        ");
        $stmt->execute([
            'cid' => $campaignId,
            'pk' => $productKey,
            'ps' => strtolower(trim($productSlug)),
            'label' => trim($label),
            'cap' => $dailyCapacity,
            'sort' => $sortOrder,
        ]);
        return ['ok' => true];
    }

    /**
     * @return array{products:list<array>, totals:array{allocated:int,registered:int,purchased:int}}
     */
    public static function report(int $campaignId): array
    {
        $campaign = self::findById($campaignId);
        if ($campaign === null) {
            return ['products' => [], 'totals' => ['allocated' => 0, 'registered' => 0, 'purchased' => 0]];
        }

        $pdo = Database::connection();
        $eventDate = (string) $campaign['event_date'];
        $products = self::products($campaignId, false);
        $rows = [];
        $totals = ['allocated' => 0, 'registered' => 0, 'purchased' => 0];

        foreach ($products as $p) {
            $allocated = (int) $p['daily_capacity'];
            $registered = self::bookedCount($campaignId, (string) $p['product_key'], $eventDate);

            $buyStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM customers c
                INNER JOIN vouchers v ON v.customer_id = c.id
                WHERE c.campaign_id = :cid
                  AND c.selected_product = :p
                  AND c.event_date = :d
                  AND v.status = 'REDEEMED'
            ");
            $buyStmt->execute(['cid' => $campaignId, 'p' => $p['product_key'], 'd' => $eventDate]);
            $purchased = (int) $buyStmt->fetchColumn();
            $remaining = self::remainingDaily($campaignId, (string) $p['product_key'], $eventDate, $allocated);
            $pct = $registered > 0 ? round(($purchased / $registered) * 100, 2) : 0.0;

            $rows[] = [
                'product_key' => $p['product_key'],
                'product_slug' => $p['product_slug'],
                'label' => $p['label'],
                'active' => (int) $p['active'],
                'allocated' => $allocated,
                'registered' => $registered,
                'purchased' => $purchased,
                'remaining' => $remaining,
                'pct' => $pct,
                'url' => self::publicUrl((string) $campaign['slug'], (string) $p['product_slug']),
            ];

            if ((int) $p['active'] === 1) {
                $totals['allocated'] += $allocated >= 99999 ? 0 : $allocated;
                $totals['registered'] += $registered;
                $totals['purchased'] += $purchased;
            }
        }

        return ['products' => $rows, 'totals' => $totals, 'campaign' => $campaign];
    }
}
