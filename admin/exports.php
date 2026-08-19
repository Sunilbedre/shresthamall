<?php
/**
 * admin/exports.php  ->  route: /admin/exports
 * Admin-only. Streams CSV / JSON exports and a full SQLite backup download.
 * CSV/JSON default to current active event dates only (past mobiles stay in DB
 * as a permanent blocklist, but do not appear in event exports).
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

AuthService::requireRole('admin');
$pdo = Database::connection();

$activeEventDates = OfferCatalog::eventDates(true);
$allRegisteredDates = OfferCatalog::registeredEventDates();
$dateChoices = array_values(array_unique(array_merge($activeEventDates, $allRegisteredDates)));
sort($dateChoices);

/**
 * event_date filter:
 *  - '' or 'current' → all active catalogue dates (this event)
 *  - 'all' → every registration ever
 *  - Y-m-d → that single day
 */
$eventDateParam = (string) ($_GET['event_date'] ?? 'all');
if ($eventDateParam === '') {
    $eventDateParam = 'all';
}

function resolveExportDates(string $param, array $activeDates): ?array
{
    if ($param === 'all') {
        return null; // no date filter
    }
    if ($param === 'current') {
        return $activeDates;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $param)) {
        return [$param];
    }
    return $activeDates;
}

function fetchExportRows(PDO $pdo, string $scope, ?array $eventDates): array
{
    $clauses = [];
    $params = [];

    if ($eventDates !== null) {
        if ($eventDates === []) {
            return [];
        }
        $placeholders = [];
        foreach ($eventDates as $i => $d) {
            $key = 'ed' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $d;
        }
        // Legacy rows may only have date on voucher
        $clauses[] = 'COALESCE(NULLIF(c.event_date, \'\'), v.event_date) IN (' . implode(',', $placeholders) . ')';
    }

    $scopeClause = match ($scope) {
        'morning' => "c.session = 'morning'",
        'evening' => "c.session = 'evening'",
        'redeemed' => "v.status = 'REDEEMED'",
        'pending' => "v.status = 'ACTIVE'",
        default => null,
    };
    if ($scopeClause !== null) {
        $clauses[] = $scopeClause;
    }

    $where = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';

    $stmt = $pdo->prepare("
        SELECT c.full_name, c.mobile_number, c.area, c.selected_product, c.session,
               COALESCE(NULLIF(c.event_date, ''), v.event_date) AS event_date,
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
        ORDER BY COALESCE(NULLIF(c.event_date, ''), v.event_date) ASC, c.id ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function streamCsv(array $rows, string $filename): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders ₹ / names correctly
    fputcsv($out, ['Name', 'Mobile Number', 'Area', 'Selected Product', 'Event Date', 'Session', 'Voucher Code', 'Registration Time', 'WhatsApp Status', 'Voucher Status', 'Redemption Time']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['full_name'],
            $r['mobile_number'],
            $r['area'] ?? '',
            Products::label($r['selected_product']) ?? $r['selected_product'],
            $r['event_date'] ?? '',
            ucfirst((string) $r['session']),
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
$exportDates = resolveExportDates($eventDateParam, $activeEventDates);
$dateSuffix = match (true) {
    $eventDateParam === 'all' => 'all-history',
    $eventDateParam === 'current' => 'event-' . ($activeEventDates ? implode('_', $activeEventDates) : 'none'),
    default => 'event-' . $eventDateParam,
};

if ($download) {
    AuditLog::record(
        AuthService::currentUsername() ?? 'admin',
        'EXPORT_DOWNLOADED',
        $download . '|event_date=' . $eventDateParam,
        $_SERVER['REMOTE_ADDR'] ?? null
    );

    $dateQs = rawurlencode($eventDateParam);

    switch ($download) {
        case 'csv_all':
            streamCsv(fetchExportRows($pdo, 'all', $exportDates), "shreeshta-registrations-{$dateSuffix}-{$datestamp}.csv");
        case 'csv_morning':
            streamCsv(fetchExportRows($pdo, 'morning', $exportDates), "shreeshta-registrations-morning-{$dateSuffix}-{$datestamp}.csv");
        case 'csv_evening':
            streamCsv(fetchExportRows($pdo, 'evening', $exportDates), "shreeshta-registrations-evening-{$dateSuffix}-{$datestamp}.csv");
        case 'csv_redeemed':
            streamCsv(fetchExportRows($pdo, 'redeemed', $exportDates), "shreeshta-registrations-redeemed-{$dateSuffix}-{$datestamp}.csv");
        case 'csv_pending':
            streamCsv(fetchExportRows($pdo, 'pending', $exportDates), "shreeshta-registrations-pending-{$dateSuffix}-{$datestamp}.csv");

        case 'json_all':
            if ($exportDates !== null) {
                if ($exportDates === []) {
                    $customers = [];
                    $vouchers = [];
                    $waLogs = [];
                } else {
                    $params = [];
                    $ph = [];
                    foreach ($exportDates as $i => $d) {
                        $ph[] = ':ed' . $i;
                        $params['ed' . $i] = $d;
                    }
                    $in = implode(',', $ph);
                    $stmtC = $pdo->prepare("
                        SELECT c.* FROM customers c
                        LEFT JOIN vouchers v ON v.customer_id = c.id
                        WHERE COALESCE(NULLIF(c.event_date,''), v.event_date) IN ($in)
                        ORDER BY c.id ASC
                    ");
                    $stmtC->execute($params);
                    $customers = $stmtC->fetchAll();
                    $ids = array_column($customers, 'id');
                    if ($ids) {
                        $idList = implode(',', array_map('intval', $ids));
                        $vouchers = $pdo->query("SELECT * FROM vouchers WHERE customer_id IN ($idList) ORDER BY id ASC")->fetchAll();
                        $waLogs = $pdo->query("SELECT * FROM whatsapp_logs WHERE customer_id IN ($idList) ORDER BY id ASC")->fetchAll();
                    } else {
                        $vouchers = [];
                        $waLogs = [];
                    }
                }
            } else {
                $customers = $pdo->query("SELECT * FROM customers ORDER BY id ASC")->fetchAll();
                $vouchers = $pdo->query("SELECT * FROM vouchers ORDER BY id ASC")->fetchAll();
                $waLogs = $pdo->query("SELECT * FROM whatsapp_logs ORDER BY id ASC")->fetchAll();
            }
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="shreeshta-registrations-' . $dateSuffix . '-' . $datestamp . '.json"');
            echo json_encode([
                'exported_at' => date('c'),
                'event_date_filter' => $eventDateParam,
                'event_dates' => $exportDates,
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

$filterLabel = match (true) {
    $eventDateParam === 'all' => 'All history — every registration (' . OfferCatalog::registrationCountForFilter('all') . ')',
    $eventDateParam === 'current' => $activeEventDates
        ? ('Current event: ' . implode(', ', array_map(
            static fn ($d) => (new DateTimeImmutable($d))->format('d M Y'),
            $activeEventDates
        )) . ' (' . OfferCatalog::registrationCountForFilter('current') . ' rows)')
        : 'Current event (no active dates configured)',
    default => 'Event date: ' . (new DateTimeImmutable($eventDateParam))->format('d M Y')
        . ' (' . OfferCatalog::registrationCountForFilter($eventDateParam) . ' rows)',
};
?>
<?php require __DIR__ . '/../templates/admin_nav.php'; ?>

<main class="flex-1 max-w-3xl mx-auto px-5 py-8">
  <h2 class="font-heading text-2xl font-bold text-maroon mb-2">Data Exports &amp; Backup</h2>
  <p class="text-sm text-maroon-dark/70 mb-5">
    Default download is <strong>All history</strong> (previous + new).
    Choose <strong>Current event</strong> only when you want this week’s registrations.
  </p>

  <form method="get" class="bg-white gold-border rounded-2xl p-4 shadow-sm mb-4 flex flex-col sm:flex-row gap-3 items-end">
    <div class="flex-1 w-full">
      <label class="block text-sm font-semibold text-maroon-dark mb-1">Event date for export</label>
      <select name="event_date" class="w-full rounded-lg gold-border px-3 py-2">
        <option value="all" <?= $eventDateParam === 'all' ? 'selected' : '' ?>>
          All history (<?= OfferCatalog::registrationCountForFilter('all') ?>)
        </option>
        <option value="current" <?= $eventDateParam === 'current' ? 'selected' : '' ?>>
          Current event (<?= OfferCatalog::registrationCountForFilter('current') ?>)
        </option>
        <?php foreach ($dateChoices as $d): ?>
          <option value="<?= e($d) ?>" <?= $eventDateParam === $d ? 'selected' : '' ?>>
            <?= e((new DateTimeImmutable($d))->format('D, d M Y')) ?>
            (<?= OfferCatalog::registrationCountForFilter($d) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="bg-maroon hover:bg-maroon-dark text-ivory font-bold px-5 py-2 rounded-lg">Apply</button>
  </form>

  <p class="text-xs text-maroon-dark/65 mb-4"><?= e($filterLabel) ?></p>

  <div class="bg-white gold-border rounded-2xl p-5 sm:p-6 shadow-sm space-y-3">
    <?php
      $buttons = [
        ['csv_all', 'Download CSV'],
        ['json_all', 'Download JSON'],
        ['csv_morning', 'Download Morning Session CSV'],
        ['csv_evening', 'Download Evening Session CSV'],
        ['csv_redeemed', 'Download Redeemed Customers CSV'],
        ['csv_pending', 'Download Pending Customers CSV'],
      ];
      $dateQs = rawurlencode($eventDateParam);
    ?>
    <?php foreach ($buttons as [$key, $label]): ?>
      <a href="?download=<?= e($key) ?>&amp;event_date=<?= e($dateQs) ?>"
        class="block text-center bg-ivory hover:bg-gold-light/40 transition gold-border rounded-xl px-4 py-3 font-semibold text-maroon-dark">
        <?= e($label) ?>
      </a>
    <?php endforeach; ?>

    <a href="?download=backup_db"
      class="block text-center bg-maroon hover:bg-maroon-dark transition text-ivory rounded-xl px-4 py-3 font-bold mt-4">
      Download Complete Database Backup
    </a>
  </div>

  <p class="text-xs text-maroon-dark/60 mt-4">All downloads are audit-logged.</p>
</main>

<?php require __DIR__ . '/../templates/footer.php'; ?>
