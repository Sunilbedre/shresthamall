<?php
/**
 * public/special_offer.php  ->  route: /s/{campaign}/{product}
 * Dedicated registration page for one product in a special event.
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$campaignSlug = strtolower(trim((string) ($_GET['campaign'] ?? '')));
$productSlug = strtolower(trim((string) ($_GET['product'] ?? '')));

if ($campaignSlug === '' || $productSlug === '') {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

$productRow = CampaignService::findProductByCampaignSlug($campaignSlug, $productSlug);
if ($productRow === null && $campaignSlug === 'oct-2026' && $productSlug === 'saree-min99') {
    $bootstrapCampaign = CampaignService::findBySlug($campaignSlug);
    if ($bootstrapCampaign !== null) {
        CampaignService::upsertProduct(
            (int) $bootstrapCampaign['id'],
            'rupee1_saree_min99_oct',
            'saree-min99',
            '1 Rupee Saree — Min purchase ₹99/-',
            500,
            2
        );
        $productRow = CampaignService::findProductByCampaignSlug($campaignSlug, $productSlug);
    }
}
if ($productRow === null) {
    http_response_code(404);
    echo 'Offer not found';
    exit;
}

$campaign = CampaignService::findBySlug($campaignSlug);
if ($campaign === null) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

$campaignOpen = CampaignService::isOpen($campaign);
$productFull = CampaignService::isProductFull($productRow);
$remaining = CampaignService::remainingDaily(
    (int) $campaign['id'],
    (string) $productRow['product_key'],
    (string) $campaign['event_date'],
    (int) $productRow['daily_capacity']
);

$eventDateFormatted = (new DateTimeImmutable((string) $campaign['event_date']))->format('l, j F Y');
$productLabel = (string) $productRow['label'];
$campaignTitle = (string) $campaign['title'];

$errors = [];
$duplicateCustomer = null;
$oldInput = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors['_general'] = 'Your session expired. Please try again.';
    } elseif (AuthService::rateLimited('special_register_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 8, 300)) {
        $errors['_general'] = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $oldInput = $_POST;
        $result = CustomerService::registerSpecial(
            $_POST,
            $campaign,
            $productRow,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        );

        if ($result['ok']) {
            $_SESSION['last_registration_customer_id'] = $result['customer']['id'];
            WhatsAppService::sendVoucher($result['customer'], $result['voucher']);
            redirect('/success.php');
        }

        switch ($result['error'] ?? '') {
            case CustomerService::ERR_VALIDATION:
                $errors = $result['fields'];
                break;
            case CustomerService::ERR_DUPLICATE_MOBILE:
            case CustomerService::ERR_CAMPAIGN_MOBILE_USED:
                $duplicateCustomer = $result['customer'];
                break;
            case CustomerService::ERR_REGISTRATION_CLOSED:
                $errors['_general'] = 'closed';
                break;
            case CustomerService::ERR_PRODUCT_FULL:
                $errors['_general'] = 'Sorry, this offer is fully booked for today. Please try another product link if available.';
                $productFull = true;
                break;
            default:
                $errors['_general'] = 'Something went wrong. Please try again.';
        }
    }
}

$pageTitle = $productLabel . ' | ' . $campaignTitle;
$compactHeader = true;
$headerTitle = 'Register for offer';
$headerSubtitle = 'WhatsApp voucher · One mobile number · One voucher · One customer';
require __DIR__ . '/../templates/header.php';
?>

<main class="max-w-md mx-auto px-3.5 sm:px-4 pt-3 pb-24 sm:pb-8">

  <?php if (!$campaignOpen && empty($duplicateCustomer)): ?>
    <div class="bg-white gold-border rounded-2xl p-6 text-center shadow-sm mt-4">
      <h2 class="font-heading text-xl font-bold text-maroon mb-2">Registrations Are Closed</h2>
      <p class="text-sm text-maroon-dark/80">This special event is not open for registration.</p>
    </div>

  <?php elseif ($productFull && empty($duplicateCustomer)): ?>
    <div class="bg-white gold-border rounded-2xl shadow-sm overflow-hidden mt-4">
      <div class="bg-maroon text-ivory px-4 py-4 text-center">
        <div class="text-4xl mb-2">😔</div>
        <h2 class="font-heading text-xl font-bold leading-tight">Fully booked</h2>
        <p class="text-gold-light/95 text-sm mt-2"><?= e($productLabel) ?> has reached today&apos;s limit.</p>
      </div>
      <div class="px-4 py-5 text-center text-sm text-maroon-dark/75">
        <p class="mb-3">Try another product link from our special event, or visit the store directly.</p>
        <?php foreach (CampaignService::products((int) $campaign['id']) as $alt): ?>
          <?php if ($alt['product_slug'] === $productSlug) continue; ?>
          <?php if (CampaignService::isProductFull(array_merge($alt, ['event_date' => $campaign['event_date']]))) continue; ?>
          <a href="<?= e(CampaignService::publicUrl($campaignSlug, (string) $alt['product_slug'])) ?>"
            class="block mb-2 tap-target bg-ivory gold-border rounded-xl py-3 px-4 font-semibold text-maroon hover:bg-gold-light/30">
            <?= e($alt['label']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

  <?php elseif ($duplicateCustomer): ?>
    <div class="bg-white gold-border rounded-2xl p-6 text-center shadow-sm mt-4">
      <h2 class="font-heading text-xl font-bold text-maroon mb-2">Already Registered</h2>
      <p class="text-sm text-maroon-dark/80 mb-3">This mobile number already has a voucher for this special event.</p>
      <p class="text-xs text-maroon-dark/60">One mobile number = one voucher for this event.</p>
    </div>

  <?php else: ?>

    <section class="bg-white gold-border rounded-2xl shadow-sm overflow-hidden mt-2">
      <div class="px-3.5 sm:px-5 pt-4 pb-1 text-center border-b border-gold/25">
        <p class="text-[10px] font-semibold uppercase tracking-widest text-maroon-dark/55"><?= e($campaignTitle) ?></p>
        <h2 class="font-heading text-lg font-bold leading-tight text-maroon mt-1"><?= e($productLabel) ?></h2>
        <?php if (str_contains((string) $productRow['product_key'], 'min99') || str_contains(strtolower($productLabel), 'min purchase')): ?>
          <p class="text-xs font-semibold text-maroon-dark/80 mt-1.5">Minimum store purchase of ₹99 required to redeem this ₹1 saree offer.</p>
        <?php endif; ?>
        <p class="text-xs text-maroon-dark/65 mt-1"><?= e($eventDateFormatted) ?></p>
        <?php if ($remaining < 99999): ?>
          <p class="text-[11px] text-maroon-dark/55 mt-1"><?= (int) $remaining ?> spots left today</p>
        <?php endif; ?>
      </div>

      <div class="px-3.5 sm:px-5 py-4">
        <?php if (!empty($errors['_general']) && $errors['_general'] !== 'closed'): ?>
          <p class="text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-2.5 text-sm mb-4"><?= e($errors['_general']) ?></p>
        <?php endif; ?>

        <form method="post" class="space-y-4" id="regForm" novalidate
          data-otp-enabled="<?= SmsAlertService::isEnabled() ? '1' : '0' ?>"
          data-campaign="<?= e($campaignSlug) ?>">
          <?= csrf_field() ?>

          <div>
            <label for="full_name" class="block text-sm font-semibold text-maroon-dark mb-1">Full name</label>
            <input type="text" id="full_name" name="full_name" required minlength="2" maxlength="80" autocomplete="name"
              value="<?= e($oldInput['full_name'] ?? '') ?>"
              class="w-full tap-target rounded-xl gold-border gold-ring px-3.5 py-3"
              placeholder="Your full name">
            <?php if (!empty($errors['full_name'])): ?><p class="text-red-600 text-xs mt-1"><?= e($errors['full_name']) ?></p><?php endif; ?>
          </div>

          <div>
            <label for="mobile_number" class="block text-sm font-semibold text-maroon-dark mb-1">WhatsApp number</label>
            <div id="mobile_wrap" class="flex items-stretch gold-border rounded-xl overflow-hidden gold-ring">
              <span class="px-3 flex items-center bg-ivory text-maroon-dark/70 font-semibold border-r border-gold/40 select-none text-sm">+91</span>
              <input type="tel" id="mobile_number" name="mobile_number" required inputmode="numeric" maxlength="10" pattern="[6-9][0-9]{9}" autocomplete="tel-national"
                value="<?= e($oldInput['mobile_number'] ?? '') ?>"
                class="flex-1 tap-target px-3.5 py-3 outline-none min-w-0"
                placeholder="10-digit number">
            </div>
            <?php require __DIR__ . '/../templates/mobile_dup_alert.php'; ?>
            <?php if (!empty($errors['mobile_number'])): ?><p class="text-red-600 text-xs mt-1"><?= e($errors['mobile_number']) ?></p><?php endif; ?>
          </div>

          <div>
            <label for="area" class="block text-sm font-semibold text-maroon-dark mb-1">Your area in Bengaluru</label>
            <select id="area" name="area" required class="w-full tap-target rounded-xl gold-border gold-ring px-3.5 py-3 bg-white">
              <option value="">Select your area</option>
              <?php foreach (Areas::byZone() as $zone => $areas): ?>
                <optgroup label="<?= e($zone) ?>">
                  <?php foreach ($areas as $areaName): ?>
                    <option value="<?= e($areaName) ?>" <?= (($oldInput['area'] ?? '') === $areaName) ? 'selected' : '' ?>><?= e($areaName) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
            <?php if (!empty($errors['area'])): ?><p class="text-red-600 text-xs mt-1"><?= e($errors['area']) ?></p><?php endif; ?>
          </div>

          <div>
            <label for="session" class="block text-sm font-semibold text-maroon-dark mb-1">Preferred time slot</label>
            <select id="session" name="session" required class="w-full tap-target rounded-xl gold-border gold-ring px-3.5 py-3 bg-white">
              <option value="">Select time slot</option>
              <option value="morning" <?= (($oldInput['session'] ?? '') === 'morning') ? 'selected' : '' ?>>
                Morning · <?= e(CampaignService::formatSessionLabel($campaign, 'morning')) ?>
              </option>
              <option value="evening" <?= (($oldInput['session'] ?? '') === 'evening') ? 'selected' : '' ?>>
                Evening · <?= e(CampaignService::formatSessionLabel($campaign, 'evening')) ?>
              </option>
            </select>
            <?php if (!empty($errors['session'])): ?><p class="text-red-600 text-xs mt-1"><?= e($errors['session']) ?></p><?php endif; ?>
          </div>

          <?php require __DIR__ . '/../templates/registration_otp.php'; ?>

          <div class="flex items-start gap-2.5 rounded-xl bg-ivory px-3 py-2.5">
            <input type="checkbox" id="consent" name="consent" value="1" required
              <?= !empty($oldInput['consent']) ? 'checked' : '' ?>
              class="mt-0.5 w-[18px] h-[18px] accent-[#7A0026] shrink-0">
            <label for="consent" class="text-xs sm:text-sm text-maroon-dark/85 leading-snug">
              I agree to the Terms &amp; Conditions and to receive my voucher on WhatsApp.
            </label>
          </div>
          <?php if (!empty($errors['consent'])): ?><p class="text-red-600 text-xs -mt-2"><?= e($errors['consent']) ?></p><?php endif; ?>

          <?php require __DIR__ . '/../templates/registration_submit.php'; ?>
        </form>
      </div>
    </section>

  <?php endif; ?>
</main>

<?php
$compactFooter = true;
require __DIR__ . '/../templates/footer.php';
