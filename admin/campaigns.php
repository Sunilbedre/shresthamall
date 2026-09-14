<?php
/**
 * admin/campaigns.php — Special campaigns (e.g. Oct 2 ₹1 day)
 * Per-product public links, stock edit, and separate report.
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

AuthService::requireRole('admin');

$flash = null;
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $flashError = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'update_slot') {
            $res = OfferCatalog::updateSlot(
                (int) ($_POST['slot_id'] ?? 0),
                (int) ($_POST['capacity'] ?? 0),
                !empty($_POST['active']),
                (string) ($_POST['slot_start'] ?? '11:30'),
                (string) ($_POST['slot_end'] ?? '14:30'),
                (string) ($_POST['event_date'] ?? ''),
                (string) ($_POST['session'] ?? '')
            );
            $flash = $res['ok'] ? 'Campaign slot stock updated.' : null;
            $flashError = $res['ok'] ? null : ($res['error'] ?? 'Could not update slot.');
            if ($flash) {
                AuditLog::record(
                    AuthService::currentUsername() ?? 'admin',
                    'CAMPAIGN_SLOT_UPDATED',
                    'slot_id=' . (int) ($_POST['slot_id'] ?? 0) . ' capacity=' . (int) ($_POST['capacity'] ?? 0),
                    $_SERVER['REMOTE_ADDR'] ?? null
                );
            }
        } elseif ($action === 'toggle_campaign') {
            $slug = CampaignService::normaliseSlug((string) ($_POST['campaign_slug'] ?? ''));
            $camp = CampaignService::findBySlug($slug);
            if ($camp) {
                $active = !empty($_POST['active']);
                CampaignService::upsertCampaign(
                    $slug,
                    (string) $camp['title'],
                    (string) $camp['start_date'],
                    (string) $camp['end_date'],
                    $active,
                    (int) ($camp['allow_repeat_mobile'] ?? 1) === 1
                );
                $flash = $active ? 'Campaign activated.' : 'Campaign deactivated (public links closed).';
            } else {
                $flashError = 'Campaign not found.';
            }
        }
    }
}

$campaigns = CampaignService::all(false);
$selectedSlug = CampaignService::normaliseSlug((string) ($_GET['slug'] ?? ''));
if ($selectedSlug === '' && $campaigns) {
    $selectedSlug = (string) $campaigns[0]['slug'];
}
$selected = $selectedSlug !== '' ? CampaignService::findBySlug($selectedSlug) : null;
$links = $selected ? CampaignService::linksForCampaign((int) $selected['id']) : [];
$slots = $selected ? CampaignService::slotsForCampaign($selected) : [];
$report = $selected ? CampaignService::report($selected) : [];

$activePage = 'campaigns';
$skipDefaultHeader = true;
$pageTitle = 'Special Campaigns – Admin';
require __DIR__ . '/../templates/header.php';
require __DIR__ . '/../templates/admin_nav.php';
?>

<main class="flex-1 max-w-5xl mx-auto px-5 py-8 space-y-8">
  <div>
    <h2 class="font-heading text-2xl font-bold text-maroon">Special Campaigns</h2>
    <p class="text-sm text-maroon-dark/70 mt-1">
      Festival / big-date offers with <strong>separate product links</strong> and a report apart from weekly Events.
      Past weekly mobiles can register once per special campaign.
    </p>
  </div>

  <?php if ($flash): ?>
    <p class="text-green-700 bg-green-50 border border-green-200 rounded-lg px-4 py-2 text-sm"><?= e($flash) ?></p>
  <?php endif; ?>
  <?php if ($flashError): ?>
    <p class="text-red-700 bg-red-50 border border-red-200 rounded-lg px-4 py-2 text-sm"><?= e($flashError) ?></p>
  <?php endif; ?>

  <?php if (!$campaigns): ?>
    <section class="bg-ivory gold-border rounded-2xl p-5 shadow-sm">
      <p class="text-sm text-maroon-dark">No campaigns yet. On the server run:</p>
      <code class="block mt-2 text-xs bg-white border border-gold/30 rounded-lg px-3 py-2">php scripts/setup_oct2_rupee1_event.php</code>
    </section>
  <?php else: ?>

    <section class="bg-white gold-border rounded-2xl p-4 shadow-sm">
      <label class="block text-sm font-semibold text-maroon-dark mb-2">Select campaign</label>
      <form method="get" class="flex flex-wrap gap-2 items-center">
        <select name="slug" class="rounded-xl gold-border px-3 py-2 bg-white text-sm" onchange="this.form.submit()">
          <?php foreach ($campaigns as $c): ?>
            <option value="<?= e((string) $c['slug']) ?>" <?= $selectedSlug === (string) $c['slug'] ? 'selected' : '' ?>>
              <?= e((string) $c['title']) ?> (<?= e((string) $c['start_date']) ?>)
              <?= (int) $c['active'] === 1 ? '' : ' — OFF' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </section>

    <?php if ($selected): ?>
      <section class="bg-ivory gold-border rounded-2xl p-5 shadow-sm space-y-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h3 class="font-heading text-lg font-bold text-maroon"><?= e((string) $selected['title']) ?></h3>
            <p class="text-xs text-maroon-dark/65 mt-1">
              Slug <code><?= e((string) $selected['slug']) ?></code>
              · <?= e((string) $selected['start_date']) ?> → <?= e((string) $selected['end_date']) ?>
              · <?= (int) $selected['active'] === 1 ? 'Active' : 'Inactive' ?>
            </p>
          </div>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_campaign">
            <input type="hidden" name="campaign_slug" value="<?= e((string) $selected['slug']) ?>">
            <?php if ((int) $selected['active'] === 1): ?>
              <input type="hidden" name="active" value="0">
              <button class="bg-white gold-border text-maroon-dark font-semibold px-4 py-2 rounded-xl text-sm">Deactivate</button>
            <?php else: ?>
              <input type="hidden" name="active" value="1">
              <button class="bg-maroon text-ivory font-bold px-4 py-2 rounded-xl text-sm">Activate</button>
            <?php endif; ?>
          </form>
        </div>

        <h4 class="font-semibold text-maroon text-sm pt-2">Public links</h4>
        <ul class="space-y-2 text-sm">
          <?php foreach ($links as $link): ?>
            <?php
              $path = CampaignService::publicPath((string) $link['link_slug']);
              $url = CampaignService::publicUrl((string) $link['link_slug']);
              $isHub = (int) ($link['sort_order'] ?? 1) === 0;
            ?>
            <li class="bg-white rounded-xl gold-border px-3 py-2 flex flex-wrap items-center justify-between gap-2">
              <div>
                <span class="font-semibold text-maroon"><?= $isHub ? 'Hub' : e((string) ($link['product_label'] ?? $link['product_key'])) ?></span>
                <span class="text-maroon-dark/55 text-xs ml-1"><?= e((string) ($link['headline'] ?? '')) ?></span>
              </div>
              <a class="text-xs font-semibold text-maroon underline break-all" href="<?= e($path) ?>" target="_blank" rel="noopener"><?= e($url) ?></a>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>

      <section class="bg-white gold-border rounded-2xl p-5 shadow-sm space-y-4">
        <h3 class="font-heading text-lg font-bold text-maroon">Stock (edit capacity)</h3>
        <?php if (!$slots): ?>
          <p class="text-sm text-maroon-dark/70">No slots in this campaign date range.</p>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[640px]">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-maroon-dark/60 border-b border-gold/30">
                  <th class="py-2 pr-2">Product</th>
                  <th class="py-2 pr-2">Date</th>
                  <th class="py-2 pr-2">Session</th>
                  <th class="py-2 pr-2">Booked</th>
                  <th class="py-2 pr-2">Capacity</th>
                  <th class="py-2 pr-2">Active</th>
                  <th class="py-2">Save</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gold/20">
                <?php foreach ($slots as $slot): ?>
                  <tr>
                    <td class="py-2 pr-2 font-medium text-maroon"><?= e((string) $slot['product_label']) ?></td>
                    <td class="py-2 pr-2"><?= e((string) $slot['event_date']) ?></td>
                    <td class="py-2 pr-2"><?= e((string) $slot['session']) ?> · <?= e((string) $slot['slot_start']) ?>–<?= e((string) $slot['slot_end']) ?></td>
                    <td class="py-2 pr-2"><?= (int) $slot['booked'] ?> / left <?= (int) $slot['remaining'] ?></td>
                    <td class="py-2 pr-2" colspan="3">
                      <form method="post" class="flex flex-wrap items-center gap-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_slot">
                        <input type="hidden" name="slot_id" value="<?= (int) $slot['id'] ?>">
                        <input type="hidden" name="event_date" value="<?= e((string) $slot['event_date']) ?>">
                        <input type="hidden" name="session" value="<?= e((string) $slot['session']) ?>">
                        <input type="hidden" name="slot_start" value="<?= e((string) $slot['slot_start']) ?>">
                        <input type="hidden" name="slot_end" value="<?= e((string) $slot['slot_end']) ?>">
                        <input type="number" name="capacity" min="0" max="9999" value="<?= (int) $slot['capacity'] ?>"
                          class="w-20 rounded-lg gold-border px-2 py-1.5">
                        <label class="text-xs flex items-center gap-1">
                          <input type="checkbox" name="active" value="1" <?= (int) $slot['active'] === 1 ? 'checked' : '' ?>>
                          On
                        </label>
                        <button class="bg-maroon text-ivory text-xs font-bold px-3 py-1.5 rounded-lg">Update</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <section class="bg-white gold-border rounded-2xl p-5 shadow-sm space-y-3">
        <h3 class="font-heading text-lg font-bold text-maroon">Campaign report</h3>
        <p class="text-xs text-maroon-dark/60">Registered / purchased scoped to this campaign only (not weekly Events).</p>
        <?php if (!$report): ?>
          <p class="text-sm text-maroon-dark/70">No allocated stock rows yet.</p>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[560px]">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-maroon-dark/60 border-b border-gold/30">
                  <th class="py-2 pr-2">Product</th>
                  <th class="py-2 pr-2">Date</th>
                  <th class="py-2 pr-2 text-right">Allocated</th>
                  <th class="py-2 pr-2 text-right">Registered</th>
                  <th class="py-2 pr-2 text-right">Purchased</th>
                  <th class="py-2 pr-2 text-right">Left</th>
                  <th class="py-2 text-right">% bought</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gold/20">
                <?php foreach ($report as $row): ?>
                  <tr>
                    <td class="py-2 pr-2 font-medium text-maroon"><?= e((string) $row['product_label']) ?></td>
                    <td class="py-2 pr-2"><?= e((string) $row['event_date']) ?></td>
                    <td class="py-2 pr-2 text-right"><?= (int) $row['allocated'] ?></td>
                    <td class="py-2 pr-2 text-right"><?= (int) $row['registered'] ?></td>
                    <td class="py-2 pr-2 text-right"><?= (int) $row['purchased'] ?></td>
                    <td class="py-2 pr-2 text-right"><?= (int) $row['remaining'] ?></td>
                    <td class="py-2 text-right"><?= e((string) $row['pct']) ?>%</td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</main>

<?php require __DIR__ . '/../templates/footer.php'; ?>
