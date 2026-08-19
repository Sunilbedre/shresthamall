<?php
/**
 * config/config.php
 * Loads .env into environment variables and exposes a single $CONFIG array.
 * No external dependencies (no composer) — small hand-rolled .env parser,
 * so this project runs on plain shared hosting with zero `composer install`.
 */

declare(strict_types=1);

// ---- Locate project root -------------------------------------------------
define('APP_ROOT', dirname(__DIR__));

// ---- Minimal .env loader ---------------------------------------------------
function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return;
    }
    // Strip UTF-8 BOM (common when .env is created via Windows PowerShell)
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // strip surrounding quotes if present
        if (strlen($value) >= 2 && (
            ($value[0] === '"' && $value[-1] === '"') ||
            ($value[0] === "'" && $value[-1] === "'")
        )) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && !array_key_exists($key, $_ENV)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

load_env(APP_ROOT . '/.env');

function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return $value;
}

// ---- Timezone ---------------------------------------------------------
date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Kolkata'));

// ---- Global config array ------------------------------------------------
$CONFIG = [
    'app_env'      => env('APP_ENV', 'production'),
    'app_url'      => env('APP_URL', ''),
    'app_secret'   => env('APP_SECRET', 'insecure-default-change-me'),
    'db_path'      => APP_ROOT . '/' . env('DB_PATH', 'storage/database.sqlite'),
    // Secret panel URL segment — never use "admin". Example: /sfs-ops-m9k2x7q4/login.php
    'admin_path'   => trim((string) env('ADMIN_PATH', 'sfs-ops-m9k2x7q4'), '/'),

    'whatsapp' => [
        'phone_number_id'      => env('WHATSAPP_PHONE_NUMBER_ID', ''),
        'business_account_id'  => env('WHATSAPP_BUSINESS_ACCOUNT_ID', ''),
        'access_token'         => env('WHATSAPP_ACCESS_TOKEN', ''),
        'template_name'        => env('WHATSAPP_TEMPLATE_NAME', 'your_1_special_offer'),
        'template_lang'        => env('WHATSAPP_TEMPLATE_LANG', 'en'),
        'otp_template_name'    => env('WHATSAPP_OTP_TEMPLATE_NAME', 'otptemp'),
        'otp_template_lang'    => env('WHATSAPP_OTP_TEMPLATE_LANG', 'en'),
        'header_image_url'     => env('WHATSAPP_HEADER_IMAGE_URL', ''),
        'api_version'          => env('WHATSAPP_API_VERSION', 'v20.0'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN', ''),
    ],

    'smsalert' => [
        'enabled'     => env('SMSALERT_ENABLED', '1') === '1',
        'apikey'      => env('SMSALERT_API_KEY', ''),
        'user'        => env('SMSALERT_USER', ''),
        'password'    => env('SMSALERT_PASSWORD', ''),
        'sender'      => env('SMSALERT_SENDER', 'BEDSOL'),
        'route'       => env('SMSALERT_ROUTE', ''),
        'otp_message' => env(
            'SMSALERT_OTP_MESSAGE',
            'Your OTP for Shreeshta Family Store offer is {otp}. Valid for 10 mins. Do not share.'
        ),
    ],

    'turnstile' => [
        'site_key'   => env('TURNSTILE_SITE_KEY', ''),
        'secret_key' => env('TURNSTILE_SECRET_KEY', ''),
    ],
];

// ---- Error display (never show raw errors in production) ---------------
if ($CONFIG['app_env'] === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');

// ---- Secure session bootstrap -------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $sessionDir = APP_ROOT . '/storage/sessions';
    if (!is_dir($sessionDir)) {
        mkdir($sessionDir, 0775, true);
    }
    // Explicit path — winget/Windows PHP often has an empty/unusable default save_path,
    // which makes CSRF tokens evaporate between the GET (form) and POST (submit).
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
    ini_set('session.gc_maxlifetime', '7200');

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('sfs_session');

    // Drop a corrupt/stale cookie (e.g. from earlier local-server restarts) so
    // session_start() doesn't warn about illegal session IDs.
    $cookieName = session_name();
    if (!empty($_COOKIE[$cookieName])) {
        $sid = (string) $_COOKIE[$cookieName];
        if ($sid === '' || !preg_match('/^[A-Za-z0-9,-]+$/', $sid) || strlen($sid) > 128) {
            unset($_COOKIE[$cookieName]);
            setcookie($cookieName, '', [
                'expires'  => time() - 42000,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    if (!@session_start()) {
        // Last resort: force a fresh session id
        session_id(bin2hex(random_bytes(16)));
        session_start();
    }
}

// ---- Security headers (all pages) ----------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    if (($CONFIG['app_env'] ?? '') === 'production') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ---- CSRF helpers --------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// ---- Simple helpers -------------------------------------------------------
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/**
 * Build a URL under the secret admin panel path.
 * Examples: admin_url('login.php') → /sfs-ops-…/login.php
 */
function admin_url(string $path = ''): string
{
    global $CONFIG;
    $base = '/' . trim((string) ($CONFIG['admin_path'] ?? 'sfs-ops-m9k2x7q4'), '/');
    $path = ltrim($path, '/');
    return $path === '' ? $base . '/' : $base . '/' . $path;
}

/** Absolute public base URL (no trailing slash). */
function app_url(string $path = ''): string
{
    global $CONFIG;
    $base = rtrim((string) ($CONFIG['app_url'] ?? ''), '/');
    if ($base === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = ($https ? 'https' : 'http') . '://' . $host;
    }
    if ($path === '') {
        return $base;
    }
    return $base . '/' . ltrim($path, '/');
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
