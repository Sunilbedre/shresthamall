<?php
/**
 * admin/settings.php — store / WhatsApp / registration open-close.
 * Weekly products, dates, times & stock → Admin → Events.
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
        $fields = [
            'event_title', 'store_name', 'branch_name', 'store_address',
            'maps_link', 'contact_number', 'max_whatsapp_resends',
            'waitlist_form_url',
        ];
        $update = [];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                $update[$f] = trim((string) $_POST[$f]);
            }
        }
        $update['registration_status'] = !empty($_POST['registration_status']) ? 'OPEN' : 'CLOSED';

        try {
            Settings::setMany($update);

            if (!empty($_FILES['whatsapp_header_image']['name'])) {
                $upload = WhatsAppService::saveUploadedHeaderImage($_FILES['whatsapp_header_image']);
                if (!$upload['ok']) {
                    $flashError = $upload['error'] ?? 'Header image upload failed.';
                } else {
                    AuditLog::record(AuthService::currentUsername() ?? 'admin', 'WHATSAPP_HEADER_UPDATED', null, $_SERVER['REMOTE_ADDR'] ?? null);
                }
            }

            if ($flashError === null) {
                AuditLog::record(AuthService::currentUsername() ?? 'admin', 'SETTINGS_UPDATED', null, $_SERVER['REMOTE_ADDR'] ?? null);
                $flash = 'Settings saved successfully.';
            }
        } catch (Throwable $e) {
            $flashError = 'Could not save settings: ' . $e->getMessage();
        }
    }
}

$s = Settings::all();
$headerPreview = WhatsAppService::headerImagePublicUrl();
$activeDates = OfferCatalog::eventDates(true);

$activePage = 'settings';
$skipDefaultHeader = true;
$pageTitle = 'Settings – Shreeshta Family Store';
require __DIR__ . '/../templates/header.php';
?>
<?php require __DIR__ . '/../templates/admin_nav.php'; ?>

<main class="flex-1 max-w-3xl mx-auto px-5 py-8">
  <h2 class="font-heading text-2xl font-bold text-maroon mb-5">Store &amp; Registration Settings</h2>

  <?php if ($flash): ?>
    <p class="text-green-700 bg-green-50 border border-green-200 rounded-lg px-4 py-2 text-sm mb-4"><?= e($flash) ?></p>
  <?php endif; ?>
  <?php if ($flashError): ?>
    <p class="text-red-700 bg-red-50 border border-red-200 rounded-lg px-4 py-2 text-sm mb-4"><?= e($flashError) ?></p>
  <?php endif; ?>

  <div class="bg-ivory gold-border rounded-2xl p-4 mb-5 text-sm space-y-2">
    <p class="font-semibold text-maroon">Weekly offers (products · dates · times · stock)</p>
    <p class="text-maroon-dark/75 text-xs leading-relaxed">
      Each week change products and slots from
      <a class="underline font-semibold text-maroon" href="<?= e(admin_url('events.php')) ?>">Events</a>.
      Past mobiles stay blocked forever. Export defaults to the current event dates only.
    </p>
    <?php if ($activeDates): ?>
      <p class="text-xs text-maroon-dark">
        Live event dates:
        <strong><?= e(implode(', ', array_map(static fn ($d) => (new DateTimeImmutable($d))->format('d M Y'), $activeDates))) ?></strong>
      </p>
    <?php else: ?>
      <p class="text-xs text-amber-800">No active event slots — open <a class="underline" href="<?= e(admin_url('events.php')) ?>">Events</a> to add this week.</p>
    <?php endif; ?>
  </div>

  <form method="post" enctype="multipart/form-data" class="bg-white gold-border rounded-2xl p-5 sm:p-6 shadow-sm space-y-5">
    <?= csrf_field() ?>

    <div class="flex items-center justify-between bg-ivory rounded-xl px-4 py-3 gold-border">
      <span class="font-semibold text-maroon-dark">Registration Status</span>
      <label class="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" name="registration_status" value="1" <?= ($s['registration_status'] ?? 'OPEN') === 'OPEN' ? 'checked' : '' ?> class="w-5 h-5 accent-[#7A0026]">
        <span class="text-sm">Open for new registrations</span>
      </label>
    </div>

    <div class="gold-border rounded-xl p-4 bg-ivory/50 space-y-3">
      <h3 class="font-heading text-lg font-bold text-maroon">WhatsApp Header Image</h3>
      <p class="text-xs text-maroon-dark/65">JPG/PNG/WebP, max 5 MB. Recommended ~1125×600.</p>
      <?php if ($headerPreview): ?>
        <img src="<?= e($headerPreview) ?>" alt="Current WhatsApp header" class="w-full max-w-lg rounded-lg gold-border bg-white">
      <?php else: ?>
        <p class="text-sm text-maroon-dark/50">No header image uploaded yet.</p>
      <?php endif; ?>
      <div>
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Upload new header</label>
        <input type="file" name="whatsapp_header_image" accept="image/jpeg,image/png,image/webp"
          class="w-full text-sm file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-maroon file:text-ivory file:font-semibold">
      </div>
    </div>

    <div class="grid sm:grid-cols-2 gap-4">
      <div class="sm:col-span-2">
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Event Title (marketing name)</label>
        <input type="text" name="event_title" value="<?= e($s['event_title'] ?? '') ?>" class="w-full rounded-lg gold-border px-3 py-2" placeholder="Weekend Special Offer">
      </div>
      <div>
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Store Name</label>
        <input type="text" name="store_name" value="<?= e($s['store_name'] ?? '') ?>" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Branch Name</label>
        <input type="text" name="branch_name" value="<?= e($s['branch_name'] ?? '') ?>" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div class="sm:col-span-2">
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Store Address</label>
        <input type="text" name="store_address" value="<?= e($s['store_address'] ?? '') ?>" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div class="sm:col-span-2">
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Google Maps Link</label>
        <input type="url" name="maps_link" value="<?= e($s['maps_link'] ?? '') ?>" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div class="sm:col-span-2">
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Waiting List Form URL (Google Form)</label>
        <input type="url" name="waitlist_form_url" value="<?= e($s['waitlist_form_url'] ?? '') ?>" class="w-full rounded-lg gold-border px-3 py-2" placeholder="https://forms.gle/...">
        <p class="text-xs text-maroon-dark/55 mt-1">Shown automatically when all offer slots are full — customers can join the waitlist.</p>
      </div>
      <div>
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Contact Number</label>
        <input type="text" name="contact_number" value="<?= e($s['contact_number'] ?? '') ?>" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Max WhatsApp Resends / Day</label>
        <input type="number" min="1" max="10" name="max_whatsapp_resends" value="<?= e($s['max_whatsapp_resends'] ?? '3') ?>" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
    </div>

    <button type="submit" class="w-full bg-maroon hover:bg-maroon-dark transition text-ivory font-bold py-3 rounded-xl">
      Save Settings
    </button>
  </form>
</main>

<?php require __DIR__ . '/../templates/footer.php'; ?>
