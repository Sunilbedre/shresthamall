<?php
/**
 * admin/offers.php — Weekly Events manager.
 * Each week: change products, dates, times, capacities.
 * Past mobiles stay forever (cannot take a new coupon).
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
        if ($action === 'add_product') {
            $res = OfferCatalog::upsertProduct(
                (string) ($_POST['product_key'] ?? ''),
                (string) ($_POST['label'] ?? ''),
                (int) ($_POST['sort_order'] ?? 0),
                true
            );
            $flash = $res['ok'] ? 'Product saved.' : null;
            $flashError = $res['ok'] ? null : ($res['error'] ?? 'Could not save product.');
        } elseif ($action === 'toggle_product') {
            OfferCatalog::setProductActive((string) ($_POST['product_key'] ?? ''), !empty($_POST['active']));
            $flash = 'Product updated.';
        } elseif ($action === 'add_slot') {
            $res = OfferCatalog::addSlot(
                (string) ($_POST['product_key'] ?? ''),
                (string) ($_POST['event_date'] ?? ''),
                (string) ($_POST['session'] ?? ''),
                (string) ($_POST['slot_start'] ?? ''),
                (string) ($_POST['slot_end'] ?? ''),
                (int) ($_POST['capacity'] ?? 0)
            );
            $flash = $res['ok'] ? 'Slot added — live on the form when capacity remains.' : null;
            $flashError = $res['ok'] ? null : ($res['error'] ?? 'Could not add slot.');
        } elseif ($action === 'update_product') {
            $res = OfferCatalog::upsertProduct(
                (string) ($_POST['product_key'] ?? ''),
                (string) ($_POST['label'] ?? ''),
                (int) ($_POST['sort_order'] ?? 0),
                !empty($_POST['active'])
            );
            $flash = $res['ok'] ? 'Product updated.' : null;
            $flashError = $res['ok'] ? null : ($res['error'] ?? 'Could not update product.');
        } elseif ($action === 'update_slot') {
            $res = OfferCatalog::updateSlot(
                (int) ($_POST['slot_id'] ?? 0),
                (int) ($_POST['capacity'] ?? 0),
                !empty($_POST['active']),
                (string) ($_POST['slot_start'] ?? '11:30'),
                (string) ($_POST['slot_end'] ?? '14:30'),
                (string) ($_POST['event_date'] ?? ''),
                (string) ($_POST['session'] ?? '')
            );
            $flash = $res['ok'] ? 'Slot updated.' : null;
            $flashError = $res['ok'] ? null : ($res['error'] ?? 'Could not update slot.');
        } elseif ($action === 'end_week') {
            $n = OfferCatalog::deactivateAllActiveSlots();
            $flash = "Week closed. Deactivated {$n} slot(s). Past mobiles stay blocked. Add new dates for next week below.";
            AuditLog::record(AuthService::currentUsername() ?? 'admin', 'EVENT_WEEK_ENDED', "slots={$n}", $_SERVER['REMOTE_ADDR'] ?? null);
        } elseif ($action === 'end_past') {
            $n = OfferCatalog::deactivatePastSlots();
            $flash = "Past dates closed. Deactivated {$n} slot(s).";
        } elseif ($action === 'clone_week') {
            $from1 = (string) ($_POST['from_date_1'] ?? '');
            $to1 = (string) ($_POST['to_date_1'] ?? '');
            $from2 = (string) ($_POST['from_date_2'] ?? '');
            $to2 = (string) ($_POST['to_date_2'] ?? '');
            $map = [];
            if ($from1 && $to1) {
                $map[$from1] = $to1;
            }
            if ($from2 && $to2) {
                $map[$from2] = $to2;
            }
            $res = OfferCatalog::cloneActiveSlots($map);
            $flash = $res['ok']
                ? "Cloned {$res['created']} slot(s)" . (($res['skipped'] ?? 0) ? " ({$res['skipped']} already existed / skipped)." : '.')
                : null;
            $flashError = $res['ok'] ? null : ($res['error'] ?? 'Clone failed.');
            if ($flash && empty($flashError)) {
                AuditLog::record(AuthService::currentUsername() ?? 'admin', 'EVENT_WEEK_CLONED', json_encode($map), $_SERVER['REMOTE_ADDR'] ?? null);
            }
        }
    }
}

$products = OfferCatalog::products(false);
$slots = OfferCatalog::allSlotsDetailed();
$activeDates = OfferCatalog::eventDates(true);
$activeSlots = array_values(array_filter($slots, static fn ($s) => (int) $s['active'] === 1));
$inactiveSlots = array_values(array_filter($slots, static fn ($s) => (int) $s['active'] !== 1));

$activePage = 'offers';
$skipDefaultHeader = true;
$pageTitle = 'Weekly Events – Admin';
require __DIR__ . '/../templates/header.php';
require __DIR__ . '/../templates/admin_nav.php';
?>

<main class="flex-1 max-w-5xl mx-auto px-5 py-8 space-y-8">
  <div>
    <h2 class="font-heading text-2xl font-bold text-maroon">Weekly Events</h2>
    <p class="text-sm text-maroon-dark/70 mt-1">
      Every week you can change <strong>products, dates, times and stock</strong> here.
      The public form always shows only active slots that still have stock.
    </p>
    <p class="text-xs text-maroon-dark/60 mt-2">
      Mobiles from any past week stay in the database forever — they cannot take another coupon.
      Exports / registrations default to the <em>current active</em> event dates.
    </p>
  </div>

  <?php if ($flash): ?>
    <p class="text-green-700 bg-green-50 border border-green-200 rounded-lg px-4 py-2 text-sm"><?= e($flash) ?></p>
  <?php endif; ?>
  <?php if ($flashError): ?>
    <p class="text-red-700 bg-red-50 border border-red-200 rounded-lg px-4 py-2 text-sm"><?= e($flashError) ?></p>
  <?php endif; ?>

  <section class="bg-ivory gold-border rounded-2xl p-5 shadow-sm space-y-3">
    <h3 class="font-heading text-lg font-bold text-maroon">This week (live)</h3>
    <?php if ($activeDates): ?>
      <p class="text-sm text-maroon-dark">
        Active dates:
        <strong><?= e(implode(' · ', array_map(static fn ($d) => (new DateTimeImmutable($d))->format('D, d M Y'), $activeDates))) ?></strong>
      </p>
      <p class="text-xs text-maroon-dark/65"><?= count($activeSlots) ?> active slot(s) · <?= count($products) ?> product(s) in catalogue</p>
    <?php else: ?>
      <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">No active slots. Add products &amp; dates below to open this week’s offer.</p>
    <?php endif; ?>

    <div class="flex flex-wrap gap-2 pt-1">
      <form method="post" onsubmit="return confirm('Close all active slots for this week? Past mobiles stay blocked. You can then add next week’s dates.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="end_week">
        <button class="bg-maroon hover:bg-maroon-dark text-ivory font-bold px-4 py-2 rounded-xl text-sm">End this week</button>
      </form>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="end_past">
        <button class="bg-white gold-border text-maroon-dark font-semibold px-4 py-2 rounded-xl text-sm">Close past dates only</button>
      </form>
      <a href="<?= e(admin_url('exports.php')) ?>" class="bg-white gold-border text-maroon-dark font-semibold px-4 py-2 rounded-xl text-sm">Export this event</a>
    </div>
  </section>

  <section class="bg-white gold-border rounded-2xl p-5 shadow-sm space-y-4">
    <h3 class="font-heading text-lg font-bold text-maroon">1. Products (change anytime)</h3>
    <p class="text-xs text-maroon-dark/60 -mt-2">Disable old products when a week ends. Add new product names for the new week.</p>
    <form method="post" class="grid sm:grid-cols-3 gap-3 items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_product">
      <div>
        <label class="block text-sm font-semibold mb-1">Key (no spaces)</label>
        <input name="product_key" required pattern="[A-Za-z0-9_]+" class="w-full rounded-lg gold-border px-3 py-2" placeholder="rupee1_saree">
      </div>
      <div class="sm:col-span-2">
        <label class="block text-sm font-semibold mb-1">Display name on form</label>
        <input name="label" required class="w-full rounded-lg gold-border px-3 py-2" placeholder="1 Rupee Saree">
      </div>
      <div>
        <label class="block text-sm font-semibold mb-1">Sort</label>
        <input type="number" name="sort_order" value="0" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div class="sm:col-span-2">
        <button class="bg-maroon text-ivory font-bold px-5 py-2.5 rounded-xl">Save product</button>
      </div>
    </form>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-maroon text-ivory"><tr>
          <th class="px-3 py-2 text-left">Key</th>
          <th class="px-3 py-2 text-left">Edit name / sort</th>
          <th class="px-3 py-2 text-left">On form</th>
        </tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
          <tr class="border-t border-gold/20 align-top">
            <td class="px-3 py-2 font-mono text-xs"><?= e($p['product_key']) ?></td>
            <td class="px-3 py-2">
              <form method="post" class="flex flex-wrap gap-2 items-center">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_product">
                <input type="hidden" name="product_key" value="<?= e($p['product_key']) ?>">
                <input type="hidden" name="active" value="<?= (int)$p['active'] === 1 ? '1' : '0' ?>">
                <input name="label" required value="<?= e($p['label']) ?>"
                  class="min-w-[220px] flex-1 rounded border border-gold/50 px-2 py-1.5" title="Display name">
                <input type="number" name="sort_order" value="<?= (int)$p['sort_order'] ?>"
                  class="w-16 rounded border border-gold/50 px-2 py-1.5" title="Sort order">
                <button class="bg-maroon text-ivory text-xs font-bold px-3 py-1.5 rounded-lg">Save</button>
              </form>
            </td>
            <td class="px-3 py-2">
              <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_product">
                <input type="hidden" name="product_key" value="<?= e($p['product_key']) ?>">
                <?php if ((int)$p['active'] === 1): ?>
                  <span class="text-green-700 text-xs font-semibold mr-2">Active</span>
                  <button class="text-red-600 underline text-xs">Disable</button>
                <?php else: ?>
                  <span class="text-maroon-dark/50 text-xs mr-2">Off</span>
                  <input type="hidden" name="active" value="1">
                  <button class="text-green-700 underline text-xs">Enable</button>
                <?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?>
          <tr><td colspan="3" class="px-3 py-4 text-center text-maroon-dark/60">No products yet — add one above.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="bg-white gold-border rounded-2xl p-5 shadow-sm space-y-4">
    <h3 class="font-heading text-lg font-bold text-maroon">2. Date + time + stock (per product)</h3>
    <p class="text-xs text-maroon-dark/60 -mt-2">Example: Sat 11:30–2:30 capacity 250, Sun evening 200 — whatever you need for this week.</p>
    <form method="post" class="grid sm:grid-cols-3 gap-3 items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_slot">
      <div class="sm:col-span-3">
        <label class="block text-sm font-semibold mb-1">Product</label>
        <select name="product_key" required class="w-full rounded-lg gold-border px-3 py-2">
          <?php foreach ($products as $p): ?>
            <option value="<?= e($p['product_key']) ?>"><?= e($p['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-semibold mb-1">Date</label>
        <input type="date" name="event_date" required class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-semibold mb-1">Session</label>
        <select name="session" class="w-full rounded-lg gold-border px-3 py-2">
          <option value="morning">Morning</option>
          <option value="evening">Evening</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-semibold mb-1">Capacity</label>
        <input type="number" name="capacity" min="1" value="200" required class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-semibold mb-1">Start</label>
        <input type="time" name="slot_start" value="11:30" required class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-semibold mb-1">End</label>
        <input type="time" name="slot_end" value="14:30" required class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div>
        <button class="bg-maroon text-ivory font-bold px-5 py-2.5 rounded-xl">Add slot</button>
      </div>
    </form>
  </section>

  <section class="bg-white gold-border rounded-2xl p-5 shadow-sm space-y-4">
    <h3 class="font-heading text-lg font-bold text-maroon">3. Clone this week → next week</h3>
    <p class="text-xs text-maroon-dark/60 -mt-2">Same products/times/capacities, new dates. Then use “End this week” on the old dates if you want them off the form.</p>
    <form method="post" class="grid sm:grid-cols-2 gap-3 items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clone_week">
      <div>
        <label class="block text-sm font-semibold mb-1">From date (existing)</label>
        <input type="date" name="from_date_1" value="<?= e($activeDates[0] ?? '') ?>" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-semibold mb-1">To date (new)</label>
        <input type="date" name="to_date_1" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-semibold mb-1">From date 2 (optional)</label>
        <input type="date" name="from_date_2" value="<?= e($activeDates[1] ?? '') ?>" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-semibold mb-1">To date 2 (optional)</label>
        <input type="date" name="to_date_2" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div class="sm:col-span-2">
        <button class="bg-maroon text-ivory font-bold px-5 py-2.5 rounded-xl">Clone slots to new dates</button>
      </div>
    </form>
  </section>

  <section class="bg-white gold-border rounded-2xl shadow-sm overflow-hidden">
    <div class="px-5 py-3 bg-maroon text-ivory font-semibold">Active slots — edit date / time / capacity below, then Save</div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-ivory">
          <tr>
            <th class="px-3 py-2 text-left">Product</th>
            <th class="px-3 py-2 text-left">Booked / Left</th>
            <th class="px-3 py-2 text-left min-w-[320px]">Edit slot</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($activeSlots as $s): ?>
          <tr class="border-t border-gold/20 align-top">
            <td class="px-3 py-2">
              <div class="font-semibold"><?= e($s['product_label']) ?></div>
              <div class="text-xs text-maroon-dark/55 mt-0.5">
                <?= e((new DateTimeImmutable($s['event_date']))->format('D, d M Y')) ?>
                · <?= e(OfferCatalog::formatSessionRange($s)) ?>
                · Cap <?= (int)$s['capacity'] ?>
              </div>
            </td>
            <td class="px-3 py-2 whitespace-nowrap">
              <span class="text-maroon-dark/70"><?= (int)$s['booked'] ?></span>
              /
              <span class="font-bold text-maroon"><?= (int)$s['remaining'] ?> left</span>
            </td>
            <td class="px-3 py-2">
              <form method="post" class="flex flex-wrap gap-2 items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_slot">
                <input type="hidden" name="slot_id" value="<?= (int)$s['id'] ?>">
                <div>
                  <label class="block text-[10px] font-semibold text-maroon-dark/60 mb-0.5">Date</label>
                  <input type="date" name="event_date" value="<?= e($s['event_date']) ?>" class="rounded border px-2 py-1.5">
                </div>
                <div>
                  <label class="block text-[10px] font-semibold text-maroon-dark/60 mb-0.5">Session</label>
                  <select name="session" class="rounded border px-2 py-1.5">
                    <option value="morning" <?= ($s['session'] ?? '') === 'morning' ? 'selected' : '' ?>>Morning</option>
                    <option value="evening" <?= ($s['session'] ?? '') === 'evening' ? 'selected' : '' ?>>Evening</option>
                  </select>
                </div>
                <div>
                  <label class="block text-[10px] font-semibold text-maroon-dark/60 mb-0.5">Capacity</label>
                  <input type="number" name="capacity" value="<?= (int)$s['capacity'] ?>" min="0" class="w-20 rounded border px-2 py-1.5">
                </div>
                <div>
                  <label class="block text-[10px] font-semibold text-maroon-dark/60 mb-0.5">Start</label>
                  <input type="time" name="slot_start" value="<?= e($s['slot_start']) ?>" class="rounded border px-2 py-1.5">
                </div>
                <div>
                  <label class="block text-[10px] font-semibold text-maroon-dark/60 mb-0.5">End</label>
                  <input type="time" name="slot_end" value="<?= e($s['slot_end']) ?>" class="rounded border px-2 py-1.5">
                </div>
                <label class="text-xs flex items-center gap-1 pb-2">
                  <input type="checkbox" name="active" value="1" checked> Active
                </label>
                <button class="bg-maroon text-ivory text-xs font-bold px-3 py-2 rounded-lg mb-0.5">Save changes</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$activeSlots): ?>
          <tr><td colspan="3" class="px-3 py-6 text-center text-maroon-dark/60">No active slots.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <?php if ($inactiveSlots): ?>
  <details class="bg-white gold-border rounded-2xl shadow-sm overflow-hidden">
    <summary class="px-5 py-3 bg-ivory font-semibold text-maroon-dark cursor-pointer">Inactive / past slots (<?= count($inactiveSlots) ?>)</summary>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-ivory/80">
          <tr>
            <th class="px-3 py-2 text-left">Product</th>
            <th class="px-3 py-2 text-left">Date</th>
            <th class="px-3 py-2 text-left">Time</th>
            <th class="px-3 py-2 text-left">Booked</th>
            <th class="px-3 py-2 text-left">Re-enable</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($inactiveSlots as $s): ?>
          <tr class="border-t border-gold/20">
            <td class="px-3 py-2"><?= e($s['product_label']) ?></td>
            <td class="px-3 py-2"><?= e((new DateTimeImmutable($s['event_date']))->format('d M Y')) ?></td>
            <td class="px-3 py-2"><?= e(OfferCatalog::formatSessionRange($s)) ?></td>
            <td class="px-3 py-2"><?= (int)$s['booked'] ?> / <?= (int)$s['capacity'] ?></td>
            <td class="px-3 py-2">
              <form method="post" class="inline-flex flex-wrap gap-2 items-center">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_slot">
                <input type="hidden" name="slot_id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="capacity" value="<?= (int)$s['capacity'] ?>">
                <input type="hidden" name="slot_start" value="<?= e($s['slot_start']) ?>">
                <input type="hidden" name="slot_end" value="<?= e($s['slot_end']) ?>">
                <input type="hidden" name="event_date" value="<?= e($s['event_date']) ?>">
                <input type="hidden" name="session" value="<?= e($s['session'] ?? 'morning') ?>">
                <input type="hidden" name="active" value="1">
                <button class="bg-maroon text-ivory text-xs font-bold px-3 py-1.5 rounded-lg">Activate / Edit on</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
  <?php endif; ?>
</main>

<?php require __DIR__ . '/../templates/footer.php'; ?>
