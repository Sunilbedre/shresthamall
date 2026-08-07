<?php
/**
 * admin/dashboard.php  ->  route: /admin
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

AuthService::requireLogin();
if (AuthService::isSubAdmin()) {
    redirect(admin_url('registrations.php'));
}
$pdo = Database::connection();

$totalRegistrations = (int) $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$morningCount = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE session = 'morning'")->fetchColumn();
$eveningCount = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE session = 'evening'")->fetchColumn();
$activeVouchers = (int) $pdo->query("SELECT COUNT(*) FROM vouchers WHERE status = 'ACTIVE'")->fetchColumn();
$redeemedVouchers = (int) $pdo->query("SELECT COUNT(*) FROM vouchers WHERE status = 'REDEEMED'")->fetchColumn();
$waSent = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_logs WHERE status IN ('SENT','DELIVERED','READ')")->fetchColumn();
$waFailed = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_logs WHERE status = 'FAILED'")->fetchColumn();

$productStmt = $pdo->query("SELECT selected_product, COUNT(*) as cnt FROM customers GROUP BY selected_product");
$productCounts = [];
foreach ($productStmt->fetchAll() as $row) {
    $productCounts[$row['selected_product']] = (int) $row['cnt'];
}

$cards = [
    ['Total Registrations', $totalRegistrations, 'maroon'],
    ['11 AM – 2 PM', $morningCount, 'gold'],
    ['5 PM – 8 PM', $eveningCount, 'gold'],
    ['Active Vouchers', $activeVouchers, 'green'],
    ['Redeemed Vouchers', $redeemedVouchers, 'maroon'],
    ['WhatsApp Sent', $waSent, 'green'],
    ['WhatsApp Failed', $waFailed, 'red'],
];

$activePage = 'dashboard';
$skipDefaultHeader = true;
$pageTitle = 'Admin Dashboard – Shreeshta Family Store';
require __DIR__ . '/../templates/header.php';
?>

<?php require __DIR__ . '/../templates/admin_nav.php'; ?>

<main class="flex-1 max-w-5xl mx-auto px-5 py-8">
  <h2 class="font-heading text-2xl font-bold text-maroon mb-5">Overview</h2>

  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-10">
    <?php foreach ($cards as [$label, $value, $color]): ?>
      <div class="bg-white gold-border rounded-2xl p-4 text-center shadow-sm">
        <p class="text-2xl sm:text-3xl font-extrabold text-maroon"><?= (int) $value ?></p>
        <p class="text-xs sm:text-sm text-maroon-dark/70 mt-1"><?= e($label) ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <h3 class="font-heading text-xl font-bold text-maroon mb-4">Product-wise Totals</h3>
  <div class="grid sm:grid-cols-2 gap-3">
    <?php foreach (Products::all() as $key => $p): ?>
      <div class="bg-white gold-border rounded-xl px-4 py-3 flex items-center justify-between">
        <span class="text-sm sm:text-base"><?= e($p['label']) ?></span>
        <span class="font-bold text-maroon text-lg"><?= (int) ($productCounts[$key] ?? 0) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</main>

<?php require __DIR__ . '/../templates/footer.php'; ?>
