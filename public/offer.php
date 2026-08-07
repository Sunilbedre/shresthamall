<?php
/**
 * public/offer.php  ->  route: /offer
 * Mobile-first registration: form is the landing experience.
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$eventDate = Settings::get('event_date');
$eventDateFormatted = $eventDate ? (new DateTimeImmutable($eventDate))->format('d M Y') : '';
$registrationOpen = Settings::get('registration_status', 'OPEN') === 'OPEN';

$errors = [];
$duplicateCustomer = null;
$oldInput = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors['_general'] = 'Your session expired. Please try again.';
    } elseif (AuthService::rateLimited('register_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 8, 300)) {
        $errors['_general'] = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $oldInput = $_POST;
        $result = CustomerService::register($_POST, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '');

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
                $duplicateCustomer = $result['customer'];
                break;
            case CustomerService::ERR_REGISTRATION_CLOSED:
                $errors['_general'] = 'closed';
                break;
            case CustomerService::ERR_PRODUCT_FULL:
                $errors['_general'] = 'Sorry, this product is fully booked for the selected time slot. Please choose another product or slot.';
                break;
            default:
                $errors['_general'] = 'Something went wrong. Please try again.';
        }
    }
}

$pageTitle = 'Rs 1 Special Offer | Shreeshta Family Store';
$compactHeader = true;
require __DIR__ . '/../templates/header.php';
?>

<main class="max-w-md mx-auto px-3.5 sm:px-4 pt-3 pb-24 sm:pb-8">

  <?php if (!$registrationOpen && empty($duplicateCustomer)): ?>
    <div class="bg-white gold-border rounded-2xl p-6 text-center shadow-sm mt-4">
      <h2 class="font-heading text-xl font-bold text-maroon mb-2">Registrations Are Closed</h2>
      <p class="text-sm text-maroon-dark/80">Thank you for your interest in the ₹1 Special Offer.</p>
    </div>

  <?php else: ?>

    <?php if ($duplicateCustomer): ?>
      <section class="bg-white gold-border rounded-2xl p-5 shadow-sm mb-3 text-center">
        <div class="w-12 h-12 rounded-full bg-amber-100 mx-auto flex items-center justify-center mb-3">
          <span class="text-xl text-amber-700">!</span>
        </div>
        <h3 class="font-heading text-lg font-bold text-maroon mb-2">Voucher already used for this number</h3>
        <p class="text-maroon-dark/80 text-sm leading-relaxed">
          This mobile number has already received a ₹1 offer voucher.
          Only <strong>one voucher per mobile number</strong> is allowed.
        </p>
        <p class="text-xs text-maroon-dark/55 mt-3">
          Please check WhatsApp for your existing voucher, or visit the store counter for help.
        </p>
      </section>
    <?php endif; ?>

    <?php if (!empty($errors['_general']) && $errors['_general'] === 'closed'): ?>
      <div class="bg-white gold-border rounded-2xl p-6 text-center shadow-sm">
        <h2 class="font-heading text-xl font-bold text-maroon mb-2">Registrations Are Closed</h2>
        <p class="text-sm text-maroon-dark/80">Thank you for your interest.</p>
      </div>
    <?php elseif (!$duplicateCustomer && $registrationOpen): ?>

      <section class="bg-white gold-border rounded-2xl shadow-sm overflow-hidden">
        <!-- Compact form header -->
        <div class="bg-maroon text-ivory px-4 py-3.5 text-center">
          <h2 class="font-heading text-xl font-bold leading-tight">Get your ₹1 voucher</h2>
          <p class="text-gold-light/95 text-xs mt-1">
            WhatsApp delivery
            <?php if ($eventDateFormatted): ?>
              &nbsp;·&nbsp; <?= e($eventDateFormatted) ?>
            <?php endif; ?>
          </p>
        </div>

        <div class="px-3.5 sm:px-5 py-4">
          <p class="text-[11px] sm:text-xs text-center text-maroon-dark/55 mb-4 leading-snug">
            One customer · One mobile number · One voucher · One product
          </p>

          <?php if (!empty($errors['_general'])): ?>
            <p class="text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-2.5 text-sm mb-4"><?= e($errors['_general']) ?></p>
          <?php endif; ?>

          <form method="post" class="space-y-4" id="regForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="register">

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
              <p id="mobile_dup_msg" class="hidden text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-2 text-xs mt-2 leading-snug" role="alert"></p>
              <?php if (!empty($errors['mobile_number'])): ?><p class="text-red-600 text-xs mt-1"><?= e($errors['mobile_number']) ?></p><?php endif; ?>
            </div>

            <div>
              <label for="area" class="block text-sm font-semibold text-maroon-dark mb-1">Your area in Bengaluru</label>
              <select id="area" name="area" required
                class="w-full tap-target rounded-xl gold-border gold-ring px-3.5 py-3 bg-white">
                <option value="">Select your area</option>
                <?php foreach (Areas::byZone() as $zone => $areas): ?>
                  <optgroup label="<?= e($zone) ?>">
                    <?php foreach ($areas as $areaName): ?>
                      <option value="<?= e($areaName) ?>"
                        <?= (($oldInput['area'] ?? '') === $areaName) ? 'selected' : '' ?>>
                        <?= e($areaName) ?>
                      </option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endforeach; ?>
              </select>
              <?php if (!empty($errors['area'])): ?><p class="text-red-600 text-xs mt-1"><?= e($errors['area']) ?></p><?php endif; ?>
            </div>

            <div>
              <label for="session" class="block text-sm font-semibold text-maroon-dark mb-1">Choose time slot</label>
              <select id="session" name="session" required
                class="w-full tap-target rounded-xl gold-border gold-ring px-3.5 py-3 bg-white">
                <option value="">Select time slot</option>
                <option value="morning" <?= (($oldInput['session'] ?? '') === 'morning') ? 'selected' : '' ?>>
                  11 AM – 2 PM
                </option>
                <option value="evening" <?= (($oldInput['session'] ?? '') === 'evening') ? 'selected' : '' ?>>
                  5 PM – 8 PM
                </option>
              </select>
              <?php if (!empty($errors['session'])): ?><p class="text-red-600 text-xs mt-1"><?= e($errors['session']) ?></p><?php endif; ?>
            </div>

            <div>
              <label for="selected_offer" class="block text-sm font-semibold text-maroon-dark mb-1">Choose ₹1 product</label>
              <select id="selected_offer" name="selected_offer" required
                class="w-full tap-target rounded-xl gold-border gold-ring px-3.5 py-3 bg-white">
                <option value="">Select product</option>
                <?php foreach (Products::all() as $key => $p): ?>
                  <option value="<?= e($key) ?>"
                    <?= (($oldInput['selected_offer'] ?? '') === $key) ? 'selected' : '' ?>>
                    <?= e($p['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if (!empty($errors['selected_offer'])): ?><p class="text-red-600 text-xs mt-1"><?= e($errors['selected_offer']) ?></p><?php endif; ?>
            </div>

            <div class="flex items-start gap-2.5 rounded-xl bg-ivory px-3 py-2.5">
              <input type="checkbox" id="consent" name="consent" value="1" required
                <?= !empty($oldInput['consent']) ? 'checked' : '' ?>
                class="mt-0.5 w-[18px] h-[18px] accent-[#7A0026] shrink-0">
              <label for="consent" class="text-xs sm:text-sm text-maroon-dark/85 leading-snug">
                Send my voucher &amp; offer updates on WhatsApp.
              </label>
            </div>
            <?php if (!empty($errors['consent'])): ?><p class="text-red-600 text-xs -mt-2"><?= e($errors['consent']) ?></p><?php endif; ?>

            <!-- Keep submit INSIDE the form so CSRF + fields always post (esp. on mobile). -->
            <div class="pt-1 sm:pt-0">
              <div class="hidden sm:block">
                <button type="submit"
                  class="w-full tap-target bg-maroon hover:bg-maroon-dark text-ivory font-bold py-3.5 rounded-xl gold-border shadow-md">
                  Get My ₹1 Voucher
                </button>
              </div>
            </div>
          </form>
        </div>
      </section>

      <details class="mt-3 px-1 text-xs text-maroon-dark/65">
        <summary class="font-semibold text-maroon-dark/80 cursor-pointer py-2">Terms &amp; conditions</summary>
        <ul class="list-disc list-inside space-y-1 pb-2 pl-0.5">
          <li>One customer, one mobile number, one ₹1 product only.</li>
          <li>Product cannot be changed after registration.</li>
          <li>Valid only on the event date &amp; selected time slot.</li>
          <li>Show the original WhatsApp voucher at the counter.</li>
          <li>Subject to stock; no cash exchange or transfer.</li>
        </ul>
      </details>

      <!-- Mobile sticky CTA — still submits #regForm, but CSRF lives inside the form -->
      <div class="fixed inset-x-0 bottom-0 z-50 sm:hidden bg-white/95 backdrop-blur border-t border-gold/40 px-3.5 pt-2.5 safe-bottom shadow-[0_-8px_24px_rgba(82,0,24,0.1)]">
        <button type="submit" form="regForm"
          class="w-full tap-target bg-maroon active:bg-maroon-dark text-ivory font-bold text-[15px] py-3.5 rounded-xl gold-border">
          Get My ₹1 Voucher on WhatsApp
        </button>
      </div>

    <?php endif; ?>
  <?php endif; ?>
</main>

<script>
  const mobileInput = document.getElementById('mobile_number');
  const mobileDupMsg = document.getElementById('mobile_dup_msg');
  const mobileWrap = document.getElementById('mobile_wrap');
  const regForm = document.getElementById('regForm');
  let mobileRegistered = false;
  let checkTimer = null;
  let lastChecked = '';

  function setMobileDupState(registered, message) {
    mobileRegistered = registered;
    if (!mobileDupMsg) return;
    if (registered) {
      mobileDupMsg.textContent = message || 'This mobile number is already registered. Voucher already used for this number.';
      mobileDupMsg.classList.remove('hidden');
      if (mobileWrap) mobileWrap.classList.add('border-red-500');
    } else {
      mobileDupMsg.textContent = '';
      mobileDupMsg.classList.add('hidden');
      if (mobileWrap) mobileWrap.classList.remove('border-red-500');
    }
  }

  function checkMobileDuplicate(force) {
    if (!mobileInput) return;
    const digits = mobileInput.value.replace(/\D/g, '').slice(0, 10);
    mobileInput.value = digits;

    if (digits.length < 10) {
      lastChecked = '';
      setMobileDupState(false, '');
      return;
    }
    if (!/^[6-9][0-9]{9}$/.test(digits)) {
      setMobileDupState(false, '');
      return;
    }
    if (!force && digits === lastChecked) return;
    lastChecked = digits;

    fetch('/check-mobile.php?mobile=' + encodeURIComponent(digits), {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then((r) => r.json())
      .then((data) => {
        if (mobileInput.value !== digits) return; // user kept typing
        setMobileDupState(!!data.registered, data.message || '');
      })
      .catch(() => {});
  }

  if (mobileInput) {
    mobileInput.addEventListener('input', () => {
      mobileInput.value = mobileInput.value.replace(/\D/g, '').slice(0, 10);
      if (checkTimer) clearTimeout(checkTimer);
      checkTimer = setTimeout(() => checkMobileDuplicate(false), 350);
    });
    mobileInput.addEventListener('blur', () => checkMobileDuplicate(true));
    if (mobileInput.value.length === 10) checkMobileDuplicate(true);
  }

  if (regForm) {
    regForm.addEventListener('submit', (e) => {
      if (mobileRegistered) {
        e.preventDefault();
        setMobileDupState(true, mobileDupMsg ? mobileDupMsg.textContent : '');
        mobileInput && mobileInput.focus();
      }
    });
  }
</script>

<?php
$compactFooter = true;
require __DIR__ . '/../templates/footer.php';
?>
