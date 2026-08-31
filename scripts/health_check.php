<?php
/**
 * Live health check — read only.
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$pdo = Database::connection();
$ok = true;
$lines = [];

function line(string $s): void
{
    global $lines;
    $lines[] = $s;
    echo $s . PHP_EOL;
}

line('=== LIVE HEALTH CHECK ===');
line('time=' . date('c'));

// Schema
$cols = array_column($pdo->query('PRAGMA table_info(customers)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['event_date', 'offer_slot_id', 'mobile_number'] as $c) {
    $has = in_array($c, $cols, true);
    line(($has ? 'OK' : 'FAIL') . " customers.{$c}");
    if (!$has) {
        $ok = false;
    }
}

$customers = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$nullDates = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE event_date IS NULL OR event_date=''")->fetchColumn();
$dupMobiles = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT mobile_number FROM customers GROUP BY mobile_number HAVING COUNT(*)>1)')->fetchColumn();
line("customers={$customers}");
line("null_event_date={$nullDates}" . ($nullDates === 0 ? ' OK' : ' WARN'));
line("duplicate_mobiles={$dupMobiles}" . ($dupMobiles === 0 ? ' OK' : ' FAIL'));
if ($dupMobiles > 0) {
    $ok = false;
}

$byDate = $pdo->query("SELECT event_date, COUNT(*) c FROM customers GROUP BY event_date ORDER BY event_date")->fetchAll(PDO::FETCH_ASSOC);
foreach ($byDate as $r) {
    line('  date ' . ($r['event_date'] ?: '(null)') . '=' . $r['c']);
}

// Offers
$products = OfferCatalog::products(false);
$activeProducts = OfferCatalog::products(true);
$slots = OfferCatalog::allSlotsDetailed();
$activeDates = OfferCatalog::eventDates(true);
line('products_total=' . count($products));
line('products_active=' . count($activeProducts));
line('slots_total=' . count($slots));
line('active_dates=' . implode(',', $activeDates));

$expected = [
    'rupee1_saree|2026-08-22|morning|250',
    'rupee1_saree|2026-08-22|evening|250',
    'rupee1_saree|2026-08-23|morning|250',
    'rupee1_saree|2026-08-23|evening|250',
    'semi_kanjeevaram_599|2026-08-22|morning|200',
    'semi_kanjeevaram_599|2026-08-23|morning|200',
];
$have = [];
foreach ($slots as $s) {
    if ((int) $s['active'] !== 1) {
        continue;
    }
    $have[] = $s['product_key'] . '|' . $s['event_date'] . '|' . $s['session'] . '|' . (int) $s['capacity'];
}
sort($expected);
sort($have);
$slotsOk = $expected === $have;
line(($slotsOk ? 'OK' : 'FAIL') . ' expected_active_slots');
if (!$slotsOk) {
    $ok = false;
    line('  expected=' . implode(' ; ', $expected));
    line('  have=' . implode(' ; ', $have));
}

$available = OfferCatalog::availableSlots();
line('public_available_slots=' . count($available));
foreach ($available as $s) {
    // labels must NOT contain "left"
    if (str_contains($s['label'], 'left')) {
        line('FAIL public label shows remaining: ' . $s['label']);
        $ok = false;
    }
}

// Export counts
$all = OfferCatalog::registrationCountForFilter('all');
$current = OfferCatalog::registrationCountForFilter('current');
$prev = OfferCatalog::registrationCountForFilter('2026-08-15');
line("export_all={$all}");
line("export_current={$current}");
line("export_2026-08-15={$prev}");
if ($all !== $customers) {
    line('FAIL export_all != customers');
    $ok = false;
}
if ($prev !== $customers && $nullDates === 0) {
    // previous event should be all legacy if only one past date
    line('INFO prev_date_count=' . $prev);
}

// Files present
$files = [
    'app/OfferCatalog.php',
    'admin/exports.php',
    'admin/dashboard.php',
    'admin/events.php',
    'admin/offers.php',
    'public/offer.php',
    'public/verify.php',
    'public/check_mobile.php',
    'sfs-ops-m9k2x7q4/events.php',
];
foreach ($files as $f) {
    $path = APP_ROOT . '/' . $f;
    $exists = is_file($path);
    line(($exists ? 'OK' : 'FAIL') . " file {$f}");
    if (!$exists) {
        $ok = false;
    }
}

// offer.php has single consent + terms wording
$offerSrc = file_get_contents(APP_ROOT . '/public/offer.php') ?: '';
line((str_contains($offerSrc, 'Terms &amp; Conditions') && str_contains($offerSrc, 'WhatsApp') ? 'OK' : 'FAIL') . ' offer_single_consent_copy');
line((!str_contains($offerSrc, 'id="terms"') ? 'OK' : 'WARN') . ' no_separate_terms_checkbox');

// vouchers
$vouchers = (int) $pdo->query('SELECT COUNT(*) FROM vouchers')->fetchColumn();
$activeV = (int) $pdo->query("SELECT COUNT(*) FROM vouchers WHERE status='ACTIVE'")->fetchColumn();
line("vouchers={$vouchers} active={$activeV}");

line($ok ? '=== RESULT: ALL CLEAR ===' : '=== RESULT: ISSUES FOUND ===');
exit($ok ? 0 : 1);
