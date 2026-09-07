<?php
/**
 * scripts/setup_september_12_13_event.php
 * Closes previous event slots and sets up 12 & 13 September 2026 event.
 *
 * Products (8):
 *  - rupee1_saree_free   FREE ₹1 Saree (150/slot)
 *  - rupee1_saree_min99  ₹1 Saree — Min purchase ₹99/- (unlimited)
 *  - rupee1_kurti_free   FREE ₹1 Kurti (150/slot)
 *  - rupee1_kurti_min99  ₹1 Kurti — Min purchase ₹99/- (unlimited)
 *  - tissue_saree_1299, semi_kanjeevaram_899, semi_kanjeevaram_599, rtw_salwar_suit_555 (unlimited)
 *
 * When FREE slots fill, that product disappears from the form; min ₹99 option stays open.
 *
 * Usage: php scripts/setup_september_12_13_event.php
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$pdo = Database::connection();
OfferCatalog::ensureSchema();

$dates = ['2026-09-12', '2026-09-13'];
$sessions = [
    ['session' => 'morning', 'start' => '11:30', 'end' => '14:30'],
    ['session' => 'evening', 'start' => '17:00', 'end' => '20:00'],
];

$unlimited = 99999;

$products = [
    ['key' => 'rupee1_saree_free', 'label' => 'FREE ₹1 Saree', 'sort' => 1, 'capacity' => 150],
    ['key' => 'rupee1_saree_min99', 'label' => '₹1 Saree — Min purchase ₹99/-', 'sort' => 2, 'capacity' => $unlimited],
    ['key' => 'rupee1_kurti_free', 'label' => 'FREE ₹1 Kurti', 'sort' => 3, 'capacity' => 150],
    ['key' => 'rupee1_kurti_min99', 'label' => '₹1 Kurti — Min purchase ₹99/-', 'sort' => 4, 'capacity' => $unlimited],
    ['key' => 'tissue_saree_1299', 'label' => 'Tissue Saree 2 Sarees Just ₹1299/-', 'sort' => 5, 'capacity' => $unlimited],
    ['key' => 'semi_kanjeevaram_899', 'label' => 'Semi Kanjeevaram 2 Sarees Just ₹899/-', 'sort' => 6, 'capacity' => $unlimited],
    ['key' => 'semi_kanjeevaram_599', 'label' => 'Semi Kanjeevaram 2 Sarees Just ₹599/-', 'sort' => 7, 'capacity' => $unlimited],
    ['key' => 'rtw_salwar_suit_555', 'label' => 'Ready-to-Wear 3-Piece Salwar Suit — ₹555/-', 'sort' => 8, 'capacity' => $unlimited],
];

// 1. Close all previous active slots
$deactivated = OfferCatalog::deactivateAllActiveSlots();
echo "Deactivated {$deactivated} previous active slot(s).\n";

// 2. Deactivate all products, then upsert this week's catalogue
$pdo->exec('UPDATE offer_products SET active = 0');

foreach ($products as $p) {
    $res = OfferCatalog::upsertProduct($p['key'], $p['label'], $p['sort'], true);
    echo 'Product [' . $p['key'] . ']: ' . (($res['ok'] ?? false) ? 'OK' : 'ERR ' . ($res['error'] ?? '')) . "\n";
}

// 3. Create / reactivate slots for 12 & 13 Sep (both sessions, all products)
$slotStmt = $pdo->prepare("
    INSERT INTO offer_slots (product_key, event_date, session, slot_start, slot_end, capacity, active)
    VALUES (:p, :d, :s, :st, :en, :c, 1)
    ON CONFLICT(product_key, event_date, session) DO UPDATE SET
        slot_start = excluded.slot_start,
        slot_end = excluded.slot_end,
        capacity = excluded.capacity,
        active = 1
");

$created = 0;
foreach ($products as $p) {
    foreach ($dates as $d) {
        foreach ($sessions as $s) {
            $slotStmt->execute([
                'p' => $p['key'],
                'd' => $d,
                's' => $s['session'],
                'st' => $s['start'],
                'en' => $s['end'],
                'c' => $p['capacity'],
            ]);
            $created++;
            echo sprintf(
                "Slot: %s | %s | %s | %s-%s | cap=%d\n",
                $p['key'],
                $d,
                $s['session'],
                $s['start'],
                $s['end'],
                $p['capacity']
            );
        }
    }
}

echo "\n--- Summary ---\n";
echo 'Slots upserted: ' . $created . "\n";
echo 'Active event dates: ' . implode(', ', OfferCatalog::eventDates(true)) . "\n";
echo 'Active products: ' . count(OfferCatalog::products(true)) . "\n";
echo 'Available slots: ' . count(OfferCatalog::availableSlots()) . "\n";

foreach ($products as $p) {
    if ($p['capacity'] === 150) {
        $slots = OfferCatalog::availableSlots($p['key']);
        echo sprintf("  %s: %d open slot(s), %d remaining total\n", $p['key'], count($slots), array_sum(array_column($slots, 'remaining')));
    }
}
