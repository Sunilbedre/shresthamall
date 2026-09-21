<?php
/**
 * scripts/setup_oct_2026_special.php
 * Gandhi Jayanti / Oct 2 special — separate link per ₹1 product.
 *
 * Links:
 *   /s/oct-2026/saree         — 1 Rupee Saree (500/day)
 *   /s/oct-2026/saree-min99   — 1 Rupee Saree — Min purchase ₹99/- (500/day)
 *   /s/oct-2026/kurti         — 1 Rupee Kurti / Leggings (200/day)
 *   /s/oct-2026/kids          — 1 Rupee Kid's T-shirt (200/day)
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

CampaignService::ensureSchema();

$res = CampaignService::upsertCampaign(
    'oct-2026',
    'Gandhi Jayanti Special — ₹1 Offer',
    '2026-10-02',
    'OPEN'
);
$campaignId = (int) ($res['id'] ?? 0);
if ($campaignId <= 0) {
    $c = CampaignService::findBySlug('oct-2026');
    $campaignId = (int) ($c['id'] ?? 0);
}
if ($campaignId <= 0) {
    fwrite(STDERR, "Failed to create campaign.\n");
    exit(1);
}

$products = [
    ['rupee1_saree_oct', 'saree', '1 Rupee Saree', 500, 1],
    ['rupee1_saree_min99_oct', 'saree-min99', '1 Rupee Saree — Min purchase ₹99/-', 500, 2],
    ['rupee1_kurti_oct', 'kurti', '1 Rupee Kurti / Leggings', 200, 3],
    ['rupee1_kids_tshirt_oct', 'kids', "1 Rupee Kid's T-shirt", 200, 4],
];

foreach ($products as [$key, $slug, $label, $cap, $sort]) {
    $r = CampaignService::upsertProduct($campaignId, $key, $slug, $label, $cap, $sort);
    echo 'Product [' . $slug . ']: ' . (($r['ok'] ?? false) ? 'OK' : 'ERR') . "\n";
    echo '  Link: ' . CampaignService::publicUrl('oct-2026', $slug) . "\n";
}

echo "\n--- Report preview ---\n";
$report = CampaignService::report($campaignId);
foreach ($report['products'] as $p) {
    echo sprintf(
        "%s | cap=%s | remaining=%s\n",
        $p['label'],
        $p['allocated'] >= 99999 ? 'unlimited' : (string) $p['allocated'],
        $p['remaining'] >= 99999 ? 'unlimited' : (string) $p['remaining']
    );
}
echo "Done.\n";
