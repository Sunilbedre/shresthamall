<?php
/**
 * scripts/setup_oct_3_15_saree_event.php
 * SL#10 — 3 Oct to 15 Oct 2026, all days (Malleshwaram store).
 *
 * One share link (free slot when available, else min ₹99 on same page):
 *   /s/oct-saree-10/saree
 * Legacy /saree-min99 redirects to /saree.
 *
 * Usage: php scripts/setup_oct_3_15_saree_event.php
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

CampaignService::ensureSchema();
CampaignService::seedOctSaree1012026();

$campaign = CampaignService::findBySlug('oct-saree-10');
if ($campaign === null) {
    fwrite(STDERR, "Failed to create campaign.\n");
    exit(1);
}

echo "Campaign: " . $campaign['title'] . "\n";
echo 'Dates: ' . $campaign['event_date'] . ' to ' . ($campaign['event_end_date'] ?? $campaign['event_date']) . "\n\n";

foreach (CampaignService::products((int) $campaign['id']) as $p) {
    $cap = (int) $p['daily_capacity'];
    $scope = (string) ($p['capacity_scope'] ?? 'day');
    echo 'Product [' . $p['product_slug'] . ']: OK' . "\n";
    echo '  ' . $p['label'] . "\n";
    echo '  Capacity: ' . ($cap >= 99999 ? 'unlimited' : (string) $cap) . ' (' . $scope . ")\n";
    echo '  Link: ' . CampaignService::publicUrl('oct-saree-10', (string) $p['product_slug']) . "\n\n";
}

echo "Done.\n";
