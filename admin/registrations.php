<?php
/**
 * admin/registrations.php  ->  route: /admin/registrations
 * Full customer/voucher table with search, filters, pagination and actions.
 * Staff role: view + resend WhatsApp only. Admin role: also block voucher.
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

AuthService::requireAnyRole(['admin', 'staff', 'subadmin']);
$pdo = Database::connection();

$canAct = AuthService::isAdmin() || AuthService::isStaff(); // resend / verify links
$canBlock = AuthService::isAdmin();

$flash = null;
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (AuthService::isSubAdmin()) {
        $flashError = 'View-only account — actions are disabled.';
    } elseif (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $flashError = 'Your session expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $customer = $customerId ? CustomerService::findById($customerId) : null;

        if (!$customer) {
            $flashError = 'Registration not found.';
        } elseif ($action === 'resend_whatsapp' && $canAct) {
            $maxResends = (int) Settings::get('max_whatsapp_resends', '3');
            if (WhatsAppService::resendsToday($customerId) >= $maxResends) {
                $flashError = 'Maximum resend attempts reached for today for this customer.';
            } else {
                $voucher = VoucherModel::findByCustomerId($customerId);
                if ($voucher) {
                    $sendResult = WhatsAppService::sendVoucher($customer, $voucher);
                    $flash = $sendResult['ok'] ? 'Voucher resent via WhatsApp.' : 'WhatsApp resend failed — see WhatsApp status for details.';
                }
            }
        } elseif ($action === 'block' && $canBlock) {
            $voucher = VoucherModel::findByCustomerId($customerId);
            if ($voucher) {
                VoucherModel::setStatus((int) $voucher['id'], 'BLOCKED', AuthService::currentUsername() ?? 'admin');
                $flash = 'Voucher blocked.';
            }
        } elseif ($action === 'unblock' && $canBlock) {
            $voucher = VoucherModel::findByCustomerId($customerId);
            if ($voucher) {
                VoucherModel::setStatus((int) $voucher['id'], 'ACTIVE', AuthService::currentUsername() ?? 'admin');
                $flash = 'Voucher unblocked.';
            }
        } elseif ($action === 'delete' && $canBlock) {
            $mobile = $customer['mobile_number'] ?? '';
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM vouchers WHERE customer_id = ?")->execute([$customerId]);
                $pdo->prepare("DELETE FROM whatsapp_logs WHERE customer_id = ?")->execute([$customerId]);
                $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$customerId]);
                $pdo->commit();
                $flash = "Registration deleted — mobile {$mobile} can register again.";
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $flashError = 'Delete failed: ' . $e->getMessage();
            }
        }
    }
}

// ---- Filters ----
$search = trim((string) ($_GET['q'] ?? ''));
$productFilter = (string) ($_GET['product'] ?? '');
$sessionFilter = (string) ($_GET['session'] ?? '');
$voucherStatusFilter = (string) ($_GET['voucher_status'] ?? '');
$waStatusFilter = (string) ($_GET['wa_status'] ?? '');
// Default: current active event dates only (past numbers stay blocked but hidden here)
$eventDateFilter = (string) ($_GET['event_date'] ?? 'current');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$activeEventDates = OfferCatalog::eventDates(true);
$registeredDates = OfferCatalog::registeredEventDates();
$dateChoices = array_values(array_unique(array_merge($activeEventDates, $registeredDates)));
rsort($dateChoices);

$where = [];
$params = [];

if ($eventDateFilter === 'current') {
    if ($activeEventDates) {
        $ph = [];
        foreach ($activeEventDates as $i => $d) {
            $key = 'ced' . $i;
            $ph[] = ':' . $key;
            $params[$key] = $d;
        }
        $where[] = 'c.event_date IN (' . implode(',', $ph) . ')';
    } else {
        $where[] = '1 = 0'; // no active event → empty list
    }
} elseif ($eventDateFilter !== 'all' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDateFilter)) {
    $where[] = 'c.event_date = :event_date';
    $params['event_date'] = $eventDateFilter;
}

if ($search !== '') {
    $normalisedSearch = Validation::normaliseMobile($search);
    $where[] = "(c.full_name LIKE :search OR c.mobile_number LIKE :search2 OR v.voucher_code LIKE :search3)";
    $params['search'] = '%' . $search . '%';
    $params['search2'] = '%' . ($normalisedSearch ?? $search) . '%';
    $params['search3'] = '%' . strtoupper($search) . '%';
}
if ($productFilter !== '' && Products::exists($productFilter)) {
    $where[] = "c.selected_product = :product";
    $params['product'] = $productFilter;
}
if (in_array($sessionFilter, ['morning', 'evening'], true)) {
    $where[] = "c.session = :session";
    $params['session'] = $sessionFilter;
}
if (in_array($voucherStatusFilter, ['ACTIVE', 'REDEEMED', 'CANCELLED', 'BLOCKED'], true)) {
    $where[] = "v.status = :vstatus";
    $params['vstatus'] = $voucherStatusFilter;
}
if (in_array($waStatusFilter, ['PENDING', 'SENT', 'DELIVERED', 'READ', 'FAILED'], true)) {
    $where[] = "wl.status = :wastatus";
    $params['wastatus'] = $waStatusFilter;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$baseQuery = "
    FROM customers c
    LEFT JOIN vouchers v ON v.customer_id = c.id
    LEFT JOIN (
        SELECT wl1.* FROM whatsapp_logs wl1
        INNER JOIN (SELECT customer_id, MAX(id) as max_id FROM whatsapp_logs GROUP BY customer_id) latest
            ON latest.customer_id = wl1.customer_id AND latest.max_id = wl1.id
    ) wl ON wl.customer_id = c.id
    $whereSql
";

$countStmt = $pdo->prepare("SELECT COUNT(*) $baseQuery");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare("
    SELECT c.*, v.id as voucher_id, v.voucher_code, v.status as voucher_status, v.redeemed_at,
           wl.status as wa_status
    $baseQuery
    ORDER BY c.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $listStmt->bindValue(':' . $k, $v);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll();

$activePage = 'registrations';
$skipDefaultHeader = true;
$pageTitle = 'Registrations – Shreeshta Family Store';
require __DIR__ . '/../templates/header.php';
?>

<?php require __DIR__ . '/../templates/admin_nav.php'; ?>

<main class="flex-1 max-w-7xl mx-auto px-5 py-8">
  <h2 class="font-heading text-2xl font-bold text-maroon mb-5">Registrations (<?= $totalRows ?>)</h2>

  <?php if ($flash): ?>
    <p class="text-green-700 bg-green-50 border border-green-200 rounded-lg px-4 py-2 text-sm mb-4"><?= e($flash) ?></p>
  <?php endif; ?>
  <?php if ($flashError): ?>
    <p class="text-red-700 bg-red-50 border border-red-200 rounded-lg px-4 py-2 text-sm mb-4"><?= e($flashError) ?></p>
  <?php endif; ?>

  <form method="get" class="bg-white gold-border rounded-2xl p-4 shadow-sm mb-6 grid sm:grid-cols-6 gap-3 items-end text-sm">
    <div class="sm:col-span-2">
      <label class="block font-semibold text-maroon-dark mb-1">Search</label>
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="Name, mobile or voucher code"
        class="w-full rounded-lg gold-border px-3 py-2">
    </div>
    <div>
      <label class="block font-semibold text-maroon-dark mb-1">Event date</label>
      <select name="event_date" class="w-full rounded-lg gold-border px-3 py-2">
        <option value="current" <?= $eventDateFilter === 'current' ? 'selected' : '' ?>>Current event</option>
        <?php foreach ($dateChoices as $d): ?>
          <option value="<?= e($d) ?>" <?= $eventDateFilter === $d ? 'selected' : '' ?>>
            <?= e((new DateTimeImmutable($d))->format('d M Y')) ?>
          </option>
        <?php endforeach; ?>
        <option value="all" <?= $eventDateFilter === 'all' ? 'selected' : '' ?>>All history</option>
      </select>
    </div>
    <div>
      <label class="block font-semibold text-maroon-dark mb-1">Product</label>
      <select name="product" class="w-full rounded-lg gold-border px-3 py-2">
        <option value="">All</option>
        <?php foreach (Products::all() as $key => $p): ?>
          <option value="<?= e($key) ?>" <?= $productFilter === $key ? 'selected' : '' ?>><?= e($p['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block font-semibold text-maroon-dark mb-1">Session</label>
      <select name="session" class="w-full rounded-lg gold-border px-3 py-2">
        <option value="">All</option>
        <option value="morning" <?= $sessionFilter === 'morning' ? 'selected' : '' ?>>Morning</option>
        <option value="evening" <?= $sessionFilter === 'evening' ? 'selected' : '' ?>>Evening</option>
      </select>
    </div>
    <div>
      <label class="block font-semibold text-maroon-dark mb-1">Voucher Status</label>
      <select name="voucher_status" class="w-full rounded-lg gold-border px-3 py-2">
        <option value="">All</option>
        <?php foreach (['ACTIVE', 'REDEEMED', 'CANCELLED', 'BLOCKED'] as $s): ?>
          <option value="<?= $s ?>" <?= $voucherStatusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="flex gap-2 sm:col-span-6">
      <button type="submit" class="bg-maroon hover:bg-maroon-dark text-ivory font-bold px-4 py-2 rounded-lg">Filter</button>
      <a href="<?= e(admin_url('registrations.php')) ?>" class="text-center bg-gray-100 hover:bg-gray-200 text-maroon-dark font-semibold px-4 py-2 rounded-lg">Reset</a>
    </div>
  </form>

  <div class="bg-white gold-border rounded-2xl shadow-sm overflow-x-auto">
    <table class="w-full text-sm min-w-[1000px]">
      <thead class="bg-maroon text-ivory">
        <tr>
          <th class="px-3 py-3 text-left">#</th>
          <th class="px-3 py-3 text-left">Name</th>
          <th class="px-3 py-3 text-left">Mobile</th>
          <th class="px-3 py-3 text-left">Area</th>
          <th class="px-3 py-3 text-left">Product</th>
          <th class="px-3 py-3 text-left">Date</th>
          <th class="px-3 py-3 text-left">Session</th>
          <th class="px-3 py-3 text-left">Voucher</th>
          <th class="px-3 py-3 text-left">Registered</th>
          <th class="px-3 py-3 text-left">WhatsApp</th>
          <th class="px-3 py-3 text-left">Status</th>
          <th class="px-3 py-3 text-left">Redeemed</th>
          <th class="px-3 py-3 text-left">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $row): ?>
          <tr class="border-t border-gold/20 hover:bg-ivory/60">
            <td class="px-3 py-2.5"><?= $offset + $i + 1 ?></td>
            <td class="px-3 py-2.5 font-medium"><?= e($row['full_name']) ?></td>
            <td class="px-3 py-2.5"><?= e(Validation::maskMobile($row['mobile_number'])) ?></td>
            <td class="px-3 py-2.5 whitespace-nowrap"><?= e($row['area'] ?? '—') ?></td>
            <td class="px-3 py-2.5"><?= e(Products::label($row['selected_product']) ?? $row['selected_product']) ?></td>
            <td class="px-3 py-2.5 whitespace-nowrap"><?= e(!empty($row['event_date']) ? (new DateTimeImmutable($row['event_date']))->format('d M Y') : '—') ?></td>
            <td class="px-3 py-2.5 whitespace-nowrap"><?= e(match ($row['session'] ?? '') {
              'morning' => 'Morning',
              'evening' => 'Evening',
              default => $row['session'] ?? '-',
            }) ?></td>
            <td class="px-3 py-2.5 font-mono"><?= e($row['voucher_code'] ?? '-') ?></td>
            <td class="px-3 py-2.5 whitespace-nowrap"><?= e($row['registered_at']) ?></td>
            <td class="px-3 py-2.5">
              <span class="px-2 py-0.5 rounded-full text-xs font-semibold
                <?= match($row['wa_status'] ?? 'PENDING') {
                    'SENT', 'DELIVERED', 'READ' => 'bg-green-100 text-green-700',
                    'FAILED' => 'bg-red-100 text-red-700',
                    default => 'bg-amber-100 text-amber-700',
                } ?>">
                <?= e($row['wa_status'] ?? 'PENDING') ?>
              </span>
            </td>
            <td class="px-3 py-2.5">
              <span class="px-2 py-0.5 rounded-full text-xs font-semibold
                <?= match($row['voucher_status'] ?? '') {
                    'ACTIVE' => 'bg-blue-100 text-blue-700',
                    'REDEEMED' => 'bg-green-100 text-green-700',
                    'BLOCKED', 'CANCELLED' => 'bg-red-100 text-red-700',
                    default => 'bg-gray-100 text-gray-600',
                } ?>">
                <?= e($row['voucher_status'] ?? '-') ?>
              </span>
            </td>
            <td class="px-3 py-2.5 whitespace-nowrap"><?= e($row['redeemed_at'] ?? '-') ?></td>
            <td class="px-3 py-2.5">
              <?php if ($canAct): ?>
              <div class="flex flex-wrap gap-1.5">
                <a href="/verify.php" class="text-xs font-semibold text-maroon underline">Verify</a>
                <form method="post" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="resend_whatsapp">
                  <input type="hidden" name="customer_id" value="<?= (int) $row['id'] ?>">
                  <button type="submit" class="text-xs font-semibold text-gold hover:text-maroon underline">Resend</button>
                </form>
                <?php if ($canBlock): ?>
                  <?php if (($row['voucher_status'] ?? '') === 'BLOCKED'): ?>
                    <form method="post" class="inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="unblock">
                      <input type="hidden" name="customer_id" value="<?= (int) $row['id'] ?>">
                      <button type="submit" class="text-xs font-semibold text-green-700 underline">Unblock</button>
                    </form>
                  <?php else: ?>
                    <form method="post" class="inline" onsubmit="return confirm('Block this voucher?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="block">
                      <input type="hidden" name="customer_id" value="<?= (int) $row['id'] ?>">
                      <button type="submit" class="text-xs font-semibold text-red-600 underline">Block</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" class="inline" onsubmit="return confirm('Delete this registration? The mobile number will be free to register again.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="customer_id" value="<?= (int) $row['id'] ?>">
                    <button type="submit" class="text-xs font-semibold text-red-700 underline">Delete</button>
                  </form>
                <?php endif; ?>
              </div>
              <?php else: ?>
                <span class="text-xs text-maroon-dark/50">View only</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
          <tr><td colspan="13" class="px-3 py-8 text-center text-maroon-dark/60">No registrations match these filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php
    $from = $totalRows === 0 ? 0 : ($offset + 1);
    $to = min($offset + count($rows), $totalRows);
    $queryBase = $_GET;
  ?>
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mt-5 text-sm">
    <p class="text-maroon-dark/70">
      Showing <span class="font-semibold"><?= $from ?></span>–<span class="font-semibold"><?= $to ?></span>
      of <span class="font-semibold"><?= $totalRows ?></span>
      <?= $totalPages > 1 ? "(page {$page} of {$totalPages})" : '' ?>
    </p>
    <?php if ($totalPages > 1): ?>
      <div class="flex flex-wrap items-center gap-2">
        <?php if ($page > 1): ?>
          <a href="?<?= e(http_build_query(array_merge($queryBase, ['page' => $page - 1]))) ?>"
            class="px-3 py-1.5 rounded-lg gold-border bg-white text-maroon-dark font-semibold">Previous</a>
        <?php endif; ?>
        <?php
          $startP = max(1, $page - 2);
          $endP = min($totalPages, $page + 2);
          for ($p = $startP; $p <= $endP; $p++):
        ?>
          <a href="?<?= e(http_build_query(array_merge($queryBase, ['page' => $p]))) ?>"
            class="px-3 py-1.5 rounded-lg gold-border font-semibold <?= $p === $page ? 'bg-maroon text-ivory' : 'bg-white text-maroon-dark' ?>">
            <?= $p ?>
          </a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="?<?= e(http_build_query(array_merge($queryBase, ['page' => $page + 1]))) ?>"
            class="px-3 py-1.5 rounded-lg gold-border bg-white text-maroon-dark font-semibold">Next</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php require __DIR__ . '/../templates/footer.php'; ?>
