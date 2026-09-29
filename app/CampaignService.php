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
                event_end_date TEXT NULL,
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
                capacity_scope TEXT NOT NULL DEFAULT 'day',
                sort_order INTEGER NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(campaign_id, product_key),
                UNIQUE(campaign_id, product_slug),
                FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
            );
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_campaign_products_campaign ON campaign_products(campaign_id);");

        self::migrateCampaignColumns($pdo);

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
            self::upsertProduct($campaignId, $key, $slug, $label, $cap, $sort, 'day');
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

    private static function migrateCampaignColumns(PDO $pdo): void
    {
        $campaignCols = array_column($pdo->query('PRAGMA table_info(campaigns)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('event_end_date', $campaignCols, true)) {
            $pdo->exec('ALTER TABLE campaigns ADD COLUMN event_end_date TEXT NULL');
        }

        $productCols = array_column($pdo->query('PRAGMA table_info(campaign_products)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('capacity_scope', $productCols, true)) {
            $pdo->exec("ALTER TABLE campaign_products ADD COLUMN capacity_scope TEXT NOT NULL DEFAULT 'day'");
        }
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
        $end = (string) ($campaign['event_end_date'] ?? $campaign['event_date'] ?? '');
        return $end !== '' && $today <= $end;
    }

    /** @return list<string> Y-m-d */
    public static function visitDates(array $campaign): array
    {
        $start = (string) ($campaign['event_date'] ?? '');
        $end = (string) ($campaign['event_end_date'] ?? $start);
        if ($start === '') {
            return [];
        }
        if ($end === '' || $end < $start) {
            $end = $start;
        }
        $tz = new DateTimeZone('Asia/Kolkata');
        $from = new DateTimeImmutable($start, $tz);
        $to = new DateTimeImmutable($end, $tz);
        $dates = [];
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $dates[] = $d->format('Y-m-d');
        }
        return $dates;
    }

    public static function isValidVisitDate(array $campaign, string $visitDate): bool
    {
        $visitDate = trim($visitDate);
        return $visitDate !== '' && in_array($visitDate, self::visitDates($campaign), true);
    }

    public static function capacityScope(array $productRow): string
    {
        $scope = strtolower(trim((string) ($productRow['capacity_scope'] ?? 'day')));
        return $scope === 'session' ? 'session' : 'day';
    }

    public static function bookedCount(int $campaignId, string $productKey, string $eventDate, ?string $session = null): int
    {
        $pdo = Database::connection();
        $sql = "
            SELECT COUNT(*) FROM customers
            WHERE campaign_id = :cid
              AND selected_product = :p
              AND event_date = :d
        ";
        $params = ['cid' => $campaignId, 'p' => $productKey, 'd' => $eventDate];
        if ($session !== null && $session !== '') {
            $sql .= ' AND session = :s';
            $params['s'] = $session;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function remainingDaily(
        int $campaignId,
        string $productKey,
        string $eventDate,
        int $dailyCapacity,
        array $productRow = [],
        ?string $session = null
    ): int {
        if ($dailyCapacity >= 99999) {
            return 99999;
        }
        $scope = self::capacityScope($productRow);
        $sessionFilter = ($scope === 'session' && $session !== null && $session !== '') ? $session : null;
        if ($scope === 'session' && ($session === null || $session === '')) {
            return $dailyCapacity;
        }
        return max(0, $dailyCapacity - self::bookedCount($campaignId, $productKey, $eventDate, $sessionFilter));
    }

    public static function isProductFull(array $productRow, ?string $eventDate = null, ?string $session = null): bool
    {
        $eventDate = $eventDate ?? (string) ($productRow['event_date'] ?? '');
        return self::remainingDaily(
            (int) $productRow['campaign_id'],
            (string) $productRow['product_key'],
            $eventDate,
            (int) $productRow['daily_capacity'],
            $productRow,
            $session
        ) <= 0;
    }

    /** True when no session on any visit date has free capacity (session-scoped products). */
    public static function isSoldOutEverywhere(array $campaign, array $productRow): bool
    {
        if (self::capacityScope($productRow) !== 'session') {
            return self::isProductFull($productRow, (string) $campaign['event_date']);
        }
        foreach (self::visitDates($campaign) as $date) {
            foreach (['morning', 'evening'] as $session) {
                if (!self::isProductFull($productRow, $date, $session)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @return array<string, array{morning:int, evening:int}>
     */
    public static function remainingByDateSession(array $campaign, array $productRow): array
    {
        $out = [];
        $cap = (int) $productRow['daily_capacity'];
        $cid = (int) $productRow['campaign_id'];
        $key = (string) $productRow['product_key'];
        foreach (self::visitDates($campaign) as $date) {
            $out[$date] = [
                'morning' => self::remainingDaily($cid, $key, $date, $cap, $productRow, 'morning'),
                'evening' => self::remainingDaily($cid, $key, $date, $cap, $productRow, 'evening'),
            ];
        }
        return $out;
    }

    public static function findMin99Sibling(array $campaign, string $currentProductSlug): ?array
    {
        foreach (self::products((int) $campaign['id']) as $p) {
            if ($p['product_slug'] === $currentProductSlug) {
                continue;
            }
            $slug = (string) $p['product_slug'];
            $key = (string) $p['product_key'];
            if (str_contains($slug, 'min99') || str_contains($key, 'min99')) {
                return self::findProduct((int) $campaign['id'], $slug);
            }
        }
        return null;
    }

    public static function sessionWindow(array $campaign, string $session, ?string $visitDate = null): array
    {
        $date = $visitDate ?? (string) $campaign['event_date'];
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
        string $status = 'OPEN',
        ?string $eventEndDate = null,
        string $morningStart = '11:30',
        string $morningEnd = '14:30',
        string $eveningStart = '17:00',
        string $eveningEnd = '20:00'
    ): array {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            INSERT INTO campaigns (slug, title, event_date, event_end_date, status, morning_start, morning_end, evening_start, evening_end)
            VALUES (:slug, :title, :date, :end, :status, :ms, :me, :es, :ee)
            ON CONFLICT(slug) DO UPDATE SET
                title = excluded.title,
                event_date = excluded.event_date,
                event_end_date = excluded.event_end_date,
                status = excluded.status,
                morning_start = excluded.morning_start,
                morning_end = excluded.morning_end,
                evening_start = excluded.evening_start,
                evening_end = excluded.evening_end
        ");
        $stmt->execute([
            'slug' => strtolower(trim($slug)),
            'title' => trim($title),
            'date' => $eventDate,
            'end' => $eventEndDate,
            'status' => strtoupper($status),
            'ms' => $morningStart,
            'me' => $morningEnd,
            'es' => $eveningStart,
            'ee' => $eveningEnd,
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
        int $sortOrder = 0,
        string $capacityScope = 'day'
    ): array {
        $scope = strtolower(trim($capacityScope)) === 'session' ? 'session' : 'day';
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            INSERT INTO campaign_products (campaign_id, product_key, product_slug, label, daily_capacity, capacity_scope, sort_order, active)
            VALUES (:cid, :pk, :ps, :label, :cap, :scope, :sort, 1)
            ON CONFLICT(campaign_id, product_key) DO UPDATE SET
                product_slug = excluded.product_slug,
                label = excluded.label,
                daily_capacity = excluded.daily_capacity,
                capacity_scope = excluded.capacity_scope,
                sort_order = excluded.sort_order,
                active = 1
        ");
        $stmt->execute([
            'cid' => $campaignId,
            'pk' => $productKey,
            'ps' => strtolower(trim($productSlug)),
            'label' => trim($label),
            'cap' => $dailyCapacity,
            'scope' => $scope,
            'sort' => $sortOrder,
        ]);
        return ['ok' => true];
    }

    /** Oct 3–15, 2026 — SL#10 saree offers (50 free / slot, unlimited min ₹99). */
    public static function seedOctSaree1012026(): void
    {
        $res = self::upsertCampaign(
            'oct-saree-10',
            '₹1 Saree Offer — Malleshwaram',
            '2026-10-03',
            'OPEN',
            '2026-10-15',
            '11:30',
            '14:30',
            '17:00',
            '20:00'
        );
        $campaignId = (int) ($res['id'] ?? 0);
        if ($campaignId <= 0) {
            $c = self::findBySlug('oct-saree-10');
            $campaignId = (int) ($c['id'] ?? 0);
        }
        if ($campaignId <= 0) {
            return;
        }

        $unlimited = 99999;
        self::upsertProduct($campaignId, 'rupee1_saree_free_oct101', 'saree', '1 Rupee Saree', 50, 1, 'session');
        self::upsertProduct($campaignId, 'rupee1_saree_min99_oct101', 'saree-min99', '1 Rupee Saree — Min purchase ₹99/-', $unlimited, 2, 'day');
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
        $startDate = (string) $campaign['event_date'];
        $endDate = (string) ($campaign['event_end_date'] ?? $startDate);
        if ($endDate === '' || $endDate < $startDate) {
            $endDate = $startDate;
        }
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        $reportDate = ($today >= $startDate && $today <= $endDate) ? $today : $startDate;

        $products = self::products($campaignId, false);
        $rows = [];
        $totals = ['allocated' => 0, 'registered' => 0, 'purchased' => 0];

        $regStmt = $pdo->prepare("
            SELECT COUNT(*) FROM customers
            WHERE campaign_id = :cid AND selected_product = :p
              AND event_date >= :start AND event_date <= :end
        ");
        $buyStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM customers c
            INNER JOIN vouchers v ON v.customer_id = c.id
            WHERE c.campaign_id = :cid
              AND c.selected_product = :p
              AND c.event_date >= :start AND c.event_date <= :end
              AND v.status = 'REDEEMED'
        ");

        foreach ($products as $p) {
            $allocated = (int) $p['daily_capacity'];
            $productKey = (string) $p['product_key'];
            $scope = self::capacityScope($p);

            $regStmt->execute(['cid' => $campaignId, 'p' => $productKey, 'start' => $startDate, 'end' => $endDate]);
            $registered = (int) $regStmt->fetchColumn();

            $buyStmt->execute(['cid' => $campaignId, 'p' => $productKey, 'start' => $startDate, 'end' => $endDate]);
            $purchased = (int) $buyStmt->fetchColumn();

            $remaining = self::remainingDaily(
                $campaignId,
                $productKey,
                $reportDate,
                $allocated,
                $p,
                $scope === 'session' ? 'morning' : null
            );
            $pct = $registered > 0 ? round(($purchased / $registered) * 100, 2) : 0.0;

            $rows[] = [
                'product_key' => $productKey,
                'product_slug' => $p['product_slug'],
                'label' => $p['label'],
                'active' => (int) $p['active'],
                'allocated' => $allocated,
                'capacity_scope' => $scope,
                'registered' => $registered,
                'purchased' => $purchased,
                'remaining' => $remaining,
                'pct' => $pct,
                'url' => self::publicUrl((string) $campaign['slug'], (string) $p['product_slug']),
            ];

            if ((int) $p['active'] === 1) {
                if ($allocated < 99999) {
                    $totals['allocated'] += $scope === 'session' ? $allocated * 2 : $allocated;
                }
                $totals['registered'] += $registered;
                $totals['purchased'] += $purchased;
            }
        }

        return ['products' => $rows, 'totals' => $totals, 'campaign' => $campaign];
    }
}
