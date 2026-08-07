<?php
/**
 * public/success.php  ->  route: /success
 * Post-registration confirmation with QR + real PDF download.
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$customerId = $_SESSION['last_registration_customer_id'] ?? null;
$customer = $customerId ? CustomerService::findById((int) $customerId) : null;
$voucher = $customer ? VoucherModel::findByCustomerId((int) $customer['id']) : null;

if (!$customer || !$voucher) {
    redirect('/offer.php');
}

$d = VoucherPresenter::details($customer, $voucher);
$qrUrl = VoucherPresenter::qrImageUrl($voucher['voucher_code'], 240);
$scanUrl = VoucherPresenter::scanUrl($voucher['voucher_code']);
$mapsLink = $d['maps'];
$callLink = 'tel:' . $d['contact'];
$pdfUrl = '/voucher-pdf.php?code=' . rawurlencode($voucher['voucher_code']);

$pageTitle = 'Voucher Confirmed | Shreeshta Family Store';
$compactHeader = true;
$eventDateFormatted = $d['offer_date'];
require __DIR__ . '/../templates/header.php';
?>

<main class="max-w-md mx-auto px-3.5 sm:px-4 pt-3 pb-8">
  <div class="bg-white gold-border rounded-2xl p-4 sm:p-6 shadow-md text-center">
    <div class="w-14 h-14 rounded-full bg-gold-light mx-auto flex items-center justify-center mb-3">
      <span class="text-2xl text-maroon">&#10003;</span>
    </div>
    <h2 class="font-heading text-2xl font-bold text-maroon mb-0.5">Congratulations!</h2>
    <p class="text-maroon-dark/80 text-sm mb-4">Your ₹1 Offer Voucher Is Confirmed</p>

    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl px-3 py-2.5 text-xs sm:text-sm mb-4 leading-snug">
      <strong>Please take a screenshot</strong> of this page and save it on your phone as a backup.<br>
      Your voucher is also sent on <strong>WhatsApp</strong> — check chats and Message requests.
    </div>

    <div class="bg-ivory gold-border rounded-2xl p-4 text-left space-y-2.5 text-sm">
      <div class="flex justify-between gap-3 border-b border-gold/30 pb-2">
        <span class="text-maroon-dark/70">Customer Name</span>
        <span class="font-semibold text-right"><?= e($d['name']) ?></span>
      </div>
      <div class="flex justify-between gap-3 border-b border-gold/30 pb-2">
        <span class="text-maroon-dark/70">Voucher Code</span>
        <span class="font-mono font-bold text-maroon tracking-wide"><?= e($d['code']) ?></span>
      </div>
      <div class="flex justify-between gap-3 border-b border-gold/30 pb-2">
        <span class="text-maroon-dark/70">Selected Product</span>
        <span class="font-semibold text-right"><?= e($d['product']) ?></span>
      </div>
      <div class="flex justify-between gap-3 border-b border-gold/30 pb-2">
        <span class="text-maroon-dark/70">Offer Date</span>
        <span class="font-semibold"><?= e($d['offer_date']) ?></span>
      </div>
      <div class="flex justify-between gap-3 border-b border-gold/30 pb-2">
        <span class="text-maroon-dark/70">Applicable Time</span>
        <span class="font-semibold text-right"><?= e($d['time_slot']) ?></span>
      </div>
      <?php if ($d['area'] !== ''): ?>
      <div class="flex justify-between gap-3 border-b border-gold/30 pb-2">
        <span class="text-maroon-dark/70">Area</span>
        <span class="font-semibold text-right"><?= e($d['area']) ?></span>
      </div>
      <?php endif; ?>
      <div class="flex justify-between gap-3">
        <span class="text-maroon-dark/70">Store</span>
        <span class="font-semibold text-right"><?= e($d['store']) ?></span>
      </div>
    </div>

    <div class="mt-5 flex flex-col items-center">
      <img src="<?= e($qrUrl) ?>" alt="Voucher QR code" width="200" height="200"
        class="rounded-xl gold-border bg-white p-2 w-[200px] h-[200px]" loading="lazy">
      <p class="text-xs text-maroon-dark/60 mt-2">Show this QR at the store counter</p>
    </div>

    <p class="text-xs text-maroon-dark/70 mt-4 bg-gold-light/30 rounded-xl px-3 py-2.5 leading-snug">
      You can avail only the product selected during registration. Product changes are not allowed after voucher generation.
    </p>

    <div class="grid grid-cols-1 gap-2.5 mt-5">
      <a href="<?= e($pdfUrl) ?>"
        class="tap-target bg-maroon hover:bg-maroon-dark transition text-ivory font-bold py-3.5 rounded-xl text-sm flex items-center justify-center">
        Download PDF with QR
      </a>
      <div class="grid grid-cols-2 gap-2.5">
        <a href="<?= e($mapsLink) ?>" target="_blank" rel="noopener"
          class="tap-target bg-ivory hover:bg-gold-light/40 transition text-maroon font-bold py-3 rounded-xl text-sm flex items-center justify-center gold-border">
          Get Directions
        </a>
        <a href="<?= e($callLink) ?>"
          class="tap-target bg-gold hover:bg-gold-light transition text-maroon-dark font-bold py-3 rounded-xl text-sm flex items-center justify-center gold-border">
          Call Store
        </a>
      </div>
    </div>
  </div>
</main>

<?php
$compactFooter = true;
require __DIR__ . '/../templates/footer.php';
?>
