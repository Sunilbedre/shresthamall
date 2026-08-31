<?php
/**
 * admin/offers_report.php — SM Offers Update report
 * Registered / Purchased / % by product × event date (Excel-style layout).
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

AuthService::requireLogin();

$pdo = Database::connection();

$activeDates = OfferCatalog::eventDates(true);
$registeredDates = OfferCatalog::registeredEventDates();
$allDates = array_values(array_unique(array_merge($activeDates, $registeredDates)));
rsort($allDates);

// Scope: current active dates, all registered dates, or pick two custom dates
// Subadmin: current event only
$scope = AuthService::isSubAdmin() ? 'current' : (string) ($_GET['scope'] ?? 'current');
$dates = [];
if ($scope === 'all' && !AuthService::isSubAdmin()) {
    $dates = $allDates;
} elseif ($scope === 'current' && $activeDates) {
    $dates = $activeDates;
    sort($dates);
} elseif ($scope === 'current' && !$activeDates && $allDates) {
    // Fallback: newest up to 2 registered dates
    $dates = array_slice($allDates, 0, 2);
    sort($dates);
} elseif (!AuthService::isSubAdmin()) {
    $d1 = (string) ($_GET['d1'] ?? '');
    $d2 = (string) ($_GET['d2'] ?? '');
    foreach ([$d1, $d2] as $d) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $dates[] = $d;
        }
    }
    $dates = array_values(array_unique($dates));
    sort($dates);
}

if (!$dates && $allDates) {
    $dates = array_slice($allDates, 0, 2);
    sort($dates);
}

// Products: active catalogue + any product keys that appear in selected dates
$products = OfferCatalog::products(false);
$productMap = [];
foreach ($products as $p) {
    $productMap[$p['product_key']] = $p['label'];
}

if ($dates) {
    $ph = implode(',', array_fill(0, count($dates), '?'));
    $extra = $pdo->prepare("
        SELECT DISTINCT selected_product
        FROM customers
        WHERE event_date IN ($ph)
          AND selected_product IS NOT NULL
          AND selected_product != ''
    ");
    $extra->execute($dates);
    foreach ($extra->fetchAll(PDO::FETCH_COLUMN) as $key) {
        $key = (string) $key;
        if ($key !== '' && !isset($productMap[$key])) {
            $productMap[$key] = Products::label($key) ?? $key;
        }
    }
}

// Prefer active products first, then others
$orderedKeys = [];
foreach ($products as $p) {
    if ((int) ($p['active'] ?? 0) === 1 && isset($productMap[$p['product_key']])) {
        $orderedKeys[] = $p['product_key'];
    }
}
foreach (array_keys($productMap) as $key) {
    if (!in_array($key, $orderedKeys, true)) {
        $orderedKeys[] = $key;
    }
}

/**
 * @return array{allocated:int, registered:int, purchased:int, pct:float}
 */
