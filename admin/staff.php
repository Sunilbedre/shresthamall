<?php
/**
 * admin/staff.php  ->  route: /admin/staff
 * Admin-only: create staff login accounts for the verify counter.
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

AuthService::requireRole('admin');

$flash = null;
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $flashError = 'Your session expired. Please try again.';
    } else {
        $username = (string) ($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'staff');
        $created = AuthService::createUser($username, $password, $role);
        if ($created['ok']) {
            AuditLog::record(AuthService::currentUsername() ?? 'admin', 'STAFF_CREATED', "username={$username} role={$role}", $_SERVER['REMOTE_ADDR'] ?? null);
            $flash = 'Account created: ' . strtolower(trim($username));
        } else {
            $flashError = $created['error'] ?? 'Could not create account.';
        }
    }
}

$users = AuthService::listUsers();
$activePage = 'staff';
$skipDefaultHeader = true;
$pageTitle = 'Staff & Sub-admin – Shreeshta Family Store';
require __DIR__ . '/../templates/header.php';
?>
<?php require __DIR__ . '/../templates/admin_nav.php'; ?>

<main class="flex-1 max-w-3xl mx-auto px-5 py-8">
  <h2 class="font-heading text-2xl font-bold text-maroon mb-2">Staff &amp; Sub-admin Accounts</h2>
  <p class="text-sm text-maroon-dark/70 mb-5">Create logins for the verify counter (staff) or registrations list only (sub-admin).</p>

  <?php if ($flash): ?>
    <p class="text-green-700 bg-green-50 border border-green-200 rounded-lg px-4 py-2 text-sm mb-4"><?= e($flash) ?></p>
  <?php endif; ?>
  <?php if ($flashError): ?>
    <p class="text-red-700 bg-red-50 border border-red-200 rounded-lg px-4 py-2 text-sm mb-4"><?= e($flashError) ?></p>
  <?php endif; ?>

  <form method="post" class="bg-white gold-border rounded-2xl p-5 shadow-sm space-y-4 mb-6">
    <?= csrf_field() ?>
    <h3 class="font-heading text-lg font-bold text-maroon">Create new login</h3>
    <div class="grid sm:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Username</label>
        <input type="text" name="username" required minlength="3" maxlength="40" pattern="[A-Za-z0-9._\-]+"
          class="w-full rounded-lg gold-border px-3 py-2" placeholder="e.g. staff1">
      </div>
      <div>
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Password</label>
        <input type="text" name="password" required minlength="12"
          class="w-full rounded-lg gold-border px-3 py-2" placeholder="Min 12 chars, upper+lower+number+symbol">
      </div>
      <div>
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Role</label>
        <select name="role" class="w-full rounded-lg gold-border px-3 py-2">
          <option value="staff" selected>Staff — verify &amp; redeem only</option>
          <option value="subadmin">Sub-admin — registrations list only (view)</option>
          <option value="admin">Admin — full access</option>
        </select>
      </div>
    </div>
    <button type="submit" class="bg-maroon text-ivory font-bold px-5 py-2.5 rounded-xl">Create Account</button>
  </form>

  <div class="bg-white gold-border rounded-2xl shadow-sm overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-maroon text-ivory">
        <tr>
          <th class="px-3 py-2.5 text-left">Username</th>
          <th class="px-3 py-2.5 text-left">Role</th>
          <th class="px-3 py-2.5 text-left">Last login</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr class="border-t border-gold/20">
            <td class="px-3 py-2.5 font-medium"><?= e($u['username']) ?></td>
            <td class="px-3 py-2.5"><?= e(match ($u['role']) {
              'admin' => 'Admin',
              'subadmin' => 'Sub-admin',
              'staff' => 'Staff',
              default => $u['role'],
            }) ?></td>
            <td class="px-3 py-2.5"><?= e($u['last_login_at'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>

<?php require __DIR__ . '/../templates/footer.php'; ?>
