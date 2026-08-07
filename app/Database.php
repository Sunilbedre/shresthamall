<?php
/**
 * app/Database.php
 * Thin PDO/SQLite wrapper. Single connection, WAL mode for better concurrency
 * on shared hosting, foreign keys enforced.
 */

declare(strict_types=1);

final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        global $CONFIG;

        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $dbPath = $CONFIG['db_path'];
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA busy_timeout = 5000;');

        self::$instance = $pdo;
        return $pdo;
    }

    /**
     * Creates all tables if they do not already exist. Safe to call on every
     * request (cheap no-op after first run) or via scripts/init_db.php.
     */
    public static function migrate(): void
    {
        $pdo = self::connection();

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'admin', -- admin | subadmin | staff
                failed_attempts INTEGER NOT NULL DEFAULT 0,
                locked_until TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                last_login_at TEXT NULL
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                full_name TEXT NOT NULL,
                mobile_number TEXT NOT NULL UNIQUE, -- +91XXXXXXXXXX
                selected_product TEXT NOT NULL,
                session TEXT NOT NULL, -- morning | evening
                area TEXT NULL,
                age_group TEXT NULL,
                gender TEXT NULL,
                consent INTEGER NOT NULL DEFAULT 0,
                registered_at TEXT NOT NULL DEFAULT (datetime('now')),
                ip_address TEXT NULL,
                user_agent TEXT NULL
            );
        ");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_mobile ON customers(mobile_number);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_customers_product ON customers(selected_product);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_customers_session ON customers(session);");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS vouchers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id INTEGER NOT NULL UNIQUE,
                voucher_code TEXT NOT NULL UNIQUE,
                event_date TEXT NOT NULL,
                session_start TEXT NOT NULL,
                session_end TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'ACTIVE', -- ACTIVE | REDEEMED | CANCELLED | BLOCKED
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                redeemed_at TEXT NULL,
                redeemed_by TEXT NULL,
                billing_reference TEXT NULL,
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            );
        ");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_vouchers_code ON vouchers(voucher_code);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vouchers_status ON vouchers(status);");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS whatsapp_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id INTEGER NOT NULL,
                voucher_id INTEGER NOT NULL,
                message_id TEXT NULL,
                template_name TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'PENDING', -- PENDING|SENT|DELIVERED|READ|FAILED
                api_response TEXT NULL,
                sent_at TEXT NULL,
                delivered_at TEXT NULL,
                read_at TEXT NULL,
                failed_at TEXT NULL,
                resend_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE
            );
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_wa_customer ON whatsapp_logs(customer_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_wa_message_id ON whatsapp_logs(message_id);");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key_name TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor TEXT NOT NULL,
                action TEXT NOT NULL,
                details TEXT NULL,
                ip_address TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
        ");

        self::seedDefaultSettings();
    }

    private static function seedDefaultSettings(): void
    {
        $defaults = [
            'event_title'            => 'Shreeshta Family Store – ₹1 Special Offer',
            'event_date'             => date('Y-m-d', strtotime('+7 days')),
            'store_name'             => 'Shreeshta Family Store',
            'branch_name'            => 'Malleshwaram, Bengaluru',
            'store_address'          => 'Shreeshta Family Store, Malleshwaram, Bengaluru, Karnataka',
            'maps_link'              => 'https://maps.google.com/?q=Shreeshta+Family+Store+Malleshwaram',
            'contact_number'         => '+919900000000',
            'morning_start'          => '11:00',
            'morning_end'            => '14:00',
            'evening_start'          => '17:00',
            'evening_end'            => '20:00',
            'registration_status'    => 'OPEN', // OPEN | CLOSED
            'max_whatsapp_resends'   => '3',
            'product_limits_enabled' => '1',
            'limit_saree'            => '500',
            'limit_kids_boys'        => '100',
            'limit_kids_girls'       => '100',
            'limit_leggings'         => '100',
            'limit_kurti'            => '100',
            'limit_mens_shirt'       => '100',
        ];

        $pdo = self::connection();
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key_name, value) VALUES (:k, :v)");
        foreach ($defaults as $k => $v) {
            $stmt->execute(['k' => $k, 'v' => $v]);
        }
    }
}
