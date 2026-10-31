<?php
/**
 * scripts/setup_nov_7_2026_special.php
 * 7 November 2026 (Saturday) — same ₹1 products as Oct 24 (separate link per product).
 *
 * Links:
 *   /s/nov-7-2026/saree         — 1 Rupee Saree (500/day)
 *   /s/nov-7-2026/saree-min99   — 1 Rupee Saree — Min purchase ₹99/- (500/day)
 *   /s/nov-7-2026/kurti         — 1 Rupee Kurti / Leggings (200/day)
 *   /s/nov-7-2026/kids          — 1 Rupee Kid's T-shirt (200/day)
 *
 * Usage: php scripts/setup_nov_7_2026_special.php
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

CampaignService::ensureSchema();
CampaignService::seedNov72026Special();

$campaign = CampaignService::findBySlug('nov-7-2026');
if ($campaign === null) {
    fwrite(STDERR, "Failed to create campaign.\n");
    exit(1);
}

echo 'Campaign: ' . $campaign['title'] . ' (' . $campaign['event_date'] . ')' . "\n\n";

foreach (CampaignService::products((int) $campaign['id']) as $p) {
    echo 'Product [' . $p['product_slug'] . ']: OK' . "\n";
    echo '  Link: ' . CampaignService::publicUrl('nov-7-2026', (string) $p['product_slug']) . "\n\n";
}

echo "--- Report preview ---\n";
$report = CampaignService::report((int) $campaign['id']);
foreach ($report['products'] as $p) {
    echo sprintf(
        "%s | cap=%s | remaining=%s\n",
        $p['label'],
        $p['allocated'] >= 99999 ? 'unlimited' : (string) $p['allocated'],
        $p['remaining'] >= 99999 ? 'unlimited' : (string) $p['remaining']
    );
}
echo "Done.\n";
