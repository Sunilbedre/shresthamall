<?php
/**
 * public/verify.php  ->  route: /verify
 * Staff/admin counter: search by mobile or voucher code, mark redeemed.
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

AuthService::requireLogin();
if (AuthService::isSubAdmin()) {
    redirect(admin_url('registrations.php'));
}
$searchValue = trim((string) ($_GET['q'] ?? ''));
$redeemMessage = null;
$redeemError = null;

function verify_lookup(string $searchValue): array
{
    $voucher = null;
    $asMobile = Validation::normaliseMobile($searchValue);
    if ($asMobile !== null) {
        $customer = CustomerService::findByMobile($asMobile);
        if ($customer) {
            $voucher = VoucherModel::findByCustomerId((int) $customer['id']);
        }
    }
    if ($voucher === null && $searchValue !== '') {
        $voucher = VoucherModel::findByCode($searchValue);
    }

    if ($voucher) {
        $customer = CustomerService::findById((int) $voucher['customer_id']);
        $status = VoucherService::checkStatus($voucher);
        return ['status' => $status, 'voucher' => $voucher, 'customer' => $customer];
    }
    return ['status' => 'INVALID', 'voucher' => null, 'customer' => null];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $searchValue !== '') {
    $result = verify_lookup($searchValue);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $redeemError = 'Your session expired. Please search again.';
    } else {
        $action = $_POST['action'] ?? 'search';

        if ($action === 'search') {
            $searchValue = trim((string) ($_POST['query'] ?? ''));
            $result = verify_lookup($searchValue);
        } elseif ($action === 'redeem') {
            $voucherId = (int) ($_POST['voucher_id'] ?? 0);
            $billingRef = trim((string) ($_POST['billing_reference'] ?? '')) ?: null;
            $confirmed = !empty($_POST['confirm_redeem']);
            $allowOutside = !empty($_POST['allow_outside_window']);

            if (!$confirmed) {
                $redeemError = 'Please tick “Mark as redeemed” to confirm.';
                $voucher = VoucherModel::findById($voucherId);
                $customer = $voucher ? CustomerService::findById((int) $voucher['customer_id']) : null;
                $status = $voucher ? VoucherService::checkStatus($voucher) : 'INVALID';
                $result = ['status' => $status, 'voucher' => $voucher, 'customer' => $customer];
                if ($customer) {
                    $searchValue = $customer['mobile_number'];
                }
            } else {
                $redeemResult = VoucherModel::redeem(
                    $voucherId,
                    AuthService::currentUsername() ?? 'staff',
                    $billingRef,
                    $allowOutside
                );

                if ($redeemResult['ok']) {
                    $redeemMessage = 'Voucher marked as redeemed successfully.';
                    $voucher = $redeemResult['voucher'];
                    $customer = CustomerService::findById((int) $voucher['customer_id']);
                    $result = ['status' => 'ALREADY_REDEEMED', 'voucher' => $voucher, 'customer' => $customer];
                    $searchValue = $customer['mobile_number'] ?? $searchValue;
                } else {
                    $voucher = VoucherModel::findById($voucherId);
                    $customer = $voucher ? CustomerService::findById((int) $voucher['customer_id']) : null;
                    $status = $voucher ? VoucherService::checkStatus($voucher) : 'INVALID';
                    $result = ['status' => $status, 'voucher' => $voucher, 'customer' => $customer];
                    $redeemError = 'Could not redeem: ' . str_replace('_', ' ', $redeemResult['error']);
                    if ($customer) {
                        $searchValue = $customer['mobile_number'];
                    }
                }
            }
        }
    }
}

$statusStyles = [
    'VALID'            => ['label' => 'Valid — ready to redeem', 'color' => 'green'],
    'NOT_ACTIVE_YET'   => ['label' => 'Not active yet (before time slot)', 'color' => 'yellow'],
    'TIME_EXPIRED'     => ['label' => 'Time slot expired', 'color' => 'red'],
    'ALREADY_REDEEMED' => ['label' => 'Already redeemed', 'color' => 'red'],
    'BLOCKED'          => ['label' => 'Voucher blocked', 'color' => 'red'],
    'CANCELLED'        => ['label' => 'Voucher cancelled', 'color' => 'red'],
    'INVALID'          => ['label' => 'No voucher found', 'color' => 'gray'],
];

$pageTitle = 'Staff Verify Counter – Shreeshta Family Store';
$compactHeader = true;
$eventDateFormatted = Settings::get('event_date')
    ? (new DateTimeImmutable(Settings::get('event_date')))->format('d M Y')
    : '';
require __DIR__ . '/../templates/header.php';
?>

<main class="max-w-md mx-auto px-3.5 sm:px-4 pt-3 pb-8">
  <div class="flex items-center justify-between mb-4 gap-2">
    <div>
      <h2 class="font-heading text-xl font-bold text-maroon">Staff Verify</h2>
      <p class="text-xs text-maroon-dark/60">Logged in as <?= e(AuthService::currentUsername() ?? '') ?> (<?= e(AuthService::currentRole() ?? '') ?>)</p>
    </div>
    <div class="flex gap-2 text-sm">
      <?php if (AuthService::isAdmin()): ?>
        <a href="<?= e(admin_url('dashboard.php')) ?>" class="text-maroon underline">Admin</a>
      <?php endif; ?>
      <a href="<?= e(admin_url('logout.php')) ?>" class="text-maroon-dark/70 underline">Logout</a>
    </div>
  </div>

  <form method="post" class="bg-white gold-border rounded-2xl p-4 shadow-sm mb-4 space-y-3">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="search">
    <label class="block text-sm font-semibold text-maroon-dark">Search by mobile number</label>
    <input type="tel" name="query" required value="<?= e($searchValue) ?>"
      inputmode="numeric" autocomplete="tel"
      placeholder="10-digit mobile or voucher code"
      class="w-full tap-target rounded-xl gold-border gold-ring px-4 py-3">
    <button type="submit" class="w-full tap-target bg-maroon text-ivory font-bold rounded-xl">
      Search Customer
    </button>
  </form>

  <?php if ($redeemError): ?>
    <p class="text-red-700 bg-red-50 border border-red-200 rounded-xl px-3 py-2.5 text-sm mb-3"><?= e($redeemError) ?></p>
  <?php endif; ?>
  <?php if ($redeemMessage): ?>
    <p class="text-green-800 bg-green-50 border border-green-200 rounded-xl px-3 py-2.5 text-sm mb-3 font-semibold"><?= e($redeemMessage) ?></p>
  <?php endif; ?>

  <?php if ($result): ?>
    <?php
      $style = $statusStyles[$result['status']] ?? $statusStyles['INVALID'];
      $colorClasses = [
        'green'  => 'bg-green-50 border-green-300 text-green-900',
        'yellow' => 'bg-yellow-50 border-yellow-300 text-yellow-900',
        'red'    => 'bg-red-50 border-red-300 text-red-900',
        'gray'   => 'bg-gray-50 border-gray-300 text-gray-700',
      ][$style['color']];
      $voucher = $result['voucher'];
      $customer = $result['customer'];
      $canRedeemBase = $customer && $voucher && in_array($result['status'], ['VALID', 'NOT_ACTIVE_YET', 'TIME_EXPIRED'], true)
          && ($voucher['status'] ?? '') === 'ACTIVE';
      $needsOutsideConfirm = $canRedeemBase && $result['status'] !== 'VALID';
    ?>
    <div class="rounded-2xl border-2 p-4 <?= $colorClasses ?>">
      <h3 class="font-heading text-lg font-bold mb-3"><?= e($style['label']) ?></h3>

      <?php if ($customer && $voucher): ?>
        <div class="space-y-2 text-sm mb-4 bg-white/60 rounded-xl p-3">
          <div class="flex justify-between gap-2"><span class="opacity-70">Customer</span><span class="font-semibold text-right"><?= e($customer['full_name']) ?></span></div>
          <div class="flex justify-between gap-2"><span class="opacity-70">Mobile</span><span class="font-semibold text-right"><?= e($customer['mobile_number']) ?></span></div>
          <?php if (!empty($customer['area'])): ?>
            <div class="flex justify-between gap-2"><span class="opacity-70">Area</span><span class="font-semibold text-right"><?= e($customer['area']) ?></span></div>
          <?php endif; ?>
          <div class="flex justify-between gap-2"><span class="opacity-70">Product</span><span class="font-semibold text-right"><?= e(Products::label($customer['selected_product'])) ?></span></div>
          <div class="flex justify-between gap-2"><span class="opacity-70">Offer Date</span><span class="font-semibold text-right"><?= e((new DateTimeImmutable($voucher['event_date']))->format('d M Y')) ?></span></div>
          <div class="flex justify-between gap-2"><span class="opacity-70">Time Slot</span><span class="font-semibold text-right"><?= e(VoucherService::formatSessionLabel($customer['session'])) ?></span></div>
          <div class="flex justify-between gap-2"><span class="opacity-70">Voucher</span><span class="font-mono font-bold text-right"><?= e($voucher['voucher_code']) ?></span></div>
          <?php if ($result['status'] === 'ALREADY_REDEEMED' && $voucher['redeemed_at']): ?>
            <div class="flex justify-between gap-2"><span class="opacity-70">Redeemed</span><span class="font-semibold text-right"><?= e($voucher['redeemed_at']) ?> by <?= e($voucher['redeemed_by'] ?? '-') ?></span></div>
          <?php endif; ?>
        </div>

        <?php if ($canRedeemBase): ?>
          <form method="post" class="space-y-3" id="redeemForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="redeem">
            <input type="hidden" name="voucher_id" value="<?= (int) $voucher['id'] ?>">

            <input type="text" name="billing_reference" placeholder="Billing reference (optional)"
              class="w-full rounded-xl border border-current/25 px-3 py-2.5 text-sm bg-white">

            <?php if ($needsOutsideConfirm): ?>
              <label class="flex items-start gap-2.5 text-sm bg-white/70 rounded-xl px-3 py-2.5">
                <input type="checkbox" name="allow_outside_window" value="1" class="mt-0.5 w-5 h-5 accent-[#7A0026]" id="outsideChk">
                <span>Time slot is not active now — still allow redeem (staff override)</span>
              </label>
            <?php endif; ?>

            <label class="flex items-start gap-2.5 text-sm bg-white rounded-xl px-3 py-3 border border-current/20 font-semibold">
              <input type="checkbox" name="confirm_redeem" value="1" required class="mt-0.5 w-5 h-5 accent-[#7A0026]" id="confirmChk">
              <span>Mark as redeemed — customer is taking the ₹1 product now</span>
            </label>

            <button type="submit" id="redeemBtn"
              class="w-full tap-target bg-maroon text-ivory font-bold rounded-xl">
              Confirm Redeem
            </button>
          </form>
        <?php elseif ($result['status'] === 'ALREADY_REDEEMED'): ?>
          <p class="text-sm font-semibold text-center py-2">This voucher is already used. Do not give another product.</p>
        <?php elseif (in_array($result['status'], ['BLOCKED', 'CANCELLED'], true)): ?>
          <p class="text-sm font-semibold text-center py-2">This voucher cannot be redeemed.</p>
        <?php endif; ?>
      <?php else: ?>
        <p class="text-sm">No registration matches this mobile number or voucher code.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>

<?php
$compactFooter = true;
require __DIR__ . '/../templates/footer.php';
?>
