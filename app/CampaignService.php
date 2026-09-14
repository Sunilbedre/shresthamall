<?php
/**
 * app/CampaignService.php
 * Named special-event campaigns (e.g. Oct 2 ₹1 day) with per-product public links,
 * separate reporting, and optional per-campaign mobile reuse.
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
                event_type TEXT NOT NULL DEFAULT 'special',
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                active INTEGER NOT NULL DEFAULT 1,
                allow_repeat_mobile INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS campaign_links (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL,
                product_key TEXT NOT NULL,
                link_slug TEXT NOT NULL UNIQUE,
                headline TEXT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
                FOREIGN KEY (product_key) REFERENCES offer_products(product_key) ON DELETE CASCADE
            );
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_campaign_links_campaign ON campaign_links(campaign_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_campaign_links_product ON campaign_links(product_key);");

        self::ensureCustomerCampaignColumn($pdo);
    }

    /**
     * Add customers.campaign_slug and switch uniqueness to (mobile_number, campaign_slug)
     * so the same mobile can join a special campaign once even if it used a past weekly offer.
     */
    private static function ensureCustomerCampaignColumn(PDO $pdo): void
    {
        $cols = $pdo->query("PRAGMA table_info(customers)")->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        if (!in_array('campaign_slug', $names, true)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN campaign_slug TEXT NOT NULL DEFAULT ''");
        }

        // Prefer composite unique. If the old column-level UNIQUE still blocks this,
        // rebuild the customers table once.
        $indexes = $pdo->query("PRAGMA index_list(customers)")->fetchAll(PDO::FETCH_ASSOC);
        $hasComposite = false;
        foreach ($indexes as $idx) {
            if (($idx['name'] ?? '') === 'idx_customers_mobile_campaign') {
                $hasComposite = true;
                break;
            }
        }

        if ($hasComposite) {
            return;
        }

        try {
            $pdo->exec("CREATE UNIQUE INDEX idx_customers_mobile_campaign ON customers(mobile_number, campaign_slug)");
            // Best-effort: drop plain mobile unique index if it was created separately
            $pdo->exec("DROP INDEX IF EXISTS idx_customers_mobile");
            return;
        } catch (PDOException $e) {
            // Likely blocked by sqlite_autoindex from column UNIQUE — rebuild.
            if (!str_contains($e->getMessage(), 'UNIQUE') && !str_contains($e->getMessage(), 'unique')) {
                // Another failure creating the composite while duplicates exist, etc.
            }
        }

        self::rebuildCustomersForCampaignUniqueness($pdo);
    }

    private static function rebuildCustomersForCampaignUniqueness(PDO $pdo): void
    {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS customers_campaign_mig (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    full_name TEXT NOT NULL,
                    mobile_number TEXT NOT NULL,
                    selected_product TEXT NOT NULL,
                    session TEXT NOT NULL,
                    event_date TEXT NULL,
                    offer_slot_id INTEGER NULL,
                    area TEXT NULL,
                    age_group TEXT NULL,
                    gender TEXT NULL,
                    consent INTEGER NOT NULL DEFAULT 0,
                    campaign_slug TEXT NOT NULL DEFAULT '',
                    registered_at TEXT NOT NULL DEFAULT (datetime('now')),
                    ip_address TEXT NULL,
                    user_agent TEXT NULL
                );
            ");

            // Copy whatever columns exist
            $cols = array_column($pdo->query("PRAGMA table_info(customers)")->fetchAll(PDO::FETCH_ASSOC), 'name');
            $wanted = [
                'id', 'full_name', 'mobile_number', 'selected_product', 'session', 'event_date',
                'offer_slot_id', 'area', 'age_group', 'gender', 'consent', 'campaign_slug',
                'registered_at', 'ip_address', 'user_agent',
            ];
            // Older DBs may use slightly different names — map common aliases
            $sourceMap = [
                'registered_at' => in_array('registered_at', $cols, true) ? 'registered_at' : (in_array('created_at', $cols, true) ? 'created_at' : null),
                'ip_address' => in_array('ip_address', $cols, true) ? 'ip_address' : null,
                'user_agent' => in_array('user_agent', $cols, true) ? 'user_agent' : null,
                'age_group' => in_array('age_group', $cols, true) ? 'age_group' : null,
                'campaign_slug' => in_array('campaign_slug', $cols, true) ? 'campaign_slug' : null,
            ];

            $selectParts = [];
            foreach ($wanted as $col) {
                if ($col === 'campaign_slug') {
                    $selectParts[] = $sourceMap['campaign_slug'] ? 'campaign_slug' : "'' AS campaign_slug";
                    continue;
                }
                if (isset($sourceMap[$col])) {
                    $src = $sourceMap[$col];
                    $selectParts[] = $src ? "{$src} AS {$col}" : "NULL AS {$col}";
                    continue;
                }
                if (in_array($col, $cols, true)) {
                    $selectParts[] = $col;
                } else {
                    $selectParts[] = "NULL AS {$col}";
                }
            }

            $pdo->exec('DELETE FROM customers_campaign_mig');
            $pdo->exec('INSERT INTO customers_campaign_mig (' . implode(',', $wanted) . ') SELECT ' . implode(',', $selectParts) . ' FROM customers');
            $pdo->exec('ALTER TABLE customers RENAME TO customers_pre_campaign_backup');
            $pdo->exec('ALTER TABLE customers_campaign_mig RENAME TO customers');
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_mobile_campaign ON customers(mobile_number, campaign_slug)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_customers_product ON customers(selected_product)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_customers_session ON customers(session)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_customers_event_date ON customers(event_date)");
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    public static function upsertCampaign(
        string $slug,
        string $title,
        string $startDate,
        string $endDate,
        bool $active = true,
        bool $allowRepeatMobile = true
    ): array {
        $slug = self::normaliseSlug($slug);
        if ($slug === '') {
            return ['ok' => false, 'error' => 'Invalid campaign slug.'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            return ['ok' => false, 'error' => 'Invalid campaign dates.'];
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            INSERT INTO campaigns (slug, title, event_type, start_date, end_date, active, allow_repeat_mobile)
            VALUES (:slug, :title, 'special', :start_date, :end_date, :active, :arm)
            ON CONFLICT(slug) DO UPDATE SET
                title = excluded.title,
                start_date = excluded.start_date,
                end_date = excluded.end_date,
                active = excluded.active,
                allow_repeat_mobile = excluded.allow_repeat_mobile
        ");
        $stmt->execute([
            'slug' => $slug,
            'title' => trim($title),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'active' => $active ? 1 : 0,
            'arm' => $allowRepeatMobile ? 1 : 0,
        ]);

        $camp = self::findBySlug($slug);
        return ['ok' => true, 'campaign' => $camp];
    }

    public static function upsertLink(
        int $campaignId,
        string $productKey,
        string $linkSlug,
        string $headline = '',
        int $sortOrder = 0,
        bool $active = true
    ): array {
        $linkSlug = self::normaliseSlug($linkSlug);
        if ($linkSlug === '' || $productKey === '') {
            return ['ok' => false, 'error' => 'Invalid link slug or product.'];
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            INSERT INTO campaign_links (campaign_id, product_key, link_slug, headline, sort_order, active)
            VALUES (:cid, :pk, :ls, :h, :so, :a)
            ON CONFLICT(link_slug) DO UPDATE SET
                campaign_id = excluded.campaign_id,
                product_key = excluded.product_key,
                headline = excluded.headline,
                sort_order = excluded.sort_order,
                active = excluded.active
        ");
        $stmt->execute([
            'cid' => $campaignId,
            'pk' => $productKey,
            'ls' => $linkSlug,
            'h' => $headline !== '' ? $headline : null,
            'so' => $sortOrder,
            'a' => $active ? 1 : 0,
        ]);
        return ['ok' => true, 'link_slug' => $linkSlug];
    }

    public static function findBySlug(string $slug): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE slug = :s LIMIT 1");
        $stmt->execute(['s' => self::normaliseSlug($slug)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array> */
    public static function all(bool $activeOnly = false): array
    {
        $pdo = Database::connection();
        $sql = "SELECT * FROM campaigns";
        if ($activeOnly) {
            $sql .= " WHERE active = 1";
        }
        $sql .= " ORDER BY start_date DESC, id DESC";
        return $pdo->query($sql)->fetchAll();
    }

    /** Resolve public /o/{link_slug} → campaign + product. */
    public static function resolvePublicLink(string $linkSlug): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            SELECT
                cl.*,
                c.slug AS campaign_slug,
                c.title AS campaign_title,
                c.start_date,
                c.end_date,
                c.active AS campaign_active,
                c.allow_repeat_mobile,
                p.label AS product_label,
                p.active AS product_active
            FROM campaign_links cl
            INNER JOIN campaigns c ON c.id = cl.campaign_id
            INNER JOIN offer_products p ON p.product_key = cl.product_key
            WHERE cl.link_slug = :s
            LIMIT 1
        ");
        $stmt->execute(['s' => self::normaliseSlug($linkSlug)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array> */
    public static function linksForCampaign(int $campaignId, bool $activeOnly = false): array
    {
        $pdo = Database::connection();
        $sql = "
            SELECT cl.*, p.label AS product_label
            FROM campaign_links cl
            LEFT JOIN offer_products p ON p.product_key = cl.product_key
            WHERE cl.campaign_id = :id
        ";
        if ($activeOnly) {
            $sql .= " AND cl.active = 1";
        }
        $sql .= " ORDER BY cl.sort_order ASC, cl.id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $campaignId]);
        return $stmt->fetchAll();
    }

    /** Slots belonging to a campaign date range (for stock management). */
    public static function slotsForCampaign(array $campaign): array
    {
        $pdo = Database::connection();
        $links = self::linksForCampaign((int) $campaign['id']);
        $keys = array_values(array_unique(array_map(static fn ($l) => (string) $l['product_key'], $links)));
        if ($keys === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $params = array_merge($keys, [(string) $campaign['start_date'], (string) $campaign['end_date']]);
        $stmt = $pdo->prepare("
            SELECT s.*, p.label AS product_label
            FROM offer_slots s
            INNER JOIN offer_products p ON p.product_key = s.product_key
            WHERE s.product_key IN ($ph)
              AND s.event_date >= ?
              AND s.event_date <= ?
            ORDER BY s.event_date ASC, p.sort_order ASC, s.slot_start ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['booked'] = OfferCatalog::bookedCount($row);
            $row['remaining'] = max(0, (int) $row['capacity'] - (int) $row['booked']);
            $row['label'] = OfferCatalog::formatSlotLabel($row);
        }
        unset($row);
        return $rows;
    }

    /**
     * Report rows for one campaign: product × date with stock + registrations + redeemed.
     * @return list<array{product_key:string,product_label:string,event_date:string,allocated:int,registered:int,purchased:int,remaining:int,pct:float}>
     */
    public static function report(array $campaign): array
    {
        $pdo = Database::connection();
        $links = self::linksForCampaign((int) $campaign['id']);
        $out = [];
        foreach ($links as $link) {
            $productKey = (string) $link['product_key'];
            $label = (string) ($link['product_label'] ?? $productKey);
            $dates = self::datesInRange((string) $campaign['start_date'], (string) $campaign['end_date']);
            foreach ($dates as $date) {
                $allocStmt = $pdo->prepare("
                    SELECT COALESCE(SUM(capacity), 0) FROM offer_slots
                    WHERE product_key = :p AND event_date = :d
                ");
                $allocStmt->execute(['p' => $productKey, 'd' => $date]);
                $allocated = (int) $allocStmt->fetchColumn();
                if ($allocated === 0) {
                    continue;
                }

                $campSlug = (string) $campaign['slug'];
                $regStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM customers
                    WHERE selected_product = :p AND event_date = :d AND campaign_slug = :c
                ");
                $regStmt->execute(['p' => $productKey, 'd' => $date, 'c' => $campSlug]);
                $registered = (int) $regStmt->fetchColumn();

                $buyStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM customers c
                    INNER JOIN vouchers v ON v.customer_id = c.id
                    WHERE c.selected_product = :p AND c.event_date = :d
                      AND c.campaign_slug = :c AND v.status = 'REDEEMED'
                ");
                $buyStmt->execute(['p' => $productKey, 'd' => $date, 'c' => $campSlug]);
                $purchased = (int) $buyStmt->fetchColumn();

                $out[] = [
                    'product_key' => $productKey,
                    'product_label' => $label,
                    'link_slug' => (string) $link['link_slug'],
                    'event_date' => $date,
                    'allocated' => $allocated,
                    'registered' => $registered,
                    'purchased' => $purchased,
                    'remaining' => max(0, $allocated - $registered),
                    'pct' => $allocated > 0 ? round(($purchased / $allocated) * 100, 1) : 0.0,
                ];
            }
        }
        return $out;
    }

    /**
     * Product keys belonging to any active campaign link (non-hub).
     * Used so the weekly /offer form does not list special-campaign products.
     * @return list<string>
     */
    public static function activeCampaignProductKeys(): array
    {
        $pdo = Database::connection();
        $rows = $pdo->query("
            SELECT DISTINCT cl.product_key
            FROM campaign_links cl
            INNER JOIN campaigns c ON c.id = cl.campaign_id
            WHERE c.active = 1 AND cl.active = 1 AND cl.sort_order > 0
        ")->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_map('strval', $rows ?: []));
    }

    public static function publicPath(string $linkSlug): string
    {
        return '/o/' . self::normaliseSlug($linkSlug);
    }

    public static function publicUrl(string $linkSlug): string
    {
        global $CONFIG;
        $base = rtrim((string) ($CONFIG['app_url'] ?? ''), '/');
        return $base . self::publicPath($linkSlug);
    }

    /** @return list<string> */
    private static function datesInRange(string $start, string $end): array
    {
        $out = [];
        try {
            $cur = new DateTimeImmutable($start);
            $last = new DateTimeImmutable($end);
        } catch (Throwable $e) {
            return [];
        }
        while ($cur <= $last) {
            $out[] = $cur->format('Y-m-d');
            $cur = $cur->modify('+1 day');
        }
        return $out;
    }

    public static function normaliseSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }
}
