<?php
/**
 * scripts/setup_september_event.php
 * Closes previous event slots and sets up 5th & 6th September 2026 event.
 * Products:
 *  1. Semi Kanjeevaram 2 Sarees Just ₹599/- (Min Purchase - ₹199/-)
 *  2. Semi Kanjeevaram 2 Sarees Just ₹899/- (Min Purchase - ₹199/-)
 *  3. Tissue Saree 2 Sarees Just ₹1299/- (Min Purchase - ₹199/-)
 *
 * Slots: 11:30 AM – 2:30 PM (morning) & 5:00 PM – 8:00 PM (evening)
 * Stock: No limit (capacity 99999)
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$pdo = Database::connection();
OfferCatalog::ensureSchema();

// 1. Deactivate all existing active slots (close past week)
$deactivated = OfferCatalog::deactivateAllActiveSlots();
echo "Deactivated {$deactivated} previous active slot(s).\n";

// Deactivate old products
$pdo->exec("UPDATE offer_products SET active = 0");

// 2. Upsert the 3 products
$products = [
    [
        'key' => 'semi_kanjeevaram_599',
        'label' => 'Semi Kanjeevaram 2 Sarees Just ₹599/- (Min Purchase - ₹199/-)',
        'sort' => 1,
    ],
    [
        'key' => 'semi_kanjeevaram_899',
        'label' => 'Semi Kanjeevaram 2 Sarees Just ₹899/- (Min Purchase - ₹199/-)',
        'sort' => 2,
    ],
    [
        'key' => 'tissue_saree_1299',
        'label' => 'Tissue Saree 2 Sarees Just ₹1299/- (Min Purchase - ₹199/-)',
        'sort' => 3,
    ],
];

foreach ($products as $p) {
    $res = OfferCatalog::upsertProduct($p['key'], $p['label'], $p['sort'], true);
    echo "Product [{$p['key']}]: " . ($res['ok'] ? 'OK' : 'ERR ' . ($res['error'] ?? '')) . "\n";
}

// 3. Create slots for 5th & 6th Sep 2026 (both morning & evening, capacity 99999 = unlimited)
$dates = ['2026-09-05', '2026-09-06'];
$sessions = [
    ['session' => 'morning', 'start' => '11:30', 'end' => '14:30'],
    ['session' => 'evening', 'start' => '17:00', 'end' => '20:00'],
];
$capacity = 99999;

$slotStmt = $pdo->prepare("
    INSERT INTO offer_slots (product_key, event_date, session, slot_start, slot_end, capacity, active)
    VALUES (:p, :d, :s, :st, :en, :c, 1)
    ON CONFLICT(product_key, event_date, session) DO UPDATE SET
        slot_start = excluded.slot_start,
        slot_end = excluded.slot_end,
        capacity = excluded.capacity,
        active = 1
");

foreach ($products as $p) {
    foreach ($dates as $d) {
        foreach ($sessions as $s) {
            $slotStmt->execute([
                'p' => $p['key'],
                'd' => $d,
                's' => $s['session'],
                'st' => $s['start'],
                'en' => $s['end'],
                'c' => $capacity,
            ]);
            echo "Slot created/activated: {$p['key']} | {$d} | {$s['session']} ({$s['start']}-{$s['end']})\n";
        }
    }
}

echo "\n--- Summary ---\n";
echo "Active Event Dates: " . implode(', ', OfferCatalog::eventDates(true)) . "\n";
echo "Active Products: " . count(OfferCatalog::products(true)) . "\n";
echo "Available Slots: " . count(OfferCatalog::availableSlots()) . "\n";
