<?php
/** templates/registration_otp.php — OTP field (hidden until OTP is sent). */
if (!SmsAlertService::isEnabled()) {
    return;
}
?>
<div id="otp_block" class="rounded-xl bg-ivory gold-border px-3.5 py-4 space-y-2">
  <p class="text-sm font-semibold text-maroon-dark text-center">Verify OTP to register</p>
  <p id="otp_hint" class="text-xs text-maroon-dark/60 text-center leading-snug">
    Tap the button below — OTP will be sent and the field will open here.
  </p>

  <div id="otp_input_wrap" class="hidden space-y-1 pt-1">
    <label for="otp_code" class="block text-xs font-semibold text-maroon-dark text-center">Enter 6-digit OTP</label>
    <input type="tel" id="otp_code" name="otp_code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}"
      autocomplete="one-time-code"
      placeholder="· · · · · ·"
      class="w-full tap-target rounded-xl gold-border gold-ring px-3.5 py-3 text-center text-xl tracking-[0.45em] font-bold">
  </div>

  <p id="otp_msg" class="text-xs text-center leading-snug min-h-[1rem]"></p>
  <input type="hidden" id="otp_verified" name="otp_verified" value="<?= !empty($oldInput['otp_verified']) ? '1' : '0' ?>">
  <input type="hidden" id="otp_sent" value="0">
  <?php if (!empty($errors['otp'])): ?><p class="text-red-600 text-xs text-center"><?= e($errors['otp']) ?></p><?php endif; ?>
</div>
