(function () {
  const form = document.getElementById('regForm');
  if (!form) return;

  const otpEnabled = form.dataset.otpEnabled === '1';
  const campaignSlug = form.dataset.campaign || '';
  const mobileInput = document.getElementById('mobile_number');
  const mobileDupMsg = document.getElementById('mobile_dup_msg');
  const mobileWrap = document.getElementById('mobile_wrap');
  const otpInput = document.getElementById('otp_code');
  const otpMsg = document.getElementById('otp_msg');
  const otpHint = document.getElementById('otp_hint');
  const otpWrap = document.getElementById('otp_input_wrap');
  const otpVerifiedInput = document.getElementById('otp_verified');
  const otpSentInput = document.getElementById('otp_sent');
  const csrfInput = form.querySelector('input[name="csrf_token"]');
  const actionBtns = [
    document.getElementById('register_action_btn'),
    document.getElementById('register_action_btn_mobile'),
  ].filter(Boolean);

  let mobileRegistered = false;
  let otpSent = false;
  let busy = false;
  let resendLeft = 0;
  let resendTimer = null;

  function setOtpMsg(text, ok) {
    if (!otpMsg) return;
    otpMsg.textContent = text || '';
    otpMsg.className = 'text-sm text-center leading-snug min-h-[1.25rem] ' + (ok ? 'text-green-700' : 'text-red-600');
  }

  function setBtnText(text) {
    actionBtns.forEach((b) => { b.textContent = text; });
  }

  function setBtnDisabled(on) {
    actionBtns.forEach((b) => { b.disabled = on; });
  }

  function isDuplicateMessage(text) {
    const t = (text || '').toLowerCase();
    return t.includes('already') && (t.includes('registered') || t.includes('voucher') || t.includes('coupon'));
  }

  function setMobileDupState(registered, message) {
    mobileRegistered = registered;
    if (!mobileDupMsg) return;
    if (registered) {
      mobileDupMsg.textContent = message || 'This mobile number is already registered.';
      mobileDupMsg.classList.remove('hidden');
      mobileWrap && mobileWrap.classList.add('border-red-500');
      setOtpMsg('', true);
      setBtnDisabled(true);
    } else {
      mobileDupMsg.textContent = '';
      mobileDupMsg.classList.add('hidden');
      mobileWrap && mobileWrap.classList.remove('border-red-500');
      if (!busy) setBtnDisabled(false);
    }
  }

  function mobileDigits() {
    return (mobileInput?.value || '').replace(/\D/g, '').slice(0, 10);
  }

  let dupCheckTimer = null;
  let lastDupChecked = '';

  function checkMobileDuplicate(force) {
    const digits = mobileDigits();
    if (!/^[6-9][0-9]{9}$/.test(digits)) {
      lastDupChecked = '';
      setMobileDupState(false, '');
      return;
    }
    if (!force && digits === lastDupChecked) return;
    lastDupChecked = digits;

    let url = '/check-mobile.php?mobile=' + encodeURIComponent(digits);
    if (campaignSlug) url += '&campaign=' + encodeURIComponent(campaignSlug);
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => r.json())
      .then((data) => {
        if (mobileDigits() !== digits) return;
        setMobileDupState(!!data.registered, data.message || '');
      })
      .catch(() => {});
  }

  function resetOtpState() {
    otpSent = false;
    if (otpSentInput) otpSentInput.value = '0';
    if (otpVerifiedInput) otpVerifiedInput.value = '0';
    if (otpInput) {
      otpInput.value = '';
      otpInput.readOnly = false;
    }
    otpWrap && otpWrap.classList.add('hidden');
    if (otpHint) otpHint.textContent = 'Tap the button below — OTP will be sent and the field will open here.';
    setOtpMsg('', true);
    setBtnText('Verify OTP to Register');
    setBtnDisabled(false);
    if (resendTimer) clearInterval(resendTimer);
    resendLeft = 0;
  }

  function validateForm() {
    if (mobileRegistered) {
      setMobileDupState(true, mobileDupMsg?.textContent || '');
      mobileInput && mobileInput.focus();
      return false;
    }
    if (!form.reportValidity()) return false;
    const digits = mobileDigits();
    if (!/^[6-9][0-9]{9}$/.test(digits)) {
      setOtpMsg('Enter a valid 10-digit WhatsApp number.', false);
      mobileInput && mobileInput.focus();
      return false;
    }
    return true;
  }

  function startResendCooldown(sec) {
    resendLeft = sec || 45;
    setBtnDisabled(true);
    if (resendTimer) clearInterval(resendTimer);
    resendTimer = setInterval(() => {
      if (resendLeft <= 0) {
        clearInterval(resendTimer);
        setBtnDisabled(busy);
        setBtnText(otpSent ? 'Verify OTP to Register' : 'Verify OTP to Register');
        return;
      }
      setBtnText('Resend OTP in ' + resendLeft + 's');
      resendLeft -= 1;
    }, 1000);
  }

  function sendOtp() {
    const digits = mobileDigits();
    setBtnDisabled(true);
    setOtpMsg('Sending OTP…', true);
    const body = new FormData();
    body.append('csrf_token', csrfInput ? csrfInput.value : '');
    body.append('mobile', digits);
    if (campaignSlug) body.append('campaign', campaignSlug);
    return fetch('/send-otp.php', { method: 'POST', body, credentials: 'same-origin' })
      .then((r) => r.json())
      .then((data) => {
        if (!data.ok) {
          const err = data.error || 'Could not send OTP.';
          if (isDuplicateMessage(err)) {
            setMobileDupState(true, err);
          } else {
            setOtpMsg(err, false);
            setBtnDisabled(false);
            setBtnText('Verify OTP to Register');
          }
          return false;
        }
        otpSent = true;
        if (otpSentInput) otpSentInput.value = '1';
        otpWrap && otpWrap.classList.remove('hidden');
        if (otpHint) otpHint.textContent = 'OTP sent! Enter the 6-digit code below.';
        setOtpMsg(data.message || 'OTP sent to your mobile.', true);
        setBtnText('Verify OTP to Register');
        otpInput && otpInput.focus();
        startResendCooldown(data.cooldown || 45);
        return true;
      })
      .catch(() => {
        setOtpMsg('Network error. Try again.', false);
        setBtnDisabled(false);
        setBtnText('Verify OTP to Register');
        return false;
      });
  }

  function verifyOtp() {
    const digits = mobileDigits();
    const code = (otpInput?.value || '').replace(/\D/g, '');
    if (code.length !== 6) {
      setOtpMsg('Enter the 6-digit OTP.', false);
      return Promise.resolve(false);
    }
    busy = true;
    setBtnDisabled(true);
    setBtnText('Verifying…');
    const body = new FormData();
    body.append('csrf_token', csrfInput ? csrfInput.value : '');
    body.append('mobile', digits);
    body.append('otp', code);
    return fetch('/verify-otp.php', { method: 'POST', body, credentials: 'same-origin' })
      .then((r) => r.json())
      .then((data) => {
        if (!data.ok) {
          busy = false;
          setBtnDisabled(resendLeft > 0);
          setBtnText('Verify OTP to Register');
          if (otpVerifiedInput) otpVerifiedInput.value = '0';
          setOtpMsg(data.error || 'Incorrect OTP. Try again.', false);
          otpInput && otpInput.focus();
          return false;
        }
        if (otpVerifiedInput) otpVerifiedInput.value = '1';
        if (otpInput) otpInput.readOnly = true;
        setOtpMsg('Verified! Getting your voucher…', true);
        setBtnText('Please wait…');
        form.submit();
        return true;
      })
      .catch(() => {
        busy = false;
        setBtnDisabled(false);
        setBtnText('Verify OTP to Register');
        setOtpMsg('Network error. Try again.', false);
        return false;
      });
  }

  function onActionClick() {
    if (!otpEnabled || busy) return;
    if (!validateForm()) return;

    const code = (otpInput?.value || '').replace(/\D/g, '');

    if (!otpSent) {
      sendOtp();
      return;
    }

    if (code.length === 6) {
      verifyOtp();
      return;
    }

    if (resendLeft <= 0) {
      sendOtp();
      return;
    }

    setOtpMsg('Enter the 6-digit OTP sent to your mobile.', false);
    otpInput && otpInput.focus();
  }

  if (mobileInput) {
    mobileInput.addEventListener('input', () => {
      mobileInput.value = mobileInput.value.replace(/\D/g, '').slice(0, 10);
      resetOtpState();
      lastDupChecked = '';
      if (dupCheckTimer) clearTimeout(dupCheckTimer);
      const digits = mobileDigits();
      if (digits.length === 10) {
        checkMobileDuplicate(true);
      } else {
        setMobileDupState(false, '');
        dupCheckTimer = setTimeout(() => checkMobileDuplicate(false), 200);
      }
    });
    mobileInput.addEventListener('blur', () => checkMobileDuplicate(true));
    if (mobileDigits().length === 10) checkMobileDuplicate(true);
  }

  actionBtns.forEach((btn) => btn.addEventListener('click', onActionClick));

  if (otpInput) {
    otpInput.addEventListener('input', () => {
      otpInput.value = otpInput.value.replace(/\D/g, '').slice(0, 6);
      if (otpVerifiedInput) otpVerifiedInput.value = '0';
      if (otpInput.value.length === 6 && otpSent && !busy) {
        verifyOtp();
      }
    });
  }

  form.addEventListener('submit', (e) => {
    if (mobileRegistered) {
      e.preventDefault();
      return;
    }
    if (otpEnabled && otpVerifiedInput && otpVerifiedInput.value !== '1') {
      e.preventDefault();
      onActionClick();
    }
  });
})();
