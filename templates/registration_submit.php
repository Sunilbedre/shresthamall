<?php
/** templates/registration_submit.php — single action button (after consent). */
$otpEnabled = SmsAlertService::isEnabled();
?>
<?php if ($otpEnabled): ?>
  <button type="button" id="register_action_btn"
    class="hidden sm:flex w-full tap-target bg-maroon hover:bg-maroon-dark text-ivory font-bold py-3.5 rounded-xl gold-border shadow-md items-center justify-center disabled:opacity-50">
    Verify OTP to Register
  </button>
<?php else: ?>
  <button type="submit"
    class="hidden sm:block w-full tap-target bg-maroon hover:bg-maroon-dark text-ivory font-bold py-3.5 rounded-xl gold-border shadow-md">
    Get My Voucher
  </button>
<?php endif; ?>

<div class="fixed inset-x-0 bottom-0 z-50 sm:hidden bg-white/95 backdrop-blur border-t border-gold/40 px-3.5 pt-2.5 safe-bottom shadow-[0_-8px_24px_rgba(82,0,24,0.1)]">
  <?php if ($otpEnabled): ?>
    <button type="button" id="register_action_btn_mobile"
      class="w-full tap-target bg-maroon active:bg-maroon-dark text-ivory font-bold text-[15px] py-3.5 rounded-xl gold-border disabled:opacity-50">
      Verify OTP to Register
    </button>
  <?php else: ?>
    <button type="submit" form="regForm"
      class="w-full tap-target bg-maroon active:bg-maroon-dark text-ivory font-bold text-[15px] py-3.5 rounded-xl gold-border">
      Get My Voucher on WhatsApp
    </button>
  <?php endif; ?>
</div>

<script src="/js/register-flow.js" defer></script>
