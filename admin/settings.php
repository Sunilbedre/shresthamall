<?php
/**
 * admin/settings.php  ->  route: /admin/settings
 * Admin-only. Editable event/store settings + registration open/closed toggle
 * + optional per-product registration limits.
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
            'event_title', 'event_date', 'store_name', 'branch_name', 'store_address',
            'maps_link', 'contact_number', 'morning_start', 'morning_end',
            'evening_start', 'evening_end', 'max_whatsapp_resends',
            'limit_saree', 'limit_kids_boys', 'limit_kids_girls',
            'limit_leggings', 'limit_kurti', 'limit_mens_shirt',
        ];
        $update = [];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                $update[$f] = trim((string) $_POST[$f]);
            }
        }
        $update['registration_status'] = !empty($_POST['registration_status']) ? 'OPEN' : 'CLOSED';
        $update['product_limits_enabled'] = !empty($_POST['product_limits_enabled']) ? '1' : '0';

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

$activePage = 'settings';
$skipDefaultHeader = true;
$pageTitle = 'Settings – Shreeshta Family Store';
require __DIR__ . '/../templates/header.php';
?>
<?php require __DIR__ . '/../templates/admin_nav.php'; ?>

<main class="flex-1 max-w-3xl mx-auto px-5 py-8">
  <h2 class="font-heading text-2xl font-bold text-maroon mb-5">Event &amp; Store Settings</h2>

  <?php if ($flash): ?>
    <p class="text-green-700 bg-green-50 border border-green-200 rounded-lg px-4 py-2 text-sm mb-4"><?= e($flash) ?></p>
  <?php endif; ?>
  <?php if ($flashError): ?>
    <p class="text-red-700 bg-red-50 border border-red-200 rounded-lg px-4 py-2 text-sm mb-4"><?= e($flashError) ?></p>
  <?php endif; ?>

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
      <p class="text-xs text-maroon-dark/65">This image appears at the top of the WhatsApp voucher message. JPG/PNG/WebP, max 5 MB. Recommended wide banner (about 1125×600).</p>
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
      <div class="sm:col-span-2 bg-maroon/5 gold-border rounded-xl p-4">
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Offer Date <span class="text-maroon">(used everywhere)</span></label>
        <input type="date" name="event_date" required value="<?= e($s['event_date'] ?? '') ?>" class="w-full rounded-lg gold-border px-3 py-2.5 text-base font-semibold">
        <p class="text-xs text-maroon-dark/60 mt-1.5">This date appears on the registration page, WhatsApp voucher, success screen, QR printout, and verification.</p>
      </div>
      <div class="sm:col-span-2">
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Event Title</label>
        <input type="text" name="event_title" value="<?= e($s['event_title'] ?? '') ?>" class="w-full rounded-lg gold-border px-3 py-2">
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
      <div>
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Contact Number</label>
        <input type="text" name="contact_number" value="<?= e($s['contact_number'] ?? '') ?>" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Max WhatsApp Resends / Day</label>
        <input type="number" min="1" max="10" name="max_whatsapp_resends" value="<?= e($s['max_whatsapp_resends'] ?? '3') ?>" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
    </div>

    <h3 class="font-heading text-lg font-bold text-maroon pt-2">Time Slots</h3>
    <p class="text-xs text-maroon-dark/60 -mt-2 mb-2">Customers pick one slot on the form. All products are available in both slots.</p>
    <div class="grid sm:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Morning Start (11 AM – 2 PM)</label>
        <input type="time" name="morning_start" value="<?= e($s['morning_start'] ?? '') ?>" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Morning End</label>
        <input type="time" name="morning_end" value="<?= e($s['morning_end'] ?? '') ?>" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Evening Start (5 PM – 8 PM)</label>
        <input type="time" name="evening_start" value="<?= e($s['evening_start'] ?? '') ?>" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Evening End</label>
        <input type="time" name="evening_end" value="<?= e($s['evening_end'] ?? '') ?>" class="w-full rounded-lg gold-border px-3 py-2">
      </div>
    </div>

    <h3 class="font-heading text-lg font-bold text-maroon pt-2">Product Limits (per time slot)</h3>
    <label class="flex items-center gap-2 cursor-pointer mb-2">
      <input type="checkbox" name="product_limits_enabled" value="1" <?= ($s['product_limits_enabled'] ?? '1') === '1' ? 'checked' : '' ?> class="w-5 h-5 accent-[#7A0026]">
      <span class="text-sm font-semibold text-maroon-dark">Enable max vouchers per product per slot</span>
    </label>
    <p class="text-xs text-maroon-dark/60 mb-2">Default: Saree 500 / slot · other products 100 / slot</p>
    <div class="grid sm:grid-cols-2 gap-4">
      <?php
        $limitFields = [
            'limit_saree' => '₹1 Saree (per slot)',
            'limit_kids_boys' => '₹1 Kids T-Shirt – Boys (per slot)',
            'limit_kids_girls' => '₹1 Kids T-Shirt – Girls (per slot)',
            'limit_leggings' => "₹1 Women's Leggings (per slot)",
            'limit_kurti' => "₹1 Women's Kurti (per slot)",
            'limit_mens_shirt' => "₹1 Men's Shirt (per slot)",
        ];
      ?>
      <?php foreach ($limitFields as $key => $label): ?>
        <div>
          <label class="block text-sm font-semibold text-maroon-dark mb-1"><?= e($label) ?></label>
          <input type="number" min="0" name="<?= e($key) ?>" value="<?= e($s[$key] ?? '0') ?>" class="w-full rounded-lg gold-border px-3 py-2">
        </div>
      <?php endforeach; ?>
    </div>

    <button type="submit" class="w-full bg-maroon hover:bg-maroon-dark transition text-ivory font-bold py-3 rounded-xl">
      Save Settings
    </button>
  </form>
</main>

<?php require __DIR__ . '/../templates/footer.php'; ?>
