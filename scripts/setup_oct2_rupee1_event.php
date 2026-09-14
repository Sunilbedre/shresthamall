<?php
/**
 * scripts/setup_oct2_rupee1_event.php
 *
 * Special campaign: Gandhi Jayanti / Oct 2 2026 — ₹1 offer day.
 * Creates three products with separate public links and daily stock:
 *   1. 1 Rupee Saree              — 500/day (250 morning + 250 evening)
 *   2. 1 Rupee Kurti / Leggings   — 200/day (100 + 100)
 *   3. 1 Rupee Kid's T-shirt      — 200/day (100 + 100)
 *
 * Public links:
 *   /o/oct2-saree
 *   /o/oct2-kurti
 *   /o/oct2-kids-tshirt
 *   /o/oct2          (chooser)
 *
 * Usage: php scripts/setup_oct2_rupee1_event.php
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$pdo = Database::connection();
OfferCatalog::ensureSchema();
CampaignService::ensureSchema();

$eventDate = '2026-10-02';
$replaceWeekly = in_array('--replace-weekly', $argv ?? [], true);

// Optional: close weekly slots so only Oct 2 stock is public (use closer to the event day)
if ($replaceWeekly) {
    $deactivated = OfferCatalog::deactivateAllActiveSlots();
    echo "Deactivated {$deactivated} previous active slot(s).\n";
    $pdo->exec("UPDATE offer_products SET active = 0 WHERE product_key NOT LIKE '%_oct2'");
} else {
    echo "Keeping existing weekly slots/products active (pass --replace-weekly to close them).\n";
}

// 2. Products
$products = [
    [
        'key' => 'rupee1_saree_oct2',
        'label' => '1 Rupee Saree',
        'sort' => 1,
        'link' => 'oct2-saree',
        'headline' => '₹1 Saree — Gandhi Jayanti Special',
        'morning' => 250,
        'evening' => 250,
    ],
    [
        'key' => 'rupee1_kurti_leggings_oct2',
        'label' => '1 Rupee Kurti / Leggings',
        'sort' => 2,
        'link' => 'oct2-kurti',
        'headline' => '₹1 Kurti / Leggings — Gandhi Jayanti Special',
        'morning' => 100,
        'evening' => 100,
    ],
    [
        'key' => 'rupee1_kids_tshirt_oct2',
        'label' => "1 Rupee Kid's T-shirt",
        'sort' => 3,
        'link' => 'oct2-kids-tshirt',
        'headline' => "₹1 Kid's T-shirt — Gandhi Jayanti Special",
        'morning' => 100,
        'evening' => 100,
    ],
];

foreach ($products as $p) {
    $res = OfferCatalog::upsertProduct($p['key'], $p['label'], $p['sort'], true);
    echo "Product [{$p['key']}]: " . ($res['ok'] ? 'OK' : ('ERR ' . ($res['error'] ?? ''))) . "\n";
}

$sessions = [
    ['session' => 'morning', 'start' => '11:30', 'end' => '14:30', 'cap_key' => 'morning'],
    ['session' => 'evening', 'start' => '17:00', 'end' => '20:00', 'cap_key' => 'evening'],
];

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
    foreach ($sessions as $s) {
        $cap = (int) $p[$s['cap_key']];
        $slotStmt->execute([
            'p' => $p['key'],
            'd' => $eventDate,
            's' => $s['session'],
            'st' => $s['start'],
            'en' => $s['end'],
            'c' => $cap,
        ]);
        echo "Slot: {$p['key']} | {$eventDate} | {$s['session']} cap={$cap}\n";
    }
}

// 3. Campaign + per-product links
$campRes = CampaignService::upsertCampaign(
    'oct2-2026',
    '₹1 Special Offer — 2 Oct 2026 (Gandhi Jayanti)',
    $eventDate,
    $eventDate,
    true,
    true // allow past weekly mobiles to register once for this campaign
);
if (!($campRes['ok'] ?? false)) {
    fwrite(STDERR, "Campaign failed: " . ($campRes['error'] ?? 'unknown') . "\n");
    exit(1);
}
$campaign = $campRes['campaign'];
$campaignId = (int) $campaign['id'];
echo "Campaign: {$campaign['slug']} (id={$campaignId})\n";

foreach ($products as $i => $p) {
    $linkRes = CampaignService::upsertLink(
        $campaignId,
        $p['key'],
        $p['link'],
        $p['headline'],
        $i + 1,
        true
    );
    echo "Link /o/{$p['link']}: " . ($linkRes['ok'] ? 'OK' : ('ERR ' . ($linkRes['error'] ?? ''))) . "\n";
}

// Chooser hub link (no product lock — campaign hub page)
$hub = CampaignService::upsertLink(
    $campaignId,
    $products[0]['key'], // placeholder product; hub page lists all links
    'oct2',
    '₹1 Gandhi Jayanti Offers — Choose your product',
    0,
    true
);
echo "Hub /o/oct2: " . ($hub['ok'] ? 'OK' : ('ERR ' . ($hub['error'] ?? ''))) . "\n";

echo "\n--- Summary ---\n";
echo "Event date: {$eventDate}\n";
echo "Active products: " . count(OfferCatalog::products(true)) . "\n";
echo "Available slots: " . count(OfferCatalog::availableSlots()) . "\n";
echo "Share these links:\n";
foreach ($products as $p) {
    echo "  " . CampaignService::publicPath($p['link']) . "  →  {$p['label']}\n";
}
echo "  /o/oct2  →  chooser hub\n";
