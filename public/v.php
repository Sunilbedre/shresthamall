<?php
/**
 * public/v.php  ->  route: /v/{CODE}
 * Staff-scannable voucher card with PDF download (no print dialog).
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$code = trim((string) ($_GET['code'] ?? ''));
if ($code === '' && !empty($_SERVER['ROUTE_VOUCHER_CODE'])) {
    $code = (string) $_SERVER['ROUTE_VOUCHER_CODE'];
}
$code = strtoupper($code);

$voucher = $code !== '' ? VoucherModel::findByCode($code) : null;
$customer = $voucher ? CustomerService::findById((int) $voucher['customer_id']) : null;

if (!$voucher || !$customer) {
    http_response_code(404);
    $pageTitle = 'Voucher Not Found';
    require __DIR__ . '/../templates/header.php';
    echo '<main class="max-w-md mx-auto px-4 py-10 text-center"><h2 class="font-heading text-xl font-bold text-maroon">Voucher not found</h2></main>';
    require __DIR__ . '/../templates/footer.php';
    exit;
}

$d = VoucherPresenter::details($customer, $voucher);
$qrUrl = VoucherPresenter::qrImageUrl($voucher['voucher_code'], 280);
$pdfUrl = '/voucher-pdf.php?code=' . rawurlencode($voucher['voucher_code']);

$pageTitle = 'Voucher ' . $d['code'] . ' – Shreeshta Family Store';
$compactHeader = true;
$eventDateFormatted = $d['offer_date'];
require __DIR__ . '/../templates/header.php';
?>

<main class="max-w-md mx-auto px-3.5 sm:px-4 pt-3 pb-8">
  <a href="<?= e($pdfUrl) ?>"
    class="block w-full tap-target bg-maroon text-ivory font-bold rounded-xl text-sm flex items-center justify-center mb-3">
    Download PDF
  </a>

  <p class="text-xs text-center text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 mb-3">
    Please take a screenshot of this voucher as a backup.
  </p>

  <article class="bg-white gold-border rounded-2xl p-5 shadow-md">
    <div class="text-center border-b border-gold/30 pb-3 mb-4">
      <p class="text-[10px] uppercase tracking-[0.16em] text-gold font-bold">Shreeshta Family Store</p>
      <h1 class="font-heading text-xl font-bold text-maroon mt-1">Special Offer Voucher</h1>
      <p class="text-xs text-maroon-dark/60 mt-1"><?= e($d['store']) ?></p>
    </div>

    <div class="flex flex-col sm:flex-row gap-4 items-center sm:items-start">
      <img src="<?= e($qrUrl) ?>" alt="QR <?= e($d['code']) ?>" width="180" height="180"
        class="rounded-lg border border-gold/40 bg-white p-1.5 w-[180px] h-[180px] shrink-0">

      <div class="flex-1 w-full text-sm space-y-2">
        <div class="flex justify-between gap-2 border-b border-gold/20 pb-1.5">
          <span class="text-maroon-dark/60">Name</span>
          <span class="font-semibold text-right"><?= e($d['name']) ?></span>
        </div>
        <div class="flex justify-between gap-2 border-b border-gold/20 pb-1.5">
          <span class="text-maroon-dark/60">Mobile</span>
          <span class="font-semibold text-right"><?= e($d['mobile']) ?></span>
        </div>
        <?php if ($d['area'] !== ''): ?>
        <div class="flex justify-between gap-2 border-b border-gold/20 pb-1.5">
          <span class="text-maroon-dark/60">Area</span>
          <span class="font-semibold text-right"><?= e($d['area']) ?></span>
        </div>
        <?php endif; ?>
        <div class="flex justify-between gap-2 border-b border-gold/20 pb-1.5">
          <span class="text-maroon-dark/60">Voucher</span>
          <span class="font-mono font-bold text-maroon text-right"><?= e($d['code']) ?></span>
        </div>
        <div class="flex justify-between gap-2 border-b border-gold/20 pb-1.5">
          <span class="text-maroon-dark/60">Product</span>
          <span class="font-semibold text-right"><?= e($d['product']) ?></span>
        </div>
        <div class="flex justify-between gap-2 border-b border-gold/20 pb-1.5">
          <span class="text-maroon-dark/60">Offer Date</span>
          <span class="font-semibold text-right"><?= e($d['offer_date']) ?></span>
        </div>
        <div class="flex justify-between gap-2">
          <span class="text-maroon-dark/60">Time Slot</span>
          <span class="font-semibold text-right"><?= e($d['time_slot']) ?></span>
        </div>
      </div>
    </div>

    <p class="text-[11px] text-maroon-dark/55 mt-4 leading-snug text-center">
      One customer · One mobile number · One voucher · One product. Show this voucher at the verification counter.
      <?php if ($d['address']): ?><br><?= e($d['address']) ?><?php endif; ?>
    </p>
  </article>
</main>

<?php
$compactFooter = true;
require __DIR__ . '/../templates/footer.php';
?>
