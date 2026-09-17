<?php
/**
 * admin/special_events_report.php — Special one-day event report (separate from weekend Offers Report).
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

AuthService::requireLogin();

$campaigns = CampaignService::listCampaigns(true);
$campaignSlug = trim((string) ($_GET['campaign'] ?? ''));
if ($campaignSlug === '' && $campaigns) {
    $campaignSlug = (string) $campaigns[0]['slug'];
}

$report = ['products' => [], 'totals' => ['allocated' => 0, 'registered' => 0, 'purchased' => 0], 'campaign' => null];
if ($campaignSlug !== '') {
    $c = CampaignService::findBySlug($campaignSlug);
    if ($c) {
        $report = CampaignService::report((int) $c['id']);
    }
}

$campaign = $report['campaign'] ?? null;
$rows = $report['products'] ?? [];
$totals = $report['totals'] ?? ['allocated' => 0, 'registered' => 0, 'purchased' => 0];

$activePage = 'special_events_report';
$skipDefaultHeader = true;
$pageTitle = 'Special Events Report – Shreeshta Family Store';
require __DIR__ . '/../templates/header.php';
?>
<?php require __DIR__ . '/../templates/admin_nav.php'; ?>

<style>
  .sp-report { width: 100%; border-collapse: collapse; font-family: Arial, Helvetica, sans-serif; font-size: 14px; }
  .sp-report th, .sp-report td { border: 1px solid #111; padding: 8px 10px; text-align: center; vertical-align: middle; }
  .sp-report .col-product { text-align: left; font-weight: 700; background: #fff59d; min-width: 280px; }
  .sp-report .hdr-title { background: #b2ebf2; font-weight: 800; font-size: 16px; }
  .sp-report .val-purchased { background: #ffeb3b; font-weight: 800; }
  .sp-report .total-row { background: #b2ebf2; font-weight: 800; }
  @media print { .no-print { display: none !important; } header, footer { display: none !important; } }
</style>

<main class="flex-1 max-w-6xl mx-auto px-4 sm:px-5 py-6">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-4 no-print">
    <div>
      <h2 class="font-heading text-2xl font-bold text-maroon">Special Events Report</h2>
      <p class="text-sm text-maroon-dark/65">Separate from weekend offers — one-day / big-date events with product links</p>
    </div>
    <button type="button" onclick="window.print()" class="px-3 py-1.5 rounded-lg text-sm font-semibold bg-gold text-maroon-dark">Print / PDF</button>
  </div>

  <?php if (!$campaigns): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm">
      No special events yet. Run a setup script (e.g. <code class="text-xs">php scripts/setup_oct_2026_special.php</code>).
    </div>
  <?php else: ?>

  <form method="get" class="no-print mb-4 flex flex-wrap gap-2 items-end">
    <div>
      <label class="block text-xs font-semibold text-maroon-dark mb-1">Event</label>
      <select name="campaign" class="rounded-lg gold-border px-3 py-2 min-w-[240px]" onchange="this.form.submit()">
        <?php foreach ($campaigns as $c): ?>
          <option value="<?= e($c['slug']) ?>" <?= $campaignSlug === $c['slug'] ? 'selected' : '' ?>>
            <?= e($c['title']) ?> (<?= e((new DateTimeImmutable($c['event_date']))->format('d M Y')) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <?php if ($campaign): ?>
  <div class="bg-white rounded-xl shadow-sm border border-gold/30 p-3 sm:p-4 overflow-x-auto mb-4 no-print">
    <h3 class="font-heading font-bold text-maroon mb-2">Public links (share on WhatsApp / social)</h3>
    <ul class="text-sm space-y-2">
      <?php foreach ($rows as $row): ?>
        <?php if ((int) ($row['active'] ?? 0) !== 1) continue; ?>
        <li class="flex flex-wrap gap-2 items-center">
          <span class="font-semibold text-maroon-dark min-w-[200px]"><?= e($row['label']) ?></span>
          <a href="<?= e($row['url']) ?>" class="text-blue-700 underline break-all" target="_blank" rel="noopener"><?= e($row['url']) ?></a>
          <?php if ((int) ($row['remaining'] ?? 0) <= 0 && (int) ($row['allocated'] ?? 0) < 99999): ?>
            <span class="text-xs bg-red-100 text-red-800 px-2 py-0.5 rounded">FULL</span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-gold/30 p-3 sm:p-4 overflow-x-auto">
    <table class="sp-report">
      <thead>
        <tr>
          <th class="hdr-title" colspan="6">
            SPECIAL EVENT — <?= e($campaign['title']) ?> · <?= e((new DateTimeImmutable($campaign['event_date']))->format('jS M Y')) ?>
          </th>
        </tr>
        <tr>
          <th class="col-product">Product</th>
          <th>Daily limit</th>
          <th>Registered</th>
          <th class="val-purchased">Redeemed</th>
          <th>Remaining</th>
          <th>% Redeemed</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <?php if ((int) ($row['active'] ?? 0) !== 1 && (int) ($row['registered'] ?? 0) === 0) continue; ?>
          <tr>
            <td class="col-product"><?= e($row['label']) ?></td>
            <td><?= ((int) $row['allocated'] >= 99999) ? 'No limit' : (int) $row['allocated'] ?></td>
            <td><?= (int) $row['registered'] ?></td>
            <td class="val-purchased"><?= (int) $row['purchased'] ?></td>
            <td><?= ((int) $row['allocated'] >= 99999) ? '—' : (int) $row['remaining'] ?></td>
            <td><?= number_format((float) $row['pct'], 2) ?>%</td>
          </tr>
        <?php endforeach; ?>
        <tr class="total-row">
          <td>Grand Total</td>
          <td><?= (int) $totals['allocated'] ?></td>
          <td><?= (int) $totals['registered'] ?></td>
          <td><?= (int) $totals['purchased'] ?></td>
          <td colspan="2"></td>
        </tr>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</main>

<?php require __DIR__ . '/../templates/footer.php'; ?>
