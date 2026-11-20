<?php
/**
 * public/rupee1_qr_register.php  ->  /1rs-saree
 * QR landing: Instagram follow + Google Form registration (not in-app OTP).
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$defaultFormUrl = 'https://docs.google.com/forms/d/e/1FAIpQLSfHW_WCT199Z4LJ2OwtOWcBdX9374-Kjit_ujgr2SAsdVu3gw/viewform';
$formUrl = trim(Settings::get('rupee1_qr_form_url', ''));
if ($formUrl === '') {
    $formUrl = $defaultFormUrl;
}

$instagramUrl = trim(Settings::get('instagram_profile_url', ''));
$storeName = Settings::get('store_name', 'Shreeshta Family Store');
$branchName = Settings::get('branch_name', 'Malleshwaram');

$publicLandingUrl = app_url('1rs-saree');

$pageTitle = '₹1 Saree Registration | ' . $storeName;
$compactHeader = true;
$headerTitle = '₹1 Saree — Scan & Register';
$headerSubtitle = $branchName . ' · QR offer';
require __DIR__ . '/../templates/header.php';
?>

<main class="max-w-md mx-auto px-3.5 sm:px-4 pt-4 pb-24 sm:pb-10">

  <section class="bg-white gold-border rounded-2xl shadow-sm overflow-hidden">
    <div class="bg-maroon text-ivory px-4 py-5 text-center">
      <p class="text-[10px] font-semibold uppercase tracking-widest text-gold-light mb-1">1 Rupee Registration</p>
      <h2 class="font-heading text-xl font-bold leading-tight">Scan &amp; Avail ₹1 Saree</h2>
    </div>

    <div class="px-4 py-5 space-y-4 text-sm text-maroon-dark/85 leading-relaxed">
      <p class="text-center">
        Complete registration on our official form after you scan the QR code at the store or on our poster.
      </p>

      <div class="rounded-xl bg-ivory gold-border px-4 py-4 text-center">
        <p class="font-semibold text-maroon mb-2">📢 Monthly offer</p>
        <p class="text-sm leading-snug">
          This offer is available <strong>once in a month</strong>.
          Follow our Instagram page to get updates regularly.
        </p>
        <?php if ($instagramUrl !== ''): ?>
          <a href="<?= e($instagramUrl) ?>" target="_blank" rel="noopener"
            class="inline-flex items-center justify-center tap-target mt-3 w-full rounded-xl border-2 border-maroon/20 bg-white text-maroon font-bold py-3 px-4 hover:bg-maroon/5">
            Follow us on Instagram
          </a>
        <?php else: ?>
          <p class="text-xs text-maroon-dark/55 mt-2">Instagram link can be set in Admin → Settings.</p>
        <?php endif; ?>
      </div>

      <a href="<?= e($formUrl) ?>" target="_blank" rel="noopener"
        class="flex tap-target w-full items-center justify-center rounded-xl bg-gold text-maroon-dark font-bold text-base py-4 shadow-md hover:bg-gold/90 active:scale-[0.98] transition-transform">
        Register for ₹1 Saree
      </a>
      <p class="text-[11px] text-center text-maroon-dark/50">You will open the secure Google registration form.</p>
    </div>
  </section>

</main>

<?php
$compactFooter = true;
require __DIR__ . '/../templates/footer.php';