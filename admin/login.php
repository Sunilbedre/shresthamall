<?php
/**
 * admin/login.php  ->  route: /admin/login
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

if (AuthService::check()) {
    redirect(AuthService::homePath());
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (AuthService::rateLimited('login_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 8, 900)) {
        $error = 'Too many login attempts. Please wait a few minutes.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $result = AuthService::attempt($username, $password);

        if ($result['ok']) {
            redirect(AuthService::homePath());
        }

        $error = match ($result['error'] ?? '') {
            'LOCKED' => 'Too many failed attempts. This account is temporarily locked for 30 minutes.',
            'RATE_LIMITED' => 'Too many login attempts from this network. Please wait 15 minutes.',
            default => 'Invalid username or password.',
        };
    }
}

$pageTitle = 'Login – Shreeshta Family Store Admin';
$compactHeader = true;
require __DIR__ . '/../templates/header.php';
?>
<main class="max-w-md mx-auto px-3.5 py-8">
  <div class="bg-white gold-border rounded-2xl p-5 sm:p-7 shadow-md">
    <h2 class="font-heading text-2xl font-bold text-maroon mb-1 text-center">Admin Login</h2>
    <p class="text-center text-sm text-maroon-dark/60 mb-5">Staff, sub-admin &amp; admin access</p>

    <?php if ($error): ?>
      <p class="text-red-700 bg-red-50 border border-red-200 rounded-lg px-4 py-2 text-sm mb-4"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" class="space-y-4">
      <?= csrf_field() ?>
      <div>
        <label class="block font-semibold text-maroon-dark mb-1.5 text-sm">Username</label>
        <input type="text" name="username" required autofocus autocomplete="username"
          class="w-full rounded-xl gold-border gold-ring px-4 py-3 text-base">
      </div>
      <div>
        <label class="block font-semibold text-maroon-dark mb-1.5 text-sm">Password</label>
        <input type="password" name="password" required autocomplete="current-password"
          class="w-full rounded-xl gold-border gold-ring px-4 py-3 text-base">
      </div>
      <button type="submit"
        class="w-full tap-target bg-maroon hover:bg-maroon-dark transition text-ivory font-bold py-3.5 rounded-xl">
        Log In
      </button>
    </form>
  </div>
</main>
<?php
$compactFooter = true;
require __DIR__ . '/../templates/footer.php';
?>
