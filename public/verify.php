<?php
/**
 * public/verify.php  ->  route: /verify
 * Staff counter: search customer → send OTP → verify OTP → redeem + print voucher.
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

AuthService::requireLogin();
if (AuthService::isSubAdmin()) {
    redirect(admin_url('registrations.php'));
}

$searchValue  = trim((string) ($_GET['q'] ?? ''));
$flash        = null;
$flashError   = null;
$result       = null;
$nameResults  = [];
$otpSent      = false;   // OTP has been sent for current mobile in this session
$otpChannel   = '';

/* ── helpers ───────────────────────────────────────────────────────────── */

function verify_lookup(string $q): array
{
    $voucher  = null;
    $asMobile = Validation::normaliseMobile($q);
    if ($asMobile !== null) {
        $customer = CustomerService::findByMobile($asMobile);
        if ($customer) {
            $voucher = VoucherModel::findByCustomerId((int) $customer['id']);
        }
    }
    if ($voucher === null && $q !== '') {
        $voucher = VoucherModel::findByCode($q);
    }
    if ($voucher) {
        $customer = CustomerService::findById((int) $voucher['customer_id']);
        return ['status' => VoucherService::checkStatus($voucher), 'voucher' => $voucher, 'customer' => $customer];
    }
    return ['status' => 'INVALID', 'voucher' => null, 'customer' => null];
}