function offersReportCell(PDO $pdo, string $productKey, string $eventDate): array
{
    $allocStmt = $pdo->prepare("
        SELECT COALESCE(SUM(capacity), 0) FROM offer_slots
        WHERE product_key = :p AND event_date = :d
    ");
    $allocStmt->execute(['p' => $productKey, 'd' => $eventDate]);
    $allocated = (int) $allocStmt->fetchColumn();

    $regStmt = $pdo->prepare("
        SELECT COUNT(*) FROM customers
        WHERE selected_product = :p AND event_date = :d
    ");
    $regStmt->execute(['p' => $productKey, 'd' => $eventDate]);
    $registered = (int) $regStmt->fetchColumn();

    $buyStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM customers c
        INNER JOIN vouchers v ON v.customer_id = c.id
        WHERE c.selected_product = :p
          AND c.event_date = :d
          AND v.status = 'REDEEMED'
    ");
    $buyStmt->execute(['p' => $productKey, 'd' => $eventDate]);
    $purchased = (int) $buyStmt->fetchColumn();

    $pct = $registered > 0 ? round(($purchased / $registered) * 100, 2) : 0.0;

    return [
        'allocated'  => $allocated,
        'registered' => $registered,
        'purchased'  => $purchased,
        'pct'        => $pct,
    ];
}

$rows = [];
$totalsByDate = [];
foreach ($dates as $d) {
    $totalsByDate[$d] = ['allocated' => 0, 'registered' => 0, 'purchased' => 0];
}

foreach ($orderedKeys as $key) {
    $cells = [];
    $rowHasData = false;
    foreach ($dates as $d) {
        $cell = offersReportCell($pdo, $key, $d);
        $cells[$d] = $cell;
        $totalsByDate[$d]['allocated'] += $cell['allocated'];
        $totalsByDate[$d]['registered'] += $cell['registered'];
        $totalsByDate[$d]['purchased'] += $cell['purchased'];
        if ($cell['allocated'] > 0 || $cell['registered'] > 0 || $cell['purchased'] > 0) {
            $rowHasData = true;
        }
    }
    // Show active products always; inactive only if they have data
    $isActive = false;
    foreach ($products as $p) {
        if ($p['product_key'] === $key && (int) ($p['active'] ?? 0) === 1) {
            $isActive = true;
            break;
        }
    }
    if ($isActive || $rowHasData || !$dates) {
        $rows[] = [
            'key'   => $key,
            'label' => $productMap[$key] ?? $key,
            'cells' => $cells,
        ];
    }
}

$dateLabels = [];
foreach ($dates as $d) {
    $dateLabels[$d] = (new DateTimeImmutable($d))->format('d-m-Y');
}

$titleDates = '';
if ($dates) {
    $parts = array_map(
        static fn (string $d): string => (new DateTimeImmutable($d))->format('jS M Y'),
        $dates
    );
    $titleDates = implode(' & ', $parts);
}

$activePage = 'offers_report';
$skipDefaultHeader = true;
$pageTitle = 'Offers Update Report – Shreeshta Family Store';
require __DIR__ . '/../templates/header.php';
?>
<?php require __DIR__ . '/../templates/admin_nav.php'; ?>

<style>
  .sm-report-wrap { overflow-x: auto; }
  .sm-report {
    width: 100%;
    min-width: 720px;
    border-collapse: collapse;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 14px;
  }
  .sm-report th, .sm-report td {
    border: 1px solid #111;
    padding: 8px 10px;
    text-align: center;
    vertical-align: middle;
  }
  .sm-report .col-product {
    text-align: left;
    font-weight: 700;
    background: #fff59d;
    min-width: 260px;
  }
  .sm-report .hdr-title {
    background: #b2ebf2;
    font-weight: 800;
    font-size: 16px;
    line-height: 1.35;
  }
  .sm-report .hdr-title .title-hl {
    display: inline-block;
    background: #76ff03;
    padding: 2px 8px;
  }
  .sm-report .hdr-product { background: #ffeb3b; font-weight: 800; }
  .sm-report .hdr-date-a { background: #ffe0b2; font-weight: 800; }
  .sm-report .hdr-date-b { background: #c8e6c9; font-weight: 800; }
  .sm-report .hdr-sub { background: #fffde7; font-weight: 700; }
  .sm-report .hdr-sub .purchased-hl {
    display: inline-block;
    background: #ffeb3b;
    padding: 1px 6px;
  }
  .sm-report .val-allocated {
    background: #e3f2fd;
    font-weight: 700;
  }
  .sm-report .val-purchased {
    background: #ffeb3b;
    font-weight: 800;
  }
  .sm-report .total-row {
    background: #b2ebf2;
    font-weight: 800;
  }
  .sm-report .total-allocated {
    background: #90caf9;
    font-weight: 800;
  }
  .sm-report .total-purchased {
    background: #76ff03;
    font-weight: 900;
  }
  @media print {
    .no-print { display: none !important; }
    header, footer { display: none !important; }
    .sm-report-wrap { overflow: visible; }
    .sm-report { min-width: 0; }
  }
</style>

<main class="flex-1 max-w-6xl mx-auto px-4 sm:px-5 py-6">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-4 no-print">
    <div>
      <h2 class="font-heading text-2xl font-bold text-maroon">Offers Update Report</h2>
      <p class="text-sm text-maroon-dark/65">Allocated vs Registered vs Purchased (redeemed) by product and event date</p>
    </div>
    <div class="flex flex-wrap gap-2">
      <?php if (!AuthService::isSubAdmin()): ?>
        <a href="?scope=current"
          class="px-3 py-1.5 rounded-lg text-sm font-semibold <?= $scope === 'current' ? 'bg-maroon text-ivory' : 'bg-ivory gold-border text-maroon-dark' ?>">
          Current event
        </a>
        <a href="?scope=all"
          class="px-3 py-1.5 rounded-lg text-sm font-semibold <?= $scope === 'all' ? 'bg-maroon text-ivory' : 'bg-ivory gold-border text-maroon-dark' ?>">
          All dates
        </a>
      <?php else: ?>
        <span class="px-3 py-1.5 rounded-lg text-sm font-semibold bg-maroon text-ivory">Current event</span>
      <?php endif; ?>
      <button type="button" onclick="window.print()"
        class="px-3 py-1.5 rounded-lg text-sm font-semibold bg-gold text-maroon-dark">
        Print / PDF
      </button>
    </div>
  </div>

  <?php if (!$dates): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-900">
      No event dates found. Add slots in <a class="underline font-semibold" href="<?= e(admin_url('events.php')) ?>">Events</a>.
    </div>
  <?php else: ?>

  <div class="bg-white rounded-xl shadow-sm border border-gold/30 p-3 sm:p-4 sm-report-wrap">
    <table class="sm-report">
      <thead>
        <tr>
          <th class="hdr-title" colspan="<?= 1 + (count($dates) * 4) ?>">
            <span class="title-hl">SM - OFFERS UPDATE</span><br>
            <?= e($titleDates) ?>
          </th>
        </tr>
        <tr>
          <th class="hdr-product" rowspan="2">Selected Product</th>
          <?php foreach ($dates as $i => $d): ?>
            <th class="<?= $i % 2 === 0 ? 'hdr-date-a' : 'hdr-date-b' ?>" colspan="4">
              <?= e($dateLabels[$d]) ?>
            </th>
          <?php endforeach; ?>
        </tr>
        <tr>
          <?php foreach ($dates as $_d): ?>
            <th class="hdr-sub">Allocated</th>
            <th class="hdr-sub">Registered</th>
            <th class="hdr-sub"><span class="purchased-hl">Purchased</span></th>
            <th class="hdr-sub">% of Purchased</th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="<?= 1 + (count($dates) * 4) ?>">No product data for these dates.</td>
          </tr>
        <?php endif; ?>

        <?php foreach ($rows as $row): ?>
          <tr>
            <td class="col-product"><?= e($row['label']) ?></td>
            <?php foreach ($dates as $d): ?>
              <?php $c = $row['cells'][$d] ?? ['allocated' => 0, 'registered' => 0, 'purchased' => 0, 'pct' => 0]; ?>
              <td class="val-allocated"><?= ((int) $c['allocated'] >= 50000) ? 'No limit' : (int) $c['allocated'] ?></td>
              <td><?= (int) $c['registered'] ?></td>
              <td class="val-purchased"><?= (int) $c['purchased'] ?></td>
              <td><?= number_format((float) $c['pct'], 2) ?>%</td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>

        <tr class="total-row">
          <td>Grand Total</td>
          <?php foreach ($dates as $d): ?>
            <?php
              $ta = (int) ($totalsByDate[$d]['allocated'] ?? 0);
              $tr = (int) ($totalsByDate[$d]['registered'] ?? 0);
              $tp = (int) ($totalsByDate[$d]['purchased'] ?? 0);
              $tpct = $tr > 0 ? round(($tp / $tr) * 100, 2) : 0.0;
            ?>
            <td class="total-allocated"><?= ($ta >= 50000) ? 'No limit' : $ta ?></td>
            <td><?= $tr ?></td>
            <td class="total-purchased"><?= $tp ?></td>
            <td><?= number_format($tpct, 2) ?>%</td>
          <?php endforeach; ?>
        </tr>
      </tbody>
    </table>
  </div>

  <p class="text-xs text-maroon-dark/55 mt-3 no-print">
    <strong>Allocated</strong> = slot capacity set for that product on that date.
    <strong>Registered</strong> = customers who booked that product on that date.
    <strong>Purchased</strong> = vouchers marked <em>REDEEMED</em> for those customers.
  </p>
  <?php endif; ?>
</main>

<?php require __DIR__ . '/../templates/footer.php'; ?>
