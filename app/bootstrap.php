<?php
/**
 * app/bootstrap.php
 * Include this one file at the top of every entry-point script
 * (public/*.php, admin/*.php). It loads config, all services, and
 * ensures the database schema exists.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/OfferCatalog.php';
require_once __DIR__ . '/CampaignService.php';
require_once __DIR__ . '/Products.php';
require_once __DIR__ . '/Areas.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Validation.php';
require_once __DIR__ . '/VoucherService.php';
require_once __DIR__ . '/WhatsAppService.php';
require_once __DIR__ . '/SmsAlertService.php';
require_once __DIR__ . '/OtpService.php';
require_once __DIR__ . '/CustomerService.php';
require_once __DIR__ . '/AuthService.php';
require_once __DIR__ . '/VoucherPresenter.php';
require_once __DIR__ . '/SimplePdf.php';

// Ensure schema exists (cheap: only creates tables if missing)
Database::migrate();
OfferCatalog::ensureSchema();
CampaignService::ensureSchema();
// ---- Hide /admin/* from the public web — only secret ADMIN_PATH gate is allowed ----
$scriptFile = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
$adminDir = rtrim(str_replace('\\', '/', APP_ROOT), '/') . '/admin/';
if ($scriptFile !== '' && str_starts_with($scriptFile, $adminDir) && !defined('SFS_ADMIN_GATE')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}