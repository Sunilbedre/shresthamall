<?php
/**
 * public/campaign_offer.php
 * Public campaign entry: /o/{link_slug}
 * - Product links lock that product on the registration form
 * - Hub slug (sort_order 0, e.g. oct2) shows a chooser of all campaign product links
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$linkSlug = CampaignService::normaliseSlug((string) (
    $_GET['slug']
    ?? $_SERVER['CAMPAIGN_LINK_SLUG']
    ?? ''
));

if ($linkSlug === '') {
    http_response_code(404);
    echo 'Offer link not found.';
    exit;
}

$link = CampaignService::resolvePublicLink($linkSlug);
if ($link === null) {
    http_response_code(404);
    echo 'Offer link not found.';
    exit;
}

$campaignActive = (int) ($link['campaign_active'] ?? 0) === 1;
$linkActive = (int) ($link['active'] ?? 0) === 1;
$productActive = (int) ($link['product_active'] ?? 0) === 1;

if (!$campaignActive || !$linkActive) {
    http_response_code(404);
    $pageTitle = 'Offer closed';
    $compactHeader = true;
    require __DIR__ . '/../templates/header.php';
    echo '<main class="max-w-md mx-auto px-4 py-10 text-center"><h1 class="font-heading text-2xl text-maroon font-bold">This offer link is closed</h1><p class="mt-2 text-sm text-maroon-dark/70">Please contact the store for the latest offers.</p></main>';
    require __DIR__ . '/../templates/footer.php';
    exit;
}

$siblingLinks = CampaignService::linksForCampaign((int) $link['campaign_id'], true);

// Hub page: sort_order 0
$isHub = ((int) ($link['sort_order'] ?? 1) === 0);

if ($isHub) {
    $pageTitle = (string) ($link['campaign_title'] ?? 'Special Offer');
    $compactHeader = true;
    require __DIR__ . '/../templates/header.php';
    ?>
    <main class="max-w-md mx-auto px-3.5 sm:px-4 pt-4 pb-16">
      <section class="bg-white gold-border rounded-2xl shadow-sm overflow-hidden">
        <div class="bg-maroon text-ivory px-4 py-5 text-center">
          <p class="text-[10px] uppercase tracking-widest text-gold-light font-semibold">Shreeshta Family Store</p>
          <h1 class="font-heading text-xl font-bold mt-1 leading-tight"><?= e((string) $link['campaign_title']) ?></h1>
          <p class="text-gold-light/90 text-xs mt-2">Choose your ₹1 product · Limited stock</p>
        </div>
        <div class="p-4 space-y-3">
          <?php foreach ($siblingLinks as $item): ?>
            <?php
              if ((int) ($item['sort_order'] ?? 1) === 0) {
                  continue; // skip hub itself
              }
              $path = CampaignService::publicPath((string) $item['link_slug']);
              $slots = OfferCatalog::availableSlots((string) $item['product_key']);
              $left = 0;
              foreach ($slots as $s) {
                  $left += (int) ($s['remaining'] ?? 0);
              }
            ?>
            <a href="<?= e($path) ?>"
               class="block rounded-xl gold-border px-4 py-3 hover:bg-ivory transition">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <p class="font-heading font-bold text-maroon text-base leading-snug"><?= e((string) ($item['product_label'] ?? $item['product_key'])) ?></p>
                  <?php if (!empty($item['headline'])): ?>
                    <p class="text-xs text-maroon-dark/65 mt-0.5"><?= e((string) $item['headline']) ?></p>
                  <?php endif; ?>
                </div>
                <span class="shrink-0 text-[11px] font-semibold px-2 py-1 rounded-lg <?= $left > 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700' ?>">
                  <?= $left > 0 ? ($left . ' left') : 'Sold out' ?>
                </span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    </main>
    <?php
    require __DIR__ . '/../templates/footer.php';
    exit;
}

if (!$productActive) {
    http_response_code(404);
    echo 'This product offer is not available.';
    exit;
}

// Lock this product into the shared registration form
$campaignContext = [
    'campaign_slug' => (string) $link['campaign_slug'],
    'campaign_title' => (string) $link['campaign_title'],
    'link_slug' => $linkSlug,
    'locked_product_key' => (string) $link['product_key'],
    'headline' => (string) ($link['headline'] ?: ($link['product_label'] ?? '')),
    'product_label' => (string) ($link['product_label'] ?? $link['product_key']),
];

require __DIR__ . '/offer.php';
