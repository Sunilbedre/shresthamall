<?php
/** templates/admin_nav.php  expects $activePage (dashboard|registrations|settings|exports|staff|offers) */
$activePage = $activePage ?? '';

$navItems = [];
if (AuthService::isAdmin() || AuthService::isStaff() || AuthService::isSubAdmin()) {
    $navItems['dashboard'] = [admin_url('dashboard.php'), 'Dashboard'];
}
if (AuthService::isAdmin() || AuthService::isStaff() || AuthService::isSubAdmin()) {
    $navItems['registrations'] = [admin_url('registrations.php'), 'Registrations'];
}
if (AuthService::isAdmin() || AuthService::isStaff() || AuthService::isSubAdmin()) {
    $navItems['offers_report'] = [admin_url('offers_report.php'), 'Offers Report'];
}
if (AuthService::isAdmin() || AuthService::isSubAdmin()) {
    $navItems['exports'] = [admin_url('exports.php'), 'Exports'];
}
if (AuthService::isAdmin()) {
    $navItems['offers'] = [admin_url('events.php'), 'Events'];
    $navItems['settings'] = [admin_url('settings.php'), 'Settings'];
    $navItems['staff'] = [admin_url('staff.php'), 'Staff'];
}
if (AuthService::isAdmin() || AuthService::isStaff()) {
    $navItems['verify'] = ['/verify.php', 'Verify Counter'];
}
?>
<header class="bg-maroon text-ivory shadow-md sticky top-0 z-40">
  <div class="max-w-7xl mx-auto px-5 py-4 flex flex-wrap items-center justify-between gap-3">
    <div>
      <p class="uppercase tracking-widest text-gold-light text-[10px] font-semibold">Shreeshta Family Store</p>
      <h1 class="font-heading text-lg sm:text-xl font-bold leading-tight">Admin Panel</h1>
      <p class="text-gold-light/90 text-xs mt-0.5">Malleshwaram, Bengaluru</p>
    </div>
    <nav class="flex flex-wrap gap-1.5 text-sm">
      <?php foreach ($navItems as $key => [$url, $label]): ?>
        <a href="<?= e($url) ?>"
          class="px-3 py-1.5 rounded-lg font-semibold transition <?= $activePage === $key ? 'bg-gold text-maroon-dark' : 'hover:bg-maroon-dark' ?>">
          <?= e($label) ?>
        </a>
      <?php endforeach; ?>
      <a href="<?= e(admin_url('logout.php')) ?>" class="px-3 py-1.5 rounded-lg font-semibold text-gold-light hover:bg-maroon-dark">Logout</a>
    </nav>
  </div>
</header>
