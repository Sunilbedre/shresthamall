<?php
/**
 * public/offer.php  ->  route: /offer
 * Mobile-first registration: form is the landing experience.
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$registrationOpen = Settings::get('registration_status', 'OPEN') === 'OPEN';
$offerProducts    = OfferCatalog::products(true);
$availableSlots   = OfferCatalog::availableSlots();
$slotsByProduct   = [];
foreach ($availableSlots as $slot) {
    $slotsByProduct[$slot['product_key']][] = [
        'id'    => (int) $slot['id'],
        'label' => $slot['label'],
    ];
}
// True when there are active products but zero remaining slots across all of them
$allSlotsFull  = $offerProducts && empty($availableSlots);
$waitlistUrl   = Settings::get('waitlist_form_url', '');

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
                $errors['_general'] = 'Sorry, this offer is fully booked for the selected date and time. Please choose another slot.';
                break;
            default:
                $errors['_general'] = 'Something went wrong. Please try again.';
        }
    }
}

$pageTitle = 'Special Offer | Shreeshta Family Store';
$compactHeader = true;
$headerTitle = 'Register for offer';
$headerSubtitle = 'WhatsApp voucher · One mobile number · One voucher';
require __DIR__ . '/../templates/header.php';
?>

<main class="max-w-md mx-auto px-3.5 sm:px-4 pt-3 pb-24 sm:pb-8">

  <?php if (!$registrationOpen && empty($duplicateCustomer)): ?>
    <div class="bg-white gold-border rounded-2xl p-6 text-center shadow-sm mt-4">
      <h2 class="font-heading text-xl font-bold text-maroon mb-2">Registrations Are Closed</h2>
      <p class="text-sm text-maroon-dark/80">Thank you for your interest in our special offer.</p>
    </div>

  <?php elseif ($allSlotsFull && empty($duplicateCustomer)): ?>
    <!-- All slots full — show waitlist banner -->
    <div class="bg-white gold-border rounded-2xl shadow-sm overflow-hidden mt-4">
      <div class="bg-maroon text-ivory px-4 py-4 text-center">
        <div class="text-4xl mb-2">😔</div>
        <h2 class="font-heading text-xl font-bold leading-tight">Missed the offer?<br>Don't worry!</h2>
      </div>
      <div class="px-5 py-5 text-center space-y-3">
        <p class="text-sm text-maroon-dark/80 leading-relaxed">
          All slots for this offer are currently full.<br>
          Click the link below to <strong>join our waiting list</strong> and stay updated for more exciting offers and opportunities.
        </p>
        <?php if ($waitlistUrl): ?>
          <a href="<?= e($waitlistUrl) ?>" target="_blank" rel="noopener"
            class="inline-block w-full tap-target bg-gold text-maroon-dark font-bold py-4 rounded-xl gold-border shadow-md text-base mt-2 hover:bg-gold/90 active:scale-95 transition-transform">
            📋 Join the Waiting List
          </a>
          <p class="text-xs text-maroon-dark/50">You will be notified first when the next offer opens.</p>
        <?php else: ?>
          <p class="text-sm text-maroon-dark/60">Please visit the store or check back later for the next offer.</p>
        <?php endif; ?>
      </div>
    </div>

  <?php else: ?>

    <?php if ($duplicateCustomer): ?>
      <section class="bg-white gold-border rounded-2xl p-5 shadow-sm mb-3 text-center">
        <div class="w-12 h-12 rounded-full bg-amber-100 mx-auto flex items-center justify-center mb-3">
          <span class="text-xl text-amber-700">!</span>
        </div>
        <h3 class="font-heading text-lg font-bold text-maroon mb-2">Voucher already used for this number</h3>
        <p class="text-maroon-dark/80 text-sm leading-relaxed">
          This mobile number already received a voucher in a previous offer.
          Only <strong>one coupon per mobile number</strong> is allowed — for this event and future events.
        </p>
        <p class="text-xs text-maroon-dark/55 mt-3">
          Please check WhatsApp for your existing voucher, or visit the store counter for help.
        </p>
      </section>
    <?php endif; ?>

    <?php if ($allSlotsFull && empty($duplicateCustomer)): ?>
      <?php /* already shown above — nothing here */ ?>
    <?php elseif (!empty($errors['_general']) && $errors['_general'] === 'closed'): ?>
      <div class="bg-white gold-border rounded-2xl p-6 text-center shadow-sm">
        <h2 class="font-heading text-xl font-bold text-maroon mb-2">Registrations Are Closed</h2>
        <p class="text-sm text-maroon-dark/80">Thank you for your interest.</p>
      </div>
    <?php elseif (!$duplicateCustomer && $registrationOpen): ?>

      <section class="bg-white gold-border rounded-2xl shadow-sm overflow-hidden mt-2">
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

              <?php if (SmsAlertService::isEnabled()): ?>
              <div id="otp_block" class="mt-3 space-y-2">
                <div class="flex gap-2">
                  <button type="button" id="send_otp_btn"
                    class="shrink-0 tap-target px-4 py-2.5 rounded-xl bg-maroon text-ivory text-sm font-bold gold-border disabled:opacity-50">
                    Send OTP
                  </button>
                  <input type="tel" id="otp_code" name="otp_code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}"
                    autocomplete="one-time-code"
                    placeholder="6-digit OTP"
                    class="flex-1 tap-target rounded-xl gold-border gold-ring px-3.5 py-2.5 min-w-0">
                  <button type="button" id="verify_otp_btn"
                    class="shrink-0 tap-target px-4 py-2.5 rounded-xl bg-ivory text-maroon-dark text-sm font-bold gold-border disabled:opacity-50">
                    Verify
                  </button>
                </div>
                <p id="otp_msg" class="text-xs text-maroon-dark/70 leading-snug"></p>
                <input type="hidden" id="otp_verified" name="otp_verified" value="<?= !empty($oldInput['otp_verified']) ? '1' : '0' ?>">
                <?php if (!empty($errors['otp'])): ?><p class="text-red-600 text-xs"><?= e($errors['otp']) ?></p><?php endif; ?>
              </div>
              <?php endif; ?>
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
              <label for="selected_offer" class="block text-sm font-semibold text-maroon-dark mb-1">Choose offer / product</label>
              <select id="selected_offer" required
                class="w-full tap-target rounded-xl gold-border gold-ring px-3.5 py-3 bg-white">
                <option value="">Select product</option>
                <?php foreach ($offerProducts as $p): ?>
                  <?php if (empty($slotsByProduct[$p['product_key']])) continue; ?>
                  <option value="<?= e($p['product_key']) ?>"
                    <?= (($oldInput['selected_product'] ?? $oldInput['selected_offer'] ?? '') === $p['product_key']) ? 'selected' : '' ?>>
                    <?= e($p['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label for="offer_slot_id" class="block text-sm font-semibold text-maroon-dark mb-1">Choose date &amp; time slot</label>
              <select id="offer_slot_id" name="offer_slot_id" required
                class="w-full tap-target rounded-xl gold-border gold-ring px-3.5 py-3 bg-white">
                <option value="">Select product first</option>
              </select>
              <?php if (!empty($errors['offer_slot_id'])): ?><p class="text-red-600 text-xs mt-1"><?= e($errors['offer_slot_id']) ?></p><?php endif; ?>
              <p class="text-[11px] text-maroon-dark/55 mt-1">Select your preferred date and time.</p>
            </div>

            <div class="flex items-start gap-2.5 rounded-xl bg-ivory px-3 py-2.5">
              <input type="checkbox" id="consent" name="consent" value="1" required
                <?= !empty($oldInput['consent']) ? 'checked' : '' ?>
                class="mt-0.5 w-[18px] h-[18px] accent-[#7A0026] shrink-0">
              <label for="consent" class="text-xs sm:text-sm text-maroon-dark/85 leading-snug">
                I agree to the
                <a href="#terms-conditions" class="underline font-semibold text-maroon">Terms &amp; Conditions</a>
                and to receive my voucher &amp; offer updates on WhatsApp.
              </label>
            </div>
            <?php if (!empty($errors['consent'])): ?>
              <p class="text-red-600 text-xs -mt-2"><?= e($errors['consent']) ?></p>
            <?php endif; ?>

            <!-- Keep submit INSIDE the form so CSRF + fields always post (esp. on mobile). -->
            <div class="pt-1 sm:pt-0">
              <div class="hidden sm:block">
                <button type="submit"
                  class="w-full tap-target bg-maroon hover:bg-maroon-dark text-ivory font-bold py-3.5 rounded-xl gold-border shadow-md">
                  Get My Voucher
                </button>
              </div>
            </div>
          </form>
        </div>
      </section>

      <details id="terms-conditions" class="mt-3 px-1 text-xs text-maroon-dark/65">
        <summary class="font-semibold text-maroon-dark/80 cursor-pointer py-2">Terms &amp; Conditions — Please read carefully</summary>
        <ul class="list-disc list-inside space-y-1.5 pb-3 pl-0.5 leading-relaxed">
          <li><strong class="text-maroon-dark/80">One customer = one offer only.</strong> Each mobile number is eligible for one voucher across all current and past offer events.</li>
          <li><strong class="text-maroon-dark/80">No multiple offers per family.</strong> Multiple offers cannot be redeemed on the same day using different family mobile numbers. Duplicate registrations will be rejected, and only one offer will be confirmed.</li>
          <li><strong class="text-maroon-dark/80">Customer must be present</strong> with the registered mobile number and complete OTP verification at the counter.</li>
          <li>The offer is <strong class="text-maroon-dark/80">non-exchangeable and non-transferable.</strong></li>
          <li>Management reserves the right to <strong class="text-maroon-dark/80">cancel or modify</strong> the offer without prior notice.</li>
          <li>Please present the <strong class="text-maroon-dark/80">original WhatsApp voucher, QR code, or printed PDF</strong> at the counter for redemption.</li>
        </ul>
      </details>

      <div class="fixed inset-x-0 bottom-0 z-50 sm:hidden bg-white/95 backdrop-blur border-t border-gold/40 px-3.5 pt-2.5 safe-bottom shadow-[0_-8px_24px_rgba(82,0,24,0.1)]">
        <button type="submit" form="regForm"
          class="w-full tap-target bg-maroon active:bg-maroon-dark text-ivory font-bold text-[15px] py-3.5 rounded-xl gold-border">
          Get My Voucher on WhatsApp
        </button>
      </div>

    <?php endif; ?>
  <?php endif; ?>
</main>

<script>
  const slotsByProduct = <?= json_encode($slotsByProduct, JSON_UNESCAPED_UNICODE) ?>;
  const productSelect = document.getElementById('selected_offer');
  const slotSelect = document.getElementById('offer_slot_id');
  const preselectedSlot = <?= json_encode((string) ($oldInput['offer_slot_id'] ?? '')) ?>;

  function fillSlots() {
    if (!productSelect || !slotSelect) return;
    const key = productSelect.value;
    const slots = slotsByProduct[key] || [];
    slotSelect.innerHTML = '';
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = slots.length ? 'Select date & time slot' : (key ? 'No slots available for this product' : 'Select product first');
    slotSelect.appendChild(placeholder);
    slots.forEach((s) => {
      const opt = document.createElement('option');
      opt.value = String(s.id);
      opt.textContent = s.label;
      if (preselectedSlot && String(s.id) === String(preselectedSlot)) opt.selected = true;
      slotSelect.appendChild(opt);
    });
  }

  if (productSelect) {
    productSelect.addEventListener('change', fillSlots);
    fillSlots();
  }

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
        if (mobileInput.value !== digits) return;
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
        return;
      }
      const otpVerified = document.getElementById('otp_verified');
      if (otpVerified && otpVerified.value !== '1') {
        e.preventDefault();
        const otpMsg = document.getElementById('otp_msg');
        if (otpMsg) {
          otpMsg.textContent = 'Please verify OTP before submitting.';
          otpMsg.className = 'text-xs text-red-600 leading-snug';
        }
        document.getElementById('otp_code')?.focus();
      }
    });
  }

  // ---- OTP (SMS Alert) ----
  const sendOtpBtn = document.getElementById('send_otp_btn');
  const verifyOtpBtn = document.getElementById('verify_otp_btn');
  const otpInput = document.getElementById('otp_code');
  const otpMsg = document.getElementById('otp_msg');
  const otpVerifiedInput = document.getElementById('otp_verified');
  const csrfInput = document.querySelector('#regForm input[name="csrf_token"]');

  function setOtpMsg(text, ok) {
    if (!otpMsg) return;
    otpMsg.textContent = text || '';
    otpMsg.className = ok ? 'text-xs text-green-700 leading-snug' : 'text-xs text-red-600 leading-snug';
  }

  function resetOtpState() {
    if (otpVerifiedInput) otpVerifiedInput.value = '0';
    if (otpInput) otpInput.value = '';
    setOtpMsg('', true);
  }

  if (mobileInput) {
    mobileInput.addEventListener('input', () => {
      resetOtpState();
    });
  }

  if (sendOtpBtn) {
    sendOtpBtn.addEventListener('click', () => {
      const digits = (mobileInput?.value || '').replace(/\D/g, '');
      if (!/^[6-9][0-9]{9}$/.test(digits)) {
        setOtpMsg('Enter a valid 10-digit mobile first.', false);
        return;
      }
      if (mobileRegistered) {
        setOtpMsg('This number already has a voucher.', false);
        return;
      }
      sendOtpBtn.disabled = true;
      setOtpMsg('Sending OTP…', true);
      const body = new FormData();
      body.append('csrf_token', csrfInput ? csrfInput.value : '');
      body.append('mobile', digits);
      fetch('/send-otp.php', { method: 'POST', body, credentials: 'same-origin' })
        .then((r) => r.json())
        .then((data) => {
          if (data.ok) {
            setOtpMsg(data.message || 'OTP sent on SMS.', true);
            otpInput && otpInput.focus();
            let left = data.cooldown || 45;
            const tick = () => {
              if (left <= 0) {
                sendOtpBtn.disabled = false;
                sendOtpBtn.textContent = 'Resend OTP';
                return;
              }
              sendOtpBtn.textContent = 'Resend in ' + left + 's';
              left -= 1;
              setTimeout(tick, 1000);
            };
            tick();
          } else {
            sendOtpBtn.disabled = false;
            setOtpMsg(data.error || 'Could not send OTP.', false);
          }
        })
        .catch(() => {
          sendOtpBtn.disabled = false;
          setOtpMsg('Network error. Try again.', false);
        });
    });
  }

  if (verifyOtpBtn) {
    verifyOtpBtn.addEventListener('click', () => {
      const digits = (mobileInput?.value || '').replace(/\D/g, '');
      const code = (otpInput?.value || '').replace(/\D/g, '');
      if (!/^[6-9][0-9]{9}$/.test(digits)) {
        setOtpMsg('Enter a valid mobile number.', false);
        return;
      }
      if (code.length !== 6) {
        setOtpMsg('Enter the 6-digit OTP.', false);
        return;
      }
      verifyOtpBtn.disabled = true;
      const body = new FormData();
      body.append('csrf_token', csrfInput ? csrfInput.value : '');
      body.append('mobile', digits);
      body.append('otp', code);
      fetch('/verify-otp.php', { method: 'POST', body, credentials: 'same-origin' })
        .then((r) => r.json())
        .then((data) => {
          verifyOtpBtn.disabled = false;
          if (data.ok) {
            if (otpVerifiedInput) otpVerifiedInput.value = '1';
            setOtpMsg(data.message || 'Verified.', true);
            if (otpInput) otpInput.readOnly = true;
            if (sendOtpBtn) sendOtpBtn.disabled = true;
            verifyOtpBtn.disabled = true;
          } else {
            if (otpVerifiedInput) otpVerifiedInput.value = '0';
            setOtpMsg(data.error || 'Incorrect OTP.', false);
          }
        })
        .catch(() => {
          verifyOtpBtn.disabled = false;
          setOtpMsg('Network error. Try again.', false);
        });
    });
  }
</script>

<?php
$compactFooter = true;
require __DIR__ . '/../templates/footer.php';
?>
