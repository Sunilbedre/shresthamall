<?php
/**
 * admin/dashboard.php  ->  route: /admin
 * Stats default to current active event dates (not all history).
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

AuthService::requireLogin();
$pdo = Database::connection();

$activeDates = OfferCatalog::eventDates(true);
// Subadmin: current event only (no all-history)
$scope = AuthService::isSubAdmin() ? 'current' : (string) ($_GET['scope'] ?? 'current'); // current | all

$customerWhere = '';
$voucherWhere = '';
$params = [];
$vParams = [];

if ($scope !== 'all' && $activeDates) {
    $ph = [];
    foreach ($activeDates as $i => $d) {
        $key = 'ed' . $i;
        $ph[] = ':' . $key;
        $params[$key] = $d;
        $vParams[$key] = $d;
    }
    $in = implode(',', $ph);
    $customerWhere = " WHERE c.event_date IN ($in)";
    $voucherWhere = " WHERE v.event_date IN ($in)";
} elseif ($scope !== 'all' && !$activeDates) {
    // No active event — show zeros for current scope
    $customerWhere = ' WHERE 1 = 0';
    $voucherWhere = ' WHERE 1 = 0';
}

$countCustomers = $pdo->prepare("SELECT COUNT(*) FROM customers c{$customerWhere}");
$countCustomers->execute($params);
$totalRegistrations = (int) $countCustomers->fetchColumn();

$morningStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c{$customerWhere}" . ($customerWhere ? ' AND' : ' WHERE') . " c.session = 'morning'");
$morningStmt->execute($params);
$morningCount = (int) $morningStmt->fetchColumn();

$eveningStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c{$customerWhere}" . ($customerWhere ? ' AND' : ' WHERE') . " c.session = 'evening'");
$eveningStmt->execute($params);
$eveningCount = (int) $eveningStmt->fetchColumn();

$activeStmt = $pdo->prepare("SELECT COUNT(*) FROM vouchers v{$voucherWhere}" . ($voucherWhere ? ' AND' : ' WHERE') . " v.status = 'ACTIVE'");
$activeStmt->execute($vParams);
$activeVouchers = (int) $activeStmt->fetchColumn();

$redeemedStmt = $pdo->prepare("SELECT COUNT(*) FROM vouchers v{$voucherWhere}" . ($voucherWhere ? ' AND' : ' WHERE') . " v.status = 'REDEEMED'");
$redeemedStmt->execute($vParams);
$redeemedVouchers = (int) $redeemedStmt->fetchColumn();

// WhatsApp: join via customers for event filter
$waBase = "
    FROM whatsapp_logs wl
    INNER JOIN customers c ON c.id = wl.customer_id
";
$waWhere = $customerWhere; // already uses c.
$waSentStmt = $pdo->prepare("SELECT COUNT(*) {$waBase}{$waWhere}" . ($waWhere ? ' AND' : ' WHERE') . " wl.status IN ('SENT','DELIVERED','READ')");
$waSentStmt->execute($params);
$waSent = (int) $waSentStmt->fetchColumn();

$waFailStmt = $pdo->prepare("SELECT COUNT(*) {$waBase}{$waWhere}" . ($waWhere ? ' AND' : ' WHERE') . " wl.status = 'FAILED'");
$waFailStmt->execute($params);
$waFailed = (int) $waFailStmt->fetchColumn();

$productStmt = $pdo->prepare("SELECT c.selected_product, COUNT(*) as cnt FROM customers c{$customerWhere} GROUP BY c.selected_product");
$productStmt->execute($params);
$productCounts = [];
foreach ($productStmt->fetchAll() as $row) {
    $productCounts[$row['selected_product']] = (int) $row['cnt'];
}

// Allocated capacity = sum of slot capacities (per product) for the scoped event dates
$productAllocated = [];
if ($scope !== 'all' && $activeDates) {
    $ph = [];
    $aParams = [];
    foreach ($activeDates as $i => $d) {
        $key = 'ad' . $i;
        $ph[] = ':' . $key;
        $aParams[$key] = $d;
    }
    $allocStmt = $pdo->prepare("
        SELECT product_key, COALESCE(SUM(capacity), 0) AS allocated
        FROM offer_slots
        WHERE event_date IN (" . implode(',', $ph) . ")
        GROUP BY product_key
    ");
    $allocStmt->execute($aParams);
    foreach ($allocStmt->fetchAll() as $row) {
        $productAllocated[$row['product_key']] = (int) $row['allocated'];
    }
} elseif ($scope === 'all') {
    $allocStmt = $pdo->query("
        SELECT product_key, COALESCE(SUM(capacity), 0) AS allocated
        FROM offer_slots
        GROUP BY product_key
    ");
    foreach ($allocStmt->fetchAll() as $row) {
        $productAllocated[$row['product_key']] = (int) $row['allocated'];
    }
}

$lifetimeTotal = (int) $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();

$cards = [
    ['Total Registrations', $totalRegistrations, 'maroon'],
    ['Morning', $morningCount, 'gold'],
    ['Evening', $eveningCount, 'gold'],
    ['Active Vouchers', $activeVouchers, 'green'],
    ['Redeemed Vouchers', $redeemedVouchers, 'maroon'],
    ['WhatsApp Sent', $waSent, 'green'],
    ['WhatsApp Failed', $waFailed, 'red'],
];

$eventLabel = $activeDates
    ? implode(' · ', array_map(static fn ($d) => (new DateTimeImmutable($d))->format('d M Y'), $activeDates))
    : 'No active event dates';

$activePage = 'dashboard';
$skipDefaultHeader = true;
$pageTitle = 'Admin Dashboard – Shreeshta Family Store';
require __DIR__ . '/../templates/header.php';
?>

<?php require __DIR__ . '/../templates/admin_nav.php'; ?>

<main class="flex-1 max-w-5xl mx-auto px-5 py-8">
  <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
    <div>
      <h2 class="font-heading text-2xl font-bold text-maroon">Overview</h2>
      <?php if ($scope !== 'all'): ?>
        <p class="text-sm text-maroon-dark/70 mt-1">Current event: <strong><?= e($eventLabel) ?></strong></p>
      <?php else: ?>
        <p class="text-sm text-maroon-dark/70 mt-1">All history (includes past events)</p>
      <?php endif; ?>
    </div>
    <div class="flex gap-2 text-sm">
      <?php if (!AuthService::isSubAdmin()): ?>
        <a href="?scope=current"
          class="px-3 py-1.5 rounded-lg font-semibold <?= $scope !== 'all' ? 'bg-maroon text-ivory' : 'bg-white gold-border text-maroon-dark' ?>">
          This event
        </a>
        <a href="?scope=all"
          class="px-3 py-1.5 rounded-lg font-semibold <?= $scope === 'all' ? 'bg-maroon text-ivory' : 'bg-white gold-border text-maroon-dark' ?>">
          All history (<?= $lifetimeTotal ?>)
        </a>
      <?php else: ?>
        <span class="px-3 py-1.5 rounded-lg font-semibold bg-maroon text-ivory">This event</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-10">
    <?php foreach ($cards as [$label, $value, $color]): ?>
      <div class="bg-white gold-border rounded-2xl p-4 text-center shadow-sm">
        <p class="text-2xl sm:text-3xl font-extrabold text-maroon"><?= (int) $value ?></p>
        <p class="text-xs sm:text-sm text-maroon-dark/70 mt-1"><?= e($label) ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <h3 class="font-heading text-xl font-bold text-maroon mb-4">Product-wise Totals</h3>
  <p class="text-xs text-maroon-dark/55 mb-3">Registered / Allocated (slot capacity)</p>
  <div class="grid sm:grid-cols-2 gap-3">
    <?php
      $products = Products::all();
      $shown = 0;
      foreach ($products as $key => $p):
        $registered = (int) ($productCounts[$key] ?? 0);
        $allocated = (int) ($productAllocated[$key] ?? 0);
        if ($scope !== 'all' && (int) $p['active'] !== 1 && $registered === 0 && $allocated === 0) {
            continue;
        }
        $shown++;
        $remaining = max(0, $allocated - $registered);
        $isNoLimit = $allocated >= 50000;
    ?>
      <div class="bg-white gold-border rounded-xl px-4 py-3 flex items-center justify-between gap-3">
        <div class="min-w-0">
          <span class="text-sm sm:text-base block"><?= e($p['label']) ?></span>
          <?php if ($isNoLimit): ?>
            <span class="text-[11px] text-green-800 font-medium">No stock limit</span>
          <?php elseif ($allocated > 0): ?>
            <span class="text-[11px] text-maroon-dark/55">Left: <?= $remaining ?></span>
          <?php endif; ?>
        </div>
        <div class="text-right shrink-0">
          <span class="font-bold text-maroon text-lg tabular-nums"><?= $registered ?></span>
          <span class="text-maroon-dark/45 font-semibold text-lg"> / </span>
          <span class="font-bold text-maroon-dark/70 text-lg tabular-nums"><?= $allocated > 0 ? ($isNoLimit ? 'No limit' : $allocated) : '—' ?></span>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if ($shown === 0): ?>
      <p class="text-sm text-maroon-dark/60">No registrations for this event yet.</p>
    <?php endif; ?>
  </div>
</main>

<?php require __DIR__ . '/../templates/footer.php'; ?>
