<?php
/**
 * admin/exports.php  ->  route: /admin/exports
 * Admin-only. Streams CSV / JSON exports and a full SQLite backup download.
 * All downloads are triggered via ?download=... on this same URL so nothing
 * else needs to know about file paths.
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

AuthService::requireRole('admin');
$pdo = Database::connection();

function fetchExportRows(PDO $pdo, string $scope): array
{
    $where = match ($scope) {
        'morning' => "WHERE c.session = 'morning'",
        'evening' => "WHERE c.session = 'evening'",
        'redeemed' => "WHERE v.status = 'REDEEMED'",
        'pending' => "WHERE v.status = 'ACTIVE'",
        default => '',
    };

    $stmt = $pdo->query("
        SELECT c.full_name, c.mobile_number, c.area, c.selected_product, c.session,
               v.voucher_code, c.registered_at, wl.status as wa_status,
               v.status as voucher_status, v.redeemed_at
        FROM customers c
        LEFT JOIN vouchers v ON v.customer_id = c.id
        LEFT JOIN (
            SELECT wl1.* FROM whatsapp_logs wl1
            INNER JOIN (SELECT customer_id, MAX(id) as max_id FROM whatsapp_logs GROUP BY customer_id) latest
                ON latest.customer_id = wl1.customer_id AND latest.max_id = wl1.id
        ) wl ON wl.customer_id = c.id
        $where
        ORDER BY c.id ASC
    ");
    return $stmt->fetchAll();
}

function streamCsv(array $rows, string $filename): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders ₹ / names correctly
    fputcsv($out, ['Name', 'Mobile Number', 'Area', 'Selected Product', 'Session', 'Voucher Code', 'Registration Time', 'WhatsApp Status', 'Voucher Status', 'Redemption Time']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['full_name'],
            $r['mobile_number'],
            $r['area'] ?? '',
            Products::label($r['selected_product']) ?? $r['selected_product'],
            ucfirst($r['session']),
            $r['voucher_code'],
            $r['registered_at'],
            $r['wa_status'] ?? 'PENDING',
            $r['voucher_status'] ?? '-',
            $r['redeemed_at'] ?? '-',
        ]);
    }
    fclose($out);
    exit;
}

$download = $_GET['download'] ?? null;
$datestamp = date('Y-m-d');

if ($download) {
    AuditLog::record(AuthService::currentUsername() ?? 'admin', 'EXPORT_DOWNLOADED', $download, $_SERVER['REMOTE_ADDR'] ?? null);

    switch ($download) {
        case 'csv_all':
            streamCsv(fetchExportRows($pdo, 'all'), "shreeshta-offer-registrations-all-{$datestamp}.csv");
        case 'csv_morning':
            streamCsv(fetchExportRows($pdo, 'morning'), "shreeshta-offer-registrations-morning-{$datestamp}.csv");
        case 'csv_evening':
            streamCsv(fetchExportRows($pdo, 'evening'), "shreeshta-offer-registrations-evening-{$datestamp}.csv");
        case 'csv_redeemed':
            streamCsv(fetchExportRows($pdo, 'redeemed'), "shreeshta-offer-registrations-redeemed-{$datestamp}.csv");
        case 'csv_pending':
            streamCsv(fetchExportRows($pdo, 'pending'), "shreeshta-offer-registrations-pending-{$datestamp}.csv");

        case 'json_all':
            $customers = $pdo->query("SELECT * FROM customers ORDER BY id ASC")->fetchAll();
            $vouchers = $pdo->query("SELECT * FROM vouchers ORDER BY id ASC")->fetchAll();
            $waLogs = $pdo->query("SELECT * FROM whatsapp_logs ORDER BY id ASC")->fetchAll();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="shreeshta-offer-registrations-' . $datestamp . '.json"');
            echo json_encode([
                'exported_at' => date('c'),
                'customers' => $customers,
                'vouchers' => $vouchers,
                'whatsapp_logs' => $waLogs,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;

        case 'backup_db':
            global $CONFIG;
            $dbPath = $CONFIG['db_path'];
            if (!is_file($dbPath)) {
                http_response_code(404);
                echo 'Database file not found.';
                exit;
            }
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="shreeshta-database-backup-' . $datestamp . '.sqlite"');
            header('Content-Length: ' . filesize($dbPath));
            readfile($dbPath);
            exit;
    }
}

$activePage = 'exports';
$skipDefaultHeader = true;
$pageTitle = 'Exports – Shreeshta Family Store';
require __DIR__ . '/../templates/header.php';
?>
<?php require __DIR__ . '/../templates/admin_nav.php'; ?>

<main class="flex-1 max-w-3xl mx-auto px-5 py-8">
  <h2 class="font-heading text-2xl font-bold text-maroon mb-5">Data Exports &amp; Backup</h2>

  <div class="bg-white gold-border rounded-2xl p-5 sm:p-6 shadow-sm space-y-3">
    <?php
      $buttons = [
        ['csv_all', 'Download All Data as CSV'],
        ['json_all', 'Download All Data as JSON'],
        ['csv_morning', 'Download Morning Session CSV'],
        ['csv_evening', 'Download Evening Session CSV'],
        ['csv_redeemed', 'Download Redeemed Customers CSV'],
        ['csv_pending', 'Download Pending Customers CSV'],
      ];
    ?>
    <?php foreach ($buttons as [$key, $label]): ?>
      <a href="?download=<?= e($key) ?>"
        class="block text-center bg-ivory hover:bg-gold-light/40 transition gold-border rounded-xl px-4 py-3 font-semibold text-maroon-dark">
        <?= e($label) ?>
      </a>
    <?php endforeach; ?>

    <a href="?download=backup_db"
      class="block text-center bg-maroon hover:bg-maroon-dark transition text-ivory rounded-xl px-4 py-3 font-bold mt-4">
      Download Complete Database Backup
    </a>
  </div>

  <p class="text-xs text-maroon-dark/60 mt-4">All exports and backups are logged in the audit trail with the admin username, timestamp and IP address.</p>
</main>

<?php require __DIR__ . '/../templates/footer.php'; ?>