function verify_name_search(string $name): array
{
    $pdo  = Database::connection();
    $stmt = $pdo->prepare("
        SELECT c.id, c.full_name, c.mobile_number, c.selected_product,
               v.voucher_code, v.status AS voucher_status
        FROM customers c
        LEFT JOIN vouchers v ON v.customer_id = c.id
        WHERE c.full_name LIKE :name
        ORDER BY c.id DESC LIMIT 20
    ");
    $stmt->execute(['name' => '%' . $name . '%']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ── GET search ─────────────────────────────────────────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $searchValue !== '') {
    $asMobile = Validation::normaliseMobile($searchValue);
    if ($asMobile !== null || preg_match('/^[A-Z]{3}/i', $searchValue)) {
        $result = verify_lookup($searchValue);
        if ($result['status'] === 'INVALID' && $asMobile === null) {
            $nameResults = verify_name_search($searchValue);
        }
    } else {
        $nameResults = verify_name_search($searchValue);
    }
}

/* ── POST actions ───────────────────────────────────────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $flashError = 'Session expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? 'search';

        /* search */
        if ($action === 'search') {
            $searchValue  = trim((string) ($_POST['query'] ?? ''));
            $asMobile     = Validation::normaliseMobile($searchValue);
            $looksLike    = $asMobile !== null || preg_match('/^\d/', $searchValue) || preg_match('/^[A-Z]{3}[0-9]/i', $searchValue);
            if ($looksLike) {
                $result = verify_lookup($searchValue);
            } else {
                $nameResults = verify_name_search($searchValue);
                if (count($nameResults) === 1) {
                    $result      = verify_lookup($nameResults[0]['mobile_number']);
                    $searchValue = $nameResults[0]['mobile_number'];
                    $nameResults = [];
                }
            }

        /* send OTP to customer */
        } elseif ($action === 'send_redeem_otp') {
            $mobile = Validation::normaliseMobile((string) ($_POST['mobile'] ?? ''));
            if ($mobile) {
                $r = OtpService::sendForRedemption($mobile, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
                if ($r['ok']) {
                    $flash      = 'OTP sent to customer\'s ' . ($r['channel'] === 'whatsapp' ? 'WhatsApp' : 'SMS') . '. Ask them to read it out.';
                    $otpChannel = $r['channel'] ?? '';
                } else {
                    $flashError = $r['error'] ?? 'Could not send OTP.';
                }
                $searchValue = $_POST['mobile_raw'] ?? $mobile;
                $result      = verify_lookup($mobile);
            }

        /* verify OTP entered by staff + redeem */
        } elseif ($action === 'verify_redeem_otp') {
            $mobile    = Validation::normaliseMobile((string) ($_POST['mobile'] ?? ''));
            $otpCode   = trim((string) ($_POST['otp_code'] ?? ''));
            $voucherId = (int) ($_POST['voucher_id'] ?? 0);
            $billingRef = trim((string) ($_POST['billing_reference'] ?? '')) ?: null;
            $allowOutside = !empty($_POST['allow_outside_window']);

            if (!$mobile) {
                $flashError = 'Invalid mobile number.';
            } else {
                $verifyResult = OtpService::verifyForRedemption($mobile, $otpCode);
                if (!$verifyResult['ok']) {
                    $flashError  = $verifyResult['error'];
                    $searchValue = $_POST['mobile_raw'] ?? $mobile;
                    $result      = verify_lookup($mobile);
                } else {
                    // OTP correct — redeem voucher
                    $redeemResult = VoucherModel::redeem($voucherId, AuthService::currentUsername() ?? 'staff', $billingRef, $allowOutside);
                    if ($redeemResult['ok']) {
                        OtpService::clearRedemption();
                        $voucher  = $redeemResult['voucher'];
                        $customer = CustomerService::findById((int) $voucher['customer_id']);
                        $result   = ['status' => 'REDEEMED_NOW', 'voucher' => $voucher, 'customer' => $customer];
                        $searchValue = $customer['mobile_number'] ?? '';
                        $flash    = 'OTP verified ✓ — voucher redeemed successfully!';
                    } else {
                        $flashError  = 'OTP correct, but redemption failed: ' . str_replace('_', ' ', $redeemResult['error'] ?? '');
                        $searchValue = $_POST['mobile_raw'] ?? $mobile;
                        $result      = verify_lookup($mobile);
                    }
                }
            }

        /* resend WhatsApp voucher */
        } elseif ($action === 'resend_whatsapp') {
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            $customer   = $customerId ? CustomerService::findById($customerId) : null;
            if ($customer) {
                $voucher = VoucherModel::findByCustomerId($customerId);
                if ($voucher) {
                    $send  = WhatsAppService::sendVoucher($customer, $voucher);
                    $flash = $send['ok'] ? 'Voucher resent to WhatsApp.' : 'WhatsApp send failed.';
                    $result      = verify_lookup($customer['mobile_number']);
                    $searchValue = $customer['mobile_number'];
                }
            }
        }
    }
}

/* ── OTP state for current search ─────────────────────────────────────── */
if ($result && ($result['customer'] ?? null)) {
    $mobile   = $result['customer']['mobile_number'] ?? '';
    $otpState = $_SESSION['redeem_otp'] ?? null;
    $otpSent  = is_array($otpState) && ($otpState['mobile'] ?? '') === $mobile
                && (int) ($otpState['expires'] ?? 0) > time()
                && empty($otpState['verified']);
    $otpChannel = $otpState['channel'] ?? '';
}

/* ── status styles ──────────────────────────────────────────────────────── */
$statusStyles = [
    'VALID'            => ['label' => '✓ Valid — ready to redeem',         'color' => 'green'],
    'NOT_ACTIVE_YET'   => ['label' => '⏳ Not active yet (before slot)',    'color' => 'yellow'],
    'TIME_EXPIRED'     => ['label' => '⌛ Time slot expired',               'color' => 'yellow'],
    'ALREADY_REDEEMED' => ['label' => '✗ Already redeemed',                'color' => 'red'],
    'REDEEMED_NOW'     => ['label' => '✓ Redeemed just now!',              'color' => 'green'],
    'BLOCKED'          => ['label' => '✗ Voucher blocked',                 'color' => 'red'],
    'CANCELLED'        => ['label' => '✗ Voucher cancelled',               'color' => 'red'],
    'INVALID'          => ['label' => 'No voucher found',                  'color' => 'gray'],
];

$pageTitle    = 'Staff Verify — Shreeshta Family Store';
$compactHeader = true;
require __DIR__ . '/../templates/header.php';
?>

<style>
@media print {
  @page { size: A4; margin: 12mm; }
  header, footer, main, .no-print { display: none !important; }
  body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
  #printVoucher { display: block !important; }
  #printVoucher * { visibility: visible !important; }
}
</style>

<main class="max-w-md mx-auto px-3.5 sm:px-4 pt-3 pb-10 no-print">

  <!-- Header -->
  <div class="flex items-center justify-between mb-4 gap-2">
    <div>
      <h2 class="font-heading text-xl font-bold text-maroon">Staff Counter</h2>
      <p class="text-xs text-maroon-dark/60">Logged in as <strong><?= e(AuthService::currentUsername() ?? '') ?></strong> (<?= e(AuthService::currentRole() ?? '') ?>)</p>
    </div>
    <div class="flex gap-3 text-sm">
      <?php if (AuthService::isAdmin()): ?>
        <a href="<?= e(admin_url('dashboard.php')) ?>" class="text-maroon underline">Admin</a>
      <?php endif; ?>
      <a href="<?= e(admin_url('logout.php')) ?>" class="text-maroon-dark/60 underline">Logout</a>
    </div>
  </div>

  <!-- Search -->
  <form method="post" class="bg-white gold-border rounded-2xl p-4 shadow-sm mb-4 space-y-3">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="search">
    <label class="block text-sm font-semibold text-maroon-dark">Search customer</label>
    <input type="search" name="query" id="searchQuery" required value="<?= e($searchValue) ?>"
      autocomplete="off" autocorrect="off" spellcheck="false"
      placeholder="Name · Mobile number · Voucher code"
      class="w-full tap-target rounded-xl gold-border gold-ring px-4 py-3 text-base">
    <p class="text-[11px] text-maroon-dark/50 -mt-1">Type name, 10-digit mobile, or voucher code</p>
    <button type="submit" class="w-full tap-target bg-maroon text-ivory font-bold rounded-xl py-3">
      🔍 Search
    </button>
  </form>

  <!-- Name results list -->
  <?php if ($nameResults): ?>
  <div class="bg-white gold-border rounded-2xl p-4 shadow-sm mb-4">
    <p class="text-xs font-semibold text-maroon-dark/70 mb-3"><?= count($nameResults) ?> result(s) — tap a row to load:</p>
    <div class="space-y-2">
      <?php foreach ($nameResults as $nr): ?>
        <a href="?q=<?= urlencode($nr['mobile_number']) ?>"
          class="flex items-center justify-between gap-2 rounded-xl border border-gold/40 px-3 py-2.5 hover:bg-ivory active:bg-gold/10 transition-colors">
          <div>
            <p class="font-semibold text-sm text-maroon-dark"><?= e($nr['full_name']) ?></p>
            <p class="text-xs text-maroon-dark/60"><?= e($nr['mobile_number']) ?> · <?= e(Products::label($nr['selected_product'])) ?></p>
          </div>
          <span class="text-xs font-bold px-2 py-1 rounded-lg <?= ($nr['voucher_status'] ?? '') === 'REDEEMED' ? 'bg-red-100 text-red-700' : (($nr['voucher_status'] ?? '') === 'ACTIVE' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600') ?>">
            <?= e($nr['voucher_status'] ?? 'N/A') ?>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Flash messages -->
  <?php if ($flashError): ?>
    <p class="text-red-700 bg-red-50 border border-red-200 rounded-xl px-3 py-2.5 text-sm mb-3"><?= e($flashError) ?></p>
  <?php endif; ?>
  <?php if ($flash): ?>
    <p class="text-green-800 bg-green-50 border border-green-200 rounded-xl px-3 py-2.5 text-sm mb-3 font-semibold"><?= e($flash) ?></p>
  <?php endif; ?>

  <!-- Result card -->
  <?php if ($result): ?>
  <?php
    $status  = $result['status'];
    $style   = $statusStyles[$status] ?? $statusStyles['INVALID'];
    $colorMap = [
      'green'  => 'bg-green-50 border-green-400',
      'yellow' => 'bg-yellow-50 border-yellow-400',
      'red'    => 'bg-red-50 border-red-400',
      'gray'   => 'bg-gray-50 border-gray-300',
    ];
    $colorCls = $colorMap[$style['color']] ?? $colorMap['gray'];
    $voucher  = $result['voucher'];
    $customer = $result['customer'];
    $canRedeem = $customer && $voucher
        && in_array($status, ['VALID', 'NOT_ACTIVE_YET', 'TIME_EXPIRED', 'REDEEMED_NOW'], true)
        && in_array($voucher['status'] ?? '', ['ACTIVE'], true);
    $needsOverride = $canRedeem && !in_array($status, ['VALID'], true);
    $justRedeemed  = $status === 'REDEEMED_NOW';
  ?>

  <div class="rounded-2xl border-2 <?= $colorCls ?> p-4 mb-4">
    <h3 class="font-heading text-lg font-bold mb-3 <?= $style['color'] === 'green' ? 'text-green-900' : ($style['color'] === 'red' ? 'text-red-900' : 'text-maroon-dark') ?>">
      <?= e($style['label']) ?>
    </h3>

    <?php if ($customer && $voucher): ?>
    <!-- Customer details -->
    <div class="space-y-1.5 text-sm mb-4 bg-white/70 rounded-xl p-3">
      <div class="flex justify-between gap-2"><span class="opacity-60">Name</span><span class="font-bold text-right"><?= e($customer['full_name']) ?></span></div>
      <div class="flex justify-between gap-2"><span class="opacity-60">Mobile</span><span class="font-semibold text-right"><?= e($customer['mobile_number']) ?></span></div>
      <?php if (!empty($customer['area'])): ?>
      <div class="flex justify-between gap-2"><span class="opacity-60">Area</span><span class="font-semibold text-right"><?= e($customer['area']) ?></span></div>
      <?php endif; ?>
      <div class="flex justify-between gap-2"><span class="opacity-60">Product</span><span class="font-semibold text-right"><?= e(Products::label($customer['selected_product'])) ?></span></div>
      <div class="flex justify-between gap-2"><span class="opacity-60">Date</span><span class="font-semibold text-right"><?= e((new DateTimeImmutable($voucher['event_date']))->format('d M Y')) ?></span></div>
      <div class="flex justify-between gap-2"><span class="opacity-60">Slot</span><span class="font-semibold text-right"><?= e(VoucherService::formatVoucherTimeLabel($voucher)) ?></span></div>
      <div class="flex justify-between gap-2"><span class="opacity-60">Voucher</span><span class="font-mono font-bold text-right tracking-wider"><?= e($voucher['voucher_code']) ?></span></div>
      <?php if ($status === 'ALREADY_REDEEMED' && $voucher['redeemed_at']): ?>
      <div class="flex justify-between gap-2 text-red-700"><span class="opacity-80">Redeemed</span><span class="font-semibold text-right"><?= e($voucher['redeemed_at']) ?> by <?= e($voucher['redeemed_by'] ?? '-') ?></span></div>
      <?php endif; ?>
    </div>

    <!-- Resend WhatsApp -->
    <form method="post" class="mb-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="resend_whatsapp">
      <input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>">
      <button type="submit" class="w-full tap-target border-2 border-maroon/40 text-maroon font-semibold rounded-xl py-2 text-sm flex items-center justify-center gap-2 hover:bg-maroon/5">
        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.558 4.114 1.528 5.845L.057 23.857l6.188-1.623A11.945 11.945 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.894a9.886 9.886 0 01-5.031-1.378l-.361-.214-3.741.981.999-3.648-.235-.374A9.861 9.861 0 012.108 12c0-5.458 4.434-9.893 9.892-9.893 5.457 0 9.892 4.435 9.892 9.893 0 5.458-4.435 9.894-9.892 9.894z"/></svg>
        Resend Voucher on WhatsApp
      </button>
    </form>

    <?php if ($justRedeemed): ?>
      <!-- Print voucher strip -->
      <button onclick="window.print()"
        class="w-full tap-target bg-maroon text-ivory font-bold rounded-xl py-3 mb-2 flex items-center justify-center gap-2">
        🖨️ Print Voucher Receipt
      </button>
    <?php endif; ?>

    <?php if ($canRedeem && !$justRedeemed): ?>
      <!-- Step 1: Send OTP -->
      <div class="bg-white rounded-xl border border-gold/40 p-3 mb-3">
        <p class="text-sm font-semibold text-maroon-dark mb-2">Step 1 — Send OTP to customer</p>
        <p class="text-xs text-maroon-dark/60 mb-3">An OTP will be sent to the customer's WhatsApp/SMS. Ask them to read it out.</p>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send_redeem_otp">
          <input type="hidden" name="mobile" value="<?= e($customer['mobile_number']) ?>">
          <input type="hidden" name="mobile_raw" value="<?= e($searchValue) ?>">
          <?php if ($needsOverride): ?>
            <label class="flex items-start gap-2 text-xs text-yellow-800 bg-yellow-50 rounded-lg px-3 py-2 mb-2">
              <input type="checkbox" name="allow_outside_window" value="1" class="mt-0.5 accent-[#7A0026]">
              <span>Time slot not active — allow staff override</span>
            </label>
          <?php endif; ?>
          <button type="submit"
            class="w-full tap-target bg-maroon text-ivory font-bold rounded-xl py-2.5 text-sm <?= $otpSent ? 'opacity-60' : '' ?>">
            <?= $otpSent ? '✓ OTP Sent — Resend OTP' : '📲 Send OTP to Customer' ?>
          </button>
        </form>
      </div>

      <!-- Step 2: Enter OTP -->
      <?php if ($otpSent): ?>
      <div class="bg-white rounded-xl border-2 border-maroon/30 p-3">
        <p class="text-sm font-semibold text-maroon-dark mb-1">Step 2 — Enter OTP from customer</p>
        <p class="text-xs text-maroon-dark/60 mb-3">
          OTP sent via <?= $otpChannel === 'whatsapp' ? 'WhatsApp' : 'SMS' ?>.
          Ask the customer to read the 6-digit code.
        </p>
        <form method="post" class="space-y-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="verify_redeem_otp">
          <input type="hidden" name="mobile" value="<?= e($customer['mobile_number']) ?>">
          <input type="hidden" name="mobile_raw" value="<?= e($searchValue) ?>">
          <input type="hidden" name="voucher_id" value="<?= (int) $voucher['id'] ?>">
          <input type="hidden" name="billing_reference" value="">
          <?php if ($needsOverride): ?>
            <input type="hidden" name="allow_outside_window" value="1">
          <?php endif; ?>

          <input type="tel" name="otp_code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}"
            placeholder="Enter 6-digit OTP"
            autocomplete="one-time-code"
            class="w-full tap-target rounded-xl gold-border gold-ring px-4 py-3 text-xl text-center font-bold tracking-widest"
            autofocus required>

          <button type="submit"
            class="w-full tap-target bg-green-700 hover:bg-green-800 text-white font-bold rounded-xl py-3 text-base">
            ✓ Verify OTP &amp; Confirm Redemption
          </button>
        </form>
      </div>
      <?php else: ?>
      <p class="text-xs text-center text-maroon-dark/50 mt-1">Send OTP first to enable redemption</p>
      <?php endif; ?>
    <?php elseif ($status === 'ALREADY_REDEEMED'): ?>
      <p class="text-sm font-bold text-center text-red-700 py-2">⛔ Already redeemed — do not give another product.</p>
    <?php elseif (in_array($status, ['BLOCKED','CANCELLED'], true)): ?>
      <p class="text-sm font-bold text-center py-2">This voucher cannot be redeemed.</p>
    <?php endif; ?>

    <?php endif; ?><!-- end customer && voucher -->
  </div>
  <?php endif; ?><!-- end result -->

</main>

<!-- ═══════════════════════════════════════════════════════════════════════
     PRINT VOUCHER — only visible on print
     ═════════════════════════════════════════════════════════════════════ -->
<?php if (($result['status'] ?? '') === 'REDEEMED_NOW' && ($result['customer'] ?? null) && ($result['voucher'] ?? null)):
  $pv = $result['voucher'];
  $pc = $result['customer'];
  $storeName = Settings::get('store_name') . ', ' . Settings::get('branch_name');
?>
<div id="printVoucher" style="display:none; font-family: Arial, Helvetica, sans-serif; color:#111; width:100%; max-width:180mm; margin:0 auto; border:1px solid #222; border-radius:8px; padding:10mm; box-sizing:border-box;">
  <div style="text-align:center; border-bottom:1px solid #ddd; padding-bottom:8px; margin-bottom:12px;">
    <div style="font-size:12px; letter-spacing:1px; text-transform:uppercase; color:#666; font-weight:700;">Shreeshta Family Store</div>
    <div style="font-size:13px; color:#333; margin-top:4px;"><?= e($storeName) ?></div>
    <div style="font-size:11px; color:#666; margin-top:4px;">Redeemed Voucher Receipt</div>
  </div>

  <div style="text-align:center; border:1px solid #ddd; border-radius:6px; padding:10px; margin-bottom:12px;">
    <div style="font-size:34px; line-height:1.1; font-weight:800; letter-spacing:2px; color:#7A0026;"><?= e($pv['voucher_code']) ?></div>
    <div style="font-size:11px; color:#666; margin-top:4px;">Voucher Code</div>
  </div>

  <table style="width:100%; border-collapse:collapse; font-size:14px; margin-bottom:12px;">
    <tr><td style="padding:6px 0; color:#666; width:35%;">Name</td><td style="padding:6px 0; font-weight:700;"><?= e($pc['full_name']) ?></td></tr>
    <tr><td style="padding:6px 0; color:#666;">Mobile</td><td style="padding:6px 0; font-weight:700;"><?= e($pc['mobile_number']) ?></td></tr>
    <tr><td style="padding:6px 0; color:#666;">Product</td><td style="padding:6px 0; font-weight:700;"><?= e(Products::label($pc['selected_product'])) ?></td></tr>
    <tr><td style="padding:6px 0; color:#666;">Offer Date</td><td style="padding:6px 0; font-weight:700;"><?= e((new DateTimeImmutable($pv['event_date']))->format('d M Y')) ?></td></tr>
    <tr><td style="padding:6px 0; color:#666;">Time Slot</td><td style="padding:6px 0; font-weight:700;"><?= e(VoucherService::formatVoucherTimeLabel($pv)) ?></td></tr>
    <tr><td style="padding:6px 0; color:#666;">Redeemed At</td><td style="padding:6px 0; font-weight:700;"><?= e($pv['redeemed_at'] ?? date('Y-m-d H:i')) ?></td></tr>
    <tr><td style="padding:6px 0; color:#666;">Staff</td><td style="padding:6px 0; font-weight:700;"><?= e($pv['redeemed_by'] ?? AuthService::currentUsername() ?? '') ?></td></tr>
  </table>

  <div style="text-align:center; border-top:1px solid #ddd; padding-top:10px;">
    <span style="display:inline-block; background:#e9f9ef; color:#0d6b33; border:1px solid #b9e7c8; padding:6px 14px; border-radius:999px; font-size:13px; font-weight:700;">REDEEMED</span>
  </div>
</div>
<?php endif; ?>

<?php
$compactFooter = true;
require __DIR__ . '/../templates/footer.php';
?>
