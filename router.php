<?php
/**
 * Router for PHP's built-in server (php -S), mirroring root .htaccess.
 * Usage: php -S localhost:8000 router.php
 */
declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rawurldecode($uri);

// Block internal folders
if (preg_match('#^/(app|config|storage|templates|scripts)(/|$)#', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Load admin path from .env (same default as config.php)
$adminPath = 'sfs-ops-m9k2x7q4';
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'ADMIN_PATH=')) {
            $adminPath = trim(substr($line, strlen('ADMIN_PATH=')), " \t\"'");
            $adminPath = trim($adminPath, '/');
            break;
        }
    }
}

// Old /admin is hidden
if (preg_match('#^/admin(/|$)#', $uri)) {
    http_response_code(404);
    echo 'Not Found';
    return true;
}

$routes = [
    '/'                      => 'public/index.php',
    '/offer'                 => 'public/offer.php',
    '/offer.php'             => 'public/offer.php',
    '/check-mobile'          => 'public/check_mobile.php',
    '/check-mobile.php'      => 'public/check_mobile.php',
    '/send-otp'              => 'public/send_otp.php',
    '/send-otp.php'          => 'public/send_otp.php',
    '/verify-otp'            => 'public/verify_otp.php',
    '/verify-otp.php'        => 'public/verify_otp.php',
    '/success'               => 'public/success.php',
    '/success.php'           => 'public/success.php',
    '/verify'                => 'public/verify.php',
    '/verify.php'            => 'public/verify.php',
    '/webhook'               => 'public/webhook.php',
    '/webhook.php'           => 'public/webhook.php',
    '/voucher-pdf'           => 'public/voucher_pdf.php',
    '/voucher-pdf.php'       => 'public/voucher_pdf.php',
    '/js/register-flow.js'   => 'public/js/register-flow.js',
];

$prefix = '/' . $adminPath;
$adminMap = [
    ''               => 'admin/dashboard.php',
    '/'              => 'admin/dashboard.php',
    '/login'         => 'admin/login.php',
    '/login.php'     => 'admin/login.php',
    '/logout'        => 'admin/logout.php',
    '/logout.php'    => 'admin/logout.php',
    '/dashboard'     => 'admin/dashboard.php',
    '/dashboard.php' => 'admin/dashboard.php',
    '/registrations' => 'admin/registrations.php',
    '/registrations.php' => 'admin/registrations.php',
    '/settings'      => 'admin/settings.php',
    '/settings.php'  => 'admin/settings.php',
    '/exports'       => 'admin/exports.php',
    '/exports.php'   => 'admin/exports.php',
    '/staff'         => 'admin/staff.php',
    '/staff.php'     => 'admin/staff.php',
    '/offers'        => 'admin/offers.php',
    '/offers.php'    => 'admin/offers.php',
    '/events'        => 'admin/events.php',
    '/events.php'    => 'admin/events.php',
    '/Events'        => 'admin/events.php',
    '/Events.php'    => 'admin/events.php',
    '/offers_report' => 'admin/offers_report.php',
    '/offers_report.php' => 'admin/offers_report.php',
    '/special_events_report' => 'admin/special_events_report.php',
    '/special_events_report.php' => 'admin/special_events_report.php',
];

$path = rtrim($uri, '/') ?: '/';

// /s/{campaign}/{product} — special event registration
if (preg_match('#^/s/([a-z0-9-]+)/([a-z0-9-]+)/?$#', $uri, $m)) {
    $_GET['campaign'] = $m[1];
    $_GET['product'] = $m[2];
    require __DIR__ . '/public/special_offer.php';
    return true;
}

// /v/{VOUCHER_CODE}
if (preg_match('#^/v/([A-Za-z0-9\\-]+)/?$#', $uri, $m)) {
    $_GET['code'] = strtoupper($m[1]);
    $_SERVER['ROUTE_VOUCHER_CODE'] = strtoupper($m[1]);
    require __DIR__ . '/public/v.php';
    return true;
}

// Secret admin panel
if ($uri === $prefix || str_starts_with($uri, $prefix . '/')) {
    $rest = substr($uri, strlen($prefix));
    if ($rest === false) {
        $rest = '';
    }
    $restNorm = $rest === '' ? '' : (str_starts_with($rest, '/') ? $rest : '/' . $rest);
    if (isset($adminMap[$restNorm])) {
        require __DIR__ . '/' . $adminMap[$restNorm];
        return true;
    }
    // Fallback: /secret/foo.php → admin/foo.php if file exists
    $candidate = __DIR__ . '/admin' . $restNorm;
    if (is_file($candidate)) {
        require $candidate;
        return true;
    }
    http_response_code(404);
    echo 'Not Found';
    return true;
}

if (isset($routes[$uri])) {
    require __DIR__ . '/' . $routes[$uri];
    return true;
}
if (isset($routes[$path])) {
    require __DIR__ . '/' . $routes[$path];
    return true;
}

// Serve existing static/PHP files as-is — but never expose /admin/*
$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}

http_response_code(404);
echo 'Not Found';
return true;
