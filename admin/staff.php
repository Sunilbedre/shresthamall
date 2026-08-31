<?php
/**
 * admin/staff.php  ->  route: /admin/staff
 * Admin-only: create, edit, and delete staff / sub-admin / admin logins.
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

AuthService::requireRole('admin');

$flash = null;
$flashError = null;
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $flashError = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? 'create');

        if ($action === 'create') {
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
        } elseif ($action === 'update') {
            $id = (int) ($_POST['user_id'] ?? 0);
            $role = (string) ($_POST['role'] ?? 'staff');
            $password = trim((string) ($_POST['password'] ?? ''));
            $updated = AuthService::updateUser($id, $role, $password !== '' ? $password : null);
            if ($updated['ok']) {
                AuditLog::record(
                    AuthService::currentUsername() ?? 'admin',
                    'STAFF_UPDATED',
                    "id={$id} role={$role}" . ($password !== '' ? ' password_reset=1' : ''),
                    $_SERVER['REMOTE_ADDR'] ?? null
                );
                $flash = 'Account updated.';
                $editId = 0;
            } else {
                $flashError = $updated['error'] ?? 'Could not update account.';
                $editId = $id;
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['user_id'] ?? 0);
            $deleted = AuthService::deleteUser($id);
            if ($deleted['ok']) {
                $uname = $deleted['username'] ?? (string) $id;
                AuditLog::record(AuthService::currentUsername() ?? 'admin', 'STAFF_DELETED', "id={$id} username={$uname}", $_SERVER['REMOTE_ADDR'] ?? null);
                $flash = 'Account deleted: ' . $uname;
                if ($editId === $id) {
                    $editId = 0;
                }
            } else {
                $flashError = $deleted['error'] ?? 'Could not delete account.';
            }
        }
    }
}

$users = AuthService::listUsers();
$editUser = null;
if ($editId > 0) {
    foreach ($users as $u) {
        if ((int) $u['id'] === $editId) {
            $editUser = $u;
            break;
        }
    }
    if (!$editUser) {
        $editId = 0;
    }
}

$currentId = (int) ($_SESSION['admin_id'] ?? 0);
$activePage = 'staff';
$skipDefaultHeader = true;
$pageTitle = 'Staff & Sub-admin – Shreeshta Family Store';
require __DIR__ . '/../templates/header.php';
?>
<?php require __DIR__ . '/../templates/admin_nav.php'; ?>

<main class="flex-1 max-w-3xl mx-auto px-5 py-8">
  <h2 class="font-heading text-2xl font-bold text-maroon mb-2">Staff &amp; Sub-admin Accounts</h2>
  <p class="text-sm text-maroon-dark/70 mb-5">Create, edit, or delete logins for verify counter (staff), registrations (sub-admin), or full admin.</p>

  <?php if ($flash): ?>
    <p class="text-green-700 bg-green-50 border border-green-200 rounded-lg px-4 py-2 text-sm mb-4"><?= e($flash) ?></p>
  <?php endif; ?>
  <?php if ($flashError): ?>
    <p class="text-red-700 bg-red-50 border border-red-200 rounded-lg px-4 py-2 text-sm mb-4"><?= e($flashError) ?></p>
  <?php endif; ?>

  <?php if ($editUser): ?>
  <form method="post" class="bg-white gold-border rounded-2xl p-5 shadow-sm space-y-4 mb-6">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>">
    <h3 class="font-heading text-lg font-bold text-maroon">Edit: <?= e($editUser['username']) ?></h3>
    <div class="grid sm:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-semibold text-maroon-dark mb-1">Role</label>
        <select name="role" class="w-full rounded-lg gold-border px-3 py-2">
          <option value="staff" <?= ($editUser['role'] ?? '') === 'staff' ? 'selected' : '' ?>>Staff — verify &amp; redeem only</option>
          <option value="subadmin" <?= ($editUser['role'] ?? '') === 'subadmin' ? 'selected' : '' ?>>Sub-admin — registrations list only (view)</option>
          <option value="admin" <?= ($editUser['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin — full access</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-semibold text-maroon-dark mb-1">New password <span class="font-normal text-maroon-dark/60">(optional)</span></label>
        <input type="text" name="password" minlength="12"
          class="w-full rounded-lg gold-border px-3 py-2" placeholder="Leave blank to keep current">
      </div>
    </div>
    <div class="flex flex-wrap gap-3">
      <button type="submit" class="bg-maroon text-ivory font-bold px-5 py-2.5 rounded-xl">Save changes</button>
      <a href="staff.php" class="inline-flex items-center px-5 py-2.5 rounded-xl gold-border text-maroon font-semibold">Cancel</a>
    </div>
  </form>
  <?php else: ?>
  <form method="post" class="bg-white gold-border rounded-2xl p-5 shadow-sm space-y-4 mb-6">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
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
  <?php endif; ?>

  <div class="bg-white gold-border rounded-2xl shadow-sm overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-maroon text-ivory">
        <tr>
          <th class="px-3 py-2.5 text-left">Username</th>
          <th class="px-3 py-2.5 text-left">Role</th>
          <th class="px-3 py-2.5 text-left">Last login</th>
          <th class="px-3 py-2.5 text-right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <?php
            $uid = (int) $u['id'];
            $isSelf = $currentId > 0 && $currentId === $uid;
          ?>
          <tr class="border-t border-gold/20">
            <td class="px-3 py-2.5 font-medium"><?= e($u['username']) ?><?= $isSelf ? ' <span class="text-maroon-dark/50">(you)</span>' : '' ?></td>
            <td class="px-3 py-2.5"><?= e(match ($u['role']) {
              'admin' => 'Admin',
              'subadmin' => 'Sub-admin',
              'staff' => 'Staff',
              default => $u['role'],
            }) ?></td>
            <td class="px-3 py-2.5"><?= e($u['last_login_at'] ?? '—') ?></td>
            <td class="px-3 py-2.5 text-right whitespace-nowrap">
              <a href="staff.php?edit=<?= $uid ?>" class="text-maroon font-semibold hover:underline mr-3">Edit</a>
              <?php if (!$isSelf): ?>
              <form method="post" class="inline" onsubmit="return confirm(<?= json_encode('Delete account ' . $u['username'] . '? This cannot be undone.', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>);">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" value="<?= $uid ?>">
                <button type="submit" class="text-red-700 font-semibold hover:underline">Delete</button>
              </form>
              <?php else: ?>
              <span class="text-maroon-dark/40">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>

<?php require __DIR__ . '/../templates/footer.php'; ?>
