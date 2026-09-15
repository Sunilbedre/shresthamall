<?php
/** templates/registration_otp.php — OTP block (include after mobile + other fields, before consent). */
if (!SmsAlertService::isEnabled()) {
    return;
}
?>
<div id="otp_block" class="rounded-xl bg-ivory gold-border px-3.5 py-4 space-y-3">
  <div>
    <p class="text-sm font-semibold text-maroon-dark">Verify OTP to register</p>
    <p class="text-xs text-maroon-dark/60 mt-0.5 leading-snug">
      Enter your WhatsApp number above, then verify the OTP sent to your mobile to complete registration.
    </p>
  </div>

  <button type="button" id="send_otp_btn"
    class="w-full tap-target px-4 py-3 rounded-xl bg-maroon text-ivory text-sm font-bold gold-border disabled:opacity-50">
    Send OTP
  </button>

  <div>
    <label for="otp_code" class="block text-xs font-semibold text-maroon-dark mb-1">Enter 6-digit OTP</label>
    <input type="tel" id="otp_code" name="otp_code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}"
      autocomplete="one-time-code"
      placeholder="6-digit OTP"
      class="w-full tap-target rounded-xl gold-border gold-ring px-3.5 py-3 text-center text-lg tracking-[0.3em] font-bold">
  </div>

  <button type="button" id="verify_otp_btn"
    class="w-full tap-target px-4 py-3 rounded-xl bg-gold text-maroon-dark text-sm font-bold gold-border disabled:opacity-50">
    Verify OTP &amp; continue
  </button>

  <p id="otp_msg" class="text-xs text-maroon-dark/70 leading-snug text-center"></p>
  <input type="hidden" id="otp_verified" name="otp_verified" value="<?= !empty($oldInput['otp_verified']) ? '1' : '0' ?>">
  <?php if (!empty($errors['otp'])): ?><p class="text-red-600 text-xs text-center"><?= e($errors['otp']) ?></p><?php endif; ?>
</div>
