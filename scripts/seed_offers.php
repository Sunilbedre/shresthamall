<?php
/**
 * scripts/seed_offers.php
 * Creates/reseeds the dynamic offer catalogue.
 * Usage: php scripts/seed_offers.php [--force]
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$force = in_array('--force', $argv ?? [], true);
$pdo = Database::connection();

if ($force) {
    $pdo->exec('DELETE FROM offer_slots');
    $pdo->exec('DELETE FROM offer_products');
    echo "Cleared existing offers.\n";
}

OfferCatalog::ensureSchema();
$slots = OfferCatalog::allSlotsDetailed();
echo 'Products: ' . count(OfferCatalog::products(false)) . "\n";
echo 'Slots: ' . count($slots) . "\n";
foreach ($slots as $s) {
    echo sprintf(
        "- %s | %s | %s | %s-%s | cap=%d left=%d\n",
        $s['product_key'],
        $s['event_date'],
        $s['session'],
        $s['slot_start'],
        $s['slot_end'],
        (int) $s['capacity'],
        (int) $s['remaining']
    );
}
echo "Done.\n";
